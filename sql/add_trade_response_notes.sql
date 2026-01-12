-- Adicionar coluna response_notes na tabela trades
-- Para armazenar observações ao aceitar/rejeitar uma proposta

ALTER TABLE trades 
ADD COLUMN IF NOT EXISTS response_notes TEXT NULL COMMENT 'Observação/mensagem ao responder a trade';

-- Índice para performance (opcional)
-- CREATE INDEX idx_trades_status ON trades(status);
