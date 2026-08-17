<?php
/**
 * Worker de WhatsApp — roda na MÁQUINA LOCAL, não na Hostinger.
 *
 * A Evolution API está num container aqui (fba-evolution, localhost:8081) e o
 * número está pareado com o celular do Marcos. A Hostinger não alcança esta
 * máquina (IP residencial, atrás de NAT), então o sentido é invertido: este
 * script puxa a fila do site, envia pela Evolution local e devolve o resultado.
 *
 * Ciclo, repetido até fechar o minuto:
 *   1. GET  /api/whatsapp-bot.php?action=pendentes
 *   2. POST na Evolution local, uma mensagem por vez
 *   3. POST /api/whatsapp-bot.php action=resultado
 *
 * Ele não faz uma passada só e sai: o Agendador do Windows não desce de 1
 * minuto, e o bot responde /comando no grupo — esperar um minuto por isso seria
 * inaceitável. Então cada execução fica viva ~55s e bate no site de poucos em
 * poucos segundos, com o intervalo ditado pelo servidor.
 *
 * A janela de horário (08:45–18:00) é decidida no SERVIDOR e só vale pro aviso
 * automático; RESPOSTA A COMANDO sai a qualquer hora, porque tem alguém do
 * outro lado esperando. Mudar o expediente não exige tocar aqui.
 *
 * PC dormindo ou desligado não quebra nada: ninguém vem buscar, a fila espera,
 * e nenhuma das 8 tentativas é gasta à toa.
 *
 * Configuração: bot/whatsapp-local.config.php (fora do git).
 * Agendamento: Agendador de Tarefas do Windows, de minuto em minuto.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// O PHP CLI desta máquina vem com Europe/Berlin, e este script não carrega o
// helpers.php (que é quem fixa o fuso no resto do app). Sem isto o log carimba
// 5h adiantado e qualquer diagnóstico vira adivinhação. A JANELA de envio não
// depende disto — quem decide é o servidor, com fuso explícito.
date_default_timezone_set('America/Sao_Paulo');

$cfgPath = __DIR__ . '/whatsapp-local.config.php';
if (!is_file($cfgPath)) {
    fwrite(STDERR, "Falta o arquivo de configuração: $cfgPath\n");
    exit(1);
}
$cfg = require $cfgPath;

foreach (['site_url', 'bot_token', 'evolution_url', 'evolution_instancia', 'evolution_api_key'] as $k) {
    if (empty($cfg[$k])) { fwrite(STDERR, "Configuração incompleta: falta '$k'\n"); exit(1); }
}

/** HTTP simples. Devolve [status, corpoDecodificado, erroDeRede]. */
function req(string $url, ?array $json = null, array $headers = [], int $timeout = 25): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $h,
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($h, ['Content-Type: application/json']));
    }
    $corpo = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroRede = $corpo === false ? curl_error($ch) : null;
    unset($ch);
    return [$status, json_decode((string)$corpo, true), $erroRede];
}

function logar(string $msg): void
{
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $linha . "\n";
    @file_put_contents(__DIR__ . '/whatsapp-local.log', $linha . "\n", FILE_APPEND);
}

$site  = rtrim($cfg['site_url'], '/');
$token = $cfg['bot_token'];
$hdrAuth = ['Authorization: Bearer ' . $token];

$urlEnvio = rtrim($cfg['evolution_url'], '/') . '/message/sendText/' . rawurlencode($cfg['evolution_instancia']);
$hdrEvo = ['apikey: ' . $cfg['evolution_api_key']];

// O agendador do Windows não desce de 1 minuto, e esperar até 1 minuto por uma
// resposta de /cap no grupo é ruim demais — quem perguntou desiste antes. Então
// cada execução fica viva quase o minuto inteiro, batendo no site de poucos em
// poucos segundos. O intervalo quem dita é o servidor (rápido no expediente,
// devagar de madrugada), pelo mesmo motivo da janela morar lá.
$FIM_DA_RODADA = time() + 55;
$totalOk = 0; $totalFalhou = 0;

// ── Nomes dos grupos ────────────────────────────────────────────────────
//
// A Hostinger não alcança a Evolution, então o nome do grupo só chega ao
// site por aqui. Sem isso o Painel do Bot mostrava o último autor no lugar
// do nome — e num grupo o último autor é uma pessoa: "The Pathetic"
// aparecia como "Lucas Xavier".
//
// Uma vez a cada 6 horas, e NO FIM da rodada. Rodava a cada minuto e no
// começo: a chamada varre todos os grupos do aparelho e empurrava o fim da
// rodada pra além dos 55s, fazendo duas execuções se sobreporem. Nome de
// grupo muda de mês em mês; de hora em hora já seria exagero.
$marcador = __DIR__ . '/.nomes-sincronizados';
$sincronizarNomesAgora = !file_exists($marcador) || (time() - filemtime($marcador)) > 6 * 3600;

while (true) {
    // ── 1. Puxa a fila ──────────────────────────────────────────────────
    [$st, $resp, $erroRede] = req($site . '/api/whatsapp-bot.php?action=pendentes&limite=50', null, $hdrAuth);

    if ($erroRede)   { logar("sem conexao com o site: $erroRede"); exit(1); }
    if ($st === 401) { logar('token recusado pelo site — confira bot_token'); exit(1); }
    if ($st !== 200) { logar("site respondeu HTTP $st"); exit(1); }

    $intervalo = max(3, min(60, (int)($resp['intervalo'] ?? 5)));
    $msgs = $resp['mensagens'] ?? [];
    // Em segundos e calculado no servidor: comparar o carimbo com o relógio
    // desta máquina depende do fuso dela estar certo, e isso já deu 5 horas
    // de diferença num PHP que assumiu Europe/Berlin.
    $entradaSeg = $resp['ultima_entrada_seg'] ?? null;

    if (!$msgs) {
        // Nada a fazer. Dorme e tenta de novo, se ainda couber no minuto.
        // Silencioso de propósito: logar cada batida encheria o arquivo de
        // linhas inúteis a noite inteira.
        if (time() + $intervalo >= $FIM_DA_RODADA) break;
        sleep($intervalo);
        continue;
    }

    processarLote($msgs, $urlEnvio, $hdrEvo, $site, $hdrAuth, $totalOk, $totalFalhou);

    if (time() >= $FIM_DA_RODADA) break;
    sleep(1);   // acabou de enviar: volta logo, pode ter vindo mais coisa
}

if ($totalOk || $totalFalhou) logar("enviadas=$totalOk falhas=$totalFalhou");

vigiarRecepcao($cfg, $entradaSeg ?? null);

// Depois do laço, com a rodada já cumprida: se demorar, atrasa só a si mesma.
if ($sincronizarNomesAgora) {
    sincronizarNomes($cfg, $site, $hdrAuth, $hdrEvo);
    @touch($marcador);
}
exit(0);

/**
 * Reconecta a Evolution quando ela para de RECEBER.
 *
 * A falha que isto cobre não dá sinal nenhum: a instância segue 'open', o
 * socket responde consulta em 0,3s, envio sai normal — e mensagem nenhuma
 * entra. Aconteceu duas vezes em 15/08, e só apareceu porque os comandos da
 * liga pararam de ser respondidos. Reiniciar a instância resolve na hora:
 * o WhatsApp entrega o acumulado assim que ela reconecta.
 *
 * Quem faz isso é o worker porque a Evolution roda nesta máquina — o site não
 * alcança ela.
 *
 * O gatilho é silêncio LONGO, não ausência de mensagem: grupo quieto é normal.
 * Com carência entre tentativas, senão uma madrugada calada vira uma fila de
 * reconexões — que é justamente o tipo de coisa que faz o WhatsApp desconfiar
 * do número.
 */
function vigiarRecepcao(array $cfg, ?int $entradaSeg): void
{
    $CARENCIA_MIN = 25;   // entre uma reconexão e a próxima

    // Vigia as 24 horas — desde 17/08 o bot mora numa VPS que não desliga, e
    // comando digitado de madrugada merece resposta igual.
    //
    // O limite muda com a hora porque o SINAL muda: de dia, 25 minutos sem
    // ninguém falar num grupo ativo é sintoma; às 4 da manhã é só gente
    // dormindo. Usar 25 minutos à noite encheria a madrugada de reconexões
    // desnecessárias, e fila de reconexão é o que faz o WhatsApp desconfiar
    // do número.
    $hora = (int)date('G');
    $deDia = $hora >= 7 && $hora <= 22;
    $SILENCIO_MIN = $deDia ? 25 : 90;

    if ($entradaSeg === null) return;             // site antigo ou nunca chegou nada

    $quieto = $entradaSeg / 60;
    if ($quieto < $SILENCIO_MIN) return;

    $marcador = __DIR__ . '/.ultima-reconexao';
    if (file_exists($marcador) && (time() - filemtime($marcador)) < $CARENCIA_MIN * 60) return;
    @touch($marcador);

    $url = rtrim($cfg['evolution_url'], '/') . '/instance/restart/'
         . rawurlencode($cfg['evolution_instancia']);
    [$st, , $erro] = req($url, [], ['apikey: ' . $cfg['evolution_api_key']]);
    logar(sprintf('recepcao parada ha %d min — reconectando a Evolution: %s',
        (int)$quieto, $erro ? "falhou ($erro)" : "HTTP $st"));
}

/**
 * Busca o nome de cada grupo na Evolution e manda pro site.
 *
 * Silenciosa quando dá errado: nome de grupo é conforto, não função. Se a
 * Evolution não responder, o painel mostra "Grupo ···1234" e o envio de
 * mensagem — que é o trabalho de verdade deste worker — segue igual.
 */
function sincronizarNomes(array $cfg, string $site, array $hdrAuth, array $hdrEvo): void
{
    $url = rtrim($cfg['evolution_url'], '/') . '/group/fetchAllGroups/'
         . rawurlencode($cfg['evolution_instancia']) . '?getParticipants=false';
    [$st, $resp, $erro] = req($url, null, $hdrEvo);
    if ($erro || $st !== 200) return;

    // A Evolution já devolveu tanto uma lista crua quanto {groups:[...]}
    // dependendo da versão. Aceita as duas em vez de depender de uma.
    $lista = is_array($resp['groups'] ?? null) ? $resp['groups'] : (is_array($resp) ? $resp : []);
    $grupos = [];
    foreach ($lista as $g) {
        if (!is_array($g)) continue;
        $jid  = trim((string)($g['id'] ?? $g['jid'] ?? ''));
        $nome = trim((string)($g['subject'] ?? $g['name'] ?? ''));
        if ($jid !== '' && $nome !== '') $grupos[] = ['jid' => $jid, 'nome' => $nome];
    }
    if (!$grupos) return;

    [$st2, , ] = req($site . '/api/whatsapp-bot.php?action=nomes', ['grupos' => $grupos], $hdrAuth);
    if ($st2 === 200) logar('nomes de grupo sincronizados: ' . count($grupos));
}

/** Envia um lote pela Evolution e devolve o resultado pro site. */
function processarLote(array $msgs, string $urlEnvio, array $hdrEvo, string $site, array $hdrAuth, int &$totalOk, int &$totalFalhou): void
{
    $resultados = [];
    $ok = 0; $falhou = 0;
    $seguidasDeRede = 0;

    foreach ($msgs as $m) {
        // 15s: envio bom responde em menos de 1s. O que estoura esse tempo é
        // Evolution travada ou destino inválido — e com o agendador rodando de
        // minuto em minuto, esperar 30s por mensagem empilharia execuções.
        $corpo = ['number' => $m['destino'], 'text' => $m['texto']];
        // Menção: o número tem que estar no texto como @numero E nesta lista.
        // Só um dos dois não marca ninguém.
        if (!empty($m['mencoes']) && is_array($m['mencoes'])) $corpo['mentioned'] = $m['mencoes'];

        [$s, $r, $e] = req($urlEnvio, $corpo, $hdrEvo, 15);
        $deuCerto = !$e && $s >= 200 && $s < 300;
        if ($deuCerto) { $ok++; } else { $falhou++; }
        $resultados[] = [
            'id'  => (int)$m['id'],
            'ok'  => $deuCerto,
            'erro'=> $deuCerto ? null : ($e ?: ('evolution HTTP ' . $s . ' ' . mb_substr(json_encode($r, JSON_UNESCAPED_UNICODE), 0, 120))),
        ];

        // Erro de REDE seguido é Evolution fora do ar: insistir no lote inteiro só
        // queima uma tentativa de cada mensagem à toa. O resto fica pra próxima
        // rodada, intacto.
        $seguidasDeRede = $e ? $seguidasDeRede + 1 : 0;
        if ($seguidasDeRede >= 3) {
            logar('evolution nao respondeu 3x seguidas — parando o lote');
            break;
        }

        // Respiro entre mensagens: disparar em rajada num número pareado é o tipo
        // de padrão que a Meta usa pra identificar automação.
        if (count($msgs) > 1) usleep(1200000);
    }

    // ── 3. Devolve o resultado ──────────────────────────────────────────
    [$st2, $r2, $e2] = req($site . '/api/whatsapp-bot.php?action=resultado', ['resultados' => $resultados], $hdrAuth);
    if ($e2 || $st2 !== 200) {
        // As mensagens foram enviadas mas o site não soube: elas continuam
        // pendentes e sairiam de novo. Registrar isso é o que permite perceber.
        logar("AVISO: enviadas $ok mas o site nao confirmou (HTTP $st2 $e2) — podem repetir");
        exit(1);
    }

    $totalOk += $ok;
    $totalFalhou += $falhou;
}
