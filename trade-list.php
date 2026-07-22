<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;
$teamId = $team['id'] ?? null;
$league = $team['league'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Trade List — FBA Manager</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#07070a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/icon-192.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ── Tokens ──────────────────────────────────── */
        :root {
            --red:        #fc0025;
            --red-soft:   color-mix(in srgb, var(--red) 10%, transparent);
            --bg:         #07070a;
            --panel:      #101013;
            --panel-2:    #16161a;
            --panel-3:    #1c1c21;
            --border:     rgba(255,255,255,.06);
            --border-md:  rgba(255,255,255,.10);
            --border-red: color-mix(in srgb, var(--red) 22%, transparent);
            --text:       #f0f0f3;
            --text-2:     #868690;
            --text-3:     #7d7d85;
            --green:      #22c55e;
            --amber:      #f59e0b;
            --blue:       #3b82f6;
            --font:       'Montserrat', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
            /* Legado (usado pelo trade-list.js via var()) */
            --fba-card-bg:    #16161a;
            --fba-border:     rgba(255,255,255,.06);
            --fba-text:       #f0f0f3;
            --fba-text-muted: #868690;
            --fba-dark-bg:    #1c1c21;
            --fba-orange:     var(--red);
        }
        :root[data-theme="light"] {
            --bg:         #f6f7fb;
            --panel:      #ffffff;
            --panel-2:    #f2f4f8;
            --panel-3:    #e9edf4;
            --border:     #e3e6ee;
            --border-md:  #d7dbe6;
            --border-red: color-mix(in srgb, var(--red) 18%, transparent);
            --text:       #111217;
            --text-2:     #5b6270;
            --text-3:     #657080;
            --fba-card-bg:    #f2f4f8;
            --fba-border:     #e3e6ee;
            --fba-text:       #111217;
            --fba-text-muted: #5b6270;
            --fba-dark-bg:    #e9edf4;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; min-height: 100vh; }

        /* ── Layout ──────────────────────────────────── */
        .app-wrap { max-width: 960px; margin: 0 auto; padding: 24px 20px 56px; }

        /* ── Page header ──────────────────────────────── */
        .page-head { margin-bottom: 22px; }
        .page-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--red); margin-bottom: 6px; }
        .page-title { font-size: 1.5rem; font-weight: 800; margin-bottom: 4px; }
        .page-sub { font-size: 13px; color: var(--text); }

        /* ── Panel card ──────────────────────────────── */
        .panel-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .panel-card-head {
            padding: 14px 18px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .panel-card-title { font-size: 13px; font-weight: 700; }
        .panel-card-body { padding: 16px 18px; }

        /* ── Count badge ─────────────────────────────── */
        .count-badge {
            display: inline-flex; padding: 4px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 700;
            background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border);
        }

        /* ── Search / Sort ───────────────────────────── */
        .search-input, .sort-select {
            background: var(--panel-2); border: 1px solid var(--border-md);
            border-radius: 8px; padding: 10px 12px;
            color: var(--text); font-family: var(--font); font-size: 13px;
            outline: none; transition: border-color var(--t) var(--ease); width: 100%;
        }
        .search-input:focus, .sort-select:focus { border-color: var(--red); }
        .search-input::placeholder { color: var(--text-3); }
        .sort-select option { background: var(--panel-2); }

        /* ── Player cards (renderizados pelo trade-list.js) ── */
        .player-card {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            margin-bottom: 8px;
            transition: border-color var(--t) var(--ease);
        }
        .player-card:last-child { margin-bottom: 0; }
        .player-card:hover { border-color: rgba(255,255,255,.14); }

        .player-name { font-weight: 600; color: var(--text); font-size: 14px; margin-bottom: 4px; }
        .player-meta { font-size: 12px; color: var(--text-2); }

        .team-chip {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--panel-3); border: 1px solid var(--border);
            padding: 6px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 500; color: var(--text-2);
            white-space: nowrap; flex-shrink: 0;
        }
        .team-chip img { width: 22px; height: 22px; border-radius: 50%; object-fit: cover; }
        .team-chip-badge {
            width: 22px; height: 22px; border-radius: 50%;
            background: var(--red-soft); border: 1px solid var(--border-red);
            display: grid; place-items: center;
            font-size: 9px; font-weight: 800; color: var(--red); flex-shrink: 0;
        }

        /* ── Loading spinner (Bootstrap override) ────── */
        .spinner-border { color: var(--red) !important; }

        /* ── Alert overrides (Bootstrap) ─────────────── */
        .alert { border-radius: var(--radius-sm) !important; font-size: 13px !important; font-family: var(--font) !important; }
        .alert-danger { background: rgba(239,68,68,.09) !important; border-color: rgba(239,68,68,.2) !important; color: #ef4444 !important; }
        .alert-info   { background: rgba(59,130,246,.09) !important; border-color: rgba(59,130,246,.2) !important; color: var(--blue) !important; }

        /* ── Empty / loading state ────────��──────────── */
        .state-empty { padding: 28px 16px; text-align: center; color: var(--text-3); font-size: 13px; }

        /* ── Responsive ──────────────────────────────── */
        @media (max-width: 768px) {
            .app-wrap { padding: 16px 14px 40px; }
            .player-card > .d-flex { flex-direction: column; gap: 10px; align-items: flex-start !important; }
            .team-chip { align-self: flex-start; }
        }
    input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible,[tabindex]:focus-visible{outline:2px solid var(--red, #fc0025);outline-offset:2px;}
     (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; animation-delay: 0ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; transition-delay: 0ms !important; scroll-behavior: auto !important; } }

    /* ── Shell padrão (sidebar + main + topbar mobile) ── */
    :root { --sidebar-w: 260px; }
    .app { display: flex; min-height: 100vh; }
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
    .sb-theme-toggle{margin:10px 14px;display:flex;align-items:center;gap:8px;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:9px 12px;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;flex-shrink:0}
    .sb-theme-toggle:hover{color:var(--text)}
    .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
    .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; flex-shrink: 0; }
    .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }
    .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 260; }
    .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
    .topbar-title em { color: var(--red); font-style: normal; }
    .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
    .icon-btn{width:34px;height:34px;border-radius:10px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:15px;cursor:pointer;text-decoration:none;transition:all .2s}
    .icon-btn:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
    .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
    .sb-overlay.show { display: block; }
    .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); }
    @media (max-width: 992px) {
        :root { --sidebar-w: 0px; }
        .sidebar { transform: translateX(-260px); }
        .sidebar.open { transform: translateX(0); }
        .main { margin-left: 0; width: 100%; padding-top: 54px; }
        .topbar { display: flex; }
    }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>
<div class="app">

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div class="topbar-title">Trade <em>List</em></div>
  <a href="trades.php" class="icon-btn"><i class="bi bi-arrow-left"></i></a>
</header>

<main class="main">
<div class="app-wrap">

    <div class="page-head">
        <div class="page-eyebrow">Trades · <?= htmlspecialchars($league) ?></div>
        <h1 class="page-title">Trade List</h1>
        <p class="page-sub">Jogadores de outros times disponíveis para negociação na sua liga.</p>
    </div>

    <div class="panel-card">
        <div class="panel-card-head">
            <div class="panel-card-title">Jogadores</div>
            <span class="count-badge" id="countBadge">—</span>
        </div>
        <div class="panel-card-body">
            <div class="d-flex flex-column flex-md-row gap-2 mb-4">
                <input type="text" id="searchInput" class="search-input" placeholder="Procurar por nome…">
                <select id="sortSelect" class="sort-select" style="max-width:240px">
                    <option value="ovr_desc">OVR (Maior primeiro)</option>
                    <option value="ovr_asc">OVR (Menor primeiro)</option>
                    <option value="name_asc">Nome (A–Z)</option>
                    <option value="name_desc">Nome (Z–A)</option>
                    <option value="age_asc">Idade (Menor primeiro)</option>
                    <option value="age_desc">Idade (Maior primeiro)</option>
                    <option value="position_asc">Posição (A–Z)</option>
                    <option value="position_desc">Posição (Z–A)</option>
                    <option value="team_asc">Time (A–Z)</option>
                    <option value="team_desc">Time (Z–A)</option>
                </select>
            </div>
            <div id="playersList">
                <div class="state-empty">Carregando…</div>
            </div>
        </div>
    </div>

</div><!-- .app-wrap -->
</main>
</div><!-- .app -->

<script>
window.__USER_LEAGUE__ = '<?= htmlspecialchars($league, ENT_QUOTES) ?>';
(function(){
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sbOverlay');
  const menuBtn = document.getElementById('menuBtn');
  if (menuBtn) menuBtn.addEventListener('click', () => { sidebar?.classList.add('open'); overlay?.classList.add('show'); });
  if (overlay) overlay.addEventListener('click', () => { sidebar?.classList.remove('open'); overlay.classList.remove('show'); });
  const themeToggle = document.getElementById('themeToggle');
  const themeKey = 'fba-theme';
  const applyTheme = (t) => {
    if (t === 'light') { document.documentElement.setAttribute('data-theme','light'); if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>'; return; }
    document.documentElement.removeAttribute('data-theme'); if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
  };
  applyTheme(localStorage.getItem(themeKey) || 'dark');
  if (themeToggle) themeToggle.addEventListener('click', () => {
    const cur = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    const next = cur === 'light' ? 'dark' : 'light';
    localStorage.setItem(themeKey, next); applyTheme(next);
  });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/trade-list.js"></script>
<script src="/js/pwa.js"></script>
</body>
</html>
