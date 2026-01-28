<?php
// Atualiza todos os jogadores sem nba_player_id buscando pelo nome
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/nba_lookup.php';

if (php_sapi_name() !== 'cli') {
    echo "Execute este script via CLI: php migrate-sync-nba-player-ids.php\n";
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$pdo = db();

try {
    $stmt = $pdo->query("SELECT id, name FROM players WHERE nba_player_id IS NULL OR nba_player_id = '' ORDER BY id ASC");
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    fwrite(STDERR, "Erro ao carregar jogadores: {$e->getMessage()}\n");
    exit(1);
}

$total = count($players);
if ($total === 0) {
    echo "Todos os jogadores já possuem nba_player_id.\n";
    exit(0);
}

echo "Encontrados {$total} jogadores sem NBA ID. Iniciando sincronização" . ($dryRun ? ' (dry-run)' : '') . "...\n";

$updated = 0;
$skipped = 0;
$failures = [];

foreach ($players as $player) {
    $name = $player['name'];
    echo "[{$player['id']}] Buscando ID para {$name}... ";
    $result = fetchNbaPlayerIdByName($name);
    if (!$result) {
        echo "não encontrado.\n";
        $skipped++;
        $failures[] = [$player['id'], $name];
        continue;
    }

    echo "encontrado #{$result['id']} ({$result['name']})";
    if ($dryRun) {
        echo " (dry-run)\n";
        $updated++;
        continue;
    }

    try {
        $upd = $pdo->prepare('UPDATE players SET nba_player_id = ? WHERE id = ?');
        $upd->execute([$result['id'], $player['id']]);
        echo " ✔\n";
        $updated++;
    } catch (Exception $e) {
        echo " erro ao salvar: {$e->getMessage()}\n";
        $failures[] = [$player['id'], $name];
    }
}

echo "\nResumo:\n";
printf(" - Atualizados: %d\n", $updated);
printf(" - Não encontrados: %d\n", $skipped);

if (!empty($failures)) {
    echo "Lista de pendências:\n";
    foreach ($failures as [$id, $name]) {
        printf("   • [%d] %s\n", $id, $name);
    }
}

exit(empty($failures) ? 0 : 2);
