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
        // Só volta a juntar quando um LOTE inteiro passa. Um evento sozinho que
        // passou não prova nada: o culpado ainda pode estar logo atrás dele.
        if ($n > 1) $isolar = false;
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
