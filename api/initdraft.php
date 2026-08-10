<?php
/**
 * API de Draft Inicial (initdraft)
 * Separado do draft de temporada. Controlado por token de acesso.
 */

// Define timezone padrão para todo o sistema: São Paulo/Brasília
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/push.php';

header('Content-Type: application/json');

$pdo = db();
ensurePlayerRestrictionColumns($pdo);
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
    // Sem sessão não há o que autorizar: o admin passava direto mesmo com token
    // inválido e o código seguinte acessava $session['id'] num false, soltando
    // warning do PHP no meio do JSON em vez de um erro limpo.
    if (!$session) return false;
    if ($isAdmin) return true;
    return hash_equals($session['access_token'], (string)$token);
}

function ensureDailyScheduleColumns(PDO $pdo): void {
    $table = 'initdraft_sessions';
    $columns = [
        'daily_schedule_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'daily_schedule_start_date' => 'DATE NULL',
        'daily_clock_start_time' => "TIME NOT NULL DEFAULT '19:30:00'",
        'daily_pick_minutes' => 'INT NOT NULL DEFAULT 10',
        'daily_last_opened_date' => 'DATE NULL',
        'daily_override_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        // Trava manual do admin: ninguém escolhe enquanto estiver ligada, nem
        // pelo site, nem por autopick, nem pelo cron do mock.
        'pausado' => 'TINYINT(1) NOT NULL DEFAULT 0',
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

function ensureInitDraftReactionsTable(PDO $pdo): void {
    // Cria a tabela de reações se não existir
    $stmt = $pdo->query("SHOW TABLES LIKE 'initdraft_reactions'");
    if (!$stmt->fetch()) {
        $pdo->exec(
            "CREATE TABLE initdraft_reactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                initdraft_order_id INT NOT NULL,
                user_id INT NOT NULL,
                emoji VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_pick_user (initdraft_order_id, user_id),
                INDEX idx_pick (initdraft_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

function ensureEmojiBinaryCollation(PDO $pdo): void {
    // Garantir que a coluna emoji use collation binária para diferenciar cada emoji
    try {
        $stmt = $pdo->query("SHOW FULL COLUMNS FROM initdraft_reactions LIKE 'emoji'");
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($col && isset($col['Collation']) && strtolower((string)$col['Collation']) !== 'utf8mb4_bin') {
            $pdo->exec("ALTER TABLE initdraft_reactions MODIFY emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
        }
    } catch (Exception $e) {
        // Se tabela ainda não existe, ignore; será criado na chamada anterior
    }
}

function ensureInitDraftMockTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS initdraft_mock_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_id INT NOT NULL,
        initdraft_session_id INT NOT NULL,
        player_id INT NOT NULL,
        priority INT NOT NULL DEFAULT 1,
        KEY idx_tms (team_id, initdraft_session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS initdraft_mock_settings (
        team_id INT NOT NULL,
        initdraft_session_id INT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (team_id, initdraft_session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureInitDraftFavoritesTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS initdraft_favorites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        player_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_player (user_id, player_id),
        INDEX idx_if_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Time do usuário logado (null se não logado ou sem time). Mesmo padrão do mock do draft normal (api/draft-mock.php). */
function getUserTeamId(PDO $pdo, ?array $user): ?int {
    if (!$user || !isset($user['id'])) return null;
    $stmt = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int)$user['id']]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function hasAnyPickMade(PDO $pdo, int $sessionId): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM initdraft_order WHERE initdraft_session_id = ? AND picked_player_id IS NOT NULL LIMIT 1');
    $stmt->execute([$sessionId]);
    return (bool)$stmt->fetchColumn();
}

function persistDraftOrder(PDO $pdo, array $roundOneOrder, array $session): void {
    $roundOneOrder = array_values(array_map('intval', $roundOneOrder));
    $sessionId = (int)$session['id'];
    $totalRounds = (int)$session['total_rounds'];

    if ($totalRounds < 1) {
        throw new InvalidArgumentException('Total de rodadas inválido para esta sessão');
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

require_once __DIR__ . "/../backend/initdraft_pick.php"; // performInitDraftPick + notificacoes


/**
 * Desfaz a última pick completada: devolve o jogador pro pool, remove do
 * elenco do time e volta o ponteiro da sessão pra apontar de novo pra
 * aquele mesmo time/rodada/posição. Usa picked_at (e não round/pick_position)
 * pra achar "a última", porque é o único carimbo que reflete a ordem real em
 * que as picks aconteceram de fato.
 */
function undoLastInitDraftPick(PDO $pdo, array $session): array {
    $stmt = $pdo->prepare('
        SELECT * FROM initdraft_order
        WHERE initdraft_session_id = ? AND picked_player_id IS NOT NULL
        ORDER BY picked_at DESC, round DESC, pick_position DESC
        LIMIT 1
    ');
    $stmt->execute([$session['id']]);
    $pick = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pick) {
        throw new InvalidArgumentException('Nenhuma pick foi feita ainda neste draft.');
    }

    $stmtPlayer = $pdo->prepare('SELECT name FROM initdraft_pool WHERE id = ?');
    $stmtPlayer->execute([$pick['picked_player_id']]);
    $playerName = (string)($stmtPlayer->fetchColumn() ?: 'Jogador');

    $stmtTeam = $pdo->prepare('SELECT city, name FROM teams WHERE id = ?');
    $stmtTeam->execute([$pick['team_id']]);
    $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
    $teamLabel = $team ? trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')) : 'Time';

    ensureInitDraftReactionsTable($pdo);

    try {
        $pdo->beginTransaction();

        $stmtDelPlayer = $pdo->prepare('DELETE FROM players WHERE team_id = ? AND drafted_by_team_id = ? AND draft_round = ? AND draft_pick_position = ?');
        $stmtDelPlayer->execute([$pick['team_id'], $pick['team_id'], $pick['round'], $pick['pick_position']]);
        if ($stmtDelPlayer->rowCount() === 0) {
            // Fallback: alguma pick antiga pode ter ficado sem draft_round/draft_pick_position
            // preenchidos no elenco. Casa pelo nome exato do jogador desta pick, no mesmo
            // time — LIMIT 1 pra nunca apagar mais de um em caso de nome duplicado.
            $pdo->prepare('DELETE FROM players WHERE team_id = ? AND drafted_by_team_id = ? AND name = ? LIMIT 1')
                ->execute([$pick['team_id'], $pick['team_id'], $playerName]);
        }

        $pdo->prepare('UPDATE initdraft_pool SET draft_status = "available", drafted_by_team_id = NULL, draft_order = NULL WHERE id = ?')
            ->execute([$pick['picked_player_id']]);

        $pdo->prepare('UPDATE initdraft_order SET picked_player_id = NULL, picked_at = NULL WHERE id = ?')
            ->execute([$pick['id']]);

        $pdo->prepare('DELETE FROM initdraft_reactions WHERE initdraft_order_id = ?')->execute([$pick['id']]);

        $pdo->prepare("UPDATE initdraft_sessions SET status = 'in_progress', completed_at = NULL, current_round = ?, current_pick = ? WHERE id = ?")
            ->execute([$pick['round'], $pick['pick_position'], $session['id']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'team' => $teamLabel,
        'player' => $playerName,
        'round' => (int)$pick['round'],
        'pick_position' => (int)$pick['pick_position'],
    ];
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

function resetClockForNextPick(PDO $pdo, int $sessionId): void {
    // Sistema antigo (sem relógio): não cria deadline nem agenda auto-pick.
}

function ensureDailyPickWindow(array $session, DateTimeImmutable $now): void {
    // Se override estiver ativo, permite picking imediatamente
    $override = (int)($session['daily_override_enabled'] ?? 0) === 1;
    if ($override) return;

    $enabled = (int)($session['daily_schedule_enabled'] ?? 0) === 1;
    if (!$enabled) return;

    $dailyRound = computeDailyRoundForDate($session['daily_schedule_start_date'] ?? null, $now);
    if (!$dailyRound) {
        throw new InvalidArgumentException('Draft ainda não iniciou (aguarde 19:30)');
    }

    if ($dailyRound > (int)$session['total_rounds']) {
        throw new InvalidArgumentException('Draft diário já encerrou');
    }

    if ((int)($session['current_round'] ?? 1) !== $dailyRound) {
        throw new InvalidArgumentException('Draft pausado até 19:30 do próximo dia');
    }

    $openAfter = new DateTimeImmutable($now->format('Y-m-d') . ' 00:01:00', $now->getTimezone());
    if ($now < $openAfter) {
        throw new InvalidArgumentException('Draft diário inicia às 00:01');
    }
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

    // abre o draft às 00:01 e marca qual dia já foi processado
    $today = $now->format('Y-m-d');
    $openAfter = new DateTimeImmutable($today . ' 00:01:00', $now->getTimezone());
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

    // Se round já terminou, pausa até o próximo dia (1 round por dia)
    if (($session['status'] ?? 'setup') === 'in_progress' && isRoundCompleted($pdo, (int)$session['id'], $dailyRound)) {
        clearDeadlinesForRound($pdo, (int)$session['id'], $dailyRound);
        return $session;
    }

    // Sistema antigo (sem relógio): garantir que não exista deadline aplicado.
    clearDeadlinesForRound($pdo, (int)$session['id'], $dailyRound);
    return $session;
}

// ========== GET ==========
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'state';

    try {
        switch ($action) {
            case 'state': {
                $token = $_GET['token'] ?? null;
                $sessionId = $_GET['id'] ?? null;

                if (!$token && !$sessionId) throw new InvalidArgumentException('token ou id obrigatório');

                if ($token) {
                    $session = getSessionByToken($pdo, $token);
                } elseif ($isAdmin) {
                    $stmt = $pdo->prepare('SELECT * FROM initdraft_sessions WHERE id = ?');
                    $stmt->execute([$sessionId]);
                    $session = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    throw new InvalidArgumentException('Não autorizado');
                }

                if (!$session) throw new InvalidArgumentException('Sessão não encontrada');

                // Aplicar regras do agendamento diário (fallback do cron)
                $session = applyDailySchedule($pdo, $session);

          // Buscar ordem
                $stmtOrder = $pdo->prepare('
                          SELECT io.*, 
                              t.city as team_city, t.name as team_name, t.photo_url as team_photo,
                              u.name as team_owner,
                              dp.name as player_name, dp.position as player_position, dp.ovr as player_ovr, dp.age as player_age
                    FROM initdraft_order io
                    INNER JOIN teams t ON io.team_id = t.id
                    LEFT JOIN users u ON t.user_id = u.id
                    LEFT JOIN initdraft_pool dp ON io.picked_player_id = dp.id
                    WHERE io.initdraft_session_id = ?
                    ORDER BY io.round ASC, io.pick_position ASC
                ');
                $stmtOrder->execute([$session['id']]);
                $order = $stmtOrder->fetchAll(PDO::FETCH_ASSOC);

                // Anexar reações por pick
                ensureInitDraftReactionsTable($pdo);
                ensureEmojiBinaryCollation($pdo);
                $pickIds = array_map(fn($o) => (int)$o['id'], $order);
                $reactionsByPick = [];
                $mineByPick = [];
                if (!empty($pickIds)) {
                    // Aggregates por emoji
                    $placeholders = implode(',', array_fill(0, count($pickIds), '?'));
                    $stmtAgg = $pdo->prepare("SELECT initdraft_order_id, emoji, COUNT(*) AS total FROM initdraft_reactions WHERE initdraft_order_id IN ($placeholders) GROUP BY initdraft_order_id, emoji");
                    $stmtAgg->execute($pickIds);
                    while ($row = $stmtAgg->fetch(PDO::FETCH_ASSOC)) {
                        $pid = (int)$row['initdraft_order_id'];
                        if (!isset($reactionsByPick[$pid])) $reactionsByPick[$pid] = [];
                        $reactionsByPick[$pid][] = [
                            'emoji' => $row['emoji'],
                            'count' => (int)$row['total'],
                            'mine' => false,
                        ];
                    }

                    // Reação do usuário atual (se logado)
                    if ($user && isset($user['id'])) {
                        $stmtMine = $pdo->prepare("SELECT initdraft_order_id, emoji FROM initdraft_reactions WHERE user_id = ? AND initdraft_order_id IN ($placeholders)");
                        $params = array_merge([$user['id']], $pickIds);
                        $stmtMine->execute($params);
                        while ($row = $stmtMine->fetch(PDO::FETCH_ASSOC)) {
                            $mineByPick[(int)$row['initdraft_order_id']] = (string)$row['emoji'];
                        }
                    }
                }

                // Atribuir reações em cada item da ordem
                foreach ($order as &$o) {
                    $pid = (int)$o['id'];
                    $list = $reactionsByPick[$pid] ?? [];
                    $mineEmoji = $mineByPick[$pid] ?? null;
                    if ($mineEmoji) {
                        foreach ($list as &$it) {
                            if ($it['emoji'] === $mineEmoji) {
                                $it['mine'] = true;
                            }
                        }
                    }
                    $o['reactions'] = $list;
                }
                unset($o);

                // Buscar todos os times elegíveis da liga
                $stmtTeams = $pdo->prepare('SELECT t.id, t.city, t.name, t.photo_url, u.name AS owner_name FROM teams t LEFT JOIN users u ON t.user_id = u.id WHERE t.league = ? ORDER BY t.name ASC');
                $stmtTeams->execute([$session['league']]);
                $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

                $hasPicks = hasAnyPickMade($pdo, (int)$session['id']);
                $canEditOrder = !$hasPicks && ($session['status'] ?? 'setup') !== 'completed';

                echo json_encode([
                    'success' => true,
                    'session' => $session,
                    'order' => $order,
                    'teams' => $teams,
                    'can_edit_order' => $canEditOrder,
                    'has_picks' => $hasPicks,
                ]);
                break;
            }

            case 'session_for_season': {
                $seasonId = (int)($_GET['season_id'] ?? 0);
                if (!$seasonId) throw new InvalidArgumentException('season_id obrigatório');

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

                if (!$session) throw new InvalidArgumentException('Sessão não encontrada');

                $stmt = $pdo->prepare('SELECT * FROM initdraft_pool WHERE season_id = ? AND draft_status = "available" ORDER BY ovr DESC, name ASC');
                $stmt->execute([$session['season_id']]);
                $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'players' => $players]);
                break;
            }
            case 'pool': {
                $token = $_GET['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!$session) throw new InvalidArgumentException('Sessão não encontrada');

                $stmt = $pdo->prepare('SELECT * FROM initdraft_pool WHERE season_id = ? ORDER BY draft_status ASC, ovr DESC, name ASC');
                $stmt->execute([$session['season_id']]);
                $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Marca favorito do usuário logado — a aba Favoritos é só um filtro
                // client-side de state.pool, então isto evita uma segunda ida ao servidor.
                if ($user && isset($user['id']) && $players) {
                    ensureInitDraftFavoritesTable($pdo);
                    $stmtFav = $pdo->prepare('SELECT player_id FROM initdraft_favorites WHERE user_id = ?');
                    $stmtFav->execute([(int)$user['id']]);
                    $favIds = array_flip(array_map('intval', $stmtFav->fetchAll(PDO::FETCH_COLUMN)));
                    foreach ($players as &$p) {
                        $p['is_favorite'] = isset($favIds[(int)$p['id']]) ? 1 : 0;
                    }
                    unset($p);
                } else {
                    foreach ($players as &$p) { $p['is_favorite'] = 0; }
                    unset($p);
                }

                echo json_encode(['success' => true, 'players' => $players]);
                break;
            }

            // Baixa o pool inteiro em CSV. Era gerado no client via Blob — funcionava
            // no desktop, mas navegadores in-app de celular (WhatsApp, Instagram) e o
            // Safari do iOS costumam ignorar/bloquear download por Blob. Um endpoint
            // que devolve o arquivo direto com Content-Disposition funciona em
            // qualquer navegador, sem depender de JS pra montar o download.
            case 'export_csv': {
                $token = $_GET['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!$session) throw new InvalidArgumentException('Sessão não encontrada');

                $stmt = $pdo->prepare('SELECT * FROM initdraft_pool WHERE season_id = ? ORDER BY draft_status ASC, ovr DESC, name ASC');
                $stmt->execute([$session['season_id']]);
                $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmtOrder = $pdo->prepare('
                    SELECT io.picked_player_id, io.round, io.pick_position,
                        t.city AS team_city, t.name AS team_name
                    FROM initdraft_order io
                    INNER JOIN teams t ON t.id = io.team_id
                    WHERE io.initdraft_session_id = ? AND io.picked_player_id IS NOT NULL
                ');
                $stmtOrder->execute([$session['id']]);
                $escolhaPorJogador = [];
                foreach ($stmtOrder->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $escolhaPorJogador[(int)$row['picked_player_id']] = [
                        'time' => trim(($row['team_city'] ?? '') . ' ' . ($row['team_name'] ?? '')),
                        'rodada' => $row['round'],
                        'pick' => $row['pick_position'],
                    ];
                }

                $campo = function ($v) {
                    return '"' . str_replace('"', '""', (string)($v ?? '')) . '"';
                };

                $linhas = [implode(',', ['Nome', 'Posicao', 'Posicao2', 'Idade', 'OVR', 'Status', 'Draftado por', 'Rodada', 'Pick'])];
                foreach ($players as $j) {
                    $e = $escolhaPorJogador[(int)$j['id']] ?? ['time' => '', 'rodada' => '', 'pick' => ''];
                    $linhas[] = implode(',', [
                        $campo($j['name']), $campo($j['position']), $campo($j['secondary_position'] ?? ''),
                        $campo($j['age']), $campo($j['ovr']),
                        $campo($j['draft_status'] === 'drafted' ? 'Draftado' : 'Disponivel'),
                        $campo($e['time']), $campo($e['rodada']), $campo($e['pick']),
                    ]);
                }

                // BOM no começo: sem ele o Excel no Windows abre nome acentuado errado. "sep=,"
                // como 1ª linha força o Excel a usar vírgula mesmo em config regional PT-BR (que
                // usa ";" por padrão) — sem isso ele jogava tudo numa coluna só ao abrir direto.
                $csv = "\xEF\xBB\xBFsep=,\r\n" . implode("\r\n", $linhas);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="draft-inicial-pool-' . date('Y-m-d') . '.csv"');
                header('Content-Length: ' . strlen($csv));
                echo $csv;
                exit;
            }

            case 'mock_get': {
                $token = $_GET['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!$session) throw new InvalidArgumentException('Sessão não encontrada');
                ensureInitDraftMockTables($pdo);

                $teamId = getUserTeamId($pdo, $user);
                if (!$teamId) {
                    echo json_encode(['success' => true, 'queue' => [], 'is_active' => false]);
                    break;
                }

                $stmt = $pdo->prepare('
                    SELECT mq.id, mq.player_id, mq.priority,
                           ip.name AS player_name, ip.position AS player_position,
                           ip.ovr AS player_ovr, ip.age AS player_age, ip.draft_status
                    FROM initdraft_mock_queue mq
                    JOIN initdraft_pool ip ON mq.player_id = ip.id
                    WHERE mq.team_id = ? AND mq.initdraft_session_id = ?
                    ORDER BY mq.priority ASC
                ');
                $stmt->execute([$teamId, $session['id']]);
                $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmtS = $pdo->prepare('SELECT is_active FROM initdraft_mock_settings WHERE team_id = ? AND initdraft_session_id = ?');
                $stmtS->execute([$teamId, $session['id']]);
                $settings = $stmtS->fetch(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success'   => true,
                    'queue'     => $queue,
                    'is_active' => (bool)($settings['is_active'] ?? false),
                ]);
                break;
            }

            // Chamada pelo poll da própria tela (a cada ~10s): não depende de quem
            // está com a página aberta ser o time da vez — qualquer visitante com o
            // token (inclusive o admin acompanhando) pode disparar o autopick de
            // QUALQUER time, porque quem decide se ele acontece é a config daquele
            // time, não de quem chamou. Mesmo modelo do check_autopick do draft normal.
            case 'check_autopick': {
                $token = $_GET['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!$session) { echo json_encode(['success' => true, 'autopicked' => false, 'reason' => 'invalid_session']); break; }
                ensureInitDraftMockTables($pdo);

                $session = applyDailySchedule($pdo, $session);
                if (($session['status'] ?? '') !== 'in_progress') {
                    echo json_encode(['success' => true, 'autopicked' => false, 'reason' => 'not_in_progress']);
                    break;
                }

                try {
                    ensureDailyPickWindow($session, tzNow());
                } catch (InvalidArgumentException $e) {
                    // Rodada agendada pro dia ainda não abriu — nada de autopick antes disso.
                    echo json_encode(['success' => true, 'autopicked' => false, 'reason' => 'window_closed']);
                    break;
                }

                $stmtPick = $pdo->prepare('SELECT * FROM initdraft_order WHERE initdraft_session_id = ? AND picked_player_id IS NULL ORDER BY round ASC, pick_position ASC LIMIT 1');
                $stmtPick->execute([$session['id']]);
                $currentPick = $stmtPick->fetch(PDO::FETCH_ASSOC);
                if (!$currentPick) {
                    echo json_encode(['success' => true, 'autopicked' => false, 'reason' => 'no_pending_pick']);
                    break;
                }

                $currentTeamId = (int)$currentPick['team_id'];

                $stmtSettings = $pdo->prepare('SELECT is_active FROM initdraft_mock_settings WHERE team_id = ? AND initdraft_session_id = ?');
                $stmtSettings->execute([$currentTeamId, $session['id']]);
                $settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);
                if (empty($settings['is_active'])) {
                    echo json_encode(['success' => true, 'autopicked' => false, 'reason' => 'mock_inactive']);
                    break;
                }

                $stmtQueue = $pdo->prepare("
                    SELECT mq.player_id FROM initdraft_mock_queue mq
                    JOIN initdraft_pool ip ON ip.id = mq.player_id
                    WHERE mq.team_id = ? AND mq.initdraft_session_id = ? AND ip.draft_status = 'available'
                    ORDER BY mq.priority ASC LIMIT 1
                ");
                $stmtQueue->execute([$currentTeamId, $session['id']]);
                $playerId = (int)($stmtQueue->fetchColumn() ?: 0);
                if (!$playerId) {
                    echo json_encode(['success' => true, 'autopicked' => false, 'reason' => 'empty_queue']);
                    break;
                }

                // Reaproveita a MESMA função que make_pick/admin_make_pick usam — trava
                // atômica, inserção no elenco, avanço de pick e notificação do próximo
                // time saem todos daqui, sem duplicar nenhuma dessas regras.
                performInitDraftPick($pdo, $session, $playerId);
                resetClockForNextPick($pdo, (int)$session['id']);

                echo json_encode(['success' => true, 'autopicked' => true, 'player_id' => $playerId]);
                break;
            }

            default:
                echo json_encode(['success' => false, 'error' => 'Ação inválida']);
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[api/initdraft.php][GET] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']);
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
                if (!$isAdmin) throw new InvalidArgumentException('Apenas administradores');

                $seasonId = (int)($data['season_id'] ?? 0);
                if (!$seasonId) throw new InvalidArgumentException('season_id obrigatório');

                // Buscar liga da temporada
                $stmtS = $pdo->prepare('SELECT league FROM seasons WHERE id = ?');
                $stmtS->execute([$seasonId]);
                $season = $stmtS->fetch(PDO::FETCH_ASSOC);
                if (!$season) throw new InvalidArgumentException('Temporada não encontrada');

                // Verifica se já existe
                $stmtChk = $pdo->prepare('SELECT id FROM initdraft_sessions WHERE season_id = ?');
                $stmtChk->execute([$seasonId]);
                if ($stmtChk->fetch()) throw new InvalidArgumentException('Já existe uma sessão de initdraft para esta temporada');

                $totalRounds = (int)($data['total_rounds'] ?? 5);
                if ($totalRounds < 1) $totalRounds = 1; if ($totalRounds > 10) $totalRounds = 10;
                $token = randomToken(32);

                $stmtIns = $pdo->prepare('INSERT INTO initdraft_sessions (season_id, league, total_rounds, access_token) VALUES (?, ?, ?, ?)');
                $stmtIns->execute([$seasonId, $season['league'], $totalRounds, $token]);

                echo json_encode(['success' => true, 'initdraft_session_id' => $pdo->lastInsertId(), 'token' => $token]);
                break;
            }

            // ADMIN/TOKEN: adicionar um jogador manualmente
            case 'add_player': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new InvalidArgumentException('Não autorizado');

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
                if (!ensureAdminOrToken($session, $token)) throw new InvalidArgumentException('Não autorizado');
                // Mesmo escopo do delete_player: setup + draft em andamento, só
                // trava depois de finalizado (quem já foi draftado nunca edita, já
                // garantido pela query abaixo).
                if ($session['status'] === 'completed') throw new InvalidArgumentException('O draft já foi finalizado.');

                $playerId = (int)($data['player_id'] ?? 0);
                if (!$playerId) throw new InvalidArgumentException('player_id obrigatório');

                // Verificar se o jogador existe e não foi draftado
                $stmt = $pdo->prepare('SELECT id FROM initdraft_pool WHERE id = ? AND season_id = ? AND draft_status = "available"');
                $stmt->execute([$playerId, $session['season_id']]);
                if (!$stmt->fetch()) throw new InvalidArgumentException('Jogador não encontrado ou já foi draftado');

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
                if (!ensureAdminOrToken($session, $token)) throw new InvalidArgumentException('Não autorizado');
                // Vale durante setup E com o draft em andamento — o que nunca pode
                // é sumir com quem já foi escolhido (garantido pelo draft_status
                // "available" da query abaixo). Só trava depois de finalizado.
                if ($session['status'] === 'completed') throw new InvalidArgumentException('O draft já foi finalizado.');

                $playerId = (int)($data['player_id'] ?? 0);
                if (!$playerId) throw new InvalidArgumentException('player_id obrigatório');

                $stmt = $pdo->prepare('SELECT id FROM initdraft_pool WHERE id = ? AND season_id = ? AND draft_status = "available"');
                $stmt->execute([$playerId, $session['season_id']]);
                if (!$stmt->fetch()) throw new InvalidArgumentException('Jogador não pode ser removido');

                $pdo->prepare('DELETE FROM initdraft_pool WHERE id = ?')->execute([$playerId]);

                echo json_encode(['success' => true]);
                break;
            }

            // ADMIN/TOKEN: esvaziar o pool inteiro da temporada.
            // Serve pra recomeçar quando o CSV veio errado — por isso só roda
            // em setup e só apaga quem ainda está disponível: jogador já
            // escolhido não pode sumir de baixo de uma pick registrada.
            case 'clear_pool': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new InvalidArgumentException('Não autorizado');
                if ($session['status'] !== 'setup') throw new InvalidArgumentException('Só é possível limpar o pool durante o setup');

                $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM initdraft_pool WHERE season_id = ? AND draft_status <> "available"');
                $stmtCount->execute([$session['season_id']]);
                $jaEscolhidos = (int)$stmtCount->fetchColumn();

                $stmtDel = $pdo->prepare('DELETE FROM initdraft_pool WHERE season_id = ? AND draft_status = "available"');
                $stmtDel->execute([$session['season_id']]);
                $removidos = $stmtDel->rowCount();

                echo json_encode([
                    'success'   => true,
                    'removidos' => $removidos,
                    'mantidos'  => $jaEscolhidos,
                ]);
                break;
            }

            // ADMIN/TOKEN: importar via CSV (multipart/form-data)
            case 'import_csv': {
                $token = $_POST['token'] ?? ($data['token'] ?? null);
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new InvalidArgumentException('Não autorizado');

                if (!isset($_FILES['csv_file'])) throw new InvalidArgumentException('Arquivo CSV obrigatório');
                $file = $_FILES['csv_file'];
                if ($file['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Falha no upload');
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext !== 'csv') throw new InvalidArgumentException('Arquivo deve ser CSV');

                $handle = fopen($file['tmp_name'], 'r');
                if (!$handle) throw new InvalidArgumentException('Não foi possível ler o arquivo');

                // Se o CSV veio do nosso próprio export_csv, a 1ª linha é a diretiva "sep=," (só
                // serve pro Excel abrir com vírgula em config regional PT-BR) — não é dado nem
                // cabeçalho, então pula ela antes de detectar o resto.
                $posDados = 0;
                $primeiraLinha = fgets($handle);
                if ($primeiraLinha !== false && trim($primeiraLinha, "\xEF\xBB\xBF\r\n ") === 'sep=,') {
                    $posDados = ftell($handle);
                } else {
                    rewind($handle);
                }

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
                        // volta para o início dos dados (não do arquivo — se tinha "sep=,", já foi consumida)
                        fseek($handle, $posDados);
                    }
                }

                // Nome repetido não vira jogador duplicado no pool — nem repetido
                // dentro do próprio CSV, nem repetindo alguém que já está na pool
                // (de uma importação anterior). Fica só a primeira ocorrência; as
                // outras linhas do CSV continuam sendo criadas normalmente.
                $stmtExistentes = $pdo->prepare('SELECT name FROM initdraft_pool WHERE season_id = ?');
                $stmtExistentes->execute([$session['season_id']]);
                $nomesVistos = array_fill_keys(
                    array_map(fn($n) => mb_strtolower(trim($n)), $stmtExistentes->fetchAll(PDO::FETCH_COLUMN)),
                    true
                );

                $stmt = $pdo->prepare('INSERT INTO initdraft_pool (season_id, name, position, age, ovr) VALUES (?, ?, ?, ?, ?)');
                $inserted = 0;
                $duplicados = 0;
                while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                    $name = trim($row[$map['name']] ?? '');
                    if ($name === '') continue;
                    $chave = mb_strtolower($name);
                    if (isset($nomesVistos[$chave])) {
                        $duplicados++;
                        continue;
                    }
                    $nomesVistos[$chave] = true;

                    $position = strtoupper(trim($row[$map['position']] ?? 'SF'));
                    if (!in_array($position, ['PG','SG','SF','PF','C'])) $position = 'SF';
                    $age = (int)($row[$map['age']] ?? 20);
                    $ovr = (int)($row[$map['ovr']] ?? 70);
                    $stmt->execute([$session['season_id'], $name, $position, $age, $ovr]);
                    $inserted++;
                }
                fclose($handle);

                echo json_encode(['success' => true, 'imported' => $inserted, 'duplicados' => $duplicados]);
                break;
            }

            // ADMIN/TOKEN: randomizar ordem (primeiro sorteado = último da 1ª rodada)
            case 'randomize_order': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new InvalidArgumentException('Não autorizado');
                if (($session['status'] ?? 'setup') === 'completed') throw new InvalidArgumentException('Draft já finalizado');

                $hasPicks = hasAnyPickMade($pdo, (int)$session['id']);
                if ($hasPicks) throw new InvalidArgumentException('Não é possível alterar a ordem após a primeira pick');

                // Buscar times da liga
                $stmtTeams = $pdo->prepare('SELECT t.id, t.city, t.name, t.photo_url, u.name AS owner_name FROM teams t LEFT JOIN users u ON t.user_id = u.id WHERE t.league = ? ORDER BY t.name ASC');
                $stmtTeams->execute([$session['league']]);
                $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);
                if (!$teams) throw new InvalidArgumentException('Sem times na liga');

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
                if (!ensureAdminOrToken($session, $token)) throw new InvalidArgumentException('Não autorizado');
                if (($session['status'] ?? 'setup') === 'completed') throw new InvalidArgumentException('Draft já finalizado');

                $hasPicks = hasAnyPickMade($pdo, (int)$session['id']);
                if ($hasPicks) throw new InvalidArgumentException('Não é possível alterar a ordem após a primeira pick');

                $teamIds = $data['team_ids'] ?? [];
                if (!is_array($teamIds) || count($teamIds) === 0) throw new InvalidArgumentException('Informe a ordem completa dos times');
                $teamIds = array_values(array_map('intval', $teamIds));

                $stmtTeams = $pdo->prepare('SELECT id FROM teams WHERE league = ? ORDER BY id ASC');
                $stmtTeams->execute([$session['league']]);
                // O PDO devolve os ids como TEXTO. Sem o intval aqui, a
                // comparação estrita lá embaixo (['1','2'] !== [1,2]) dava
                // errado sempre — e "Salvar ordem" nunca funcionava.
                $leagueTeams = array_map('intval', $stmtTeams->fetchAll(PDO::FETCH_COLUMN));
                if (!$leagueTeams) throw new InvalidArgumentException('Sem times cadastrados para a liga');

                sort($leagueTeams);
                $sortedInput = $teamIds;
                sort($sortedInput);
                if ($leagueTeams !== $sortedInput) {
                    throw new InvalidArgumentException('A ordem precisa incluir todos os times da liga exatamente uma vez');
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
                if (!ensureAdminOrToken($session, $token)) throw new InvalidArgumentException('Não autorizado');
                if (($session['status'] ?? 'setup') === 'completed') throw new InvalidArgumentException('Draft já finalizado');

                $hasPicks = hasAnyPickMade($pdo, (int)$session['id']);
                if ($hasPicks) throw new InvalidArgumentException('Não é possível alterar rodadas após a primeira pick');

                $totalRounds = (int)($data['total_rounds'] ?? 0);
                if ($totalRounds < 1 || $totalRounds > 10) {
                    throw new InvalidArgumentException('Informe um número de rodadas entre 1 e 10');
                }

                // Atualizar total_rounds na sessão
                $pdo->prepare('UPDATE initdraft_sessions SET total_rounds = ? WHERE id = ?')
                    ->execute([$totalRounds, $session['id']]);

                // Atualizar session array para retornar valor atualizado
                $session['total_rounds'] = $totalRounds;

                // Se a ordem JÁ tinha sido sorteada, acertar a tabela de picks.
                // Antes isto só trocava o contador: a sessão passava a dizer "8
                // rodadas" enquanto a tabela continuava com 5, e o draft acabava
                // cedo sem ninguém entender.
                //
                // Acrescento ou removo rodadas no fim, em vez de chamar
                // persistDraftOrder: aquela função apaga tudo e recria com
                // team_id = original_team_id, o que apagaria qualquer pick que
                // já tivesse sido trocada. Pick trocada antes do draft começar é
                // caso real, e ninguém ia perceber a perda até a vez chegar no
                // time errado.
                $stmtReal = $pdo->prepare('SELECT COALESCE(MAX(round), 0) FROM initdraft_order WHERE initdraft_session_id = ?');
                $stmtReal->execute([(int)$session['id']]);
                $real = (int)$stmtReal->fetchColumn();
                $ajuste = 'nenhum';

                if ($real > 0 && $totalRounds !== $real) {
                    $stmtBase = $pdo->prepare('SELECT original_team_id FROM initdraft_order
                                               WHERE initdraft_session_id = ? AND round = 1
                                               ORDER BY pick_position ASC');
                    $stmtBase->execute([(int)$session['id']]);
                    $base = array_map('intval', $stmtBase->fetchAll(PDO::FETCH_COLUMN));

                    if ($totalRounds > $real && $base) {
                        $ins = $pdo->prepare('INSERT INTO initdraft_order
                            (initdraft_session_id, team_id, original_team_id, pick_position, round)
                            VALUES (?, ?, ?, ?, ?)');
                        for ($r = $real + 1; $r <= $totalRounds; $r++) {
                            $ordem = ($r % 2 === 1) ? $base : array_reverse($base);
                            foreach ($ordem as $i => $teamId) {
                                $ins->execute([(int)$session['id'], $teamId, $teamId, $i + 1, $r]);
                            }
                        }
                        $ajuste = 'rodadas_criadas';
                    } elseif ($totalRounds < $real) {
                        $pdo->prepare('DELETE FROM initdraft_order WHERE initdraft_session_id = ? AND round > ?')
                            ->execute([(int)$session['id'], $totalRounds]);
                        $ajuste = 'rodadas_removidas';
                    }
                }

                echo json_encode(['success' => true, 'total_rounds' => $totalRounds, 'ajuste' => $ajuste]);
                break;
            }

            case 'set_daily_schedule': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new InvalidArgumentException('Não autorizado');
                if ($session['status'] !== 'setup') throw new InvalidArgumentException('Só é possível configurar o agendamento durante setup');

                ensureDailyScheduleColumns($pdo);

                $enabled = (int)($data['enabled'] ?? 0) === 1 ? 1 : 0;
                $startDate = trim((string)($data['start_date'] ?? ''));
                if ($enabled && !$startDate) {
                    throw new InvalidArgumentException('Informe a data de início');
                }
                if ($startDate) {
                    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $startDate, new DateTimeZone('America/Sao_Paulo'));
                    if (!$dt || $dt->format('Y-m-d') !== $startDate) {
                        throw new InvalidArgumentException('Data inválida (use YYYY-MM-DD)');
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
                if (!ensureAdminOrToken($session, $token)) throw new InvalidArgumentException('Não autorizado');
                if (($session['status'] ?? 'setup') === 'completed') throw new InvalidArgumentException('Draft já finalizado — não pode ser reiniciado.');

                // Precisa ter ordem
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ?');
                $stmt->execute([$session['id']]);
                if ((int)$stmt->fetchColumn() === 0) throw new InvalidArgumentException('Defina a ordem antes de iniciar');

                $pdo->prepare('UPDATE initdraft_sessions SET status = "in_progress", started_at = NOW() WHERE id = ?')->execute([$session['id']]);

                // Avisa o dono da 1ª pick — dali em diante quem avisa é performInitDraftPick.
                $stmtFirst = $pdo->prepare('SELECT team_id, round, pick_position FROM initdraft_order WHERE initdraft_session_id = ? AND picked_player_id IS NULL ORDER BY round ASC, pick_position ASC LIMIT 1');
                $stmtFirst->execute([$session['id']]);
                $firstPick = $stmtFirst->fetch(PDO::FETCH_ASSOC);
                if ($firstPick) {
                    notificarInitDraftVez($pdo, $session, (int)$firstPick['team_id'], (int)$firstPick['round'], (int)$firstPick['pick_position']);
                }

                echo json_encode(['success' => true]);
                break;
            }

            // Reagir a uma pick com emoji
            case 'react_pick': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!$session) throw new InvalidArgumentException('Sessão inválida');
                if (!$user || !isset($user['id'])) throw new InvalidArgumentException('Faça login para reagir');

                ensureInitDraftReactionsTable($pdo);
                ensureEmojiBinaryCollation($pdo);

                $pickId = (int)($data['pick_id'] ?? 0);
                $emoji = trim((string)($data['emoji'] ?? ''));
                if ($pickId <= 0 || $emoji === '') throw new InvalidArgumentException('pick_id e emoji obrigatórios');
                // Confirma que a pick pertence à sessão
                $stmtChk = $pdo->prepare('SELECT id FROM initdraft_order WHERE id = ? AND initdraft_session_id = ?');
                $stmtChk->execute([$pickId, $session['id']]);
                if (!$stmtChk->fetch()) throw new InvalidArgumentException('Pick inválida');

                // Limita tamanho do emoji
                if (strlen($emoji) > 16) $emoji = substr($emoji, 0, 16);

                // Upsert (um por usuário/pick)
                $stmtIns = $pdo->prepare('INSERT INTO initdraft_reactions (initdraft_order_id, user_id, emoji, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), updated_at = NOW()');
                $stmtIns->execute([$pickId, $user['id'], $emoji]);

                // Retorna agregados da pick
                $stmtAgg = $pdo->prepare('SELECT emoji, COUNT(*) AS total FROM initdraft_reactions WHERE initdraft_order_id = ? GROUP BY emoji');
                $stmtAgg->execute([$pickId]);
                $agg = $stmtAgg->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'pick_id' => $pickId, 'reactions' => $agg]);
                break;
            }

            case 'remove_reaction': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!$session) throw new InvalidArgumentException('Sessão inválida');
                if (!$user || !isset($user['id'])) throw new InvalidArgumentException('Faça login para reagir');

                ensureInitDraftReactionsTable($pdo);
                ensureEmojiBinaryCollation($pdo);

                $pickId = (int)($data['pick_id'] ?? 0);
                if ($pickId <= 0) throw new InvalidArgumentException('pick_id obrigatório');
                $stmtChk = $pdo->prepare('SELECT id FROM initdraft_order WHERE id = ? AND initdraft_session_id = ?');
                $stmtChk->execute([$pickId, $session['id']]);
                if (!$stmtChk->fetch()) throw new InvalidArgumentException('Pick inválida');

                $pdo->prepare('DELETE FROM initdraft_reactions WHERE initdraft_order_id = ? AND user_id = ?')->execute([$pickId, $user['id']]);

                $stmtAgg = $pdo->prepare('SELECT emoji, COUNT(*) AS total FROM initdraft_reactions WHERE initdraft_order_id = ? GROUP BY emoji');
                $stmtAgg->execute([$pickId]);
                $agg = $stmtAgg->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'pick_id' => $pickId, 'reactions' => $agg]);
                break;
            }

            // TOKEN: fazer pick na posição corrente — exclusivo do GM do time na
            // vez, sem exceção pra admin (geral ou de liga). Admin escolhe pelo
            // Painel Admin (action admin_make_pick), não por aqui. O token é
            // compartilhado com toda a liga pra acompanhar o draft, então NÃO
            // pode ser a única barreira.
            case 'make_pick': {
                $token = $data['token'] ?? null;
                $playerId = (int)($data['player_id'] ?? 0);
                $session = getSessionByToken($pdo, $token);
                if (!$session) throw new InvalidArgumentException('Sessão inválida');

                $stmtCur = $pdo->prepare('SELECT team_id FROM initdraft_order WHERE initdraft_session_id = ? AND round = ? AND pick_position = ? AND picked_player_id IS NULL');
                $stmtCur->execute([$session['id'], (int)($session['current_round'] ?? 1), (int)($session['current_pick'] ?? 1)]);
                $currentTeamId = (int)($stmtCur->fetchColumn() ?: 0);
                if (!$currentTeamId) {
                    $stmtCur = $pdo->prepare('SELECT team_id FROM initdraft_order WHERE initdraft_session_id = ? AND picked_player_id IS NULL ORDER BY round ASC, pick_position ASC LIMIT 1');
                    $stmtCur->execute([$session['id']]);
                    $currentTeamId = (int)($stmtCur->fetchColumn() ?: 0);
                }

                $myTeamId = 0;
                if ($user && isset($user['id'])) {
                    $stmtMyTeam = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? AND league = ? LIMIT 1');
                    $stmtMyTeam->execute([$user['id'], $session['league']]);
                    $myTeamId = (int)($stmtMyTeam->fetchColumn() ?: 0);
                }

                if (!$currentTeamId || !$myTeamId || $myTeamId !== $currentTeamId) {
                    throw new InvalidArgumentException('Não é a sua vez de escolher.');
                }

                ensureDailyPickWindow($session, tzNow());

                performInitDraftPick($pdo, $session, $playerId);
                resetClockForNextPick($pdo, (int)$session['id']);
                echo json_encode(['success' => true, 'message' => 'Pick realizada']);
                break;
            }

            // ADMIN: trava o draft. Ninguém escolhe enquanto estiver pausado —
            // nem pelo site, nem por autopick, nem pelo cron do mock. Serve pra
            // quando aparece confusão de ordem ou troca no meio do draft.
            case 'toggle_pausa': {
                if (!$isAdmin) throw new InvalidArgumentException('Apenas administradores');
                $sessionId = (int)($data['session_id'] ?? 0);
                $session = getSessionById($pdo, $sessionId);
                if (!$session) throw new InvalidArgumentException('Sessão inválida');

                $novo = array_key_exists('pausado', $data)
                    ? (int)!empty($data['pausado'])
                    : (int)empty($session['pausado']);   // sem valor: alterna

                $pdo->prepare('UPDATE initdraft_sessions SET pausado = ? WHERE id = ?')
                    ->execute([$novo, $sessionId]);

                echo json_encode(['success' => true, 'pausado' => (bool)$novo]);
                break;
            }

            // ADMIN: acrescenta UMA rodada ao fim do draft, já com o chaveamento
            // certo. Diferente de set_total_rounds, que só vale antes da
            // primeira pick — este existe pra usar com o draft rolando.
            case 'admin_add_round': {
                if (!$isAdmin) throw new InvalidArgumentException('Apenas administradores');
                $sessionId = (int)($data['session_id'] ?? 0);
                $session = getSessionById($pdo, $sessionId);
                if (!$session) throw new InvalidArgumentException('Sessão inválida');
                if (($session['status'] ?? '') === 'completed') throw new InvalidArgumentException('Draft já finalizado');

                // O que vale é a rodada que EXISTE na tabela de picks, não o
                // total_rounds da sessão — os dois podem discordar (o
                // set_total_rounds antigo mexia só no contador). Quando o
                // contador está à frente, este botão preenche o que falta em vez
                // de criar uma rodada solta lá na frente.
                $stmtReal = $pdo->prepare('SELECT COALESCE(MAX(round), 0) FROM initdraft_order WHERE initdraft_session_id = ?');
                $stmtReal->execute([$sessionId]);
                $real = (int)$stmtReal->fetchColumn();
                $configurado = (int)($session['total_rounds'] ?? 0);

                if ($real >= 10) throw new InvalidArgumentException('Máximo de 10 rodadas');

                // Contador à frente = tem buraco pra tapar; senão, mais uma.
                $alvo = $configurado > $real ? min($configurado, 10) : $real + 1;

                // A ordem base é a da 1ª rodada, pelo original_team_id: a rodada
                // nova é de picks que ninguém trocou ainda, então ela pertence ao
                // dono original, não a quem ficou com a pick de outro ano.
                $stmtBase = $pdo->prepare('SELECT original_team_id FROM initdraft_order
                                           WHERE initdraft_session_id = ? AND round = 1
                                           ORDER BY pick_position ASC');
                $stmtBase->execute([$sessionId]);
                $base = array_map('intval', $stmtBase->fetchAll(PDO::FETCH_COLUMN));
                if (!$base) throw new InvalidArgumentException('A ordem do draft ainda não foi sorteada');

                $criadas = 0;
                $pdo->beginTransaction();
                try {
                    $ins = $pdo->prepare('INSERT INTO initdraft_order
                        (initdraft_session_id, team_id, original_team_id, pick_position, round)
                        VALUES (?, ?, ?, ?, ?)');
                    for ($r = $real + 1; $r <= $alvo; $r++) {
                        // Mesmo chaveamento do resto: ímpar na ordem, par invertida.
                        $ordem = ($r % 2 === 1) ? $base : array_reverse($base);
                        foreach ($ordem as $i => $teamId) {
                            $ins->execute([$sessionId, $teamId, $teamId, $i + 1, $r]);
                            $criadas++;
                        }
                    }
                    $pdo->prepare('UPDATE initdraft_sessions SET total_rounds = ? WHERE id = ?')
                        ->execute([$alvo, $sessionId]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                echo json_encode([
                    'success' => true,
                    'total_rounds' => $alvo,
                    'rodadas_criadas' => $alvo - $real,
                    'picks_criadas' => $criadas,
                    'completou_buraco' => $configurado > $real,
                ]);
                break;
            }

            // ADMIN: desfaz a última pick — devolve o jogador pro pool e volta
            // o ponteiro da sessão pro time que escolheu, pra ele escolher de novo.
            case 'admin_undo_last_pick': {
                if (!$isAdmin) throw new InvalidArgumentException('Apenas administradores');
                $sessionId = (int)($data['session_id'] ?? 0);
                $session = getSessionById($pdo, $sessionId);
                if (!$session) throw new InvalidArgumentException('Sessão inválida');

                $undone = undoLastInitDraftPick($pdo, $session);
                echo json_encode(['success' => true] + $undone);
                break;
            }

            case 'admin_make_pick': {
                if (!$isAdmin) throw new InvalidArgumentException('Apenas administradores');
                $sessionId = (int)($data['session_id'] ?? 0);
                $playerId = (int)($data['player_id'] ?? 0);
                $session = getSessionById($pdo, $sessionId);
                if (!$session) throw new InvalidArgumentException('Sessão inválida');

                ensureDailyPickWindow($session, tzNow());

                performInitDraftPick($pdo, $session, $playerId);
                resetClockForNextPick($pdo, (int)$session['id']);
                echo json_encode(['success' => true, 'message' => 'Pick realizada pelo admin']);
                break;
            }

            // ADMIN: abrir rodada imediatamente (sem aguardar virada do dia)
            case 'admin_open_next_round_now': {
                if (!$isAdmin) throw new InvalidArgumentException('Apenas administradores');
                $sessionId = (int)($data['session_id'] ?? 0);
                $session = getSessionById($pdo, $sessionId);
                if (!$session) throw new InvalidArgumentException('Sessão inválida');

                ensureDailyScheduleColumns($pdo);

                $now = tzNow();
                $today = $now->format('Y-m-d');
                $scheduleEnabled = (int)($session['daily_schedule_enabled'] ?? 0) === 1;
                $currentRound = (int)($session['current_round'] ?? 1);
                $totalRounds = (int)($session['total_rounds'] ?? 1);
                $newRound = null;

                if ($scheduleEnabled) {
                    $dailyRound = computeDailyRoundForDate($session['daily_schedule_start_date'] ?? null, $now);
                    if ($dailyRound !== null && $dailyRound >= 1 && $dailyRound <= $totalRounds && $dailyRound !== $currentRound) {
                        $newRound = $dailyRound;
                    } else {
                        // Se a rodada atual terminou, avançar para a próxima imediatamente
                        if (isRoundCompleted($pdo, $sessionId, $currentRound) && $currentRound < $totalRounds) {
                            $newRound = $currentRound + 1;
                        }
                    }
                } else {
                    // Sem agendamento diário: apenas garantir in_progress ou avançar se rodada terminou
                    if (isRoundCompleted($pdo, $sessionId, $currentRound) && $currentRound < $totalRounds) {
                        $newRound = $currentRound + 1;
                    }
                }

                try {
                    $pdo->beginTransaction();

                    // Garante status em andamento
                    $pdo->prepare('UPDATE initdraft_sessions SET status = "in_progress", started_at = COALESCE(started_at, NOW()) WHERE id = ?')
                        ->execute([$sessionId]);

                    if ($newRound !== null) {
                        $openPick = getCurrentOpenPick($pdo, $sessionId, $newRound);
                        $newPick = $openPick ? (int)$openPick['pick_position'] : 1;
                        $pdo->prepare('UPDATE initdraft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')
                            ->execute([$newRound, $newPick, $sessionId]);
                        clearDeadlinesForRound($pdo, $sessionId, $newRound);
                    } else {
                        // Apenas limpa deadlines da rodada atual
                        clearDeadlinesForRound($pdo, $sessionId, $currentRound);
                    }

                    // Ativa override e marca como aberto hoje
                    $pdo->prepare('UPDATE initdraft_sessions SET daily_override_enabled = 1, daily_last_opened_date = ? WHERE id = ?')
                        ->execute([$today, $sessionId]);

                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                // Retorna estado atualizado
                $updated = getSessionById($pdo, $sessionId);
                echo json_encode(['success' => true, 'session' => $updated, 'message' => 'Rodada aberta imediatamente']);
                break;
            }

            // TOKEN: finalizar (garantia idempotente)
            case 'finalize': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new InvalidArgumentException('Não autorizado');

                // Apenas marca completed se todas picks efetuadas
                $stmtMissing = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND picked_player_id IS NULL');
                $stmtMissing->execute([$session['id']]);
                if ((int)$stmtMissing->fetchColumn() > 0) throw new InvalidArgumentException('Ainda existem picks pendentes');

                $pdo->prepare('UPDATE initdraft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')->execute([$session['id']]);
                echo json_encode(['success' => true]);
                break;
            }

            case 'mock_save': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!$session) throw new InvalidArgumentException('Sessão não encontrada');
                ensureInitDraftMockTables($pdo);

                $teamId = getUserTeamId($pdo, $user);
                if (!$teamId) throw new InvalidArgumentException('Você precisa estar logado com um time nesta liga.');

                $playerIds = array_values(array_filter(array_map('intval', $data['player_ids'] ?? []), fn($v) => $v > 0));
                if (count($playerIds) > 20) throw new InvalidArgumentException('Máximo 20 jogadores no mock.');

                $pdo->beginTransaction();
                try {
                    $pdo->prepare('DELETE FROM initdraft_mock_queue WHERE team_id = ? AND initdraft_session_id = ?')
                        ->execute([$teamId, $session['id']]);
                    foreach ($playerIds as $idx => $playerId) {
                        $pdo->prepare('INSERT INTO initdraft_mock_queue (team_id, initdraft_session_id, player_id, priority) VALUES (?, ?, ?, ?)')
                            ->execute([$teamId, $session['id'], $playerId, $idx + 1]);
                    }
                    $pdo->commit();
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw new InvalidArgumentException('Erro ao salvar o mock.');
                }
                break;
            }

            case 'mock_toggle': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!$session) throw new InvalidArgumentException('Sessão não encontrada');
                ensureInitDraftMockTables($pdo);

                $teamId = getUserTeamId($pdo, $user);
                if (!$teamId) throw new InvalidArgumentException('Você precisa estar logado com um time nesta liga.');

                $isActive = !empty($data['is_active']);
                $pdo->prepare('
                    INSERT INTO initdraft_mock_settings (team_id, initdraft_session_id, is_active)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)
                ')->execute([$teamId, $session['id'], $isActive ? 1 : 0]);

                echo json_encode(['success' => true, 'is_active' => $isActive]);
                break;
            }

            case 'toggle_favorite': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!$session) throw new InvalidArgumentException('Sessão não encontrada');
                if (!$user || !isset($user['id'])) throw new InvalidArgumentException('Você precisa estar logado.');
                ensureInitDraftFavoritesTable($pdo);

                $playerId = (int)($data['player_id'] ?? 0);
                if (!$playerId) throw new InvalidArgumentException('player_id obrigatório');

                $stmtChk = $pdo->prepare('SELECT id FROM initdraft_favorites WHERE user_id = ? AND player_id = ?');
                $stmtChk->execute([(int)$user['id'], $playerId]);
                if ($stmtChk->fetchColumn()) {
                    $pdo->prepare('DELETE FROM initdraft_favorites WHERE user_id = ? AND player_id = ?')
                        ->execute([(int)$user['id'], $playerId]);
                    echo json_encode(['success' => true, 'is_favorite' => false]);
                } else {
                    $pdo->prepare('INSERT INTO initdraft_favorites (user_id, player_id) VALUES (?, ?)')
                        ->execute([(int)$user['id'], $playerId]);
                    echo json_encode(['success' => true, 'is_favorite' => true]);
                }
                break;
            }

            default:
                echo json_encode(['success' => false, 'error' => 'Ação inválida']);
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[api/initdraft.php][POST] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);

?>
