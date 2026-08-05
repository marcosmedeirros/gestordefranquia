<?php
session_start();
require_once 'backend/config.php';
require_once 'backend/db.php';
$pdo = db();
require_once 'backend/auth.php';
require_once 'backend/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
if (!hasAdminAccess($pdo, (int)$user_id)) {
    header('Location: /dashboard.php');
    exit;
}

$team_id = $_SESSION['team_id'] ?? null;

$team = [];
if ($team_id) {
    $stmt = $pdo->prepare("SELECT id, name, city, photo_url, league FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch() ?: [];
}

$stmt = $pdo->prepare("SELECT id, name, photo_url, league, user_type, accent_color FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch() ?: [];
$user['user_type'] = $user['user_type'] ?? ($_SESSION['user_type'] ?? 'jogador');

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Roletas — FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
    <style>
        :root {
            --red: #fc0025; --red-2: color-mix(in srgb, var(--red) 85%, white); --red-soft: color-mix(in srgb, var(--red) 10%, transparent); --red-glow: color-mix(in srgb, var(--red) 18%, transparent);
            --bg: #07070a; --panel: #101013; --panel-2: #16161a; --panel-3: #1c1c21;
            --border: rgba(255,255,255,.06); --border-md: rgba(255,255,255,.10); --border-strong: var(--border-md); --border-red: color-mix(in srgb, var(--red) 22%, transparent);
            --text: #f0f0f3; --text-2: #868690; --text-3: #7d7d85;
            --green: #22c55e; --amber: #f59e0b; --blue: #3b82f6; --purple: #a855f7;
            --sidebar-w: 260px; --font: 'Montserrat', sans-serif;
            --radius: 14px; --radius-sm: 10px; --radius-xs: 6px;
            --ease: cubic-bezier(.2,.8,.2,1); --t: 200ms;
        }
        :root[data-theme="light"] {
            --bg: #f6f7fb; --panel: #ffffff; --panel-2: #f2f4f8; --panel-3: #e9edf4;
            --border: #e3e6ee; --border-md: #d7dbe6; --border-red: color-mix(in srgb, var(--red) 18%, transparent);
            --text: #111217; --text-2: #5b6270; --text-3: #657080;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }
        .app { display: flex; min-height: 100vh; }
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }
        .page-hero { padding: 32px 32px 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .hero-title { font-size: 26px; font-weight: 800; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 10px; }
        .hero-sub { font-size: 13px; color: var(--text-2); }
        .hero-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .content { padding: 20px 32px 48px; flex: 1; }
        /* Topbar mobile */
        .topbar { display: none; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); padding: 0 16px; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 200; }
        .topbar-title { font-size: 15px; font-weight: 700; color: var(--text); }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { background: transparent; border: 1px solid var(--border); color: var(--text); width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 299; }
        .sb-overlay.show { display: block; }

        /* Menu lateral */
        .sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; transition: transform var(--t) var(--ease); overflow-y: auto; scrollbar-width: none; }
        .sidebar::-webkit-scrollbar { display: none; }
        .sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
        .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
        .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
        .sb-nav a { font-family:'Inter',sans-serif; display: flex; align-items: center; gap: 10px; padding: 10px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
        .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
        .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
        .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
        .sb-nav a.active i { color: var(--red); }
        .sb-theme-toggle { margin: 0 14px 12px; padding: 8px 10px; border-radius: 10px; border: 1px solid var(--border); background: var(--panel-2); color: var(--text); display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease); }
        .sb-theme-toggle:hover { border-color: var(--border-red); color: var(--red); }
        .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; flex-shrink: 0; }
        .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        .btn-orange { background: var(--red); border: none; color: #fff; font-weight: 600; font-size: 13px; border-radius: var(--radius-xs); padding: 10px 20px; transition: background var(--t); cursor: pointer; }
        .btn-orange:hover, .btn-orange:focus { background: var(--red-2); color: #fff; }
        .btn-orange:disabled { background: var(--panel-3); color: var(--text-3); cursor: not-allowed; }
        .btn-ghost { background: transparent; border: 1px solid var(--border-md); color: var(--text-2); font-weight: 600; font-size: 12px; border-radius: var(--radius-xs); padding: 6px 12px; transition: all var(--t); cursor: pointer; }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

        .empty { padding: 40px 16px; color: var(--text-3); text-align: center; }
        .empty i { font-size: 32px; display: block; margin-bottom: 10px; }
        .empty p { font-size: 13px; margin: 0; }

        .roleta-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
        .roleta-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px; cursor: pointer; transition: all var(--t) var(--ease); display: flex; flex-direction: column; gap: 10px; }
        .roleta-card:hover { border-color: var(--border-red); transform: translateY(-2px); }
        .roleta-card-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 19px; }
        .roleta-card-title { font-size: 15px; font-weight: 700; color: var(--text); line-height: 1.25; }
        .roleta-card-sub { font-size: 11px; color: var(--text-3); }
        .roleta-card-progress { height: 5px; border-radius: 999px; background: var(--panel-3); overflow: hidden; margin-top: 2px; }
        .roleta-card-progress > div { height: 100%; background: var(--red); }
        .roleta-card-status { font-size: 10px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; padding: 3px 9px; border-radius: 999px; align-self: flex-start; }
        .roleta-card-new { border: 1.5px dashed var(--border-md); background: transparent; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: var(--text-2); min-height: 138px; }
        .roleta-card-new:hover { border-color: var(--red); color: var(--red); background: var(--red-soft); }
        .roleta-card-new i { font-size: 26px; }

        /* Modal criar roleta */
        .rl-modal-body .form-label { font-size: 12px; font-weight: 600; color: var(--text-2); margin-bottom: 6px; }
        .rl-tipo-tabs { display: flex; gap: 8px; margin-bottom: 14px; }
        .rl-tipo-tab { flex: 1; text-align: center; padding: 9px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--panel-2); color: var(--text-2); font-size: 12px; font-weight: 700; cursor: pointer; transition: all var(--t); }
        .rl-tipo-tab.active { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }
        .rl-autocomplete { position: relative; }
        .rl-autocomplete-results { position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: var(--panel-2); border: 1px solid var(--border-md); border-radius: var(--radius-sm); max-height: 220px; overflow-y: auto; z-index: 50; box-shadow: 0 12px 28px rgba(0,0,0,.3); display: none; }
        .rl-autocomplete-results.show { display: block; }
        .rl-ac-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; cursor: pointer; font-size: 13px; }
        .rl-ac-item:hover { background: var(--panel-3); }
        .rl-ac-item img { width: 26px; height: 26px; border-radius: 6px; object-fit: cover; flex-shrink: 0; background: var(--panel-3); }
        .rl-ac-empty { padding: 10px 12px; font-size: 12px; color: var(--text-3); }
        .rl-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; min-height: 30px; }
        .rl-chip { display: inline-flex; align-items: center; gap: 7px; background: var(--panel-2); border: 1px solid var(--border); border-radius: 999px; padding: 5px 8px 5px 12px; font-size: 12px; font-weight: 600; color: var(--text); }
        .rl-chip button { background: transparent; border: none; color: var(--text-3); width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; }
        .rl-chip button:hover { background: var(--red-soft); color: var(--red); }
        .rl-check-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); margin-top: 14px; font-size: 13px; font-weight: 600; color: var(--text); }
        .rl-check-row small { display: block; font-size: 11px; color: var(--text-3); font-weight: 400; }

        input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible,[tabindex]:focus-visible{outline:2px solid var(--red, #fc0025);outline-offset:2px;}
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; animation-delay: 0ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; transition-delay: 0ms !important; scroll-behavior: auto !important; } }
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .page-hero { padding: 16px 16px 0; }
            .content { padding: 16px 16px 48px; }
        }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body class="roleta-page">
<div class="app">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="sb-overlay" id="sbOverlay"></div>

    <main class="main">
        <header class="topbar">
            <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
            <div class="topbar-title">FBA <em>Admin</em></div>
        </header>

        <div class="page-hero">
            <div>
                <div class="hero-eyebrow">Admin · Gestão</div>
                <h1 class="hero-title"><i class="bi bi-record-circle" style="color:var(--red)"></i>Roletas</h1>
                <p class="hero-sub">Crie quantas roletas quiser — GMs, times ou lista personalizada — e sorteie a ordem de eliminação de cada uma.</p>
            </div>
            <div class="hero-actions">
                <a class="btn-ghost" href="/admin.php#gestao"><i class="bi bi-arrow-left me-1"></i>Voltar ao Admin</a>
                <button type="button" class="btn-orange" id="btnNovaRoletaTop"><i class="bi bi-plus-lg me-1"></i>Nova Roleta</button>
            </div>
        </div>

        <div class="content">
            <div class="roleta-grid" id="roletaGrid">
                <div class="empty"><p>Carregando...</p></div>
            </div>
        </div>
    </main>
</div>

<!-- Modal: criar roleta -->
<div class="modal fade" id="modalNovaRoleta" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--panel);border:1px solid var(--border);color:var(--text)">
      <div class="modal-header" style="border-color:var(--border)">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2" style="color:var(--red)"></i>Nova Roleta</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body rl-modal-body">
        <div class="mb-3">
          <div class="form-label">Título</div>
          <input type="text" id="rlTitulo" class="form-control" placeholder="Ex: Roleta de saída — Temporada 5" style="background:var(--panel-2);border:1px solid var(--border);color:var(--text)">
        </div>

        <div class="mb-3">
          <div class="form-label">Liga</div>
          <select id="rlLiga" class="form-control" style="background:var(--panel-2);border:1px solid var(--border);color:var(--text)">
            <?php foreach (getAdminLeagues($pdo, (int)$user_id) as $lgOpt): ?>
            <option value="<?= htmlspecialchars($lgOpt) ?>"><?= htmlspecialchars($lgOpt) ?></option>
            <?php endforeach; ?>
          </select>
          <small style="color:var(--text-3);font-size:11px">A roleta aparece no painel dos GMs desta liga, e só o admin dela pode girar.</small>
        </div>

        <div class="form-label">Tipo de participante</div>
        <div class="rl-tipo-tabs">
          <div class="rl-tipo-tab active" data-tipo="gms">GMs</div>
          <div class="rl-tipo-tab" data-tipo="times">Times</div>
          <div class="rl-tipo-tab" data-tipo="personalizado">Personalizado</div>
        </div>

        <div id="rlBuscaWrap">
          <div class="form-label" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
            <span>Adicionar participantes</span>
            <button type="button" class="btn-ghost" id="btnRlAddTodos" style="padding:4px 10px;font-size:11px;white-space:nowrap">
              <i class="bi bi-people-fill me-1"></i><span id="rlAddTodosLabel">Adicionar todos os GMs da liga</span>
            </button>
          </div>
          <div class="rl-autocomplete">
            <input type="text" id="rlBusca" class="form-control" placeholder="Digite o nome do GM ou do time..." style="background:var(--panel-2);border:1px solid var(--border);color:var(--text)" autocomplete="off">
            <div class="rl-autocomplete-results" id="rlBuscaResultados"></div>
          </div>
        </div>
        <div id="rlPersonalizadoWrap" style="display:none">
          <div class="form-label">Adicionar participante</div>
          <div style="display:flex;gap:8px">
            <input type="text" id="rlNomeLivre" class="form-control" placeholder="Nome do participante" style="background:var(--panel-2);border:1px solid var(--border);color:var(--text)">
            <button type="button" class="btn-orange" id="btnAddNomeLivre" style="padding:8px 16px"><i class="bi bi-plus-lg"></i></button>
          </div>
          <small style="color:var(--text-3);font-size:11px">Dá pra colar vários nomes de uma vez — um por linha, cada linha vira um participante.</small>
        </div>

        <div class="rl-chips" id="rlChips"></div>

        <label class="rl-check-row">
          <input type="checkbox" id="rlNotificar" checked style="width:16px;height:16px">
          <span><span style="display:block">Notificar quem sair da roleta</span><small>Envia push pra todos os participantes cadastrados avisando quem foi eliminado a cada giro.</small></span>
        </label>
      </div>
      <div class="modal-footer" style="border-color:var(--border)">
        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-orange" id="btnCriarRoleta">Criar roleta</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= assetUrl('/js/roleta.js') ?>"></script>
<script src="/js/pwa.js"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    const sbOverlay = document.getElementById('sbOverlay');
    const menuBtn = document.getElementById('menuBtn');
    if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); sbOverlay.classList.add('show'); });
    if (sbOverlay) sbOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); });
    // Theme
    const themeKey = 'fba-theme';
    const themeBtn = document.getElementById('themeToggle');
    const applyTheme = (theme) => {
        if (theme === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
            if (themeBtn) { themeBtn.innerHTML = '<i class="bi bi-moon-fill"></i><span>Tema escuro</span>'; themeBtn.setAttribute('aria-pressed','true'); }
        } else {
            document.documentElement.removeAttribute('data-theme');
            if (themeBtn) { themeBtn.innerHTML = '<i class="bi bi-sun-fill"></i><span>Tema claro</span>'; themeBtn.setAttribute('aria-pressed','false'); }
        }
    };
    applyTheme(localStorage.getItem(themeKey) || 'dark');
    if (themeBtn) themeBtn.addEventListener('click', () => {
        const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        localStorage.setItem(themeKey, next);
        applyTheme(next);
    });
</script>
</body>
</html>
