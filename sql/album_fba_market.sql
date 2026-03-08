-- Mercado de cartinhas duplicadas do Album FBA
CREATE TABLE IF NOT EXISTS album_fba_market (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_user_id INT NOT NULL,
    buyer_user_id INT NULL,
    sticker_id VARCHAR(10) NOT NULL,
    rarity VARCHAR(20) NOT NULL,
    price_points INT NOT NULL,
    status ENUM('active','sold','cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sold_at TIMESTAMP NULL DEFAULT NULL,
    cancelled_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_album_market_status_created (status, created_at),
    INDEX idx_album_market_seller_status (seller_user_id, status),
    INDEX idx_album_market_buyer (buyer_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
