-- Adiciona campos para número de trocas e edital na tabela league_settings

ALTER TABLE league_settings 
ADD COLUMN IF NOT EXISTS max_trades INT NOT NULL DEFAULT 0 AFTER cap_max,
ADD COLUMN IF NOT EXISTS edital TEXT NULL AFTER max_trades;

-- Atualiza valores iniciais
UPDATE league_settings SET max_trades = 3 WHERE max_trades = 0;
