<?php
/**
 * Põe os elencos que já existiam na mesma régua dos novos.
 *
 *   php games/core/fut_reescalar_cli.php [--conferir]
 *
 * Os elencos brasileiros vieram do importador que calcula o OVR pelo valor de
 * mercado, na escala do catálogo do Copero — onde o Flamengo é 92 e tem
 * titular de 91. A escala do jogo de carreira é outra: ninguém passa de 89 e o
 * topo é 85 (@see futOvrForcaAlvo). Sem passar por aqui, o Brasil ficaria numa
 * régua e a CONMEBOL em outra, e o River Plate acabaria mais forte que o
 * Flamengo.
 *
 * Desloca o elenco inteiro pelo mesmo número, então a hierarquia de dentro
 * dele não muda.
 */

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/fut_importar_ovr.php';

$sóConferir = in_array('--conferir', $argv ?? [], true);

$catalogo = [];
foreach (COPERO_CLUBES as $c) $catalogo[$c[0]] = (int)$c[2];
// O catálogo brasileiro extra também vale: é de lá que vem o clube pequeno.
foreach (FUT_CLUBES_BR_EXTRA as $c) $catalogo[$c[0]] = (int)$c[3];

$mexidos = 0; $intactos = 0; $semCatalogo = [];
foreach (glob(__DIR__ . '/../data/elencos/*.php') as $arq) {
    $slug  = basename($arq, '.php');
    $lista = require $arq;
    if (!is_array($lista) || !$lista) continue;

    // Do slug de volta pro nome: é o único caminho, o arquivo não guarda o nome.
    $nome = null;
    foreach (array_keys($catalogo) as $cand) {
        if (futSlugDoClube($cand) === $slug) { $nome = $cand; break; }
    }
    if ($nome === null) { $semCatalogo[] = $slug; continue; }

    $antes = futForcaDoElenco($lista);
    $alvo  = futOvrForcaAlvo($catalogo[$nome]);
    if ($antes === $alvo) { $intactos++; continue; }

    $lista = futOvrAjustarParaForca($lista, $alvo);
    $depois = futForcaDoElenco($lista);
    printf("  %-24s %d -> %d  (alvo %d)\n", $nome, $antes, $depois, $alvo);

    if (!$sóConferir) futOvrGravar($nome, $lista);
    $mexidos++;
}

echo "\n" . ($sóConferir ? 'Mexeria em' : 'Reescalados:') . " {$mexidos} elencos, {$intactos} já estavam na régua.\n";
if ($semCatalogo) {
    echo "\nSem clube no catálogo (" . count($semCatalogo) . "): " . implode(', ', $semCatalogo) . "\n";
}
