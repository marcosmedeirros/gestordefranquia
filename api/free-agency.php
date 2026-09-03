<?php
/**
 * API Free Agency - Propostas com moedas
 */

require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/loja.php';   // waiverGarantirColunaExtra()
require_once __DIR__ . '/../backend/push.php';
require_once __DIR__ . '/../backend/salary_cap.php'; // capCabeNoTime()

/**
 * O CAP SÓ VALE NA ELITE — na Free Agency.
 *
 * O salary cap nasceu pra ELITE e é dela: lá o contrato tem valor em dinheiro
 * e o teto é a regra central da liga. As outras três (RISE, ROOKIE e NEXT)
 * têm o modo "soma de OVR", que existe pra segurar TROCA — não pra dizer quem
 * pode receber proposta na janela. Aplicado aqui, ele virava um segundo
 * limite em cima do que a Free Agency já cobra: moeda e prioridade.
 *
 * O resultado prático era um time de RISE olhando um dispensado de 78 OVR com
 * o botão travado escrito "não cabe no seu cap", numa liga que não usa cap
 * nenhum. A régua das três voltou a ser a de antes: você tem moeda? tem
 * prioridade? então proponha.
 *
 * Só a Free Agency muda. Troca, leilão e o resto continuam com a soma de OVR
 * onde ela sempre valeu.
 */
function faCapAplica(PDO $pdo, ?int $teamId): bool
{
    static $cache = [];
    $teamId = (int)$teamId;
    if ($teamId <= 0) return false;
    if (array_key_exists($teamId, $cache)) return $cache[$teamId];

    try {
        $st = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
        $st->execute([$teamId]);
        $liga = strtoupper(trim((string)($st->fetchColumn() ?: '')));
    } catch (Throwable $e) {
        // Falha de banco não pode LIBERAR uma regra da ELITE por acidente —
        // mas também não pode travar as outras três. Sem saber a liga, o
        // caminho seguro é o de antes: sem cap.
        error_log('[fa] faCapAplica: ' . $e->getMessage());
        return $cache[$teamId] = false;
    }

    return $cache[$teamId] = ($liga === 'ELITE');
}

/** A liga do time, pra quem só tem o id em mãos. '' se não achar. */
function faLigaDoTime(PDO $pdo, ?int $teamId): string
{
    static $cache = [];
    if (!$teamId) return '';
    if (array_key_exists($teamId, $cache)) return $cache[$teamId];
    try {
        $st = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
        $st->execute([$teamId]);
        return $cache[$teamId] = strtoupper(trim((string)($st->fetchColumn() ?: '')));
    } catch (Throwable $e) {
        error_log('[fa] faLigaDoTime: ' . $e->getMessage());
        return $cache[$teamId] = '';
    }
}

/**
 * capCabeNoTime() com o portão da liga na frente.
 *
 * Fora da ELITE devolve a forma que o resto do código já sabe ler como "não
 * há cap aqui": custo e espaço nulos, cabe sempre. O front-end já trata
 * espaço nulo escondendo o aviso inteiro — foi escrito assim desde o começo.
 */
function faCap(PDO $pdo, ?int $teamId, int $ovr): array
{
    if (!faCapAplica($pdo, $teamId)) {
        return ['custo' => null, 'espaco' => null, 'cabe' => true,
                'unidade' => 'M', 'modo' => 'livre'];
    }
    return capCabeNoTime($pdo, (int)$teamId, $ovr);
}

/**
 * Quanto sobra no teto do time, em milhões. NULL fora da ELITE.
 *
 * O lance da Free Agency é comparado com isto, e não com o salário por OVR
 * do jogador: na ELITE quem paga é o lance.
 */
/**
 * A ORDEM DE DESEMPATE DA FREE AGENCY — regra da liga, 30/08/2026.
 *
 * Empatou no valor? Leva quem tem MAIOR ESPAÇO na folha. Empatou nisso
 * também? Leva o time de PIOR CAMPANHA — em igualdade, a prioridade é de
 * quem está pior posicionado.
 *
 * Vale só na ELITE: nas outras não há folha pra comparar, e lá o desempate
 * segue sendo a prioridade declarada e a hora do lance.
 *
 * Devolve [espaço, ruindade] pra quem for ordenar: os dois já vêm no sentido
 * "maior primeiro", então basta comparar em ordem.
 */
function faCriterioDesempate(PDO $pdo, int $teamId, string $league): array
{
    static $cache = [];
    if (isset($cache[$teamId])) return $cache[$teamId];

    $espaco = (int)(faEspacoNoCap($pdo, $teamId) ?? 0);

    /* "Pior campanha": quanto MAIOR o número, pior o time — é a mesma
       convenção do registro de pontuação, onde o 17º em diante é quem ficou
       de fora. Sem classificação lançada todos empatam em 0, e o critério
       simplesmente não desempata nada. */
    $ruindade = 0;
    try {
        $st = $pdo->prepare("SELECT COALESCE(ss.overall_position, ss.position, 0)
                               FROM season_standings ss
                               JOIN seasons s ON s.id = ss.season_id
                              WHERE ss.team_id = ? AND s.league = ?
                           ORDER BY s.season_number DESC, s.id DESC LIMIT 1");
        $st->execute([$teamId, $league]);
        $ruindade = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('[faCriterioDesempate] ' . $e->getMessage());
    }

    return $cache[$teamId] = ['espaco' => $espaco, 'ruindade' => $ruindade];
}

/**
 * Ordena as propostas de um jogador pelo critério da liga.
 *
 * Cada item precisa de `amount` e `team_id`; o resto passa intacto.
 */
function faOrdenarPropostas(PDO $pdo, array $propostas, string $league): array
{
    if (count($propostas) < 2) return $propostas;

    $naElite = strtoupper(trim($league)) === 'ELITE';
    usort($propostas, function ($a, $b) use ($pdo, $league, $naElite) {
        // 1) o maior valor sempre manda
        $va = (int)($a['amount'] ?? 0); $vb = (int)($b['amount'] ?? 0);
        if ($va !== $vb) return $vb <=> $va;

        if ($naElite) {
            $ca = faCriterioDesempate($pdo, (int)$a['team_id'], $league);
            $cb = faCriterioDesempate($pdo, (int)$b['team_id'], $league);
            // 2) maior espaço na folha
            if ($ca['espaco'] !== $cb['espaco']) return $cb['espaco'] <=> $ca['espaco'];
            // 3) pior campanha
            if ($ca['ruindade'] !== $cb['ruindade']) return $cb['ruindade'] <=> $ca['ruindade'];
        }

        // Fora da ELITE (e como último recurso): prioridade e hora do lance.
        $pa = (int)($a['priority'] ?? 9); $pb = (int)($b['priority'] ?? 9);
        if ($pa !== $pb) return $pa <=> $pb;
        return strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
    });
    return $propostas;
}

function faEspacoNoCap(PDO $pdo, ?int $teamId): ?int
{
    if (!faCapAplica($pdo, $teamId)) return null;
    try {
        $s = getTeamCapSummary($pdo, (int)$teamId);
        return max(0, (int)$s['cap_max'] - (int)$s['payroll']);
    } catch (Throwable $e) {
        error_log('[faEspacoNoCap] ' . $e->getMessage());
        return null;
    }
}

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
            priority TINYINT NOT NULL DEFAULT 2,
            status ENUM('pending','accepted','rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_request_team (request_id, team_id),
            INDEX idx_fa_request_offers_status (status),
            INDEX idx_fa_request_offers_team (team_id),
            CONSTRAINT fk_fa_request_offers_request FOREIGN KEY (request_id) REFERENCES fa_requests(id) ON DELETE CASCADE,
            CONSTRAINT fk_fa_request_offers_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Adiciona coluna priority se ainda não existe (migration automática)
        $hasPriority = $pdo->query("SHOW COLUMNS FROM fa_request_offers LIKE 'priority'")->fetch();
        if (!$hasPriority) {
            $pdo->exec("ALTER TABLE fa_request_offers ADD COLUMN priority TINYINT NOT NULL DEFAULT 2 AFTER amount");
        }
    } catch (Exception $e) {
        error_log('[free-agency] ensureNewFaTables: ' . $e->getMessage());
    }
}

$pdo = db();
waiverGarantirColunaExtra($pdo);   // waivers_extra nasce aqui em bases antigas
ensureTeamFreeAgencyColumns($pdo);
ensureNewFaTables($pdo);
ensureOfferCanceledStatus($pdo);

$user_id = $_SESSION['user_id'];
// Admin global (user_type='admin') OU admin da liga via league_admins — mesmo critério
// usado na página leilao.php, em api/market.php e api/draft.php.
$is_admin = hasAdminAccess($pdo, (int)$user_id);
$team_id = $_SESSION['team_id'] ?? null;

$team = null;
if ($team_id) {
    $stmt = $pdo->prepare('SELECT id, league, COALESCE(moedas, 0) as moedas, waivers_used, COALESCE(waivers_extra, 0) AS waivers_extra, fa_signings_used FROM teams WHERE id = ?');
    $stmt->execute([$team_id]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
}

$team_league = $team['league'] ?? ($_SESSION['user_league'] ?? null);
$team_coins = (int)($team['moedas'] ?? 0);
$valid_leagues = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

if (!$team && $user_id) {
    $stmt = $pdo->prepare('SELECT id, league, COALESCE(moedas, 0) as moedas, waivers_used, COALESCE(waivers_extra, 0) AS waivers_extra, fa_signings_used FROM teams WHERE user_id = ? LIMIT 1');
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
            // O slot comprado vale por UMA temporada, como diz a loja.
            $updates[] = 'waivers_extra = 0';
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

function ensureOfferPriorityColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        if (!columnExists($pdo, 'free_agent_offers', 'priority')) {
            $pdo->exec('ALTER TABLE free_agent_offers ADD COLUMN priority TINYINT NOT NULL DEFAULT 1 AFTER amount');
        }
    } catch (Exception $e) {
        error_log('[free-agency] priority column: ' . $e->getMessage());
    }
    $checked = true;
}

/**
 * Garante que "canceled" existe no ENUM de status das duas tabelas de oferta.
 *
 * O código já mandava status="canceled" quando o time ficava sem moedas ou
 * com o elenco cheio, mas o ENUM só conhecia pending/accepted/rejected — em
 * banco estrito o UPDATE explodia e derrubava a aprovação inteira. O valor
 * importa: "rejected" é o admin dizendo não, "canceled" é o sistema dizendo
 * que a conta não fecha mais.
 *
 * Roda no boot do arquivo, nunca dentro de transação: ALTER TABLE dá commit
 * implícito e partiria a transação da aprovação no meio.
 */
function ensureOfferCanceledStatus(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    foreach (['free_agent_offers', 'fa_request_offers'] as $tabela) {
        try {
            if (!tableExists($pdo, $tabela)) continue;
            $st = $pdo->query("SHOW COLUMNS FROM {$tabela} LIKE 'status'");
            $col = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
            if (!$col || str_contains((string)$col['Type'], "'canceled'")) continue;
            $pdo->exec("ALTER TABLE {$tabela} MODIFY status
                        ENUM('pending','accepted','rejected','canceled') DEFAULT 'pending'");
        } catch (Exception $e) {
            error_log('[free-agency] status canceled em ' . $tabela . ': ' . $e->getMessage());
        }
    }
    $checked = true;
}

function ensureTeamPunishmentColumns(PDO $pdo): void
{
    try {
        if (!columnExists($pdo, 'teams', 'ban_fa_until_cycle')) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN ban_fa_until_cycle INT NULL AFTER ban_trades_picks_until_cycle");
        }
    } catch (Exception $e) {
        // ignore
    }
}

function getTeamCurrentCycle(PDO $pdo, int $teamId): int
{
    if (!columnExists($pdo, 'teams', 'current_cycle')) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT current_cycle FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function isTeamFaBanned(PDO $pdo, int $teamId): bool
{
    ensureTeamPunishmentColumns($pdo);
    if (!columnExists($pdo, 'teams', 'ban_fa_until_cycle')) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT ban_fa_until_cycle FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $banUntil = (int)($stmt->fetchColumn() ?: 0);
    if ($banUntil <= 0) {
        return false;
    }
    $currentCycle = getTeamCurrentCycle($pdo, $teamId);
    return $currentCycle > 0 && $currentCycle <= $banUntil;
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
        case 'admin_fa_history':
            if (!$is_admin) { jsonError('Acesso negado', 403); }
            adminFaHistory($pdo);
            break;
        case 'teams_by_league':
            if (!$is_admin) { jsonError('Acesso negado', 403); }
            $lg = strtoupper(trim($_GET['league'] ?? ''));
            if (!$lg) { jsonSuccess(['teams' => []]); break; }
            $stmtTL = $pdo->prepare('SELECT id, TRIM(CONCAT(COALESCE(city,""), " ", COALESCE(name,""))) AS full_name FROM teams WHERE league = ? ORDER BY name ASC');
            $stmtTL->execute([$lg]);
            jsonSuccess(['teams' => $stmtTL->fetchAll(PDO::FETCH_ASSOC)]);
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
        case 'cap_espaco':
            capEspacoDoTime($pdo, $team_id);
            break;
        case 'dispensados':
            listDispensadosDaTemporada($pdo, getLeagueFromRequest($valid_leagues, $team_league), $team_id);
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
            // Ação cria free agent em uma liga arbitrária vinda do payload; admin de
            // liga (não-global) só pode cadastrar nas ligas que ele administra.
            $addPlayerLeague = strtoupper(trim((string)($body['league'] ?? '')));
            if ($addPlayerLeague && !in_array($addPlayerLeague, getAdminLeagues($pdo, (int)$user_id), true)) {
                jsonError('Você não administra essa liga', 403);
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
        case 'corrigir_ficha':
            corrigirFichaFreeAgent($pdo, $body, (int)$user_id, $team_league);
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
            // Liga arbitrária vinda do payload; admin de liga (não-global) só pode
            // ligar/desligar a FA das ligas que ele administra.
            if (!in_array($league, getAdminLeagues($pdo, (int)$user_id), true)) {
                jsonError('Você não administra essa liga', 403);
            }
            ensureFaEnabledColumn($pdo);
            try {
                if (!tableExists($pdo, 'league_settings')) {
                    jsonError('Tabela league_settings ausente', 500);
                }
                // Inserir ou atualizar com base na UNIQUE(league)
                $estavaLigada = getFaEnabled($pdo, $league);
                $stmt = $pdo->prepare("INSERT INTO league_settings (league, fa_enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE fa_enabled = VALUES(fa_enabled)");
                $stmt->execute([$league, $enabled]);

                // Só avisa quando o estado muda de fato.
                if ($estavaLigada !== ($enabled === 1)) {
                    sendPushToLeague($pdo, $league, $enabled === 1
                        ? ['title' => '💰 Free Agency aberta na ' . $league,
                           'body'  => 'A janela de propostas está no ar. Corra pros free agents!',
                           'url'   => '/free-agency.php']
                        : ['title' => '🔒 Free Agency fechada na ' . $league,
                           'body'  => 'A janela de propostas foi encerrada. Aguarde as decisões do admin.',
                           'url'   => '/free-agency.php'],
                        'free_agency');
                }

                jsonSuccess(['league' => $league, 'enabled' => $enabled === 1]);
            } catch (Exception $e) {
                jsonError('Falha ao atualizar status da FA.', 500);
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
        case 'admin_fa_revert':
            if (!$is_admin) { jsonError('Acesso negado', 403); }
            adminFaRevert($pdo, $body, $user_id);
            break;
        case 'admin_fa_change_team':
            if (!$is_admin) { jsonError('Acesso negado', 403); }
            adminFaChangeTeam($pdo, $body, $user_id);
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
    $hasPriority = columnExists($pdo, 'free_agent_offers', 'priority');
    $prioritySelect = $hasPriority ? 'fao.priority' : '1';
    $stmt = $pdo->prepare("
        SELECT fao.id, fao.free_agent_id, fao.team_id, fao.amount, ({$prioritySelect}) AS priority, fao.status, fao.created_at,
               fa.name AS player_name, fa.position, {$secondarySelect} AS secondary_position, fa.{$ovrColumn} AS ovr, fa.age, fa.original_team_name,
               t.city AS team_city, t.name AS team_name, COALESCE(t.moedas, 0) AS team_coins,
               (SELECT COUNT(*) FROM players WHERE team_id = fao.team_id) AS roster_count
        FROM free_agent_offers fao
        JOIN free_agents fa ON fao.free_agent_id = fa.id
        JOIN teams t ON fao.team_id = t.id
        WHERE {$where} AND fao.status = 'pending'
        ORDER BY fa.name ASC, fao.amount DESC, ({$prioritySelect}) ASC, fao.created_at ASC
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
            'id'           => $offer['id'],
            'team_id'      => $offer['team_id'],
            'team_name'    => trim(($offer['team_city'] ?? '') . ' ' . ($offer['team_name'] ?? '')),
            'amount'       => (int)$offer['amount'],
            'priority'     => (int)($offer['priority'] ?? 1),
            'team_coins'   => (int)$offer['team_coins'],
            'roster_count' => (int)$offer['roster_count'],
            'created_at'   => $offer['created_at']
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

    $stmt = $pdo->prepare("SELECT fa.id, fa.name, fa.original_team_name, fa.waived_at, {$seasonSelect}
        FROM free_agents fa
        {$seasonJoin}
        WHERE {$where}
        ORDER BY fa.waived_at DESC
        LIMIT 200");
    $stmt->execute($params);
    $waivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess(['league' => $league, 'waivers' => $waivers]);
}

/** As dispensas que todo time tem por temporada, antes de comprar slot. */
const WAIVERS_BASE = 3;

function freeAgencyLimits(?array $team): void
{
    $waiversUsed = isset($team['waivers_used']) ? (int)$team['waivers_used'] : 0;
    $signingsUsed = isset($team['fa_signings_used']) ? (int)$team['fa_signings_used'] : 0;
    // O slot comprado na loja entra aqui: o teto do time é a base mais o que
    // ele comprou nesta temporada. Somar no teto, e não descontar do usado,
    // mantém o "usei 3 de 4" legível — descontando, o número de usadas viraria
    // mentira e o admin que mexesse nele apagaria a compra sem perceber.
    $extra = isset($team['waivers_extra']) ? (int)$team['waivers_extra'] : 0;
    jsonSuccess([
        'waivers_used' => $waiversUsed,
        'waivers_max' => WAIVERS_BASE + $extra,
        'waivers_extra' => $extra,
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
         o.id AS offer_id, o.amount, o.priority, o.status AS offer_status,
         wt.city AS winner_city, wt.name AS winner_name
        FROM fa_requests r
        JOIN fa_request_offers o ON o.request_id = r.id
        LEFT JOIN teams wt ON r.winner_team_id = wt.id
        WHERE o.team_id = ?
          AND r.season_id IN (
              SELECT id FROM seasons WHERE sprint_id = (
                  SELECT id FROM sprints
                  WHERE league = (SELECT league FROM teams WHERE id = ?) AND status = "active"
                  ORDER BY id DESC LIMIT 1
              )
          )
        ORDER BY o.created_at DESC
    ');
    $stmt->execute([$teamId, $teamId]);
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
            'priority' => (int)($row['priority'] ?? 2),
            'status' => $status,
            'winner_team' => trim(($row['winner_city'] ?? '') . ' ' . ($row['winner_name'] ?? ''))
        ];
    }

    jsonSuccess(['requests' => $requests]);
}

function listAdminFaRequests(PDO $pdo, string $league): void
{
    $allLeagues = strtoupper(trim($league)) === 'ALL';
    $sql = '
        SELECT r.id AS request_id, r.player_name, r.position, r.secondary_position, r.ovr, r.age, r.season_year,
               o.id AS offer_id, o.amount, o.priority, o.created_at, o.team_id,
               t.city AS team_city, t.name AS team_name, COALESCE(t.moedas, 0) AS team_coins,
               (SELECT COUNT(*) FROM players WHERE team_id = o.team_id) AS roster_count
        FROM fa_requests r
        JOIN fa_request_offers o ON o.request_id = r.id AND o.status = "pending"
        JOIN teams t ON o.team_id = t.id
        WHERE r.status = "open"';

    if (!$allLeagues) {
        $sql .= ' AND r.league = ?';
    }
    $sql .= ' ORDER BY o.amount DESC, o.priority ASC, o.created_at ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($allLeagues ? [] : [$league]);
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
        $grouped[$requestId]['offers'][] = [
            'id' => (int)$row['offer_id'],
            'team_id' => (int)$row['team_id'],
            'team_name' => trim(($row['team_city'] ?? '') . ' ' . ($row['team_name'] ?? '')),
            'amount' => (int)$row['amount'],
            'priority' => (int)($row['priority'] ?? 2),
            'team_coins' => (int)$row['team_coins'],
            'roster_count' => (int)$row['roster_count'],
            'created_at' => $row['created_at']
        ];
    }

    /* A ordem vem do SQL só até o valor; o desempate da liga (espaço na
       folha, depois pior campanha) precisa de conta que o banco não faz
       sozinho. Reordenado aqui, o primeiro da lista é o vencedor. */
    foreach ($grouped as $rid => $g) {
        $grouped[$rid]['offers'] = faOrdenarPropostas($pdo, $g['offers'], $league);
    }

    jsonSuccess(['requests' => array_values($grouped)]);
}

function listNewFaHistory(PDO $pdo, string $league): void
{
    $seasonFilter = isset($_GET['season_year']) ? (int)$_GET['season_year'] : null;
    // Só a sprint em andamento — o histórico das sprints anteriores fica fora.
    $where = 'r.league = ? AND r.status = "assigned"'
           . ' AND r.season_id IN (SELECT id FROM seasons WHERE sprint_id ='
           . ' (SELECT id FROM sprints WHERE league = ? AND status = "active" ORDER BY id DESC LIMIT 1))';
    $params = [$league, $league];
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

function adminFaHistory(PDO $pdo): void
{
    $seasonFilter = isset($_GET['season_year']) ? (int)$_GET['season_year'] : null;
    $leagueFilter = isset($_GET['league']) ? trim($_GET['league']) : null;

    /*
     * SÓ A SPRINT EM ANDAMENTO.
     *
     * Sem este recorte o card somava as 746 contratações de todas as sprints
     * já jogadas. Não dá pra separar por season_year: o ano se repete a cada
     * sprint, então 2026 da sprint 1 e 2026 da sprint 2 caem no mesmo balde.
     * O vínculo confiável é season_id -> seasons.sprint_id, e a subconsulta
     * se amarra em r.league pra funcionar com o filtro em "todas as ligas".
     */
    $where = 'r.status = "assigned"'
           . ' AND r.season_id IN (SELECT id FROM seasons WHERE sprint_id ='
           . ' (SELECT id FROM sprints WHERE league = r.league AND status = "active"'
           . '  ORDER BY id DESC LIMIT 1))';
    $params = [];
    if ($seasonFilter) {
        $where .= ' AND r.season_year = ?';
        $params[] = $seasonFilter;
    }
    if ($leagueFilter) {
        $where .= ' AND r.league = ?';
        $params[] = $leagueFilter;
    }

    $stmt = $pdo->prepare('
        SELECT r.id AS request_id, r.player_name, r.position, r.secondary_position, r.ovr, r.age,
               r.season_year, r.league, r.resolved_at,
               t.id AS team_id,
               TRIM(CONCAT(COALESCE(t.city,""), " ", COALESCE(t.name,""))) AS team_full_name
        FROM fa_requests r
        LEFT JOIN teams t ON r.winner_team_id = t.id
        WHERE ' . $where . '
        ORDER BY t.name ASC, r.resolved_at DESC
    ');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // O seletor de temporada só oferece as que existem na sprint atual —
    // senão ele lista anos que o filtro acima nunca vai devolver.
    $stmtS = $pdo->query('
        SELECT DISTINCT r.season_year FROM fa_requests r
        WHERE r.status = "assigned" AND r.season_year IS NOT NULL
          AND r.season_id IN (SELECT id FROM seasons WHERE sprint_id =
              (SELECT id FROM sprints WHERE league = r.league AND status = "active"
               ORDER BY id DESC LIMIT 1))
        ORDER BY r.season_year DESC');
    $seasons = $stmtS->fetchAll(PDO::FETCH_COLUMN);

    jsonSuccess(['rows' => $rows, 'seasons' => $seasons]);
}

function adminFaRevert(PDO $pdo, array $body, int $adminId): void
{
    $requestId = (int)($body['request_id'] ?? 0);
    if (!$requestId) jsonError('request_id inválido');

    $stmt = $pdo->prepare('
        SELECT r.player_name, r.winner_team_id
        FROM fa_requests r
        WHERE r.id = ? AND r.status = "assigned"
    ');
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) jsonError('Solicitação não encontrada ou já revertida');

    $stmtOffer = $pdo->prepare('SELECT amount FROM fa_request_offers WHERE request_id = ? AND status = "accepted" LIMIT 1');
    $stmtOffer->execute([$requestId]);
    $offer = $stmtOffer->fetch(PDO::FETCH_ASSOC);
    $amount = $offer ? (int)$offer['amount'] : 0;

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM players WHERE team_id = ? AND name = ? LIMIT 1')
            ->execute([(int)$req['winner_team_id'], $req['player_name']]);

        /* Na ELITE não houve desconto — o lance ali é folha salarial, não
           moeda. Devolver aqui criaria moeda do nada, e o valor é em milhões:
           reverter um lance de 7M creditava 7 moedas que ninguém pagou. */
        $eliteRev = faCapAplica($pdo, (int)$req['winner_team_id']);
        if ($amount > 0 && !$eliteRev) {
            $pdo->prepare('UPDATE teams SET moedas = moedas + ? WHERE id = ?')
                ->execute([$amount, (int)$req['winner_team_id']]);
        }

        if (columnExists($pdo, 'teams', 'fa_signings_used')) {
            $pdo->prepare('UPDATE teams SET fa_signings_used = GREATEST(0, COALESCE(fa_signings_used,0) - 1) WHERE id = ?')
                ->execute([(int)$req['winner_team_id']]);
        }

        if ($amount > 0 && !$eliteRev && tableExists($pdo, 'team_coins_log')) {
            $pdo->prepare('INSERT INTO team_coins_log (team_id, amount, reason, admin_id, created_at) VALUES (?,?,?,?,NOW())')
                ->execute([(int)$req['winner_team_id'], $amount, 'Reversão FA: ' . $req['player_name'], $adminId]);
        }

        $pdo->prepare('UPDATE fa_requests SET status = "open", winner_team_id = NULL, resolved_at = NULL WHERE id = ?')
            ->execute([$requestId]);
        $pdo->prepare('UPDATE fa_request_offers SET status = "pending" WHERE request_id = ?')
            ->execute([$requestId]);

        $pdo->commit();
        jsonSuccess(['message' => 'Reversão realizada. Jogador removido do time e moedas devolvidas.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao reverter.', 500);
    }
}

function adminFaChangeTeam(PDO $pdo, array $body, int $adminId): void
{
    $requestId = (int)($body['request_id'] ?? 0);
    $newTeamId = (int)($body['new_team_id'] ?? 0);
    if (!$requestId || !$newTeamId) jsonError('Parâmetros inválidos');

    $stmt = $pdo->prepare('
        SELECT r.player_name, r.position, r.secondary_position, r.age, r.ovr,
               r.winner_team_id AS old_team_id
        FROM fa_requests r
        WHERE r.id = ? AND r.status = "assigned"
    ');
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) jsonError('Solicitação não encontrada');
    if ((int)$req['old_team_id'] === $newTeamId) jsonError('Time já é o atual');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM players WHERE team_id = ? AND name = ? LIMIT 1')
            ->execute([(int)$req['old_team_id'], $req['player_name']]);

        $cols = ['team_id','name','age','position','ovr'];
        $vals = [$newTeamId, $req['player_name'], (int)$req['age'], $req['position'], (int)$req['ovr']];
        if (columnExists($pdo,'players','secondary_position')) { $cols[]='secondary_position'; $vals[]=$req['secondary_position']?:null; }
        if (columnExists($pdo,'players','seasons_in_league'))  { $cols[]='seasons_in_league';  $vals[]=0; }
        if (columnExists($pdo,'players','role'))                { $cols[]='role';                $vals[]='Banco'; }
        if (columnExists($pdo,'players','available_for_trade')) { $cols[]='available_for_trade'; $vals[]=0; }
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $pdo->prepare('INSERT INTO players ('.implode(',',$cols).") VALUES ({$ph})")->execute($vals);

        $pdo->prepare('UPDATE fa_requests SET winner_team_id = ? WHERE id = ?')
            ->execute([$newTeamId, $requestId]);

        $pdo->commit();
        jsonSuccess(['message' => 'Jogador transferido para o novo time com sucesso.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao mudar time.', 500);
    }
}

function requestNewFaPlayer(PDO $pdo, array $body, ?int $teamId, ?string $teamLeague, int $teamCoins): void
{
    if (!$teamId) {
        jsonError('Voce precisa ter um time');
    }

    if (isTeamFaBanned($pdo, (int)$teamId)) {
        jsonError('Seu time está bloqueado de usar a Free Agency nesta temporada');
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
    if ($amount < 0) {
        jsonError('Valor da proposta invalido');
    }
    /* Mesma regra do lance na FA normal: na ELITE o valor é salário, e quem
       limita é o teto da média salarial e o espaço no cap — não a moedinha,
       que lá não existe mais. */
    if (faCapAplica($pdo, (int)$teamId)) {
        $teto = capMediaSalarialDaLiga($pdo, (string)$teamLeague);
        if ($teto > 0 && $amount > $teto) {
            jsonError('O lance máximo da Free Agency é ' . $teto . 'M — a média salarial da liga.');
        }
        $espaco = faEspacoNoCap($pdo, (int)$teamId);
        if ($espaco !== null && $amount > $espaco) {
            jsonError('Você tem ' . $espaco . 'M de espaço no cap, e o lance é de ' . $amount . 'M.');
        }
    } elseif ($teamCoins < $amount) {
        jsonError('Moedas insuficientes');
    }

    // O OVR é o que define o custo no cap, então dá pra saber na hora se o
    // contrato cabe. Cada proposta é medida sozinha — o time pode sondar
    // dois de 40M com 60M de espaço; quem cai é a segunda, se ele ganhar a
    // primeira (ver cancelarPropostasSemEspacoNoCap).
    $fit = faCap($pdo, (int)$teamId, $ovr);
    if (!$fit['cabe']) {
        jsonError('Um jogador de ' . $ovr . ' OVR custa ' . capValorEscrito($fit['custo'], $fit['unidade'])
                . ' no cap, e ' . capEspacoEscrito($fit['espaco'], $fit['unidade']) . '.');
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

    $priority = max(1, min(3, (int)($body['priority'] ?? 2)));

    $stmtUpsert = $pdo->prepare('
        INSERT INTO fa_request_offers (request_id, team_id, amount, priority, status, created_at)
        VALUES (?, ?, ?, ?, "pending", NOW())
        ON DUPLICATE KEY UPDATE amount = VALUES(amount), priority = VALUES(priority), status = "pending"
    ');
    $stmtUpsert->execute([$requestId, $teamId, $amount, $priority]);

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
    /* Na ELITE o lance é salário em milhões, não moeda: comparar os dois
       recusava proposta legítima — um lance de 7M num time com 3 moedas era
       barrado como "sem saldo". Quem manda lá é o cap, conferido logo abaixo. */
    if (!faCapAplica($pdo, (int)$offer['team_id'])
        && (int)$offer['moedas'] < (int)$offer['amount']) {
        jsonError('Time nao tem moedas suficientes');
    }

    if (getTeamFaWins($pdo, (int)$offer['team_id']) >= 3) {
        jsonError('Este time ja atingiu o limite de 3 contratacoes na Free Agency');
    }

    // O espaço pode ter sumido entre a proposta e a aprovação — o time pode
    // ter assinado outro no meio do caminho.
    $fit = faCap($pdo, (int)$offer['team_id'], (int)$offer['ovr']);
    if (!$fit['cabe']) {
        jsonError($offer['player_name'] . ' custa ' . capValorEscrito($fit['custo'], $fit['unidade'])
                . ' e o cap de ' . $offer['team_city'] . ' ' . $offer['team_name'] . ' não cobre: '
                . capEspacoEscrito($fit['espaco'], $fit['unidade']) . '.');
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

        /* NA ELITE O LANCE VIRA O SALÁRIO DO PRIMEIRO ANO.
           Depois dele o jogador passa a receber pela tabela de OVR, como
           todo mundo — a coluna é zerada na virada da temporada. */
        $ehElite = faCapAplica($pdo, (int)$offer['team_id']);
        if ($ehElite && (int)$offer['amount'] > 0) {
            capGarantirColunaContrato($pdo);
            $columns[] = 'contract_salary';
            $values[]  = (int)$offer['amount'];
        }

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $stmtInsert = $pdo->prepare('INSERT INTO players (' . implode(',', $columns) . ") VALUES ({$placeholders})");
        $stmtInsert->execute($values);

        /* "Acabaram as moedinhas na ELITE": lá o lance é folha salarial, e
           descontar moeda cobraria duas vezes pela mesma contratação. Nas
           outras três a moeda continua sendo o preço. */
        if (!$ehElite) {
            $stmtCoins = $pdo->prepare('UPDATE teams SET moedas = moedas - ? WHERE id = ?');
            $stmtCoins->execute([(int)$offer['amount'], (int)$offer['team_id']]);
        }

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

        // O espaço acabou de encolher: o que o time não paga mais sai da mesa.
        cancelarPropostasSemEspacoNoCap($pdo, (int)$offer['team_id']);

        $pdo->commit();
        jsonSuccess([
            'message' => sprintf('%s agora faz parte de %s %s', $offer['player_name'], $offer['team_city'], $offer['team_name'])
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao aprovar solicitacao.', 500);
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
        jsonError('Erro ao recusar solicitacao.', 500);
    }
}

/**
 * Derruba as propostas pendentes que o time não tem mais espaço pra pagar.
 *
 * Cada proposta é isolada na hora de enviar: com 60M dá pra pedir dois de
 * 40M, e é de propósito — o time sonda mais de um alvo. Mas assinar um deles
 * gasta o espaço, e a outra proposta vira promessa que o cap não cobre. Roda
 * depois de toda assinatura, nos dois fluxos de proposta.
 *
 * Cancela, não rejeita: rejeitada é decisão do admin; cancelada é o sistema
 * dizendo que a conta não fecha mais. Devolve quantas caíram.
 */
function cancelarPropostasSemEspacoNoCap(PDO $pdo, int $teamId): int
{
    if ($teamId <= 0) return 0;
    // Fora da ELITE não existe espaço pra faltar: a proposta se sustenta em
    // moeda e prioridade, e nenhuma das duas some porque o time assinou outro.
    if (!faCapAplica($pdo, $teamId)) return 0;
    $caidas = 0;

    // Fluxo novo: fa_request_offers -> fa_requests.ovr
    try {
        $st = $pdo->prepare('SELECT o.id, r.ovr
                             FROM fa_request_offers o
                             JOIN fa_requests r ON r.id = o.request_id
                             WHERE o.team_id = ? AND o.status = "pending" AND r.status = "open"');
        $st->execute([$teamId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
            if (faCap($pdo, $teamId, (int)$o['ovr'])['cabe']) continue;
            $pdo->prepare('UPDATE fa_request_offers SET status = "canceled" WHERE id = ?')->execute([(int)$o['id']]);
            $caidas++;
        }
    } catch (Throwable $e) { error_log('cancelarPropostasSemEspacoNoCap (novo): ' . $e->getMessage()); }

    // Fluxo antigo: free_agent_offers -> free_agents.<coluna de ovr>
    try {
        $col = freeAgentOvrColumn($pdo);
        $st = $pdo->prepare("SELECT o.id, fa.{$col} AS ovr
                             FROM free_agent_offers o
                             JOIN free_agents fa ON fa.id = o.free_agent_id
                             WHERE o.team_id = ? AND o.status = 'pending'");
        $st->execute([$teamId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
            if (faCap($pdo, $teamId, (int)$o['ovr'])['cabe']) continue;
            $pdo->prepare('UPDATE free_agent_offers SET status = "canceled" WHERE id = ?')->execute([(int)$o['id']]);
            $caidas++;
        }
    } catch (Throwable $e) { error_log('cancelarPropostasSemEspacoNoCap (antigo): ' . $e->getMessage()); }

    return $caidas;
}

/**
 * O espaço no cap do time e quanto cada OVR custaria nele.
 *
 * Manda a tabela inteira (40 a 99) de uma vez em vez de responder a cada
 * tecla digitada no campo de OVR: são sessenta números, e assim o formulário
 * atualiza o "custa 16M" na hora, sem ida ao servidor.
 *
 * No modo soma de OVR o custo depende do elenco atual — o reserva que não
 * entra no top custa zero — por isso a tabela é calculada por time, não
 * fixada em código.
 */
/**
 * Quem foi dispensado nesta temporada e ainda está sem time.
 *
 * Só a temporada corrente, de propósito: no jogo, dispensado de temporada
 * passada pode já ter se aposentado, e propor por um fantasma é perder a
 * vaga e as moedas. A ELITE chega aqui depois do waiver de 12h — quem foi
 * reivindicado nunca virou free agent; as outras ligas caem direto.
 *
 * Vem com o custo no cap de quem está olhando, pra lista já dizer o que
 * cabe, e com a proposta que o time já tenha feito pelo mesmo nome.
 */
function listDispensadosDaTemporada(PDO $pdo, ?string $league, ?int $teamId): void
{
    if (!$league) {
        jsonSuccess(['temporada' => null, 'jogadores' => []]);
    }

    $temporada = resolveCurrentSeason($pdo, $league);
    if (!$temporada['id']) {
        jsonSuccess(['temporada' => null, 'jogadores' => []]);
    }

    $ovrCol = freeAgentOvrColumn($pdo);
    $secCol = freeAgentSecondaryColumn($pdo);
    $sec = $secCol ? "fa.{$secCol}" : 'NULL';
    // is_retirement e season_id são colunas novas em bases antigas.
    $aposentou = columnExists($pdo, 'free_agents', 'is_retirement') ? ' AND COALESCE(fa.is_retirement, 0) = 0' : '';
    if (!columnExists($pdo, 'free_agents', 'season_id')) {
        jsonSuccess(['temporada' => $temporada, 'jogadores' => []]);
    }

    $st = $pdo->prepare("
        SELECT fa.id, fa.name, fa.age, fa.position, {$sec} AS secondary_position,
               fa.{$ovrCol} AS ovr, fa.original_team_name, fa.waived_at
        FROM free_agents fa
        WHERE fa.league = ?
          AND fa.season_id = ?
          AND (fa.status = 'available' OR fa.status IS NULL){$aposentou}
        ORDER BY fa.{$ovrCol} DESC, fa.name ASC");
    $st->execute([$league, $temporada['id']]);
    $jogadores = $st->fetchAll(PDO::FETCH_ASSOC);

    /*
     * OS PEDIDOS ABERTOS ENTRAM NA MESMA LISTA.
     *
     * Quem pede um jogador que não está na lista cria uma linha em
     * `fa_requests` — e ela não aparecia em lugar nenhum pros outros GMs. Na
     * prática o jogador existia só pra quem pediu: ninguém mais sabia que
     * dava pra disputar, e a "disputa" era um lance só.
     *
     * Eles vêm marcados com `pedido = 1` porque o lance segue por outro
     * caminho (fa_request_offers, não free_agent_offers) — a tela precisa
     * saber qual dos dois usar.
     */
    try {
        $stR = $pdo->prepare("
            SELECT r.id, r.player_name AS name, r.age, r.position, r.secondary_position, r.ovr,
                   TRIM(CONCAT(COALESCE(t.city,''), ' ', COALESCE(t.name,''))) AS original_team_name,
                   r.created_at AS waived_at,
                   (SELECT COUNT(*) FROM fa_request_offers o
                     WHERE o.request_id = r.id AND o.status = 'pending') AS propostas
            FROM fa_requests r
            LEFT JOIN teams t ON t.id = r.created_by_team_id
            WHERE r.league = ? AND r.status = 'open'
            ORDER BY r.ovr DESC, r.player_name ASC");
        $stR->execute([$league]);
        $jaNaLista = array_map(fn($j) => normalizeFaPlayerName($j['name']), $jogadores);
        foreach ($stR->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // Se o mesmo nome já está entre os dispensados, o pedido é o
            // duplicado — mostrar os dois seria oferecer duas filas pro mesmo
            // jogador.
            if (in_array(normalizeFaPlayerName($r['name']), $jaNaLista, true)) continue;
            $r['pedido'] = 1;
            $r['propostas'] = (int)$r['propostas'];
            $jogadores[] = $r;
        }
    } catch (Throwable $e) {
        error_log('[fa/dispensados] pedidos: ' . $e->getMessage());
    }

    // Proposta que o time já fez, casada pelo nome normalizado — é assim que
    // o fluxo de pedido agrupa, então é assim que ele reconhece o que é seu.
    $jaPedi = [];
    if ($teamId) {
        try {
            $stP = $pdo->prepare('SELECT r.normalized_name, o.amount
                                  FROM fa_request_offers o
                                  JOIN fa_requests r ON r.id = o.request_id
                                  WHERE o.team_id = ? AND o.status = "pending" AND r.status = "open"');
            $stP->execute([$teamId]);
            foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $r) $jaPedi[$r['normalized_name']] = (int)$r['amount'];
        } catch (Throwable $e) {}
    }

    // Depois de juntar as duas origens, a ordem tem que valer pra lista toda.
    usort($jogadores, fn($a, $b) => ((int)$b['ovr'] <=> (int)$a['ovr'])
        ?: strcasecmp((string)$a['name'], (string)$b['name']));

    foreach ($jogadores as &$j) {
        $j['ovr'] = (int)$j['ovr'];
        $j['age'] = (int)$j['age'];
        $j['pedido'] = !empty($j['pedido']) ? 1 : 0;
        $fit = $teamId ? faCap($pdo, $teamId, $j['ovr']) : null;
        $j['cap_custo']   = $fit['custo'] ?? null;
        $j['cap_cabe']    = $fit['cabe'] ?? true;
        $j['cap_unidade'] = $fit['unidade'] ?? 'M';
        $j['minha_proposta'] = $jaPedi[normalizeFaPlayerName($j['name'])] ?? null;
    }
    unset($j);

    jsonSuccess(['temporada' => $temporada, 'league' => $league, 'jogadores' => $jogadores]);
}

function capEspacoDoTime(PDO $pdo, ?int $teamId): void
{
    if (!$teamId) {
        jsonSuccess(['espaco' => null, 'unidade' => 'M', 'custo_por_ovr' => []]);
    }
    // Fora da ELITE a resposta é "não há cap": espaço nulo. O front lê isso
    // e esconde o aviso inteiro em vez de mostrar zero, que pareceria um time
    // estourado.
    if (!faCapAplica($pdo, $teamId)) {
        jsonSuccess(['espaco' => null, 'unidade' => 'M', 'modo' => 'livre', 'custo_por_ovr' => []]);
    }
    $base = capCabeNoTime($pdo, $teamId, 80);
    $tabela = [];
    for ($ovr = 40; $ovr <= 99; $ovr++) {
        $tabela[$ovr] = capCabeNoTime($pdo, $teamId, $ovr)['custo'];
    }
    jsonSuccess([
        'espaco'        => $base['espaco'],
        'unidade'       => $base['unidade'],
        'modo'          => $base['modo'],
        'custo_por_ovr' => $tabela,
    ]);
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
        /* Só as desta sprint. Sem o recorte, este plano B contava a vida
           inteira do time — 37 contratações num caso — e o limite de 3 da
           FA barraria todo mundo pra sempre. */
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM fa_requests r
            WHERE r.winner_team_id = ? AND r.status = "assigned"
              AND r.season_id IN (SELECT id FROM seasons WHERE sprint_id =
                  (SELECT id FROM sprints WHERE league = r.league AND status = "active"
                   ORDER BY id DESC LIMIT 1))');
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

    $offerId  = (int)($body['offer_id'] ?? 0);
    $amount   = (int)($body['amount'] ?? 0);
    $priority = max(1, min(3, (int)($body['priority'] ?? 2)));
    if (!$offerId) {
        jsonError('Proposta invalida');
    }
    if ($amount <= 0) {
        jsonError('Valor invalido');
    }
    // Editar a proposta passa pela mesma régua de criá-la: na ELITE, teto da
    // média salarial e espaço no cap; nas outras, moeda.
    if (faCapAplica($pdo, (int)$teamId)) {
        $ligaDoTime = faLigaDoTime($pdo, (int)$teamId);
        $teto = capMediaSalarialDaLiga($pdo, $ligaDoTime);
        if ($teto > 0 && $amount > $teto) {
            jsonError('O lance máximo da Free Agency é ' . $teto . 'M — a média salarial da liga.');
        }
        $espaco = faEspacoNoCap($pdo, (int)$teamId);
        if ($espaco !== null && $amount > $espaco) {
            jsonError('Você tem ' . $espaco . 'M de espaço no cap, e o lance é de ' . $amount . 'M.');
        }
    } elseif ($teamCoins < $amount) {
        jsonError('Moedas insuficientes');
    }

    $stmt = $pdo->prepare('SELECT id FROM fa_request_offers WHERE id = ? AND team_id = ? AND status = "pending"');
    $stmt->execute([$offerId, $teamId]);
    if (!$stmt->fetchColumn()) {
        jsonError('Proposta nao encontrada');
    }

    $stmtUpdate = $pdo->prepare('UPDATE fa_request_offers SET amount = ?, priority = ? WHERE id = ?');
    $stmtUpdate->execute([$amount, $priority, $offerId]);
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
        jsonError('Erro ao excluir proposta.', 500);
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
    ensureOfferPriorityColumn($pdo);

    if (!$teamId) {
        jsonError('Voce precisa ter um time');
    }

    if (isTeamFaBanned($pdo, (int)$teamId)) {
        jsonError('Seu time est? bloqueado de usar a Free Agency nesta temporada');
    }

    $player_id = (int)($body['free_agent_id'] ?? 0);
    $amount = (int)($body['amount'] ?? 0);
    $priority = (int)($body['priority'] ?? 1);
    if ($priority < 1 || $priority > 3) {
        $priority = 1;
    }

    if (!$player_id) {
        jsonError('Dados invalidos');
    }

    // Bloqueio por per?odo fechado na liga do time
    if ($teamLeague && !getFaEnabled($pdo, $teamLeague)) {
        jsonError('O per?odo de propostas est? fechado para esta liga');
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

    /*
     * NA ELITE O LANCE É SALÁRIO, NÃO MOEDA — regra da liga, 30/08/2026.
     *
     * "Acabaram as moedinhas na ELITE": o time oferece milhões, o teto é a
     * média salarial da liga, e o que ele ofereceu vira o salário do jogador
     * no primeiro ano. Nas outras três nada muda — lá o lance continua em
     * moedas, que é a régua que elas têm.
     */
    if (faCapAplica($pdo, (int)$teamId)) {
        $teto = capMediaSalarialDaLiga($pdo, (string)$teamLeague);
        if ($teto > 0 && $amount > $teto) {
            jsonError('O lance máximo da Free Agency é ' . $teto . 'M — a média salarial da liga.');
        }

        // O que pesa no cap é o LANCE, não a tabela por OVR: é ele que vira o
        // salário. Sem isto o time daria 7M num jogador com 2M de espaço.
        $espaco = faEspacoNoCap($pdo, (int)$teamId);
        if ($espaco !== null && $amount > $espaco) {
            jsonError('Você tem ' . $espaco . 'M de espaço no cap, e o lance é de ' . $amount . 'M.');
        }
    } elseif ($teamCoins < $amount) {
        jsonError('Moedas insuficientes');
    }

    $stmt = $pdo->prepare('SELECT id FROM free_agent_offers WHERE free_agent_id = ? AND team_id = ?');
    $stmt->execute([$player_id, $teamId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($amount > 0 && !$existing) {
        // Bloqueia só se o elenco já está literalmente cheio (sem vagas).
        // O time pode enviar várias propostas simultâneas; a validação de quantos
        // pode GANHAR é feita no momento da aprovação (approveOffer).
        $stmtRoster = $pdo->prepare('SELECT COUNT(*) FROM players WHERE team_id = ?');
        $stmtRoster->execute([$teamId]);
        $rosterCount = (int)$stmtRoster->fetchColumn();

        if ($rosterCount >= ELENCO_MAX) {
            jsonError('Elenco cheio (' . ELENCO_MAX . ' jogadores). Dispense um jogador antes de enviar propostas.');
        }
    }

    if (!$existing) {
        // Limite de propostas pendentes adicionais (seguran?a existente)
        $stmtLimit = $pdo->prepare('SELECT COUNT(*) FROM free_agent_offers WHERE team_id = ? AND status = "pending"');
        $stmtLimit->execute([$teamId]);
        $pendingCount = (int)$stmtLimit->fetchColumn();
        if ($pendingCount >= 10) {
            jsonError('Limite de 10 propostas pendentes por time');
        }

        $stmt = $pdo->prepare('INSERT INTO free_agent_offers (free_agent_id, team_id, amount, priority, status, created_at) VALUES (?, ?, ?, ?, "pending", NOW())');
        $stmt->execute([$player_id, $teamId, $amount, $priority]);
    } else {
        $stmt = $pdo->prepare('UPDATE free_agent_offers SET amount = ?, priority = ?, status = "pending", updated_at = NOW() WHERE id = ?');
        $stmt->execute([$amount, $priority, $existing['id']]);
    }

    jsonSuccess(['success' => true]);
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

    /* Na ELITE o lance é salário em milhões, não moeda: comparar os dois
       recusava proposta legítima — um lance de 7M num time com 3 moedas era
       barrado como "sem saldo". Quem manda lá é o cap, conferido logo abaixo. */
    if (!faCapAplica($pdo, (int)$offer['team_id'])
        && (int)$offer['moedas'] < (int)$offer['amount']) {
        jsonError('Time nao tem moedas suficientes');
    }

    // Quem mais estava na disputa — precisa ser lido ANTES, porque o UPDATE
    // abaixo já muda todas essas propostas pra "rejected".
    $perdedores = [];
    try {
        $stPerd = $pdo->prepare('SELECT DISTINCT team_id FROM free_agent_offers WHERE free_agent_id = ? AND status = "pending" AND team_id <> ?');
        $stPerd->execute([(int)$offer['free_agent_id'], (int)$offer['team_id']]);
        $perdedores = array_map('intval', $stPerd->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('approveOffer perdedores: ' . $e->getMessage());
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

        /* NA ELITE O LANCE VIRA O SALÁRIO DO PRIMEIRO ANO.
           Depois dele o jogador passa a receber pela tabela de OVR, como
           todo mundo — a coluna é zerada na virada da temporada. */
        $ehElite = faCapAplica($pdo, (int)$offer['team_id']);
        if ($ehElite && (int)$offer['amount'] > 0) {
            capGarantirColunaContrato($pdo);
            $columns[] = 'contract_salary';
            $values[]  = (int)$offer['amount'];
        }

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $stmtInsert = $pdo->prepare('INSERT INTO players (' . implode(',', $columns) . ") VALUES ({$placeholders})");
        $stmtInsert->execute($values);

        /* "Acabaram as moedinhas na ELITE": lá o lance é folha salarial, e
           descontar moeda cobraria duas vezes pela mesma contratação. Nas
           outras três a moeda continua sendo o preço. */
        if (!$ehElite) {
            $stmtCoins = $pdo->prepare('UPDATE teams SET moedas = moedas - ? WHERE id = ?');
            $stmtCoins->execute([(int)$offer['amount'], (int)$offer['team_id']]);
        }

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

        // Rejeitar outras propostas para este mesmo free agent
        $stmtOffers = $pdo->prepare('
            UPDATE free_agent_offers
            SET status = CASE WHEN id = ? THEN "accepted" ELSE "rejected" END
            WHERE free_agent_id = ? AND status = "pending"
        ');
        $stmtOffers->execute([(int)$offer['id'], (int)$offer['free_agent_id']]);

        // Calcular novo estado do time vencedor
        $newCoins = (int)$offer['moedas'] - (int)$offer['amount'];
        $stmtNewRoster = $pdo->prepare('SELECT COUNT(*) FROM players WHERE team_id = ?');
        $stmtNewRoster->execute([(int)$offer['team_id']]);
        $newRosterCount = (int)$stmtNewRoster->fetchColumn();

        // Cancelar outras propostas deste time que ele não consegue mais ganhar:
        // — elenco cheio (bateu o teto) OU moedas insuficientes
        if ($newRosterCount >= ELENCO_MAX) {
            // Elenco cheio: cancela todas as demais propostas pendentes do time
            $pdo->prepare('UPDATE free_agent_offers SET status = "canceled" WHERE team_id = ? AND status = "pending"')
                ->execute([(int)$offer['team_id']]);
        } elseif ($newCoins < 0) {
            // Moedas insuficientes: cancela propostas que custam mais do que sobrou
            $pdo->prepare('UPDATE free_agent_offers SET status = "canceled" WHERE team_id = ? AND status = "pending" AND amount > 0')
                ->execute([(int)$offer['team_id']]);
        } else {
            $pdo->prepare('UPDATE free_agent_offers SET status = "canceled" WHERE team_id = ? AND status = "pending" AND amount > ?')
                ->execute([(int)$offer['team_id'], $newCoins]);
        }

        // E as que o cap não cobre mais — o jogador que acabou de entrar
        // comeu o espaço que sustentava as outras propostas.
        cancelarPropostasSemEspacoNoCap($pdo, (int)$offer['team_id']);

        $pdo->commit();

        // Só depois do commit — o resultado da disputa vai pra quem participou.
        try {
            $nomeJogador = (string)$offer['player_name'];
            sendPushToTeam($pdo, (int)$offer['team_id'], [
                'title' => '✍️ Free Agent assinado!',
                'body'  => "{$nomeJogador} é seu por " . (int)$offer['amount'] . ' moedas.',
                'url'   => '/my-roster.php',
            ], 'free_agency');
            foreach ($perdedores as $tid) {
                sendPushToTeam($pdo, $tid, [
                    'title' => '❌ Free Agent perdido',
                    'body'  => "{$nomeJogador} assinou com {$offer['team_city']} {$offer['team_name']}.",
                    'url'   => '/free-agency.php',
                ], 'free_agency');
            }
        } catch (Throwable $e) {
            error_log('push approveOffer #' . $offer_id . ': ' . $e->getMessage());
        }

        jsonSuccess([
            'message' => sprintf('%s agora faz parte de %s %s', $offer['player_name'], $offer['team_city'], $offer['team_name'])
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao aprovar proposta.', 500);
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
        jsonError('Erro ao encerrar sem vencedor.', 500);
    }
}

/**
 * CORRIGIR O OVR E A IDADE DE UM FREE AGENT.
 *
 * O app e o jogo saem do lugar: o atleta é cadastrado uma vez e continua
 * evoluindo dentro do 2K, então o card mostra 46 OVR / 19 anos enquanto o
 * vídeo do GM mostra 71 / 21. Antes, o jeito de resolver era o GM cadastrar um
 * SEGUNDO jogador com o mesmo nome — e aí a liga ficava com dois Xue Yuyang,
 * um deles fantasma, e ninguém sabia em qual dar lance.
 *
 * Por isso a correção é aberta a qualquer GM, e não só ao admin: quem está
 * assistindo ao vídeo é quem vê a diferença, e esperar o admin é o que fazia
 * todo mundo duplicar.
 *
 * O que protege de mão pesada:
 * - só enquanto o jogador está DISPONÍVEL — assinado ou fechado, o número já
 *   valeu pra decisão de alguém e mexer nele reescreveria a disputa;
 * - só na liga do próprio GM;
 * - toda mudança fica registrada com quem fez e o valor anterior.
 */
function corrigirFichaFreeAgent(PDO $pdo, array $body, int $userId, ?string $minhaLiga): void
{
    $id   = (int)($body['free_agent_id'] ?? 0);
    $ovr  = isset($body['ovr']) ? (int)$body['ovr'] : null;
    $age  = isset($body['age']) ? (int)$body['age'] : null;
    $nome = isset($body['nome']) ? trim((string)$body['nome']) : null;

    if (!$id) jsonError('Jogador não informado');
    if ($ovr === null && $age === null && $nome === null) jsonError('Informe o que mudou');
    /* O nome e o que identifica o atleta na lista: apagar deixaria um card sem
       dono, e um nome de uma letra e erro de digitacao, nao correcao. */
    if ($nome !== null && mb_strlen($nome) < 2) jsonError('O nome não pode ficar vazio');
    if ($nome !== null && mb_strlen($nome) > 120) jsonError('Nome longo demais');
    if ($ovr !== null && ($ovr < 40 || $ovr > 99)) jsonError('OVR precisa ficar entre 40 e 99');
    if ($age !== null && ($age < 18 || $age > 45)) jsonError('Idade precisa ficar entre 18 e 45');

    $st = $pdo->prepare('SELECT id, name, overall, age, league, status FROM free_agents WHERE id = ?');
    $st->execute([$id]);
    $fa = $st->fetch(PDO::FETCH_ASSOC);
    /* Sumir da lista quase sempre significa que OUTRO GM acabou de resolver o
       caso — removeu a duplicata, ou o jogador foi assinado. Dizer so "nao
       encontrado" faz a pessoa achar que o proprio clique falhou. */
    if (!$fa) jsonError('Esse jogador saiu da lista — alguém mexeu nele agora há pouco. A lista foi atualizada.');

    if ($minhaLiga && strtoupper((string)$fa['league']) !== strtoupper($minhaLiga)) {
        jsonError('Esse jogador é de outra liga', 403);
    }
    $status = strtolower((string)($fa['status'] ?? 'available'));
    if ($status !== '' && $status !== 'available') {
        jsonError('Esse jogador já saiu da fila — a ficha dele não muda mais');
    }

    $novoOvr  = $ovr  ?? (int)$fa['overall'];
    $novaAge  = $age  ?? (int)$fa['age'];
    $novoNome = $nome ?? (string)$fa['name'];
    if ($novoOvr === (int)$fa['overall'] && $novaAge === (int)$fa['age']
        && $novoNome === (string)$fa['name']) {
        jsonSuccess(['mudou' => false, 'ovr' => $novoOvr, 'age' => $novaAge, 'nome' => $novoNome]);
    }

    /* O registro nasce junto com a primeira correção: a tabela é pequena e
       criar aqui evita mais uma migração pra uma coisa que só este caminho
       usa. */
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS free_agent_correcoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            free_agent_id INT NOT NULL,
            user_id INT NOT NULL,
            ovr_antes INT NULL, ovr_depois INT NULL,
            age_antes INT NULL, age_depois INT NULL,
            nome_antes VARCHAR(120) NULL, nome_depois VARCHAR(120) NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_fa (free_agent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        // A tabela pode ter nascido antes de o nome ser editável, e o CREATE
        // acima não mexe em tabela que já existe.
        if (!$pdo->query("SHOW COLUMNS FROM free_agent_correcoes LIKE 'nome_antes'")->fetch()) {
            $pdo->exec("ALTER TABLE free_agent_correcoes
                        ADD COLUMN nome_antes VARCHAR(120) NULL,
                        ADD COLUMN nome_depois VARCHAR(120) NULL");
        }
    } catch (Throwable $e) {
        error_log('[fa] tabela de correcoes: ' . $e->getMessage());
    }

    $pdo->prepare('UPDATE free_agents SET overall = ?, age = ?, name = ? WHERE id = ?')
        ->execute([$novoOvr, $novaAge, $novoNome, $id]);

    try {
        $pdo->prepare('INSERT INTO free_agent_correcoes
                       (free_agent_id, user_id, ovr_antes, ovr_depois, age_antes, age_depois, nome_antes, nome_depois)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $userId, (int)$fa['overall'], $novoOvr, (int)$fa['age'], $novaAge,
                       (string)$fa['name'], $novoNome]);
    } catch (Throwable $e) {
        error_log('[fa] registrar correcao: ' . $e->getMessage());
    }

    jsonSuccess([
        'mudou' => true,
        'ovr'   => $novoOvr,
        'age'   => $novaAge,
        'nome'  => $novoNome,
    ]);
}
