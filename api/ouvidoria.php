<?php
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/helpers.php';

header('Content-Type: application/json');

$pdo = db();
$user = getUserSession();
$method = $_SERVER['REQUEST_METHOD'];

function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if ($method === 'POST') {
    if (!$user || !isset($user['id'])) {
        jsonErr('Nao autenticado', 401);
    }

    $data = readJsonBody();
    $action = (string)($data['action'] ?? '');

    if ($action === 'delete_message') {
        if (($user['user_type'] ?? 'jogador') !== 'admin') {
            jsonErr('Acesso negado', 403);
        }

        $messageId = (int)($data['message_id'] ?? 0);
        if (!$messageId) {
            jsonErr('message_id obrigatorio');
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM ouvidoria_messages WHERE id = ?');
            $stmt->execute([$messageId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            jsonErr('Erro ao apagar mensagem: ' . $e->getMessage(), 500);
        }
        exit;
    }

    $message = trim((string)($data['message'] ?? ''));

    if ($message === '') {
        jsonErr('Mensagem obrigatoria');
    }
    if (mb_strlen($message) > 1000) {
        jsonErr('Maximo 1000 caracteres');
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO ouvidoria_messages (message) VALUES (?)');
        $stmt->execute([$message]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        jsonErr('Erro ao salvar mensagem: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($method === 'GET') {
    if (!$user || ($user['user_type'] ?? 'jogador') !== 'admin') {
        jsonErr('Acesso negado', 403);
    }

    $limit = (int)($_GET['limit'] ?? 10);
    if ($limit <= 0) {
        $limit = 10;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    try {
        $stmt = $pdo->prepare('SELECT id, message, created_at FROM ouvidoria_messages ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtCount = $pdo->query('SELECT COUNT(*) FROM ouvidoria_messages');
        $total = (int)$stmtCount->fetchColumn();

        echo json_encode(['success' => true, 'messages' => $rows, 'total' => $total]);
    } catch (Exception $e) {
        jsonErr('Erro ao carregar mensagens: ' . $e->getMessage(), 500);
    }
    exit;
}

jsonErr('Metodo nao suportado', 405);
