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
 * SÃO TRÊS COISAS presas ao id velho, e a ficha do bot mostra as três:
 * as temporadas disputadas (`player_season_log`), as estatísticas
 * (`player_season_stats`) e as letrinhas. As duas primeiras se religam; as
 * letrinhas são copiadas do log de volta pro jogador, porque elas moram em
 * `players` e morreram junto com a linha antiga — o log é a única cópia que
 * sobrou.
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

        /* AS ESTATÍSTICAS VÃO NO MESMO PASSO. Sem isto a ficha ficava com as
           temporadas certas e "sem estatísticas" — foi exatamente o que o grupo
           viu no Zach Randolph depois do primeiro conserto: seis temporadas
           listadas e nenhum número embaixo.

           `player_season_stats` NÃO GUARDA O NOME, só o player_id. Então quem
           identifica a linha órfã é o log: se algum log daquele id morto traz
           este nome nesta liga, aquele id foi este jogador, e as estatísticas
           dele são dele. Não dá pra casar log e stats pela temporada, porque o
           log já foi religado antes — o par (id morto, temporada) não existe
           mais no log, e foi essa a primeira versão que não achava nada.

           O NOME TEM QUE VALER NAQUELA TEMPORADA, NÃO NA VIDA DO ID. Há id
           reaproveitado por mais de um jogador: o 7466 foi Charlie Bell e
           depois Damion Baugh. Aceitar o nome em qualquer temporada daquele id
           deu, em 06/10/2026, uma temporada do Charlie Bell na ficha do Damion
           — erro pego no conserto, não na liga. Então:
             · se o log daquele id ainda tem a temporada, ele é quem decide;
             · se não tem (foi religado embora, que é o caso comum — o Zach
               Randolph não tem nenhum log sobrando nas seis temporadas que
               recuperou), exige-se que aquele id só tenha carregado ESTE nome
               na vida inteira. Id de um nome só não tem com quem confundir.
           Id de dois nomes sem log da temporada fica de fora: é melhor a ficha
           não ter o número do que ter o número de outro.

           Mais duas travas: a temporada tem que estar NO HISTÓRICO DELE, pra
           não aparecer ano que ele não jogou; e uma linha por temporada
           (MAX(id)), que é o mesmo único em (player_id, season_id) do log —
           duas da mesma temporada no mesmo id estouram a chave e derrubam o
           UPDATE inteiro. */
        $st = $pdo->prepare("
            SELECT MAX(ps.id) AS stat_id
              FROM player_season_stats ps
              JOIN seasons se ON se.id = ps.season_id
              JOIN sprints sp ON sp.id = se.sprint_id AND sp.status = 'active' AND sp.league = ?
             WHERE ps.league = ? AND ps.player_id <> ?
               AND (
                     EXISTS (SELECT 1 FROM player_season_log l
                              WHERE l.player_id = ps.player_id AND l.season_id = ps.season_id
                                AND l.player_name = ? AND l.league = ?)
                     OR (
                         NOT EXISTS (SELECT 1 FROM player_season_log l2
                                      WHERE l2.player_id = ps.player_id
                                        AND l2.season_id = ps.season_id)
                         AND EXISTS (SELECT 1 FROM player_season_log l3
                                      WHERE l3.player_id = ps.player_id
                                        AND l3.player_name = ? AND l3.league = ?)
                         AND NOT EXISTS (SELECT 1 FROM player_season_log l4
                                          WHERE l4.player_id = ps.player_id
                                            AND (l4.player_name <> ? OR l4.league <> ?))
                     )
                   )
               AND EXISTS (SELECT 1 FROM player_season_log m
                            WHERE m.player_id = ? AND m.season_id = ps.season_id)
               AND NOT EXISTS (SELECT 1 FROM players p WHERE p.id = ps.player_id)
               AND NOT EXISTS (SELECT 1 FROM player_season_stats j
                                WHERE j.player_id = ? AND j.season_id = ps.season_id)
          GROUP BY ps.season_id");
        $st->execute([$liga, $liga, $playerId,
                      $nome, $liga,            // o log da própria temporada
                      $nome, $liga,            // ou o id que só teve este nome
                      $nome, $liga,
                      $playerId, $playerId]);
        $statIds = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'stat_id');
        if ($statIds) {
            $ph = implode(',', array_fill(0, count($statIds), '?'));
            $pdo->prepare("UPDATE player_season_stats SET player_id = ? WHERE id IN ($ph)")
                ->execute(array_merge([$playerId], $statIds));
            $n += count($statIds);
        }

        /* AS LETRINHAS VOLTAM DO LOG. Elas moram em `players`, então a linha
           apagada na dispensa as levou junto — o log é a única cópia que
           sobrou. Copia-se a mais recente, e só pra jogador que está sem
           nenhuma: quem já tem notas foi avaliado depois de voltar, e o
           passado não pode escrever por cima disso. */
        historicoSkillsDoLog($pdo, $playerId, $nome, $liga);

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

/** As dez letrinhas, do jeito que as duas tabelas as chamam. */
const HISTORICO_SKILLS = ['skill_in', 'skill_mid', 'skill_3pt', 'skill_post_d', 'skill_per_d',
                          'skill_play', 'skill_reb', 'skill_athl', 'skill_iq', 'skill_pot'];

/**
 * Copia as letrinhas da temporada mais recente do log de volta pro jogador.
 *
 * SÓ PRA QUEM ESTÁ SEM NENHUMA. Jogador que voltou e já foi reavaliado tem
 * nota nova, e a nota velha não pode passar por cima — o log aqui é rede de
 * segurança, não fonte da verdade.
 *
 * @return bool Se alguma letrinha foi devolvida.
 */
function historicoSkillsDoLog(PDO $pdo, int $playerId, string $nome, string $liga): bool
{
    try {
        $cols = 'l.' . implode(', l.', HISTORICO_SKILLS);
        $vazio = implode(' IS NULL AND p.', HISTORICO_SKILLS);

        /* O JSON ENTRA NA TRAVA JUNTO COM AS COLUNAS. A ficha do bot lê a
           coluna primeiro e só cai no `player_skill_grades` se ela estiver
           vazia (@see wcSkillsDoJogador) — então escrever coluna velha num
           jogador que só tem o JSON novo faria a nota antiga passar na frente
           da atual. Hoje não existe ninguém nesse estado na liga, e a trava
           está aqui pra que continue não existindo. */
        $st = $pdo->prepare("SELECT p.id FROM players p
                              WHERE p.id = ? AND p.{$vazio} IS NULL
                                AND (p.player_skill_grades IS NULL
                                     OR p.player_skill_grades IN ('', '{}', '[]'))");
        $st->execute([$playerId]);
        if (!$st->fetchColumn()) return false;   // já tem nota: não encosta

        /* SÓ DA SPRINT ATIVA, como todo o resto daqui: nota de uma edição que
           já terminou não descreve o jogador que está em quadra agora. */
        $st = $pdo->prepare("
            SELECT {$cols} FROM player_season_log l
              JOIN seasons se ON se.id = l.season_id
              JOIN sprints sp ON sp.id = se.sprint_id AND sp.status = 'active' AND sp.league = ?
             WHERE l.player_id = ? AND l.league = ? AND l.skill_in IS NOT NULL
          ORDER BY l.season_number DESC, l.id DESC LIMIT 1");
        $st->execute([$liga, $playerId, $liga]);
        $notas = $st->fetch(PDO::FETCH_ASSOC);
        if (!$notas) return false;

        $sets = implode(' = ?, ', HISTORICO_SKILLS) . ' = ?';
        $pdo->prepare("UPDATE players SET {$sets} WHERE id = ?")
            ->execute(array_merge(array_values($notas), [$playerId]));
        return true;
    } catch (Throwable $e) {
        error_log('[historico] letrinhas ' . $nome . ': ' . $e->getMessage());
        return false;
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
