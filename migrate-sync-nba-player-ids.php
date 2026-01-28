<?php
/**
 * Atualiza todos os jogadores sem nba_player_id buscando pelo nome.
 * CLI: php migrate-sync-nba-player-ids.php [--dry-run]
 * Web: https://fbabrasil.com.br/migrate-sync-nba-player-ids.php?dry_run=1 (logado como admin)
 */

require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/nba_lookup.php';

$isCli = php_sapi_name() === 'cli';
$dryRun = false;
$outputBuffer = [];

if ($isCli) {
    $dryRun = in_array('--dry-run', $argv ?? [], true);
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
    $stmt = $pdo->query("SELECT id, name FROM players WHERE nba_player_id IS NULL OR nba_player_id = '' ORDER BY id ASC");
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

$write("Encontrados {$total} jogadores sem NBA ID. Iniciando sincronização" . ($dryRun ? ' (dry-run)' : '') . "...\n");

$updated = 0;
$skipped = 0;
$failures = [];

foreach ($players as $player) {
    $name = $player['name'];
    $write("[{$player['id']}] Buscando ID para {$name}... ");
    $result = fetchNbaPlayerIdByName($name);
    if (!$result) {
        $write("não encontrado.\n");
        $skipped++;
        $failures[] = [$player['id'], $name];
        continue;
    }

    $write("encontrado #{$result['id']} ({$result['name']})");
    if ($dryRun) {
        $write(" (dry-run)\n");
        $updated++;
        continue;
    }

    try {
        $upd = $pdo->prepare('UPDATE players SET nba_player_id = ? WHERE id = ?');
        $upd->execute([$result['id'], $player['id']]);
        $write(" ✔\n");
        $updated++;
    } catch (Exception $e) {
        $write(" erro ao salvar: {$e->getMessage()}\n");
        $failures[] = [$player['id'], $name];
    }
}

$write("\nResumo:\n");
$write(sprintf(" - Atualizados: %d\n", $updated));
$write(sprintf(" - Não encontrados: %d\n", $skipped));

if (!empty($failures)) {
    $write("Lista de pendências:\n");
    foreach ($failures as [$id, $name]) {
        $write(sprintf("   • [%d] %s\n", $id, $name));
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
