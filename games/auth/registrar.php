<?php
// registrar.php - TELA DE REGISTRO (DARK MODE 🌑)
session_start();
require '../core/conexao.php';

$erro = "";
$sucesso = "";

// Se já estiver logado, redireciona
if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nome = trim($_POST['nome']);
    $email = trim($_POST['email']);
    $senha = trim($_POST['senha']);

    if (empty($nome) || empty($email) || empty($senha)) {
        $erro = "Preencha todos os campos.";
    } else {
        // 1. Verifica se o e-mail já existe
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email");
        $stmt->execute([':email' => $email]);
        
        if ($stmt->rowCount() > 0) {
            $erro = "Este e-mail já está cadastrado.";
        } else {
            // 2. Cria o hash da senha (Segurança)
            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

            // 3. Insere no banco com 50 pontos iniciais
            try {
                $sql = "INSERT INTO usuarios (nome, email, senha, pontos, is_admin) VALUES (:nome, :email, :senha, 50.00, 0)";
                $stmtInsert = $pdo->prepare($sql);
                $stmtInsert->execute([
                    ':nome' => $nome,
                    ':email' => $email,
                    ':senha' => $senhaHash // Salvando seguro
                ]);

                $sucesso = "Conta criada com sucesso! Redirecionando...";
                
                // Login automático ou redirecionar para login? Vamos redirecionar para login.
                header("refresh:2;url=index.php"); // Espera 2 seg e vai pro login
                
            } catch (PDOException $e) {
                $erro = "Erro ao cadastrar: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Conta - FBA games</title>
    
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🚀</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        /* PADRÃO DARK MODE (Igual ao Login) */
        body, html { height: 100%; margin: 0; background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        .row-full { height: 100vh; width: 100%; margin: 0; }
        
        /* Lado Esquerdo (Banner) */
        .left-side {
            background: linear-gradient(135deg, #000000 0%, #1e1e1e 100%);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 50px;
            border-right: 1px solid #333;
        }
        
        /* Lado Direito (Formulário) */
        .right-side {
            background-color: #121212;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-card {
            width: 100%;
            max-width: 400px;
            padding: 40px;
            background: #1e1e1e;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            border: 1px solid #333;
        }

    .brand-text { font-size: 2.5rem; font-weight: 800; margin-bottom: 20px; color: #FC082B; text-shadow: 0 0 10px rgba(252, 8, 43, 0.3); }
        .hero-text { font-size: 1.2rem; line-height: 1.6; opacity: 0.8; color: #aaa; }

        /* Inputs Dark */
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
        <!-- LADO ESQUERDO -->
        <div class="col-md-6 left-side">
            <div>
                <h1 class="brand-text">Criar conta 🚀</h1>
                <p class="hero-text">
                    Crie sua conta no <strong>FBA games</strong> agora.<br><br>
                    <span class="text-white fw-bold">🎁 Você já começa com <span class="text-warning">50 pontos</span> grátis para fazer sua primeira aposta.</span><br><br>
                    Participe dos jogos, suba no ranking e divirta-se!
                </p>
            </div>
        </div>

        <!-- LADO DIREITO -->
        <div class="col-md-6 right-side">
            <div class="login-card">
                <h3 class="text-center mb-2 fw-bold text-white"><i class="bi bi-person-plus-fill me-2"></i>Nova Conta</h3>
                <p class="text-center text-secondary small mb-4">Preencha seus dados abaixo</p>
                
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
                    <div class="mb-3">
                        <label class="form-label">Nome Completo</label>
                        <input type="text" name="nome" class="form-control form-control-lg" placeholder="Seu nome" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <input type="email" name="email" class="form-control form-control-lg" placeholder="seu@email.com" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Senha</label>
                        <input type="password" name="senha" class="form-control form-control-lg" placeholder="Crie uma senha forte" required>
                    </div>

                    <button type="submit" class="btn btn-success-custom btn-lg w-100 mb-3">Cadastrar-se</button>
                    
                    <div class="text-center border-top border-secondary pt-3">
                        <p class="small text-muted mb-1">Já tem uma conta?</p>
                        <a href="login.php" class="btn btn-outline-light btn-sm fw-bold w-50">Fazer Login</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>
</html>
