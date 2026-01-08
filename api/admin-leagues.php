<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';

$user = getUserSession();
if (!$user || ($user['user_type'] ?? 'jogador') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Listar ligas com configurações e times
    $stmtLeagues = $pdo->query("SELECT name FROM leagues ORDER BY FIELD(name,'ELITE','NEXT','RISE','ROOKIE')");
    $leagues = $stmtLeagues->fetchAll(PDO::FETCH_COLUMN);

    $result = [];
    foreach ($leagues as $league) {
        // Config da liga
        $stmtCfg = $pdo->prepare('SELECT cap_min, cap_max FROM league_settings WHERE league = ?');
        $stmtCfg->execute([$league]);
        $cfg = $stmtCfg->fetch() ?: ['cap_min' => 0, 'cap_max' => 0];

        // Times da liga
        $stmtTeams = $pdo->prepare('SELECT id, city, name FROM teams WHERE league = ? ORDER BY city, name');
        $stmtTeams->execute([$league]);
        $teams = [];
        while ($t = $stmtTeams->fetch(PDO::FETCH_ASSOC)) {
            $teams[] = [
                'id' => (int)$t['id'],
                'city' => $t['city'],
                'name' => $t['name'],
                'cap_top8' => topEightCap($pdo, (int)$t['id']),
                'total_players' => (int)($pdo->query("SELECT COUNT(*) FROM players WHERE team_id = " . (int)$t['id'])->fetchColumn())
            ];
        }

        $result[] = [
            'league' => $league,
            'cap_min' => (int)$cfg['cap_min'],
            'cap_max' => (int)$cfg['cap_max'],
            'teams' => $teams,
        ];
    }

    echo json_encode(['success' => true, 'leagues' => $result]);
    exit;
}

if ($method === 'PUT') {
    // Atualizar cap da liga
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    $league = $data['league'] ?? null;
    $cap_min = isset($data['cap_min']) ? (int)$data['cap_min'] : null;
    $cap_max = isset($data['cap_max']) ? (int)$data['cap_max'] : null;

    if (!$league || $cap_min === null || $cap_max === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO league_settings (league, cap_min, cap_max) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cap_min=VALUES(cap_min), cap_max=VALUES(cap_max)');
    $stmt->execute([$league, $cap_min, $cap_max]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido']);
