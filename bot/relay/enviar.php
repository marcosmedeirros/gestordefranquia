<?php
/**
 * RELAY DO WEBHOOK — a metade que ENVIA. Ver o porquê em receber.php.
 *
 * Roda como serviço (fba-webhook-enviar.service). Lê a pasta spool/, junta o que
 * estiver esperando num POST só e manda pro site no máximo uma vez a cada
 * RELAY_INTERVALO segundos.
 *
 * O QUE ELE GARANTE
 *  - O site aceita uma LISTA de mensagens em `data` (api/whatsapp-webhook.php,
 *    "a Evolução manda ora um objeto, ora uma lista"), então 30 mensagens que
 *    chegam numa rajada viram 1 requisição, não 30. Nada muda no que o site faz
 *    com cada mensagem — ele repete o mesmo laço.
 *  - Nada se perde: o evento só sai do disco depois que o site respondeu 2xx. Se
 *    o site recusar (429, 5xx, rede), o evento espera e tenta de novo com recuo
 *    crescente (15s, 30s, 60s... até 5 min), respeitando o Retry-After.
 *  - Evento velho demais (RELAY_IDADE_MAX, 30 min) é descartado: responder um
 *    comando de meia hora atrás só atrapalha, e o limite evita uma enxurrada de
 *    respostas antigas depois de uma pane longa.
 *  - O site descarta mensagem repetida (wcMensagemInedita), então reenviar é
 *    seguro.
 *  - Lote que o site rejeita como um todo (5xx / 400 / 404 / 422) passa a ser
 *    mandado um a um até achar o que quebra; esse é descartado, os outros saem.
 *    401/403 NÃO descartam: é token errado, e jogar fora tudo por causa de uma
 *    configuração errada seria pior que esperar.
 *  - FOTO DE COMANDO (/atualizarelenco): a Evolution é a única que baixa a mídia e
 *    ela só alcança a VPS — o site na Hostinger não chega nela. Então, antes de
 *    mandar, o relay pede a imagem à Evolution local e cola no evento em
 *    `fba_midia` ({base64, mimetype}, ou {erro} se falhou). Só as fotos cuja legenda
 *    é esse comando passam por isso (o resto do tráfego segue intacto), e elas
 *    saem sozinhas no POST (o base64 de uma foto não viaja junto de 24 mensagens).
 *    O evento enriquecido é regravado no disco, pra um retry não baixar de novo.
 */

declare(strict_types=1);

$SITE    = getenv('RELAY_SITE_URL') ?: 'https://fbabrasil.com.br/api/whatsapp-webhook.php';
$SPOOL   = getenv('RELAY_SPOOL') ?: (__DIR__ . '/spool');
$JANELA  = (float)(getenv('RELAY_JANELA') ?: 1.5);      // espera pra juntar mais eventos
$INTERV  = (float)(getenv('RELAY_INTERVALO') ?: 2.5);   // mínimo entre dois envios
$LOTE    = (int)(getenv('RELAY_LOTE') ?: 25);
$BASE    = (float)(getenv('RELAY_RECUO_BASE') ?: 15);   // 1º recuo, em segundos
$RECUO_MAX = 300.0;
$IDADE_MAX = (float)(getenv('RELAY_IDADE_MAX') ?: 1800);
$SPOOL_MAX = 5000;

if (!is_dir($SPOOL)) @mkdir($SPOOL, 0700, true);

function logar(string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] $m\n"); }

/** A foto é de um comando de atualizar elenco? (a legenda manda: sem ela é foto qualquer e passa batida) */
function ehFotoDeComando(array $m): bool
{
    $cap = (string)($m['message']['imageMessage']['caption'] ?? '');
    return $cap !== '' && preg_match('~^\s*/atualizar[\s-]?elenco\b~iu', $cap) === 1;
}

/** O evento tem foto de comando? */
function eventoTemFotoDeComando(array $evento): bool
{
    foreach (mensagensDe($evento) ?? [] as $m) if (is_array($m) && ehFotoDeComando($m)) return true;
    return false;
}

/**
 * A Evolution local, com a mesma configuração do worker (whatsapp-local.config.php, uma pasta
 * acima) ou por variável de ambiente RELAY_EVOLUTION_URL / _INSTANCIA / _KEY.
 *
 * @return ?array{url:string,instancia:string,chave:string}
 */
function configEvolution(): ?array
{
    $url = (string)getenv('RELAY_EVOLUTION_URL'); $inst = (string)getenv('RELAY_EVOLUTION_INSTANCIA'); $chave = (string)getenv('RELAY_EVOLUTION_KEY');
    if ($url === '' || $inst === '' || $chave === '') {
        $arq = getenv('RELAY_CONFIG') ?: dirname(__DIR__) . '/whatsapp-local.config.php';
        $c = is_file($arq) ? @include $arq : null;
        if (is_array($c)) {
            $url = $url ?: (string)($c['evolution_url'] ?? '');
            $inst = $inst ?: (string)($c['evolution_instancia'] ?? '');
            $chave = $chave ?: (string)($c['evolution_api_key'] ?? '');
        }
    }
    return ($url !== '' && $inst !== '' && $chave !== '') ? ['url' => rtrim($url, '/'), 'instancia' => $inst, 'chave' => $chave] : null;
}

/** Pede a imagem à Evolution. @return array{base64?:string,mimetype?:string,erro?:string} */
function baixarMidia(array $m, ?array $evo): array
{
    if (!$evo) return ['erro' => 'relay sem configuração da Evolution'];
    $ch = curl_init($evo['url'] . '/chat/getBase64FromMediaMessage/' . rawurlencode($evo['instancia']));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['message' => ['key' => $m['key'] ?? [], 'message' => $m['message'] ?? []], 'convertToMp4' => false], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . $evo['chave']],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $falha = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $falha !== '') return ['erro' => 'Evolution sem resposta: ' . $falha];
    $j = json_decode((string)$resp, true);
    $b64 = is_array($j) ? (string)($j['base64'] ?? '') : '';
    if ($st < 200 || $st >= 300 || $b64 === '') {
        return ['erro' => 'Evolution HTTP ' . $st . ' ' . mb_substr(is_array($j) ? json_encode($j['response']['message'] ?? $j['message'] ?? $j, JSON_UNESCAPED_UNICODE) : (string)$resp, 0, 160)];
    }
    // Alguns builds devolvem "data:image/jpeg;base64,...": o site quer só o base64.
    if (str_starts_with($b64, 'data:') && ($p = strpos($b64, ',')) !== false) $b64 = substr($b64, $p + 1);
    // Acima de ~4,5 MB de imagem o POST pro site corre risco de bater no limite da hospedagem.
    if (strlen($b64) > 6000000) return ['erro' => 'foto grande demais (' . round(strlen($b64) / 1.37 / 1048576, 1) . ' MB)'];
    return ['base64' => $b64, 'mimetype' => (string)($j['mimetype'] ?? $m['message']['imageMessage']['mimetype'] ?? 'image/jpeg')];
}

/** Cola a foto nas mensagens de comando do evento. @return bool true se mexeu (e o arquivo precisa ser regravado) */
function enriquecerEvento(array &$evento, ?array $evo): bool
{
    $d = $evento['b']['data'] ?? null;
    if (!is_array($d)) return false;
    $unica = isset($d['key']);
    $lista = $unica ? [$d] : $d;
    $mexeu = false;
    foreach ($lista as $i => $m) {
        if (!is_array($m) || !ehFotoDeComando($m) || isset($m['fba_midia'])) continue;
        $m['fba_midia'] = baixarMidia($m, $evo);
        $lista[$i] = $m;
        $mexeu = true;
        logar('foto de comando ' . (string)($m['key']['id'] ?? '?') . ': ' . (isset($m['fba_midia']['erro'])
            ? 'FALHOU ' . $m['fba_midia']['erro'] : 'baixada (' . strlen($m['fba_midia']['base64']) . ' bytes em base64)'));
    }
    if ($mexeu) $evento['b']['data'] = $unica ? $lista[0] : $lista;
    return $mexeu;
}

/** @return string[] caminhos dos eventos prontos, do mais antigo ao mais novo */
function listar(string $spool): array
{
    $a = glob($spool . '/*.json') ?: [];
    sort($a, SORT_STRING);   // o nome começa pelo relógio em microssegundos
    return $a;
}

/** @return ?array{b:array,h:array,t:float} */
function ler(string $arq): ?array
{
    $j = json_decode((string)@file_get_contents($arq), true);
    return (is_array($j) && isset($j['b']) && is_array($j['b'])) ? $j : null;
}

/** As mensagens de um evento, quer o `data` seja um objeto, quer uma lista. */
function mensagensDe(array $evento): ?array
{
    $d = $evento['b']['data'] ?? null;
    if (!is_array($d)) return null;
    if (isset($d['key'])) return [$d];
    // Lista de verdade (0,1,2...) e não vazia. array_is_list() só existe no PHP 8.1
    // e a VPS roda 8.0.
    return ($d && array_keys($d) === range(0, count($d) - 1)) ? $d : null;
}

/** Quantos dos primeiros eventos dá pra mandar juntos (mesmo tipo e mesmo token). */
function quantosJuntam(array $eventos, int $max): int
{
    $n = 0; $evento = null; $token = null;
    foreach ($eventos as $e) {
        if ($n >= $max) break;
        if (mensagensDe($e) === null) break;
        // Foto de comando viaja sozinha: o base64 dela não pode pegar carona com um lote.
        if (eventoTemFotoDeComando($e)) { if ($n === 0) $n = 1; break; }
        $ev  = (string)($e['b']['event'] ?? '');
        $tok = (string)($e['h']['x-fba-token'] ?? '');
        if ($n === 0) { $evento = $ev; $token = $tok; }
        elseif ($ev !== $evento || $tok !== $token) break;
        $n++;
    }
    return max(1, $n);   // um evento que não junta com ninguém sai sozinho
}

/** @return array{0:int,1:float} [status HTTP (0 = sem resposta), Retry-After em s] */
function postar(string $url, array $corpo, array $h): array
{
    $retry = 0.0;
    $cab = ['Content-Type: application/json'];
    foreach ($h as $k => $v) $cab[] = $k . ': ' . $v;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($corpo, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $cab,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => function ($c, $linha) use (&$retry) {
            if (stripos($linha, 'retry-after:') === 0) $retry = (float)trim(substr($linha, 12));
            return strlen($linha);
        },
    ]);
    curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$st, $retry];
}

logar("relay no ar: $SITE (juntando por {$JANELA}s, 1 envio a cada {$INTERV}s)");

$falhas = 0;
$isolar = false;   // true: manda um por vez até achar o evento que o site rejeita
/* Quantos eventos sozinhos precisam passar pro relay desconfiar que não há culpado
   nenhum e voltar a juntar. @see o bloco do 2xx, que explica por que isto existe. */
const RELAY_OK_PARA_VOLTAR_AO_LOTE = 10;
$okIsolados = 0;   // sucessos seguidos mandando um por vez

while (true) {
    $arqs = listar($SPOOL);
    if (!$arqs) { usleep(400000); continue; }

    usleep((int)($JANELA * 1e6));   // deixa a rajada terminar de chegar
    $arqs = listar($SPOOL);

    // Fila que passou do razoável: fica só com o que é mais novo.
    if (count($arqs) > $SPOOL_MAX) {
        $sobra = count($arqs) - $SPOOL_MAX;
        foreach (array_slice($arqs, 0, $sobra) as $velho) @unlink($velho);
        $arqs = array_slice($arqs, $sobra);
        logar("fila acima de $SPOOL_MAX: descartei os $sobra mais antigos");
    }

    // Lê o começo da fila já separando o que está velho demais.
    $janela = array_slice($arqs, 0, $isolar ? 1 : $LOTE);
    $eventos = []; $paths = []; $velhos = 0;
    foreach ($janela as $arq) {
        $e = ler($arq);
        if ($e === null || (microtime(true) - (float)($e['t'] ?? 0)) > $IDADE_MAX) {
            @unlink($arq); $velhos++; continue;
        }
        $eventos[] = $e; $paths[] = $arq;
    }
    if ($velhos) logar("descartei $velhos evento(s) velho(s) ou ilegível(is)");
    if (!$eventos) continue;

    $n = $isolar ? 1 : quantosJuntam($eventos, $LOTE);
    $eventos = array_slice($eventos, 0, $n);
    $paths   = array_slice($paths, 0, $n);

    // Foto de /atualizarelenco: baixa na Evolution e cola no evento (uma vez; fica no disco).
    foreach ($eventos as $i => $ev) {
        if (eventoTemFotoDeComando($ev) && enriquecerEvento($ev, configEvolution())) {
            $eventos[$i] = $ev;
            $tmp = $paths[$i] . '.tmp';
            file_put_contents($tmp, json_encode($ev, JSON_UNESCAPED_UNICODE));
            rename($tmp, $paths[$i]);
        }
    }

    $corpo = $eventos[0]['b'];
    if ($n > 1) {
        $msgs = [];
        foreach ($eventos as $e) foreach (mensagensDe($e) as $m) $msgs[] = $m;
        $corpo['data'] = $msgs;
    }

    [$st, $retry] = postar($SITE, $corpo, $eventos[0]['h'] ?? []);

    if ($st >= 200 && $st < 300) {
        foreach ($paths as $p) @unlink($p);
        $falhas = 0;
        /* SAIR DO "UM POR VEZ" TEM QUE SER POSSÍVEL SOZINHO.
           A regra era: só volta a juntar quando um lote inteiro passa — porque um
           evento sozinho que passou não prova que o culpado sumiu, ele pode estar
           logo atrás. Só que em modo isolado o relay manda SEMPRE 1, então a
           condição `$n > 1` nunca podia acontecer: uma vez isolado, isolado pra
           sempre. Em 09/11/2026 ele ficou preso assim e empatou com a entrada
           (1 evento por POST a cada 4s, entrando 1 a cada 4s): 411 eventos parados
           e ~27 min de atraso em qualquer comando, sem um único erro no log.
           Agora uma sequência de sucessos sozinhos também libera. Se o culpado
           ainda estiver na fila, o próximo lote falha e o isolamento volta — custa
           um POST, e é muito mais barato que parar o bot. */
        if ($n > 1) {
            $isolar = false;
            $okIsolados = 0;
        } elseif ($isolar && ++$okIsolados >= RELAY_OK_PARA_VOLTAR_AO_LOTE) {
            $isolar = false;
            $okIsolados = 0;
            logar('voltando a juntar em lote (' . RELAY_OK_PARA_VOLTAR_AO_LOTE . ' eventos sozinhos passaram)');
        }
        logar("ok: $n evento(s) em 1 POST (HTTP $st), restam " . count(listar($SPOOL)));
        usleep((int)($INTERV * 1e6));
        continue;
    }

    // 400/404/422 de um evento sozinho: o site não quer esse, e tentar de novo
    // não muda nada. Descarta e segue.
    if ($n === 1 && in_array($st, [400, 404, 422], true)) {
        foreach ($paths as $p) @unlink($p);
        $isolar = false;   // achou o culpado: o resto volta a sair em lote
        logar("HTTP $st num evento só: descartado (" . basename($paths[0]) . ")");
        usleep((int)($INTERV * 1e6));
        continue;
    }

    // Lote rejeitado por motivo que não é limite de taxa: separa pra achar o culpado.
    if ($n > 1 && ($st >= 500 || in_array($st, [400, 404, 422], true))) {
        $isolar = true;
        logar("HTTP $st no lote de $n: passo a mandar um por vez");
        usleep((int)($INTERV * 1e6));
        continue;
    }

    // 429, 5xx, 401/403, ou sem resposta: o evento fica no disco e o relay recua.
    $falhas++;
    $espera = $retry > 0 ? min($retry, 600.0) : min($RECUO_MAX, $BASE * (2 ** ($falhas - 1)));
    $espera *= 1 + mt_rand(0, 20) / 100;   // um pouco de variação, pra não bater em compasso
    logar(sprintf('HTTP %d (falha %d): %d evento(s) esperam, tento de novo em %.0fs', $st, $falhas, count(listar($SPOOL)), $espera));
    usleep((int)($espera * 1e6));
}
