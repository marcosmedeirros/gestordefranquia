<?php
/**
 * Roda a importação dos elencos que já vêm com OVR.
 *
 *   php games/core/fut_importar_ovr_cli.php            → importa tudo
 *   php games/core/fut_importar_ovr_cli.php --conferir → só mostra, não grava
 *
 * Confere cada elenco contra a força do catálogo e avisa quem passou de 2 de
 * diferença: o elenco real MANDA na força do clube, então um elenco fora da
 * régua muda o equilíbrio da Libertadores sem ninguém perceber.
 */

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/fut_importar_ovr.php';

$sóConferir = in_array('--conferir', $argv ?? [], true);
$fonte = __DIR__ . '/../data/elencos_fonte';

$catalogo = [];
foreach (COPERO_CLUBES as $c) $catalogo[$c[0]] = (int)$c[2];
foreach (FUT_CLUBES_BR_EXTRA as $c) $catalogo[$c[0]] = (int)$c[3];

$total = 0; $fora = []; $semCatalogo = [];
foreach (array_merge(glob($fonte . '/conmebol-*.txt'), glob($fonte . '/br-ovr.txt')) as $arq) {
    $clubes = futOvrLerTexto(file_get_contents($arq));
    echo "\n=== " . basename($arq) . ' — ' . count($clubes) . " clubes ===\n";

    foreach ($clubes as $nome => $lista) {
        $forca = futForcaDoElenco($lista);
        $oficial = futOvrClubeDoCatalogo($nome);
        $alvo  = $oficial === null ? null : ($catalogo[$oficial] ?? null);
        if ($alvo !== null) { $alvo = futOvrForcaAlvo($alvo); $lista = futOvrAjustarParaForca($lista, $alvo); $forca = futForcaDoElenco($lista); }
        $dif   = $alvo === null ? null : $forca - $alvo;

        if ($alvo === null) $semCatalogo[] = $nome;
        elseif (abs($dif) > 2) $fora[] = "{$nome}: elenco {$forca}, catálogo {$alvo}";

        if (!$sóConferir && $alvo !== null) {
            $r = futOvrGravar($oficial, $lista);
            $total++;
        }
        printf("  %-26s %2d jog   força %2d   catálogo %s%s\n",
            $nome, count($lista), $forca, $alvo ?? '—',
            $dif === null ? '   <<< NÃO ACHEI NO CATÁLOGO' : ($dif === 0 ? '' : sprintf('  (%+d)', $dif)));
    }
}

echo "\n" . str_repeat('-', 60) . "\n";
echo $sóConferir ? "Nada foi gravado (--conferir).\n" : "Gravados: {$total} elencos.\n";
if ($semCatalogo) {
    echo "\nNOME QUE NÃO CASOU COM O CATÁLOGO (" . count($semCatalogo) . "):\n";
    foreach ($semCatalogo as $n) echo "  - {$n}\n";
}
if ($fora) {
    echo "\nFORA DA RÉGUA por mais de 2 (" . count($fora) . "):\n";
    foreach ($fora as $f) echo "  - {$f}\n";
}
