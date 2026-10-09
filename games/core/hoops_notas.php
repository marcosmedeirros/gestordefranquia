<?php
/**
 * ── FBA HOOPS: A CARTA SAI DA ESTATÍSTICA ────────────────────────────
 *
 * Nenhuma API gratuita tem nota de jogador — nem OVR, nem atributo. O que
 * elas têm é o que ele fez em quadra. Então a carta é DERIVADA disso, e
 * gravada (@see hoops_importar_cli.php): a mesma pessoa vale o mesmo em
 * toda partida, e dá pra corrigir na mão quem a conta errar.
 *
 * ── AS OITO NOTAS ────────────────────────────────────────────────────
 *
 *   arr3  Arremesso de 3    — % de três (com volume: 40% em 1 tentativa
 *                             por jogo não é a mesma coisa que em 9)
 *   meia  Meia distância    — lance livre e eficiência, o "toque"
 *   fin   Finalização       — % de dois, ida à linha e pontos
 *   pas   Passe             — assistência, e quanto ela custa em erro
 *   ctrl  Controle de bola  — quanto a bola passa pela mão sem se perder
 *   dper  Defesa perímetro  — roubos e a confiança do técnico (minutos)
 *   dgar  Defesa garrafão   — tocos, altura e rebote
 *   reb   Rebote            — rebote por minuto e altura
 *
 * Cada uma é um PERCENTIL DENTRO DA PRÓPRIA LIGA. É o percentil que faz a
 * escala usar a régua inteira; com limiar fixo, uma liga de baixa pontuação
 * teria todo mundo com nota baixa de finalização.
 *
 * ── AMOSTRA PEQUENA ENCOLHE PRA MÉDIA ────────────────────────────────
 *
 * Quem jogou 40 minutos no ano e fez 3 de 3 de três não é o melhor
 * arremessador da liga. Toda taxa é "encolhida" em direção à média da liga
 * na proporção de quanto o jogador jogou (HOOPS_ENCOLHE_*). Muito minuto,
 * a conta é a dele; pouco minuto, a conta é quase a da liga.
 *
 * ── O OVR NÃO É A MÉDIA DAS NOTAS ────────────────────────────────────
 *
 * Se fosse, o Jokić — que não rouba bola nem chuta muito de três — seria
 * um 78. O OVR mistura duas coisas: as notas que importam pra posição dele
 * (HOOPS_PESOS_POSICAO) e o IMPACTO, que é o game score por jogo do
 * Hollinger. O impacto é por jogo e não por minuto, e de propósito: o
 * titular de 36 minutos vale mais que o reserva eficiente de 12, como no
 * jogo de verdade.
 *
 * ── AS LIGAS NA MESMA RÉGUA ──────────────────────────────────────────
 *
 * Percentil por liga põe o melhor de cada uma lá em cima. O que separa as
 * ligas é o teto e o piso de OVR de cada uma (HOOPS_ESCALA_LIGA): o melhor
 * da EuroCup não vira o Jokić. A WNBA joga no mesmo baralho, misturada, e
 * com a MESMA régua da NBA — os dois pedidos do Victor em 08/10/2026.
 */

/** As oito notas, na ordem da carta. */
const HOOPS_ATRIBUTOS = [
    'arr3' => ['label' => 'Arremesso de 3',   'curto' => '3PT'],
    'meia' => ['label' => 'Meia distância',   'curto' => 'MEI'],
    'fin'  => ['label' => 'Finalização',      'curto' => 'FIN'],
    'pas'  => ['label' => 'Passe',            'curto' => 'PAS'],
    'ctrl' => ['label' => 'Controle de bola', 'curto' => 'CTR'],
    'dper' => ['label' => 'Defesa perímetro', 'curto' => 'DPE'],
    'dgar' => ['label' => 'Defesa garrafão',  'curto' => 'DGA'],
    'reb'  => ['label' => 'Rebote',           'curto' => 'REB'],
];

const HOOPS_POSICOES = ['PG', 'SG', 'SF', 'PF', 'C'];

/**
 * Quanto cada nota pesa no OVR de cada posição. Cada linha soma 1.
 * O armador vive de passe e controle; o pivô, de garrafão e rebote.
 */
const HOOPS_PESOS_POSICAO = [
    'PG' => ['arr3' => .17, 'meia' => .12, 'fin' => .10, 'pas' => .25, 'ctrl' => .22, 'dper' => .10, 'dgar' => .01, 'reb' => .03],
    'SG' => ['arr3' => .25, 'meia' => .17, 'fin' => .16, 'pas' => .10, 'ctrl' => .12, 'dper' => .14, 'dgar' => .02, 'reb' => .04],
    'SF' => ['arr3' => .18, 'meia' => .13, 'fin' => .18, 'pas' => .09, 'ctrl' => .08, 'dper' => .14, 'dgar' => .08, 'reb' => .12],
    'PF' => ['arr3' => .10, 'meia' => .08, 'fin' => .22, 'pas' => .07, 'ctrl' => .04, 'dper' => .08, 'dgar' => .19, 'reb' => .22],
    'C'  => ['arr3' => .04, 'meia' => .05, 'fin' => .24, 'pas' => .07, 'ctrl' => .02, 'dper' => .04, 'dgar' => .27, 'reb' => .27],
];

/** No OVR, quanto vem do impacto (game score) e quanto das notas. */
const HOOPS_PESO_IMPACTO = 0.55;

/** Minutos de quadra que "valem" a média da liga, na hora de encolher. */
const HOOPS_ENCOLHE_MIN = 400;
/** O mesmo, em tentativas — pras porcentagens. */
const HOOPS_ENCOLHE_2P  = 80;
const HOOPS_ENCOLHE_3P  = 100;
const HOOPS_ENCOLHE_LL  = 50;

/**
 * Quem entra na conta da média e dos percentis de cada liga. Os de pouco
 * minuto continuam ganhando carta, mas não puxam a régua dos outros.
 */
const HOOPS_MIN_TOTAL_REGUA = 300;

/**
 * A régua de OVR de cada liga: percentil do jogador → OVR. Pontos de
 * ancoragem, interpolados em linha reta. O topo da NBA é 97; o do resto,
 * menos, e é isso que põe as ligas umas em relação às outras.
 */
const HOOPS_ESCALA_LIGA = [
    'NBA'        => [[0, 62], [.25, 68], [.50, 72], [.75, 77], [.90, 82], [.97, 87], [.995, 92], [1, 97]],
    // A WNBA usa a régua da NBA (pedido do Victor, 08/10/2026): a estrela
    // de lá é carta de estrela aqui, no mesmo baralho.
    'WNBA'       => [[0, 62], [.25, 68], [.50, 72], [.75, 77], [.90, 82], [.97, 87], [.995, 92], [1, 97]],
    'EuroLeague' => [[0, 60], [.25, 65], [.50, 69], [.75, 73], [.90, 77], [.97, 81], [.995, 85], [1, 89]],
    'EuroCup'    => [[0, 56], [.25, 61], [.50, 64], [.75, 68], [.90, 72], [.97, 76], [.995, 79], [1, 83]],
    // O NBB só tem 20 cartas, e cada uma tem um piso de OVR escolhido à mão
    // (games/data/hoops_nbb.php). Esta régua vale pra quem a estatística
    // levar ACIMA do piso.
    'NBB'        => [[0, 55], [.25, 60], [.50, 64], [.75, 68], [.90, 72], [.97, 77], [.995, 81], [1, 84]],
];

/** As notas de atributo andam junto com a liga, mas menos que o OVR. */
const HOOPS_DESCONTO_ATRIBUTO = [
    'NBA' => 0, 'WNBA' => 0, 'EuroLeague' => 4, 'EuroCup' => 8, 'NBB' => 8,
];

// ───────────────────────────── utilitários ─────────────────────────────

/** Interpola nos pontos de ancoragem [[x, y], ...], ordenados por x. */
function hoopsInterpolar(array $ancoras, float $x): float
{
    if ($x <= $ancoras[0][0]) return $ancoras[0][1];
    for ($i = 1; $i < count($ancoras); $i++) {
        [$x1, $y1] = $ancoras[$i];
        if ($x <= $x1) {
            [$x0, $y0] = $ancoras[$i - 1];
            return $y0 + ($y1 - $y0) * ($x - $x0) / max(1e-9, $x1 - $x0);
        }
    }
    return end($ancoras)[1];
}

/** Percentil (0..1) de $v dentro de $ordenados (crescente). */
function hoopsPercentil(array $ordenados, float $v): float
{
    $n = count($ordenados);
    if ($n === 0) return .5;
    // Busca binária: quantos são estritamente menores, quantos iguais.
    $lo = 0; $hi = $n;
    while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($ordenados[$m] < $v) $lo = $m + 1; else $hi = $m; }
    $menores = $lo;
    $hi = $n;
    while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($ordenados[$m] <= $v) $lo = $m + 1; else $hi = $m; }
    $iguais = $lo - $menores;
    return ($menores + $iguais / 2) / $n;
}

/** Percentil → nota de atributo (35 a 99). */
function hoopsNotaDoPercentil(float $p): int
{
    return (int)round(hoopsInterpolar([[0, 35], [.2, 50], [.5, 64], [.8, 77], [.95, 88], [1, 99]], $p));
}

// ─────────────────────────── as contas brutas ──────────────────────────

/**
 * Os números de que as notas precisam, já encolhidos pra média da liga.
 *
 * @param array $st  médias por jogo (gp, min, pts, fgm, ...)
 * @param array $med médias da liga (a mesma estrutura, por 36 e em %)
 */
function hoopsBrutos(array $st, float $alturaCm, array $med): array
{
    $minTot = max(1.0, $st['gp'] * $st['min']);
    $por36  = fn(float $x) => $st['min'] > 0 ? $x / $st['min'] * 36 : 0.0;
    $enc36  = fn(string $k, float $x) => ($por36($x) * $minTot + $med[$k] * HOOPS_ENCOLHE_MIN) / ($minTot + HOOPS_ENCOLHE_MIN);
    $encPct = function (float $acertos, float $tent, float $media, int $k) use ($st) {
        $acertos *= $st['gp']; $tent *= $st['gp'];
        return ($acertos + $media * $k) / ($tent + $k);
    };

    $fg2m = max(0, $st['fgm'] - $st['tpm']);
    $fg2a = max(0, $st['fga'] - $st['tpa']);
    $uso  = $st['fga'] + 0.44 * $st['fta'] + $st['tov'];

    return [
        'pct3'  => $encPct($st['tpm'], $st['tpa'], $med['pct3'], HOOPS_ENCOLHE_3P),
        'pct2'  => $encPct($fg2m, $fg2a, $med['pct2'], HOOPS_ENCOLHE_2P),
        'pctll' => $encPct($st['ftm'], $st['fta'], $med['pctll'], HOOPS_ENCOLHE_LL),
        'ts'    => $encPct($st['pts'] / 2, $st['fga'] + 0.44 * $st['fta'], $med['ts'], HOOPS_ENCOLHE_2P),
        'tpa36' => $enc36('tpa36', $st['tpa']),
        'fta36' => $enc36('fta36', $st['fta']),
        'pts36' => $enc36('pts36', $st['pts']),
        'ast36' => $enc36('ast36', $st['ast']),
        'tov36' => $enc36('tov36', $st['tov']),
        'stl36' => $enc36('stl36', $st['stl']),
        'blk36' => $enc36('blk36', $st['blk']),
        'reb36' => $enc36('reb36', $st['reb']),
        'uso36' => $enc36('uso36', $uso),
        'min'   => $st['min'],
        'alt'   => $alturaCm,
        // Game score por jogo (Hollinger), com o rebote total no lugar do
        // ofensivo/defensivo que a ESPN não separa: 0,4 por rebote é o peso
        // médio das duas metades na fórmula original.
        'gmsc'  => $st['pts'] + 0.4 * $st['fgm'] - 0.7 * $st['fga'] - 0.4 * ($st['fta'] - $st['ftm'])
                 + 0.4 * $st['reb'] + 0.7 * $st['ast'] + $st['stl'] + 0.7 * $st['blk'] - $st['tov'],
    ];
}

/** As médias de uma liga, a partir de quem tem minuto suficiente. */
function hoopsMediasLiga(array $jogadores): array
{
    $soma = array_fill_keys(['min', 'pts', 'fgm', 'fga', 'tpm', 'tpa', 'ftm', 'fta', 'reb', 'ast', 'tov', 'stl', 'blk'], 0.0);
    foreach ($jogadores as $j) {
        $peso = $j['st']['gp']; // soma de totais, não média de médias
        foreach ($soma as $k => $_) $soma[$k] += $j['st'][$k] * $peso;
    }
    $p36 = fn(float $x) => $soma['min'] > 0 ? $x / $soma['min'] * 36 : 0.0;
    $fg2a = max(1, $soma['fga'] - $soma['tpa']);
    return [
        'pct3'  => $soma['tpa'] > 0 ? $soma['tpm'] / $soma['tpa'] : .34,
        'pct2'  => ($soma['fgm'] - $soma['tpm']) / $fg2a,
        'pctll' => $soma['fta'] > 0 ? $soma['ftm'] / $soma['fta'] : .75,
        'ts'    => $soma['pts'] / 2 / max(1, $soma['fga'] + 0.44 * $soma['fta']),
        'tpa36' => $p36($soma['tpa']), 'fta36' => $p36($soma['fta']), 'pts36' => $p36($soma['pts']),
        'ast36' => $p36($soma['ast']), 'tov36' => $p36($soma['tov']), 'stl36' => $p36($soma['stl']),
        'blk36' => $p36($soma['blk']), 'reb36' => $p36($soma['reb']),
        'uso36' => $p36($soma['fga'] + 0.44 * $soma['fta'] + $soma['tov']),
    ];
}

/**
 * A fórmula de cada nota, em cima dos percentis dos brutos.
 * Cada linha soma 1 — a nota final é um percentil da combinação.
 */
function hoopsCombinacoes(): array
{
    return [
        'arr3' => ['pct3' => .65, 'tpa36' => .35],
        'meia' => ['pctll' => .60, 'ts' => .40],
        'fin'  => ['pct2' => .40, 'fta36' => .30, 'pts36' => .30],
        'pas'  => ['ast36' => .75, 'astTov' => .25],
        'ctrl' => ['ast36' => .40, 'uso36' => .35, 'tovUso' => .25],
        // Roubo é o único número de defesa de perímetro que existe. Os
        // minutos entram pouco: com 30% o Luka saía com 95 de defesa, porque
        // estrela joga 36 minutos por ser estrela, não por marcar bem.
        'dper' => ['stl36' => .65, 'alt' => .20, 'min' => .15],
        'dgar' => ['blk36' => .60, 'alt' => .25, 'reb36' => .15],
        'reb'  => ['reb36' => .75, 'alt' => .25],
    ];
}

// ─────────────────────────────── posição ───────────────────────────────

/**
 * A posição de cinco a partir do que a fonte diz.
 *
 * A ESPN e a EuroLeague falam quase sempre em três (G, F, C). O que separa
 * PG de SG é o passe; SF de PF, o rebote contra o chute de três — ambos
 * comparados com quem tem a mesma posição larga na mesma liga, porque
 * "muito rebote" pra um ala da WNBA é outro número que pra um da NBA.
 *
 * @return array{0:string,1:string} [posição, segunda posição]
 */
function hoopsPosicao(string $fonte, array $pctGrupo): array
{
    // Quando a fonte já diz a posição exata, ela manda.
    $exatas = [
        'PG' => ['PG', 'SG'], 'SG' => ['SG', 'PG'], 'SF' => ['SF', 'PF'], 'PF' => ['PF', 'C'],
        'G-F' => ['SG', 'SF'], 'F-G' => ['SF', 'SG'], 'F-C' => ['PF', 'C'], 'C-F' => ['C', 'PF'],
    ];
    $f = strtoupper(trim($fonte));
    if (isset($exatas[$f])) return $exatas[$f];

    switch (hoopsGrupoLargo($fonte)) {
        case 'C':
            return ['C', 'PF'];
        case 'G':
            // Metade dos armadores da liga que mais dá assistência é PG.
            $armador = $pctGrupo['ast36'] ?? .5;
            if ($armador >= .5) return ['PG', 'SG'];
            return ['SG', $armador >= .3 ? 'PG' : 'SF'];
        default:
            // Ala: o jogo de dentro (rebote, altura) contra o de fora (três).
            // Os mais altos do grupo jogam de pivô também, mesmo chutando de
            // três — senão o Wembanyama saía com SF de segunda posição.
            if (($pctGrupo['alt'] ?? 0) >= .85) return ['PF', 'C'];
            $interno = (($pctGrupo['reb36'] ?? .5) + ($pctGrupo['alt'] ?? .5) + 1 - ($pctGrupo['tpa36'] ?? .5)) / 3;
            if ($interno >= .5) return ['PF', $interno >= .75 ? 'C' : 'SF'];
            return ['SF', $interno >= .3 ? 'PF' : 'SG'];
    }
}

/** O grupo largo da posição (G, F ou C), pra comparar com os parecidos. */
function hoopsGrupoLargo(string $fonte): string
{
    $f = strtoupper(trim($fonte));
    if (in_array($f, ['PG', 'SG', 'G', 'GUARD', 'G-F'], true)) return 'G';
    if (in_array($f, ['C', 'CENTER', 'CENTRE', 'C-F'], true)) return 'C';
    return 'F';
}

// ─────────────────────────────── a carta ───────────────────────────────

/**
 * Transforma a lista bruta (todas as ligas) em cartas.
 *
 * @param array $jogadores linhas de hoops_fontes.php
 * @return array cartas, com ovr, pos, pos2 e as oito notas em `at`
 */
function hoopsNotasCalcular(array $jogadores): array
{
    $porLiga = [];
    foreach ($jogadores as $i => $j) $porLiga[$j['liga']][] = $i;

    $cartas = [];
    foreach ($porLiga as $liga => $indices) {
        $daLiga = array_map(fn($i) => $jogadores[$i], $indices);
        $regua  = array_values(array_filter($daLiga, fn($j) => $j['st']['gp'] * $j['st']['min'] >= HOOPS_MIN_TOTAL_REGUA));
        if (count($regua) < 30) $regua = $daLiga; // liga pequena: todo mundo é régua
        $med = hoopsMediasLiga($regua);

        // 1) brutos de todo mundo, mais as razões derivadas
        $brutos = [];
        foreach ($daLiga as $k => $j) {
            $b = hoopsBrutos($j['st'], (float)$j['altura'], $med);
            $b['astTov'] = $b['ast36'] / max(.5, $b['tov36']);
            $b['tovUso'] = -$b['tov36'] / max(1, $b['uso36']); // menos erro por posse = melhor
            $brutos[$k] = $b;
        }
        // Altura desconhecida (0) vira a mediana da liga. No NBB só os
        // escolhidos têm ficha; com zero nos outros 292, qualquer ala de
        // 1,85 m parecia pivô — e ganhava nota de rebote e garrafão de graça.
        $alturas = array_values(array_filter(array_column($brutos, 'alt'), fn($x) => $x > 0));
        sort($alturas);
        $altMediana = $alturas ? $alturas[intdiv(count($alturas), 2)] : 0;
        foreach ($brutos as $k => $b) if ($b['alt'] <= 0) $brutos[$k]['alt'] = $altMediana;

        $ehRegua = [];
        foreach ($daLiga as $k => $j) $ehRegua[$k] = $j['st']['gp'] * $j['st']['min'] >= HOOPS_MIN_TOTAL_REGUA || count($regua) === count($daLiga);

        // 2) a régua de cada bruto (só quem joga o bastante)
        $colunas = array_keys(reset($brutos));
        $ordem = [];
        foreach ($colunas as $c) {
            $vals = [];
            foreach ($brutos as $k => $b) if ($ehRegua[$k]) $vals[] = $b[$c];
            sort($vals);
            $ordem[$c] = $vals;
        }
        $pct = [];
        foreach ($brutos as $k => $b) foreach ($b as $c => $v) $pct[$k][$c] = hoopsPercentil($ordem[$c], $v);

        // 3) as oito notas: percentil da combinação, de novo contra a régua
        $combos = hoopsCombinacoes();
        $mistura = [];
        foreach ($brutos as $k => $_) {
            foreach ($combos as $at => $pesos) {
                $s = 0;
                foreach ($pesos as $c => $w) $s += $pct[$k][$c] * $w;
                $mistura[$at][$k] = $s;
            }
        }
        $notas = [];
        $desconto = HOOPS_DESCONTO_ATRIBUTO[$liga] ?? 0;
        foreach ($combos as $at => $_) {
            $vals = [];
            foreach ($mistura[$at] as $k => $v) if ($ehRegua[$k]) $vals[] = $v;
            sort($vals);
            foreach ($mistura[$at] as $k => $v) {
                $notas[$k][$at] = max(25, hoopsNotaDoPercentil(hoopsPercentil($vals, $v)) - $desconto);
            }
        }

        // 4) posição, comparando com o mesmo grupo largo da liga
        $grupoPct = [];
        foreach (['G', 'F', 'C'] as $g) {
            $membros = array_keys(array_filter($daLiga, fn($j) => hoopsGrupoLargo($j['pos_fonte']) === $g));
            foreach (['ast36', 'reb36', 'tpa36', 'alt'] as $c) {
                $vals = array_map(fn($k) => $brutos[$k][$c], $membros);
                sort($vals);
                foreach ($membros as $k) $grupoPct[$k][$c] = hoopsPercentil($vals, $brutos[$k][$c]);
            }
        }

        // 5) o OVR: impacto + notas da posição, e aí a régua da liga
        $ovrBruto = [];
        $posicoes = [];
        foreach ($daLiga as $k => $j) {
            $posicoes[$k] = hoopsPosicao($j['pos_fonte'], $grupoPct[$k] ?? []);
            $doPosto = 0;
            foreach (HOOPS_PESOS_POSICAO[$posicoes[$k][0]] as $at => $w) $doPosto += ($notas[$k][$at] + $desconto) * $w;
            $ovrBruto[$k] = HOOPS_PESO_IMPACTO * $pct[$k]['gmsc'] * 100 + (1 - HOOPS_PESO_IMPACTO) * $doPosto;
        }
        $vals = [];
        foreach ($ovrBruto as $k => $v) if ($ehRegua[$k]) $vals[] = $v;
        sort($vals);

        foreach ($daLiga as $k => $j) {
            $p = hoopsPercentil($vals, $ovrBruto[$k]);
            $ovr = (int)round(hoopsInterpolar(HOOPS_ESCALA_LIGA[$liga], $p));
            unset($j['st']);
            $cartas[] = $j + [
                'pos'   => $posicoes[$k][0],
                'pos2'  => $posicoes[$k][1],
                'ovr'   => $ovr,
                'at'    => $notas[$k],
                'linha' => [ // o que a carta mostra como "a temporada dele"
                    'pts' => round($jogadores[$indices[$k]]['st']['pts'], 1),
                    'reb' => round($jogadores[$indices[$k]]['st']['reb'], 1),
                    'ast' => round($jogadores[$indices[$k]]['st']['ast'], 1),
                    'gp'  => (int)$jogadores[$indices[$k]]['st']['gp'],
                    // Os minutos reais: a simulação divide pontos, rebotes e
                    // assistências pelo que ele produz POR MINUTO.
                    'min' => round($jogadores[$indices[$k]]['st']['min'], 1),
                ],
            ];
        }
    }

    usort($cartas, fn($a, $b) => $b['ovr'] <=> $a['ovr'] ?: strcmp($a['nome'], $b['nome']));
    return $cartas;
}

/** A cor da carta pela faixa de OVR. */
function hoopsRaridade(int $ovr): string
{
    if ($ovr >= 90) return 'icone';
    if ($ovr >= 80) return 'ouro';
    if ($ovr >= 70) return 'prata';
    return 'bronze';
}
