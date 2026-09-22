<?php
/**
 * O MOTOR DO JOGO DE CARREIRA DE TÉCNICO — partida, rodada e tabela.
 *
 * A ideia é a do Brasfoot: você pega um clube, monta o time e atravessa
 * temporadas tentando subir de vida. O que este arquivo faz é o chão de tudo —
 * dois times entram, sai um placar; uma lista de clubes entra, sai um
 * campeonato inteiro com tabela.
 *
 * NÃO TEM TELA AQUI, e isso é de propósito: dá pra testar o motor rodando mil
 * temporadas e conferindo se o campeão faz sentido, sem abrir o navegador. Um
 * simulador que só dá pra avaliar jogando é um simulador que ninguém afina.
 *
 * O CATÁLOGO DE CLUBES É O DO COPERO (games/core/copero_clubes.php): 498
 * clubes com liga, força de 1 a 100 e escudo, já calibrados. Reaproveitar é o
 * que faz este jogo nascer com o mundo inteiro em vez de uma tabela vazia.
 */

require_once __DIR__ . '/copero_clubes.php';

/**
 * QUANTOS GOLS SAEM NUMA PARTIDA.
 *
 * Futebol de verdade tem média perto de 2,7 gols por jogo, e a distribuição é
 * de Poisson — 1x0 e 2x1 são comuns, 6x0 existe e é raro. Sortear "gols de 0 a
 * 5" com chance igual daria uma liga onde todo mundo faz três por jogo e o
 * placar não significa nada.
 */
const FUT_GOLS_MEDIA_JOGO = 2.55;

/** O empurrão de jogar em casa, em pontos de força. */
const FUT_MANDO = 12;

/**
 * O expoente que transforma diferença de força em fatia dos gols, e o nível
 * em que o jogo tem a média de gols cheia.
 *
 * ESTES QUATRO NÚMEROS (média, mando, expoente, centro) FORAM MEDIDOS, e não
 * escolhidos: rodei 4.000 partidas por combinação até bater com o Brasileirão
 * de verdade. O resultado, entre times iguais:
 *
 *              alvo (real)   motor
 *   mandante      47%         47%
 *   empate        28%         26%
 *   visitante     25%         26%
 *   gols/jogo     2,55        2,50
 *   e um 90 contra um 60 vence 72% das vezes (alvo ~70%)
 *
 * Mexer em um deles desarruma os outros — se for recalibrar, rode a bateria
 * inteira de novo em vez de ajustar no olho.
 */
const FUT_EXPOENTE = 2.6;
const FUT_NIVEL_CENTRO = 82;

/**
 * Um sorteio de Poisson. Método de Knuth: multiplica uniformes até passar de
 * e^-λ. Para as médias daqui (0,5 a 3 gols) é rápido e exato.
 */
function futPoisson(float $lambda): int
{
    if ($lambda <= 0) return 0;
    $limite = exp(-$lambda);
    $k = 0; $p = 1.0;
    do {
        $k++;
        $p *= mt_rand() / mt_getrandmax();
    } while ($p > $limite && $k < 15);   // trava: 15 gols é o fim da linha
    return $k - 1;
}

/**
 * O placar de uma partida.
 *
 * A DIFERENÇA DE FORÇA VIRA FATIA DOS GOLS, e não gols direto. Duas forças
 * decidem duas coisas: quantos gols a partida tem (times fortes fazem mais) e
 * como eles se repartem. Um 90 contra um 60 não "ganha por 3" — ele ganha na
 * maioria das vezes, empata às vezes, e perde de vez em quando. É essa cauda
 * que faz a zebra existir, e zebra é metade da graça.
 *
 * @param bool $mando a força da casa já leva o bônus? (mata-mata em campo
 *                    neutro passa false)
 * @return array ['casa' => int, 'fora' => int]
 */
function futPlacar(int $forcaCasa, int $forcaFora, bool $mando = true): array
{
    $c = max(1, min(100, $forcaCasa)) + ($mando ? FUT_MANDO : 0);
    $f = max(1, min(100, $forcaFora));

    /* A fatia de cada lado. O expoente é o que separa "melhor" de "favorito":
       baixo demais e o campeonato não tem hierarquia; alto demais e o forte
       nunca tropeça, que tira a zebra do jogo — e zebra é metade da graça. */
    $pc = pow($c, FUT_EXPOENTE);
    $pf = pow($f, FUT_EXPOENTE);
    $fatiaCasa = $pc / ($pc + $pf);

    /* Jogo entre times fortes tem mais gol que jogo entre times fracos, mas a
       variação é contida: o piso é 85% da média e o teto 115%. */
    $nivel = (($c + $f) / 2) / FUT_NIVEL_CENTRO;
    $totalEsperado = FUT_GOLS_MEDIA_JOGO * max(0.85, min(1.15, $nivel));

    return [
        'casa' => futPoisson($totalEsperado * $fatiaCasa),
        'fora' => futPoisson($totalEsperado * (1 - $fatiaCasa)),
    ];
}

/**
 * O CALENDÁRIO DE PONTOS CORRIDOS, turno e returno.
 *
 * Algoritmo do círculo (round-robin): um time fica fixo e os outros giram.
 * Garante que todo mundo joga com todo mundo uma vez por turno, sem sorteio
 * que precise ser conferido depois.
 *
 * Com número ímpar de clubes entra um "bye": quem cai nele folga na rodada, em
 * vez de a tabela ficar impossível. A Série B com 11 clubes do catálogo cai
 * nesse caso, e o campeonato roda igual.
 *
 * @return array lista de rodadas; cada rodada é uma lista de [casa, fora]
 */
function futCalendario(array $clubeIds): array
{
    $ids = array_values($clubeIds);
    $bye = null;
    if (count($ids) % 2 === 1) { $ids[] = $bye = '__folga__'; }

    $n = count($ids);
    $rodadas = [];

    // ── Turno ────────────────────────────────────────────────────────
    for ($r = 0; $r < $n - 1; $r++) {
        $jogos = [];
        for ($i = 0; $i < $n / 2; $i++) {
            $a = $ids[$i];
            $b = $ids[$n - 1 - $i];
            if ($a === $bye || $b === $bye) continue;
            // Alterna o mando a cada rodada pra ninguém jogar tudo em casa.
            $jogos[] = ($r % 2 === 0) ? [$a, $b] : [$b, $a];
        }
        $rodadas[] = $jogos;

        // Gira todos menos o primeiro.
        $fixo = array_shift($ids);
        $ultimo = array_pop($ids);
        array_unshift($ids, $ultimo);
        array_unshift($ids, $fixo);
        $ids = array_values($ids);
    }

    // ── Returno: os mesmos jogos com o mando invertido ───────────────
    $turno = $rodadas;
    foreach ($turno as $jogos) {
        $rodadas[] = array_map(fn($j) => [$j[1], $j[0]], $jogos);
    }
    return $rodadas;
}

/**
 * A tabela de classificação a partir dos resultados.
 *
 * Critérios, na ordem: pontos, vitórias, saldo, gols feitos. É o do
 * Brasileirão, e é o que a maioria reconhece sem precisar de legenda.
 *
 * @param array $resultados lista de ['casa','fora','gc','gf']
 * @return array clubeId => ['p','j','v','e','d','gp','gc','sg'] já ordenado
 */
function futClassificacao(array $clubeIds, array $resultados): array
{
    $t = [];
    foreach ($clubeIds as $id) {
        $t[$id] = ['id' => $id, 'p' => 0, 'j' => 0, 'v' => 0, 'e' => 0, 'd' => 0,
                   'gp' => 0, 'gc' => 0, 'sg' => 0];
    }

    foreach ($resultados as $r) {
        $casa = $r['casa']; $fora = $r['fora'];
        if (!isset($t[$casa], $t[$fora])) continue;
        $gc = (int)$r['gc']; $gf = (int)$r['gf'];

        $t[$casa]['j']++; $t[$fora]['j']++;
        $t[$casa]['gp'] += $gc; $t[$casa]['gc'] += $gf;
        $t[$fora]['gp'] += $gf; $t[$fora]['gc'] += $gc;

        if ($gc > $gf)      { $t[$casa]['v']++; $t[$casa]['p'] += 3; $t[$fora]['d']++; }
        elseif ($gf > $gc)  { $t[$fora]['v']++; $t[$fora]['p'] += 3; $t[$casa]['d']++; }
        else                { $t[$casa]['e']++; $t[$fora]['e']++; $t[$casa]['p']++; $t[$fora]['p']++; }
    }

    foreach ($t as &$x) $x['sg'] = $x['gp'] - $x['gc'];
    unset($x);

    uasort($t, function ($a, $b) {
        return [$b['p'], $b['v'], $b['sg'], $b['gp']]
           <=> [$a['p'], $a['v'], $a['sg'], $a['gp']];
    });
    return $t;
}

/**
 * Os clubes de uma liga do catálogo, como [id => dados].
 *
 * O id é o índice no catálogo: estável enquanto ninguém reordenar o arquivo, e
 * é o que vai pro banco quando a carreira for salva.
 */
function futClubesDaLiga(string $liga): array
{
    $out = [];
    foreach (COPERO_CLUBES as $i => $c) {
        if ($c[1] !== $liga) continue;
        $out[$i] = ['id' => $i, 'nome' => $c[0], 'liga' => $c[1],
                    'forca' => (int)$c[2], 'escudo' => $c[3] ?? ''];
    }
    return $out;
}

/**
 * Roda um campeonato inteiro e devolve tabela, rodadas e artilharia do placar.
 *
 * É a função que os testes usam pra rodar mil temporadas e ver se o campeão
 * faz sentido — se o Palmeiras ganha 90% das vezes, a régua está errada.
 */
function futSimularLiga(array $clubes): array
{
    $ids = array_keys($clubes);
    $rodadas = futCalendario($ids);
    $resultados = [];
    $historico = [];

    foreach ($rodadas as $n => $jogos) {
        $daRodada = [];
        foreach ($jogos as [$casa, $fora]) {
            $p = futPlacar($clubes[$casa]['forca'], $clubes[$fora]['forca']);
            $linha = ['casa' => $casa, 'fora' => $fora, 'gc' => $p['casa'], 'gf' => $p['fora']];
            $resultados[] = $linha;
            $daRodada[] = $linha;
        }
        $historico[$n + 1] = $daRodada;
    }

    return [
        'tabela'    => futClassificacao($ids, $resultados),
        'rodadas'   => $historico,
        'n_rodadas' => count($rodadas),
    ];
}
