<?php
/**
 * Migração para adicionar coluna reset_token_expiry
 * Execute acessando: https://fbabrasil.com.br/backend/migrate-reset-token.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = db();
    $config = loadConfig();
    $dbName = $config['db']['name'];
    
    $migrations = [];
    
    // Verificar se a coluna reset_token_expiry existe
    $checkColumn = $pdo->prepare("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'reset_token_expiry'
    ");
    $checkColumn->execute([$dbName]);
    
    if (!$checkColumn->fetch()) {
        try {
            $pdo->exec("
                ALTER TABLE users 
                ADD COLUMN reset_token_expiry DATETIME DEFAULT NULL AFTER reset_token
            ");
            $migrations[] = "✓ Coluna 'reset_token_expiry' adicionada à tabela 'users'";
        } catch (PDOException $e) {
            $migrations[] = "✗ Erro ao adicionar coluna reset_token_expiry: " . $e->getMessage();
        }
    } else {
        $migrations[] = "✓ Coluna 'reset_token_expiry' já existe na tabela 'users'";
    }
    
    jsonResponse(200, [
        'success' => true,
        'message' => 'Migração concluída!',
        'migrations' => $migrations
    ]);
    
} catch (Exception $e) {
    jsonResponse(500, [
        'success' => false,
        'error' => 'Erro na migração: ' . $e->getMessage()
    ]);
}
