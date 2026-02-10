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
            notes TEXT NULL,
            pick_id INT NULL,
            season_scope VARCHAR(20) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_punishments_team (team_id),
            INDEX idx_punishments_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) {
        // ignore
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
ensureTeamPunishmentColumns($pdo);

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
        if (!$teamId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Time inválido']);
            exit;
        }
        $stmt = $pdo->prepare('
            SELECT tp.*, pk.season_year, pk.round
            FROM team_punishments tp
            LEFT JOIN picks pk ON pk.id = tp.pick_id
            WHERE tp.team_id = ?
            ORDER BY tp.created_at DESC, tp.id DESC
        ');
        $stmt->execute([$teamId]);
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
    if ($action !== 'add') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
        exit;
    }

    $teamId = (int)($body['team_id'] ?? 0);
    $type = strtoupper(trim($body['type'] ?? ''));
    $notes = trim($body['notes'] ?? '');
    $pickId = isset($body['pick_id']) ? (int)$body['pick_id'] : null;
    $seasonScope = strtolower(trim($body['season_scope'] ?? 'current'));
    $createdAt = trim($body['created_at'] ?? '');

    if (!$teamId || !in_array($type, $allowedTypes, true)) {
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

    try {
        $pdo->beginTransaction();

        // Aplicar efeitos
        if ($type === 'PERDA_PICK_1R') {
            $stmtPick = $pdo->prepare('SELECT id FROM picks WHERE team_id = ? AND round = 1 ORDER BY season_year ASC, id ASC LIMIT 1');
            $stmtPick->execute([$teamId]);
            $pickId = (int)($stmtPick->fetchColumn() ?: 0);
            if ($pickId) {
                $stmtDel = $pdo->prepare('DELETE FROM picks WHERE id = ?');
                $stmtDel->execute([$pickId]);
            }
        }

        if ($type === 'PERDA_PICK_ESPECIFICA') {
            if (!$pickId) {
                throw new Exception('Selecione a pick para remover');
            }
            $stmtDel = $pdo->prepare('DELETE FROM picks WHERE id = ? AND team_id = ?');
            $stmtDel->execute([$pickId, $teamId]);
        }

        if ($type === 'BAN_TRADES') {
            $stmt = $pdo->prepare('UPDATE teams SET ban_trades_until_cycle = ? WHERE id = ?');
            $stmt->execute([$banUntil, $teamId]);
        }
        if ($type === 'BAN_TRADES_PICKS') {
            $stmt = $pdo->prepare('UPDATE teams SET ban_trades_picks_until_cycle = ? WHERE id = ?');
            $stmt->execute([$banUntil, $teamId]);
        }
        if ($type === 'BAN_FREE_AGENCY') {
            $stmt = $pdo->prepare('UPDATE teams SET ban_fa_until_cycle = ? WHERE id = ?');
            $stmt->execute([$banUntil, $teamId]);
        }

        // Registrar punição
        if ($createdAt !== '') {
            $stmtIns = $pdo->prepare('INSERT INTO team_punishments (team_id, league, type, notes, pick_id, season_scope, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmtIns->execute([$teamId, $league, $type, $notes ?: null, $pickId ?: null, $seasonScope, $user['id'], $createdAt]);
        } else {
            $stmtIns = $pdo->prepare('INSERT INTO team_punishments (team_id, league, type, notes, pick_id, season_scope, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmtIns->execute([$teamId, $league, $type, $notes ?: null, $pickId ?: null, $seasonScope, $user['id']]);
        }

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
