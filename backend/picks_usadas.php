<?php
/**
 * PICK QUE JÁ VIROU JOGADOR não é mais moeda de troca.
 *
 * Vive fora de api/trades.php porque o draft também precisa: é ele quem gasta
 * a pick, e cancelar a proposta na hora é melhor que esperar alguém abrir a
 * tela de trocas.
 */

/**
 * As picks que JÁ FORAM GASTAS num draft: o time sentou, escolheu, e o
 * jogador está no elenco dele.
 *
 * Pick usada não é mais moeda de troca, mas continuava na tabela `picks` do
 * mesmo jeito — pick escolhida não é apagada. Então uma proposta enviada de
 * manhã com a pick 23 podia ser aceita à tarde, depois de a 23 já ter virado
 * jogador. O aceite transferia uma pick que não existe mais na prática, e o
 * time "recebia" algo que o outro nunca poderia entregar.
 *
 * O casamento é por (ano da classe, rodada, time de origem), que é o que a
 * vaga do draft e a pick têm em comum — `draft_order` guarda a vaga pela
 * origem, não pelo id da pick. O ano da classe vem de draftAnoDasPicks(),
 * a mesma conta que monta a ordem.
 *
 * @return array<int,bool> id da pick => true
 */
function picksJaUsadas(PDO $pdo, bool $recarregar = false): array
{
    static $cache = null;
    if ($cache !== null && !$recarregar) return $cache;
    $cache = [];

    try {
        require_once __DIR__ . '/draft_swaps.php';

        // Só sessões que chegaram a rodar: em setup ninguém escolheu nada.
        $sessoes = $pdo->query("SELECT id, season_id FROM draft_sessions
                                 WHERE status IN ('in_progress','completed')")->fetchAll(PDO::FETCH_ASSOC);
        if (!$sessoes) return $cache;

        $stVagas = $pdo->prepare('SELECT DISTINCT original_team_id, round
                                    FROM draft_order
                                   WHERE draft_session_id = ? AND picked_player_id IS NOT NULL');
        $stPick = $pdo->prepare('SELECT id FROM picks
                                  WHERE CAST(season_year AS UNSIGNED) = ? AND round = ? AND original_team_id = ?');

        foreach ($sessoes as $s) {
            $ano = draftAnoDasPicks($pdo, (int)$s['season_id']);
            if ($ano <= 0) continue;
            $stVagas->execute([(int)$s['id']]);
            foreach ($stVagas->fetchAll(PDO::FETCH_ASSOC) as $v) {
                $stPick->execute([$ano, (int)$v['round'], (int)$v['original_team_id']]);
                foreach ($stPick->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    $cache[(int)$pid] = true;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[trades] picksJaUsadas: ' . $e->getMessage());
    }
    return $cache;
}

/**
 * Cancela as propostas pendentes que envolvem pick já escolhida.
 *
 * Irmã de cancelarTrocasImpossiveis(), que faz o mesmo pelos jogadores que
 * mudaram de time. Aqui não dá pra resolver em um UPDATE só: a pick não sabe
 * que foi usada — quem sabe é a vaga do draft, e o ano da classe sai de uma
 * função PHP.
 *
 * @return int quantas foram canceladas
 */
function cancelarTrocasComPickUsada(PDO $pdo, ?int $tradeIdIgnorar = null, bool $recarregar = false): int
{
    // O draft chama isto logo depois de gravar a escolha, e um autopick em
    // cadeia faz várias no mesmo request: sem recarregar, a segunda pick usada
    // não estaria no cache montado pela primeira.
    $usadas = picksJaUsadas($pdo, $recarregar);
    if (!$usadas) return 0;

    try {
        $ids = implode(',', array_map('intval', array_keys($usadas)));
        $sql = "UPDATE trades t
                  JOIN trade_items ti ON ti.trade_id = t.id AND ti.pick_id IN ($ids)
                   SET t.status = 'cancelled'
                 WHERE t.status = 'pending'";
        $params = [];
        if ($tradeIdIgnorar) { $sql .= ' AND t.id <> ?'; $params[] = $tradeIdIgnorar; }

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $n = $st->rowCount();
        if ($n > 0) error_log("[trades] {$n} troca(s) cancelada(s): pick já escolhida no draft");
        return $n;
    } catch (Throwable $e) {
        error_log('[trades] cancelar com pick usada: ' . $e->getMessage());
        return 0;
    }
}

/** As picks desta troca que já foram gastas, com o nome de cada uma. */
function picksUsadasDaTroca(PDO $pdo, int $tradeId): array
{
    $usadas = picksJaUsadas($pdo);
    if (!$usadas) return [];
    try {
        $st = $pdo->prepare("SELECT p.id, p.season_year, p.round,
                                    TRIM(CONCAT(COALESCE(t.city,''),' ',t.name)) AS origem
                               FROM trade_items ti
                               JOIN picks p ON p.id = ti.pick_id
                          LEFT JOIN teams t ON t.id = p.original_team_id
                              WHERE ti.trade_id = ? AND ti.pick_id IS NOT NULL");
        $st->execute([$tradeId]);
        $fora = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (empty($usadas[(int)$p['id']])) continue;
            $fora[] = $p['round'] . 'ª rodada ' . $p['season_year']
                    . ($p['origem'] ? ' (' . trim($p['origem']) . ')' : '');
        }
        return $fora;
    } catch (Throwable $e) {
        error_log('[trades] picksUsadasDaTroca: ' . $e->getMessage());
        return [];
    }
}
