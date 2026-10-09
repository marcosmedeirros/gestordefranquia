<?php
/**
 * SIMULA PARTIDAS DE LENDAS DA QUADRA — roda na mão.
 *
 *   php games/core/lendas_simular_cli.php            (300 partidas por cenário)
 *   php games/core/lendas_simular_cli.php --n=1000
 *
 * Máquina contra máquina (nível 3), pra medir o que só dá pra medir
 * jogando: quanto dura uma partida, se quem começa leva vantagem, se um
 * baralho melhorado nos pacotes pesa o quanto deveria, e se algum
 * arquétipo virou a resposta pra tudo.
 *
 * O TEMPO em minutos é estimativa: cada turno humano leva uns 15 segundos
 * de leitura mais ~9 por ação (carta, movimento, ataque — no máximo uma de
 * cada), com o teto de 75 do relógio. A meta do desenho é de 20 a 30 min.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/lendas_ia.php';

$n = 300;
foreach ($argv as $a) if (preg_match('/^--n=(\d+)$/', $a, $m)) $n = (int)$m[1];

/** Um baralho "de quem já abriu uns dez pacotes": raras e épicas no meio. */
function baralhoEvoluido(int $semente): array
{
    mt_srand($semente);
    $cat = lendasCatalogo();
    // Com curva de custo, como um jogador de verdade montaria: baratos pro
    // começo, raras no meio, épicas pro fim.
    $herois = ['barato' => [], 'rara' => [], 'epica' => []];
    $efeitos = ['comum' => [], 'rara' => [], 'epica' => []];
    foreach ($cat as $c) {
        if ($c['classe'] === 'heroi') {
            if ($c['rar'] === 'comum' && $c['custo'] <= 2) $herois['barato'][] = $c['id'];
            elseif (in_array($c['rar'], ['rara', 'epica'], true)) $herois[$c['rar']][] = $c['id'];
        } elseif (isset($efeitos[$c['rar']])) $efeitos[$c['rar']][] = $c['id'];
    }
    $pega = function (array $l, int $q) { shuffle($l); return array_slice($l, 0, $q); };
    $b = array_merge($pega($herois['barato'], 4), $pega($herois['rara'], 8), $pega($herois['epica'], 4),
                     $pega($efeitos['comum'], 4), $pega($efeitos['rara'], 7), $pega($efeitos['epica'], 3));
    mt_srand();
    return $b;
}

function partida(array $baralhoA, array $baralhoB, int $semente): array
{
    $e = lendasNovaPartida(['uid' => 1, 'nome' => 'A', 'baralho' => $baralhoA, 'ia' => true],
                           ['uid' => 2, 'nome' => 'B', 'baralho' => $baralhoB, 'ia' => true], $semente);
    $acoes = ['A' => [], 'B' => []];
    while (!$e['fim']) {
        $lado = $e['vez'];
        $antes = $e['seq'];
        lendasIaTurno($e, 3);
        // Ações humanas do turno ≈ eventos de ação (entrou, efeito, moveu, ataque).
        $qtd = 0;
        foreach ($e['eventos'] as $ev) if ($ev['seq'] > $antes && in_array($ev['tipo'], ['entrou', 'efeito', 'moveu', 'ataque'], true)) $qtd++;
        $acoes[$lado][] = $qtd;
    }
    $segundos = 0;
    foreach ($acoes as $lista) foreach ($lista as $q) $segundos += min(75, 15 + 9 * $q);
    return ['vencedor' => $e['vencedor'], 'motivo' => $e['motivo'], 'turnos' => $e['turno'],
            'minutos' => $segundos / 60, 'fortA' => $e['jogadores']['A']['fortaleza'], 'fortB' => $e['jogadores']['B']['fortaleza']];
}

function relatorio(string $titulo, array $res): void
{
    $n = count($res);
    $vA = count(array_filter($res, fn($r) => $r['vencedor'] === 'A'));
    $turnos = array_column($res, 'turnos'); sort($turnos);
    $min = array_column($res, 'minutos'); sort($min);
    $motivos = array_count_values(array_column($res, 'motivo'));
    printf("── %s (%d partidas) ──\n", $titulo, $n);
    printf("  A vence: %.0f%%   ·   motivos: %s\n", 100 * $vA / $n, json_encode($motivos));
    printf("  turnos (soma dos dois): p10 %d · mediana %d · p90 %d\n", $turnos[(int)($n * .1)], $turnos[(int)($n * .5)], $turnos[(int)($n * .9)]);
    printf("  minutos estimados:      p10 %.0f · mediana %.0f · p90 %.0f\n\n", $min[(int)($n * .1)], $min[(int)($n * .5)], $min[(int)($n * .9)]);
}

$t0 = microtime(true);
$r = [];
for ($i = 0; $i < $n; $i++) $r[] = partida(lendasBaralhoInicial(100 + $i), lendasBaralhoInicial(5000 + $i), random_int(1, 2e9));
relatorio('Inicial × Inicial', $r);

$r = [];
for ($i = 0; $i < $n; $i++) $r[] = partida(baralhoEvoluido(100 + $i), baralhoEvoluido(5000 + $i), random_int(1, 2e9));
relatorio('Evoluído × Evoluído', $r);

$r = [];
for ($i = 0; $i < $n; $i++) {
    // Metade das vezes o evoluído começa, metade não — o "A vence" aqui é o evoluído.
    if ($i % 2) { $x = partida(baralhoEvoluido(100 + $i), lendasBaralhoInicial(5000 + $i), random_int(1, 2e9)); }
    else { $x = partida(lendasBaralhoInicial(5000 + $i), baralhoEvoluido(100 + $i), random_int(1, 2e9)); $x['vencedor'] = $x['vencedor'] === 'A' ? 'B' : 'A'; }
    $r[] = $x;
}
relatorio('Evoluído (A) × Inicial (B), lados alternados', $r);

// Qual arquétipo mais causa estrago? Taxa de vitória de baralhos inclinados a cada um.
echo "── Baralho inclinado a um arquétipo × inicial (lados alternados) ──\n";
$cat = lendasCatalogo();
foreach (array_keys(LENDAS_ARQUETIPOS) as $tipo) {
    $base = array_values(array_filter($cat, fn($c) => $c['classe'] === 'heroi' && $c['rar'] === 'comum' && $c['tipo'] === $tipo));
    $vit = 0; $m = intdiv($n, 2);
    for ($i = 0; $i < $m; $i++) {
        $b = lendasBaralhoInicial(900 + $i);
        // troca os 16 heróis por 16 do arquétipo, mesma faixa de custo
        $doTipo = array_column(array_filter($base, fn($c) => $c['custo'] <= 3), 'id');
        shuffle($doTipo);
        for ($k = 0; $k < 16 && $k < count($doTipo); $k++) $b[$k] = $doTipo[$k];
        $x = $i % 2 ? partida($b, lendasBaralhoInicial(7000 + $i), random_int(1, 2e9))
                    : partida(lendasBaralhoInicial(7000 + $i), $b, random_int(1, 2e9));
        if (($i % 2 && $x['vencedor'] === 'A') || (!($i % 2) && $x['vencedor'] === 'B')) $vit++;
    }
    printf("  %-13s %.0f%%  (%d heróis comuns de custo ≤3 no catálogo)\n", $tipo, 100 * $vit / $m, count(array_filter($base, fn($c) => $c['custo'] <= 3)));
}
printf("\n(%.1fs)\n", microtime(true) - $t0);
