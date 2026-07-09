<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'];

    // POST - Publico: solicitar participação (nome + telefone)
    if ($method === 'POST') {
        $body = readJsonBody();
        $name = trim($body['name'] ?? '');
        $phoneRaw = trim($body['phone'] ?? '');
        $phone = normalizeBrazilianPhone($phoneRaw);

        if ($name === '' || $phoneRaw === '') {
            jsonResponse(422, ['error' => 'Nome e telefone são obrigatórios.']);
        }
        if (!$phone) {
            jsonResponse(422, ['error' => 'Informe um telefone válido (DDD brasileiro ou código do país).']);
        }

        $token = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare('INSERT INTO waitlist_requests (name, phone, token) VALUES (?, ?, ?)');
        $stmt->execute([$name, $phone, $token]);

        jsonResponse(201, ['message' => 'Pedido enviado! Em breve entraremos em contato pelo WhatsApp.']);
    }

    // A partir daqui, apenas admin
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(401, ['error' => 'Não autenticado']);
    }
    if (($_SESSION['user_type'] ?? 'jogador') !== 'admin') {
        jsonResponse(403, ['error' => 'Acesso negado. Apenas administradores.']);
    }

    // GET - Listar interessados (admin)
    if ($method === 'GET') {
        $stmt = $pdo->query("
            SELECT id, name, phone, token, status, link_sent_at, registered_user_id, created_at
            FROM waitlist_requests
            WHERE status != 'dismissed'
            ORDER BY created_at DESC
        ");
        jsonResponse(200, ['requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // PUT - Marcar link enviado ou dispensar (admin)
    if ($method === 'PUT') {
        $body = readJsonBody();
        $id = (int)($body['id'] ?? 0);
        $action = $body['action'] ?? '';

        if (!$id || !in_array($action, ['mark_sent', 'dismiss'], true)) {
            jsonResponse(422, ['error' => 'Parâmetros inválidos']);
        }

        if ($action === 'mark_sent') {
            $stmt = $pdo->prepare("UPDATE waitlist_requests SET status = 'link_sent', link_sent_at = NOW() WHERE id = ? AND status != 'registered'");
            $stmt->execute([$id]);
            jsonResponse(200, ['message' => 'Marcado como enviado.']);
        } else {
            $stmt = $pdo->prepare("UPDATE waitlist_requests SET status = 'dismissed' WHERE id = ? AND status != 'registered'");
            $stmt->execute([$id]);
            jsonResponse(200, ['message' => 'Pedido dispensado.']);
        }
    }

    jsonResponse(405, ['error' => 'Método não permitido']);

} catch (PDOException $e) {
    error_log('Erro SQL no waitlist.php: ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Erro no banco de dados', 'details' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('Erro no waitlist.php: ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Erro interno do servidor', 'details' => $e->getMessage()]);
}
