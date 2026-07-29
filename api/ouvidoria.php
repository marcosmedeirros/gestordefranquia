<?php
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/helpers.php';

header('Content-Type: application/json');

$pdo = db();
$user = getUserSession();
$method = $_SERVER['REQUEST_METHOD'];

$allowedSubjects = ['Reclamação', 'Sugestão', 'Erro de Gameplay'];

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

    // Roteamento explicito: o front (ouvidoria.php) nao envia "action" ao criar
    // mensagem, entao '' e 'create_message' sao equivalentes para compatibilidade.
    // Qualquer outro valor de action e rejeitado.
    $allowedActions = ['', 'create_message', 'delete_message'];
    if (!in_array($action, $allowedActions, true)) {
        jsonErr('Ação inválida', 400);
    }

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
            jsonErr('Erro ao apagar mensagem.', 500);
        }
        exit;
    }

    // action === '' ou 'create_message': fluxo de criacao de mensagem.
    // Rate limit leve baseado em sessao: no maximo 1 envio a cada 30s.
    $now = time();
    $lastSubmit = (int)($_SESSION['ouvidoria_last_submit'] ?? 0);
    if ($now - $lastSubmit < 30) {
        jsonErr('Aguarde um momento antes de enviar outra mensagem.', 429);
    }

    $subject = trim((string)($data['subject'] ?? ''));
    $message = trim((string)($data['message'] ?? ''));

    if ($subject === '') {
        $subject = 'Reclamação';
    }
    if (!in_array($subject, $allowedSubjects, true)) {
        jsonErr('Assunto invalido');
    }

    if ($message === '') {
        jsonErr('Mensagem obrigatoria');
    }
    if (mb_strlen($message) > 1000) {
        jsonErr('Maximo 1000 caracteres');
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO ouvidoria_messages (subject, message) VALUES (?, ?)');
        $stmt->execute([$subject, $message]);
        $_SESSION['ouvidoria_last_submit'] = $now;
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        jsonErr('Erro ao salvar mensagem.', 500);
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

    $subjectFilter = trim((string)($_GET['subject'] ?? ''));
    if ($subjectFilter !== '' && !in_array($subjectFilter, $allowedSubjects, true)) {
        jsonErr('Assunto invalido');
    }

    try {
        $sql = 'SELECT id, subject, message, created_at FROM ouvidoria_messages';
        $params = [];
        if ($subjectFilter !== '') {
            $sql .= ' WHERE subject = ?';
            $params[] = $subjectFilter;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $idx => $value) {
            $stmt->bindValue($idx + 1, $value);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($subjectFilter !== '') {
            $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM ouvidoria_messages WHERE subject = ?');
            $stmtCount->execute([$subjectFilter]);
            $total = (int)$stmtCount->fetchColumn();
        } else {
            $stmtCount = $pdo->query('SELECT COUNT(*) FROM ouvidoria_messages');
            $total = (int)$stmtCount->fetchColumn();
        }

        echo json_encode(['success' => true, 'messages' => $rows, 'total' => $total]);
    } catch (Exception $e) {
        jsonErr('Erro ao carregar mensagens.', 500);
    }
    exit;
}

jsonErr('Metodo nao suportado', 405);
