<?php
/**
 * ── FBA HOOPS: O PRÊMIO ──────────────────────────────────────────────
 *
 * Entrada de graça; o prêmio cresce com a fase a que o time chegou. Os
 * valores foram escolhidos JUNTO com a calibração (@see
 * hoops_calibrar_cli.php): um draft bem feito paga, em média, algo na casa
 * de um dia bom de minigames diários — e o título, que sai em poucas
 * temporadas a cada cem, paga como evento.
 *
 * O jogo é livre, então o que segura a economia é HOOPS_PREMIADAS_POR_DIA:
 * passadas essas temporadas no dia, dá pra continuar jogando, mas valendo só
 * pro salão. Sem o teto, quem tem tempo sobrando faria draft em série até a
 * sorte de um título.
 */

const HOOPS_PREMIO = [
    'fora'    => 0,
    'playin'  => 20,
    'r1'      => 60,
    'r2'      => 150,
    'cf'      => 300,
    'final'   => 600,
    'campeao' => 1200,
];

const HOOPS_PREMIADAS_POR_DIA = 3;
