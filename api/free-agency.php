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

function ensureNewFaTables(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fa_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
            normalized_name VARCHAR(140) NOT NULL,
            player_name VARCHAR(140) NOT NULL,
            position VARCHAR(20) NOT NULL,
            secondary_position VARCHAR(20) NULL,
            age INT NOT NULL,
            ovr INT NOT NULL,
            season_id INT NULL,
            season_year INT NULL,
            status ENUM('open','assigned','rejected') DEFAULT 'open',
            created_by_team_id INT NULL,
            winner_team_id INT NULL,
            resolved_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_fa_requests_league (league),
            INDEX idx_fa_requests_name (normalized_name),
            INDEX idx_fa_requests_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS fa_request_offers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            team_id INT NOT NULL,
            amount INT NOT NULL DEFAULT 0,
            status ENUM('pending','accepted','rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_request_team (request_id, team_id),
            INDEX idx_fa_request_offers_status (status),
            INDEX idx_fa_request_offers_team (team_id),
            CONSTRAINT fk_fa_request_offers_request FOREIGN KEY (request_id) REFERENCES fa_requests(id) ON DELETE CASCADE,
            CONSTRAINT fk_fa_request_offers_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('[free-agency] ensureNewFaTables: ' . $e->getMessage());
    }
}

$pdo = db();
ensureTeamFreeAgencyColumns($pdo);
ensureNewFaTables($pdo);

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

if ($team_id && $team_league) {
    syncFaSeasonCounters($pdo, $team_id, $team_league);
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

function normalizeFaPlayerName(string $name): string
{
    $normalized = trim(preg_replace('/\s+/', ' ', $name));
    $normalized = mb_strtolower($normalized, 'UTF-8');
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT', $normalized);
    if ($translit !== false) {
        $normalized = $translit;
    }
    $normalized = preg_replace('/[^a-z0-9 ]/i', '', $normalized);
    return trim($normalized);
}

function resolveCurrentSeason(PDO $pdo, string $league): array
{
    if (!tableExists($pdo, 'seasons')) {
        return ['id' => null, 'year' => null];
    }

    $stmt = $pdo->prepare("SELECT id, year FROM seasons WHERE league = ? AND status <> 'completed' ORDER BY year DESC, id DESC LIMIT 1");
    $stmt->execute([$league]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ['id' => (int)$row['id'], 'year' => (int)$row['year']];
    }

    $stmt = $pdo->prepare('SELECT id, year FROM seasons WHERE league = ? ORDER BY year DESC, id DESC LIMIT 1');
    $stmt->execute([$league]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ['id' => (int)$row['id'], 'year' => (int)$row['year']] : ['id' => null, 'year' => null];
}

function syncFaSeasonCounters(PDO $pdo, int $teamId, string $league): void
{
    if ($teamId <= 0 || !$league) {
        return;
    }

    $season = resolveCurrentSeason($pdo, $league);
    if (empty($season['year'])) {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT waivers_used, fa_signings_used, waivers_reset_year, fa_reset_year FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $updates = [];
        $params = [];
        if ((int)($row['waivers_reset_year'] ?? 0) !== (int)$season['year']) {
            $updates[] = 'waivers_used = 0';
            $updates[] = 'waivers_reset_year = ?';
            $params[] = (int)$season['year'];
        }
        if ((int)($row['fa_reset_year'] ?? 0) !== (int)$season['year']) {
            $updates[] = 'fa_signings_used = 0';
            $updates[] = 'fa_reset_year = ?';
            $params[] = (int)$season['year'];
        }

        if ($updates) {
            $params[] = $teamId;
            $stmtUpdate = $pdo->prepare('UPDATE teams SET ' . implode(', ', $updates) . ' WHERE id = ?');
            $stmtUpdate->execute($params);
        }
    } catch (Exception $e) {
        error_log('[free-agency] syncFaSeasonCounters: ' . $e->getMessage());
    }
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

function ensureFaEnabledColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    try {
        if (tableExists($pdo, 'league_settings')) {
            $stmt = $pdo->query("SHOW COLUMNS FROM league_settings LIKE 'fa_enabled'");
            if ($stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE league_settings ADD COLUMN fa_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER max_trades");
            }
        }
    } catch (Exception $e) {
        error_log('[free-agency] ensureFaEnabledColumn: ' . $e->getMessage());
    }
    $checked = true;
}

function getFaEnabled(PDO $pdo, ?string $league): bool
{
    if (!$league || !tableExists($pdo, 'league_settings')) {
        return true; // padrão: aberto
    }
    ensureFaEnabledColumn($pdo);
    try {
        $stmt = $pdo->prepare('SELECT fa_enabled FROM league_settings WHERE league = ?');
        $stmt->execute([$league]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return true;
        $val = $row['fa_enabled'];
        return $val === null ? true : ((int)$val === 1);
    } catch (Exception $e) {
        error_log('[free-agency] getFaEnabled: ' . $e->getMessage());
        return true;
    }
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
        case 'fa_status':
            $league = getLeagueFromRequest($valid_leagues, $team_league);
            if (!$league) {
                jsonSuccess(['league' => null, 'enabled' => true]);
            }
            $enabled = getFaEnabled($pdo, $league);
            jsonSuccess(['league' => $league, 'enabled' => $enabled]);
            break;
        case 'my_offers':
            listMyOffers($pdo, $team_id);
            break;
        case 'my_fa_requests':
            listMyFaRequests($pdo, $team_id);
            break;
        case 'admin_free_agents':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            $league = getLeagueFromRequest($valid_leagues, null);
            error_log("🔍 Free Agency Admin - Liga recebida via GET: " . ($_GET['league'] ?? 'null'));
            error_log("🔍 Free Agency Admin - Liga processada: " . ($league ?? 'null'));
            error_log("🔍 Free Agency Admin - Team league (não deve interferir): " . ($team_league ?? 'null'));
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
        case 'admin_new_fa_requests':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            $league = getLeagueFromRequest($valid_leagues, null);
            if (!$league) {
                jsonError('Liga invalida');
            }
            listAdminFaRequests($pdo, $league);
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
        case 'new_fa_limits':
            newFaLimits($pdo, $team_id);
            break;
        case 'contracts':
            $league = getLeagueFromRequest($valid_leagues, $team_league);
            if (!$league) {
                jsonSuccess(['league' => $league, 'contracts' => []]);
            }
            listContracts($pdo, $league);
            break;
        case 'new_fa_history':
            $league = getLeagueFromRequest($valid_leagues, $team_league);
            if (!$league) {
                jsonSuccess(['league' => $league, 'history' => []]);
            }
            listNewFaHistory($pdo, $league);
            break;
        case 'waivers':
            $league = getLeagueFromRequest($valid_leagues, $team_league);
            if (!$league) {
                jsonSuccess(['league' => $league, 'waivers' => []]);
            }
            listWaivers($pdo, $league);
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
        case 'request_player':
            requestNewFaPlayer($pdo, $body, $team_id, $team_league, $team_coins);
            break;
        case 'set_fa_status':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            $league = strtoupper(trim((string)($body['league'] ?? '')));
            $enabled = (int)!!($body['enabled'] ?? 0);
            if (!$league) {
                jsonError('Liga invalida');
            }
            ensureFaEnabledColumn($pdo);
            try {
                if (!tableExists($pdo, 'league_settings')) {
                    jsonError('Tabela league_settings ausente', 500);
                }
                // Inserir ou atualizar com base na UNIQUE(league)
                $stmt = $pdo->prepare("INSERT INTO league_settings (league, fa_enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE fa_enabled = VALUES(fa_enabled)");
                $stmt->execute([$league, $enabled]);
                jsonSuccess(['league' => $league, 'enabled' => $enabled === 1]);
            } catch (Exception $e) {
                jsonError('Falha ao atualizar status da FA: ' . $e->getMessage(), 500);
            }
            break;
        case 'approve_offer':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            approveOffer($pdo, $body, $user_id);
            break;
        case 'admin_assign_request':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            assignNewFaRequest($pdo, $body, $user_id);
            break;
        case 'update_request_offer':
            updateNewFaOffer($pdo, $body, $team_id, $team_coins);
            break;
        case 'cancel_request_offer':
            cancelNewFaOffer($pdo, $body, $team_id);
            break;
        case 'reject_all_offers':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            rejectAllOffers($pdo, $body);
            break;
        case 'admin_reject_request':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            rejectNewFaRequest($pdo, $body);
            break;
        case 'close_without_winner':
            if (!$is_admin) {
                jsonError('Acesso negado', 403);
            }
            closeWithoutWinner($pdo, $body);
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
    error_log("🏀 listAdminFreeAgents chamada com league: " . $league);
    $ovrColumn = freeAgentOvrColumn($pdo);
    $secondaryColumn = freeAgentSecondaryColumn($pdo);
    $hasSeasonId = columnExists($pdo, 'free_agents', 'season_id');
    $hasSeasonsTable = tableExists($pdo, 'seasons');
    $where = '(fa.status = "available" OR fa.status IS NULL)';
    $params = [];

    if (freeAgentsUseLeagueEnum($pdo) && columnExists($pdo, 'free_agents', 'league_id')) {
        $leagueId = resolveLeagueId($pdo, $league);
        error_log("🔑 Usando league enum + league_id. League: $league, LeagueId: " . ($leagueId ?? 'null'));
        $where .= ' AND (fa.league = ?' . ($leagueId ? ' OR fa.league_id = ?' : '') . ')';
        $params[] = $league;
        if ($leagueId) {
            $params[] = $leagueId;
        }
    } elseif (freeAgentsUseLeagueEnum($pdo)) {
        error_log("🔑 Usando apenas league enum. League: $league");
        $where .= ' AND fa.league = ?';
        $params[] = $league;
    } elseif (freeAgentsUseLeagueId($pdo)) {
        $leagueId = resolveLeagueId($pdo, $league);
        error_log("🔑 Usando apenas league_id. League: $league, LeagueId: " . ($leagueId ?? 'null'));
        if (!$leagueId) {
            jsonSuccess(['league' => $league, 'players' => []]);
        }
        $where .= ' AND fa.league_id = ?';
        $params[] = $leagueId;
    }

    error_log("📝 Query WHERE: $where");
    error_log("📝 Query PARAMS: " . json_encode($params));

    $select = "fa.id, fa.name, fa.age, fa.position, fa.{$ovrColumn} AS ovr";
    if ($secondaryColumn) {
        $select .= ", fa.{$secondaryColumn} AS secondary_position";
    } else {
        $select .= ", NULL AS secondary_position";
    }
    $select .= ", fa.original_team_name";
    if ($hasSeasonId && $hasSeasonsTable) {
        $select .= ", s.year AS season_year, s.season_number";
    } else {
        $select .= ", NULL AS season_year, NULL AS season_number";
    }
    $stmt = $pdo->prepare("
        SELECT {$select}, (
            SELECT COUNT(*) FROM free_agent_offers
            WHERE free_agent_id = fa.id AND status = 'pending'
        ) AS pending_offers
        FROM free_agents fa
        " . (($hasSeasonId && $hasSeasonsTable) ? "LEFT JOIN seasons s ON s.id = fa.season_id" : "") . "
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
    $whereParts = [];
    if (columnExists($pdo, 'free_agents', 'status')) {
        $whereParts[] = 'fa.status = "signed"';
    }
    if (columnExists($pdo, 'free_agents', 'winner_team_id')) {
        $whereParts[] = 'fa.winner_team_id IS NOT NULL';
    }
    $where = $whereParts ? '(' . implode(' OR ', $whereParts) . ')' : '1 = 0';
    $params = [];
    $seasonSelect = 'NULL AS season_year';
    $seasonJoin = '';

    if (columnExists($pdo, 'free_agents', 'season_id') && tableExists($pdo, 'seasons')) {
        $seasonSelect = 's.year AS season_year';
        $seasonJoin = 'LEFT JOIN seasons s ON fa.season_id = s.id';
    }

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
               fa.original_team_name, fa.waived_at, {$seasonSelect},
               t.city AS team_city, t.name AS team_name
        FROM free_agents fa
        LEFT JOIN teams t ON fa.winner_team_id = t.id
        {$seasonJoin}
        WHERE {$where}
        ORDER BY fa.waived_at DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess(['league' => $league, 'contracts' => $contracts]);
}

function listContracts(PDO $pdo, string $league): void
{
    listAdminContracts($pdo, $league);
}

function listWaivers(PDO $pdo, string $league): void
{
    $params = [];
    $seasonYearExpr = 'NULL';
    $seasonNumberExpr = 'NULL';
    $seasonJoin = '';
    $seasonFilter = isset($_GET['season_year']) ? (int)$_GET['season_year'] : null;
    $teamFilter = isset($_GET['team_name']) ? trim((string)$_GET['team_name']) : '';

    if (columnExists($pdo, 'free_agents', 'season_id') && tableExists($pdo, 'seasons')) {
        $seasonJoin = 'LEFT JOIN seasons s ON fa.season_id = s.id';
        $seasonYearExpr = 's.year';
        $seasonNumberExpr = 's.season_number';
    }

    $seasonSelect = $seasonYearExpr . ' AS season_year, ' . $seasonNumberExpr . ' AS season_number';

    $where = 'fa.original_team_name IS NOT NULL';

    if (freeAgentsUseLeagueEnum($pdo) && columnExists($pdo, 'free_agents', 'league')) {
        $where .= ' AND fa.league = ?';
        $params[] = $league;
    } elseif (freeAgentsUseLeagueId($pdo) && columnExists($pdo, 'free_agents', 'league_id')) {
        $leagueId = resolveLeagueId($pdo, $league);
        if ($leagueId) {
            $where .= ' AND fa.league_id = ?';
            $params[] = $leagueId;
        }
    }

    // Excluir aposentadorias, se coluna existir
    if (columnExists($pdo, 'free_agents', 'is_retirement')) {
        $where .= ' AND (fa.is_retirement = 0 OR fa.is_retirement IS NULL)';
    }

    // Somente ainda disponíveis (não assinados)
    if (columnExists($pdo, 'free_agents', 'status')) {
        $where .= " AND (fa.status IS NULL OR fa.status = 'available')";
    }
    if (columnExists($pdo, 'free_agents', 'winner_team_id')) {
        $where .= ' AND (fa.winner_team_id IS NULL)';
    }

    if ($seasonFilter) {
        $where .= ' AND ' . $seasonYearExpr . ' = ?';
        $params[] = $seasonFilter;
    }
    if ($teamFilter !== '') {
        $where .= ' AND fa.original_team_name = ?';
        $params[] = $teamFilter;
    }

    $stmt = $pdo->prepare("SELECT fa.id, fa.name, fa.original_team_name, {$seasonSelect}
        FROM free_agents fa
        {$seasonJoin}
        WHERE {$where}
        ORDER BY fa.waived_at DESC
        LIMIT 200");
    $stmt->execute($params);
    $waivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess(['league' => $league, 'waivers' => $waivers]);
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

function newFaLimits(PDO $pdo, ?int $teamId): void
{
    if (!$teamId) {
        jsonSuccess(['remaining' => 0, 'used' => 0, 'limit' => 3]);
    }
    $used = getTeamFaWins($pdo, $teamId);
    $limit = 3;
    $remaining = max(0, $limit - $used);
    jsonSuccess(['remaining' => $remaining, 'used' => $used, 'limit' => $limit]);
}

function listMyFaRequests(PDO $pdo, ?int $teamId): void
{
    if (!$teamId) {
        jsonSuccess(['requests' => []]);
    }

    $stmt = $pdo->prepare('
     SELECT r.id, r.player_name, r.position, r.secondary_position, r.ovr, r.season_year, r.status AS request_status,
         o.id AS offer_id, o.amount, o.status AS offer_status
        FROM fa_requests r
        JOIN fa_request_offers o ON o.request_id = r.id
        WHERE o.team_id = ?
        ORDER BY o.created_at DESC
    ');
    $stmt->execute([$teamId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $requests = [];
    foreach ($rows as $row) {
        $status = $row['request_status'] === 'assigned'
            ? ($row['offer_status'] === 'accepted' ? 'assigned' : 'rejected')
            : ($row['request_status'] === 'rejected' ? 'rejected' : 'pending');
        $requests[] = [
            'id' => (int)$row['id'],
            'offer_id' => (int)$row['offer_id'],
            'player_name' => $row['player_name'],
            'position' => $row['position'],
            'secondary_position' => $row['secondary_position'],
            'ovr' => $row['ovr'],
            'season_year' => $row['season_year'],
            'amount' => (int)$row['amount'],
            'status' => $status
        ];
    }

    jsonSuccess(['requests' => $requests]);
}

function listAdminFaRequests(PDO $pdo, string $league): void
{
    $stmt = $pdo->prepare('
        SELECT r.id AS request_id, r.player_name, r.position, r.secondary_position, r.ovr, r.age, r.season_year,
               o.id AS offer_id, o.amount, o.created_at, o.team_id,
               t.city AS team_city, t.name AS team_name
        FROM fa_requests r
        JOIN fa_request_offers o ON o.request_id = r.id AND o.status = "pending"
        JOIN teams t ON o.team_id = t.id
        WHERE r.league = ? AND r.status = "open"
        ORDER BY o.amount DESC, o.created_at ASC
    ');
    $stmt->execute([$league]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($rows as $row) {
        $requestId = (int)$row['request_id'];
        if (!isset($grouped[$requestId])) {
            $grouped[$requestId] = [
                'request' => [
                    'id' => $requestId,
                    'player_name' => $row['player_name'],
                    'position' => $row['position'],
                    'secondary_position' => $row['secondary_position'],
                    'ovr' => $row['ovr'],
                    'age' => $row['age'],
                    'season_year' => $row['season_year']
                ],
                'offers' => []
            ];
        }
        $remaining = max(0, 3 - getTeamFaWins($pdo, (int)$row['team_id']));
        $grouped[$requestId]['offers'][] = [
            'id' => (int)$row['offer_id'],
            'team_name' => trim(($row['team_city'] ?? '') . ' ' . ($row['team_name'] ?? '')),
            'amount' => (int)$row['amount'],
            'created_at' => $row['created_at'],
            'remaining_signings' => $remaining
        ];
    }

    jsonSuccess(['requests' => array_values($grouped)]);
}

function listNewFaHistory(PDO $pdo, string $league): void
{
    $seasonFilter = isset($_GET['season_year']) ? (int)$_GET['season_year'] : null;
    $where = 'r.league = ? AND r.status = "assigned"';
    $params = [$league];
    if ($seasonFilter) {
        $where .= ' AND r.season_year = ?';
        $params[] = $seasonFilter;
    }

    $stmt = $pdo->prepare('
        SELECT r.player_name, r.ovr, r.season_year,
               t.city AS team_city, t.name AS team_name
        FROM fa_requests r
        LEFT JOIN teams t ON r.winner_team_id = t.id
        WHERE ' . $where . '
        ORDER BY r.resolved_at DESC, r.id DESC
        LIMIT 100
    ');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = array_map(function ($row) {
        $row['season_year'] = $row['season_year'] ?: null;
        return $row;
    }, $rows);
    jsonSuccess(['history' => $rows]);
}

function requestNewFaPlayer(PDO $pdo, array $body, ?int $teamId, ?string $teamLeague, int $teamCoins): void
{
    if (!$teamId) {
        jsonError('Voce precisa ter um time');
    }

    $league = strtoupper(trim((string)($body['league'] ?? $teamLeague ?? '')));
    $name = trim((string)($body['name'] ?? ''));
    $position = trim((string)($body['position'] ?? 'PG')) ?: 'PG';
    $secondary = trim((string)($body['secondary_position'] ?? ''));
    $age = (int)($body['age'] ?? 24);
    $ovr = (int)($body['ovr'] ?? 70);
    $amount = (int)($body['amount'] ?? 0);

    if (!$league || !$name) {
        jsonError('Dados incompletos');
    }
    if ($teamLeague && $league !== $teamLeague) {
        jsonError('Liga invalida para o seu time');
    }
    if ($amount <= 0) {
        jsonError('Valor da proposta invalido');
    }
    if ($teamCoins < $amount) {
        jsonError('Moedas insuficientes');
    }

    if ($teamLeague && !getFaEnabled($pdo, $teamLeague)) {
        jsonError('O periodo de propostas esta fechado para esta liga');
    }

    $normalizedName = normalizeFaPlayerName($name);
    if (!$normalizedName) {
        jsonError('Nome do jogador invalido');
    }

    $stmt = $pdo->prepare('SELECT id FROM fa_requests WHERE league = ? AND normalized_name = ? AND status = "open" LIMIT 1');
    $stmt->execute([$league, $normalizedName]);
    $requestId = $stmt->fetchColumn();

    if (!$requestId) {
        $season = resolveCurrentSeason($pdo, $league);
        $stmtInsert = $pdo->prepare('
            INSERT INTO fa_requests (league, normalized_name, player_name, position, secondary_position, age, ovr, season_id, season_year, status, created_by_team_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "open", ?)
        ');
        $stmtInsert->execute([
            $league,
            $normalizedName,
            $name,
            $position,
            $secondary ?: null,
            $age,
            $ovr,
            $season['id'],
            $season['year'],
            $teamId
        ]);
        $requestId = (int)$pdo->lastInsertId();
    }

    $stmtOffer = $pdo->prepare('SELECT id FROM fa_request_offers WHERE request_id = ? AND team_id = ?');
    $stmtOffer->execute([$requestId, $teamId]);
    $existingOffer = $stmtOffer->fetchColumn();

    if ($existingOffer) {
        $stmtUpdate = $pdo->prepare('UPDATE fa_request_offers SET amount = ?, status = "pending" WHERE id = ?');
        $stmtUpdate->execute([$amount, $existingOffer]);
    } else {
        $stmtInsertOffer = $pdo->prepare('
            INSERT INTO fa_request_offers (request_id, team_id, amount, status, created_at)
            VALUES (?, ?, ?, "pending", NOW())
        ');
        $stmtInsertOffer->execute([$requestId, $teamId, $amount]);
    }

    jsonSuccess(['request_id' => (int)$requestId]);
}

function assignNewFaRequest(PDO $pdo, array $body, int $adminId): void
{
    $offerId = (int)($body['offer_id'] ?? 0);
    if (!$offerId) {
        jsonError('Proposta invalida');
    }

    $stmt = $pdo->prepare('
        SELECT o.id, o.request_id, o.team_id, o.amount, o.status,
               r.player_name, r.position, r.secondary_position, r.age, r.ovr, r.league, r.status AS request_status,
               t.city AS team_city, t.name AS team_name, COALESCE(t.moedas, 0) AS moedas
        FROM fa_request_offers o
        JOIN fa_requests r ON o.request_id = r.id
        JOIN teams t ON o.team_id = t.id
        WHERE o.id = ?
    ');
    $stmt->execute([$offerId]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$offer || $offer['status'] !== 'pending' || $offer['request_status'] !== 'open') {
        jsonError('Proposta nao encontrada');
    }
    if ((int)$offer['moedas'] < (int)$offer['amount']) {
        jsonError('Time nao tem moedas suficientes');
    }

    if (getTeamFaWins($pdo, (int)$offer['team_id']) >= 3) {
        jsonError('Este time ja atingiu o limite de 3 contratacoes na Free Agency');
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
            $values[] = $offer['secondary_position'] ?: null;
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
            $reason = 'Nova FA: ' . $offer['player_name'];
            $stmtLog->execute([(int)$offer['team_id'], -(int)$offer['amount'], $reason, $adminId]);
        }

        $stmtRequest = $pdo->prepare('UPDATE fa_requests SET status = "assigned", winner_team_id = ?, resolved_at = NOW() WHERE id = ?');
        $stmtRequest->execute([(int)$offer['team_id'], (int)$offer['request_id']]);

        $stmtOffers = $pdo->prepare('
            UPDATE fa_request_offers
            SET status = CASE WHEN id = ? THEN "accepted" ELSE "rejected" END
            WHERE request_id = ? AND status = "pending"
        ');
        $stmtOffers->execute([(int)$offer['id'], (int)$offer['request_id']]);

        $pdo->commit();
        jsonSuccess([
            'message' => sprintf('%s agora faz parte de %s %s', $offer['player_name'], $offer['team_city'], $offer['team_name'])
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao aprovar solicitacao: ' . $e->getMessage(), 500);
    }
}

function rejectNewFaRequest(PDO $pdo, array $body): void
{
    $requestId = (int)($body['request_id'] ?? 0);
    if (!$requestId) {
        jsonError('Solicitacao invalida');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM fa_request_offers WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $stmt = $pdo->prepare('DELETE FROM fa_requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $pdo->commit();
        jsonSuccess();
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao recusar solicitacao: ' . $e->getMessage(), 500);
    }
}

function getTeamFaWins(PDO $pdo, int $teamId): int
{
    if ($teamId <= 0) {
        return 0;
    }

    try {
        if (columnExists($pdo, 'teams', 'fa_signings_used')) {
            $stmt = $pdo->prepare('SELECT COALESCE(fa_signings_used, 0) FROM teams WHERE id = ?');
            $stmt->execute([$teamId]);
            return (int)($stmt->fetchColumn() ?? 0);
        }
    } catch (Exception $e) {
        // fallback below
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM fa_requests WHERE winner_team_id = ? AND status = "assigned"');
        $stmt->execute([$teamId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function updateNewFaOffer(PDO $pdo, array $body, ?int $teamId, int $teamCoins): void
{
    if (!$teamId) {
        jsonError('Voce precisa ter um time');
    }

    $offerId = (int)($body['offer_id'] ?? 0);
    $amount = (int)($body['amount'] ?? 0);
    if (!$offerId) {
        jsonError('Proposta invalida');
    }
    if ($amount <= 0) {
        jsonError('Valor invalido');
    }
    if ($teamCoins < $amount) {
        jsonError('Moedas insuficientes');
    }

    $stmt = $pdo->prepare('SELECT id FROM fa_request_offers WHERE id = ? AND team_id = ? AND status = "pending"');
    $stmt->execute([$offerId, $teamId]);
    if (!$stmt->fetchColumn()) {
        jsonError('Proposta nao encontrada');
    }

    $stmtUpdate = $pdo->prepare('UPDATE fa_request_offers SET amount = ? WHERE id = ?');
    $stmtUpdate->execute([$amount, $offerId]);
    jsonSuccess();
}

function cancelNewFaOffer(PDO $pdo, array $body, ?int $teamId): void
{
    if (!$teamId) {
        jsonError('Voce precisa ter um time');
    }

    $offerId = (int)($body['offer_id'] ?? 0);
    if (!$offerId) {
        jsonError('Proposta invalida');
    }

    $stmt = $pdo->prepare('SELECT request_id FROM fa_request_offers WHERE id = ? AND team_id = ? AND status = "pending"');
    $stmt->execute([$offerId, $teamId]);
    $requestId = $stmt->fetchColumn();
    if (!$requestId) {
        jsonError('Proposta nao encontrada');
    }

    $pdo->beginTransaction();
    try {
        $stmtDel = $pdo->prepare('DELETE FROM fa_request_offers WHERE id = ?');
        $stmtDel->execute([$offerId]);

        $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM fa_request_offers WHERE request_id = ?');
        $stmtCount->execute([(int)$requestId]);
        $remaining = (int)$stmtCount->fetchColumn();
        if ($remaining === 0) {
            $stmtReq = $pdo->prepare('DELETE FROM fa_requests WHERE id = ?');
            $stmtReq->execute([(int)$requestId]);
        }
        $pdo->commit();
        jsonSuccess();
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao excluir proposta: ' . $e->getMessage(), 500);
    }
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

    if (!$player_id) {
        jsonError('Dados invalidos');
    }

    // Bloqueio por período fechado na liga do time
    if ($teamLeague && !getFaEnabled($pdo, $teamLeague)) {
        jsonError('O período de propostas está fechado para esta liga');
    }

    // Cancelar proposta quando amount = 0
    if ($amount === 0) {
        $stmt = $pdo->prepare('SELECT id FROM free_agent_offers WHERE free_agent_id = ? AND team_id = ? AND status = "pending"');
        $stmt->execute([$player_id, $teamId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $del = $pdo->prepare('DELETE FROM free_agent_offers WHERE id = ?');
            $del->execute([$existing['id']]);
        }
        jsonSuccess(['canceled' => true]);
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

    if (!$existing) {
        $stmtLimit = $pdo->prepare('SELECT COUNT(*) FROM free_agent_offers WHERE team_id = ? AND status = "pending"');
        $stmtLimit->execute([$teamId]);
        $pendingCount = (int)$stmtLimit->fetchColumn();
        if ($pendingCount >= 3) {
            jsonError('Limite de 3 propostas pendentes por time');
        }
    }

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

        $updatedFreeAgent = false;
        if (columnExists($pdo, 'free_agents', 'winner_team_id') || columnExists($pdo, 'free_agents', 'status')) {
            $updates = [];
            $valuesUpdate = [];
            if (columnExists($pdo, 'free_agents', 'winner_team_id')) {
                $updates[] = 'winner_team_id = ?';
                $valuesUpdate[] = (int)$offer['team_id'];
            }
            if (columnExists($pdo, 'free_agents', 'status')) {
                $updates[] = 'status = "signed"';
            }
            if ($updates) {
                $valuesUpdate[] = (int)$offer['free_agent_id'];
                $sqlUpdate = 'UPDATE free_agents SET ' . implode(', ', $updates) . ' WHERE id = ?';
                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $stmtUpdate->execute($valuesUpdate);
                $updatedFreeAgent = true;
            }
        }

        if (!$updatedFreeAgent) {
            $stmtDelete = $pdo->prepare('DELETE FROM free_agents WHERE id = ?');
            $stmtDelete->execute([(int)$offer['free_agent_id']]);
        }

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

function rejectAllOffers(PDO $pdo, array $body): void
{
    $playerId = (int)($body['free_agent_id'] ?? 0);
    if (!$playerId) {
        jsonError('Jogador nao informado');
    }

    $stmt = $pdo->prepare('UPDATE free_agent_offers SET status = "rejected" WHERE free_agent_id = ? AND status = "pending"');
    $stmt->execute([$playerId]);

    jsonSuccess(['updated' => $stmt->rowCount()]);
}

function closeWithoutWinner(PDO $pdo, array $body): void
{
    $playerId = (int)($body['free_agent_id'] ?? 0);
    if (!$playerId) {
        jsonError('Jogador nao informado');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE free_agent_offers SET status = "rejected" WHERE free_agent_id = ? AND status = "pending"');
        $stmt->execute([$playerId]);

        if (columnExists($pdo, 'free_agents', 'status')) {
            $updates = ['status = "closed"'];
            if (columnExists($pdo, 'free_agents', 'winner_team_id')) {
                $updates[] = 'winner_team_id = NULL';
            }
            $sql = 'UPDATE free_agents SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $stmtUpdate = $pdo->prepare($sql);
            $stmtUpdate->execute([$playerId]);
        } else {
            $stmtDelete = $pdo->prepare('DELETE FROM free_agents WHERE id = ?');
            $stmtDelete->execute([$playerId]);
        }

        $pdo->commit();
        jsonSuccess();
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao encerrar sem vencedor: ' . $e->getMessage(), 500);
    }
}
