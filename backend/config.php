<?php
// Default config; replace values or use environment variables on Hostinger.
return [
    'db' => [
        'host' => getenv('FBA_DB_HOST') ?: 'localhost',
        'name' => getenv('FBA_DB_NAME') ?: 'u289267434_gmfba',
        'user' => getenv('FBA_DB_USER') ?: 'u289267434_gmfba',
        'pass' => getenv('FBA_DB_PASS') ?: 'Gmfba123456',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'from' => getenv('FBA_MAIL_FROM') ?: 'no-reply@example.com',
        'verify_base_url' => getenv('FBA_VERIFY_BASE_URL') ?: 'https://example.com/api/verify.php?token=',
    ],
    'app' => [
        'cap_min' => 618,
        'cap_max' => 648,
    ],
];
