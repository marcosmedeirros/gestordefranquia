<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

// Verificar autenticação
$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$pdo = db();

function findActiveDraftSession(PDO $pdo, ?string $league, ?int $seasonId, ?int $seasonYear): ?array
{
    try {
        if ($seasonId) {
            $stmt = $pdo->prepare("SELECT ds.* FROM draft_sessions ds WHERE ds.season_id = ? AND ds.status IN ('setup','in_progress') ORDER BY ds.created_at DESC LIMIT 1");
            $stmt->execute([$seasonId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
        if ($league && $seasonYear) {
            $stmt = $pdo->prepare("SELECT ds.* FROM draft_sessions ds INNER JOIN seasons s ON ds.season_id = s.id WHERE s.league = ? AND s.year = ? AND ds.status IN ('setup','in_progress') ORDER BY ds.created_at DESC LIMIT 1");
            $stmt->execute([$league, $seasonYear]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
        if ($league) {
            $stmt = $pdo->prepare("SELECT ds.* FROM draft_sessions ds WHERE ds.league = ? AND ds.status IN ('setup','in_progress') ORDER BY ds.created_at DESC LIMIT 1");
            $stmt->execute([$league]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
    } catch (Exception $e) {
        return null;
    }
    return null;
}

function buildDraftOrderMap(PDO $pdo, int $draftSessionId): array
{
    $map = [];
    try {
        $stmt = $pdo->prepare('SELECT id, team_id, original_team_id, pick_position, round FROM draft_order WHERE draft_session_id = ? ORDER BY round ASC, pick_position ASC, id ASC');
        $stmt->execute([$draftSessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $overall = 1;
        foreach ($rows as $row) {
            $key = (int)$row['original_team_id'] . '-' . (int)$row['round'];
            $map[$key] = [
                'draft_order_id' => (int)$row['id'],
                'team_id' => (int)$row['team_id'],
                'original_team_id' => (int)$row['original_team_id'],
                'round' => (int)$row['round'],
                'pick_position' => (int)$row['pick_position'],
                'pick_number' => $overall
            ];
            $overall++;
        }
    } catch (Exception $e) {
        return [];
    }
    return $map;
}

function applyDraftContextToPick(array $pick, ?array $draftSession, array $draftMap, ?int $sessionSeasonId = null, ?int $sessionYear = null): array
{
    if (!$draftSession) {
        return $pick;
    }
    if ($sessionSeasonId && !empty($pick['season_id']) && (int)$pick['season_id'] !== $sessionSeasonId) {
        return $pick;
    }
    if ($sessionYear && empty($pick['season_id']) && !empty($pick['season_year']) && (int)$pick['season_year'] !== $sessionYear) {
        return $pick;
    }
    $round = isset($pick['round']) ? (int)$pick['round'] : 0;
    $originalTeamId = isset($pick['original_team_id']) ? (int)$pick['original_team_id'] : 0;
    if ($round <= 0 || $originalTeamId <= 0) {
        return $pick;
    }
    $key = $originalTeamId . '-' . $round;
    if (!isset($draftMap[$key])) {
        return $pick;
    }
    $info = $draftMap[$key];
    $pick['draft_session_id'] = (int)$draftSession['id'];
    $pick['draft_pick_number'] = (int)$info['pick_number'];
    $pick['draft_pick_position'] = (int)$info['pick_position'];
    $pick['draft_round'] = (int)$info['round'];
    return $pick;
}

// POST - Desabilitado: sistema gera picks automaticamente
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Edição manual de picks desabilitada. As picks são geradas automaticamente.']);
    exit;
}

// DELETE - Desabilitado
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Exclusão manual de picks desabilitada. As picks são geridas automaticamente.']);
    exit;
}

// PUT - Desabilitado
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Atualização manual de picks desabilitada. As picks são geradas automaticamente.']);
    exit;
}

// GET - Listar picks
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $teamId = $_GET['team_id'] ?? null;
    $includeAway = isset($_GET['include_away']) && $_GET['include_away'] === '1';

    if (!$teamId) {
        echo json_encode(['success' => false, 'error' => 'Team ID não informado']);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT p.*, 
               orig.city as original_team_city, orig.name as original_team_name,
               last_t.city as last_owner_city, last_t.name as last_owner_name
        FROM picks p
        LEFT JOIN teams orig ON p.original_team_id = orig.id
        LEFT JOIN teams last_t ON p.last_owner_team_id = last_t.id
        WHERE p.team_id = ?
        ORDER BY p.season_year, p.round
    ');
    $stmt->execute([$teamId]);
    $picks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtTeam = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $stmtTeam->execute([$teamId]);
    $league = $stmtTeam->fetchColumn() ?: null;

    $seasonId = null;
    $seasonYear = null;
    foreach ($picks as $pick) {
        if (!$seasonId && !empty($pick['season_id'])) {
            $seasonId = (int)$pick['season_id'];
        }
        if (!$seasonYear && !empty($pick['season_year'])) {
            $seasonYear = (int)$pick['season_year'];
        }
        if ($seasonId || $seasonYear) {
            break;
        }
    }

    $draftSession = findActiveDraftSession($pdo, $league, $seasonId, $seasonYear);
    if ($draftSession) {
        $draftMap = buildDraftOrderMap($pdo, (int)$draftSession['id']);
        if (!empty($draftMap)) {
            $sessionSeasonId = !empty($draftSession['season_id']) ? (int)$draftSession['season_id'] : null;
            $sessionYear = null;
            if ($sessionSeasonId) {
                try {
                    $stmtSeason = $pdo->prepare('SELECT year FROM seasons WHERE id = ?');
                    $stmtSeason->execute([$sessionSeasonId]);
                    $sessionYear = (int)($stmtSeason->fetchColumn() ?: 0);
                } catch (Exception $e) {
                    $sessionYear = null;
                }
            }
            $picks = array_map(static function ($pick) use ($draftSession, $draftMap, $sessionSeasonId, $sessionYear) {
                return applyDraftContextToPick($pick, $draftSession, $draftMap, $sessionSeasonId, $sessionYear);
            }, $picks);
        }
    }
    
    $payload = ['success' => true, 'picks' => $picks];

    if ($includeAway) {
        $stmtAway = $pdo->prepare('
            SELECT p.*, current_owner.city as current_team_city, current_owner.name as current_team_name
            FROM picks p
            LEFT JOIN teams current_owner ON p.team_id = current_owner.id
            WHERE p.original_team_id = ? AND p.team_id <> ?
            ORDER BY p.season_year, p.round
        ');
        $stmtAway->execute([$teamId, $teamId]);
        $payload['picks_away'] = $stmtAway->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode($payload);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);
