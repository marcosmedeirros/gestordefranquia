<?php
/**
 * Migração para adicionar colunas secondary_position e seasons_in_league na tabela players
 */

require_once __DIR__ . '/backend/db.php';

$pdo = db();

echo "=== Migração de Colunas da Tabela Players ===\n\n";

// Verificar e adicionar secondary_position
try {
    $checkCol = $pdo->query("SHOW COLUMNS FROM players LIKE 'secondary_position'");
    if ($checkCol->rowCount() === 0) {
        echo "Adicionando coluna secondary_position...\n";
        $pdo->exec("ALTER TABLE players ADD COLUMN secondary_position VARCHAR(20) NULL AFTER position");
        echo "✓ Coluna secondary_position adicionada!\n";
    } else {
        echo "✓ Coluna secondary_position já existe.\n";
    }
} catch (Exception $e) {
    echo "✗ Erro ao adicionar secondary_position: " . $e->getMessage() . "\n";
}

// Verificar e adicionar seasons_in_league
try {
    $checkCol = $pdo->query("SHOW COLUMNS FROM players LIKE 'seasons_in_league'");
    if ($checkCol->rowCount() === 0) {
        echo "Adicionando coluna seasons_in_league...\n";
        $pdo->exec("ALTER TABLE players ADD COLUMN seasons_in_league INT DEFAULT 0 AFTER age");
        echo "✓ Coluna seasons_in_league adicionada!\n";
    } else {
        echo "✓ Coluna seasons_in_league já existe.\n";
    }
} catch (Exception $e) {
    echo "✗ Erro ao adicionar seasons_in_league: " . $e->getMessage() . "\n";
}

echo "\n=== Migração concluída! ===\n";
