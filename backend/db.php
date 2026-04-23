<?php
require_once __DIR__ . '/helpers.php';

// Define timezone padrão para todo o sistema: São Paulo/Brasília
date_default_timezone_set('America/Sao_Paulo');

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = loadConfig();
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['db']['host'], $config['db']['name'], $config['db']['charset']);
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Definir timezone no MySQL também
    $pdo->exec("SET time_zone = '-03:00'");

    ensureSchema($pdo, $config['db']['name']);

    return $pdo;
}

function dbGames(): ?PDO
{
    static $pdoGames = null;
    if ($pdoGames instanceof PDO) {
        return $pdoGames;
    }

    $config = loadConfig();
    $gamesConfig = $config['games_db'] ?? null;
    if (!is_array($gamesConfig)) {
        return null;
    }

    $host = $gamesConfig['host'] ?? '';
    $name = $gamesConfig['name'] ?? '';
    $user = $gamesConfig['user'] ?? '';
    $pass = $gamesConfig['pass'] ?? '';
    $charset = $gamesConfig['charset'] ?? 'utf8mb4';

    if ($host === '' || $name === '' || $user === '') {
        return null;
    }

    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);
        $pdoGames = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdoGames;
    } catch (Exception $e) {
        return null;
    }
}

function ensureSchema(PDO $pdo, string $dbName): void
{
    // Carrega e executa migrações automáticas
    require_once __DIR__ . '/migrations.php';
    
    try {
        runMigrations();
    } catch (Exception $e) {
        error_log('Erro ao executar migrações: ' . $e->getMessage());
    }
}
