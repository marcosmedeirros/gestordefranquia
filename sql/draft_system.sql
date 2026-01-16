-- Sistema de Draft Completo
-- Data: 2026-01-16

-- Tabela para configurar drafts ativos por temporada
CREATE TABLE IF NOT EXISTS draft_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT NOT NULL,
    league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
    status ENUM('setup', 'in_progress', 'completed') DEFAULT 'setup',
    current_round INT DEFAULT 1,
    current_pick INT DEFAULT 1,
    total_rounds INT DEFAULT 2,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_season_draft (season_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para definir a ordem de draft por time
CREATE TABLE IF NOT EXISTS draft_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    draft_session_id INT NOT NULL,
    team_id INT NOT NULL,
    original_team_id INT NOT NULL COMMENT 'Time dono original da pick',
    pick_position INT NOT NULL COMMENT 'Posição na ordem (1 = primeira pick)',
    round INT NOT NULL DEFAULT 1,
    picked_player_id INT NULL COMMENT 'ID do jogador escolhido do draft_pool',
    picked_at DATETIME NULL,
    traded_from_team_id INT NULL COMMENT 'Se a pick foi trocada, de quem veio',
    notes VARCHAR(255) NULL,
    FOREIGN KEY (draft_session_id) REFERENCES draft_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (original_team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (picked_player_id) REFERENCES draft_pool(id) ON DELETE SET NULL,
    FOREIGN KEY (traded_from_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_draft_position (draft_session_id, round, pick_position),
    INDEX idx_draft_team (draft_session_id, team_id),
    INDEX idx_draft_order (draft_session_id, round, pick_position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices adicionais para performance
CREATE INDEX IF NOT EXISTS idx_draft_sessions_status ON draft_sessions(status);
CREATE INDEX IF NOT EXISTS idx_draft_sessions_league ON draft_sessions(league);
