-- Adicionar coluna ranking_points na tabela teams
-- Esta coluna armazena o total de pontos acumulados do time

ALTER TABLE teams 
ADD COLUMN IF NOT EXISTS ranking_points INT DEFAULT 0 COMMENT 'Pontos acumulados do ranking geral';

-- Criar índice para ordenação
CREATE INDEX IF NOT EXISTS idx_team_ranking ON teams(league, ranking_points DESC);
