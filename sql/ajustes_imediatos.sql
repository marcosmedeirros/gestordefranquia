-- ==============================================
-- AJUSTES IMEDIATOS - Execute no phpMyAdmin
-- ==============================================

-- ===== 1. CORRIGIR ANOS DAS TEMPORADAS =====

-- Ver estado atual dos sprints
SELECT id, league, sprint_number, start_year, start_date 
FROM sprints 
ORDER BY league, sprint_number;

-- Atualizar start_year dos sprints (ajuste os anos conforme necessário)
UPDATE sprints SET start_year = 2016 WHERE league = 'ELITE' AND (start_year IS NULL OR start_year = 0);
UPDATE sprints SET start_year = 2017 WHERE league = 'NEXT' AND (start_year IS NULL OR start_year = 0);
UPDATE sprints SET start_year = 2018 WHERE league = 'RISE' AND (start_year IS NULL OR start_year = 0);
UPDATE sprints SET start_year = 2019 WHERE league = 'ROOKIE' AND (start_year IS NULL OR start_year = 0);

-- Recalcular anos das temporadas com base no sprint
UPDATE seasons s
JOIN sprints sp ON s.sprint_id = sp.id
SET s.year = sp.start_year + s.season_number - 1
WHERE s.year = 0 OR s.year IS NULL OR s.year != (sp.start_year + s.season_number - 1);

-- Verificar resultado
SELECT s.id, s.league, s.season_number, s.year, sp.start_year, sp.sprint_number
FROM seasons s
JOIN sprints sp ON s.sprint_id = sp.id
ORDER BY s.league, s.season_number;


-- ===== 2. VERIFICAR DUPLICATAS DE PICKS =====

-- Ver se existem picks duplicadas (mesmo time, mesmo ano, mesmo round)
SELECT original_team_id, season_year, round, COUNT(*) as total
FROM picks
GROUP BY original_team_id, season_year, round
HAVING total > 1;

-- Se não houver duplicatas, adicionar constraint de unicidade
-- ATENÇÃO: Se houver duplicatas, corrija-as primeiro manualmente
ALTER TABLE picks 
ADD UNIQUE KEY unique_pick_per_team_year_round (original_team_id, season_year, round);


-- ===== 3. VERIFICAR DUPLICATAS DE JOGADORES POR LIGA =====

-- Ver jogadores com nomes duplicados na mesma liga (via time)
SELECT p.name, t.league, COUNT(*) as total
FROM players p
JOIN teams t ON p.team_id = t.id
GROUP BY p.name, t.league
HAVING total > 1
ORDER BY t.league, total DESC;

-- Ver jogadores duplicados no draft_pool
SELECT league, name, season_id, COUNT(*) as total
FROM draft_pool
GROUP BY league, name, season_id
HAVING total > 1
ORDER BY league, total DESC;

-- Ver free agents duplicados
SELECT league, name, COUNT(*) as total
FROM free_agents
GROUP BY league, name
HAVING total > 1
ORDER BY league, total DESC;

-- Se não houver duplicatas, adicionar constraints
-- ATENÇÃO: Corrija duplicatas primeiro antes de executar

-- Para players (considerando apenas nome e team)
-- Nota: Não adicionar constraint direto em players pois pode ter histórico
-- Melhor: adicionar validação no backend

-- Para draft_pool
ALTER TABLE draft_pool 
ADD UNIQUE KEY unique_draft_player_per_league_season (league, name, season_id);

-- Para free_agents
ALTER TABLE free_agents 
ADD UNIQUE KEY unique_fa_per_league (league, name);


-- ===== 4. VERIFICAR ESTADO DAS TRADES =====

-- Ver quantas trades cada time fez
SELECT t.id, t.city, t.name, t.league, t.trades_made, t.max_trades
FROM teams t
ORDER BY t.league, t.trades_made DESC;

-- Se quiser resetar trades de todos times da ROOKIE para 4/10
UPDATE teams 
SET trades_made = 4, max_trades = 10 
WHERE league = 'ROOKIE';


-- ===== 5. BACKUP ANTES DE EXECUTAR =====

-- IMPORTANTE: Sempre faça backup antes de executar updates em massa!
-- Comando para backup via terminal:
-- mysqldump -u seu_usuario -p seu_banco > backup_$(date +%Y%m%d_%H%M%S).sql

