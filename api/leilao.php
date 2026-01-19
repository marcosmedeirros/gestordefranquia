<?php
/**
 * API do Leilao de Jogadores
 * Sistema de trocas via leilao
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
$is_admin = $_SESSION['is_admin'] ?? (($_SESSION['user_type'] ?? '') === 'admin');
$team_id = $_SESSION['team_id'] ?? null;
$league_id = $_SESSION['current_league_id'] ?? null;

$pdo = db();

function playerOvrColumn(PDO $pdo): string
{
    $stmt = $pdo->query("SHOW COLUMNS FROM players LIKE 'ovr'");
    return $stmt && $stmt->rowCount() > 0 ? 'ovr' : 'overall';
}

// GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'listar_ativos':
            listarLeiloesAtivos($pdo, $league_id);
            break;
        case 'listar_admin':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            listarLeiloesAdmin($pdo);
            break;
        case 'minhas_propostas':
            minhasPropostas($pdo, $team_id);
            break;
        case 'propostas_recebidas':
            propostasRecebidas($pdo, $team_id);
            break;
        case 'ver_propostas':
            $leilao_id = $_GET['leilao_id'] ?? 0;
            verPropostas($pdo, $leilao_id, $team_id, $is_admin);
            break;
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
        case 'cadastrar':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            cadastrarLeilao($pdo, $body);
            break;
        case 'cancelar':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            cancelarLeilao($pdo, $body);
            break;
        case 'enviar_proposta':
            enviarProposta($pdo, $body, $team_id);
            break;
        case 'aceitar_proposta':
            aceitarProposta($pdo, $body, $team_id, $is_admin);
            break;
        case 'recusar_proposta':
            recusarProposta($pdo, $body, $team_id, $is_admin);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Acao nao reconhecida']);
    }
    exit;
}

// ========== FUNCOES GET ==========

function listarLeiloesAtivos($pdo, $league_id) {
    $ovrColumn = playerOvrColumn($pdo);
    $sql = "SELECT l.*, 
                   p.name as player_name, p.position, p.age, p.{$ovrColumn} as ovr,
                   t.name as team_name,
                   lg.name as league_name,
                   (SELECT COUNT(*) FROM leilao_propostas WHERE leilao_id = l.id) as total_propostas
            FROM leilao_jogadores l
            JOIN players p ON l.player_id = p.id
            JOIN teams t ON l.team_id = t.id
            JOIN leagues lg ON l.league_id = lg.id
            WHERE l.status = 'ativo' AND (l.data_fim IS NULL OR l.data_fim > NOW())";
    
    if ($league_id) {
        $sql .= " AND l.league_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$league_id]);
    } else {
        $stmt = $pdo->query($sql);
    }
    
    $leiloes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'leiloes' => $leiloes]);
}

function listarLeiloesAdmin($pdo) {
    $ovrColumn = playerOvrColumn($pdo);
    $sql = "SELECT l.*, 
                   p.name as player_name, p.position, p.age, p.{$ovrColumn} as ovr,
                   t.name as team_name,
                   lg.name as league_name,
                   (SELECT COUNT(*) FROM leilao_propostas WHERE leilao_id = l.id) as total_propostas
            FROM leilao_jogadores l
            JOIN players p ON l.player_id = p.id
            JOIN teams t ON l.team_id = t.id
            JOIN leagues lg ON l.league_id = lg.id
            ORDER BY l.created_at DESC";
    
    $stmt = $pdo->query($sql);
    $leiloes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'leiloes' => $leiloes]);
}

function minhasPropostas($pdo, $team_id) {
    if (!$team_id) {
        echo json_encode(['success' => true, 'propostas' => []]);
        return;
    }
    
    $sql = "SELECT lp.*, 
                   l.player_id,
                   p.name as player_name,
                   t.name as team_name,
                   GROUP_CONCAT(po.name SEPARATOR ', ') as jogadores_oferecidos
            FROM leilao_propostas lp
            JOIN leilao_jogadores l ON lp.leilao_id = l.id
            JOIN players p ON l.player_id = p.id
            JOIN teams t ON l.team_id = t.id
            LEFT JOIN leilao_proposta_jogadores lpj ON lp.id = lpj.proposta_id
            LEFT JOIN players po ON lpj.player_id = po.id
            WHERE lp.team_id = ?
            GROUP BY lp.id
            ORDER BY lp.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$team_id]);
    $propostas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'propostas' => $propostas]);
}

function propostasRecebidas($pdo, $team_id) {
    if (!$team_id) {
        echo json_encode(['success' => true, 'leiloes' => []]);
        return;
    }
    
    // Buscar leiloes dos meus jogadores que tem propostas
    $ovrColumn = playerOvrColumn($pdo);
    $sql = "SELECT l.*, 
                   p.name as player_name, p.position, p.{$ovrColumn} as ovr,
                   (SELECT COUNT(*) FROM leilao_propostas WHERE leilao_id = l.id) as total_propostas
            FROM leilao_jogadores l
            JOIN players p ON l.player_id = p.id
            WHERE l.team_id = ? AND l.status = 'ativo'
            HAVING total_propostas > 0";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$team_id]);
    $leiloes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'leiloes' => $leiloes]);
}

function verPropostas($pdo, $leilao_id, $team_id, $is_admin) {
    // Verificar se e dono do jogador ou admin
    $stmt = $pdo->prepare("SELECT team_id FROM leilao_jogadores WHERE id = ?");
    $stmt->execute([$leilao_id]);
    $leilao = $stmt->fetch();
    
    if (!$leilao || (!$is_admin && $leilao['team_id'] != $team_id)) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }
    
    $sql = "SELECT lp.*, t.name as team_name
            FROM leilao_propostas lp
            JOIN teams t ON lp.team_id = t.id
            WHERE lp.leilao_id = ?
            ORDER BY lp.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$leilao_id]);
    $propostas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Para cada proposta, buscar os jogadores oferecidos
    foreach ($propostas as &$proposta) {
        $stmt2 = $pdo->prepare("SELECT p.* FROM players p 
                                JOIN leilao_proposta_jogadores lpj ON p.id = lpj.player_id 
                                WHERE lpj.proposta_id = ?");
        $stmt2->execute([$proposta['id']]);
        $proposta['jogadores'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode(['success' => true, 'propostas' => $propostas]);
}

// ========== FUNCOES POST ==========

function cadastrarLeilao($pdo, $body) {
    $player_id = $body['player_id'] ?? null;
    $team_id = $body['team_id'] ?? null;
    $league_id = $body['league_id'] ?? null;
    $data_inicio = $body['data_inicio'] ?? null;
    $data_fim = $body['data_fim'] ?? null;
    $new_player = $body['new_player'] ?? null;
    
    if ((!$player_id && !$new_player) || !$team_id || !$league_id) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        return;
    }

    if ($new_player) {
        $name = trim((string)($new_player['name'] ?? ''));
        $position = trim((string)($new_player['position'] ?? ''));
        $age = (int)($new_player['age'] ?? 0);
        $ovr = (int)($new_player['ovr'] ?? 0);
        if (!$name || !$position || !$age || !$ovr) {
            echo json_encode(['success' => false, 'error' => 'Dados do novo jogador incompletos']);
            return;
        }
        $ovrColumn = playerOvrColumn($pdo);
        $stmt = $pdo->prepare("INSERT INTO players (team_id, name, age, position, {$ovrColumn}) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$team_id, $name, $age, $position, $ovr]);
        $player_id = $pdo->lastInsertId();
    }
    
    // Verificar se jogador ja esta em leilao ativo
    $stmt = $pdo->prepare("SELECT id FROM leilao_jogadores WHERE player_id = ? AND status = 'ativo'");
    $stmt->execute([$player_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Jogador ja esta em leilao ativo']);
        return;
    }
    
    $data_inicio = $data_inicio ?: date('Y-m-d H:i:s');
    $data_fim = $data_fim ?: date('Y-m-d H:i:s', time() + (20 * 60));
    $stmt = $pdo->prepare("INSERT INTO leilao_jogadores (player_id, team_id, league_id, data_inicio, data_fim, status, created_at) 
                           VALUES (?, ?, ?, ?, ?, 'ativo', NOW())");
    $stmt->execute([$player_id, $team_id, $league_id, $data_inicio, $data_fim]);
    
    echo json_encode(['success' => true, 'leilao_id' => $pdo->lastInsertId()]);
}

function cancelarLeilao($pdo, $body) {
    $leilao_id = $body['leilao_id'] ?? null;
    
    if (!$leilao_id) {
        echo json_encode(['success' => false, 'error' => 'ID do leilao nao informado']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE leilao_jogadores SET status = 'cancelado' WHERE id = ?");
    $stmt->execute([$leilao_id]);
    
    // Atualizar todas as propostas para recusadas
    $stmt = $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE leilao_id = ?");
    $stmt->execute([$leilao_id]);
    
    echo json_encode(['success' => true]);
}

function enviarProposta($pdo, $body, $team_id) {
    if (!$team_id) {
        echo json_encode(['success' => false, 'error' => 'Voce precisa ter um time para enviar propostas']);
        return;
    }
    
    $leilao_id = $body['leilao_id'] ?? null;
    $player_ids = $body['player_ids'] ?? [];
    $notas = $body['notas'] ?? '';
    
    if (!$leilao_id || (empty($player_ids) && trim($notas) === '')) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        return;
    }
    
    // Verificar se leilao existe e esta ativo
    $stmt = $pdo->prepare("SELECT * FROM leilao_jogadores WHERE id = ? AND status = 'ativo'");
    $stmt->execute([$leilao_id]);
    $leilao = $stmt->fetch();
    
    if (!$leilao) {
        echo json_encode(['success' => false, 'error' => 'Leilao nao encontrado ou nao esta ativo']);
        return;
    }

    if (!empty($leilao['data_fim']) && strtotime($leilao['data_fim']) <= time()) {
        echo json_encode(['success' => false, 'error' => 'Leilao encerrado']);
        return;
    }
    
    // Nao pode enviar proposta para proprio jogador
    if ($leilao['team_id'] == $team_id) {
        echo json_encode(['success' => false, 'error' => 'Voce nao pode enviar proposta para seu proprio jogador']);
        return;
    }
    
    // Verificar se ja enviou proposta para este leilao
    $stmt = $pdo->prepare("SELECT id FROM leilao_propostas WHERE leilao_id = ? AND team_id = ? AND status = 'pendente'");
    $stmt->execute([$leilao_id, $team_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Voce ja tem uma proposta pendente para este leilao']);
        return;
    }
    
    if (!empty($player_ids)) {
        $placeholders = implode(',', array_fill(0, count($player_ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM players WHERE id IN ($placeholders) AND team_id = ?");
        $params = array_merge($player_ids, [$team_id]);
        $stmt->execute($params);
        $jogadores_validos = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($jogadores_validos) !== count($player_ids)) {
            echo json_encode(['success' => false, 'error' => 'Alguns jogadores selecionados nao pertencem ao seu time']);
            return;
        }
    }
    
    $pdo->beginTransaction();
    
    try {
        // Criar proposta
        $stmt = $pdo->prepare("INSERT INTO leilao_propostas (leilao_id, team_id, notas, status, created_at) VALUES (?, ?, ?, 'pendente', NOW())");
        $stmt->execute([$leilao_id, $team_id, $notas]);
        $proposta_id = $pdo->lastInsertId();
        
        // Adicionar jogadores da proposta
        if (!empty($player_ids)) {
            $stmt = $pdo->prepare("INSERT INTO leilao_proposta_jogadores (proposta_id, player_id) VALUES (?, ?)");
            foreach ($player_ids as $pid) {
                $stmt->execute([$proposta_id, $pid]);
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'proposta_id' => $proposta_id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao salvar proposta: ' . $e->getMessage()]);
    }
}

function aceitarProposta($pdo, $body, $team_id, $is_admin) {
    $proposta_id = $body['proposta_id'] ?? null;
    
    if (!$proposta_id) {
        echo json_encode(['success' => false, 'error' => 'ID da proposta nao informado']);
        return;
    }
    
    // Buscar proposta e leilao
    $stmt = $pdo->prepare("SELECT lp.*, l.player_id, l.team_id as leilao_team_id, l.id as leilao_id, l.data_fim
                           FROM leilao_propostas lp
                           JOIN leilao_jogadores l ON lp.leilao_id = l.id
                           WHERE lp.id = ?");
    $stmt->execute([$proposta_id]);
    $proposta = $stmt->fetch();
    
    if (!$proposta) {
        echo json_encode(['success' => false, 'error' => 'Proposta nao encontrada']);
        return;
    }
    
    // Verificar se e dono do jogador ou admin
    if (!$is_admin && $proposta['leilao_team_id'] != $team_id) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }

    if (!empty($proposta['data_fim']) && strtotime($proposta['data_fim']) > time()) {
        echo json_encode(['success' => false, 'error' => 'Leilao ainda esta em andamento']);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        // Marcar proposta como aceita
        $stmt = $pdo->prepare("UPDATE leilao_propostas SET status = 'aceita' WHERE id = ?");
        $stmt->execute([$proposta_id]);
        
        // Marcar outras propostas como recusadas
        $stmt = $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE leilao_id = ? AND id != ?");
        $stmt->execute([$proposta['leilao_id'], $proposta_id]);
        
        // Finalizar leilao
        $stmt = $pdo->prepare("UPDATE leilao_jogadores SET status = 'finalizado', proposta_aceita_id = ? WHERE id = ?");
        $stmt->execute([$proposta_id, $proposta['leilao_id']]);
        
        // Buscar jogadores oferecidos na proposta
        $stmt = $pdo->prepare("SELECT player_id FROM leilao_proposta_jogadores WHERE proposta_id = ?");
        $stmt->execute([$proposta_id]);
        $jogadores_oferecidos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Transferir jogador do leilao para o time que fez a proposta
        $stmt = $pdo->prepare("UPDATE players SET team_id = ? WHERE id = ?");
        $stmt->execute([$proposta['team_id'], $proposta['player_id']]);
        
        // Transferir jogadores oferecidos para o time do leilao
        foreach ($jogadores_oferecidos as $player_id) {
            $stmt->execute([$proposta['leilao_team_id'], $player_id]);
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Troca realizada com sucesso']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao processar troca: ' . $e->getMessage()]);
    }
}

function recusarProposta($pdo, $body, $team_id, $is_admin) {
    $proposta_id = $body['proposta_id'] ?? null;
    
    if (!$proposta_id) {
        echo json_encode(['success' => false, 'error' => 'ID da proposta nao informado']);
        return;
    }
    
    // Buscar proposta e leilao
    $stmt = $pdo->prepare("SELECT lp.*, l.team_id as leilao_team_id
                           FROM leilao_propostas lp
                           JOIN leilao_jogadores l ON lp.leilao_id = l.id
                           WHERE lp.id = ?");
    $stmt->execute([$proposta_id]);
    $proposta = $stmt->fetch();
    
    if (!$proposta) {
        echo json_encode(['success' => false, 'error' => 'Proposta nao encontrada']);
        return;
    }
    
    // Verificar se e dono do jogador ou admin
    if (!$is_admin && $proposta['leilao_team_id'] != $team_id) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE id = ?");
    $stmt->execute([$proposta_id]);
    
    echo json_encode(['success' => true]);
}
