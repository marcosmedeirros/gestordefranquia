<?php
/**
 * API Free Agency - Propostas com moedas
 */

session_start();
require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nao autorizado']);
    exit;
}

$pdo = db();
ensureTeamFreeAgencyColumns($pdo);

$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? (($_SESSION['user_type'] ?? '') === 'admin');
$team_id = $_SESSION['team_id'] ?? null;

$team = null;
if ($team_id) {
    $stmt = $pdo->prepare('SELECT id, league, COALESCE(moedas, 0) as moedas, waivers_used, fa_signings_used FROM teams WHERE id = ?');
    $stmt->execute([$team_id]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
}

$team_league = $team['league'] ?? ($_SESSION['user_league'] ?? null);
$team_coins = (int)($team['moedas'] ?? 0);
$valid_leagues = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

if (!$team && $user_id) {
    $stmt = $pdo->prepare('SELECT id, league, COALESCE(moedas, 0) as moedas, waivers_used, fa_signings_used FROM teams WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($team) {
        $team_id = (int)$team['id'];
        $team_league = $team['league'] ?? $team_league;
        $team_coins = (int)$team['moedas'];
    }
}

function jsonError(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function jsonSuccess(array $payload = []): void
{
    echo json_encode(array_merge(['success' => true], $payload));
    exit;
}

function tableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    $cache[$table] = $stmt->rowCount() > 0;
    return $cache[$table];
}

function freeAgentsUseLeagueId(PDO $pdo): bool
{
    return columnExists($pdo, 'free_agents', 'league_id') && !columnExists($pdo, 'free_agents', 'league');
}

function freeAgentsUseLeagueEnum(PDO $pdo): bool
{
    return columnExists($pdo, 'free_agents', 'league');
}

function freeAgentOvrColumn(PDO $pdo): string
{
    return columnExists($pdo, 'free_agents', 'ovr') ? 'ovr' : 'overall';
}

function freeAgentSecondaryColumn(PDO $pdo): ?string
{
    return columnExists($pdo, 'free_agents', 'secondary_position') ? 'secondary_position' : null;
}

function resolveLeagueId(PDO $pdo, string $leagueName): ?int
{
    if (!tableExists($pdo, 'leagues')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM leagues WHERE UPPER(name) = ? LIMIT 1');
    $stmt->execute([strtoupper($leagueName)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

function resolveLeagueName(PDO $pdo, int $leagueId): ?string
{
    if (!tableExists($pdo, 'leagues')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT name FROM leagues WHERE id = ? LIMIT 1');
    $stmt->execute([$leagueId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['name'] : null;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . ':' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
    $stmt->execute([$column]);
    $cache[$key] = $stmt->rowCount() > 0;
    return $cache[$key];
}

function ensureOfferAmountColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        if (!columnExists($pdo, 'free_agent_offers', 'amount')) {
            $pdo->exec('ALTER TABLE free_agent_offers ADD COLUMN amount INT NOT NULL DEFAULT 0 AFTER team_id');
        }
    } catch (Exception $e) {
        error_log('[free-agency] amount column: ' . $e->getMessage());
    }

    $checked = true;
}

function getLeagueFromRequest(array $validLeagues, ?string $fallback = null): ?string
{
    $league = strtoupper(trim((string)($_GET['league'] ?? $fallback ?? '')));
    if (!$league) {
        return null;
    }
    if (!in_array($league, $validLeagues, true)) {
        return null;
    }
    return $league;
}

// GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'list':
            $league = getLeagueFromRequest($valid_leagues, $team_league);
            listFreeAgents($pdo, $league, $team_id);
            break;
        case 'my_offers':
            listMyOffers($pdo, $team_id);
            break;
        case 'admin_free_agents':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            $league = getLeagueFromRequest($valid_leagues, null);
            if (!$league) {
                jsonError('Liga invalida');
            }
            listAdminFreeAgents($pdo, $league);
            break;
        case 'admin_offers':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            $league = getLeagueFromRequest($valid_leagues, null);
            if (!$league) {
                jsonError('Liga invalida');
            }
            listAdminOffers($pdo, $league);
            break;
        case 'admin_contracts':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            $league = getLeagueFromRequest($valid_leagues, null);
            if (!$league) {
                jsonError('Liga invalida');
            }
            listAdminContracts($pdo, $league);
            break;
        case 'limits':
            freeAgencyLimits($team);
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
            jsonError('Acao nao reconhecida');
    }
}

// POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';

    switch ($action) {
        case 'add_player':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            addPlayer($pdo, $body);
            break;
        case 'remove_player':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            removePlayer($pdo, $body);
            break;
        case 'place_offer':
            placeOffer($pdo, $body, $team_id, $team_league, $team_coins);
            break;
        case 'approve_offer':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            approveOffer($pdo, $body, $user_id);
            break;
        default:
            jsonError('Acao nao reconhecida');
    }
}

jsonError('Metodo nao permitido', 405);

// ========== GET ==========

function listFreeAgents(PDO $pdo, ?string $league, ?int $teamId): void
{
    if (!$league) {
        jsonSuccess(['players' => []]);
    }

    $ovrColumn = freeAgentOvrColumn($pdo);
    $secondaryColumn = freeAgentSecondaryColumn($pdo);
    $fields = "fa.id, fa.name, fa.age, fa.position, fa.{$ovrColumn} AS ovr";
    if ($secondaryColumn) {
        $fields .= ", fa.{$secondaryColumn} AS secondary_position";
    } else {
        $fields .= ", NULL AS secondary_position";
    }
    $fields .= ", fa.original_team_name";
    $params = [];
    $where = '(fa.status = "available" OR fa.status IS NULL)';

    if (freeAgentsUseLeagueEnum($pdo) && columnExists($pdo, 'free_agents', 'league_id')) {
        $leagueId = resolveLeagueId($pdo, $league);
        $where .= ' AND (fa.league = ?' . ($leagueId ? ' OR fa.league_id = ?' : '') . ')';
        $params[] = $league;
        if ($leagueId) {
            $params[] = $leagueId;
        }
    } elseif (freeAgentsUseLeagueEnum($pdo)) {
        $where .= ' AND fa.league = ?';
        $params[] = $league;
    } elseif (freeAgentsUseLeagueId($pdo)) {
        $leagueId = resolveLeagueId($pdo, $league);
        if (!$leagueId) {
            jsonSuccess(['players' => []]);
        }
        $where .= ' AND fa.league_id = ?';
        $params[] = $leagueId;
    }

    if ($teamId) {
        $fields .= ', (SELECT amount FROM free_agent_offers WHERE free_agent_id = fa.id AND team_id = ? AND status = "pending" LIMIT 1) AS my_offer_amount';
        array_unshift($params, $teamId);
    }

    $stmt = $pdo->prepare("
        SELECT {$fields}
        FROM free_agents fa
        WHERE {$where}
        ORDER BY fa.{$ovrColumn} DESC, fa.name ASC
    ");
    $stmt->execute($params);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess(['league' => $league, 'players' => $players]);
}

function listMyOffers(PDO $pdo, ?int $teamId): void
{
    if (!$teamId) {
        jsonSuccess(['offers' => []]);
    }

    ensureOfferAmountColumn($pdo);
    $ovrColumn = freeAgentOvrColumn($pdo);

    $stmt = $pdo->prepare('
        SELECT fao.id, fao.amount, fao.status, fao.created_at,
               fa.name AS player_name, fa.position, fa.' . $ovrColumn . ' AS ovr
        FROM free_agent_offers fao
        JOIN free_agents fa ON fao.free_agent_id = fa.id
        WHERE fao.team_id = ?
        ORDER BY fao.created_at DESC
    ');
    $stmt->execute([$teamId]);
    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess(['offers' => $offers]);
}

function listAdminFreeAgents(PDO $pdo, string $league): void
{
    $ovrColumn = freeAgentOvrColumn($pdo);
    $secondaryColumn = freeAgentSecondaryColumn($pdo);
    $where = '(fa.status = "available" OR fa.status IS NULL)';
    $params = [];

    if (freeAgentsUseLeagueEnum($pdo) && columnExists($pdo, 'free_agents', 'league_id')) {
        $leagueId = resolveLeagueId($pdo, $league);
        $where .= ' AND (fa.league = ?' . ($leagueId ? ' OR fa.league_id = ?' : '') . ')';
        $params[] = $league;
        if ($leagueId) {
            $params[] = $leagueId;
        }
    } elseif (freeAgentsUseLeagueEnum($pdo)) {
        $where .= ' AND fa.league = ?';
        $params[] = $league;
    } elseif (freeAgentsUseLeagueId($pdo)) {
        $leagueId = resolveLeagueId($pdo, $league);
        if (!$leagueId) {
            jsonSuccess(['league' => $league, 'players' => []]);
        }
        $where .= ' AND fa.league_id = ?';
        $params[] = $leagueId;
    }

    $select = "fa.id, fa.name, fa.age, fa.position, fa.{$ovrColumn} AS ovr";
    if ($secondaryColumn) {
        $select .= ", fa.{$secondaryColumn} AS secondary_position";
    } else {
        $select .= ", NULL AS secondary_position";
    }
    $select .= ", fa.original_team_name";
    $stmt = $pdo->prepare("
        SELECT {$select}, (
            SELECT COUNT(*) FROM free_agent_offers
            WHERE free_agent_id = fa.id AND status = 'pending'
        ) AS pending_offers
        FROM free_agents fa
        WHERE {$where}
        ORDER BY fa.{$ovrColumn} DESC, fa.name ASC
    ");
    $stmt->execute($params);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess(['league' => $league, 'players' => $players]);
}

function listAdminOffers(PDO $pdo, string $league): void
{
    ensureOfferAmountColumn($pdo);

    $ovrColumn = freeAgentOvrColumn($pdo);
    $secondaryColumn = freeAgentSecondaryColumn($pdo);
    $where = '';
    $params = [];
    if (freeAgentsUseLeagueEnum($pdo) && columnExists($pdo, 'free_agents', 'league_id')) {
        $leagueId = resolveLeagueId($pdo, $league);
        $where = '(fa.league = ?' . ($leagueId ? ' OR fa.league_id = ?' : '') . ')';
        $params[] = $league;
        if ($leagueId) {
            $params[] = $leagueId;
        }
    } elseif (freeAgentsUseLeagueEnum($pdo)) {
        $where = 'fa.league = ?';
        $params[] = $league;
    } elseif (freeAgentsUseLeagueId($pdo)) {
        $leagueId = resolveLeagueId($pdo, $league);
        if (!$leagueId) {
            jsonSuccess(['league' => $league, 'players' => []]);
        }
        $where = 'fa.league_id = ?';
        $params[] = $leagueId;
    }

    $secondarySelect = $secondaryColumn ? "fa.{$secondaryColumn}" : "NULL";
    $stmt = $pdo->prepare("
        SELECT fao.id, fao.free_agent_id, fao.team_id, fao.amount, fao.status, fao.created_at,
               fa.name AS player_name, fa.position, {$secondarySelect} AS secondary_position, fa.{$ovrColumn} AS ovr, fa.age, fa.original_team_name,
               t.city AS team_city, t.name AS team_name
        FROM free_agent_offers fao
        JOIN free_agents fa ON fao.free_agent_id = fa.id
        JOIN teams t ON fao.team_id = t.id
        WHERE {$where} AND fao.status = 'pending'
        ORDER BY fa.name ASC, fao.created_at ASC
    ");
    $stmt->execute($params);
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
                    'secondary_position' => $offer['secondary_position'],
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
            'team_name' => trim(($offer['team_city'] ?? '') . ' ' . ($offer['team_name'] ?? '')),
            'amount' => $offer['amount'],
            'created_at' => $offer['created_at']
        ];
    }

    jsonSuccess(['league' => $league, 'players' => array_values($grouped)]);
}

function listAdminContracts(PDO $pdo, string $league): void
{
    $ovrColumn = freeAgentOvrColumn($pdo);
    $secondaryColumn = freeAgentSecondaryColumn($pdo);
    $where = '(fa.status = "signed" OR fa.winner_team_id IS NOT NULL)';
    $params = [];

    if (freeAgentsUseLeagueEnum($pdo) && columnExists($pdo, 'free_agents', 'league_id')) {
        $leagueId = resolveLeagueId($pdo, $league);
        $where .= ' AND (fa.league = ?' . ($leagueId ? ' OR fa.league_id = ?' : '') . ')';
        $params[] = $league;
        if ($leagueId) {
            $params[] = $leagueId;
        }
    } elseif (freeAgentsUseLeagueEnum($pdo)) {
        $where .= ' AND fa.league = ?';
        $params[] = $league;
    } elseif (freeAgentsUseLeagueId($pdo)) {
        $leagueId = resolveLeagueId($pdo, $league);
        if (!$leagueId) {
            jsonSuccess(['league' => $league, 'contracts' => []]);
        }
        $where .= ' AND fa.league_id = ?';
        $params[] = $leagueId;
    }

    $secondarySelect = $secondaryColumn ? "fa.{$secondaryColumn}" : "NULL";
    $stmt = $pdo->prepare("
        SELECT fa.id, fa.name, fa.position, {$secondarySelect} AS secondary_position, fa.{$ovrColumn} AS ovr,
               fa.original_team_name, fa.waived_at,
               t.city AS team_city, t.name AS team_name
        FROM free_agents fa
        LEFT JOIN teams t ON fa.winner_team_id = t.id
        WHERE {$where}
        ORDER BY fa.waived_at DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess(['league' => $league, 'contracts' => $contracts]);
}

function freeAgencyLimits(?array $team): void
{
    $waiversUsed = isset($team['waivers_used']) ? (int)$team['waivers_used'] : 0;
    $signingsUsed = isset($team['fa_signings_used']) ? (int)$team['fa_signings_used'] : 0;
    jsonSuccess([
        'waivers_used' => $waiversUsed,
        'waivers_max' => 3,
        'signings_used' => $signingsUsed,
        'signings_max' => 3
    ]);
}

// ========== POST ==========

function addPlayer(PDO $pdo, array $body): void
{
    $league = strtoupper(trim((string)($body['league'] ?? '')));
    $name = trim((string)($body['name'] ?? ''));
    $position = trim((string)($body['position'] ?? 'PG'));
    $secondary = trim((string)($body['secondary_position'] ?? ''));
    $age = (int)($body['age'] ?? 25);
    $ovr = (int)($body['ovr'] ?? 70);

    if (!$league || !$name) {
        jsonError('Dados incompletos');
    }

    $columns = ['name', 'age', 'position'];
    $values = [$name, $age, $position];

    $ovrColumn = freeAgentOvrColumn($pdo);
    $columns[] = $ovrColumn;
    $values[] = $ovr;

    $secondaryColumn = freeAgentSecondaryColumn($pdo);
    if ($secondaryColumn) {
        $columns[] = $secondaryColumn;
        $values[] = $secondary ?: null;
    }

    if (freeAgentsUseLeagueEnum($pdo)) {
        $columns[] = 'league';
        $values[] = $league;
    }

    if (columnExists($pdo, 'free_agents', 'league_id')) {
        $leagueId = resolveLeagueId($pdo, $league);
        if ($leagueId) {
            $columns[] = 'league_id';
            $values[] = $leagueId;
        }
    }

    if (columnExists($pdo, 'free_agents', 'original_team_id')) {
        $columns[] = 'original_team_id';
        $values[] = null;
    }
    if (columnExists($pdo, 'free_agents', 'original_team_name')) {
        $columns[] = 'original_team_name';
        $values[] = null;
    }

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare('INSERT INTO free_agents (' . implode(',', $columns) . ") VALUES ({$placeholders})");
    $stmt->execute($values);

    jsonSuccess(['id' => $pdo->lastInsertId()]);
}

function removePlayer(PDO $pdo, array $body): void
{
    $player_id = (int)($body['player_id'] ?? 0);
    if (!$player_id) {
        jsonError('ID nao informado');
    }

    $stmt = $pdo->prepare('DELETE FROM free_agent_offers WHERE free_agent_id = ?');
    $stmt->execute([$player_id]);
    $stmt = $pdo->prepare('DELETE FROM free_agents WHERE id = ?');
    $stmt->execute([$player_id]);

    jsonSuccess();
}

function placeOffer(PDO $pdo, array $body, ?int $teamId, ?string $teamLeague, int $teamCoins): void
{
    ensureOfferAmountColumn($pdo);

    if (!$teamId) {
        jsonError('Voce precisa ter um time');
    }

    $player_id = (int)($body['free_agent_id'] ?? 0);
    $amount = (int)($body['amount'] ?? 0);

    if (!$player_id || $amount <= 0) {
        jsonError('Dados invalidos');
    }

    $stmt = $pdo->prepare('SELECT * FROM free_agents WHERE id = ?');
    $stmt->execute([$player_id]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$player) {
        jsonError('Jogador nao encontrado');
    }

    if ($teamLeague) {
        $playerLeague = $player['league'] ?? null;
        if (!$playerLeague && isset($player['league_id'])) {
            $playerLeague = resolveLeagueName($pdo, (int)$player['league_id']);
        }
        if ($playerLeague && strtoupper($playerLeague) !== strtoupper($teamLeague)) {
            jsonError('Jogador e time precisam ser da mesma liga');
        }
    }

    if ($teamCoins < $amount) {
        jsonError('Moedas insuficientes');
    }

    $stmt = $pdo->prepare('SELECT id FROM free_agent_offers WHERE free_agent_id = ? AND team_id = ?');
    $stmt->execute([$player_id, $teamId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE free_agent_offers SET amount = ?, status = "pending" WHERE id = ?');
        $stmt->execute([$amount, $existing['id']]);
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO free_agent_offers (free_agent_id, team_id, amount, status, created_at)
            VALUES (?, ?, ?, "pending", NOW())
        ');
        $stmt->execute([$player_id, $teamId, $amount]);
    }

    jsonSuccess();
}

function approveOffer(PDO $pdo, array $body, int $adminId): void
{
    ensureOfferAmountColumn($pdo);

    $offer_id = (int)($body['offer_id'] ?? 0);
    if (!$offer_id) {
        jsonError('Proposta invalida');
    }

    $ovrColumn = freeAgentOvrColumn($pdo);
    $secondaryColumn = freeAgentSecondaryColumn($pdo);
    $secondarySelect = $secondaryColumn ? "fa.{$secondaryColumn}" : "NULL";
    $selectLeague = columnExists($pdo, 'free_agents', 'league') ? ', fa.league' : '';
    $stmt = $pdo->prepare("
        SELECT fao.id, fao.free_agent_id, fao.team_id, fao.amount, fao.status,
               fa.name AS player_name, fa.age, fa.position, {$secondarySelect} AS secondary_position, fa.{$ovrColumn} AS ovr{$selectLeague},
               t.city AS team_city, t.name AS team_name, COALESCE(t.moedas, 0) AS moedas
        FROM free_agent_offers fao
        JOIN free_agents fa ON fao.free_agent_id = fa.id
        JOIN teams t ON fao.team_id = t.id
        WHERE fao.id = ?
    ");
    $stmt->execute([$offer_id]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$offer || $offer['status'] !== 'pending') {
        jsonError('Proposta nao encontrada');
    }

    if ((int)$offer['moedas'] < (int)$offer['amount']) {
        jsonError('Time nao tem moedas suficientes');
    }

    $pdo->beginTransaction();
    try {
        $columns = ['team_id', 'name', 'age', 'position', 'ovr'];
        $values = [
            (int)$offer['team_id'],
            $offer['player_name'],
            (int)$offer['age'],
            $offer['position'],
            (int)$offer['ovr']
        ];

        if (columnExists($pdo, 'players', 'secondary_position')) {
            $columns[] = 'secondary_position';
            $values[] = $offer['secondary_position'];
        }
        if (columnExists($pdo, 'players', 'seasons_in_league')) {
            $columns[] = 'seasons_in_league';
            $values[] = 0;
        }
        if (columnExists($pdo, 'players', 'role')) {
            $columns[] = 'role';
            $values[] = 'Banco';
        }
        if (columnExists($pdo, 'players', 'available_for_trade')) {
            $columns[] = 'available_for_trade';
            $values[] = 0;
        }

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $stmtInsert = $pdo->prepare('INSERT INTO players (' . implode(',', $columns) . ") VALUES ({$placeholders})");
        $stmtInsert->execute($values);

        $stmtCoins = $pdo->prepare('UPDATE teams SET moedas = moedas - ? WHERE id = ?');
        $stmtCoins->execute([(int)$offer['amount'], (int)$offer['team_id']]);

        if (columnExists($pdo, 'teams', 'fa_signings_used')) {
            $stmtSign = $pdo->prepare('UPDATE teams SET fa_signings_used = COALESCE(fa_signings_used, 0) + 1 WHERE id = ?');
            $stmtSign->execute([(int)$offer['team_id']]);
        }

        if (tableExists($pdo, 'team_coins_log')) {
            $stmtLog = $pdo->prepare('
                INSERT INTO team_coins_log (team_id, amount, reason, admin_id, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ');
            $reason = 'Contratacao FA: ' . $offer['player_name'];
            $stmtLog->execute([(int)$offer['team_id'], -(int)$offer['amount'], $reason, $adminId]);
        }

        $stmtDelete = $pdo->prepare('DELETE FROM free_agents WHERE id = ?');
        $stmtDelete->execute([(int)$offer['free_agent_id']]);

        $stmtOffers = $pdo->prepare('
            UPDATE free_agent_offers
            SET status = CASE WHEN id = ? THEN "accepted" ELSE "rejected" END
            WHERE free_agent_id = ? AND status = "pending"
        ');
        $stmtOffers->execute([(int)$offer['id'], (int)$offer['free_agent_id']]);

        $pdo->commit();
        jsonSuccess([
            'message' => sprintf('%s agora faz parte de %s %s', $offer['player_name'], $offer['team_city'], $offer['team_name'])
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao aprovar proposta: ' . $e->getMessage(), 500);
    }
}
