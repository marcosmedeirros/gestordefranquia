<?php
/**
 * Migração via WEB (admin-only) e CLI para preencher players.nba_player_id usando balldontlie.
 *
 * Web:
 *  /migrate-sync-nba-player-ids-balldontlie.php?dry_run=1&limit=50&offset=0&sleep_ms=300
 *
 * CLI:
 *  php migrate-sync-nba-player-ids-balldontlie.php --dry-run --limit=50 --offset=0 --sleep-ms=300
 */

require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/balldontlie.php';

$isCli = php_sapi_name() === 'cli';
$outputBuffer = [];
set_time_limit(180);

$dryRun = false;
$limit = null;
$offset = 0;
$sleepMs = 350;

if ($isCli) {
    $argvList = $argv ?? [];
    $dryRun = in_array('--dry-run', $argvList, true);

    $limitArg = array_values(array_filter($argvList, function ($a) { return substr($a, 0, 8) === '--limit='; }))[0] ?? null;
    $offsetArg = array_values(array_filter($argvList, function ($a) { return substr($a, 0, 9) === '--offset='; }))[0] ?? null;
    $sleepArg = array_values(array_filter($argvList, function ($a) { return substr($a, 0, 11) === '--sleep-ms='; }))[0] ?? null;

    $limit = $limitArg ? (int)substr($limitArg, 8) : null;
    $offset = $offsetArg ? (int)substr($offsetArg, 9) : 0;
    $sleepMs = $sleepArg ? (int)substr($sleepArg, 11) : $sleepMs;
} else {
    require_once __DIR__ . '/backend/auth.php';
    requireAuth();
    $session = getUserSession();
    if (($session['user_type'] ?? 'jogador') !== 'admin') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Acesso restrito. Somente administradores podem executar esta migração.";
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');

    $dryRun = isset($_GET['dry_run']);
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $sleepMs = isset($_GET['sleep_ms']) ? (int)$_GET['sleep_ms'] : $sleepMs;
}

$write = function (string $message) use ($isCli, &$outputBuffer): void {
    if ($isCli) {
        echo $message;
    } else {
        $outputBuffer[] = $message;
    }
};

$pdo = db();

try {
    $sql = "SELECT id, name FROM players WHERE nba_player_id IS NULL OR nba_player_id = '' ORDER BY id ASC";
    if ($limit !== null) {
        $sql .= " LIMIT " . max(1, (int)$limit) . " OFFSET " . max(0, (int)$offset);
    }

    $stmt = $pdo->query($sql);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $write("Erro ao carregar jogadores: {$e->getMessage()}\n");
    finish($isCli, $outputBuffer, 1);
}

$total = count($players);
if ($total === 0) {
    $write("Todos os jogadores já possuem nba_player_id.\n");
    finish($isCli, $outputBuffer, 0);
}

$write("Encontrados {$total} jogadores sem NBA ID (balldontlie). Iniciando sincronização" . ($dryRun ? ' (dry-run)' : '') . "...\n");
$write("Config: limit=" . ($limit ?? 'ALL') . " offset={$offset} sleep_ms={$sleepMs}\n\n");

$updated = 0;
$skipped = 0;
$failures = [];

foreach ($players as $player) {
    $name = trim($player['name'] ?? '');
    $id = (int)($player['id'] ?? 0);

    if ($name === '' || !$id) {
        $skipped++;
        continue;
    }

    $write("[{$id}] Buscando ID para {$name}... ");

    $result = balldontlieFetchPlayerIdByName($name);
    if (!$result) {
        $write("não encontrado.\n");
        $skipped++;
        $failures[] = [$id, $name];
        balldontlieSleepMs($sleepMs);
        continue;
    }

    $write("encontrado #{$result['id']} ({$result['name']})");

    if ($dryRun) {
        $write(" (dry-run)\n");
        $updated++;
        balldontlieSleepMs($sleepMs);
        continue;
    }

    try {
        $upd = $pdo->prepare('UPDATE players SET nba_player_id = ? WHERE id = ?');
        $upd->execute([$result['id'], $id]);
        $write(" ✔\n");
        $updated++;
    } catch (Exception $e) {
        $write(" erro ao salvar: {$e->getMessage()}\n");
        $failures[] = [$id, $name];
    }

    balldontlieSleepMs($sleepMs);
}

$write("\nResumo:\n");
$write(sprintf(" - Atualizados: %d\n", $updated));
$write(sprintf(" - Não encontrados: %d\n", $skipped));

if (!empty($failures)) {
    $write("Lista de pendências:\n");
    foreach ($failures as [$pid, $pname]) {
        $write(sprintf("   • [%d] %s\n", $pid, $pname));
    }
}

finish($isCli, $outputBuffer, empty($failures) ? 0 : 2);

function finish(bool $isCli, array $buffer, int $exitCode): void
{
    if ($isCli) {
        exit($exitCode);
    }

    http_response_code($exitCode === 0 ? 200 : 207);
    echo implode('', $buffer);
    exit;
}
