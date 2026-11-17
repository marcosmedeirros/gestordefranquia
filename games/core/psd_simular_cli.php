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

require_once __DIR__ . '/psd_motor.php';

$opts = getopt('', ['n::', 'ver', 'semente::']);
$N    = max(1, (int)($opts['n'] ?? 200));
$VER  = isset($opts['ver']);
$SEM  = (int)($opts['semente'] ?? 20261117);

/**
 * O jogador de máquina. Não é esperto e não precisa ser: ele existe pra
 * exercitar o motor, não pra ganhar. Mas também não pode ser burro a ponto
 * de nunca trocar nem usar status, senão metade das regras nunca roda.
 *
 * Então: se o ativo caiu, entra o de mais vida. Senão, 15% das vezes troca,
 * e no resto escolhe o golpe que mais machuca pela tabela de tipos.
 */
function psdIA(array &$e, string $lado): array
{
    $vivos = psdVivos($e, $lado);
    if (!$vivos) return ['tipo' => 'nada'];

    $meu = psdAtivo($e, $lado);
    if ($meu['hp'] <= 0 || $e['lados'][$lado]['precisa_trocar']) {
        $melhor = $vivos[0]; $maisVida = -1;
        foreach ($vivos as $i) {
            if ($e['lados'][$lado]['time'][$i]['hp'] > $maisVida) {
                $maisVida = $e['lados'][$lado]['time'][$i]['hp']; $melhor = $i;
            }
        }
        return ['tipo' => 'trocar', 'i' => $melhor];
    }

    $outros = array_values(array_diff($vivos, [$e['lados'][$lado]['ativo']]));
    if ($outros && psdChance($e, 15)) {
        return ['tipo' => 'trocar', 'i' => $outros[psdSorteio($e, 0, count($outros) - 1)]];
    }

    /* UM TERÇO DAS VEZES ELA JOGA QUALQUER COISA. Parece defeito e é o
       contrário: jogando sempre o golpe mais forte, ela nunca usa status,
       e aí Substitute, Protect, Stealth Rock, Leech Seed, cura e sono
       nunca rodam na simulação — metade do motor ficaria sem teste. */
    $comPP = [];
    foreach ($meu['golpes'] as $i => $g) if ($g['pp'] > 0) $comPP[] = $i;
    if ($comPP && psdChance($e, 33)) {
        return ['tipo' => 'golpe', 'i' => $comPP[psdSorteio($e, 0, count($comPP) - 1)]];
    }

    $alvo = psdAtivo($e, psdOutro($lado));
    $melhorI = 0; $melhorNota = -1;
    foreach ($meu['golpes'] as $i => $g) {
        if ($g['pp'] <= 0) continue;
        $mv = psdGolpe($g['id']);
        if (!$mv) continue;
        if ($mv['cat'] === 'status') {
            $nota = 25;   // às vezes vale, pra o motor exercitar status
        } else {
            $ef = psdEficacia($mv['tipo'], $alvo['tipos']);
            $stab = in_array($mv['tipo'], $meu['tipos'], true) ? 1.5 : 1.0;
            $nota = $mv['power'] * $ef * $stab * ($mv['acc'] / 100);
        }
        if ($nota > $melhorNota) { $melhorNota = $nota; $melhorI = $i; }
    }
    return ['tipo' => 'golpe', 'i' => $melhorI];
}

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
        $ea = psdIA($e, 'a');
        $eb = psdIA($e, 'b');
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
