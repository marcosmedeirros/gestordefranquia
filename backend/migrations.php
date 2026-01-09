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
                user_type ENUM('jogador','admin') NOT NULL DEFAULT 'jogador',
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
                email_verified TINYINT(1) NOT NULL DEFAULT 0,
                verification_token VARCHAR(64) DEFAULT NULL,
                reset_token VARCHAR(64) DEFAULT NULL,
                reset_token_expiry DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_league (league)
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
                position VARCHAR(20) NOT NULL,
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
                notes VARCHAR(255) NULL,
                CONSTRAINT fk_pick_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                CONSTRAINT fk_pick_original_team FOREIGN KEY (original_team_id) REFERENCES teams(id) ON DELETE CASCADE,
                UNIQUE KEY uniq_pick (team_id, season_year, round)
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
                year INT NOT NULL UNIQUE,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
                status ENUM('planejamento','regular','playoffs','finalizado') DEFAULT 'planejamento',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_season_year_league (year, league),
                INDEX idx_season_league (league)
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
                season_year INT NOT NULL,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
                team_from INT NOT NULL,
                team_to INT NOT NULL,
                players_given TEXT,
                players_received TEXT,
                picks_given TEXT,
                picks_received TEXT,
                status ENUM('proposto','aceito','recusado') DEFAULT 'proposto',
                proposed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL,
                CONSTRAINT fk_trade_from FOREIGN KEY (team_from) REFERENCES teams(id) ON DELETE CASCADE,
                CONSTRAINT fk_trade_to FOREIGN KEY (team_to) REFERENCES teams(id) ON DELETE CASCADE,
                INDEX idx_trade_season (season_year),
                INDEX idx_trade_league (league)
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
