-- Tabela de Inventário de Avatar (Loot Boxes)

CREATE TABLE IF NOT EXISTS usuario_inventario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    categoria VARCHAR(50) NOT NULL,
    item_id VARCHAR(100) NOT NULL,
    nome_item VARCHAR(150) NOT NULL,
    raridade VARCHAR(50) NOT NULL,
    data_obtencao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_raridade (raridade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
