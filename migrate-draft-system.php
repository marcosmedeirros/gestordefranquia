<?php
/**
 * Migração - Sistema de Draft
 * Cria as tabelas necessárias para o novo sistema de draft
 */

require_once __DIR__ . '/backend/db.php';

$pdo = db();

try {
    echo "Iniciando migração do sistema de draft...\n\n";
    
    // 1. Criar tabela draft_sessions
    echo "1. Criando tabela draft_sessions...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS draft_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            season_id INT NOT NULL,
            league ENUM('ELITE', 'NEXT', 'RISE', 'ROOKIE') NOT NULL,
            status ENUM('setup', 'in_progress', 'completed') DEFAULT 'setup',
            current_round INT DEFAULT 1,
            current_pick INT DEFAULT 1,
            total_rounds INT DEFAULT 2,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            
            INDEX idx_season (season_id),
            INDEX idx_league_status (league, status),
            
            FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   ✓ Tabela draft_sessions criada\n";
    
    // 2. Criar tabela draft_order
    echo "\n2. Criando tabela draft_order...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS draft_order (
            id INT AUTO_INCREMENT PRIMARY KEY,
            draft_session_id INT NOT NULL,
            team_id INT NOT NULL COMMENT 'Time atual dono da pick',
            original_team_id INT NOT NULL COMMENT 'Time original da pick',
            pick_position INT NOT NULL COMMENT 'Posição na rodada',
            round INT NOT NULL DEFAULT 1,
            traded_from_team_id INT NULL COMMENT 'De quem a pick foi adquirida (se foi trocada)',
            picked_player_id INT NULL COMMENT 'ID do jogador escolhido',
            picked_at DATETIME NULL,
            
            INDEX idx_session_round (draft_session_id, round, pick_position),
            INDEX idx_team (team_id),
            INDEX idx_original_team (original_team_id),
            
            FOREIGN KEY (draft_session_id) REFERENCES draft_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (original_team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (traded_from_team_id) REFERENCES teams(id) ON DELETE SET NULL,
            FOREIGN KEY (picked_player_id) REFERENCES draft_pool(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   ✓ Tabela draft_order criada\n";
    
    // 3. Verificar se draft_pool existe e adicionar colunas se necessário
    echo "\n3. Verificando tabela draft_pool...\n";
    
    $columns = $pdo->query("SHOW COLUMNS FROM draft_pool")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('drafted_by_team_id', $columns)) {
        $pdo->exec("ALTER TABLE draft_pool ADD COLUMN drafted_by_team_id INT NULL");
        $pdo->exec("ALTER TABLE draft_pool ADD CONSTRAINT fk_draft_pool_team FOREIGN KEY (drafted_by_team_id) REFERENCES teams(id) ON DELETE SET NULL");
        echo "   ✓ Coluna drafted_by_team_id adicionada\n";
    } else {
        echo "   - Coluna drafted_by_team_id já existe\n";
    }
    
    if (!in_array('draft_order', $columns)) {
        $pdo->exec("ALTER TABLE draft_pool ADD COLUMN draft_order INT NULL");
        echo "   ✓ Coluna draft_order adicionada\n";
    } else {
        echo "   - Coluna draft_order já existe\n";
    }
    
    echo "\n========================================\n";
    echo "✅ Migração concluída com sucesso!\n";
    echo "========================================\n";
    
} catch (PDOException $e) {
    echo "\n❌ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
