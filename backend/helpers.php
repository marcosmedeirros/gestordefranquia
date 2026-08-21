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

    $localConfigFile = __DIR__ . '/config.local.php';
    $configFile = file_exists($localConfigFile) ? $localConfigFile : __DIR__ . '/config.php';
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

function buildMailHeaders(array $config, array $extra = []): array
{
    $from = $config['mail']['from'] ?? 'no-reply@localhost';
    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    return array_merge($headers, $extra);
}

function buildMailParams(array $config): ?string
{
    $from = $config['mail']['from'] ?? '';
    if ($from && filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return '-f ' . $from;
    }
    return null;
}

function smtpRead($socket): string
{
    $data = '';
    while ($line = fgets($socket, 515)) {
        $data .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    return $data;
}

function smtpWrite($socket, string $command): void
{
    fwrite($socket, $command . "\r\n");
}

function sendViaSmtp(string $to, string $subject, string $message, array $config, array $extraHeaders = []): bool
{
    $smtp = $config['mail']['smtp'] ?? [];
    $host = $smtp['host'] ?? '';
    $port = (int)($smtp['port'] ?? 587);
    $user = $smtp['user'] ?? '';
    $pass = $smtp['pass'] ?? '';
    $secure = strtolower(trim((string)($smtp['secure'] ?? 'tls')));

    if ($host === '' || $user === '' || $pass === '') {
        return false;
    }

    $remote = ($secure === 'ssl') ? "ssl://{$host}:{$port}" : "{$host}:{$port}";
    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        error_log("SMTP connect failed: {$errstr} ({$errno})");
        return false;
    }

    $response = smtpRead($socket);
    if (strpos($response, '220') !== 0) {
        fclose($socket);
        return false;
    }

    $hostname = $_SERVER['HTTP_HOST'] ?? 'localhost';
    smtpWrite($socket, "EHLO {$hostname}");
    $response = smtpRead($socket);
    if (strpos($response, '250') !== 0) {
        fclose($socket);
        return false;
    }

    if ($secure === 'tls') {
        smtpWrite($socket, 'STARTTLS');
        $response = smtpRead($socket);
        if (strpos($response, '220') !== 0) {
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return false;
        }
        smtpWrite($socket, "EHLO {$hostname}");
        $response = smtpRead($socket);
        if (strpos($response, '250') !== 0) {
            fclose($socket);
            return false;
        }
    }

    smtpWrite($socket, 'AUTH LOGIN');
    $response = smtpRead($socket);
    if (strpos($response, '334') !== 0) {
        fclose($socket);
        return false;
    }
    smtpWrite($socket, base64_encode($user));
    $response = smtpRead($socket);
    if (strpos($response, '334') !== 0) {
        fclose($socket);
        return false;
    }
    smtpWrite($socket, base64_encode($pass));
    $response = smtpRead($socket);
    if (strpos($response, '235') !== 0) {
        fclose($socket);
        return false;
    }

    $from = $config['mail']['from'] ?? $user;
    smtpWrite($socket, "MAIL FROM:<{$from}>");
    $response = smtpRead($socket);
    if (strpos($response, '250') !== 0) {
        fclose($socket);
        return false;
    }

    smtpWrite($socket, "RCPT TO:<{$to}>");
    $response = smtpRead($socket);
    if (strpos($response, '250') !== 0 && strpos($response, '251') !== 0) {
        fclose($socket);
        return false;
    }

    smtpWrite($socket, 'DATA');
    $response = smtpRead($socket);
    if (strpos($response, '354') !== 0) {
        fclose($socket);
        return false;
    }

    $headers = array_merge(buildMailHeaders($config), $extraHeaders);
    $data = 'Subject: ' . $subject . "\r\n";
    $data .= implode("\r\n", $headers) . "\r\n\r\n";
    $data .= preg_replace("/\r?\n/", "\r\n", $message) . "\r\n";
    $data .= ".\r\n";

    smtpWrite($socket, $data);
    $response = smtpRead($socket);
    smtpWrite($socket, 'QUIT');
    fclose($socket);

    return strpos($response, '250') === 0;
}

function sendVerificationEmail(string $email, string $token): bool
{
    $config = loadConfig();
    $verifyUrl = rtrim($config['mail']['verify_base_url'], '?') . '?token=' . urlencode($token);
    $subject = 'Verifique seu e-mail (FBA)';
    $message = "Clique para verificar seu e-mail: {$verifyUrl}";
    if (!empty($config['mail']['smtp']['host'])) {
        return sendViaSmtp($email, $subject, $message, $config);
    }
    $headers = implode("\r\n", buildMailHeaders($config));
    $params = buildMailParams($config);
    return $params ? mail($email, $subject, $message, $headers, $params) : mail($email, $subject, $message, $headers);
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

    if (!empty($config['mail']['smtp']['host'])) {
        return sendViaSmtp($email, $subject, $message, $config);
    }

    $headers = implode("\r\n", buildMailHeaders($config));
    $params = buildMailParams($config);
    return $params ? mail($email, $subject, $message, $headers, $params) : mail($email, $subject, $message, $headers);
}

/**
 * E-mail de boas-vindas pro GM criado direto pelo admin em Gestão — link de
 * login + senha padrão em texto (aviso pra trocar depois). Mesmo mecanismo de
 * envio de sendPasswordResetEmail (SMTP cru se configurado, senão mail() nativo).
 */
function sendGmWelcomeEmail(string $email, string $name, string $password, string $league): bool
{
    $config = loadConfig();
    $loginUrl = 'https://fbabrasil.com.br/login.php';
    $subject = 'Bem-vindo ao FBA Manager!';

    $message = "
Olá {$name},

Seu time na liga {$league} foi criado no FBA Manager!

Acesse pelo link abaixo com os dados de acesso:
{$loginUrl}

E-mail: {$email}
Senha: {$password}

Recomendamos trocar sua senha assim que possível em Configurações.

Atenciosamente,
Equipe FBA Manager
    ";

    if (!empty($config['mail']['smtp']['host'])) {
        return sendViaSmtp($email, $subject, $message, $config);
    }

    $headers = implode("\r\n", buildMailHeaders($config));
    $params = buildMailParams($config);
    return $params ? mail($email, $subject, $message, $headers, $params) : mail($email, $subject, $message, $headers);
}

/**
 * Quantos jogadores entram na soma de OVR que forma o CAP das ligas NEXT, RISE
 * e ROOKIE. Era 8, virou 10 em agosto de 2026.
 *
 * Este número estava repetido em nove lugares — quatro consultas soltas, dois
 * helpers e três arquivos de JS — e mudar de 8 pra 10 exigia achar todos. É por
 * isso que agora existe uma constante: a próxima mudança é de um dígito só.
 *
 * A ELITE não usa isto: lá o CAP é folha salarial (ver backend/salary_cap.php).
 */
const CAP_TOP_N = 10;

/**
 * O tamanho permitido do elenco.
 *
 * Estava escrito à mão no dashboard e nas pendências, e o máximo ainda
 * aparece solto na free agency — mudar o mínimo de 13 pra 14 significava
 * caçar cada um. Daqui pra frente é um número só.
 */
const ELENCO_MIN = 14;
const ELENCO_MAX = 15;

/**
 * Soma de OVR dos CAP_TOP_N melhores do elenco — o CAP fora da ELITE.
 *
 * O nome não é topEightCap desde que o número deixou de ser oito: função que
 * diz "eight" e devolve dez é o tipo de coisa que engana quem lê depois.
 */
function topOvrCap(PDO $pdo, int $teamId): int
{
    $stmt = $pdo->prepare('SELECT SUM(ovr) as cap FROM (
        SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT ' . CAP_TOP_N . '
    ) as ranked');
    $stmt->execute([$teamId]);
    $row = $stmt->fetch();
    return (int) ($row['cap'] ?? 0);
}

/** Nome antigo, mantido pra não quebrar chamada que tenha escapado. */
function topEightCap(PDO $pdo, int $teamId): int
{
    return topOvrCap($pdo, $teamId);
}

function ensurePlayerRestrictionColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        $needsDraftedBy = $pdo->query("SHOW COLUMNS FROM players LIKE 'drafted_by_team_id'")->rowCount() === 0;
        if ($needsDraftedBy) {
            $pdo->exec("ALTER TABLE players ADD COLUMN drafted_by_team_id INT NULL AFTER team_id");
            $pdo->exec("CREATE INDEX idx_players_drafted_by_team_id ON players(drafted_by_team_id)");
        }

        $needsWasTraded = $pdo->query("SHOW COLUMNS FROM players LIKE 'was_traded'")->rowCount() === 0;
        if ($needsWasTraded) {
            $pdo->exec("ALTER TABLE players ADD COLUMN was_traded TINYINT(1) NOT NULL DEFAULT 0 AFTER drafted_by_team_id");
        }

        $needsFranchise = $pdo->query("SHOW COLUMNS FROM players LIKE 'is_franchise_player'")->rowCount() === 0;
        if ($needsFranchise) {
            $pdo->exec("ALTER TABLE players ADD COLUMN is_franchise_player TINYINT(1) DEFAULT NULL AFTER was_traded");
        }

        // Override manual da tag "Leal" (markLoyaltyEligibility mais abaixo) — NULL
        // deixa a regra automática decidir, 0/1 força o valor independente dela.
        $needsLoyalOverride = $pdo->query("SHOW COLUMNS FROM players LIKE 'loyal_override'")->rowCount() === 0;
        if ($needsLoyalOverride) {
            $pdo->exec("ALTER TABLE players ADD COLUMN loyal_override TINYINT(1) DEFAULT NULL AFTER is_franchise_player");
        }

        // Tag LENDA: um jogador por franquia. O efeito é no salário do cap —
        // a lenda vale no mínimo 40M (ver getPlayerBaseSalary), então marcar
        // alguém é decisão com peso financeiro, não só cosmética.
        $needsLenda = $pdo->query("SHOW COLUMNS FROM players LIKE 'is_lenda'")->rowCount() === 0;
        if ($needsLenda) {
            $pdo->exec("ALTER TABLE players ADD COLUMN is_lenda TINYINT(1) NOT NULL DEFAULT 0 AFTER loyal_override");
            $pdo->exec("CREATE INDEX idx_players_is_lenda ON players(team_id, is_lenda)");
        }

        $needsDraftSeason = $pdo->query("SHOW COLUMNS FROM players LIKE 'drafted_season_number'")->rowCount() === 0;
        if ($needsDraftSeason) {
            $pdo->exec("ALTER TABLE players ADD COLUMN drafted_season_number INT NULL AFTER drafted_by_team_id");
            // Backfill: preenche drafted_season_number para players existentes via draft_pool → seasons
            $pdo->exec("
                UPDATE players p
                INNER JOIN draft_pool dp
                    ON dp.name = p.name
                    AND dp.drafted_by_team_id = p.drafted_by_team_id
                    AND dp.draft_status = 'drafted'
                INNER JOIN seasons s ON s.id = dp.season_id
                SET p.drafted_season_number = s.season_number
                WHERE p.drafted_season_number IS NULL
                  AND p.drafted_by_team_id IS NOT NULL
            ");
        }
    } catch (Exception $e) {
        error_log('[ensurePlayerRestrictionColumns] ' . $e->getMessage());
    }

    $checked = true;
}

/**
 * Marca cada jogador de $players com is_loyal (nunca foi trocado E veio do
 * DRAFT NORMAL da própria franquia, via draft_pool — tag "Leal", vale pra
 * qualquer liga) e cap_bonus_eligible (leal + OVR>=90 — mesma régua em toda
 * liga). O EFEITO no cap é que muda por liga: RISE/NEXT usam o bônus por
 * soma de OVR (restrictedCapBonus), ELITE usa +8M direto no salary cap
 * (salary_cap.php).
 *
 * draft_pool é exclusivo do draft normal/recorrente — o Draft Inicial grava
 * em initdraft_pool, uma tabela separada, então em teoria um jogador vindo de
 * lá nunca bateria aqui. Na prática, como o pool usa nomes de jogadores reais
 * da NBA, dava pra um jogador do Draft Inicial coincidir por nome+time com uma
 * entrada de draft_pool de outra temporada e ser marcado leal por engano (o
 * bug do "um dia é leal, no outro não"). Por isso performInitDraftPick()
 * agora grava loyal_override=0 direto na pick, sem depender desse match por
 * nome. O mesmo vale pra quem entrou por Free Agency ou waiver: só o draft
 * normal confere lealdade automaticamente.
 *
 * O admin (ou o próprio GM, ao cadastrar um jogador direto) pode sobrepor
 * essa conta manualmente (checkbox "Leal", players.loyal_override) — quando
 * setado, vale por cima da regra automática pra is_loyal, mas nunca sobrevive
 * a uma troca (ver notTraded abaixo), e cap_bonus_eligible continua exigindo
 * OVR>=90 mesmo assim (o override muda quem é "leal", não a régua do bônus).
 *
 * Espera que cada item tenha pelo menos id, team_id, name, ovr, was_traded.
 */
function markLoyaltyEligibility(PDO $pdo, array &$players): void
{
    if (!$players) return;
    $teamIds = [];
    $playerIds = [];
    foreach ($players as $p) {
        $tid = (int)($p['team_id'] ?? 0);
        if ($tid) $teamIds[$tid] = true;
        $pid = (int)($p['id'] ?? 0);
        if ($pid) $playerIds[] = $pid;
    }
    $seasonDraftPairs = [];
    if ($teamIds) {
        try {
            $ph = implode(',', array_fill(0, count($teamIds), '?'));
            $stmt = $pdo->prepare("SELECT drafted_by_team_id, name FROM draft_pool WHERE draft_status = 'drafted' AND drafted_by_team_id IN ($ph)");
            $stmt->execute(array_keys($teamIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $seasonDraftPairs[$r['drafted_by_team_id'] . '|' . $r['name']] = true;
            }
        } catch (Exception $e) {}
    }

    // Busca em lote por id — funciona mesmo quando quem chamou não incluiu
    // loyal_override no SELECT (a maioria dos ~8 pontos que usam essa função).
    $overrides = [];
    if ($playerIds) {
        try {
            ensurePlayerRestrictionColumns($pdo);
            $ph = implode(',', array_fill(0, count($playerIds), '?'));
            $stmt = $pdo->prepare("SELECT id, loyal_override FROM players WHERE id IN ($ph)");
            $stmt->execute($playerIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['loyal_override'] !== null) $overrides[(int)$r['id']] = (int)$r['loyal_override'];
            }
        } catch (Exception $e) {}
    }

    foreach ($players as &$p) {
        $notTraded = (int)($p['was_traded'] ?? 0) === 0;
        $highOvr = (int)($p['ovr'] ?? 0) >= 90;
        $key = ($p['team_id'] ?? 0) . '|' . ($p['name'] ?? '');
        $fromNormalDraft = isset($seasonDraftPairs[$key]);
        $autoLoyal = $notTraded && $fromNormalDraft;

        // Mesmo um override "leal" não sobrevive a uma troca — lealdade sempre
        // vale "até ser trocado", venha ela da regra automática ou do check manual.
        $pid = (int)($p['id'] ?? 0);
        $isLoyal = array_key_exists($pid, $overrides) ? ($overrides[$pid] === 1 && $notTraded) : $autoLoyal;

        $p['is_loyal'] = $isLoyal ? 1 : 0;
        $p['cap_bonus_eligible'] = ($isLoyal && $highOvr) ? 1 : 0;
    }
    unset($p);
}

function restrictedEligibleCount(PDO $pdo, int $teamId): int
{
    ensurePlayerRestrictionColumns($pdo);
    try {
        $leagueStmt = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
        $leagueStmt->execute([$teamId]);
        $league = strtoupper(trim((string)($leagueStmt->fetchColumn() ?? '')));
        if ($league === '') return 0;
        // Bônus por soma de OVR: vale pra RISE e NEXT (a ELITE usa +8M direto
        // no salary cap real, calculado em backend/salary_cap.php).
        if (!str_starts_with($league, 'RISE') && !str_starts_with($league, 'NEXT')) return 0;

        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM players p
            WHERE p.team_id = ?
            AND p.ovr >= 90
            AND COALESCE(p.was_traded, 0) = 0
            AND EXISTS (
                SELECT 1 FROM draft_pool dp
                WHERE dp.name = p.name
                AND dp.drafted_by_team_id = ?
                AND dp.draft_status = "drafted"
            )
        ');
        $stmt->execute([$teamId, $teamId]);
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function restrictedCapBonus(PDO $pdo, int $teamId): int
{
    $count90 = restrictedEligibleCount($pdo, $teamId);
    if ($count90 === 0) return 0;

    // Verifica se há pelo menos 1 elegível com OVR >= 94
    try {
        $stmt94 = $pdo->prepare('
            SELECT COUNT(*) FROM players p
            WHERE p.team_id = ?
            AND p.ovr >= 94
            AND COALESCE(p.was_traded, 0) = 0
            AND EXISTS (
                SELECT 1 FROM draft_pool dp
                WHERE dp.name = p.name
                AND dp.drafted_by_team_id = ?
                AND dp.draft_status = "drafted"
            )
        ');
        $stmt94->execute([$teamId, $teamId]);
        $count94 = (int)$stmt94->fetchColumn();
    } catch (Exception $e) {
        $count94 = 0;
    }

    // +4 se tiver >= 2 elegíveis e pelo menos 1 com 94+; caso contrário +2
    if ($count90 >= 2 && $count94 >= 1) return 4;
    return 2;
}

function capMaxWithRestrictedBonus(PDO $pdo, int $teamId, int $capMax): int
{
    return $capMax + restrictedCapBonus($pdo, $teamId);
}

function capWithCandidate(PDO $pdo, int $teamId, int $candidateOvr): int
{
    $stmt = $pdo->prepare('SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT ' . CAP_TOP_N);
    $stmt->execute([$teamId]);
    $ovrs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $ovrs[] = $candidateOvr;
    rsort($ovrs, SORT_NUMERIC);
    $slice = array_slice($ovrs, 0, CAP_TOP_N);
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
        $hasTechnicalModel = $pdo->query("SHOW COLUMNS FROM team_directives LIKE 'technical_model'")->rowCount() > 0;
        if (!$hasTechnicalModel) {
            $pdo->exec("ALTER TABLE team_directives ADD COLUMN technical_model VARCHAR(60) NULL COMMENT 'Modelo técnico (Elite)' AFTER gleague_2_id");
        }
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] technical_model: ' . $e->getMessage());
    }

    try {
        $hasPlaybook = $pdo->query("SHOW COLUMNS FROM team_directives LIKE 'playbook'")->rowCount() > 0;
        if (!$hasPlaybook) {
            $pdo->exec("ALTER TABLE team_directives ADD COLUMN playbook TEXT NULL COMMENT 'Playbook (Elite)' AFTER technical_model");
        }
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] playbook: ' . $e->getMessage());
    }

    try {
        $hasModelChanged = $pdo->query("SHOW COLUMNS FROM team_directives LIKE 'technical_model_changed'")->rowCount() > 0;
        if (!$hasModelChanged) {
            $pdo->exec("ALTER TABLE team_directives ADD COLUMN technical_model_changed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Modelo tecnico alterado no envio' AFTER technical_model");
        }
    } catch (Exception $e) {
        error_log('[ensureDirectiveOptionalColumns] technical_model_changed: ' . $e->getMessage());
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

function ensureTeamDirectiveProfileColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        $hasProfile = $pdo->query("SHOW COLUMNS FROM teams LIKE 'directive_profile'")->rowCount() > 0;
        if (!$hasProfile) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN directive_profile LONGTEXT NULL COMMENT 'Diretriz base do time (JSON)' AFTER photo_url");
        }
    } catch (Exception $e) {
        error_log('[ensureTeamDirectiveProfileColumns] directive_profile: ' . $e->getMessage());
    }

    try {
        $hasProfileUpdatedAt = $pdo->query("SHOW COLUMNS FROM teams LIKE 'directive_profile_updated_at'")->rowCount() > 0;
        if (!$hasProfileUpdatedAt) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN directive_profile_updated_at DATETIME NULL COMMENT 'Última atualização da diretriz base' AFTER directive_profile");
        }
    } catch (Exception $e) {
        error_log('[ensureTeamDirectiveProfileColumns] directive_profile_updated_at: ' . $e->getMessage());
    }

    try {
        $hasModelCurrent = $pdo->query("SHOW COLUMNS FROM teams LIKE 'technical_model_current'")->rowCount() > 0;
        if (!$hasModelCurrent) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN technical_model_current VARCHAR(60) NULL COMMENT 'Modelo técnico atual (conta mudanças)' AFTER directive_profile_updated_at");
        }
    } catch (Exception $e) {
        error_log('[ensureTeamDirectiveProfileColumns] technical_model_current: ' . $e->getMessage());
    }

    try {
        $hasModelChanges = $pdo->query("SHOW COLUMNS FROM teams LIKE 'technical_model_changes_used'")->rowCount() > 0;
        if (!$hasModelChanges) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN technical_model_changes_used INT NOT NULL DEFAULT 0 COMMENT 'Mudanças usadas no modelo técnico' AFTER technical_model_current");
        }
    } catch (Exception $e) {
        error_log('[ensureTeamDirectiveProfileColumns] technical_model_changes_used: ' . $e->getMessage());
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

/**
 * Interpreta a URL de um vídeo administrativo de liga (Progression, Sistemas,
 * Free Agency) e decide como exibi-lo no dashboard:
 * - 'direct': arquivo de vídeo (mp4/webm/ogg/mov) tocado num <video> nativo,
 *   o único caso em que dá pra capturar o frame direto via canvas (embeds
 *   de terceiros são cross-origin e o navegador bloqueia a leitura do canvas).
 * - 'iframe': YouTube, Vimeo ou Google Drive, incorporado num iframe.
 * - 'link': qualquer outra URL — mostra só um botão "Assistir" que abre em nova aba.
 */
function resolveVideoEmbed(?string $url): ?array
{
    $url = trim((string)$url);
    if ($url === '') return null;

    if (preg_match('#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})#i', $url, $m)) {
        // Tipo próprio: o dashboard monta um player nosso por cima (controles,
        // barra de progresso e capa), com os controles do YouTube desligados.
        // Não dá pra remover a marca do YouTube por parâmetro — o jeito é não
        // deixar a interface deles aparecer.
        return ['type' => 'youtube', 'video_id' => $m[1], 'embed_url' => null, 'raw_url' => $url];
    }
    if (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $url, $m)) {
        return ['type' => 'iframe', 'embed_url' => 'https://player.vimeo.com/video/' . $m[1], 'raw_url' => $url];
    }
    if (preg_match('#drive\.google\.com/file/d/([A-Za-z0-9_-]+)#i', $url, $m)) {
        return ['type' => 'iframe', 'embed_url' => 'https://drive.google.com/file/d/' . $m[1] . '/preview', 'raw_url' => $url];
    }
    if (preg_match('#\.(mp4|webm|ogg|ogv|mov)(\?.*)?$#i', $url)) {
        return ['type' => 'direct', 'embed_url' => $url, 'raw_url' => $url];
    }
    return ['type' => 'link', 'embed_url' => null, 'raw_url' => $url];
}

function ensureLoginAttemptsTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            identifier VARCHAR(191) NOT NULL PRIMARY KEY,
            attempts INT NOT NULL DEFAULT 0,
            last_attempt_at DATETIME NOT NULL,
            locked_until DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('[ensureLoginAttemptsTable] ' . $e->getMessage());
    }
    $checked = true;
}

function loginRateLimitIdentifier(string $email): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return $ip . '|' . strtolower(trim($email));
}

// Bloqueia a tentativa de login se o identificador (IP + e-mail) estourou o limite.
function checkLoginRateLimit(PDO $pdo, string $email): void
{
    ensureLoginAttemptsTable($pdo);
    $identifier = loginRateLimitIdentifier($email);
    $stmt = $pdo->prepare('SELECT locked_until FROM login_attempts WHERE identifier = ?');
    $stmt->execute([$identifier]);
    $lockedUntil = $stmt->fetchColumn();

    if ($lockedUntil && strtotime($lockedUntil) > time()) {
        jsonResponse(429, ['error' => 'Muitas tentativas de login. Tente novamente em alguns minutos.']);
    }
}

function recordLoginFailure(PDO $pdo, string $email): void
{
    ensureLoginAttemptsTable($pdo);
    $identifier = loginRateLimitIdentifier($email);
    $maxAttempts = 5;
    $windowSeconds = 15 * 60;
    $lockSeconds = 15 * 60;

    $stmt = $pdo->prepare('SELECT attempts, last_attempt_at FROM login_attempts WHERE identifier = ?');
    $stmt->execute([$identifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $attempts = 1;
    if ($row && strtotime($row['last_attempt_at']) > time() - $windowSeconds) {
        $attempts = (int)$row['attempts'] + 1;
    }

    $lockedUntil = $attempts >= $maxAttempts ? date('Y-m-d H:i:s', time() + $lockSeconds) : null;

    $pdo->prepare('INSERT INTO login_attempts (identifier, attempts, last_attempt_at, locked_until)
        VALUES (?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), last_attempt_at = VALUES(last_attempt_at), locked_until = VALUES(locked_until)')
        ->execute([$identifier, $attempts, $lockedUntil]);
}

function recordLoginSuccess(PDO $pdo, string $email): void
{
    ensureLoginAttemptsTable($pdo);
    $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([loginRateLimitIdentifier($email)]);
}

// Garante no máximo 1 registro ativo por time+liga no Hall da Fama, pra não
// misturar títulos de divisões diferentes no mesmo contador.
function ensureHallOfFameLeagueUnique(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    // DDL dentro de transação causa COMMIT implícito no MySQL — o commit() lá
    // na frente morreria com "There is no active transaction" e a ação
    // devolveria erro mesmo tendo gravado tudo. Quem chama dentro de uma
    // transação tem que garantir o índice ANTES de abri-la.
    if ($pdo->inTransaction()) {
        return;
    }
    try {
        $idx = $pdo->query("SHOW INDEX FROM hall_of_fame WHERE Key_name = 'uk_hof_team_league'")->fetch();
        if (!$idx) {
            $pdo->exec("ALTER TABLE hall_of_fame ADD UNIQUE KEY uk_hof_team_league (team_id, league)");
        }
    } catch (Exception $e) {
        error_log('[ensureHallOfFameLeagueUnique] ' . $e->getMessage());
    }
    $checked = true;
}

// Soma 1 título ao time na liga informada — cria o registro (com 1 título) se
// ainda não existir um pra esse time nessa liga especificamente.
function hallOfFameAddTitle(PDO $pdo, int $teamId, string $league): void
{
    ensureHallOfFameLeagueUnique($pdo);

    $stmtTeam = $pdo->prepare('SELECT t.city, t.name, t.user_id, u.name AS owner_name FROM teams t LEFT JOIN users u ON t.user_id = u.id WHERE t.id = ?');
    $stmtTeam->execute([$teamId]);
    $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
    $teamName = trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''));
    $gmName = $team['owner_name'] ?? '';
    $userId = isset($team['user_id']) ? (int)$team['user_id'] : null;

    $stmtExisting = $pdo->prepare('SELECT id FROM hall_of_fame WHERE team_id = ? AND league = ? AND is_active = 1 LIMIT 1');
    $stmtExisting->execute([$teamId, $league]);
    $existingId = $stmtExisting->fetchColumn();

    if ($existingId) {
        // Atualiza também o GM/dono atual, caso tenha mudado desde o último título.
        $pdo->prepare('UPDATE hall_of_fame SET titles = titles + 1, gm_name = ?, user_id = ? WHERE id = ?')
            ->execute([$gmName ?: null, $userId, (int)$existingId]);
        return;
    }

    $pdo->prepare('INSERT INTO hall_of_fame (is_active, league, team_id, user_id, team_name, gm_name, titles) VALUES (1, ?, ?, ?, ?, ?, 1)')
        ->execute([$league, $teamId, $userId, $teamName ?: null, $gmName ?: null]);
}

// Remove 1 título do time na liga informada — usado quando o campeão de uma
// temporada já registrada é corrigido, pra não deixar o título "fantasma".
function hallOfFameRemoveTitle(PDO $pdo, int $teamId, string $league): void
{
    $stmtExisting = $pdo->prepare('SELECT id, titles FROM hall_of_fame WHERE team_id = ? AND league = ? AND is_active = 1 LIMIT 1');
    $stmtExisting->execute([$teamId, $league]);
    $row = $stmtExisting->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $newTitles = max(0, (int)$row['titles'] - 1);
    $pdo->prepare('UPDATE hall_of_fame SET titles = ? WHERE id = ?')->execute([$newTitles, (int)$row['id']]);
}

// Peso de cada título de acordo com a liga, usado pra rankear o Hall da Fama
// geral — um título na Elite vale mais que um na Rookie, já que é a divisão top.
const HOF_LEAGUE_WEIGHT = ['ELITE' => 4, 'NEXT' => 3, 'RISE' => 2, 'ROOKIE' => 1];

// Garante a tabela hall_of_fame (e a coluna user_id, usada por getHallOfFameGrouped()
// para agrupar por conta em vez de casar por nome de GM). Função única compartilhada
// por api/hall-of-fame.php e api/admin.php — evita duas cópias divergirem no futuro.
function ensureHallOfFameTable(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'hall_of_fame'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("CREATE TABLE hall_of_fame (
                id INT AUTO_INCREMENT PRIMARY KEY,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                league ENUM('ELITE','NEXT','RISE','ROOKIE') NULL,
                team_id INT NULL,
                user_id INT NULL,
                team_name VARCHAR(255) NULL,
                gm_name VARCHAR(255) NULL,
                titles INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_hof_titles (titles)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            return;
        }
        // Tabela já existia de antes da coluna user_id ser introduzida — garante que exista,
        // sem depender só da migração assíncrona (que tem throttle e pode não ter rodado ainda).
        $hasUserId = $pdo->query("SHOW COLUMNS FROM hall_of_fame LIKE 'user_id'")->fetch();
        if (!$hasUserId) {
            $pdo->exec("ALTER TABLE hall_of_fame ADD COLUMN user_id INT NULL AFTER team_id");
        }
    } catch (Exception $e) {
        // ignore
    }
}

// Agrupa os registros do Hall da Fama por GM (por user_id quando disponível,
// caindo pro nome do GM como fallback nos registros congelados/legados sem
// vínculo de conta). Cada grupo junta os times que a pessoa teve e soma os
// títulos por liga, pra montar um card único por pessoa em vez de uma linha
// por time+liga. Ordena pela pontuação ponderada por liga (HOF_LEAGUE_WEIGHT),
// já que um título na Elite deve pesar mais que um na Rookie.
function getHallOfFameGrouped(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT hof.id, hof.is_active, hof.league, hof.team_id, hof.user_id AS stored_user_id,
               hof.team_name, hof.gm_name, hof.titles,
               t.city AS team_city, t.name AS team_name_live, u.name AS gm_name_live
        FROM hall_of_fame hof
        LEFT JOIN teams t ON hof.team_id = t.id
        LEFT JOIN users u ON t.user_id = u.id
        WHERE hof.titles > 0
        ORDER BY hof.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($rows as $row) {
        $isActive = (int)($row['is_active'] ?? 0) === 1;
        $teamName = $isActive
            ? trim(($row['team_city'] ?? '') . ' ' . ($row['team_name_live'] ?? ''))
            : (string)($row['team_name'] ?? '');
        if ($teamName === '') {
            $teamName = (string)($row['team_name'] ?? '');
        }
        $gmName = $isActive ? ($row['gm_name_live'] ?? '') : ($row['gm_name'] ?? '');
        if (!$gmName) {
            $gmName = $row['gm_name'] ?? '';
        }

        $userId = $row['stored_user_id'] !== null ? (int)$row['stored_user_id'] : null;
        $groupKey = $userId ? ('u' . $userId) : ('n' . mb_strtolower(trim($gmName)));
        if ($groupKey === 'n') {
            $groupKey = 'row' . $row['id']; // sem nome nem user_id: nao agrupa com nada
        }

        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'gm_name' => $gmName,
                'user_id' => $userId,
                'is_active' => false,
                'current_league' => null,
                'teams' => [],
                'leagues' => [],
                'total_titles' => 0,
                'weighted_score' => 0,
                'rows' => [],
            ];
        }

        if ($gmName && !$groups[$groupKey]['gm_name']) {
            $groups[$groupKey]['gm_name'] = $gmName;
        }
        if ($teamName && !in_array($teamName, $groups[$groupKey]['teams'], true)) {
            $groups[$groupKey]['teams'][] = $teamName;
        }
        $league = $row['league'] ?: 'N/A';
        $groups[$groupKey]['leagues'][$league] = ($groups[$groupKey]['leagues'][$league] ?? 0) + (int)$row['titles'];
        $groups[$groupKey]['total_titles'] += (int)$row['titles'];
        $groups[$groupKey]['weighted_score'] += (int)$row['titles'] * (HOF_LEAGUE_WEIGHT[$league] ?? 0);
        $groups[$groupKey]['rows'][] = [
            'id' => (int)$row['id'],
            'league' => $league,
            'team_name' => $teamName,
            'titles' => (int)$row['titles'],
            'is_active' => $isActive,
        ];
        if ($isActive) {
            $groups[$groupKey]['is_active'] = true;
            $groups[$groupKey]['current_league'] = $league;
        }
    }

    $result = array_values($groups);
    usort($result, function (array $a, array $b): int {
        if ($a['weighted_score'] !== $b['weighted_score']) {
            return $b['weighted_score'] <=> $a['weighted_score'];
        }
        return $b['total_titles'] <=> $a['total_titles'];
    });

    return $result;
}

function normalizeBrazilianPhone(?string $input): ?string
{
    if ($input === null) {
        return null;
    }

    // O "+" é a única coisa que diz, sem ambiguidade, "este número já tem o
    // código do país". Sem olhar pra ele, +40 754 944 065 (Romênia) tem onze
    // dígitos e é indistinguível de um celular brasileiro sem DDI — e virava
    // 5540754944065, que não existe. Aconteceu com três GMs: um romeno, um
    // americano e um português, todos com 55 grudado na frente pelo sistema.
    $internacionalExplicito = str_starts_with(ltrim((string)$input), '+');

    $digits = preg_replace('/\D+/', '', $input);
    if ($digits === '') {
        return null;
    }

    // Remove prefix "00" usado em discagem internacional — mesma intenção do
    // "+", só que na forma que se disca do telefone.
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
        $internacionalExplicito = true;
    }

    $maxLength = 15; // E.164
    if (strlen($digits) > $maxLength) {
        $digits = substr($digits, 0, $maxLength);
    }

    if (strlen($digits) < 10) {
        return null;
    }

    // Só é código de país se o TAMANHO comportar. "55" na frente sozinho não
    // basta: 55 também é o DDD de Santa Maria/RS, e um número de 11 dígitos no
    // Brasil é sempre DDD (2) + celular (9) — nunca DDI + local, que precisaria
    // de 12 ou 13. Era por isso que 55997164253 ficava sem o DDI e o WhatsApp
    // não reconhecia: ele lia 55 + 997164253, nove dígitos, número que não
    // existe. O certo é 5555997164253.
    // Acrescenta o 55 SÓ quando o que veio lê como número brasileiro sem país.
    // Antes bastava ter 10 ou 11 dígitos, e por isso +40 754 944 065 (Romênia)
    // e +1 216 983-9569 (EUA) viraram 5540754944065 e 5512169839569 — números
    // que não existem, de GMs que nunca receberam nada do bot.
    $hasCountryCode = $internacionalExplicito
        || strlen($digits) > 11
        || (str_starts_with($digits, '55') && strlen($digits) >= 12)
        || !telefoneEhLocalBrasileiro($digits);

    if (!$hasCountryCode) {
        $digits = '55' . $digits;
        if (strlen($digits) > $maxLength) {
            $digits = substr($digits, 0, $maxLength);
        }
    }

    return $digits;
}

/** Os DDDs que existem de verdade. Não é "entre 11 e 99": 40, 58 e outros não existem. */
function telefoneDDDs(): array
{
    return [
        11,12,13,14,15,16,17,18,19,          21,22,24,  27,28,
        31,32,33,34,35,37,38,                41,42,43,44,45,46,47,48,49,
        51,53,54,55,                         61,62,63,64,65,66,67,68,69,
        71,73,74,75,77,79,                   81,82,83,84,85,86,87,88,89,
        91,92,93,94,95,96,97,98,99,
    ];
}

/**
 * Estes 10 ou 11 dígitos são um número brasileiro sem o código do país?
 *
 * A pergunta parece boba mas é o coração do problema. "11 dígitos = brasileiro
 * sem DDI" era a regra antiga, e ela quebrou três GMs de uma vez: +40 754 944
 * 065 (Romênia) e +1 216 983-9569 (EUA) também têm 11 dígitos, e ganharam um 55
 * na frente que os destruiu.
 *
 * O critério que funciona é ler como brasileiro e ver se faz sentido: DDD que
 * existe e linha no formato certo. O romeno começa com 40, que não é DDD. O
 * americano começa com 12, que é DDD válido, mas aí a linha seria 169839569 —
 * celular tem que começar com 9. Os dois caem fora, e é o que se quer.
 */
function telefoneEhLocalBrasileiro(string $digitos): bool
{
    $n = strlen($digitos);
    if ($n !== 10 && $n !== 11) return false;
    if (!in_array((int)substr($digitos, 0, 2), telefoneDDDs(), true)) return false;

    $linha = substr($digitos, 2);
    // Celular tem 9 dígitos e começa com 9. Fixo tem 8 e começa com 2 a 5 —
    // 8 dígitos começando em 6-9 é celular velho, que também é local daqui.
    return strlen($linha) === 9
        ? $linha[0] === '9'
        : in_array($linha[0], ['2','3','4','5','6','7','8','9'], true);
}

/**
 * O bot consegue marcar essa pessoa no grupo?
 *
 * O WhatsApp só reconhece o número no formato completo (DDI + DDD + linha).
 * Quando falta pedaço, a menção sai como texto solto e ninguém é etiquetado —
 * foi o que aconteceu com um GM cujo número tinha 11 dígitos começando em 55:
 * o WhatsApp leu como DDI 55 + 997164253 e não achou ninguém.
 *
 * Isto é conferência de FORMA, não de existência: só quem sabe se a conta
 * existe é o WhatsApp. Mas número mal formado nunca funciona, e é o que
 * explica todos os casos que apareceram até agora.
 *
 * Devolve ['ok' => bool, 'motivo' => ?string, 'sugestao' => ?string].
 */
function whatsappNumeroUsavel(?string $phone): array
{
    $d = preg_replace('/\D+/', '', (string)$phone);
    if ($d === '') return ['ok' => false, 'motivo' => 'Sem número cadastrado.', 'sugestao' => null];

    // Curto E lendo como brasileiro: falta o país. Se não lê como brasileiro,
    // é estrangeiro com código de país curto (+1, +40) e está completo.
    if (strlen($d) <= 11 && telefoneEhLocalBrasileiro($d)) {
        // Só sugere o conserto se o conserto der um número válido. Palpite que
        // continua quebrado é pior que nenhum: quem clica confia.
        $tentativa = '55' . $d;
        $valida = whatsappNumeroUsavel($tentativa);
        return ['ok' => false,
                'motivo' => 'Falta o código do país. Estes ' . strlen($d) . ' dígitos são DDD + linha.',
                'sugestao' => $valida['ok'] && !$valida['motivo'] ? $tentativa : null];
    }

    // Fora do Brasil, o app não tem como validar numeração nacional — aceita
    // e não inventa regra que não conhece. O que dá pra checar é o que o envio
    // checa: cabe no E.164 e não começa com zero (código de país nenhum
    // começa com 0; quem escreve assim digitou o zero do DDD antigo).
    if (!str_starts_with($d, '55')) {
        if ($d[0] === '0') {
            return ['ok' => false, 'motivo' => 'Começa com zero — código de país não tem zero na frente.', 'sugestao' => null];
        }
        if (strlen($d) > 15) {
            return ['ok' => false, 'motivo' => 'Tem ' . strlen($d) . ' dígitos; o máximo internacional é 15.', 'sugestao' => null];
        }
        // Sem motivo = verde. O bot manda esse número como está, e é assim que
        // ele tem que ir: quem resolve o DDI estrangeiro é o WhatsApp.
        return ['ok' => true, 'motivo' => null, 'sugestao' => null];
    }

    $local = substr($d, 2);                       // sem o DDI
    $ddd   = (int)substr($local, 0, 2);
    $linha = substr($local, 2);

    if (!in_array($ddd, telefoneDDDs(), true)) {
        return ['ok' => false, 'motivo' => "DDD {$ddd} não existe no Brasil.", 'sugestao' => null];
    }
    if (strlen($linha) === 9) {
        return $linha[0] === '9'
            ? ['ok' => true, 'motivo' => null, 'sugestao' => null]
            : ['ok' => false, 'motivo' => 'Celular de 9 dígitos tem que começar com 9.', 'sugestao' => null];
    }
    if (strlen($linha) === 8) {
        // Fixo começa com 2 a 5; celular sempre começou com 6 a 9. Então linha
        // de 8 dígitos começando em 6-9 é celular anterior ao nono dígito, e o
        // conserto é a regra da Anatel: enfia um 9 na frente. Não é chute — é
        // a conversão oficial, e ainda passa pela validação antes de virar
        // sugestão. Fixo de verdade fica como está: funciona no WhatsApp.
        if (in_array($linha[0], ['6', '7', '8', '9'], true)) {
            $comNono = substr($d, 0, 4) . '9' . $linha;
            $valida = whatsappNumeroUsavel($comNono);
            return ['ok' => false,
                    'motivo' => 'Celular sem o 9º dígito (formato antigo).',
                    'sugestao' => $valida['ok'] && !$valida['motivo'] ? $comNono : null];
        }
        // Fixo válido não tem o que consertar — verde. Avisar "isto é um fixo"
        // toda vez seria âmbar eterno sem ação possível, ou seja, ruído.
        return ['ok' => true, 'motivo' => null, 'sugestao' => null];
    }
    return ['ok' => false,
            'motivo' => 'Tem ' . strlen($d) . ' dígitos — o esperado é 13 (55 + DDD + 9 dígitos).',
            'sugestao' => null];
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

/**
 * Catálogo de páginas que podem virar atalho no dashboard — mesma lista
 * de navegação usada em includes/sidebar.php.
 */
function getShortcutCatalog(): array {
    return [
        'trades'           => ['label' => 'Trades',         'icon' => 'bi-arrow-left-right',     'href' => '/trades.php'],
        'players'          => ['label' => 'Jogadores',       'icon' => 'bi-person-lines-fill',    'href' => '/players.php'],
        'teams'            => ['label' => 'Times',           'icon' => 'bi-people-fill',          'href' => '/teams.php'],
        'my-roster'        => ['label' => 'Meu Elenco',      'icon' => 'bi-person-fill',          'href' => '/my-roster.php'],
        'picks'            => ['label' => 'Picks',           'icon' => 'bi-calendar-check-fill',  'href' => '/picks.php'],
        'mercado'          => ['label' => 'Mercado',         'icon' => 'bi-shop',                 'href' => '/mercado.php'],
        'free-agency'      => ['label' => 'Free Agency',     'icon' => 'bi-coin',                 'href' => '/free-agency.php'],
        'leilao'           => ['label' => 'Leilão',          'icon' => 'bi-hammer',                'href' => '/leilao.php'],
        'drafts'           => ['label' => 'Draft',           'icon' => 'bi-trophy',                'href' => '/drafts.php'],
        'tapas'            => ['label' => 'Tapas',           'icon' => 'bi-hand-index-thumb',     'href' => '/tapas.php'],
        'rankings'         => ['label' => 'Rankings',        'icon' => 'bi-bar-chart-fill',       'href' => '/rankings.php'],
        'history'          => ['label' => 'Prêmios',         'icon' => 'bi-trophy-fill',          'href' => '/history.php'],
        'hall-da-fama'     => ['label' => 'Hall da Fama',    'icon' => 'bi-award-fill',           'href' => '/hall-da-fama.php'],
        'diretrizes'       => ['label' => 'Tática',          'icon' => 'bi-clipboard2-pulse',     'href' => '/tatica.php'],
        'mundo-fba'        => ['label' => 'Mundo FBA',       'icon' => 'bi-globe2',                'href' => '/mundo-fba.php'],
        'estatisticas'     => ['label' => 'Estatísticas',    'icon' => 'bi-bar-chart-line-fill',  'href' => '/estatisticas.php'],
        'ouvidoria'        => ['label' => 'Ouvidoria',       'icon' => 'bi-chat-dots',            'href' => '/ouvidoria.php'],
        'thepathetic'      => ['label' => 'The Pathetic',    'icon' => 'bi-newspaper',            'href' => '/thepathetic.php'],
        'team-public-page' => ['label' => 'Página do Time',  'icon' => 'bi-globe2',                'href' => '/team-public-page.php'],
        'settings'         => ['label' => 'Minha Conta',     'icon' => 'bi-gear-fill',            'href' => '/settings.php'],
    ];
}

function getDefaultShortcuts(): array {
    return ['trades', 'players', 'teams', 'my-roster'];
}

/**
 * Resolve os atalhos salvos do usuário (string "key1,key2,..." vindo de
 * users.dashboard_shortcuts) pro padrão renderizável, caindo pro padrão
 * (Trades/Jogadores/Times/Meu Elenco) quando vazio ou inválido.
 */
function getUserShortcuts(?string $stored): array {
    $catalog = getShortcutCatalog();
    $keys = $stored ? array_filter(array_map('trim', explode(',', $stored))) : [];
    $keys = array_values(array_filter($keys, fn($k) => isset($catalog[$k])));
    if (!$keys) {
        $keys = getDefaultShortcuts();
    }
    $keys = array_slice($keys, 0, 4);
    return array_map(fn($k) => ['key' => $k] + $catalog[$k], $keys);
}

/**
 * Tipos de notificação que o GM pode ligar/desligar em Minha Conta.
 *
 * A chave é o que os pontos de envio passam pro sendPushToUser(). Tudo nasce
 * ligado: users.notif_off guarda só o que a pessoa DESLIGOU, então um tipo novo
 * já vale pra todo mundo sem precisar de backfill.
 */
function getNotifCatalog(): array {
    return [
        'trade'       => ['label' => 'Trades',            'icon' => 'bi-arrow-left-right',    'desc' => 'Propostas recebidas, aceites, recusas e trades múltiplas.'],
        'draft'       => ['label' => 'Draft',             'icon' => 'bi-trophy',              'desc' => 'Quando chegar a sua vez de escolher no draft.'],
        'leilao'      => ['label' => 'Leilão',            'icon' => 'bi-hammer',              'desc' => 'Leilão novo na sua liga, proposta recebida e resultado.'],
        'waiver'      => ['label' => 'Waivers',           'icon' => 'bi-clock-history',       'desc' => 'Jogador entrando nos waivers e resultado do seu claim.'],
        'free_agency' => ['label' => 'Free Agency',       'icon' => 'bi-coin',                'desc' => 'Abertura da janela e resultado dos seus pedidos.'],
        'tatica'      => ['label' => 'Tática',            'icon' => 'bi-clipboard2-pulse',    'desc' => 'Aviso quando a janela de edição da tática abre e fecha.'],
        'cap'         => ['label' => 'CAP da liga',       'icon' => 'bi-graph-up-arrow',      'desc' => 'Recálculo do teto salarial da sua liga.'],
        'eventos'     => ['label' => 'Roletas e sorteios','icon' => 'bi-shuffle',             'desc' => 'Roletas, draft de lendas e drafts aleatórios.'],
        'games'       => ['label' => 'Games',             'icon' => 'bi-controller',          'desc' => 'Desafios recebidos nos games, como o Starting5x5.'],
    ];
}

/** Chaves desligadas pelo usuário (string "a,b,c" vinda de users.notif_off). */
function getUserNotifOff(?string $stored): array {
    $catalog = getNotifCatalog();
    $keys = $stored ? array_filter(array_map('trim', explode(',', $stored))) : [];
    return array_values(array_unique(array_filter($keys, fn($k) => isset($catalog[$k]))));
}

/** true quando o tipo está ligado pro usuário (ou quando não há tipo definido). */
function userWantsNotif(PDO $pdo, int $userId, ?string $tipo): bool {
    if ($tipo === null || $tipo === '') return true;
    if (!isset(getNotifCatalog()[$tipo])) return true;

    static $cache = [];
    if (!array_key_exists($userId, $cache)) {
        try {
            $st = $pdo->prepare('SELECT notif_off FROM users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $cache[$userId] = getUserNotifOff($st->fetchColumn() ?: null);
        } catch (Throwable $e) {
            // Coluna ainda não migrada neste banco: manda tudo, como era antes.
            $cache[$userId] = [];
        }
    }
    return !in_array($tipo, $cache[$userId], true);
}

/** "agora", "12min", "3h", "2d" ou a data — pro carimbo de tempo dos cards. */
function tempoRelativoCurto(?string $quando): string {
    $ts = $quando ? strtotime($quando) : false;
    if (!$ts) return '';
    $seg = time() - $ts;
    if ($seg < 60)     return 'agora';
    if ($seg < 3600)   return floor($seg / 60) . 'min';
    if ($seg < 86400)  return floor($seg / 3600) . 'h';
    if ($seg < 604800) return floor($seg / 86400) . 'd';
    return date('d/m', $ts);
}

function isValidAccentColor(?string $color): bool {
    return $color !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
}

function accentColorHex(?string $accentColor): string {
    return isValidAccentColor($accentColor) ? ltrim($accentColor, '#') : 'fc0025';
}

/**
 * Congela a classificação atual do ranking de uma liga.
 *
 * Chamado ao fechar a sprint (antes de zerar os pontos) e pelo botão manual do
 * admin. Sem isto a pontuação do ciclo se perdia por completo — e é também a
 * referência da variação de posição mostrada em rankings.php.
 *
 * Nunca lança: congelar é acessório, não pode derrubar o reset da temporada.
 *
 * @return int quantos times foram congelados
 */
function congelarRankingDaSprint(PDO $pdo, string $league, ?string $label = null): int
{
    $league = strtoupper(trim($league));
    if (!in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) return 0;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ranking_snapshots (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            league        VARCHAR(20) NOT NULL,
            sprint_id     INT NULL,
            sprint_number INT NULL,
            team_id       INT NOT NULL,
            position      INT NOT NULL,
            points        INT NOT NULL DEFAULT 0,
            titles        INT NOT NULL DEFAULT 0,
            label         VARCHAR(120) NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sprint_team (sprint_id, team_id),
            KEY idx_liga_sprint (league, sprint_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stSp = $pdo->prepare("SELECT id, sprint_number FROM sprints WHERE league = ?
                               ORDER BY sprint_number DESC LIMIT 1");
        $stSp->execute([$league]);
        $sprint = $stSp->fetch(PDO::FETCH_ASSOC) ?: null;

        $temPts = false; $temTit = false;
        foreach ($pdo->query("SHOW COLUMNS FROM teams") as $c) {
            if ($c['Field'] === 'ranking_points') $temPts = true;
            if ($c['Field'] === 'ranking_titles') $temTit = true;
        }
        $selPts = $temPts ? 'COALESCE(t.ranking_points,0)' : '0';
        $selTit = $temTit ? 'COALESCE(t.ranking_titles,0)' : '0';

        $st = $pdo->prepare("SELECT t.id AS team_id, {$selPts} AS pts, {$selTit} AS tit
                             FROM teams t WHERE t.league = ?
                             ORDER BY pts DESC, tit DESC, t.city, t.name");
        $st->execute([$league]);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$linhas) return 0;

        // Uma sprint que ja tinha sido congelada e regravada: o estado final
        // manda, nao o de uma tentativa anterior.
        $ins = $pdo->prepare("INSERT INTO ranking_snapshots
            (league, sprint_id, sprint_number, team_id, position, points, titles, label)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE position=VALUES(position), points=VALUES(points),
                                    titles=VALUES(titles), label=VALUES(label)");
        $pos = 0;
        foreach ($linhas as $r) {
            $pos++;
            $ins->execute([$league, $sprint['id'] ?? null, $sprint['sprint_number'] ?? null,
                           (int)$r['team_id'], $pos, (int)$r['pts'], (int)$r['tit'], $label]);
        }
        return $pos;
    } catch (Throwable $e) {
        error_log('congelarRankingDaSprint: ' . $e->getMessage());
        return 0;
    }
}

/**
 * URL de um asset local com ?v=<mtime do arquivo>.
 *
 * O service worker (sw.js) serve CSS em Cache First, entao sem isto o
 * navegador de quem ja acessou continua com a versao antiga ate o CACHE_NAME
 * mudar. Com o mtime na query, qualquer alteracao no arquivo ja vira uma URL
 * nova — e enquanto nada muda, o cache segue valendo.
 */
function assetUrl(string $path): string
{
    $rel = '/' . ltrim($path, '/');
    $mtime = @filemtime(__DIR__ . '/..' . $rel);
    return $mtime ? $rel . '?v=' . $mtime : $rel;
}

/**
 * Temporada ativa de uma liga (a mais recente que ainda não foi concluída).
 * Devolve ['id','season_number','year'] ou null.
 */
function temporadaAtivaDaLiga(PDO $pdo, string $league): ?array
{
    try {
        $st = $pdo->prepare("SELECT id, season_number, year FROM seasons
                             WHERE league = ? AND (status IS NULL OR status <> 'completed')
                             ORDER BY id DESC LIMIT 1");
        $st->execute([strtoupper(trim($league))]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * A sprint em andamento de uma liga.
 *
 * Toda tela deve mostrar só dados da sprint atual — sprint é um ciclo fechado,
 * e o que ficou pra trás vira histórico, não se mistura. Esta consulta estava
 * copiada em três lugares (dashboard, api/team, api/seasons); aqui fica uma vez.
 *
 * @return array|null ['id','sprint_number','start_year','start_date']
 */
/**
 * Liga real do usuário logado: a do time dele no banco, não a que ficou
 * gravada na sessão no login. Quando o admin movia um time de liga, o GM
 * continuava vendo a liga antiga em todo lugar até deslogar e logar de novo.
 * Além de devolver a liga certa, corrige a sessão quando as duas divergem,
 * então a próxima página já carrega certa sozinha.
 *
 * Quem não tem time (admin geral, por exemplo) mantém a liga da sessão.
 */
function ligaAtualDoUsuario(PDO $pdo, ?array $user = null): string
{
    $ligaSessao = strtoupper((string)($user['league'] ?? $_SESSION['user_league'] ?? 'ROOKIE'));
    $userId     = (int)($user['id'] ?? $_SESSION['user_id'] ?? 0);
    if (!$userId) return $ligaSessao;

    try {
        $st = $pdo->prepare('SELECT league FROM teams WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $ligaDoTime = $st->fetchColumn();
    } catch (Throwable $e) {
        return $ligaSessao;
    }
    if (!$ligaDoTime) return $ligaSessao;

    $ligaDoTime = strtoupper((string)$ligaDoTime);
    if ($ligaDoTime !== $ligaSessao) {
        $_SESSION['user_league'] = $ligaDoTime;
    }
    return $ligaDoTime;
}

function sprintAtualDaLiga(PDO $pdo, string $league): ?array
{
    try {
        $st = $pdo->prepare("SELECT id, sprint_number, start_year, start_date
                             FROM sprints WHERE league = ? AND status = 'active'
                             ORDER BY id DESC LIMIT 1");
        $st->execute([strtoupper(trim($league))]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Os ids de temporada que pertencem à sprint atual da liga.
 *
 * Serve pros filtros `... IN (...)` nas tabelas que guardam season_id
 * (season_history, team_season_points, player_season_log, fa_requests,
 * season_awards, playoff_results, draft_sessions).
 *
 * Devolve [0] quando não há sprint ativa: um id impossível, pra consulta não
 * virar "sem filtro" e passar a mostrar tudo — falhar vazio é mais seguro que
 * falhar mostrando dados de outra sprint.
 *
 * @return int[]
 */
function seasonIdsDaSprintAtual(PDO $pdo, string $league): array
{
    $sprint = sprintAtualDaLiga($pdo, $league);
    if (!$sprint) return [0];
    try {
        $st = $pdo->prepare("SELECT id FROM seasons WHERE sprint_id = ?");
        $st->execute([(int)$sprint['id']]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        return $ids ?: [0];
    } catch (Throwable $e) {
        return [0];
    }
}

/**
 * As duas colunas de "mexeu no elenco": QUANDO e em que TEMPORADA.
 *
 * `roster_updated_at` já existia e alimenta o "atualizado há X" na lista de
 * times. `roster_touched_season` é o que o painel usa, e guarda o id em vez
 * da data porque comparar com o início da temporada é conta que pode dar
 * errado; o id não tem como.
 */
function ensureRosterTouchColumn(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    try {
        if ($pdo->query("SHOW COLUMNS FROM teams LIKE 'roster_touched_season'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN roster_touched_season INT NULL");
        }
        if ($pdo->query("SHOW COLUMNS FROM teams LIKE 'roster_updated_at'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN roster_updated_at TIMESTAMP NULL DEFAULT NULL");
        }
        $feito = true;
    } catch (Throwable $e) {
        $feito = true;   // base antiga: segue sem o carimbo, o log ainda vale
    }
}

/**
 * Carimba que este time mexeu no elenco na temporada ativa.
 *
 * Chamado por QUALQUER edição de elenco: salvar atributos, salvar
 * estatísticas, editar um jogador, contratar, dispensar. Antes só o botão
 * "Salvar atributos" contava, e quem preenchia a temporada inteira de
 * estatísticas continuava aparecendo como pendente no painel.
 *
 * Falha em silêncio de propósito: isto é contabilidade do admin, e não pode
 * derrubar a edição do elenco de ninguém.
 */
function marcarElencoAtualizado(PDO $pdo, ?int $teamId): void
{
    if (!$teamId) return;
    try {
        ensureRosterTouchColumn($pdo);
        $st = $pdo->prepare("SELECT league FROM teams WHERE id = ? LIMIT 1");
        $st->execute([$teamId]);
        $league = (string)$st->fetchColumn();
        if ($league === '') return;

        $st = $pdo->prepare("SELECT id FROM seasons
                             WHERE league = ? AND (status IS NULL OR status <> 'completed')
                             ORDER BY id DESC LIMIT 1");
        $st->execute([$league]);
        $seasonId = (int)$st->fetchColumn();
        if ($seasonId <= 0) return;

        $pdo->prepare("UPDATE teams SET roster_touched_season = ?, roster_updated_at = NOW() WHERE id = ?")
            ->execute([$seasonId, $teamId]);
    } catch (Throwable $e) {
        error_log('[roster-touch] ' . $e->getMessage());
    }
}

/**
 * O time já fez a atualização de elenco da temporada ativa?
 *
 * Vale QUALQUER mexida no elenco, e não só o botão "Salvar atributos": ou o
 * carimbo `roster_touched_season`, ou uma linha em player_season_log daquela
 * temporada. Os dois sinais somam porque o carimbo é novo — sem o segundo, os
 * times que já tinham atualizado voltariam a aparecer como pendentes no dia
 * em que isto subisse.
 *
 * Sem temporada ativa não há o que cobrar — devolve true para não travar nada.
 */
function elencoAtualizadoNaTemporada(PDO $pdo, ?int $teamId, ?int $seasonId): bool
{
    if (!$teamId || !$seasonId) return true;
    try {
        ensureRosterTouchColumn($pdo);
        $st = $pdo->prepare("SELECT 1 FROM teams WHERE id = ? AND roster_touched_season = ? LIMIT 1");
        $st->execute([$teamId, $seasonId]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $e) { /* sem a coluna, sobra o log */ }
    try {
        $st = $pdo->prepare("SELECT 1 FROM player_season_log
                             WHERE team_id = ? AND season_id = ? LIMIT 1");
        $st->execute([$teamId, $seasonId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        // Tabela ausente numa base antiga: não é motivo para bloquear trades.
        return true;
    }
}

/**
 * O draft da temporada ativa da liga já foi concluído?
 * É o gatilho para começar a cobrar a atualização de elenco.
 */
function draftConcluidoNaTemporada(PDO $pdo, ?int $seasonId): bool
{
    if (!$seasonId) return false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM draft_sessions
                             WHERE season_id = ? AND status = 'completed' LIMIT 1");
        $st->execute([$seasonId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Garante o schema do Games no banco do site.
 *
 * As tabelas do games nascem sozinhas no primeiro acesso a uma página de jogo
 * (games/core/conexao.php). Mas as telas de administração podem ser abertas
 * antes disso — e aí quebravam com "table doesn't exist". Esta função é o
 * ponto único: barata em regime normal (uma consulta), roda o SQL só quando a
 * tabela de perfil ainda não existe.
 */
function ensureGamesSchema(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'games_usuarios'")->fetch()) {
            $sql = @file_get_contents(__DIR__ . '/../sql/games_merge.sql');
            if ($sql !== false) $pdo->exec($sql);
        }
        $ok = true;
    } catch (Throwable $e) {
        error_log('[ensureGamesSchema] ' . $e->getMessage());
    }
}

/**
 * O ano a partir do qual uma pick ainda vale como ativo de troca.
 *
 * Três telas faziam esta conta, cada uma de um jeito, e por isso mostravam
 * conjuntos diferentes de picks:
 *
 *   picks.php          só a temporada corrente
 *   api/picks.php      o DRAFT aberto, e a temporada como reserva
 *   trade-simulator    igual à api, com outra função pra calcular o ano
 *
 * Quando a liga tinha um draft aberto de um ano MAIOR que o da temporada, a
 * Trade Machine cortava por ele e a lista de picks vinha vazia — enquanto a
 * página de Picks, ali do lado, mostrava as mesmas picks normalmente. Foi o
 * que aconteceu na RISE, na NEXT e na ROOKIE.
 *
 * A regra agora é uma só: vale o MENOR dos dois. O draft aberto existe pra
 * ABAIXAR o corte (o draft de um ano que já passou ainda está rolando, e as
 * picks dele continuam em jogo), nunca pra subir — uma pick não pode sumir da
 * troca por causa de um draft que ainda nem começou.
 */
function anoDeCorteDasPicks(PDO $pdo, ?string $liga): int
{
    $liga = trim((string)$liga);
    if ($liga === '') return (int)date('Y');

    // fetch() devolve FALSE quando não há linha, não null: o hint `?array`
    // recusava o valor, a exceção subia e a função inteira caía no ano do
    // relógio — que não tem nada a ver com o ano da liga no jogo.
    $ano = function ($r): int {
        if (!is_array($r)) return 0;
        if (isset($r['start_year'], $r['season_number'])) {
            return (int)$r['start_year'] + (int)$r['season_number'] - 1;
        }
        return (int)($r['year'] ?? 0);
    };

    $anos = [];
    try {
        // O draft aberto mais ANTIGO: se dois estão abertos, quem manda é o
        // que ainda não terminou, não o que foi criado por último.
        $st = $pdo->prepare('SELECT s.season_number, s.year, sp.start_year
            FROM draft_sessions ds
            JOIN seasons s ON ds.season_id = s.id
            LEFT JOIN sprints sp ON s.sprint_id = sp.id
            WHERE ds.league = ? AND ds.status IN ("setup","in_progress")');
        $st->execute([$liga]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $y = $ano($r);
            if ($y > 0) $anos[] = $y;
        }

        $st2 = $pdo->prepare('SELECT s.season_number, s.year, sp.start_year
            FROM seasons s LEFT JOIN sprints sp ON s.sprint_id = sp.id
            WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ("completed"))
            ORDER BY s.created_at DESC LIMIT 1');
        $st2->execute([$liga]);
        $y = $ano($st2->fetch(PDO::FETCH_ASSOC));
        if ($y > 0) $anos[] = $y;
    } catch (Throwable $e) {
        error_log('[anoDeCorteDasPicks] ' . $e->getMessage());
    }

    return $anos ? min($anos) : (int)date('Y');
}
