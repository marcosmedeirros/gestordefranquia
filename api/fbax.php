<?php
/**
 * A API do FBAX — o Twitter da FBA. @see backend/fbax.php pro modelo.
 *
 * Leitura: feed (com filtro de liga e paginação por data) e thread (um post
 * com as respostas). Escrita: publicar, responder, repostar, curtir, apagar.
 *
 * Postar exige ser GM do time (ou admin), como no feed: quem fala é a
 * franquia. Curtir não exige nada além de estar logado — curtida é do
 * torcedor, e exigir time deixaria o pessoal sem franquia de fora.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/fbax.php';
require_once __DIR__ . '/../backend/fbax_avisos.php';

$pdo = db();
$user = getUserSession();
if (!$user) jsonResponse(401, ['error' => 'Não autenticado']);
$userId = (int)$user['id'];

$st = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? LIMIT 1');
$st->execute([$userId]);
$myTeamId = (int)($st->fetchColumn() ?: 0);

/** Vazio vira null — o feed usa null pra "sem filtro". */
$param = function ($v): ?string {
    $v = trim((string)($v ?? ''));
    return $v !== '' ? $v : null;
};

try {

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $acao = $_GET['action'] ?? 'feed';

    if ($acao === 'feed') {
        jsonResponse(200, [
            'success'    => true,
            'meu_time'   => $myTeamId,
            'posso_postar' => $myTeamId > 0 && isTeamGmOrAdmin($pdo, $user, $myTeamId),
            'posts'      => fbaxFeed($pdo, $userId, $myTeamId,
                                     $param($_GET['league'] ?? null), $param($_GET['before'] ?? null)),
        ]);
    }

    if ($acao === 'thread') {
        $id = (int)($_GET['id'] ?? 0);
        $post = $id > 0 ? fbaxPost($pdo, $id, $userId, $myTeamId) : null;
        if (!$post) jsonResponse(404, ['error' => 'Post não encontrado.']);
        jsonResponse(200, [
            'success'   => true,
            'meu_time'  => $myTeamId,
            'post'      => $post,
            'respostas' => fbaxRespostas($pdo, $id, $userId, $myTeamId),
        ]);
    }

    /* A LISTA DE TIMES VAI INTEIRA, UMA VEZ. São 122 e a tela precisa dela
       em dois momentos que não podem esperar servidor: completar o @ na
       tecla, e desenhar a menção já marcada em cada post do feed. */
    if ($acao === 'times') {
        jsonResponse(200, ['success' => true, 'times' => array_map(
            fn($t) => ['id' => $t['id'], 'slug' => $t['slug'], 'nome' => $t['nome'],
                      'curto' => $t['curto'], 'liga' => $t['liga'], 'logo' => $t['logo']],
            fbaxTimesParaMencao($pdo))]);
    }

    if ($acao === 'avisos') {
        jsonResponse(200, [
            'success'   => true,
            'avisos'    => fbaxAvisosLista($pdo, $userId),
            'nao_lidos' => fbaxAvisosNaoLidos($pdo, $userId),
        ]);
    }

    jsonResponse(400, ['error' => 'Ação inválida']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $acao = $body['action'] ?? '';

    /* CURTIR PRIMEIRO, porque é a única que não pede time. As outras todas
       escrevem em nome de uma franquia e passam pela trava logo abaixo. */
    if ($acao === 'curtir') {
        $id = (int)($body['post_id'] ?? 0);
        if (!$id) jsonResponse(422, ['error' => 'post_id é obrigatório']);
        jsonResponse(200, ['success' => true] + fbaxCurtir($pdo, $id, $userId));
    }

    if ($acao === 'avisos_lidos') {
        fbaxAvisosMarcarLidos($pdo, $userId, (int)($body['aviso_id'] ?? 0));
        jsonResponse(200, ['success' => true, 'nao_lidos' => fbaxAvisosNaoLidos($pdo, $userId)]);
    }

    if ($acao === 'apagar') {
        $id = (int)($body['post_id'] ?? 0);
        if (!$id) jsonResponse(422, ['error' => 'post_id é obrigatório']);
        $r = fbaxApagar($pdo, $id, $user);
        if (!$r['ok']) jsonResponse(403, ['error' => $r['erro']]);
        jsonResponse(200, ['success' => true]);
    }

    if (in_array($acao, ['postar', 'responder', 'repostar'], true)) {
        if (!$myTeamId) jsonResponse(403, ['error' => 'Você precisa ter um time pra postar no FBAX.']);
        if (!isTeamGmOrAdmin($pdo, $user, $myTeamId)) {
            jsonResponse(403, ['error' => 'Só o GM do time (ou admin) posta pela franquia.']);
        }

        $alvo = (int)($body['post_id'] ?? 0);
        if (($acao === 'responder' || $acao === 'repostar') && !$alvo) {
            jsonResponse(422, ['error' => 'post_id é obrigatório']);
        }

        $r = fbaxPublicar(
            $pdo, $myTeamId, $userId,
            (string)($body['texto'] ?? ''),
            (string)($body['photo_base64'] ?? ''),
            $acao === 'responder' ? $alvo : null,
            $acao === 'repostar'  ? $alvo : null
        );
        if (!$r['ok']) jsonResponse(422, ['error' => $r['erro']]);

        /* QUEM FOI MENCIONADO OU RESPONDIDO FICA SABENDO. Depois do post
           gravado e fora de transação: avisar é consequência, e um erro aqui
           não pode derrubar a publicação que a pessoa pediu. Repost seco não
           entra — passa id 0 e sai sem fazer nada. */
        if ($r['id']) {
            fbaxAvisarDoPost($pdo, $r['id'], $userId, $myTeamId, (string)($body['texto'] ?? ''),
                             $acao === 'responder' ? $alvo : null,
                             $acao === 'repostar'  ? $alvo : null);
        }

        /* Devolve o post pronto pra tela encaixar sem recarregar o feed — menos
           um ida-e-volta, e a rolagem de quem está lendo não salta. O repost
           desfeito devolve id 0, e aí não há post novo pra mandar. */
        jsonResponse(200, [
            'success' => true,
            'post'    => $r['id'] ? fbaxPost($pdo, $r['id'], $userId, $myTeamId) : null,
            'desfez'  => $r['id'] === 0,
        ]);
    }

    jsonResponse(400, ['error' => 'Ação inválida']);
}

jsonResponse(405, ['error' => 'Método não permitido']);

} catch (Throwable $e) {
    error_log('[fbax] ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Deu erro aqui. Avisa o admin.']);
}
