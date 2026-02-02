<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json');

try {
    session_start();
    require_once __DIR__ . '/../backend/db.php';
    require_once __DIR__ . '/../backend/helpers.php';
    require_once __DIR__ . '/../backend/auth.php';

    requireMethod('POST');
    $pdo = db();
    $body = readJsonBody();
    $email = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if ($email === '' || $password === '') {
        jsonResponse(422, ['error' => 'E-mail e senha são obrigatórios.']);
    }

    $stmt = $pdo->prepare('SELECT id, name, password_hash, user_type, league, email_verified, photo_url, phone, approved FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(401, ['error' => 'Credenciais inválidas.']);
    }

    // Criar sessão
    setUserSession([
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $email,
        'user_type' => $user['user_type'],
        'league' => $user['league'],
        'photo_url' => $user['photo_url'] ?? null,
        'phone' => $user['phone'] ?? null,
        'approved' => $user['approved'] ?? 1,
    ]);

    jsonResponse(200, [
        'message' => 'Login realizado com sucesso',
        'user' => [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $email,
            'user_type' => $user['user_type'],
            'league' => $user['league'],
            'email_verified' => (bool) $user['email_verified'],
            'photo_url' => $user['photo_url'] ?? null,
            'phone' => $user['phone'] ?? null,
            'approved' => (bool) ($user['approved'] ?? 1),
        ],
    ]);
} catch (PDOException $e) {
    error_log('Erro SQL no login.php: ' . $e->getMessage());
    
    // Se o erro é sobre coluna 'league' não existir, retorna mensagem específica
    if (strpos($e->getMessage(), "Unknown column 'league'") !== false) {
        jsonResponse(500, [
            'error' => 'Schema do banco desatualizado. Execute a migração: https://fbabrasil.com.br/backend/migrate.php',
            'technical' => $e->getMessage()
        ]);
    }
    // Tabela users não existe
    if (strpos($e->getMessage(), 'Base table or view not found') !== false && strpos($e->getMessage(), 'users') !== false) {
        jsonResponse(500, [
            'error' => 'Tabela users ausente. Execute a migração: https://fbabrasil.com.br/backend/migrate.php',
            'technical' => $e->getMessage()
        ]);
    }
    // Coluna password_hash/email_verified ausente
    if (strpos($e->getMessage(), "Unknown column 'password_hash'") !== false || strpos($e->getMessage(), "Unknown column 'email_verified'") !== false) {
        jsonResponse(500, [
            'error' => 'Schema do banco desatualizado. Execute a migração: https://fbabrasil.com.br/backend/migrate.php',
            'technical' => $e->getMessage()
        ]);
    }
    // Erros de conexão com banco
    if (strpos($e->getMessage(), 'SQLSTATE[HY000] [1045]') !== false) {
        jsonResponse(500, [
            'error' => 'Falha na conexão com o banco (credenciais inválidas). Verifique backend/config.php.',
            'technical' => $e->getMessage()
        ]);
    }
    if (strpos($e->getMessage(), 'SQLSTATE[HY000] [2002]') !== false) {
        jsonResponse(500, [
            'error' => 'Banco de dados indisponível/host inválido. Verifique backend/config.php e disponibilidade do MySQL.',
            'technical' => $e->getMessage()
        ]);
    }
    
    jsonResponse(500, ['error' => 'Erro ao fazer login.', 'details' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('Erro no login.php: ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Erro interno do servidor.', 'details' => $e->getMessage()]);
}
