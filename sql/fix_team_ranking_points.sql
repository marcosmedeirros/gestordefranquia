-- Corrigir erro de coluna 'points' ausente em team_ranking_points
ALTER TABLE team_ranking_points ADD COLUMN points INT DEFAULT 0 AFTER season_id;