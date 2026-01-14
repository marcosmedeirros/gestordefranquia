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
$method = $_SERVER['REQUEST_METHOD'];

function buildDeadlineDateTime(?string $date, ?string $time = null): string {
    if (!$date) {
        throw new Exception('Data do prazo obrigatória');
    }

    $time = $time ?: '23:59';
    $tz = new DateTimeZone('America/Sao_Paulo');
    $dateTimeString = trim($date . ' ' . $time);
    $dateTime = DateTime::createFromFormat('Y-m-d H:i', $dateTimeString, $tz);

    if (!$dateTime) {
        try {
            $dateTime = new DateTime($date, $tz);
        } catch (Exception $e) {
            $dateTime = false;
        }
    }

    if (!$dateTime) {
        throw new Exception('Formato de data/hora inválido');
    }

    return $dateTime->format('Y-m-d H:i:s');
}

function formatDeadlineRow(?array $deadline): ?array {
    if (!$deadline || empty($deadline['deadline_date'])) {
        return $deadline;
    }

    try {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $dateTime = new DateTime($deadline['deadline_date'], $tz);
        $deadline['deadline_date_iso'] = $dateTime->format(DateTime::ATOM);
        $deadline['deadline_date_display'] = $dateTime->format('d/m/Y H:i');
        $deadline['deadline_timezone'] = 'America/Sao_Paulo';
    } catch (Exception $e) {
        // Ignorar falha de formatação e retornar dados originais
    }

    return $deadline;
}

// GET - Buscar diretrizes ou prazos
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'active_deadline';

    // Buscar time do usuário (não exigido para admin listar prazos)
    $stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
    $stmtTeam->execute([$user['id']]);
    $team = $stmtTeam->fetch();

    if (!$team && !in_array($action, ['list_deadlines_admin', 'view_all_directives_admin'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Usuário sem time']);
        exit;
    }

    switch ($action) {
        case 'active_deadline':
            // Buscar prazo ativo para a liga do time
            $stmt = $pdo->prepare("SELECT * FROM directive_deadlines WHERE league = ? AND is_active = 1 ORDER BY deadline_date DESC LIMIT 1");
            $stmt->execute([$team['league']]);
            $deadline = $stmt->fetch();
            $deadline = formatDeadlineRow($deadline);
            echo json_encode(['success' => true, 'deadline' => $deadline]);
            break;

        case 'my_directive':
            $deadlineId = $_GET['deadline_id'] ?? null;
            if (!$deadlineId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'deadline_id obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT td.*,
                       s1.name as starter_1_name, s1.position as starter_1_pos,
                       s2.name as starter_2_name, s2.position as starter_2_pos,
                       s3.name as starter_3_name, s3.position as starter_3_pos,
                       s4.name as starter_4_name, s4.position as starter_4_pos,
                       s5.name as starter_5_name, s5.position as starter_5_pos,
                       b1.name as bench_1_name, b1.position as bench_1_pos,
                       b2.name as bench_2_name, b2.position as bench_2_pos,
                       b3.name as bench_3_name, b3.position as bench_3_pos
                FROM team_directives td
                LEFT JOIN players s1 ON td.starter_1_id = s1.id
                LEFT JOIN players s2 ON td.starter_2_id = s2.id
                LEFT JOIN players s3 ON td.starter_3_id = s3.id
                LEFT JOIN players s4 ON td.starter_4_id = s4.id
                LEFT JOIN players s5 ON td.starter_5_id = s5.id
                LEFT JOIN players b1 ON td.bench_1_id = b1.id
                LEFT JOIN players b2 ON td.bench_2_id = b2.id
                LEFT JOIN players b3 ON td.bench_3_id = b3.id
                WHERE td.team_id = ? AND td.deadline_id = ?
            ");
            $stmt->execute([$team['id'], $deadlineId]);
            $directive = $stmt->fetch();

            // Buscar minutagem dos jogadores
            if ($directive) {
                $stmtMinutes = $pdo->prepare("SELECT player_id, minutes_per_game FROM directive_player_minutes WHERE directive_id = ?");
                $stmtMinutes->execute([$directive['id']]);
                $minutesData = $stmtMinutes->fetchAll(PDO::FETCH_ASSOC);
                $playerMinutes = [];
                foreach ($minutesData as $row) {
                    $playerMinutes[$row['player_id']] = $row['minutes_per_game'];
                }
                $directive['player_minutes'] = $playerMinutes;
            }

            echo json_encode(['success' => true, 'directive' => $directive]);
            break;

        case 'list_deadlines_admin':
            if (($user['user_type'] ?? 'jogador') !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            $league = $_GET['league'] ?? null;
            $where = $league ? 'WHERE league = ?' : '';
            $params = $league ? [$league] : [];
            $stmt = $pdo->prepare("SELECT dd.*, (SELECT COUNT(*) FROM team_directives WHERE deadline_id = dd.id) as submissions_count FROM directive_deadlines dd $where ORDER BY deadline_date DESC");
            $stmt->execute($params);
            $deadlines = $stmt->fetchAll();
            $deadlines = array_map('formatDeadlineRow', $deadlines);
            echo json_encode(['success' => true, 'deadlines' => $deadlines]);
            break;

        case 'view_all_directives_admin':
            if (($user['user_type'] ?? 'jogador') !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            $deadlineId = $_GET['deadline_id'] ?? null;
            if (!$deadlineId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'deadline_id obrigatório']);
                exit;
            }
            $stmt = $pdo->prepare("
                SELECT td.*, t.city, t.name as team_name,
                       s1.name as starter_1_name, s1.position as starter_1_pos,
                       s2.name as starter_2_name, s2.position as starter_2_pos,
                       s3.name as starter_3_name, s3.position as starter_3_pos,
                       s4.name as starter_4_name, s4.position as starter_4_pos,
                       s5.name as starter_5_name, s5.position as starter_5_pos,
                       b1.name as bench_1_name, b1.position as bench_1_pos,
                       b2.name as bench_2_name, b2.position as bench_2_pos,
                       b3.name as bench_3_name, b3.position as bench_3_pos
                FROM team_directives td
                INNER JOIN teams t ON td.team_id = t.id
                LEFT JOIN players s1 ON td.starter_1_id = s1.id
                LEFT JOIN players s2 ON td.starter_2_id = s2.id
                LEFT JOIN players s3 ON td.starter_3_id = s3.id
                LEFT JOIN players s4 ON td.starter_4_id = s4.id
                LEFT JOIN players s5 ON td.starter_5_id = s5.id
                LEFT JOIN players b1 ON td.bench_1_id = b1.id
                LEFT JOIN players b2 ON td.bench_2_id = b2.id
                LEFT JOIN players b3 ON td.bench_3_id = b3.id
                WHERE td.deadline_id = ?
                ORDER BY t.name
            ");
            $stmt->execute([$deadlineId]);
            $directives = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Anexar minutos por jogador para cada diretriz (admin visualização)
            if ($directives) {
                $directiveIds = array_column($directives, 'id');
                if (!empty($directiveIds)) {
                    $placeholders = implode(',', array_fill(0, count($directiveIds), '?'));
                    $stmtMin = $pdo->prepare("SELECT directive_id, player_id, minutes_per_game FROM directive_player_minutes WHERE directive_id IN ($placeholders)");
                    $stmtMin->execute($directiveIds);
                    $minRows = $stmtMin->fetchAll(PDO::FETCH_ASSOC);
                    $minutesByDirective = [];
                    foreach ($minRows as $mr) {
                        $dId = (int)$mr['directive_id'];
                        if (!isset($minutesByDirective[$dId])) $minutesByDirective[$dId] = [];
                        $minutesByDirective[$dId][(int)$mr['player_id']] = (int)$mr['minutes_per_game'];
                    }
                    foreach ($directives as &$dRow) {
                        $id = (int)$dRow['id'];
                        $dRow['player_minutes'] = $minutesByDirective[$id] ?? [];
                    }
                    unset($dRow);
                }
            }

            echo json_encode(['success' => true, 'directives' => $directives]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

// POST - Enviar/atualizar diretriz ou criar prazo
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? 'submit_directive';

    // Buscar time (não exigido para criar prazo)
    $stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
    $stmtTeam->execute([$user['id']]);
    $team = $stmtTeam->fetch();

    if (!$team && $action !== 'create_deadline') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Usuário sem time']);
        exit;
    }

    switch ($action) {
        case 'submit_directive':
            $deadlineId = $data['deadline_id'] ?? null;
            if (!$deadlineId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'deadline_id obrigatório']);
                exit;
            }

            // Validar quinteto titular (5 jogadores)
            $starters = array_filter([
                $data['starter_1_id'] ?? null,
                $data['starter_2_id'] ?? null,
                $data['starter_3_id'] ?? null,
                $data['starter_4_id'] ?? null,
                $data['starter_5_id'] ?? null
            ]);
            if (count($starters) !== 5) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Quinteto titular deve ter exatamente 5 jogadores']);
                exit;
            }

            // Validar banco (3 jogadores)
            $bench = array_filter([
                $data['bench_1_id'] ?? null,
                $data['bench_2_id'] ?? null,
                $data['bench_3_id'] ?? null
            ]);
            if (count($bench) !== 3) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Banco deve ter exatamente 3 jogadores']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                // Verificar se já existe diretriz para este prazo
                $stmtCheck = $pdo->prepare('SELECT id FROM team_directives WHERE team_id = ? AND deadline_id = ?');
                $stmtCheck->execute([$team['id'], $deadlineId]);
                $existing = $stmtCheck->fetch();

                if ($existing) {
                    $stmt = $pdo->prepare("
                        UPDATE team_directives SET
                            starter_1_id = ?, starter_2_id = ?, starter_3_id = ?, starter_4_id = ?, starter_5_id = ?,
                            bench_1_id = ?, bench_2_id = ?, bench_3_id = ?,
                            pace = ?, offensive_rebound = ?, offensive_aggression = ?, defensive_rebound = ?,
                            rotation_style = ?, game_style = ?, offense_style = ?,
                            rotation_players = ?, veteran_focus = ?,
                            gleague_1_id = ?, gleague_2_id = ?,
                            notes = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $data['starter_1_id'], $data['starter_2_id'], $data['starter_3_id'], $data['starter_4_id'], $data['starter_5_id'],
                        $data['bench_1_id'], $data['bench_2_id'], $data['bench_3_id'],
                        $data['pace'] ?? 'no_preference', $data['offensive_rebound'] ?? 'no_preference', $data['offensive_aggression'] ?? 'no_preference', $data['defensive_rebound'] ?? 'no_preference',
                        $data['rotation_style'] ?? 'auto', $data['game_style'] ?? 'balanced', $data['offense_style'] ?? 'no_preference',
                        $data['rotation_players'] ?? 10, $data['veteran_focus'] ?? 50,
                        $data['gleague_1_id'] ?? null, $data['gleague_2_id'] ?? null,
                        $data['notes'] ?? null,
                        $existing['id']
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO team_directives (
                            team_id, deadline_id,
                            starter_1_id, starter_2_id, starter_3_id, starter_4_id, starter_5_id,
                            bench_1_id, bench_2_id, bench_3_id,
                            pace, offensive_rebound, offensive_aggression, defensive_rebound,
                            rotation_style, game_style, offense_style,
                            rotation_players, veteran_focus,
                            gleague_1_id, gleague_2_id, notes
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $team['id'], $deadlineId,
                        $data['starter_1_id'], $data['starter_2_id'], $data['starter_3_id'], $data['starter_4_id'], $data['starter_5_id'],
                        $data['bench_1_id'], $data['bench_2_id'], $data['bench_3_id'],
                        $data['pace'] ?? 'no_preference', $data['offensive_rebound'] ?? 'no_preference', $data['offensive_aggression'] ?? 'no_preference', $data['defensive_rebound'] ?? 'no_preference',
                        $data['rotation_style'] ?? 'auto', $data['game_style'] ?? 'balanced', $data['offense_style'] ?? 'no_preference',
                        $data['rotation_players'] ?? 10, $data['veteran_focus'] ?? 50,
                        $data['gleague_1_id'] ?? null, $data['gleague_2_id'] ?? null,
                        $data['notes'] ?? null
                    ]);
                }

                $directiveId = $existing['id'] ?? $pdo->lastInsertId();

                // Salvar minutagem (sempre), validando por fase do prazo
                if (!empty($data['player_minutes'])) {
                    $maxMinutes = 40;
                    $stmtDeadline = $pdo->prepare('SELECT phase FROM directive_deadlines WHERE id = ?');
                    $stmtDeadline->execute([$deadlineId]);
                    $deadlineRow = $stmtDeadline->fetch();
                    if ($deadlineRow && strtolower($deadlineRow['phase'] ?? '') === 'playoffs') {
                        $maxMinutes = 45;
                    }

                    // Limpar minutos antigos
                    $stmtDelete = $pdo->prepare('DELETE FROM directive_player_minutes WHERE directive_id = ?');
                    $stmtDelete->execute([$directiveId]);

                    $stmtMinutes = $pdo->prepare('INSERT INTO directive_player_minutes (directive_id, player_id, minutes_per_game) VALUES (?, ?, ?)');
                    foreach ($data['player_minutes'] as $playerId => $minutes) {
                        $m = (int)$minutes;
                        if ($m < 5 || $m > $maxMinutes) {
                            throw new Exception("Minutos inválidos para o jogador {$playerId}. Deve estar entre 5 e {$maxMinutes}.");
                        }
                        $stmtMinutes->execute([$directiveId, (int)$playerId, $m]);
                    }
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Diretriz enviada com sucesso']);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro ao enviar diretriz: ' . $e->getMessage()]);
            }
            break;

        case 'create_deadline':
            // ADMIN: Criar prazo
            if (($user['user_type'] ?? 'jogador') !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            $league = $data['league'] ?? null;
            $deadlineDate = $data['deadline_date'] ?? null;
            $deadlineTime = $data['deadline_time'] ?? null;
            $description = $data['description'] ?? null;
            $phase = $data['phase'] ?? 'regular';
            if (!$league || !$deadlineDate) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga e data obrigatórios']);
                exit;
            }
            try {
                $deadlineDateTime = buildDeadlineDateTime($deadlineDate, $deadlineTime);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $stmt = $pdo->prepare('INSERT INTO directive_deadlines (league, deadline_date, description, phase) VALUES (?, ?, ?, ?)');
            $stmt->execute([$league, $deadlineDateTime, $description, $phase]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

// PUT - Atualizar prazo (admin)
if ($method === 'PUT') {
    if (($user['user_type'] ?? 'jogador') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $deadlineId = $data['id'] ?? null;
    if (!$deadlineId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID obrigatório']);
        exit;
    }
    $updates = [];
    $params = [];

    if (isset($data['deadline_date']) || isset($data['deadline_time'])) {
        try {
            $deadlineDateTime = buildDeadlineDateTime($data['deadline_date'] ?? null, $data['deadline_time'] ?? null);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $updates[] = 'deadline_date = ?';
        $params[] = $deadlineDateTime;
    }

    if (array_key_exists('description', $data)) {
        $updates[] = 'description = ?';
        $params[] = $data['description'];
    }

    if (array_key_exists('is_active', $data)) {
        $updates[] = 'is_active = ?';
        $params[] = (int)$data['is_active'];
    }

    if (array_key_exists('phase', $data)) {
        $updates[] = 'phase = ?';
        $params[] = $data['phase'] ?? 'regular';
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
        exit;
    }

    $params[] = $deadlineId;
    $stmt = $pdo->prepare('UPDATE directive_deadlines SET ' . implode(', ', $updates) . ' WHERE id = ?');
    $stmt->execute($params);
    echo json_encode(['success' => true]);
    exit;
}

// DELETE - Deletar prazo ou diretriz (admin)
if ($method === 'DELETE') {
    if (($user['user_type'] ?? 'jogador') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? 'delete_deadline';
    if ($action === 'delete_directive') {
        $directiveId = $data['directive_id'] ?? null;
        if (!$directiveId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'directive_id obrigatório']);
            exit;
        }
        $stmt = $pdo->prepare('DELETE FROM team_directives WHERE id = ?');
        $stmt->execute([$directiveId]);
        echo json_encode(['success' => true, 'message' => 'Diretriz excluída com sucesso']);
        exit;
    }
    $deadlineId = $data['id'] ?? null;
    if (!$deadlineId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID obrigatório']);
        exit;
    }
    $stmt = $pdo->prepare('DELETE FROM directive_deadlines WHERE id = ?');
    $stmt->execute([$deadlineId]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido']);
