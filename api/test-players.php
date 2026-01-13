<?php
/**
 * API de teste para debug dos jogadores
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';

$output = [];

try {
    $pdo = db();
    $output['connection'] = 'OK';
    
    // Testar conexão
    $pdo->query('SELECT 1');
    $output['query_test'] = 'OK';
    
    // Contar total de jogadores
    $totalResult = $pdo->query('SELECT COUNT(*) as total FROM players');
    $totalPlayers = $totalResult->fetch();
    $output['total_players'] = $totalPlayers['total'];
    
    // Buscar jogadores do time 1 direto
    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 1;
    $output['team_id_requested'] = $teamId;
    
    $stmt = $pdo->prepare('SELECT id, name, team_id, position, ovr, role FROM players WHERE team_id = ?');
    $stmt->execute([$teamId]);
    $playersTeam = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $output['players_for_team'] = $playersTeam;
    $output['players_count'] = count($playersTeam);
    
    // Verificar distribuição por times
    $distStmt = $pdo->query('SELECT team_id, COUNT(*) as cnt FROM players GROUP BY team_id ORDER BY team_id');
    $output['distribution_by_team'] = $distStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sessão do usuário
    $user = getUserSession();
    $output['session_user'] = $user;
    
    // Info da config
    $config = loadConfig();
    $output['db_host'] = $config['db']['host'];
    $output['db_name'] = $config['db']['name'];
    
    $output['success'] = true;
    
} catch (Exception $e) {
    $output['success'] = false;
    $output['error'] = $e->getMessage();
    $output['file'] = $e->getFile();
    $output['line'] = $e->getLine();
}

echo json_encode($output, JSON_PRETTY_PRINT);
