-- Query completa para buscar vencedores do último sprint
-- Execute esta query para testar se está retornando dados

SELECT 
    sp.*,
    t1.id as champion_id, 
    t1.city as champion_city, 
    t1.name as champion_name,
    t1.photo_url as champion_photo, 
    u1.name as champion_owner,
    t2.id as runner_up_id, 
    t2.city as runner_up_city, 
    t2.name as runner_up_name,
    t2.photo_url as runner_up_photo, 
    u2.name as runner_up_owner,
    p.id as mvp_id, 
    p.name as mvp_name, 
    p.position as mvp_position, 
    p.ovr as mvp_ovr,
    t3.city as mvp_team_city, 
    t3.name as mvp_team_name
FROM sprints sp
LEFT JOIN teams t1 ON sp.champion_team_id = t1.id
LEFT JOIN users u1 ON t1.user_id = u1.id
LEFT JOIN teams t2 ON sp.runner_up_team_id = t2.id
LEFT JOIN users u2 ON t2.user_id = u2.id
LEFT JOIN players p ON sp.mvp_player_id = p.id
LEFT JOIN teams t3 ON p.team_id = t3.id
WHERE sp.league = 'ROOKIE'  -- TROQUE 'ROOKIE' pela sua liga: ELITE, NEXT, RISE, ROOKIE
ORDER BY sp.start_year DESC, sp.sprint_number DESC
LIMIT 1;
