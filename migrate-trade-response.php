<?php
/**
 * Migração: Adicionar coluna response_notes na tabela trades
 */

require_once __DIR__ . '/backend/db.php';

$pdo = db();

try {
    // Verificar se a coluna já existe
    $stmt = $pdo->query("SHOW COLUMNS FROM trades LIKE 'response_notes'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        $pdo->exec("ALTER TABLE trades ADD COLUMN response_notes TEXT NULL COMMENT 'Observação ao responder trade'");
        echo "✅ Coluna 'response_notes' adicionada com sucesso!\n";
    } else {
        echo "ℹ️ Coluna 'response_notes' já existe.\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
