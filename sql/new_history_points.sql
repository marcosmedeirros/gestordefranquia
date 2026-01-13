-- =====================================================
-- NOVA ESTRUTURA: HISTÓRICO E PONTOS SIMPLIFICADOS
-- =====================================================

-- 1. Tabela de Histórico de Temporada (simplificada)
-- Salva: Campeão, Vice, MVP, DPOY, MIP, 6º Homem
DROP TABLE IF EXISTS season_history;
CREATE TABLE season_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT NOT NULL,
    league ENUM('ELITE', 'NEXT', 'RISE', 'ROOKIE') NOT NULL,
    sprint_number INT NOT NULL,
    season_number INT NOT NULL,
    year INT NOT NULL,
    
    -- Times
    champion_team_id INT,
    runner_up_team_id INT,
    
    -- Prêmios individuais (nome do jogador)
    mvp_player VARCHAR(100),
    mvp_team_id INT,
    dpoy_player VARCHAR(100),
    dpoy_team_id INT,
    mip_player VARCHAR(100),
    mip_team_id INT,
    sixth_man_player VARCHAR(100),
    sixth_man_team_id INT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    FOREIGN KEY (champion_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (runner_up_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (mvp_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (dpoy_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (mip_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (sixth_man_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    
    UNIQUE KEY unique_season_history (season_id),
    INDEX idx_league_history (league)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabela de Pontos por Time/Temporada
-- Pontos são somados no ranking e NÃO resetam com o sprint
DROP TABLE IF EXISTS team_season_points;
CREATE TABLE team_season_points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    team_name VARCHAR(150) NOT NULL COMMENT 'Nome do time no momento do registro',
    league ENUM('ELITE', 'NEXT', 'RISE', 'ROOKIE') NOT NULL,
    season_id INT NOT NULL,
    sprint_number INT NOT NULL,
    season_number INT NOT NULL,
    points INT NOT NULL DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_team_season_points (team_id, season_id),
    INDEX idx_league_points (league),
    INDEX idx_team_total (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. View para ranking por liga (soma dos pontos de todas as temporadas)
CREATE OR REPLACE VIEW vw_ranking_by_league AS
SELECT 
    t.id as team_id,
    CONCAT(t.city, ' ', t.name) as team_name,
    t.league,
    COALESCE(SUM(tsp.points), 0) as total_points
FROM teams t
LEFT JOIN team_season_points tsp ON t.id = tsp.team_id
GROUP BY t.id, t.city, t.name, t.league
ORDER BY t.league, total_points DESC;

-- 4. View para histórico completo
CREATE OR REPLACE VIEW vw_season_history AS
SELECT 
    sh.id,
    sh.season_id,
    sh.league,
    sh.sprint_number,
    sh.season_number,
    sh.year,
    
    -- Campeão
    sh.champion_team_id,
    CONCAT(tc.city, ' ', tc.name) as champion_name,
    
    -- Vice
    sh.runner_up_team_id,
    CONCAT(tr.city, ' ', tr.name) as runner_up_name,
    
    -- MVP
    sh.mvp_player,
    sh.mvp_team_id,
    CONCAT(tm.city, ' ', tm.name) as mvp_team_name,
    
    -- DPOY
    sh.dpoy_player,
    sh.dpoy_team_id,
    CONCAT(td.city, ' ', td.name) as dpoy_team_name,
    
    -- MIP
    sh.mip_player,
    sh.mip_team_id,
    CONCAT(ti.city, ' ', ti.name) as mip_team_name,
    
    -- 6º Homem
    sh.sixth_man_player,
    sh.sixth_man_team_id,
    CONCAT(ts.city, ' ', ts.name) as sixth_man_team_name,
    
    sh.created_at
FROM season_history sh
LEFT JOIN teams tc ON sh.champion_team_id = tc.id
LEFT JOIN teams tr ON sh.runner_up_team_id = tr.id
LEFT JOIN teams tm ON sh.mvp_team_id = tm.id
LEFT JOIN teams td ON sh.dpoy_team_id = td.id
LEFT JOIN teams ti ON sh.mip_team_id = ti.id
LEFT JOIN teams ts ON sh.sixth_man_team_id = ts.id
ORDER BY sh.league, sh.sprint_number DESC, sh.season_number DESC;
