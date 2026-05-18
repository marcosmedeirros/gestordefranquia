-- Adiciona coluna priority em fa_request_offers para o sistema de prioridade na Free Agency
ALTER TABLE fa_request_offers
    ADD COLUMN IF NOT EXISTS priority TINYINT NOT NULL DEFAULT 2 AFTER amount;
