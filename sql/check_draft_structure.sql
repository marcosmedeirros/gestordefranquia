-- Verificar estrutura da tabela draft_order
DESCRIBE draft_order;

-- Ver todos os registros de draft_order
SELECT * FROM draft_order LIMIT 10;

-- Ver draft_sessions ativos
SELECT * FROM draft_sessions WHERE status = 'in_progress';

-- Query completa para debug do draft
SELECT 
    ds.id as session_id,
    ds.status,
    ds.league,
    s.year,
    COUNT(do.id) as total_picks,
    SUM(CASE WHEN do.picked_player_id IS NULL THEN 1 ELSE 0 END) as picks_pendentes
FROM draft_sessions ds
JOIN seasons s ON ds.season_id = s.id
LEFT JOIN draft_order do ON do.draft_session_id = ds.id
WHERE ds.league = 'ROOKIE'
GROUP BY ds.id, ds.status, ds.league, s.year
ORDER BY ds.id DESC;

-- Próxima pick (a que está pendente com menor número)
SELECT do.*, t.city, t.name as team_name, t.photo_url
FROM draft_order do
JOIN draft_sessions ds ON do.draft_session_id = ds.id
JOIN teams t ON do.team_id = t.id
WHERE ds.league = 'ROOKIE' 
  AND ds.status = 'in_progress'
  AND do.picked_player_id IS NULL
ORDER BY do.round ASC, do.pick_position ASC
LIMIT 5;
