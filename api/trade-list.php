<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$pdo = db();

// Detect league from user's team
// Sempre usar a liga do time do usuário (evitar misturar ligas)
$stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
$league = $team['league'] ?? null;

if (!$league) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Liga do usuário não encontrada']);
    exit;
}

$q = trim($_GET['q'] ?? '');
$sort = strtolower($_GET['sort'] ?? 'ovr');
$dir = strtolower($_GET['dir'] ?? 'desc');

$allowedSort = [
    'name' => 'p.name',
    'ovr' => 'p.ovr',
    'age' => 'p.age',
    'position' => 'p.position',
    'team' => 't.name'
];
$orderBy = $allowedSort[$sort] ?? 'p.ovr';
$orderDir = $dir === 'asc' ? 'ASC' : 'DESC';

$where = ['p.available_for_trade = 1', 't.league = ?'];
$params = [$league];

if ($q !== '') {
    $where[] = 'p.name LIKE ?';
    $params[] = '%' . $q . '%';
}

$sql = "
    SELECT 
        p.id, p.name, p.age, p.position, p.secondary_position, p.role, p.ovr,
        t.id AS team_id, CONCAT(t.city, ' ', t.name) AS team_name, t.league,
        COALESCE(t.photo_url, '') AS team_logo
    FROM players p
    JOIN teams t ON p.team_id = t.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY $orderBy $orderDir, p.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$players = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'league' => $league,
    'count' => count($players),
    'players' => $players
]);
