<?php
/**
 * Migration: Adicionar coluna ranking_points na tabela teams
 */
require_once __DIR__ . '/backend/db.php';

echo "=== Migration: Add ranking_points to teams ===\n";

try {
    $pdo = db();
    
    // Verificar se a coluna já existe
    $stmt = $pdo->query("SHOW COLUMNS FROM teams LIKE 'ranking_points'");
    $exists = $stmt->rowCount() > 0;
    
    if ($exists) {
        echo "✓ Coluna 'ranking_points' já existe na tabela teams.\n";
    } else {
        // Adicionar a coluna
        $pdo->exec("
            ALTER TABLE teams 
            ADD COLUMN ranking_points INT DEFAULT 0 COMMENT 'Pontos acumulados do ranking geral'
        ");
        echo "✓ Coluna 'ranking_points' adicionada à tabela teams.\n";
    }
    
    // Verificar/criar índice
    $stmt = $pdo->query("SHOW INDEX FROM teams WHERE Key_name = 'idx_team_ranking'");
    $indexExists = $stmt->rowCount() > 0;
    
    if (!$indexExists) {
        $pdo->exec("CREATE INDEX idx_team_ranking ON teams(league, ranking_points DESC)");
        echo "✓ Índice 'idx_team_ranking' criado.\n";
    } else {
        echo "✓ Índice 'idx_team_ranking' já existe.\n";
    }
    
    echo "\n=== Migration concluída com sucesso! ===\n";
    
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
