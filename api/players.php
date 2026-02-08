<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';

$pdo = db();
$config = loadConfig();
$method = $_SERVER['REQUEST_METHOD'];
$MAX_WAIVERS = 3;

ensureTeamFreeAgencyColumns($pdo);

if (!function_exists('playersTableExists')) {
    function playersTableExists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        $cache[$table] = $stmt->rowCount() > 0;
        return $cache[$table];
    }
}

if (!function_exists('playersColumnExists')) {
    function playersColumnExists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . ':' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE ?');
        $stmt->execute([$column]);
        $cache[$key] = $stmt->rowCount() > 0;
        return $cache[$key];
    }
}

if (!function_exists('resolveSeasonYear')) {
    function resolveSeasonYear(PDO $pdo, string $league): ?int
    {
        try {
            $stmt = $pdo->prepare("SELECT year FROM seasons WHERE league = ? AND status <> 'completed' ORDER BY year DESC, id DESC LIMIT 1");
            $stmt->execute([$league]);
            $year = $stmt->fetchColumn();
            if ($year) {
                return (int)$year;
            }
            $stmt = $pdo->prepare('SELECT year FROM seasons WHERE league = ? ORDER BY year DESC, id DESC LIMIT 1');
            $stmt->execute([$league]);
            $year = $stmt->fetchColumn();
            return $year ? (int)$year : null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('resolveSeasonInfo')) {
    function resolveSeasonInfo(PDO $pdo, string $league): array
    {
        if (!playersTableExists($pdo, 'seasons')) {
            return ['id' => null, 'year' => null];
        }
        try {
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
        } catch (Exception $e) {
            return ['id' => null, 'year' => null];
        }
    }
}

if (!function_exists('syncWaiversSeasonCounter')) {
    function syncWaiversSeasonCounter(PDO $pdo, int $teamId, string $league): int
    {
        $seasonYear = resolveSeasonYear($pdo, $league);
        if (!$seasonYear) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare('SELECT waivers_used, waivers_reset_year FROM teams WHERE id = ?');
            $stmt->execute([$teamId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return 0;
            }
            if ((int)($row['waivers_reset_year'] ?? 0) !== (int)$seasonYear) {
                $stmtUpdate = $pdo->prepare('UPDATE teams SET waivers_used = 0, waivers_reset_year = ? WHERE id = ?');
                $stmtUpdate->execute([$seasonYear, $teamId]);
                return 0;
            }
            return (int)($row['waivers_used'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }
}

if ($method === 'GET') {
    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : null;
    
    // Query simples e direta
    $sql = "SELECT * FROM players";
    $params = [];
    
    if ($teamId) {
        $sql .= ' WHERE team_id = ?';
        $params[] = $teamId;
    }
    $sql .= ' ORDER BY ovr DESC, id DESC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'players' => $players, 'count' => count($players), 'team_id' => $teamId]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao buscar jogadores', 'details' => $e->getMessage()]);
        exit;
    }
}

if ($method === 'POST') {
    $body = readJsonBody();
    $teamId = (int) ($body['team_id'] ?? 0);
    $name = trim($body['name'] ?? '');
    $age = (int) ($body['age'] ?? 0);
    $position = trim($body['position'] ?? '');
    $role = $body['role'] ?? 'Titular';
    $ovr = (int) ($body['ovr'] ?? 0);
    $availableForTrade = isset($body['available_for_trade']) ? (int) ((bool) $body['available_for_trade']) : 0;

    if (!$teamId || $name === '' || !$age || $position === '' || !$ovr) {
        jsonResponse(422, ['error' => 'Campos obrigatórios: team_id, nome, idade, posição, ovr.']);
    }

    // Verificar propriedade do time pelo usuário logado
    $sessionUser = getUserSession();
    if (!isset($sessionUser['id'])) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }
    $teamExists = $pdo->prepare('SELECT id, user_id FROM teams WHERE id = ?');
    $teamExists->execute([$teamId]);
    $teamRow = $teamExists->fetch();
    if (!$teamRow) {
        jsonResponse(404, ['error' => 'Time não encontrado.']);
    }
    if ((int)$teamRow['user_id'] !== (int)$sessionUser['id']) {
        jsonResponse(403, ['error' => 'Sem permissão para alterar este time.']);
    }

    $stmtLeague = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $stmtLeague->execute([$teamId]);
    $teamLeague = strtoupper((string)$stmtLeague->fetchColumn());
    if ($teamLeague !== 'ELITE') {
        jsonResponse(403, ['error' => 'Adição de jogador disponível apenas para a liga ELITE.']);
    }

    // Validar limitadores de função
    $roleCountStmt = $pdo->prepare('SELECT role, COUNT(*) as count FROM players WHERE team_id = ? GROUP BY role');
    $roleCountStmt->execute([$teamId]);
    $roleCounts = [];
    while ($row = $roleCountStmt->fetch()) {
        $roleCounts[$row['role']] = (int)$row['count'];
    }
    
    $titularCount = $roleCounts['Titular'] ?? 0;
    $bancoCount = $roleCounts['Banco'] ?? 0;
    $gleagueCount = $roleCounts['G-League'] ?? 0;
    
    // Validar limites
    if ($role === 'Titular' && $titularCount >= 5) {
        jsonResponse(409, ['error' => 'Limite de Titulares atingido (máximo 5).']);
    }
    if ($role === 'G-League' && $gleagueCount >= 2) {
        jsonResponse(409, ['error' => 'Limite de G-League atingido (máximo 2).']);
    }
    
    // Validar elegibilidade para G-League
    if ($role === 'G-League' && $age >= 25) {
        jsonResponse(409, ['error' => 'Jogador não elegível para G-League: deve ter menos de 25 anos.']);
    }

    // Validar nome único por liga
    if ($teamLeague) {
        $stmtDuplicate = $pdo->prepare('
            SELECT p.id FROM players p 
            INNER JOIN teams t ON t.id = p.team_id 
            WHERE LOWER(p.name) = LOWER(?) AND t.league = ?
        ');
        $stmtDuplicate->execute([$name, $teamLeague]);
        if ($stmtDuplicate->fetch()) {
            jsonResponse(409, ['error' => "Já existe um jogador chamado '{$name}' na liga {$teamLeague}."]);
        }
    }

    $prospectiveCap = capWithCandidate($pdo, $teamId, $ovr);
    $warnings = [];
    if ($prospectiveCap > $config['app']['cap_max']) {
        $warnings[] = 'CAP acima do limite recomendado (' . $prospectiveCap . ' / ' . $config['app']['cap_max'] . ').';
    }

    $stmt = $pdo->prepare('INSERT INTO players (team_id, name, age, position, role, ovr, available_for_trade) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$teamId, $name, $age, $position, $role, $ovr, $availableForTrade]);

    $newCap = topEightCap($pdo, $teamId);
    if ($newCap < $config['app']['cap_min']) {
        $warnings[] = 'CAP abaixo do mínimo recomendado (' . $newCap . ' / ' . $config['app']['cap_min'] . ').';
    }

    jsonResponse(201, [
        'message' => 'Jogador adicionado.',
        'player_id' => $pdo->lastInsertId(),
        'cap_top8' => $newCap,
        'warning' => !empty($warnings) ? implode(' ', $warnings) : null,
    ]);
}

if ($method === 'PUT') {
    $body = readJsonBody();
    $playerId = (int) ($body['id'] ?? 0);
    if (!$playerId) jsonResponse(422, ['error' => 'ID do jogador é obrigatório.']);

    $sessionUser = getUserSession();
    if (!isset($sessionUser['id'])) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }

    $stmt = $pdo->prepare('SELECT p.*, t.user_id FROM players p INNER JOIN teams t ON t.id = p.team_id WHERE p.id = ?');
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();
    if (!$player) jsonResponse(404, ['error' => 'Jogador não encontrado.']);
    if ((int)$player['user_id'] !== (int)$sessionUser['id']) {
        jsonResponse(403, ['error' => 'Sem permissão para alterar este jogador.']);
    }

    $name = isset($body['name']) ? trim($body['name']) : $player['name'];
    $age = isset($body['age']) ? (int)$body['age'] : (int)$player['age'];
    $position = isset($body['position']) ? trim($body['position']) : $player['position'];
    $secondaryPosition = isset($body['secondary_position']) ? trim($body['secondary_position']) : ($player['secondary_position'] ?? '');
    $seasonsInLeague = isset($body['seasons_in_league']) ? (int)$body['seasons_in_league'] : (int)($player['seasons_in_league'] ?? 0);
    $role = isset($body['role']) ? $body['role'] : $player['role'];
    $ovr = isset($body['ovr']) ? (int)$body['ovr'] : (int)$player['ovr'];
    $availableForTrade = isset($body['available_for_trade']) ? (int)((bool)$body['available_for_trade']) : (int)$player['available_for_trade'];

    if ($name === '' || !$age || $position === '' || !$ovr) {
        jsonResponse(422, ['error' => 'Campos obrigatórios: nome, idade, posição, ovr.']);
    }

    // Validar limitadores de função se mudou o role
    if ($role !== $player['role']) {
        $roleCountStmt = $pdo->prepare('SELECT role, COUNT(*) as count FROM players WHERE team_id = ? AND id <> ? GROUP BY role');
        $roleCountStmt->execute([(int)$player['team_id'], $playerId]);
        $roleCounts = [];
        while ($row = $roleCountStmt->fetch()) {
            $roleCounts[$row['role']] = (int)$row['count'];
        }
        
        $titularCount = $roleCounts['Titular'] ?? 0;
        $bancoCount = $roleCounts['Banco'] ?? 0;
        $gleagueCount = $roleCounts['G-League'] ?? 0;
        
        if ($role === 'Titular' && $titularCount >= 5) {
            jsonResponse(409, ['error' => 'Limite de Titulares atingido (máximo 5).']);
        }
        if ($role === 'G-League' && $gleagueCount >= 2) {
            jsonResponse(409, ['error' => 'Limite de G-League atingido (máximo 2).']);
        }
        
        // Validar elegibilidade para G-League
        if ($role === 'G-League' && $age >= 25) {
            jsonResponse(409, ['error' => 'Jogador não elegível para G-League: deve ter menos de 25 anos.']);
        }
    }

    // CAP check: recalcular considerando o novo OVR substituindo o anterior
    $ovrsStmt = $pdo->prepare('SELECT ovr FROM players WHERE team_id = ? AND id <> ? ORDER BY ovr DESC LIMIT 8');
    $ovrsStmt->execute([(int)$player['team_id'], $playerId]);
    $ovrs = $ovrsStmt->fetchAll(PDO::FETCH_COLUMN);
    $ovrs[] = $ovr;
    rsort($ovrs, SORT_NUMERIC);
    $capAfter = array_sum(array_slice($ovrs, 0, 8));
    $warnings = [];
    if ($capAfter > $config['app']['cap_max']) {
        $warnings[] = 'CAP acima do limite recomendado (' . $capAfter . ' / ' . $config['app']['cap_max'] . ').';
    }

    // Verificar se as colunas extras existem
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM players LIKE 'secondary_position'");
        $hasSecondaryPosition = $checkCol->rowCount() > 0;
        
        $checkCol2 = $pdo->query("SHOW COLUMNS FROM players LIKE 'seasons_in_league'");
        $hasSeasonsInLeague = $checkCol2->rowCount() > 0;
    } catch (Exception $e) {
        $hasSecondaryPosition = false;
        $hasSeasonsInLeague = false;
    }

    // Construir UPDATE dinamicamente
    if ($hasSecondaryPosition && $hasSeasonsInLeague) {
        $upd = $pdo->prepare('UPDATE players SET name = ?, age = ?, position = ?, secondary_position = ?, seasons_in_league = ?, role = ?, ovr = ?, available_for_trade = ? WHERE id = ?');
        $upd->execute([$name, $age, $position, $secondaryPosition ?: null, $seasonsInLeague, $role, $ovr, $availableForTrade, $playerId]);
    } elseif ($hasSecondaryPosition) {
        $upd = $pdo->prepare('UPDATE players SET name = ?, age = ?, position = ?, secondary_position = ?, role = ?, ovr = ?, available_for_trade = ? WHERE id = ?');
        $upd->execute([$name, $age, $position, $secondaryPosition ?: null, $role, $ovr, $availableForTrade, $playerId]);
    } else {
        $upd = $pdo->prepare('UPDATE players SET name = ?, age = ?, position = ?, role = ?, ovr = ?, available_for_trade = ? WHERE id = ?');
        $upd->execute([$name, $age, $position, $role, $ovr, $availableForTrade, $playerId]);
    }

    $newCap = topEightCap($pdo, (int)$player['team_id']);
    if ($newCap < $config['app']['cap_min']) {
        $warnings[] = 'CAP abaixo do mínimo recomendado (' . $newCap . ' / ' . $config['app']['cap_min'] . ').';
    }
    jsonResponse(200, [
        'message' => 'Jogador atualizado.',
        'cap_top8' => $newCap,
        'warning' => !empty($warnings) ? implode(' ', $warnings) : null,
    ]);
}

if ($method === 'DELETE') {
    $body = readJsonBody();
    $playerId = (int) ($body['id'] ?? 0);
    $isRetirement = (bool) ($body['retirement'] ?? false);
    
    if (!$playerId) jsonResponse(422, ['error' => 'ID do jogador é obrigatório.']);

    $sessionUser = getUserSession();
    if (!isset($sessionUser['id'])) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }

    $stmt = $pdo->prepare('
        SELECT 
            p.*, 
            t.user_id, t.city, t.name AS team_name, t.league,
            COALESCE(t.waivers_used, 0) AS waivers_used
        FROM players p 
        INNER JOIN teams t ON t.id = p.team_id 
        WHERE p.id = ?
    ');
    $stmt->execute([$playerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonResponse(404, ['error' => 'Jogador não encontrado.']);
    if ((int)$row['user_id'] !== (int)$sessionUser['id']) {
        jsonResponse(403, ['error' => 'Sem permissão para remover este jogador.']);
    }

    // Se for aposentadoria, verificar idade mínima (maior que 35)
    if ($isRetirement) {
        if ((int)$row['age'] <= 35) {
            jsonResponse(400, ['error' => 'Apenas jogadores com mais de 35 anos podem se aposentar.']);
        }

        try {
            $pdo->beginTransaction();

            // Aposentadoria: remove o jogador e limpa possíveis registros em free_agents
            $del = $pdo->prepare('DELETE FROM players WHERE id = ?');
            $del->execute([$playerId]);

            if (playersTableExists($pdo, 'free_agents')) {
                try {
                    if (playersColumnExists($pdo, 'free_agents', 'original_team_id')) {
                        $cleanup = $pdo->prepare('DELETE FROM free_agents WHERE original_team_id = ? AND name = ?');
                        $cleanup->execute([$row['team_id'], $row['name']]);
                    } else {
                        $cleanup = $pdo->prepare('DELETE FROM free_agents WHERE name = ?');
                        $cleanup->execute([$row['name']]);
                    }
                } catch (Exception $cleanupErr) {
                    error_log('[players-retirement] cleanup free_agents failed: ' . $cleanupErr->getMessage());
                }
            }

            $pdo->commit();

            $newCap = topEightCap($pdo, (int)$row['team_id']);

            jsonResponse(200, [
                'message' => $row['name'] . ' se aposentou após uma grande carreira!',
                'cap_top8' => $newCap,
                'retirement' => true
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(500, ['error' => 'Erro ao aposentar jogador: ' . $e->getMessage()]);
        }
    }

    // Dispensa normal - verifica limite de waivers
    $leagueForReset = strtoupper($row['league'] ?? 'ELITE');
    $row['waivers_used'] = syncWaiversSeasonCounter($pdo, (int)$row['team_id'], $leagueForReset);
    if ($row['waivers_used'] >= $MAX_WAIVERS) {
        jsonResponse(400, ['error' => 'Limite de dispensas por temporada atingido.']);
    }

    try {
        $pdo->beginTransaction();

        $league = strtoupper($row['league'] ?? 'ELITE');
        $validLeagues = ['ELITE','NEXT','RISE','ROOKIE'];
        if (!in_array($league, $validLeagues, true)) {
            $league = 'ELITE';
        }

        $seasonInfo = resolveSeasonInfo($pdo, $league);
        $hasSeasonIdCol = playersColumnExists($pdo, 'free_agents', 'season_id');
        $hasSeasonYearCol = playersColumnExists($pdo, 'free_agents', 'season_year');

        $columns = ['name', 'age', 'position', 'secondary_position', 'overall', 'league', 'original_team_id', 'original_team_name'];
        $values = [
            $row['name'],
            $row['age'],
            $row['position'],
            $row['secondary_position'] ?? null,
            $row['ovr'],
            $league,
            $row['team_id'],
            trim(($row['city'] ?? '') . ' ' . ($row['team_name'] ?? '')) ?: null
        ];

        if ($hasSeasonIdCol) {
            $columns[] = 'season_id';
            $values[] = $seasonInfo['id'];
        }
        if ($hasSeasonYearCol) {
            $columns[] = 'season_year';
            $values[] = $seasonInfo['year'];
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmtFA = $pdo->prepare('INSERT INTO free_agents (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
        $stmtFA->execute($values);

        $del = $pdo->prepare('DELETE FROM players WHERE id = ?');
        $del->execute([$playerId]);

        $stmtUpd = $pdo->prepare('UPDATE teams SET waivers_used = COALESCE(waivers_used, 0) + 1 WHERE id = ?');
        $stmtUpd->execute([$row['team_id']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => 'Erro ao dispensar jogador: ' . $e->getMessage()]);
    }

    $newCap = topEightCap($pdo, (int)$row['team_id']);
    $waiversRemaining = max(0, $MAX_WAIVERS - ($row['waivers_used'] + 1));

    jsonResponse(200, [
        'message' => 'Jogador dispensado e enviado para a Free Agency.',
        'cap_top8' => $newCap,
        'waivers_remaining' => $waiversRemaining
    ]);
}

jsonResponse(405, ['error' => 'Method not allowed']);
