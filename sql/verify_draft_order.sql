-- Query para verificar a ordem correta do draft
-- Execute no phpMyAdmin para ver a ordem real

-- 1. Ver draft ativo e suas informações
SELECT ds.*, s.year, s.season_number
FROM draft_sessions ds
JOIN seasons s ON ds.season_id = s.id
WHERE ds.league = 'ROOKIE' AND ds.status = 'in_progress';

-- 2. Ver TODAS as picks do draft em ordem (com e sem jogador)
SELECT 
    do.id,
    do.round,
    do.pick_position,
    t.city,
    t.name as team_name,
    t.photo_url,
    u.name as owner_name,
    CASE WHEN do.picked_player_id IS NULL THEN '❌ PENDENTE' ELSE '✅ USADA' END as status,
    dp.name as jogador_escolhido
FROM draft_order do
JOIN draft_sessions ds ON do.draft_session_id = ds.id
JOIN teams t ON do.team_id = t.id
LEFT JOIN users u ON t.user_id = u.id
LEFT JOIN draft_pool dp ON do.picked_player_id = dp.id
WHERE ds.league = 'ROOKIE' 
  AND ds.status = 'in_progress'
ORDER BY do.round ASC, do.pick_position ASC;

-- 3. Ver APENAS a próxima pick (primeira pendente)
SELECT 
    do.round,
    do.pick_position,
    t.id as team_id,
    t.city,
    t.name as team_name,
    t.photo_url,
    u.name as owner_name,
    'ESTA É A PRÓXIMA PICK' as observacao
FROM draft_order do
JOIN draft_sessions ds ON do.draft_session_id = ds.id
JOIN teams t ON do.team_id = t.id
LEFT JOIN users u ON t.user_id = u.id
WHERE ds.league = 'ROOKIE' 
  AND ds.status = 'in_progress'
  AND do.picked_player_id IS NULL
ORDER BY do.round ASC, do.pick_position ASC
LIMIT 1;

-- 4. Verificar estrutura da tabela draft_order
DESCRIBE draft_order;
