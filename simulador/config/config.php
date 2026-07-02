<?php
/**
 * Configuração do banco de dados.
 *
 * driver: 'mysql' (Hostinger / produção) ou 'sqlite' (teste local sem servidor)
 *
 * Para a HOSTINGER:
 *  - Se o jogo for HOSPEDADO na Hostinger (recomendado), use host = 'localhost'.
 *  - Se você rodar o jogo no seu PC conectando à Hostinger, é preciso ativar
 *    "Remote MySQL" no hPanel e liberar o seu IP; o host será algo como
 *    'srvXXX.hstgr.io' (a Hostinger informa no painel de Bancos de Dados).
 *
 * Existe config.local.php (opcional) que sobrescreve estes valores em ambiente
 * de desenvolvimento — não suba esse arquivo para produção.
 */
return [
    'driver' => 'mysql',

    // Subdiretório onde o simulador está hospedado (ex.: /simulador).
    // Usado como prefixo para URLs absolutas como /face.php.
    'app_base' => '/simulador',

    'mysql' => [
        'host'     => 'localhost',          // veja observação acima
        'port'     => 3306,
        'database' => 'u289267434_simuladorfba',
        'user'     => 'u289267434_simuladorfba',
        'pass'     => 'Zonete@13',
        'charset'  => 'utf8mb4',
    ],

    // usado apenas quando driver = 'sqlite'
    'sqlite' => [
        'path' => __DIR__ . '/../storage/game.sqlite',
    ],
];
