<?php
/**
 * Worker de WhatsApp — roda na MÁQUINA LOCAL, não na Hostinger.
 *
 * A Evolution API está num container aqui (fba-evolution, localhost:8081) e o
 * número está pareado com o celular do Marcos. A Hostinger não alcança esta
 * máquina (IP residencial, atrás de NAT), então o sentido é invertido: este
 * script puxa a fila do site, envia pela Evolution local e devolve o resultado.
 *
 * Ciclo, a cada execução:
 *   1. GET  /api/whatsapp-bot.php?action=pendentes
 *   2. POST na Evolution local, uma mensagem por vez
 *   3. POST /api/whatsapp-bot.php action=resultado
 *
 * A janela de horário (08:45–18:00) é decidida no SERVIDOR: fora dela o passo 1
 * volta vazio e este script não faz nada. Mudar o expediente não exige tocar
 * aqui.
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

// ── 1. Puxa a fila ──────────────────────────────────────────────────────
[$st, $resp, $erroRede] = req($site . '/api/whatsapp-bot.php?action=pendentes&limite=50', null, $hdrAuth);

if ($erroRede)  { logar("sem conexao com o site: $erroRede"); exit(1); }
if ($st === 401) { logar('token recusado pelo site — confira bot_token'); exit(1); }
if ($st !== 200) { logar("site respondeu HTTP $st"); exit(1); }

if (empty($resp['janela'])) {
    // Silencioso de propósito: rodando de minuto em minuto, logar isso encheria
    // o arquivo de linhas inúteis a noite inteira.
    exit(0);
}

$msgs = $resp['mensagens'] ?? [];
if (!$msgs) exit(0);

// ── 2. Envia pela Evolution local ───────────────────────────────────────
$urlEnvio = rtrim($cfg['evolution_url'], '/') . '/message/sendText/' . rawurlencode($cfg['evolution_instancia']);
$hdrEvo = ['apikey: ' . $cfg['evolution_api_key']];

$resultados = [];
$ok = 0; $falhou = 0;
$seguidasDeRede = 0;

foreach ($msgs as $m) {
    // 15s: envio bom responde em menos de 1s. O que estoura esse tempo é
    // Evolution travada ou destino inválido — e com o agendador rodando de
    // minuto em minuto, esperar 30s por mensagem empilharia execuções.
    [$s, $r, $e] = req($urlEnvio, ['number' => $m['destino'], 'text' => $m['texto']], $hdrEvo, 15);
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

// ── 3. Devolve o resultado ──────────────────────────────────────────────
[$st2, $r2, $e2] = req($site . '/api/whatsapp-bot.php?action=resultado', ['resultados' => $resultados], $hdrAuth);
if ($e2 || $st2 !== 200) {
    // As mensagens foram enviadas mas o site não soube: elas continuam
    // pendentes e sairiam de novo. Registrar isso é o que permite perceber.
    logar("AVISO: enviadas $ok mas o site nao confirmou (HTTP $st2 $e2) — podem repetir");
    exit(1);
}

logar("enviadas=$ok falhas=$falhou");
