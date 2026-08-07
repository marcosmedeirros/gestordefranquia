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
    <title>Drafts Aleatórios — FBA Manager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
    <style>
        :root {
            --red: #fc0025; --red-2: color-mix(in srgb, var(--red) 85%, white); --red-soft: color-mix(in srgb, var(--red) 10%, transparent);
            --bg: #07070a; --panel: #101013; --panel-2: #16161a; --panel-3: #1c1c21;
            --border: rgba(255,255,255,.06); --border-md: rgba(255,255,255,.10); --border-red: color-mix(in srgb, var(--red) 22%, transparent);
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
        .hero-sub { font-size: 13px; color: var(--text-2); max-width: 640px; }
        .hero-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .content { padding: 20px 32px 48px; flex: 1; }
        .topbar { display: none; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); padding: 0 16px; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 200; }
        .topbar-title { font-size: 15px; font-weight: 700; color: var(--text); }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { background: transparent; border: 1px solid var(--border); color: var(--text); width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 299; }
        .sb-overlay.show { display: block; }

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
        .btn-ghost { background: transparent; border: 1px solid var(--border-md); color: var(--text-2); font-weight: 600; font-size: 12px; border-radius: var(--radius-xs); padding: 6px 12px; transition: all var(--t); cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }
        .dc-btn-del:hover { border-color: rgba(239,68,68,.45); color: #ef4444; background: rgba(239,68,68,.08); }

        .empty { padding: 40px 16px; color: var(--text-3); text-align: center; grid-column: 1/-1; }
        .empty i { font-size: 32px; display: block; margin-bottom: 10px; }
        .empty p { font-size: 13px; margin: 0; }

        .dc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
        .dc-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); transition: all var(--t) var(--ease); display: flex; flex-direction: column; }
        .dc-card:hover { border-color: var(--border-red); transform: translateY(-2px); }
        .dc-card-main { padding: 18px; cursor: pointer; display: flex; flex-direction: column; gap: 10px; flex: 1; }
        .dc-card-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 19px; background: rgba(168,85,247,.12); color: #a855f7; }
        .dc-card-title { font-size: 15px; font-weight: 700; color: var(--text); line-height: 1.25; }
        .dc-card-sub { font-size: 11px; color: var(--text-3); }
        .dc-card-progress { height: 5px; border-radius: 999px; background: var(--panel-3); overflow: hidden; margin-top: 2px; }
        .dc-card-progress > div { height: 100%; background: var(--red); }
        .dc-card-status { font-size: 10px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; padding: 3px 9px; border-radius: 999px; align-self: flex-start; }
        .dc-card-actions { display: flex; gap: 6px; padding: 10px 14px; border-top: 1px solid var(--border); }
        .dc-card-actions .btn-ghost { padding: 5px 10px; font-size: 11px; }
        .dc-card-new { border: 1.5px dashed var(--border-md); background: transparent; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: var(--text-2); min-height: 160px; cursor: pointer; }
        .dc-card-new:hover { border-color: var(--red); color: var(--red); background: var(--red-soft); }
        .dc-card-new i { font-size: 26px; }

        .dc-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 500; align-items: center; justify-content: center; padding: 20px; }
        .dc-modal-overlay.open { display: flex; }
        .dc-modal { background: var(--panel); border: 1px solid var(--border-md); border-radius: var(--radius); padding: 22px; max-width: 460px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,.5); }
        .dc-modal-title { font-size: 16px; font-weight: 800; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
        .dc-modal-title i { color: var(--red); }
        .dc-modal-sub { font-size: 12.5px; color: var(--text-2); margin-bottom: 14px; }
        .dc-roleta-item { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 11px 14px; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text); cursor: pointer; margin-bottom: 8px; text-align: left; transition: all var(--t); }
        .dc-roleta-item:hover { border-color: var(--border-red); background: var(--red-soft); }
        .dc-roleta-nome { font-size: 13px; font-weight: 700; }
        .dc-roleta-meta { font-size: 11px; color: var(--text-3); }
        .dc-ac-empty { padding: 12px 4px; font-size: 12.5px; color: var(--text-3); line-height: 1.5; }
        .dc-modal-actions { display: flex; justify-content: flex-end; margin-top: 12px; }

        input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible,[tabindex]:focus-visible{outline:2px solid var(--red, #fc0025);outline-offset:2px;}
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; } }
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
<body>
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
                <h1 class="hero-title"><i class="bi bi-shuffle" style="color:var(--red)"></i>Drafts Aleatórios</h1>
                <p class="hero-sub">Cada draft nasce da ordem sorteada numa roleta concluída. Qualquer pessoa logada registra as escolhas; pular, desfazer e finalizar funcionam igual ao Draft de Lendas.</p>
            </div>
            <div class="hero-actions">
                <a class="btn-ghost" href="/admin.php#gestao"><i class="bi bi-arrow-left me-1"></i>Voltar ao Admin</a>
                <button type="button" class="btn-orange" id="btnNovoDraftTop"><i class="bi bi-plus-lg me-1"></i>Novo Draft</button>
            </div>
        </div>

        <div class="content">
            <div class="dc-grid" id="draftsGrid">
                <div class="empty"><p>Carregando...</p></div>
            </div>
        </div>
    </main>
</div>

<div class="dc-modal-overlay" id="modalNovoDraft">
    <div class="dc-modal">
        <div class="dc-modal-title"><i class="bi bi-shuffle"></i> Criar draft de uma roleta</div>
        <p class="dc-modal-sub">Escolha uma roleta já sorteada por completo. A ordem dela vira a ordem do draft.</p>
        <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text-2);margin-bottom:12px;cursor:pointer">
            <input type="checkbox" id="dcModoTimeNba" style="width:15px;height:15px">
            Sorteio de marca da ROOKIE — cada um escolhe um time da NBA (sem repetir) em vez de digitar um nome
        </label>
        <div id="dcRoletasLista"></div>
        <div class="dc-modal-actions">
            <button type="button" class="btn-ghost" onclick="dcFecharModalNovo()">Fechar</button>
        </div>
    </div>
</div>

<script>
    const sidebar = document.getElementById('sidebar');
    const sbOverlay = document.getElementById('sbOverlay');
    const menuBtn = document.getElementById('menuBtn');
    if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); sbOverlay.classList.add('show'); });
    if (sbOverlay) sbOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); });
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
<script src="<?= assetUrl('/js/drafts-aleatorios.js') ?>"></script>
<script src="<?= assetUrl('/js/pwa.js') ?>"></script>
</body>
</html>
