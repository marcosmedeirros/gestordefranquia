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

// GET - Buscar diretrizes ou prazos
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'get_directives';
    
    // Buscar time do usuário
    $stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
    $stmtTeam->execute([$user['id']]);
    $team = $stmtTeam->fetch();
    
    if (!$team && $action !== 'list_deadlines_admin') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Usuário sem time']);
        exit;
    }
    
    switch ($action) {
        case 'active_deadline':
            // Buscar prazo ativo para a liga do time
            $stmt = $pdo->prepare("
                SELECT * FROM directive_deadlines 
                WHERE league = ? AND is_active = 1 
                ORDER BY deadline_date DESC LIMIT 1
            ");
            $stmt->execute([$team['league']]);
            $deadline = $stmt->fetch();
            
            echo json_encode(['success' => true, 'deadline' => $deadline]);
            break;
            
        case 'my_directive':
            // Buscar diretriz enviada pelo time
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
                LEFT JOIN players b2 on td.bench_2_id = b2.id
                LEFT JOIN players b3 ON td.bench_3_id = b3.id
                WHERE td.team_id = ? AND td.deadline_id = ?
            ");
            $stmt->execute([$team['id'], $deadlineId]);
            $directive = $stmt->fetch();
            
            // Buscar minutagem dos jogadores
            $playerMinutes = [];
            if ($directive) {
                $stmtMinutes = $pdo->prepare("
                    SELECT player_id, minutes_per_game 
                    FROM directive_player_minutes 
                    WHERE directive_id = ?
                ");
                $stmtMinutes->execute([$directive['id']]);
                $minutesData = $stmtMinutes->fetchAll(PDO::FETCH_ASSOC);
                foreach ($minutesData as $row) {
                    $playerMinutes[$row['player_id']] = $row['minutes_per_game'];
                }
                $directive['player_minutes'] = $playerMinutes;
            }
            
            echo json_encode(['success' => true, 'directive' => $directive]);
            break;
            
        case 'list_deadlines_admin':
            // ADMIN: Listar todos os prazos
            if (($user['user_type'] ?? 'jogador') !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
                exit;
            }
            
            $league = $_GET['league'] ?? null;
            $where = $league ? 'WHERE league = ?' : '';
            $params = $league ? [$league] : [];
            
            $stmt = $pdo->prepare("
                SELECT dd.*,
                       (SELECT COUNT(*) FROM team_directives WHERE deadline_id = dd.id) as submissions_count
                FROM directive_deadlines dd
                $where
                ORDER BY deadline_date DESC
            ");
            $stmt->execute($params);
            $deadlines = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'deadlines' => $deadlines]);
            break;
            
        case 'view_all_directives_admin':
            // ADMIN: Ver todas as diretrizes de um prazo
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
                SELECT td.*,
                       t.city, t.name as team_name,
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
            $directives = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'directives' => $directives]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

// POST - Enviar/atualizar diretriz
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? 'submit_directive';
    
    // Buscar time do usuário
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
                    // Atualizar
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
                        $data['starter_1_id'], $data['starter_2_id'], $data['starter_3_id'], 
                        $data['starter_4_id'], $data['starter_5_id'],
                        $data['bench_1_id'], $data['bench_2_id'], $data['bench_3_id'],
                        $data['pace'] ?? 'no_preference', $data['offensive_rebound'] ?? 'no_preference', 
                        $data['offensive_aggression'] ?? 'no_preference', $data['defensive_rebound'] ?? 'no_preference',
                        $data['rotation_style'] ?? 'auto', $data['game_style'] ?? 'balanced',
                        $data['offense_style'] ?? 'no_preference',
                        $data['rotation_players'] ?? 10, $data['veteran_focus'] ?? 50,
                        $data['gleague_1_id'] ?? null, $data['gleague_2_id'] ?? null,
                        $data['notes'] ?? null,
                        $existing['id']
                    ]);
                } else {
                    // Inserir
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
                        $data['starter_1_id'], $data['starter_2_id'], $data['starter_3_id'], 
                        $data['starter_4_id'], $data['starter_5_id'],
                        $data['bench_1_id'], $data['bench_2_id'], $data['bench_3_id'],
                        $data['pace'] ?? 'no_preference', $data['offensive_rebound'] ?? 'no_preference', 
                        $data['offensive_aggression'] ?? 'no_preference', $data['defensive_rebound'] ?? 'no_preference',
                        $data['rotation_style'] ?? 'auto', $data['game_style'] ?? 'balanced',
                        $data['offense_style'] ?? 'no_preference',
                        $data['rotation_players'] ?? 10, $data['veteran_focus'] ?? 50,
                        $data['gleague_1_id'] ?? null, $data['gleague_2_id'] ?? null,
                        $data['notes'] ?? null
                    ]);
                }
                
                $directiveId = $existing['id'] ?? $pdo->lastInsertId();
                
                // Salvar minutagem dos jogadores se rotação automática
                if (($data['rotation_style'] ?? 'auto') === 'auto' && !empty($data['player_minutes'])) {
                    // Deletar minutagens antigas
                    $stmtDeleteMinutes = $pdo->prepare('DELETE FROM directive_player_minutes WHERE directive_id = ?');
                    $stmtDeleteMinutes->execute([$directiveId]);
                    
                    // Inserir novas minutagens
                    $stmtMinutes = $pdo->prepare("
                        INSERT INTO directive_player_minutes (directive_id, player_id, minutes_per_game)
                        VALUES (?, ?, ?)
                    ");
                    
                    foreach ($data['player_minutes'] as $playerId => $minutes) {
                        $stmtMinutes->execute([$directiveId, $playerId, (int)$minutes]);
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
            $description = $data['description'] ?? null;
            
            if (!$league || !$deadlineDate) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga e data obrigatórios']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO directive_deadlines (league, deadline_date, description)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$league, $deadlineDate, $description]);
            
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
    
    $stmt = $pdo->prepare("
        UPDATE directive_deadlines 
        SET deadline_date = ?, description = ?, is_active = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $data['deadline_date'] ?? null,
        $data['description'] ?? null,
        isset($data['is_active']) ? (int)$data['is_active'] : 1,
        $deadlineId
    ]);
    
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
        // Deletar uma diretriz específica de um time
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
    
    // Default: delete deadline
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
