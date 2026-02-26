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
// Helpers para checar colunas/tabelas e detectar o campo de OVR
function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) { return false; }
}

function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) { return false; }
}

function playerOvrColumn(PDO $pdo): string {
    return columnExists($pdo, 'players', 'ovr') ? 'ovr' : (columnExists($pdo, 'players', 'overall') ? 'overall' : 'ovr');
}

// Snapshot dos jogadores nos itens da trade, para manter histórico mesmo se o jogador for dispensado
function ensureTradeItemSnapshotColumns(PDO $pdo): void {
    if (!tableExists($pdo, 'trade_items')) return;
    try {
        if (!columnExists($pdo, 'trade_items', 'player_name')) {
            $pdo->exec("ALTER TABLE trade_items ADD COLUMN player_name VARCHAR(255) NULL AFTER player_id");
        }
        if (!columnExists($pdo, 'trade_items', 'player_position')) {
            $pdo->exec("ALTER TABLE trade_items ADD COLUMN player_position VARCHAR(10) NULL AFTER player_name");
        }
        if (!columnExists($pdo, 'trade_items', 'player_age')) {
            $pdo->exec("ALTER TABLE trade_items ADD COLUMN player_age INT NULL AFTER player_position");
        }
        if (!columnExists($pdo, 'trade_items', 'player_ovr')) {
            $pdo->exec("ALTER TABLE trade_items ADD COLUMN player_ovr INT NULL AFTER player_age");
        }
    } catch (Exception $e) {
        // ignora falhas de migração em runtime
    }
}

ensureTradeItemSnapshotColumns($pdo);

function ensureTradeItemPickProtectionColumn(PDO $pdo): void {
    if (!tableExists($pdo, 'trade_items')) {
        return;
    }
    try {
        if (!columnExists($pdo, 'trade_items', 'pick_protection')) {
            $pdo->exec("ALTER TABLE trade_items ADD COLUMN pick_protection VARCHAR(20) NULL AFTER pick_id");
        }
    } catch (Exception $e) {
        // ignora caso não seja possível alterar em runtime
    }
}

ensureTradeItemPickProtectionColumn($pdo);

function ensureTradeReactionsTable(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'trade_reactions'");
        if ($stmt->rowCount() > 0) {
            return;
        }
        $pdo->exec("CREATE TABLE trade_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            trade_id INT NOT NULL,
            trade_type ENUM('single','multi') NOT NULL DEFAULT 'single',
            user_id INT NOT NULL,
            emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_trade_reaction (trade_id, trade_type, user_id),
            INDEX idx_trade_reaction_trade (trade_id, trade_type),
            INDEX idx_trade_reaction_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {
        // ignore
    }
}

function ensureTradeReactionsEmojiCollation(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SHOW FULL COLUMNS FROM trade_reactions LIKE 'emoji'");
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos((string)$col['Collation'], 'utf8mb4_bin') === false) {
            $pdo->exec("ALTER TABLE trade_reactions MODIFY emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
        }
    } catch (Exception $e) {
        // ignore
    }
}

function buildTradeReactionsMap(PDO $pdo, array $tradeIds, int $userId, string $tradeType): array
{
    if (empty($tradeIds)) {
        return [];
    }

    ensureTradeReactionsTable($pdo);
    ensureTradeReactionsEmojiCollation($pdo);

    $tradeIds = array_values(array_filter(array_map('intval', $tradeIds)));
    if (empty($tradeIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($tradeIds), '?'));
    $map = [];

    $stmtAgg = $pdo->prepare("SELECT trade_id, emoji, COUNT(*) AS total FROM trade_reactions WHERE trade_type = ? AND trade_id IN ($placeholders) GROUP BY trade_id, emoji");
    $stmtAgg->execute(array_merge([$tradeType], $tradeIds));
    while ($row = $stmtAgg->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$row['trade_id'];
        if (!isset($map[$tid])) {
            $map[$tid] = [];
        }
        $map[$tid][] = [
            'emoji' => $row['emoji'],
            'count' => (int)$row['total']
        ];
    }

    $stmtMine = $pdo->prepare("SELECT trade_id, emoji FROM trade_reactions WHERE trade_type = ? AND user_id = ? AND trade_id IN ($placeholders)");
    $stmtMine->execute(array_merge([$tradeType, $userId], $tradeIds));
    $mineByTrade = [];
    while ($row = $stmtMine->fetch(PDO::FETCH_ASSOC)) {
        $mineByTrade[(int)$row['trade_id']] = (string)$row['emoji'];
    }

    foreach ($tradeIds as $tid) {
        $list = $map[$tid] ?? [];
        $mineEmoji = $mineByTrade[$tid] ?? null;
        if ($mineEmoji) {
            $found = false;
            foreach ($list as &$it) {
                if ($it['emoji'] === $mineEmoji) {
                    $it['mine'] = true;
                    $found = true;
                    break;
                }
            }
            unset($it);
            if (!$found) {
                $list[] = ['emoji' => $mineEmoji, 'count' => 0, 'mine' => true];
            }
        }
        $map[$tid] = $list;
    }

    return $map;
}

function ensureMultiTradeTables(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS multi_trades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            league ENUM('ELITE','NEXT','RISE','ROOKIE') NULL,
            created_by_team_id INT NOT NULL,
            status ENUM('pending','accepted','cancelled') DEFAULT 'pending',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_multi_trades_league (league),
            INDEX idx_multi_trades_status (status),
            FOREIGN KEY (created_by_team_id) REFERENCES teams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS multi_trade_teams (
            id INT AUTO_INCREMENT PRIMARY KEY,
            trade_id INT NOT NULL,
            team_id INT NOT NULL,
            accepted_at TIMESTAMP NULL,
            UNIQUE KEY uniq_multi_trade_team (trade_id, team_id),
            INDEX idx_multi_trade_team (team_id),
            FOREIGN KEY (trade_id) REFERENCES multi_trades(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS multi_trade_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            trade_id INT NOT NULL,
            from_team_id INT NOT NULL,
            to_team_id INT NOT NULL,
            player_id INT NULL,
            pick_id INT NULL,
            pick_protection VARCHAR(20) NULL,
            player_name VARCHAR(255) NULL,
            player_position VARCHAR(10) NULL,
            player_age INT NULL,
            player_ovr INT NULL,
            INDEX idx_multi_trade_item_trade (trade_id),
            INDEX idx_multi_trade_item_from (from_team_id),
            INDEX idx_multi_trade_item_to (to_team_id),
            FOREIGN KEY (trade_id) REFERENCES multi_trades(id) ON DELETE CASCADE,
            FOREIGN KEY (from_team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (to_team_id) REFERENCES teams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {
        // ignore migration errors
    }
}

ensureMultiTradeTables($pdo);

// Garante coluna 'cycle' para controle de limite por ciclo de temporadas
function ensureTradeCycleColumn(PDO $pdo): void
{
    try {
        $col = $pdo->query("SHOW COLUMNS FROM trades LIKE 'cycle'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE trades ADD COLUMN cycle INT NULL AFTER league");
        }
    } catch (Exception $e) {
        // Se não conseguir (permissões/sem tabela), segue sem ciclo
    }
}

function ensureTeamTradeCounterColumns(PDO $pdo): void
{
    try {
        $hasTradesUsed = $pdo->query("SHOW COLUMNS FROM teams LIKE 'trades_used'")->fetch();
        if (!$hasTradesUsed) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN trades_used INT NOT NULL DEFAULT 0 AFTER current_cycle");
        }
        $hasTradesCycle = $pdo->query("SHOW COLUMNS FROM teams LIKE 'trades_cycle'")->fetch();
        if (!$hasTradesCycle) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN trades_cycle INT NOT NULL DEFAULT 1 AFTER trades_used");
        }
    } catch (Exception $e) {
        // Se não conseguir (permissões), segue sem colunas
    }
}

function ensureTeamPunishmentColumns(PDO $pdo): void
{
    try {
        if (!columnExists($pdo, 'teams', 'ban_trades_until_cycle')) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN ban_trades_until_cycle INT NULL AFTER trades_cycle");
        }
        if (!columnExists($pdo, 'teams', 'ban_trades_picks_until_cycle')) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN ban_trades_picks_until_cycle INT NULL AFTER ban_trades_until_cycle");
        }
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

function isTeamTradeBanned(PDO $pdo, int $teamId): bool
{
    if (!columnExists($pdo, 'teams', 'ban_trades_until_cycle')) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT ban_trades_until_cycle FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $banUntil = (int)($stmt->fetchColumn() ?: 0);
    if ($banUntil <= 0) {
        return false;
    }
    $currentCycle = getTeamCurrentCycle($pdo, $teamId);
    return $currentCycle > 0 && $currentCycle <= $banUntil;
}

function isTeamPickTradeBanned(PDO $pdo, int $teamId): bool
{
    if (!columnExists($pdo, 'teams', 'ban_trades_picks_until_cycle')) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT ban_trades_picks_until_cycle FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $banUntil = (int)($stmt->fetchColumn() ?: 0);
    if ($banUntil <= 0) {
        return false;
    }
    $currentCycle = getTeamCurrentCycle($pdo, $teamId);
    return $currentCycle > 0 && $currentCycle <= $banUntil;
}

ensureTradeCycleColumn($pdo);
ensureTeamTradeCounterColumns($pdo);
ensureTeamPunishmentColumns($pdo);

function syncTeamTradeCounter(PDO $pdo, int $teamId): int
{
    try {
        $stmt = $pdo->prepare('SELECT current_cycle, trades_cycle, trades_used FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0;
        }
        $currentCycle = (int)($row['current_cycle'] ?? 0);
        $tradesCycle = (int)($row['trades_cycle'] ?? 0);
        $tradesUsed = (int)($row['trades_used'] ?? 0);

        // Se trades_cycle ainda não estiver inicializado, alinhar com current_cycle e não zerar o contador.
        if ($currentCycle > 0 && $tradesCycle <= 0) {
            $pdo->prepare('UPDATE teams SET trades_cycle = ? WHERE id = ?')
                ->execute([$currentCycle, $teamId]);
            return $tradesUsed;
        }

        // Só zera quando já existe um ciclo anterior registrado e ele mudou
        if ($currentCycle > 0 && $tradesCycle > 0 && $tradesCycle !== $currentCycle) {
            $pdo->prepare('UPDATE teams SET trades_used = 0, trades_cycle = ? WHERE id = ?')
                ->execute([$currentCycle, $teamId]);
            return 0;
        }

        return $tradesUsed;
    } catch (Exception $e) {
        return 0;
    }
}

function getTeamTradesUsed(PDO $pdo, int $teamId): int
{
    // Primeiro tenta o novo modelo (campo em teams). 0 é um valor válido.
    try {
        $col = $pdo->query("SHOW COLUMNS FROM teams LIKE 'trades_used'")->fetch();
        $col2 = $pdo->query("SHOW COLUMNS FROM teams LIKE 'trades_cycle'")->fetch();
        if ($col && $col2) {
            return syncTeamTradeCounter($pdo, $teamId);
        }
    } catch (Exception $e) {
        // segue pro fallback
    }

    // Fallback antigo: contagem por ano (somente se o schema novo não existir)
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM trades WHERE status = 'accepted' AND YEAR(updated_at) = YEAR(NOW()) AND (from_team_id = ? OR to_team_id = ?)");
        $stmt->execute([$teamId, $teamId]);
        return (int) ($stmt->fetchColumn() ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

function getLeagueMaxTrades(PDO $pdo, string $league, int $default = 3): int
{
    try {
        $stmt = $pdo->prepare('SELECT max_trades FROM league_settings WHERE league = ?');
        $stmt->execute([$league]);
        $settings = $stmt->fetch();
        if ($settings && isset($settings['max_trades'])) {
            return (int) $settings['max_trades'];
        }
    } catch (Exception $e) {
        error_log('Erro ao buscar limite de trades: ' . $e->getMessage());
    }
    return $default;
}

function getTeamLeague(PDO $pdo, int $teamId): ?string
{
    $stmt = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    return $stmt->fetchColumn() ?: null;
}

function normalizePickId(PDO $pdo, int $pickId): int
{
    $stmtPick = $pdo->prepare('SELECT * FROM picks WHERE id = ?');
    $stmtPick->execute([$pickId]);
    $pick = $stmtPick->fetch(PDO::FETCH_ASSOC);

    if (!$pick) {
        throw new Exception('Pick ID ' . $pickId . ' não encontrada');
    }

    $stmtDuplicates = $pdo->prepare('SELECT * FROM picks WHERE original_team_id = ? AND season_year = ? AND round = ? ORDER BY id ASC');
    $stmtDuplicates->execute([$pick['original_team_id'], $pick['season_year'], $pick['round']]);
    $duplicates = $stmtDuplicates->fetchAll(PDO::FETCH_ASSOC);

    if (count($duplicates) <= 1) {
        return $pickId;
    }

    // Prioriza o registro usado na trade ou qualquer registro manual
    $canonical = $duplicates[0];
    foreach ($duplicates as $dup) {
        if ((int)$dup['id'] === (int)$pickId) {
            $canonical = $dup;
            break;
        }
        if ((int)($canonical['auto_generated'] ?? 1) === 1 && (int)($dup['auto_generated'] ?? 1) === 0) {
            $canonical = $dup;
        }
    }

    $canonicalId = (int)$canonical['id'];

    $stmtUpdateTradeItems = $pdo->prepare('UPDATE trade_items SET pick_id = ? WHERE pick_id = ?');
    $stmtDeletePick = $pdo->prepare('DELETE FROM picks WHERE id = ?');

    foreach ($duplicates as $dup) {
        if ((int)$dup['id'] === $canonicalId) {
            continue;
        }

        // Atualiza o registro canônico com os dados do duplicado se ele for mais recente/manual
        if ((int)$dup['id'] === (int)$pickId) {
            $stmt = $pdo->prepare('UPDATE picks SET team_id = ?, season_id = ?, auto_generated = ?, notes = ?, last_owner_team_id = ? WHERE id = ?');
            $stmt->execute([
                $dup['team_id'],
                $dup['season_id'],
                $dup['auto_generated'],
                $dup['notes'],
                $dup['last_owner_team_id'],
                $canonicalId
            ]);
        }

        $stmtUpdateTradeItems->execute([$canonicalId, $dup['id']]);
        $stmtDeletePick->execute([$dup['id']]);
    }

    return $canonicalId;
}

function normalizePickPayloadList($raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $allowedProtections = ['top3', 'top5', 'top10', 'lottery'];
    $normalized = [];
    foreach ($raw as $entry) {
        if (is_array($entry)) {
            $pickId = isset($entry['id']) ? (int)$entry['id'] : null;
            if (!$pickId) {
                continue;
            }
            $protection = $entry['protection'] ?? null;
        } else {
            $pickId = (int)$entry;
            if (!$pickId) {
                continue;
            }
            $protection = null;
        }

        $protection = is_string($protection) ? strtolower($protection) : null;
        if (!in_array($protection, $allowedProtections, true)) {
            $protection = null;
        }

        $normalized[] = [
            'id' => $pickId,
            'protection' => $protection
        ];
    }

    return $normalized;
}

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
if ($method === 'GET' && ($_GET['action'] ?? '') !== 'multi_trades') {
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
    } elseif ($type === 'league') {
        $conditions[] = '(COALESCE(t.league, from_team.league, to_team.league)) = ?';
        $conditions[] = "t.status = 'accepted'";
        $params[] = $user['league'];
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

    $tradeIds = array_map('intval', array_column($trades, 'id'));
    $reactionsMap = buildTradeReactionsMap($pdo, $tradeIds, (int)$user['id'], 'single');
    
    // Para cada trade, buscar itens
    foreach ($trades as &$trade) {
        $trade['offer_players'] = [];
        $trade['offer_picks'] = [];
        $trade['request_players'] = [];
        $trade['request_picks'] = [];
        $trade['reactions'] = $reactionsMap[(int)$trade['id']] ?? [];

        try {
            $ovrCol = playerOvrColumn($pdo);
            $stmtOfferPlayers = $pdo->prepare(
                "SELECT 
                          COALESCE(p.id, ti.player_id) AS id,
                          COALESCE(p.name, ti.player_name, CONCAT('Jogador #', ti.player_id)) AS name,
                    COALESCE(p.position, ti.player_position) AS position,
                    COALESCE(p.age, ti.player_age) AS age,
                    COALESCE(p.{$ovrCol}, ti.player_ovr) AS ovr
              FROM trade_items ti
              LEFT JOIN players p ON p.id = ti.player_id
                      WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.pick_id IS NULL"
            );
            $stmtOfferPlayers->execute([$trade['id']]);
            $trade['offer_players'] = $stmtOfferPlayers->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Erro ao buscar jogadores oferecidos da trade #' . $trade['id'] . ': ' . $e->getMessage());
        }

        try {
            $stmtOfferPicks = $pdo->prepare('
                SELECT pk.*, 
                       t.city as original_team_city, t.name as original_team_name,
                       lo.city as last_owner_city, lo.name as last_owner_name,
                       ti.pick_protection
                FROM picks pk
                JOIN trade_items ti ON pk.id = ti.pick_id
                JOIN teams t ON pk.original_team_id = t.id
                LEFT JOIN teams lo ON pk.last_owner_team_id = lo.id
                WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.pick_id IS NOT NULL
            ');
            $stmtOfferPicks->execute([$trade['id']]);
            $trade['offer_picks'] = $stmtOfferPicks->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Erro ao buscar picks oferecidas da trade #' . $trade['id'] . ': ' . $e->getMessage());
        }

        try {
            $ovrCol = playerOvrColumn($pdo);
            $stmtRequestPlayers = $pdo->prepare(
                "SELECT 
                          COALESCE(p.id, ti.player_id) AS id,
                          COALESCE(p.name, ti.player_name, CONCAT('Jogador #', ti.player_id)) AS name,
                    COALESCE(p.position, ti.player_position) AS position,
                    COALESCE(p.age, ti.player_age) AS age,
                    COALESCE(p.{$ovrCol}, ti.player_ovr) AS ovr
              FROM trade_items ti
              LEFT JOIN players p ON p.id = ti.player_id
                      WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.pick_id IS NULL"
            );
            $stmtRequestPlayers->execute([$trade['id']]);
            $trade['request_players'] = $stmtRequestPlayers->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Erro ao buscar jogadores pedidos da trade #' . $trade['id'] . ': ' . $e->getMessage());
        }

        try {
            $stmtRequestPicks = $pdo->prepare('
                SELECT pk.*, 
                       t.city as original_team_city, t.name as original_team_name,
                       lo.city as last_owner_city, lo.name as last_owner_name,
                       ti.pick_protection
                FROM picks pk
                JOIN trade_items ti ON pk.id = ti.pick_id
                JOIN teams t ON pk.original_team_id = t.id
                LEFT JOIN teams lo ON pk.last_owner_team_id = lo.id
                WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.pick_id IS NOT NULL
            ');
            $stmtRequestPicks->execute([$trade['id']]);
            $trade['request_picks'] = $stmtRequestPicks->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Erro ao buscar picks pedidas da trade #' . $trade['id'] . ': ' . $e->getMessage());
        }
    }
    
    echo json_encode(['success' => true, 'trades' => $trades]);
    exit;
}

// GET - Listar trades multiplas
if ($method === 'GET' && ($_GET['action'] ?? '') === 'multi_trades') {
    $type = $_GET['type'] ?? 'received';

    $conditions = [];
    $params = [];
    if ($type === 'received') {
        $conditions[] = 'mt.status = "pending"';
        $conditions[] = 'mtt.team_id = ?';
        $params[] = $teamId;
    } elseif ($type === 'sent') {
        $conditions[] = 'mt.status = "pending"';
        $conditions[] = 'mt.created_by_team_id = ?';
        $params[] = $teamId;
    } elseif ($type === 'league') {
        $conditions[] = '(mt.league = ?)';
        $conditions[] = 'mt.status = "accepted"';
        $params[] = $user['league'];
    } else {
        $conditions[] = 'mtt.team_id = ?';
        $conditions[] = 'mt.status IN ("accepted","cancelled")';
        $params[] = $teamId;
    }

    $whereClause = implode(' AND ', $conditions);
    $query = "
        SELECT mt.*, 
               (SELECT COUNT(*) FROM multi_trade_teams WHERE trade_id = mt.id) AS teams_total,
               (SELECT COUNT(*) FROM multi_trade_teams WHERE trade_id = mt.id AND accepted_at IS NOT NULL) AS teams_accepted
        FROM multi_trades mt
        JOIN multi_trade_teams mtt ON mtt.trade_id = mt.id
        WHERE {$whereClause}
        GROUP BY mt.id
        ORDER BY mt.created_at DESC
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tradeIds = array_map('intval', array_column($trades, 'id'));
    $reactionsMap = buildTradeReactionsMap($pdo, $tradeIds, (int)$user['id'], 'multi');

    foreach ($trades as &$trade) {
        $trade['is_multi'] = true;
        $trade['reactions'] = $reactionsMap[(int)$trade['id']] ?? [];
        $tradeId = (int)$trade['id'];
        $stmtMy = $pdo->prepare('SELECT accepted_at FROM multi_trade_teams WHERE trade_id = ? AND team_id = ?');
        $stmtMy->execute([$tradeId, $teamId]);
        $trade['my_accepted'] = (bool)$stmtMy->fetchColumn();
        $stmtTeams = $pdo->prepare('SELECT t.id, t.city, t.name FROM multi_trade_teams mtt JOIN teams t ON t.id = mtt.team_id WHERE mtt.trade_id = ?');
        $stmtTeams->execute([$tradeId]);
        $trade['teams'] = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

        $stmtItems = $pdo->prepare('SELECT * FROM multi_trade_items WHERE trade_id = ?');
        $stmtItems->execute([$tradeId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $ovrCol = playerOvrColumn($pdo);
        $stmtPickInfo = $pdo->prepare('SELECT pk.*, t.city as original_team_city, t.name as original_team_name, lo.city as last_owner_city, lo.name as last_owner_name FROM picks pk JOIN teams t ON pk.original_team_id = t.id LEFT JOIN teams lo ON pk.last_owner_team_id = lo.id WHERE pk.id = ?');
        foreach ($items as &$item) {
            if ($item['player_id'] && (!$item['player_name'] || !$item['player_ovr'])) {
                $stmtP = $pdo->prepare("SELECT name, position, age, {$ovrCol} AS ovr FROM players WHERE id = ?");
                $stmtP->execute([(int)$item['player_id']]);
                $p = $stmtP->fetch(PDO::FETCH_ASSOC) ?: [];
                $item['player_name'] = $item['player_name'] ?: ($p['name'] ?? null);
                $item['player_position'] = $item['player_position'] ?: ($p['position'] ?? null);
                $item['player_age'] = $item['player_age'] ?: ($p['age'] ?? null);
                $item['player_ovr'] = $item['player_ovr'] ?: ($p['ovr'] ?? null);
            }
            if ($item['pick_id']) {
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

    echo json_encode(['success' => true, 'trades' => $trades]);
    exit;
}

// POST - Reacoes nas trades gerais
if ($method === 'POST' && ($_GET['action'] ?? '') === 'trade_reaction') {
    $data = json_decode(file_get_contents('php://input'), true);
    $tradeId = (int)($data['trade_id'] ?? 0);
    $tradeType = $data['trade_type'] ?? 'single';
    $action = $data['action'] ?? 'set';
    $emoji = trim((string)($data['emoji'] ?? ''));

    if ($tradeId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'trade_id obrigatorio']);
        exit;
    }
    if (!in_array($tradeType, ['single', 'multi'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tipo de trade invalido']);
        exit;
    }
    if ($action !== 'remove' && $emoji === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Emoji obrigatorio']);
        exit;
    }

    ensureTradeReactionsTable($pdo);
    ensureTradeReactionsEmojiCollation($pdo);

    if (strlen($emoji) > 16) {
        $emoji = substr($emoji, 0, 16);
    }

    if ($tradeType === 'single') {
        $stmtTrade = $pdo->prepare('SELECT t.status, COALESCE(t.league, tf.league, tt.league) AS league FROM trades t JOIN teams tf ON t.from_team_id = tf.id JOIN teams tt ON t.to_team_id = tt.id WHERE t.id = ?');
        $stmtTrade->execute([$tradeId]);
    } else {
        $stmtTrade = $pdo->prepare('SELECT mt.status, COALESCE(mt.league, creator.league) AS league FROM multi_trades mt JOIN teams creator ON mt.created_by_team_id = creator.id WHERE mt.id = ?');
        $stmtTrade->execute([$tradeId]);
    }
    $tradeRow = $stmtTrade->fetch(PDO::FETCH_ASSOC);
    if (!$tradeRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Trade nao encontrada']);
        exit;
    }
    if (($tradeRow['status'] ?? '') !== 'accepted') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Reacoes so em trades aceitas']);
        exit;
    }
    if (!empty($tradeRow['league']) && strtoupper((string)$tradeRow['league']) !== strtoupper((string)$user['league'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sem permissao para reagir']);
        exit;
    }

    try {
        if ($action === 'remove') {
            $stmtDel = $pdo->prepare('DELETE FROM trade_reactions WHERE trade_id = ? AND trade_type = ? AND user_id = ?');
            $stmtDel->execute([$tradeId, $tradeType, $user['id']]);
        } else {
            $stmtIns = $pdo->prepare('INSERT INTO trade_reactions (trade_id, trade_type, user_id, emoji, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), updated_at = NOW()');
            $stmtIns->execute([$tradeId, $tradeType, $user['id'], $emoji]);
        }

        $stmtAgg = $pdo->prepare('SELECT emoji, COUNT(*) AS total FROM trade_reactions WHERE trade_id = ? AND trade_type = ? GROUP BY emoji');
        $stmtAgg->execute([$tradeId, $tradeType]);
        $list = [];
        while ($row = $stmtAgg->fetch(PDO::FETCH_ASSOC)) {
            $list[] = ['emoji' => $row['emoji'], 'count' => (int)$row['total']];
        }

        $stmtMine = $pdo->prepare('SELECT emoji FROM trade_reactions WHERE trade_id = ? AND trade_type = ? AND user_id = ? LIMIT 1');
        $stmtMine->execute([$tradeId, $tradeType, $user['id']]);
        $mineEmoji = $stmtMine->fetchColumn();
        if ($mineEmoji) {
            $found = false;
            foreach ($list as &$it) {
                if ($it['emoji'] === $mineEmoji) {
                    $it['mine'] = true;
                    $found = true;
                    break;
                }
            }
            unset($it);
            if (!$found) {
                $list[] = ['emoji' => $mineEmoji, 'count' => 0, 'mine' => true];
            }
        }

        echo json_encode([
            'success' => true,
            'trade_id' => $tradeId,
            'trade_type' => $tradeType,
            'reactions' => $list
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao salvar reacao']);
        exit;
    }
}

// POST - Criar trade
if ($method === 'POST' && ($_GET['action'] ?? '') === 'multi_trades') {
    $data = json_decode(file_get_contents('php://input'), true);
    $teams = $data['teams'] ?? [];
    $items = $data['items'] ?? [];
    $notes = trim($data['notes'] ?? '');

    if (!is_array($teams) || count($teams) < 2) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Informe pelo menos 2 times.']);
        exit;
    }

    $teams = array_values(array_unique(array_map('intval', $teams)));
    if (!in_array((int)$teamId, $teams, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Seu time deve participar da troca.']);
        exit;
    }
    if (count($teams) > 7) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Máximo de 7 times por troca.']);
        exit;
    }
    if (!is_array($items) || count($items) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Adicione pelo menos um item na troca.']);
        exit;
    }

    $stmtLeague = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $stmtLeague->execute([$teamId]);
    $league = $stmtLeague->fetchColumn();
    if (!$league) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Liga inválida.']);
        exit;
    }
    try {
        $placeholders = implode(',', array_fill(0, count($teams), '?'));
        $stmtTeamsLeague = $pdo->prepare("SELECT id FROM teams WHERE id IN ({$placeholders}) AND league = ?");
        $stmtTeamsLeague->execute([...$teams, $league]);
        $validTeams = $stmtTeamsLeague->fetchAll(PDO::FETCH_COLUMN);
        if (count($validTeams) !== count($teams)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Todos os times precisam estar na mesma liga.']);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao validar liga dos times.']);
        exit;
    }

    foreach ($teams as $tid) {
        if (isTeamTradeBanned($pdo, (int)$tid)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Um dos times está com trades bloqueadas na temporada.']);
            exit;
        }
    }

    try {
        $sendCounts = array_fill_keys($teams, 0);
        $receiveCounts = array_fill_keys($teams, 0);
        $validatedItems = [];

        foreach ($items as $item) {
            $fromTeam = (int)($item['from_team_id'] ?? 0);
            $toTeam = (int)($item['to_team_id'] ?? 0);
            $playerId = isset($item['player_id']) ? (int)$item['player_id'] : null;
            $pickId = isset($item['pick_id']) ? (int)$item['pick_id'] : null;

            if (!$fromTeam || !$toTeam || (!$playerId && !$pickId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Todos os itens precisam ter origem, destino e jogador/pick.']);
                exit;
            }
            if ($fromTeam === $toTeam) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Time de origem e destino não podem ser o mesmo.']);
                exit;
            }
            if (!in_array($fromTeam, $teams, true) || !in_array($toTeam, $teams, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Todos os itens devem usar times participantes.']);
                exit;
            }

            $sendCounts[$fromTeam]++;
            $receiveCounts[$toTeam]++;

            $validatedItems[] = [
                'from_team_id' => $fromTeam,
                'to_team_id' => $toTeam,
                'player_id' => $playerId,
                'pick_id' => $pickId
            ];
        }

        foreach ($teams as $tid) {
            if (($sendCounts[$tid] ?? 0) === 0 || ($receiveCounts[$tid] ?? 0) === 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Todos os times devem enviar e receber pelo menos um item.']);
                exit;
            }
        }

        $pdo->beginTransaction();

        $stmtTrade = $pdo->prepare('INSERT INTO multi_trades (league, created_by_team_id, notes) VALUES (?, ?, ?)');
        $stmtTrade->execute([$league, $teamId, $notes]);
        $tradeId = (int)$pdo->lastInsertId();

        $stmtTeam = $pdo->prepare('INSERT INTO multi_trade_teams (trade_id, team_id) VALUES (?, ?)');
        foreach ($teams as $tid) {
            $stmtTeam->execute([$tradeId, $tid]);
        }

        $hasPickProtectionCol = columnExists($pdo, 'multi_trade_items', 'pick_protection');
        $ovrCol = playerOvrColumn($pdo);
        $stmtItem = $pdo->prepare('INSERT INTO multi_trade_items (trade_id, from_team_id, to_team_id, player_id, pick_id, pick_protection, player_name, player_position, player_age, player_ovr) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($validatedItems as $item) {
            $fromTeam = (int)$item['from_team_id'];
            $toTeam = (int)$item['to_team_id'];
            $playerId = $item['player_id'] ? (int)$item['player_id'] : null;
            $pickId = $item['pick_id'] ? (int)$item['pick_id'] : null;

            if ($playerId) {
                $stmtOwner = $pdo->prepare('SELECT team_id FROM players WHERE id = ?');
                $stmtOwner->execute([$playerId]);
                $ownerId = (int)($stmtOwner->fetchColumn() ?: 0);
                if ($ownerId !== $fromTeam) {
                    throw new Exception('Jogador não pertence ao time informado.');
                }
            }
            if ($pickId) {
                if (isTeamPickTradeBanned($pdo, (int)$fromTeam)) {
                    throw new Exception('Um dos times está bloqueado de usar picks em trades.');
                }
                $stmtOwner = $pdo->prepare('SELECT team_id FROM picks WHERE id = ?');
                $stmtOwner->execute([$pickId]);
                $ownerId = (int)($stmtOwner->fetchColumn() ?: 0);
                if ($ownerId !== $fromTeam) {
                    throw new Exception('Pick não pertence ao time informado.');
                }
            }

            $playerName = null;
            $playerPosition = null;
            $playerAge = null;
            $playerOvr = null;
            if ($playerId) {
                $stmtP = $pdo->prepare("SELECT name, position, age, {$ovrCol} AS ovr FROM players WHERE id = ?");
                $stmtP->execute([$playerId]);
                $p = $stmtP->fetch(PDO::FETCH_ASSOC) ?: [];
                $playerName = $p['name'] ?? null;
                $playerPosition = $p['position'] ?? null;
                $playerAge = $p['age'] ?? null;
                $playerOvr = $p['ovr'] ?? null;
            }
            $stmtItem->execute([
                $tradeId,
                $fromTeam,
                $toTeam,
                $playerId,
                $pickId,
                $hasPickProtectionCol ? null : null,
                $playerName,
                $playerPosition,
                $playerAge,
                $playerOvr
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'trade_id' => $tradeId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao criar troca múltipla: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $toTeamId = $data['to_team_id'] ?? null;
    $offerPlayers = $data['offer_players'] ?? [];
    $offerPicks = normalizePickPayloadList($data['offer_picks'] ?? []);
    $requestPlayers = $data['request_players'] ?? [];
    $requestPicks = normalizePickPayloadList($data['request_picks'] ?? []);
    $notes = $data['notes'] ?? '';
    $counterTradeId = isset($data['counter_to_trade_id']) ? (int)$data['counter_to_trade_id'] : null;
    $counterTrade = null;
    
    if (!$toTeamId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Time destino não informado']);
        exit;
    }

    if (isTeamTradeBanned($pdo, (int)$teamId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Seu time está com trades bloqueadas nesta temporada.']);
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

    if (!empty($offerPicks) && isTeamPickTradeBanned($pdo, (int)$teamId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Seu time está bloqueado de usar picks em trades.']);
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
    
    // Validar liga do time alvo
    $stmtTargetTeam = $pdo->prepare('SELECT id, league FROM teams WHERE id = ?');
    $stmtTargetTeam->execute([$toTeamId]);
    $targetTeamData = $stmtTargetTeam->fetch(PDO::FETCH_ASSOC);
    if (!$targetTeamData) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Time alvo não encontrado']);
        exit;
    }

    if (isTeamTradeBanned($pdo, (int)$targetTeamData['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'O time alvo está com trades bloqueadas nesta temporada.']);
        exit;
    }

    if (!empty($requestPicks) && isTeamPickTradeBanned($pdo, (int)$targetTeamData['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'O time alvo está bloqueado de usar picks em trades.']);
        exit;
    }
    if ($targetTeamData['league'] !== $teamData['league']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Só é possível propor trades entre times da mesma liga']);
        exit;
    }

    $maxTrades = getLeagueMaxTrades($pdo, $teamData['league'], 10);

    $tradesUsed = getTeamTradesUsed($pdo, (int)$teamId);
    if ($tradesUsed >= $maxTrades) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Limite de {$maxTrades} trades aceitas por temporada atingido"]);
        exit;
    }

    // Validar posse das picks oferecidas
    if (!empty($offerPicks)) {
        $stmtPickOwner = $pdo->prepare('SELECT 1 FROM picks WHERE id = ? AND team_id = ?');
        foreach ($offerPicks as $pickEntry) {
            $pickId = (int)($pickEntry['id'] ?? 0);
            if ($pickId <= 0) {
                continue;
            }
            $stmtPickOwner->execute([$pickId, $teamId]);
            if (!$stmtPickOwner->fetchColumn()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Você só pode oferecer picks que pertencem ao seu time']);
                exit;
            }
        }
    }

    // Validar posse das picks solicitadas
    if (!empty($requestPicks)) {
        $stmtPickOwner = $pdo->prepare('SELECT 1 FROM picks WHERE id = ? AND team_id = ?');
        foreach ($requestPicks as $pickEntry) {
            $pickId = (int)($pickEntry['id'] ?? 0);
            if ($pickId <= 0) {
                continue;
            }
            $stmtPickOwner->execute([$pickId, $toTeamId]);
            if (!$stmtPickOwner->fetchColumn()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Só é possível pedir picks que pertencem ao time alvo']);
                exit;
            }
        }
    }
    
    try {
    $pdo->beginTransaction();
        
        // Criar trade (sem coluna cycle - ela é opcional)
    // Definir ciclo atual do time proponente, quando disponível
    $cycle = null;
    try {
        $stmtCycle = $pdo->prepare('SELECT current_cycle FROM teams WHERE id = ?');
        $stmtCycle->execute([$teamId]);
        $cycleVal = $stmtCycle->fetchColumn();
        if ($cycleVal !== false && $cycleVal !== null) {
            $cycle = (int)$cycleVal;
        }
    } catch (Exception $e) {}

    // Inserir com coluna cycle se existir
    $hasCycleCol = false;
    try {
        $hasCycleCol = (bool)$pdo->query("SHOW COLUMNS FROM trades LIKE 'cycle'")->fetch();
    } catch (Exception $e) {}

    if ($hasCycleCol) {
        $stmtTrade = $pdo->prepare('INSERT INTO trades (from_team_id, to_team_id, league, cycle, notes) VALUES (?, ?, ?, ?, ?)');
        $stmtTrade->execute([$teamId, $toTeamId, $teamData['league'], $cycle, $notes]);
    } else {
        $stmtTrade = $pdo->prepare('INSERT INTO trades (from_team_id, to_team_id, league, notes) VALUES (?, ?, ?, ?)');
        $stmtTrade->execute([$teamId, $toTeamId, $teamData['league'], $notes]);
    }
        $tradeId = $pdo->lastInsertId();
        
        // Adicionar itens oferecidos
        // Preparar inserção de itens com snapshot de jogador e proteção de pick, quando disponível
        $hasSnapshot = columnExists($pdo, 'trade_items', 'player_name');
        $hasPickProtectionCol = columnExists($pdo, 'trade_items', 'pick_protection');
        $ovrCol = playerOvrColumn($pdo);

        $columns = ['trade_id', 'player_id', 'pick_id', 'from_team'];
        $placeholders = ['?', '?', '?', '?'];
        if ($hasSnapshot) {
            $columns = array_merge($columns, ['player_name', 'player_position', 'player_age', 'player_ovr']);
            $placeholders = array_merge($placeholders, ['?', '?', '?', '?']);
        }
        if ($hasPickProtectionCol) {
            $columns[] = 'pick_protection';
            $placeholders[] = '?';
        }

        $sqlItem = 'INSERT INTO trade_items (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmtItem = $pdo->prepare($sqlItem);
        
        foreach ($offerPlayers as $playerId) {
            $playerId = (int)$playerId;
            $params = [$tradeId, $playerId, null, true];
            if ($hasSnapshot) {
                $stmtP = $pdo->prepare("SELECT id, name, position, {$ovrCol} AS ovr, age FROM players WHERE id = ?");
                $stmtP->execute([$playerId]);
                $p = $stmtP->fetch(PDO::FETCH_ASSOC) ?: [];
                $params[] = $p['name'] ?? null;
                $params[] = $p['position'] ?? null;
                $params[] = isset($p['age']) ? (int)$p['age'] : null;
                $params[] = isset($p['ovr']) ? (int)$p['ovr'] : null;
            }
            if ($hasPickProtectionCol) {
                $params[] = null;
            }
            $stmtItem->execute($params);
        }
        
        foreach ($offerPicks as $pickEntry) {
            $pickId = (int)($pickEntry['id'] ?? 0);
            if ($pickId <= 0) {
                continue;
            }
            $params = [$tradeId, null, $pickId, true];
            if ($hasSnapshot) {
                $params[] = null;
                $params[] = null;
                $params[] = null;
                $params[] = null;
            }
            if ($hasPickProtectionCol) {
                $params[] = $pickEntry['protection'] ?? null;
            }
            $stmtItem->execute($params);
        }
        
        // Adicionar itens pedidos
        foreach ($requestPlayers as $playerId) {
            $playerId = (int)$playerId;
            $params = [$tradeId, $playerId, null, false];
            if ($hasSnapshot) {
                $stmtP = $pdo->prepare("SELECT id, name, position, {$ovrCol} AS ovr, age FROM players WHERE id = ?");
                $stmtP->execute([$playerId]);
                $p = $stmtP->fetch(PDO::FETCH_ASSOC) ?: [];
                $params[] = $p['name'] ?? null;
                $params[] = $p['position'] ?? null;
                $params[] = isset($p['age']) ? (int)$p['age'] : null;
                $params[] = isset($p['ovr']) ? (int)$p['ovr'] : null;
            }
            if ($hasPickProtectionCol) {
                $params[] = null;
            }
            $stmtItem->execute($params);
        }
        
        foreach ($requestPicks as $pickEntry) {
            $pickId = (int)($pickEntry['id'] ?? 0);
            if ($pickId <= 0) {
                continue;
            }
            $params = [$tradeId, null, $pickId, false];
            if ($hasSnapshot) {
                $params[] = null;
                $params[] = null;
                $params[] = null;
                $params[] = null;
            }
            if ($hasPickProtectionCol) {
                $params[] = $pickEntry['protection'] ?? null;
            }
            $stmtItem->execute($params);
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

// PUT - Responder trade múltipla
if ($method === 'PUT' && ($_GET['action'] ?? '') === 'multi_trades') {
    $data = json_decode(file_get_contents('php://input'), true);
    $tradeId = (int)($data['trade_id'] ?? 0);
    $action = $data['action'] ?? null; // accepted, rejected, cancelled

    if (!$tradeId || !$action) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }

    $stmtTrade = $pdo->prepare('SELECT * FROM multi_trades WHERE id = ?');
    $stmtTrade->execute([$tradeId]);
    $trade = $stmtTrade->fetch(PDO::FETCH_ASSOC);
    if (!$trade) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Troca múltipla não encontrada']);
        exit;
    }

    $stmtTeamCheck = $pdo->prepare('SELECT * FROM multi_trade_teams WHERE trade_id = ? AND team_id = ?');
    $stmtTeamCheck->execute([$tradeId, $teamId]);
    $teamEntry = $stmtTeamCheck->fetch(PDO::FETCH_ASSOC);
    if (!$teamEntry) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sem permissão para responder']);
        exit;
    }

    if ($action === 'cancelled' && (int)$trade['created_by_team_id'] !== (int)$teamId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Só quem criou pode cancelar']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'rejected' || $action === 'cancelled') {
            $stmtCancel = $pdo->prepare('UPDATE multi_trades SET status = ? WHERE id = ?');
            $stmtCancel->execute(['cancelled', $tradeId]);
            $pdo->commit();
            echo json_encode(['success' => true, 'status' => 'cancelled']);
            exit;
        }

        if ($action === 'accepted') {
            $stmtAccept = $pdo->prepare('UPDATE multi_trade_teams SET accepted_at = NOW() WHERE trade_id = ? AND team_id = ? AND accepted_at IS NULL');
            $stmtAccept->execute([$tradeId, $teamId]);

            $stmtCounts = $pdo->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN accepted_at IS NOT NULL THEN 1 ELSE 0 END) AS accepted FROM multi_trade_teams WHERE trade_id = ?');
            $stmtCounts->execute([$tradeId]);
            $counts = $stmtCounts->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'accepted' => 0];
            $totalTeams = (int)$counts['total'];
            $acceptedTeams = (int)$counts['accepted'];

            if ($totalTeams > 0 && $acceptedTeams === $totalTeams) {
                $stmtTeams = $pdo->prepare('SELECT team_id FROM multi_trade_teams WHERE trade_id = ?');
                $stmtTeams->execute([$tradeId]);
                $tradeTeams = array_map('intval', $stmtTeams->fetchAll(PDO::FETCH_COLUMN));

                $league = $trade['league'] ?: $user['league'];
                $maxTrades = getLeagueMaxTrades($pdo, $league, 3);
                foreach ($tradeTeams as $tid) {
                    if (getTeamTradesUsed($pdo, (int)$tid) >= $maxTrades) {
                        throw new Exception('Um dos times já atingiu o limite de trades.');
                    }
                }

                $stmtItems = $pdo->prepare('SELECT * FROM multi_trade_items WHERE trade_id = ?');
                $stmtItems->execute([$tradeId]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                $stmtUpdateMultiPick = $pdo->prepare('UPDATE multi_trade_items SET pick_id = ? WHERE id = ?');
                foreach ($items as &$item) {
                    if (!empty($item['pick_id'])) {
                        $normalizedId = normalizePickId($pdo, (int)$item['pick_id']);
                        if ($normalizedId !== (int)$item['pick_id']) {
                            $stmtUpdateMultiPick->execute([$normalizedId, $item['id']]);
                            $item['pick_id'] = $normalizedId;
                        }
                    }
                }
                unset($item);

                $stmtTransferPlayer = $pdo->prepare('UPDATE players SET team_id = ? WHERE id = ?');
                $stmtTransferPick = $pdo->prepare('UPDATE picks SET team_id = ?, last_owner_team_id = ?, auto_generated = 0 WHERE id = ?');
                $stmtPlayerOwner = $pdo->prepare('SELECT team_id FROM players WHERE id = ?');
                $stmtPickOwner = $pdo->prepare('SELECT team_id FROM picks WHERE id = ?');

                foreach ($items as $item) {
                    if ($item['player_id']) {
                        $stmtPlayerOwner->execute([(int)$item['player_id']]);
                        $currentOwner = (int)($stmtPlayerOwner->fetchColumn() ?: 0);
                        if ($currentOwner && $currentOwner !== (int)$item['from_team_id']) {
                            if ($currentOwner !== (int)$item['to_team_id']) {
                                throw new Exception('Jogador ID ' . $item['player_id'] . ' não pertence ao time de origem.');
                            }
                        } else {
                            $stmtTransferPlayer->execute([(int)$item['to_team_id'], (int)$item['player_id']]);
                        }
                    }
                    if ($item['pick_id']) {
                        $stmtPickOwner->execute([(int)$item['pick_id']]);
                        $currentOwner = (int)($stmtPickOwner->fetchColumn() ?: 0);
                        if ($currentOwner && $currentOwner !== (int)$item['from_team_id']) {
                            if ($currentOwner !== (int)$item['to_team_id']) {
                                throw new Exception('Pick ID ' . $item['pick_id'] . ' não pertence ao time de origem.');
                            }
                        } else {
                            $stmtTransferPick->execute([(int)$item['to_team_id'], (int)$item['from_team_id'], (int)$item['pick_id']]);
                        }
                    }
                }

                foreach ($tradeTeams as $tid) {
                    syncTeamTradeCounter($pdo, (int)$tid);
                }
                $stmtIncTrades = $pdo->prepare('UPDATE teams SET trades_used = trades_used + 1 WHERE id = ?');
                foreach ($tradeTeams as $tid) {
                    $stmtIncTrades->execute([(int)$tid]);
                }

                $pdo->prepare('UPDATE multi_trades SET status = ? WHERE id = ?')->execute(['accepted', $tradeId]);
                $pdo->commit();
                echo json_encode(['success' => true, 'status' => 'accepted']);
                exit;
            }

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'status' => 'pending',
                'teams_total' => $totalTeams,
                'teams_accepted' => $acceptedTeams
            ]);
            exit;
        }

        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage() ?: 'Erro ao processar troca múltipla']);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage() ?: 'Erro ao processar troca múltipla']);
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
    
    if ($action === 'accepted') {
        $tradeLeague = $trade['league'] ?? null;
        if (!$tradeLeague) {
            $tradeLeague = getTeamLeague($pdo, (int)$trade['from_team_id'])
                ?? getTeamLeague($pdo, (int)$trade['to_team_id'])
                ?? $user['league'];
        }
        $maxTrades = getLeagueMaxTrades($pdo, $tradeLeague ?: $user['league'], 3);
        $fromTradesUsed = getTeamTradesUsed($pdo, (int)$trade['from_team_id']);
        if ($fromTradesUsed >= $maxTrades) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'O time proponente já atingiu o limite de trades para esta temporada.']);
            exit;
        }
        $toTradesUsed = getTeamTradesUsed($pdo, (int)$trade['to_team_id']);
        if ($toTradesUsed >= $maxTrades) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Seu time já atingiu o limite de trades para esta temporada.']);
            exit;
        }
        // Preenche ciclo da trade, se existir a coluna, com o ciclo do time receptor no momento da aceitação
        try {
            $hasCycleCol = (bool)$pdo->query("SHOW COLUMNS FROM trades LIKE 'cycle'")->fetch();
            if ($hasCycleCol) {
                $stmtCycle = $pdo->prepare('SELECT current_cycle FROM teams WHERE id = ?');
                $stmtCycle->execute([$trade['to_team_id']]);
                $cycle = (int)($stmtCycle->fetchColumn() ?: 0);
                if ($cycle > 0) {
                    $pdo->prepare('UPDATE trades SET cycle = ? WHERE id = ?')->execute([$cycle, $tradeId]);
                }
            }
        } catch (Exception $e) {}
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

            $hasSnapshot = columnExists($pdo, 'trade_items', 'player_name');
            $ovrCol = playerOvrColumn($pdo);
            if ($hasSnapshot && !empty($items)) {
                $stmtSnapshot = $pdo->prepare(
                    "UPDATE trade_items
                     SET player_name = ?, player_position = ?, player_age = ?, player_ovr = ?
                     WHERE id = ?"
                );
                $stmtFetchPlayer = $pdo->prepare("SELECT name, position, age, {$ovrCol} AS ovr FROM players WHERE id = ?");

                foreach ($items as $item) {
                    if (empty($item['player_id'])) {
                        continue;
                    }
                    if (!empty($item['player_name'])) {
                        continue;
                    }
                    $stmtFetchPlayer->execute([(int)$item['player_id']]);
                    $p = $stmtFetchPlayer->fetch(PDO::FETCH_ASSOC) ?: [];
                    $stmtSnapshot->execute([
                        $p['name'] ?? null,
                        $p['position'] ?? null,
                        isset($p['age']) ? (int)$p['age'] : null,
                        isset($p['ovr']) ? (int)$p['ovr'] : null,
                        (int)$item['id']
                    ]);
                }
            }

            $stmtUpdateTradeItemPick = $pdo->prepare('UPDATE trade_items SET pick_id = ? WHERE id = ?');

            foreach ($items as &$item) {
                if (!empty($item['pick_id'])) {
                    $normalizedId = normalizePickId($pdo, (int)$item['pick_id']);
                    if ($normalizedId !== (int)$item['pick_id']) {
                        $stmtUpdateTradeItemPick->execute([$normalizedId, $item['id']]);
                        $item['pick_id'] = $normalizedId;
                    }
                }
            }
            unset($item);
            
            $stmtTransferPlayer = $pdo->prepare('UPDATE players SET team_id = ? WHERE id = ? AND team_id = ?');
            $stmtTransferPick = $pdo->prepare('UPDATE picks SET team_id = ?, last_owner_team_id = ?, auto_generated = 0 WHERE id = ? AND team_id = ?');
            
            foreach ($items as $item) {
                if ($item['player_id']) {
                    // Transferir jogador
                    $expectedOwner = $item['from_team'] ? $trade['from_team_id'] : $trade['to_team_id'];
                    $newTeamId = $item['from_team'] ? $trade['to_team_id'] : $trade['from_team_id'];
                    $stmtTransferPlayer->execute([$newTeamId, $item['player_id'], $expectedOwner]);
                    if ($stmtTransferPlayer->rowCount() === 0) {
                        throw new Exception('Jogador ID ' . $item['player_id'] . ' não está mais disponível para transferência');
                    }
                }
                
                if ($item['pick_id']) {
                    // Transferir pick
                    $expectedOwner = $item['from_team'] ? $trade['from_team_id'] : $trade['to_team_id'];
                    $newTeamId = $item['from_team'] ? $trade['to_team_id'] : $trade['from_team_id'];
                    $stmtTransferPick->execute([$newTeamId, $expectedOwner, $item['pick_id'], $expectedOwner]);
                    if ($stmtTransferPick->rowCount() === 0) {
                        throw new Exception('Pick ID ' . $item['pick_id'] . ' não está mais disponível para transferência');
                    }
                }
            }

            // Atualizar contador de trades por time
            syncTeamTradeCounter($pdo, (int)$trade['from_team_id']);
            syncTeamTradeCounter($pdo, (int)$trade['to_team_id']);
            $stmtIncTrades = $pdo->prepare('UPDATE teams SET trades_used = trades_used + 1 WHERE id = ?');
            $stmtIncTrades->execute([(int)$trade['from_team_id']]);
            if ((int)$trade['to_team_id'] !== (int)$trade['from_team_id']) {
                $stmtIncTrades->execute([(int)$trade['to_team_id']]);
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[trade_accept] ' . $e->getMessage());
        if (str_contains($e->getMessage(), 'uniq_pick')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Uma das picks já foi negociada e não pode ser transferida novamente. Atualize a proposta.']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage() ?: 'Erro ao processar trade']);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        error_log('[trade_accept] ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage() ?: 'Erro ao processar trade']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido']);
