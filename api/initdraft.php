<?php
/**
 * API de Draft Inicial (initdraft)
 * Separado do draft de temporada. Controlado por token de acesso.
 */

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

                echo json_encode(['success' => true, 'session' => $session, 'order' => $order]);
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
                $session = getSessionByToken($pdo, $token);
                if (!$session) throw new Exception('Sessão não encontrada');

                $stmt = $pdo->prepare('SELECT * FROM initdraft_pool WHERE season_id = ? AND draft_status = "available" ORDER BY ovr DESC, name ASC');
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
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? '';

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

                $stmt = $pdo->prepare('INSERT INTO initdraft_pool (season_id, name, position, secondary_position, age, ovr, photo_url, bio, strengths, weaknesses) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $count = 0;
                foreach ($players as $p) {
                    $stmt->execute([
                        $session['season_id'],
                        $p['name'] ?? '',
                        $p['position'] ?? 'SF',
                        $p['secondary_position'] ?? null,
                        (int)($p['age'] ?? 20),
                        (int)($p['ovr'] ?? 70),
                        $p['photo_url'] ?? null,
                        $p['bio'] ?? null,
                        $p['strengths'] ?? null,
                        $p['weaknesses'] ?? null,
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

                $stmt = $pdo->prepare('INSERT INTO initdraft_pool (season_id, name, position, secondary_position, age, ovr, photo_url, bio, strengths, weaknesses) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $session['season_id'],
                    $data['name'],
                    $data['position'],
                    $data['secondary_position'] ?? null,
                    (int)$data['age'],
                    (int)$data['ovr'],
                    $data['photo_url'] ?? null,
                    $data['bio'] ?? null,
                    $data['strengths'] ?? null,
                    $data['weaknesses'] ?? null,
                ]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                break;
            }

            // ADMIN/TOKEN: randomizar ordem (primeiro sorteado = último da 1ª rodada)
            case 'randomize_order': {
                $token = $data['token'] ?? null;
                $session = getSessionByToken($pdo, $token);
                if (!ensureAdminOrToken($session, $token)) throw new Exception('Não autorizado');
                if ($session['status'] !== 'setup') throw new Exception('Só é possível randomizar durante setup');

                // Buscar times da liga
                $stmtTeams = $pdo->prepare('SELECT id FROM teams WHERE league = ? ORDER BY id ASC');
                $stmtTeams->execute([$session['league']]);
                $teams = $stmtTeams->fetchAll(PDO::FETCH_COLUMN);
                if (!$teams) throw new Exception('Sem times na liga');

                shuffle($teams);
                $orderRound1 = array_reverse($teams); // primeiro sorteado vira último

                try {
                    $pdo->beginTransaction();
                    // Limpa ordem existente
                    $pdo->prepare('DELETE FROM initdraft_order WHERE initdraft_session_id = ?')->execute([$session['id']]);

                    for ($round = 1; $round <= (int)$session['total_rounds']; $round++) {
                        $roundOrder = ($round % 2 === 1) ? $orderRound1 : array_reverse($orderRound1);
                        foreach ($roundOrder as $idx => $teamId) {
                            $pdo->prepare('INSERT INTO initdraft_order (initdraft_session_id, team_id, original_team_id, pick_position, round) VALUES (?, ?, ?, ?, ?)')
                                ->execute([$session['id'], $teamId, $teamId, $idx + 1, $round]);
                        }
                    }

                    $pdo->commit();
                    echo json_encode(['success' => true, 'order' => $orderRound1]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
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
                if ($session['status'] !== 'in_progress') throw new Exception('Draft não está em andamento');
                if (!$playerId) throw new Exception('player_id obrigatório');

                // Pick corrente
                $stmtPick = $pdo->prepare('SELECT * FROM initdraft_order WHERE initdraft_session_id = ? AND round = ? AND pick_position = ? AND picked_player_id IS NULL');
                $stmtPick->execute([$session['id'], $session['current_round'], $session['current_pick']]);
                $currentPick = $stmtPick->fetch(PDO::FETCH_ASSOC);
                if (!$currentPick) throw new Exception('Nenhuma pick pendente');

                // Jogador disponível
                $stmtP = $pdo->prepare('SELECT * FROM initdraft_pool WHERE id = ? AND draft_status = "available"');
                $stmtP->execute([$playerId]);
                $player = $stmtP->fetch(PDO::FETCH_ASSOC);
                if (!$player) throw new Exception('Jogador indisponível');

                try {
                    $pdo->beginTransaction();

                    // Registrar pick
                    $pdo->prepare('UPDATE initdraft_order SET picked_player_id = ?, picked_at = NOW() WHERE id = ?')
                        ->execute([$playerId, $currentPick['id']]);

                    // Calcular número absoluto da pick
                    $stmtRoundSize = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = ?');
                    $stmtRoundSize->execute([$session['id'], $session['current_round']]);
                    $roundSize = (int)$stmtRoundSize->fetchColumn();
                    $pickNumber = (($session['current_round'] - 1) * $roundSize) + $session['current_pick'];

                    // Marcar jogador e inserir no elenco
                    $pdo->prepare('UPDATE initdraft_pool SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ? WHERE id = ?')
                        ->execute([$currentPick['team_id'], $pickNumber, $playerId]);

                    $pdo->prepare('INSERT INTO players (team_id, name, position, age, ovr, role, available_for_trade) VALUES (?, ?, ?, ?, ?, "Banco", 0)')
                        ->execute([$currentPick['team_id'], $player['name'], $player['position'], $player['age'], $player['ovr']]);

                    // Avançar ponteiro
                    $nextPick = $session['current_pick'] + 1;
                    $nextRound = $session['current_round'];
                    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = ?');
                    $stmtCount->execute([$session['id'], $nextRound]);
                    $totalPicks = (int)$stmtCount->fetchColumn();

                    if ($nextPick > $totalPicks) {
                        $nextRound++;
                        $nextPick = 1;
                        if ($nextRound > (int)$session['total_rounds']) {
                            $pdo->prepare('UPDATE initdraft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')->execute([$session['id']]);
                        } else {
                            $pdo->prepare('UPDATE initdraft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')->execute([$nextRound, $nextPick, $session['id']]);
                        }
                    } else {
                        $pdo->prepare('UPDATE initdraft_sessions SET current_pick = ? WHERE id = ?')->execute([$nextPick, $session['id']]);
                    }

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Pick realizada']);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
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
