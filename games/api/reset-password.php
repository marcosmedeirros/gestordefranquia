<?php
// API - Solicitar redefinição de senha (FBA games)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../../backend/helpers.php';

try {
    requireMethod('POST');
    $body = readJsonBody();

    $email = strtolower(trim($body['email'] ?? ''));
    if ($email === '') {
        jsonResponse(422, ['error' => 'E-mail é obrigatório.']);
    }

    ensureResetColumns($pdo);

    $stmt = $pdo->prepare('SELECT id, nome FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Não revelamos se o e-mail existe ou não
        jsonResponse(200, ['message' => 'Se o e-mail existir, você receberá um link de recuperação.']);
    }

    $token = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $update = $pdo->prepare('UPDATE usuarios SET reset_token = ?, reset_token_expiry = ? WHERE id = ?');
    $update->execute([$token, $expiry, $user['id']]);

    $resetUrl = buildGamesResetUrl($token);

    $sent = sendGamesPasswordResetEmail($email, $user['nome'] ?? 'jogador', $resetUrl);
    if (!$sent) {
        error_log('Falha ao enviar e-mail de recuperação (games) para: ' . $email);
        jsonResponse(500, ['error' => 'Falha ao enviar o e-mail de recuperação. Tente novamente.']);
    }

    $config = loadConfig();
    if (!empty($config['app']['debug_reset_link'])) {
        error_log('DEBUG games reset link para ' . $email . ': ' . $resetUrl);
        jsonResponse(200, [
            'message' => 'Link de recuperação enviado! Verifique seu e-mail.',
            'debug_reset_link' => $resetUrl
        ]);
    }

    jsonResponse(200, ['message' => 'Link de recuperação enviado! Verifique seu e-mail.']);
} catch (PDOException $e) {
    error_log('Erro SQL no games/api/reset-password.php: ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Erro ao processar solicitação.', 'details' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('Erro no games/api/reset-password.php: ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Erro interno do servidor.', 'details' => $e->getMessage()]);
}

function ensureResetColumns(PDO $pdo): void
{
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) NULL");
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_token_expiry DATETIME NULL");
    } catch (Exception $e) {
        error_log('Erro ao garantir colunas de reset (games): ' . $e->getMessage());
    }
}

function buildGamesResetUrl(string $token): string
{
    $config = loadConfig();
    $base = $config['mail']['reset_games_base_url'] ?? '';

    if (!$base) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'fbabrasil.com.br';
        $base = $scheme . '://' . $host . '/games/auth/resetar.php';
    }

    if (str_contains($base, '{token}')) {
        return str_replace('{token}', urlencode($token), $base);
    }

    if (str_contains($base, '?')) {
        $sep = str_ends_with($base, '=') || str_contains($base, 'token=') ? '' : '&token=';
        return $base . $sep . urlencode($token);
    }

    return rtrim($base, '/') . '?token=' . urlencode($token);
}

function sendGamesPasswordResetEmail(string $email, string $name, string $resetUrl): bool
{
    $config = loadConfig();
    $subject = 'Recuperação de Senha - FBA games';
    $message = "Olá {$name},\n\n" .
        "Recebemos uma solicitação para redefinir sua senha no FBA games.\n\n" .
        "Clique no link abaixo para criar uma nova senha:\n" .
        "{$resetUrl}\n\n" .
        "Este link expira em 1 hora.\n\n" .
        "Se você não solicitou esta alteração, ignore este e-mail.\n\n" .
        "Atenciosamente,\nEquipe FBA games";

    if (!empty($config['mail']['smtp']['host'])) {
        return sendViaSmtp($email, $subject, $message, $config);
    }

    $headers = implode("\r\n", buildMailHeaders($config));
    $params = buildMailParams($config);
    return $params ? mail($email, $subject, $message, $headers, $params) : mail($email, $subject, $message, $headers);
}
?>
