<?php
session_start();
require_once 'backend/config.php';
require_once 'backend/db.php';
$pdo = db();
require_once 'backend/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$is_global_admin = ($_SESSION['user_type'] ?? 'jogador') === 'admin';
$admin_leagues = $is_global_admin ? ['ELITE', 'NEXT', 'RISE', 'ROOKIE'] : getAdminLeagues($pdo, (int)$user_id);

if (empty($admin_leagues)) {
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
    <title>Roleta — FBA Manager</title>
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
            --green: #22c55e; --amber: #f59e0b; --blue: #3b82f6;
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
        .page-hero { padding: 32px 32px 0; }
        .hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .hero-title { font-size: 26px; font-weight: 800; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 10px; }
        .hero-sub { font-size: 13px; color: var(--text-2); }
        .content { padding: 24px 32px 48px; flex: 1; }
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

        /* League tabs */
        .league-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
        .league-tab { font-family: var(--font); background: var(--panel); border: 1px solid var(--border); color: var(--text-2); font-size: 12px; font-weight: 700; padding: 8px 16px; border-radius: 999px; cursor: pointer; transition: all var(--t) var(--ease); }
        .league-tab:hover { border-color: var(--border-red); color: var(--text); }
        .league-tab.active { background: var(--red-soft); border-color: var(--border-red); color: var(--red); }

        .grid-2 { display: grid; grid-template-columns: 1.1fr .9fr; gap: 20px; align-items: start; }
        @media (max-width: 992px) { .grid-2 { grid-template-columns: 1fr; } }

        .panel-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 16px; }
        .panel-card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .panel-card-title { font-size: 14px; font-weight: 600; color: var(--text); }
        .panel-card-icon { color: var(--red); font-size: 15px; }
        .panel-card-body { padding: 20px; }

        .form-control { background: var(--panel-2); border: 1px solid var(--border); color: var(--text); border-radius: var(--radius-xs); font-size: 13px; }
        .form-control::placeholder { color: var(--text-3); }
        .form-control:focus { background: var(--panel-2); border-color: var(--red); color: var(--text); box-shadow: 0 0 0 3px var(--red-soft); }

        .btn-orange { background: var(--red); border: none; color: #fff; font-weight: 600; font-size: 13px; border-radius: var(--radius-xs); padding: 8px 18px; transition: background var(--t); }
        .btn-orange:hover, .btn-orange:focus { background: var(--red-2); color: #fff; }
        .btn-orange:disabled { background: var(--panel-3); color: var(--text-3); cursor: not-allowed; }
        .btn-ghost { background: transparent; border: 1px solid var(--border-md); color: var(--text-2); font-weight: 600; font-size: 12px; border-radius: var(--radius-xs); padding: 6px 12px; transition: all var(--t); }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

        .empty { padding: 24px 16px; color: var(--text-3); text-align: center; }
        .empty i { font-size: 28px; display: block; margin-bottom: 8px; }
        .empty p { font-size: 12px; margin: 0; }

        /* Chips do pool */
        .pool-list { display: flex; flex-wrap: wrap; gap: 8px; }
        .pool-chip { display: inline-flex; align-items: center; gap: 8px; background: var(--panel-2); border: 1px solid var(--border); border-radius: 999px; padding: 6px 8px 6px 14px; font-size: 13px; font-weight: 600; color: var(--text); }
        .pool-chip button { background: transparent; border: none; color: var(--text-3); width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; transition: all var(--t); }
        .pool-chip button:hover { background: var(--red-soft); color: var(--red); }

        /* Histórico */
        .hist-list { display: flex; flex-direction: column; gap: 8px; }
        .hist-row { display: flex; align-items: center; gap: 12px; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px 14px; }
        .hist-order { width: 26px; height: 26px; border-radius: 50%; background: var(--panel-3); border: 1px solid var(--border); color: var(--text-3); font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .hist-year { font-size: 14px; font-weight: 700; color: var(--text); flex: 1; }
        .hist-time { font-size: 11px; color: var(--text-3); }

        /* Roleta */
        .wheel-wrap { display: flex; flex-direction: column; align-items: center; gap: 22px; padding: 8px 0 4px; }
        .wheel-stage { position: relative; width: min(340px, 80vw); height: min(340px, 80vw); }
        .wheel-pointer { position: absolute; top: -6px; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 14px solid transparent; border-right: 14px solid transparent; border-top: 22px solid var(--red); z-index: 5; filter: drop-shadow(0 2px 4px rgba(0,0,0,.4)); }
        .wheel {
            width: 100%; height: 100%; border-radius: 50%; position: relative; overflow: hidden;
            border: 6px solid var(--panel-3); box-shadow: 0 0 0 2px var(--border-md), 0 20px 50px -20px rgba(0,0,0,.6);
            transition: transform 4.2s cubic-bezier(.12,.72,.15,1);
        }
        .wheel-hub { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); width: 46px; height: 46px; border-radius: 50%; background: var(--panel); border: 3px solid var(--red); z-index: 4; display: flex; align-items: center; justify-content: center; color: var(--red); font-size: 16px; }
        .wheel-seg-label { position: absolute; top: 50%; left: 50%; width: 50%; height: 2px; transform-origin: 0 50%; display: flex; align-items: center; justify-content: flex-end; padding-right: 14px; }
        .wheel-seg-label span { font-size: 13px; font-weight: 800; color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,.5); white-space: nowrap; }
        .wheel-empty-msg { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; text-align: center; color: var(--text-3); font-size: 12px; padding: 20px; }

        .winner-banner { background: linear-gradient(135deg, color-mix(in srgb, var(--green) 16%, transparent), transparent); border: 1px solid color-mix(in srgb, var(--green) 35%, transparent); border-radius: var(--radius); padding: 18px 20px; text-align: center; margin-bottom: 16px; }
        .winner-banner .wb-label { font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--green); margin-bottom: 4px; }
        .winner-banner .wb-year { font-size: 32px; font-weight: 800; color: var(--text); }

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
            <div class="hero-eyebrow">Admin · Roleta</div>
            <h1 class="hero-title"><i class="bi bi-record-circle" style="color:var(--red)"></i>Roleta de Eliminação</h1>
            <p class="hero-sub">Sorteio de anos por liga — cada giro elimina um ano, até sobrar só o vencedor.</p>
        </div>

        <div class="content">
            <div class="league-tabs" id="leagueTabs"></div>

            <div id="winnerArea"></div>

            <div class="grid-2">
                <div class="panel-card">
                    <div class="panel-card-header"><i class="bi bi-record-circle panel-card-icon"></i><span class="panel-card-title">Roleta</span></div>
                    <div class="panel-card-body">
                        <div class="wheel-wrap">
                            <div class="wheel-stage">
                                <div class="wheel-pointer"></div>
                                <div class="wheel" id="wheel">
                                    <div class="wheel-empty-msg" id="wheelEmptyMsg">Cadastre pelo menos 2 anos para começar.</div>
                                </div>
                                <div class="wheel-hub"><i class="bi bi-dice-5-fill"></i></div>
                            </div>
                            <button type="button" class="btn btn-orange" id="btnSpin" style="padding:10px 28px;font-size:14px" disabled>
                                <i class="bi bi-arrow-repeat me-1"></i>Girar
                            </button>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="panel-card">
                        <div class="panel-card-header"><i class="bi bi-plus-circle panel-card-icon"></i><span class="panel-card-title">Anos na roleta</span></div>
                        <div class="panel-card-body">
                            <div style="display:flex;gap:8px;margin-bottom:14px">
                                <input type="number" id="yearInput" class="form-control" placeholder="Ex: 2027" style="max-width:140px">
                                <button type="button" class="btn btn-orange" id="btnAddYear"><i class="bi bi-plus-lg"></i></button>
                            </div>
                            <div id="poolList"><p style="color:var(--text-3);font-size:13px">Carregando...</p></div>
                            <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
                                <button type="button" class="btn-ghost" id="btnReset"><i class="bi bi-arrow-counterclockwise me-1"></i>Reiniciar roleta desta liga</button>
                            </div>
                        </div>
                    </div>

                    <div class="panel-card">
                        <div class="panel-card-header"><i class="bi bi-clock-history panel-card-icon"></i><span class="panel-card-title">Histórico de eliminação</span></div>
                        <div class="panel-card-body">
                            <div id="histList"><p style="color:var(--text-3);font-size:13px">Carregando...</p></div>
                            <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
                                <button type="button" class="btn-ghost" id="btnClearHistory"><i class="bi bi-eraser me-1"></i>Limpar histórico</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const adminLeagues = <?= json_encode(array_values($admin_leagues)) ?>;
</script>
<script src="/js/roleta.js"></script>
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
