-- Configurações por liga (CAP min/max)
CREATE TABLE IF NOT EXISTS league_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  league ENUM('ELITE','PRIME','RISE','ROOKIE') NOT NULL UNIQUE,
  cap_min INT NOT NULL DEFAULT 0,
  cap_max INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Valores iniciais (opcional)
INSERT IGNORE INTO league_settings (league, cap_min, cap_max) VALUES
('ELITE', 618, 648),
('PRIME', 618, 648),
('RISE', 618, 648),
('ROOKIE', 618, 648);
