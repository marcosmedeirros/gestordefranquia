<?php
session_start();
require '../core/conexao.php';

$erro = "";
$sucesso = "";

if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nome = trim($_POST['nome']);
    $email = trim($_POST['email']);
    $senha = trim($_POST['senha']);
    $liga = strtoupper(trim($_POST['liga'] ?? ''));

    $ligas_validas = ['ELITE', 'RISE', 'NEXT', 'ROOKIE'];

    if (empty($nome) || empty($email) || empty($senha) || empty($liga)) {
        $erro = "Preencha todos os campos.";
    } elseif (!in_array($liga, $ligas_validas, true)) {
        $erro = "Selecione uma liga válida.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email");
        $stmt->execute([':email' => $email]);

        if ($stmt->rowCount() > 0) {
            $erro = "Este e-mail já está cadastrado.";
        } else {
            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
            try {
                $pdo->exec("ALTER TABLE usuarios ADD COLUMN league ENUM('ELITE','RISE','NEXT','ROOKIE') DEFAULT 'ROOKIE'");
            } catch (Exception $e) {}

            try {
                $sql = "INSERT INTO usuarios (nome, email, senha, pontos, is_admin, league) VALUES (:nome, :email, :senha, 50.00, 0, :league)";
                $pdo->prepare($sql)->execute([':nome' => $nome, ':email' => $email, ':senha' => $senhaHash, ':league' => $liga]);
                $sucesso = "Conta criada com sucesso! Redirecionando...";
                header("refresh:2;url=login.php");
            } catch (PDOException $e) {
                $erro = "Erro ao cadastrar: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Criar Conta - FBA Games</title>
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
    .auth-bonus {
      display: flex; align-items: center; gap: 12px;
      background: rgba(245,158,11,.10); border: 1px solid rgba(245,158,11,.2);
      border-radius: 12px; padding: 14px 18px; margin-top: 32px; position: relative;
    }
    .auth-bonus i { font-size: 22px; color: var(--amber); flex-shrink: 0; }
    .auth-bonus-text { font-size: 13px; color: var(--text-2); line-height: 1.5; }
    .auth-bonus-text strong { color: var(--amber); }
    .liga-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 28px; position: relative; }
    .liga-pill {
      display: flex; align-items: center; gap: 6px;
      background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: 8px; padding: 6px 12px;
      font-size: 11px; font-weight: 700; color: var(--text-2); letter-spacing: .4px;
    }

    .auth-right {
      width: 480px; flex-shrink: 0; display: flex; align-items: center;
      justify-content: center; padding: 40px 36px; overflow-y: auto;
    }
    .auth-card { width: 100%; max-width: 400px; }
    .auth-card-title { font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 4px; }
    .auth-card-sub { font-size: 13px; color: var(--text-2); margin-bottom: 24px; }

    .fba-alert {
      display: flex; align-items: center; gap: 10px;
      padding: 11px 14px; border-radius: 10px;
      font-size: 13px; font-weight: 500; margin-bottom: 18px;
    }
    .fba-alert.danger { background: rgba(252,0,37,.10); border: 1px solid var(--border-red); color: #ff6680; }
    .fba-alert.success { background: rgba(34,197,94,.10); border: 1px solid rgba(34,197,94,.22); color: #4ade80; }

    .fba-field { margin-bottom: 14px; }
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
    .fba-select {
      width: 100%; background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: 10px; padding: 11px 14px;
      color: var(--text); font-family: var(--font); font-size: 13px;
      outline: none; transition: border-color var(--t) var(--ease);
      appearance: none; cursor: pointer;
    }
    .fba-select:focus { border-color: var(--red); }

    .btn-primary {
      width: 100%; background: var(--red); color: #fff; border: none;
      border-radius: 10px; padding: 12px;
      font-family: var(--font); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: opacity var(--t) var(--ease);
      display: flex; align-items: center; justify-content: center; gap: 8px;
      margin-top: 8px;
    }
    .btn-primary:hover { opacity: .87; }

    .auth-divider { display: flex; align-items: center; gap: 12px; margin: 18px 0; }
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

    @media (max-width: 900px) {
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
    <h1 class="auth-headline">Crie sua conta e<br>entre na <em>liga</em></h1>
    <p class="auth-sub">Aposte, jogue e suba no ranking. Escolha sua liga e comece agora mesmo.</p>
    <div class="auth-bonus">
      <i class="bi bi-gift-fill"></i>
      <div class="auth-bonus-text">Você começa com <strong>50 moedas grátis</strong> para fazer sua primeira aposta.</div>
    </div>
    <div class="liga-pills">
      <span class="liga-pill">🏅 ELITE</span>
      <span class="liga-pill">⚡ RISE</span>
      <span class="liga-pill">🔥 NEXT</span>
      <span class="liga-pill">🌱 ROOKIE</span>
    </div>
  </div>

  <div class="auth-right">
    <div class="auth-card">
      <h2 class="auth-card-title">Criar conta</h2>
      <p class="auth-card-sub">Preencha seus dados para começar</p>

      <?php if ($erro): ?>
      <div class="fba-alert danger"><i class="bi bi-exclamation-circle-fill"></i><?= $erro ?></div>
      <?php endif; ?>

      <?php if ($sucesso): ?>
      <div class="fba-alert success"><i class="bi bi-check-circle-fill"></i><?= $sucesso ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="fba-field">
          <label class="fba-label">Nome completo</label>
          <div class="fba-input-wrap">
            <i class="bi bi-person"></i>
            <input type="text" name="nome" class="fba-input" placeholder="Seu nome" required>
          </div>
        </div>

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
            <input type="password" name="senha" class="fba-input" placeholder="Crie uma senha forte" required>
          </div>
        </div>

        <div class="fba-field">
          <label class="fba-label">Liga</label>
          <select name="liga" class="fba-select" required>
            <option value="" disabled selected>Selecione sua liga</option>
            <option value="ELITE">🏅 Elite</option>
            <option value="RISE">⚡ Rise</option>
            <option value="NEXT">🔥 Next</option>
            <option value="ROOKIE">🌱 Rookie</option>
          </select>
        </div>

        <button type="submit" class="btn-primary">
          <i class="bi bi-person-plus-fill"></i>Cadastrar
        </button>
      </form>

      <div class="auth-divider"><span>já tem conta?</span></div>
      <a href="login.php" class="btn-secondary">Fazer login</a>
    </div>
  </div>

</div>
</body>
</html>
