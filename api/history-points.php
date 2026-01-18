<?php
/**
 * API para Histórico e Pontos de Temporada
 * 
 * Endpoints:
 * - get_history: Busca histórico de todas as temporadas
 * - save_history: Salva histórico (Campeão, Vice, MVP, DPOY, MIP, 6º Homem)
 * - get_teams_for_points: Lista times por liga para registro de pontos
 * - save_season_points: Salva pontos dos times na temporada
 * - get_ranking: Busca ranking (soma de pontos)
 */

// Garantir que erros sejam retornados como JSON
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
    exit;
});

require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/helpers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_REQUEST['action'] ?? '';

// Obter usuário atual
$user = getUserSession();

// Verificar se é admin para ações protegidas
$adminActions = ['save_history', 'delete_history', 'save_season_points'];
if (in_array($action, $adminActions)) {
    if (!$user || ($user['user_type'] ?? 'jogador') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Apenas administradores podem realizar esta ação.']);
        exit;
    }
}

// Verificar se as tabelas existem
$pdo = db();
function checkTablesExist($pdo) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'season_history'");
    if ($stmt->rowCount() == 0) {
        return false;
    }
    $stmt = $pdo->query("SHOW TABLES LIKE 'team_season_points'");
    if ($stmt->rowCount() == 0) {
        return false;
    }
    return true;
}

try {
    // Verificar tabelas para ações que precisam delas
    $tableActions = ['get_history', 'save_history', 'delete_history', 'get_ranking', 'save_season_points', 'get_season_points', 'get_teams_for_points'];
    if (in_array($action, $tableActions) && !checkTablesExist($pdo)) {
        echo json_encode([
            'success' => false, 
            'error' => 'Tabelas não encontradas. Execute a migração acessando: /migrate-history-points.php'
        ]);
        exit;
    }

    switch ($action) {
        
        // =====================================================
        // HISTÓRICO
        // =====================================================
        
        case 'get_history':
            $league = $_REQUEST['league'] ?? null;
            
            // Verificar se coluna roy_player existe
            $stmtRoy = $pdo->query("SHOW COLUMNS FROM season_history LIKE 'roy_player'");
            $hasRoy = $stmtRoy->rowCount() > 0;
            
            $royFields = $hasRoy ? ",
                        sh.roy_player,
                        sh.roy_team_id,
                        CONCAT(troy.city, ' ', troy.name) as roy_team_name" : ",
                        NULL as roy_player,
                        NULL as roy_team_id,
                        NULL as roy_team_name";
            
            $royJoin = $hasRoy ? "LEFT JOIN teams troy ON sh.roy_team_id = troy.id" : "";
            
            $sql = "SELECT 
                        sh.id,
                        sh.season_id,
                        sh.league,
                        sh.sprint_number,
                        sh.season_number,
                        sh.year,
                        sh.champion_team_id,
                        CONCAT(tc.city, ' ', tc.name) as champion_name,
                        sh.runner_up_team_id,
                        CONCAT(tr.city, ' ', tr.name) as runner_up_name,
                        sh.mvp_player,
                        sh.mvp_team_id,
                        CONCAT(tm.city, ' ', tm.name) as mvp_team_name,
                        sh.dpoy_player,
                        sh.dpoy_team_id,
                        CONCAT(td.city, ' ', td.name) as dpoy_team_name,
                        sh.mip_player,
                        sh.mip_team_id,
                        CONCAT(ti.city, ' ', ti.name) as mip_team_name,
                        sh.sixth_man_player,
                        sh.sixth_man_team_id,
                        CONCAT(ts.city, ' ', ts.name) as sixth_man_team_name
                        {$royFields},
                        sh.created_at,
                        CASE WHEN s.draft_order_snapshot IS NOT NULL THEN 1 ELSE 0 END as has_draft_history
                    FROM season_history sh
                    LEFT JOIN seasons s ON sh.season_id = s.id
                    LEFT JOIN teams tc ON sh.champion_team_id = tc.id
                    LEFT JOIN teams tr ON sh.runner_up_team_id = tr.id
                    LEFT JOIN teams tm ON sh.mvp_team_id = tm.id
                    LEFT JOIN teams td ON sh.dpoy_team_id = td.id
                    LEFT JOIN teams ti ON sh.mip_team_id = ti.id
                    LEFT JOIN teams ts ON sh.sixth_man_team_id = ts.id
                    {$royJoin}";
            
            $params = [];
            if ($league) {
                $sql .= " WHERE sh.league = ?";
                $params[] = $league;
            }
            
            $sql .= " ORDER BY sh.league, sh.year DESC, sh.sprint_number DESC, sh.season_number DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Agrupar por liga
            $grouped = [];
            foreach ($history as $row) {
                $grouped[$row['league']][] = $row;
            }
            
            echo json_encode(['success' => true, 'history' => $grouped]);
            break;
            
        case 'save_history':
            // Admin já verificado no início
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $seasonId = $data['season_id'] ?? null;
            
            if (!$seasonId) {
                throw new Exception('ID da temporada é obrigatório');
            }
            
            // Buscar dados da temporada com sprint
            $stmt = $pdo->prepare("
                SELECT s.*, sp.sprint_number 
                FROM seasons s 
                LEFT JOIN sprints sp ON s.sprint_id = sp.id 
                WHERE s.id = ?
            ");
            $stmt->execute([$seasonId]);
            $season = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$season) {
                throw new Exception('Temporada não encontrada');
            }
            
            // Verificar se já existe histórico para esta temporada
            $stmt = $pdo->prepare("SELECT id FROM season_history WHERE season_id = ?");
            $stmt->execute([$seasonId]);
            $existing = $stmt->fetch();
            
            $historyData = [
                'season_id' => $seasonId,
                'league' => $data['league'],
                'sprint_number' => $season['sprint_number'] ?? 1,
                'season_number' => $season['season_number'] ?? 1,
                'year' => $season['year'] ?? date('Y'),
                'champion_team_id' => $data['champion_team_id'] ?: null,
                'runner_up_team_id' => $data['runner_up_team_id'] ?: null,
                'mvp_player' => $data['mvp_player'] ?: null,
                'mvp_team_id' => $data['mvp_team_id'] ?: null,
                'dpoy_player' => $data['dpoy_player'] ?: null,
                'dpoy_team_id' => $data['dpoy_team_id'] ?: null,
                'mip_player' => $data['mip_player'] ?: null,
                'mip_team_id' => $data['mip_team_id'] ?: null,
                'sixth_man_player' => $data['sixth_man_player'] ?: null,
                'sixth_man_team_id' => $data['sixth_man_team_id'] ?: null
            ];
            
            if ($existing) {
                // Atualizar
                $sql = "UPDATE season_history SET 
                            league = :league,
                            sprint_number = :sprint_number,
                            season_number = :season_number,
                            year = :year,
                            champion_team_id = :champion_team_id,
                            runner_up_team_id = :runner_up_team_id,
                            mvp_player = :mvp_player,
                            mvp_team_id = :mvp_team_id,
                            dpoy_player = :dpoy_player,
                            dpoy_team_id = :dpoy_team_id,
                            mip_player = :mip_player,
                            mip_team_id = :mip_team_id,
                            sixth_man_player = :sixth_man_player,
                            sixth_man_team_id = :sixth_man_team_id
                        WHERE season_id = :season_id";
            } else {
                // Inserir
                $sql = "INSERT INTO season_history 
                            (season_id, league, sprint_number, season_number, year, 
                             champion_team_id, runner_up_team_id, 
                             mvp_player, mvp_team_id, 
                             dpoy_player, dpoy_team_id, 
                             mip_player, mip_team_id, 
                             sixth_man_player, sixth_man_team_id)
                        VALUES 
                            (:season_id, :league, :sprint_number, :season_number, :year,
                             :champion_team_id, :runner_up_team_id,
                             :mvp_player, :mvp_team_id,
                             :dpoy_player, :dpoy_team_id,
                             :mip_player, :mip_team_id,
                             :sixth_man_player, :sixth_man_team_id)";
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($historyData);
            
            echo json_encode(['success' => true, 'message' => 'Histórico salvo com sucesso']);
            break;
            
        case 'delete_history':
            // Admin já verificado no início
            
            $seasonId = $_REQUEST['season_id'] ?? null;
            
            if (!$seasonId) {
                throw new Exception('ID da temporada é obrigatório');
            }
            
            $stmt = $pdo->prepare("DELETE FROM season_history WHERE season_id = ?");
            $stmt->execute([$seasonId]);
            
            echo json_encode(['success' => true, 'message' => 'Histórico excluído com sucesso']);
            break;
            
        // =====================================================
        // PONTOS
        // =====================================================
        
        case 'get_teams_for_points':
            $seasonId = $_REQUEST['season_id'] ?? null;
            $league = $_REQUEST['league'] ?? null;
            
            if (!$league) {
                throw new Exception('Liga é obrigatória');
            }
            
            // Buscar times da liga
            $stmt = $pdo->prepare("
                SELECT 
                    t.id,
                    CONCAT(t.city, ' ', t.name) as team_name,
                    t.league,
                    COALESCE(tsp.points, 0) as current_points
                FROM teams t
                LEFT JOIN team_season_points tsp ON t.id = tsp.team_id AND tsp.season_id = ?
                WHERE t.league = ?
                ORDER BY t.city, t.name
            ");
            $stmt->execute([$seasonId, $league]);
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'teams' => $teams]);
            break;
            
        case 'save_season_points':
            // Admin já verificado no início
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $seasonId = $data['season_id'] ?? null;
            $league = $data['league'] ?? null;
            $teamPoints = $data['team_points'] ?? [];
            
            if (!$seasonId || !$league) {
                throw new Exception('ID da temporada e liga são obrigatórios');
            }
            
            // Buscar dados da temporada com sprint
            $stmt = $pdo->prepare("
                SELECT s.*, sp.sprint_number 
                FROM seasons s 
                LEFT JOIN sprints sp ON s.sprint_id = sp.id 
                WHERE s.id = ?
            ");
            $stmt->execute([$seasonId]);
            $season = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$season) {
                throw new Exception('Temporada não encontrada');
            }
            
            $sprintNumber = $season['sprint_number'] ?? 1;
            $seasonNumber = $season['season_number'] ?? 1;
            
            $pdo->beginTransaction();
            
            try {
                foreach ($teamPoints as $tp) {
                    $teamId = $tp['team_id'];
                    $points = intval($tp['points']);
                    
                    // Buscar nome do time
                    $stmt = $pdo->prepare("SELECT CONCAT(city, ' ', name) as team_name FROM teams WHERE id = ?");
                    $stmt->execute([$teamId]);
                    $team = $stmt->fetch();
                    $teamName = $team ? $team['team_name'] : 'Time Desconhecido';
                    
                    // Inserir ou atualizar pontos
                    $stmt = $pdo->prepare("
                        INSERT INTO team_season_points 
                            (team_id, team_name, league, season_id, sprint_number, season_number, points)
                        VALUES 
                            (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                            points = VALUES(points),
                            team_name = VALUES(team_name),
                            updated_at = NOW()
                    ");
                    $stmt->execute([
                        $teamId,
                        $teamName,
                        $league,
                        $seasonId,
                        $sprintNumber,
                        $seasonNumber,
                        $points
                    ]);
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Pontos salvos com sucesso']);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'get_season_points':
            $seasonId = $_REQUEST['season_id'] ?? null;
            $league = $_REQUEST['league'] ?? null;
            
            $sql = "SELECT 
                        tsp.*,
                        CONCAT(t.city, ' ', t.name) as team_name_current
                    FROM team_season_points tsp
                    JOIN teams t ON tsp.team_id = t.id";
            
            $params = [];
            $where = [];
            
            if ($seasonId) {
                $where[] = "tsp.season_id = ?";
                $params[] = $seasonId;
            }
            if ($league) {
                $where[] = "tsp.league = ?";
                $params[] = $league;
            }
            
            if ($where) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }
            
            $sql .= " ORDER BY tsp.league, tsp.points DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $points = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'points' => $points]);
            break;
            
        // =====================================================
        // RANKING
        // =====================================================
        
        case 'get_ranking':
            $league = $_REQUEST['league'] ?? null;

            // Usar ranking_points da tabela teams (onde os pontos são salvos pelo sistema de playoffs)
            // Também somar pontos da team_season_points se existir
            $sql = "SELECT 
                        t.id as team_id,
                        CONCAT(t.city, ' ', t.name) as team_name,
                        t.league,
                        (COALESCE(t.ranking_points, 0) + COALESCE(points.total_points, 0)) as total_points,
                        COALESCE(titles.total_titles, 0) as total_titles
                    FROM teams t
                    LEFT JOIN (
                        SELECT team_id, SUM(points) as total_points
                        FROM team_season_points
                        GROUP BY team_id
                    ) points ON points.team_id = t.id
                    LEFT JOIN (
                        SELECT champion_team_id as team_id, COUNT(*) as total_titles
                        FROM season_history
                        WHERE champion_team_id IS NOT NULL
                        GROUP BY champion_team_id
                    ) titles ON titles.team_id = t.id";

            $params = [];
            if ($league) {
                $sql .= " WHERE t.league = ?";
                $params[] = $league;
            }

            $sql .= " GROUP BY t.id, t.city, t.name, t.league, total_points, total_titles
                      ORDER BY t.league, total_points DESC, total_titles DESC, t.city, t.name";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Agrupar por liga
            $grouped = [];
            foreach ($ranking as $row) {
                $grouped[$row['league']][] = $row;
            }

            echo json_encode(['success' => true, 'ranking' => $grouped]);
            break;
            
        // =====================================================
        // UTILIDADES
        // =====================================================
        
        case 'get_seasons':
            // Lista temporadas para selects
            $stmt = $pdo->query("
                SELECT id, sprint_number, season_number, year, status
                FROM seasons
                ORDER BY year DESC, sprint_number DESC, season_number DESC
            ");
            $seasons = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'seasons' => $seasons]);
            break;
            
        case 'get_teams_by_league':
            $league = $_REQUEST['league'] ?? null;
            
            $sql = "SELECT id, CONCAT(city, ' ', name) as team_name, league FROM teams";
            $params = [];
            
            if ($league) {
                $sql .= " WHERE league = ?";
                $params[] = $league;
            }
            
            $sql .= " ORDER BY league, city, name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'teams' => $teams]);
            break;
            
        default:
            throw new Exception('Ação não reconhecida: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
