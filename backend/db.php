<?php
require_once __DIR__ . '/helpers.php';

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

    ensureSchema($pdo, $config['db']['name']);

    return $pdo;
}

function ensureSchema(PDO $pdo, string $dbName): void
{
    // Verifica se já existe a tabela principal; se não, roda o schema completo.
    $check = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1');
    $check->execute([$dbName, 'users']);
    if ($check->fetch()) {
        return;
    }

    $schemaFile = __DIR__ . '/../sql/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException('Arquivo schema.sql não encontrado.');
    }

    $sql = file_get_contents($schemaFile);
    // Executa múltiplos CREATE TABLE IF NOT EXISTS de uma vez.
    $pdo->exec($sql);
}
