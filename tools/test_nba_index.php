<?php
require __DIR__ . '/../backend/nba_lookup.php';
$players = loadNbaPlayersIndex(true);
echo 'count=' . count($players) . "\n";
for ($i = 0; $i < min(5, count($players)); $i++) {
    $p = $players[$i];
    echo ($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? '') . ' (#' . ($p['personId'] ?? '') . ")\n";
}
