-- Query completa para buscar vencedores do último sprint
-- Execute esta query para testar se está retornando dados
-- DADOS ESTÃO NA TABELA season_history

SELECT 
    sh.*,
    t1.id as champion_id, 
    t1.city as champion_city, 
    t1.name as champion_name,
    t1.photo_url as champion_photo, 
    u1.name as champion_owner,
    t2.id as runner_up_id, 
    t2.city as runner_up_city, 
    t2.name as runner_up_name,
    t2.photo_url as runner_up_photo, 
    u2.name as runner_up_owner
FROM season_history sh
LEFT JOIN teams t1 ON sh.champion_team_id = t1.id
LEFT JOIN users u1 ON t1.user_id = u1.id
LEFT JOIN teams t2 ON sh.runner_up_team_id = t2.id
LEFT JOIN users u2 ON t2.user_id = u2.id
WHERE sh.league = 'ROOKIE'  -- TROQUE 'ROOKIE' pela sua liga: ELITE, NEXT, RISE, ROOKIE
ORDER BY sh.id DESC
LIMIT 1;

