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
            $stmtTeams = $pdo->prepare("SELECT id FROM teams WHERE league = ?");
            $stmtTeams->execute([$league]);
            $teams = $stmtTeams->fetchAll();
            
            $stmtPick = $pdo->prepare("
                INSERT INTO picks (team_id, original_team_id, season_year, round, season_id, auto_generated)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            
            foreach ($teams as $team) {
                // 1ª rodada
                $stmtPick->execute([$team['id'], $team['id'], $year, '1', $seasonId]);
                // 2ª rodada
                $stmtPick->execute([$team['id'], $team['id'], $year, '2', $seasonId]);
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'season_id' => $seasonId,
                'message' => "Temporada {$seasonNumber} criada com sucesso! Picks geradas automaticamente."
            ]);
            break;

        // ========== BUSCAR JOGADORES DO DRAFT ==========
        case 'draft_players':
            $seasonId = $_GET['season_id'] ?? null;
            if (!$seasonId) throw new Exception('Season ID não especificado');
            
            $stmt = $pdo->prepare("
                SELECT * FROM draft_pool 
                WHERE season_id = ? 
                ORDER BY draft_status ASC, ovr DESC, name ASC
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
            if ($method !== 'DELETE') throw new Exception('Método inválido');
            
            $id = $_GET['id'] ?? null;
            if (!$id) throw new Exception('ID não especificado');
            
            $stmt = $pdo->prepare("DELETE FROM draft_pool WHERE id = ? AND draft_status = 'available'");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true]);
            break;

        // ========== ATRIBUIR JOGADOR DRAFTADO A UM TIME (ADMIN) ==========
        case 'assign_draft_pick':
            if ($method !== 'POST') throw new Exception('Método inválido');
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $pdo->beginTransaction();
            
            // Atualizar draft_pool
            $stmt = $pdo->prepare("
                UPDATE draft_pool 
                SET draft_status = 'drafted', drafted_by_team_id = ?, draft_order = ?
                WHERE id = ?
            ");
            $stmt->execute([$data['team_id'], $data['draft_order'], $data['player_id']]);
            
            // Adicionar jogador ao elenco do time
            $stmtPlayer = $pdo->prepare("SELECT * FROM draft_pool WHERE id = ?");
            $stmtPlayer->execute([$data['player_id']]);
            $draftPlayer = $stmtPlayer->fetch();
            
            $stmtInsert = $pdo->prepare("
                INSERT INTO players (team_id, name, position, age, ovr, role, available_for_trade, photo_url)
                VALUES (?, ?, ?, ?, ?, 'Reserva', 0, ?)
            ");
            $stmtInsert->execute([
                $data['team_id'],
                $draftPlayer['name'],
                $draftPlayer['position'],
                $draftPlayer['age'],
                $draftPlayer['ovr'],
                $draftPlayer['photo_url']
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
