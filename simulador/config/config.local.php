<?php
/**
 * Override de desenvolvimento (este PC, MySQL do XAMPP).
 * Mesmas credenciais da Hostinger, mudando apenas o host para o servidor local.
 * NÃO suba este arquivo para a Hostinger.
 */
return [
    'driver' => 'mysql',
    'mysql' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'u289267434_fbagame',
        'user'     => 'u289267434_fbagame',
        'pass'     => 'Zonete@13',
        'charset'  => 'utf8mb4',
    ],
];
