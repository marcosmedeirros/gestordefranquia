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

    // A sessão de draft mais recente da liga.
    $st = $pdo->prepare("SELECT ds.id, ds.status, s.season_number
                           FROM draft_sessions ds
                           JOIN seasons s ON s.id = ds.season_id
                          WHERE ds.league = ?
                       ORDER BY ds.id DESC LIMIT 1");
    $st->execute([$liga]);
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
                          WHERE s.league = ?
                            AND EXISTS (SELECT 1 FROM season_standings ss WHERE ss.season_id = s.id)
                       ORDER BY s.season_number DESC, s.id DESC LIMIT 1");
    $st->execute([$liga]);
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

    $g = loteriaMontarGrupos($standings);
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
function loteriaMontarGrupos(array $standings): array
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

    $ids = array_map(fn($r) => (int)$r['team_id'], $elegiveis);

    /* "Pior campanha": a ordem geral declarada no registro da temporada
       manda, porque é uma lista única e não empata os dois lados. Sem ela,
       vale o critério antigo — vitórias, saldo, posição —, que hoje é frágil:
       vitórias não são mais cadastradas e ficam todas em zero. */
    $piorPrimeiro = function ($a, $b) use ($wins, $pdiff, $pos, $overall) {
        $oa = $overall[$a] ?? null;
        $ob = $overall[$b] ?? null;
        if ($oa !== null && $ob !== null && $oa !== $ob) return $ob <=> $oa;
        if ($wins[$a] !== $wins[$b])   return $wins[$a] <=> $wins[$b];
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
        $g = $grupoDe[$t] ?? 2;
        $bolinhas[$t] = LOTERIA_GRUPOS_META[$g]['balls'];
    }
    $odds = loteriaOdds($bolinhas);

    return [
        'elegiveis'    => $ids,
        'playoff'      => array_map(fn($r) => (int)$r['team_id'], $playoff),
        'grupo_de'     => $grupoDe,
        'bolinhas'     => $bolinhas,
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
