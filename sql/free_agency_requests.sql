-- Nova Free Agency (solicitacoes criadas por usuarios)

CREATE TABLE IF NOT EXISTS fa_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
    normalized_name VARCHAR(140) NOT NULL,
    player_name VARCHAR(140) NOT NULL,
    position VARCHAR(20) NOT NULL,
    secondary_position VARCHAR(20) NULL,
    age INT NOT NULL,
    ovr INT NOT NULL,
    season_id INT NULL,
    season_year INT NULL,
    status ENUM('open','assigned','rejected') DEFAULT 'open',
    created_by_team_id INT NULL,
    winner_team_id INT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fa_requests_league (league),
    INDEX idx_fa_requests_name (normalized_name),
    INDEX idx_fa_requests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fa_request_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    team_id INT NOT NULL,
    amount INT NOT NULL DEFAULT 0,
    status ENUM('pending','accepted','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_request_team (request_id, team_id),
    INDEX idx_fa_request_offers_status (status),
    INDEX idx_fa_request_offers_team (team_id),
    CONSTRAINT fk_fa_request_offers_request FOREIGN KEY (request_id) REFERENCES fa_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_fa_request_offers_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
