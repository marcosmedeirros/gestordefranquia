<?php
// Define timezone padrão para todo o sistema: São Paulo/Brasília
date_default_timezone_set('America/Sao_Paulo');

session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$pdo = db();
ensureDirectiveOptionalColumns($pdo);
ensureTeamDirectiveProfileColumns($pdo);
$method = $_SERVER['REQUEST_METHOD'];

function buildTeamDirectiveProfilePayload(array $data): array
{
    $benchPlayers = $data['bench_players'] ?? [];
    if (!is_array($benchPlayers)) {
        $benchPlayers = [];
    }
    $benchPlayers = array_values(array_filter($benchPlayers));

    $profile = [
        'starter_1_id' => $data['starter_1_id'] ?? null,
        'starter_2_id' => $data['starter_2_id'] ?? null,
        'starter_3_id' => $data['starter_3_id'] ?? null,
        'starter_4_id' => $data['starter_4_id'] ?? null,
        'starter_5_id' => $data['starter_5_id'] ?? null,
        'bench_players' => $benchPlayers,
        'bench_1_id' => $benchPlayers[0] ?? null,
        'bench_2_id' => $benchPlayers[1] ?? null,
        'bench_3_id' => $benchPlayers[2] ?? null,
        'pace' => $data['pace'] ?? 'no_preference',
        'offensive_rebound' => $data['offensive_rebound'] ?? 'no_preference',
        'offensive_aggression' => $data['offensive_aggression'] ?? 'no_preference',
        'defensive_rebound' => $data['defensive_rebound'] ?? 'no_preference',
        'defensive_focus' => $data['defensive_focus'] ?? 'no_preference',
        'rotation_style' => $data['rotation_style'] ?? 'auto',
        'game_style' => $data['game_style'] ?? 'balanced',
        'offense_style' => $data['offense_style'] ?? 'no_preference',
        'rotation_players' => $data['rotation_players'] ?? 10,
        'veteran_focus' => $data['veteran_focus'] ?? 50,
        'gleague_1_id' => $data['gleague_1_id'] ?? null,
        'gleague_2_id' => $data['gleague_2_id'] ?? null,
        'notes' => $data['notes'] ?? null,
        'technical_model' => $data['technical_model'] ?? null,
        'playbook' => $data['playbook'] ?? null,
        'player_minutes' => $data['player_minutes'] ?? []
    ];

    return $profile;
}

function saveTeamDirectiveProfile(PDO $pdo, int $teamId, array $data): void
{
    $profile = buildTeamDirectiveProfilePayload($data);
    $stmt = $pdo->prepare('UPDATE teams SET directive_profile = ?, directive_profile_updated_at = NOW() WHERE id = ?');
    $stmt->execute([json_encode($profile), $teamId]);
}

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
    $stmtTeam = $pdo->prepare('SELECT id, league, directive_profile, directive_profile_updated_at, technical_model_current, technical_model_changes_used FROM teams WHERE user_id = ? LIMIT 1');
    $stmtTeam->execute([$user['id']]);
    $team = $stmtTeam->fetch();

    if (!$team && !in_array($action, ['list_deadlines_admin', 'view_all_directives_admin'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Usuário sem time']);
        exit;
    }

    switch ($action) {
        case 'active_deadline':
            // Buscar prazo ativo para a liga do time (somente se ainda não expirou - usando horário de Brasília)
            $nowBrasilia = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare("SELECT * FROM directive_deadlines WHERE league = ? AND is_active = 1 AND deadline_date > ? ORDER BY deadline_date ASC LIMIT 1");
            $stmt->execute([$team['league'], $nowBrasilia]);
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

        case 'team_profile':
            $profile = null;
            if (!empty($team['directive_profile'])) {
                $decoded = json_decode($team['directive_profile'], true);
                if (is_array($decoded)) {
                    $profile = $decoded;
                }
            }
            $changesUsed = (int)($team['technical_model_changes_used'] ?? 0);
            $changesLimit = 3;
            $changesRemaining = max(0, $changesLimit - $changesUsed);
            echo json_encode([
                'success' => true,
                'profile' => $profile,
                'profile_updated_at' => $team['directive_profile_updated_at'] ?? null,
                'technical_model_current' => $team['technical_model_current'] ?? null,
                'technical_model_changes_used' => $changesUsed,
                'technical_model_changes_limit' => $changesLimit,
                'technical_model_changes_remaining' => $changesRemaining
            ]);
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
            $league = $_GET['league'] ?? null;
            $all = isset($_GET['all']) && $_GET['all'] == '1';
            $debug = isset($_GET['debug']) && $_GET['debug'] == '1';
            $strict = isset($_GET['strict']) && $_GET['strict'] == '1';
            $fallback = false;
            if (!$deadlineId && !$all) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'deadline_id obrigatório']);
                exit;
            }
            if ($all && $league) {
                $stmt = $pdo->prepare("
                    SELECT td.*, COALESCE(t.city, 'Time') as city, COALESCE(t.name, 'Desconhecido') as team_name,
                           s1.name as starter_1_name, s1.position as starter_1_pos, s1.ovr as starter_1_ovr, s1.age as starter_1_age,
                           s2.name as starter_2_name, s2.position as starter_2_pos, s2.ovr as starter_2_ovr, s2.age as starter_2_age,
                           s3.name as starter_3_name, s3.position as starter_3_pos, s3.ovr as starter_3_ovr, s3.age as starter_3_age,
                           s4.name as starter_4_name, s4.position as starter_4_pos, s4.ovr as starter_4_ovr, s4.age as starter_4_age,
                           s5.name as starter_5_name, s5.position as starter_5_pos, s5.ovr as starter_5_ovr, s5.age as starter_5_age,
                           b1.name as bench_1_name, b1.position as bench_1_pos, b1.ovr as bench_1_ovr, b1.age as bench_1_age,
                           b2.name as bench_2_name, b2.position as bench_2_pos, b2.ovr as bench_2_ovr, b2.age as bench_2_age,
                           b3.name as bench_3_name, b3.position as bench_3_pos, b3.ovr as bench_3_ovr, b3.age as bench_3_age,
                           g1.name as gleague_1_name, g1.position as gleague_1_pos, g1.ovr as gleague_1_ovr, g1.age as gleague_1_age,
                           g2.name as gleague_2_name, g2.position as gleague_2_pos, g2.ovr as gleague_2_ovr, g2.age as gleague_2_age
                    FROM team_directives td
                    LEFT JOccoIN teams t ON td.team_id = t.id
                    LEFT JOIN players s1 ON td.starter_1_id = s1.id
                    LEFT JOIN players s2 ON td.starter_2_id = s2.id
                    LEFT JOIN players s3 ON td.starter_3_id = s3.id
                    LEFT JOIN players s4 ON td.starter_4_id = s4.id
                    LEFT JOIN players s5 ON td.starter_5_id = s5.id
                    LEFT JOIN players b1 ON td.bench_1_id = b1.id
                    LEFT JOIN players b2 ON td.bench_2_id = b2.id
                    LEFT JOIN players b3 ON td.bench_3_id = b3.id
                    LEFT JOIN players g1 ON td.gleague_1_id = g1.id
                    LEFT JOIN players g2 ON td.gleague_2_id = g2.id
                    WHERE t.league = ?
                    ORDER BY COALESCE(td.updated_at, td.submitted_at) DESC, t.name
                    LIMIT 200
                ");
                $stmt->execute([$league]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT td.*, COALESCE(t.city, 'Time') as city, COALESCE(t.name, 'Desconhecido') as team_name,
                           s1.name as starter_1_name, s1.position as starter_1_pos, s1.ovr as starter_1_ovr, s1.age as starter_1_age,
                           s2.name as starter_2_name, s2.position as starter_2_pos, s2.ovr as starter_2_ovr, s2.age as starter_2_age,
                           s3.name as starter_3_name, s3.position as starter_3_pos, s3.ovr as starter_3_ovr, s3.age as starter_3_age,
                           s4.name as starter_4_name, s4.position as starter_4_pos, s4.ovr as starter_4_ovr, s4.age as starter_4_age,
                           s5.name as starter_5_name, s5.position as starter_5_pos, s5.ovr as starter_5_ovr, s5.age as starter_5_age,
                           b1.name as bench_1_name, b1.position as bench_1_pos, b1.ovr as bench_1_ovr, b1.age as bench_1_age,
                           b2.name as bench_2_name, b2.position as bench_2_pos, b2.ovr as bench_2_ovr, b2.age as bench_2_age,
                           b3.name as bench_3_name, b3.position as bench_3_pos, b3.ovr as bench_3_ovr, b3.age as bench_3_age,
                           g1.name as gleague_1_name, g1.position as gleague_1_pos, g1.ovr as gleague_1_ovr, g1.age as gleague_1_age,
                           g2.name as gleague_2_name, g2.position as gleague_2_pos, g2.ovr as gleague_2_ovr, g2.age as gleague_2_age
                    FROM team_directives td
                    LEFT JOIN teams t ON td.team_id = t.id
                    LEFT JOIN players s1 ON td.starter_1_id = s1.id
                    LEFT JOIN players s2 ON td.starter_2_id = s2.id
                    LEFT JOIN players s3 ON td.starter_3_id = s3.id
                    LEFT JOIN players s4 ON td.starter_4_id = s4.id
                    LEFT JOIN players s5 ON td.starter_5_id = s5.id
                    LEFT JOIN players b1 ON td.bench_1_id = b1.id
                    LEFT JOIN players b2 ON td.bench_2_id = b2.id
                    LEFT JOIN players b3 ON td.bench_3_id = b3.id
                    LEFT JOIN players g1 ON td.gleague_1_id = g1.id
                    LEFT JOIN players g2 ON td.gleague_2_id = g2.id
                    WHERE td.deadline_id = ?
                    ORDER BY COALESCE(td.updated_at, td.submitted_at) DESC, t.name
                ");
                $stmt->execute([$deadlineId]);
            }
            $directives = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$strict && !$directives && !$league) {
                $stmtLeague = $pdo->prepare('SELECT league FROM directive_deadlines WHERE id = ?');
                $stmtLeague->execute([$deadlineId]);
                $rowLeague = $stmtLeague->fetch(PDO::FETCH_ASSOC);
                if ($rowLeague && !empty($rowLeague['league'])) {
                    $league = $rowLeague['league'];
                }
            }

            if (!$strict && !$directives && $league) {
                $fallback = true;
                $stmt = $pdo->prepare("
                    SELECT td.*, COALESCE(t.city, 'Time') as city, COALESCE(t.name, 'Desconhecido') as team_name,
                           s1.name as starter_1_name, s1.position as starter_1_pos, s1.ovr as starter_1_ovr, s1.age as starter_1_age,
                           s2.name as starter_2_name, s2.position as starter_2_pos, s2.ovr as starter_2_ovr, s2.age as starter_2_age,
                           s3.name as starter_3_name, s3.position as starter_3_pos, s3.ovr as starter_3_ovr, s3.age as starter_3_age,
                           s4.name as starter_4_name, s4.position as starter_4_pos, s4.ovr as starter_4_ovr, s4.age as starter_4_age,
                           s5.name as starter_5_name, s5.position as starter_5_pos, s5.ovr as starter_5_ovr, s5.age as starter_5_age,
                           b1.name as bench_1_name, b1.position as bench_1_pos, b1.ovr as bench_1_ovr, b1.age as bench_1_age,
                           b2.name as bench_2_name, b2.position as bench_2_pos, b2.ovr as bench_2_ovr, b2.age as bench_2_age,
                           b3.name as bench_3_name, b3.position as bench_3_pos, b3.ovr as bench_3_ovr, b3.age as bench_3_age,
                           g1.name as gleague_1_name, g1.position as gleague_1_pos, g1.ovr as gleague_1_ovr, g1.age as gleague_1_age,
                           g2.name as gleague_2_name, g2.position as gleague_2_pos, g2.ovr as gleague_2_ovr, g2.age as gleague_2_age
                    FROM team_directives td
                    LEFT JOIN teams t ON td.team_id = t.id
                    LEFT JOIN players s1 ON td.starter_1_id = s1.id
                    LEFT JOIN players s2 ON td.starter_2_id = s2.id
                    LEFT JOIN players s3 ON td.starter_3_id = s3.id
                    LEFT JOIN players s4 ON td.starter_4_id = s4.id
                    LEFT JOIN players s5 ON td.starter_5_id = s5.id
                    LEFT JOIN players b1 ON td.bench_1_id = b1.id
                    LEFT JOIN players b2 ON td.bench_2_id = b2.id
                    LEFT JOIN players b3 ON td.bench_3_id = b3.id
                    LEFT JOIN players g1 ON td.gleague_1_id = g1.id
                    LEFT JOIN players g2 ON td.gleague_2_id = g2.id
                    WHERE t.league = ?
                    ORDER BY COALESCE(td.updated_at, td.submitted_at) DESC, t.name
                    LIMIT 200
                ");
                $stmt->execute([$league]);
                $directives = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (!$strict && !$directives) {
                $fallback = true;
                $stmt = $pdo->prepare("
                    SELECT td.*, COALESCE(t.city, 'Time') as city, COALESCE(t.name, 'Desconhecido') as team_name,
                           s1.name as starter_1_name, s1.position as starter_1_pos, s1.ovr as starter_1_ovr, s1.age as starter_1_age,
                           s2.name as starter_2_name, s2.position as starter_2_pos, s2.ovr as starter_2_ovr, s2.age as starter_2_age,
                           s3.name as starter_3_name, s3.position as starter_3_pos, s3.ovr as starter_3_ovr, s3.age as starter_3_age,
                           s4.name as starter_4_name, s4.position as starter_4_pos, s4.ovr as starter_4_ovr, s4.age as starter_4_age,
                           s5.name as starter_5_name, s5.position as starter_5_pos, s5.ovr as starter_5_ovr, s5.age as starter_5_age,
                           b1.name as bench_1_name, b1.position as bench_1_pos, b1.ovr as bench_1_ovr, b1.age as bench_1_age,
                           b2.name as bench_2_name, b2.position as bench_2_pos, b2.ovr as bench_2_ovr, b2.age as bench_2_age,
                           b3.name as bench_3_name, b3.position as bench_3_pos, b3.ovr as bench_3_ovr, b3.age as bench_3_age,
                           g1.name as gleague_1_name, g1.position as gleague_1_pos, g1.ovr as gleague_1_ovr, g1.age as gleague_1_age,
                           g2.name as gleague_2_name, g2.position as gleague_2_pos, g2.ovr as gleague_2_ovr, g2.age as gleague_2_age
                    FROM team_directives td
                    LEFT JOIN teams t ON td.team_id = t.id
                    LEFT JOIN players s1 ON td.starter_1_id = s1.id
                    LEFT JOIN players s2 ON td.starter_2_id = s2.id
                    LEFT JOIN players s3 ON td.starter_3_id = s3.id
                    LEFT JOIN players s4 ON td.starter_4_id = s4.id
                    LEFT JOIN players s5 ON td.starter_5_id = s5.id
                    LEFT JOIN players b1 ON td.bench_1_id = b1.id
                    LEFT JOIN players b2 ON td.bench_2_id = b2.id
                    LEFT JOIN players b3 ON td.bench_3_id = b3.id
                    LEFT JOIN players g1 ON td.gleague_1_id = g1.id
                    LEFT JOIN players g2 ON td.gleague_2_id = g2.id
                    ORDER BY COALESCE(td.updated_at, td.submitted_at, td.created_at) DESC, t.name
                    LIMIT 200
                ");
                $stmt->execute();
                $directives = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Anexar minutos por jogador para cada diretriz (admin visualização)
            if ($directives) {
                $directiveIds = array_column($directives, 'id');
                if (!empty($directiveIds)) {
                    $placeholders = implode(',', array_fill(0, count($directiveIds), '?'));
                    
                    // Buscar minutagem com nome e posição do jogador
                    $stmtMin = $pdo->prepare("
                        SELECT dpm.directive_id, dpm.player_id, dpm.minutes_per_game, 
                               p.name as player_name, p.position as player_position,
                               p.ovr as player_ovr, p.age as player_age
                        FROM directive_player_minutes dpm
                        INNER JOIN players p ON dpm.player_id = p.id
                        WHERE dpm.directive_id IN ($placeholders)
                    ");
                    $stmtMin->execute($directiveIds);
                    $minRows = $stmtMin->fetchAll(PDO::FETCH_ASSOC);
                    
                    $minutesByDirective = [];
                    $playerInfoByDirective = [];
                    foreach ($minRows as $mr) {
                        $dId = (int)$mr['directive_id'];
                        $pId = (int)$mr['player_id'];
                        if (!isset($minutesByDirective[$dId])) $minutesByDirective[$dId] = [];
                        if (!isset($playerInfoByDirective[$dId])) $playerInfoByDirective[$dId] = [];
                        $minutesByDirective[$dId][$pId] = (int)$mr['minutes_per_game'];
                        $playerInfoByDirective[$dId][$pId] = [
                            'name' => $mr['player_name'],
                            'position' => $mr['player_position'],
                            'ovr' => $mr['player_ovr'],
                            'age' => $mr['player_age']
                        ];
                    }
                    foreach ($directives as &$dRow) {
                        $id = (int)$dRow['id'];
                        $dRow['player_minutes'] = $minutesByDirective[$id] ?? [];
                        $dRow['player_info'] = $playerInfoByDirective[$id] ?? [];
                    }
                    unset($dRow);
                }
            }

            // Anexar diretriz anterior por time para comparação
            if ($directives) {
                $prevStmt = $pdo->prepare("SELECT * FROM team_directives WHERE team_id = ? AND COALESCE(updated_at, submitted_at, created_at) < ? ORDER BY COALESCE(updated_at, submitted_at, created_at) DESC LIMIT 1");
                $prevMinutesStmt = $pdo->prepare("SELECT player_id, minutes_per_game FROM directive_player_minutes WHERE directive_id = ?");

                $prevFields = [
                    'id',
                    'team_id',
                    'deadline_id',
                    'starter_1_id', 'starter_2_id', 'starter_3_id', 'starter_4_id', 'starter_5_id',
                    'bench_1_id', 'bench_2_id', 'bench_3_id',
                    'pace', 'offensive_rebound', 'offensive_aggression', 'defensive_rebound', 'defensive_focus',
                    'rotation_style', 'game_style', 'offense_style',
                    'rotation_players', 'veteran_focus',
                    'gleague_1_id', 'gleague_2_id',
                    'notes', 'technical_model', 'playbook',
                    'submitted_at', 'updated_at', 'created_at'
                ];

                foreach ($directives as &$dRow) {
                    $currentTs = $dRow['updated_at'] ?? $dRow['submitted_at'] ?? $dRow['created_at'] ?? null;
                    if (!$currentTs || empty($dRow['team_id'])) {
                        continue;
                    }

                    $prevStmt->execute([(int)$dRow['team_id'], $currentTs]);
                    $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$prev) {
                        continue;
                    }

                    $prevFiltered = array_intersect_key($prev, array_flip($prevFields));
                    $prevMinutesStmt->execute([$prev['id']]);
                    $prevMinutesRows = $prevMinutesStmt->fetchAll(PDO::FETCH_ASSOC);
                    $prevMinutes = [];
                    foreach ($prevMinutesRows as $pmRow) {
                        $prevMinutes[(int)$pmRow['player_id']] = (int)$pmRow['minutes_per_game'];
                    }
                    $prevFiltered['player_minutes'] = $prevMinutes;

                    $dRow['previous_directive'] = $prevFiltered;
                }
                unset($dRow);
            }

            $deadlineCount = 0;
            $leagueCount = 0;
            $leagueJoinCount = 0;
            $totalDirectives = 0;
            try {
                $totalDirectives = (int)$pdo->query('SELECT COUNT(*) FROM team_directives')->fetchColumn();
                if ($deadlineId) {
                    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM team_directives WHERE deadline_id = ?');
                    $stmtCount->execute([$deadlineId]);
                    $deadlineCount = (int)$stmtCount->fetchColumn();
                }
                if ($league) {
                    $stmtLeague = $pdo->prepare('SELECT COUNT(*) FROM team_directives WHERE team_id IN (SELECT id FROM teams WHERE league = ?)');
                    $stmtLeague->execute([$league]);
                    $leagueCount = (int)$stmtLeague->fetchColumn();

                    $stmtLeagueJoin = $pdo->prepare('SELECT COUNT(*) FROM team_directives td LEFT JOIN teams t ON td.team_id = t.id WHERE t.league = ?');
                    $stmtLeagueJoin->execute([$league]);
                    $leagueJoinCount = (int)$stmtLeagueJoin->fetchColumn();
                }
            } catch (Exception $e) {
                // manter 0
            }
            $debugInfo = $debug ? [
                'total_directives' => $totalDirectives,
                'deadline_count' => $deadlineCount,
                'league_count' => $leagueCount,
                'league_join_count' => $leagueJoinCount
            ] : null;

            echo json_encode(['success' => true, 'directives' => $directives, 'fallback' => $fallback, 'debug' => $debugInfo]);
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
    $stmtTeam = $pdo->prepare('SELECT id, league, directive_profile, directive_profile_updated_at, technical_model_current, technical_model_changes_used FROM teams WHERE user_id = ? LIMIT 1');
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

            // Verificar se o prazo ainda está válido (não expirou - usando horário de Brasília)
            $nowBrasilia = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
            $stmtCheckDeadline = $pdo->prepare("SELECT * FROM directive_deadlines WHERE id = ? AND is_active = 1 AND deadline_date > ?");
            $stmtCheckDeadline->execute([$deadlineId, $nowBrasilia]);
            $validDeadline = $stmtCheckDeadline->fetch();
            if (!$validDeadline) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'O prazo para envio de diretrizes já expirou']);
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

            // Validar banco (dinâmico - pode ter 0 ou mais jogadores)
            $benchPlayers = $data['bench_players'] ?? [];
            if (!is_array($benchPlayers)) {
                $benchPlayers = [];
            }
            $benchPlayers = array_values(array_filter($benchPlayers));
            
            // Preencher bench_1_id, bench_2_id, bench_3_id para compatibilidade (primeiros 3)
            $bench1 = $benchPlayers[0] ?? null;
            $bench2 = $benchPlayers[1] ?? null;
            $bench3 = $benchPlayers[2] ?? null;

            try {
                $pdo->beginTransaction();

                $isElite = strtoupper($team['league'] ?? '') === 'ELITE';
                $technicalModel = $isElite ? ($data['technical_model'] ?? null) : null;
                $playbook = $isElite ? ($data['playbook'] ?? null) : null;

                $changesUsed = (int)($team['technical_model_changes_used'] ?? 0);
                $changesLimit = 3;
                $currentModel = $team['technical_model_current'] ?? null;
                $isModelChoice = $technicalModel && $technicalModel !== 'FBA 14';
                $modelChanged = $isModelChoice && $technicalModel !== $currentModel;
                if ($isElite && $modelChanged && $changesUsed >= $changesLimit) {
                    throw new Exception('Limite de mudanças do modelo técnico atingido (3 escolhas).');
                }

                // Verificar se já existe diretriz para este prazo
                $stmtCheck = $pdo->prepare('SELECT id FROM team_directives WHERE team_id = ? AND deadline_id = ?');
                $stmtCheck->execute([$team['id'], $deadlineId]);
                $existing = $stmtCheck->fetch();

                $technicalModelChangedFlag = $modelChanged ? 1 : 0;

                if ($existing) {
                    $stmt = $pdo->prepare("
                        UPDATE team_directives SET
                            starter_1_id = ?, starter_2_id = ?, starter_3_id = ?, starter_4_id = ?, starter_5_id = ?,
                            bench_1_id = ?, bench_2_id = ?, bench_3_id = ?,
                            pace = ?, offensive_rebound = ?, offensive_aggression = ?, defensive_rebound = ?, defensive_focus = ?,
                            rotation_style = ?, game_style = ?, offense_style = ?,
                            rotation_players = ?, veteran_focus = ?,
                            gleague_1_id = ?, gleague_2_id = ?,
                            notes = ?, technical_model = ?, technical_model_changed = ?, playbook = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $data['starter_1_id'], $data['starter_2_id'], $data['starter_3_id'], $data['starter_4_id'], $data['starter_5_id'],
                        $bench1, $bench2, $bench3,
                        $data['pace'] ?? 'no_preference', $data['offensive_rebound'] ?? 'no_preference', $data['offensive_aggression'] ?? 'no_preference', $data['defensive_rebound'] ?? 'no_preference', $data['defensive_focus'] ?? 'no_preference',
                        $data['rotation_style'] ?? 'auto', $data['game_style'] ?? 'balanced', $data['offense_style'] ?? 'no_preference',
                        $data['rotation_players'] ?? 10, $data['veteran_focus'] ?? 50,
                        $data['gleague_1_id'] ?? null, $data['gleague_2_id'] ?? null,
                        $data['notes'] ?? null,
                        $technicalModel,
                        $technicalModelChangedFlag,
                        $playbook,
                        $existing['id']
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO team_directives (
                            team_id, deadline_id,
                            starter_1_id, starter_2_id, starter_3_id, starter_4_id, starter_5_id,
                            bench_1_id, bench_2_id, bench_3_id,
                            pace, offensive_rebound, offensive_aggression, defensive_rebound, defensive_focus,
                            rotation_style, game_style, offense_style,
                            rotation_players, veteran_focus,
                            gleague_1_id, gleague_2_id, notes,
                            technical_model, technical_model_changed, playbook
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $team['id'], $deadlineId,
                        $data['starter_1_id'], $data['starter_2_id'], $data['starter_3_id'], $data['starter_4_id'], $data['starter_5_id'],
                        $bench1, $bench2, $bench3,
                        $data['pace'] ?? 'no_preference', $data['offensive_rebound'] ?? 'no_preference', $data['offensive_aggression'] ?? 'no_preference', $data['defensive_rebound'] ?? 'no_preference', $data['defensive_focus'] ?? 'no_preference',
                        $data['rotation_style'] ?? 'auto', $data['game_style'] ?? 'balanced', $data['offense_style'] ?? 'no_preference',
                        $data['rotation_players'] ?? 10, $data['veteran_focus'] ?? 50,
                        $data['gleague_1_id'] ?? null, $data['gleague_2_id'] ?? null,
                        $data['notes'] ?? null,
                        $technicalModel,
                        $technicalModelChangedFlag,
                        $playbook
                    ]);
                }

                $directiveId = $existing['id'] ?? $pdo->lastInsertId();

                // Salvar minutagem para todos os jogadores selecionados (titulares + banco)
                $allSelectedPlayers = array_merge($starters, $benchPlayers);
                
                // Verificar se rotação é manual
                $rotationStyle = $data['rotation_style'] ?? 'auto';
                
                // Validar por fase do prazo
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

                // Inserir minutagem para todos os jogadores selecionados
                $playerMinutes = $data['player_minutes'] ?? [];
                $allowInvalid = !empty($data['allow_invalid']);
                $stmtMinutes = $pdo->prepare('INSERT INTO directive_player_minutes (directive_id, player_id, minutes_per_game) VALUES (?, ?, ?)');
                
                // Se rotação for manual, validar total de 240 minutos
                if (!$allowInvalid && $rotationStyle === 'manual') {
                    $totalMinutes = 0;
                    foreach ($allSelectedPlayers as $playerId) {
                        $m = isset($playerMinutes[$playerId]) ? (int)$playerMinutes[$playerId] : 20;
                        $totalMinutes += $m;
                    }
                    
                    if ($totalMinutes !== 240) {
                        throw new Exception("A soma dos minutos deve ser exatamente 240. Atual: {$totalMinutes} minutos.");
                    }
                }
                
                // Buscar OVRs dos jogadores selecionados
                $placeholders = implode(',', array_fill(0, count($allSelectedPlayers), '?'));
                $stmtOvrs = $pdo->prepare("SELECT id, name, ovr FROM players WHERE id IN ($placeholders) ORDER BY ovr DESC");
                $stmtOvrs->execute($allSelectedPlayers);
                $playersWithOvr = $stmtOvrs->fetchAll(PDO::FETCH_ASSOC);
                
                // Mapear OVR por player_id
                $ovrMap = [];
                foreach ($playersWithOvr as $p) {
                    $ovrMap[(int)$p['id']] = [
                        'ovr' => (int)$p['ovr'],
                        'name' => $p['name']
                    ];
                }
                
                // Contar quantos jogadores 85+
                $count85Plus = 0;
                foreach ($playersWithOvr as $p) {
                    if ((int)$p['ovr'] >= 85) {
                        $count85Plus++;
                    }
                }
                
                // Se não tem 3 jogadores 85+, identificar os 5 maiores OVRs
                $top5Ids = [];
                if ($count85Plus < 3) {
                    $top5 = array_slice($playersWithOvr, 0, 5);
                    $top5Ids = array_map(function($p) { return (int)$p['id']; }, $top5);
                }
                
                foreach ($allSelectedPlayers as $playerId) {
                    $m = isset($playerMinutes[$playerId]) ? (int)$playerMinutes[$playerId] : 20;
                    $playerIdInt = (int)$playerId;
                    $playerName = $ovrMap[$playerIdInt]['name'] ?? "Jogador {$playerIdInt}";
                    $isStarter = in_array($playerIdInt, $starters);
                    $isTop5 = in_array($playerIdInt, $top5Ids);
                    
                    // Validação: Titulares devem ter no mínimo 25 minutos
                    if (!$allowInvalid && $isStarter && $m < 25) {
                        throw new Exception("Titular {$playerName} deve jogar no mínimo 25 minutos.");
                    }
                    
                    // Validação: Reservas devem ter no mínimo 5 minutos
                    if (!$allowInvalid && !$isStarter && $m < 5) {
                        throw new Exception("Reserva {$playerName} deve jogar no mínimo 5 minutos.");
                    }
                    
                    // Regra informativa: Se time não tem 3 jogadores 85+, top 5 OVRs idealmente 25+ minutos
                    // (não bloqueia envio)
                    
                    if (!$allowInvalid && $m > $maxMinutes) {
                        throw new Exception("Jogador {$playerName} não pode jogar mais de {$maxMinutes} minutos.");
                    }
                    $stmtMinutes->execute([$directiveId, $playerIdInt, $m]);
                }

                // Atualizar modelo técnico do time (contando mudanças)
                $modelNotice = null;
                $changesRemaining = max(0, $changesLimit - $changesUsed);
                if ($isElite && $modelChanged) {
                    $changesUsed++;
                    $changesRemaining = max(0, $changesLimit - $changesUsed);
                    $stmtModel = $pdo->prepare('UPDATE teams SET technical_model_current = ?, technical_model_changes_used = ? WHERE id = ?');
                    $stmtModel->execute([$technicalModel, $changesUsed, $team['id']]);
                    $modelNotice = 'Modelo técnico atualizado. Você perdeu 1 mudança.';
                }

                // Salvar diretriz base do time para reutilização
                saveTeamDirectiveProfile($pdo, (int)$team['id'], $data);

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Diretriz enviada com sucesso',
                    'model_change_notice' => $modelNotice,
                    'technical_model_changes_used' => $changesUsed,
                    'technical_model_changes_limit' => $changesLimit,
                    'technical_model_changes_remaining' => $changesRemaining,
                    'technical_model_current' => $modelChanged ? $technicalModel : $currentModel
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro ao enviar diretriz: ' . $e->getMessage()]);
            }
            break;

        case 'save_team_profile':
            // Salvar diretriz base do time (sem prazo)
            // Validar quinteto titular (5 jogadores)
            $startersProfile = array_filter([
                $data['starter_1_id'] ?? null,
                $data['starter_2_id'] ?? null,
                $data['starter_3_id'] ?? null,
                $data['starter_4_id'] ?? null,
                $data['starter_5_id'] ?? null
            ]);
            if (count($startersProfile) !== 5) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Quinteto titular deve ter exatamente 5 jogadores']);
                exit;
            }
            try {
                $pdo->beginTransaction();
                saveTeamDirectiveProfile($pdo, (int)$team['id'], $data);
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Diretriz do time salva com sucesso']);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro ao salvar diretriz do time: ' . $e->getMessage()]);
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
