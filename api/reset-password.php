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

    $email = strtolower(trim($body['email'] ?? ''));

    if ($email === '') {
        jsonResponse(422, ['error' => 'E-mail é obrigatório.']);
    }

    // Verifica se o e-mail existe
    $stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Por segurança, não revela se o e-mail existe ou não
        jsonResponse(200, ['message' => 'Se o e-mail existir, você receberá um link de recuperação.']);
    }

    // Gera token de recuperação
    $token = bin2hex(random_bytes(32));
    $tokenExpiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Atualiza o token no banco
    $updateStmt = $pdo->prepare('UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?');
    $updateStmt->execute([$token, $tokenExpiry, $user['id']]);

    // Envia e-mail
    $sent = sendPasswordResetEmail($email, $token, $user['name']);

    if (!$sent) {
        error_log('Falha ao enviar e-mail de recuperação para: ' . $email);
        jsonResponse(500, ['error' => 'Falha ao enviar e-mail de recuperação. Tente novamente mais tarde.']);
    }

    jsonResponse(200, ['message' => 'Link de recuperação enviado! Verifique seu e-mail.']);

} catch (PDOException $e) {
    error_log('Erro SQL no reset-password.php: ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Erro ao processar solicitação.', 'details' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('Erro no reset-password.php: ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Erro interno do servidor.', 'details' => $e->getMessage()]);
}
