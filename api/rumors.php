<?php
/**
 * API de Rumores (aba Rumores ao lado de Trades Gerais)
 * - GMs podem publicar e remover seus próprios rumores
 * - Admin pode comentar (máx 3 por liga; ao criar o 4º, apaga o mais antigo) e remover qualquer comentário
 * - Admin pode remover qualquer rumor
 */

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';

header('Content-Type: application/json');

$pdo = db();
$user = getUserSession();
$isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
$method = $_SERVER['REQUEST_METHOD'];

function jsonErr($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function getUserTeam(PDO $pdo, int $userId) {
    $stmt = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($method === 'GET') {
    // Listar rumores e comentários do admin por liga do usuário (ou query param)
    $league = $_GET['league'] ?? null;
    if (!$league) {
        $team = $user && isset($user['id']) ? getUserTeam($pdo, (int)$user['id']) : null;
        $league = $team['league'] ?? ($_GET['league'] ?? null);
    }
    if (!$league) jsonErr('league obrigatório');

    try {
        $stmtR = $pdo->prepare('SELECT r.*, t.city, t.name, t.photo_url FROM rumors r INNER JOIN teams t ON r.team_id = t.id WHERE r.league = ? ORDER BY r.created_at DESC');
        $stmtR->execute([$league]);
        $rumors = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        $stmtA = $pdo->prepare('SELECT c.*, u.name as admin_name FROM rumor_admin_comments c INNER JOIN users u ON c.user_id = u.id WHERE c.league = ? ORDER BY c.created_at DESC LIMIT 3');
        $stmtA->execute([$league]);
        $adminComments = $stmtA->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'rumors' => $rumors, 'admin_comments' => $adminComments]);
    } catch (Exception $e) {
        jsonErr($e->getMessage());
    }
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? '';

    try {
        switch ($action) {
            case 'add_rumor': {
                if (!$user || !isset($user['id'])) jsonErr('Não autenticado', 401);
                $team = getUserTeam($pdo, (int)$user['id']);
                if (!$team) jsonErr('Usuário sem time', 400);
                $content = trim((string)($data['content'] ?? ''));
                if ($content === '') jsonErr('Conteúdo obrigatório');
                if (mb_strlen($content) > 500) jsonErr('Máximo 500 caracteres');

                $stmt = $pdo->prepare('INSERT INTO rumors (user_id, team_id, league, content) VALUES (?, ?, ?, ?)');
                $stmt->execute([(int)$user['id'], (int)$team['id'], $team['league'], $content]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                break;
            }
            case 'delete_rumor': {
                $rumorId = (int)($data['rumor_id'] ?? 0);
                if (!$rumorId) jsonErr('rumor_id obrigatório');

                $stmt = $pdo->prepare('SELECT * FROM rumors WHERE id = ?');
                $stmt->execute([$rumorId]);
                $rumor = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$rumor) jsonErr('Rumor inexistente', 404);

                $canDelete = $isAdmin || ($user && isset($user['id']) && (int)$rumor['user_id'] === (int)$user['id']);
                if (!$canDelete) jsonErr('Não autorizado', 403);

                $pdo->prepare('DELETE FROM rumors WHERE id = ?')->execute([$rumorId]);
                echo json_encode(['success' => true]);
                break;
            }
            case 'add_admin_comment': {
                if (!$isAdmin) jsonErr('Apenas administradores', 403);
                $league = (string)($data['league'] ?? '');
                $content = trim((string)($data['content'] ?? ''));
                if ($league === '') jsonErr('league obrigatório');
                if ($content === '') jsonErr('Conteúdo obrigatório');
                if (mb_strlen($content) > 500) jsonErr('Máximo 500 caracteres');

                // Inserir comentário
                $pdo->prepare('INSERT INTO rumor_admin_comments (user_id, league, content) VALUES (?, ?, ?)')
                    ->execute([(int)$user['id'], $league, $content]);

                // Enforce rolling limit: manter somente os 3 mais recentes por liga
                $stmtCnt = $pdo->prepare('SELECT COUNT(*) FROM rumor_admin_comments WHERE league = ?');
                $stmtCnt->execute([$league]);
                $count = (int)$stmtCnt->fetchColumn();
                if ($count > 3) {
                    // apagar os mais antigos além do top 3
                    $stmtOld = $pdo->prepare('SELECT id FROM rumor_admin_comments WHERE league = ? ORDER BY created_at ASC');
                    $stmtOld->execute([$league]);
                    $rows = $stmtOld->fetchAll(PDO::FETCH_COLUMN);
                    $toDelete = $count - 3;
                    for ($i = 0; $i < $toDelete; $i++) {
                        $pdo->prepare('DELETE FROM rumor_admin_comments WHERE id = ?')->execute([(int)$rows[$i]]);
                    }
                }

                echo json_encode(['success' => true]);
                break;
            }
            case 'delete_admin_comment': {
                if (!$isAdmin) jsonErr('Apenas administradores', 403);
                $commentId = (int)($data['comment_id'] ?? 0);
                if (!$commentId) jsonErr('comment_id obrigatório');
                $pdo->prepare('DELETE FROM rumor_admin_comments WHERE id = ?')->execute([$commentId]);
                echo json_encode(['success' => true]);
                break;
            }
            default:
                jsonErr('Ação inválida');
        }
    } catch (Exception $e) {
        jsonErr($e->getMessage());
    }
    exit;
}

jsonErr('Método não suportado', 405);

?>
