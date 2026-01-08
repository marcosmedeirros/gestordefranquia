-- Sistema de Temporadas e Sprints

-- 1. Tabela de Sprints (ciclos de temporadas por liga)
CREATE TABLE IF NOT EXISTS sprints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    league ENUM('ELITE', 'NEXT', 'RISE', 'ROOKIE') NOT NULL,
    sprint_number INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    status ENUM('active', 'completed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_league_sprint (league, sprint_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabela de Temporadas (seasons dentro de cada sprint)
CREATE TABLE IF NOT EXISTS seasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sprint_id INT NOT NULL,
    league ENUM('ELITE', 'NEXT', 'RISE', 'ROOKIE') NOT NULL,
    season_number INT NOT NULL COMMENT 'Número da temporada dentro do sprint',
    year INT NOT NULL COMMENT 'Ano civil da temporada',
    start_date DATE NOT NULL,
    end_date DATE,
    status ENUM('draft', 'regular', 'playoffs', 'completed') DEFAULT 'draft',
    current_phase VARCHAR(50) DEFAULT 'draft' COMMENT 'draft, regular_season, playoffs, finals',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
    INDEX idx_league_season (league, season_number),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabela de Draft Pool (jogadores disponíveis para draft)
CREATE TABLE IF NOT EXISTS draft_pool (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    position ENUM('Armador', 'Ala-Armador', 'Ala', 'Ala-Pivô', 'Pivô') NOT NULL,
    age INT NOT NULL,
    ovr INT NOT NULL CHECK (ovr >= 1 AND ovr <= 99),
    photo_url VARCHAR(255),
    bio TEXT,
    strengths TEXT COMMENT 'Pontos fortes do jogador',
    weaknesses TEXT COMMENT 'Pontos fracos do jogador',
    draft_status ENUM('available', 'drafted') DEFAULT 'available',
    drafted_by_team_id INT,
    draft_order INT COMMENT 'Ordem em que foi draftado',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    FOREIGN KEY (drafted_by_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    INDEX idx_season_status (season_id, draft_status),
    INDEX idx_draft_order (draft_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabela de Classificação da Temporada Regular
CREATE TABLE IF NOT EXISTS season_standings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT NOT NULL,
    team_id INT NOT NULL,
    wins INT DEFAULT 0,
    losses INT DEFAULT 0,
    points_for INT DEFAULT 0,
    points_against INT DEFAULT 0,
    position INT COMMENT 'Posição final na temporada regular',
    conference VARCHAR(50) COMMENT 'Conferência do time',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    UNIQUE KEY unique_season_team (season_id, team_id),
    INDEX idx_season_position (season_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabela de Resultados de Playoffs
CREATE TABLE IF NOT EXISTS playoff_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT NOT NULL,
    team_id INT NOT NULL,
    round ENUM('first_round', 'second_round', 'conference_finals', 'finals') NOT NULL,
    result ENUM('eliminated', 'advanced', 'runner_up', 'champion') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    INDEX idx_season_team (season_id, team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabela de Premiações (MVP, DPOY, MIP, 6TH MAN)
CREATE TABLE IF NOT EXISTS season_awards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT NOT NULL,
    award_type ENUM('mvp', 'dpoy', 'mip', '6th_man', 'champion', 'runner_up') NOT NULL,
    team_id INT NOT NULL,
    player_name VARCHAR(100) COMMENT 'Nome do jogador premiado (se aplicável)',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    INDEX idx_season_award (season_id, award_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabela de Pontos do Ranking (acumulativo, nunca reseta)
CREATE TABLE IF NOT EXISTS team_ranking_points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    season_id INT NOT NULL,
    league ENUM('ELITE', 'NEXT', 'RISE', 'ROOKIE') NOT NULL,
    
    -- Pontos da Temporada Regular
    regular_season_position INT,
    regular_season_points INT DEFAULT 0 COMMENT '1º=4pts, 2º-4º=3pts, 5º-8º=2pts',
    
    -- Pontos dos Playoffs
    playoff_first_round BOOLEAN DEFAULT FALSE COMMENT '+1 ponto',
    playoff_second_round BOOLEAN DEFAULT FALSE COMMENT '+2 pontos',
    playoff_conference_finals BOOLEAN DEFAULT FALSE COMMENT '+3 pontos',
    playoff_runner_up BOOLEAN DEFAULT FALSE COMMENT '+2 pontos',
    playoff_champion BOOLEAN DEFAULT FALSE COMMENT '+5 pontos',
    playoff_points INT DEFAULT 0,
    
    -- Pontos de Premiações
    awards_count INT DEFAULT 0 COMMENT 'Cada premiação = +1 ponto',
    awards_points INT DEFAULT 0,
    
    -- Total
    total_points INT GENERATED ALWAYS AS (regular_season_points + playoff_points + awards_points) STORED,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    UNIQUE KEY unique_team_season (team_id, season_id),
    INDEX idx_team_points (team_id, total_points),
    INDEX idx_league_points (league, total_points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Atualizar tabela de picks para incluir season_id
ALTER TABLE picks 
ADD COLUMN IF NOT EXISTS season_id INT AFTER league,
ADD COLUMN IF NOT EXISTS auto_generated BOOLEAN DEFAULT FALSE COMMENT 'Se foi gerada automaticamente pelo sistema',
ADD FOREIGN KEY IF NOT EXISTS fk_picks_season (season_id) REFERENCES seasons(id) ON DELETE SET NULL;

-- 9. Adicionar índices para otimização
CREATE INDEX IF NOT EXISTS idx_picks_season ON picks(season_id);
CREATE INDEX IF NOT EXISTS idx_picks_team_season ON picks(team_id, season_id);

-- 10. Tabela de configuração de sprints por liga
CREATE TABLE IF NOT EXISTS league_sprint_config (
    league ENUM('ELITE', 'NEXT', 'RISE', 'ROOKIE') PRIMARY KEY,
    max_seasons INT NOT NULL COMMENT 'Número de temporadas por sprint',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configurações padrão
INSERT INTO league_sprint_config (league, max_seasons) VALUES
('ELITE', 20),
('NEXT', 15),
('RISE', 10),
('ROOKIE', 10)
ON DUPLICATE KEY UPDATE max_seasons = VALUES(max_seasons);

-- 11. View para ranking geral (todos os times, todas as ligas)
CREATE OR REPLACE VIEW vw_global_ranking AS
SELECT 
    t.id as team_id,
    t.name as team_name,
    t.city,
    t.league,
    t.photo_url,
    SUM(rp.total_points) as total_points,
    COUNT(DISTINCT rp.season_id) as seasons_played,
    SUM(rp.playoff_champion) as championships,
    SUM(rp.playoff_runner_up) as runner_ups,
    SUM(rp.awards_count) as total_awards
FROM teams t
LEFT JOIN team_ranking_points rp ON t.id = rp.team_id
GROUP BY t.id, t.name, t.city, t.league, t.photo_url
ORDER BY total_points DESC;

-- 12. View para ranking por liga
CREATE OR REPLACE VIEW vw_league_ranking AS
SELECT 
    t.id as team_id,
    t.name as team_name,
    t.city,
    t.league,
    t.photo_url,
    SUM(rp.total_points) as total_points,
    COUNT(DISTINCT rp.season_id) as seasons_played,
    SUM(rp.playoff_champion) as championships,
    SUM(rp.playoff_runner_up) as runner_ups,
    SUM(rp.awards_count) as total_awards,
    RANK() OVER (PARTITION BY t.league ORDER BY SUM(rp.total_points) DESC) as league_rank
FROM teams t
LEFT JOIN team_ranking_points rp ON t.id = rp.team_id
GROUP BY t.id, t.name, t.city, t.league, t.photo_url
ORDER BY t.league, total_points DESC;

-- 13. View para histórico de campeões
CREATE OR REPLACE VIEW vw_champions_history AS
SELECT 
    s.id as season_id,
    s.league,
    s.season_number,
    s.year,
    t.id as team_id,
    t.name as team_name,
    t.city,
    'champion' as position
FROM seasons s
INNER JOIN season_awards sa ON s.id = sa.season_id AND sa.award_type = 'champion'
INNER JOIN teams t ON sa.team_id = t.id
UNION ALL
SELECT 
    s.id as season_id,
    s.league,
    s.season_number,
    s.year,
    t.id as team_id,
    t.name as team_name,
    t.city,
    'runner_up' as position
FROM seasons s
INNER JOIN season_awards sa ON s.id = sa.season_id AND sa.award_type = 'runner_up'
INNER JOIN teams t ON sa.team_id = t.id
ORDER BY season_id DESC, position;
