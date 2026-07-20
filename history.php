<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

$userLeague = $team['league'];
$isAdmin = hasAdminAccess($pdo, (int)$user['id']);

$currentSeason = null;
try {
    $stmtSeason = $pdo->prepare("
        SELECT s.season_number, s.year, s.status, sp.sprint_number, sp.start_year
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed'))
        ORDER BY s.created_at DESC LIMIT 1
    ");
    $stmtSeason->execute([$userLeague]);
    $currentSeason = $stmtSeason->fetch();
} catch (Exception $e) {}

$seasonDisplayYear = null;
if ($currentSeason && isset($currentSeason['start_year'], $currentSeason['season_number'])) {
    $seasonDisplayYear = (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1;
} elseif ($currentSeason && isset($currentSeason['year'])) {
    $seasonDisplayYear = (int)$currentSeason['year'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <meta name="theme-color" content="#fc0025" />
  <title>Histórico - FBA Manager</title>

  <?php include __DIR__ . '/includes/head-pwa.php'; ?>

  <link rel="icon" type="image/png" href="/img/fba-logo.png?v=3" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <!-- Bootstrap só para modais -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />

  <style>
    /* ── Tokens ──────────────────────────────────── */
    :root {
      --red:        #fc0025;
      --red-soft:   color-mix(in srgb, var(--red) 10%, transparent);
      --red-glow:   color-mix(in srgb, var(--red) 18%, transparent);
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
      --purple:     #a855f7;
      --sidebar-w:  260px;
      --font:       'Montserrat', sans-serif;
      --radius:     14px;
      --radius-sm:  10px;
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
      --border-red: color-mix(in srgb, var(--red) 18%, transparent);
      --text: #111217;
      --text-2: #5b6270;
      --text-3: #657080;
    }

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

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

    /* ── Shell ───────────────────────────────────── */
    .app { display: flex; min-height: 100vh; }

    /* ── Sidebar ─────────────────────────────────── */
    .sidebar {
      position: fixed; top: 0; left: 0;
      width: 260px; height: 100vh;
      background: var(--panel); border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      z-index: 300; transition: transform var(--t) var(--ease);
      overflow-y: auto; scrollbar-width: none;
    }
    .sidebar::-webkit-scrollbar { display: none; }

    .sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
    .sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
    .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
    .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }

    .sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
    .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
    .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }

    .sb-season { margin: 10px 14px 0; background: var(--red-soft); border: 1px solid var(--border-red); border-radius: 8px; padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .sb-season-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); }
    .sb-season-val { font-size: 14px; font-weight: 700; color: var(--red); }

    .sb-nav { flex: 1; padding: 12px 10px 8px; }
    .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
    .sb-nav a { font-family:'Inter',sans-serif; display: flex; align-items: center; gap: 10px; padding: 10px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
    .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
    .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
    .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
    .sb-nav a.active i { color: var(--red); }

    .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
    .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; flex-shrink: 0; }
    .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

    /* ── Topbar mobile ───────────────────────────── */
    .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 260; }
    .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
    .topbar-title em { color: var(--red); font-style: normal; }
    .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
    .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
    .sb-overlay.show { display: block; }

    /* ── Main ────────────────────────────────────── */
    .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }

    /* ── Hero ────────────────────────────────────── */
    .page-hero { padding: 32px 32px 0; }
    .hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
    .hero-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
    .hero-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }

    /* ── Content ─────────────────────────────────── */
    .content { padding: 20px 32px 48px; flex: 1; }

    /* ── Season card ─────────────────────────────── */
    .season-card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
      margin-bottom: 14px;
      transition: border-color var(--t) var(--ease);
    }
    .season-card:hover { border-color: var(--border-md); }

    .season-head {
      padding: 16px 20px;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
    }
    .season-head-left { display: flex; align-items: center; gap: 12px; }
    .season-icon { width: 36px; height: 36px; border-radius: 9px; background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.2); display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
    .season-title { font-size: 15px; font-weight: 700; }
    .season-sub { font-size: 11px; color: var(--text-2); margin-top: 2px; }
    .season-year-badge { display: inline-flex; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }

    .season-body { padding: 18px 20px; }

    /* ── Awards grid ─────────────────────────────── */
    .awards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 10px;
    }

    .award-chip {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 14px;
      border-radius: var(--radius-sm);
      background: var(--panel-2);
      border: 1px solid var(--border);
      transition: border-color var(--t) var(--ease);
    }
    .award-chip:hover { border-color: var(--border-md); }

    .award-icon {
      width: 36px; height: 36px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 16px; flex-shrink: 0;
    }
    /* variantes de cor */
    .award-icon.gold   { background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.2); }
    .award-icon.silver { background: rgba(148,163,184,.10); border: 1px solid rgba(148,163,184,.15); }
    .award-icon.amber  { background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.2); }
    .award-icon.blue   { background: rgba(59,130,246,.10); border: 1px solid rgba(59,130,246,.15); }
    .award-icon.green  { background: rgba(34,197,94,.10);  border: 1px solid rgba(34,197,94,.15); }
    .award-icon.purple { background: rgba(168,85,247,.10); border: 1px solid rgba(168,85,247,.15); }
    .award-icon.red    { background: var(--red-soft);      border: 1px solid var(--border-red); }

    .award-label { font-size: 10px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; margin-bottom: 4px; }
    .award-label.gold   { color: var(--amber); }
    .award-label.silver { color: #94a3b8; }
    .award-label.amber  { color: var(--amber); }
    .award-label.blue   { color: var(--blue); }
    .award-label.green  { color: var(--green); }
    .award-label.purple { color: var(--purple); }
    .award-label.red    { color: var(--red); }

    .award-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
    .award-team { font-size: 11px; color: var(--text-2); margin-top: 2px; }

    /* ── Season footer ───────────────────────────── */
    .season-foot {
      padding: 12px 20px;
      border-top: 1px solid var(--border);
      display: flex; align-items: center; justify-content: flex-end;
    }

    /* ── Buttons ─────────────────────────────────── */
    .btn-ghost-sm {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 12px; border-radius: 7px;
      background: transparent; border: 1px solid var(--border); color: var(--text-2);
      font-family: var(--font); font-size: 12px; font-weight: 600;
      cursor: pointer; transition: all var(--t) var(--ease);
      text-decoration: none;
    }
    .btn-ghost-sm:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

    /* ── Draft table ─────────────────────────────── */
    .draft-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .draft-table th { font-size: 10px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase; color: var(--text-3); padding: 8px 12px; border-bottom: 1px solid var(--border); text-align: left; }
    .draft-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); color: var(--text-2); vertical-align: middle; }
    .draft-table tr:last-child td { border-bottom: none; }
    .draft-table tr:hover td { background: var(--panel-2); }
    .draft-table .td-player { font-weight: 600; color: var(--text); }

    .pick-pill { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; }
    .pick-pill.r1 { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }
    .pick-pill.r2 { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); }

    .pos-pill { display: inline-flex; padding: 2px 8px; border-radius: 5px; font-size: 10px; font-weight: 800; background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); }

    /* ── Empty / loading states ──────────────────── */
    .state-empty { padding: 40px 20px; text-align: center; color: var(--text-3); }
    .state-empty i { font-size: 36px; display: block; margin-bottom: 12px; }
    .state-empty p { font-size: 13px; max-width: 320px; margin: 0 auto; }

    .spinner { width: 28px; height: 28px; border: 3px solid var(--border-md); border-top-color: var(--red); border-radius: 50%; animation: spin .7s linear infinite; margin: 0 auto; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Skeleton loaders ────────────────────────── */
    .sk { background: linear-gradient(90deg, var(--panel-2) 25%, var(--panel-3) 50%, var(--panel-2) 75%); background-size: 200% 100%; animation: shimmer 1.2s infinite; border-radius: 8px; }
    @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
    .sk-season-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 14px; }
    .sk-season-head { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
    .sk-season-body { padding: 18px 20px; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
    .sk-chip { height: 60px; border-radius: var(--radius-sm); }

    /* ── Modal overrides ─────────────────────────── */
    .modal-content { background: var(--panel); border: 1px solid var(--border-md); border-radius: var(--radius); color: var(--text); font-family: var(--font); }
    .modal-header { border-bottom: 1px solid var(--border); padding: 18px 20px; }
    .modal-header .modal-title { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .modal-header .modal-title i { color: var(--red); }
    .modal-body { padding: 20px; }
    .modal-footer { border-top: 1px solid var(--border); padding: 14px 20px; }

    /* ── Responsive ──────────────────────────────── */
    @media (max-width: 992px) {
      :root { --sidebar-w: 0px; }
      .sidebar { transform: translateX(-260px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; width: 100%; padding-top: 54px; }
      .topbar { display: flex; }
      .page-hero, .content { padding-left: 16px; padding-right: 16px; }
      .page-hero { padding-top: 18px; }
    }
    @media (max-width: 768px) {
      .awards-grid { grid-template-columns: 1fr; }
      .season-head { padding: 14px 16px; }
      .season-body { padding: 14px 16px; }
      .season-foot { padding: 10px 16px; }
    }
    /* classes usadas pelo history.js */
    .text-orange       { color: var(--red) !important; }
    .bg-dark-secondary { background: var(--panel-2); }
    .btn-outline-orange {
      border: 1px solid var(--red); color: var(--red);
      background: transparent; border-radius: 8px; font-size: .82rem;
      padding: 4px 12px; font-family: var(--font); cursor: pointer;
      transition: background .15s, color .15s;
    }
    .btn-outline-orange:hover { background: var(--red-soft); }
  <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>
<div class="app">

  <!-- ══════════════════════════════════════════════
       SIDEBAR
  ══════════════════════════════════════════════ -->
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <!-- Overlay mobile -->
  <div class="sb-overlay" id="sbOverlay"></div>

  <!-- Topbar mobile -->
  <header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Manager</em></div>
    <?php if ($currentSeason): ?>
    <span style="font-size:11px;font-weight:700;color:var(--red)"><?= $seasonDisplayYear ?></span>
    <?php endif; ?>
  </header>

  <!-- ══════════════════════════════════════════════
       MAIN
  ══════════════════════════════════════════════ -->
  <main class="main">

    <div class="page-hero">
      <div class="hero-eyebrow">Liga · <?= htmlspecialchars($userLeague) ?></div>
      <h1 class="hero-title">Histórico de Temporadas</h1>
      <p class="hero-sub">Campeões, premiações e ordem do draft por sprint</p>
    </div>

    <div class="content">
      <div id="historyContainer" data-league="<?= htmlspecialchars($userLeague) ?>">
        <?php for ($i = 0; $i < 3; $i++): ?>
        <div class="sk-season-card">
          <div class="sk-season-head">
            <div class="sk" style="width:36px;height:36px;border-radius:9px"></div>
            <div class="sk" style="width:120px;height:16px"></div>
          </div>
          <div class="sk-season-body">
            <div class="sk sk-chip"></div><div class="sk sk-chip"></div>
            <div class="sk sk-chip"></div><div class="sk sk-chip"></div>
          </div>
        </div>
        <?php endfor; ?>
      </div>
    </div>

  </main>
</div><!-- .app -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const sidebar   = document.getElementById('sidebar');
  const sbOverlay = document.getElementById('sbOverlay');
  const menuBtn   = document.getElementById('menuBtn');
  function openSidebar()  { sidebar.classList.add('open'); sbOverlay.classList.add('show'); }
  function closeSidebar() { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); }
  if (menuBtn)   menuBtn.addEventListener('click', openSidebar);
  if (sbOverlay) sbOverlay.addEventListener('click', closeSidebar);

  // Theme
  const themeKey = 'fba-theme';
  const themeToggle = document.getElementById('themeToggle');
  const applyTheme = (theme) => {
    if (theme === 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
      if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
      return;
    }
    document.documentElement.removeAttribute('data-theme');
    if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
  };
  applyTheme(localStorage.getItem(themeKey) || 'dark');
  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      localStorage.setItem(themeKey, next);
      applyTheme(next);
    });
  }
</script>
<script src="/js/history.js" defer></script>
<script src="/js/pwa.js"></script>
</body>
</html>
