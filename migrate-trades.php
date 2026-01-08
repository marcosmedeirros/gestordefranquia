<?php
require_once __DIR__ . '/backend/db.php';

$pdo = db();

echo "Criando tabelas de trades...\n\n";

try {
    // Criar tabela trades
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_team_id INT NOT NULL,
            to_team_id INT NOT NULL,
            status ENUM('pending', 'accepted', 'rejected', 'cancelled') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            notes TEXT,
            FOREIGN KEY (from_team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (to_team_id) REFERENCES teams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ Tabela 'trades' criada\n";
    
    // Criar tabela trade_items
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trade_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            trade_id INT NOT NULL,
            player_id INT,
            pick_id INT,
            from_team BOOLEAN DEFAULT TRUE,
            FOREIGN KEY (trade_id) REFERENCES trades(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (pick_id) REFERENCES picks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ Tabela 'trade_items' criada\n";
    
    // Criar índices
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_trades_from_team ON trades(from_team_id)");
    echo "✓ Índice idx_trades_from_team criado\n";
    
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_trades_to_team ON trades(to_team_id)");
    echo "✓ Índice idx_trades_to_team criado\n";
    
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_trades_status ON trades(status)");
    echo "✓ Índice idx_trades_status criado\n";
    
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_trade_items_trade ON trade_items(trade_id)");
    echo "✓ Índice idx_trade_items_trade criado\n";
    
    echo "\n✅ Migração concluída com sucesso!\n";
    echo "Agora você pode usar o sistema de trades.\n";
    
} catch (PDOException $e) {
    echo "❌ Erro na migração: " . $e->getMessage() . "\n";
    exit(1);
}
