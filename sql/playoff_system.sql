-- =====================================================
-- SISTEMA DE PLAYOFFS COMPLETO
-- Criado em: 2026-01-16
-- =====================================================

-- 1. Tabela de brackets de playoffs (times classificados)
-- Armazena os 8 times classificados de cada conferência
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabela de partidas de playoffs
-- Armazena todas as partidas: 1ª rodada, semifinais, finais de conferência e finais
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SISTEMA DE PONTUAÇÃO DOS PLAYOFFS
-- =====================================================
-- 
-- CLASSIFICAÇÃO (TEMPORADA REGULAR):
-- - 1º lugar na conferência: +4 pontos
-- - 2º ao 4º lugar: +3 pontos  
-- - 5º ao 8º lugar: +2 pontos
--
-- PLAYOFFS:
-- - Eliminado na 1ª rodada: +1 ponto
-- - Eliminado nas semifinais (2ª rodada): +2 pontos
-- - Finalista da conferência (perdeu a final da conf): +3 pontos
-- - Vice-campeão (perdeu as finais): +2 pontos
-- - Campeão: +5 pontos
--
-- PRÊMIOS INDIVIDUAIS (cada um +1 ponto para o time):
-- - MVP
-- - DPOY (Defensor do Ano)
-- - MIP (Jogador que Mais Evoluiu)
-- - 6º Homem
--
-- =====================================================

-- =====================================================
-- FORMATO DO BRACKET
-- =====================================================
-- 
-- 1ª Rodada (4 jogos por conferência):
-- Jogo 1: (1) vs (8)
-- Jogo 2: (4) vs (5)
-- Jogo 3: (3) vs (6)
-- Jogo 4: (2) vs (7)
--
-- Semifinais (2 jogos por conferência):
-- Semi 1: Vencedor Jogo 1 vs Vencedor Jogo 2
-- Semi 2: Vencedor Jogo 3 vs Vencedor Jogo 4
--
-- Final da Conferência (1 jogo por conferência):
-- Vencedor Semi 1 vs Vencedor Semi 2
--
-- Finais da Liga:
-- Campeão LESTE vs Campeão OESTE
-- =====================================================
