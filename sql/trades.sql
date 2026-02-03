-- Tabela de trades
CREATE TABLE IF NOT EXISTS trades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_team_id INT NOT NULL,
    to_team_id INT NOT NULL,
    league ENUM('ELITE','NEXT','RISE','ROOKIE') NULL,
    status ENUM('pending', 'accepted', 'rejected', 'cancelled', 'countered') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (from_team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (to_team_id) REFERENCES teams(id) ON DELETE CASCADE
);

-- Itens da trade (jogadores oferecidos pelo time que propõe)
CREATE TABLE IF NOT EXISTS trade_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trade_id INT NOT NULL,
    player_id INT,
    pick_id INT,
    pick_protection VARCHAR(20) NULL,
    from_team BOOLEAN DEFAULT TRUE, -- TRUE = oferecido por from_team, FALSE = pedido de to_team
    FOREIGN KEY (trade_id) REFERENCES trades(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (pick_id) REFERENCES picks(id) ON DELETE CASCADE
);

-- Índices adicionais são gerenciados pelas migrações automáticas.
