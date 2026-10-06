<?php
/**
 * ── O HISTÓRICO SEGUE O JOGADOR, NÃO A LINHA DA TABELA ───────────────
 *
 * Dispensar um jogador APAGA a linha dele em `players` — ele vira registro em
 * `free_agents` e o id some. Quando alguém o contrata de volta, nasce um
 * jogador novo, com id novo. Só que o histórico (`player_season_log`) aponta
 * pro id ANTIGO: ele fica órfão, e o jogador reaparece na liga como se tivesse
 * chegado ontem.
 *
 * Foi o que o grupo da ELITE viu em 06/10/2026 com o Zach Randolph: seis
 * temporadas jogadas e a ficha dizendo "Temporadas na liga: 0". Ele já tinha
 * colecionado QUATRO ids diferentes na mesma liga, um por passagem pela Free
 * Agency, com o histórico fatiado entre eles.
 *
 * ── SÓ ÓRFÃO É RELIGADO ──────────────────────────────────────────────
 *
 * A ligação é por nome + liga, que é o que sobra depois que o id morre. Isso
 * poderia roubar o passado de um xará — e a trava contra isso é só religar o
 * log cujo `player_id` NÃO EXISTE mais em `players`. Um homônimo vivo tem o
 * id dele de pé, o log dele não é órfão, e ninguém encosta nele.
 *
 * As quatro ligas são povoadas pelos mesmos jogadores da NBA, então o filtro
 * de liga não é detalhe: sem ele, o Zach Randolph da ROOKIE herdaria as
 * temporadas do da ELITE.
 *
 * ── E SÓ DA SPRINT ATUAL ─────────────────────────────────────────────
 *
 * A liga já rodou outras edições, e o log guarda todas. O mesmo nome aparece
 * lá atrás jogando por times que nem existem mais — "temporadas na liga" é
 * desta edição, não da história do boneco. Sem este corte, metade da ELITE
 * ganharia dez temporadas de uma vez e o LeBron apareceria com dezesseis.
 */

require_once __DIR__ . '/db.php';

/**
 * Devolve o histórico órfão deste nome a um jogador que voltou.
 *
 * Chamada depois de criar a linha em `players`, em todo caminho onde alguém
 * entra na liga vindo de fora: Free Agency, waiver, leilão, cadastro do admin.
 * Não custa nada quando não há órfão — e quando há, é a diferença entre a
 * ficha dizer "0 temporadas" e dizer a verdade.
 *
 * @return int Quantas linhas de histórico voltaram pro jogador.
 */
function historicoReligarDoJogador(PDO $pdo, int $playerId, string $nome, string $liga): int
{
    $nome = trim($nome);
    $liga = strtoupper(trim($liga));
    if ($playerId <= 0 || $nome === '' || $liga === '') return 0;

    try {
        /* AS LINHAS A RELIGAR, uma por temporada.
           `player_season_log` tem único em (player_id, season_id), e um
           jogador que foi e voltou no meio do ano tem DUAS linhas da mesma
           temporada, uma por id. Jogar as duas no mesmo id estoura a chave e
           o UPDATE inteiro falha — foi o que segurou o Zach Randolph em zero
           enquanto os outros religavam. Fica a de id maior: é a última a ser
           escrita, a que reflete como ele terminou a temporada. */
        $st = $pdo->prepare("
            SELECT MAX(l.id) AS log_id, l.season_id
              FROM player_season_log l
              JOIN seasons se ON se.id = l.season_id
              JOIN sprints sp ON sp.id = se.sprint_id AND sp.status = 'active'
             WHERE l.player_name = ? AND l.league = ? AND l.player_id <> ?
               AND sp.league = ?
               AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM players) p WHERE p.id = l.player_id)
               AND NOT EXISTS (SELECT 1 FROM (SELECT player_id, season_id FROM player_season_log) j
                                WHERE j.player_id = ? AND j.season_id = l.season_id)
          GROUP BY l.season_id");
        $st->execute([$nome, $liga, $playerId, $liga, $playerId]);
        $alvos = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'log_id');

        $n = 0;
        if ($alvos) {
            $ph = implode(',', array_fill(0, count($alvos), '?'));
            $up = $pdo->prepare("UPDATE player_season_log SET player_id = ? WHERE id IN ($ph)");
            $up->execute(array_merge([$playerId], $alvos));
            $n = $up->rowCount();
        }

        /* O CONTADOR SE REFAZ DO LOG. Mesma conta de recalcularTemporadasNaLiga
           (api/seasons.php), aplicada a um jogador só: temporada CONCLUÍDA em
           que ele aparece. Sem isto, a ficha ficaria certa só na virada
           seguinte — e quem contratou quer ver agora. */
        if ($n > 0) {
            $pdo->prepare("
                UPDATE players p
                   SET p.seasons_in_league = (
                       SELECT COUNT(DISTINCT l.season_id)
                         FROM player_season_log l
                         JOIN seasons se ON se.id = l.season_id
                        WHERE l.player_id = p.id AND se.status = 'completed')
                 WHERE p.id = ?")->execute([$playerId]);
            /* O contador segue a regra de quem o criou (recalcularTemporadasNaLiga):
               conta TODA temporada concluída do id, de qualquer sprint. Como o
               religamento só junta as da sprint ativa, o número não herda a
               edição passada. */
        }
        return $n;
    } catch (Throwable $e) {
        /* Engole de propósito: religar histórico é conserto, não parte da
           contratação. Falhar aqui não pode desfazer uma assinatura que já
           aconteceu — o jogador entra, e o passado dele espera a próxima
           passagem por aqui. */
        error_log('[historico] religar ' . $nome . ' (' . $liga . '): ' . $e->getMessage());
        return 0;
    }
}

/**
 * A liga de um time, pra quem só tem o team_id na mão.
 *
 * Existe aqui porque quase todo chamador de historicoReligarDoJogador está num
 * ponto onde a liga não está carregada — e buscá-la duas linhas antes, em cada
 * um deles, é o tipo de repetição que uma hora diverge.
 */
function historicoLigaDoTime(PDO $pdo, int $teamId): string
{
    static $cache = [];
    if ($teamId <= 0) return '';
    if (isset($cache[$teamId])) return $cache[$teamId];
    try {
        $st = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
        $st->execute([$teamId]);
        return $cache[$teamId] = strtoupper(trim((string)($st->fetchColumn() ?: '')));
    } catch (Throwable $e) {
        return $cache[$teamId] = '';
    }
}
