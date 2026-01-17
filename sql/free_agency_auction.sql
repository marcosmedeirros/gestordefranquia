-- =====================================================
-- SISTEMA DE LEILÃO FREE AGENCY
-- Criado em: 2026-01-16
-- =====================================================

-- Tabela de leilões ativos
CREATE TABLE IF NOT EXISTS free_agency_auctions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL COMMENT 'Jogador sendo leiloado',
    league VARCHAR(50) NOT NULL,
    status ENUM('active', 'finished', 'cancelled') DEFAULT 'active',
    start_time DATETIME NOT NULL COMMENT 'Quando o leilão começou',
    end_time DATETIME NOT NULL COMMENT 'Quando o leilão termina (start + 20min)',
    current_bid INT DEFAULT 1 COMMENT 'Lance atual (começa em 1)',
    current_bidder_team_id INT NULL COMMENT 'Time com o maior lance atual',
    winner_team_id INT NULL COMMENT 'Time vencedor (preenchido quando termina)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_player_auction (player_id, status),
    
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (current_bidder_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (winner_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    
    INDEX idx_auction_status (status),
    INDEX idx_auction_league (league, status),
    INDEX idx_auction_end_time (end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de histórico de lances
CREATE TABLE IF NOT EXISTS free_agency_bids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auction_id INT NOT NULL,
    team_id INT NOT NULL,
    bid_amount INT NOT NULL COMMENT 'Valor do lance em pontos',
    bid_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (auction_id) REFERENCES free_agency_auctions(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    
    INDEX idx_bid_auction (auction_id),
    INDEX idx_bid_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- REGRAS DO LEILÃO
-- =====================================================
-- 
-- 1. Admin inicia o leilão para um jogador específico
-- 2. Leilão dura 20 minutos
-- 3. Todos os lances começam em 1 ponto
-- 4. Time deve ter pontos suficientes para dar lance
-- 5. O lance deve ser maior que o lance atual
-- 6. Quando o tempo acaba, o maior lance vence
-- 7. Pontos são debitados do time vencedor
-- 8. Jogador é transferido para o time vencedor
--
-- =====================================================
