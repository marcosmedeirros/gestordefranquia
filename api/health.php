<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json');

$status = [
    'success' => false,
    'checks' => [],
];

function addCheck(&$status, $name, $ok, $details = null) {
    $status['checks'][] = [
        'name' => $name,
        'ok' => (bool)$ok,
        'details' => $details,
    ];
}

try {
    require_once __DIR__ . '/../backend/helpers.php';
    require_once __DIR__ . '/../backend/db.php';

    // Config
    $cfgOk = false;
    try {
        $config = loadConfig();
        $cfgOk = isset($config['db']['host'], $config['db']['name'], $config['db']['user']);
        addCheck($status, 'config', $cfgOk, $cfgOk ? 'OK' : 'backend/config.php ausente/inválido');
    } catch (Exception $e) {
        addCheck($status, 'config', false, $e->getMessage());
    }

    // DB connection
    $pdo = null;
    try {
        $pdo = db();
        addCheck($status, 'db_connection', true, 'OK');
    } catch (Exception $e) {
        addCheck($status, 'db_connection', false, $e->getMessage());
        echo json_encode($status);
        exit;
    }

    // Tables/columns
    try {
        $hasUsers = $pdo->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;
        addCheck($status, 'table_users', $hasUsers, $hasUsers ? 'OK' : 'Tabela ausente');

        if ($hasUsers) {
            $columns = ['email','password_hash','user_type','league','email_verified'];
            foreach ($columns as $col) {
                $colOk = $pdo->query("SHOW COLUMNS FROM users LIKE '" . $col . "'")->rowCount() > 0;
                addCheck($status, 'users.' . $col, $colOk, $colOk ? 'OK' : 'Coluna ausente');
            }
        }
    } catch (Exception $e) {
        addCheck($status, 'schema_check', false, $e->getMessage());
    }

    $status['success'] = true;
    echo json_encode($status);
    exit;
} catch (Throwable $e) {
    addCheck($status, 'fatal', false, $e->getMessage());
    echo json_encode($status);
    exit;
}
