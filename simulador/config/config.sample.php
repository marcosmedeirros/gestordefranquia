<?php
/**
 * Modelo de configuração — copie para config.php (produção) e/ou
 * config.local.php (override de dev local) e preencha com valores reais.
 * Esses dois arquivos NÃO são versionados (veja simulador/.gitignore).
 *
 * driver: 'mysql' (Hostinger / produção) ou 'sqlite' (teste local sem servidor)
 */
return [
    'driver' => 'mysql',

    'app_base' => '/simulador',

    'mysql' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => '',
        'user'     => '',
        'pass'     => '',
        'charset'  => 'utf8mb4',
    ],

    // Opcional: conexão somente-leitura ao banco do site principal (fbabrasil.com.br),
    // usada para permitir login com a conta do site principal dentro do /simulador.
    // Deixe null/ausente para desativar esse recurso.
    'main_mysql' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => '',
        'user'     => '',
        'pass'     => '',
        'charset'  => 'utf8mb4',
    ],

    // usado apenas quando driver = 'sqlite'
    'sqlite' => [
        'path' => __DIR__ . '/../storage/game.sqlite',
    ],
];
