<?php
/**
 * Script de diagnóstico para problemas no "Meu Elenco"
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';

header('Content-Type: application/json');

// Verificar sessão
$user = getUserSession();
if (!$user) {
    echo json_encode(['error' => 'Não autenticado', 'session' => $_SESSION]);
    exit;
}

$pdo = db();

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ?');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

// Buscar todos os times (para debug)
$allTeams = $pdo->query('SELECT id, name, city, user_id, league FROM teams LIMIT 10')->fetchAll();

// Buscar jogadores do time (se existir)
$players = [];
if ($team) {
    $stmtPlayers = $pdo->prepare('SELECT id, name, position, ovr FROM players WHERE team_id = ? LIMIT 5');
    $stmtPlayers->execute([$team['id']]);
    $players = $stmtPlayers->fetchAll();
}

echo json_encode([
    'user' => $user,
    'team' => $team,
    'players_sample' => $players,
    'all_teams_sample' => $allTeams,
    'session' => [
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
    ]
], JSON_PRETTY_PRINT);
