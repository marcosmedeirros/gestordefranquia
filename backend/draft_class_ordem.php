<?php
/**
 * A ORDEM DOS JOGADORES DENTRO DE UMA CLASSE DE DRAFT.
 *
 * O campo `pick_hint` sempre existiu, mas era só um palpite: a lista saía
 * ordenada por `COALESCE(pick_hint, 999999), ovr DESC`, e dois jogadores com
 * o mesmo número dividiam a posição — o desempate era o overall. Na prática
 * escolher a ordem não funcionava: quem digitava 3 num lugar já ocupado
 * podia acabar em 4º sem nada na tela explicando por quê.
 *
 * Aqui a ordem passa a ser posição de verdade. Pôr alguém no 3 empurra o 3
 * pro 4, o 4 pro 5, e assim por diante.
 *
 * ── O EMPURRÃO PARA NO PRIMEIRO BURACO ───────────────────────────────
 *
 * E isso é de propósito. Uma classe pode ter só alguns com ordem definida —
 * 1, 2, 3 e depois o 10 — porque o admin marcou os primeiros e deixou o
 * resto solto. Empurrar todo mundo de 3 pra baixo mexeria no 10, que não
 * estava no caminho de ninguém. Só desce quem está no bloco colado ao lugar
 * pedido.
 *
 * ── MOVER É DIFERENTE DE INSERIR ─────────────────────────────────────
 *
 * Quem já tem posição e muda de ideia não cria vaga nova: ele troca de lugar
 * com quem está no meio do caminho. O conjunto de números ocupados continua o
 * mesmo — por isso `draftClasseMover` desloca o intervalo em vez de abrir
 * espaço, senão cada edição furaria a lista com um buraco novo.
 *
 * @see api/admin.php  sub-ações add_player e update_player de draft_class_bank
 */

/**
 * Abre a vaga na posição pedida, empurrando pra baixo quem estiver nela.
 *
 * @param int|null $ignorarId jogador que não conta no empurrão (o que está
 *                            sendo movido, pra ele não empurrar a si mesmo)
 */
function draftClasseAbrirVaga(PDO $pdo, int $tplId, int $pos, ?int $ignorarId = null): void
{
    if ($pos < 1) return;

    $sql = "SELECT id, pick_hint FROM draft_class_template_players
             WHERE template_id = ? AND pick_hint IS NOT NULL AND pick_hint >= ?";
    $par = [$tplId, $pos];
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
       mesma posição por um instante — e se um dia esta tabela ganhar um índice
       único de (template_id, pick_hint), essa ordem é a diferença entre
       funcionar e estourar. */
    $up = $pdo->prepare("UPDATE draft_class_template_players
                            SET pick_hint = pick_hint + 1 WHERE id = ?");
    foreach (array_reverse($empurrar) as $id) $up->execute([$id]);
}

/**
 * Fecha a vaga que alguém deixou, puxando pra cima quem está logo abaixo.
 *
 * É o espelho de draftClasseAbrirVaga, e existe pela mesma razão: se entrar
 * na 3 empurra todo mundo pra baixo, sair da 3 tem que puxar de volta. Sem
 * isto a lista ia juntando buraco a cada saída — 1, 2, 4, 5 — e número
 * faltando no meio parece defeito pra quem olha, mesmo não sendo.
 *
 * PARA NO PRIMEIRO BURACO, igual ao empurrão: quem estava solto lá no 10
 * ficou solto de propósito, e não é a saída do 3 que vai arrastá-lo.
 */
function draftClasseFecharVaga(PDO $pdo, int $tplId, int $pos): void
{
    if ($pos < 1) return;

    $st = $pdo->prepare("SELECT id, pick_hint FROM draft_class_template_players
                          WHERE template_id = ? AND pick_hint IS NOT NULL AND pick_hint > ?
                       ORDER BY pick_hint ASC, id ASC");
    $st->execute([$tplId, $pos]);

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
    $up = $pdo->prepare("UPDATE draft_class_template_players
                            SET pick_hint = pick_hint - 1 WHERE id = ?");
    foreach ($puxar as $id) $up->execute([$id]);
}

/**
 * Troca um jogador de posição dentro da classe.
 *
 * Diferente de inserir: ninguém entra nem sai da lista, então o conjunto de
 * posições ocupadas tem que continuar igual. Quem está no caminho anda uma
 * casa na direção contrária, e é só.
 *
 * @param int|null $de   posição atual (null = ele não tinha ordem)
 * @param int|null $para posição pedida (null = tirar a ordem dele)
 */
function draftClasseMover(PDO $pdo, int $tplId, int $playerId, ?int $de, ?int $para): void
{
    if ($de === $para) return;

    // Tirar a ordem: ele sai da fila e quem estava embaixo sobe uma casa.
    if ($para === null) {
        $pdo->prepare("UPDATE draft_class_template_players SET pick_hint = NULL WHERE id = ?")
            ->execute([$playerId]);
        if ($de !== null) draftClasseFecharVaga($pdo, $tplId, $de);
        return;
    }

    // Não tinha ordem: é entrada nova na fila, então precisa de vaga.
    if ($de === null) {
        draftClasseAbrirVaga($pdo, $tplId, $para, $playerId);
        $pdo->prepare("UPDATE draft_class_template_players SET pick_hint = ? WHERE id = ?")
            ->execute([$para, $playerId]);
        return;
    }

    if ($de < $para) {
        // Desceu: quem estava entre o lugar velho e o novo sobe uma casa.
        $pdo->prepare("UPDATE draft_class_template_players
                          SET pick_hint = pick_hint - 1
                        WHERE template_id = ? AND id <> ?
                          AND pick_hint > ? AND pick_hint <= ?")
            ->execute([$tplId, $playerId, $de, $para]);
    } else {
        // Subiu: quem estava no intervalo desce uma casa.
        $pdo->prepare("UPDATE draft_class_template_players
                          SET pick_hint = pick_hint + 1
                        WHERE template_id = ? AND id <> ?
                          AND pick_hint >= ? AND pick_hint < ?")
            ->execute([$tplId, $playerId, $para, $de]);
    }

    $pdo->prepare("UPDATE draft_class_template_players SET pick_hint = ? WHERE id = ?")
        ->execute([$para, $playerId]);
}

/**
 * A ordem como ficou, pra tela redesenhar a lista inteira.
 *
 * Um empurrão mexe em vários jogadores de uma vez; devolver só o que foi
 * clicado deixaria os outros com o número velho na tela até o próximo F5.
 *
 * @return array<int,int|null> id do jogador => posição
 */
function draftClasseOrdemAtual(PDO $pdo, int $tplId): array
{
    $st = $pdo->prepare("SELECT id, pick_hint FROM draft_class_template_players WHERE template_id = ?");
    $st->execute([$tplId]);
    $fora = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $fora[(int)$l['id']] = $l['pick_hint'] === null ? null : (int)$l['pick_hint'];
    }
    return $fora;
}

/** O template de um jogador — o update_player só recebe o id dele. */
function draftClasseTemplateDoJogador(PDO $pdo, int $playerId): array
{
    $st = $pdo->prepare("SELECT template_id, pick_hint FROM draft_class_template_players WHERE id = ?");
    $st->execute([$playerId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    return [
        (int)($l['template_id'] ?? 0),
        ($l && $l['pick_hint'] !== null) ? (int)$l['pick_hint'] : null,
    ];
}
