<?php
/**
 * Migration: Sistema de Moedas e Leilão
 * - Adiciona coluna 'moedas' na tabela teams
 * - Cria tabela leilao_jogadores (jogadores em leilão)
 * - Cria tabela leilao_propostas (propostas de troca)
 * - Cria tabela leilao_proposta_jogadores (jogadores oferecidos)
 * - Cria tabela team_coins_log (histórico de moedas)
 */

require_once __DIR__ . '/backend/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h1>Migration: Sistema de Moedas e Leilão</h1>";

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. Adicionar coluna 'moedas' na tabela teams
    echo "<h2>1. Adicionando coluna 'moedas' na teams...</h2>";
    
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM teams LIKE 'moedas'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN moedas INT DEFAULT 0 AFTER ranking_points");
            echo "<p style='color:green'>✓ Coluna 'moedas' adicionada!</p>";
        } else {
            echo "<p style='color:orange'>⚠ Coluna 'moedas' já existe</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Erro: " . $e->getMessage() . "</p>";
    }
    
    // 2. Criar tabela leilao_jogadores
    echo "<h2>2. Criando tabela leilao_jogadores...</h2>";
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS leilao_jogadores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                player_id INT NOT NULL,
                team_id INT NOT NULL,
                league_id INT NOT NULL,
                data_inicio DATETIME NULL,
                data_fim DATETIME NULL,
                status ENUM('ativo', 'finalizado', 'cancelado') DEFAULT 'ativo',
                proposta_aceita_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_leilao_status (status),
                INDEX idx_leilao_league (league_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "<p style='color:green'>✓ Tabela leilao_jogadores criada!</p>";
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Erro: " . $e->getMessage() . "</p>";
    }
    
    // 3. Criar tabela leilao_propostas
    echo "<h2>3. Criando tabela leilao_propostas...</h2>";
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS leilao_propostas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                leilao_id INT NOT NULL,
                team_id INT NOT NULL,
                notas TEXT NULL,
                status ENUM('pendente', 'aceita', 'recusada') DEFAULT 'pendente',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_proposta_status (status),
                INDEX idx_proposta_leilao (leilao_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "<p style='color:green'>✓ Tabela leilao_propostas criada!</p>";
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Erro: " . $e->getMessage() . "</p>";
    }
    
    // 4. Criar tabela leilao_proposta_jogadores
    echo "<h2>4. Criando tabela leilao_proposta_jogadores...</h2>";
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS leilao_proposta_jogadores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                proposta_id INT NOT NULL,
                player_id INT NOT NULL,
                INDEX idx_proposta_jogador (proposta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "<p style='color:green'>✓ Tabela leilao_proposta_jogadores criada!</p>";
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Erro: " . $e->getMessage() . "</p>";
    }
    
    // 5. Criar tabela team_coins_log (histórico de moedas)
    echo "<h2>5. Criando tabela team_coins_log...</h2>";
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS team_coins_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                team_id INT NOT NULL,
                amount INT NOT NULL COMMENT 'Valor positivo ou negativo',
                reason VARCHAR(255) NOT NULL,
                admin_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_coins_team (team_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "<p style='color:green'>✓ Tabela team_coins_log criada!</p>";
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Erro: " . $e->getMessage() . "</p>";
    }
    
    echo "<h2 style='color:green'>✓ Migration concluída!</h2>";
    echo "<p><a href='dashboard.php'>Ir para Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<h2 style='color:red'>Erro Fatal: " . $e->getMessage() . "</h2>";
}
