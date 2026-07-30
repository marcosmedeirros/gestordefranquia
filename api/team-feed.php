<?php
/**
 * Feed do Time — timeline automática (trades, draft, punições, prêmios,
 * playoffs) juntada com posts manuais do GM (foto+texto), curtidas e
 * Stories que somem em 24h. Visível pra qualquer logado; só o dono do
 * time (ou admin) posta/apaga no feed do próprio time.
 */

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

$user = getUserSession();
if (!$user) jsonResponse(401, ['error' => 'Não autenticado']);
$userId = (int)$user['id'];

function ensureTeamFeedTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_id INT NOT NULL,
        author_user_id INT NOT NULL,
        texto TEXT NULL,
        photo_url VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_team_posts_team (team_id, created_at),
        INDEX idx_team_posts_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS team_post_likes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        post_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_post_user (post_id, user_id),
        INDEX idx_tpl_post (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS team_stories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_id INT NOT NULL,
        author_user_id INT NOT NULL,
        photo_url VARCHAR(255) NOT NULL,
        texto VARCHAR(280) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expira_em DATETIME NOT NULL,
        deleted_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_stories_team_active (team_id, expira_em),
        INDEX idx_stories_expira (expira_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS team_story_views (
        id INT AUTO_INCREMENT PRIMARY KEY,
        story_id INT NOT NULL,
        user_id INT NOT NULL,
        viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_story_user (story_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureTeamFeedTables($pdo);

function isTeamGmOrAdmin(PDO $pdo, array $user, int $teamId): bool
{
    if (hasAdminAccess($pdo, (int)$user['id'])) return true;
    $stmt = $pdo->prepare("SELECT 1 FROM teams WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$teamId, (int)$user['id']]);
    return (bool)$stmt->fetch();
}

/**
 * Timeline automática: uma query pequena por tabela-fonte (todas já
 * indexadas por team_id), normalizada em PHP e ordenada por data — mais
 * simples e seguro que um UNION gigante entre tabelas bem diferentes.
 */
function getTeamTimeline(PDO $pdo, int $teamId, int $limit = 30, ?string $before = null): array
{
    $eventos = [];
    // "before" entra no WHERE/HAVING de cada query-fonte (não só filtrado depois
    // em PHP) — senão, times com mais de $limit trades (por exemplo) nunca
    // teriam os eventos mais antigos buscados na página seguinte.

    // Trades 1-para-1 aceitas
    $sql = "
        SELECT t.id, COALESCE(t.updated_at, t.created_at) AS data_evt,
               GROUP_CONCAT(CONCAT(ti.player_name, IF(ti.from_team=1,' (enviado)',' (recebido)')) SEPARATOR ', ') AS itens
        FROM trades t
        JOIN trade_items ti ON ti.trade_id = t.id
        WHERE t.status = 'accepted' AND (t.from_team_id = ? OR t.to_team_id = ?)
        GROUP BY t.id" . ($before ? " HAVING data_evt < ?" : "") . "
        ORDER BY data_evt DESC LIMIT ?
    ";
    $params = $before ? [$teamId, $teamId, $before, $limit] : [$teamId, $teamId, $limit];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $eventos[] = ['tipo' => 'trade', 'icone' => '🔄', 'texto' => 'Trade concluída: ' . ($r['itens'] ?: '—'), 'data' => $r['data_evt']];
    }

    // Trades multi-time aceitas (esse time participou)
    $sql = "
        SELECT mt.id, mt.updated_at AS data_evt,
               GROUP_CONCAT(DISTINCT CONCAT(mti.player_name, IF(mti.from_team_id=?,' (enviado)',' (recebido)')) SEPARATOR ', ') AS itens
        FROM multi_trades mt
        JOIN multi_trade_teams mtt ON mtt.trade_id = mt.id AND mtt.team_id = ?
        LEFT JOIN multi_trade_items mti ON mti.trade_id = mt.id AND (mti.from_team_id = ? OR mti.to_team_id = ?) AND mti.player_name IS NOT NULL
        WHERE mt.status = 'accepted'" . ($before ? " AND mt.updated_at < ?" : "") . "
        GROUP BY mt.id
        ORDER BY data_evt DESC LIMIT ?
    ";
    $params = $before ? [$teamId, $teamId, $teamId, $teamId, $before, $limit] : [$teamId, $teamId, $teamId, $teamId, $limit];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $eventos[] = ['tipo' => 'trade', 'icone' => '🔄', 'texto' => 'Trade multi-time concluída' . ($r['itens'] ? ': ' . $r['itens'] : ''), 'data' => $r['data_evt']];
    }

    // Punições
    $sql = "SELECT id, motive, punishment_label, type, created_at, reverted_at FROM team_punishments WHERE team_id = ?" . ($before ? " AND created_at < ?" : "") . " ORDER BY created_at DESC LIMIT ?";
    $params = $before ? [$teamId, $before, $limit] : [$teamId, $limit];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $label = $r['punishment_label'] ?: ($r['motive'] ?: $r['type']);
        $eventos[] = ['tipo' => 'punicao', 'icone' => '⚠️', 'texto' => 'Punição: ' . $label, 'data' => $r['created_at']];
    }

    // Prêmios da temporada
    $sql = "SELECT id, award_type, player_name, created_at FROM season_awards WHERE team_id = ?" . ($before ? " AND created_at < ?" : "") . " ORDER BY created_at DESC LIMIT ?";
    $params = $before ? [$teamId, $before, $limit] : [$teamId, $limit];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $eventos[] = ['tipo' => 'premio', 'icone' => '🏆', 'texto' => $r['player_name'] . ' ganhou ' . $r['award_type'], 'data' => $r['created_at']];
    }

    // Playoffs (título/vice/eliminação) — não usar hall_of_fame aqui, que é só
    // um contador agregado e duplicaria os mesmos títulos.
    $labels = [
        'champion' => '🏆 Conquistou o título da temporada!',
        'runner_up' => '🥈 Foi vice-campeão da temporada.',
        'conference_final' => 'Chegou à final de conferência.',
        'second_round' => 'Chegou à segunda rodada dos playoffs.',
        'first_round' => 'Se classificou pros playoffs.',
    ];
    $sql = "SELECT id, position, created_at FROM playoff_results WHERE team_id = ?" . ($before ? " AND created_at < ?" : "") . " ORDER BY created_at DESC LIMIT ?";
    $params = $before ? [$teamId, $before, $limit] : [$teamId, $limit];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $eventos[] = ['tipo' => 'playoff', 'icone' => '🏀', 'texto' => $labels[$r['position']] ?? $r['position'], 'data' => $r['created_at']];
    }

    // Picks de draft (draft de temporada + draft inicial), nome via lookup em lote
    foreach ([['draft_order', 'draft_pool'], ['initdraft_order', 'initdraft_pool']] as [$tabOrder, $tabPool]) {
        $sql = "SELECT id, round, pick_position, picked_player_id, picked_at FROM {$tabOrder} WHERE team_id = ? AND picked_player_id IS NOT NULL" . ($before ? " AND picked_at < ?" : "") . " ORDER BY picked_at DESC LIMIT ?";
        $params = $before ? [$teamId, $before, $limit] : [$teamId, $limit];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $picks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$picks) continue;

        $poolIds = array_map(fn($p) => (int)$p['picked_player_id'], $picks);
        $ph = implode(',', array_fill(0, count($poolIds), '?'));
        $stmtP = $pdo->prepare("SELECT id, name, position FROM {$tabPool} WHERE id IN ($ph)");
        $stmtP->execute($poolIds);
        $poolMap = [];
        foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) $poolMap[(int)$p['id']] = $p;

        foreach ($picks as $r) {
            $jogador = $poolMap[(int)$r['picked_player_id']] ?? null;
            $nome = $jogador ? $jogador['name'] . ' (' . $jogador['position'] . ')' : 'jogador';
            $eventos[] = ['tipo' => 'draft', 'icone' => '🎓', 'texto' => "Selecionou {$nome} — Rodada {$r['round']}, Pick {$r['pick_position']}", 'data' => $r['picked_at']];
        }
    }

    usort($eventos, fn($a, $b) => strtotime($b['data']) <=> strtotime($a['data']));

    // Cada fonte já trouxe até $limit linhas mais recentes (e mais antigas que
    // $before quando presente) — a junção pode passar de $limit, então corta
    // aqui; a próxima página usa a data do último item devolvido como "before".
    return array_slice($eventos, 0, $limit);
}

function getTeamPosts(PDO $pdo, int $teamId, int $userId, int $limit = 20, ?string $before = null): array
{
    $sql = "SELECT tp.id, tp.team_id, tp.author_user_id, tp.texto, tp.photo_url, tp.created_at,
                   u.name AS author_name, u.photo_url AS author_photo,
                   (SELECT COUNT(*) FROM team_post_likes WHERE post_id = tp.id) AS like_count,
                   EXISTS(SELECT 1 FROM team_post_likes WHERE post_id = tp.id AND user_id = ?) AS liked_by_me
            FROM team_posts tp
            JOIN users u ON u.id = tp.author_user_id
            WHERE tp.team_id = ? AND tp.deleted_at IS NULL";
    $params = [$userId, $teamId];
    if ($before) { $sql .= " AND tp.created_at < ?"; $params[] = $before; }
    $sql .= " ORDER BY tp.created_at DESC LIMIT ?";
    $params[] = $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map(function ($r) {
        $r['like_count'] = (int)$r['like_count'];
        $r['liked_by_me'] = (bool)$r['liked_by_me'];
        return $r;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getActiveStories(PDO $pdo, int $teamId, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT ts.id, ts.team_id, ts.author_user_id, ts.photo_url, ts.texto, ts.created_at, ts.expira_em,
               EXISTS(SELECT 1 FROM team_story_views WHERE story_id = ts.id AND user_id = ?) AS vista_por_mim
        FROM team_stories ts
        WHERE ts.team_id = ? AND ts.expira_em > NOW() AND ts.deleted_at IS NULL
        ORDER BY ts.created_at ASC
    ");
    $stmt->execute([$userId, $teamId]);
    return array_map(function ($r) {
        $r['vista_por_mim'] = (bool)$r['vista_por_mim'];
        return $r;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Decodifica uma foto em data-URL, valida (tamanho + MIME real) e salva em $subdir. Devolve o caminho público ou null. */
function salvarFotoFeed(string $dataUrl, string $subdir, int $teamId): ?string
{
    if (!str_starts_with($dataUrl, 'data:image/')) return null;
    $commaPos = strpos($dataUrl, ',');
    if ($commaPos === false) jsonResponse(422, ['error' => 'Imagem inválida.']);
    $base64 = substr($dataUrl, $commaPos + 1);
    $binary = base64_decode($base64, true);
    if ($binary === false) jsonResponse(422, ['error' => 'Imagem inválida.']);
    if (strlen($binary) > 5 * 1024 * 1024) jsonResponse(422, ['error' => 'Imagem maior que 5MB.']);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->buffer($binary);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$realMime])) jsonResponse(422, ['error' => 'Formato de imagem não suportado.']);
    $ext = $allowed[$realMime];

    $dirFs = __DIR__ . '/../img/' . $subdir;
    if (!is_dir($dirFs)) { @mkdir($dirFs, 0775, true); }
    $filename = $subdir . '-' . $teamId . '-' . time() . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (file_put_contents($dirFs . '/' . $filename, $binary) === false) {
        jsonResponse(500, ['error' => 'Falha ao salvar a imagem.']);
    }
    return '/img/' . $subdir . '/' . $filename;
}

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
