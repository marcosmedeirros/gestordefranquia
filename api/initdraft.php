<?php
/**
 * API de Draft Inicial (initdraft)
 * Separado do draft de temporada. Controlado por token de acesso.
 */

// Define timezone padrão para todo o sistema: São Paulo/Brasília
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';

header('Content-Type: application/json');

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

// Usuário (pode não estar logado; token controla acesso)
$user = getUserSession();
$isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';

function randomToken($len = 32) {
    return bin2hex(random_bytes(max(8, (int)($len/2))));
}

function getSessionByToken($pdo, $token) {
    $stmt = $pdo->prepare('SELECT * FROM initdraft_sessions WHERE access_token = ?');
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function ensureAdminOrToken($session, $token) {
    global $isAdmin;
    if ($isAdmin) return true;
    return $session && hash_equals($session['access_token'], (string)$token);
}

function ensureDailyScheduleColumns(PDO $pdo): void {
    $table = 'initdraft_sessions';
    $columns = [
        'daily_schedule_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'daily_schedule_start_date' => 'DATE NULL',
        'daily_clock_start_time' => "TIME NOT NULL DEFAULT '19:30:00'",
        'daily_pick_minutes' => 'INT NOT NULL DEFAULT 10',
        'daily_last_opened_date' => 'DATE NULL',
    ];
    foreach ($columns as $name => $definition) {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE ?');
        $stmt->execute([$name]);
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
        }
    }
}

function ensureDeadlineColumn(PDO $pdo): void {
    $table = 'initdraft_order';
    $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE ?');
    $stmt->execute(['deadline_at']);
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN deadline_at DATETIME NULL");
    }
}

function persistDraftOrder(PDO $pdo, array $roundOneOrder, array $session): void {
    $roundOneOrder = array_values(array_map('intval', $roundOneOrder));
    $sessionId = (int)$session['id'];
    $totalRounds = (int)$session['total_rounds'];

    if ($totalRounds < 1) {
        throw new Exception('Total de rodadas inválido para esta sessão');
    }

    $pdo->prepare('DELETE FROM initdraft_order WHERE initdraft_session_id = ?')->execute([$sessionId]);

    for ($round = 1; $round <= $totalRounds; $round++) {
        $roundOrder = ($round % 2 === 1) ? $roundOneOrder : array_reverse($roundOneOrder);
        foreach ($roundOrder as $idx => $teamId) {
            $pdo->prepare('INSERT INTO initdraft_order (initdraft_session_id, team_id, original_team_id, pick_position, round) VALUES (?, ?, ?, ?, ?)')
                ->execute([$sessionId, $teamId, $teamId, $idx + 1, $round]);
        }
    }

    // Garantir que o ponteiro de rodada/pick volte ao início
    $pdo->prepare('UPDATE initdraft_sessions SET current_round = 1, current_pick = 1 WHERE id = ?')->execute([$sessionId]);
}

function getSessionById(PDO $pdo, int $sessionId): ?array {
    if ($sessionId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM initdraft_sessions WHERE id = ?');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    return $session ?: null;
}

function performInitDraftPick(PDO $pdo, array $session, int $playerId): void {
    if (!$session) {
        throw new Exception('Sessão inválida');
    }
    if ($session['status'] !== 'in_progress') {
        throw new Exception('Draft não está em andamento');
    }
    if ($playerId <= 0) {
        throw new Exception('player_id obrigatório');
    }

    $sessionRound = (int)($session['current_round'] ?? 1);
    $sessionPick = (int)($session['current_pick'] ?? 1);

    $stmtPick = $pdo->prepare('SELECT * FROM initdraft_order WHERE initdraft_session_id = ? AND round = ? AND pick_position = ? AND picked_player_id IS NULL');
    $stmtPick->execute([$session['id'], $sessionRound, $sessionPick]);
    $currentPick = $stmtPick->fetch(PDO::FETCH_ASSOC);

    if (!$currentPick) {
        $stmtPick = $pdo->prepare('SELECT * FROM initdraft_order WHERE initdraft_session_id = ? AND picked_player_id IS NULL ORDER BY round ASC, pick_position ASC LIMIT 1');
        $stmtPick->execute([$session['id']]);
        $currentPick = $stmtPick->fetch(PDO::FETCH_ASSOC);
        if (!$currentPick) {
            throw new Exception('Todas as picks já foram realizadas');
        }
        $sessionRound = (int)$currentPick['round'];
        $sessionPick = (int)$currentPick['pick_position'];
        $pdo->prepare('UPDATE initdraft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')
            ->execute([$sessionRound, $sessionPick, $session['id']]);
    }

    $stmtP = $pdo->prepare('SELECT * FROM initdraft_pool WHERE id = ? AND draft_status = "available"');
    $stmtP->execute([$playerId]);
    $player = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!$player) {
        throw new Exception('Jogador indisponível');
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare('UPDATE initdraft_order SET picked_player_id = ?, picked_at = NOW() WHERE id = ?')
            ->execute([$playerId, $currentPick['id']]);

        $stmtRoundSize = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = ?');
        $stmtRoundSize->execute([$session['id'], $sessionRound]);
        $roundSize = max(1, (int)$stmtRoundSize->fetchColumn());
        $pickNumber = (($sessionRound - 1) * $roundSize) + $sessionPick;

        $pdo->prepare('UPDATE initdraft_pool SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ? WHERE id = ?')
            ->execute([$currentPick['team_id'], $pickNumber, $playerId]);

        $pdo->prepare('INSERT INTO players (team_id, name, position, age, ovr, role, available_for_trade) VALUES (?, ?, ?, ?, ?, "Banco", 0)')
            ->execute([$currentPick['team_id'], $player['name'], $player['position'], $player['age'], $player['ovr']]);

        $nextPick = $sessionPick + 1;
        $nextRound = $sessionRound;
        $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = ?');
        $stmtCount->execute([$session['id'], $nextRound]);
        $totalPicks = (int)$stmtCount->fetchColumn();

        if ($nextPick > $totalPicks) {
            $nextRound++;
            $nextPick = 1;
            if ($nextRound > (int)$session['total_rounds']) {
                $pdo->prepare('UPDATE initdraft_sessions SET status = "completed", completed_at = NOW(), current_round = ?, current_pick = ? WHERE id = ?')
                    ->execute([$sessionRound, $sessionPick, $session['id']]);
            } else {
                $pdo->prepare('UPDATE initdraft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')
                    ->execute([$nextRound, $nextPick, $session['id']]);
            }
        } else {
            $pdo->prepare('UPDATE initdraft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')
                ->execute([$nextRound, $nextPick, $session['id']]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function tzNow(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
}

function computeDailyRoundForDate(?string $startDate, DateTimeImmutable $now): ?int {
    if (!$startDate) return null;
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate, $now->getTimezone());
    if (!$start) return null;
    // antes do dia de start, não existe round do dia ainda
    if ($now->format('Y-m-d') < $start->format('Y-m-d')) {
        return null;
    }
    $days = (int)$start->diff($now)->format('%a');
    return $days + 1;
}

function getCurrentOpenPick(PDO $pdo, int $sessionId, int $round): ?array {
    $stmt = $pdo->prepare('SELECT * FROM initdraft_order WHERE initdraft_session_id = ? AND round = ? AND picked_player_id IS NULL ORDER BY pick_position ASC LIMIT 1');
    $stmt->execute([$sessionId, $round]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function isRoundCompleted(PDO $pdo, int $sessionId, int $round): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = ? AND picked_player_id IS NULL');
    $stmt->execute([$sessionId, $round]);
    return (int)$stmt->fetchColumn() === 0;
}

function clearDeadlinesForRound(PDO $pdo, int $sessionId, int $round): void {
    ensureDeadlineColumn($pdo);
    $pdo->prepare('UPDATE initdraft_order SET deadline_at = NULL WHERE initdraft_session_id = ? AND round = ? AND picked_player_id IS NULL')
        ->execute([$sessionId, $round]);
}

function ensureDeadlineForPick(PDO $pdo, array $pick, DateTimeImmutable $now, int $pickMinutes): void {
    if (!empty($pick['deadline_at'])) return;
    ensureDeadlineColumn($pdo);
    $deadline = $now->add(new DateInterval('PT' . max(1, $pickMinutes) . 'M'));
    $pdo->prepare('UPDATE initdraft_order SET deadline_at = ? WHERE id = ?')
        ->execute([$deadline->format('Y-m-d H:i:s'), $pick['id']]);
}

function pickHighestOvrAvailable(PDO $pdo, int $seasonId): ?int {
    // Regra: maior OVR; empate -> maior idade; empate -> aleatório.
    // OBS: age pode ser NULL. Neste caso, tratamos como idade "muito baixa" para priorizar quem tem idade definida.

    // 1) Melhor OVR disponível
    $stmtMax = $pdo->prepare('SELECT MAX(ovr) FROM initdraft_pool WHERE season_id = ? AND draft_status = "available"');
    $stmtMax->execute([$seasonId]);
    $maxOvr = $stmtMax->fetchColumn();
    if ($maxOvr === false || $maxOvr === null) return null;
    $maxOvr = (int)$maxOvr;

    // 2) Maior idade dentro do melhor OVR
    $stmtMaxAge = $pdo->prepare('
        SELECT MAX(COALESCE(NULLIF(age, 0), 0))
        FROM initdraft_pool
        WHERE season_id = ? AND draft_status = "available" AND ovr = ?
    ');
    $stmtMaxAge->execute([$seasonId, $maxOvr]);
    $maxAge = $stmtMaxAge->fetchColumn();
    if ($maxAge === false || $maxAge === null) {
        $maxAge = 0;
    }
    $maxAge = (int)$maxAge;

    // 3) Seleciona aleatoriamente entre os empatados
    $stmt = $pdo->prepare('
        SELECT id
        FROM initdraft_pool
        WHERE season_id = ?
          AND draft_status = "available"
          AND ovr = ?
        AND COALESCE(NULLIF(age, 0), 0) = ?
    ');
    $stmt->execute([$seasonId, $maxOvr, $maxAge]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return null;

    $picked = $ids[random_int(0, count($ids) - 1)];
    return (int)$picked;
}

function autoPickIfTimedOut(PDO $pdo, array $session, DateTimeImmutable $now): void {
    $enabled = (int)($session['daily_schedule_enabled'] ?? 0) === 1;
    if (!$enabled) return;
    if (($session['status'] ?? 'setup') !== 'in_progress') return;

    $dailyRound = computeDailyRoundForDate($session['daily_schedule_start_date'] ?? null, $now);
    if (!$dailyRound) return;
    if ($dailyRound > (int)$session['total_rounds']) return;

    // relógio só depois do horário configurado
    $clockStart = ($session['daily_clock_start_time'] ?? '19:30:00');
    $clockStartDT = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $clockStart, $now->getTimezone());
    if ($now < $clockStartDT) return;

    $pick = getCurrentOpenPick($pdo, (int)$session['id'], $dailyRound);
    if (!$pick) return;

    if (empty($pick['deadline_at'])) {
        ensureDeadlineForPick($pdo, $pick, $now, (int)($session['daily_pick_minutes'] ?? 10));
        return;
    }

    $deadline = new DateTimeImmutable($pick['deadline_at'], $now->getTimezone());
    if ($now <= $deadline) return;

    $playerId = pickHighestOvrAvailable($pdo, (int)$session['season_id']);
    if (!$playerId) {
        // sem jogadores: encerra round e draft
        $pdo->prepare('UPDATE initdraft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')
            ->execute([$session['id']]);
        return;
    }

    // Faz pick e limpa deadlines do round pra recalcular no próximo
    performInitDraftPick($pdo, $session, $playerId);
    clearDeadlinesForRound($pdo, (int)$session['id'], $dailyRound);
}

function applyDailySchedule(PDO $pdo, array $session): array {
    $enabled = (int)($session['daily_schedule_enabled'] ?? 0) === 1;
    if (!$enabled) return $session;

    $now = tzNow();
    $dailyRound = computeDailyRoundForDate($session['daily_schedule_start_date'] ?? null, $now);
    if (!$dailyRound) {
        return $session;
    }
    if ($dailyRound > (int)$session['total_rounds']) {
        return $session;
    }

    // abre o draft (00:00:01) e marca qual dia já foi processado
    $today = $now->format('Y-m-d');
    $openAfter = new DateTimeImmutable($today . ' 00:00:01', $now->getTimezone());
    if ($now >= $openAfter && ($session['daily_last_opened_date'] ?? null) !== $today) {
        // garante que draft esteja em andamento
        if (($session['status'] ?? 'setup') === 'setup') {
            $pdo->prepare('UPDATE initdraft_sessions SET status = "in_progress", started_at = COALESCE(started_at, NOW()) WHERE id = ?')
                ->execute([$session['id']]);
        }
        $pdo->prepare('UPDATE initdraft_sessions SET daily_last_opened_date = ? WHERE id = ?')
            ->execute([$today, $session['id']]);
        $session['daily_last_opened_date'] = $today;
        $session['status'] = 'in_progress';
    }

    // Se round já terminou, encerra o draft inteiro (regra pedida)
    if (($session['status'] ?? 'setup') === 'in_progress' && isRoundCompleted($pdo, (int)$session['id'], $dailyRound)) {
        $pdo->prepare('UPDATE initdraft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')
            ->execute([$session['id']]);
        $session['status'] = 'completed';
        return $session;
    }

    // Antes das 19:30: sem relógio (remove deadlines)
    $clockStart = ($session['daily_clock_start_time'] ?? '19:30:00');
    $clockStartDT = new DateTimeImmutable($today . ' ' . $clockStart, $now->getTimezone());
    if ($now < $clockStartDT) {
        clearDeadlinesForRound($pdo, (int)$session['id'], $dailyRound);
        return $session;
    }

    // Depois das 19:30: garante deadline do pick atual e faz auto-pick se expirou
    autoPickIfTimedOut($pdo, $session, $now);
    // Recarrega sessão em caso de mudanças
    $fresh = getSessionById($pdo, (int)$session['id']);
    return $fresh ?: $session;
}

// ========== GET ==========
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'state';

    try {
        switch ($action) {
            case 'state': {
                $token = $_GET['token'] ?? null;
                $sessionId = $_GET['id'] ?? null;

                if (!$token && !$sessionId) throw new Exception('token ou id obrigatório');

                if ($token) {
                    $session = getSessionByToken($pdo, $token);
                } else {
                    $stmt = $pdo->prepare('SELECT * FROM initdraft_sessions WHERE id = ?');
                    $stmt->execute([$sessionId]);
                    $session = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                if (!$session) throw new Exception('Sessão não encontrada');

                // Aplicar regras do agendamento diário (fallback do cron)
                $session = applyDailySchedule($pdo, $session);

          // Buscar ordem
                $stmtOrder = $pdo->prepare('
                    SELECT io.*, 
                           t.city as team_city, t.name as team_name, t.photo_url as team_photo,
                           dp.name as player_name, dp.position as player_position, dp.ovr as player_ovr
                    FROM initdraft_order io
                    INNER JOIN teams t ON io.team_id = t.id
                    LEFT JOIN initdraft_pool dp ON io.picked_player_id = dp.id
                    WHERE io.initdraft_session_id = ?
                    ORDER BY io.round ASC, io.pick_position ASC
                ');
                $stmtOrder->execute([$session['id']]);
                $order = $stmtOrder->fetchAll(PDO::FETCH_ASSOC);

                // Buscar todos os times elegíveis da liga
                $stmtTeams = $pdo->prepare('SELECT t.id, t.city, t.name, t.photo_url, u.name AS owner_name FROM teams t LEFT JOIN users u ON t.user_id = u.id WHERE t.league = ? ORDER BY t.name ASC');
                $stmtTeams->execute([$session['league']]);
                $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'session' => $session, 'order' => $order, 'teams' => $teams]);
                break;
            }

            case 'session_for_season': {
                $seasonId = (int)($_GET['season_id'] ?? 0);
                if (!$seasonId) throw new Exception('season_id obrigatório');

                $stmt = $pdo->prepare('SELECT * FROM initdraft_sessions WHERE season_id = ? LIMIT 1');
                $stmt->execute([$seasonId]);
                $session = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'session' => $session]);
                break;
            }

            case 'available_players': {
                $token = $_GET['token'] ?? null;
                $sessionId = (int)($_GET['session_id'] ?? 0);
                $session = null;

                if ($sessionId && $isAdmin) {
                    $session = getSessionById($pdo, $sessionId);
                } elseif ($token) {
                    $session = getSessionByToken($pdo, $token);
                }

                if (!$session) throw new Exception('Sessão não encontrada');

                $stmt = $pdo->prepare('SELECT * FROM initdraft_pool WHERE season_id = ? AND draft_status = "available" ORDER BY ovr DESC, name ASC');
                $stmt->execute([$session['season_id']]);
                $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'players' => $players]);
                break;
            }
            case 'pool': {
                $token = $_GET['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!$session) throw new Exception('Sessão não encontrada');

                $stmt = $pdo->prepare('SELECT * FROM initdraft_pool WHERE season_id = ? ORDER BY draft_status ASC, ovr DESC, name ASC');
                $stmt->execute([$session['season_id']]);
                $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'players' => $players]);
                break;
            }

            default:
                echo json_encode(['success' => false, 'error' => 'Ação inválida']);
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========== POST ==========
if ($method === 'POST') {
    // Support JSON body or multipart/form-data (CSV import)
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? [];
    $action = $data['action'] ?? ($_POST['action'] ?? '');

    try {
        switch ($action) {
            // ADMIN: criar sessão de initdraft (gera token único)
            case 'create_session': {
                if (!$isAdmin) throw new Exception('Apenas administradores');

                $seasonId = (int)($data['season_id'] ?? 0);
                if (!$seasonId) throw new Exception('season_id obrigatório');

                // Buscar liga da temporada
                $stmtS = $pdo->prepare('SELECT league FROM seasons WHERE id = ?');
                $stmtS->execute([$seasonId]);
                $season = $stmtS->fetch(PDO::FETCH_ASSOC);
                if (!$season) throw new Exception('Temporada não encontrada');

                // Verifica se já existe
                $stmtChk = $pdo->prepare('SELECT id FROM initdraft_sessions WHERE season_id = ?');
                $stmtChk->execute([$seasonId]);
                if ($stmtChk->fetch()) throw new Exception('Já existe uma sessão de initdraft para esta temporada');

                $totalRounds = (int)($data['total_rounds'] ?? 5);
                if ($totalRounds < 1) $totalRounds = 1; if ($totalRounds > 10) $totalRounds = 10;
                $token = randomToken(32);

                $stmtIns = $pdo->prepare('INSERT INTO initdraft_sessions (season_id, league, total_rounds, access_token) VALUES (?, ?, ?, ?)');
                $stmtIns->execute([$seasonId, $season['league'], $totalRounds, $token]);

                echo json_encode(['success' => true, 'initdraft_session_id' => $pdo->lastInsertId(), 'token' => $token]);
                break;
            }

            // ADMIN/TOKEN: importar jogadores (array de objetos)
            case 'import_players': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new Exception('Não autorizado');

                $players = $data['players'] ?? [];
                if (!is_array($players) || count($players) === 0) throw new Exception('Nada para importar');

                // Campos mínimos: name, position, age, ovr
                $stmt = $pdo->prepare('INSERT INTO initdraft_pool (season_id, name, position, age, ovr) VALUES (?, ?, ?, ?, ?)');
                $count = 0;
                foreach ($players as $p) {
                    $stmt->execute([
                        $session['season_id'],
                        $p['name'] ?? '',
                        $p['position'] ?? 'SF',
                        (int)($p['age'] ?? 20),
                        (int)($p['ovr'] ?? 70),
                    ]);
                    $count++;
                }

                echo json_encode(['success' => true, 'imported' => $count]);
                break;
            }

            // ADMIN/TOKEN: adicionar um jogador manualmente
            case 'add_player': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new Exception('Não autorizado');

                // Campos mínimos
                $stmt = $pdo->prepare('INSERT INTO initdraft_pool (season_id, name, position, age, ovr) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([
                    $session['season_id'],
                    $data['name'],
                    $data['position'],
                    (int)$data['age'],
                    (int)$data['ovr'],
                ]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                break;
            }

            case 'edit_player': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new Exception('Não autorizado');
                if ($session['status'] !== 'setup') throw new Exception('Só é possível editar jogadores durante setup');

                $playerId = (int)($data['player_id'] ?? 0);
                if (!$playerId) throw new Exception('player_id obrigatório');

                // Verificar se o jogador existe e não foi draftado
                $stmt = $pdo->prepare('SELECT id FROM initdraft_pool WHERE id = ? AND season_id = ? AND draft_status = "available"');
                $stmt->execute([$playerId, $session['season_id']]);
                if (!$stmt->fetch()) throw new Exception('Jogador não encontrado ou já foi draftado');

                // Atualizar dados
                $stmt = $pdo->prepare('UPDATE initdraft_pool SET name = ?, position = ?, age = ?, ovr = ? WHERE id = ?');
                $stmt->execute([
                    $data['name'],
                    $data['position'],
                    (int)$data['age'],
                    (int)$data['ovr'],
                    $playerId,
                ]);

                echo json_encode(['success' => true]);
                break;
            }

            case 'delete_player': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new Exception('Não autorizado');
                if ($session['status'] !== 'setup') throw new Exception('Só é possível remover jogadores durante setup');

                $playerId = (int)($data['player_id'] ?? 0);
                if (!$playerId) throw new Exception('player_id obrigatório');

                $stmt = $pdo->prepare('SELECT id FROM initdraft_pool WHERE id = ? AND draft_status = "available"');
                $stmt->execute([$playerId]);
                if (!$stmt->fetch()) throw new Exception('Jogador não pode ser removido');

                $pdo->prepare('DELETE FROM initdraft_pool WHERE id = ?')->execute([$playerId]);

                echo json_encode(['success' => true]);
                break;
            }

            // ADMIN/TOKEN: importar via CSV (multipart/form-data)
            case 'import_csv': {
                $token = $_POST['token'] ?? ($data['token'] ?? null);
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new Exception('Não autorizado');

                if (!isset($_FILES['csv_file'])) throw new Exception('Arquivo CSV obrigatório');
                $file = $_FILES['csv_file'];
                if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Falha no upload');
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext !== 'csv') throw new Exception('Arquivo deve ser CSV');

                $handle = fopen($file['tmp_name'], 'r');
                if (!$handle) throw new Exception('Não foi possível ler o arquivo');

                // Tenta detectar cabeçalho; aceita colunas: name,position,age,ovr
                $header = fgetcsv($handle, 1000, ',');
                $hasHeader = false;
                $map = ['name' => 0, 'position' => 1, 'age' => 2, 'ovr' => 3];
                if ($header) {
                    $lower = array_map(fn($x) => strtolower(trim($x)), $header);
                    if (in_array('name', $lower) && in_array('age', $lower)) {
                        $hasHeader = true;
                        $map = [
                            'name' => array_search('name', $lower),
                            'position' => array_search('position', $lower),
                            'age' => array_search('age', $lower),
                            'ovr' => array_search('ovr', $lower),
                        ];
                    } else {
                        // volta para primeira linha como dados
                        rewind($handle);
                    }
                }

                $stmt = $pdo->prepare('INSERT INTO initdraft_pool (season_id, name, position, age, ovr) VALUES (?, ?, ?, ?, ?)');
                $inserted = 0;
                while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                    $name = trim($row[$map['name']] ?? '');
                    if ($name === '') continue;
                    $position = strtoupper(trim($row[$map['position']] ?? 'SF'));
                    if (!in_array($position, ['PG','SG','SF','PF','C'])) $position = 'SF';
                    $age = (int)($row[$map['age']] ?? 20);
                    $ovr = (int)($row[$map['ovr']] ?? 70);
                    $stmt->execute([$session['season_id'], $name, $position, $age, $ovr]);
                    $inserted++;
                }
                fclose($handle);

                echo json_encode(['success' => true, 'imported' => $inserted]);
                break;
            }

            // ADMIN/TOKEN: randomizar ordem (primeiro sorteado = último da 1ª rodada)
            case 'randomize_order': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new Exception('Não autorizado');
                if ($session['status'] !== 'setup') throw new Exception('Só é possível randomizar durante setup');

                // Buscar times da liga
                $stmtTeams = $pdo->prepare('SELECT t.id, t.city, t.name, t.photo_url, u.name AS owner_name FROM teams t LEFT JOIN users u ON t.user_id = u.id WHERE t.league = ? ORDER BY t.name ASC');
                $stmtTeams->execute([$session['league']]);
                $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);
                if (!$teams) throw new Exception('Sem times na liga');

                $teamIds = array_column($teams, 'id');
                shuffle($teamIds);
                $orderRound1 = array_values($teamIds);

                try {
                    $pdo->beginTransaction();
                    persistDraftOrder($pdo, $orderRound1, $session);
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                $teamsById = [];
                foreach ($teams as $teamData) {
                    $teamsById[$teamData['id']] = $teamData;
                }
                $orderDetails = array_map(fn($id) => $teamsById[$id] ?? ['id' => $id], $orderRound1);

                echo json_encode(['success' => true, 'order' => $orderRound1, 'order_details' => $orderDetails]);
                break;
            }

            case 'set_manual_order': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new Exception('Não autorizado');
                if ($session['status'] !== 'setup') throw new Exception('Só é possível definir a ordem durante setup');

                $teamIds = $data['team_ids'] ?? [];
                if (!is_array($teamIds) || count($teamIds) === 0) throw new Exception('Informe a ordem completa dos times');
                $teamIds = array_values(array_map('intval', $teamIds));

                $stmtTeams = $pdo->prepare('SELECT id FROM teams WHERE league = ? ORDER BY id ASC');
                $stmtTeams->execute([$session['league']]);
                $leagueTeams = $stmtTeams->fetchAll(PDO::FETCH_COLUMN);
                if (!$leagueTeams) throw new Exception('Sem times cadastrados para a liga');

                sort($leagueTeams);
                $sortedInput = $teamIds;
                sort($sortedInput);
                if ($leagueTeams !== $sortedInput) {
                    throw new Exception('A ordem precisa incluir todos os times da liga exatamente uma vez');
                }

                try {
                    $pdo->beginTransaction();
                    persistDraftOrder($pdo, $teamIds, $session);
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                echo json_encode(['success' => true]);
                break;
            }

            case 'set_total_rounds': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new Exception('Não autorizado');
                if ($session['status'] !== 'setup') throw new Exception('Só é possível ajustar rodadas durante setup');

                $totalRounds = (int)($data['total_rounds'] ?? 0);
                if ($totalRounds < 1 || $totalRounds > 10) {
                    throw new Exception('Informe um número de rodadas entre 1 e 10');
                }

                // Atualizar total_rounds na sessão
                $pdo->prepare('UPDATE initdraft_sessions SET total_rounds = ? WHERE id = ?')
                    ->execute([$totalRounds, $session['id']]);

                // Atualizar session array para retornar valor atualizado
                $session['total_rounds'] = $totalRounds;

                echo json_encode(['success' => true, 'total_rounds' => $totalRounds]);
                break;
            }

            case 'set_daily_schedule': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new Exception('Não autorizado');
                if ($session['status'] !== 'setup') throw new Exception('Só é possível configurar o agendamento durante setup');

                ensureDailyScheduleColumns($pdo);

                $enabled = (int)($data['enabled'] ?? 0) === 1 ? 1 : 0;
                $startDate = trim((string)($data['start_date'] ?? ''));
                if ($enabled && !$startDate) {
                    throw new Exception('Informe a data de início');
                }
                if ($startDate) {
                    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $startDate, new DateTimeZone('America/Sao_Paulo'));
                    if (!$dt || $dt->format('Y-m-d') !== $startDate) {
                        throw new Exception('Data inválida (use YYYY-MM-DD)');
                    }
                }

                $pdo->prepare('UPDATE initdraft_sessions SET daily_schedule_enabled = ?, daily_schedule_start_date = ?, daily_last_opened_date = NULL WHERE id = ?')
                    ->execute([$enabled, $startDate ?: null, $session['id']]);

                $endDate = null;
                if ($enabled && $startDate) {
                    $start = new DateTimeImmutable($startDate, new DateTimeZone('America/Sao_Paulo'));
                    $end = $start->add(new DateInterval('P' . max(0, ((int)$session['total_rounds']) - 1) . 'D'));
                    $endDate = $end->format('Y-m-d');
                }

                echo json_encode([
                    'success' => true,
                    'enabled' => (bool)$enabled,
                    'start_date' => $startDate ?: null,
                    'end_date' => $endDate,
                ]);
                break;
            }

            // ADMIN/TOKEN: iniciar draft
            case 'start': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new Exception('Não autorizado');

                // Precisa ter ordem
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ?');
                $stmt->execute([$session['id']]);
                if ((int)$stmt->fetchColumn() === 0) throw new Exception('Defina a ordem antes de iniciar');

                $pdo->prepare('UPDATE initdraft_sessions SET status = "in_progress", started_at = NOW() WHERE id = ?')->execute([$session['id']]);
                echo json_encode(['success' => true]);
                break;
            }

            // TOKEN: fazer pick na posição corrente
            case 'make_pick': {
                $token = $data['token'] ?? null;
                $playerId = (int)($data['player_id'] ?? 0);
                $session = getSessionByToken($pdo, $token);
                if (!$session) throw new Exception('Sessão inválida');

                performInitDraftPick($pdo, $session, $playerId);
                echo json_encode(['success' => true, 'message' => 'Pick realizada']);
                break;
            }

            case 'admin_make_pick': {
                if (!$isAdmin) throw new Exception('Apenas administradores');
                $sessionId = (int)($data['session_id'] ?? 0);
                $playerId = (int)($data['player_id'] ?? 0);
                $session = getSessionById($pdo, $sessionId);
                if (!$session) throw new Exception('Sessão inválida');

                performInitDraftPick($pdo, $session, $playerId);
                echo json_encode(['success' => true, 'message' => 'Pick realizada pelo admin']);
                break;
            }

            // TOKEN: finalizar (garantia idempotente)
            case 'finalize': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new Exception('Não autorizado');

                // Apenas marca completed se todas picks efetuadas
                $stmtMissing = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND picked_player_id IS NULL');
                $stmtMissing->execute([$session['id']]);
                if ((int)$stmtMissing->fetchColumn() > 0) throw new Exception('Ainda existem picks pendentes');

                $pdo->prepare('UPDATE initdraft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')->execute([$session['id']]);
                echo json_encode(['success' => true]);
                break;
            }

            default:
                echo json_encode(['success' => false, 'error' => 'Ação inválida']);
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);

?>
