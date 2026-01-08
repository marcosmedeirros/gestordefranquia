-- Alterar o ENUM da coluna position na tabela draft_pool
ALTER TABLE draft_pool 
MODIFY COLUMN position ENUM('PG', 'SG', 'SF', 'PF', 'C') NOT NULL;

-- Alterar o ENUM da coluna position na tabela players (se necessário)
ALTER TABLE players 
MODIFY COLUMN position ENUM('PG', 'SG', 'SF', 'PF', 'C') NOT NULL;
