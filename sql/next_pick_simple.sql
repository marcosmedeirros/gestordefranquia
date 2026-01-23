-- Query simplificada para ver o próximo time a draftar
-- COPIE E EXECUTE ESTA QUERY COMPLETA:

SELECT 
    do.round,
    do.pick_position,
    t.id as team_id,
    t.city,
    t.name as team_name,
    u.name as owner_name
FROM draft_order do
JOIN draft_sessions ds ON do.draft_session_id = ds.id
JOIN teams t ON do.team_id = t.id
LEFT JOIN users u ON t.user_id = u.id
WHERE ds.league = 'ROOKIE' 
  AND ds.status = 'in_progress'
  AND do.picked_player_id IS NULL
ORDER BY do.round ASC, do.pick_position ASC
LIMIT 1;
