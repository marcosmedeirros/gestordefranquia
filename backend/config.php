<?php
// Default config; replace values or use environment variables on Hostinger.
return [
    'db' => [
        'host' => getenv('FBA_DB_HOST') ?: 'localhost',
        'name' => getenv('FBA_DB_NAME') ?: 'fba',
        'user' => getenv('FBA_DB_USER') ?: 'root',
        'pass' => getenv('FBA_DB_PASS') ?: '',
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
