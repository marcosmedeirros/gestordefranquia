<?php
/**
 * API da Roleta de Eliminação de Anos (por liga)
 *
 * Admin cadastra os anos que entram na roleta de uma liga, gira pra eliminar
 * um ano por vez (sorteio aleatório dentre os que ainda restam), e o histórico
 * de eliminação fica registrado. Roda até sobrar só 1 ano.
 */

require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$pdo = db();
$user_id = $_SESSION['user_id'];
$is_global_admin = hasAdminAccess($pdo, (int)$user_id);
$admin_leagues = $is_global_admin ? ['ELITE', 'NEXT', 'RISE', 'ROOKIE'] : getAdminLeagues($pdo, (int)$user_id);

if (empty($admin_leagues)) {
    echo json_encode(['success' => false, 'error' => 'Apenas administradores podem acessar a roleta']);
    exit;
}

function ensureRoletaTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS roleta_anos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
        year INT NOT NULL,
        status ENUM('pool','eliminated') NOT NULL DEFAULT 'pool',
        elimination_order INT NULL,
        eliminated_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_league_year (league, year),
        INDEX idx_league_status (league, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureRoletaTable($pdo);

function requireLeagueAccess(array $adminLeagues, ?string $league): string
{
    $league = $league ? strtoupper(trim($league)) : '';
    if (!in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Liga inválida']);
        exit;
    }
    if (!in_array($league, $adminLeagues, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Você não administra esta liga']);
        exit;
    }
    return $league;
}

function loadState(PDO $pdo, string $league): array
{
    $stmt = $pdo->prepare("SELECT id, year FROM roleta_anos WHERE league = ? AND status = 'pool' ORDER BY year ASC");
    $stmt->execute([$league]);
    $pool = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt2 = $pdo->prepare("SELECT id, year, elimination_order, eliminated_at FROM roleta_anos WHERE league = ? AND status = 'eliminated' ORDER BY elimination_order ASC");
    $stmt2->execute([$league]);
    $eliminated = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    return [
        'pool' => $pool,
        'eliminated' => $eliminated,
        'finished' => count($pool) === 1,
        'winner' => count($pool) === 1 ? $pool[0]['year'] : null,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'state';
    if ($action === 'state') {
        $league = requireLeagueAccess($admin_leagues, $_GET['league'] ?? null);
        echo json_encode(['success' => true, 'league' => $league] + loadState($pdo, $league));
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    switch ($action) {
        case 'add_year': {
            $league = requireLeagueAccess($admin_leagues, $body['league'] ?? null);
            $year = (int)($body['year'] ?? 0);
            if ($year < 1900 || $year > 2200) {
                echo json_encode(['success' => false, 'error' => 'Ano inválido']);
                exit;
            }
            try {
                $stmt = $pdo->prepare("INSERT INTO roleta_anos (league, year, status) VALUES (?, ?, 'pool')");
                $stmt->execute([$league, $year]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => 'Esse ano já está na roleta desta liga']);
                exit;
            }
            echo json_encode(['success' => true, 'league' => $league] + loadState($pdo, $league));
            break;
        }

        case 'remove_year': {
            $league = requireLeagueAccess($admin_leagues, $body['league'] ?? null);
            $id = (int)($body['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'id obrigatório']);
                exit;
            }
            // Só remove se ainda estiver no pool (não mexe em histórico já sorteado)
            $stmt = $pdo->prepare("DELETE FROM roleta_anos WHERE id = ? AND league = ? AND status = 'pool'");
            $stmt->execute([$id, $league]);
            echo json_encode(['success' => true, 'league' => $league] + loadState($pdo, $league));
            break;
        }

        case 'spin': {
            $league = requireLeagueAccess($admin_leagues, $body['league'] ?? null);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT id, year FROM roleta_anos WHERE league = ? AND status = 'pool' FOR UPDATE");
                $stmt->execute([$league]);
                $pool = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($pool) <= 1) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => count($pool) === 1
                        ? 'Só resta um ano — já é o vencedor.'
                        : 'Cadastre pelo menos dois anos antes de girar.']);
                    exit;
                }

                $chosen = $pool[random_int(0, count($pool) - 1)];

                $stmtMax = $pdo->prepare("SELECT COALESCE(MAX(elimination_order), 0) FROM roleta_anos WHERE league = ?");
                $stmtMax->execute([$league]);
                $nextOrder = (int)$stmtMax->fetchColumn() + 1;

                $stmtElim = $pdo->prepare("UPDATE roleta_anos SET status = 'eliminated', elimination_order = ?, eliminated_at = NOW() WHERE id = ?");
                $stmtElim->execute([$nextOrder, $chosen['id']]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erro ao girar a roleta.']);
                exit;
            }

            echo json_encode(['success' => true, 'league' => $league, 'eliminated_year' => (int)$chosen['year']] + loadState($pdo, $league));
            break;
        }

        case 'reset': {
            $league = requireLeagueAccess($admin_leagues, $body['league'] ?? null);
            $pdo->prepare("DELETE FROM roleta_anos WHERE league = ?")->execute([$league]);
            echo json_encode(['success' => true, 'league' => $league] + loadState($pdo, $league));
            break;
        }

        default:
            echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);
