-- Sistema de Free Agency
-- Jogadores dispensados disponíveis para contratação

-- Tabela de jogadores em Free Agency
CREATE TABLE IF NOT EXISTS free_agents (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de propostas para Free Agents
CREATE TABLE IF NOT EXISTS free_agent_offers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Controle de dispensas e contratações por temporada
-- Adicionar colunas na tabela teams se não existirem
-- ALTER TABLE teams ADD COLUMN waivers_used INT DEFAULT 0;
-- ALTER TABLE teams ADD COLUMN fa_signings_used INT DEFAULT 0;
