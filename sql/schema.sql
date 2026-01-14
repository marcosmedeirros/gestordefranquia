-- Run this on Hostinger MySQL to create the FBA schema with multi-league support.

CREATE TABLE IF NOT EXISTS leagues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default leagues
INSERT IGNORE INTO leagues (name, description) VALUES 
('ELITE', 'Liga Elite - Jogadores experientes'),
('NEXT', 'Liga Next - Jogadores intermediários avançados'),
('RISE', 'Liga Rise - Jogadores intermediários'),
('ROOKIE', 'Liga Rookie - Jogadores iniciantes');

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    photo_url VARCHAR(255) NULL,
    phone VARCHAR(30) NULL,
    user_type ENUM('jogador','admin') NOT NULL DEFAULT 'jogador',
    league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    verification_token VARCHAR(64) DEFAULT NULL,
    reset_token VARCHAR(64) DEFAULT NULL,
    reset_token_expiry DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_league (league),
    INDEX idx_user_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS divisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
    importance INT DEFAULT 0,
    champions TEXT NULL,
    UNIQUE KEY uniq_division_league (name, league),
    INDEX idx_division_league (league)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
    conference ENUM('LESTE','OESTE') NULL,
    name VARCHAR(120) NOT NULL,
    city VARCHAR(120) NOT NULL,
    mascot VARCHAR(120) NOT NULL,
    photo_url VARCHAR(255) NULL,
    division_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_team_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL,
    INDEX idx_team_league (league),
    INDEX idx_team_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    age INT NOT NULL,
    position VARCHAR(20) NOT NULL,
    role ENUM('Titular','Banco','Outro','G-League') NOT NULL DEFAULT 'Titular',
    available_for_trade TINYINT(1) NOT NULL DEFAULT 0,
    ovr INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_player_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    INDEX idx_player_team (team_id),
    INDEX idx_player_ovr (ovr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS picks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    original_team_id INT NOT NULL,
    season_year INT NOT NULL,
    round ENUM('1','2') NOT NULL,
    last_owner_team_id INT NULL,
    notes VARCHAR(255) NULL,
    CONSTRAINT fk_pick_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_pick_original_team FOREIGN KEY (original_team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_pick_last_owner_team FOREIGN KEY (last_owner_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_pick (original_team_id, season_year, round)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_draft_year_league (year, league),
    INDEX idx_draft_league (league)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS draft_players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    draft_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    position VARCHAR(20) NOT NULL,
    age INT NOT NULL,
    ovr INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_draft_player_draft FOREIGN KEY (draft_id) REFERENCES drafts(id) ON DELETE CASCADE,
    INDEX idx_draft_player_draft (draft_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
