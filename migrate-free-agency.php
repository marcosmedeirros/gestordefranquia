<?php
/**
 * Migração para criar sistema de Free Agency
 */

require_once __DIR__ . '/backend/db.php';

$pdo = db();

echo "=== Migração de Free Agency ===\n\n";

// Criar tabela free_agents
try {
    $result = $pdo->query("SHOW TABLES LIKE 'free_agents'");
    if ($result->rowCount() === 0) {
        echo "Criando tabela free_agents...\n";
        $pdo->exec("
            CREATE TABLE free_agents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                age INT NOT NULL,
                position VARCHAR(20) NOT NULL,
                secondary_position VARCHAR(20) NULL,
                ovr INT NOT NULL,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
                original_team_id INT NULL,
                original_team_name VARCHAR(120) NULL,
                waived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                season_id INT NULL,
                INDEX idx_fa_league (league),
                INDEX idx_fa_season (season_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "✓ Tabela free_agents criada!\n";
    } else {
        echo "✓ Tabela free_agents já existe.\n";
    }
} catch (Exception $e) {
    echo "✗ Erro ao criar tabela free_agents: " . $e->getMessage() . "\n";
}

// Criar tabela free_agent_offers
try {
    $result = $pdo->query("SHOW TABLES LIKE 'free_agent_offers'");
    if ($result->rowCount() === 0) {
        echo "Criando tabela free_agent_offers...\n";
        $pdo->exec("
            CREATE TABLE free_agent_offers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                free_agent_id INT NOT NULL,
                team_id INT NOT NULL,
                amount INT NOT NULL DEFAULT 0,
                notes TEXT NULL,
                status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (free_agent_id) REFERENCES free_agents(id) ON DELETE CASCADE,
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                UNIQUE KEY unique_offer (free_agent_id, team_id),
                INDEX idx_fao_status (status),
                INDEX idx_fao_team (team_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "✓ Tabela free_agent_offers criada!\n";
    } else {
        echo "✓ Tabela free_agent_offers já existe.\n";
    }
} catch (Exception $e) {
    echo "✗ Erro ao criar tabela free_agent_offers: " . $e->getMessage() . "\n";
}

try {
    $checkCol = $pdo->query("SHOW COLUMNS FROM free_agent_offers LIKE 'amount'");
    if ($checkCol->rowCount() === 0) {
        echo "Adicionando coluna amount em free_agent_offers...\n";
        $pdo->exec("ALTER TABLE free_agent_offers ADD COLUMN amount INT NOT NULL DEFAULT 0 AFTER team_id");
        echo "✓ Coluna amount adicionada!\n";
    }
} catch (Exception $e) {
    echo "Aviso: " . $e->getMessage() . "\n";
}

// Adicionar colunas na tabela teams
echo "\nVerificando colunas na tabela teams...\n";

try {
    $checkCol = $pdo->query("SHOW COLUMNS FROM teams LIKE 'waivers_used'");
    if ($checkCol->rowCount() === 0) {
        echo "Adicionando coluna waivers_used...\n";
        $pdo->exec("ALTER TABLE teams ADD COLUMN waivers_used INT DEFAULT 0");
        echo "✓ Coluna waivers_used adicionada!\n";
    } else {
        echo "✓ Coluna waivers_used já existe.\n";
    }
} catch (Exception $e) {
    echo "Aviso: " . $e->getMessage() . "\n";
}

try {
    $checkCol = $pdo->query("SHOW COLUMNS FROM teams LIKE 'fa_signings_used'");
    if ($checkCol->rowCount() === 0) {
        echo "Adicionando coluna fa_signings_used...\n";
        $pdo->exec("ALTER TABLE teams ADD COLUMN fa_signings_used INT DEFAULT 0");
        echo "✓ Coluna fa_signings_used adicionada!\n";
    } else {
        echo "✓ Coluna fa_signings_used já existe.\n";
    }
} catch (Exception $e) {
    echo "Aviso: " . $e->getMessage() . "\n";
}

echo "\n=== Migração concluída! ===\n";
echo "\nAgora você pode usar o sistema de Free Agency:\n";
echo "- Jogadores podem dispensar até 3 jogadores por temporada\n";
echo "- Jogadores dispensados vão para Free Agency\n";
echo "- Times podem enviar propostas para contratá-los\n";
echo "- Admin decide qual time contrata cada jogador\n";
echo "- Cada time pode contratar até 3 jogadores via FA\n";
echo "- O botão Resetar limpa tudo (free agents + contadores)\n";
