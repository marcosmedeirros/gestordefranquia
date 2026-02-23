<?php
require_once __DIR__ . '/backend/db.php';

$pdo = db();

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function ensureSnapshotColumns(PDO $pdo, string $table): void
{
    if (!tableExists($pdo, $table)) {
        return;
    }

    if (!columnExists($pdo, $table, 'player_name')) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN player_name VARCHAR(255) NULL AFTER player_id");
    }
    if (!columnExists($pdo, $table, 'player_position')) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN player_position VARCHAR(10) NULL AFTER player_name");
    }
    if (!columnExists($pdo, $table, 'player_age')) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN player_age INT NULL AFTER player_position");
    }
    if (!columnExists($pdo, $table, 'player_ovr')) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN player_ovr INT NULL AFTER player_age");
    }
}

function resolveOvrColumn(PDO $pdo): string
{
    return columnExists($pdo, 'players', 'ovr')
        ? 'ovr'
        : (columnExists($pdo, 'players', 'overall') ? 'overall' : 'ovr');
}

function backfillSnapshots(PDO $pdo, string $table, string $ovrCol): array
{
    if (!tableExists($pdo, $table)) {
        return ['updated' => 0, 'missing' => 0];
    }

    $stmtSelect = $pdo->prepare(
        "SELECT id, player_id
         FROM {$table}
         WHERE player_id IS NOT NULL
           AND (player_name IS NULL OR player_name = '')"
    );
    $stmtSelect->execute();
    $rows = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return ['updated' => 0, 'missing' => 0];
    }

    $stmtPlayer = $pdo->prepare("SELECT name, position, age, {$ovrCol} AS ovr FROM players WHERE id = ?");
    $stmtUpdate = $pdo->prepare(
        "UPDATE {$table}
         SET player_name = ?, player_position = ?, player_age = ?, player_ovr = ?
         WHERE id = ?"
    );

    $updated = 0;
    $missing = 0;

    foreach ($rows as $row) {
        $playerId = (int)$row['player_id'];
        if ($playerId <= 0) {
            continue;
        }

        $stmtPlayer->execute([$playerId]);
        $player = $stmtPlayer->fetch(PDO::FETCH_ASSOC);
        if (!$player) {
            $missing++;
            continue;
        }

        $stmtUpdate->execute([
            $player['name'] ?? null,
            $player['position'] ?? null,
            isset($player['age']) ? (int)$player['age'] : null,
            isset($player['ovr']) ? (int)$player['ovr'] : null,
            (int)$row['id']
        ]);
        $updated++;
    }

    return ['updated' => $updated, 'missing' => $missing];
}

try {
    ensureSnapshotColumns($pdo, 'trade_items');
    ensureSnapshotColumns($pdo, 'multi_trade_items');

    $ovrCol = resolveOvrColumn($pdo);

    $tradeStats = backfillSnapshots($pdo, 'trade_items', $ovrCol);
    $multiStats = backfillSnapshots($pdo, 'multi_trade_items', $ovrCol);

    echo "Trade items atualizados: {$tradeStats['updated']}\n";
    echo "Trade items sem jogador na tabela: {$tradeStats['missing']}\n";
    echo "Multi-trade items atualizados: {$multiStats['updated']}\n";
    echo "Multi-trade items sem jogador na tabela: {$multiStats['missing']}\n";
} catch (Exception $e) {
    echo 'Erro ao atualizar snapshots: ' . $e->getMessage() . "\n";
    exit(1);
}
