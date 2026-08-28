<?php
/**
 * Quem escolhe em cada vaga do draft.
 *
 * A ordem tem duas colunas que costumam ser confundidas:
 *
 *   original_team_id  de quem é a VAGA — sai da campanha, via loteria
 *   team_id           quem ESCOLHE nela — muda por troca e por swap
 *
 * Duas coisas mexem no segundo:
 *
 *   1. Troca de pick. A vaga continua sendo do time de origem ("Dono via ORI"),
 *      mas quem escolhe é o dono atual da pick.
 *
 *   2. Swap. Dois times combinam trocar de vaga entre si. Quem ficou com o
 *      lado SB ("melhor") escolhe na vaga mais alta das duas; quem ficou com o
 *      SW ("pior") escolhe na mais baixa. Quem é melhor só se sabe depois da
 *      loteria — é esse o sentido do acordo.
 *
 * Isto morava em lugar nenhum: `swap_type` e `swap_pair_pick_id` eram gravados
 * no aceite da troca, exibidos em três telas, e NUNCA lidos na hora de montar
 * a ordem. O swap era etiqueta.
 */

/** O ano que o draft de uma temporada representa. */
function draftAnoDaTemporada(PDO $pdo, int $seasonId): int
{
    $st = $pdo->prepare('SELECT s.season_number, s.year, sp.start_year
                         FROM seasons s LEFT JOIN sprints sp ON sp.id = s.sprint_id
                         WHERE s.id = ?');
    $st->execute([$seasonId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return 0;
    return (!empty($r['start_year']) && isset($r['season_number']))
        ? (int)$r['start_year'] + (int)$r['season_number'] - 1
        : (int)($r['year'] ?? 0);
}

/**
 * O draft que está rolando numa liga: a sessão e o ano que ela sorteia.
 *
 * Existe pra que ninguém mais precise refazer a conta do ano dentro de um
 * SQL. Duas telas faziam isso — a de Picks e a Trade Machine — com
 * `COALESCE(sp.start_year + s.season_number - 1, s.year)`, que NÃO é a mesma
 * regra de draftAnoDaTemporada(): o COALESCE só troca de braço quando
 * start_year é NULL, e com start_year = 0 ele devolve `season_number - 1`
 * (um "ano" 1) enquanto o PHP devolve s.year. Quando as duas contas
 * divergem, a pick não encontra a vaga e a escolha aparece sem número — a
 * tela fica dizendo "2026 · 1ª Round" no lugar de "Escolha 21".
 *
 * Com o ano vindo daqui, a subconsulta só compara dois números.
 *
 * Havendo mais de um draft aberto (o que rola agora e o da temporada
 * seguinte), vale o de MENOR ano: é o que está acontecendo.
 *
 * @return array{id:int, ano:int}|null
 */
function draftAbertoDaLiga(PDO $pdo, string $liga): ?array
{
    $liga = trim($liga);
    if ($liga === '') return null;
    try {
        $st = $pdo->prepare('SELECT id, season_id FROM draft_sessions
                              WHERE league = ? AND status IN ("setup","in_progress")');
        $st->execute([$liga]);
        $melhor = null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $ano = draftAnoDaTemporada($pdo, (int)$s['season_id']);
            if ($ano <= 0) continue;
            if ($melhor === null || $ano < $melhor['ano']) {
                $melhor = ['id' => (int)$s['id'], 'ano' => $ano];
            }
        }
        return $melhor;
    } catch (Throwable $e) {
        error_log('[draftAbertoDaLiga] ' . $e->getMessage());
        return null;
    }
}

/**
 * Reescreve quem escolhe em cada vaga: primeiro pelo dono da pick, depois
 * aplicando os swaps.
 *
 * A ordem dos dois passos importa. O swap troca as vagas entre os DONOS das
 * picks, então o dono precisa estar certo antes — senão o swap troca o time
 * errado. E é justamente o que acontecia: apply_order gravava
 * team_id = original_team_id pra todo mundo, apagando quem tinha comprado a
 * pick numa troca.
 *
 * Idempotente: rodar de novo com os mesmos dados não muda nada.
 *
 * Devolve ['donos' => n, 'swaps' => n] com quantas vagas cada passo mexeu.
 */
function draftSincronizarOrdem(PDO $pdo, int $draftSessionId): array
{
    $st = $pdo->prepare('SELECT id, season_id, league FROM draft_sessions WHERE id = ?');
    $st->execute([$draftSessionId]);
    $sessao = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sessao) return ['donos' => 0, 'swaps' => 0];

    $ano = draftAnoDaTemporada($pdo, (int)$sessao['season_id']);
    if ($ano <= 0) return ['donos' => 0, 'swaps' => 0];

    // As picks daquele ano, indexadas por [rodada][time de origem].
    $st = $pdo->prepare('SELECT id, original_team_id, team_id, round, swap_type, swap_pair_pick_id
                         FROM picks WHERE season_year = ?');
    $st->execute([$ano]);
    $porOrigem = [];
    $porId = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $porOrigem[(int)$p['round']][(int)$p['original_team_id']] = $p;
        $porId[(int)$p['id']] = $p;
    }
    if (!$porOrigem) return ['donos' => 0, 'swaps' => 0];

    $st = $pdo->prepare('SELECT id, team_id, original_team_id, pick_position, round
                         FROM draft_order WHERE draft_session_id = ? ORDER BY round, pick_position');
    $st->execute([$draftSessionId]);
    $vagas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$vagas) return ['donos' => 0, 'swaps' => 0];

    // O cálculo é todo em memória e só o que mudou vai pro banco.
    //
    // Fazer em dois passos gravados era instável: o passo do dono reescrevia
    // team_id a partir de original_team_id, o que DESFAZIA o swap, e o passo
    // seguinte refazia. O estado final até batia, mas rodar duas vezes
    // reportava mexidas que não existiram — e bastava o passo 2 falhar uma vez
    // pra o swap sumir calado.
    $desejado = [];    // id da vaga → quem escolhe
    $porSwap  = [];    // id da vaga → veio de swap?

    // ── Quem escolhe por dono da pick ────────────────────────────────────
    foreach ($vagas as $v) {
        $pick = $porOrigem[(int)$v['round']][(int)$v['original_team_id']] ?? null;
        // Vaga sem pick correspondente fica como está: não é o caso desta
        // função inventar dono.
        $desejado[(int)$v['id']] = $pick && (int)$pick['team_id'] > 0
            ? (int)$pick['team_id']
            : (int)$v['team_id'];
    }

    // ── O swap troca as vagas entre os dois donos ────────────────────────
    //
    // Só 1ª rodada tem swap (mesma regra de normalizeSwapPairs em
    // api/trades.php). Cada par é resolvido uma vez, pelo lado SB.
    $vagaDaOrigem = [];
    foreach ($vagas as $v) {
        if ((int)$v['round'] === 1) $vagaDaOrigem[(int)$v['original_team_id']] = $v;
    }

    $feitos = [];
    $pares = [];
    foreach ($porOrigem[1] ?? [] as $origemId => $pick) {
        if (strtoupper(trim((string)($pick['swap_type'] ?? ''))) !== 'SB') continue;
        $parId = (int)($pick['swap_pair_pick_id'] ?? 0);
        if (!$parId || isset($feitos[(int)$pick['id']])) continue;

        $par = $porId[$parId] ?? null;
        // O par tem que existir, ser da 1ª rodada, ser o SW e apontar de volta.
        // Meio-swap (um lado limpo, o outro pendurado) não vira troca de vaga.
        if (!$par
            || (int)$par['round'] !== 1
            || strtoupper(trim((string)($par['swap_type'] ?? ''))) !== 'SW'
            || (int)($par['swap_pair_pick_id'] ?? 0) !== (int)$pick['id']) {
            continue;
        }

        $vagaSB = $vagaDaOrigem[$origemId] ?? null;
        $vagaSW = $vagaDaOrigem[(int)$par['original_team_id']] ?? null;
        if (!$vagaSB || !$vagaSW) continue;        // time fora deste draft

        // Quem tem SB fica com a vaga de número menor.
        $melhor = ((int)$vagaSB['pick_position'] <= (int)$vagaSW['pick_position']) ? $vagaSB : $vagaSW;
        $pior   = ($melhor === $vagaSB) ? $vagaSW : $vagaSB;

        $desejado[(int)$melhor['id']] = (int)$pick['team_id'];   // dono da pick SB
        $desejado[(int)$pior['id']]   = (int)$par['team_id'];    // dono da pick SW
        $porSwap[(int)$melhor['id']] = true;
        $porSwap[(int)$pior['id']] = true;

        // Guarda o desfecho: quem é o dono do SB fica com a vaga melhor. É a
        // única hora em que dá pra saber isso — antes da loteria o swap é uma
        // aposta, e quem lê a ordem depois só vê o resultado sem a explicação.
        $pares[] = [
            'pos_melhor'  => (int)$melhor['pick_position'],
            'pos_pior'    => (int)$pior['pick_position'],
            'time_melhor' => (int)$pick['team_id'],
            'time_pior'   => (int)$par['team_id'],
            'vaga_melhor_de' => (int)$melhor['original_team_id'],
            'vaga_pior_de'   => (int)$pior['original_team_id'],
        ];

        $feitos[(int)$pick['id']] = true;
        $feitos[$parId] = true;
    }

    // ── Grava só a diferença ─────────────────────────────────────────────
    $atualizar = $pdo->prepare('UPDATE draft_order SET team_id = ?, traded_from_team_id = ? WHERE id = ?');
    $mexidasDono = 0; $mexidasSwap = 0;
    foreach ($vagas as $v) {
        $novo = $desejado[(int)$v['id']] ?? (int)$v['team_id'];
        if ($novo === (int)$v['team_id']) continue;
        $origem = (int)$v['original_team_id'];
        $atualizar->execute([$novo, $novo !== $origem ? $origem : null, (int)$v['id']]);
        if (!empty($porSwap[(int)$v['id']])) $mexidasSwap++; else $mexidasDono++;
    }

    return ['donos' => $mexidasDono, 'swaps' => $mexidasSwap, 'pares' => $pares];
}

/**
 * Nome dos times de uma lista de ids, pra montar texto de tela.
 * [id => "Cidade Nome"].
 */
function draftNomesDosTimes(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',name)) AS nome
                         FROM teams WHERE id IN ($ph)");
    $st->execute($ids);
    $mapa = [];
    foreach ($st as $r) $mapa[(int)$r['id']] = $r['nome'];
    return $mapa;
}

/**
 * CONFERÊNCIA DA ORDEM — só olha, não toca em nada.
 *
 * Diz, vaga por vaga, se quem está escolhendo é quem deveria: o dono atual
 * da pick, com o swap resolvido. É a mesma conta de draftSincronizarOrdem,
 * mas sem gravar — serve pra responder "as picks que comprei estão comigo?"
 * sem que perguntar mude alguma coisa.
 *
 * @return array{ano:int, vagas:int, divergencias:array, swaps:array, sem_pick:array, protecoes:array}
 */
function draftConferirOrdem(PDO $pdo, int $draftSessionId): array
{
    $vazio = ['ano' => 0, 'vagas' => 0, 'divergencias' => [], 'origem_repetida' => [], 'swaps' => [], 'sem_pick' => [], 'protecoes' => []];

    $st = $pdo->prepare('SELECT id, season_id, league FROM draft_sessions WHERE id = ?');
    $st->execute([$draftSessionId]);
    $sessao = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sessao) return $vazio;

    $ano = draftAnoDaTemporada($pdo, (int)$sessao['season_id']);
    if ($ano <= 0) return $vazio;

    $st = $pdo->prepare('SELECT id, original_team_id, team_id, round, swap_type, swap_pair_pick_id,
                                protection, protection_resultado
                         FROM picks WHERE season_year = ?');
    $st->execute([$ano]);
    $porOrigem = $porId = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $porOrigem[(int)$p['round']][(int)$p['original_team_id']] = $p;
        $porId[(int)$p['id']] = $p;
    }

    $st = $pdo->prepare('SELECT id, team_id, original_team_id, pick_position, round
                         FROM draft_order WHERE draft_session_id = ? ORDER BY round, pick_position');
    $st->execute([$draftSessionId]);
    $vagas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$vagas) return array_merge($vazio, ['ano' => $ano]);

    // Quem deveria escolher em cada vaga, pela mesma regra da sincronização.
    $esperado = $veioDeSwap = [];
    foreach ($vagas as $v) {
        $pick = $porOrigem[(int)$v['round']][(int)$v['original_team_id']] ?? null;
        $esperado[(int)$v['id']] = $pick && (int)$pick['team_id'] > 0
            ? (int)$pick['team_id'] : (int)$v['team_id'];
    }

    $vagaDaOrigem = [];
    foreach ($vagas as $v) if ((int)$v['round'] === 1) $vagaDaOrigem[(int)$v['original_team_id']] = $v;

    $swaps = [];
    foreach ($porOrigem[1] ?? [] as $origemId => $pick) {
        if (strtoupper(trim((string)($pick['swap_type'] ?? ''))) !== 'SB') continue;
        $par = $porId[(int)($pick['swap_pair_pick_id'] ?? 0)] ?? null;
        if (!$par || (int)$par['round'] !== 1
            || strtoupper(trim((string)($par['swap_type'] ?? ''))) !== 'SW'
            || (int)($par['swap_pair_pick_id'] ?? 0) !== (int)$pick['id']) continue;

        $vagaSB = $vagaDaOrigem[$origemId] ?? null;
        $vagaSW = $vagaDaOrigem[(int)$par['original_team_id']] ?? null;
        if (!$vagaSB || !$vagaSW) continue;

        $melhor = ((int)$vagaSB['pick_position'] <= (int)$vagaSW['pick_position']) ? $vagaSB : $vagaSW;
        $pior   = ($melhor === $vagaSB) ? $vagaSW : $vagaSB;
        $esperado[(int)$melhor['id']] = (int)$pick['team_id'];
        $esperado[(int)$pior['id']]   = (int)$par['team_id'];
        $veioDeSwap[(int)$melhor['id']] = $veioDeSwap[(int)$pior['id']] = true;

        $swaps[] = [
            'melhor_pick'  => (int)$melhor['pick_position'],
            'melhor_dono'  => (int)$pick['team_id'],
            'melhor_de'    => (int)$melhor['original_team_id'],
            'pior_pick'    => (int)$pior['pick_position'],
            'pior_dono'    => (int)$par['team_id'],
            'pior_de'      => (int)$pior['original_team_id'],
        ];
    }

    $divergencias = $semPick = $protecoes = [];
    foreach ($vagas as $v) {
        $id  = (int)$v['id'];
        $deveria = $esperado[$id] ?? (int)$v['team_id'];
        if ($deveria !== (int)$v['team_id']) {
            $divergencias[] = [
                'rodada'   => (int)$v['round'],
                'pick'     => (int)$v['pick_position'],
                'origem'   => (int)$v['original_team_id'],
                'esta_com' => (int)$v['team_id'],
                'deveria'  => $deveria,
                'swap'     => !empty($veioDeSwap[$id]),
            ];
        }
        $pick = $porOrigem[(int)$v['round']][(int)$v['original_team_id']] ?? null;
        if (!$pick) {
            $semPick[] = ['rodada' => (int)$v['round'], 'pick' => (int)$v['pick_position'], 'origem' => (int)$v['original_team_id']];
        } elseif (!empty($pick['protection']) && empty($pick['protection_resultado'])) {
            $protecoes[] = [
                'rodada'    => (int)$v['round'],
                'pick'      => (int)$v['pick_position'],
                'origem'    => (int)$v['original_team_id'],
                'dono'      => (int)$pick['team_id'],
                'protecao'  => (string)$pick['protection'],
            ];
        }
    }

    /* CADA TIME É DONO DE UMA VAGA POR RODADA — a dele.
       Esta checagem existe porque a de cima não bastaria: ela compara pelo
       time de origem gravado na vaga, e se ELE estiver errado a conta fecha
       com o dado errado. Foi o que aconteceu quando a ordem era aplicada
       com o dono já resolvido no lugar da origem: um time aparecia como
       origem de três vagas e outros dois, de nenhuma. Contar as origens
       pega isso, porque a soma não muda de lugar. */
    $origensPorRodada = [];
    foreach ($vagas as $v) {
        $origensPorRodada[(int)$v['round']][(int)$v['original_team_id']][] = (int)$v['pick_position'];
    }
    $origens = [];
    foreach ($origensPorRodada as $rodada => $porTime) {
        foreach ($porTime as $tid => $posicoes) {
            if (count($posicoes) > 1) {
                $origens[] = ['rodada' => $rodada, 'time' => $tid, 'picks' => $posicoes];
            }
        }
    }

    return [
        'ano'             => $ano,
        'vagas'           => count($vagas),
        'divergencias'    => $divergencias,
        'origem_repetida' => $origens,
        'swaps'           => $swaps,
        'sem_pick'        => $semPick,
        'protecoes'       => $protecoes,
    ];
}
