<?php
/**
 * CALIBRA A DIFICULDADE DO FBA HOOPS — roda na mão.
 *
 *   php games/core/hoops_calibrar_cli.php            (500 drafts por perfil)
 *   php games/core/hoops_calibrar_cli.php --n=2000
 *
 * O prêmio cresce com a fase, então "difícil mas não impossível" tem que
 * ser MEDIDO. Este script faz drafts de mentira com dois perfis e simula a
 * temporada de cada um:
 *
 *   esperto — em cada pacote fica com a carta que mais aumenta a força do
 *             time (química e equilíbrio incluídos). É o teto realista.
 *   casual  — fica sempre com o maior OVR, sem pensar em química.
 *   mediano — escolhe qualquer uma das cinco, ao acaso. É quem monta sem
 *             prestar atenção; esse time quase nunca deveria passar da 1ª
 *             rodada.
 *
 * E imprime: com que frequência cada perfil chega em cada fase, quantas
 * moedas o jogo paga em média por temporada, e se a liga em volta parece
 * NBA (o melhor time com ~60 vitórias, o pior com ~20).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/hoops_temporada.php';
require_once __DIR__ . '/hoops_premios.php';

$n = 500;
foreach ($argv as $a) if (preg_match('/^--n=(\d+)$/', $a, $m)) $n = (int)$m[1];

/** Um draft inteiro com um perfil de escolha. */
function draftDeMentira(string $perfil): array
{
    $time = array_fill(0, HOOPS_VAGAS, null);
    $usados = [];
    for ($v = 0; $v < HOOPS_VAGAS; $v++) {
        $opcoes = hoopsAbrirPacote($v, $usados);
        $melhor = null; $nota = -INF;
        foreach ($opcoes as $c) {
            if ($perfil === 'mediano') { $s = random_int(0, 1000); }
            elseif ($perfil === 'casual') { $s = $c['ovr']; }
            else { $t = $time; $t[$v] = $c; $s = hoopsForca($t)['forca']; }
            if ($s > $nota) { $nota = $s; $melhor = $c; }
        }
        $time[$v] = $melhor;
        $usados[] = $melhor['id'];
    }
    return $time;
}

// ── A liga sozinha: os 30 times reais parecem NBA? ────────────────────
$nba = hoopsTimesNba();
$forcas = array_column($nba, 'forca', 'sigla');
arsort($forcas);
echo "Força dos times da NBA (sem draft nenhum):\n  ";
foreach ($forcas as $s => $f) echo "$s $f  ";
echo "\n\n";

$vitorias = [];
for ($i = 0; $i < 40; $i++) {
    mt_srand(1000 + $i);
    $conf = array_map(fn($t) => $t['conf'], $nba);
    $jogos = [];
    foreach (hoopsCalendario($conf) as [$c, $f]) {
        [$pc, $pf] = hoopsJogo($forcas[$c], $forcas[$f]);
        $jogos[] = [$c, $f, $pc, $pf];
    }
    foreach (hoopsTabela(array_keys($nba), $jogos) as $s => $l) $vitorias[$s][] = $l['v'];
}
$medias = array_map(fn($l) => array_sum($l) / count($l), $vitorias);
arsort($medias);
printf("Vitórias médias: melhor %s %.0f · 5º %.0f · mediana %.0f · 26º %.0f · pior %s %.0f\n\n",
    array_key_first($medias), reset($medias), array_values($medias)[4], array_values($medias)[15],
    array_values($medias)[25], array_key_last($medias), end($medias));

// ── Os drafts ─────────────────────────────────────────────────────────
foreach (['esperto', 'casual', 'mediano'] as $perfil) {
    $fases = array_fill_keys(array_keys(HOOPS_FASES), 0);
    $vits = []; $forcasEu = []; $quims = []; $moedas = 0;
    $t0 = microtime(true);
    for ($i = 0; $i < $n; $i++) {
        $time = draftDeMentira($perfil);
        $r = hoopsTemporada($time, random_int(1, 2000000000), 'Teste', true);
        $fases[$r['fase']]++;
        $vits[] = $r['v'];
        $forcasEu[] = $r['forca']['forca'];
        $quims[] = hoopsQuimica($time)['total'];
        $moedas += HOOPS_PREMIO[$r['fase']];
    }
    sort($vits); sort($forcasEu);
    printf("── %s (%d drafts, %.1fs) ──\n", strtoupper($perfil), $n, microtime(true) - $t0);
    printf("  força do time: p10 %.1f · mediana %.1f · p90 %.1f\n",
        $forcasEu[(int)($n * .1)], $forcasEu[(int)($n * .5)], $forcasEu[(int)($n * .9)]);
    printf("  vitórias:      p10 %d · mediana %d · p90 %d\n", $vits[(int)($n * .1)], $vits[(int)($n * .5)], $vits[(int)($n * .9)]);
    $acum = 0;
    foreach (array_reverse($fases, true) as $f => $q) {
        $acum += $q;
        printf("  %-8s %5.1f%%   (chegou pelo menos aqui: %5.1f%%)\n", $f, 100 * $q / $n, 100 * $acum / $n);
    }
    printf("  moedas por temporada, em média: %.0f\n\n", $moedas / $n);
}
