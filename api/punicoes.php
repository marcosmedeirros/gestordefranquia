<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

$user = getUserSession();
if (!$user || ($user['user_type'] ?? 'jogador') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function ensurePunishmentsTable(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS team_punishments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team_id INT NOT NULL,
            league VARCHAR(20) NULL,
            type VARCHAR(50) NOT NULL,
            motive VARCHAR(120) NULL,
            punishment_label VARCHAR(120) NULL,
            effect_type VARCHAR(50) NULL,
            notes TEXT NULL,
            pick_id INT NULL,
            season_scope VARCHAR(20) NULL,
            ban_until_cycle INT NULL,
            removed_pick_season_year INT NULL,
            removed_pick_round INT NULL,
            removed_pick_original_team_id INT NULL,
            removed_pick_last_owner_team_id INT NULL,
            reverted_at DATETIME NULL,
            reverted_by INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_punishments_team (team_id),
            INDEX idx_punishments_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) {
        // ignore
    }
}

function ensurePunishmentsColumns(PDO $pdo): void
{
    $columns = [
        'motive' => "ALTER TABLE team_punishments ADD COLUMN motive VARCHAR(120) NULL",
        'punishment_label' => "ALTER TABLE team_punishments ADD COLUMN punishment_label VARCHAR(120) NULL",
        'effect_type' => "ALTER TABLE team_punishments ADD COLUMN effect_type VARCHAR(50) NULL",
        'ban_until_cycle' => "ALTER TABLE team_punishments ADD COLUMN ban_until_cycle INT NULL",
        'removed_pick_season_year' => "ALTER TABLE team_punishments ADD COLUMN removed_pick_season_year INT NULL",
        'removed_pick_round' => "ALTER TABLE team_punishments ADD COLUMN removed_pick_round INT NULL",
        'removed_pick_original_team_id' => "ALTER TABLE team_punishments ADD COLUMN removed_pick_original_team_id INT NULL",
        'removed_pick_last_owner_team_id' => "ALTER TABLE team_punishments ADD COLUMN removed_pick_last_owner_team_id INT NULL",
        'reverted_at' => "ALTER TABLE team_punishments ADD COLUMN reverted_at DATETIME NULL",
        'reverted_by' => "ALTER TABLE team_punishments ADD COLUMN reverted_by INT NULL"
    ];

    foreach ($columns as $column => $statement) {
        if (!columnExists($pdo, 'team_punishments', $column)) {
            try { $pdo->exec($statement); } catch (Exception $e) {}
        }
    }
}

function ensurePunishmentsCatalog(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS punishment_motives (
            id INT AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(120) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS punishment_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(120) NOT NULL UNIQUE,
            effect_type VARCHAR(50) NOT NULL,
            requires_pick TINYINT(1) NOT NULL DEFAULT 0,
            requires_scope TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) {
        return;
    }

    $defaultMotives = [
        'Numero minimo de jogadores',
        'Numero maximo de jogadores',
        'Diretrizes erradas'
    ];

    foreach ($defaultMotives as $motive) {
        try {
            $stmt = $pdo->prepare('INSERT IGNORE INTO punishment_motives (label) VALUES (?)');
            $stmt->execute([$motive]);
        } catch (Exception $e) {}
    }

    $defaultTypes = [
        ['Aviso formal', 'AVISO_FORMAL', 0, 0],
        ['Perda da Pick 1º rodada', 'PERDA_PICK_1R', 0, 0],
        ['Perda de pick especifica', 'PERDA_PICK_ESPECIFICA', 1, 0],
        ['Trades bloqueadas por uma temporada', 'BAN_TRADES', 0, 1],
        ['Trades sem picks', 'BAN_TRADES_PICKS', 0, 1],
        ['Sem poder usar FA na temporada', 'BAN_FREE_AGENCY', 0, 1],
        ['Rotacao automatica', 'ROTACAO_AUTOMATICA', 0, 1]
    ];

    foreach ($defaultTypes as $typeRow) {
        try {
            $stmt = $pdo->prepare('INSERT IGNORE INTO punishment_types (label, effect_type, requires_pick, requires_scope) VALUES (?, ?, ?, ?)');
            $stmt->execute($typeRow);
        } catch (Exception $e) {}
    }
}

function ensureTeamPunishmentColumns(PDO $pdo): void
{
    try {
        if (!columnExists($pdo, 'teams', 'ban_trades_until_cycle')) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN ban_trades_until_cycle INT NULL AFTER trades_cycle");
        }
        if (!columnExists($pdo, 'teams', 'ban_trades_picks_until_cycle')) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN ban_trades_picks_until_cycle INT NULL AFTER ban_trades_until_cycle");
        }
        if (!columnExists($pdo, 'teams', 'ban_fa_until_cycle')) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN ban_fa_until_cycle INT NULL AFTER ban_trades_picks_until_cycle");
        }
            if (!columnExists($pdo, 'teams', 'auto_rotation_until_cycle')) {
                $pdo->exec("ALTER TABLE teams ADD COLUMN auto_rotation_until_cycle INT NULL AFTER ban_fa_until_cycle");
            }
    } catch (Exception $e) {
        // ignore
    }
}

function getTeamCurrentCycle(PDO $pdo, int $teamId): int
{
    if (!columnExists($pdo, 'teams', 'current_cycle')) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT current_cycle FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

ensurePunishmentsTable($pdo);
ensurePunishmentsColumns($pdo);
ensureTeamPunishmentColumns($pdo);
ensurePunishmentsCatalog($pdo);

$allowedTypes = [
    'AVISO_FORMAL',
    'PERDA_PICK_1R',
    'PERDA_PICK_ESPECIFICA',
    'BAN_TRADES',
    'BAN_TRADES_PICKS',
    'BAN_FREE_AGENCY',
    'ROTACAO_AUTOMATICA',
    'TETO_MINUTOS',
    'REDISTRIBUICAO_MINUTOS',
    'ANULACAO_TRADE',
    'ANULACAO_FA',
    'DROP_OBRIGATORIO',
    'CORRECAO_ROSTER',
    'INATIVIDADE_REGISTRADA',
    'EXCLUSAO_LIGA'
];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'catalog') {
        try {
            $motives = $pdo->query('SELECT id, label FROM punishment_motives ORDER BY label ASC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $motives = [];
        }

        try {
            $types = $pdo->query('SELECT id, label, effect_type, requires_pick, requires_scope FROM punishment_types ORDER BY label ASC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $types = [];
        }

        echo json_encode(['success' => true, 'motives' => $motives, 'types' => $types]);
        exit;
    }
    if ($action === 'leagues') {
        try {
            $stmt = $pdo->query("SELECT name FROM leagues ORDER BY FIELD(name,'ELITE','NEXT','RISE','ROOKIE')");
            $leagues = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['success' => true, 'leagues' => $leagues]);
        } catch (Exception $e) {
            echo json_encode(['success' => true, 'leagues' => []]);
        }
        exit;
    }

    if ($action === 'teams') {
        $league = strtoupper(trim($_GET['league'] ?? ''));
        if (!$league) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Liga inválida']);
            exit;
        }
        $stmt = $pdo->prepare('SELECT id, city, name FROM teams WHERE league = ? ORDER BY city, name');
        $stmt->execute([$league]);
        echo json_encode(['success' => true, 'teams' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'punishments') {
        $teamId = (int)($_GET['team_id'] ?? 0);
        $league = strtoupper(trim($_GET['league'] ?? ''));

        if (!$teamId && !$league) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Informe time ou liga']);
            exit;
        }

        $conditions = [];
        $params = [];
        if ($teamId) {
            $conditions[] = 'tp.team_id = ?';
            $params[] = $teamId;
        }
        if ($league) {
            $conditions[] = 'tp.league = ?';
            $params[] = $league;
        }

        $where = implode(' AND ', $conditions);
        $stmt = $pdo->prepare('
            SELECT tp.*, pk.season_year, pk.round,
                   t.city, t.name, t.league AS team_league
            FROM team_punishments tp
            LEFT JOIN picks pk ON pk.id = tp.pick_id
            JOIN teams t ON t.id = tp.team_id
            WHERE ' . $where . '
            ORDER BY tp.created_at DESC, tp.id DESC
        ');
        $stmt->execute($params);
        echo json_encode(['success' => true, 'punishments' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'picks') {
        $teamId = (int)($_GET['team_id'] ?? 0);
        if (!$teamId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Time inválido']);
            exit;
        }
        $stmt = $pdo->prepare('SELECT id, season_year, round FROM picks WHERE team_id = ? ORDER BY season_year ASC, round ASC');
        $stmt->execute([$teamId]);
        echo json_encode(['success' => true, 'picks' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    if (!in_array($action, ['add', 'add_motive', 'add_type', 'revert'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
        exit;
    }

    if ($action === 'add_motive') {
        $label = trim($body['label'] ?? '');
        if ($label === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Motivo obrigatório']);
            exit;
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO punishment_motives (label) VALUES (?)');
            $stmt->execute([$label]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar motivo']);
        }
        exit;
    }

    if ($action === 'add_type') {
        $label = trim($body['label'] ?? '');
        $effectType = strtoupper(trim($body['effect_type'] ?? ''));
        $requiresPick = !empty($body['requires_pick']) ? 1 : 0;
        $requiresScope = !empty($body['requires_scope']) ? 1 : 0;
        if ($label === '' || $effectType === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Punição e efeito obrigatórios']);
            exit;
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO punishment_types (label, effect_type, requires_pick, requires_scope) VALUES (?, ?, ?, ?)');
            $stmt->execute([$label, $effectType, $requiresPick, $requiresScope]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar punição']);
        }
        exit;
    }

    if ($action === 'revert') {
        $punishmentId = (int)($body['punishment_id'] ?? 0);
        if (!$punishmentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Punição inválida']);
            exit;
        }

        $stmtPun = $pdo->prepare('SELECT * FROM team_punishments WHERE id = ?');
        $stmtPun->execute([$punishmentId]);
        $pun = $stmtPun->fetch(PDO::FETCH_ASSOC);
        if (!$pun) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Punição não encontrada']);
            exit;
        }
        if (!empty($pun['reverted_at'])) {
            echo json_encode(['success' => true]);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $teamId = (int)$pun['team_id'];
            $effectType = strtoupper($pun['effect_type'] ?? $pun['type']);

            if ($effectType === 'PERDA_PICK_1R' || $effectType === 'PERDA_PICK_ESPECIFICA') {
                $seasonYear = (int)($pun['removed_pick_season_year'] ?? 0);
                $round = (int)($pun['removed_pick_round'] ?? 0);
                $originalTeamId = (int)($pun['removed_pick_original_team_id'] ?? 0);
                if ($seasonYear && $round && $originalTeamId) {
                    $stmtExists = $pdo->prepare('SELECT id FROM picks WHERE original_team_id = ? AND season_year = ? AND round = ?');
                    $stmtExists->execute([$originalTeamId, $seasonYear, $round]);
                    if (!$stmtExists->fetchColumn()) {
                        $stmtInsert = $pdo->prepare('INSERT INTO picks (team_id, original_team_id, season_year, round, last_owner_team_id, notes) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmtInsert->execute([
                            $teamId,
                            $originalTeamId,
                            $seasonYear,
                            (string)$round,
                            $pun['removed_pick_last_owner_team_id'] ? (int)$pun['removed_pick_last_owner_team_id'] : null,
                            'Reversão de punição'
                        ]);
                    }
                }
            }

            if ($effectType === 'BAN_TRADES') {
                $stmt = $pdo->prepare('UPDATE teams SET ban_trades_until_cycle = NULL WHERE id = ? AND ban_trades_until_cycle = ?');
                $stmt->execute([$teamId, $pun['ban_until_cycle']]);
            }
            if ($effectType === 'BAN_TRADES_PICKS') {
                $stmt = $pdo->prepare('UPDATE teams SET ban_trades_picks_until_cycle = NULL WHERE id = ? AND ban_trades_picks_until_cycle = ?');
                $stmt->execute([$teamId, $pun['ban_until_cycle']]);
            }
            if ($effectType === 'BAN_FREE_AGENCY') {
                $stmt = $pdo->prepare('UPDATE teams SET ban_fa_until_cycle = NULL WHERE id = ? AND ban_fa_until_cycle = ?');
                $stmt->execute([$teamId, $pun['ban_until_cycle']]);
            }
            if ($effectType === 'ROTACAO_AUTOMATICA') {
                $stmt = $pdo->prepare('UPDATE teams SET auto_rotation_until_cycle = NULL WHERE id = ? AND auto_rotation_until_cycle = ?');
                $stmt->execute([$teamId, $pun['ban_until_cycle']]);
            }

            $stmtRev = $pdo->prepare('UPDATE team_punishments SET reverted_at = NOW(), reverted_by = ? WHERE id = ?');
            $stmtRev->execute([$user['id'], $punishmentId]);

            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Erro ao reverter punição']);
        }
        exit;
    }

    $teamId = (int)($body['team_id'] ?? 0);
    $type = strtoupper(trim($body['type'] ?? ''));
    $notes = trim($body['notes'] ?? '');
    $motive = trim($body['motive'] ?? '');
    $punishmentLabel = trim($body['punishment_label'] ?? '');
    $effectType = strtoupper(trim($body['effect_type'] ?? $type));
    $pickId = isset($body['pick_id']) ? (int)$body['pick_id'] : null;
    $seasonScope = strtolower(trim($body['season_scope'] ?? 'current'));
    $createdAt = trim($body['created_at'] ?? '');

    if (!$teamId || !in_array($effectType, $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }

    $stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE id = ?');
    $stmtTeam->execute([$teamId]);
    $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
    if (!$team) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
        exit;
    }

    $league = $team['league'] ?? null;
    $currentCycle = getTeamCurrentCycle($pdo, $teamId);
    $banUntil = $currentCycle;
    if ($seasonScope === 'next' && $currentCycle > 0) {
        $banUntil = $currentCycle + 1;
    }
    $banUntilForRecord = in_array($effectType, ['BAN_TRADES', 'BAN_TRADES_PICKS', 'BAN_FREE_AGENCY', 'ROTACAO_AUTOMATICA'], true) ? $banUntil : null;

    try {
        $pdo->beginTransaction();

        // Aplicar efeitos
        $removedPick = null;
        if ($effectType === 'PERDA_PICK_1R') {
            $stmtPick = $pdo->prepare('SELECT id FROM picks WHERE team_id = ? AND round = 1 ORDER BY season_year ASC, id ASC LIMIT 1');
            $stmtPick->execute([$teamId]);
            $pickId = (int)($stmtPick->fetchColumn() ?: 0);
            if ($pickId) {
                $stmtPickInfo = $pdo->prepare('SELECT season_year, round, original_team_id, last_owner_team_id FROM picks WHERE id = ?');
                $stmtPickInfo->execute([$pickId]);
                $removedPick = $stmtPickInfo->fetch(PDO::FETCH_ASSOC);

                $stmtDel = $pdo->prepare('DELETE FROM picks WHERE id = ?');
                $stmtDel->execute([$pickId]);
            }
        }

        if ($effectType === 'PERDA_PICK_ESPECIFICA') {
            if (!$pickId) {
                throw new Exception('Selecione a pick para remover');
            }
            $stmtPickInfo = $pdo->prepare('SELECT season_year, round, original_team_id, last_owner_team_id FROM picks WHERE id = ? AND team_id = ?');
            $stmtPickInfo->execute([$pickId, $teamId]);
            $removedPick = $stmtPickInfo->fetch(PDO::FETCH_ASSOC);

            $stmtDel = $pdo->prepare('DELETE FROM picks WHERE id = ? AND team_id = ?');
            $stmtDel->execute([$pickId, $teamId]);
        }

        if ($effectType === 'BAN_TRADES') {
            $stmt = $pdo->prepare('UPDATE teams SET ban_trades_until_cycle = ? WHERE id = ?');
            $stmt->execute([$banUntil, $teamId]);
        }
        if ($effectType === 'BAN_TRADES_PICKS') {
            $stmt = $pdo->prepare('UPDATE teams SET ban_trades_picks_until_cycle = ? WHERE id = ?');
            $stmt->execute([$banUntil, $teamId]);
        }
        if ($effectType === 'BAN_FREE_AGENCY') {
            $stmt = $pdo->prepare('UPDATE teams SET ban_fa_until_cycle = ? WHERE id = ?');
            $stmt->execute([$banUntil, $teamId]);
        }
        if ($effectType === 'ROTACAO_AUTOMATICA') {
            $stmt = $pdo->prepare('UPDATE teams SET auto_rotation_until_cycle = ? WHERE id = ?');
            $stmt->execute([$banUntil, $teamId]);
        }

        // Registrar punição
    $columns = 'team_id, league, type, motive, punishment_label, effect_type, notes, pick_id, season_scope, ban_until_cycle, removed_pick_season_year, removed_pick_round, removed_pick_original_team_id, removed_pick_last_owner_team_id, created_by';
        $values = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
        $params = [
            $teamId,
            $league,
            $effectType,
            $motive ?: null,
            $punishmentLabel ?: null,
            $effectType,
            $notes ?: null,
            $pickId ?: null,
            $seasonScope,
            $banUntilForRecord,
            $removedPick['season_year'] ?? null,
            $removedPick['round'] ?? null,
            $removedPick['original_team_id'] ?? null,
            $removedPick['last_owner_team_id'] ?? null,
            $user['id']
        ];

        if ($createdAt !== '') {
            $columns .= ', created_at';
            $values .= ', ?';
            $params[] = $createdAt;
        }

        $stmtIns = $pdo->prepare('INSERT INTO team_punishments (' . $columns . ') VALUES (' . $values . ')');
        $stmtIns->execute($params);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não suportado']);
