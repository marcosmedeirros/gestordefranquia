-- Script para adicionar coluna league às tabelas existentes (para migração)
-- Execute APENAS se você já tem dados no banco e precisa adicionar o sistema de ligas

-- Adicionar tabela de ligas
CREATE TABLE IF NOT EXISTS leagues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name ENUM('ELITE','PRIME','RISE','ROOKIE') NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inserir ligas padrão
INSERT IGNORE INTO leagues (name, description) VALUES 
('ELITE', 'Liga Elite - Jogadores experientes'),
('PRIME', 'Liga Prime - Jogadores intermediários avançados'),
('RISE', 'Liga Rise - Jogadores intermediários'),
('ROOKIE', 'Liga Rookie - Jogadores iniciantes');

-- Adicionar coluna league na tabela users (se não existir)
ALTER TABLE users ADD COLUMN IF NOT EXISTS league ENUM('ELITE','PRIME','RISE','ROOKIE') NOT NULL DEFAULT 'ROOKIE' AFTER user_type;
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_user_league (league);

-- Adicionar coluna league na tabela teams (se não existir)
ALTER TABLE teams ADD COLUMN IF NOT EXISTS league ENUM('ELITE','PRIME','RISE','ROOKIE') NOT NULL DEFAULT 'ROOKIE' AFTER user_id;
ALTER TABLE teams ADD INDEX IF NOT EXISTS idx_team_league (league);
ALTER TABLE teams ADD INDEX IF NOT EXISTS idx_team_user (user_id);

-- Adicionar coluna league na tabela divisions (se não existir)
ALTER TABLE divisions ADD COLUMN IF NOT EXISTS league ENUM('ELITE','PRIME','RISE','ROOKIE') NOT NULL DEFAULT 'ROOKIE' AFTER name;
ALTER TABLE divisions DROP INDEX IF EXISTS name;
ALTER TABLE divisions ADD UNIQUE KEY IF NOT EXISTS uniq_division_league (name, league);
ALTER TABLE divisions ADD INDEX IF NOT EXISTS idx_division_league (league);

-- Adicionar coluna league na tabela drafts (se não existir)
ALTER TABLE drafts ADD COLUMN IF NOT EXISTS league ENUM('ELITE','PRIME','RISE','ROOKIE') NOT NULL DEFAULT 'ROOKIE' AFTER year;
ALTER TABLE drafts DROP INDEX IF EXISTS year;
ALTER TABLE drafts ADD UNIQUE KEY IF NOT EXISTS uniq_draft_year_league (year, league);
ALTER TABLE drafts ADD INDEX IF NOT EXISTS idx_draft_league (league);

-- Atualizar teams existentes para herdar a league do usuário
UPDATE teams t
INNER JOIN users u ON t.user_id = u.id
SET t.league = u.league
WHERE t.league IS NULL OR t.league = '';
