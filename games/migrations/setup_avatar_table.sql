-- SQL para criar a tabela de avatares
-- Execute isso no seu banco de dados

CREATE TABLE IF NOT EXISTS usuario_avatars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    color VARCHAR(50) DEFAULT 'default',
    hardware VARCHAR(50) DEFAULT 'none',
    clothing VARCHAR(50) DEFAULT 'none',
    footwear VARCHAR(50) DEFAULT 'none',
    elite VARCHAR(50) DEFAULT 'none',
    aura VARCHAR(50) DEFAULT 'none',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
