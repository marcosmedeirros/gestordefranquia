<?php
/**
 * Schema Migration System
 * Verifica e cria/atualiza schema automaticamente
 */

require_once __DIR__ . '/db.php';

function runMigrations() {
    $pdo = db();
    
    // Array de migrações com nome único para rastrear execução
    $migrations = [
        'create_leagues' => [
            'condition' => "SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME = 'leagues'",
            'sql' => "CREATE TABLE IF NOT EXISTS leagues (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL UNIQUE,
                description VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_league_settings' => [
            'sql' => "CREATE TABLE IF NOT EXISTS league_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL UNIQUE,
                cap_min INT NOT NULL DEFAULT 0,
                cap_max INT NOT NULL DEFAULT 0,
                max_trades INT NOT NULL DEFAULT 3,
                edital TEXT NULL,
                edital_file VARCHAR(255) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_league_settings_league (league)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_sprints' => [
            'sql' => "CREATE TABLE IF NOT EXISTS sprints (
                id INT AUTO_INCREMENT PRIMARY KEY,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
                sprint_number INT NOT NULL,
                start_year INT NULL,
                start_date DATE NOT NULL,
                end_date DATE NULL,
                status ENUM('active','completed') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_league_sprint (league, sprint_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_league_sprint_config' => [
            'sql' => "CREATE TABLE IF NOT EXISTS league_sprint_config (
                league ENUM('ELITE','NEXT','RISE','ROOKIE') PRIMARY KEY,
                max_seasons INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'seed_league_settings' => [
            'sql' => "INSERT IGNORE INTO league_settings (league, cap_min, cap_max, max_trades) VALUES
                ('ELITE', 618, 648, 3),
                ('NEXT', 618, 648, 3),
                ('RISE', 618, 648, 3),
                ('ROOKIE', 618, 648, 3);"
        ],
        'seed_league_sprint_config' => [
            'sql' => "INSERT INTO league_sprint_config (league, max_seasons) VALUES
                ('ELITE', 20),
                ('NEXT', 15),
                ('RISE', 10),
                ('ROOKIE', 10)
            ON DUPLICATE KEY UPDATE max_seasons = VALUES(max_seasons);"
        ],
        'insert_leagues' => [
            'condition' => "SELECT COUNT(*) as cnt FROM leagues",
            'sql' => "INSERT IGNORE INTO leagues (name, description) VALUES 
                ('ELITE', 'Liga Elite - Jogadores experientes'),
                ('NEXT', 'Liga Next - Jogadores intermediários avançados'),
                ('RISE', 'Liga Rise - Jogadores intermediários'),
                ('ROOKIE', 'Liga Rookie - Jogadores iniciantes');"
        ],
        'create_users' => [
            'sql' => "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                photo_url VARCHAR(255) NULL,
                phone VARCHAR(30) NULL,
                user_type ENUM('jogador','admin') NOT NULL DEFAULT 'jogador',
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
                email_verified TINYINT(1) NOT NULL DEFAULT 0,
                verification_token VARCHAR(64) DEFAULT NULL,
                reset_token VARCHAR(64) DEFAULT NULL,
                reset_token_expiry DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_league (league),
                INDEX idx_user_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_divisions' => [
            'sql' => "CREATE TABLE IF NOT EXISTS divisions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
                importance INT DEFAULT 0,
                champions TEXT NULL,
                UNIQUE KEY uniq_division_league (name, league),
                INDEX idx_division_league (league)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
    'create_teams' => [
            'sql' => "CREATE TABLE IF NOT EXISTS teams (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
        current_cycle INT NOT NULL DEFAULT 1,
                conference ENUM('LESTE','OESTE') NULL,
                name VARCHAR(120) NOT NULL,
                city VARCHAR(120) NOT NULL,
                mascot VARCHAR(120) NOT NULL,
                photo_url VARCHAR(255) NULL,
                division_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_team_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_team_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL,
                INDEX idx_team_league (league),
                INDEX idx_team_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_players' => [
            'sql' => "CREATE TABLE IF NOT EXISTS players (
                id INT AUTO_INCREMENT PRIMARY KEY,
                team_id INT NOT NULL,
                name VARCHAR(120) NOT NULL,
                age INT NOT NULL,
                seasons_in_league INT NOT NULL DEFAULT 0,
                position VARCHAR(20) NOT NULL,
                secondary_position VARCHAR(20) NULL,
                role ENUM('Titular','Banco','Outro','G-League') NOT NULL DEFAULT 'Titular',
                available_for_trade TINYINT(1) NOT NULL DEFAULT 0,
                ovr INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_player_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                INDEX idx_player_team (team_id),
                INDEX idx_player_ovr (ovr)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_picks' => [
            'sql' => "CREATE TABLE IF NOT EXISTS picks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                team_id INT NOT NULL,
                original_team_id INT NOT NULL,
                season_year INT NOT NULL,
                round ENUM('1','2') NOT NULL,
                season_id INT NULL,
                auto_generated TINYINT(1) NOT NULL DEFAULT 0,
                last_owner_team_id INT NULL,
                notes VARCHAR(255) NULL,
                CONSTRAINT fk_pick_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                CONSTRAINT fk_pick_original_team FOREIGN KEY (original_team_id) REFERENCES teams(id) ON DELETE CASCADE,
                CONSTRAINT fk_pick_last_owner_team FOREIGN KEY (last_owner_team_id) REFERENCES teams(id) ON DELETE SET NULL,
                CONSTRAINT fk_pick_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE SET NULL,
                UNIQUE KEY uniq_pick (original_team_id, season_year, round),
                INDEX idx_pick_season (season_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_drafts' => [
            'sql' => "CREATE TABLE IF NOT EXISTS drafts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                year INT NOT NULL,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_draft_year_league (year, league),
                INDEX idx_draft_league (league)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_draft_players' => [
            'sql' => "CREATE TABLE IF NOT EXISTS draft_players (
                id INT AUTO_INCREMENT PRIMARY KEY,
                draft_id INT NOT NULL,
                name VARCHAR(120) NOT NULL,
                position VARCHAR(20) NOT NULL,
                age INT NOT NULL,
                ovr INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_draft_player_draft FOREIGN KEY (draft_id) REFERENCES drafts(id) ON DELETE CASCADE,
                INDEX idx_draft_player_draft (draft_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_seasons' => [
            'sql' => "CREATE TABLE IF NOT EXISTS seasons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sprint_id INT NOT NULL,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
                season_number INT NOT NULL,
                year INT NOT NULL,
                start_date DATE NULL,
                end_date DATE NULL,
                status ENUM('draft','regular','playoffs','completed') DEFAULT 'draft',
                current_phase VARCHAR(50) DEFAULT 'draft',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_season_sprint FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE,
                INDEX idx_season_league (league),
                INDEX idx_season_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_draft_pool' => [
            'sql' => "CREATE TABLE IF NOT EXISTS draft_pool (
                id INT AUTO_INCREMENT PRIMARY KEY,
                season_id INT NOT NULL,
                name VARCHAR(120) NOT NULL,
                position ENUM('PG','SG','SF','PF','C') NOT NULL,
                secondary_position ENUM('PG','SG','SF','PF','C') NULL,
                age INT NOT NULL,
                ovr INT NOT NULL,
                photo_url VARCHAR(255) NULL,
                bio TEXT NULL,
                strengths TEXT NULL,
                weaknesses TEXT NULL,
                draft_status ENUM('available','drafted') DEFAULT 'available',
                drafted_by_team_id INT NULL,
                draft_order INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_draft_pool_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
                CONSTRAINT fk_draft_pool_team FOREIGN KEY (drafted_by_team_id) REFERENCES teams(id) ON DELETE SET NULL,
                INDEX idx_draft_pool_season_status (season_id, draft_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_season_standings' => [
            'sql' => "CREATE TABLE IF NOT EXISTS season_standings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                season_id INT NOT NULL,
                team_id INT NOT NULL,
                wins INT DEFAULT 0,
                losses INT DEFAULT 0,
                points_for INT DEFAULT 0,
                points_against INT DEFAULT 0,
                position INT NULL,
                conference VARCHAR(50) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_standings_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
                CONSTRAINT fk_standings_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                UNIQUE KEY uniq_season_team (season_id, team_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_team_ranking_points' => [
            'sql' => "CREATE TABLE IF NOT EXISTS team_ranking_points (
                id INT AUTO_INCREMENT PRIMARY KEY,
                team_id INT NOT NULL,
                season_id INT NOT NULL,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
                regular_season_points INT DEFAULT 0,
                playoff_champion TINYINT(1) DEFAULT 0,
                playoff_runner_up TINYINT(1) DEFAULT 0,
                playoff_conference_finals TINYINT(1) DEFAULT 0,
                playoff_second_round TINYINT(1) DEFAULT 0,
                playoff_first_round TINYINT(1) DEFAULT 0,
                playoff_points INT DEFAULT 0,
                awards_count INT DEFAULT 0,
                awards_points INT DEFAULT 0,
                points INT DEFAULT 0,
                reason VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_rank_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                CONSTRAINT fk_rank_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
                INDEX idx_rank_team (team_id),
                INDEX idx_rank_season (season_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_waivers' => [
            'sql' => "CREATE TABLE IF NOT EXISTS waivers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                season_id INT NOT NULL,
                team_id INT NOT NULL,
                player_id INT NOT NULL,
                claim_order INT DEFAULT 0,
                status ENUM('pending','approved','denied') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_waivers_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
                CONSTRAINT fk_waivers_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                CONSTRAINT fk_waivers_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
                INDEX idx_waivers_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_awards' => [
            'sql' => "CREATE TABLE IF NOT EXISTS awards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                season_year INT NOT NULL,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
                award_name VARCHAR(120) NOT NULL,
                team_id INT,
                player_name VARCHAR(120),
                points INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_awards_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
                INDEX idx_awards_season (season_year),
                INDEX idx_awards_league (league),
                INDEX idx_awards_team (team_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_playoff_results' => [
            'sql' => "CREATE TABLE IF NOT EXISTS playoff_results (
                id INT AUTO_INCREMENT PRIMARY KEY,
                season_id INT NOT NULL,
                team_id INT NOT NULL,
                position ENUM('champion','runner_up','conference_final','second_round','first_round') NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_playoff_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
                CONSTRAINT fk_playoff_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                INDEX idx_playoff_season (season_id),
                INDEX idx_playoff_team (team_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_season_awards' => [
            'sql' => "CREATE TABLE IF NOT EXISTS season_awards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                season_id INT NOT NULL,
                team_id INT,
                award_type VARCHAR(50) NOT NULL,
                player_name VARCHAR(120) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_award_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
                CONSTRAINT fk_award_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
                INDEX idx_award_season (season_id),
                INDEX idx_award_team (team_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_directives' => [
            'sql' => "CREATE TABLE IF NOT EXISTS directives (
                id INT AUTO_INCREMENT PRIMARY KEY,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
                directive_name VARCHAR(120) NOT NULL,
                deadline DATE NOT NULL,
                description TEXT,
                active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_directive_league (league)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_trades' => [
            'sql' => "CREATE TABLE IF NOT EXISTS trades (
                id INT AUTO_INCREMENT PRIMARY KEY,
                from_team_id INT NOT NULL,
                to_team_id INT NOT NULL,
                status ENUM('pending','accepted','rejected','cancelled','countered') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                notes TEXT,
                FOREIGN KEY (from_team_id) REFERENCES teams(id) ON DELETE CASCADE,
                FOREIGN KEY (to_team_id) REFERENCES teams(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        'create_trade_items' => [
            'sql' => "CREATE TABLE IF NOT EXISTS trade_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trade_id INT NOT NULL,
                player_id INT NULL,
                pick_id INT NULL,
                from_team TINYINT(1) DEFAULT 1,
                FOREIGN KEY (trade_id) REFERENCES trades(id) ON DELETE CASCADE,
                FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
                FOREIGN KEY (pick_id) REFERENCES picks(id) ON DELETE CASCADE,
                INDEX idx_trade_items_trade (trade_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ]
    ];

    $executed = 0;
    $errors = [];

    foreach ($migrations as $name => $migration) {
        try {
            // Executar a migração
            $pdo->exec($migration['sql']);
            $executed++;
            error_log("[MIGRATION] ✓ {$name} executada com sucesso");
        } catch (PDOException $e) {
            $errors[] = "{$name}: " . $e->getMessage();
            error_log("[MIGRATION] ✗ {$name} falhou: " . $e->getMessage());
        }
    }

    // Ajustes de schema legado
    try {
        $hasPlayoffPosition = $pdo->query("SHOW COLUMNS FROM playoff_results LIKE 'playoff_position'")->fetch();
        if ($hasPlayoffPosition) {
            $pdo->exec("ALTER TABLE playoff_results CHANGE playoff_position position ENUM('champion','runner_up','conference_final','second_round','first_round') NOT NULL");
        }
        $hasSeasonId = $pdo->query("SHOW COLUMNS FROM playoff_results LIKE 'season_id'")->fetch();
        if (!$hasSeasonId) {
            $pdo->exec("ALTER TABLE playoff_results ADD COLUMN season_id INT NOT NULL AFTER id");
            $pdo->exec("ALTER TABLE playoff_results ADD CONSTRAINT fk_playoff_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE");
        }
        $hasSeasonYear = $pdo->query("SHOW COLUMNS FROM playoff_results LIKE 'season_year'")->fetch();
        if ($hasSeasonYear) {
            $pdo->exec("ALTER TABLE playoff_results DROP COLUMN season_year");
        }
        $hasLeague = $pdo->query("SHOW COLUMNS FROM playoff_results LIKE 'league'")->fetch();
        if ($hasLeague) {
            $pdo->exec("ALTER TABLE playoff_results DROP COLUMN league");
        }
        $hasPoints = $pdo->query("SHOW COLUMNS FROM playoff_results LIKE 'points'")->fetch();
        if ($hasPoints) {
            $pdo->exec("ALTER TABLE playoff_results DROP COLUMN points");
        }
    } catch (PDOException $e) {
        $errors[] = "ajuste_playoff_results: " . $e->getMessage();
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS season_awards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            season_id INT NOT NULL,
            team_id INT,
            award_type VARCHAR(50) NOT NULL,
            player_name VARCHAR(120) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_award_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
            CONSTRAINT fk_award_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
            INDEX idx_award_season (season_id),
            INDEX idx_award_team (team_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $e) {
        $errors[] = "ajuste_season_awards: " . $e->getMessage();
    }

    try {
        $hasPhone = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();
        if (!$hasPhone) {
            $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL AFTER photo_url, ADD INDEX idx_user_phone (phone)");
        }
    } catch (PDOException $e) {
        $errors[] = "ajuste_users_phone: " . $e->getMessage();
    }

    try {
        $hasLeagueSettings = $pdo->query("SHOW TABLES LIKE 'league_settings'")->fetch();
        if ($hasLeagueSettings) {
            $hasMaxTrades = $pdo->query("SHOW COLUMNS FROM league_settings LIKE 'max_trades'")->fetch();
            if (!$hasMaxTrades) {
                $pdo->exec("ALTER TABLE league_settings ADD COLUMN max_trades INT NOT NULL DEFAULT 3 AFTER cap_max");
            }

            $hasEdital = $pdo->query("SHOW COLUMNS FROM league_settings LIKE 'edital'")->fetch();
            if (!$hasEdital) {
                $pdo->exec("ALTER TABLE league_settings ADD COLUMN edital TEXT NULL AFTER max_trades");
            }

            $hasEditalFile = $pdo->query("SHOW COLUMNS FROM league_settings LIKE 'edital_file'")->fetch();
            if (!$hasEditalFile) {
                $pdo->exec("ALTER TABLE league_settings ADD COLUMN edital_file VARCHAR(255) NULL AFTER edital");
            }

            $pdo->exec("UPDATE league_settings SET max_trades = 3 WHERE max_trades IS NULL OR max_trades = 0");
            $pdo->exec("INSERT IGNORE INTO league_settings (league, cap_min, cap_max, max_trades) VALUES
                ('ELITE', 618, 648, 3),
                ('NEXT', 618, 648, 3),
                ('RISE', 618, 648, 3),
                ('ROOKIE', 618, 648, 3)");
        }
    } catch (PDOException $e) {
        $errors[] = "ajuste_league_settings: " . $e->getMessage();
    }

    try {
        $hasSeasonsTable = $pdo->query("SHOW TABLES LIKE 'seasons'")->fetch();
        if ($hasSeasonsTable) {
            $hasSprintId = $pdo->query("SHOW COLUMNS FROM seasons LIKE 'sprint_id'")->fetch();
            if (!$hasSprintId) {
                $pdo->exec("ALTER TABLE seasons ADD COLUMN sprint_id INT NULL AFTER id");
            }

            $hasSeasonNumber = $pdo->query("SHOW COLUMNS FROM seasons LIKE 'season_number'")->fetch();
            if (!$hasSeasonNumber) {
                $pdo->exec("ALTER TABLE seasons ADD COLUMN season_number INT NOT NULL DEFAULT 1 AFTER league");
            }

            $hasStartDate = $pdo->query("SHOW COLUMNS FROM seasons LIKE 'start_date'")->fetch();
            if (!$hasStartDate) {
                $pdo->exec("ALTER TABLE seasons ADD COLUMN start_date DATE NULL AFTER year");
            }

            $hasEndDate = $pdo->query("SHOW COLUMNS FROM seasons LIKE 'end_date'")->fetch();
            if (!$hasEndDate) {
                $pdo->exec("ALTER TABLE seasons ADD COLUMN end_date DATE NULL AFTER start_date");
            }

            $hasStatusCol = $pdo->query("SHOW COLUMNS FROM seasons LIKE 'status'")->fetch();
            if (!$hasStatusCol) {
                $pdo->exec("ALTER TABLE seasons ADD COLUMN status ENUM('draft','regular','playoffs','completed') DEFAULT 'draft' AFTER end_date");
            }

            $hasCurrentPhase = $pdo->query("SHOW COLUMNS FROM seasons LIKE 'current_phase'")->fetch();
            if (!$hasCurrentPhase) {
                $pdo->exec("ALTER TABLE seasons ADD COLUMN current_phase VARCHAR(50) DEFAULT 'draft' AFTER status");
            }

            $hasUpdatedAt = $pdo->query("SHOW COLUMNS FROM seasons LIKE 'updated_at'")->fetch();
            if (!$hasUpdatedAt) {
                $pdo->exec("ALTER TABLE seasons ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
            }

            // Garantir índices básicos
            $idxLeague = $pdo->query("SHOW INDEX FROM seasons WHERE Key_name = 'idx_season_league'")->fetch();
            if (!$idxLeague) {
                $pdo->exec("CREATE INDEX idx_season_league ON seasons(league)");
            }

            $idxStatus = $pdo->query("SHOW INDEX FROM seasons WHERE Key_name = 'idx_season_status'")->fetch();
            if (!$idxStatus) {
                $pdo->exec("CREATE INDEX idx_season_status ON seasons(status)");
            }

            $uniqueYearIdx = $pdo->query("SHOW INDEX FROM seasons WHERE Key_name = 'year' AND Non_unique = 0")->fetch();
            if ($uniqueYearIdx) {
                $pdo->exec("ALTER TABLE seasons DROP INDEX `year`");
            }

            // Garante associação entre temporadas existentes e um sprint
            $seasonsMissingSprint = $pdo->query("SELECT DISTINCT league FROM seasons WHERE sprint_id IS NULL OR sprint_id = 0")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($seasonsMissingSprint as $league) {
                $stmtSprint = $pdo->prepare("SELECT id FROM sprints WHERE league = ? ORDER BY id ASC LIMIT 1");
                $stmtSprint->execute([$league]);
                $sprintId = $stmtSprint->fetchColumn();

                if (!$sprintId) {
                    $stmtInsertSprint = $pdo->prepare("INSERT INTO sprints (league, sprint_number, start_date, status) VALUES (?, 1, CURDATE(), 'active')");
                    $stmtInsertSprint->execute([$league]);
                    $sprintId = $pdo->lastInsertId();
                }

                $stmtUpdateSeason = $pdo->prepare("UPDATE seasons SET sprint_id = ? WHERE (sprint_id IS NULL OR sprint_id = 0) AND league = ?");
                $stmtUpdateSeason->execute([$sprintId, $league]);
            }

            // Tornar sprint_id obrigatório após preenchimento
            $pdo->exec("ALTER TABLE seasons MODIFY sprint_id INT NOT NULL");

            // Foreign key sprint
            $hasSprintFk = $pdo->prepare("
                SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'seasons'
                  AND COLUMN_NAME = 'sprint_id'
                  AND REFERENCED_TABLE_NAME = 'sprints'
            ");
            $hasSprintFk->execute();
            if (!$hasSprintFk->fetch()) {
                $pdo->exec("ALTER TABLE seasons ADD CONSTRAINT fk_season_sprint FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE CASCADE");
            }
        }
    } catch (PDOException $e) {
        $errors[] = "ajuste_seasons: " . $e->getMessage();
    }

    try {
        $hasPicksTable = $pdo->query("SHOW TABLES LIKE 'picks'")->fetch();
        if ($hasPicksTable) {
            $hasSeasonId = $pdo->query("SHOW COLUMNS FROM picks LIKE 'season_id'")->fetch();
            if (!$hasSeasonId) {
                $pdo->exec("ALTER TABLE picks ADD COLUMN season_id INT NULL AFTER round");
            }

            $hasAutoGenerated = $pdo->query("SHOW COLUMNS FROM picks LIKE 'auto_generated'")->fetch();
            if (!$hasAutoGenerated) {
                $pdo->exec("ALTER TABLE picks ADD COLUMN auto_generated TINYINT(1) NOT NULL DEFAULT 0 AFTER season_id");
            }

            $hasLastOwner = $pdo->query("SHOW COLUMNS FROM picks LIKE 'last_owner_team_id'")->fetch();
            if (!$hasLastOwner) {
                $pdo->exec("ALTER TABLE picks ADD COLUMN last_owner_team_id INT NULL AFTER auto_generated");
                try {
                    $pdo->exec("ALTER TABLE picks ADD CONSTRAINT fk_pick_last_owner_team FOREIGN KEY (last_owner_team_id) REFERENCES teams(id) ON DELETE SET NULL");
                } catch (PDOException $e) {
                    $errors[] = "fk_pick_last_owner_team: " . $e->getMessage();
                }
                try {
                    $pdo->exec("UPDATE picks SET last_owner_team_id = team_id WHERE last_owner_team_id IS NULL");
                } catch (PDOException $e) {
                    $errors[] = "init_last_owner_team: " . $e->getMessage();
                }
            }

            $idxPickSeason = $pdo->query("SHOW INDEX FROM picks WHERE Key_name = 'idx_pick_season'")->fetch();
            if (!$idxPickSeason) {
                $pdo->exec("CREATE INDEX idx_pick_season ON picks(season_id)");
            }

            // Consolidar duplicatas de picks para garantir que cada combinação exista apenas uma vez
            $dupStmt = $pdo->query("SELECT original_team_id, season_year, round
                FROM picks
                GROUP BY original_team_id, season_year, round
                HAVING COUNT(*) > 1");
            $duplicates = $dupStmt ? $dupStmt->fetchAll(PDO::FETCH_ASSOC) : [];

            if (!empty($duplicates)) {
                $stmtDupRows = $pdo->prepare("SELECT id, team_id, auto_generated, season_id, notes
                    FROM picks
                    WHERE original_team_id = ? AND season_year = ? AND round = ?
                    ORDER BY auto_generated ASC, id DESC");
                $stmtUpdatePick = $pdo->prepare("UPDATE picks SET team_id = ?, auto_generated = ?, season_id = ?, notes = ? WHERE id = ?");
                $stmtDeletePick = $pdo->prepare("DELETE FROM picks WHERE id = ?");

                foreach ($duplicates as $dup) {
                    $stmtDupRows->execute([$dup['original_team_id'], $dup['season_year'], $dup['round']]);
                    $rows = $stmtDupRows->fetchAll(PDO::FETCH_ASSOC);
                    if (count($rows) <= 1) {
                        continue;
                    }

                    $chosen = $rows[0]; // Prioriza registros manuais (auto_generated = 0) mais recentes
                    $canonical = null;
                    foreach ($rows as $row) {
                        if ((int)($row['auto_generated'] ?? 0) === 1) {
                            $canonical = $row;
                            break;
                        }
                    }
                    if (!$canonical) {
                        $canonical = $rows[0];
                    }

                    if ($canonical['id'] !== $chosen['id']) {
                        $stmtUpdatePick->execute([
                            $chosen['team_id'],
                            $chosen['auto_generated'],
                            $chosen['season_id'],
                            $chosen['notes'],
                            $canonical['id']
                        ]);
                    }

                    foreach ($rows as $row) {
                        if ($row['id'] == $canonical['id']) {
                            continue;
                        }
                        $stmtDeletePick->execute([$row['id']]);
                    }
                }
            }

            // Garantir índice único correto para picks (considera time original)
            $stmtUniqPick = $pdo->prepare("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) as cols
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'picks'
                  AND INDEX_NAME = 'uniq_pick'");
            $stmtUniqPick->execute();
            $uniqPickCols = $stmtUniqPick->fetchColumn();
            $expectedCols = 'original_team_id,season_year,round';
            if ($uniqPickCols !== $expectedCols) {
                try {
                    $pdo->exec("ALTER TABLE picks DROP INDEX uniq_pick");
                } catch (PDOException $e) {
                    $errors[] = "drop_uniq_pick: " . $e->getMessage();
                }
                try {
                    $pdo->exec("ALTER TABLE picks ADD UNIQUE KEY uniq_pick (original_team_id, season_year, round)");
                } catch (PDOException $e) {
                    $errors[] = "create_uniq_pick: " . $e->getMessage();
                }
            }

            $stmtFk = $pdo->prepare("
                SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'picks'
                  AND COLUMN_NAME = 'season_id'
                  AND REFERENCED_TABLE_NAME = 'seasons'
            ");
            $stmtFk->execute();
            if (!$stmtFk->fetch()) {
                // Remove FK duplicado se existir com outro nome apontando para tabela antiga
                $pdo->exec("ALTER TABLE picks ADD CONSTRAINT fk_pick_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE SET NULL");
            }
        }
    } catch (PDOException $e) {
        $errors[] = "ajuste_picks: " . $e->getMessage();
    }

    try {
        $hasTeamsTable = $pdo->query("SHOW TABLES LIKE 'teams'")->fetch();
        if ($hasTeamsTable) {
            $hasCurrentCycle = $pdo->query("SHOW COLUMNS FROM teams LIKE 'current_cycle'")->fetch();
            if (!$hasCurrentCycle) {
                $pdo->exec("ALTER TABLE teams ADD COLUMN current_cycle INT NOT NULL DEFAULT 1 AFTER league");
            }
        }
    } catch (PDOException $e) {
        $errors[] = "ajuste_teams: " . $e->getMessage();
    }

    try {
        $hasPlayersTable = $pdo->query("SHOW TABLES LIKE 'players'")->fetch();
        if ($hasPlayersTable) {
            $hasSeasonsInLeague = $pdo->query("SHOW COLUMNS FROM players LIKE 'seasons_in_league'")->fetch();
            if (!$hasSeasonsInLeague) {
                $pdo->exec("ALTER TABLE players ADD COLUMN seasons_in_league INT NOT NULL DEFAULT 0 AFTER age");
            }

            $hasSecondaryPosition = $pdo->query("SHOW COLUMNS FROM players LIKE 'secondary_position'")->fetch();
            if (!$hasSecondaryPosition) {
                $pdo->exec("ALTER TABLE players ADD COLUMN secondary_position VARCHAR(20) NULL AFTER position");
            }
        }
    } catch (PDOException $e) {
        $errors[] = "ajuste_players: " . $e->getMessage();
    }

    try {
        $hasTradesTable = $pdo->query("SHOW TABLES LIKE 'trades'")->fetch();
        if ($hasTradesTable) {
            $hasFromTeamId = $pdo->query("SHOW COLUMNS FROM trades LIKE 'from_team_id'")->fetch();
            if (!$hasFromTeamId) {
                $hasTeamFrom = $pdo->query("SHOW COLUMNS FROM trades LIKE 'team_from'")->fetch();
                if ($hasTeamFrom) {
                    $pdo->exec("ALTER TABLE trades CHANGE COLUMN team_from from_team_id INT NOT NULL");
                } else {
                    $pdo->exec("ALTER TABLE trades ADD COLUMN from_team_id INT NOT NULL AFTER id");
                }
            }

            $hasToTeamId = $pdo->query("SHOW COLUMNS FROM trades LIKE 'to_team_id'")->fetch();
            if (!$hasToTeamId) {
                $hasTeamTo = $pdo->query("SHOW COLUMNS FROM trades LIKE 'team_to'")->fetch();
                if ($hasTeamTo) {
                    $pdo->exec("ALTER TABLE trades CHANGE COLUMN team_to to_team_id INT NOT NULL");
                } else {
                    $pdo->exec("ALTER TABLE trades ADD COLUMN to_team_id INT NOT NULL AFTER from_team_id");
                }
            }

            $hasStatus = $pdo->query("SHOW COLUMNS FROM trades LIKE 'status'")->fetch();
            if (!$hasStatus) {
                $pdo->exec("ALTER TABLE trades ADD COLUMN status ENUM('pending','accepted','rejected','cancelled','countered') DEFAULT 'pending' AFTER to_team_id");
            } else {
                $pdo->exec("ALTER TABLE trades MODIFY COLUMN status ENUM('pending','accepted','rejected','cancelled','countered') DEFAULT 'pending'");
            }

            $hasCreatedAt = $pdo->query("SHOW COLUMNS FROM trades LIKE 'created_at'")->fetch();
            if (!$hasCreatedAt) {
                $pdo->exec("ALTER TABLE trades ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status");
            }

            $hasUpdatedAt = $pdo->query("SHOW COLUMNS FROM trades LIKE 'updated_at'")->fetch();
            if (!$hasUpdatedAt) {
                $pdo->exec("ALTER TABLE trades ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
            }

            $hasNotes = $pdo->query("SHOW COLUMNS FROM trades LIKE 'notes'")->fetch();
            if (!$hasNotes) {
                $pdo->exec("ALTER TABLE trades ADD COLUMN notes TEXT NULL AFTER updated_at");
            }

            $hasLeagueColumn = $pdo->query("SHOW COLUMNS FROM trades LIKE 'league'")->fetch();
            if (!$hasLeagueColumn) {
                $pdo->exec("ALTER TABLE trades ADD COLUMN league ENUM('ELITE','NEXT','RISE','ROOKIE') NULL AFTER to_team_id");
                try {
                    $pdo->exec("UPDATE trades t JOIN teams tf ON t.from_team_id = tf.id SET t.league = tf.league WHERE t.league IS NULL");
                } catch (PDOException $e) {
                    $errors[] = "populate_trade_league: " . $e->getMessage();
                }
                try {
                    $pdo->exec("ALTER TABLE trades MODIFY COLUMN league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL");
                } catch (PDOException $e) {
                    $errors[] = "enforce_trade_league_not_null: " . $e->getMessage();
                }
            }

            $hasResponseNotes = $pdo->query("SHOW COLUMNS FROM trades LIKE 'response_notes'")->fetch();
            if (!$hasResponseNotes) {
                $pdo->exec("ALTER TABLE trades ADD COLUMN response_notes TEXT NULL AFTER notes");
            }

            $idxFrom = $pdo->query("SHOW INDEX FROM trades WHERE Key_name = 'idx_trades_from_team'")->fetch();
            if (!$idxFrom) {
                $pdo->exec("CREATE INDEX idx_trades_from_team ON trades(from_team_id)");
            }

            $idxTo = $pdo->query("SHOW INDEX FROM trades WHERE Key_name = 'idx_trades_to_team'")->fetch();
            if (!$idxTo) {
                $pdo->exec("CREATE INDEX idx_trades_to_team ON trades(to_team_id)");
            }

            $idxStatus = $pdo->query("SHOW INDEX FROM trades WHERE Key_name = 'idx_trades_status'")->fetch();
            if (!$idxStatus) {
                $pdo->exec("CREATE INDEX idx_trades_status ON trades(status)");
            }
        }

        $hasTradeItems = $pdo->query("SHOW TABLES LIKE 'trade_items'")->fetch();
        if (!$hasTradeItems) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS trade_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trade_id INT NOT NULL,
                player_id INT NULL,
                pick_id INT NULL,
                from_team TINYINT(1) DEFAULT 1,
                FOREIGN KEY (trade_id) REFERENCES trades(id) ON DELETE CASCADE,
                FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
                FOREIGN KEY (pick_id) REFERENCES picks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } else {
            $idxTradeItems = $pdo->query("SHOW INDEX FROM trade_items WHERE Key_name = 'idx_trade_items_trade'")->fetch();
            if (!$idxTradeItems) {
                $pdo->exec("CREATE INDEX idx_trade_items_trade ON trade_items(trade_id)");
            }
        }
    } catch (PDOException $e) {
        $errors[] = "ajuste_trades: " . $e->getMessage();
    }

    try {
        $hasStartYearColumn = $pdo->query("SHOW COLUMNS FROM sprints LIKE 'start_year'")->fetch();
        if (!$hasStartYearColumn) {
            $pdo->exec("ALTER TABLE sprints ADD COLUMN start_year INT NULL AFTER sprint_number");
        }
    } catch (PDOException $e) {
        $errors[] = "ajuste_sprint_start_year: " . $e->getMessage();
    }

    try {
        $hasDirectiveDeadlines = $pdo->query("SHOW TABLES LIKE 'directive_deadlines'")->fetch();
        if ($hasDirectiveDeadlines) {
            $deadlineColumn = $pdo->query("SHOW COLUMNS FROM directive_deadlines LIKE 'deadline_date'")->fetch(PDO::FETCH_ASSOC);
            if ($deadlineColumn && stripos($deadlineColumn['Type'], 'datetime') === false) {
                $pdo->exec("ALTER TABLE directive_deadlines MODIFY COLUMN deadline_date DATETIME NOT NULL");
            }
        }
    } catch (PDOException $e) {
        $errors[] = "ajuste_directive_deadline_datetime: " . $e->getMessage();
    }

    return [
        'success' => count($errors) === 0,
        'executed' => $executed,
        'errors' => $errors,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

// Executar se chamado diretamente
if (php_sapi_name() === 'cli' || (isset($_GET['run_migrations']) && $_GET['run_migrations'] === 'true')) {
    $result = runMigrations();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
