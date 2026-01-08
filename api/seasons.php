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
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ==================== ADMIN ONLY ACTIONS ====================
$adminActions = ['create_season', 'end_season', 'start_draft', 'end_draft', 'add_draft_player', 
                 'update_draft_player', 'delete_draft_player', 'assign_draft_pick', 
                 'set_standings', 'set_playoff_results', 'set_awards', 'reset_sprint'];

if (in_array($action, $adminActions) && ($user['user_type'] ?? 'jogador') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
    exit;
}

try {
    switch ($action) {
        // ========== BUSCAR TEMPORADA ATUAL ==========
        case 'current_season':
            $league = $_GET['league'] ?? null;
            if (!$league) {
                throw new Exception('Liga não especificada');
            }
            
            $stmt = $pdo->prepare("
                SELECT s.*, sp.sprint_number, sp.status as sprint_status,
                       lsc.max_seasons as sprint_max_seasons
                FROM seasons s
                INNER JOIN sprints sp ON s.sprint_id = sp.id
                INNER JOIN league_sprint_config lsc ON s.league = lsc.league
                WHERE s.league = ? AND s.status != 'completed'
                ORDER BY s.id DESC LIMIT 1
            ");
            $stmt->execute([$league]);
            $season = $stmt->fetch();
            
            echo json_encode(['success' => true, 'season' => $season]);
            break;

        // ========== LISTAR TODAS AS TEMPORADAS ==========
        case 'list_seasons':
            $league = $_GET['league'] ?? null;
            $where = $league ? "WHERE s.league = ?" : "";
            $params = $league ? [$league] : [];
            
            $stmt = $pdo->prepare("
                SELECT s.*, sp.sprint_number,
                       (SELECT COUNT(*) FROM draft_pool WHERE season_id = s.id) as draft_players_count
                FROM seasons s
                INNER JOIN sprints sp ON s.sprint_id = sp.id
                $where
                ORDER BY s.id DESC
            ");
            $stmt->execute($params);
            $seasons = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'seasons' => $seasons]);
            break;

        // ========== CRIAR NOVA TEMPORADA (ADMIN) ==========
        case 'create_season':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $data = json_decode(file_get_contents('php://input'), true);
            $league = $data['league'] ?? null;
            
            if (!$league || !in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'])) {
                throw new Exception('Liga inválida');
            }
            
            $pdo->beginTransaction();
            
            // Buscar ou criar sprint atual
            $stmtSprint = $pdo->prepare("
                SELECT id, sprint_number FROM sprints 
                WHERE league = ? AND status = 'active' 
                ORDER BY id DESC LIMIT 1
            ");
            $stmtSprint->execute([$league]);
            $sprint = $stmtSprint->fetch();
            
            if (!$sprint) {
                // Criar primeiro sprint
                $stmtCreate = $pdo->prepare("
                    INSERT INTO sprints (league, sprint_number, start_date) 
                    VALUES (?, 1, CURDATE())
                ");
                $stmtCreate->execute([$league]);
                $sprintId = $pdo->lastInsertId();
                $sprintNumber = 1;
            } else {
                $sprintId = $sprint['id'];
                $sprintNumber = $sprint['sprint_number'];
            }
            
            // Contar temporadas no sprint atual
            $stmtCount = $pdo->prepare("
                SELECT COUNT(*) as count FROM seasons WHERE sprint_id = ?
            ");
            $stmtCount->execute([$sprintId]);
            $seasonCount = $stmtCount->fetch()['count'];
            
            // Buscar limite de temporadas
            $stmtConfig = $pdo->prepare("SELECT max_seasons FROM league_sprint_config WHERE league = ?");
            $stmtConfig->execute([$league]);
            $maxSeasons = $stmtConfig->fetch()['max_seasons'];
            
            if ($seasonCount >= $maxSeasons) {
                throw new Exception("Sprint já completou o máximo de {$maxSeasons} temporadas. Inicie um novo sprint.");
            }
            
            // Criar nova temporada
            $seasonNumber = $seasonCount + 1;
            $year = date('Y');
            
            $stmtSeason = $pdo->prepare("
                INSERT INTO seasons (sprint_id, league, season_number, year, start_date, status, current_phase)
                VALUES (?, ?, ?, ?, CURDATE(), 'draft', 'draft')
            ");
            $stmtSeason->execute([$sprintId, $league, $seasonNumber, $year]);
            $seasonId = $pdo->lastInsertId();
            
            // Gerar picks automaticamente para todos os times da liga
            // IMPORTANTE: Gera picks para TODAS as temporadas do sprint (não apenas a atual)
            $stmtTeams = $pdo->prepare("SELECT id FROM teams WHERE league = ?");
            $stmtTeams->execute([$league]);
            $teams = $stmtTeams->fetchAll();
            
            $stmtPick = $pdo->prepare("
                INSERT INTO picks (team_id, original_team_id, season_year, round, season_id, auto_generated)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            
            // Para cada time, gerar 2 picks POR TEMPORADA do sprint
            foreach ($teams as $team) {
                for ($tempNum = 1; $tempNum <= $maxSeasons; $tempNum++) {
                    $yearLabel = "Ano " . str_pad($tempNum, 2, '0', STR_PAD_LEFT); // "Ano 01", "Ano 02", etc
                    
                    // Pick Rodada 1 para temporada X
                    $pickLabel = "T{$tempNum} R1";
                    $stmtPick->execute([$team['id'], $team['id'], $yearLabel, $pickLabel, $seasonId]);
                    
                    // Pick Rodada 2 para temporada X
                    $pickLabel = "T{$tempNum} R2";
                    $stmtPick->execute([$team['id'], $team['id'], $yearLabel, $pickLabel, $seasonId]);
                }
            }
            
            $totalPicksPerTeam = $maxSeasons * 2; // 2 picks por temporada
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'season_id' => $seasonId,
                'message' => "Temporada {$seasonNumber} criada! Geradas {$totalPicksPerTeam} picks por time ({$maxSeasons} temporadas x 2 rodadas)."
            ]);
            break;

        // ========== BUSCAR JOGADORES DO DRAFT ==========
        case 'draft_players':
            $seasonId = $_GET['season_id'] ?? null;
            if (!$seasonId) throw new Exception('Season ID não especificado');
            
            $stmt = $pdo->prepare("
                SELECT 
                    dp.*,
                    CONCAT(t.city, ' ', t.name) as team_name
                FROM draft_pool dp
                LEFT JOIN teams t ON dp.drafted_by_team_id = t.id
                WHERE dp.season_id = ? 
                ORDER BY dp.draft_status ASC, dp.draft_order ASC, dp.ovr DESC, dp.name ASC
            ");
            $stmt->execute([$seasonId]);
            $players = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'players' => $players]);
            break;

        // ========== ADICIONAR JOGADOR NO DRAFT POOL (ADMIN) ==========
        case 'add_draft_player':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("
                INSERT INTO draft_pool (season_id, name, position, age, ovr, photo_url, bio, strengths, weaknesses)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['season_id'],
                $data['name'],
                $data['position'],
                $data['age'],
                $data['ovr'],
                $data['photo_url'] ?? null,
                $data['bio'] ?? null,
                $data['strengths'] ?? null,
                $data['weaknesses'] ?? null
            ]);
            
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        // ========== ATUALIZAR JOGADOR DO DRAFT (ADMIN) ==========
        case 'update_draft_player':
            if ($method !== 'PUT') throw new Exception('Método inválido');
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("
                UPDATE draft_pool 
                SET name = ?, position = ?, age = ?, ovr = ?, 
                    photo_url = ?, bio = ?, strengths = ?, weaknesses = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['name'],
                $data['position'],
                $data['age'],
                $data['ovr'],
                $data['photo_url'] ?? null,
                $data['bio'] ?? null,
                $data['strengths'] ?? null,
                $data['weaknesses'] ?? null,
                $data['id']
            ]);
            
            echo json_encode(['success' => true]);
            break;

        // ========== DELETAR JOGADOR DO DRAFT (ADMIN) ==========
        case 'delete_draft_player':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['player_id'] ?? null;
            if (!$id) throw new Exception('ID não especificado');
            
            $stmt = $pdo->prepare("DELETE FROM draft_pool WHERE id = ? AND draft_status = 'available'");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true]);
            break;

        // ========== ATRIBUIR JOGADOR DRAFTADO A UM TIME (ADMIN) ==========
        case 'assign_draft_pick':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['team_id']) || !isset($data['player_id'])) {
                throw new Exception('team_id e player_id são obrigatórios');
            }
            
            $pdo->beginTransaction();
            
            // Buscar próximo draft_order
            $stmtOrder = $pdo->prepare("SELECT COALESCE(MAX(draft_order), 0) + 1 as next_order FROM draft_pool WHERE draft_status = 'drafted'");
            $stmtOrder->execute();
            $nextOrder = $stmtOrder->fetch()['next_order'];
            
            // Atualizar draft_pool
            $stmt = $pdo->prepare("
                UPDATE draft_pool 
                SET draft_status = 'drafted', drafted_by_team_id = ?, draft_order = ?
                WHERE id = ?
            ");
            $stmt->execute([$data['team_id'], $nextOrder, $data['player_id']]);
            
            // Adicionar jogador ao elenco do time
            $stmtPlayer = $pdo->prepare("SELECT * FROM draft_pool WHERE id = ?");
            $stmtPlayer->execute([$data['player_id']]);
            $draftPlayer = $stmtPlayer->fetch();
            
            $stmtInsert = $pdo->prepare("
                INSERT INTO players (team_id, name, position, age, ovr, role, available_for_trade)
                VALUES (?, ?, ?, ?, ?, 'Reserva', 0)
            ");
            $stmtInsert->execute([
                $data['team_id'],
                $draftPlayer['name'],
                $draftPlayer['position'],
                $draftPlayer['age'],
                $draftPlayer['ovr']
            ]);
            
            $pdo->commit();
            
            echo json_encode(['success' => true]);
            break;

        // ========== BUSCAR RANKING GLOBAL ==========
        case 'global_ranking':
            $stmt = $pdo->query("SELECT * FROM vw_global_ranking LIMIT 100");
            $ranking = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'ranking' => $ranking]);
            break;

        // ========== BUSCAR RANKING POR LIGA ==========
        case 'league_ranking':
            $league = $_GET['league'] ?? null;
            if (!$league) throw new Exception('Liga não especificada');
            
            $stmt = $pdo->prepare("SELECT * FROM vw_league_ranking WHERE league = ?");
            $stmt->execute([$league]);
            $ranking = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'ranking' => $ranking]);
            break;

        // ========== HISTÓRICO DE CAMPEÕES ==========
        case 'champions_history':
            $league = $_GET['league'] ?? null;
            $where = $league ? "WHERE league = ?" : "";
            $params = $league ? [$league] : [];
            
            $stmt = $pdo->prepare("SELECT * FROM vw_champions_history $where");
            $stmt->execute($params);
            $history = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'history' => $history]);
            break;

        // ========== SALVAR HISTÓRICO DA TEMPORADA ==========
        case 'save_history':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $input = json_decode(file_get_contents('php://input'), true);
            $seasonId = $input['season_id'] ?? null;
            $champion = $input['champion'] ?? null;
            $runnerUp = $input['runner_up'] ?? null;
            
            if (!$seasonId || !$champion || !$runnerUp) {
                throw new Exception('Dados incompletos: season_id, champion e runner_up são obrigatórios');
            }
            
            if ($champion == $runnerUp) {
                throw new Exception('Campeão e vice-campeão não podem ser o mesmo time');
            }
            
            $pdo->beginTransaction();
            
            // Salvar resultados dos playoffs
            $stmtDelete = $pdo->prepare("DELETE FROM playoff_results WHERE season_id = ?");
            $stmtDelete->execute([$seasonId]);
            
            $stmtPlayoff = $pdo->prepare("
                INSERT INTO playoff_results (season_id, team_id, position)
                VALUES (?, ?, ?)
            ");
            $stmtPlayoff->execute([$seasonId, $champion, 'champion']);
            $stmtPlayoff->execute([$seasonId, $runnerUp, 'runner_up']);
            
            // Salvar prêmios individuais
            $stmtDeleteAwards = $pdo->prepare("DELETE FROM season_awards WHERE season_id = ?");
            $stmtDeleteAwards->execute([$seasonId]);
            
            $stmtAward = $pdo->prepare("
                INSERT INTO season_awards (season_id, team_id, award_type)
                VALUES (?, ?, ?)
            ");
            
            if (!empty($input['mvp'])) {
                $stmtAward->execute([$seasonId, $input['mvp'], 'MVP']);
            }
            if (!empty($input['dpoy'])) {
                $stmtAward->execute([$seasonId, $input['dpoy'], 'DPOY']);
            }
            if (!empty($input['mip'])) {
                $stmtAward->execute([$seasonId, $input['mip'], 'MIP']);
            }
            if (!empty($input['sixth_man'])) {
                $stmtAward->execute([$seasonId, $input['sixth_man'], '6th Man']);
            }
            
            // Atualizar pontos no ranking (campeão +100, vice +50, prêmios +20 cada)
            $stmtGetSeason = $pdo->prepare("SELECT league, year FROM seasons WHERE id = ?");
            $stmtGetSeason->execute([$seasonId]);
            $season = $stmtGetSeason->fetch();
            
            if ($season) {
                // Campeão: 100 pontos
                $stmtPoints = $pdo->prepare("
                    INSERT INTO team_ranking_points (team_id, season_id, points, reason)
                    VALUES (?, ?, 100, 'Campeão')
                    ON DUPLICATE KEY UPDATE points = points + 100
                ");
                $stmtPoints->execute([$champion, $seasonId]);
                
                // Vice: 50 pontos
                $stmtPoints = $pdo->prepare("
                    INSERT INTO team_ranking_points (team_id, season_id, points, reason)
                    VALUES (?, ?, 50, 'Vice-Campeão')
                    ON DUPLICATE KEY UPDATE points = points + 50
                ");
                $stmtPoints->execute([$runnerUp, $seasonId]);
                
                // Prêmios: 20 pontos cada
                foreach (['mvp', 'dpoy', 'mip', 'sixth_man'] as $award) {
                    if (!empty($input[$award])) {
                        $stmtPoints = $pdo->prepare("
                            INSERT INTO team_ranking_points (team_id, season_id, points, reason)
                            VALUES (?, ?, 20, ?)
                            ON DUPLICATE KEY UPDATE points = points + 20
                        ");
                        $stmtPoints->execute([$input[$award], $seasonId, strtoupper($award)]);
                    }
                }
            }
            
            // Marcar temporada como completa
            $stmtComplete = $pdo->prepare("UPDATE seasons SET status = 'completed' WHERE id = ?");
            $stmtComplete->execute([$seasonId]);
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Histórico salvo com sucesso']);
            break;

        // ========== RESETAR SPRINT (NOVO CICLO) ==========
        case 'reset_sprint':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $input = json_decode(file_get_contents('php://input'), true);
            $league = $input['league'] ?? null;
            
            if (!$league) {
                throw new Exception('Liga não especificada');
            }
            
            $pdo->beginTransaction();
            
            // ATENÇÃO: Isso deleta TUDO da liga!
            
            // 1. Deletar picks relacionadas aos times da liga
            $pdo->exec("
                DELETE p FROM picks p
                INNER JOIN teams t ON p.team_id = t.id
                WHERE t.league = '$league'
            ");
            
            // 2. Deletar trades relacionados aos times da liga
            $pdo->exec("
                DELETE tr FROM trades tr
                INNER JOIN teams t1 ON tr.team_id_1 = t1.id
                WHERE t1.league = '$league'
            ");
            
            // 3. Deletar jogadores dos times da liga
            $pdo->exec("
                DELETE tp FROM team_players tp
                INNER JOIN teams t ON tp.team_id = t.id
                WHERE t.league = '$league'
            ");
            
            // 4. Deletar pontos de ranking dos times
            $pdo->exec("
                DELETE trp FROM team_ranking_points trp
                INNER JOIN teams t ON trp.team_id = t.id
                WHERE t.league = '$league'
            ");
            
            // 5. Deletar prêmios das temporadas
            $pdo->exec("
                DELETE sa FROM season_awards sa
                INNER JOIN seasons s ON sa.season_id = s.id
                WHERE s.league = '$league'
            ");
            
            // 6. Deletar resultados de playoffs
            $pdo->exec("
                DELETE pr FROM playoff_results pr
                INNER JOIN seasons s ON pr.season_id = s.id
                WHERE s.league = '$league'
            ");
            
            // 7. Deletar standings
            $pdo->exec("
                DELETE ss FROM season_standings ss
                INNER JOIN seasons s ON ss.season_id = s.id
                WHERE s.league = '$league'
            ");
            
            // 8. Deletar draft pool
            $pdo->exec("
                DELETE dp FROM draft_pool dp
                INNER JOIN seasons s ON dp.season_id = s.id
                WHERE s.league = '$league'
            ");
            
            // 9. Deletar temporadas
            $pdo->exec("DELETE FROM seasons WHERE league = '$league'");
            
            // 10. Deletar sprints
            $pdo->exec("DELETE FROM sprints WHERE league = '$league'");
            
            // 11. Deletar times (e seus usuários associados)
            $pdo->exec("
                DELETE u FROM users u
                INNER JOIN teams t ON u.id = t.user_id
                WHERE t.league = '$league'
            ");
            
            $pdo->exec("DELETE FROM teams WHERE league = '$league'");
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Sprint resetado com sucesso']);
            break;

        default:
            throw new Exception('Ação inválida');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

