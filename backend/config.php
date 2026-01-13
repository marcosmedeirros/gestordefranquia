<?php
// Default config; replace values or use environment variables on Hostinger.
return [
    'db' => [
        'host' => getenv('FBA_DB_HOST') ?: 'localhost',
    'name' => getenv('FBA_DB_NAME') ?: 'u289267434_fbabrasilbanco',
    'user' => getenv('FBA_DB_USER') ?: 'u289267434_fbabrasilbanco',
    'pass' => getenv('FBA_DB_PASS') ?: 'Fbabrasilbanco@2025',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
    'from' => getenv('FBA_MAIL_FROM') ?: 'no-reply@fbabrasil.com.br',
    'verify_base_url' => getenv('FBA_VERIFY_BASE_URL') ?: 'https://fbabrasil.com.br/api/verify.php?token=',
    ],
    'app' => [
        'cap_min' => 618,
        'cap_max' => 648,
    ],
];
