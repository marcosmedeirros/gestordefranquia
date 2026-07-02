<?php
require_once dirname(__DIR__) . '/helpers.php';
fba_head('Entrar — FBA');
$err = $_GET['err'] ?? null;
?>
<body class="auth-body">
<div class="auth-split">

  <!-- PAINEL ESQUERDO: branding / arena -->
  <div class="auth-left">
    <div class="court-line"></div>
    <div class="al-logo">
      <img src="assets/img/fba-logo.svg" alt="FBA">
      <div class="al-brand">
        <span class="al-name">FBA</span>
        <span class="al-tagline">Franchise Basketball Association</span>
      </div>
    </div>
    <div class="al-divider"></div>
    <ul class="al-features">
      <li><span class="fi">🏀</span><span>Assuma o comando de uma franquia real da NBA</span></li>
      <li><span class="fi">⏩</span><span>Simule jogos ao vivo, quarto a quarto</span></li>
      <li><span class="fi">📅</span><span><strong>82 jogos</strong> + Play-In, Playoffs e Draft</span></li>
      <li><span class="fi">🗓️</span><span>7 eras históricas — de 1979 a 2025</span></li>
      <li><span class="fi">🔄</span><span>Trocas, Free Agency e multi-temporada</span></li>
      <li><span class="fi">🏆</span><span>Construa uma dinastia. Deixe sua marca.</span></li>
    </ul>
    <p class="al-quote">"O jogo não é ganho nos últimos segundos. É construído ao longo de toda uma temporada."</p>
  </div>

  <!-- PAINEL DIREITO: formulário -->
  <div class="auth-right">
    <div class="auth-form-box">
      <h1>Bem-vindo de volta</h1>
      <p class="muted">Entre para continuar sua dinastia.</p>

      <?php if ($err): ?>
        <div class="auth-err" style="margin-bottom:18px"><?= e($err) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= url('home', ['action' => 'login']) ?>">
        <div class="auth-field">
          <label>Usuário</label>
          <input type="text" name="username" autofocus required maxlength="20" autocomplete="username">
        </div>
        <div class="auth-field">
          <label>Senha</label>
          <input type="password" name="password" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary auth-submit" type="submit">Entrar na Liga</button>
      </form>

      <div class="auth-separator">ou</div>
      <p class="auth-links">Não tem conta? <a href="<?= url('register') ?>">Criar conta grátis →</a></p>
    </div>
  </div>

</div>
<script src="assets/js/app.js"></script>
</body>
</html>
