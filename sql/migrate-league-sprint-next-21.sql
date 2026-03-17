-- Ajusta o limite de temporadas da liga NEXT para 21
UPDATE league_sprint_config
SET max_seasons = 21
WHERE league = 'NEXT';
