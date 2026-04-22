<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recuperar Senha - FBA Games</title>
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
      background: radial-gradient(circle, rgba(252,0,37,.14) 0%, transparent 70%);
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
    .auth-info-box {
      display: flex; align-items: flex-start; gap: 14px;
      background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: 14px; padding: 18px 20px; margin-top: 36px; position: relative;
    }
    .auth-info-box i { font-size: 20px; color: var(--text-2); flex-shrink: 0; margin-top: 2px; }
    .auth-info-box p { font-size: 13px; color: var(--text-2); line-height: 1.6; margin: 0; }

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
    }
    .fba-alert.danger { background: rgba(252,0,37,.10); border: 1px solid var(--border-red); color: #ff6680; }
    .fba-alert.success { background: rgba(34,197,94,.10); border: 1px solid rgba(34,197,94,.22); color: #4ade80; }

    #reset-message:empty { display: none; }

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

    .btn-primary {
      width: 100%; background: var(--red); color: #fff; border: none;
      border-radius: 10px; padding: 12px;
      font-family: var(--font); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: opacity var(--t) var(--ease);
      display: flex; align-items: center; justify-content: center; gap: 8px;
      margin-bottom: 12px;
    }
    .btn-primary:hover { opacity: .87; }

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
    <h1 class="auth-headline">Recuperar<br>o <em>acesso</em></h1>
    <p class="auth-sub">Informe seu e-mail e enviaremos um link para você redefinir sua senha.</p>
    <div class="auth-info-box">
      <i class="bi bi-shield-lock"></i>
      <p>O link de recuperação expira em 30 minutos e só pode ser usado uma vez.</p>
    </div>
  </div>

  <div class="auth-right">
    <div class="auth-card">
      <h2 class="auth-card-title">Esqueceu a senha?</h2>
      <p class="auth-card-sub">Enviaremos um link de redefinição para seu e-mail</p>

      <div id="reset-message"></div>

      <form id="form-recuperar">
        <div class="fba-field">
          <label class="fba-label">E-mail</label>
          <div class="fba-input-wrap">
            <i class="bi bi-envelope"></i>
            <input type="email" name="email" class="fba-input" placeholder="seu@email.com" required>
          </div>
        </div>

        <button type="submit" class="btn-primary">
          <i class="bi bi-send-fill"></i>Enviar link de recuperação
        </button>
      </form>

      <a href="login.php" class="btn-secondary"><i class="bi bi-arrow-left"></i> Voltar ao login</a>
    </div>
  </div>

</div>
<script>
const api = (path, options = {}) => fetch(path, {
  headers: { 'Content-Type': 'application/json' },
  ...options,
}).then(async res => {
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw body;
  return body;
});

const showMessage = (elementId, message, type = 'danger') => {
  const el = document.getElementById(elementId);
  el.innerHTML = `<div class="fba-alert ${type}"><i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill'}"></i>${message}</div>`;
};

document.getElementById('form-recuperar').addEventListener('submit', async (e) => {
  e.preventDefault();
  const email = (e.target.email.value || '').trim();
  if (!email) { showMessage('reset-message', 'Informe seu e-mail.'); return; }
  try {
    const result = await api('../api/reset-password.php', { method: 'POST', body: JSON.stringify({ email }) });
    showMessage('reset-message', result.message || 'Se o e-mail existir, você receberá um link de recuperação.', 'success');
    e.target.reset();
  } catch (err) {
    showMessage('reset-message', err.error || 'Erro ao enviar o link. Tente novamente.');
  }
});
</script>
</body>
</html>
