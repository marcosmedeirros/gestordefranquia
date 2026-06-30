<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
requireAuth();

$user   = getUserSession();
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Auto-create feed table if it doesn't exist
try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS mercado_feed (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        league     VARCHAR(100) NOT NULL,
        user_id    INT NOT NULL,
        team_id    INT NULL,
        content    TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_league_created (league, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Exception $e) { /* silencia — tabela pode já existir com outro charset */ }

header('Content-Type: application/json');

try {
    switch ($action) {

        case 'feed_posts':
            $league = $user['league'] ?? null;
            if (!$league) { echo json_encode(['posts' => []]); exit; }

            $limit  = min(100, (int)($_GET['limit'] ?? 50));
            $offset = max(0,   (int)($_GET['offset'] ?? 0));

            $stmt = $pdo->prepare('
                SELECT mp.id, mp.content, mp.created_at,
                       u.name  AS user_name,  u.photo_url  AS user_photo,
                       u.id    AS user_id,
                       t.city  AS team_city,  t.name       AS team_name,
                       t.photo_url AS team_photo
                FROM mercado_feed mp
                JOIN users  u ON mp.user_id = u.id
                LEFT JOIN teams t ON mp.team_id = t.id
                WHERE mp.league = ?
                ORDER BY mp.created_at DESC
                LIMIT ? OFFSET ?
            ');
            $stmt->execute([$league, $limit, $offset]);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['posts' => $posts]);
            break;

        case 'feed_post':
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

            $league  = $user['league'] ?? null;
            $data    = json_decode(file_get_contents('php://input'), true) ?? [];
            $content = trim($data['content'] ?? '');

            if (!$league || $content === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Conteúdo obrigatório']);
                exit;
            }
            if (mb_strlen($content) > 500) {
                http_response_code(400);
                echo json_encode(['error' => 'Máximo 500 caracteres']);
                exit;
            }

            $stmtTeam = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? AND league = ? LIMIT 1');
            $stmtTeam->execute([$user['id'], $league]);
            $team   = $stmtTeam->fetch();
            $teamId = $team ? (int)$team['id'] : null;

            $ins = $pdo->prepare('INSERT INTO mercado_feed (league, user_id, team_id, content) VALUES (?, ?, ?, ?)');
            $ins->execute([$league, (int)$user['id'], $teamId, $content]);
            $postId = (int)$pdo->lastInsertId();

            // FIFO: mantém máximo 3 posts por usuário, apaga o(s) mais antigo(s)
            $stmtOld = $pdo->prepare('
                SELECT id FROM mercado_feed WHERE user_id = ? ORDER BY created_at ASC
            ');
            $stmtOld->execute([(int)$user['id']]);
            $allIds = $stmtOld->fetchAll(PDO::FETCH_COLUMN);
            if (count($allIds) > 3) {
                $toDelete = array_slice($allIds, 0, count($allIds) - 3);
                $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
                $pdo->prepare("DELETE FROM mercado_feed WHERE id IN ($placeholders)")
                    ->execute($toDelete);
            }

            $stmtGet = $pdo->prepare('
                SELECT mp.id, mp.content, mp.created_at,
                       u.name  AS user_name,  u.photo_url  AS user_photo,
                       u.id    AS user_id,
                       t.city  AS team_city,  t.name       AS team_name,
                       t.photo_url AS team_photo
                FROM mercado_feed mp
                JOIN users  u ON mp.user_id = u.id
                LEFT JOIN teams t ON mp.team_id = t.id
                WHERE mp.id = ?
            ');
            $stmtGet->execute([$postId]);
            $post = $stmtGet->fetch(PDO::FETCH_ASSOC) ?: [];

            echo json_encode(['success' => true, 'post' => $post]);
            break;

        case 'feed_delete':
            if ($method !== 'POST' && $method !== 'DELETE') {
                http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
            }
            $data   = json_decode(file_get_contents('php://input'), true) ?? [];
            $postId = (int)($data['post_id'] ?? $_GET['post_id'] ?? 0);

            if (!$postId) { http_response_code(400); echo json_encode(['error' => 'ID inválido']); exit; }

            $stmt = $pdo->prepare('SELECT user_id FROM mercado_feed WHERE id = ?');
            $stmt->execute([$postId]);
            $row = $stmt->fetch();
            if (!$row) { http_response_code(404); echo json_encode(['error' => 'Post não encontrado']); exit; }

            $isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
            if (!$isAdmin && (int)$row['user_id'] !== (int)$user['id']) {
                http_response_code(403); echo json_encode(['error' => 'Sem permissão']); exit;
            }

            $pdo->prepare('DELETE FROM mercado_feed WHERE id = ?')->execute([$postId]);
            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Action not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
