<?php
// resetar.php - REDEFINIÇÃO DE SENHA (FBA games)
session_start();
require '../core/conexao.php';

$erro = "";
$sucesso = "";

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha = trim($_POST['senha'] ?? '');
    $confirmar = trim($_POST['confirmar'] ?? '');

    if ($token === '') {
        $erro = "Token inválido. Solicite um novo link.";
    } elseif ($senha === '' || $confirmar === '') {
        $erro = "Preencha todos os campos.";
    } elseif ($senha !== $confirmar) {
        $erro = "As senhas não coincidem.";
    } elseif (strlen($senha) < 6) {
        $erro = "A senha deve ter pelo menos 6 caracteres.";
    } else {
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) NULL");
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_token_expiry DATETIME NULL");
        } catch (Exception $e) {
            // ignora se já existir
        }

        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE reset_token = :token AND reset_token_expiry > NOW() LIMIT 1");
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $erro = "Token inválido ou expirado. Solicite um novo link.";
        } else {
            $hash = password_hash($senha, PASSWORD_BCRYPT);
            $update = $pdo->prepare("UPDATE usuarios SET senha = :senha, reset_token = NULL, reset_token_expiry = NULL WHERE id = :id");
            $update->execute([':senha' => $hash, ':id' => $user['id']]);

            $sucesso = "Senha redefinida com sucesso! Redirecionando para o login...";
            header("refresh:2;url=login.php");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir Senha - FBA games</title>

    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🔑</text></svg>">

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
                <h1 class="brand-text">Nova senha 🔑</h1>
                <p class="hero-text">
                    Defina uma nova senha para acessar o <strong>FBA games</strong>.
                </p>
            </div>
        </div>

        <div class="col-md-6 right-side">
            <div class="login-card">
                <h3 class="text-center mb-2 fw-bold text-white"><i class="bi bi-shield-lock-fill me-2"></i>Redefinir senha</h3>
                <p class="text-center text-secondary small mb-4">Digite sua nova senha abaixo</p>

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
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="mb-3">
                        <label class="form-label">Nova Senha</label>
                        <input type="password" name="senha" class="form-control form-control-lg" placeholder="Mínimo 6 caracteres" required minlength="6">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Confirmar Senha</label>
                        <input type="password" name="confirmar" class="form-control form-control-lg" placeholder="Digite novamente" required minlength="6">
                    </div>

                    <button type="submit" class="btn btn-success-custom btn-lg w-100 mb-3">Atualizar senha</button>

                    <div class="text-center border-top border-secondary pt-3">
                        <a href="login.php" class="btn btn-outline-light btn-sm fw-bold w-50">Voltar ao login</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>
</html>
