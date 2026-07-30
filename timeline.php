<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

$team_id = $_SESSION['team_id'] ?? null;
$team = [];
if ($team_id) {
    $stmt = $pdo->prepare("SELECT id, name, city, photo_url, league FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch() ?: [];
}
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
    <title>Timeline — FBA Manager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
    <style>
        :root {
            --red: #fc0025; --red-2: color-mix(in srgb, var(--red) 85%, white); --red-soft: color-mix(in srgb, var(--red) 10%, transparent); --red-glow: color-mix(in srgb, var(--red) 18%, transparent);
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
        .page-hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin: 32px 32px 4px; }
        .page-hero-title { font-size: 26px; font-weight: 800; color: var(--text); margin: 0 32px 4px; display: flex; align-items: center; gap: 10px; }
        .page-hero-sub { font-size: 13px; color: var(--text-2); margin: 0 32px 18px; max-width: 640px; }
        .content { padding: 0 32px 48px; flex: 1; }
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

        /* Coluna central estreita, tipo feed de rede social — Posts (grid) usa
           uma variante mais larga, já que grid precisa de mais espaço. */
        .tl-col { max-width: 470px; margin: 0 auto; }
        .tl-col-wide { max-width: 720px; margin: 0 auto; }

        .tl-tabs { display: flex; justify-content: center; gap: 4px; margin-bottom: 20px; background: var(--panel); border: 1px solid var(--border); border-radius: 999px; padding: 4px; max-width: 470px; margin-left: auto; margin-right: auto; }
        .tl-tab { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; background: transparent; border: none; border-radius: 999px; padding: 9px 10px; color: var(--text-3); font-family: var(--font); font-size: 12.5px; font-weight: 700; cursor: pointer; white-space: nowrap; transition: all var(--t); }
        .tl-tab i { font-size: 15px; }
        .tl-tab:hover { color: var(--text); }
        .tl-tab.active { background: var(--red); color: #fff; box-shadow: 0 4px 14px -4px var(--red-glow); }
        .tl-hidden { display: none !important; }

        .tl-chips { display: flex; justify-content: center; gap: 8px; overflow-x: auto; margin-bottom: 16px; padding-bottom: 2px; }
        .tl-chip { background: var(--panel); border: 1px solid var(--border); border-radius: 999px; padding: 7px 14px; color: var(--text-2); font-family: var(--font); font-size: 11.5px; font-weight: 700; letter-spacing: .3px; cursor: pointer; white-space: nowrap; transition: all var(--t); }
        .tl-chip:hover { color: var(--text); }
        .tl-chip.active { background: var(--red); border-color: var(--red); color: #fff; }

        /* Barra de stories — anel gradiente pra quem tem story não vista,
           anel neutro pra já vista. Igual qualquer rede social. */
        .tl-stories { display: flex; gap: 14px; overflow-x: auto; padding: 2px 2px 14px; margin-bottom: 6px; scrollbar-width: none; }
        .tl-stories::-webkit-scrollbar { display: none; }
        .tl-story { background: none; border: none; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; gap: 6px; cursor: pointer; font-family: var(--font); width: 66px; }
        .tl-story-ring { width: 62px; height: 62px; border-radius: 50%; padding: 2.5px; display: flex; background: var(--border-md); flex-shrink: 0; }
        .tl-story-ring.unseen { background: linear-gradient(135deg, var(--red), var(--amber)); }
        .tl-story-ring img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 2.5px solid var(--bg-story, var(--bg)); background: var(--panel-3); }
        .tl-story-name { font-size: 10.5px; color: var(--text-2); max-width: 66px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: center; }

        /* Post — cabeçalho enxuto, foto de ponta a ponta, ações com ícones e
           "N curtidas" em linha própria, legenda com o nome em negrito na
           frente (padrão clássico de rede social). */
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); margin-bottom: 16px; overflow: hidden; }
        .card-head { display: flex; align-items: center; gap: 10px; padding: 12px 14px; }
        .card-head img { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; background: var(--panel-3); flex-shrink: 0; border: 1px solid var(--border-md); }
        .card-author { font-size: 13.5px; font-weight: 700; text-decoration: none; color: inherit; }
        .card-league { font-size: 10px; color: var(--text-3); font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
        .card-time { font-size: 11px; color: var(--text-3); margin-left: auto; white-space: nowrap; }
        .card-photo { width: 100%; max-height: 520px; object-fit: cover; display: block; background: var(--panel-2); }
        .card-body { padding: 12px 14px 14px; }
        .card-actions { display: flex; align-items: center; gap: 18px; margin-bottom: 8px; }
        .like-btn { display: inline-flex; align-items: center; gap: 0; background: none; border: none; cursor: pointer; font-size: 22px; color: var(--text); font-family: inherit; padding: 2px 0; line-height: 1; transition: transform .15s; }
        .like-btn:active { transform: scale(1.25); }
        .like-btn.liked { color: var(--red); }
        .card-likes { font-size: 13px; font-weight: 700; margin-bottom: 4px; }
        .card-caption { font-size: 13.5px; line-height: 1.5; margin-bottom: 6px; }
        .card-caption b { font-weight: 700; margin-right: 5px; }
        .del-btn { margin-left: auto; background: none; border: none; color: var(--text-3); cursor: pointer; font-size: 16px; }

        /* Eventos automáticos: discretos de propósito, pra não competir com
           post de verdade — lê como "atividade do sistema", não conteúdo. */
        .auto-row { display: flex; align-items: center; gap: 10px; padding: 9px 4px; margin-bottom: 2px; }
        .auto-icon { width: 26px; height: 26px; border-radius: 50%; background: var(--panel-2); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; }
        .auto-text { font-size: 12.5px; color: var(--text-3); }
        .auto-text b { color: var(--text-2); font-weight: 600; }
        .auto-time { font-size: 10.5px; color: var(--text-3); margin-left: auto; white-space: nowrap; flex-shrink: 0; opacity: .8; }

        .tl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 3px; }
        .tl-grid-item { position: relative; aspect-ratio: 1; overflow: hidden; background: var(--panel-2); border: none; cursor: pointer; }
        .tl-grid-item img { width: 100%; height: 100%; object-fit: cover; transition: transform .2s; }
        .tl-grid-item:hover img { transform: scale(1.04); }
        .tl-grid-item .no-photo { display: flex; align-items: center; justify-content: center; height: 100%; padding: 10px; font-size: 11.5px; color: var(--text-3); text-align: center; background: var(--panel-2); }

        .btn-orange { background: var(--red); border: none; color: #fff; font-weight: 700; font-size: 13px; border-radius: 999px; padding: 9px 20px; transition: background var(--t); cursor: pointer; }
        .btn-orange:hover { background: var(--red-2); }
        .btn-orange:disabled { background: var(--panel-3); color: var(--text-3); cursor: not-allowed; }
        .btn-ghost { background: var(--panel-2); border: 1px solid var(--border-md); color: var(--text); font-weight: 700; font-size: 12.5px; border-radius: 999px; padding: 8px 16px; transition: all var(--t); cursor: pointer; }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }
        .btn-ghost.following { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

        .tl-search-input { width: 100%; background: var(--panel); border: 1px solid var(--border); color: var(--text); border-radius: 999px; padding: 12px 18px; font-family: var(--font); font-size: 13.5px; margin-bottom: 18px; }
        .tl-search-input::placeholder { color: var(--text-3); }
        .tl-search-item { display: flex; align-items: center; gap: 12px; padding: 11px 12px; border-radius: var(--radius-sm); background: var(--panel); border: 1px solid var(--border); margin-bottom: 8px; cursor: pointer; text-decoration: none; color: inherit; transition: border-color var(--t); }
        .tl-search-item:hover { border-color: var(--border-red); }
        .tl-search-item img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; background: var(--panel-3); flex-shrink: 0; }

        /* Perfil — cabeçalho estilo perfil de rede social: avatar grande à
           esquerda, nome/estatísticas/seguir à direita. */
        .tl-profile-header { display: flex; align-items: center; gap: 22px; margin-bottom: 22px; flex-wrap: wrap; }
        .tl-profile-avatar { width: 88px; height: 88px; border-radius: 50%; object-fit: cover; background: var(--panel-2); border: 1px solid var(--border-md); flex-shrink: 0; }
        .tl-profile-name { font-size: 19px; font-weight: 800; }
        .tl-profile-sub { font-size: 12px; color: var(--text-3); font-weight: 600; text-transform: uppercase; letter-spacing: .3px; margin-top: 2px; }
        .tl-profile-stats { display: flex; gap: 22px; font-size: 13px; color: var(--text-2); margin-top: 10px; }
        .tl-profile-stats b { color: var(--text); display: block; font-size: 15px; font-weight: 800; }
        .empty { text-align: center; padding: 30px 16px; color: var(--text-3); font-size: 13px; }

        input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible,[tabindex]:focus-visible{outline:2px solid var(--red, #fc0025);outline-offset:2px;}
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; } }
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .page-hero-eyebrow, .page-hero-title, .page-hero-sub { margin-left: 16px; margin-right: 16px; }
            .content { padding: 0 16px 48px; }
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
            <div class="topbar-title">FBA <em>Timeline</em></div>
        </header>

        <div class="page-hero-eyebrow">FBA · Comunidade</div>
        <h1 class="page-hero-title"><i class="bi bi-collection-play-fill" style="color:var(--red)"></i>Timeline</h1>
        <p class="page-hero-sub">O que está rolando entre todos os GMs — de qualquer liga. Poste no seu perfil, siga outros times e acompanhe o feed.</p>

        <div class="content">
            <div class="tl-tabs" id="tlTabs">
                <button class="tl-tab active" data-tab="feed"><i class="bi bi-house-door-fill"></i> Feed</button>
                <button class="tl-tab" data-tab="perfil"><i class="bi bi-person-circle"></i> Perfil</button>
                <button class="tl-tab" data-tab="posts"><i class="bi bi-grid-3x3-gap-fill"></i> Posts</button>
                <button class="tl-tab" data-tab="pesquisar"><i class="bi bi-search"></i> Buscar</button>
            </div>

            <div data-tl-tab="feed">
                <div class="tl-col">
                    <div class="tl-chips" id="tlFeedChips">
                        <button class="tl-chip active" data-league="">Todas</button>
                        <button class="tl-chip" data-league="ELITE">ELITE</button>
                        <button class="tl-chip" data-league="NEXT">NEXT</button>
                        <button class="tl-chip" data-league="RISE">RISE</button>
                    </div>
                    <div class="tl-stories" id="tlStoriesBar"></div>
                    <div id="tlFeedList"><div class="empty">Carregando...</div></div>
                    <button type="button" class="btn-ghost" id="tlFeedMore" style="display:none;width:100%">Carregar mais</button>
                </div>
            </div>

            <div data-tl-tab="perfil" class="tl-hidden">
                <div class="tl-col">
                    <div id="tlPerfilBox"><div class="empty">Carregando...</div></div>
                </div>
            </div>

            <div data-tl-tab="posts" class="tl-hidden">
                <div class="tl-col-wide">
                    <div class="tl-chips" id="tlPostsChips">
                        <button class="tl-chip active" data-league="">Todas</button>
                        <button class="tl-chip" data-league="ELITE">ELITE</button>
                        <button class="tl-chip" data-league="NEXT">NEXT</button>
                        <button class="tl-chip" data-league="RISE">RISE</button>
                    </div>
                    <div class="tl-grid" id="tlPostsGrid"></div>
                </div>
            </div>

            <div data-tl-tab="pesquisar" class="tl-hidden">
                <div class="tl-col">
                    <input type="text" id="tlSearchInput" class="tl-search-input" placeholder="Buscar time ou GM...">
                    <div id="tlSearchResults"></div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    window.MY_TEAM_ID = <?= (int)($team['id'] ?? 0) ?>;
</script>
<script src="<?= assetUrl('/js/timeline.js') ?>"></script>
<script src="/js/pwa.js"></script>
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
</body>
</html>
