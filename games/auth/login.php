<?php
// index.php - TELA DE LOGIN PRINCIPAL
session_start();
require '../core/conexao.php';

// 1. Se já estiver logado, joga pro painel direto
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
        header("Location: ../admin/dashboard.php");
    } else {
        header("Location: ../index.php");
    }
    exit;
}

$erro = "";

// 2. Processa o Login
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']); // Remove espaços extras
    $senha = trim($_POST['senha']);

    if (empty($email) || empty($senha)) {
        $erro = "Preencha todos os campos.";
    } else {
        // Prepara a busca
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // --- BLOCO DE DEBUG (SENIOR TIP) ---
        // Se der erro de senha, tire os '//' das linhas abaixo para ver a verdade nua e crua:
        /*
        echo "<pre>";
        echo "Usuário encontrado? " . ($user ? 'SIM' : 'NÃO') . "<br>";
        echo "Senha digitada (trim): [" . $senha . "]<br>";
        echo "Senha no banco (raw):  [" . $user['senha'] . "]<br>";
        echo "Senha verify hash: " . (password_verify($senha, $user['senha']) ? 'OK' : 'FAIL') . "<br>";
        echo "Senha texto puro: " . ($user['senha'] == $senha ? 'OK' : 'FAIL') . "<br>";
        echo "</pre>";
        exit;
        */
        // -----------------------------------

        // Verifica senha com ROBUSTEZ:
        // 1. password_verify: Caso você use hash no futuro (Recomendado)
        // 2. == $senha: Caso seja texto puro
        // 3. trim() == $senha: Caso tenha entrado um espaço acidental no banco de dados
        if ($user && (password_verify($senha, $user['senha']) || $user['senha'] == $senha || trim($user['senha']) == $senha)) {
            
            // Login Sucesso
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nome'] = $user['nome'];
            $_SESSION['is_admin'] = $user['is_admin']; // Salva se é admin na sessão
            
            // Redireciona
            if($user['is_admin'] == 1) {
                header("Location: ../admin/dashboard.php");
            } else {
                header("Location: ../index.php");
            }
            exit;
        } else {
            $erro = "E-mail ou senha incorretos.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - FBA games</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body, html { height: 100%; margin: 0; }
        .row-full { height: 100vh; width: 100%; margin: 0; }
        
        /* Lado Esquerdo (Banner) */
        .left-side {
            background: linear-gradient(135deg, #2c3e50 0%, #000000 100%);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 50px;
        }
        
        /* Lado Direito (Formulário) */
        .right-side {
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-card {
            width: 100%;
            max-width: 400px;
            padding: 30px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        }

        .brand-text { font-size: 2.5rem; font-weight: 800; margin-bottom: 20px; color: #e67e22; }
        .hero-text { font-size: 1.2rem; line-height: 1.6; opacity: 0.9; }

        @media (max-width: 768px) {
            .row-full { height: auto; }
            .left-side { padding: 40px 20px; text-align: center; }
            .right-side { padding: 40px 20px; height: auto; }
        }
    </style>
</head>
<body>

    <div class="row row-full">
        <!-- LADO ESQUERDO: Texto e Boas-vindas -->
        <div class="col-md-6 left-side">
            <div>
                <h1 class="brand-text">FBA games 🎮</h1>
                <p class="hero-text">
                    Bem vindo ao FBA games.<br><br>
                    Acerte mais que o seus companheiros, ganhe pontos e fique em primeiro do ranking.<br><br>
                
                </p>
            </div>
        </div>

        <!-- LADO DIREITO: Formulário de Login -->
        <div class="col-md-6 right-side">
            <div class="login-card">
                <h3 class="text-center mb-4 fw-bold text-secondary">Acessar Conta</h3>
                
                <?php if($erro): ?>
                    <div class="alert alert-danger text-center p-2 small border-0 bg-danger-subtle text-danger">
                        <i class="bi bi-exclamation-circle me-1"></i><?= $erro ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <input type="email" name="email" class="form-control form-control-lg" placeholder="seu@email.com" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Senha</label>
                        <input type="password" name="senha" class="form-control form-control-lg" placeholder="******" required>
                    </div>

                    <div class="text-end mb-3">
                        <a href="recuperar.php" class="text-secondary small">Esqueci minha senha</a>
                    </div>

                    <button type="submit" class="btn btn-dark btn-lg w-100 fw-bold mb-3">Entrar</button>
                    
                    <div class="text-center border-top pt-3">
                        <p class="small text-muted mb-1">Ainda não tem conta?</p>
                        <a href="registrar.php" class="btn btn-outline-primary btn-sm fw-bold w-50">Criar Conta Agora</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>
</html>
