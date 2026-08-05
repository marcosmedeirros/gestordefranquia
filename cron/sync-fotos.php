<?php
/**
 * Cron de sincronização de fotos (NBA).
 *
 * Preenche nba_player_id/nba_id de jogadores recém-cadastrados (draft, free
 * agency, cadastro direto) sem precisar de ninguém visitar sync_fotos.php ou
 * clicar no botão em Gestão. Roda pouco: o cadastro da NBA não muda de hora
 * em hora, uma vez por dia já cobre folgado.
 *
 * Agendar uma vez por dia na Hostinger, por exemplo às 6h:
 *   0 6 * * *  /usr/bin/php /home/u289267434/domains/fbabrasil.com.br/public_html/cron/sync-fotos.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/nba_sync.php';

date_default_timezone_set('America/Sao_Paulo');

$pdo = db();
$r = syncNbaPlayerPhotos($pdo);

$linha = $r['ok']
    ? sprintf('[%s] sync-fotos atualizados=%d verificados=%d sem_correspondencia=%d',
        date('Y-m-d H:i:s'), $r['atualizados'], $r['total_verificados'], count($r['sem_correspondencia']))
    : sprintf('[%s] sync-fotos ERRO: %s', date('Y-m-d H:i:s'), $r['erro']);

echo $linha . "\n";

$logDir = dirname(__DIR__) . '/games/logs';
if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
    @file_put_contents($logDir . '/sync-fotos-cron.log', $linha . "\n", FILE_APPEND);
}
