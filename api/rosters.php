<?php
session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, ['error' => 'Method not allowed']);
}

$pdo = db();

// Obter league do usuário da sessão
$user = getUserSession();
$league = $user['league'] ?? 'ROOKIE';

$sql = 'SELECT t.id, t.name, t.city, t.mascot, t.photo_url AS team_photo, t.league, t.division_id,
               d.name AS division_name,
               u.id AS user_id, u.name AS user_name, u.photo_url AS user_photo
        FROM teams t
        LEFT JOIN divisions d ON d.id = t.division_id
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.league = ?
        ORDER BY t.id DESC';

$teamsStmt = $pdo->prepare($sql);
$teamsStmt->execute([$league]);
$teams = $teamsStmt->fetchAll();

$playerStmt = $pdo->prepare('SELECT id, team_id, name, age, position, role, ovr, available_for_trade FROM players WHERE team_id = ? ORDER BY ovr DESC, id DESC');
$pickStmt = $pdo->prepare('SELECT id, season_year, round, notes, original_team_id FROM picks WHERE team_id = ? ORDER BY season_year DESC, round ASC');

foreach ($teams as &$team) {
    $teamId = (int) $team['id'];
    $team['cap_top8'] = topEightCap($pdo, $teamId);

    $playerStmt->execute([$teamId]);
    $team['players'] = $playerStmt->fetchAll();

    $pickStmt->execute([$teamId]);
    $team['picks'] = $pickStmt->fetchAll();
}

jsonResponse(200, ['teams' => $teams]);
