-- Adicionar campo defensive_focus para a tabela team_directives
-- Data: 2026-01-15

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'team_directives'
        AND COLUMN_NAME = 'defensive_focus'
);

SET @sql := IF(@col_exists = 0,
    'ALTER TABLE team_directives ADD COLUMN defensive_focus ENUM(
        ''neutral'',           -- Neutral Defensive Focus
        ''protect_paint'',     -- Protect the Paint
        ''limit_perimeter'',   -- Limit Perimeter Shots
        ''no_preference''      -- No Preference
    ) DEFAULT ''no_preference'' COMMENT ''Foco defensivo'' AFTER offensive_aggression',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
