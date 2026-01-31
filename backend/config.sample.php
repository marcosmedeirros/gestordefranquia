<?php
// Copy this file to config.php and adjust values for your Hostinger environment.
return [
    'db' => [
        'host' => getenv('FBA_DB_HOST') ?: 'localhost',
        'name' => getenv('FBA_DB_NAME') ?: 'fba',
        'user' => getenv('FBA_DB_USER') ?: 'root',
        'pass' => getenv('FBA_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        // Update to a real sender that your host allows (e.g., support@yourdomain.com).
        'from' => getenv('FBA_MAIL_FROM') ?: 'no-reply@example.com',
        // Base URL used in verification emails; must point to your hosted verify endpoint.
        'verify_base_url' => getenv('FBA_VERIFY_BASE_URL') ?: 'https://example.com/api/verify.php?token=',
    ],
    'app' => [
        'cap_min' => 618,
        'cap_max' => 648,
    ],
];
