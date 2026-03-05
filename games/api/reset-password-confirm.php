<?php
// API - Confirmar redefinição de senha (FBA games)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../../backend/helpers.php';

try {
    requireMethod('POST');
    $body = readJsonBody();

    $token = trim($body['token'] ?? '');
    $password = $body['password'] ?? '';

    if ($token === '' || $password === '') {
        jsonResponse(422, ['error' => 'Token e senha são obrigatórios.']);
    }

    if (strlen($password) < 6) {
        jsonResponse(422, ['error' => 'A senha deve ter pelo menos 6 caracteres.']);
    }

    ensureResetColumns($pdo);

    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE reset_token = ? AND reset_token_expiry > NOW() LIMIT 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        jsonResponse(400, ['error' => 'Token inválido ou expirado. Solicite um novo link.']);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $update = $pdo->prepare('UPDATE usuarios SET senha = :senha, reset_token = NULL, reset_token_expiry = NULL WHERE id = :id');
    $update->execute([':senha' => $hash, ':id' => $user['id']]);

    jsonResponse(200, ['message' => 'Senha redefinida com sucesso!']);
} catch (PDOException $e) {
    error_log('Erro SQL no games/api/reset-password-confirm.php: ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Erro ao redefinir senha.', 'details' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('Erro no games/api/reset-password-confirm.php: ' . $e->getMessage());
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
?>
