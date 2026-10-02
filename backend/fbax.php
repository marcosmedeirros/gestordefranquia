<?php
/**
 * ── FBAX: O TWITTER DA FBA ───────────────────────────────────────────
 *
 * O Timeline nasceu com cara de Instagram — grid de fotos, stories, foto
 * obrigatória pra aparecer direito. Não pegou, e o motivo é o formato: numa
 * liga o que as pessoas querem fazer é FALAR. Provocar depois de uma troca,
 * responder a provocação, repostar a do outro. Isso é Twitter, não Instagram.
 *
 * O FBAX é a mesma parede, com a gramática trocada: post curto, texto sozinho
 * vale, foto é opcional, e cada post pode ser respondido e repostado.
 *
 * ── UMA TABELA SÓ, TRÊS COMPORTAMENTOS ───────────────────────────────
 *
 * Não criei `comentarios` nem `reposts`. Resposta e repost SÃO posts, e é
 * assim que o Twitter funciona — dois campos em `team_posts` resolvem:
 *
 *   reply_to_id    nulo = post de raiz; preenchido = resposta àquele post
 *   repost_of_id   preenchido = repost daquele post
 *
 * E a combinação dá o quarto caso de graça:
 *
 *   repost_of_id + texto vazio   repost seco (o RT clássico)
 *   repost_of_id + texto         repost com comentário (o quote)
 *
 * O que se ganha com isso: curtida, exclusão, foto e permissão já existem e
 * passam a valer pra resposta e pro quote sem uma linha a mais. Tabela
 * separada de comentário significaria reescrever as quatro.
 *
 * ── O AUTOR É O TIME, A ASSINATURA É A PESSOA ────────────────────────
 *
 * Herdado do feed e mantido de propósito: quem posta é o GM, mas quem aparece
 * é a franquia. É o que faz a liga ler como liga, e não como grupo de amigos.
 */

require_once __DIR__ . '/team-feed-helpers.php';

/** Quantos posts por página do feed. */
const FBAX_PAGINA = 20;

/** Teto do texto. Twitter tem 280; aqui sobra espaço pra citar troca inteira. */
const FBAX_TEXTO_MAX = 600;

/**
 * A ESTREIA. O que é de antes desta data não aparece no FBAX.
 *
 * A parede é a mesma do Timeline, e o acervo que veio junto é de outro
 * jogo: 54 posts, todos com foto, nenhuma resposta, nenhum repost — a
 * gramática de Instagram que não pegou. Quem abrisse o FBAX veria aquilo
 * como se fosse a conversa da liga, e aprenderia o formato errado logo na
 * primeira tela.
 *
 * Corte próprio e não o FEED_DATA_CORTE porque são duas decisões
 * diferentes: aquele é do feed do dashboard, e mexer nele pra limpar esta
 * tela mudaria a outra de carona.
 *
 * Nada é apagado: os posts continuam no banco, e um link direto ainda abre
 * a thread. O que muda é o que a linha do tempo mostra.
 */
const FBAX_DATA_CORTE = '2026-10-02 00:00:00';

/**
 * As duas colunas novas em `team_posts`.
 *
 * ALTER guardado em função, como o resto da casa: a tabela nasce em
 * ensureTeamFeedTables() e esta é a camada do FBAX por cima dela. Idempotente —
 * roda a cada requisição e não custa nada depois da primeira.
 */
function fbaxGarantirColunas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    ensureTeamFeedTables($pdo);
    foreach ([
        'reply_to_id'  => 'ALTER TABLE team_posts ADD COLUMN reply_to_id INT NULL',
        'repost_of_id' => 'ALTER TABLE team_posts ADD COLUMN repost_of_id INT NULL',
    ] as $col => $sql) {
        try {
            if ($pdo->query("SHOW COLUMNS FROM team_posts LIKE '{$col}'")->rowCount() === 0) $pdo->exec($sql);
        } catch (Throwable $e) { error_log('[fbax] coluna ' . $col . ': ' . $e->getMessage()); }
    }
    foreach ([
        'idx_fbax_reply'  => 'ALTER TABLE team_posts ADD INDEX idx_fbax_reply (reply_to_id, created_at)',
        'idx_fbax_repost' => 'ALTER TABLE team_posts ADD INDEX idx_fbax_repost (repost_of_id)',
    ] as $idx => $sql) {
        try {
            if ($pdo->query("SHOW INDEX FROM team_posts WHERE Key_name = '{$idx}'")->rowCount() === 0) $pdo->exec($sql);
        } catch (Throwable $e) { /* índice é performance, não correção */ }
    }
}

/** O SELECT de um post, com os contadores que a tela mostra. */
function fbaxColunas(): string
{
    return "tp.id, tp.team_id, tp.author_user_id, tp.texto, tp.photo_url, tp.created_at,
            tp.reply_to_id, tp.repost_of_id,
            u.name AS author_name, u.photo_url AS author_photo,
            tm.city AS team_city, tm.name AS team_name, tm.photo_url AS team_photo, tm.league AS team_league,
            (SELECT COUNT(*) FROM team_post_likes l WHERE l.post_id = tp.id) AS curtidas,
            (SELECT COUNT(*) FROM team_posts r WHERE r.reply_to_id = tp.id AND r.deleted_at IS NULL) AS respostas,
            (SELECT COUNT(*) FROM team_posts r WHERE r.repost_of_id = tp.id AND r.deleted_at IS NULL) AS reposts,
            EXISTS(SELECT 1 FROM team_post_likes l WHERE l.post_id = tp.id AND l.user_id = ?) AS curti,
            EXISTS(SELECT 1 FROM team_posts r WHERE r.repost_of_id = tp.id AND r.team_id = ?
                   AND r.texto IS NULL AND r.deleted_at IS NULL) AS repostei";
}

/** Normaliza uma linha crua do banco pro formato que a tela consome. */
function fbaxLinha(array $r): array
{
    $r['id'] = (int)$r['id'];
    $r['team_id'] = (int)$r['team_id'];
    $r['author_user_id'] = (int)$r['author_user_id'];
    $r['reply_to_id'] = $r['reply_to_id'] !== null ? (int)$r['reply_to_id'] : null;
    $r['repost_of_id'] = $r['repost_of_id'] !== null ? (int)$r['repost_of_id'] : null;
    foreach (['curtidas', 'respostas', 'reposts'] as $k) $r[$k] = (int)($r[$k] ?? 0);
    foreach (['curti', 'repostei'] as $k) $r[$k] = (bool)($r[$k] ?? false);
    $r['team_name'] = trim(($r['team_city'] ?? '') . ' ' . ($r['team_name'] ?? ''));
    unset($r['team_city']);
    return $r;
}

/**
 * Pendura em cada repost o post original.
 *
 * Em lote, numa consulta só: o feed tem 20 posts e um N+1 aqui seria 20
 * consultas por rolagem. Original apagado vira `original_sumiu`, e a tela
 * mostra "post removido" em vez de um repost vazio sem explicação.
 */
function fbaxAnexarOriginais(PDO $pdo, array $posts, int $userId, int $myTeamId): array
{
    $ids = array_values(array_unique(array_filter(array_column($posts, 'repost_of_id'))));
    if (!$ids) return $posts;

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT " . fbaxColunas() . "
                         FROM team_posts tp
                         JOIN users u ON u.id = tp.author_user_id
                         JOIN teams tm ON tm.id = tp.team_id
                         WHERE tp.id IN ({$ph}) AND tp.deleted_at IS NULL");
    $st->execute(array_merge([$userId, $myTeamId], $ids));

    $porId = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $n = fbaxLinha($r); $porId[$n['id']] = $n; }

    foreach ($posts as &$p) {
        if (!$p['repost_of_id']) continue;
        $p['original'] = $porId[$p['repost_of_id']] ?? null;
        $p['original_sumiu'] = $p['original'] === null;
    }
    return $posts;
}

/**
 * O FEED: só posts de raiz, do mais novo pro mais velho.
 *
 * RESPOSTA NÃO SOBE PRA LINHA DO TEMPO. Ela vive dentro do post que
 * respondeu — senão uma discussão de quinze mensagens enterra todo o resto da
 * liga, que é exatamente o que afogou o Timeline antigo.
 *
 * Repost SOBE, porque é isso que ele é: trazer de volta pra frente.
 */
function fbaxFeed(PDO $pdo, int $userId, int $myTeamId, ?string $league = null,
                  ?string $before = null, int $limit = FBAX_PAGINA): array
{
    fbaxGarantirColunas($pdo);

    $sql = "SELECT " . fbaxColunas() . "
            FROM team_posts tp
            JOIN users u ON u.id = tp.author_user_id
            JOIN teams tm ON tm.id = tp.team_id
            WHERE tp.deleted_at IS NULL AND tp.reply_to_id IS NULL";
    $params = [$userId, $myTeamId];

    if ($league) { $sql .= " AND tm.league = ?"; $params[] = $league; }
    if ($before) { $sql .= " AND tp.created_at < ?"; $params[] = $before; }
    // O FBAX herda a parede, não a arqueologia. @see FBAX_DATA_CORTE
    $sql .= " AND tp.created_at >= ?"; $params[] = FBAX_DATA_CORTE;
    $sql .= " ORDER BY tp.created_at DESC LIMIT " . (int)$limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $posts = array_map('fbaxLinha', $st->fetchAll(PDO::FETCH_ASSOC));
    return fbaxAnexarOriginais($pdo, $posts, $userId, $myTeamId);
}

/** Um post só, pela chave. Null quando não existe ou foi apagado. */
function fbaxPost(PDO $pdo, int $postId, int $userId, int $myTeamId): ?array
{
    fbaxGarantirColunas($pdo);
    $st = $pdo->prepare("SELECT " . fbaxColunas() . "
                         FROM team_posts tp
                         JOIN users u ON u.id = tp.author_user_id
                         JOIN teams tm ON tm.id = tp.team_id
                         WHERE tp.id = ? AND tp.deleted_at IS NULL");
    $st->execute([$userId, $myTeamId, $postId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $um = fbaxAnexarOriginais($pdo, [fbaxLinha($r)], $userId, $myTeamId);
    return $um[0];
}

/** As respostas de um post, da mais velha pra mais nova (ordem de conversa). */
function fbaxRespostas(PDO $pdo, int $postId, int $userId, int $myTeamId): array
{
    fbaxGarantirColunas($pdo);
    $st = $pdo->prepare("SELECT " . fbaxColunas() . "
                         FROM team_posts tp
                         JOIN users u ON u.id = tp.author_user_id
                         JOIN teams tm ON tm.id = tp.team_id
                         WHERE tp.reply_to_id = ? AND tp.deleted_at IS NULL
                         ORDER BY tp.created_at ASC");
    $st->execute([$userId, $myTeamId, $postId]);
    return array_map('fbaxLinha', $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * PUBLICA: post de raiz, resposta ou quote — é o mesmo gesto.
 *
 * @return array{ok:bool, erro:string, id:int}
 */
function fbaxPublicar(PDO $pdo, int $teamId, int $userId, string $texto,
                      ?string $fotoBase64 = null, ?int $replyTo = null, ?int $repostOf = null): array
{
    fbaxGarantirColunas($pdo);
    $falha = fn(string $e) => ['ok' => false, 'erro' => $e, 'id' => 0];

    $texto = trim($texto);
    $fotoBase64 = trim((string)$fotoBase64);
    if ($texto === '' && $fotoBase64 === '' && !$repostOf) {
        return $falha('Escreva alguma coisa ou mande uma foto.');
    }
    if (mb_strlen($texto) > FBAX_TEXTO_MAX) {
        return $falha('Passou de ' . FBAX_TEXTO_MAX . ' caracteres.');
    }

    /* O ALVO TEM QUE EXISTIR. Sem isto, responder um post apagado criaria uma
       resposta órfã — ela não apareceria em lugar nenhum e o autor acharia
       que o site comeu o texto dele. */
    foreach (['reply_to_id' => $replyTo, 'repost_of_id' => $repostOf] as $campo => $alvo) {
        if ($alvo === null) continue;
        $st = $pdo->prepare('SELECT 1 FROM team_posts WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$alvo]);
        if (!$st->fetchColumn()) return $falha('Esse post não existe mais.');
    }

    /* REPOST SECO SÓ UMA VEZ POR TIME — é liga/desliga, como no Twitter. O
       quote (repost COM texto) não entra nesta trava: comentar duas vezes a
       mesma coisa é opinião, não duplicata. */
    if ($repostOf !== null && $texto === '' && $fotoBase64 === '') {
        $st = $pdo->prepare('SELECT id FROM team_posts
                              WHERE repost_of_id = ? AND team_id = ? AND texto IS NULL AND deleted_at IS NULL');
        $st->execute([$repostOf, $teamId]);
        if ($ja = (int)($st->fetchColumn() ?: 0)) {
            // Já repostou: desfaz, que é o que o botão promete quando está aceso.
            $pdo->prepare('UPDATE team_posts SET deleted_at = NOW() WHERE id = ?')->execute([$ja]);
            return ['ok' => true, 'erro' => '', 'id' => 0];
        }
    }

    $photoUrl = $fotoBase64 !== '' ? salvarFotoFeed($fotoBase64, 'team-posts', $teamId) : null;

    $st = $pdo->prepare('INSERT INTO team_posts (team_id, author_user_id, texto, photo_url, reply_to_id, repost_of_id)
                         VALUES (?,?,?,?,?,?)');
    $st->execute([$teamId, $userId, $texto !== '' ? $texto : null, $photoUrl, $replyTo, $repostOf]);
    return ['ok' => true, 'erro' => '', 'id' => (int)$pdo->lastInsertId()];
}

/**
 * APAGA o post (e o que pendurou nele).
 *
 * As respostas vão junto: deixá-las sem o post de origem daria uma conversa
 * pela metade, com gente respondendo a nada. Os reposts secos também — eles
 * não têm conteúdo próprio. O QUOTE fica: ali o texto é de quem citou, e
 * apagar seria tirar a palavra de alguém porque um terceiro se arrependeu;
 * ele passa a mostrar "post removido".
 */
function fbaxApagar(PDO $pdo, int $postId, array $user): array
{
    fbaxGarantirColunas($pdo);

    $st = $pdo->prepare('SELECT team_id, author_user_id FROM team_posts WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$postId]);
    $post = $st->fetch(PDO::FETCH_ASSOC);
    if (!$post) return ['ok' => false, 'erro' => 'Esse post não existe mais.'];

    $dono = (int)$post['author_user_id'] === (int)$user['id'];
    if (!$dono && !isTeamGmOrAdmin($pdo, $user, (int)$post['team_id'])) {
        return ['ok' => false, 'erro' => 'Você não pode apagar esse post.'];
    }

    $pdo->prepare('UPDATE team_posts SET deleted_at = NOW() WHERE id = ?')->execute([$postId]);
    $pdo->prepare('UPDATE team_posts SET deleted_at = NOW()
                    WHERE deleted_at IS NULL AND (reply_to_id = ? OR (repost_of_id = ? AND texto IS NULL))')
        ->execute([$postId, $postId]);
    return ['ok' => true, 'erro' => ''];
}

/** Curte ou descurte, pelo estado de agora. @return array{curtidas:int, curti:bool} */
function fbaxCurtir(PDO $pdo, int $postId, int $userId): array
{
    fbaxGarantirColunas($pdo);

    $st = $pdo->prepare('SELECT 1 FROM team_post_likes WHERE post_id = ? AND user_id = ?');
    $st->execute([$postId, $userId]);
    if ($st->fetchColumn()) {
        $pdo->prepare('DELETE FROM team_post_likes WHERE post_id = ? AND user_id = ?')->execute([$postId, $userId]);
        $curti = false;
    } else {
        $pdo->prepare('INSERT IGNORE INTO team_post_likes (post_id, user_id) VALUES (?,?)')->execute([$postId, $userId]);
        $curti = true;
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM team_post_likes WHERE post_id = ?');
    $st->execute([$postId]);
    return ['curtidas' => (int)$st->fetchColumn(), 'curti' => $curti];
}
