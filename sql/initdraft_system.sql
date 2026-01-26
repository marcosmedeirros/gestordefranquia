-- Sistema de Draft Inicial (initdraft) separado do draft da temporada
-- Data: 2026-01-26

-- Sessões de draft inicial por temporada/ligas, com link de acesso via token
CREATE TABLE IF NOT EXISTS initdraft_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT NOT NULL,
    league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
    status ENUM('setup', 'in_progress', 'completed') DEFAULT 'setup',
    current_round INT DEFAULT 1,
    current_pick INT DEFAULT 1,
    total_rounds INT DEFAULT 5,
    access_token VARCHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_season_initdraft (season_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ordem de picks do draft inicial (snake)
CREATE TABLE IF NOT EXISTS initdraft_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    initdraft_session_id INT NOT NULL,
    team_id INT NOT NULL,
    original_team_id INT NOT NULL COMMENT 'Time dono original da pick',
    pick_position INT NOT NULL COMMENT 'Posição na ordem (1 = primeira pick)',
    round INT NOT NULL DEFAULT 1,
    picked_player_id INT NULL COMMENT 'ID do jogador escolhido do initdraft_pool',
    picked_at DATETIME NULL,
    traded_from_team_id INT NULL COMMENT 'Se a pick foi trocada, de quem veio',
    notes VARCHAR(255) NULL,
    FOREIGN KEY (initdraft_session_id) REFERENCES initdraft_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (original_team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (picked_player_id) REFERENCES initdraft_pool(id) ON DELETE SET NULL,
    FOREIGN KEY (traded_from_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_initdraft_position (initdraft_session_id, round, pick_position),
    INDEX idx_initdraft_team (initdraft_session_id, team_id),
    INDEX idx_initdraft_order (initdraft_session_id, round, pick_position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pool de jogadores do draft inicial (campos mínimos)
CREATE TABLE IF NOT EXISTS initdraft_pool (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    position ENUM('PG', 'SG', 'SF', 'PF', 'C') NOT NULL,
    age INT NOT NULL,
    ovr INT NOT NULL CHECK (ovr >= 1 AND ovr <= 99),
    draft_status ENUM('available', 'drafted') DEFAULT 'available',
    drafted_by_team_id INT NULL,
    draft_order INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    INDEX idx_initdraft_pool_season (season_id),
    INDEX idx_initdraft_pool_status (draft_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices auxiliares
CREATE INDEX IF NOT EXISTS idx_initdraft_sessions_status ON initdraft_sessions(status);
CREATE INDEX IF NOT EXISTS idx_initdraft_sessions_league ON initdraft_sessions(league);
