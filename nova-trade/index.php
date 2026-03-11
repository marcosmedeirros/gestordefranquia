<?php
require_once __DIR__ . '/../backend/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

$league = strtoupper((string)($_GET['league'] ?? ''));
$limit = (int)($_GET['limit'] ?? 10);
if ($limit <= 0) {
    $limit = 10;
}
if ($limit > 50) {
    $limit = 50;
}

$validLeagues = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
if ($league === '' || !in_array($league, $validLeagues, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'league is required (ELITE, NEXT, RISE, ROOKIE)'
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        t.id AS trade_id,
        t.league,
        t.created_at,
        t.status,
        from_team.city AS from_city,
        from_team.name AS from_name,
        to_team.city AS to_city,
        to_team.name AS to_name,
        from_user.phone AS from_user_phone
    FROM trades t
    JOIN teams from_team ON t.from_team_id = from_team.id
    JOIN teams to_team ON t.to_team_id = to_team.id
    JOIN users from_user ON from_team.user_id = from_user.id
    WHERE t.league = ?
    AND t.status = 'pending'
      AND t.created_at >= (NOW() - INTERVAL 1 HOUR)
    ORDER BY t.created_at DESC
    LIMIT ?
");
$stmt->bindValue(1, $league, PDO::PARAM_STR);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $row) {
    $out[] = [
        'trade_id' => (int)$row['trade_id'],
        'league' => $row['league'],
        'status' => $row['status'],
        'from_team' => trim($row['from_city'] . ' ' . $row['from_name']),
        'to_team' => trim($row['to_city'] . ' ' . $row['to_name']),
        'from_user_phone' => $row['from_user_phone'],
        'created_at' => $row['created_at']
    ];
}

echo json_encode([
    'success' => true,
    'count' => count($out),
    'items' => $out
]);
