<?php
/**
 * API de Free Agency
 * Gerencia jogadores dispensados e propostas de contratação
 */
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$pdo = db();
$config = loadConfig();
$method = $_SERVER['REQUEST_METHOD'];

// Limites
$MAX_WAIVERS = 3; // Máximo de dispensas por temporada
$MAX_SIGNINGS = 3; // Máximo de contratações FA por temporada

// Pegar time do usuário
$stmtTeam = $pdo->prepare('SELECT t.*, 
    COALESCE(t.waivers_used, 0) as waivers_used,
    COALESCE(t.fa_signings_used, 0) as fa_signings_used
    FROM teams t WHERE t.user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();
$teamId = $team['id'] ?? null;
$userLeague = $team['league'] ?? $user['league'];

// GET - Listar free agents ou propostas
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action === 'list') {
        // Listar todos os free agents da liga
        $stmt = $pdo->prepare('
            SELECT fa.*, 
                   (SELECT COUNT(*) FROM free_agent_offers WHERE free_agent_id = fa.id AND status = "pending") as pending_offers
            FROM free_agents fa 
            WHERE fa.league = ?
            ORDER BY fa.ovr DESC, fa.name ASC
        ');
        $stmt->execute([$userLeague]);
        $freeAgents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'free_agents' => $freeAgents]);
        exit;
    }
    
    if ($action === 'my_offers') {
        // Minhas propostas enviadas
        if (!$teamId) {
            echo json_encode(['success' => true, 'offers' => []]);
            exit;
        }
        
        $stmt = $pdo->prepare('
            SELECT fao.*, fa.name as player_name, fa.position, fa.ovr, fa.age
            FROM free_agent_offers fao
            JOIN free_agents fa ON fao.free_agent_id = fa.id
            WHERE fao.team_id = ?
            ORDER BY fao.created_at DESC
        ');
        $stmt->execute([$teamId]);
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'offers' => $offers]);
        exit;
    }
    
    if ($action === 'admin_offers') {
        // Admin: Ver todas as propostas pendentes
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $league = $_GET['league'] ?? $userLeague;
        
        $stmt = $pdo->prepare('
            SELECT fao.*, 
                   fa.name as player_name, fa.position, fa.ovr, fa.age, fa.original_team_name,
                   t.name as team_name, t.city as team_city
            FROM free_agent_offers fao
            JOIN free_agents fa ON fao.free_agent_id = fa.id
            JOIN teams t ON fao.team_id = t.id
            WHERE fa.league = ? AND fao.status = "pending"
            ORDER BY fa.name, fao.created_at ASC
        ');
        $stmt->execute([$league]);
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Agrupar por jogador
        $grouped = [];
        foreach ($offers as $offer) {
            $faId = $offer['free_agent_id'];
            if (!isset($grouped[$faId])) {
                $grouped[$faId] = [
                    'player' => [
                        'id' => $faId,
                        'name' => $offer['player_name'],
                        'position' => $offer['position'],
                        'ovr' => $offer['ovr'],
                        'age' => $offer['age'],
                        'original_team' => $offer['original_team_name']
                    ],
                    'offers' => []
                ];
            }
            $grouped[$faId]['offers'][] = [
                'id' => $offer['id'],
                'team_id' => $offer['team_id'],
                'team_name' => $offer['team_city'] . ' ' . $offer['team_name'],
                'notes' => $offer['notes'],
                'created_at' => $offer['created_at']
            ];
        }
        
        echo json_encode(['success' => true, 'players' => array_values($grouped)]);
        exit;
    }
    
    if ($action === 'limits') {
        // Retornar limites do time
        echo json_encode([
            'success' => true,
            'waivers_used' => (int)($team['waivers_used'] ?? 0),
            'waivers_max' => $MAX_WAIVERS,
            'signings_used' => (int)($team['fa_signings_used'] ?? 0),
            'signings_max' => $MAX_SIGNINGS
        ]);
        exit;
    }
}

// POST - Dispensar jogador ou enviar proposta
if ($method === 'POST') {
    if (!$teamId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Você não possui um time']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    // Dispensar jogador (waive)
    if ($action === 'waive') {
        $playerId = $data['player_id'] ?? null;
        
        if (!$playerId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID do jogador não informado']);
            exit;
        }
        
        // Verificar limite de dispensas
        $waiversUsed = (int)($team['waivers_used'] ?? 0);
        if ($waiversUsed >= $MAX_WAIVERS) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Limite de {$MAX_WAIVERS} dispensas por temporada atingido"]);
            exit;
        }
        
        // Verificar se o jogador pertence ao time
        $stmtPlayer = $pdo->prepare('SELECT * FROM players WHERE id = ? AND team_id = ?');
        $stmtPlayer->execute([$playerId, $teamId]);
        $player = $stmtPlayer->fetch();
        
        if (!$player) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Jogador não encontrado ou não pertence ao seu time']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Adicionar à free agency
            $stmtFA = $pdo->prepare('
                INSERT INTO free_agents (name, age, position, secondary_position, ovr, league, original_team_id, original_team_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmtFA->execute([
                $player['name'],
                $player['age'],
                $player['position'],
                $player['secondary_position'] ?? null,
                $player['ovr'],
                $team['league'],
                $teamId,
                $team['city'] . ' ' . $team['name']
            ]);
            
            // Remover jogador do time
            $stmtDel = $pdo->prepare('DELETE FROM players WHERE id = ?');
            $stmtDel->execute([$playerId]);
            
            // Incrementar contador de dispensas
            $stmtUpd = $pdo->prepare('UPDATE teams SET waivers_used = COALESCE(waivers_used, 0) + 1 WHERE id = ?');
            $stmtUpd->execute([$teamId]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Jogador dispensado e adicionado à Free Agency',
                'waivers_remaining' => $MAX_WAIVERS - $waiversUsed - 1
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao dispensar jogador: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Enviar proposta para free agent
    if ($action === 'offer') {
        $freeAgentId = $data['free_agent_id'] ?? null;
        $notes = $data['notes'] ?? '';
        
        if (!$freeAgentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID do free agent não informado']);
            exit;
        }
        
        // Verificar se o free agent existe e é da mesma liga
        $stmtFA = $pdo->prepare('SELECT * FROM free_agents WHERE id = ? AND league = ?');
        $stmtFA->execute([$freeAgentId, $team['league']]);
        $freeAgent = $stmtFA->fetch();
        
        if (!$freeAgent) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Free agent não encontrado']);
            exit;
        }
        
        // Verificar se já enviou proposta
        $stmtCheck = $pdo->prepare('SELECT id FROM free_agent_offers WHERE free_agent_id = ? AND team_id = ?');
        $stmtCheck->execute([$freeAgentId, $teamId]);
        if ($stmtCheck->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Você já enviou uma proposta para este jogador']);
            exit;
        }
        
        // Verificar CAP
        $currentCap = topEightCap($pdo, $teamId);
        $capAfter = $currentCap + $freeAgent['ovr'];
        // Nota: não verificamos CAP aqui pois o admin decide, mas podemos avisar
        
        try {
            $stmtOffer = $pdo->prepare('
                INSERT INTO free_agent_offers (free_agent_id, team_id, notes)
                VALUES (?, ?, ?)
            ');
            $stmtOffer->execute([$freeAgentId, $teamId, $notes]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Proposta enviada! Aguarde a decisão do administrador.'
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao enviar proposta']);
        }
        exit;
    }
    
    // Admin: Aprovar proposta
    if ($action === 'approve') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $offerId = $data['offer_id'] ?? null;
        
        if (!$offerId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID da proposta não informado']);
            exit;
        }
        
        // Buscar proposta e free agent
        $stmtOffer = $pdo->prepare('
            SELECT fao.*, fa.*, t.league as team_league,
                   COALESCE(t.fa_signings_used, 0) as signings_used
            FROM free_agent_offers fao
            JOIN free_agents fa ON fao.free_agent_id = fa.id
            JOIN teams t ON fao.team_id = t.id
            WHERE fao.id = ? AND fao.status = "pending"
        ');
        $stmtOffer->execute([$offerId]);
        $offer = $stmtOffer->fetch();
        
        if (!$offer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Proposta não encontrada ou já processada']);
            exit;
        }
        
        // Verificar limite de contratações do time
        if ((int)$offer['signings_used'] >= $MAX_SIGNINGS) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Time já atingiu o limite de contratações FA']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Criar jogador no time
            $stmtPlayer = $pdo->prepare('
                INSERT INTO players (team_id, name, age, position, secondary_position, ovr, role, available_for_trade)
                VALUES (?, ?, ?, ?, ?, ?, "Banco", 0)
            ');
            $stmtPlayer->execute([
                $offer['team_id'],
                $offer['name'],
                $offer['age'],
                $offer['position'],
                $offer['secondary_position'],
                $offer['ovr']
            ]);
            
            // Atualizar proposta para aceita
            $stmtAccept = $pdo->prepare('UPDATE free_agent_offers SET status = "accepted" WHERE id = ?');
            $stmtAccept->execute([$offerId]);
            
            // Rejeitar outras propostas para o mesmo jogador
            $stmtReject = $pdo->prepare('UPDATE free_agent_offers SET status = "rejected" WHERE free_agent_id = ? AND id != ?');
            $stmtReject->execute([$offer['free_agent_id'], $offerId]);
            
            // Remover da free agency
            $stmtDelFA = $pdo->prepare('DELETE FROM free_agents WHERE id = ?');
            $stmtDelFA->execute([$offer['free_agent_id']]);
            
            // Incrementar contador de contratações
            $stmtUpd = $pdo->prepare('UPDATE teams SET fa_signings_used = COALESCE(fa_signings_used, 0) + 1 WHERE id = ?');
            $stmtUpd->execute([$offer['team_id']]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Jogador contratado com sucesso!'
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao aprovar proposta: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Admin: Rejeitar proposta
    if ($action === 'reject') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $offerId = $data['offer_id'] ?? null;
        
        if (!$offerId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID da proposta não informado']);
            exit;
        }
        
        $stmtReject = $pdo->prepare('UPDATE free_agent_offers SET status = "rejected" WHERE id = ? AND status = "pending"');
        $stmtReject->execute([$offerId]);
        
        if ($stmtReject->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Proposta rejeitada']);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Proposta não encontrada ou já processada']);
        }
        exit;
    }
    
    // Admin: Rejeitar todas as propostas de um jogador
    if ($action === 'reject_all') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $freeAgentId = $data['free_agent_id'] ?? null;
        
        if (!$freeAgentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID do free agent não informado']);
            exit;
        }
        
        $stmtReject = $pdo->prepare('UPDATE free_agent_offers SET status = "rejected" WHERE free_agent_id = ? AND status = "pending"');
        $stmtReject->execute([$freeAgentId]);
        
        echo json_encode(['success' => true, 'message' => 'Todas as propostas foram rejeitadas', 'count' => $stmtReject->rowCount()]);
        exit;
    }
}

// DELETE - Cancelar proposta ou limpar free agency (admin)
if ($method === 'DELETE') {
    $action = $_GET['action'] ?? '';
    
    // Cancelar minha proposta
    if ($action === 'cancel_offer') {
        $offerId = $_GET['offer_id'] ?? null;
        
        if (!$offerId || !$teamId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
            exit;
        }
        
        $stmtDel = $pdo->prepare('DELETE FROM free_agent_offers WHERE id = ? AND team_id = ? AND status = "pending"');
        $stmtDel->execute([$offerId, $teamId]);
        
        if ($stmtDel->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Proposta cancelada']);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Proposta não encontrada']);
        }
        exit;
    }
    
    // Admin: Resetar free agency (limpar tudo)
    if ($action === 'reset') {
        if (($user['user_type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        $league = $_GET['league'] ?? $userLeague;
        
        try {
            $pdo->beginTransaction();
            
            // Deletar propostas de free agents da liga
            $pdo->prepare('
                DELETE fao FROM free_agent_offers fao
                JOIN free_agents fa ON fao.free_agent_id = fa.id
                WHERE fa.league = ?
            ')->execute([$league]);
            
            // Deletar free agents da liga
            $pdo->prepare('DELETE FROM free_agents WHERE league = ?')->execute([$league]);
            
            // Resetar contadores dos times
            $pdo->prepare('UPDATE teams SET waivers_used = 0, fa_signings_used = 0 WHERE league = ?')->execute([$league]);
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Free Agency resetada com sucesso']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao resetar: ' . $e->getMessage()]);
        }
        exit;
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido']);
