-- Queries para testar dados do dashboard
-- Execute no phpMyAdmin para ver os dados

-- ========================================
-- 1. TESTAR DRAFT ATIVO
-- ========================================

-- Verificar se existe draft em progresso
SELECT ds.*, s.year, s.start_year, s.season_number
FROM draft_sessions ds
JOIN seasons s ON ds.season_id = s.id
WHERE ds.league = 'ROOKIE' AND ds.status = 'in_progress';

-- Verificar próxima pick do draft (QUERY CORRETA)
SELECT do.*, t.city, t.name as team_name, t.photo_url, u.name as owner_name
FROM draft_order do
JOIN draft_sessions ds ON do.draft_session_id = ds.id
JOIN teams t ON do.team_id = t.id
LEFT JOIN users u ON t.user_id = u.id
WHERE ds.league = 'ROOKIE' 
  AND ds.status = 'in_progress'
  AND do.picked_player_id IS NULL
ORDER BY do.round ASC, do.pick_position ASC
LIMIT 1;

-- ========================================
-- 2. TESTAR ÚLTIMA TRADE
-- ========================================

-- Buscar última trade aceita
SELECT 
    t.*,
    t1.city as from_city, t1.name as from_name, t1.photo_url as from_photo,
    t2.city as to_city, t2.name as to_name, t2.photo_url as to_photo,
    u1.name as from_owner, u2.name as to_owner
FROM trades t
JOIN teams t1 ON t.from_team_id = t1.id
JOIN teams t2 ON t.to_team_id = t2.id
LEFT JOIN users u1 ON t1.user_id = u1.id
LEFT JOIN users u2 ON t2.user_id = u2.id
WHERE t.status = 'accepted' AND t1.league = 'ROOKIE'
ORDER BY t.updated_at DESC
LIMIT 1;

-- Buscar itens da última trade (use o ID da trade acima)
-- Substitua X pelo ID da trade retornada acima

-- Jogadores do Time 1 (FROM team - ofereceu)
SELECT p.name, p.position, p.ovr 
FROM players p
JOIN trade_items ti ON p.id = ti.player_id
WHERE ti.trade_id = X AND ti.from_team = TRUE AND ti.player_id IS NOT NULL;

-- Jogadores do Time 2 (TO team - recebeu/ofereceu de volta)
SELECT p.name, p.position, p.ovr 
FROM players p
JOIN trade_items ti ON p.id = ti.player_id
WHERE ti.trade_id = X AND ti.from_team = FALSE AND ti.player_id IS NOT NULL;

-- Picks do Time 1 (FROM team - ofereceu)
SELECT pk.season_year, pk.round 
FROM picks pk
JOIN trade_items ti ON pk.id = ti.pick_id
WHERE ti.trade_id = X AND ti.from_team = TRUE AND ti.pick_id IS NOT NULL;

-- Picks do Time 2 (TO team - recebeu/ofereceu de volta)
SELECT pk.season_year, pk.round 
FROM picks pk
JOIN trade_items ti ON pk.id = ti.pick_id
WHERE ti.trade_id = X AND ti.from_team = FALSE AND ti.pick_id IS NOT NULL;

-- ========================================
-- 3. VERIFICAR ESTRUTURA DAS TABELAS
-- ========================================

-- Ver estrutura da tabela trades
DESCRIBE trades;

-- Ver estrutura da tabela draft_order
DESCRIBE draft_order;

-- Ver estrutura da tabela draft_sessions
DESCRIBE draft_sessions;
