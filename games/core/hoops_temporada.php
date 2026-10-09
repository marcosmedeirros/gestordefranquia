<?php
/**
 * ── FBA HOOPS: A TEMPORADA ───────────────────────────────────────────
 *
 * 82 jogos de temporada regular, play-in e playoffs melhor de sete, com os
 * 29 times reais da NBA e o time do GM no lugar de um deles. Tudo sai de
 * UMA SEMENTE: a mesma semente conta a mesma temporada, jogo por jogo. É o
 * que deixa o resultado ser calculado (e pago) no servidor antes da
 * animação começar — o navegador só assiste, não decide nada.
 *
 * ── O CALENDÁRIO É O DA NBA ──────────────────────────────────────────
 *
 *   contra cada time da outra conferência ...... 2 jogos  (15 × 2 = 30)
 *   contra 10 times da própria conferência ..... 4 jogos  (10 × 4 = 40)
 *   contra os outros 4 da própria conferência .. 3 jogos  ( 4 × 3 = 12)
 *                                                          ─────────────
 *                                                               82
 *
 * Quem são os quatro de três jogos é sorteado pela semente, num anel: cada
 * time pega os dois vizinhos de cada lado. Anel garante que TODO time tenha
 * exatamente quatro — sorteio solto deixaria um com cinco e outro com três.
 *
 * ── UM JOGO ──────────────────────────────────────────────────────────
 *
 * A margem é sorteada de uma normal: a média é a diferença de força vezes
 * HOOPS_PONTOS_POR_FORCA, mais o mando; o desvio é o da NBA (~12,5 pontos
 * por jogo). O placar sai da margem e de um total em volta de 228. Empate
 * vai pra prorrogação, que sorteia de novo com a média encolhida.
 */

require_once __DIR__ . '/hoops.php';

/** Pontos de margem por ponto de força. Calibrado (@see hoops_calibrar_cli.php). */
const HOOPS_PONTOS_POR_FORCA = 2.2;
/** O teto da margem esperada entre dois times, em pontos (@see hoopsJogo). */
const HOOPS_MARGEM_TETO = 15;
/** Vantagem de jogar em casa, em pontos. A da NBA nos últimos anos. */
const HOOPS_MANDO  = 2.4;
/** Desvio da margem de um jogo. */
const HOOPS_DESVIO = 12.5;
/**
 * ENTROSAMENTO DE PLAYOFF — pedido do Victor (08/10/2026): "deixe ser
 * campeão ainda mais difícil".
 *
 * Nos playoffs (não no play-in) os times reais ganham este tanto de força,
 * e o do GM não. É o elenco que joga junto há anos contra o que foi montado
 * dez minutos atrás num pacote. Mexer aqui, e não no baralho, mantém a
 * temporada regular como estava — chegar aos playoffs continua possível pra
 * metade dos drafts bem feitos — e endurece justamente as séries, que é
 * onde o prêmio grande está.
 */
const HOOPS_ENTROSAMENTO_PLAYOFF = 1.5;

/** Pontos somados dos dois times, em média, e o desvio disso. */
const HOOPS_TOTAL_MEDIO  = 228;
const HOOPS_TOTAL_DESVIO = 16;

/** As fases, da pior pra melhor. É a ordem do prêmio. */
const HOOPS_FASES = [
    'fora'    => 'Fora dos playoffs',
    'playin'  => 'Caiu no play-in',
    'r1'      => 'Caiu na 1ª rodada',
    'r2'      => 'Caiu na semifinal de conferência',
    'cf'      => 'Caiu na final de conferência',
    'final'   => 'Vice-campeão',
    'campeao' => 'Campeão da NBA',
];

/** Sigla do time do GM dentro da temporada. */
const HOOPS_EU = 'EU';

// ─────────────────────────── sorteio com semente ───────────────────────

/** Normal padrão (Box-Muller) a partir do mt_rand já semeado. */
function hoopsNormal(): float
{
    $u1 = max(1e-12, mt_rand() / mt_getrandmax());
    $u2 = mt_rand() / mt_getrandmax();
    return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
}

function hoopsEmbaralhar(array $lista): array
{
    for ($i = count($lista) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$lista[$i], $lista[$j]] = [$lista[$j], $lista[$i]];
    }
    return $lista;
}

// ──────────────────────────────── um jogo ──────────────────────────────

/**
 * @return array{0:int,1:int,2:int} [pontos casa, pontos fora, prorrogações]
 */
function hoopsJogo(float $forcaCasa, float $forcaFora): array
{
    /* A vantagem SATURA nas diferenças grandes (tanh). Em linha reta, um
       time 10 pontos de força mais fraco perdia por 22 de média e fazia
       1-81 — nem o pior time da história da NBA é assim. Nas diferenças
       pequenas, que é onde se decidem os playoffs, a curva é praticamente
       a reta de antes. */
    $diff  = ($forcaCasa - $forcaFora) * HOOPS_PONTOS_POR_FORCA;
    $media = HOOPS_MARGEM_TETO * tanh($diff / HOOPS_MARGEM_TETO) + HOOPS_MANDO;
    $margem = (int)round($media + hoopsNormal() * HOOPS_DESVIO);
    $total  = HOOPS_TOTAL_MEDIO + hoopsNormal() * HOOPS_TOTAL_DESVIO;
    $prorr = 0;
    while ($margem === 0) {
        // Prorrogação: cinco minutos, ~1/10 de um jogo — a margem esperada
        // e o desvio encolhem junto, e o total cresce uns 10 pontos por vez.
        $prorr++;
        $total += 10;
        $margem = (int)round($media / 10 + hoopsNormal() * HOOPS_DESVIO / 3.2);
    }
    $casa = (int)round(($total + $margem) / 2);
    $fora = $casa - $margem;
    if ($fora < 70) { $fora = 70 + mt_rand(0, 8); $casa = $fora + $margem; }
    return [$casa, $fora, $prorr];
}

// ────────────────────────────── calendário ─────────────────────────────

/**
 * Os 1.230 jogos da temporada, já em ordem de calendário.
 *
 * @param array<string,string> $conf sigla → 'L' | 'O'
 * @return array<int,array{0:string,1:string}> [casa, fora]
 */
function hoopsCalendario(array $conf): array
{
    $leste = hoopsEmbaralhar(array_keys(array_filter($conf, fn($c) => $c === 'L')));
    $oeste = hoopsEmbaralhar(array_keys(array_filter($conf, fn($c) => $c === 'O')));
    $jogos = [];

    // Outra conferência: um em casa, um fora.
    foreach ($leste as $a) foreach ($oeste as $b) { $jogos[] = [$a, $b]; $jogos[] = [$b, $a]; }

    // Mesma conferência: anel de três jogos, o resto de quatro.
    foreach ([$leste, $oeste] as $grupo) {
        $n = count($grupo);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $dist = min($j - $i, $n - ($j - $i));
                [$a, $b] = [$grupo[$i], $grupo[$j]];
                $jogos[] = [$a, $b]; $jogos[] = [$b, $a];
                if ($dist <= 2) {
                    $jogos[] = mt_rand(0, 1) ? [$a, $b] : [$b, $a];
                } else {
                    $jogos[] = [$a, $b]; $jogos[] = [$b, $a];
                }
            }
        }
    }
    return hoopsEmbaralhar($jogos);
}

// ───────────────────────────── classificação ───────────────────────────

/**
 * @param array<int,array> $jogos [casa, fora, pc, pf, prorr]
 * @return array<string,array{v:int,d:int,saldo:int}>
 */
function hoopsTabela(array $siglas, array $jogos): array
{
    $t = array_fill_keys($siglas, ['v' => 0, 'd' => 0, 'saldo' => 0]);
    foreach ($jogos as [$c, $f, $pc, $pf]) {
        $t[$c]['saldo'] += $pc - $pf;
        $t[$f]['saldo'] += $pf - $pc;
        if ($pc > $pf) { $t[$c]['v']++; $t[$f]['d']++; } else { $t[$f]['v']++; $t[$c]['d']++; }
    }
    return $t;
}

/** A conferência em ordem: vitórias, depois saldo, depois sorteio fixo. */
function hoopsOrdenarConf(array $tabela, array $conf, string $qual): array
{
    $siglas = array_keys(array_filter($conf, fn($c) => $c === $qual));
    usort($siglas, fn($a, $b) => [$tabela[$b]['v'], $tabela[$b]['saldo'], $b] <=> [$tabela[$a]['v'], $tabela[$a]['saldo'], $a]);
    return $siglas;
}

// ───────────────────────────────── playoffs ────────────────────────────

/**
 * Uma série melhor de sete. Mando no formato 2-2-1-1-1: quem tem a melhor
 * campanha joga os jogos 1, 2, 5 e 7 em casa.
 *
 * @return array{a:string,b:string,jogos:array,vencedor:string,placar:array{0:int,1:int}}
 */
function hoopsSerie(string $melhor, string $pior, array $forca): array
{
    $vit = [$melhor => 0, $pior => 0];
    $jogos = [];
    $casaDe = [1 => $melhor, 2 => $melhor, 3 => $pior, 4 => $pior, 5 => $melhor, 6 => $pior, 7 => $melhor];
    for ($g = 1; $vit[$melhor] < 4 && $vit[$pior] < 4; $g++) {
        $casa = $casaDe[$g];
        $fora = $casa === $melhor ? $pior : $melhor;
        [$pc, $pf, $pr] = hoopsJogo($forca[$casa], $forca[$fora]);
        $jogos[] = [$casa, $fora, $pc, $pf, $pr];
        $vit[$pc > $pf ? $casa : $fora]++;
    }
    $venc = $vit[$melhor] === 4 ? $melhor : $pior;
    return ['a' => $melhor, 'b' => $pior, 'jogos' => $jogos, 'vencedor' => $venc,
            'placar' => [$vit[$melhor], $vit[$pior]]];
}

/** Jogo único (play-in), na casa de quem terminou melhor. */
function hoopsJogoUnico(string $casa, string $fora, array $forca): array
{
    [$pc, $pf, $pr] = hoopsJogo($forca[$casa], $forca[$fora]);
    return ['a' => $casa, 'b' => $fora, 'jogos' => [[$casa, $fora, $pc, $pf, $pr]],
            'vencedor' => $pc > $pf ? $casa : $fora, 'placar' => $pc > $pf ? [1, 0] : [0, 1]];
}

/**
 * Play-in de uma conferência: 7×8 (quem ganha é o 7º), 9×10 (quem perde
 * cai), e o perdedor do 7×8 contra o vencedor do 9×10 pela 8ª vaga.
 *
 * @return array{jogos:array, s7:string, s8:string}
 */
function hoopsPlayIn(array $ordem, array $forca): array
{
    $a = hoopsJogoUnico($ordem[6], $ordem[7], $forca);
    $b = hoopsJogoUnico($ordem[8], $ordem[9], $forca);
    $perdeuA = $a['vencedor'] === $a['a'] ? $a['b'] : $a['a'];
    $c = hoopsJogoUnico($perdeuA, $b['vencedor'], $forca);
    return ['jogos' => [$a, $b, $c], 's7' => $a['vencedor'], 's8' => $c['vencedor']];
}

/** Os mata-matas de uma conferência, de 8 times a 1. */
function hoopsChaveConf(array $seeds, array $forca): array
{
    // 1×8, 4×5, 3×6, 2×7 — nessa ordem, pra 1/8 cruzar com 4/5 na semi.
    $r1 = [];
    foreach ([[0, 7], [3, 4], [2, 5], [1, 6]] as [$i, $j]) $r1[] = hoopsSerie($seeds[$i], $seeds[$j], $forca);
    $semente = array_flip($seeds);
    $melhorDe = fn($x, $y) => $semente[$x] < $semente[$y] ? [$x, $y] : [$y, $x];
    $r2 = [
        hoopsSerie(...$melhorDe($r1[0]['vencedor'], $r1[1]['vencedor']), forca: $forca),
        hoopsSerie(...$melhorDe($r1[2]['vencedor'], $r1[3]['vencedor']), forca: $forca),
    ];
    $cf = hoopsSerie(...$melhorDe($r2[0]['vencedor'], $r2[1]['vencedor']), forca: $forca);
    return ['r1' => $r1, 'r2' => $r2, 'cf' => $cf, 'campeao' => $cf['vencedor']];
}

// ─────────────────────────── as linhas do GM ───────────────────────────

/**
 * A linha de cada jogador do GM num jogo: pontos, rebotes e assistências.
 *
 * É enfeite com fundamento: os totais do time são divididos pelo que cada
 * um produz POR MINUTO na vida real, vezes os minutos que ele joga aqui. O
 * Sengun que faz 20 pontos em Houston faz uns 20 de pivô titular, e o
 * mesmo Sengun no 11º lugar do banco quase não pontua. A primeira versão
 * dividia pela nota de arremesso, e um ala de 73 virava cestinha de 32
 * enquanto o pivô de 85 fazia 8.
 *
 * Um pouco de sorte em cima, pra o destaque não ser sempre o mesmo.
 */
function hoopsLinhasDoJogo(array $time, int $pontosTime): array
{
    $pesos = ['pts' => [], 'reb' => [], 'ast' => []];
    foreach ($time as $v => $c) {
        if (!$c) continue;
        $minReal = max(8.0, (float)($c['linha']['min'] ?? 24));
        foreach ($pesos as $k => $_) {
            $porMinuto = max(0.02, (float)$c['linha'][$k] / $minReal);
            $pesos[$k][$v] = HOOPS_MINUTOS[$v] * $porMinuto * (0.7 + mt_rand(0, 60) / 100);
        }
    }
    $totais = ['pts' => $pontosTime, 'reb' => 44 + mt_rand(-6, 6), 'ast' => 26 + mt_rand(-5, 5)];
    $linhas = [];
    foreach ($totais as $k => $total) {
        $soma = array_sum($pesos[$k]) ?: 1;
        foreach ($pesos[$k] as $v => $p) $linhas[$v][$k] = (int)round($total * $p / $soma);
    }
    return $linhas;
}

// ────────────────────────────── a temporada ────────────────────────────

/**
 * Simula a temporada inteira do GM.
 *
 * @param array $time    as doze vagas do GM, cartas completas
 * @param int   $semente a semente que conta esta temporada
 * @param bool  $leve    true pra calibração: pula as linhas de jogador
 * @param float $bonus   só o código mestre usa (@see hoops.php,
 *                       HOOPS_CODIGO_MESTRE): o time do GM joga com a força
 *                       do melhor time da liga MAIS isto, qualquer que seja
 *                       o elenco. A força MOSTRADA continua a de verdade.
 */
function hoopsTemporada(array $time, int $semente, string $nomeTime = 'Seu time', bool $leve = false, float $bonus = 0): array
{
    mt_srand($semente);

    $meuForca = hoopsForca($time);
    $nba = hoopsTimesNba(array_column(array_filter($time), 'id'));

    // O GM entra no lugar de um time sorteado — que "cede a vaga".
    $siglasNba = array_keys($nba);
    $cedeu = $siglasNba[mt_rand(0, count($siglasNba) - 1)];
    $minhaConf = $nba[$cedeu]['conf'];
    unset($nba[$cedeu]);

    $times = [];
    foreach ($nba as $s => $t) $times[$s] = array_intersect_key($t, array_flip(['sigla', 'nome', 'curto', 'logo', 'cor', 'conf', 'forca']));
    $times[HOOPS_EU] = ['sigla' => HOOPS_EU, 'nome' => $nomeTime, 'curto' => $nomeTime, 'logo' => '',
                        'cor' => '#fc0025', 'conf' => $minhaConf, 'forca' => $meuForca['forca']];
    $forca = array_map(fn($t) => (float)$t['forca'], $times);
    // O código mestre parte do MELHOR time da liga, não do próprio: assim
    // até o pior elenco possível sai campeão — "100% dos casos".
    if ($bonus > 0) $forca[HOOPS_EU] = max($forca) + $bonus;
    $conf  = array_map(fn($t) => $t['conf'], $times);

    // Temporada regular
    $jogos = [];
    $linhas = [];
    foreach (hoopsCalendario($conf) as [$c, $f]) {
        [$pc, $pf, $pr] = hoopsJogo($forca[$c], $forca[$f]);
        $jogos[] = [$c, $f, $pc, $pf, $pr];
        if (!$leve && ($c === HOOPS_EU || $f === HOOPS_EU)) {
            $linhas[count($jogos) - 1] = hoopsLinhasDoJogo($time, $c === HOOPS_EU ? $pc : $pf);
        }
    }
    $tabela = hoopsTabela(array_keys($times), $jogos);
    $ordem = ['L' => hoopsOrdenarConf($tabela, $conf, 'L'), 'O' => hoopsOrdenarConf($tabela, $conf, 'O')];
    $minhaPos = array_search(HOOPS_EU, $ordem[$minhaConf], true) + 1;

    // Play-in e playoffs das duas conferências
    $forcaPO = [];
    foreach ($forca as $s => $f) $forcaPO[$s] = $s === HOOPS_EU ? $f : $f + HOOPS_ENTROSAMENTO_PLAYOFF;
    $playin = [];
    $chave = [];
    foreach (['L', 'O'] as $cf) {
        $playin[$cf] = hoopsPlayIn($ordem[$cf], $forca);
        $seeds = array_merge(array_slice($ordem[$cf], 0, 6), [$playin[$cf]['s7'], $playin[$cf]['s8']]);
        $chave[$cf] = hoopsChaveConf($seeds, $forcaPO);
        $chave[$cf]['seeds'] = $seeds;
    }
    $finalistas = [$chave['L']['campeao'], $chave['O']['campeao']];
    usort($finalistas, fn($a, $b) => [$tabela[$b]['v'], $tabela[$b]['saldo']] <=> [$tabela[$a]['v'], $tabela[$a]['saldo']]);
    $final = hoopsSerie($finalistas[0], $finalistas[1], $forcaPO);

    // Até onde o GM chegou
    $fase = 'fora';
    if ($minhaPos >= 7 && $minhaPos <= 10) $fase = 'playin';
    $minhaChave = $chave[$minhaConf];
    if (in_array(HOOPS_EU, $minhaChave['seeds'], true)) {
        $fase = 'r1';
        foreach ($minhaChave['r1'] as $s) if ($s['vencedor'] === HOOPS_EU) $fase = 'r2';
        foreach ($minhaChave['r2'] as $s) if ($s['vencedor'] === HOOPS_EU) $fase = 'cf';
        if ($minhaChave['campeao'] === HOOPS_EU) $fase = $final['vencedor'] === HOOPS_EU ? 'campeao' : 'final';
    }

    // As médias da temporada do elenco
    $medias = [];
    if (!$leve) {
        $n = count($linhas);
        foreach ($linhas as $porVaga) foreach ($porVaga as $v => $l) {
            foreach ($l as $k => $x) $medias[$v][$k] = ($medias[$v][$k] ?? 0) + $x / max(1, $n);
        }
        foreach ($medias as $v => $m) $medias[$v] = array_map(fn($x) => round($x, 1), $m);
    }

    return [
        'semente'   => $semente,
        'cedeu'     => ['sigla' => $cedeu, 'nome' => $nba[$cedeu]['nome'] ?? hoopsDados()['times']['NBA|' . $cedeu]['nome'] ?? $cedeu],
        'conf'      => $minhaConf,
        'times'     => $times,
        'forca'     => $meuForca,
        'jogos'     => $jogos,
        'linhas'    => $linhas,
        'tabela'    => $tabela,
        'ordem'     => $ordem,
        'posicao'   => $minhaPos,
        'v'         => $tabela[HOOPS_EU]['v'],
        'd'         => $tabela[HOOPS_EU]['d'],
        'playin'    => $playin,
        'chave'     => $chave,
        'final'     => $final,
        'campeao'   => $final['vencedor'],
        'fase'      => $fase,
        'medias'    => $medias,
    ];
}
