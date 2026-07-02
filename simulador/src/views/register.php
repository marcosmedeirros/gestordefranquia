<?php
require_once dirname(__DIR__) . '/helpers.php';
fba_head('Criar Conta — FBA');
$err = $_GET['err'] ?? null;
?>
<body class="auth-body">
<div class="auth-split">

  <!-- PAINEL ESQUERDO -->
  <div class="auth-left">
    <div class="court-line"></div>
    <div class="al-logo">
      <img src="assets/img/fba-logo.svg" alt="FBA">
      <div class="al-brand">
        <span class="al-name">FBA</span>
        <span class="al-tagline">GM Simulator · Franchise Mode</span>
      </div>
    </div>
    <div class="al-divider"></div>
    <ul class="al-features">
      <li><span class="fi">🎮</span><span>Até <strong>2 saves</strong> por conta — cada um com sua história</span></li>
      <li><span class="fi">🗓️</span><span>Escolha a era: desde 1979-80 até hoje</span></li>
      <li><span class="fi">🏟️</span><span>30 franquias com identidade real</span></li>
      <li><span class="fi">📈</span><span>Progressão de jogadores temporada a temporada</span></li>
      <li><span class="fi">🤝</span><span>Loteria do Draft com animação ao vivo</span></li>
    </ul>
    <p class="al-quote">"Cada franquia tem uma história. A sua começa agora."</p>
  </div>

  <!-- PAINEL DIREITO -->
  <div class="auth-right">
    <div class="auth-form-box">
      <h1>Criar conta</h1>
      <p class="muted">Grátis. Sem anúncios. Só basquete.</p>

      <?php if ($err): ?>
        <div class="auth-err" style="margin-bottom:18px"><?= e($err) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= url('home', ['action' => 'register']) ?>">
        <div class="auth-field">
          <label>Usuário <span class="muted" style="text-transform:none;font-weight:400">(3–20 letras/números)</span></label>
          <input type="text" name="username" autofocus required maxlength="20"
                 pattern="[A-Za-z0-9_]{3,20}" autocomplete="username">
        </div>
        <div class="auth-field">
          <label>E-mail <span class="muted" style="text-transform:none;font-weight:400">(opcional)</span></label>
          <input type="email" name="email" autocomplete="email">
        </div>
        <div class="auth-field">
          <label>Senha <span class="muted" style="text-transform:none;font-weight:400">(mín. 4)</span></label>
          <input type="password" name="password" required minlength="4" autocomplete="new-password">
        </div>
        <button class="btn btn-primary auth-submit" type="submit">Criar conta e jogar</button>
      </form>

      <div class="auth-separator">ou</div>
      <p class="auth-links">Já tem conta? <a href="<?= url('login') ?>">Entrar →</a></p>
    </div>
  </div>

</div>
<script src="assets/js/app.js"></script>
</body>
</html>
