<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require_once __DIR__ . '/../backend/db.php';
    require_once __DIR__ . '/../backend/helpers.php';

    requireMethod('POST');
    $pdo = db();
    $body = readJsonBody();

    $token = trim($body['token'] ?? '');
    $password = $body['password'] ?? '';

    if ($token === '' || $password === '') {
        jsonResponse(422, ['error' => 'Token e senha são obrigatórios.']);
    }

    if (strlen($password) < 6) {
        jsonResponse(422, ['error' => 'A senha deve ter pelo menos 6 caracteres.']);
    }

    // Busca usuário com token válido
    $stmt = $pdo->prepare('
        SELECT id, email, name 
        FROM users 
        WHERE reset_token = ? 
        AND reset_token_expiry > NOW()
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(400, ['error' => 'Token inválido ou expirado. Solicite um novo link de recuperação.']);
    }

    // Atualiza a senha
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $updateStmt = $pdo->prepare('
        UPDATE users 
        SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL 
        WHERE id = ?
    ');
    $updateStmt->execute([$hash, $user['id']]);

    jsonResponse(200, ['message' => 'Senha redefinida com sucesso!']);

} catch (PDOException $e) {
    error_log('Erro SQL no reset-password-confirm.php: ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Erro ao redefinir senha.', 'details' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('Erro no reset-password-confirm.php: ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Erro interno do servidor.', 'details' => $e->getMessage()]);
}
