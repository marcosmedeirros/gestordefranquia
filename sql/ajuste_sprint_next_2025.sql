-- ==============================================
-- AJUSTE SPRINT NEXT: inicio 2025 + rollback 1 temporada
-- ==============================================
-- Use este script uma unica vez. Revise os SELECTs antes de executar os UPDATE/DELETE.

SET @league := 'NEXT';
SET @old_start_year := 1991;
SET @new_start_year := 2025;
SET @offset := @new_start_year - @old_start_year;

-- 1) Conferir sprint ativo da liga
SELECT id, league, sprint_number, start_year, start_date
FROM sprints
WHERE league = @league AND status = 'active'
ORDER BY id DESC
LIMIT 1;

-- 2) Atualizar start_year do sprint ativo
UPDATE sprints
SET start_year = @new_start_year
WHERE league = @league AND status = 'active' AND start_year = @old_start_year;

-- 3) Ajustar anos das temporadas do sprint ativo (shift por offset)
UPDATE seasons s
JOIN sprints sp ON s.sprint_id = sp.id
SET s.year = s.year + @offset
WHERE sp.league = @league AND sp.status = 'active';

-- 4) Voltar uma temporada: remover a temporada mais recente do sprint ativo
DELETE s
FROM seasons s
JOIN sprints sp ON s.sprint_id = sp.id
WHERE sp.league = @league AND sp.status = 'active'
ORDER BY s.season_number DESC, s.id DESC
LIMIT 1;

-- 5) Recalcular anos com base no start_year (garantir consistencia)
UPDATE seasons s
JOIN sprints sp ON s.sprint_id = sp.id
SET s.year = sp.start_year + s.season_number - 1
WHERE sp.league = @league AND sp.status = 'active';

-- 6) Ajustar picks auto-geradas da liga (shift por offset)
UPDATE picks p
JOIN teams t ON p.team_id = t.id
SET p.season_year = p.season_year + @offset
WHERE t.league = @league AND p.auto_generated = 1;

-- 7) Conferir temporadas e picks resultantes
SELECT s.id, s.league, s.season_number, s.year, sp.start_year
FROM seasons s
JOIN sprints sp ON s.sprint_id = sp.id
WHERE s.league = @league
ORDER BY s.season_number;

SELECT p.id, p.season_year, p.round, p.original_team_id, p.team_id
FROM picks p
JOIN teams t ON p.team_id = t.id
WHERE t.league = @league AND p.auto_generated = 1
ORDER BY p.season_year, p.round;
