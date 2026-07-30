<?php
/**
 * Timeline — camada social entre todos os GMs (diferente do Feed do Time
 * em team-history.php, que é só a parede de um time): feed global (posts +
 * timeline automática de todo mundo, com filtro opcional de liga), grid de
 * posts, perfil de um time (com seguir) e busca de contas.
 *
 * Postar/curtir/apagar/stories continuam só em api/team-feed.php — aqui é
 * leitura global mais o toggle de seguir.
 */

// Nunca deixa um aviso/notice do PHP vazar misturado com a resposta (isso
// quebra o JSON pro cliente: "Unexpected token '<'...") — loga de verdade
// em vez de mostrar na tela. A API sempre responde só JSON.
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/team-feed-helpers.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

$user = getUserSession();
if (!$user) jsonResponse(401, ['error' => 'Não autenticado']);
$userId = (int)$user['id'];

ensureTeamFeedTables($pdo);
ensureTimelineTables($pdo);

$stmtMe = $pdo->prepare("SELECT id FROM teams WHERE user_id = ? LIMIT 1");
$stmtMe->execute([$userId]);
$myTeamId = (int)($stmtMe->fetchColumn() ?: 0);

/** Normaliza pra null quando ausente/vazio — importante pro modo global do getTeamTimeline/getTeamPosts. */
function tlParam(?string $v): ?string
{
    $v = trim((string)($v ?? ''));
    return $v !== '' ? $v : null;
}

// Qualquer exceção/erro não previsto vira JSON de erro em vez de estourar
// uma página de erro em HTML no meio da resposta.
try {

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'feed';
    $league = tlParam($_GET['league'] ?? null);
    $before = tlParam($_GET['before'] ?? null);

    if ($action === 'feed') {
        jsonResponse(200, [
            'success' => true,
            'stories' => $before ? [] : getActiveStoriesGlobal($pdo, $userId, $league),
            'posts' => getTeamPosts($pdo, null, $userId, 20, $before, $league),
            'timeline' => getTeamTimeline($pdo, null, 20, $before, $league),
        ]);
    }

    if ($action === 'posts_grid') {
        jsonResponse(200, ['success' => true, 'posts' => getTeamPosts($pdo, null, $userId, 24, $before, $league)]);
    }

    if ($action === 'perfil') {
        $teamId = (int)($_GET['team_id'] ?? 0) ?: $myTeamId;
        if (!$teamId) jsonResponse(404, ['error' => 'Você ainda não tem um time — não há perfil pra mostrar.']);

        $stmtT = $pdo->prepare("SELECT id, city, name, photo_url, league, user_id FROM teams WHERE id = ?");
        $stmtT->execute([$teamId]);
        $team = $stmtT->fetch(PDO::FETCH_ASSOC);
        if (!$team) jsonResponse(404, ['error' => 'Time não encontrado.']);

        $isOwn = $teamId === $myTeamId;

        $stmtFers = $pdo->prepare("SELECT COUNT(*) FROM team_follows WHERE followed_team_id = ?");
        $stmtFers->execute([$teamId]);
        $stmtFing = $pdo->prepare("SELECT COUNT(*) FROM team_follows WHERE follower_team_id = ?");
        $stmtFing->execute([$teamId]);

        $isFollowing = false;
        if (!$isOwn && $myTeamId) {
            $stmtIsF = $pdo->prepare("SELECT 1 FROM team_follows WHERE follower_team_id = ? AND followed_team_id = ? LIMIT 1");
            $stmtIsF->execute([$myTeamId, $teamId]);
            $isFollowing = (bool)$stmtIsF->fetch();
        }

        jsonResponse(200, [
            'success' => true,
            'team' => ['id' => (int)$team['id'], 'city' => $team['city'], 'name' => $team['name'], 'photo_url' => $team['photo_url'], 'league' => $team['league']],
            'is_own' => $isOwn,
            'can_post' => $isOwn || hasAdminAccess($pdo, $userId),
            'follower_count' => (int)$stmtFers->fetchColumn(),
            'following_count' => (int)$stmtFing->fetchColumn(),
            'is_following' => $isFollowing,
            'stories' => getActiveStories($pdo, $teamId, $userId),
            'posts' => getTeamPosts($pdo, $teamId, $userId),
            'timeline' => getTeamTimeline($pdo, $teamId),
        ]);
    }

    if ($action === 'buscar_contas') {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) jsonResponse(200, ['success' => true, 'resultados' => []]);

        $like = '%' . $q . '%';
        $stmt = $pdo->prepare("
            SELECT t.id AS team_id, t.user_id, t.league, t.photo_url,
                   CONCAT(t.city,' ',t.name) AS time_label, u.name AS gm_label
            FROM teams t
            JOIN users u ON u.id = t.user_id
            WHERE (CONCAT(t.city,' ',t.name) LIKE ? OR u.name LIKE ?)
            ORDER BY u.name ASC LIMIT 15
        ");
        $stmt->execute([$like, $like]);
        jsonResponse(200, ['success' => true, 'resultados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    jsonResponse(400, ['error' => 'Ação inválida']);
}

if ($method === 'POST') {
    $body = readJsonBody();
    $action = $body['action'] ?? '';
    $teamId = (int)($body['team_id'] ?? 0);

    if ($action === 'seguir' || $action === 'deixar_de_seguir') {
        if (!$myTeamId) jsonResponse(422, ['error' => 'Você precisa ter um time pra seguir outros GMs.']);
        if (!$teamId) jsonResponse(422, ['error' => 'team_id é obrigatório']);
        if ($teamId === $myTeamId) jsonResponse(422, ['error' => 'Você não pode seguir o seu próprio time.']);

        if ($action === 'seguir') {
            $pdo->prepare("INSERT IGNORE INTO team_follows (follower_team_id, followed_team_id) VALUES (?,?)")->execute([$myTeamId, $teamId]);
        } else {
            $pdo->prepare("DELETE FROM team_follows WHERE follower_team_id = ? AND followed_team_id = ?")->execute([$myTeamId, $teamId]);
        }

        $stmtFers = $pdo->prepare("SELECT COUNT(*) FROM team_follows WHERE followed_team_id = ?");
        $stmtFers->execute([$teamId]);
        jsonResponse(200, ['success' => true, 'is_following' => $action === 'seguir', 'follower_count' => (int)$stmtFers->fetchColumn()]);
    }

    jsonResponse(400, ['error' => 'Ação inválida']);
}

jsonResponse(405, ['error' => 'Método não permitido']);

} catch (Throwable $e) {
    error_log('api/timeline.php: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(500, ['error' => 'Erro interno. Tente de novo em instantes.']);
}
