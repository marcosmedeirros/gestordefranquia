-- Atualização do formulário de diretrizes com novas opções
-- Data: Atualização do sistema

-- Alterar coluna game_style para novos valores
ALTER TABLE team_directives 
MODIFY COLUMN game_style ENUM(
    'balanced',           -- Balanced
    'triangle',           -- Triangle
    'grit_grind',         -- Grit & Grind
    'pace_space',         -- Pace & Space
    'perimeter_centric',  -- Perimeter Centric
    'post_centric',       -- Post Centric
    'seven_seconds',      -- Seven Seconds
    'defense',            -- Defense
    'franchise_player',   -- Melhor esquema pro Franchise Player
    'most_stars'          -- Maior nº de Estrelas
) DEFAULT 'balanced' COMMENT 'Estilo de jogo';

-- Alterar coluna offense_style para novos valores
ALTER TABLE team_directives 
MODIFY COLUMN offense_style ENUM(
    'no_preference',      -- No Preference
    'pick_roll',          -- Pick & Roll Offense
    'neutral',            -- Neutral Offensive Focus
    'play_through_star',  -- Play Through Star
    'get_to_basket',      -- Get to The Basket
    'get_shooters_open',  -- Get Shooters Open
    'feed_post'           -- Feed The Post
) DEFAULT 'no_preference' COMMENT 'Estilo de ataque';

-- Alterar coluna rotation_style para novos valores
ALTER TABLE team_directives 
MODIFY COLUMN rotation_style ENUM(
    'manual',             -- Manual
    'auto'                -- Automática
) DEFAULT 'auto' COMMENT 'Estilo de rotação';

-- Alterar pace para novo campo attack_tempo (Tempo de Ataque)
ALTER TABLE team_directives 
MODIFY COLUMN pace ENUM(
    'no_preference',      -- No Preference
    'patient',            -- Patient Offense
    'average',            -- Average Tempo
    'shoot_at_will'       -- Shoot at Will
) DEFAULT 'no_preference' COMMENT 'Tempo de ataque';

-- Alterar offensive_aggression para defensive_aggression (Agressividade Defensiva)
ALTER TABLE team_directives 
MODIFY COLUMN offensive_aggression ENUM(
    'physical',           -- Play Physical Defense
    'no_preference',      -- No Preference
    'conservative',       -- Conservative Defense
    'neutral'             -- Neutral Defensive Aggression
) DEFAULT 'no_preference' COMMENT 'Agressividade defensiva';

-- Alterar offensive_rebound para novos valores (Rebote Ofensivo)
ALTER TABLE team_directives 
MODIFY COLUMN offensive_rebound ENUM(
    'limit_transition',   -- Limit Transition
    'no_preference',      -- No Preference
    'crash_glass',        -- Crash Offensive Glass
    'some_crash'          -- Some Crash Others Get Back
) DEFAULT 'no_preference' COMMENT 'Rebote ofensivo';

-- Alterar defensive_rebound para novos valores (Rebote Defensivo)
ALTER TABLE team_directives 
MODIFY COLUMN defensive_rebound ENUM(
    'run_transition',     -- Run in Transition
    'crash_glass',        -- Crash Defensive Glass
    'some_crash',         -- Some Crash Others Run
    'no_preference'       -- No Preference
) DEFAULT 'no_preference' COMMENT 'Rebote defensivo';

-- Remover defense_style (não usado mais)
ALTER TABLE team_directives 
DROP COLUMN defense_style;

-- Adicionar novos campos
ALTER TABLE team_directives 
ADD COLUMN rotation_players INT DEFAULT 10 COMMENT 'Jogadores na rotação (8-15)';

ALTER TABLE team_directives 
ADD COLUMN veteran_focus INT DEFAULT 50 COMMENT 'Foco em jogadores veteranos (0-100%)';

ALTER TABLE team_directives 
ADD COLUMN gleague_1_id INT NULL COMMENT 'Jogador 1 a mandar para G-League';

ALTER TABLE team_directives 
ADD COLUMN gleague_2_id INT NULL COMMENT 'Jogador 2 a mandar para G-League';

-- Adicionar foreign keys para G-League
ALTER TABLE team_directives 
ADD CONSTRAINT fk_directive_gleague1 FOREIGN KEY (gleague_1_id) REFERENCES players(id) ON DELETE SET NULL;

ALTER TABLE team_directives 
ADD CONSTRAINT fk_directive_gleague2 FOREIGN KEY (gleague_2_id) REFERENCES players(id) ON DELETE SET NULL;
