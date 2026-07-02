<?php
$db = new PDO('sqlite:storage/saves/save_1.sqlite');
$rows = $db->query('SELECT name FROM sqlite_master WHERE type="table" ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
echo "TABELAS: " . implode(', ', $rows) . "\n\n";

// Check if 'franchises' or 'teams' table
$tbl = in_array('franchises', $rows) ? 'franchises' : (in_array('teams', $rows) ? 'teams' : null);
if ($tbl) {
    $c = $db->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
    echo "$tbl count: $c\n";
    $sample = $db->query("SELECT * FROM $tbl LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    echo "Sample cols: " . implode(', ', array_keys($sample[0] ?? [])) . "\n";
    foreach($sample as $r) echo "  " . json_encode(array_slice($r, 0, 5)) . "\n";
}

// Players
if (in_array('players', $rows)) {
    $c = $db->query("SELECT COUNT(*) FROM players")->fetchColumn();
    echo "\nplayers: $c\n";
    $pcols = $db->query('PRAGMA table_info(players)')->fetchAll(PDO::FETCH_COLUMN, 1);
    echo "cols: " . implode(', ', $pcols) . "\n";
    echo "has photo: " . (in_array('photo', $pcols) ? 'YES' : 'NO') . "\n";
}

// Draft tables
$draftTables = array_filter($rows, fn($t) => str_contains(strtolower($t), 'draft'));
echo "\nDraft tables: " . implode(', ', $draftTables) . "\n";

// Coaches
$coachTables = array_filter($rows, fn($t) => str_contains(strtolower($t), 'coach'));
echo "Coach tables: " . implode(', ', $coachTables) . "\n";

// Trades
$tradeTables = array_filter($rows, fn($t) => str_contains(strtolower($t), 'trade'));
echo "Trade tables: " . implode(', ', $tradeTables) . "\n";
