-- Adicionar campo de posição secundária aos jogadores
ALTER TABLE players ADD COLUMN secondary_position VARCHAR(20) NULL AFTER position;

-- Adicionar campos para controle de waiver (dispensas por temporada)
ALTER TABLE players ADD COLUMN seasons_in_league INT DEFAULT 0 AFTER age;

-- Adicionar tabela para controlar dispensas por temporada
CREATE TABLE IF NOT EXISTS waivers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    player_name VARCHAR(120) NOT NULL,
    season_year INT NOT NULL,
    waived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_waiver_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    INDEX idx_waiver_team_season (team_id, season_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Adicionar campo para controlar o ciclo atual (2 temporadas)
ALTER TABLE teams ADD COLUMN current_cycle INT DEFAULT 1 AFTER league;

-- Adicionar campo para controlar trades por ciclo
ALTER TABLE trades ADD COLUMN cycle INT DEFAULT 1 AFTER user_id;

-- Adicionar campo max_trades em league_settings
ALTER TABLE league_settings ADD COLUMN max_trades INT DEFAULT 10 AFTER cap_max;
