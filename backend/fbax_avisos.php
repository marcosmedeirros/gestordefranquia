<?php
/**
 * ── FBAX: MARCAR UM TIME E SER AVISADO ───────────────────────────────
 *
 * Duas coisas que andam juntas e por isso moram no mesmo arquivo: a menção
 * (@voidmakers no meio do texto) existe pra gerar o aviso, e o aviso sem a
 * menção cobriria só metade da conversa.
 *
 * O problema que elas resolvem é o mesmo: no FBAX de ontem você respondia o
 * post de alguém e esse alguém nunca ficava sabendo. A conversa morria na
 * primeira troca, porque o outro lado só descobriria voltando na página por
 * conta própria e rolando até achar. Provocação sem destinatário não provoca.
 *
 * ── O APELIDO DO TIME É O NOME DELE ──────────────────────────────────
 *
 * Nada de cadastrar handle: @ + o nome da franquia sem espaço nem acento.
 * "Alley Dogs" vira @alleydogs, "Pererês" vira @pereres, "Dobby's" vira
 * @dobbys. Os 122 times da FBA têm nome único, e continuam únicos depois de
 * normalizados — conferido antes de escolher esta régua. Um handle separado
 * seria mais um campo pra alguém preencher e mais um jeito de errar o alvo.
 *
 * ── O AVISO É DE QUEM RECEBE, NÃO DO POST ────────────────────────────
 *
 * A linha de `fbax_avisos` é sempre "fulano tem algo pra ver", e não "este
 * post gerou barulho". Por isso a chave é o user_id do destinatário: marcar
 * como lido é dele, e o mesmo post pode virar três avisos pra três pessoas.
 */

/* NÃO PUXA O backend/fbax.php. Este arquivo roda no menu lateral, ou seja,
   em toda página do site, e tudo que ele precisa são as duas tabelas do
   banco — nenhuma função de lá. Quem precisa dos dois (a API) carrega os
   dois. */

/** Quantos avisos a tela mostra de uma vez. */
const FBAX_AVISOS_PAGINA = 30;

/**
 * A tabela de avisos. Idempotente, no padrão da casa.
 *
 * `vistos` guarda o lido/não lido por pessoa; o post é referência, e some
 * junto com ele (CASCADE) porque aviso de post apagado não leva a lugar
 * nenhum.
 */
function fbaxAvisosGarantirTabela(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fbax_avisos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            post_id INT NOT NULL,
            de_team_id INT NULL,
            tipo ENUM('resposta','mencao','citacao') NOT NULL,
            lido TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fbax_avisos_dono (user_id, lido),
            INDEX idx_fbax_avisos_data (user_id, created_at),
            UNIQUE KEY uq_fbax_aviso (user_id, post_id, tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[fbax/avisos] tabela: ' . $e->getMessage());
    }
}

/**
 * O apelido de um time: minúsculo, sem acento, sem espaço, sem pontuação.
 *
 * Mesma régua de normalizeFaPlayerName, menos o espaço — aqui ele some, e
 * não vira separador, porque o apelido tem que ser uma palavra só pra caber
 * depois do @ sem ambiguidade de onde termina.
 */
function fbaxSlug(string $nome): string
{
    $s = mb_strtolower(trim($nome), 'UTF-8');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    if ($t !== false) $s = $t;
    return preg_replace('/[^a-z0-9]/', '', $s);
}

/**
 * Todos os times com seu apelido, pra tela completar e pra render marcar.
 *
 * Os 122 cabem numa consulta só e a tela guarda a lista — completar o @ tem
 * que responder na tecla, e uma ida ao servidor por caractere digitado seria
 * lenta justamente onde a pessoa está no meio de uma frase.
 */
function fbaxTimesParaMencao(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    try {
        $st = $pdo->query("SELECT id, city, name, league, photo_url, user_id FROM teams ORDER BY name");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $slug = fbaxSlug((string)$t['name']);
            if ($slug === '') continue;
            $cache[] = [
                'id'      => (int)$t['id'],
                'slug'    => $slug,
                'nome'    => trim(($t['city'] ?? '') . ' ' . ($t['name'] ?? '')),
                'curto'   => (string)$t['name'],
                'liga'    => (string)$t['league'],
                'logo'    => $t['photo_url'] ?: null,
                'user_id' => (int)($t['user_id'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        error_log('[fbax/avisos] times: ' . $e->getMessage());
    }
    return $cache;
}

/**
 * Os times marcados num texto.
 *
 * @ seguido de letras e números, e nada mais: @voidmakers. Um @ solto, um
 * e-mail colado ou um @ que não casa com time nenhum simplesmente não vira
 * menção — não dá erro nem avisa ninguém, só continua texto.
 *
 * @return array<int, array> Times, indexados por id (sem repetição: marcar
 *                           duas vezes no mesmo post é um aviso só).
 */
function fbaxMencoesNoTexto(PDO $pdo, string $texto): array
{
    if (strpos($texto, '@') === false) return [];
    if (!preg_match_all('/@([A-Za-z0-9]{2,40})/u', $texto, $m)) return [];

    $porSlug = [];
    /* NOME REPETIDO: O PRIMEIRO FICA COM O @. Os 122 times da FBA têm nome
       único hoje, mas nada impede alguém rebatizar o seu com o do vizinho —
       e aí o apelido tem que apontar sempre pro mesmo lugar, e não pro que
       calhou de vir por último na consulta. */
    foreach (fbaxTimesParaMencao($pdo) as $t) $porSlug[$t['slug']] ??= $t;

    $achados = [];
    foreach ($m[1] as $bruto) {
        $slug = fbaxSlug($bruto);
        if (isset($porSlug[$slug])) $achados[$porSlug[$slug]['id']] = $porSlug[$slug];
    }
    return $achados;
}

/**
 * Grava um aviso. Repetido é ignorado pela chave única, não por consulta.
 *
 * Pra si mesmo nunca: responder o próprio post ou marcar o próprio time é
 * coisa que acontece o tempo todo, e um sino aceso pelo que você mesmo
 * escreveu treina a pessoa a ignorar o sino.
 */
function fbaxAvisar(PDO $pdo, int $paraUserId, int $postId, string $tipo, int $deTeamId = 0, int $autorUserId = 0): void
{
    if ($paraUserId <= 0 || $postId <= 0) return;
    if ($paraUserId === $autorUserId) return;

    fbaxAvisosGarantirTabela($pdo);
    try {
        $pdo->prepare('INSERT IGNORE INTO fbax_avisos (user_id, post_id, de_team_id, tipo) VALUES (?,?,?,?)')
            ->execute([$paraUserId, $postId, $deTeamId ?: null, $tipo]);
    } catch (Throwable $e) {
        error_log('[fbax/avisos] gravar: ' . $e->getMessage());
    }
}

/**
 * TUDO QUE UM POST NOVO TEM A AVISAR.
 *
 * Chamada depois que o post já existe, e de propósito fora de qualquer
 * transação: avisar é consequência, e falhar aqui não pode derrubar a
 * publicação, que é o que a pessoa pediu.
 *
 * - resposta → avisa quem escreveu o post respondido
 * - citação  → avisa quem escreveu o post citado (o quote; repost seco não
 *              tem texto novo e viraria sino por um clique)
 * - menção   → avisa o GM de cada time marcado no texto
 *
 * UM POST, UM AVISO POR PESSOA. Responder alguém e marcar o time dele no
 * mesmo texto é comum ("Pode vir, @testers"), e sem este cuidado o cara
 * recebia dois sinos pela mesma frase. Vale o primeiro, que é o mais
 * específico: foi uma resposta, e a marcação veio junto.
 */
function fbaxAvisarDoPost(PDO $pdo, int $postId, int $autorUserId, int $teamId,
                          string $texto, ?int $replyTo, ?int $repostOf): void
{
    fbaxAvisosGarantirTabela($pdo);

    try {
        $avisados = [];
        $alvo = $replyTo ?: ($texto !== '' ? $repostOf : null);
        if ($alvo) {
            $st = $pdo->prepare('SELECT author_user_id FROM team_posts WHERE id = ? AND deleted_at IS NULL');
            $st->execute([$alvo]);
            $dono = (int)($st->fetchColumn() ?: 0);
            fbaxAvisar($pdo, $dono, $postId, $replyTo ? 'resposta' : 'citacao', $teamId, $autorUserId);
            $avisados[$dono] = true;
        }

        foreach (fbaxMencoesNoTexto($pdo, $texto) as $time) {
            $dela = (int)$time['user_id'];
            if (isset($avisados[$dela])) continue;   // já soube pela resposta
            fbaxAvisar($pdo, $dela, $postId, 'mencao', $teamId, $autorUserId);
            $avisados[$dela] = true;
        }
    } catch (Throwable $e) {
        error_log('[fbax/avisos] do post: ' . $e->getMessage());
    }
}

/**
 * Quantos avisos novos a pessoa tem.
 *
 * Roda no menu lateral, ou seja, em toda página do site — por isso é uma
 * contagem seca num índice e engole qualquer erro devolvendo zero: menu que
 * quebra por causa de um badge é pior que menu sem badge.
 */
function fbaxAvisosNaoLidos(PDO $pdo, int $userId): int
{
    if ($userId <= 0) return 0;
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM fbax_avisos WHERE user_id = ? AND lido = 0');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;   // tabela ainda não existe, ou o banco tossiu: badge some
    }
}

/**
 * A lista de avisos, do mais novo pro mais velho, com o post junto.
 *
 * Aviso de post apagado não entra: o CASCADE não existe (a tabela de posts
 * usa apagado lógico), então o filtro é aqui.
 */
function fbaxAvisosLista(PDO $pdo, int $userId, int $limit = FBAX_AVISOS_PAGINA): array
{
    fbaxAvisosGarantirTabela($pdo);
    if ($userId <= 0) return [];

    try {
        $st = $pdo->prepare("SELECT a.id, a.post_id, a.tipo, a.lido, a.created_at,
                                    tp.texto, tp.reply_to_id, tp.repost_of_id,
                                    TRIM(CONCAT(COALESCE(tm.city,''),' ',COALESCE(tm.name,''))) AS de_time,
                                    tm.photo_url AS de_logo, tm.league AS de_liga
                               FROM fbax_avisos a
                               JOIN team_posts tp ON tp.id = a.post_id AND tp.deleted_at IS NULL
                          LEFT JOIN teams tm ON tm.id = a.de_team_id
                              WHERE a.user_id = ?
                              ORDER BY a.created_at DESC
                              LIMIT " . (int)$limit);
        $st->execute([$userId]);
        return array_map(function (array $a): array {
            $a['id'] = (int)$a['id'];
            $a['post_id'] = (int)$a['post_id'];
            $a['lido'] = (bool)$a['lido'];
            /* A THREAD ABRE NA RAIZ, não no post que gerou o aviso: resposta
               solta fora da conversa não diz a quem respondeu o quê. */
            $a['abrir_id'] = (int)($a['reply_to_id'] ?: $a['post_id']);
            unset($a['reply_to_id'], $a['repost_of_id']);
            return $a;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        error_log('[fbax/avisos] lista: ' . $e->getMessage());
        return [];
    }
}

/** Marca como lido: um aviso, ou todos quando $avisoId é zero. */
function fbaxAvisosMarcarLidos(PDO $pdo, int $userId, int $avisoId = 0): void
{
    fbaxAvisosGarantirTabela($pdo);
    if ($userId <= 0) return;
    try {
        if ($avisoId > 0) {
            $pdo->prepare('UPDATE fbax_avisos SET lido = 1 WHERE user_id = ? AND id = ?')
                ->execute([$userId, $avisoId]);
        } else {
            $pdo->prepare('UPDATE fbax_avisos SET lido = 1 WHERE user_id = ? AND lido = 0')
                ->execute([$userId]);
        }
    } catch (Throwable $e) {
        error_log('[fbax/avisos] marcar: ' . $e->getMessage());
    }
}
