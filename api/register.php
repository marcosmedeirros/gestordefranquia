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

    $name = trim($body['name'] ?? '');
    $email = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';
    $phoneRaw = trim($body['phone'] ?? '');
    $phone = normalizeBrazilianPhone($phoneRaw);
    $userType = 'jogador'; // Sempre jogador por padrão
    $photoUrl = trim($body['photo_url'] ?? '');
    $waitlistToken = trim($body['waitlist_token'] ?? '');

    // Cadastro só é permitido através do link enviado pelo admin a partir
    // da lista de espera (ver api/waitlist.php). Não existe mais cadastro aberto.
    if ($waitlistToken === '') {
        jsonResponse(403, ['error' => 'Cadastro disponível apenas através do link enviado pelo administrador.']);
    }

    $stmtWl = $pdo->prepare("SELECT * FROM waitlist_requests WHERE token = ? AND status != 'registered' LIMIT 1");
    $stmtWl->execute([$waitlistToken]);
    $waitlistRow = $stmtWl->fetch(PDO::FETCH_ASSOC);
    if (!$waitlistRow) {
        jsonResponse(404, ['error' => 'Link de cadastro inválido ou já utilizado.']);
    }

    // Novos cadastros sempre entram na liga ROOKIE — não há mais escolha de liga.
    $league = 'ROOKIE';

    if ($name === '' || $email === '' || $password === '' || $phoneRaw === '') {
        jsonResponse(422, ['error' => 'Nome, e-mail, telefone e senha são obrigatórios.']);
    }

    if (!$phone) {
        jsonResponse(422, ['error' => 'Informe um telefone válido (DDD brasileiro ou código do país).']);
    }

    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        jsonResponse(409, ['error' => 'E-mail já cadastrado.']);
    }

    $token = bin2hex(random_bytes(16));
    $hash = password_hash($password, PASSWORD_BCRYPT);

    // Quem chega até aqui já foi aprovado pelo admin na lista de espera
    // (ele que mandou o link), então o cadastro já entra liberado.
    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, user_type, league, verification_token, photo_url, phone, approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
    $stmt->execute([$name, $email, $hash, $userType, $league, $token, $photoUrl ?: null, $phone]);
    $newUserId = (int)$pdo->lastInsertId();

    $stmtDone = $pdo->prepare("UPDATE waitlist_requests SET status = 'registered', registered_user_id = ? WHERE id = ?");
    $stmtDone->execute([$newUserId, $waitlistRow['id']]);

    sendVerificationEmail($email, $token);

    jsonResponse(201, ['message' => 'Cadastro concluído!', 'user_id' => $newUserId]);
} catch (PDOException $e) {
    error_log('Erro SQL no register.php: ' . $e->getMessage());

    // Se o erro é sobre coluna 'league' não existir, retorna mensagem específica
    if (strpos($e->getMessage(), "Unknown column 'league'") !== false) {
        jsonResponse(500, [
            'error' => 'Schema do banco desatualizado. Execute a migração: https://fbabrasil.com.br/backend/migrate.php',
        ]);
    }

    jsonResponse(500, ['error' => 'Erro ao registrar usuário.']);
} catch (Exception $e) {
    error_log('Erro no register.php: ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Erro interno do servidor.']);
}
