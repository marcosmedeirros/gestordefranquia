<?php
require_once __DIR__ . '/backend/db.php';

try {
    $pdo = db();
    
    echo "Iniciando migração para adicionar controle de ativação/desativação de trades...\n";
    
    // Adicionar coluna trades_enabled em league_settings
    $pdo->exec("
        ALTER TABLE league_settings 
        ADD COLUMN IF NOT EXISTS trades_enabled TINYINT(1) DEFAULT 1 COMMENT 'Se 1, trades estão ativas na liga; se 0, desativadas'
    ");
    
    echo "✅ Coluna trades_enabled adicionada à tabela league_settings\n";
    echo "✅ Por padrão, todas as ligas têm trades ativas (valor 1)\n";
    echo "\nMigração concluída com sucesso!\n";
    
} catch (PDOException $e) {
    echo "❌ Erro na migração: " . $e->getMessage() . "\n";
    exit(1);
}
