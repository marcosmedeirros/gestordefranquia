<?php
session_start();
require '../core/conexao.php';

if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$erro = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $senha = trim($_POST['senha']);

    if (empty($email) || empty($senha)) {
        $erro = "Preencha todos os campos.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && (password_verify($senha, $user['senha']) || $user['senha'] == $senha || trim($user['senha']) == $senha)) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nome'] = $user['nome'];
            $_SESSION['is_admin'] = $user['is_admin'];
            header("Location: ../index.php");
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
  <title>Login - FBA Games</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --red:      #fc0025;
      --red-soft: rgba(252,0,37,.10);
      --bg:       #07070a;
      --panel:    #101013;
      --panel-2:  #16161a;
      --border:   rgba(255,255,255,.06);
      --border-md:rgba(255,255,255,.10);
      --border-red:rgba(252,0,37,.22);
      --text:     #f0f0f3;
      --text-2:   #868690;
      --text-3:   #48484f;
      --amber:    #f59e0b;
      --green:    #22c55e;
      --font:     'Poppins', sans-serif;
      --radius:   14px;
      --ease:     cubic-bezier(.2,.8,.2,1);
      --t:        200ms;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100vh; display: flex; -webkit-font-smoothing: antialiased; }

    .auth-layout { display: flex; min-height: 100vh; width: 100%; }

    /* Left panel */
    .auth-left {
      flex: 1; background: var(--panel); border-right: 1px solid var(--border);
      display: flex; flex-direction: column; justify-content: center; align-items: flex-start;
      padding: 60px 56px; position: relative; overflow: hidden;
    }
    .auth-left::before {
      content: ''; position: absolute; top: -120px; right: -120px;
      width: 360px; height: 360px; border-radius: 50%;
      background: radial-gradient(circle, rgba(252,0,37,.18) 0%, transparent 70%);
      pointer-events: none;
    }
    .auth-left::after {
      content: ''; position: absolute; bottom: -80px; left: -80px;
      width: 240px; height: 240px; border-radius: 50%;
      background: radial-gradient(circle, rgba(252,0,37,.10) 0%, transparent 70%);
      pointer-events: none;
    }
    .auth-logo {
      display: flex; align-items: center; gap: 12px; margin-bottom: 48px; position: relative;
    }
    .auth-logo-box {
      width: 44px; height: 44px; border-radius: 12px; background: var(--red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 900; font-size: 16px; color: #fff;
    }
    .auth-logo-name { font-size: 20px; font-weight: 800; color: var(--text); }
    .auth-logo-name span { color: var(--red); }
    .auth-headline { font-size: 32px; font-weight: 900; line-height: 1.2; color: var(--text); margin-bottom: 16px; position: relative; }
    .auth-headline em { color: var(--red); font-style: normal; }
    .auth-sub { font-size: 14px; color: var(--text-2); line-height: 1.7; max-width: 380px; position: relative; }
    .auth-badges { display: flex; gap: 10px; margin-top: 36px; flex-wrap: wrap; position: relative; }
    .auth-badge {
      display: flex; align-items: center; gap: 7px;
      background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: 999px; padding: 7px 14px;
      font-size: 12px; font-weight: 600; color: var(--text-2);
    }
    .auth-badge i { color: var(--red); font-size: 13px; }

    /* Right panel */
    .auth-right {
      width: 440px; flex-shrink: 0; display: flex; align-items: center;
      justify-content: center; padding: 40px 36px;
    }
    .auth-card { width: 100%; max-width: 380px; }
    .auth-card-title { font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 4px; }
    .auth-card-sub { font-size: 13px; color: var(--text-2); margin-bottom: 28px; }

    .fba-alert {
      display: flex; align-items: center; gap: 10px;
      padding: 11px 14px; border-radius: 10px;
      font-size: 13px; font-weight: 500; margin-bottom: 20px;
      background: rgba(252,0,37,.10); border: 1px solid var(--border-red); color: #ff6680;
    }

    .fba-field { margin-bottom: 16px; }
    .fba-label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-2); margin-bottom: 7px; }
    .fba-input-wrap { position: relative; }
    .fba-input-wrap i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--text-3); font-size: 14px; pointer-events: none; }
    .fba-input {
      width: 100%; background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: 10px; padding: 11px 14px 11px 38px;
      color: var(--text); font-family: var(--font); font-size: 13px;
      outline: none; transition: border-color var(--t) var(--ease);
    }
    .fba-input:focus { border-color: var(--red); }
    .fba-input::placeholder { color: var(--text-3); }

    .fba-link { font-size: 12px; color: var(--text-2); text-decoration: none; transition: color var(--t); }
    .fba-link:hover { color: var(--red); }
    .fba-forgot { text-align: right; margin-bottom: 20px; }

    .btn-primary {
      width: 100%; background: var(--red); color: #fff; border: none;
      border-radius: 10px; padding: 12px;
      font-family: var(--font); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: opacity var(--t) var(--ease);
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-primary:hover { opacity: .87; }

    .auth-divider { display: flex; align-items: center; gap: 12px; margin: 20px 0; }
    .auth-divider::before, .auth-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
    .auth-divider span { font-size: 11px; color: var(--text-3); }

    .btn-secondary {
      width: 100%; background: transparent; border: 1px solid var(--border-md);
      border-radius: 10px; padding: 11px;
      font-family: var(--font); font-size: 13px; font-weight: 600; color: var(--text-2);
      cursor: pointer; text-decoration: none; text-align: center; display: block;
      transition: all var(--t) var(--ease);
    }
    .btn-secondary:hover { border-color: var(--text-2); color: var(--text); }

    @media (max-width: 860px) {
      .auth-left { display: none; }
      .auth-right { width: 100%; padding: 40px 24px; }
    }
  </style>
</head>
<body>
<div class="auth-layout">

  <div class="auth-left">
    <div class="auth-logo">
      <div class="auth-logo-box">FBA</div>
      <span class="auth-logo-name">FBA <span>Games</span></span>
    </div>
    <h1 class="auth-headline">Bem-vindo de<br>volta ao <em>FBA</em></h1>
    <p class="auth-sub">Acerte as apostas, suba no ranking e domine a liga. Seus amigos já estão jogando.</p>
    <div class="auth-badges">
      <span class="auth-badge"><i class="bi bi-lightning-charge-fill"></i>Apostas ao vivo</span>
      <span class="auth-badge"><i class="bi bi-trophy-fill"></i>Rankings</span>
      <span class="auth-badge"><i class="bi bi-joystick"></i>Mini games</span>
      <span class="auth-badge"><i class="bi bi-gem"></i>FBA Points</span>
    </div>
  </div>

  <div class="auth-right">
    <div class="auth-card">
      <h2 class="auth-card-title">Entrar na conta</h2>
      <p class="auth-card-sub">Coloque suas credenciais para continuar</p>

      <?php if ($erro): ?>
      <div class="fba-alert"><i class="bi bi-exclamation-circle-fill"></i><?= $erro ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="fba-field">
          <label class="fba-label">E-mail</label>
          <div class="fba-input-wrap">
            <i class="bi bi-envelope"></i>
            <input type="email" name="email" class="fba-input" placeholder="seu@email.com" required>
          </div>
        </div>

        <div class="fba-field">
          <label class="fba-label">Senha</label>
          <div class="fba-input-wrap">
            <i class="bi bi-lock"></i>
            <input type="password" name="senha" class="fba-input" placeholder="••••••••" required>
          </div>
        </div>

        <div class="fba-forgot">
          <a href="recuperar.php" class="fba-link">Esqueci minha senha</a>
        </div>

        <button type="submit" class="btn-primary">
          <i class="bi bi-arrow-right-circle-fill"></i>Entrar
        </button>
      </form>

      <div class="auth-divider"><span>ou</span></div>
      <a href="registrar.php" class="btn-secondary">Criar conta agora</a>
    </div>
  </div>

</div>
</body>
</html>
