<?php
/**
 * Feed do Time — timeline automática (trades, draft, punições, prêmios,
 * playoffs) juntada com posts manuais do GM (foto+texto), curtidas e
 * Stories que somem em 24h. Visível pra qualquer logado; só o dono do
 * time (ou admin) posta/apaga no feed do próprio time.
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

// Qualquer exceção/erro não previsto vira JSON de erro em vez de estourar
// uma página de erro em HTML no meio da resposta.
try {

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'estado';
    $teamId = (int)($_GET['team_id'] ?? 0);
    if (!$teamId) jsonResponse(422, ['error' => 'team_id é obrigatório']);

    if ($action === 'estado') {
        jsonResponse(200, [
            'success' => true,
            'team_id' => $teamId,
            'can_post' => isTeamGmOrAdmin($pdo, $user, $teamId),
            'stories' => getActiveStories($pdo, $teamId, $userId),
            'posts' => getTeamPosts($pdo, $teamId, $userId),
            'timeline' => getTeamTimeline($pdo, $teamId),
        ]);
    }

    if ($action === 'timeline') {
        $before = $_GET['before'] ?? null;
        jsonResponse(200, ['success' => true, 'timeline' => getTeamTimeline($pdo, $teamId, 30, $before)]);
    }

    if ($action === 'posts') {
        $before = $_GET['before'] ?? null;
        jsonResponse(200, ['success' => true, 'posts' => getTeamPosts($pdo, $teamId, $userId, 20, $before)]);
    }

    jsonResponse(400, ['error' => 'Ação inválida']);
}

if ($method === 'POST') {
    $body = readJsonBody();
    $action = $body['action'] ?? '';
    $teamId = (int)($body['team_id'] ?? 0);

    if ($action === 'postar') {
        if (!$teamId) jsonResponse(422, ['error' => 'team_id é obrigatório']);
        if (!isTeamGmOrAdmin($pdo, $user, $teamId)) jsonResponse(403, ['error' => 'Só o GM do time (ou admin) pode postar aqui.']);

        $texto = trim((string)($body['texto'] ?? ''));
        $fotoBase64 = trim((string)($body['photo_base64'] ?? ''));
        if ($texto === '' && $fotoBase64 === '') jsonResponse(422, ['error' => 'Escreva algo ou adicione uma foto.']);

        $photoUrl = $fotoBase64 !== '' ? salvarFotoFeed($fotoBase64, 'team-posts', $teamId) : null;

        $stmt = $pdo->prepare("INSERT INTO team_posts (team_id, author_user_id, texto, photo_url) VALUES (?,?,?,?)");
        $stmt->execute([$teamId, $userId, $texto !== '' ? $texto : null, $photoUrl]);

        jsonResponse(200, ['success' => true, 'posts' => getTeamPosts($pdo, $teamId, $userId)]);
    }

    if ($action === 'curtir' || $action === 'descurtir') {
        $postId = (int)($body['post_id'] ?? 0);
        if (!$postId) jsonResponse(422, ['error' => 'post_id é obrigatório']);

        if ($action === 'curtir') {
            $pdo->prepare("INSERT IGNORE INTO team_post_likes (post_id, user_id) VALUES (?,?)")->execute([$postId, $userId]);
        } else {
            $pdo->prepare("DELETE FROM team_post_likes WHERE post_id = ? AND user_id = ?")->execute([$postId, $userId]);
        }
        $stmtC = $pdo->prepare("SELECT COUNT(*) FROM team_post_likes WHERE post_id = ?");
        $stmtC->execute([$postId]);
        $count = (int)$stmtC->fetchColumn();
        jsonResponse(200, ['success' => true, 'like_count' => $count, 'liked_by_me' => $action === 'curtir']);
    }

    if ($action === 'excluir_post') {
        $postId = (int)($body['post_id'] ?? 0);
        if (!$postId) jsonResponse(422, ['error' => 'post_id é obrigatório']);

        $stmt = $pdo->prepare("SELECT team_id FROM team_posts WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$postId]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$post) jsonResponse(404, ['error' => 'Post não encontrado']);
        if (!isTeamGmOrAdmin($pdo, $user, (int)$post['team_id'])) jsonResponse(403, ['error' => 'Sem permissão.']);

        $pdo->prepare("UPDATE team_posts SET deleted_at = NOW() WHERE id = ?")->execute([$postId]);
        jsonResponse(200, ['success' => true]);
    }

    if ($action === 'postar_story') {
        if (!$teamId) jsonResponse(422, ['error' => 'team_id é obrigatório']);
        if (!isTeamGmOrAdmin($pdo, $user, $teamId)) jsonResponse(403, ['error' => 'Só o GM do time (ou admin) pode postar aqui.']);

        $fotoBase64 = trim((string)($body['photo_base64'] ?? ''));
        if ($fotoBase64 === '') jsonResponse(422, ['error' => 'A story precisa de uma foto.']);
        $texto = trim((string)($body['texto'] ?? ''));

        $photoUrl = salvarFotoFeed($fotoBase64, 'team-stories', $teamId);
        if (!$photoUrl) jsonResponse(422, ['error' => 'Imagem inválida.']);

        $stmt = $pdo->prepare("INSERT INTO team_stories (team_id, author_user_id, photo_url, texto, expira_em) VALUES (?,?,?,?, NOW() + INTERVAL 24 HOUR)");
        $stmt->execute([$teamId, $userId, $photoUrl, $texto !== '' ? $texto : null]);

        jsonResponse(200, ['success' => true, 'stories' => getActiveStories($pdo, $teamId, $userId)]);
    }

    if ($action === 'excluir_story') {
        $storyId = (int)($body['story_id'] ?? 0);
        if (!$storyId) jsonResponse(422, ['error' => 'story_id é obrigatório']);

        $stmt = $pdo->prepare("SELECT team_id FROM team_stories WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$storyId]);
        $story = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$story) jsonResponse(404, ['error' => 'Story não encontrada']);
        if (!isTeamGmOrAdmin($pdo, $user, (int)$story['team_id'])) jsonResponse(403, ['error' => 'Sem permissão.']);

        $pdo->prepare("UPDATE team_stories SET deleted_at = NOW() WHERE id = ?")->execute([$storyId]);
        jsonResponse(200, ['success' => true]);
    }

    if ($action === 'visualizar_story') {
        $storyId = (int)($body['story_id'] ?? 0);
        if (!$storyId) jsonResponse(422, ['error' => 'story_id é obrigatório']);
        $pdo->prepare("INSERT IGNORE INTO team_story_views (story_id, user_id) VALUES (?,?)")->execute([$storyId, $userId]);
        jsonResponse(200, ['success' => true]);
    }

    jsonResponse(400, ['error' => 'Ação inválida']);
}

jsonResponse(405, ['error' => 'Método não permitido']);

} catch (Throwable $e) {
    error_log('api/team-feed.php: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    $resp = ['error' => 'Erro interno. Tente de novo em instantes.'];
    // Diagnóstico temporário (só pra admin) — remover assim que a causa raiz for confirmada.
    if (hasAdminAccess($pdo, $userId)) {
        $resp['debug'] = $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    jsonResponse(500, $resp);
}
