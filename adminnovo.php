<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$user = getUserSession();
if (($user['user_type'] ?? 'jogador') !== 'admin') {
  header('Location: /dashboard.php');
  exit;
}
$pdo = db();

// Buscar time do usuário (se tiver)
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
  <meta name="theme-color" content="#fc0025">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="FBA Manager">
  <meta name="mobile-web-app-capable" content="yes">
  <link rel="manifest" href="/manifest.json?v=3">
  <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
  <title>Admin - Ligas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
  <style>
    :root {
      --red:        #fc0025;
      --red-2:      #ff2a44;
      --red-soft:   rgba(252,0,37,.10);
      --red-glow:   rgba(252,0,37,.18);
      --bg:         #07070a;
      --panel:      #101013;
      --panel-2:    #16161a;
      --panel-3:    #1c1c21;
      --border:     rgba(255,255,255,.06);
      --border-md:  rgba(255,255,255,.10);
      --border-red: rgba(252,0,37,.22);
      --text:       #f0f0f3;
      --text-2:     #868690;
      --text-3:     #48484f;
      --green:      #22c55e;
      --amber:      #f59e0b;
      --blue:       #3b82f6;
      --sidebar-w:  260px;
      --font:       'Poppins', sans-serif;
      --radius:     14px;
      --radius-sm:  10px;
      --radius-xs:  6px;
      --ease:       cubic-bezier(.2,.8,.2,1);
      --t:          200ms;
    }

    :root[data-theme="light"] {
      --bg: #f6f7fb;
      --panel: #ffffff;
      --panel-2: #f2f4f8;
      --panel-3: #e9edf4;
      --border: #e3e6ee;
      --border-md: #d7dbe6;
      --border-red: rgba(252,0,37,.18);
      --text: #111217;
      --text-2: #5b6270;
      --text-3: #8b93a5;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      font-family: var(--font);
      background: var(--bg);
      color: var(--text);
      -webkit-font-smoothing: antialiased;
    }

    .app { display: flex; min-height: 100vh; }

    .sidebar {
      position: fixed; top: 0; left: 0;
      width: var(--sidebar-w); height: 100vh;
      background: var(--panel);
      border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      z-index: 300;
      transition: transform var(--t) var(--ease);
      overflow-y: auto;
      scrollbar-width: none;
    }
    .sidebar::-webkit-scrollbar { display: none; }

    .sb-brand {
      padding: 22px 18px 18px;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 12px;
      flex-shrink: 0;
    }
    .sb-logo {
      width: 34px; height: 34px; border-radius: 9px;
      background: var(--red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 13px; color: #fff;
      flex-shrink: 0;
    }
    .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
    .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }

    .sb-team {
      margin: 14px 14px 0;
      background: var(--panel-2);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 14px;
      display: flex; align-items: center; gap: 10px;
      flex-shrink: 0;
    }
    .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
    .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
    .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }

    .sb-season {
      margin: 10px 14px 0;
      background: var(--red-soft);
      border: 1px solid var(--border-red);
      border-radius: 8px;
      padding: 8px 12px;
      display: flex; align-items: center; justify-content: space-between;
      flex-shrink: 0;
    }
    .sb-season-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); }
    .sb-season-val { font-size: 14px; font-weight: 700; color: var(--red); }

    .sb-nav { flex: 1; padding: 12px 10px 8px; }
    .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
    .sb-nav a {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 10px; border-radius: var(--radius-sm);
      color: var(--text-2); font-size: 13px; font-weight: 500;
      text-decoration: none; margin-bottom: 2px;
      transition: all var(--t) var(--ease);
    }
    .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
    .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
    .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
    .sb-nav a.active i { color: var(--red); }

    .sb-theme-toggle {
      margin: 0 14px 12px;
      padding: 8px 10px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: var(--panel-2);
      color: var(--text);
      display: flex; align-items: center; justify-content: center; gap: 8px;
      font-size: 12px; font-weight: 600;
      cursor: pointer;
      transition: all var(--t) var(--ease);
    }
    .sb-theme-toggle:hover { border-color: var(--border-red); color: var(--red); }

    .sb-footer {
      padding: 12px 14px;
      border-top: 1px solid var(--border);
      display: flex; align-items: center; gap: 10px;
      flex-shrink: 0;
    }
    .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
    .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sb-logout {
      width: 26px; height: 26px; border-radius: 7px;
      background: transparent; border: 1px solid var(--border);
      color: var(--text-2); display: flex; align-items: center; justify-content: center;
      font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease);
      text-decoration: none; flex-shrink: 0;
    }
    .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

    .topbar {
      display: none; position: fixed; top: 0; left: 0; right: 0;
      height: 54px; background: var(--panel);
      border-bottom: 1px solid var(--border);
      align-items: center; padding: 0 16px; gap: 12px; z-index: 199;
    }
    .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
    .topbar-title em { color: var(--red); font-style: normal; }
    .topbar-pill { font-size: 11px; font-weight: 700; color: var(--red); }
    .menu-btn {
      width: 34px; height: 34px; border-radius: 9px;
      background: var(--panel-2); border: 1px solid var(--border);
      color: var(--text); display: flex; align-items: center; justify-content: center;
      cursor: pointer; font-size: 17px;
    }
    .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 199; }
    .sb-overlay.show { display: block; }

    .main {
      margin-left: var(--sidebar-w);
      min-height: 100vh;
      width: calc(100% - var(--sidebar-w));
      display: flex; flex-direction: column;
    }

    .breadcrumb-admin {
      margin: 18px 28px 0;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 10px 14px;
    }
    .breadcrumb-admin a { color: var(--red); text-decoration: none; }
    .breadcrumb-admin .breadcrumb { margin: 0; font-size: 12px; }

    .page-header { padding: 18px 28px 0; }
    .page-header h1 { font-size: 24px; font-weight: 800; }
    .page-header .text-orange { color: var(--red); }

    .admin-hero {
      padding: 6px 28px 0;
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: 16px; flex-wrap: wrap;
    }
    .admin-eyebrow {
      font-size: 11px; font-weight: 600; letter-spacing: 1.4px;
      text-transform: uppercase; color: var(--red);
      margin-bottom: 4px;
    }
    .admin-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
    .admin-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }

    .admin-badges { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding-top: 2px; }
    .admin-badge {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 12px; border-radius: 999px;
      font-size: 12px; font-weight: 600;
      border: 1px solid var(--border-md);
      background: var(--panel);
    }
    .admin-badge.red { background: var(--red-soft); border-color: var(--border-red); color: var(--red); }
    .admin-badge.blue { background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.25); color: #93c5fd; }

    .admin-actions {
      padding: 12px 28px 0;
      display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 12px;
    }
    .admin-action {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 14px 16px;
      text-decoration: none; color: var(--text);
      display: flex; align-items: center; gap: 10px;
      transition: transform var(--t) var(--ease), border-color var(--t) var(--ease);
    }
    .admin-action:hover { transform: translateY(-2px); border-color: var(--border-md); color: var(--text); }
    .admin-action i { font-size: 18px; color: var(--red); }
    .admin-action span { font-size: 12px; font-weight: 600; }

    .admin-content { padding: 16px 28px 40px; }
    .admin-panel {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 18px 20px;
      min-height: 220px;
    }

    .admin-check-card { border: 2px solid var(--red) !important; }
    .admin-check-card.is-accepted { border-color: var(--green) !important; }

    @media (max-width: 860px) {
      :root { --sidebar-w: 0px; }
      .sidebar { transform: translateX(-260px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; width: 100%; padding-top: 54px; }
      .topbar { display: flex; }
      .breadcrumb-admin, .page-header, .admin-hero, .admin-actions, .admin-content { padding-left: 16px; padding-right: 16px; }
      .breadcrumb-admin { margin-left: 16px; margin-right: 16px; }
      .admin-actions { grid-template-columns: repeat(2, minmax(0,1fr)); }
    }
    @media (max-width: 520px) {
      .admin-actions { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="sb-brand">
      <div class="sb-logo">FBA</div>
      <div class="sb-brand-text">
        FBA Manager
        <span>Painel Admin</span>
      </div>
    </div>

    <div class="sb-team">
      <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
           alt="Admin"
           onerror="this.src='/img/default-team.png'">
      <div>
        <div class="sb-team-name"><?= $team ? htmlspecialchars($team['city'] . ' ' . $team['name']) : 'Admin' ?></div>
        <div class="sb-team-league"><?= $team ? htmlspecialchars($team['league']) : 'Painel' ?></div>
      </div>
    </div>

    <div class="sb-season">
      <div>
        <div class="sb-season-label">Acesso</div>
        <div class="sb-season-val">Admin</div>
      </div>
      <div style="text-align:right">
        <div class="sb-season-label">Liga</div>
        <div class="sb-season-val"><?= htmlspecialchars($team['league'] ?? 'FBA') ?></div>
      </div>
    </div>

    <nav class="sb-nav">
      <div class="sb-section">Principal</div>
      <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
      <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
      <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
      <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
      <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
      <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
      <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
      <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>

      <div class="sb-section">Liga</div>
      <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
      <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
      <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
      <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
      <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>

      <div class="sb-section">Admin</div>
      <a href="/admin.php" class="active"><i class="bi bi-shield-lock-fill"></i> Admin</a>
      <a href="/punicoes.php"><i class="bi bi-exclamation-triangle-fill"></i> Punições</a>
      <a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a>

      <div class="sb-section">Conta</div>
      <a href="/settings.php"><i class="bi bi-gear-fill"></i> Configurações</a>
    </nav>

    <button class="sb-theme-toggle" type="button" id="themeToggle">
      <i class="bi bi-moon"></i>
      <span>Modo escuro</span>
    </button>

    <div class="sb-footer">
      <img src="<?= htmlspecialchars($user['photo_url'] ?? 'https://ui-avatars.com/api/?name=' . rawurlencode($user['name']) . '&background=1c1c21&color=fc0025') ?>"
           alt="<?= htmlspecialchars($user['name']) ?>"
           class="sb-avatar"
           onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name']) ?>&background=1c1c21&color=fc0025'">
      <span class="sb-username"><?= htmlspecialchars($user['name']) ?></span>
      <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </aside>

  <div class="sb-overlay" id="sbOverlay"></div>

  <header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Manager</em></div>
    <span class="topbar-pill">Admin</span>
  </header>

  <main class="main">
    <div class="breadcrumb-admin" id="breadcrumbContainer" style="display: none;">
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0" id="breadcrumb">
          <li class="breadcrumb-item"><a href="#" onclick="showHome(); return false;">Admin</a></li>
        </ol>
      </nav>
    </div>

    <div class="page-header mb-3">
      <h1 class="fw-bold mb-0">
        <i class="bi bi-shield-lock-fill me-2 text-orange"></i>
        <span id="pageTitle">Painel Administrativo</span>
      </h1>
    </div>

    <div class="admin-hero">
      <div>
        <div class="admin-eyebrow">Central de Controle</div>
        <h2 class="admin-title">Admin da Liga</h2>
        <p class="admin-sub">Gerencie aprovacoes, punicoes e temporadas com rapidez.</p>
      </div>
      <div class="admin-badges">
        <span class="admin-badge red"><i class="bi bi-shield-check"></i> Perfil admin</span>
        <span class="admin-badge blue"><i class="bi bi-flag"></i> Liga <?= htmlspecialchars($team['league'] ?? 'FBA') ?></span>
      </div>
    </div>

    <div class="admin-actions">
      <a class="admin-action" href="/admin.php">
        <i class="bi bi-shield-lock"></i>
        <span>Modulo admin</span>
      </a>
      <a class="admin-action" href="/pending-approval.php">
        <i class="bi bi-person-check"></i>
        <span>Aprovacoes</span>
      </a>
      <a class="admin-action" href="/punicoes.php">
        <i class="bi bi-exclamation-triangle"></i>
        <span>Punicoes</span>
      </a>
      <a class="admin-action" href="/temporadas.php">
        <i class="bi bi-calendar3"></i>
        <span>Temporadas</span>
      </a>
    </div>

    <div class="admin-content" id="mainContainer">
      <div class="admin-panel">
        <!-- Conteudo sera carregado dinamicamente aqui -->
      </div>
    </div>
  </main>
</div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/admin.js?v=<?= time() ?>"></script>
  <script src="/js/seasons.js?v=<?= time() ?>"></script>
  <script>
    const themeToggle = document.getElementById('themeToggle');
    const themeKey = 'fba-theme';

    const applyTheme = (theme) => {
      if (theme === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
        if (themeToggle) {
          themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
        }
        return;
      }
      document.documentElement.removeAttribute('data-theme');
      if (themeToggle) {
        themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
      }
    };

    const savedTheme = localStorage.getItem(themeKey);
    applyTheme(savedTheme || 'dark');

    if (themeToggle) {
      themeToggle.addEventListener('click', () => {
        const current = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        const next = current === 'light' ? 'dark' : 'light';
        localStorage.setItem(themeKey, next);
        applyTheme(next);
      });
    }

    const sidebar = document.getElementById('sidebar');
    const menuBtn = document.getElementById('menuBtn');
    const sbOverlay = document.getElementById('sbOverlay');

    const closeSidebar = () => {
      sidebar.classList.remove('open');
      sbOverlay.classList.remove('show');
    };

    if (menuBtn) {
      menuBtn.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        sbOverlay.classList.toggle('show');
      });
    }

    if (sbOverlay) {
      sbOverlay.addEventListener('click', closeSidebar);
    }

    if (window.innerWidth <= 860) {
      document.querySelectorAll('.sb-nav a').forEach((link) => {
        link.addEventListener('click', closeSidebar);
      });
    }
  </script>
  <script src="/js/pwa.js"></script>
</body>
</html>
