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
$action = $_GET['action'] ?? '';
$validLeagues = ['ELITE','NEXT','RISE','ROOKIE'];

function handleFreeAgentCreation(PDO $pdo, array $validLeagues, array $data): void
{
    $name = trim($data['name'] ?? '');
    $age = isset($data['age']) ? (int)$data['age'] : null;
    $position = strtoupper(trim($data['position'] ?? ''));
    $secondaryPosition = $data['secondary_position'] ?? null;
    $ovr = isset($data['ovr']) ? (int)$data['ovr'] : null;
    $league = strtoupper($data['league'] ?? 'ELITE');
    $originalTeamName = trim($data['original_team_name'] ?? '');

    if (!in_array($league, $validLeagues, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Liga inválida']);
        return;
    }

    if ($name === '' || !$age || !$position || !$ovr) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Preencha nome, idade, posição e OVR']);
        return;
    }

    try {
        $stmt = $pdo->prepare('
            INSERT INTO free_agents (name, age, position, secondary_position, ovr, league, original_team_id, original_team_name)
            VALUES (?, ?, ?, ?, ?, ?, NULL, ?)
        ');
        $stmt->execute([
            $name,
            $age,
            $position,
            $secondaryPosition ?: null,
            $ovr,
            $league,
            $originalTeamName ?: null
        ]);

        echo json_encode(['success' => true, 'message' => 'Free agent criado com sucesso!']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao criar free agent: ' . $e->getMessage()]);
    }
}

function handleFreeAgentAssignment(PDO $pdo, array $data): void
{
    $freeAgentId = isset($data['free_agent_id']) ? (int)$data['free_agent_id'] : null;
    $teamId = isset($data['team_id']) ? (int)$data['team_id'] : null;

    if (!$freeAgentId || !$teamId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Free agent e time são obrigatórios']);
        return;
    }

    $stmtFA = $pdo->prepare('SELECT * FROM free_agents WHERE id = ?');
    $stmtFA->execute([$freeAgentId]);
    $freeAgent = $stmtFA->fetch(PDO::FETCH_ASSOC);

    if (!$freeAgent) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Free agent não encontrado']);
        return;
    }

    $stmtTeam = $pdo->prepare('SELECT id, league, city, name FROM teams WHERE id = ?');
    $stmtTeam->execute([$teamId]);
    $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);

    if (!$team) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
        return;
    }

    if (strtoupper($team['league']) !== strtoupper($freeAgent['league'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Time e jogador precisam ser da mesma liga']);
        return;
    }

    try {
        $pdo->beginTransaction();

        $stmtPlayer = $pdo->prepare('
            INSERT INTO players (team_id, name, age, seasons_in_league, position, secondary_position, role, available_for_trade, ovr)
            VALUES (?, ?, ?, 0, ?, ?, "Banco", 0, ?)
        ');
        $stmtPlayer->execute([
            $teamId,
            $freeAgent['name'],
            $freeAgent['age'],
            $freeAgent['position'],
            $freeAgent['secondary_position'],
            $freeAgent['ovr']
        ]);

        $stmtDelete = $pdo->prepare('DELETE FROM free_agents WHERE id = ?');
        $stmtDelete->execute([$freeAgentId]);

        $stmtUpdateTeam = $pdo->prepare('UPDATE teams SET fa_signings_used = COALESCE(fa_signings_used, 0) + 1 WHERE id = ?');
        $stmtUpdateTeam->execute([$teamId]);

        $stmtOffers = $pdo->prepare('UPDATE free_agent_offers SET status = CASE WHEN team_id = ? THEN "accepted" ELSE "rejected" END WHERE free_agent_id = ? AND status = "pending"');
        $stmtOffers->execute([$teamId, $freeAgentId]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => sprintf('%s agora faz parte de %s %s', $freeAgent['name'], $team['city'], $team['name'])
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao atribuir jogador: ' . $e->getMessage()]);
    }
}

// GET - Listar dados do admin
if ($method === 'GET') {
    switch ($action) {
        case 'leagues':
            // Listar todas as ligas com configurações
            $stmtLeagues = $pdo->query("SELECT name FROM leagues ORDER BY FIELD(name,'ELITE','NEXT','RISE','ROOKIE')");
            $leagues = $stmtLeagues->fetchAll(PDO::FETCH_COLUMN);

            $result = [];
            foreach ($leagues as $league) {
                $stmtCfg = $pdo->prepare('SELECT cap_min, cap_max, max_trades, edital FROM league_settings WHERE league = ?');
                $stmtCfg->execute([$league]);
                $cfg = $stmtCfg->fetch() ?: ['cap_min' => 0, 'cap_max' => 0, 'max_trades' => 3, 'edital' => null];

                $stmtTeams = $pdo->prepare('SELECT COUNT(*) as total FROM teams WHERE league = ?');
                $stmtTeams->execute([$league]);
                $teamCount = $stmtTeams->fetch()['total'];

                $result[] = [
                    'league' => $league,
                    'cap_min' => (int)$cfg['cap_min'],
                    'cap_max' => (int)$cfg['cap_max'],
                    'max_trades' => (int)$cfg['max_trades'],
                    'edital' => $cfg['edital'],
                    'team_count' => (int)$teamCount
                ];
            }

            echo json_encode(['success' => true, 'leagues' => $result]);
            break;

        case 'teams':
            // Listar todos os times com detalhes
            $league = $_GET['league'] ?? null;
            
            $query = "
                SELECT 
                    t.id, t.city, t.name, t.mascot, t.league, t.conference, t.photo_url,
                    u.name as owner_name, u.email as owner_email,
                    d.name as division_name,
                    (SELECT COUNT(*) FROM players WHERE team_id = t.id) as player_count
                FROM teams t
                JOIN users u ON t.user_id = u.id
                LEFT JOIN divisions d ON t.division_id = d.id
            ";
            
            if ($league) {
                $query .= " WHERE t.league = ? ORDER BY t.city, t.name";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$league]);
            } else {
                $query .= " ORDER BY FIELD(t.league,'ELITE','NEXT','RISE','ROOKIE'), t.city, t.name";
                $stmt = $pdo->query($query);
            }
            
            $teams = [];
            while ($team = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $capTop8 = topEightCap($pdo, $team['id']);
                $team['cap_top8'] = $capTop8;
                $teams[] = $team;
            }

            echo json_encode(['success' => true, 'teams' => $teams]);
            break;

        case 'team_details':
            // Detalhes completos de um time específico
            $teamId = $_GET['team_id'] ?? null;
            if (!$teamId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Team ID obrigatório']);
                exit;
            }

            $stmtTeam = $pdo->prepare("
                SELECT 
                    t.*, 
                    u.name as owner_name, u.email as owner_email,
                    d.name as division_name
                FROM teams t
                JOIN users u ON t.user_id = u.id
                LEFT JOIN divisions d ON t.division_id = d.id
                WHERE t.id = ?
            ");
            $stmtTeam->execute([$teamId]);
            $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);

            if (!$team) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
                exit;
            }

            // Buscar jogadores
            $stmtPlayers = $pdo->prepare("
                SELECT * FROM players 
                WHERE team_id = ? 
                ORDER BY ovr DESC, role, name
            ");
            $stmtPlayers->execute([$teamId]);
            $team['players'] = $stmtPlayers->fetchAll(PDO::FETCH_ASSOC);

            // Buscar picks
            $stmtPicks = $pdo->prepare("
                SELECT p.*, t.city, t.name as team_name 
                FROM picks p
                JOIN teams t ON p.original_team_id = t.id
                WHERE p.team_id = ?
                ORDER BY p.season_year, p.round
            ");
            $stmtPicks->execute([$teamId]);
            $team['picks'] = $stmtPicks->fetchAll(PDO::FETCH_ASSOC);

            $team['cap_top8'] = topEightCap($pdo, $teamId);

            echo json_encode(['success' => true, 'team' => $team]);
            break;

        case 'trades':
            // Listar todas as trades
            $status = $_GET['status'] ?? 'all'; // all, pending, accepted, rejected, cancelled
            $league = $_GET['league'] ?? null;
            
            $conditions = [];
            $params = [];
            
            if ($status !== 'all') {
                $conditions[] = "t.status = ?";
                $params[] = $status;
            }
            
            if ($league) {
                $conditions[] = "from_team.league = ?";
                $params[] = $league;
            }
            
            $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
            
            $query = "
                SELECT 
                    t.*,
                    from_team.city as from_city,
                    from_team.name as from_name,
                    from_team.league as from_league,
                    to_team.city as to_city,
                    to_team.name as to_name,
                    to_team.league as to_league
                FROM trades t
                JOIN teams from_team ON t.from_team_id = from_team.id
                JOIN teams to_team ON t.to_team_id = to_team.id
                $whereClause
                ORDER BY t.created_at DESC
                LIMIT 100
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Para cada trade, buscar itens
            foreach ($trades as &$trade) {
                $stmtOfferPlayers = $pdo->prepare('
                    SELECT p.* FROM players p
                    JOIN trade_items ti ON p.id = ti.player_id
                    WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.player_id IS NOT NULL
                ');
                $stmtOfferPlayers->execute([$trade['id']]);
                $trade['offer_players'] = $stmtOfferPlayers->fetchAll(PDO::FETCH_ASSOC);
                
                $stmtOfferPicks = $pdo->prepare('
                    SELECT pk.*, t.city, t.name as team_name FROM picks pk
                    JOIN trade_items ti ON pk.id = ti.pick_id
                    JOIN teams t ON pk.original_team_id = t.id
                    WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.pick_id IS NOT NULL
                ');
                $stmtOfferPicks->execute([$trade['id']]);
                $trade['offer_picks'] = $stmtOfferPicks->fetchAll(PDO::FETCH_ASSOC);
                
                $stmtRequestPlayers = $pdo->prepare('
                    SELECT p.* FROM players p
                    JOIN trade_items ti ON p.id = ti.player_id
                    WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.player_id IS NOT NULL
                ');
                $stmtRequestPlayers->execute([$trade['id']]);
                $trade['request_players'] = $stmtRequestPlayers->fetchAll(PDO::FETCH_ASSOC);
                
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
            break;

        case 'divisions':
            // Listar divisões por liga
            $league = $_GET['league'] ?? null;
            if (!$league) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga obrigatória']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM divisions WHERE league = ? ORDER BY importance DESC, name");
            $stmt->execute([$league]);
            $divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'divisions' => $divisions]);
            break;

        case 'free_agents':
            $league = strtoupper($_GET['league'] ?? 'ELITE');
            if (!in_array($league, $validLeagues, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga inválida']);
                exit;
            }

            $stmt = $pdo->prepare('
                SELECT fa.*, (
                    SELECT COUNT(*) FROM free_agent_offers 
                    WHERE free_agent_id = fa.id AND status = "pending"
                ) AS pending_offers
                FROM free_agents fa
                WHERE fa.league = ?
                ORDER BY fa.ovr DESC, fa.name ASC
            ');
            $stmt->execute([$league]);
            $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'league' => $league, 'free_agents' => $agents]);
            break;

        case 'free_agent_offers':
            $league = strtoupper($_GET['league'] ?? 'ELITE');
            if (!in_array($league, $validLeagues, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga inválida']);
                exit;
            }

            $stmt = $pdo->prepare('
                SELECT fao.*, 
                       fa.name AS player_name, fa.position, fa.ovr, fa.age, fa.original_team_name,
                       t.name AS team_name, t.city AS team_city
                FROM free_agent_offers fao
                JOIN free_agents fa ON fao.free_agent_id = fa.id
                JOIN teams t ON fao.team_id = t.id
                WHERE fa.league = ? AND fao.status = "pending"
                ORDER BY fa.name, fao.created_at ASC
            ');
            $stmt->execute([$league]);
            $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

            echo json_encode(['success' => true, 'league' => $league, 'players' => array_values($grouped)]);
            break;

        case 'free_agent_teams':
            $league = strtoupper($_GET['league'] ?? 'ELITE');
            if (!in_array($league, $validLeagues, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga inválida']);
                exit;
            }

            $stmt = $pdo->prepare('SELECT id, city, name FROM teams WHERE league = ? ORDER BY city, name');
            $stmt->execute([$league]);
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'league' => $league, 'teams' => $teams]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

// PUT - Atualizar dados
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'free_agent':
            handleFreeAgentCreation($pdo, $validLeagues, $data);
            break;

        case 'free_agent_assign':
            handleFreeAgentAssignment($pdo, $data);
            break;
        case 'league_settings':
            // Atualizar configurações de liga
            $league = $data['league'] ?? null;
            $cap_min = isset($data['cap_min']) ? (int)$data['cap_min'] : null;
            $cap_max = isset($data['cap_max']) ? (int)$data['cap_max'] : null;
            $max_trades = isset($data['max_trades']) ? (int)$data['max_trades'] : null;
            $edital = $data['edital'] ?? null;

            if (!$league) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga obrigatória']);
                exit;
            }

            $updates = [];
            $params = [];

            if ($cap_min !== null) {
                $updates[] = 'cap_min = ?';
                $params[] = $cap_min;
            }
            if ($cap_max !== null) {
                $updates[] = 'cap_max = ?';
                $params[] = $cap_max;
            }
            if ($max_trades !== null) {
                $updates[] = 'max_trades = ?';
                $params[] = $max_trades;
            }
            if ($edital !== null) {
                $updates[] = 'edital = ?';
                $params[] = $edital;
            }

            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
                exit;
            }

            $params[] = $league;
            
            // Verifica se já existe
            $stmtCheck = $pdo->prepare('SELECT id FROM league_settings WHERE league = ?');
            $stmtCheck->execute([$league]);
            
            if ($stmtCheck->fetch()) {
                $sql = 'UPDATE league_settings SET ' . implode(', ', $updates) . ' WHERE league = ?';
            } else {
                $sql = 'INSERT INTO league_settings (league, ' . implode(', ', array_map(function($u) {
                    return explode(' = ', $u)[0];
                }, $updates)) . ') VALUES (?, ' . implode(', ', array_fill(0, count($updates), '?')) . ')';
                array_unshift($params, $league);
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true]);
            break;

        case 'team':
            // Atualizar informações do time
            $teamId = $data['team_id'] ?? null;
            $city = $data['city'] ?? null;
            $name = $data['name'] ?? null;
            $mascot = $data['mascot'] ?? null;
            $conference = $data['conference'] ?? null;
            $divisionId = $data['division_id'] ?? null;

            if (!$teamId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Team ID obrigatório']);
                exit;
            }

            $updates = [];
            $params = [];

            if ($city !== null) {
                $updates[] = 'city = ?';
                $params[] = $city;
            }
            if ($name !== null) {
                $updates[] = 'name = ?';
                $params[] = $name;
            }
            if ($mascot !== null) {
                $updates[] = 'mascot = ?';
                $params[] = $mascot;
            }
            if ($conference !== null) {
                $updates[] = 'conference = ?';
                $params[] = $conference;
            }
            if ($divisionId !== null) {
                $updates[] = 'division_id = ?';
                $params[] = $divisionId;
            }

            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
                exit;
            }

            $params[] = $teamId;
            $sql = 'UPDATE teams SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['success' => true]);
            break;

        case 'player':
            // Atualizar jogador ou transferir para outro time
            $playerId = $data['player_id'] ?? null;
            $teamId = $data['team_id'] ?? null;
            $ovr = $data['ovr'] ?? null;
            $role = $data['role'] ?? null;
            $position = $data['position'] ?? null;

            if (!$playerId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Player ID obrigatório']);
                exit;
            }

            $updates = [];
            $params = [];

            if ($teamId !== null) {
                $updates[] = 'team_id = ?';
                $params[] = $teamId;
            }
            if ($ovr !== null) {
                $updates[] = 'ovr = ?';
                $params[] = $ovr;
            }
            if ($role !== null) {
                $updates[] = 'role = ?';
                $params[] = $role;
            }
            if ($position !== null) {
                $updates[] = 'position = ?';
                $params[] = $position;
            }

            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
                exit;
            }

            $params[] = $playerId;
            $sql = 'UPDATE players SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['success' => true]);
            break;

        case 'cancel_trade':
            // Cancelar trade (admin pode cancelar qualquer trade)
            $tradeId = $data['trade_id'] ?? null;

            if (!$tradeId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Trade ID obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE trades SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$tradeId]);

            echo json_encode(['success' => true, 'message' => 'Trade cancelada']);
            break;

        case 'revert_trade':
            // Reverter trade aceita
            $tradeId = $data['trade_id'] ?? null;

            if (!$tradeId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Trade ID obrigatório']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                // Buscar trade
                $stmtTrade = $pdo->prepare("SELECT * FROM trades WHERE id = ? AND status = 'accepted'");
                $stmtTrade->execute([$tradeId]);
                $trade = $stmtTrade->fetch(PDO::FETCH_ASSOC);

                if (!$trade) {
                    throw new Exception('Trade não encontrada ou não foi aceita');
                }

                // Buscar itens da trade
                $stmtItems = $pdo->prepare('SELECT * FROM trade_items WHERE trade_id = ?');
                $stmtItems->execute([$tradeId]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                // Reverter transferências
                foreach ($items as $item) {
                    if ($item['player_id']) {
                        // Reverter jogador para o time original
                        $originalTeamId = $item['from_team'] ? $trade['from_team_id'] : $trade['to_team_id'];
                        $stmtRevert = $pdo->prepare('UPDATE players SET team_id = ? WHERE id = ?');
                        $stmtRevert->execute([$originalTeamId, $item['player_id']]);
                    }

                    if ($item['pick_id']) {
                        // Reverter pick para o time original
                        $originalTeamId = $item['from_team'] ? $trade['from_team_id'] : $trade['to_team_id'];
                        $stmtRevert = $pdo->prepare('UPDATE picks SET team_id = ?, last_owner_team_id = ? WHERE id = ?');
                        $stmtRevert->execute([$originalTeamId, $originalTeamId, $item['pick_id']]);
                    }
                }

                // Atualizar status da trade
                $stmtUpdate = $pdo->prepare("UPDATE trades SET status = 'cancelled', notes = CONCAT(notes, '\n[Admin] Trade revertida em ', NOW()) WHERE id = ?");
                $stmtUpdate->execute([$tradeId]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Trade revertida com sucesso']);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'pick':
            // Atualizar ou adicionar pick
            $pickId = $data['pick_id'] ?? null;
            $teamId = $data['team_id'] ?? null;
            $originalTeamId = $data['original_team_id'] ?? null;
            $seasonYear = $data['season_year'] ?? null;
            $round = $data['round'] ?? null;
            $notes = $data['notes'] ?? null;

            if (!$teamId || !$originalTeamId || !$seasonYear || !$round) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Dados obrigatórios ausentes']);
                exit;
            }

            if ($pickId) {
                // Atualizar pick existente
                $stmt = $pdo->prepare('
                    UPDATE picks 
                    SET team_id = ?, original_team_id = ?, season_year = ?, round = ?, notes = ?, auto_generated = 0, last_owner_team_id = ?
                    WHERE id = ?
                ');
                $stmt->execute([$teamId, $originalTeamId, $seasonYear, $round, $notes, $teamId, $pickId]);
            } else {
                // Reutilizar pick existente com mesma origem/ano/rodada ou criar um novo
                $stmtExisting = $pdo->prepare('
                    SELECT id FROM picks WHERE original_team_id = ? AND season_year = ? AND round = ?
                ');
                $stmtExisting->execute([$originalTeamId, $seasonYear, $round]);
                $existingId = $stmtExisting->fetchColumn();

                if ($existingId) {
                    $stmt = $pdo->prepare('
                        UPDATE picks 
                        SET team_id = ?, original_team_id = ?, season_year = ?, round = ?, notes = ?, auto_generated = 0, last_owner_team_id = ?
                        WHERE id = ?
                    ');
                    $stmt->execute([$teamId, $originalTeamId, $seasonYear, $round, $notes, $teamId, $existingId]);
                    $pickId = $existingId;
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO picks (team_id, original_team_id, season_year, round, auto_generated, last_owner_team_id, notes)
                        VALUES (?, ?, ?, ?, 0, ?, ?)
                    ');
                    $stmt->execute([$teamId, $originalTeamId, $seasonYear, $round, $teamId, $notes]);
                    $pickId = $pdo->lastInsertId();
                }
            }

            echo json_encode(['success' => true, 'pick_id' => $pickId]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

// POST - Adicionar novos dados
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'player':
            // Adicionar novo jogador
            $teamId = $data['team_id'] ?? null;
            $name = $data['name'] ?? null;
            $age = $data['age'] ?? null;
            $position = $data['position'] ?? null;
            $secondaryPosition = $data['secondary_position'] ?? null;
            $role = $data['role'] ?? 'Banco';
            $ovr = $data['ovr'] ?? 50;

            if (!$teamId || !$name || !$age || !$position) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Dados obrigatórios ausentes']);
                exit;
            }

            // Verificar se a coluna secondary_position existe
            try {
                $checkCol = $pdo->query("SHOW COLUMNS FROM players LIKE 'secondary_position'");
                $hasSecondaryPosition = $checkCol->rowCount() > 0;
            } catch (Exception $e) {
                $hasSecondaryPosition = false;
            }

            if ($hasSecondaryPosition) {
                $stmt = $pdo->prepare('
                    INSERT INTO players (team_id, name, age, position, secondary_position, role, ovr)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$teamId, $name, $age, $position, $secondaryPosition, $role, $ovr]);
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO players (team_id, name, age, position, role, ovr)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$teamId, $name, $age, $position, $role, $ovr]);
            }
            
            $newPlayerId = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'player_id' => $newPlayerId]);
            break;

        case 'pick':
            // Adicionar novo pick
            $teamId = $data['team_id'] ?? null;
            $originalTeamId = $data['original_team_id'] ?? null;
            $seasonYear = $data['season_year'] ?? null;
            $round = $data['round'] ?? null;
            $notes = $data['notes'] ?? null;

            if (!$teamId || !$originalTeamId || !$seasonYear || !$round) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Dados obrigatórios ausentes']);
                exit;
            }

            $stmtExisting = $pdo->prepare('
                SELECT id FROM picks WHERE original_team_id = ? AND season_year = ? AND round = ?
            ');
            $stmtExisting->execute([$originalTeamId, $seasonYear, $round]);
            $existingId = $stmtExisting->fetchColumn();

            if ($existingId) {
                $stmt = $pdo->prepare('
                    UPDATE picks 
                    SET team_id = ?, original_team_id = ?, season_year = ?, round = ?, notes = ?, auto_generated = 0, last_owner_team_id = ?
                    WHERE id = ?
                ');
                $stmt->execute([$teamId, $originalTeamId, $seasonYear, $round, $notes, $teamId, $existingId]);
                $newPickId = $existingId;
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO picks (team_id, original_team_id, season_year, round, auto_generated, last_owner_team_id, notes)
                    VALUES (?, ?, ?, ?, 0, ?, ?)
                ');
                $stmt->execute([$teamId, $originalTeamId, $seasonYear, $round, $teamId, $notes]);
                $newPickId = $pdo->lastInsertId();
            }

            echo json_encode(['success' => true, 'pick_id' => $newPickId]);
            break;

        case 'free_agent':
            handleFreeAgentCreation($pdo, $validLeagues, $data);
            break;

        case 'free_agent_assign':
            handleFreeAgentAssignment($pdo, $data);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

// DELETE - Deletar dados
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    
    switch ($action) {
        case 'free_agent':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID do free agent obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare('DELETE FROM free_agents WHERE id = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Free agent removido']);
            break;

        case 'player':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Player ID obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare('DELETE FROM players WHERE id = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Jogador deletado']);
            break;

        case 'pick':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Pick ID obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare('DELETE FROM picks WHERE id = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Pick deletado']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido']);
