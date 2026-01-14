<?php
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

function sendPasswordResetEmail(string $email, string $token, string $name): bool
{
    $config = loadConfig();
    $resetUrl = 'https://fbabrasil.com.br/reset-password.php?token=' . urlencode($token);
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

    if (strlen($digits) < 10) {
        return null;
    }

    if (strlen($digits) > 13) {
        $digits = substr($digits, 0, 13);
    }

    if (!str_starts_with($digits, '55')) {
        $digits = '55' . $digits;
        if (strlen($digits) > 13) {
            $digits = substr($digits, 0, 13);
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
    if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
        $digits = substr($digits, -11);
    }

    if (strlen($digits) === 11) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
    }

    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
    }

    return $phone;
}
