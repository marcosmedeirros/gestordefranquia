<?php
/**
 * A ORDEM DOS JOGADORES NUMA LISTA DE DRAFT.
 *
 * Vale pros dois lugares que têm ordem, e é a mesma regra nos dois:
 *
 *   · o POOL DO DRAFT (`draft_pool`, agrupado por `season_id`) — o Admin ›
 *     Draft › Adicionar Jogador, que é onde a ordem realmente importa: ela é
 *     a fila que o board mostra e o autopick segue;
 *   · o BANCO DE CLASSES (`draft_class_template_players`, por `template_id`)
 *     — o molde que vira pool depois.
 *
 * Duas cópias da regra dariam dois comportamentos pra mesma coisa, e a
 * segunda só seria descoberta no dia em que alguém reclamasse de uma delas.
 *
 * ── O QUE A ORDEM ERA, E O QUE PASSOU A SER ──────────────────────────
 *
 * O campo `pick_hint` existe há tempo, mas era só um palpite: as listas saem
 * ordenadas por `COALESCE(pick_hint, 999999), ovr DESC`, e dois jogadores com
 * o mesmo número dividiam a posição — o desempate era o overall. Escolher a
 * ordem não funcionava: quem digitava 1 num lugar ocupado podia acabar em 2º
 * sem nada na tela explicando.
 *
 * Aqui virou posição de verdade. Pôr alguém no 1 empurra o 1 pro 2, o 2 pro 3.
 *
 * ── O EMPURRÃO PARA NO PRIMEIRO BURACO ───────────────────────────────
 *
 * E isso é de propósito. Uma lista pode ter só alguns com ordem definida — 1,
 * 2, 3 e depois o 10 — porque o admin marcou os primeiros e deixou o resto
 * solto. Empurrar todo mundo de 3 pra baixo mexeria no 10, que não estava no
 * caminho de ninguém. Só anda quem está no bloco colado ao lugar pedido.
 *
 * ── MOVER É DIFERENTE DE INSERIR ─────────────────────────────────────
 *
 * Quem já tem posição e muda de ideia não cria vaga nova: troca de lugar com
 * quem está no meio do caminho. O conjunto de números ocupados continua o
 * mesmo — por isso `draftOrdemMover` desloca o intervalo em vez de abrir
 * espaço, senão cada edição furaria a lista com um buraco novo.
 *
 * @see api/draft.php   add_draft_player (o pool)
 * @see api/admin.php   draft_class_bank (o banco de classes)
 */

/**
 * As duas listas que têm ordem, e por qual coluna cada uma se agrupa.
 *
 * A tabela NUNCA vem de fora: ela é escolhida por esta chave, e é o que
 * garante que nenhum nome de tabela chegue da requisição até o SQL.
 */
const DRAFT_ORDEM_LISTAS = [
    'pool'   => ['tabela' => 'draft_pool',                   'escopo' => 'season_id'],
    'classe' => ['tabela' => 'draft_class_template_players', 'escopo' => 'template_id'],
];

/** Resolve a lista, ou explode — chave errada é erro de programação, não de dado. */
function draftOrdemLista(string $qual): array
{
    if (!isset(DRAFT_ORDEM_LISTAS[$qual])) {
        throw new InvalidArgumentException('Lista de ordem desconhecida: ' . $qual);
    }
    return DRAFT_ORDEM_LISTAS[$qual];
}

/**
 * Abre a vaga na posição pedida, empurrando pra baixo quem estiver nela.
 *
 * @param string   $qual      'pool' ou 'classe'
 * @param int      $escopoId  season_id ou template_id
 * @param int|null $ignorarId jogador que não conta no empurrão (o que está
 *                            sendo movido, pra ele não empurrar a si mesmo)
 */
function draftOrdemAbrirVaga(PDO $pdo, string $qual, int $escopoId, int $pos, ?int $ignorarId = null): void
{
    if ($pos < 1 || $escopoId < 1) return;
    ['tabela' => $t, 'escopo' => $e] = draftOrdemLista($qual);

    $sql = "SELECT id, pick_hint FROM {$t}
             WHERE {$e} = ? AND pick_hint IS NOT NULL AND pick_hint >= ?";
    $par = [$escopoId, $pos];
    if ($ignorarId) { $sql .= " AND id <> ?"; $par[] = $ignorarId; }
    $sql .= " ORDER BY pick_hint ASC, id ASC";

    $st = $pdo->prepare($sql);
    $st->execute($par);

    /* Caminha do lugar pedido pra baixo enquanto as posições vierem coladas.
       O primeiro buraco encerra: dali pra frente ninguém foi atropelado. */
    $empurrar = [];
    $esperado = $pos;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        if ((int)$linha['pick_hint'] > $esperado) break;
        $empurrar[] = (int)$linha['id'];
        $esperado++;
    }
    if (!$empurrar) return;

    /* DE BAIXO PRA CIMA. Subindo, o primeiro UPDATE poria dois jogadores na
       mesma posição por um instante — e se um dia estas tabelas ganharem um
       índice único de (escopo, pick_hint), essa ordem é a diferença entre
       funcionar e estourar. */
    $up = $pdo->prepare("UPDATE {$t} SET pick_hint = pick_hint + 1 WHERE id = ?");
    foreach (array_reverse($empurrar) as $id) $up->execute([$id]);
}

/**
 * Fecha a vaga que alguém deixou, puxando pra cima quem está logo abaixo.
 *
 * É o espelho de draftOrdemAbrirVaga, e existe pela mesma razão: se entrar na
 * 3 empurra todo mundo pra baixo, sair da 3 tem que puxar de volta. Sem isto
 * a lista ia juntando buraco a cada saída — 1, 2, 4, 5 — e número faltando no
 * meio parece defeito pra quem olha, mesmo não sendo.
 *
 * PARA NO PRIMEIRO BURACO, igual ao empurrão: quem estava solto lá no 10
 * ficou solto de propósito, e não é a saída do 3 que vai arrastá-lo.
 */
function draftOrdemFecharVaga(PDO $pdo, string $qual, int $escopoId, int $pos): void
{
    if ($pos < 1 || $escopoId < 1) return;
    ['tabela' => $t, 'escopo' => $e] = draftOrdemLista($qual);

    $st = $pdo->prepare("SELECT id, pick_hint FROM {$t}
                          WHERE {$e} = ? AND pick_hint IS NOT NULL AND pick_hint > ?
                       ORDER BY pick_hint ASC, id ASC");
    $st->execute([$escopoId, $pos]);

    $puxar = [];
    $esperado = $pos + 1;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        if ((int)$linha['pick_hint'] > $esperado) break;
        $puxar[] = (int)$linha['id'];
        $esperado++;
    }
    if (!$puxar) return;

    /* DE CIMA PRA BAIXO aqui — o contrário do empurrão. Subindo, o primeiro a
       andar ocupa a vaga que acabou de vagar; descendo, ele esbarraria em quem
       ainda não saiu do lugar. */
    $up = $pdo->prepare("UPDATE {$t} SET pick_hint = pick_hint - 1 WHERE id = ?");
    foreach ($puxar as $id) $up->execute([$id]);
}

/**
 * Troca um jogador de posição dentro da lista.
 *
 * Diferente de inserir: ninguém entra nem sai, então o conjunto de posições
 * ocupadas tem que continuar igual. Quem está no caminho anda uma casa na
 * direção contrária, e é só.
 *
 * @param int|null $de   posição atual (null = ele não tinha ordem)
 * @param int|null $para posição pedida (null = tirar a ordem dele)
 */
function draftOrdemMover(PDO $pdo, string $qual, int $escopoId, int $playerId, ?int $de, ?int $para): void
{
    if ($de === $para) return;
    ['tabela' => $t, 'escopo' => $e] = draftOrdemLista($qual);

    // Tirar a ordem: ele sai da fila e quem estava embaixo sobe uma casa.
    if ($para === null) {
        $pdo->prepare("UPDATE {$t} SET pick_hint = NULL WHERE id = ?")->execute([$playerId]);
        if ($de !== null) draftOrdemFecharVaga($pdo, $qual, $escopoId, $de);
        return;
    }

    // Não tinha ordem: é entrada nova na fila, então precisa de vaga.
    if ($de === null) {
        draftOrdemAbrirVaga($pdo, $qual, $escopoId, $para, $playerId);
        $pdo->prepare("UPDATE {$t} SET pick_hint = ? WHERE id = ?")->execute([$para, $playerId]);
        return;
    }

    if ($de < $para) {
        // Desceu: quem estava entre o lugar velho e o novo sobe uma casa.
        $pdo->prepare("UPDATE {$t} SET pick_hint = pick_hint - 1
                        WHERE {$e} = ? AND id <> ? AND pick_hint > ? AND pick_hint <= ?")
            ->execute([$escopoId, $playerId, $de, $para]);
    } else {
        // Subiu: quem estava no intervalo desce uma casa.
        $pdo->prepare("UPDATE {$t} SET pick_hint = pick_hint + 1
                        WHERE {$e} = ? AND id <> ? AND pick_hint >= ? AND pick_hint < ?")
            ->execute([$escopoId, $playerId, $para, $de]);
    }

    $pdo->prepare("UPDATE {$t} SET pick_hint = ? WHERE id = ?")->execute([$para, $playerId]);
}

/**
 * A ordem como ficou, pra tela redesenhar a lista inteira.
 *
 * Um empurrão mexe em vários jogadores de uma vez; devolver só o que foi
 * clicado deixaria os outros com o número velho na tela até o próximo F5.
 *
 * @return array<int,int|null> id do jogador => posição
 */
function draftOrdemAtual(PDO $pdo, string $qual, int $escopoId): array
{
    ['tabela' => $t, 'escopo' => $e] = draftOrdemLista($qual);
    $st = $pdo->prepare("SELECT id, pick_hint FROM {$t} WHERE {$e} = ?");
    $st->execute([$escopoId]);
    $fora = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $fora[(int)$l['id']] = $l['pick_hint'] === null ? null : (int)$l['pick_hint'];
    }
    return $fora;
}

/** O escopo e a posição de um jogador — quem edita só manda o id dele. */
function draftOrdemDoJogador(PDO $pdo, string $qual, int $playerId): array
{
    ['tabela' => $t, 'escopo' => $e] = draftOrdemLista($qual);
    $st = $pdo->prepare("SELECT {$e} AS escopo, pick_hint FROM {$t} WHERE id = ?");
    $st->execute([$playerId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    return [
        (int)($l['escopo'] ?? 0),
        ($l && $l['pick_hint'] !== null) ? (int)$l['pick_hint'] : null,
    ];
}
