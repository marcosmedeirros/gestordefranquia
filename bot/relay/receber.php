<?php
/**
 * RELAY DO WEBHOOK — a metade que RECEBE.
 *
 * Roda na VPS com `php -S <ip-da-ponte-do-docker>:9099 receber.php`. A Evolution
 * (que está num container) manda o webhook pra cá em vez de mandar direto pro
 * site, e esta metade faz uma coisa só: guarda o evento em disco e responde 200
 * NA HORA.
 *
 * POR QUE EXISTE (04/11/2026). A Evolution dispara um POST pro site a cada
 * mensagem de grupo, sem fila e sem limite de ritmo, e tenta de novo (10x) se o
 * site recusar. A Hostinger limita requisição dinâmica por IP; com o worker
 * batendo a cada 5s, o uso normal já ficava colado no limite, e qualquer rajada
 * (a Evolution religando e despejando as mensagens acumuladas, por exemplo)
 * passava dele. Passou, o site respondia 429, os retries somavam mais carga, e o
 * bloqueio se sustentava sozinho — o bot ficava mudo até alguém parar tudo na
 * mão. Caiu em 03/11 às 15:01 e em 04/11 às 13:26.
 *
 * Com o relay a Evolution nunca vê um erro (responde 200 sempre) e quem fala com
 * o site é o enviar.php, num ritmo que ele controla.
 *
 * Mora só em 127.0.0.1/ponte do Docker: nada disto fica exposto na internet.
 */

$spool = getenv('RELAY_SPOOL') ?: (__DIR__ . '/spool');
if (!is_dir($spool)) @mkdir($spool, 0700, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    // Um GET serve de "está vivo?" — e diz quantos eventos esperam pra sair.
    header('Content-Type: text/plain; charset=utf-8');
    echo 'relay ok, na fila: ' . count(glob($spool . '/*.json') ?: []) . "\n";
    return;
}

$corpo = file_get_contents('php://input');
$json  = json_decode((string)$corpo, true);
if (!is_array($json)) {
    http_response_code(400);
    echo '{"ok":false}';
    return;
}

// Só os cabeçalhos que o site confere. O token (x-fba-token) vem configurado no
// webhook da Evolution e segue daqui exatamente como chegou.
$headers = [];
foreach (['x-fba-token' => 'HTTP_X_FBA_TOKEN', 'authorization' => 'HTTP_AUTHORIZATION', 'apikey' => 'HTTP_APIKEY'] as $nome => $chave) {
    if (!empty($_SERVER[$chave])) $headers[$nome] = (string)$_SERVER[$chave];
}

$nomeArq = sprintf('%d-%s', (int)(microtime(true) * 1000000), bin2hex(random_bytes(3)));
$tmp = $spool . '/' . $nomeArq . '.tmp';
// Grava num .tmp e renomeia: o enviar.php só enxerga arquivo completo.
file_put_contents($tmp, json_encode(['t' => microtime(true), 'h' => $headers, 'b' => $json], JSON_UNESCAPED_UNICODE));
rename($tmp, $spool . '/' . $nomeArq . '.json');

header('Content-Type: application/json');
echo '{"ok":true}';
