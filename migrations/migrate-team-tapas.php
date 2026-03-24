<?php
/**
 * Migration: Adicionar coluna tapas na tabela teams
 */
require_once __DIR__ . '/backend/db.php';

echo "=== Migration: Add tapas to teams ===\n";

try {
    $pdo = db();

    $stmt = $pdo->query("SHOW COLUMNS FROM teams LIKE 'tapas'");
    $exists = $stmt->rowCount() > 0;

    if ($exists) {
        echo "✓ Coluna 'tapas' já existe na tabela teams.\n";
    } else {
        $pdo->exec("ALTER TABLE teams ADD COLUMN tapas INT NOT NULL DEFAULT 0 COMMENT 'Tapas do time'");
        echo "✓ Coluna 'tapas' adicionada à tabela teams.\n";
    }

    echo "\n=== Migration concluída com sucesso! ===\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
