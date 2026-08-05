<?php
/**
 * sync-hoopgrid-status.php
 * Sincroniza status ativo/inativo e time atual de hoopgrid_players contra a
 * temporada corrente da NBA — sem isso, jogador trocado de time ou aposentado
 * fica com dado velho até alguém abrir dadosjogadores.php e clicar no botão
 * manual. Usado pelo BoxNBA, Hoopgrid e outros jogos que leem essa tabela.
 *
 * Roda pouco: elenco da NBA não muda de hora em hora, uma vez por dia cobre.
 *
 * Crontab sugerido (uma vez por dia, 7h BRT = 10h UTC):
 *   0 10 * * *  /usr/local/bin/php /home/.../games/cron/sync-hoopgrid-status.php
 *
 * Via URL:
 *   https://games.fbabrasil.com.br/cron/sync-hoopgrid-status.php?key=SEU_CRON_SECRET
 */

$cron_secret = (string) (getenv('FBA_GAMES_CRON_SECRET') ?: '');

$via_cli  = (PHP_SAPI === 'cli');
$via_http = $cron_secret !== ''
    && isset($_GET['key'])
    && hash_equals($cron_secret, (string) $_GET['key']);

if (!$via_cli && !$via_http) { http_response_code(403); exit('Acesso negado.'); }

date_default_timezone_set('America/Sao_Paulo');
$log_file = __DIR__ . '/../logs/sync-hoopgrid-status.log';

function logHS(string $msg): void {
    global $log_file;
    $linha = '[' . date('Y-m-d H:i:s') . "] {$msg}" . PHP_EOL;
    @file_put_contents($log_file, $linha, FILE_APPEND | LOCK_EX);
    if (PHP_SAPI === 'cli') echo $linha;
}

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../core/hoopgrid_sync.php';

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    logHS('ERRO conexão: ' . $e->getMessage());
    exit(1);
}

$r = syncHoopgridPlayerStatus($pdo);

if ($r['ok']) {
    logHS(sprintf(
        'sync-hoopgrid-status OK temporada=%s encontrados=%d ativados=%d inativados=%d',
        $r['season'], $r['encontrados'], $r['ativados'], $r['inativados']
    ));
} else {
    logHS('sync-hoopgrid-status ERRO: ' . $r['error']);
}
