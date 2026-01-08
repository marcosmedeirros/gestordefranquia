-- Migração: Atualizar PRIME para NEXT e adicionar novos campos
-- Data: 08/01/2026

-- 1. Adicionar novos campos na tabela league_settings
ALTER TABLE league_settings 
ADD COLUMN IF NOT EXISTS max_trades INT NOT NULL DEFAULT 3 AFTER cap_max,
ADD COLUMN IF NOT EXISTS edital TEXT NULL AFTER max_trades;

-- 2. Atualizar ENUM nas tabelas para trocar PRIME por NEXT
-- Tabela: leagues
ALTER TABLE leagues 
MODIFY COLUMN name ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL;

UPDATE leagues SET name = 'NEXT' WHERE name = 'PRIME';
UPDATE leagues SET description = 'Liga Next - Jogadores intermediários avançados' WHERE name = 'NEXT';

-- Tabela: users
ALTER TABLE users 
MODIFY COLUMN league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL;

UPDATE users SET league = 'NEXT' WHERE league = 'PRIME';

-- Tabela: divisions
ALTER TABLE divisions 
MODIFY COLUMN league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL;

UPDATE divisions SET league = 'NEXT' WHERE league = 'PRIME';

-- Tabela: teams
ALTER TABLE teams 
MODIFY COLUMN league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL;

UPDATE teams SET league = 'NEXT' WHERE league = 'PRIME';

-- Tabela: drafts
ALTER TABLE drafts 
MODIFY COLUMN league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL;

UPDATE drafts SET league = 'NEXT' WHERE league = 'PRIME';

-- Tabela: league_settings
ALTER TABLE league_settings 
MODIFY COLUMN league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL;

UPDATE league_settings SET league = 'NEXT' WHERE league = 'PRIME';

-- 3. Atualizar valores padrão para max_trades
UPDATE league_settings SET max_trades = 3 WHERE max_trades = 0;

-- 4. Verificação final
SELECT 'Migração concluída!' as status;
SELECT * FROM league_settings;
