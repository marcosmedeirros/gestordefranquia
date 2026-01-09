-- Tabela para configuração de prazos de envio de diretrizes (admin define)
CREATE TABLE IF NOT EXISTS directive_deadlines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
    season_id INT NULL,
    deadline_date DATE NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_deadline_league_date (league, deadline_date),
    FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela para armazenar diretrizes enviadas pelos times
CREATE TABLE IF NOT EXISTS team_directives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    deadline_id INT NOT NULL,
    
    -- Quinteto titular (5 jogadores)
    starter_1_id INT NULL,
    starter_2_id INT NULL,
    starter_3_id INT NULL,
    starter_4_id INT NULL,
    starter_5_id INT NULL,
    
    -- Banco (3 jogadores)
    bench_1_id INT NULL,
    bench_2_id INT NULL,
    bench_3_id INT NULL,
    
    -- Estratégia de jogo (valores 0-100)
    pace INT DEFAULT 50 COMMENT 'Tempo de ataque (0=lento, 100=rápido)',
    offensive_rebound INT DEFAULT 50 COMMENT 'Rebote ofensivo (0=baixo, 100=alto)',
    offensive_aggression INT DEFAULT 50 COMMENT 'Agressividade ofensiva (0=conservador, 100=agressivo)',
    defensive_rebound INT DEFAULT 50 COMMENT 'Rebote defensivo (0=baixo, 100=alto)',
    
    -- Configurações de rotação e estilos
    rotation_style ENUM('balanced','short','deep') DEFAULT 'balanced' COMMENT 'Estilo de rotação',
    game_style ENUM('fast','balanced','slow') DEFAULT 'balanced' COMMENT 'Estilo de jogo',
    offense_style ENUM('inside','balanced','outside') DEFAULT 'balanced' COMMENT 'Estilo de ataque',
    defense_style ENUM('man','zone','mixed') DEFAULT 'man' COMMENT 'Estilo de defesa',
    
    notes TEXT NULL COMMENT 'Observações adicionais',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_directive_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_directive_deadline FOREIGN KEY (deadline_id) REFERENCES directive_deadlines(id) ON DELETE CASCADE,
    CONSTRAINT fk_directive_starter1 FOREIGN KEY (starter_1_id) REFERENCES players(id) ON DELETE SET NULL,
    CONSTRAINT fk_directive_starter2 FOREIGN KEY (starter_2_id) REFERENCES players(id) ON DELETE SET NULL,
    CONSTRAINT fk_directive_starter3 FOREIGN KEY (starter_3_id) REFERENCES players(id) ON DELETE SET NULL,
    CONSTRAINT fk_directive_starter4 FOREIGN KEY (starter_4_id) REFERENCES players(id) ON DELETE SET NULL,
    CONSTRAINT fk_directive_starter5 FOREIGN KEY (starter_5_id) REFERENCES players(id) ON DELETE SET NULL,
    CONSTRAINT fk_directive_bench1 FOREIGN KEY (bench_1_id) REFERENCES players(id) ON DELETE SET NULL,
    CONSTRAINT fk_directive_bench2 FOREIGN KEY (bench_2_id) REFERENCES players(id) ON DELETE SET NULL,
    CONSTRAINT fk_directive_bench3 FOREIGN KEY (bench_3_id) REFERENCES players(id) ON DELETE SET NULL,
    
    UNIQUE KEY uniq_team_deadline (team_id, deadline_id),
    INDEX idx_directive_team (team_id),
    INDEX idx_directive_deadline (deadline_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
