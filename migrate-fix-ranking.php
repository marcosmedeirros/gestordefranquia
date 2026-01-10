<?php
/**
 * Script de migração para corrigir a tabela team_ranking_points
 * Execute uma vez para garantir que a estrutura está correta
 */

require_once __DIR__ . '/backend/db.php';
$pdo = db();

echo "<h2>🔧 Corrigindo estrutura do banco de dados...</h2>";
echo "<pre style='background:#222;color:#0f0;padding:20px;'>";

try {
    // 1. Verificar se a tabela team_ranking_points existe
    $tables = $pdo->query("SHOW TABLES LIKE 'team_ranking_points'")->fetchAll();
    
    if (count($tables) === 0) {
        echo "❌ Tabela team_ranking_points NÃO existe. Criando...\n";
        
        $pdo->exec("
            CREATE TABLE team_ranking_points (
                id INT AUTO_INCREMENT PRIMARY KEY,
                team_id INT NOT NULL,
                season_id INT NOT NULL,
                points INT NOT NULL DEFAULT 0,
                reason VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
                INDEX idx_team_season (team_id, season_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        echo "✅ Tabela team_ranking_points criada com sucesso!\n";
    } else {
        echo "✅ Tabela team_ranking_points existe.\n";
        
        // 2. Verificar colunas existentes
        $columns = $pdo->query("SHOW COLUMNS FROM team_ranking_points")->fetchAll(PDO::FETCH_COLUMN);
        echo "Colunas atuais: " . implode(', ', $columns) . "\n\n";
        
        // 3. Adicionar coluna 'points' se não existir
        if (!in_array('points', $columns)) {
            echo "❌ Coluna 'points' não existe. Adicionando...\n";
            $pdo->exec("ALTER TABLE team_ranking_points ADD COLUMN points INT NOT NULL DEFAULT 0 AFTER season_id");
            echo "✅ Coluna 'points' adicionada!\n";
        } else {
            echo "✅ Coluna 'points' já existe.\n";
        }
        
        // 4. Adicionar coluna 'reason' se não existir
        if (!in_array('reason', $columns)) {
            echo "❌ Coluna 'reason' não existe. Adicionando...\n";
            $pdo->exec("ALTER TABLE team_ranking_points ADD COLUMN reason VARCHAR(255) NULL AFTER points");
            echo "✅ Coluna 'reason' adicionada!\n";
        } else {
            echo "✅ Coluna 'reason' já existe.\n";
        }
    }
    
    // 5. Verificar tabela playoff_results
    $tables = $pdo->query("SHOW TABLES LIKE 'playoff_results'")->fetchAll();
    if (count($tables) === 0) {
        echo "\n❌ Tabela playoff_results NÃO existe. Criando...\n";
        
        $pdo->exec("
            CREATE TABLE playoff_results (
                id INT AUTO_INCREMENT PRIMARY KEY,
                season_id INT NOT NULL,
                team_id INT NOT NULL,
                position ENUM('champion','runner_up','conference_final','second_round','first_round') NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                INDEX idx_playoff_season (season_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        echo "✅ Tabela playoff_results criada!\n";
    } else {
        echo "\n✅ Tabela playoff_results existe.\n";
    }
    
    // 6. Verificar tabela season_awards
    $tables = $pdo->query("SHOW TABLES LIKE 'season_awards'")->fetchAll();
    if (count($tables) === 0) {
        echo "\n❌ Tabela season_awards NÃO existe. Criando...\n";
        
        $pdo->exec("
            CREATE TABLE season_awards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                season_id INT NOT NULL,
                team_id INT,
                award_type VARCHAR(50) NOT NULL,
                player_name VARCHAR(120) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
                INDEX idx_award_season (season_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        echo "✅ Tabela season_awards criada!\n";
    } else {
        echo "\n✅ Tabela season_awards existe.\n";
    }
    
    // 7. Verificar se existem temporadas
    echo "\n📊 Verificando temporadas...\n";
    $seasons = $pdo->query("SELECT id, league, season_number, status FROM seasons ORDER BY id DESC LIMIT 10")->fetchAll();
    
    if (count($seasons) === 0) {
        echo "⚠️ Nenhuma temporada encontrada no banco.\n";
    } else {
        echo "Temporadas encontradas:\n";
        foreach ($seasons as $s) {
            $statusIcon = $s['status'] === 'completed' ? '✅' : '🔄';
            echo "  {$statusIcon} ID:{$s['id']} | Liga:{$s['league']} | Temp:{$s['season_number']} | Status:{$s['status']}\n";
        }
    }
    
    // 8. Verificar playoff_results
    echo "\n📊 Verificando playoff_results...\n";
    $playoffs = $pdo->query("SELECT pr.*, s.league, s.season_number FROM playoff_results pr JOIN seasons s ON pr.season_id = s.id ORDER BY pr.id DESC LIMIT 10")->fetchAll();
    
    if (count($playoffs) === 0) {
        echo "⚠️ Nenhum resultado de playoff encontrado.\n";
    } else {
        echo "Resultados encontrados: " . count($playoffs) . "\n";
    }
    
    // 9. Verificar season_awards
    echo "\n📊 Verificando season_awards...\n";
    $awards = $pdo->query("SELECT * FROM season_awards ORDER BY id DESC LIMIT 10")->fetchAll();
    
    if (count($awards) === 0) {
        echo "⚠️ Nenhum prêmio encontrado.\n";
    } else {
        echo "Prêmios encontrados: " . count($awards) . "\n";
    }
    
    echo "\n</pre>";
    echo "<h3 style='color:green'>✅ Verificação concluída!</h3>";
    echo "<p><strong>Próximos passos:</strong></p>";
    echo "<ol>";
    echo "<li>Vá em <strong>Temporadas</strong> e selecione uma liga</li>";
    echo "<li>Clique em <strong>Cadastrar Histórico da Temporada</strong></li>";
    echo "<li>Preencha Campeão, Vice e os prêmios</li>";
    echo "<li>Salve - isso marca a temporada como 'completed'</li>";
    echo "<li>Depois vá em <strong>Histórico</strong> para ver os dados</li>";
    echo "</ol>";
    
} catch (PDOException $e) {
    echo "\n❌ ERRO: " . $e->getMessage() . "\n";
    echo "</pre>";
}
