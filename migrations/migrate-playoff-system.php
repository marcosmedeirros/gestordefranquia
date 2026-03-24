<?php
/**
 * Migration: Sistema de Playoffs
 * Cria as tabelas necessárias para o sistema de playoffs por conferência
 */

require_once __DIR__ . '/backend/db.php';

$pdo = db();

echo "=== Iniciando migração do sistema de playoffs ===\n\n";

try {
    // 1. Criar tabela playoff_brackets
    echo "1. Criando tabela playoff_brackets...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS playoff_brackets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            season_id INT NOT NULL,
            league VARCHAR(50) NOT NULL,
            team_id INT NOT NULL,
            conference ENUM('LESTE', 'OESTE') NOT NULL,
            seed TINYINT NOT NULL COMMENT 'Posição 1-8 na classificação',
            status ENUM('active', 'first_round', 'semifinalist', 'conference_finalist', 'runner_up', 'champion') DEFAULT 'active',
            points_earned INT DEFAULT 0 COMMENT 'Pontos de classificação: 1º=4, 2-4º=3, 5-8º=2',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            UNIQUE KEY unique_team_season (season_id, league, team_id),
            UNIQUE KEY unique_seed_conf (season_id, league, conference, seed),
            
            FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            
            INDEX idx_bracket_season (season_id, league),
            INDEX idx_bracket_conference (conference)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "   ✓ Tabela playoff_brackets criada!\n\n";
    
    // 2. Criar tabela playoff_matches
    echo "2. Criando tabela playoff_matches...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS playoff_matches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            season_id INT NOT NULL,
            league VARCHAR(50) NOT NULL,
            conference ENUM('LESTE', 'OESTE', 'FINALS') NOT NULL,
            round ENUM('first_round', 'semifinals', 'conference_finals', 'finals') NOT NULL,
            match_number TINYINT NOT NULL COMMENT 'Número da partida na rodada',
            team1_id INT NULL COMMENT 'Primeiro time (maior seed na 1ª rodada)',
            team2_id INT NULL COMMENT 'Segundo time',
            winner_id INT NULL COMMENT 'Time vencedor',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            UNIQUE KEY unique_match (season_id, league, conference, round, match_number),
            
            FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
            FOREIGN KEY (team1_id) REFERENCES teams(id) ON DELETE SET NULL,
            FOREIGN KEY (team2_id) REFERENCES teams(id) ON DELETE SET NULL,
            FOREIGN KEY (winner_id) REFERENCES teams(id) ON DELETE SET NULL,
            
            INDEX idx_match_season (season_id, league),
            INDEX idx_match_round (round)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "   ✓ Tabela playoff_matches criada!\n\n";
    
    echo "=== Migração concluída com sucesso! ===\n";
    echo "\nSistema de Pontuação:\n";
    echo "----------------------------\n";
    echo "CLASSIFICAÇÃO:\n";
    echo "  1º lugar: +4 pontos\n";
    echo "  2º-4º lugar: +3 pontos\n";
    echo "  5º-8º lugar: +2 pontos\n";
    echo "\nPLAYOFFS:\n";
    echo "  Campeão: +5 pontos\n";
    echo "  Vice-Campeão: +2 pontos\n";
    echo "  Finalista Conferência: +3 pontos\n";
    echo "  Semifinalista: +2 pontos\n";
    echo "  1ª Rodada: +1 ponto\n";
    echo "\nPRÊMIOS (+1 ponto cada):\n";
    echo "  MVP, DPOY, MIP, 6º Homem\n";
    
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
