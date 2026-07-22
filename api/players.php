<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';

$pdo = db();
$config = loadConfig();
$method = $_SERVER['REQUEST_METHOD'];
$MAX_WAIVERS = 3;

ensureTeamFreeAgencyColumns($pdo);
ensurePlayerRestrictionColumns($pdo);

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

if (!function_exists('ensurePlayerSkillGradesColumn')) {
    function ensurePlayerSkillGradesColumn(PDO $pdo): void
    {
        try {
            if (!playersColumnExists($pdo, 'players', 'player_skill_grades')) {
                $pdo->exec("ALTER TABLE players ADD COLUMN player_skill_grades TEXT NULL");
            }
        } catch (Exception $e) {
            // ignore migration errors
        }
    }
}

if (!function_exists('ensureSkillGradeColumns')) {
    function ensureSkillGradeColumns(PDO $pdo): array
    {
        $columns = [
            'skill_in',
            'skill_mid',
            'skill_3pt',
            'skill_post_d',
            'skill_per_d',
            'skill_play',
            'skill_reb',
            'skill_athl',
            'skill_iq',
            'skill_pot',
        ];
        foreach ($columns as $col) {
            try {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM players LIKE ?");
                $stmt->execute([$col]);
                if ($stmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE players ADD COLUMN {$col} VARCHAR(3) NULL");
                }
            } catch (Exception $e) {
                // ignore migration errors
            }
        }
        return $columns;
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

if (!function_exists('ensureTradeItemSnapshotColumns')) {
    function ensureTradeItemSnapshotColumns(PDO $pdo): void
    {
        if (!playersTableExists($pdo, 'trade_items')) {
            return;
        }
        try {
            if (!playersColumnExists($pdo, 'trade_items', 'player_name')) {
                $pdo->exec("ALTER TABLE trade_items ADD COLUMN player_name VARCHAR(255) NULL AFTER player_id");
            }
            if (!playersColumnExists($pdo, 'trade_items', 'player_position')) {
                $pdo->exec("ALTER TABLE trade_items ADD COLUMN player_position VARCHAR(10) NULL AFTER player_name");
            }
            if (!playersColumnExists($pdo, 'trade_items', 'player_age')) {
                $pdo->exec("ALTER TABLE trade_items ADD COLUMN player_age INT NULL AFTER player_position");
            }
            if (!playersColumnExists($pdo, 'trade_items', 'player_ovr')) {
                $pdo->exec("ALTER TABLE trade_items ADD COLUMN player_ovr INT NULL AFTER player_age");
            }
        } catch (Exception $e) {
            // ignore migration errors
        }
    }
}

if (!function_exists('snapshotTradeItemsForPlayer')) {
    function snapshotTradeItemsForPlayer(PDO $pdo, array $playerRow): void
    {
        if (!playersTableExists($pdo, 'trade_items')) {
            return;
        }
        ensureTradeItemSnapshotColumns($pdo);

        if (!playersColumnExists($pdo, 'trade_items', 'player_id')) {
            return;
        }

        $name = $playerRow['name'] ?? null;
        $position = $playerRow['position'] ?? null;
        $age = isset($playerRow['age']) ? (int)$playerRow['age'] : null;
        $ovr = $playerRow['ovr'] ?? ($playerRow['overall'] ?? null);
        $ovr = isset($ovr) ? (int)$ovr : null;

        if (!playersColumnExists($pdo, 'trade_items', 'player_name')) {
            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE trade_items
             SET player_name = COALESCE(player_name, ?),
                 player_position = COALESCE(player_position, ?),
                 player_age = COALESCE(player_age, ?),
                 player_ovr = COALESCE(player_ovr, ?),
                 player_id = NULL
             WHERE player_id = ?'
        );
        $stmt->execute([
            $name,
            $position,
            $age,
            $ovr,
            (int)($playerRow['id'] ?? 0)
        ]);

        // Also snapshot multi_trade_items so multi-trade history survives waivers/retirements
        if (playersTableExists($pdo, 'multi_trade_items') && playersColumnExists($pdo, 'multi_trade_items', 'player_name')) {
            try {
                $stmt2 = $pdo->prepare(
                    'UPDATE multi_trade_items
                     SET player_name = COALESCE(player_name, ?),
                         player_position = COALESCE(player_position, ?),
                         player_age = COALESCE(player_age, ?),
                         player_ovr = COALESCE(player_ovr, ?),
                         player_id = NULL
                     WHERE player_id = ?'
                );
                $stmt2->execute([$name, $position, $age, $ovr, (int)($playerRow['id'] ?? 0)]);
            } catch (Exception $e) {
                error_log('[snapshotTradeItems] multi_trade_items failed: ' . $e->getMessage());
            }
        }

        // Snapshot leilao_jogadores so auction history preserves the player name
        if (playersTableExists($pdo, 'leilao_jogadores')) {
            try {
                $pdo->prepare(
                    'UPDATE leilao_jogadores SET temp_name = COALESCE(temp_name, ?) WHERE player_id = ?'
                )->execute([$name, (int)($playerRow['id'] ?? 0)]);
            } catch (Exception $e) {
                error_log('[snapshotTradeItems] leilao_jogadores failed: ' . $e->getMessage());
            }
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

        // Pedigree de draft (usado pela avaliação de trade): em que pick o
        // jogador foi escolhido. Uma consulta só para todos os jogadores.
        if ($players) {
            try {
                $ids = array_map(static fn($p) => (int)$p['id'], $players);
                $in = implode(',', array_fill(0, count($ids), '?'));
                // O pick pode estar no draft de temporada (draft_order) ou no
                // draft inicial da liga (initdraft_order) — os dois contam.
                $stmtD = $pdo->prepare("
                    SELECT picked_player_id, MIN(pick_position) AS pick_position, MIN(round) AS round
                    FROM (
                        SELECT picked_player_id, pick_position, round FROM draft_order
                        WHERE picked_player_id IN ($in)
                        UNION ALL
                        SELECT picked_player_id, pick_position, round FROM initdraft_order
                        WHERE picked_player_id IN ($in)
                    ) d
                    GROUP BY picked_player_id
                ");
                $stmtD->execute(array_merge($ids, $ids));
                $draftMap = [];
                foreach ($stmtD->fetchAll(PDO::FETCH_ASSOC) as $d) {
                    $draftMap[(int)$d['picked_player_id']] = [
                        'pick' => (int)$d['pick_position'],
                        'round' => (int)$d['round'],
                    ];
                }
                foreach ($players as &$p) {
                    $d = $draftMap[(int)$p['id']] ?? null;
                    $p['draft_pick']  = $d['pick']  ?? null;
                    $p['draft_round'] = $d['round'] ?? null;
                }
                unset($p);
            } catch (Exception $e) {
                foreach ($players as &$p) { $p['draft_pick'] = null; $p['draft_round'] = null; }
                unset($p);
            }
        }

        // Computa cap_bonus_eligible para ligas RISE (draft_pool = draft de temporadas)
        if ($teamId) {
            try {
                $stmtLeague = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
                $stmtLeague->execute([$teamId]);
                $league = strtoupper(trim((string)($stmtLeague->fetchColumn() ?? '')));
                $isRise = str_starts_with($league, 'RISE');
                $seasonDraftNames = [];
                if ($isRise) {
                    $stmtSD = $pdo->prepare('SELECT name FROM draft_pool WHERE drafted_by_team_id = ? AND draft_status = "drafted"');
                    $stmtSD->execute([$teamId]);
                    foreach ($stmtSD->fetchAll(PDO::FETCH_COLUMN) as $n) {
                        $seasonDraftNames[$n] = true;
                    }
                }
                foreach ($players as &$p) {
                    if (!$isRise) { $p['cap_bonus_eligible'] = 0; continue; }
                    $notTraded       = (int)($p['was_traded'] ?? 0) === 0;
                    $highOvr         = (int)($p['ovr'] ?? 0) >= 90;
                    $fromSeasonDraft = isset($seasonDraftNames[$p['name']]);
                    $p['cap_bonus_eligible'] = ($notTraded && $highOvr && $fromSeasonDraft) ? 1 : 0;
                }
                unset($p);
            } catch (Exception $e) {}
        }

        // Salário por jogador (ELITE / modo salary) — aditivo, não altera os demais usos.
        $salaryMode = false;
        try {
            require_once __DIR__ . '/../backend/salary_cap.php';
            $lgStmt = $pdo->prepare("SELECT ls.cap_mode FROM teams t JOIN league_settings ls ON ls.league = t.league WHERE t.id = ?");
            $lgStmt->execute([$teamId]);
            if (($lgStmt->fetchColumn() ?: 'ovr_sum') === 'salary') {
                $summary = getTeamCapSummary($pdo, (int)$teamId);
                $salById = [];
                foreach ($summary['roster'] as $rp) { $salById[(int)$rp['id']] = (int)$rp['total_salary']; }
                foreach ($players as &$p) { $p['salary'] = $salById[(int)$p['id']] ?? 0; }
                unset($p);
                $salaryMode = true;
            }
        } catch (Exception $e) { $salaryMode = false; }

        echo json_encode(['success' => true, 'players' => $players, 'count' => count($players), 'team_id' => $teamId, 'salary_mode' => $salaryMode]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao buscar jogadores']);
        exit;
    }
}

if ($method === 'POST') {
    $body = readJsonBody();
    $action = $body['action'] ?? null;
    if ($action === 'bulk_update_skill_grades') {
        $sessionUser = getUserSession();
        if (!isset($sessionUser['id'])) {
            jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
        }
        $teamId = (int)($body['team_id'] ?? 0);
        if (!$teamId) {
            $stmtTeam = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? LIMIT 1');
            $stmtTeam->execute([(int)$sessionUser['id']]);
            $teamId = (int)($stmtTeam->fetchColumn() ?? 0);
        }
        if (!$teamId) {
            jsonResponse(404, ['error' => 'Time não encontrado.']);
        }
        $teamOwner = $pdo->prepare('SELECT user_id FROM teams WHERE id = ?');
        $teamOwner->execute([$teamId]);
        $ownerId = (int)($teamOwner->fetchColumn() ?? 0);
        if ($ownerId !== (int)$sessionUser['id']) {
            jsonResponse(403, ['error' => 'Sem permissão para alterar este time.']);
        }

        $updates = $body['updates'] ?? [];
        if (!is_array($updates)) {
            jsonResponse(422, ['error' => 'Formato inválido para updates.']);
        }
        ensurePlayerSkillGradesColumn($pdo);
        ensureSkillGradeColumns($pdo);
        $stmtUpd = $pdo->prepare('UPDATE players SET player_skill_grades = ?, skill_in = ?, skill_mid = ?, skill_3pt = ?, skill_post_d = ?, skill_per_d = ?, skill_play = ?, skill_reb = ?, skill_athl = ?, skill_iq = ?, skill_pot = ?, age = COALESCE(?, age), ovr = COALESCE(?, ovr) WHERE id = ? AND team_id = ?');
        $updated = 0;
        $updatedIds = [];
        $itemResults = [];
        foreach ($updates as $item) {
            $playerId = (int)($item['player_id'] ?? 0);
            $grades = $item['skill_grades'] ?? null;
            if (!$playerId || (!is_array($grades) && $grades !== null)) {
                $itemResults[] = ['player_id' => $playerId, 'status' => 'skipped'];
                continue;
            }
            $json = $grades === null ? null : json_encode($grades);
            $gradeVal = function ($key) use ($grades) {
                if (!is_array($grades)) return null;
                $val = $grades[$key] ?? null;
                return $val !== '' ? $val : null;
            };
            $age = isset($item['age']) && is_numeric($item['age']) ? (int)$item['age'] : null;
            $ovr = isset($item['ovr']) && is_numeric($item['ovr']) ? (int)$item['ovr'] : null;
            $stmtUpd->execute([
                $json,
                $gradeVal('in'),
                $gradeVal('mid'),
                $gradeVal('pt3'),
                $gradeVal('post_d'),
                $gradeVal('per_d'),
                $gradeVal('play'),
                $gradeVal('reb'),
                $gradeVal('athl'),
                $gradeVal('iq'),
                $gradeVal('pot'),
                $age,
                $ovr,
                $playerId,
                $teamId
            ]);
            $affected = $stmtUpd->rowCount();
            $matched = null;
            if ($affected === 0) {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM players WHERE id = ? AND team_id = ?');
                $chk->execute([$playerId, $teamId]);
                $matched = (int)$chk->fetchColumn();
            }
            if ($affected > 0) {
                $updated += 1;
                $updatedIds[] = $playerId;
            }
            $itemResults[] = [
                'player_id' => $playerId,
                'age' => $age,
                'ovr' => $ovr,
                'affected' => $affected,
                'matched' => $matched,
            ];
        }
        jsonResponse(200, [
            'updated' => $updated,
            'total' => count($updates),
            'updated_ids' => $updatedIds,
            'items' => $itemResults,
        ]);
    }
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

    $prospectiveCap = capWithCandidate($pdo, $teamId, $ovr);
    $warnings = [];
    $capMaxAdjusted = capMaxWithRestrictedBonus($pdo, $teamId, (int)$config['app']['cap_max']);
    if ($prospectiveCap > $capMaxAdjusted) {
        $warnings[] = 'CAP acima do limite recomendado (' . $prospectiveCap . ' / ' . $capMaxAdjusted . ').';
    }

    $stmt = $pdo->prepare('INSERT INTO players (team_id, name, age, position, role, ovr, available_for_trade) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$teamId, $name, $age, $position, $role, $ovr, $availableForTrade]);

    $newCap = topEightCap($pdo, $teamId);
    $capMaxAdjusted = capMaxWithRestrictedBonus($pdo, $teamId, (int)$config['app']['cap_max']);
    if ($newCap < $config['app']['cap_min']) {
        $warnings[] = 'CAP abaixo do mínimo recomendado (' . $newCap . ' / ' . $config['app']['cap_min'] . ').';
    }
    if ($newCap > $capMaxAdjusted) {
        $warnings[] = 'CAP acima do limite recomendado (' . $newCap . ' / ' . $capMaxAdjusted . ').';
    }

    jsonResponse(201, [
        'message' => 'Jogador adicionado.',
        'player_id' => $pdo->lastInsertId(),
        'cap_top8' => $newCap,
        'warning' => !empty($warnings) ? implode(' ', array_unique($warnings)) : null,
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
    $position = array_key_exists('position', $body) ? trim((string)$body['position']) : $player['position'];
    $secondaryPosition = array_key_exists('secondary_position', $body)
        ? trim((string)$body['secondary_position'])
        : ($player['secondary_position'] ?? '');
    $badgesCount = array_key_exists('badges_count', $body)
        ? (is_numeric($body['badges_count']) ? (int)$body['badges_count'] : null)
        : ($player['badges_count'] ?? null);
    $seasonsInLeague = isset($body['seasons_in_league']) ? (int)$body['seasons_in_league'] : (int)($player['seasons_in_league'] ?? 0);
    $role = isset($body['role']) ? $body['role'] : $player['role'];
    $ovr = isset($body['ovr']) ? (int)$body['ovr'] : (int)$player['ovr'];
    $availableForTrade = isset($body['available_for_trade']) ? (int)((bool)$body['available_for_trade']) : (int)$player['available_for_trade'];
    $hasFotoAdicionalField = array_key_exists('foto_adicional', $body);
    $fotoAdicional = $hasFotoAdicionalField ? trim((string)$body['foto_adicional']) : ($player['foto_adicional'] ?? null);
    if ($hasFotoAdicionalField && $fotoAdicional === '') {
        $fotoAdicional = null;
    }
    $playerTag = array_key_exists('player_tag', $body)
        ? (substr(trim((string)$body['player_tag']), 0, 25) ?: null)
        : ($player['player_tag'] ?? null);
    $playerTagColor = array_key_exists('player_tag_color', $body)
        ? (trim((string)$body['player_tag_color']) ?: null)
        : ($player['player_tag_color'] ?? null);
    $playerTagCopy = isset($body['player_tag_copy'])
        ? (int)((bool)$body['player_tag_copy'])
        : (int)($player['player_tag_copy'] ?? 0);
    $skillGrades = null;
    $skillGradesArray = null;
    if (array_key_exists('skill_grades', $body)) {
        $rawGrades = $body['skill_grades'];
        if (is_array($rawGrades) || is_object($rawGrades)) {
            $skillGradesArray = (array)$rawGrades;
            $skillGrades = json_encode($rawGrades);
        } elseif ($rawGrades === null) {
            $skillGrades = null;
            $skillGradesArray = [];
        } elseif (is_string($rawGrades)) {
            $skillGrades = $rawGrades;
            $decoded = json_decode($rawGrades, true);
            if (is_array($decoded)) {
                $skillGradesArray = $decoded;
            }
        }
    }

    if ($hasFotoAdicionalField && $fotoAdicional && str_starts_with($fotoAdicional, 'data:image/')) {
        try {
            $commaPos = strpos($fotoAdicional, ',');
            if ($commaPos === false) {
                throw new Exception('Imagem invalida.');
            }
            $meta = substr($fotoAdicional, 0, $commaPos);
            $base64 = substr($fotoAdicional, $commaPos + 1);
            $mime = null;
            if (preg_match('/data:(image\/(png|jpeg|jpg|webp));base64/i', $meta, $m)) {
                $mime = strtolower($m[1]);
            }
            $ext = 'png';
            if ($mime === 'image/jpeg' || $mime === 'image/jpg') { $ext = 'jpg'; }
            if ($mime === 'image/webp') { $ext = 'webp'; }
            $binary = base64_decode($base64);
            if ($binary === false) { throw new Exception('Falha ao decodificar imagem.'); }

            $dirFs = __DIR__ . '/../uploads/players';
            if (!is_dir($dirFs)) { @mkdir($dirFs, 0775, true); }
            $filename = 'player-' . $playerId . '-' . time() . '.' . $ext;
            $fullPath = $dirFs . '/' . $filename;
            if (file_put_contents($fullPath, $binary) === false) {
                throw new Exception('Falha ao salvar imagem.');
            }
            $fotoAdicional = '/uploads/players/' . $filename;
        } catch (Exception $e) {
            jsonResponse(422, ['error' => 'Falha ao salvar foto do jogador.']);
        }
    }

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

    $warnings = [];

    // Verificar se as colunas extras existem
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM players LIKE 'secondary_position'");
        $hasSecondaryPosition = $checkCol->rowCount() > 0;

        $checkCol2 = $pdo->query("SHOW COLUMNS FROM players LIKE 'seasons_in_league'");
        $hasSeasonsInLeague = $checkCol2->rowCount() > 0;

        $checkCol3 = $pdo->query("SHOW COLUMNS FROM players LIKE 'foto_adicional'");
        $hasFotoAdicional = $checkCol3->rowCount() > 0;

        $checkCol4 = $pdo->query("SHOW COLUMNS FROM players LIKE 'player_tag'");
        $hasPlayerTag = $checkCol4->rowCount() > 0;
        if (!$hasPlayerTag) {
            $pdo->exec("ALTER TABLE players ADD COLUMN player_tag VARCHAR(25) NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE players ADD COLUMN player_tag_color VARCHAR(7) NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE players ADD COLUMN player_tag_copy TINYINT(1) NOT NULL DEFAULT 0");
            $hasPlayerTag = true;
        }
        $checkCol5 = $pdo->query("SHOW COLUMNS FROM players LIKE 'player_skill_grades'");
        $hasSkillGrades = $checkCol5->rowCount() > 0;
        if (!$hasSkillGrades) {
            $pdo->exec("ALTER TABLE players ADD COLUMN player_skill_grades TEXT NULL");
            $hasSkillGrades = true;
        }
        $checkCol6 = $pdo->query("SHOW COLUMNS FROM players LIKE 'badges_count'");
        $hasBadgesCount = $checkCol6->rowCount() > 0;
        if (!$hasBadgesCount) {
            $pdo->exec("ALTER TABLE players ADD COLUMN badges_count INT NULL");
            $hasBadgesCount = true;
        }
        $skillCols = ensureSkillGradeColumns($pdo);
        $hasSkillGradeColumns = !empty($skillCols);
    } catch (Exception $e) {
        $hasSecondaryPosition = false;
        $hasSeasonsInLeague = false;
        $hasFotoAdicional = false;
        $hasPlayerTag = false;
        $hasSkillGrades = false;
        $hasBadgesCount = false;
        $hasSkillGradeColumns = false;
    }

    // Construir UPDATE dinamicamente
    $fields = [
        'name' => $name,
        'age' => $age,
        'position' => $position,
        'role' => $role,
        'ovr' => $ovr,
        'available_for_trade' => $availableForTrade,
    ];
    if ($hasSecondaryPosition) {
        $fields['secondary_position'] = $secondaryPosition ?: null;
    }
    if ($hasBadgesCount) {
        $fields['badges_count'] = $badgesCount !== null ? max(0, (int)$badgesCount) : null;
    }
    if ($hasSeasonsInLeague) {
        $fields['seasons_in_league'] = $seasonsInLeague;
    }
    if ($hasFotoAdicional && $hasFotoAdicionalField) {
        $fields['foto_adicional'] = $fotoAdicional;
    }
    if ($hasPlayerTag) {
        $fields['player_tag']       = $playerTag;
        $fields['player_tag_color'] = $playerTagColor;
        $fields['player_tag_copy']  = $playerTagCopy;
    }
    if ($hasSkillGrades && array_key_exists('skill_grades', $body)) {
        $fields['player_skill_grades'] = $skillGrades;
    }
    if ($hasSkillGradeColumns && $skillGradesArray !== null) {
        $fields['skill_in'] = $skillGradesArray['in'] ?? null;
        $fields['skill_mid'] = $skillGradesArray['mid'] ?? null;
        $fields['skill_3pt'] = $skillGradesArray['pt3'] ?? null;
        $fields['skill_post_d'] = $skillGradesArray['post_d'] ?? null;
        $fields['skill_per_d'] = $skillGradesArray['per_d'] ?? null;
        $fields['skill_play'] = $skillGradesArray['play'] ?? null;
        $fields['skill_reb'] = $skillGradesArray['reb'] ?? null;
        $fields['skill_athl'] = $skillGradesArray['athl'] ?? null;
        $fields['skill_iq'] = $skillGradesArray['iq'] ?? null;
        $fields['skill_pot'] = $skillGradesArray['pot'] ?? null;
    }

    $setClause = implode(', ', array_map(fn($col) => $col . ' = ?', array_keys($fields)));
    $upd = $pdo->prepare('UPDATE players SET ' . $setClause . ' WHERE id = ?');
    $values = array_values($fields);
    $values[] = $playerId;
    $upd->execute($values);

    // Registrar que o time atualizou o elenco nesta temporada
    try {
        $chkRUA = $pdo->query("SHOW COLUMNS FROM teams LIKE 'roster_updated_at'");
        if ($chkRUA->rowCount() === 0) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN roster_updated_at TIMESTAMP NULL DEFAULT NULL");
        }
        $pdo->prepare("UPDATE teams SET roster_updated_at = NOW() WHERE id = ?")->execute([(int)$player['team_id']]);
    } catch (Exception $e) {}

    $newCap = topEightCap($pdo, (int)$player['team_id']);
    $capMaxAdjusted = capMaxWithRestrictedBonus($pdo, (int)$player['team_id'], (int)$config['app']['cap_max']);
    if ($newCap < $config['app']['cap_min']) {
        $warnings[] = 'CAP abaixo do mínimo recomendado (' . $newCap . ' / ' . $config['app']['cap_min'] . ').';
    }
    if ($newCap > $capMaxAdjusted) {
        $warnings[] = 'CAP acima do limite recomendado (' . $newCap . ' / ' . $capMaxAdjusted . ').';
    }
    jsonResponse(200, [
        'message' => 'Jogador atualizado.',
        'cap_top8' => $newCap,
        'warning' => !empty($warnings) ? implode(' ', array_unique($warnings)) : null,
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
            snapshotTradeItemsForPlayer($pdo, $row);
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
            jsonResponse(500, ['error' => 'Erro ao aposentar jogador']);
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

        snapshotTradeItemsForPlayer($pdo, $row);
        $del = $pdo->prepare('DELETE FROM players WHERE id = ?');
        $del->execute([$playerId]);

        $stmtUpd = $pdo->prepare('UPDATE teams SET waivers_used = COALESCE(waivers_used, 0) + 1 WHERE id = ?');
        $stmtUpd->execute([$row['team_id']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => 'Erro ao dispensar jogador']);
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
