<?php
/**
 * Migration: Adicionar coluna tapas_used na tabela teams
 */
require_once __DIR__ . '/backend/db.php';

echo "=== Migration: Add tapas_used to teams ===\n";

try {
    $pdo = db();

    $stmt = $pdo->query("SHOW COLUMNS FROM teams LIKE 'tapas_used'");
    $exists = $stmt->rowCount() > 0;

    if ($exists) {
        echo "OK: Coluna 'tapas_used' ja existe na tabela teams.\n";
    } else {
        $pdo->exec("ALTER TABLE teams ADD COLUMN tapas_used INT NOT NULL DEFAULT 0 COMMENT 'Tapas usados do time'");
        echo "OK: Coluna 'tapas_used' adicionada a tabela teams.\n";
    }

    echo "\n=== Migration concluida com sucesso! ===\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
