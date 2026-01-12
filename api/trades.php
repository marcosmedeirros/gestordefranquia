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

// Pegar time do usuário
$stmtTeam = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();
$teamId = $team['id'] ?? null;

if (!$teamId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Usuário sem time']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// GET - Listar trades
if ($method === 'GET') {
    $type = $_GET['type'] ?? 'received'; // received, sent, history
    
    $conditions = [];
    $params = [];
    
    if ($type === 'received') {
        $conditions[] = 't.to_team_id = ?';
        $conditions[] = "t.status = 'pending'";
        $params[] = $teamId;
    } elseif ($type === 'sent') {
        $conditions[] = 't.from_team_id = ?';
        $conditions[] = "t.status = 'pending'";
        $params[] = $teamId;
    } else { // history
        $conditions[] = '(t.from_team_id = ? OR t.to_team_id = ?)';
        $conditions[] = "t.status IN ('accepted', 'rejected', 'cancelled', 'countered')";
        $params[] = $teamId;
        $params[] = $teamId;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    $query = "
        SELECT 
            t.*,
            from_team.city as from_city,
            from_team.name as from_name,
            to_team.city as to_city,
            to_team.name as to_name
        FROM trades t
        JOIN teams from_team ON t.from_team_id = from_team.id
        JOIN teams to_team ON t.to_team_id = to_team.id
        WHERE $whereClause
        ORDER BY t.created_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Para cada trade, buscar itens
    foreach ($trades as &$trade) {
        // Jogadores oferecidos
        $stmtOfferPlayers = $pdo->prepare('
            SELECT p.* FROM players p
            JOIN trade_items ti ON p.id = ti.player_id
            WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.player_id IS NOT NULL
        ');
        $stmtOfferPlayers->execute([$trade['id']]);
        $trade['offer_players'] = $stmtOfferPlayers->fetchAll(PDO::FETCH_ASSOC);
        
        // Picks oferecidas
        $stmtOfferPicks = $pdo->prepare('
            SELECT pk.*, t.city, t.name as team_name FROM picks pk
            JOIN trade_items ti ON pk.id = ti.pick_id
            JOIN teams t ON pk.original_team_id = t.id
            WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.pick_id IS NOT NULL
        ');
        $stmtOfferPicks->execute([$trade['id']]);
        $trade['offer_picks'] = $stmtOfferPicks->fetchAll(PDO::FETCH_ASSOC);
        
        // Jogadores pedidos
        $stmtRequestPlayers = $pdo->prepare('
            SELECT p.* FROM players p
            JOIN trade_items ti ON p.id = ti.player_id
            WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.player_id IS NOT NULL
        ');
        $stmtRequestPlayers->execute([$trade['id']]);
        $trade['request_players'] = $stmtRequestPlayers->fetchAll(PDO::FETCH_ASSOC);
        
        // Picks pedidas
        $stmtRequestPicks = $pdo->prepare('
            SELECT pk.*, t.city, t.name as team_name FROM picks pk
            JOIN trade_items ti ON pk.id = ti.pick_id
            JOIN teams t ON pk.original_team_id = t.id
            WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.pick_id IS NOT NULL
        ');
        $stmtRequestPicks->execute([$trade['id']]);
        $trade['request_picks'] = $stmtRequestPicks->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode(['success' => true, 'trades' => $trades]);
    exit;
}

// POST - Criar trade
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $toTeamId = $data['to_team_id'] ?? null;
    $offerPlayers = $data['offer_players'] ?? [];
    $offerPicks = $data['offer_picks'] ?? [];
    $requestPlayers = $data['request_players'] ?? [];
    $requestPicks = $data['request_picks'] ?? [];
    $notes = $data['notes'] ?? '';
    $counterTradeId = isset($data['counter_to_trade_id']) ? (int)$data['counter_to_trade_id'] : null;
    $counterTrade = null;
    
    if (!$toTeamId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Time destino não informado']);
        exit;
    }
    
    // Verificar se há algo para trocar
    if (empty($offerPlayers) && empty($offerPicks)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Você precisa oferecer algo']);
        exit;
    }
    
    if (empty($requestPlayers) && empty($requestPicks)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Você precisa pedir algo em troca']);
        exit;
    }
    
    // Buscar o time para obter a liga
    $stmtTeamLeague = $pdo->prepare('SELECT league, city, name FROM teams WHERE id = ?');
    $stmtTeamLeague->execute([$teamId]);
    $teamData = $stmtTeamLeague->fetch();
    
    if (!$teamData) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
        exit;
    }

    if ($counterTradeId) {
        $stmtCounter = $pdo->prepare('SELECT * FROM trades WHERE id = ?');
        $stmtCounter->execute([$counterTradeId]);
        $counterTrade = $stmtCounter->fetch(PDO::FETCH_ASSOC);
        if (!$counterTrade) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Trade original não encontrada para contraproposta']);
            exit;
        }
        if ((int)$counterTrade['to_team_id'] !== (int)$teamId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Você não pode fazer contraproposta para esta trade']);
            exit;
        }
        if ($counterTrade['status'] !== 'pending') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'A trade original não está mais pendente']);
            exit;
        }
        if ((int)$counterTrade['from_team_id'] !== (int)$toTeamId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Selecione o mesmo time da proposta original para contrapropor']);
            exit;
        }
    }
    
    // Verificar se a coluna max_trades existe em league_settings
    $maxTrades = 10; // Default
    try {
        $stmtSettings = $pdo->prepare('SELECT max_trades FROM league_settings WHERE league = ?');
        $stmtSettings->execute([$teamData['league']]);
        $settings = $stmtSettings->fetch();
        if ($settings && isset($settings['max_trades'])) {
            $maxTrades = (int)$settings['max_trades'];
        }
    } catch (Exception $e) {
        // Coluna não existe, usar default
    }
    
    // Verificar limite de trades por ano (simplificado - sem ciclo)
    $stmtCount = $pdo->prepare('SELECT COUNT(*) as total FROM trades WHERE from_team_id = ? AND YEAR(created_at) = YEAR(NOW())');
    $stmtCount->execute([$teamId]);
    $count = (int)$stmtCount->fetch()['total'];
    
    if ($count >= $maxTrades) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Limite de {$maxTrades} trades por temporada atingido"]);
        exit;
    }
    
    try {
    $pdo->beginTransaction();
        
        // Criar trade (sem coluna cycle - ela é opcional)
        $stmtTrade = $pdo->prepare('INSERT INTO trades (from_team_id, to_team_id, notes) VALUES (?, ?, ?)');
        $stmtTrade->execute([$teamId, $toTeamId, $notes]);
        $tradeId = $pdo->lastInsertId();
        
        // Adicionar itens oferecidos
        $stmtItem = $pdo->prepare('INSERT INTO trade_items (trade_id, player_id, pick_id, from_team) VALUES (?, ?, ?, ?)');
        
        foreach ($offerPlayers as $playerId) {
            $stmtItem->execute([$tradeId, $playerId, null, true]);
        }
        
        foreach ($offerPicks as $pickId) {
            $stmtItem->execute([$tradeId, null, $pickId, true]);
        }
        
        // Adicionar itens pedidos
        foreach ($requestPlayers as $playerId) {
            $stmtItem->execute([$tradeId, $playerId, null, false]);
        }
        
        foreach ($requestPicks as $pickId) {
            $stmtItem->execute([$tradeId, null, $pickId, false]);
        }

        if ($counterTrade) {
            $counterNote = trim($notes) !== '' ? trim($notes) : 'Contraproposta enviada.';
            $counterNote .= ' Nova proposta #' . $tradeId;
            $stmtCounterUpdate = $pdo->prepare('UPDATE trades SET status = ?, response_notes = ? WHERE id = ?');
            $stmtCounterUpdate->execute(['countered', $counterNote, $counterTradeId]);
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'trade_id' => $tradeId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao criar trade: ' . $e->getMessage()]);
    }
    exit;
}

// PUT - Responder trade (aceitar/rejeitar/cancelar)
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $tradeId = $data['trade_id'] ?? null;
    $action = $data['action'] ?? null; // accepted, rejected, cancelled
    $responseNotes = $data['response_notes'] ?? '';
    
    if (!$tradeId || !$action) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    // Verificar se o usuário pode responder esta trade
    $stmtCheck = $pdo->prepare('SELECT * FROM trades WHERE id = ? AND (from_team_id = ? OR to_team_id = ?)');
    $stmtCheck->execute([$tradeId, $teamId, $teamId]);
    $trade = $stmtCheck->fetch();
    
    if (!$trade) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Trade não encontrada ou sem permissão']);
        exit;
    }
    
    // Verificar se pode cancelar (só quem enviou)
    if ($action === 'cancelled' && $trade['from_team_id'] != $teamId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Só quem enviou pode cancelar']);
        exit;
    }
    
    // Verificar se pode aceitar/rejeitar (só quem recebeu)
    if (($action === 'accepted' || $action === 'rejected') && $trade['to_team_id'] != $teamId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Só quem recebeu pode aceitar/rejeitar']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Atualizar status e observação de resposta
        $stmtUpdate = $pdo->prepare('UPDATE trades SET status = ?, response_notes = ? WHERE id = ?');
        $stmtUpdate->execute([$action, $responseNotes, $tradeId]);
        
        // Se aceito, executar a trade (transferir jogadores e picks)
        if ($action === 'accepted') {
            // Buscar itens da trade
            $stmtItems = $pdo->prepare('SELECT * FROM trade_items WHERE trade_id = ?');
            $stmtItems->execute([$tradeId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                if ($item['player_id']) {
                    // Transferir jogador
                    $newTeamId = $item['from_team'] ? $trade['to_team_id'] : $trade['from_team_id'];
                    $stmtTransfer = $pdo->prepare('UPDATE players SET team_id = ? WHERE id = ?');
                    $stmtTransfer->execute([$newTeamId, $item['player_id']]);
                }
                
                if ($item['pick_id']) {
                    // Transferir pick
                    $newTeamId = $item['from_team'] ? $trade['to_team_id'] : $trade['from_team_id'];
                    $stmtTransfer = $pdo->prepare('UPDATE picks SET team_id = ? WHERE id = ?');
                    $stmtTransfer->execute([$newTeamId, $item['pick_id']]);
                }
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao processar trade']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido']);
