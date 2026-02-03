<?php
// Define timezone padrão para todo o sistema: São Paulo/Brasília
date_default_timezone_set('America/Sao_Paulo');

// Incluir funções de timezone
require_once __DIR__ . '/timezone.php';

function loadConfig(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        http_response_code(500);
        echo json_encode(['error' => 'Config file missing.']);
        exit;
    }

    $config = require $configFile;
    return $config;
}

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function requireMethod(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        jsonResponse(405, ['error' => 'Method not allowed']);
    }
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    return $data;
}

function sendVerificationEmail(string $email, string $token): bool
{
    $config = loadConfig();
    $verifyUrl = rtrim($config['mail']['verify_base_url'], '?') . '?token=' . urlencode($token);
    $subject = 'Verifique seu e-mail (FBA)';
    $message = "Clique para verificar seu e-mail: {$verifyUrl}";
    $headers = 'From: ' . $config['mail']['from'];
    // On Hostinger, ensure mail() is permitted; replace with SMTP if needed.
    return mail($email, $subject, $message, $headers);
}

function buildPasswordResetUrl(string $token): string
{
    $config = loadConfig();
    $resetBase = $config['mail']['reset_base_url'] ?? '';
    if (!$resetBase && !empty($config['mail']['verify_base_url'])) {
        $parts = parse_url($config['mail']['verify_base_url']);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $port = !empty($parts['port']) ? ':' . $parts['port'] : '';
            $resetBase = $parts['scheme'] . '://' . $parts['host'] . $port . '/reset-password.php';
        }
    }
    if (!$resetBase && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $resetBase = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/reset-password.php';
    }
    if (!$resetBase) {
        $resetBase = 'https://fbabrasil.com.br/reset-password.php';
    }

    if (str_contains($resetBase, '{token}')) {
        return str_replace('{token}', urlencode($token), $resetBase);
    }
    if (str_contains($resetBase, '?')) {
        $sep = str_ends_with($resetBase, '=') || str_contains($resetBase, 'token=') ? '' : '&token=';
        return $resetBase . $sep . urlencode($token);
    }
    return rtrim($resetBase, '/') . '?token=' . urlencode($token);
}

function sendPasswordResetEmail(string $email, string $token, string $name): bool
{
    $config = loadConfig();
    $resetUrl = buildPasswordResetUrl($token);
    $subject = 'Recuperação de Senha - FBA Manager';
    
    $message = "
Olá {$name},

Recebemos uma solicitação para redefinir sua senha do FBA Manager Control.

Clique no link abaixo para criar uma nova senha:
{$resetUrl}

Este link expira em 1 hora.

Se você não solicitou esta alteração, ignore este e-mail.

Atenciosamente,
Equipe FBA Manager
    ";
    
    $headers = 'From: ' . $config['mail']['from'];
    return mail($email, $subject, $message, $headers);
}

function topEightCap(PDO $pdo, int $teamId): int
{
    $stmt = $pdo->prepare('SELECT SUM(ovr) as cap FROM (
        SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8
    ) as ranked');
    $stmt->execute([$teamId]);
    $row = $stmt->fetch();
    return (int) ($row['cap'] ?? 0);
}

function capWithCandidate(PDO $pdo, int $teamId, int $candidateOvr): int
{
    $stmt = $pdo->prepare('SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8');
    $stmt->execute([$teamId]);
    $ovrs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $ovrs[] = $candidateOvr;
    rsort($ovrs, SORT_NUMERIC);
    $slice = array_slice($ovrs, 0, 8);
    return array_sum($slice);
}

function ensureTeamFreeAgencyColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        $needsWaivers = $pdo->query("SHOW COLUMNS FROM teams LIKE 'waivers_used'")->rowCount() === 0;
        if ($needsWaivers) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN waivers_used INT DEFAULT 0");
        }

        $needsSignings = $pdo->query("SHOW COLUMNS FROM teams LIKE 'fa_signings_used'")->rowCount() === 0;
        if ($needsSignings) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN fa_signings_used INT DEFAULT 0");
        }
            $needsFaResetYear = $pdo->query("SHOW COLUMNS FROM teams LIKE 'fa_reset_year'")->rowCount() === 0;
            if ($needsFaResetYear) {
                $pdo->exec("ALTER TABLE teams ADD COLUMN fa_reset_year INT NULL AFTER fa_signings_used");
            }
            $needsWaiversResetYear = $pdo->query("SHOW COLUMNS FROM teams LIKE 'waivers_reset_year'")->rowCount() === 0;
            if ($needsWaiversResetYear) {
                $pdo->exec("ALTER TABLE teams ADD COLUMN waivers_reset_year INT NULL AFTER waivers_used");
            }
    } catch (Exception $e) {
        error_log('[ensureTeamFreeAgencyColumns] ' . $e->getMessage());
    }

    $checked = true;
}

function ensureDirectiveOptionalColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        $tableExists = $pdo->query("SHOW TABLES LIKE 'directive_player_minutes'")->rowCount() > 0;
        if (!$tableExists) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS directive_player_minutes (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                directive_id INT NOT NULL,\n                player_id INT NOT NULL,\n                minutes_per_game INT NOT NULL,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                INDEX idx_directive_minutes_directive (directive_id),\n                INDEX idx_directive_minutes_player (player_id),\n                CONSTRAINT fk_minutes_directive FOREIGN KEY (directive_id) REFERENCES team_directives(id) ON DELETE CASCADE,\n                CONSTRAINT fk_minutes_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] directive_player_minutes: ' . $e->getMessage());
    }

    try {
        $hasRotationPlayers = $pdo->query("SHOW COLUMNS FROM team_directives LIKE 'rotation_players'")->rowCount() > 0;
        if (!$hasRotationPlayers) {
            $pdo->exec("ALTER TABLE team_directives ADD COLUMN rotation_players INT DEFAULT 10 COMMENT 'Jogadores na rotação (8-15)' AFTER offense_style");
        }
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] rotation_players: ' . $e->getMessage());
    }

    try {
        $hasVeteranFocus = $pdo->query("SHOW COLUMNS FROM team_directives LIKE 'veteran_focus'")->rowCount() > 0;
        if (!$hasVeteranFocus) {
            $pdo->exec("ALTER TABLE team_directives ADD COLUMN veteran_focus INT DEFAULT 50 COMMENT 'Preferência por veteranos (0-100)' AFTER rotation_players");
        }
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] veteran_focus: ' . $e->getMessage());
    }

    try {
        $hasGleague1 = $pdo->query("SHOW COLUMNS FROM team_directives LIKE 'gleague_1_id'")->rowCount() > 0;
        if (!$hasGleague1) {
            $pdo->exec("ALTER TABLE team_directives ADD COLUMN gleague_1_id INT NULL COMMENT 'Jogador 1 a mandar para G-League' AFTER veteran_focus");
        }
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] gleague_1_id: ' . $e->getMessage());
    }

    try {
        $hasGleague2 = $pdo->query("SHOW COLUMNS FROM team_directives LIKE 'gleague_2_id'")->rowCount() > 0;
        if (!$hasGleague2) {
            $pdo->exec("ALTER TABLE team_directives ADD COLUMN gleague_2_id INT NULL COMMENT 'Jogador 2 a mandar para G-League' AFTER gleague_1_id");
        }
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] gleague_2_id: ' . $e->getMessage());
    }

    try {
        $fks = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'team_directives' AND REFERENCED_TABLE_NAME = 'players' AND COLUMN_NAME = 'gleague_1_id'")->rowCount();
        if ($fks === 0) {
            $pdo->exec("ALTER TABLE team_directives ADD CONSTRAINT fk_directive_gleague1 FOREIGN KEY (gleague_1_id) REFERENCES players(id) ON DELETE SET NULL");
        }
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] fk gleague_1_id: ' . $e->getMessage());
    }

    try {
        $fks = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'team_directives' AND REFERENCED_TABLE_NAME = 'players' AND COLUMN_NAME = 'gleague_2_id'")->rowCount();
        if ($fks === 0) {
            $pdo->exec("ALTER TABLE team_directives ADD CONSTRAINT fk_directive_gleague2 FOREIGN KEY (gleague_2_id) REFERENCES players(id) ON DELETE SET NULL");
        }
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] fk gleague_2_id: ' . $e->getMessage());
    }

    // Garantir que colunas que armazenam opções sejam compatíveis com os novos valores do formulário
    $columnsToConvert = [
        'pace' => [
            'default' => 'no_preference',
            'comment' => 'Tempo de ataque'
        ],
        'offensive_rebound' => [
            'default' => 'no_preference',
            'comment' => 'Rebote ofensivo'
        ],
        'offensive_aggression' => [
            'default' => 'no_preference',
            'comment' => 'Agressividade defensiva'
        ],
        'defensive_rebound' => [
            'default' => 'no_preference',
            'comment' => 'Rebote defensivo'
        ],
        'rotation_style' => [
            'default' => 'auto',
            'comment' => 'Estilo de rotação'
        ],
        'game_style' => [
            'default' => 'balanced',
            'comment' => 'Estilo de jogo'
        ],
        'offense_style' => [
            'default' => 'no_preference',
            'comment' => 'Estilo de ataque'
        ]
    ];

    foreach ($columnsToConvert as $column => $meta) {
        try {
            $colInfo = $pdo->query("SHOW COLUMNS FROM team_directives LIKE '{$column}'")->fetch(PDO::FETCH_ASSOC);
            if (!$colInfo) {
                continue;
            }
            $type = strtolower((string)($colInfo['Type'] ?? ''));
            if (!str_contains($type, 'varchar')) {
                $pdo->exec("ALTER TABLE team_directives MODIFY COLUMN {$column} VARCHAR(50) DEFAULT '{$meta['default']}' COMMENT '{$meta['comment']}'");
            }
        } catch (Exception $e) {
            error_log("[ensureDirectiveOptionalColumns] {$column} modify: " . $e->getMessage());
        }
    }

    try {
        $pdo->exec("UPDATE team_directives SET pace = 'no_preference' WHERE pace IS NULL OR pace NOT IN ('no_preference','patient','average','shoot_at_will')");
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] pace normalize: ' . $e->getMessage());
    }

    try {
        $pdo->exec("UPDATE team_directives SET offensive_rebound = 'no_preference' WHERE offensive_rebound IS NULL OR offensive_rebound NOT IN ('limit_transition','no_preference','crash_glass','some_crash')");
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] offensive_rebound normalize: ' . $e->getMessage());
    }

    try {
        $pdo->exec("UPDATE team_directives SET offensive_aggression = 'no_preference' WHERE offensive_aggression IS NULL OR offensive_aggression NOT IN ('physical','no_preference','conservative','neutral')");
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] offensive_aggression normalize: ' . $e->getMessage());
    }

    try {
        $pdo->exec("UPDATE team_directives SET defensive_rebound = 'no_preference' WHERE defensive_rebound IS NULL OR defensive_rebound NOT IN ('run_transition','crash_glass','some_crash','no_preference')");
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] defensive_rebound normalize: ' . $e->getMessage());
    }

    try {
        $pdo->exec("UPDATE team_directives SET rotation_style = 'auto' WHERE rotation_style IS NULL OR rotation_style NOT IN ('manual','auto')");
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] rotation_style normalize: ' . $e->getMessage());
    }

    try {
        $pdo->exec("UPDATE team_directives SET game_style = 'balanced' WHERE game_style IS NULL OR game_style NOT IN ('balanced','triangle','grit_grind','pace_space','perimeter_centric','post_centric','seven_seconds','defense','defensive_focus','franchise_player','most_stars')");
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] game_style normalize: ' . $e->getMessage());
    }

    try {
        $pdo->exec("UPDATE team_directives SET offense_style = 'no_preference' WHERE offense_style IS NULL OR offense_style NOT IN ('no_preference','pick_roll','neutral','play_through_star','get_to_basket','get_shooters_open','feed_post')");
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] offense_style normalize: ' . $e->getMessage());
    }

    try {
        $hasDefenseStyle = $pdo->query("SHOW COLUMNS FROM team_directives LIKE 'defense_style'")->rowCount() > 0;
        if ($hasDefenseStyle) {
            $pdo->exec("ALTER TABLE team_directives DROP COLUMN defense_style");
        }
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] drop defense_style: ' . $e->getMessage());
    }

    $checked = true;
}

/**
 * Retorna URL de imagem válida ou imagem padrão
 */
function getTeamPhoto(?string $photoUrl, string $default = '/img/default-team.png'): string
{
    if (empty($photoUrl) || trim($photoUrl) === '') {
        return $default;
    }
    return $photoUrl;
}

/**
 * Retorna URL de avatar válida ou avatar padrão
 */
function getUserPhoto(?string $photoUrl, string $default = '/img/default-avatar.png'): string
{
    if (empty($photoUrl) || trim($photoUrl) === '') {
        return $default;
    }
    return $photoUrl;
}

function normalizeBrazilianPhone(?string $input): ?string
{
    if ($input === null) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $input);
    if ($digits === '') {
        return null;
    }

    // Remove prefix "00" usado em discagem internacional
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }

    $maxLength = 15; // E.164
    if (strlen($digits) > $maxLength) {
        $digits = substr($digits, 0, $maxLength);
    }

    if (strlen($digits) < 10) {
        return null;
    }

    $hasCountryCode = str_starts_with($digits, '55') || strlen($digits) > 11;

    if (!$hasCountryCode) {
        $digits = '55' . $digits;
        if (strlen($digits) > $maxLength) {
            $digits = substr($digits, 0, $maxLength);
        }
    }

    return $digits;
}

function formatBrazilianPhone(?string $phone): ?string
{
    if (!$phone) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') {
        return null;
    }

    if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
        $localDigits = substr($digits, -11);
        if (strlen($localDigits) === 11) {
            return sprintf('(%s) %s-%s', substr($localDigits, 0, 2), substr($localDigits, 2, 5), substr($localDigits, 7));
        }
        if (strlen($localDigits) === 10) {
            return sprintf('(%s) %s-%s', substr($localDigits, 0, 2), substr($localDigits, 2, 4), substr($localDigits, 6));
        }
    }

    if (strlen($digits) === 11) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
    }

    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
    }

    return '+' . $digits;
}
