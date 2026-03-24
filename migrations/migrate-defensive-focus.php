<?php
/**
 * Migração: Adicionar coluna defensive_focus
 * Data: 2026-01-15
 */

require_once __DIR__ . '/backend/config.php';
require_once __DIR__ . '/backend/db.php';

try {
    // Verificar se a coluna já existe
    $stmt = $pdo->query("SHOW COLUMNS FROM team_directives LIKE 'defensive_focus'");
    if ($stmt->rowCount() > 0) {
        echo "Coluna defensive_focus já existe.\n";
    } else {
        // Adicionar coluna defensive_focus
        $pdo->exec("
            ALTER TABLE team_directives ADD COLUMN defensive_focus ENUM(
                'neutral',
                'protect_paint',
                'limit_perimeter',
                'no_preference'
            ) DEFAULT 'no_preference' COMMENT 'Foco defensivo' AFTER offensive_aggression
        ");
        echo "Coluna defensive_focus adicionada com sucesso!\n";
    }
    
    echo "\nMigração concluída com sucesso!\n";
} catch (PDOException $e) {
    echo "Erro na migração: " . $e->getMessage() . "\n";
    exit(1);
}
