-- Adicionar campos de minutagem por jogador nas diretrizes
-- Data: 12 de janeiro de 2026

-- Tabela para armazenar minutagem dos jogadores
CREATE TABLE IF NOT EXISTS directive_player_minutes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    directive_id INT NOT NULL,
    player_id INT NOT NULL,
    minutes_per_game INT NOT NULL DEFAULT 20 COMMENT 'Minutos por jogo (5-40 regular, 5-45 playoffs)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_dpm_directive FOREIGN KEY (directive_id) REFERENCES team_directives(id) ON DELETE CASCADE,
    CONSTRAINT fk_dpm_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_directive_player (directive_id, player_id),
    INDEX idx_dpm_directive (directive_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
