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

ensureTradeCycleColumn($pdo);
ensureTeamTradeCounterColumns($pdo);

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
    
    // Para cada trade, buscar itens
    foreach ($trades as &$trade) {
        $trade['offer_players'] = [];
        $trade['offer_picks'] = [];
        $trade['request_players'] = [];
        $trade['request_picks'] = [];

        try {
            $ovrCol = playerOvrColumn($pdo);
            $stmtOfferPlayers = $pdo->prepare(
                "SELECT 
                    COALESCE(p.id, ti.player_id) AS id,
                    COALESCE(p.name, ti.player_name) AS name,
                    COALESCE(p.position, ti.player_position) AS position,
                    COALESCE(p.age, ti.player_age) AS age,
                    COALESCE(p.{$ovrCol}, ti.player_ovr) AS ovr
              FROM trade_items ti
              LEFT JOIN players p ON p.id = ti.player_id
              WHERE ti.trade_id = ? AND ti.from_team = TRUE AND (ti.player_id IS NOT NULL OR ti.player_name IS NOT NULL)"
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
                    COALESCE(p.name, ti.player_name) AS name,
                    COALESCE(p.position, ti.player_position) AS position,
                    COALESCE(p.age, ti.player_age) AS age,
                    COALESCE(p.{$ovrCol}, ti.player_ovr) AS ovr
              FROM trade_items ti
              LEFT JOIN players p ON p.id = ti.player_id
              WHERE ti.trade_id = ? AND ti.from_team = FALSE AND (ti.player_id IS NOT NULL OR ti.player_name IS NOT NULL)"
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

// POST - Criar trade
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
    
    // Validar liga do time alvo
    $stmtTargetTeam = $pdo->prepare('SELECT id, league FROM teams WHERE id = ?');
    $stmtTargetTeam->execute([$toTeamId]);
    $targetTeamData = $stmtTargetTeam->fetch(PDO::FETCH_ASSOC);
    if (!$targetTeamData) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Time alvo não encontrado']);
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
