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
        // Base URL used in password reset emails.
        'reset_base_url' => getenv('FBA_RESET_BASE_URL') ?: 'https://example.com/reset-password.php?token=',
        // SMTP settings (recommended for Hostinger and other providers).
        'smtp' => [
            'host' => getenv('FBA_SMTP_HOST') ?: '',
            'port' => getenv('FBA_SMTP_PORT') ?: 587,
            'user' => getenv('FBA_SMTP_USER') ?: '',
            'pass' => getenv('FBA_SMTP_PASS') ?: '',
            // Use 'tls' (587) or 'ssl' (465).
            'secure' => getenv('FBA_SMTP_SECURE') ?: 'tls',
        ],
    ],
    'app' => [
        'cap_min' => 618,
        'cap_max' => 648,
        'debug_reset_link' => getenv('FBA_DEBUG_RESET_LINK') ?: false,
    ],
];
