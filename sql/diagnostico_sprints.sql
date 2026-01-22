-- Query de diagnóstico para verificar dados dos sprints
-- Execute estas queries para entender o que tem no banco

-- 1. Ver todos os sprints da liga
SELECT 
    id,
    league,
    sprint_number,
    start_year,
    status,
    champion_team_id,
    runner_up_team_id,
    mvp_player_id,
    created_at
FROM sprints
WHERE league = 'ROOKIE'  -- TROQUE pela sua liga
ORDER BY start_year DESC, sprint_number DESC;

-- 2. Ver se os IDs existem nas tabelas relacionadas
SELECT 
    'Sprint' as tabela,
    sp.id,
    sp.sprint_number,
    sp.champion_team_id,
    CASE WHEN t1.id IS NOT NULL THEN 'OK' ELSE 'FALTANDO' END as champion_existe,
    sp.runner_up_team_id,
    CASE WHEN t2.id IS NOT NULL THEN 'OK' ELSE 'FALTANDO' END as runner_up_existe,
    sp.mvp_player_id,
    CASE WHEN p.id IS NOT NULL THEN 'OK' ELSE 'FALTANDO' END as mvp_existe
FROM sprints sp
LEFT JOIN teams t1 ON sp.champion_team_id = t1.id
LEFT JOIN teams t2 ON sp.runner_up_team_id = t2.id
LEFT JOIN players p ON sp.mvp_player_id = p.id
WHERE sp.league = 'ROOKIE'  -- TROQUE pela sua liga
ORDER BY sp.start_year DESC, sp.sprint_number DESC;

-- 3. Ver estrutura da tabela sprints
DESCRIBE sprints;
