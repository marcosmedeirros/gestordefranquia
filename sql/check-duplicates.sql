-- =========================================
-- VERIFICAÇÃO DE DUPLICATAS NO BANCO
-- =========================================

-- 1. VERIFICAR JOGADORES COM MESMO NOME NA MESMA LIGA
-- (Cada jogador deve ser único por liga)
SELECT 
    p.name AS player_name,
    t.league,
    COUNT(*) AS quantidade,
    GROUP_CONCAT(CONCAT(tc.city, ' ', tc.name) SEPARATOR ', ') AS times
FROM players p
JOIN teams t ON p.team_id = t.id
LEFT JOIN teams tc ON p.team_id = tc.id
GROUP BY p.name, t.league
HAVING quantidade > 1
ORDER BY quantidade DESC, p.name;

-- 2. VERIFICAR PICKS DUPLICADAS (mesmo ano, mesma rodada, mesmo time original)
-- (Só pode existir UMA pick de 2017 R1 do Las Vegas, por exemplo)
SELECT 
    p.season_year,
    p.round,
    orig.city AS original_city,
    orig.name AS original_name,
    COUNT(*) AS quantidade,
    GROUP_CONCAT(CONCAT(curr.city, ' ', curr.name) SEPARATOR ', ') AS donos_atuais
FROM picks p
JOIN teams orig ON p.original_team_id = orig.id
JOIN teams curr ON p.team_id = curr.id
GROUP BY p.season_year, p.round, p.original_team_id
HAVING quantidade > 1
ORDER BY p.season_year, p.round;

-- 3. VERIFICAR TODAS AS PICKS (para auditoria completa)
SELECT 
    p.id,
    p.season_year,
    p.round,
    CONCAT(orig.city, ' ', orig.name) AS time_original,
    CONCAT(curr.city, ' ', curr.name) AS dono_atual,
    p.original_team_id,
    p.team_id
FROM picks p
JOIN teams orig ON p.original_team_id = orig.id
JOIN teams curr ON p.team_id = curr.id
ORDER BY p.season_year, p.round, orig.city;

-- 4. CONTAR PICKS POR TIME ORIGINAL E ANO
-- (Deve ter exatamente 2 picks por time por ano: 1ª e 2ª rodada)
SELECT 
    p.season_year,
    CONCAT(t.city, ' ', t.name) AS time_original,
    COUNT(*) AS total_picks,
    GROUP_CONCAT(p.round ORDER BY p.round SEPARATOR ', ') AS rodadas
FROM picks p
JOIN teams t ON p.original_team_id = t.id
GROUP BY p.season_year, p.original_team_id
HAVING total_picks != 2
ORDER BY p.season_year, t.city;

-- 5. VERIFICAR SE HÁ PICKS SEM TIME ORIGINAL OU DONO
SELECT 
    p.id,
    p.season_year,
    p.round,
    p.original_team_id,
    p.team_id,
    CASE 
        WHEN p.original_team_id IS NULL THEN 'SEM TIME ORIGINAL'
        WHEN p.team_id IS NULL THEN 'SEM DONO ATUAL'
        ELSE 'OK'
    END AS status
FROM picks p
WHERE p.original_team_id IS NULL OR p.team_id IS NULL;

-- 6. VERIFICAR JOGADORES ÓRFÃOS (sem time ou com team_id inválido)
SELECT 
    p.id,
    p.name,
    p.team_id,
    t.city,
    t.name AS team_name
FROM players p
LEFT JOIN teams t ON p.team_id = t.id
WHERE t.id IS NULL;

-- 7. RESUMO GERAL POR LIGA
SELECT 
    t.league,
    COUNT(DISTINCT p.id) AS total_jogadores,
    COUNT(DISTINCT p.name) AS nomes_unicos,
    COUNT(DISTINCT p.id) - COUNT(DISTINCT p.name) AS nomes_duplicados
FROM players p
JOIN teams t ON p.team_id = t.id
GROUP BY t.league
ORDER BY t.league;
