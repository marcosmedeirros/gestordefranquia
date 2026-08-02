<?php
/**
 * Cron das Dispensas (waiver de 12h).
 *
 * Fecha os waivers cuja janela venceu: entrega o jogador a quem deu o maior
 * lance (maior espaço no cap no momento do lance; empate vai pro lance mais
 * antigo) ou, sem nenhum lance, manda ele pra Free Agency.
 *
 * Sem este cron, a resolução só acontecia quando alguém abria a aba Dispensas
 * — um waiver que vencesse de madrugada ficava parado até o primeiro acesso.
 *
 * Agendar de hora em hora na Hostinger:
 *   0 * * * *  /usr/bin/php /home/u289267434/domains/fbabrasil.com.br/public_html/cron/waivers.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/waivers.php';

date_default_timezone_set('America/Sao_Paulo');

$pdo = db();
ensureWaiverTables($pdo);

$r = resolveExpiredWaivers($pdo);

$linha = sprintf(
    "[%s] waivers resolvidos=%d (reivindicados=%d, liberados pra free agency=%d)\n",
    date('Y-m-d H:i:s'),
    $r['resolved'] ?? 0,
    $r['claimed'] ?? 0,
    $r['cleared'] ?? 0
);

echo $linha;

// Log próprio, no mesmo lugar dos outros crons do projeto.
$dir = __DIR__ . '/../games/logs';
if (is_dir($dir)) {
    @file_put_contents($dir . '/waivers-cron.log', $linha, FILE_APPEND | LOCK_EX);
}
