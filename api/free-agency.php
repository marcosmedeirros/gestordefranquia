<?php
/**
 * API Free Agency - Sistema de Moedas
 */

session_start();
require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Nao autorizado']);
    exit;
}

$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? false;
$team_id = $_SESSION['team_id'] ?? null;
$league_id = $_SESSION['current_league_id'] ?? null;

$pdo = db();

// GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'list':
            listFreeAgents($pdo, $league_id);
            break;
        case 'list_admin':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            listFreeAgentsAdmin($pdo);
            break;
        case 'my_bids':
            myBids($pdo, $team_id);
            break;
        case 'get_bids':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            $player_id = $_GET['player_id'] ?? 0;
            getBids($pdo, $player_id);
            break;
        case 'fa_signings_count':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            $team_ids = isset($_GET['team_ids']) ? explode(',', $_GET['team_ids']) : [];
            faSigningsCount($pdo, $team_ids);
            break;
        // Conta quantos jogadores cada time já contratou na FA
        function faSigningsCount($pdo, $team_ids) {
            $counts = [];
            if (empty($team_ids)) {
                echo json_encode(['success' => true, 'counts' => $counts]);
                return;
            }
            $in = str_repeat('?,', count($team_ids) - 1) . '?';
            $sql = "SELECT winner_team_id, COUNT(*) as total FROM free_agents WHERE winner_team_id IN ($in) AND status = 'signed' GROUP BY winner_team_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($team_ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[$row['winner_team_id']] = (int)$row['total'];
            }
            echo json_encode(['success' => true, 'counts' => $counts]);
        }
        default:
            echo json_encode(['success' => false, 'error' => 'Acao nao reconhecida']);
    }
    exit;
}

// POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? '';
    
    switch ($action) {
        case 'add_player':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            addPlayer($pdo, $body, $user_id);
            break;
        case 'remove_player':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            removePlayer($pdo, $body);
            break;
        case 'place_bid':
            placeBid($pdo, $body, $team_id);
            break;
        case 'select_winner':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            selectWinner($pdo, $body, $user_id);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Acao nao reconhecida']);
    }
    exit;
}

// ========== GET FUNCTIONS ==========

function listFreeAgents($pdo, $league_id) {
    $sql = "SELECT fa.*, l.name as league_name,
                   (SELECT MAX(amount) FROM fa_bids WHERE free_agent_id = fa.id) as max_bid
            FROM free_agents fa
            LEFT JOIN leagues l ON fa.league_id = l.id
            WHERE fa.status = 'available'";
    
    if ($league_id) {
        $sql .= " AND fa.league_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$league_id]);
    } else {
        $stmt = $pdo->query($sql);
    }
    
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'players' => $players]);
}

function listFreeAgentsAdmin($pdo) {
    $sql = "SELECT fa.*, l.name as league_name,
                   (SELECT COUNT(*) FROM fa_bids WHERE free_agent_id = fa.id) as total_bids,
                   (SELECT MAX(amount) FROM fa_bids WHERE free_agent_id = fa.id) as max_bid
            FROM free_agents fa
            LEFT JOIN leagues l ON fa.league_id = l.id
            WHERE fa.status = 'available'
            ORDER BY fa.created_at DESC";
    
    $stmt = $pdo->query($sql);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'players' => $players]);
}

function myBids($pdo, $team_id) {
    if (!$team_id) {
        echo json_encode(['success' => true, 'bids' => []]);
        return;
    }
    
    $sql = "SELECT b.*, fa.name as player_name,
                   (SELECT MAX(amount) FROM fa_bids WHERE free_agent_id = fa.id) as max_bid
            FROM fa_bids b
            JOIN free_agents fa ON b.free_agent_id = fa.id
            WHERE b.team_id = ? AND fa.status = 'available'
            ORDER BY b.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$team_id]);
    $bids = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'bids' => $bids]);
}

function getBids($pdo, $player_id) {
    $sql = "SELECT b.*, t.name as team_name
            FROM fa_bids b
            JOIN teams t ON b.team_id = t.id
            WHERE b.free_agent_id = ?
            ORDER BY b.amount DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$player_id]);
    $bids = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'bids' => $bids]);
}

// ========== POST FUNCTIONS ==========

function addPlayer($pdo, $body, $admin_id) {
    $league_id = $body['league_id'] ?? null;
    $name = $body['name'] ?? '';
    $position = $body['position'] ?? 'PG';
    $age = $body['age'] ?? 25;
    $overall = $body['overall'] ?? 70;
    $min_bid = $body['min_bid'] ?? 0;
    
    if (!$league_id || !$name) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        return;
    }
    
    $stmt = $pdo->prepare("INSERT INTO free_agents (league_id, name, position, age, overall, min_bid, status, created_by, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, 'available', ?, NOW())");
    $stmt->execute([$league_id, $name, $position, $age, $overall, $min_bid, $admin_id]);
    
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
}

function removePlayer($pdo, $body) {
    $player_id = $body['player_id'] ?? null;
    
    if (!$player_id) {
        echo json_encode(['success' => false, 'error' => 'ID nao informado']);
        return;
    }
    
    // Remover lances primeiro
    $stmt = $pdo->prepare("DELETE FROM fa_bids WHERE free_agent_id = ?");
    $stmt->execute([$player_id]);
    
    // Remover jogador
    $stmt = $pdo->prepare("DELETE FROM free_agents WHERE id = ?");
    $stmt->execute([$player_id]);
    
    echo json_encode(['success' => true]);
}

function placeBid($pdo, $body, $team_id) {
    if (!$team_id) {
        echo json_encode(['success' => false, 'error' => 'Voce precisa ter um time']);
        return;
    }
    
    $player_id = $body['player_id'] ?? null;
    $amount = (int)($body['amount'] ?? 0);
    
    if (!$player_id || $amount < 0) {
        echo json_encode(['success' => false, 'error' => 'Dados invalidos']);
        return;
    }
    
    // Verificar se jogador existe e esta disponivel
    $stmt = $pdo->prepare("SELECT * FROM free_agents WHERE id = ? AND status = 'available'");
    $stmt->execute([$player_id]);
    $player = $stmt->fetch();
    
    if (!$player) {
        echo json_encode(['success' => false, 'error' => 'Jogador nao encontrado']);
        return;
    }
    
    if ($amount < $player['min_bid']) {
        echo json_encode(['success' => false, 'error' => 'Lance abaixo do minimo']);
        return;
    }
    
    // Verificar moedas do time
    $stmt = $pdo->prepare("SELECT moedas FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch();
    
    if (!$team || $team['moedas'] < $amount) {
        echo json_encode(['success' => false, 'error' => 'Moedas insuficientes']);
        return;
    }
    
    // Verificar se ja tem lance
    $stmt = $pdo->prepare("SELECT id FROM fa_bids WHERE free_agent_id = ? AND team_id = ?");
    $stmt->execute([$player_id, $team_id]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Atualizar lance existente
        $stmt = $pdo->prepare("UPDATE fa_bids SET amount = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$amount, $existing['id']]);
    } else {
        // Criar novo lance
        $stmt = $pdo->prepare("INSERT INTO fa_bids (free_agent_id, team_id, amount, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$player_id, $team_id, $amount]);
    }
    
    echo json_encode(['success' => true]);
}

function selectWinner($pdo, $body, $admin_id) {
    $player_id = $body['player_id'] ?? null;
    $team_id = $body['team_id'] ?? null;
    $amount = (int)($body['amount'] ?? 0);
    
    if (!$player_id || !$team_id) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        return;
    }
    
    // Verificar se jogador existe
    $stmt = $pdo->prepare("SELECT * FROM free_agents WHERE id = ? AND status = 'available'");
    $stmt->execute([$player_id]);
    $player = $stmt->fetch();
    
    if (!$player) {
        echo json_encode(['success' => false, 'error' => 'Jogador nao encontrado']);
        return;
    }
    
    // Verificar moedas do time vencedor
    $stmt = $pdo->prepare("SELECT moedas FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch();

    if (!$team || $team['moedas'] < $amount) {
        echo json_encode(['success' => false, 'error' => 'Time nao tem moedas suficientes']);
        return;
    }

    // Verificar limite de 3 contratações FA
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM free_agents WHERE winner_team_id = ? AND status = 'signed'");
    $stmt->execute([$team_id]);
    $signedCount = $stmt->fetchColumn();
    if ($signedCount >= 3) {
        echo json_encode(['success' => false, 'error' => 'Este time já contratou 3 jogadores na Free Agency.']);
        return;
    }

    $pdo->beginTransaction();

    try {
        // Criar jogador na tabela players
        $stmt = $pdo->prepare("INSERT INTO players (name, position, age, overall, team_id, league_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$player['name'], $player['position'], $player['age'], $player['overall'], $team_id, $player['league_id']]);

        // Descontar moedas
        $stmt = $pdo->prepare("UPDATE teams SET moedas = moedas - ? WHERE id = ?");
        $stmt->execute([$amount, $team_id]);

        // Registrar log de moedas
        $stmt = $pdo->prepare("INSERT INTO team_coins_log (team_id, amount, reason, admin_id, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$team_id, -$amount, 'Contratacao FA: ' . $player['name'], $admin_id]);

        // Marcar free agent como contratado
        $stmt = $pdo->prepare("UPDATE free_agents SET status = 'signed', winner_team_id = ? WHERE id = ?");
        $stmt->execute([$team_id, $player_id]);

        // Remover lances
        $stmt = $pdo->prepare("DELETE FROM fa_bids WHERE free_agent_id = ?");
        $stmt->execute([$player_id]);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro: ' . $e->getMessage()]);
    }
}
