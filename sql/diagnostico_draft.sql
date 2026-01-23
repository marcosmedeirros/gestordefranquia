-- Queries para diagnosticar o draft ativo
-- Execute estas queries no phpMyAdmin para descobrir o problema

-- 1. Verificar se a tabela draft_sessions existe e seus dados
SELECT * FROM draft_sessions LIMIT 10;

-- 2. Verificar todos os status possíveis
SELECT DISTINCT status FROM draft_sessions;

-- 3. Verificar se existe algum draft com qualquer status
SELECT ds.*, s.year as season_year 
FROM draft_sessions ds
LEFT JOIN seasons s ON ds.season_id = s.id
ORDER BY ds.id DESC
LIMIT 5;

-- 4. Verificar draft por liga específica (TROQUE 'ROOKIE' pela sua liga)
SELECT ds.*, s.year as season_year 
FROM draft_sessions ds
LEFT JOIN seasons s ON ds.season_id = s.id
WHERE ds.league = 'ROOKIE'
ORDER BY ds.id DESC
LIMIT 5;

-- 5. Verificar a estrutura da tabela draft_sessions
DESCRIBE draft_sessions;

-- 6. Verificar draft_order (picks pendentes)
SELECT do.*, t.city, t.name as team_name 
FROM draft_order do
LEFT JOIN teams t ON do.team_id = t.id
WHERE do.status = 'pending'
ORDER BY do.pick_number ASC
LIMIT 10;

-- 7. Se usar tabela "drafts" ao invés de "draft_sessions":
SELECT * FROM drafts WHERE league = 'ROOKIE' ORDER BY id DESC LIMIT 5;
