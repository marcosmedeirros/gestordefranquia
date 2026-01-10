-- Migração segura - apenas adiciona colunas que não existem

-- Adicionar secondary_position na tabela players (se não existir)
SET @dbname = DATABASE();
SET @tablename = 'players';
SET @columnname = 'secondary_position';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  'ALTER TABLE players ADD COLUMN secondary_position VARCHAR(20) NULL AFTER position'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Adicionar seasons_in_league na tabela players (se não existir)
SET @columnname = 'seasons_in_league';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  'ALTER TABLE players ADD COLUMN seasons_in_league INT DEFAULT 0 AFTER age'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
