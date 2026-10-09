<?php
/**
 * FBA HOOPS — CORREÇÃO DE OVR À MÃO. Este arquivo É editado à mão.
 *
 * A carta sai da estatística (@see games/core/hoops_notas.php), e
 * estatística não mede tudo: quem teve duas temporadas abaixo do nome que
 * tem fica com carta de coadjuvante. Aqui vai o OVR certo, pelo NOME
 * EXATO da carta. As oito notas sobem ou descem junto, na proporção.
 *
 * Depois de mexer, rode:  php games/core/hoops_importar_cli.php --offline
 */
return [
    // "Uma Ionescu com apenas 76 é surreal" — Victor, 08/10/2026. Com duas
    // temporadas na conta ela foi a 80; o resto é o nome que ela tem.
    'Sabrina Ionescu' => 86,
];
