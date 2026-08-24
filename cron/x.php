<?php
/**
 * Cron da fila do X.
 *
 * Diferente do WhatsApp, aqui o cron é o caminho PRINCIPAL e não o remendo:
 * quem fecha a trade não espera o post sair. Postar leva de meio a dois
 * segundos de HTTP com o X, e pendurar isso no fim do request de uma trade
 * é fazer o GM esperar por algo que não é da conta dele.
 *
 * Um post por rodada, de propósito. O cron roda a cada 5 minutos e é daí que
 * sai o espaçamento: uma multi-trade que gera quatro posts leva vinte minutos
 * pra sair inteira, que é como uma conta de gente se comporta — e não como o
 * antispam do X imagina um robô.
 *
 * Agendar a cada 5 minutos na Hostinger:
 *   /usr/bin/php /home/u289267434/domains/fbabrasil.com.br/public_html/cron/x.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/x_social.php';

$pdo = db();

if (!xAtivo($pdo)) {
    echo "[x-cron] desligado ou sem conta conectada — nada a fazer\n";
    exit;
}

$r    = xProcessarFila($pdo, 1);
$cota = xCota($pdo);

$linha = sprintf('[%s] x postados=%d falhas=%d%s cota=%d/%d',
    date('Y-m-d H:i:s'), $r['postados'], $r['falhas'],
    $r['motivo'] ? ' (' . $r['motivo'] . ')' : '',
    $cota['usados'], $cota['teto']);

echo $linha . "\n";

$logDir = dirname(__DIR__) . '/games/logs';
if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
    @file_put_contents($logDir . '/x-cron.log', $linha . "\n", FILE_APPEND);
}
