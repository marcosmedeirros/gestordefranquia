<?php
/**
 * ── FBA SHOWDOWN: MÁQUINA CONTRA MÁQUINA ─────────────────────────────
 *
 * Roda N partidas com os dois lados jogando sozinhos e conta o que saiu.
 * Serve pra duas perguntas, e as duas só se respondem em volume:
 *
 *   1. O MOTOR QUEBRA? Vida negativa, vida acima do máximo, partida que
 *      não acaba, golpe que não existe. Uma partida na mão não acha isso;
 *      quinhentas acham.
 *
 *   2. A PARTIDA TEM TAMANHO? Se a média der 6 turnos, todo mundo morre
 *      de um golpe e não há jogo. Se der 150, ninguém termina uma.
 *
 *   php games/core/psd_simular_cli.php --n=500
 *   php games/core/psd_simular_cli.php --n=1 --ver   (narra uma partida)
 */

require_once __DIR__ . '/psd_ia.php';

$opts = getopt('', ['n::', 'ver', 'semente::']);
$N    = max(1, (int)($opts['n'] ?? 200));
$VER  = isset($opts['ver']);
$SEM  = (int)($opts['semente'] ?? 20261117);

// ─────────────────────────────── roda ───────────────────────────────

$turnos = [];
$vitorias = ['a' => 0, 'b' => 0, 'empate' => 0];
$motivos = [];
$problemas = [];
$usados = [];
$statusVistos = [];

for ($p = 0; $p < $N; $p++) {
    $e = psdNovaPartida($SEM + $p * 7919, 'A', 'B');

    $guarda = 0;
    while (!$e['fim'] && $guarda++ < PSD_TURNOS_MAX + 5) {
        $ea = psdIA($e, 'a', 33);
        $eb = psdIA($e, 'b', 33);
        psdResolverTurno($e, $ea, $eb);

        if ($VER) {
            foreach ($e['eventos'] as $ev) {
                if ($ev['t'] === 'texto') echo '   ' . $ev['m'] . "\n";
            }
            $A = psdAtivo($e, 'a'); $B = psdAtivo($e, 'b');
            printf("T%-3d  %-14s %4d/%-4d    %-14s %4d/%-4d\n",
                $e['turno'], $A['nome'], $A['hp'], $A['hpmax'], $B['nome'], $B['hp'], $B['hpmax']);
        }

        // ── invariantes: o que nunca pode acontecer ──
        foreach (['a', 'b'] as $l) {
            foreach ($e['lados'][$l]['time'] as $b) {
                if ($b['hp'] < 0)             $problemas[] = "vida negativa em {$b['nome']}";
                if ($b['hp'] > $b['hpmax'])   $problemas[] = "vida acima do teto em {$b['nome']}";
                foreach ($b['golpes'] as $g) {
                    if ($g['pp'] < 0)         $problemas[] = "PP negativo em {$b['nome']}";
                    if (!psdGolpe($g['id']))  $problemas[] = "golpe inexistente: {$g['id']}";
                }
                if ($b['status']) $statusVistos[$b['status']] = ($statusVistos[$b['status']] ?? 0) + 1;
            }
        }
    }

    if (!$e['fim']) { $problemas[] = "partida $p não terminou"; continue; }

    $turnos[] = $e['turno'];
    $vitorias[$e['fim']]++;
    $motivos[$e['motivo']] = ($motivos[$e['motivo']] ?? 0) + 1;

    foreach (['a', 'b'] as $l) {
        foreach ($e['lados'][$l]['time'] as $b) {
            foreach ($b['golpes'] as $g) $usados[$g['id']] = ($usados[$g['id']] ?? 0) + 1;
        }
    }
}

// ─────────────────────────────── conta ───────────────────────────────

sort($turnos);
$med = $turnos ? $turnos[(int)floor(count($turnos) / 2)] : 0;
$mediaT = $turnos ? array_sum($turnos) / count($turnos) : 0;

echo "\n──────── " . count($turnos) . " partidas ────────\n";
printf("turnos      média %.1f · mediana %d · menor %d · maior %d\n",
    $mediaT, $med, $turnos ? $turnos[0] : 0, $turnos ? end($turnos) : 0);
printf("vitórias    A %d (%.0f%%) · B %d (%.0f%%) · empate %d\n",
    $vitorias['a'], $turnos ? 100 * $vitorias['a'] / count($turnos) : 0,
    $vitorias['b'], $turnos ? 100 * $vitorias['b'] / count($turnos) : 0,
    $vitorias['empate']);
echo "motivo      ";
foreach ($motivos as $m => $n) echo "$m $n · ";
echo "\n";

echo "status      ";
foreach ($statusVistos as $s => $n) echo "$s ";
echo "\n";
echo "golpes      " . count($usados) . " diferentes entraram em quadra\n";

if ($problemas) {
    $u = array_count_values($problemas);
    echo "\n!! PROBLEMAS\n";
    foreach ($u as $m => $n) echo "   ($n x) $m\n";
    exit(1);
}
echo "\nsem problema de invariante.\n";
