<?php
ob_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
// Proteção de pick: a trava do ano seguinte e quem pode proteger (só ELITE).
require_once dirname(__DIR__) . '/backend/pick_protection.php';
require_once dirname(__DIR__) . '/backend/draft_swaps.php';  // findActiveDraftSession()

// Verificar autenticação
$user = getUserSession();
if (!$user) {
    http_response_code(401);
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$pdo = db();

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

function computeSeasonDisplayYear(?array $row): ?int
{
    if (!$row) {
        return null;
    }
    if (isset($row['start_year'], $row['season_number'])) {
        return (int)$row['start_year'] + (int)$row['season_number'] - 1;
    }
    if (!empty($row['year'])) {
        return (int)$row['year'];
    }
    return null;
}

// POST - Desabilitado: sistema gera picks automaticamente
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(405);
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Edição manual de picks desabilitada. As picks são geradas automaticamente.']);
    exit;
}

// DELETE - Desabilitado
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    http_response_code(405);
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Exclusão manual de picks desabilitada. As picks são geridas automaticamente.']);
    exit;
}

// PUT - Desabilitado
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    http_response_code(405);
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Atualização manual de picks desabilitada. As picks são geradas automaticamente.']);
    exit;
}

// GET - Listar picks
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $teamId = $_GET['team_id'] ?? null;
    $includeAway = isset($_GET['include_away']) && $_GET['include_away'] === '1';

    if (!$teamId) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Team ID não informado']);
        exit;
    }

    // ── Calcular ano corrente ANTES da query para filtrar no SQL ──────────
    $stmtTeam = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $stmtTeam->execute([$teamId]);
    $league = $stmtTeam->fetchColumn() ?: null;

    // O corte vem do ponto único (backend/helpers.php): as três telas de pick
    // faziam esta conta cada uma de um jeito e discordavam entre si.
    $currentYear = anoDeCorteDasPicks($pdo, $league);
    // ─────────────────────────────────────────────────────────────────────

    $stmt = $pdo->prepare('
        SELECT p.*,
               orig.city as original_team_city, orig.name as original_team_name,
               last_t.city as last_owner_city, last_t.name as last_owner_name,
               swap_team.id as swap_partner_team_id,
               swap_team.city as swap_partner_city, swap_team.name as swap_partner_name
        FROM picks p
        LEFT JOIN teams orig ON p.original_team_id = orig.id
        LEFT JOIN teams last_t ON p.last_owner_team_id = last_t.id
        LEFT JOIN picks swap_pick ON p.swap_pair_pick_id = swap_pick.id
        LEFT JOIN teams swap_team ON swap_pick.original_team_id = swap_team.id
        WHERE p.team_id = ?
          AND (p.season_year IS NULL OR p.season_year >= ?)
        ORDER BY p.season_year, p.round
    ');
    $stmt->execute([$teamId, $currentYear]);
    $picks = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                    $stmtSeason = $pdo->prepare('SELECT s.season_number, s.year, sp.start_year FROM seasons s LEFT JOIN sprints sp ON s.sprint_id = sp.id WHERE s.id = ?');
                    $stmtSeason->execute([$sessionSeasonId]);
                    $sessionYear = computeSeasonDisplayYear($stmtSeason->fetch(PDO::FETCH_ASSOC) ?: null);
                } catch (Exception $e) {
                    $sessionYear = null;
                }
            }
            $picks = array_map(static function ($pick) use ($draftSession, $draftMap, $sessionSeasonId, $sessionYear) {
                return applyDraftContextToPick($pick, $draftSession, $draftMap, $sessionSeasonId, $sessionYear);
            }, $picks);
        }
    }

    $picks = array_values(array_filter($picks, function($pick) use ($currentYear) {
        $y = (int)($pick['season_year'] ?? 0);
        return $y >= $currentYear;
    }));

    // Proteção de pick (só ELITE): a tela precisa saber de duas coisas — se a
    // pick está travada por servir de lastro, e se pode receber proteção. A
    // regra fica no backend; aqui só é entregue pronta pra não haver uma
    // segunda versão dela no navegador.
    $picks = protecaoAnotarPicks($pdo, $picks, (string)$league);

    // O parenteses da linha de "copiar time" tambem sai pronto daqui:
    // e a MESMA funcao que o dashboard e o my-roster usam, e as tres
    // telas tinham cada uma a sua copia dessa linha.
    foreach ($picks as &$__pk) { $__pk['copia'] = pickCopiaParenteses($__pk); }
    unset($__pk);

    $payload = ['success' => true, 'picks' => $picks,
                'protecoes' => protecaoLigaUsa($league) ? PICK_PROTECOES : []];

    if ($includeAway) {
        $stmtAway = $pdo->prepare('
            SELECT p.*, current_owner.city as current_team_city, current_owner.name as current_team_name,
                   swap_team.city as swap_partner_city, swap_team.name as swap_partner_name
            FROM picks p
            LEFT JOIN teams current_owner ON p.team_id = current_owner.id
            LEFT JOIN picks swap_pick ON p.swap_pair_pick_id = swap_pick.id
            LEFT JOIN teams swap_team ON swap_pick.original_team_id = swap_team.id
            WHERE p.original_team_id = ? AND p.team_id <> ?
            ORDER BY p.season_year, p.round
        ');
        $stmtAway->execute([$teamId, $teamId]);
        $payload['picks_away'] = $stmtAway->fetchAll(PDO::FETCH_ASSOC);
    }

    ob_end_clean();
    echo json_encode($payload);
    exit;
}

ob_end_clean();
echo json_encode(['success' => false, 'error' => 'Método não suportado']);
