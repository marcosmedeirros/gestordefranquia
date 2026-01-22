-- Adicionar coluna para controlar ativação/desativação de trades por liga
-- Execute este SQL no phpMyAdmin ou ferramenta de administração do banco

ALTER TABLE league_settings 
ADD COLUMN IF NOT EXISTS trades_enabled TINYINT(1) DEFAULT 1 
COMMENT 'Se 1, trades estão ativas na liga; se 0, desativadas';

-- Por padrão, todas as ligas iniciam com trades ativas (valor 1)
