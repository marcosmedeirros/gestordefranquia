-- Rollback pontual da ULTIMA atualizacao de pontos da liga ELITE (quando foi aplicada em dobro).
-- Este script NAO recalcula ranking inteiro: ele remove apenas 1x os pontos da temporada mais recentemente atualizada.
-- Se quiser forcar uma temporada especifica, troque o valor de @season_id manualmente.

SET @league = 'ELITE';
SET @season_id = NULL;

-- Auto-detecta a temporada mais recentemente alterada da liga.
SELECT @season_id := x.season_id
FROM (
    SELECT tsp.season_id
    FROM team_season_points tsp
    WHERE tsp.league = @league
    GROUP BY tsp.season_id
    ORDER BY MAX(tsp.updated_at) DESC, tsp.season_id DESC
    LIMIT 1
) x;

START TRANSACTION;

-- 1) Preview: quanto sera removido por time (1x).
SELECT
    t.id,
    CONCAT(t.city, ' ', t.name) AS team_name,
    t.ranking_points AS ranking_points_antes,
    tsp.points AS remover_agora,
    GREATEST(COALESCE(t.ranking_points, 0) - COALESCE(tsp.points, 0), 0) AS ranking_points_depois
FROM teams t
JOIN team_season_points tsp
    ON tsp.team_id = t.id
   AND tsp.league = @league
   AND tsp.season_id = @season_id
WHERE t.league = @league
ORDER BY team_name;

-- 2) Rollback: tira apenas uma aplicacao da ultima atualizacao (a duplicada).
UPDATE teams t
JOIN team_season_points tsp
    ON tsp.team_id = t.id
   AND tsp.league = @league
   AND tsp.season_id = @season_id
SET t.ranking_points = GREATEST(COALESCE(t.ranking_points, 0) - COALESCE(tsp.points, 0), 0)
WHERE t.league = @league;

-- 3) Verificacao pos-ajuste.
SELECT
    t.id,
    CONCAT(t.city, ' ', t.name) AS team_name,
    t.ranking_points AS ranking_points_atual,
    tsp.points AS abatido_nesta_execucao,
    @season_id AS season_id_ajustada
FROM teams t
JOIN team_season_points tsp
    ON tsp.team_id = t.id
   AND tsp.league = @league
   AND tsp.season_id = @season_id
WHERE t.league = @league
ORDER BY team_name;

COMMIT;
