<?php
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, ['error' => 'Method not allowed']);
}

$teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : null;
$sql = 'SELECT p.id, p.team_id, p.original_team_id, p.season_year, p.round, p.notes, t.name AS team_name
        FROM picks p
        LEFT JOIN teams t ON t.id = p.original_team_id';
$params = [];
if ($teamId) {
    $sql .= ' WHERE p.team_id = ?';
    $params[] = $teamId;
}
$sql .= ' ORDER BY p.season_year DESC, p.round ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$picks = $stmt->fetchAll();

jsonResponse(200, ['picks' => $picks]);
