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

// Helpers para colunas e OVR
function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) { return false; }
}
function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) { return false; }
}
function ensureHallOfFameTable(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'hall_of_fame'");
        if ($stmt->rowCount() > 0) {
            return;
        }
        $pdo->exec("CREATE TABLE hall_of_fame (
            id INT AUTO_INCREMENT PRIMARY KEY,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            league ENUM('ELITE','NEXT','RISE','ROOKIE') NULL,
            team_id INT NULL,
            team_name VARCHAR(255) NULL,
            gm_name VARCHAR(255) NULL,
            titles INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_hof_titles (titles)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {
        // ignore
    }
}
function playerOvrColumn(PDO $pdo): string {
    return columnExists($pdo, 'players', 'ovr') ? 'ovr' : (columnExists($pdo, 'players', 'overall') ? 'overall' : 'ovr');
}

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
        case 'copy_rosters':
            $league = strtoupper(trim((string)($_GET['league'] ?? '')));
            if (!in_array($league, $validLeagues, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga inválida']);
                break;
            }

            $stmtTeams = $pdo->prepare('SELECT t.id, t.city, t.name, u.name AS owner_name FROM teams t LEFT JOIN users u ON t.user_id = u.id WHERE t.league = ? ORDER BY t.city, t.name');
            $stmtTeams->execute([$league]);
            $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);
            if (!$teams) {
                echo json_encode(['success' => true, 'text' => 'Nenhum time encontrado.']);
                break;
            }

            $teamIds = array_map(static function ($row) {
                return (int)$row['id'];
            }, $teams);
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $playerOvr = playerOvrColumn($pdo);
            $stmtPlayers = $pdo->prepare(
                'SELECT team_id, name, position, age, role, ' . $playerOvr . ' AS ovr
                 FROM players
                 WHERE team_id IN (' . $placeholders . ')
                 ORDER BY team_id,
                   CASE role
                     WHEN "Titular" THEN 1
                     WHEN "Banco" THEN 2
                     WHEN "Outro" THEN 3
                     WHEN "G-League" THEN 4
                     WHEN "G-League" THEN 4
                     ELSE 5
                   END,
                   ' . $playerOvr . ' DESC,
                   name ASC'
            );
            $stmtPlayers->execute($teamIds);
            $players = $stmtPlayers->fetchAll(PDO::FETCH_ASSOC);

            $playersByTeam = [];
            foreach ($players as $player) {
                $playersByTeam[(int)$player['team_id']][] = $player;
            }

            $lines = [];
            foreach ($teams as $team) {
                $teamName = trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''));
                $lines[] = '*' . $teamName . '*';
                $lines[] = 'GM: ' . ($team['owner_name'] ?? '-');
                $roster = $playersByTeam[(int)$team['id']] ?? [];
                if (!$roster) {
                    $lines[] = '- Sem jogadores';
                } else {
                    foreach ($roster as $player) {
                        $ovr = $player['ovr'] ?? '-';
                        $age = $player['age'] ?? '-';
                        $role = $player['role'] ?? '-';
                        $lines[] = sprintf('- %s | %s | OVR %s | %s anos | %s', $player['position'], $player['name'], $ovr, $age, $role);
                    }
                }
                $lines[] = '';
            }

            echo json_encode(['success' => true, 'text' => trim(implode("\n", $lines))]);
            break;
        case 'leagues':
            // Listar todas as ligas com configurações
            $stmtLeagues = $pdo->query("SELECT name FROM leagues ORDER BY FIELD(name,'ELITE','NEXT','RISE','ROOKIE')");
            $leagues = $stmtLeagues->fetchAll(PDO::FETCH_COLUMN);

            $result = [];
            foreach ($leagues as $league) {
                $stmtCfg = $pdo->prepare('SELECT cap_min, cap_max, max_trades, edital, trades_enabled, fa_enabled FROM league_settings WHERE league = ?');
                $stmtCfg->execute([$league]);
                $cfg = $stmtCfg->fetch() ?: ['cap_min' => 0, 'cap_max' => 0, 'max_trades' => 3, 'edital' => null, 'trades_enabled' => 1, 'fa_enabled' => 1];

                $stmtTeams = $pdo->prepare('SELECT COUNT(*) as total FROM teams WHERE league = ?');
                $stmtTeams->execute([$league]);
                $teamCount = $stmtTeams->fetch()['total'];

                $result[] = [
                    'league' => $league,
                    'cap_min' => (int)$cfg['cap_min'],
                    'cap_max' => (int)$cfg['cap_max'],
                    'max_trades' => (int)$cfg['max_trades'],
                    'edital' => $cfg['edital'],
                    'edital_file' => $cfg['edital'],
                    'trades_enabled' => (int)($cfg['trades_enabled'] ?? 1),
                    'fa_enabled' => (int)($cfg['fa_enabled'] ?? 1),
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
                    COALESCE(t.tapas, 0) as tapas,
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

        case 'tapas':
            // Listar times com tapas
            $league = isset($_GET['league']) ? strtoupper($_GET['league']) : null;

            $query = "
                SELECT 
                    t.id, t.city, t.name, t.league,
                    COALESCE(t.tapas, 0) as tapas,
                    u.name as owner_name
                FROM teams t
                JOIN users u ON t.user_id = u.id
            ";

            if ($league && in_array($league, $validLeagues, true)) {
                $query .= " WHERE t.league = ? ORDER BY t.tapas DESC, t.city, t.name";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$league]);
            } else {
                $query .= " ORDER BY FIELD(t.league,'ELITE','NEXT','RISE','ROOKIE'), t.tapas DESC, t.city, t.name";
                $stmt = $pdo->query($query);
            }

            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'teams' => $teams, 'league' => $league]);
            break;

        case 'trades':
            // Listar todas as trades
            $status = $_GET['status'] ?? 'all'; // all, pending, accepted, rejected, cancelled
            $league = $_GET['league'] ?? null;
            $seasonYear = $_GET['season_year'] ?? null;
            
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

            if ($seasonYear) {
                $conditions[] = "YEAR(t.created_at) = ?";
                $params[] = (int)$seasonYear;
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
                $ovrCol = playerOvrColumn($pdo);
                $stmtOfferPlayers = $pdo->prepare("SELECT 
                    COALESCE(p.id, ti.player_id) AS id,
                    COALESCE(p.name, ti.player_name, CONCAT('Jogador #', ti.player_id)) AS name,
                    COALESCE(p.position, ti.player_position) AS position,
                    COALESCE(p.age, ti.player_age) AS age,
                    COALESCE(p.{$ovrCol}, ti.player_ovr) AS ovr
                 FROM trade_items ti
                 LEFT JOIN players p ON p.id = ti.player_id
                 WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.player_id IS NOT NULL");
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
                
                $stmtRequestPlayers = $pdo->prepare("SELECT 
                    COALESCE(p.id, ti.player_id) AS id,
                    COALESCE(p.name, ti.player_name, CONCAT('Jogador #', ti.player_id)) AS name,
                    COALESCE(p.position, ti.player_position) AS position,
                    COALESCE(p.age, ti.player_age) AS age,
                    COALESCE(p.{$ovrCol}, ti.player_ovr) AS ovr
                 FROM trade_items ti
                 LEFT JOIN players p ON p.id = ti.player_id
                 WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.player_id IS NOT NULL");
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

            $multiTrades = [];
            if (tableExists($pdo, 'multi_trades') && tableExists($pdo, 'multi_trade_items') && tableExists($pdo, 'multi_trade_teams')) {
                $multiConditions = [];
                $multiParams = [];

                if ($status !== 'all') {
                    if ($status === 'rejected') {
                        $multiConditions[] = '1 = 0';
                    } else {
                        $multiConditions[] = 'mt.status = ?';
                        $multiParams[] = $status;
                    }
                }

                if ($league) {
                    $multiConditions[] = 'COALESCE(mt.league, creator.league) = ?';
                    $multiParams[] = $league;
                }

                if ($seasonYear) {
                    $multiConditions[] = 'YEAR(mt.created_at) = ?';
                    $multiParams[] = (int)$seasonYear;
                }

                $multiWhere = !empty($multiConditions) ? 'WHERE ' . implode(' AND ', $multiConditions) : '';
                $multiQuery = "
                    SELECT
                        mt.*,
                        COALESCE(mt.league, creator.league) AS league,
                        creator.city AS creator_city,
                        creator.name AS creator_name,
                        (SELECT COUNT(*) FROM multi_trade_teams WHERE trade_id = mt.id) AS teams_total,
                        (SELECT COUNT(*) FROM multi_trade_teams WHERE trade_id = mt.id AND accepted_at IS NOT NULL) AS teams_accepted
                    FROM multi_trades mt
                    JOIN teams creator ON mt.created_by_team_id = creator.id
                    {$multiWhere}
                    ORDER BY mt.created_at DESC
                    LIMIT 100
                ";

                $stmtMulti = $pdo->prepare($multiQuery);
                $stmtMulti->execute($multiParams);
                $multiTrades = $stmtMulti->fetchAll(PDO::FETCH_ASSOC);

                $ovrCol = playerOvrColumn($pdo);
                $stmtTeams = $pdo->prepare('SELECT t.id, t.city, t.name FROM multi_trade_teams mtt JOIN teams t ON t.id = mtt.team_id WHERE mtt.trade_id = ?');
                $stmtItems = $pdo->prepare('SELECT * FROM multi_trade_items WHERE trade_id = ?');
                $stmtPickInfo = $pdo->prepare('SELECT pk.*, t.city as original_team_city, t.name as original_team_name, lo.city as last_owner_city, lo.name as last_owner_name FROM picks pk JOIN teams t ON pk.original_team_id = t.id LEFT JOIN teams lo ON pk.last_owner_team_id = lo.id WHERE pk.id = ?');

                foreach ($multiTrades as &$trade) {
                    $trade['is_multi'] = true;

                    $stmtTeams->execute([$trade['id']]);
                    $trade['teams'] = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

                    $stmtItems->execute([$trade['id']]);
                    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($items as &$item) {
                        if (!empty($item['player_id']) && (empty($item['player_name']) || empty($item['player_ovr']))) {
                            $stmtP = $pdo->prepare("SELECT name, position, age, {$ovrCol} AS ovr FROM players WHERE id = ?");
                            $stmtP->execute([(int)$item['player_id']]);
                            $p = $stmtP->fetch(PDO::FETCH_ASSOC) ?: [];
                            $item['player_name'] = $item['player_name'] ?: ($p['name'] ?? null);
                            $item['player_position'] = $item['player_position'] ?: ($p['position'] ?? null);
                            $item['player_age'] = $item['player_age'] ?: ($p['age'] ?? null);
                            $item['player_ovr'] = $item['player_ovr'] ?: ($p['ovr'] ?? null);
                        }
                        if (!empty($item['pick_id'])) {
                            $stmtPickInfo->execute([(int)$item['pick_id']]);
                            $pick = $stmtPickInfo->fetch(PDO::FETCH_ASSOC) ?: [];
                            $item['season_year'] = $pick['season_year'] ?? null;
                            $item['round'] = $pick['round'] ?? null;
                            $item['original_team_id'] = $pick['original_team_id'] ?? null;
                            $item['original_team_city'] = $pick['original_team_city'] ?? null;
                            $item['original_team_name'] = $pick['original_team_name'] ?? null;
                            $item['last_owner_team_id'] = $pick['last_owner_team_id'] ?? null;
                            $item['last_owner_city'] = $pick['last_owner_city'] ?? null;
                            $item['last_owner_name'] = $pick['last_owner_name'] ?? null;
                        }
                    }
                    unset($item);

                    $trade['items'] = $items;
                }
                unset($trade);
            }

            if (!empty($multiTrades)) {
                $trades = array_merge($trades, $multiTrades);
                usort($trades, static function ($a, $b) {
                    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
                });
            }

            echo json_encode(['success' => true, 'trades' => $trades]);
            break;

        case 'hall_of_fame':
            ensureHallOfFameTable($pdo);
            if ($method === 'GET') {
                $query = "
                    SELECT
                        hof.*,
                        t.city AS team_city,
                        t.name AS team_name_live,
                        u.name AS gm_name_live
                    FROM hall_of_fame hof
                    LEFT JOIN teams t ON hof.team_id = t.id
                    LEFT JOIN users u ON t.user_id = u.id
                    ORDER BY hof.titles DESC, COALESCE(hof.team_name, t.name) ASC, hof.id DESC
                ";
                $stmt = $pdo->query($query);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $items = array_map(static function (array $row): array {
                    $isActive = (int)($row['is_active'] ?? 0) === 1;
                    $teamName = $isActive
                        ? trim(($row['team_city'] ?? '') . ' ' . ($row['team_name_live'] ?? ''))
                        : (string)($row['team_name'] ?? '');
                    if ($teamName === '') {
                        $teamName = (string)($row['team_name'] ?? '');
                    }
                    $gmName = $isActive ? ($row['gm_name_live'] ?? '') : ($row['gm_name'] ?? '');
                    if (!$gmName) {
                        $gmName = $row['gm_name'] ?? '';
                    }
                    return [
                        'id' => (int)$row['id'],
                        'is_active' => $isActive ? 1 : 0,
                        'league' => $row['league'] ?? null,
                        'team_id' => isset($row['team_id']) ? (int)$row['team_id'] : null,
                        'team_name' => $teamName,
                        'gm_name' => $gmName,
                        'titles' => (int)($row['titles'] ?? 0)
                    ];
                }, $rows);

                echo json_encode(['success' => true, 'items' => $items]);
                break;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if ($method === 'POST') {
                $isActive = (int)($data['is_active'] ?? 0) === 1;
                $titles = isset($data['titles']) ? (int)$data['titles'] : 0;

                if ($titles < 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Titulos invalidos']);
                    break;
                }

                $league = $data['league'] ?? null;
                $teamId = isset($data['team_id']) ? (int)$data['team_id'] : null;
                $teamName = trim((string)($data['team_name'] ?? ''));
                $gmName = trim((string)($data['gm_name'] ?? ''));

                if ($isActive) {
                    if (!$teamId) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'error' => 'Time obrigatorio']);
                        break;
                    }
                    $stmtTeam = $pdo->prepare('SELECT t.league, t.city, t.name, u.name AS owner_name FROM teams t JOIN users u ON t.user_id = u.id WHERE t.id = ?');
                    $stmtTeam->execute([$teamId]);
                    $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
                    if (!$team) {
                        http_response_code(404);
                        echo json_encode(['success' => false, 'error' => 'Time nao encontrado']);
                        break;
                    }
                    $league = $team['league'] ?? $league;
                    $teamName = trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''));
                    $gmName = $team['owner_name'] ?? '';
                } else {
                    if ($gmName === '') {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'error' => 'Nome do GM obrigatorio']);
                        break;
                    }
                }

                $stmt = $pdo->prepare('INSERT INTO hall_of_fame (is_active, league, team_id, team_name, gm_name, titles) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $isActive ? 1 : 0,
                    $league ?: null,
                    $isActive ? $teamId : null,
                    $teamName ?: null,
                    $gmName ?: null,
                    $titles
                ]);

                echo json_encode(['success' => true]);
                break;
            }

            if ($method === 'PUT') {
                $id = isset($data['id']) ? (int)$data['id'] : 0;
                if ($id <= 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'ID obrigatorio']);
                    break;
                }
                $titles = isset($data['titles']) ? (int)$data['titles'] : null;
                if ($titles !== null && $titles < 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Titulos invalidos']);
                    break;
                }

                $fields = [];
                $params = [];
                if ($titles !== null) {
                    $fields[] = 'titles = ?';
                    $params[] = $titles;
                }

                $teamName = isset($data['team_name']) ? trim((string)$data['team_name']) : null;
                $gmName = isset($data['gm_name']) ? trim((string)$data['gm_name']) : null;
                if ($teamName !== null) {
                    $fields[] = 'team_name = ?';
                    $params[] = $teamName;
                }
                if ($gmName !== null) {
                    $fields[] = 'gm_name = ?';
                    $params[] = $gmName;
                }

                $params[] = $id;
                if (empty($fields)) {
                    echo json_encode(['success' => true]);
                    break;
                }

                $stmt = $pdo->prepare('UPDATE hall_of_fame SET ' . implode(', ', $fields) . ' WHERE id = ?');
                $stmt->execute($params);
                echo json_encode(['success' => true]);
                break;
            }

            if ($method === 'DELETE') {
                $id = isset($data['id']) ? (int)$data['id'] : 0;
                if ($id <= 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'ID obrigatorio']);
                    break;
                }
                $stmt = $pdo->prepare('DELETE FROM hall_of_fame WHERE id = ?');
                $stmt->execute([$id]);
                echo json_encode(['success' => true]);
                break;
            }

            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Metodo nao suportado']);
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

        case 'coins':
            // Listar times com moedas
            $league = isset($_GET['league']) ? strtoupper($_GET['league']) : null;
            
            $query = "
                SELECT 
                    t.id, t.city, t.name, t.league, 
                    COALESCE(t.moedas, 0) as moedas,
                    u.name as owner_name
                FROM teams t
                JOIN users u ON t.user_id = u.id
            ";
            
            if ($league && in_array($league, $validLeagues, true)) {
                $query .= " WHERE t.league = ? ORDER BY t.moedas DESC, t.city, t.name";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$league]);
            } else {
                $query .= " ORDER BY FIELD(t.league,'ELITE','NEXT','RISE','ROOKIE'), t.moedas DESC, t.city, t.name";
                $stmt = $pdo->query($query);
            }
            
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'teams' => $teams, 'league' => $league]);
            break;

        case 'coins_log':
            // Histórico de moedas de um time
            $teamId = $_GET['team_id'] ?? null;
            if (!$teamId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Team ID obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT * FROM team_coins_log 
                WHERE team_id = ? 
                ORDER BY created_at DESC 
                LIMIT 50
            ");
            $stmt->execute([$teamId]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'logs' => $logs]);
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
        case 'hall_of_fame':
            ensureHallOfFameTable($pdo);
            $id = isset($data['id']) ? (int)$data['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID obrigatorio']);
                exit;
            }

            $titles = isset($data['titles']) ? (int)$data['titles'] : null;
            if ($titles !== null && $titles < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Titulos invalidos']);
                exit;
            }

            $fields = [];
            $params = [];
            if ($titles !== null) {
                $fields[] = 'titles = ?';
                $params[] = $titles;
            }

            $teamName = isset($data['team_name']) ? trim((string)$data['team_name']) : null;
            $gmName = isset($data['gm_name']) ? trim((string)$data['gm_name']) : null;
            if ($teamName !== null) {
                $fields[] = 'team_name = ?';
                $params[] = $teamName;
            }
            if ($gmName !== null) {
                $fields[] = 'gm_name = ?';
                $params[] = $gmName;
            }

            if (empty($fields)) {
                echo json_encode(['success' => true]);
                exit;
            }

            $params[] = $id;
            $stmt = $pdo->prepare('UPDATE hall_of_fame SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->execute($params);
            echo json_encode(['success' => true]);
            exit;

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
            $trades_enabled = isset($data['trades_enabled']) ? (int)$data['trades_enabled'] : null;
            $fa_enabled = isset($data['fa_enabled']) ? (int)$data['fa_enabled'] : null;

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
            if ($trades_enabled !== null) {
                $updates[] = 'trades_enabled = ?';
                $params[] = $trades_enabled;
            }
            if ($fa_enabled !== null) {
                $updates[] = 'fa_enabled = ?';
                $params[] = $fa_enabled;
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
            $age = $data['age'] ?? null;
            $secondaryPosition = $data['secondary_position'] ?? null;

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
            if ($age !== null) {
                $updates[] = 'age = ?';
                $params[] = $age;
            }
            if ($secondaryPosition !== null) {
                $updates[] = 'secondary_position = ?';
                $params[] = $secondaryPosition ?: null;
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

                $playersReverted = [];
                $picksReverted = [];
                $errors = [];

                // Reverter transferências
                foreach ($items as $item) {
                    if ($item['player_id']) {
                        // Reverter jogador para o time original
                        $originalTeamId = $item['from_team'] ? $trade['from_team_id'] : $trade['to_team_id'];
                        
                        // Verificar time atual do jogador
                        $stmtCheckPlayer = $pdo->prepare('SELECT team_id, name FROM players WHERE id = ?');
                        $stmtCheckPlayer->execute([$item['player_id']]);
                        $player = $stmtCheckPlayer->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$player) {
                            $errors[] = "Jogador ID {$item['player_id']} não encontrado (pode ter sido dispensado)";
                            continue;
                        }
                        
                        // Só reverter se o jogador não estiver já no time original (evita duplicação)
                        if ((int)$player['team_id'] !== (int)$originalTeamId) {
                            $stmtRevert = $pdo->prepare('UPDATE players SET team_id = ? WHERE id = ?');
                            $stmtRevert->execute([$originalTeamId, $item['player_id']]);
                            $playersReverted[] = $player['name'];
                        } else {
                            // Jogador já está no time original (pode ter sido revertido antes)
                            $playersReverted[] = $player['name'] . ' (já estava no time)';
                        }
                    }

                    if ($item['pick_id']) {
                        // Reverter pick para o time original
                        $originalTeamId = $item['from_team'] ? $trade['from_team_id'] : $trade['to_team_id'];
                        
                        // Verificar estado atual da pick
                        $stmtCheckPick = $pdo->prepare('SELECT team_id, original_team_id, last_owner_team_id, season_year, round FROM picks WHERE id = ?');
                        $stmtCheckPick->execute([$item['pick_id']]);
                        $pick = $stmtCheckPick->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$pick) {
                            $errors[] = "Pick ID {$item['pick_id']} não encontrada";
                            continue;
                        }
                        
                        // Só reverter se a pick não estiver já no time original
                        if ((int)$pick['team_id'] !== (int)$originalTeamId) {
                            // O last_owner deve ser quem tinha antes da trade atual
                            // Se from_team=true, o dono original era from_team, então last_owner deve ser NULL ou from_team
                            // Se from_team=false, o dono original era to_team
                            $lastOwnerBeforeTrade = $item['from_team'] ? null : $trade['to_team_id'];
                            
                            $stmtRevert = $pdo->prepare('UPDATE picks SET team_id = ?, last_owner_team_id = ? WHERE id = ?');
                            $stmtRevert->execute([$originalTeamId, $lastOwnerBeforeTrade, $item['pick_id']]);
                            $picksReverted[] = "{$pick['season_year']} R{$pick['round']}";
                        } else {
                            $picksReverted[] = "{$pick['season_year']} R{$pick['round']} (já estava no time)";
                        }
                    }
                }

                // Atualizar status da trade
                $revertLog = "[Admin] Trade revertida em " . date('Y-m-d H:i:s');
                if (!empty($playersReverted)) {
                    $revertLog .= "\nJogadores revertidos: " . implode(', ', $playersReverted);
                }
                if (!empty($picksReverted)) {
                    $revertLog .= "\nPicks revertidas: " . implode(', ', $picksReverted);
                }
                if (!empty($errors)) {
                    $revertLog .= "\nAvisos: " . implode('; ', $errors);
                }
                
                $stmtUpdate = $pdo->prepare("UPDATE trades SET status = 'cancelled', notes = CONCAT(IFNULL(notes, ''), '\n', ?) WHERE id = ?");
                $stmtUpdate->execute([$revertLog, $tradeId]);

                $pdo->commit();
                
                $response = [
                    'success' => true,
                    'message' => 'Trade revertida com sucesso',
                    'players_reverted' => count($playersReverted),
                    'picks_reverted' => count($picksReverted)
                ];
                
                if (!empty($errors)) {
                    $response['warnings'] = $errors;
                }
                
                echo json_encode($response);
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
        case 'hall_of_fame':
            ensureHallOfFameTable($pdo);
            $isActive = (int)($data['is_active'] ?? 0) === 1;
            $titles = isset($data['titles']) ? (int)$data['titles'] : 0;

            if ($titles < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Titulos invalidos']);
                exit;
            }

            $league = $data['league'] ?? null;
            $teamId = isset($data['team_id']) ? (int)$data['team_id'] : null;
            $teamName = trim((string)($data['team_name'] ?? ''));
            $gmName = trim((string)($data['gm_name'] ?? ''));

            if ($isActive) {
                if (!$teamId) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Time obrigatorio']);
                    exit;
                }
                $stmtTeam = $pdo->prepare('SELECT t.league, t.city, t.name, u.name AS owner_name FROM teams t JOIN users u ON t.user_id = u.id WHERE t.id = ?');
                $stmtTeam->execute([$teamId]);
                $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
                if (!$team) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Time nao encontrado']);
                    exit;
                }
                $league = $team['league'] ?? $league;
                $teamName = trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''));
                $gmName = $team['owner_name'] ?? '';
            } else {
                if ($gmName === '') {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Nome do GM obrigatorio']);
                    exit;
                }
            }

            $stmt = $pdo->prepare('INSERT INTO hall_of_fame (is_active, league, team_id, team_name, gm_name, titles) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $isActive ? 1 : 0,
                $league ?: null,
                $isActive ? $teamId : null,
                $teamName ?: null,
                $gmName ?: null,
                $titles
            ]);

            echo json_encode(['success' => true]);
            break;

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

        case 'coins':
            // Adicionar ou remover moedas de um time
            $teamId = $data['team_id'] ?? null;
            $amount = $data['amount'] ?? null;
            $reason = $data['reason'] ?? 'Ajuste administrativo';
            $operation = $data['operation'] ?? 'add'; // add ou remove

            if (!$teamId || $amount === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Team ID e quantidade são obrigatórios']);
                exit;
            }

            $amount = (int)$amount;
            if ($amount <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Quantidade deve ser maior que zero']);
                exit;
            }

            // Buscar time
            $stmtTeam = $pdo->prepare('SELECT id, city, name, COALESCE(moedas, 0) as moedas FROM teams WHERE id = ?');
            $stmtTeam->execute([$teamId]);
            $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);

            if (!$team) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
                exit;
            }

            $currentCoins = (int)$team['moedas'];
            
            if ($operation === 'remove') {
                if ($currentCoins < $amount) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Time não tem moedas suficientes']);
                    exit;
                }
                $newBalance = $currentCoins - $amount;
                $logAmount = -$amount;
                $logType = 'admin_remove';
            } else {
                $newBalance = $currentCoins + $amount;
                $logAmount = $amount;
                $logType = 'admin_add';
            }

            try {
                $pdo->beginTransaction();

                // Atualizar moedas do time
                $stmtUpdate = $pdo->prepare('UPDATE teams SET moedas = ? WHERE id = ?');
                $stmtUpdate->execute([$newBalance, $teamId]);

                // Registrar no log
                $stmtLog = $pdo->prepare('
                    INSERT INTO team_coins_log (team_id, amount, balance_after, reason, type)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmtLog->execute([$teamId, $logAmount, $newBalance, $reason, $logType]);

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => sprintf(
                        '%s %d moedas %s %s %s. Novo saldo: %d',
                        $operation === 'add' ? 'Adicionadas' : 'Removidas',
                        $amount,
                        $operation === 'add' ? 'para' : 'de',
                        $team['city'],
                        $team['name'],
                        $newBalance
                    ),
                    'new_balance' => $newBalance
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro ao atualizar moedas: ' . $e->getMessage()]);
            }
            break;

        case 'tapas':
            // Definir quantidade de tapas de um time
            $teamId = $data['team_id'] ?? null;
            $amount = $data['amount'] ?? null;
            $operation = $data['operation'] ?? 'set'; // set | add | remove

            if (!$teamId || $amount === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Team ID e quantidade são obrigatórios']);
                exit;
            }

            $amount = (int)$amount;
            if ($amount < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Quantidade deve ser maior ou igual a zero']);
                exit;
            }

            $stmtTeam = $pdo->prepare('SELECT id, city, name, COALESCE(tapas, 0) as tapas FROM teams WHERE id = ?');
            $stmtTeam->execute([$teamId]);
            $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);

            if (!$team) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
                exit;
            }

            $currentTapas = (int)($team['tapas'] ?? 0);
            if ($operation === 'add') {
                $newBalance = $currentTapas + $amount;
            } elseif ($operation === 'remove') {
                $newBalance = max(0, $currentTapas - $amount);
            } else {
                $newBalance = $amount;
            }

            try {
                $stmtUpdate = $pdo->prepare('UPDATE teams SET tapas = ? WHERE id = ?');
                $stmtUpdate->execute([$newBalance, $teamId]);

                echo json_encode([
                    'success' => true,
                    'message' => sprintf('Tapas atualizados para %s %s: %d', $team['city'], $team['name'], $newBalance),
                    'new_balance' => $newBalance
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro ao atualizar tapas: ' . $e->getMessage()]);
            }
            break;

        case 'coins_bulk':
            // Adicionar moedas em massa para todos os times de uma liga
            $league = $data['league'] ?? null;
            $amount = $data['amount'] ?? null;
            $reason = $data['reason'] ?? 'Distribuição de moedas';

            if (!$league || $amount === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga e quantidade são obrigatórios']);
                exit;
            }

            if (!in_array(strtoupper($league), $validLeagues, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga inválida']);
                exit;
            }

            $amount = (int)$amount;
            if ($amount <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Quantidade deve ser maior que zero']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                // Buscar todos os times da liga
                $stmtTeams = $pdo->prepare('SELECT id, COALESCE(moedas, 0) as moedas FROM teams WHERE league = ?');
                $stmtTeams->execute([strtoupper($league)]);
                $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

                $count = 0;
                foreach ($teams as $team) {
                    $newBalance = (int)$team['moedas'] + $amount;
                    
                    // Atualizar moedas do time
                    $stmtUpdate = $pdo->prepare('UPDATE teams SET moedas = ? WHERE id = ?');
                    $stmtUpdate->execute([$newBalance, $team['id']]);

                    // Registrar no log
                    $stmtLog = $pdo->prepare('
                        INSERT INTO team_coins_log (team_id, amount, balance_after, reason, type)
                        VALUES (?, ?, ?, ?, ?)
                    ');
                    $stmtLog->execute([$team['id'], $amount, $newBalance, $reason, 'admin_bulk']);
                    $count++;
                }

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => sprintf('Adicionadas %d moedas para %d times da liga %s', $amount, $count, strtoupper($league)),
                    'teams_updated' => $count
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro ao distribuir moedas: ' . $e->getMessage()]);
            }
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
        case 'hall_of_fame':
            $body = json_decode(file_get_contents('php://input'), true);
            $id = isset($body['id']) ? (int)$body['id'] : (int)$id;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID obrigatorio']);
                exit;
            }
            ensureHallOfFameTable($pdo);
            $stmt = $pdo->prepare('DELETE FROM hall_of_fame WHERE id = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;

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
