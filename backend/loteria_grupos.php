<?php
/**
 * OS GRUPOS DA LOTERIA DO DRAFT, num lugar só.
 *
 * Quem fica de fora do playoff entra na loteria dividido em quatro grupos,
 * e cada grupo tem um número de bolinhas. É daqui que saem as chances de
 * Top 3 e Top 5 que a tela mostra e que o bot responde.
 *
 * Vive fora do api/draft.php porque agora tem dois leitores: a tela da
 * loteria e o /loteria do WhatsApp. Com a regra escrita nos dois, bastaria
 * um ajuste num deles pra liga ver uma chance no site e outra no grupo — e
 * a pergunta "então qual é a minha chance?" não teria resposta.
 */

/** Quantos de cada conferência entram no playoff. O resto vai pra loteria. */
const LOTERIA_PLAYOFF_POR_CONF = 8;

/**
 * Os quatro grupos e quantas bolinhas cada um leva.
 *
 * As chances NÃO moram aqui. Elas eram números fixos ao lado de cada grupo —
 * 16%, 24%, 16%, 8% —, corretos enquanto os grupos tivessem exatamente 3, 7,
 * 4 e 2 times. Quando o play-in termina de um jeito que não cabe nesse molde,
 * os tamanhos mudam, o total de bolinhas muda junto e os números fixos passam
 * a descrever um sorteio que não é o que vai acontecer. Agora saem de
 * loteriaOdds(), calculadas em cima da urna que existe de fato.
 */
const LOTERIA_GRUPOS_META = [
    1 => ['label' => '3 piores recordes',              'balls' => 2],
    2 => ['label' => 'Melhores fora do play-in',       'balls' => 3],
    3 => ['label' => 'Eliminados no play-in',          'balls' => 2],
    4 => ['label' => 'Derrotados no 7x8',              'balls' => 1],
];

/**
 * QUEM VAI PRA QUAL GRUPO.
 *
 * Dois grupos são fato de jogo e dois são consequência da campanha, e essa é
 * a distinção que faltava:
 *
 *  · G3 (caiu no play-in) e G4 (perdeu o 7x8) dependem de quem ganhou qual
 *    jogo. Nenhuma ordenação da tabela revela isso — o 12º pode não ter ido
 *    ao play-in e o 9º pode ter ido. Por isso são DECLARADOS pelo admin.
 *
 *  · G1 (3 piores) e G2 (o miolo) saem sozinhos da ordem da campanha.
 *
 * O G4 é o que mudou de verdade. Ele era "os 2 menos ruins que sobraram",
 * o que dava ao grupo o tamanho certo e as pessoas erradas: dois times que
 * sequer jogaram o play-in levavam o rótulo de derrotados nele — e a menor
 * chance da urna. Agora ninguém entra no G4 sem ser marcado. Um grupo vazio
 * é a resposta honesta quando ninguém declarou quem perdeu aquele jogo.
 *
 * O G3 ainda tem palpite: 9º e 10º da conferência costumam ser mesmo quem
 * caiu no play-in, e o palpite vale só pra quem o admin não marcou. Uma
 * marcação nunca desliga a dedução dos outros — marcar um time e ver os
 * outros quatro mudarem de grupo junto seria pior que não ter marcação.
 *
 * @param array $declarado [team_id => 1..4] o que o admin marcou
 * @return array [team_id => grupo]
 */
function loteriaDistribuirGrupos(array $ids, array $posicoes, array $declarado, callable $piorPrimeiro): array
{
    $grupoDe = [];
    foreach ($declarado as $tid => $g) {
        $g = (int)$g;
        if ($g >= 1 && $g <= 4) $grupoDe[(int)$tid] = $g;
    }

    foreach ($ids as $t) {
        if (isset($grupoDe[$t])) continue;
        $p = $posicoes[$t] ?? 0;
        if ($p === 9 || $p === 10) $grupoDe[$t] = 3;
    }

    $resto = array_values(array_filter($ids, fn($t) => !isset($grupoDe[$t])));
    usort($resto, $piorPrimeiro);
    foreach ($resto as $i => $t) $grupoDe[$t] = $i < 3 ? 1 : 2;

    return $grupoDe;
}

/**
 * As colunas que a loteria usa nasceram depois da tabela, e
 * CREATE TABLE IF NOT EXISTS não mexe em tabela que já existe — bancos
 * antigos chegam sem elas e o SELECT quebra. O erro de "já existe" é o
 * caso normal, não uma falha.
 */
function loteriaGarantirColunas(PDO $pdo): void
{
    foreach ([
        'overall_position    INT NULL',   // colocação geral da campanha (17 em diante)
        'draft_tail_position INT NULL',   // ordem de escolha entre os classificados
        'lottery_group       INT NULL',   // grupo declarado: 1..4
    ] as $coluna) {
        try { $pdo->exec('ALTER TABLE season_standings ADD COLUMN ' . $coluna); } catch (Throwable $e) { /* já existe */ }
    }
}

/**
 * A SPRINT QUE ESTÁ RODANDO NA LIGA.
 *
 * A liga é reiniciada a cada sprint, e as temporadas anteriores continuam no
 * banco com a numeração que tinham. Sem este filtro, uma temporada de sprint
 * passada — e a sessão de draft dela — reaparece disputando com a atual, e a
 * tela mostra a loteria de uma liga que não existe mais.
 *
 * Com mais de uma sprint marcada como ativa, vale a de número maior: é a que
 * começou depois.
 *
 * @return int|null id da sprint, ou null quando a liga não tem nenhuma
 */
function loteriaSprintAtiva(PDO $pdo, string $liga): ?int
{
    $st = $pdo->prepare("SELECT id FROM sprints
                          WHERE league = ? AND status = 'active'
                       ORDER BY sprint_number DESC, id DESC LIMIT 1");
    $st->execute([$liga]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * A LOTERIA DE UMA LIGA EM TEXTO, pro grupo do WhatsApp.
 *
 * Mostra a ordem já confirmada quando ela existe — depois do sorteio é isso
 * que a liga quer ver. Antes, mostra quem entra e com que chance: uma linha
 * por time, porque quem pergunta quer achar o próprio nome e ver um número.
 */
function loteriaTexto(PDO $pdo, string $liga): string
{
    loteriaGarantirColunas($pdo);
    $liga = strtoupper(trim($liga));
    if (!in_array($liga, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
        return 'Liga não reconhecida. Use ELITE, NEXT, RISE ou ROOKIE.';
    }

    // Tudo daqui pra baixo é da sprint em andamento: a liga recomeça a cada
    // uma, e a temporada 20 de uma sprint encerrada não é a de ninguém hoje.
    $sprintAtiva = loteriaSprintAtiva($pdo, $liga);
    if ($sprintAtiva === null) return "A *{$liga}* não tem uma sprint em andamento.";

    // A sessão de draft mais recente da liga.
    $st = $pdo->prepare("SELECT ds.id, ds.status, s.season_number
                           FROM draft_sessions ds
                           JOIN seasons s ON s.id = ds.season_id
                          WHERE ds.league = ? AND s.sprint_id = ?
                       ORDER BY s.season_number DESC, ds.id DESC LIMIT 1");
    $st->execute([$liga, $sprintAtiva]);
    $sessao = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sessao) return "A *{$liga}* ainda não tem draft montado.";

    // Já confirmada? Então a loteria acabou, e o que vale é a ordem.
    $st = $pdo->prepare("SELECT do.pick_position, t.name AS time_nome
                           FROM draft_order do
                           JOIN teams t ON t.id = do.team_id
                          WHERE do.draft_session_id = ? AND do.round = 1
                       ORDER BY do.pick_position ASC");
    $st->execute([(int)$sessao['id']]);
    $ordem = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($ordem) {
        $l = ["🎲 *LOTERIA — {$liga}*", "_Ordem do draft · Temporada " . (int)$sessao['season_number'] . "_", ''];
        foreach ($ordem as $o) {
            $l[] = str_pad((string)(int)$o['pick_position'], 2, ' ', STR_PAD_LEFT) . '. ' . $o['time_nome'];
        }
        return implode("\n", $l);
    }

    // Ainda não sorteou: mostra quem entra e com que chance.
    $st = $pdo->prepare("SELECT s.id, s.season_number FROM seasons s
                          WHERE s.league = ? AND s.sprint_id = ?
                            AND EXISTS (SELECT 1 FROM season_standings ss WHERE ss.season_id = s.id)
                       ORDER BY s.season_number DESC, s.id DESC LIMIT 1");
    $st->execute([$liga, $sprintAtiva]);
    $temp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$temp) return "A *{$liga}* ainda não tem classificação lançada — sem ela não dá pra montar a loteria.";

    $st = $pdo->prepare("SELECT ss.team_id, ss.position, COALESCE(ss.conference, t.conference) AS conference,
                                ss.wins, ss.points_for, ss.points_against, ss.overall_position,
                                ss.lottery_group,
                                t.name AS team_name
                           FROM season_standings ss
                           JOIN teams t ON t.id = ss.team_id
                          WHERE ss.season_id = ?");
    $st->execute([(int)$temp['id']]);
    $standings = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$standings) return "A *{$liga}* não tem posições registradas.";

    // O /loteria do grupo responde a mesma coisa que a tela: se a temporada
    // ainda não foi jogada, a chance é igual pra todo mundo lá também.
    $g = loteriaMontarGrupos($standings, !loteriaTemporadaFoiJogada($pdo, (int)$temp['id']));
    if (!$g['elegiveis']) return "Ninguém ficou fora do playoff na *{$liga}*.";

    /* UMA LINHA POR TIME, com uma porcentagem só.
       A primeira versão separava por grupo e mostrava Top 3 e Top 5 — era
       fiel ao modelo e ilegível no celular. Quem pergunta quer achar o
       próprio time e ver um número.

       O número é o Top 3: é a faixa que decide a loteria, e o Top 5 conta a
       mesma história um degrau abaixo.

       A ordem é a da CAMPANHA, pior primeiro. Ordenar por porcentagem
       esconderia o que mais surpreende quem lê: neste desenho os três piores
       têm MENOS chance que o miolo, porque levam 2 bolinhas contra 3. */
    $ordenados = $g['elegiveis'];
    usort($ordenados, $g['pior_primeiro']);

    $l = ["🎲 *LOTERIA — {$liga}*", ''];
    foreach ($ordenados as $t) {
        $l[] = ($g['nomes'][$t] ?? ('#' . $t)) . ' — *' . number_format($g['top3'][$t] ?? 0, 1, ',', '') . '%*';
    }
    $l[] = '';
    $l[] = '_Chance de pegar uma das 3 primeiras escolhas._';
    return implode("\n", $l);
}

/**
 * Divide a classificação em quem foi pro playoff e quem entra na loteria,
 * e distribui os elegíveis nos quatro grupos.
 *
 * @param array $standings linhas de season_standings já com team_id,
 *        position, conference, wins, points_for, points_against,
 *        overall_position e team_name
 * @return array{elegiveis:array, playoff:array, grupo_de:array, bolinhas:array}
 */
/**
 * Esta temporada já foi disputada?
 *
 * O sinal é `playoff_results`: ele nasce quando a pontuação é registrada até
 * o fim, e é a única marca confiável de que a temporada aconteceu. Vitória
 * NÃO serve — a FBA nunca cadastra vitória, e o campo fica zerado em toda
 * liga, sempre. `overall_position` e `draft_tail_position` também não: só
 * aparecem quando o admin ajusta a ordem da loteria na mão.
 *
 * Na dúvida (erro de leitura), responde que jogou: manter o 3-2-1 erra menos
 * que zerar as chances de todo mundo.
 */
function loteriaTemporadaFoiJogada(PDO $pdo, int $seasonId): bool
{
    if ($seasonId <= 0) return false;
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM playoff_results WHERE season_id = ?');
        $st->execute([$seasonId]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('[loteriaTemporadaFoiJogada] ' . $e->getMessage());
        return true;
    }
}

/**
 * @param bool $semCampanhaForcado A temporada ainda não foi disputada. Quem
 *        sabe disso é quem consulta o banco; ver loteriaTemporadaFoiJogada().
 */
function loteriaMontarGrupos(array $standings, bool $semCampanhaForcado = false): array
{
    $byConf = [];
    foreach ($standings as $row) {
        $conf = $row['conference'] ?: 'UNICA';
        $byConf[$conf][] = $row;
    }
    foreach ($byConf as &$list) {
        usort($list, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
    }
    unset($list);

    $pos = $wins = $pdiff = $overall = $nomes = $confDe = $declarado = [];
    $elegiveis = $playoff = [];

    foreach ($byConf as $conf => $list) {
        $corte = min(LOTERIA_PLAYOFF_POR_CONF, count($list));
        foreach ($list as $i => $row) {
            $tid = (int)$row['team_id'];
            $nomes[$tid]   = $row['team_name'] ?? ('Time #' . $tid);
            $confDe[$tid]  = $conf === 'UNICA' ? '' : $conf;
            $pos[$tid]     = (int)$row['position'];
            $wins[$tid]    = isset($row['wins']) ? (int)$row['wins'] : 0;
            $pdiff[$tid]   = (int)($row['points_for'] ?? 0) - (int)($row['points_against'] ?? 0);
            $overall[$tid] = ($row['overall_position'] ?? null) !== null ? (int)$row['overall_position'] : null;
            $declarado[$tid] = ($row['lottery_group'] ?? null) !== null ? (int)$row['lottery_group'] : null;
            if ($i < $corte) $playoff[] = $row; else $elegiveis[] = $row;
        }
    }

    /*
     * A MESMA REGRA DA LOTERIA OFICIAL — ver api/draft.php, $semCampanha.
     *
     * Temporada que ninguém jogou não tem "3 piores" nem playoff: todo mundo
     * entra na urna com a mesma chance. O sinal é o PLAYOFF REGISTRADO, que
     * nasce quando a pontuação é lançada até o fim — vitória não é
     * cadastrada na FBA e fica zerada em toda liga, sempre.
     *
     * Quem passa o flag é quem chamou, porque a consulta ao playoff mora no
     * banco e esta função só recebe as linhas da classificação.
     */
    $semCampanha = $semCampanhaForcado;
    if ($semCampanha && $playoff) {
        $elegiveis = array_merge($elegiveis, $playoff);
        $playoff = [];
    }

    $ids = array_map(fn($r) => (int)$r['team_id'], $elegiveis);

    /*
     * "PIOR CAMPANHA" É A ORDEM QUE O ADMIN DEFINIU. Nada de vitórias.
     *
     * A FBA não cadastra vitória — nunca cadastrou. O campo existe na tabela
     * e fica zerado em toda liga, então compará-lo era um passo que sempre
     * empatava e só servia pra fazer o desempate parecer mais fundamentado
     * do que é.
     *
     * Manda a ordem geral declarada (`overall_position`), que é lista única e
     * não empata os dois lados. Sem ela, vale `position` — a classificação
     * que sai do card Pontuação, que é justamente onde a ordem é definida.
     * Saldo de pontos fica no meio como desempate de quem tiver.
     */
    $piorPrimeiro = function ($a, $b) use ($pdiff, $pos, $overall) {
        $oa = $overall[$a] ?? null;
        $ob = $overall[$b] ?? null;
        if ($oa !== null && $ob !== null && $oa !== $ob) return $ob <=> $oa;
        if ($pdiff[$a] !== $pdiff[$b]) return $pdiff[$a] <=> $pdiff[$b];
        return $pos[$b] <=> $pos[$a];
    };

    $declaradoElegiveis = array_filter(
        array_intersect_key($declarado, array_flip($ids)),
        fn($g) => $g !== null
    );
    $grupoDe = loteriaDistribuirGrupos($ids, $pos, $declaradoElegiveis, $piorPrimeiro);

    $bolinhas = [];
    foreach ($ids as $t) {
        // Urna igual pra todos sem campanha, como na oficial.
        $g = $grupoDe[$t] ?? 2;
        $bolinhas[$t] = $semCampanha ? 1 : LOTERIA_GRUPOS_META[$g]['balls'];
    }
    $odds = loteriaOdds($bolinhas);

    return [
        'sem_campanha' => $semCampanha,
        'elegiveis'    => $ids,
        'playoff'      => array_map(fn($r) => (int)$r['team_id'], $playoff),
        'grupo_de'     => $grupoDe,
        'bolinhas'     => $bolinhas,
        'top1'         => $odds['top1'],
        'top3'         => $odds['top3'],
        'top5'         => $odds['top5'],
        'declarado'    => $declaradoElegiveis,
        'nomes'        => $nomes,
        'conferencias' => $confDe,
        'posicoes'     => $pos,
        'ordem_geral'  => $overall,
        'pior_primeiro'=> $piorPrimeiro,
    ];
}

/**
 * AS CHANCES DE VERDADE, a partir das bolinhas que estão na urna.
 *
 * Antes as porcentagens eram números fixos ao lado de cada grupo — certas
 * enquanto os grupos tivessem exatamente 3, 7, 4 e 2 times. Quando o play-in
 * termina de um jeito que não cabe nesse molde, os tamanhos mudam, o total de
 * bolinhas muda junto e os números do quadro deixam de descrever o sorteio.
 *
 * Aqui a conta é feita em cima da urna que existe. O sorteio é ponderado e
 * SEM reposição, e sem renormalizar os pesos de quem sobra (regra da NBA), o
 * que dá a chance de um time com peso w sair na k-ésima bola:
 *
 *     P(1ª)  = w / T
 *     P(2ª)  = Σ  P(sair alguém de peso j primeiro) · w / (T − j)
 *     ...
 *
 * Times com o mesmo número de bolinhas têm exatamente a mesma chance, então
 * a conta roda uma vez por peso e não uma vez por time. O estado que importa
 * não é QUEM saiu, e sim quantos de cada peso saíram — são poucas
 * combinações até a 5ª bola, e o resultado é exato, não simulado.
 *
 * @param array $bolinhas  [team_id => nº de bolinhas]
 * @return array{top3: array<int,float>, top5: array<int,float>}  em %
 */
function loteriaOdds(array $bolinhas): array
{
    $total = 0;
    foreach ($bolinhas as $w) $total += (int)$w;
    if ($total <= 0) return ['top3' => [], 'top5' => []];

    $porPeso = [];
    foreach ($bolinhas as $w) {
        $w = (int)$w;
        if ($w > 0) $porPeso[$w] = ($porPeso[$w] ?? 0) + 1;
    }

    $porPesoResultado = [];
    foreach (array_keys($porPeso) as $wAlvo) {
        // O alvo sai do bolo: ele não pode ser sorteado antes de si mesmo.
        $disp = $porPeso;
        $disp[$wAlvo]--;

        $estados = ['' => ['c' => array_fill_keys(array_keys($porPeso), 0), 'p' => 1.0, 'r' => 0]];
        $acumulado = 0.0;
        $faixa = [];

        for ($k = 0; $k < 5; $k++) {
            foreach ($estados as $e) {
                $restante = $total - $e['r'];
                if ($restante <= 0) continue;
                $acumulado += $e['p'] * $wAlvo / $restante;
            }
            $faixa[$k + 1] = $acumulado;   // chance de estar entre as k+1 primeiras

            $novos = [];
            foreach ($estados as $e) {
                $restante = $total - $e['r'];
                if ($restante <= 0) continue;
                foreach ($disp as $j => $qtd) {
                    $sobram = $qtd - $e['c'][$j];
                    if ($sobram <= 0) continue;
                    $c = $e['c'];
                    $c[$j]++;
                    $chave = implode(',', $c);
                    if (!isset($novos[$chave])) $novos[$chave] = ['c' => $c, 'p' => 0.0, 'r' => $e['r'] + $j];
                    $novos[$chave]['p'] += $e['p'] * ($sobram * $j) / $restante;
                }
            }
            $estados = $novos;
        }
        $porPesoResultado[$wAlvo] = ['top1' => $faixa[1], 'top3' => $faixa[3], 'top5' => $faixa[5]];
    }

    $top1 = $top3 = $top5 = [];
    foreach ($bolinhas as $tid => $w) {
        $w = (int)$w;
        $top1[$tid] = $w > 0 ? round($porPesoResultado[$w]['top1'] * 100, 1) : 0.0;
        $top3[$tid] = $w > 0 ? round($porPesoResultado[$w]['top3'] * 100, 1) : 0.0;
        $top5[$tid] = $w > 0 ? round($porPesoResultado[$w]['top5'] * 100, 1) : 0.0;
    }
    return ['top1' => $top1, 'top3' => $top3, 'top5' => $top5];
}

/**
 * A MATRIZ COMPLETA: a chance de cada time terminar em CADA pick.
 *
 * As faixas Top 3 e Top 5 respondem "com que chance eu pego uma escolha
 * boa", mas somam 300% e 500% entre os times — três e cinco escolhas sendo
 * distribuídas —, e quem lê a tabela procurando um total de 100% não acha.
 * Aqui cada linha soma 100% (o time termina em alguma pick) e cada coluna
 * também (a pick vai pra alguém).
 *
 * É SIMULADA, e não calculada, por causa do piso de proteção: os 3 piores
 * não podem cair além da pick 12, e quando isso acontece eles trocam de
 * lugar com quem estiver na vaga mais funda do top-12. Essa troca depende da
 * ordem inteira que saiu, não da posição de um time só — não há fórmula
 * fechada pra ela como há pro sorteio puro.
 *
 * A semente é FIXA de propósito. Uma matriz que muda de casa decimal a cada
 * F5 destrói a confiança em toda a tela, e a liga não teria como saber se o
 * número mudou porque a urna mudou ou porque o acaso mudou. Mesma urna,
 * mesma matriz, sempre.
 *
 * @param array $bolinhas [team_id => bolinhas]
 * @param array $protegidos ids que não podem cair além de $pisoIdx (0-based)
 * @return array [team_id => [pick_1_based => pct]]
 */
function loteriaMatriz(array $bolinhas, array $protegidos = [], int $pisoIdx = 11, int $rodadas = 200000): array
{
    if (!$bolinhas) return [];

    $cache = loteriaMatrizCache($bolinhas, $protegidos, $pisoIdx, $rodadas);
    if ($cache !== null) return $cache;

    $ids = array_keys($bolinhas);
    $n = count($ids);
    $contagem = [];
    foreach ($ids as $t) $contagem[$t] = array_fill(0, $n, 0);

    mt_srand(20260827);
    for ($r = 0; $r < $rodadas; $r++) {
        // Sorteio ponderado sem reposição, sem renormalizar quem sobra —
        // o mesmo do api/draft.php.
        $pool = $bolinhas;
        $ordem = [];
        $total = array_sum($pool);
        while ($pool) {
            $sorteado = mt_rand(1, $total);
            $acumulado = 0;
            foreach ($pool as $tid => $peso) {
                $acumulado += $peso;
                if ($sorteado <= $acumulado) {
                    $ordem[] = $tid;
                    $total -= $peso;
                    unset($pool[$tid]);
                    break;
                }
            }
        }

        /* Ordem de atendimento sorteada, igual ao api/draft.php: quem é
           atendido primeiro fica com a vaga mais funda do top-12, e uma ordem
           fixa daria a três times de bolinhas iguais chances diferentes. */
        $ordemProtecao = $protegidos;
        shuffle($ordemProtecao);
        foreach ($ordemProtecao as $tid) {
            $idx = array_search($tid, $ordem, true);
            if ($idx === false || $idx <= $pisoIdx) continue;
            for ($j = $pisoIdx; $j >= 0; $j--) {
                if (!in_array($ordem[$j], $protegidos, true)) {
                    $troca = $ordem[$j];
                    $ordem[$j] = $tid;
                    $ordem[$idx] = $troca;
                    break;
                }
            }
        }

        foreach ($ordem as $pos => $tid) $contagem[$tid][$pos]++;
    }

    /* TIMES DE MESMA URNA TÊM A MESMA CHANCE — e precisam mostrar o mesmo
       número. Dois times com as mesmas bolinhas e o mesmo status de proteção
       são estatisticamente idênticos; o que os separava na tela era só o
       ruído da simulação, e ver 7,6 num e 7,8 no outro parece favorecimento.
       Somando as contagens dos iguais e dividindo pelo tamanho do bloco, o
       número passa a ser um só — e de quebra fica mais preciso, porque cada
       célula reúne a amostra de todos eles. */
    $blocos = [];
    foreach ($ids as $t) {
        $chave = $bolinhas[$t] . '|' . (in_array($t, $protegidos, true) ? 'p' : '-');
        $blocos[$chave][] = $t;
    }
    foreach ($blocos as $membros) {
        if (count($membros) < 2) continue;
        $soma = array_fill(0, $n, 0);
        foreach ($membros as $t) foreach ($contagem[$t] as $p => $q) $soma[$p] += $q;
        foreach ($membros as $t) foreach ($soma as $p => $q) $contagem[$t][$p] = $q / count($membros);
    }

    /* ARREDONDAMENTO QUE FECHA OS DOIS SENTIDOS.
       Nos valores exatos, linha e coluna somam 100 por construção: em toda
       rodada o time termina em exatamente uma pick, e cada pick recebe
       exatamente um time. É o arredondamento que estraga isso — e uma tabela
       que mostra 99,8 dá razão a quem desconfia da conta.

       Duas etapas. Primeiro cada linha é fechada com sobra: as células descem
       pro centésimo de baixo e os centésimos que faltam vão pras de maior
       resto. Depois as colunas são acertadas por troca DENTRO de cada linha —
       tirar um centésimo de uma célula e dar a outra da mesma linha mantém a
       linha fechada e move a diferença pra coluna que precisa. */
    $casas = 2;
    $escala = 10 ** $casas;          // centésimos
    $alvo   = 100 * $escala;

    $exatos = $celulas = [];
    foreach ($contagem as $tid => $porPos) {
        $restos = [];
        $acumulado = 0;
        foreach ($porPos as $pos => $qtd) {
            $valor = $qtd / $rodadas * 100 * $escala;   // em centésimos
            $exatos[$tid][$pos + 1] = $valor;
            $piso = (int)floor($valor);
            $celulas[$tid][$pos + 1] = $piso;
            $restos[$pos + 1] = $valor - $piso;
            $acumulado += $piso;
        }
        arsort($restos);
        foreach (array_keys($restos) as $pick) {
            if ($acumulado >= $alvo) break;
            $celulas[$tid][$pick]++;
            $acumulado++;
        }
    }

    $picks = range(1, $n);
    $tids  = array_keys($celulas);
    for ($volta = 0; $volta < 200; $volta++) {
        $somaCol = [];
        foreach ($picks as $p) {
            $s = 0;
            foreach ($tids as $t) $s += $celulas[$t][$p];
            $somaCol[$p] = $s;
        }
        $sobrando = array_keys(array_filter($somaCol, fn($s) => $s > $alvo));
        $faltando = array_keys(array_filter($somaCol, fn($s) => $s < $alvo));
        if (!$sobrando || !$faltando) break;

        $de = reset($sobrando);
        $para = reset($faltando);
        /* Move o centésimo na linha que menos sofre com isso: aquela cuja
           célula de origem está mais acima do valor real e a de destino mais
           abaixo. Célula que vale zero de verdade nunca recebe — os 3 piores
           precisam continuar vazios nas picks que o piso proíbe. */
        $melhorTid = null;
        $melhorGanho = -INF;
        foreach ($tids as $t) {
            if ($celulas[$t][$de] <= 0) continue;
            if ($exatos[$t][$para] <= 0) continue;
            $ganho = ($celulas[$t][$de] - $exatos[$t][$de]) + ($exatos[$t][$para] - $celulas[$t][$para]);
            if ($ganho > $melhorGanho) { $melhorGanho = $ganho; $melhorTid = $t; }
        }
        if ($melhorTid === null) break;   // nada a trocar sem inventar número
        $celulas[$melhorTid][$de]--;
        $celulas[$melhorTid][$para]++;
    }

    $matriz = [];
    foreach ($celulas as $tid => $linha) {
        ksort($linha);
        foreach ($linha as $pick => $centesimos) $matriz[$tid][$pick] = $centesimos / $escala;
    }

    loteriaMatrizCache($bolinhas, $protegidos, $pisoIdx, $rodadas, $matriz);
    return $matriz;
}

/**
 * Guarda a matriz em disco, chaveada pela urna que a gerou.
 *
 * A simulação leva mais de um segundo, e a prévia é recarregada a cada seta
 * clicada — sem isso, ajustar a ordem viraria uma espera. A chave inclui
 * tudo que muda o resultado, então uma urna diferente nunca lê a matriz de
 * outra. Falha de escrita é ignorada: sem cache a conta apenas refaz.
 */
function loteriaMatrizCache(array $bolinhas, array $protegidos, int $pisoIdx, int $rodadas, ?array $gravar = null): ?array
{
    ksort($bolinhas);
    sort($protegidos);
    /* A VERSÃO ENTRA NA CHAVE.
       Sem ela, mudar a forma de calcular não invalidava nada: a urna era a
       mesma, a chave era a mesma, e o servidor seguia entregando a matriz
       feita pelo cálculo antigo. Foi assim que uma tela recém-publicada com
       duas casas continuou mostrando números de uma casa só, com as colunas
       fora de 100. Toda mudança no cálculo mexe neste número. */
    $versaoDoCalculo = 3;
    $chave = md5(json_encode([$versaoDoCalculo, $bolinhas, $protegidos, $pisoIdx, $rodadas]));
    $arquivo = sys_get_temp_dir() . '/fba_loteria_matriz_' . $chave . '.json';

    if ($gravar === null) {
        if (!is_readable($arquivo)) return null;
        $conteudo = json_decode((string)file_get_contents($arquivo), true);
        return is_array($conteudo) ? $conteudo : null;
    }

    @file_put_contents($arquivo, json_encode($gravar));
    return null;
}
