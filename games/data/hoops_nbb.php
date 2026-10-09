<?php
/**
 * FBA HOOPS — OS JOGADORES DO NBB. Este arquivo É editado à mão.
 *
 * O NBB entra com uma lista fechada, escolhida pelo Victor em 09/10/2026:
 * os destaques da temporada 2025/26. Os OUTROS jogadores da liga também são
 * baixados, mas só servem de régua pras notas (percentil dentro do NBB) —
 * não viram carta.
 *
 *   'slug'  — o endereço do atleta em lnb.com.br/atletas/<slug>/
 *   'nome'  — como aparece na carta (o nome pelo qual a liga conhece)
 *   'piso'  — OVR MÍNIMO. Se a estatística der mais, vale a estatística;
 *             se der menos, a carta sobe até o piso e as notas acompanham.
 *
 * Pisos pedidos pelo Victor: do 1º ao 5º, 85; do 6º ao 11º, 82; do 12º ao
 * 20º, 78 — com exceção do Davaunta Thomas, que é 85.
 *
 * Depois de mexer, rode:  php games/core/hoops_importar_cli.php
 */
return [
    // O quinteto ideal da temporada
    ['slug' => 'george-lucas-alves-de-paula',       'nome' => 'Georginho de Paula', 'piso' => 85],
    ['slug' => 'elyjah-cyres-clark',                'nome' => 'Elyjah Clark',       'piso' => 85],
    ['slug' => 'david-wayne-jackson-jr',            'nome' => 'David Jackson',      'piso' => 85],
    ['slug' => 'lucas-dias-silva',                  'nome' => 'Lucas Dias',         'piso' => 85],
    ['slug' => 'andre-felipe-barbosa-de-abreu',     'nome' => 'Andrezão',           'piso' => 85],
    // Destaques individuais
    ['slug' => 'david-lee-sloan-jr',                'nome' => 'David Sloan',        'piso' => 82],
    ['slug' => 'elio-corazza-neto',                 'nome' => 'Elinho Corazza',     'piso' => 82],
    ['slug' => 'alex-negrete',                      'nome' => 'Alex Negrete',       'piso' => 82],
    ['slug' => 'felipe-gregate-pontara-borges-de-carvalho', 'nome' => 'Felipe Gregate', 'piso' => 82],
    ['slug' => 'matheus-da-silva-brito',            'nome' => 'Matheusinho',        'piso' => 82],
    ['slug' => 'vitor-da-silva-brandao',            'nome' => 'Vitinho Brandão',    'piso' => 82],
    // Líderes de desempenho
    ['slug' => 'anthony-james-harris',              'nome' => 'Anthony Harris',     'piso' => 78],
    ['slug' => 'dontrell-chaquan-brite',            'nome' => 'Dontrell Brite',     'piso' => 78],
    ['slug' => 'daniel-von-haydin',                 'nome' => 'Daniel Von Haydin',  'piso' => 78],
    ['slug' => 'davaunta-latrae-thomas',            'nome' => 'Davaunta Thomas',    'piso' => 85],
    ['slug' => 'jordan-charles-williams',           'nome' => 'Jordan Williams',    'piso' => 78],
    ['slug' => 'alexey-thiago-pereira-borges',      'nome' => 'Alexey Borges',      'piso' => 78],
    ['slug' => 'tulio-henrique-da-silva',           'nome' => 'Túlio Da Silva',     'piso' => 78],
    ['slug' => 'maicon-douglas-da-silva-waldemar',  'nome' => 'Maicon Douglas',     'piso' => 78],
    ['slug' => 'fabricio-da-silva-verissimo',       'nome' => 'Fabrício Veríssimo', 'piso' => 78],
];
