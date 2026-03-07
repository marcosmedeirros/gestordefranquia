<?php
// API - Alterar senha (usuário logado) - FBA games
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$current = trim($payload['current_password'] ?? '');
$new = trim($payload['new_password'] ?? '');
$confirm = trim($payload['confirm_password'] ?? '');

if ($current === '' || $new === '' || $confirm === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Preencha todos os campos.']);
    exit;
}

if (mb_strlen($new) < 6) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'A nova senha deve ter pelo menos 6 caracteres.']);
    exit;
}

if ($new !== $confirm) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'A confirmação não confere com a nova senha.']);
    exit;
}

try {
    $userId = (int)$_SESSION['user_id'];
    $stmt = $pdo->prepare('SELECT senha FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado.']);
        exit;
    }

    $stored = $user['senha'] ?? '';
    $isValid = password_verify($current, $stored) || $stored === $current || trim($stored) === $current;
    if (!$isValid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Senha atual incorreta.']);
        exit;
    }

    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $stmtUpd = $pdo->prepare('UPDATE usuarios SET senha = :senha WHERE id = :id');
    $stmtUpd->execute([':senha' => $newHash, ':id' => $userId]);

    echo json_encode(['success' => true, 'message' => 'Senha atualizada com sucesso.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao atualizar senha.', 'details' => $e->getMessage()]);
}
