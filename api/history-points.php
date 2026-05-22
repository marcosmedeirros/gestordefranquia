<?php
/**
 * API para Histórico e Pontos de Temporada
 * 
 * Endpoints:
 * - get_history: Busca histórico de todas as temporadas
 * - save_history: Salva histórico (Campeão, Vice, MVP, DPOY, MIP, 6º Homem, ROY)
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
$rawInput = file_get_contents('php://input');
$jsonPayload = null;
if ($rawInput !== '') {
    $jsonPayload = json_decode($rawInput, true);
}
if (!$action && is_array($jsonPayload) && isset($jsonPayload['action'])) {
    $action = $jsonPayload['action'];
}

// Obter usuário atual
$user = getUserSession();

// Verificar se é admin para ações protegidas
$adminActions = ['save_history', 'delete_history', 'save_season_points', 'save_ranking_totals', 'edit_season_points', 'delete_season_points'];
if (in_array($action, $adminActions)) {
    if (!$user || ($user['user_type'] ?? 'jogador') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Apenas administradores podem realizar esta ação.']);
        exit;
    }
}

// Verificar se as tabelas existem
$pdo = db();
// Garantir colunas ROY na tabela season_history (para compatibilidade)
function ensureSeasonHistoryRoyColumns(PDO $pdo): void {
    // roy_player
    $stmt = $pdo->prepare("SHOW COLUMNS FROM season_history LIKE 'roy_player'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE season_history ADD COLUMN roy_player VARCHAR(100) NULL AFTER sixth_man_team_id");
    }
    // roy_team_id
    $stmt2 = $pdo->prepare("SHOW COLUMNS FROM season_history LIKE 'roy_team_id'");
    $stmt2->execute();
    if (!$stmt2->fetch()) {
        $pdo->exec("ALTER TABLE season_history ADD COLUMN roy_team_id INT NULL AFTER roy_player");
    }
}
function teamColumnExists(PDO $pdo, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM teams LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}
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

// Recalcula os títulos em hall_of_fame com base em season_history para uma liga
function syncHallOfFame(PDO $pdo, string $league): void {
    $stmtTbl = $pdo->query("SHOW TABLES LIKE 'hall_of_fame'");
    if (!$stmtTbl || $stmtTbl->rowCount() === 0) return;

    $stmtCounts = $pdo->prepare("
        SELECT sh.champion_team_id,
               COUNT(*) AS total,
               CONCAT(t.city, ' ', t.name) AS team_name,
               u.name AS gm_name
        FROM season_history sh
        LEFT JOIN teams t ON sh.champion_team_id = t.id
        LEFT JOIN users u ON t.user_id = u.id
        WHERE sh.league = ? AND sh.champion_team_id IS NOT NULL
        GROUP BY sh.champion_team_id
    ");
    $stmtCounts->execute([$league]);
    $counts = $stmtCounts->fetchAll(PDO::FETCH_ASSOC);

    $championIds = array_column($counts, 'champion_team_id');

    if (!empty($championIds)) {
        $placeholders = implode(',', array_fill(0, count($championIds), '?'));
        $pdo->prepare("UPDATE hall_of_fame SET titles = 0 WHERE league = ? AND (team_id NOT IN ({$placeholders}) OR team_id IS NULL)")
            ->execute(array_merge([$league], $championIds));
    } else {
        $pdo->prepare("UPDATE hall_of_fame SET titles = 0 WHERE league = ?")
            ->execute([$league]);
    }

    $stmtCheck  = $pdo->prepare("SELECT id FROM hall_of_fame WHERE team_id = ? AND league = ? LIMIT 1");
    $stmtUpdate = $pdo->prepare("UPDATE hall_of_fame SET titles = ?, team_name = ?, gm_name = ? WHERE team_id = ? AND league = ?");
    $stmtInsert = $pdo->prepare("INSERT INTO hall_of_fame (team_id, league, titles, team_name, gm_name, is_active) VALUES (?, ?, ?, ?, ?, 1)");

    foreach ($counts as $row) {
        $teamId = $row['champion_team_id'];
        $total  = (int)$row['total'];
        $stmtCheck->execute([$teamId, $league]);
        if ($stmtCheck->fetch()) {
            $stmtUpdate->execute([$total, $row['team_name'], $row['gm_name'], $teamId, $league]);
        } else {
            $stmtInsert->execute([$teamId, $league, $total, $row['team_name'], $row['gm_name']]);
        }
    }
}

// Garante que a coluna teams.ranking_points exista para sobrescrita manual do ranking
function ensureRankingPointsColumn(PDO $pdo): void {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM teams LIKE 'ranking_points'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN ranking_points INT NOT NULL DEFAULT 0 AFTER name");
    }
}
// Garante que a coluna teams.ranking_titles exista para sobrescrita manual de títulos
function ensureRankingTitlesColumn(PDO $pdo): void {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM teams LIKE 'ranking_titles'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN ranking_titles INT NOT NULL DEFAULT 0 AFTER ranking_points");
    }
}

// Garante que a tabela de log de pontos de temporada existe
function ensureSeasonPointsLogTable(PDO $pdo): void {
    $stmt = $pdo->query("SHOW TABLES LIKE 'season_points_log'");
    if ($stmt && $stmt->rowCount() > 0) return;
    $pdo->exec("CREATE TABLE season_points_log (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        season_id    INT NOT NULL,
        league       VARCHAR(20) NOT NULL,
        sprint_number INT NOT NULL DEFAULT 1,
        season_number INT NOT NULL DEFAULT 1,
        team_id      INT NOT NULL,
        team_name    VARCHAR(255) NOT NULL DEFAULT '',
        points_old   INT NOT NULL DEFAULT 0,
        points_new   INT NOT NULL DEFAULT 0,
        delta        INT NOT NULL DEFAULT 0,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_spl_team    (team_id),
        INDEX idx_spl_league  (league),
        INDEX idx_spl_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

try {
    // Verificar tabelas para ações que precisam delas
    $tableActions = ['get_history', 'save_history', 'delete_history', 'get_ranking', 'save_season_points', 'get_season_points', 'get_teams_for_points', 'edit_season_points', 'delete_season_points'];
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

            // Verificar coluna nba_cup_team_id
            $stmtNbaCup = $pdo->query("SHOW COLUMNS FROM season_history LIKE 'nba_cup_team_id'");
            $hasNbaCup  = $stmtNbaCup->rowCount() > 0;
            $nbaCupFields = $hasNbaCup ? ",
                        sh.nba_cup_team_id,
                        CONCAT(tnc.city, ' ', tnc.name) as nba_cup_team_name" : ",
                        NULL as nba_cup_team_id,
                        NULL as nba_cup_team_name";
            $nbaCupJoin = $hasNbaCup ? "LEFT JOIN teams tnc ON sh.nba_cup_team_id = tnc.id" : "";

            $sql = "SELECT
                        sh.id,
                        sh.season_id,
                        sh.league,
                        sh.sprint_number,
                        sh.season_number,
                        COALESCE(sh.year, s.year) as year,
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
                        {$royFields}
                        {$nbaCupFields},
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
                    {$royJoin}
                    {$nbaCupJoin}";
            
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
            
            $data = is_array($jsonPayload) ? $jsonPayload : null;
            
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
            
            // Garantir colunas ROY (para projetos que ainda não possuem)
            ensureSeasonHistoryRoyColumns($pdo);

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
                'sixth_man_team_id' => $data['sixth_man_team_id'] ?: null,
                'roy_player' => $data['roy_player'] ?: null,
                'roy_team_id' => $data['roy_team_id'] ?: null
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
                            sixth_man_team_id = :sixth_man_team_id,
                            roy_player = :roy_player,
                            roy_team_id = :roy_team_id
                        WHERE season_id = :season_id";
            } else {
                // Inserir
                $sql = "INSERT INTO season_history 
                            (season_id, league, sprint_number, season_number, year, 
                             champion_team_id, runner_up_team_id, 
                             mvp_player, mvp_team_id, 
                             dpoy_player, dpoy_team_id, 
                             mip_player, mip_team_id, 
                             sixth_man_player, sixth_man_team_id,
                             roy_player, roy_team_id)
                        VALUES 
                            (:season_id, :league, :sprint_number, :season_number, :year,
                             :champion_team_id, :runner_up_team_id,
                             :mvp_player, :mvp_team_id,
                             :dpoy_player, :dpoy_team_id,
                             :mip_player, :mip_team_id,
                             :sixth_man_player, :sixth_man_team_id,
                             :roy_player, :roy_team_id)";
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($historyData);

            if (!empty($historyData['league'])) {
                syncHallOfFame($pdo, $historyData['league']);
            }

            echo json_encode(['success' => true, 'message' => 'Histórico salvo com sucesso']);
            break;
            
        case 'delete_history':
            // Admin já verificado no início

            $seasonId = $_REQUEST['season_id'] ?? null;

            if (!$seasonId) {
                throw new Exception('ID da temporada é obrigatório');
            }

            // Captura a liga antes de deletar para poder sincronizar depois
            $stmtLeague = $pdo->prepare("SELECT league FROM season_history WHERE season_id = ? LIMIT 1");
            $stmtLeague->execute([$seasonId]);
            $deletedLeague = $stmtLeague->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM season_history WHERE season_id = ?");
            $stmt->execute([$seasonId]);

            if ($deletedLeague) {
                syncHallOfFame($pdo, $deletedLeague);
            }

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

            // Verificar lock via season_points_lock (novo) com fallback para team_season_points (legado)
            $lockedAt = null;
            $pointsLocked = false;
            $stmtLockTbl = $pdo->query("SHOW TABLES LIKE 'season_points_lock'");
            if ($stmtLockTbl && $stmtLockTbl->rowCount() > 0) {
                $stmtLockCheck = $pdo->prepare("SELECT locked_at FROM season_points_lock WHERE season_id = ? AND league = ? LIMIT 1");
                $stmtLockCheck->execute([$seasonId, $league]);
                $lockRow = $stmtLockCheck->fetch(PDO::FETCH_ASSOC);
                if ($lockRow) { $lockedAt = $lockRow['locked_at']; $pointsLocked = true; }
            }
            if (!$pointsLocked) {
                $stmtLockFallback = $pdo->prepare("SELECT MIN(created_at) AS locked_at FROM team_season_points WHERE season_id = ? AND league = ?");
                $stmtLockFallback->execute([$seasonId, $league]);
                $lockRow = $stmtLockFallback->fetch(PDO::FETCH_ASSOC);
                if (!empty($lockRow['locked_at'])) { $lockedAt = $lockRow['locked_at']; $pointsLocked = true; }
            }

            echo json_encode([
                'success' => true,
                'teams' => $teams,
                'points_locked' => $pointsLocked,
                'points_locked_at' => $lockedAt
            ]);
            break;
            
        case 'save_season_points':
            // Admin já verificado no início

            $data = is_array($jsonPayload) ? $jsonPayload : null;

            $seasonId = $data['season_id'] ?? null;
            $league = $data['league'] ?? null;
            $teamPoints = $data['team_points'] ?? [];

            if (!$seasonId || !$league) {
                throw new Exception('ID da temporada e liga são obrigatórios');
            }

            // ── Lock atômico anti-duplo-clique / race-condition ───────────────────
            // Garante coluna de lock na tabela seasons (migração segura)
            try { $pdo->exec("ALTER TABLE seasons ADD COLUMN IF NOT EXISTS points_locked_at TIMESTAMP NULL DEFAULT NULL"); } catch (Exception $e) {}
            // Coluna de lock por liga (chave composta season_id + liga)
            try { $pdo->exec("ALTER TABLE seasons ADD COLUMN IF NOT EXISTS points_locked_league VARCHAR(20) NULL DEFAULT NULL"); } catch (Exception $e) {}

            // Verificar se já há lock para esta liga nesta temporada via tabela dedicada
            $stmtTbl = $pdo->query("SHOW TABLES LIKE 'season_points_lock'");
            if (!$stmtTbl || $stmtTbl->rowCount() === 0) {
                $pdo->exec("CREATE TABLE season_points_lock (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    season_id  INT NOT NULL,
                    league     VARCHAR(20) NOT NULL,
                    locked_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    locked_by  INT NULL,
                    UNIQUE KEY uq_season_league (season_id, league)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }

            // Tentativa de inserção atômica — falha silenciosamente se já existe (UNIQUE)
            $lockInsert = $pdo->prepare("INSERT IGNORE INTO season_points_lock (season_id, league, locked_by) VALUES (?, ?, ?)");
            $lockInsert->execute([$seasonId, $league, $user['id'] ?? null]);
            if ($lockInsert->rowCount() === 0) {
                // Outro processo/admin já registrou — busca data para mensagem
                $stmtLockedRow = $pdo->prepare("SELECT locked_at FROM season_points_lock WHERE season_id = ? AND league = ? LIMIT 1");
                $stmtLockedRow->execute([$seasonId, $league]);
                $lockedRow = $stmtLockedRow->fetch(PDO::FETCH_ASSOC);
                $lockedAtStr = $lockedRow ? date('d/m/Y H:i', strtotime($lockedRow['locked_at'])) : '';
                echo json_encode([
                    'success'        => false,
                    'already_locked' => true,
                    'error'          => 'Pontos desta temporada já foram definidos' . ($lockedAtStr ? " em {$lockedAtStr}" : '') . '. Não é permitido registrar novamente.',
                ]);
                exit;
            }
            // ── Fim do lock atômico ───────────────────────────────────────────────

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

            ensureSeasonPointsLogTable($pdo);

            $pdo->beginTransaction();

            try {

                foreach ($teamPoints as $tp) {
                    $teamId = $tp['team_id'];
                    $points = intval($tp['points']);

                    // Evita corrida em cliques/requisições duplicadas para o mesmo time
                    // dentro da mesma liga/temporada, serializando a atualização por team_id.
                    $stmtTeamLock = $pdo->prepare("SELECT id FROM teams WHERE id = ? FOR UPDATE");
                    $stmtTeamLock->execute([$teamId]);

                    $prevPoints = 0;
                    $stmtPrev = $pdo->prepare("SELECT points FROM team_season_points WHERE team_id = ? AND season_id = ? LIMIT 1 FOR UPDATE");
                    $stmtPrev->execute([$teamId, $seasonId]);
                    $prevRow = $stmtPrev->fetch(PDO::FETCH_ASSOC);
                    if ($prevRow) {
                        $prevPoints = (int) $prevRow['points'];
                    }
                    
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

                    $delta = $points - $prevPoints;
                    if ($delta !== 0 && teamColumnExists($pdo, 'ranking_points')) {
                        $stmtUpdate = $pdo->prepare("
                            UPDATE teams
                            SET ranking_points = COALESCE(ranking_points, 0) + ?
                            WHERE id = ?
                        ");
                        $stmtUpdate->execute([$delta, $teamId]);
                    }

                    // Registra no log de pontos para rastreabilidade
                    $stmtLog = $pdo->prepare("
                        INSERT INTO season_points_log
                            (season_id, league, sprint_number, season_number, team_id, team_name, points_old, points_new, delta)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtLog->execute([
                        $seasonId, $league, $sprintNumber, $seasonNumber,
                        $teamId, $teamName, $prevPoints, $points, $delta
                    ]);
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Pontos salvos com sucesso']);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                // Remove o lock para que possa tentar novamente em caso de erro inesperado
                try {
                    $pdo->prepare("DELETE FROM season_points_lock WHERE season_id = ? AND league = ?")->execute([$seasonId, $league]);
                } catch (Exception $ignored) {}
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

        case 'get_points_history':
            $league = $_REQUEST['league'] ?? null;
            if (!$league) throw new Exception('Liga é obrigatória');

            $stmt = $pdo->prepare("
                SELECT
                    tsp.season_id,
                    tsp.sprint_number,
                    tsp.season_number,
                    tsp.league,
                    tsp.team_id,
                    COALESCE(NULLIF(CONCAT(t.city, ' ', t.name), ' '), tsp.team_name) AS team_name,
                    tsp.points,
                    s.year
                FROM team_season_points tsp
                LEFT JOIN teams t ON tsp.team_id = t.id
                LEFT JOIN seasons s ON tsp.season_id = s.id
                WHERE tsp.league = ?
                ORDER BY tsp.sprint_number DESC, tsp.season_number DESC, tsp.points DESC
            ");
            $stmt->execute([$league]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $seasons = [];
            foreach ($rows as $row) {
                $key = $row['season_id'] ?? ($row['sprint_number'] . '_' . $row['season_number']);
                if (!isset($seasons[$key])) {
                    $seasons[$key] = [
                        'season_id'     => $row['season_id'],
                        'sprint_number' => (int)$row['sprint_number'],
                        'season_number' => (int)$row['season_number'],
                        'year'          => $row['year'] ?? null,
                        'teams'         => [],
                    ];
                }
                $seasons[$key]['teams'][] = [
                    'team_name' => $row['team_name'],
                    'points'    => (int)$row['points'],
                ];
            }

            echo json_encode(['success' => true, 'seasons' => array_values($seasons)]);
            break;

        case 'get_league_seasons_overview':
            $league = $_REQUEST['league'] ?? null;
            if (!$league) { echo json_encode(['success'=>false,'error'=>'league required']); break; }

            $stmtS = $pdo->prepare("
                SELECT s.id AS season_id, s.season_number, s.year, s.status,
                       sp.sprint_number, sp.start_year
                FROM seasons s
                LEFT JOIN sprints sp ON s.sprint_id = sp.id
                WHERE s.league = ?
                ORDER BY s.id ASC
            ");
            $stmtS->execute([$league]);
            $allSeasons = $stmtS->fetchAll(PDO::FETCH_ASSOC);

            $stmtReg = $pdo->prepare("SELECT DISTINCT season_id FROM team_season_points WHERE league = ?");
            $stmtReg->execute([$league]);
            $registeredIds = array_flip(array_column($stmtReg->fetchAll(PDO::FETCH_ASSOC), 'season_id'));

            $stmtLT = $pdo->prepare("
                SELECT t.id AS team_id, CONCAT(t.city,' ',t.name) AS team_name
                FROM teams t WHERE t.league = ? ORDER BY t.city, t.name
            ");
            $stmtLT->execute([$league]);
            $leagueTeams = $stmtLT->fetchAll(PDO::FETCH_ASSOC);

            $overview = [];
            foreach ($allSeasons as $row) {
                $sid  = (int)$row['season_id'];
                $isReg = isset($registeredIds[$sid]) || isset($registeredIds[(string)$sid]);
                $teams = [];
                if ($isReg) {
                    $stmtPts = $pdo->prepare("
                        SELECT tsp.team_id,
                               COALESCE(NULLIF(CONCAT(t.city,' ',t.name),' '), tsp.team_name) AS team_name,
                               tsp.points
                        FROM team_season_points tsp
                        LEFT JOIN teams t ON tsp.team_id = t.id
                        WHERE tsp.season_id = ? AND tsp.league = ?
                        ORDER BY tsp.points DESC
                    ");
                    $stmtPts->execute([$sid, $league]);
                    $teams = $stmtPts->fetchAll(PDO::FETCH_ASSOC);
                }
                $overview[] = [
                    'season_id'         => $sid,
                    'season_number'     => (int)($row['season_number'] ?? 0),
                    'sprint_number'     => (int)($row['sprint_number'] ?? 0),
                    'year'              => $row['year'] ?? $row['start_year'] ?? null,
                    'status'            => $row['status'] ?? null,
                    'points_registered' => $isReg,
                    'teams'             => $teams,
                ];
            }
            echo json_encode(['success' => true, 'seasons' => $overview, 'league_teams' => $leagueTeams]);
            break;

        case 'get_team_season_log':
            $teamId = (int)($_REQUEST['team_id'] ?? 0);
            if (!$teamId) { echo json_encode(['success'=>false,'error'=>'team_id required']); break; }

            $stmtTm = $pdo->prepare("SELECT league FROM teams WHERE id = ? LIMIT 1");
            $stmtTm->execute([$teamId]);
            $tmRow = $stmtTm->fetch(PDO::FETCH_ASSOC);
            $teamLeague = $tmRow['league'] ?? null;
            if (!$teamLeague) { echo json_encode(['success'=>false,'error'=>'time não encontrado']); break; }

            $stmtAS = $pdo->prepare("
                SELECT s.id AS season_id, s.season_number, s.year,
                       sp.sprint_number, sp.start_year
                FROM seasons s
                LEFT JOIN sprints sp ON s.sprint_id = sp.id
                WHERE s.league = ?
                ORDER BY s.id ASC
            ");
            $stmtAS->execute([$teamLeague]);
            $allSeasonsForTeam = $stmtAS->fetchAll(PDO::FETCH_ASSOC);

            $stmtTP = $pdo->prepare("SELECT season_id, points FROM team_season_points WHERE team_id = ? AND league = ?");
            $stmtTP->execute([$teamId, $teamLeague]);
            $pointsMap = [];
            foreach ($stmtTP->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pointsMap[(int)$r['season_id']] = (int)$r['points'];
            }

            $log = [];
            foreach ($allSeasonsForTeam as $row) {
                $sid = (int)$row['season_id'];
                $log[] = [
                    'season_id'     => $sid,
                    'season_number' => (int)($row['season_number'] ?? 0),
                    'sprint_number' => (int)($row['sprint_number'] ?? 0),
                    'year'          => $row['year'] ?? $row['start_year'] ?? null,
                    'points'        => $pointsMap[$sid] ?? 0,
                ];
            }
            echo json_encode(['success' => true, 'seasons' => $log]);
            break;

        case 'edit_season_points':
            // Admin bypasses season_points_lock to correct existing points
            $data = is_array($jsonPayload) ? $jsonPayload : null;
            $seasonId = (int)($data['season_id'] ?? 0);
            $league = $data['league'] ?? null;
            $teamPoints = $data['team_points'] ?? [];

            if (!$seasonId || !$league) {
                throw new Exception('ID da temporada e liga são obrigatórios');
            }

            $stmtSzn = $pdo->prepare("
                SELECT s.season_number, s.year,
                       COALESCE(sp.sprint_number, 1) AS sprint_number
                FROM seasons s
                LEFT JOIN sprints sp ON s.sprint_id = sp.id
                WHERE s.id = ?
            ");
            $stmtSzn->execute([$seasonId]);
            $season = $stmtSzn->fetch(PDO::FETCH_ASSOC);
            if (!$season) throw new Exception('Temporada não encontrada');

            $sprintNumber = (int)($season['sprint_number'] ?? 1);
            $seasonNumber = (int)($season['season_number'] ?? 1);

            $pdo->beginTransaction();
            try {
                $stmtPrev   = $pdo->prepare("SELECT points FROM team_season_points WHERE team_id = ? AND season_id = ? LIMIT 1");
                $stmtName   = $pdo->prepare("SELECT CONCAT(city, ' ', name) AS team_name FROM teams WHERE id = ?");
                $stmtUpsert = $pdo->prepare("
                    INSERT INTO team_season_points (team_id, team_name, league, season_id, sprint_number, season_number, points)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE points = VALUES(points), team_name = VALUES(team_name), updated_at = NOW()
                ");
                $hasRankCol = teamColumnExists($pdo, 'ranking_points');
                $stmtDelta  = $hasRankCol
                    ? $pdo->prepare("UPDATE teams SET ranking_points = GREATEST(0, COALESCE(ranking_points, 0) + ?) WHERE id = ?")
                    : null;

                foreach ($teamPoints as $tp) {
                    $teamId    = (int)($tp['team_id'] ?? 0);
                    $newPoints = (int)($tp['points'] ?? 0);
                    if ($teamId <= 0) continue;

                    $stmtPrev->execute([$teamId, $seasonId]);
                    $prevRow   = $stmtPrev->fetch(PDO::FETCH_ASSOC);
                    $prevPoints = (int)($prevRow['points'] ?? 0);

                    $stmtName->execute([$teamId]);
                    $nameRow  = $stmtName->fetch(PDO::FETCH_ASSOC);
                    $teamName = $nameRow['team_name'] ?? 'Time Desconhecido';

                    $stmtUpsert->execute([$teamId, $teamName, $league, $seasonId, $sprintNumber, $seasonNumber, $newPoints]);

                    $delta = $newPoints - $prevPoints;
                    if ($delta !== 0 && $stmtDelta) {
                        $stmtDelta->execute([$delta, $teamId]);
                    }
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true, 'message' => 'Pontos da temporada atualizados com sucesso']);
            break;

        case 'delete_season_points':
            // Admin zeroes a season's points, removes locks and adjusts ranking totals
            $data = is_array($jsonPayload) ? $jsonPayload : null;
            $seasonId = (int)($data['season_id'] ?? 0);
            $league = $data['league'] ?? null;

            if (!$seasonId || !$league) {
                throw new Exception('ID da temporada e liga são obrigatórios');
            }

            $pdo->beginTransaction();
            try {
                if (teamColumnExists($pdo, 'ranking_points')) {
                    $stmtCur = $pdo->prepare("SELECT team_id, points FROM team_season_points WHERE season_id = ? AND league = ?");
                    $stmtCur->execute([$seasonId, $league]);
                    $currentPoints = $stmtCur->fetchAll(PDO::FETCH_ASSOC);

                    $stmtSub = $pdo->prepare("UPDATE teams SET ranking_points = GREATEST(0, COALESCE(ranking_points, 0) - ?) WHERE id = ?");
                    foreach ($currentPoints as $cp) {
                        if ((int)$cp['points'] > 0) {
                            $stmtSub->execute([(int)$cp['points'], (int)$cp['team_id']]);
                        }
                    }
                }

                $pdo->prepare("DELETE FROM team_season_points WHERE season_id = ? AND league = ?")->execute([$seasonId, $league]);
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            // Remove locks outside transaction (DDL-safe)
            try {
                $stmtTbl = $pdo->query("SHOW TABLES LIKE 'season_points_lock'");
                if ($stmtTbl && $stmtTbl->rowCount() > 0) {
                    $pdo->prepare("DELETE FROM season_points_lock WHERE season_id = ? AND league = ?")->execute([$seasonId, $league]);
                }
            } catch (Exception $ignored) {}
            try {
                $stmtTbl = $pdo->query("SHOW TABLES LIKE 'playoff_finalize_lock'");
                if ($stmtTbl && $stmtTbl->rowCount() > 0) {
                    $pdo->prepare("DELETE FROM playoff_finalize_lock WHERE season_id = ? AND league = ?")->execute([$seasonId, $league]);
                }
            } catch (Exception $ignored) {}

            echo json_encode(['success' => true, 'message' => 'Pontos da temporada removidos e locks liberados com sucesso']);
            break;

        // =====================================================
        // RANKING
        // =====================================================

        case 'get_ranking':
            $league = $_REQUEST['league'] ?? null;
            $hasRankingPointsCol = teamColumnExists($pdo, 'ranking_points');
            $hasRankingTitlesCol = teamColumnExists($pdo, 'ranking_titles');
            $titlesSelect = $hasRankingTitlesCol
                ? "COALESCE(t.ranking_titles, titles.total_titles) as total_titles"
                : "COALESCE(titles.total_titles, 0) as total_titles";

            // Verificar disponibilidade de team_ranking_points como fallback melhor que team_season_points
            $stmtTbl = $pdo->query("SHOW TABLES LIKE 'team_ranking_points'");
            $hasTeamRankingPoints = $stmtTbl && $stmtTbl->rowCount() > 0;

            if ($hasRankingPointsCol) {
                // 1) Preferir coluna teams.ranking_points (usada pelo editor manual)
                $sql = "SELECT 
                            t.id as team_id,
                            CONCAT(t.city, ' ', t.name) as team_name,
                            t.league,
                            COALESCE(t.ranking_points, 0) as total_points,
                            {$titlesSelect},
                            u.name as owner_name
                        FROM teams t
                        LEFT JOIN users u ON u.id = t.user_id
                        LEFT JOIN (
                            SELECT champion_team_id as team_id, COUNT(*) as total_titles
                            FROM season_history
                            WHERE champion_team_id IS NOT NULL
                            GROUP BY champion_team_id
                        ) titles ON titles.team_id = t.id";
                $params = [];
                if ($league) { $sql .= " WHERE t.league = ?"; $params[] = $league; }
                $sql .= " GROUP BY t.id, t.city, t.name, t.league, total_points, total_titles, owner_name
                          ORDER BY t.league, total_points DESC, total_titles DESC, t.city, t.name";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } elseif ($hasTeamRankingPoints) {
                // 2) Caso não exista coluna, usar soma do total_points da tabela team_ranking_points (automático)
                $sql = "SELECT 
                            t.id as team_id,
                            CONCAT(t.city, ' ', t.name) as team_name,
                            t.league,
                            COALESCE(SUM(trp.total_points), 0) as total_points,
                            {$titlesSelect},
                            u.name as owner_name
                        FROM teams t
                        LEFT JOIN users u ON u.id = t.user_id
                        LEFT JOIN team_ranking_points trp ON trp.team_id = t.id
                        LEFT JOIN (
                            SELECT champion_team_id as team_id, COUNT(*) as total_titles
                            FROM season_history
                            WHERE champion_team_id IS NOT NULL
                            GROUP BY champion_team_id
                        ) titles ON titles.team_id = t.id";
                $params = [];
                if ($league) { $sql .= " WHERE t.league = ?"; $params[] = $league; }
                $sql .= " GROUP BY t.id, t.city, t.name, t.league, total_titles, owner_name
                          ORDER BY t.league, total_points DESC, total_titles DESC, t.city, t.name";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                // 3) Fallback legado: somar team_season_points
                $sql = "SELECT 
                            t.id as team_id,
                            CONCAT(t.city, ' ', t.name) as team_name,
                            t.league,
                            COALESCE(points.total_points, 0) as total_points,
                            {$titlesSelect},
                            u.name as owner_name
                        FROM teams t
                        LEFT JOIN users u ON u.id = t.user_id
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
                if ($league) { $sql .= " WHERE t.league = ?"; $params[] = $league; }
                $sql .= " GROUP BY t.id, t.city, t.name, t.league, total_points, total_titles, owner_name
                          ORDER BY t.league, total_points DESC, total_titles DESC, t.city, t.name";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            $ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Busca o último delta registrado por time (da tabela de log)
            $lastDeltas = [];
            $stmtLogTbl = $pdo->query("SHOW TABLES LIKE 'season_points_log'");
            if ($stmtLogTbl && $stmtLogTbl->rowCount() > 0) {
                $deltaParams = $league ? [$league] : [];
                $deltaWhere  = $league ? "WHERE league = ?" : "";
                $stmtDelta = $pdo->prepare("
                    SELECT team_id, delta
                    FROM season_points_log
                    WHERE id IN (
                        SELECT MAX(id) FROM season_points_log {$deltaWhere} GROUP BY team_id
                    )
                ");
                $stmtDelta->execute($deltaParams);
                foreach ($stmtDelta->fetchAll(PDO::FETCH_ASSOC) as $dr) {
                    $lastDeltas[(int)$dr['team_id']] = (int)$dr['delta'];
                }
            }

            // Agrupar por liga e injetar last_delta
            $grouped = [];
            foreach ($ranking as $row) {
                $row['last_delta'] = $lastDeltas[(int)$row['team_id']] ?? 0;
                $grouped[$row['league']][] = $row;
            }

            // Calcula movimento de posição com base na última temporada registrada
            $prevSeasonPositions = [];
            $leaguesToCheck = $league ? [$league] : array_keys($grouped);
            foreach ($leaguesToCheck as $lg) {
                $stmtSeason = $pdo->prepare('SELECT season_id FROM team_season_points WHERE league = ? ORDER BY season_id DESC LIMIT 1');
                $stmtSeason->execute([$lg]);
                $lastSeasonId = (int)($stmtSeason->fetchColumn() ?: 0);
                if (!$lastSeasonId) {
                    continue;
                }

                $stmtRank = $pdo->prepare('SELECT team_id, points FROM team_season_points WHERE league = ? AND season_id = ? ORDER BY points DESC, team_id ASC');
                $stmtRank->execute([$lg, $lastSeasonId]);
                $pos = 1;
                foreach ($stmtRank->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $prevSeasonPositions[$lg][(int)$row['team_id']] = $pos;
                    $pos++;
                }
            }

            foreach ($grouped as $lg => $rows) {
                foreach ($rows as $idx => $row) {
                    $currentPos = $idx + 1;
                    $prevPos = $prevSeasonPositions[$lg][(int)$row['team_id']] ?? null;
                    $row['rank_delta'] = $prevPos ? ($prevPos - $currentPos) : 0;
                    $row['prev_position'] = $prevPos;
                    $grouped[$lg][$idx] = $row;
                }
            }

            echo json_encode(['success' => true, 'ranking' => $grouped]);
            break;

        case 'save_ranking_totals':
            // Admin já verificado no início
            // Edita diretamente o total de pontos de ranking por time (teams.ranking_points)
            $payload = is_array($jsonPayload) ? $jsonPayload : null;
            $league = $payload['league'] ?? null;
            $teamPoints = $payload['team_points'] ?? [];

            if (!$league || !is_array($teamPoints)) {
                throw new Exception('Liga e lista de pontos são obrigatórias');
            }

            // Garante coluna
            ensureRankingPointsColumn($pdo);
            ensureRankingTitlesColumn($pdo);

            $pdo->beginTransaction();
            try {
                $stmtPoints = $pdo->prepare("UPDATE teams SET ranking_points = ? WHERE id = ? AND league = ?");
                $stmtPointsTitles = $pdo->prepare("UPDATE teams SET ranking_points = ?, ranking_titles = ? WHERE id = ? AND league = ?");
                foreach ($teamPoints as $tp) {
                    $teamId = (int)($tp['team_id'] ?? 0);
                    $points = (int)($tp['points'] ?? 0);
                    if ($teamId <= 0) continue;
                    if (array_key_exists('titles', $tp)) {
                        $titles = (int)($tp['titles'] ?? 0);
                        $stmtPointsTitles->execute([$points, $titles, $teamId, $league]);
                    } else {
                        $stmtPoints->execute([$points, $teamId, $league]);
                    }
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true, 'message' => 'Ranking atualizado com sucesso']);
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
