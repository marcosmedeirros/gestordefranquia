<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Debug: Ver exatamente quais times estão sendo carregados
$stmt = $pdo->prepare('
    SELECT t.id, t.user_id, t.league, t.conference, t.name, t.city, u.name AS owner_name
    FROM teams t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.league = ?
    ORDER BY t.conference, t.name, t.id
');
$stmt->execute([$user['league']]);
$allTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<pre>";
echo "=== DEBUG: Times da liga {$user['league']} ===\n\n";
echo "Total de times encontrados: " . count($allTeams) . "\n\n";

foreach ($allTeams as $t) {
    echo "ID: {$t['id']} | User: {$t['user_id']} | Nome: {$t['city']} {$t['name']} | Conferência: {$t['conference']} | Owner: {$t['owner_name']}\n";
}

echo "\n=== Separação por Conferência ===\n";
$teams_by_conf = [];
foreach ($allTeams as $t) {
    $conf = $t['conference'] ?? 'Sem Conferência';
    if (!isset($teams_by_conf[$conf])) {
        $teams_by_conf[$conf] = [];
    }
    $teams_by_conf[$conf][] = $t;
}

foreach ($teams_by_conf as $conf => $teams) {
    echo "\n$conf: " . count($teams) . " times\n";
    foreach ($teams as $t) {
        echo "  - {$t['id']}: {$t['city']} {$t['name']}\n";
    }
}

echo "</pre>";
?>
