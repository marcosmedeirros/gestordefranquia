<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require_once __DIR__ . '/../backend/db.php';
    require_once __DIR__ . '/../backend/helpers.php';
    require_once __DIR__ . '/../backend/nba_teams.php';

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

    // Cadastro só acontece por link do admin: ou o individual da lista de
    // espera (api/waitlist.php), ou o convite reutilizável da liga. Cadastro
    // aberto, sem link nenhum, continua não existindo.
    $inviteToken = trim($body['invite_token'] ?? '');
    $waitlistRow = null;
    $ligaConvite = $inviteToken !== '' ? ligaDoConviteReutilizavel($pdo, $inviteToken) : null;

    if ($ligaConvite === null) {
        if ($waitlistToken === '') {
            jsonResponse(403, ['error' => 'Cadastro disponível apenas através do link enviado pelo administrador.']);
        }
        $stmtWl = $pdo->prepare("SELECT * FROM waitlist_requests WHERE token = ? AND status != 'registered' LIMIT 1");
        $stmtWl->execute([$waitlistToken]);
        $waitlistRow = $stmtWl->fetch(PDO::FETCH_ASSOC);
        if (!$waitlistRow) {
            jsonResponse(404, ['error' => 'Link de cadastro inválido ou já utilizado.']);
        }
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

    // Foto do GM: se vier como data URL, decodifica e salva em img/users
    // (mesmo padrão de api/user.php).
    if ($photoUrl && str_starts_with($photoUrl, 'data:image/')) {
        try {
            $commaPos = strpos($photoUrl, ',');
            $meta = substr($photoUrl, 0, $commaPos);
            $base64 = substr($photoUrl, $commaPos + 1);
            $mime = null;
            if (preg_match('/data:(image\/(png|jpeg|jpg|webp));base64/i', $meta, $m)) {
                $mime = strtolower($m[1]);
            }
            $ext = 'png';
            if ($mime === 'image/jpeg' || $mime === 'image/jpg') { $ext = 'jpg'; }
            if ($mime === 'image/webp') { $ext = 'webp'; }
            $binary = base64_decode($base64);
            if ($binary === false) { throw new Exception('Falha ao decodificar imagem.'); }

            $dirFs = __DIR__ . '/../img/users';
            if (!is_dir($dirFs)) { @mkdir($dirFs, 0775, true); }
            $filename = 'user-' . $token . '-' . time() . '.' . $ext;
            if (file_put_contents($dirFs . '/' . $filename, $binary) === false) {
                throw new Exception('Falha ao salvar imagem.');
            }
            $photoUrl = '/img/users/' . $filename;
        } catch (Exception $e) {
            $photoUrl = '';
        }
    }

    $pdo->beginTransaction();
    try {
        // Quem chega até aqui já foi aprovado pelo admin na lista de espera
        // (ele que mandou o link), então o cadastro já entra liberado.
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, user_type, league, verification_token, photo_url, phone, approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
        $stmt->execute([$name, $email, $hash, $userType, $league, $token, $photoUrl ?: null, $phone]);
        $newUserId = (int)$pdo->lastInsertId();

        // Sem time ainda de propósito: o time da NBA é escolhido depois, no
        // sorteio de ordem + draft de marca da liga (rookie-sorteio.php) — não
        // mais no próprio cadastro. requireAuth() manda quem não tem time pra
        // lá em vez de liberar o app.

        // Só o link individual "queima" ao ser usado; o convite reutilizável
        // continua valendo pra próxima pessoa.
        if ($waitlistRow) {
            $stmtDone = $pdo->prepare("UPDATE waitlist_requests SET status = 'registered', registered_user_id = ? WHERE id = ?");
            $stmtDone->execute([$newUserId, $waitlistRow['id']]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }

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
