<?php
// recuperar.php - RECUPERAÇÃO DE SENHA (FBA games)
session_start();
require '../core/conexao.php';

$erro = "";
$sucesso = "";

if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

function sendResetEmail(string $email, string $nome, string $resetUrl): bool
{
    $subject = 'Recuperação de Senha - FBA games';
    $message = "Olá {$nome},\n\n" .
        "Recebemos uma solicitação para redefinir sua senha do FBA games.\n\n" .
        "Clique no link abaixo para criar uma nova senha:\n" .
        "{$resetUrl}\n\n" .
        "Este link expira em 1 hora.\n\n" .
        "Se você não solicitou esta alteração, ignore este e-mail.\n\n" .
        "Atenciosamente,\nEquipe FBA games";

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-type: text/plain; charset=UTF-8',
        'From: FBA games <no-reply@fbabrasil.com.br>'
    ]);

    return mail($email, $subject, $message, $headers);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if ($email === '') {
        $erro = "Informe seu e-mail.";
    } else {
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) NULL");
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_token_expiry DATETIME NULL");
        } catch (Exception $e) {
            // ignora se já existir
        }

        $stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $update = $pdo->prepare("UPDATE usuarios SET reset_token = :token, reset_token_expiry = :expiry WHERE id = :id");
            $update->execute([':token' => $token, ':expiry' => $expiry, ':id' => $user['id']]);

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'jogos.fbabrasil.com.br';
            $resetUrl = $scheme . '://' . $host . '/games/auth/resetar.php?token=' . urlencode($token);

            $sent = sendResetEmail($email, $user['nome'], $resetUrl);
            if (!$sent) {
                $erro = "Falha ao enviar o e-mail. Tente novamente mais tarde.";
            }
        }

        if ($erro === "") {
            $sucesso = "Se o e-mail existir, você receberá um link de recuperação.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Senha - FBA games</title>

    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🔒</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        body, html { height: 100%; margin: 0; background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        .row-full { height: 100vh; width: 100%; margin: 0; }

        .left-side {
            background: linear-gradient(135deg, #000000 0%, #1e1e1e 100%);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 50px;
            border-right: 1px solid #333;
        }

        .right-side {
            background-color: #121212;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 40px;
            background: #1e1e1e;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            border: 1px solid #333;
        }

        .brand-text { font-size: 2.4rem; font-weight: 800; margin-bottom: 20px; color: #FC082B; }
        .hero-text { font-size: 1.1rem; line-height: 1.6; opacity: 0.8; color: #aaa; }

        .form-control {
            background-color: #2b2b2b; border: 1px solid #444; color: #fff;
        }
        .form-control:focus {
            background-color: #2b2b2b; border-color: #FC082B; color: #fff; box-shadow: 0 0 0 0.25rem rgba(252, 8, 43, 0.25);
        }
        .form-label { color: #ccc; }

        .btn-success-custom {
            background-color: #FC082B; color: #000; font-weight: 800; border: none;
            transition: 0.3s;
        }
        .btn-success-custom:hover { background-color: #e00627; box-shadow: 0 0 15px rgba(252, 8, 43, 0.4); }

        @media (max-width: 768px) {
            .row-full { height: auto; }
            .left-side { padding: 40px 20px; text-align: center; border-right: none; border-bottom: 1px solid #333; }
            .right-side { padding: 40px 20px; height: auto; }
        }
    </style>
</head>
<body>

    <div class="row row-full">
        <div class="col-md-6 left-side">
            <div>
                <h1 class="brand-text">Recuperar senha 🔒</h1>
                <p class="hero-text">
                    Informe seu e-mail para receber o link de redefinição de senha do <strong>FBA games</strong>.
                </p>
            </div>
        </div>

        <div class="col-md-6 right-side">
            <div class="login-card">
                <h3 class="text-center mb-2 fw-bold text-white"><i class="bi bi-envelope-fill me-2"></i>Recuperar acesso</h3>
                <p class="text-center text-secondary small mb-4">Enviaremos um link para seu e-mail</p>

                <?php if($erro): ?>
                    <div class="alert alert-danger text-center p-2 small border-0 bg-danger bg-opacity-25 text-white">
                        <i class="bi bi-exclamation-circle me-1"></i><?= $erro ?>
                    </div>
                <?php endif; ?>

                <?php if($sucesso): ?>
                    <div class="alert alert-success text-center p-2 small border-0 bg-success bg-opacity-25 text-white">
                        <i class="bi bi-check-circle me-1"></i><?= $sucesso ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-4">
                        <label class="form-label">E-mail</label>
                        <input type="email" name="email" class="form-control form-control-lg" placeholder="seu@email.com" required>
                    </div>

                    <button type="submit" class="btn btn-success-custom btn-lg w-100 mb-3">Enviar link</button>

                    <div class="text-center border-top border-secondary pt-3">
                        <a href="login.php" class="btn btn-outline-light btn-sm fw-bold w-50">Voltar ao login</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>
</html>
