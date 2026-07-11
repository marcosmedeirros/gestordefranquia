<?php
session_start();
require_once 'backend/auth.php';
require_once 'backend/db.php';
require_once 'backend/helpers.php';

requireAuth();

$user = getUserSession();
$pdo  = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

$userLeague = $team['league'] ?? ($user['league'] ?? 'ELITE');
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <meta name="theme-color" content="#fc0025" />
  <title>Hall da Fama - FBA Manager</title>

  <?php include __DIR__ . '/includes/head-pwa.php'; ?>

  <link rel="icon" type="image/png" href="/img/fba-logo.png?v=3" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />

  <style>
    /* ── Tokens ──────────────────────────────────── */
    :root {
      --red:        #fc0025;
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
      --ease:       cubic-bezier(.2,.8,.2,1);
      --t:          200ms;
    }

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
    .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
    .sb-nav a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
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
    .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 240; }
    .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
    .topbar-title em { color: var(--red); font-style: normal; }
    .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
    .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
    .sb-overlay.show { display: block; }

    /* ── Main ────────────────────────────────────── */
    .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }

    /* ── Hero ────────────────────────────────────── */
    .page-hero { padding: 32px 32px 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
    .hero-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
    .hero-sub { font-size: 13px; color: var(--text-2); margin-top: 3px; }

    /* ── Content ─────────────────────────────────── */
    .content { padding: 20px 32px 48px; flex: 1; }

    /* ── Filter bar ──────────────────────────────── */
    .filter-bar {
      display: flex; align-items: center; gap: 8px;
      margin-bottom: 20px; flex-wrap: wrap;
    }
    .filter-label { font-size: 12px; font-weight: 600; color: var(--text-2); flex-shrink: 0; }

    .filter-pills { display: flex; gap: 6px; flex-wrap: wrap; }
    .filter-pill {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 5px 12px; border-radius: 999px;
      font-family: var(--font); font-size: 12px; font-weight: 600;
      border: 1px solid var(--border-md); background: var(--panel-2); color: var(--text-2);
      cursor: pointer; transition: all var(--t) var(--ease);
    }
    .filter-pill:hover { border-color: var(--border-red); color: var(--red); }
    .filter-pill.active { background: var(--red-soft); border-color: var(--border-red); color: var(--red); }

    /* ── HOF badges (compartilhado pódio + lista) ──── */
    .hof-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 8px; border-radius: 999px;
      font-size: 10px; font-weight: 700;
    }
    .hof-badge.league { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border-md); }
    .hof-badge.league.current { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }
    .hof-badge.active { background: rgba(34,197,94,.10); color: var(--green); border: 1px solid rgba(34,197,94,.2); }
    .hof-badge.inactive { background: var(--panel-3); color: var(--text-3); border: 1px solid var(--border); }

    /* ── Pódio (top 3) ───────────────────────────── */
    .hof-podium {
      display: grid;
      grid-template-columns: 1fr 1.12fr 1fr;
      gap: 14px;
      margin-bottom: 28px;
      align-items: end;
    }
    .podium-card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 22px 18px;
      text-align: center;
      position: relative;
      overflow: hidden;
      transition: all var(--t) var(--ease);
    }
    .podium-card:hover { border-color: var(--border-md); transform: translateY(-2px); }
    .podium-card.rank-1 { order: 2; padding: 30px 22px; border-color: rgba(245,158,11,.35); background: linear-gradient(180deg, rgba(245,158,11,.12), var(--panel) 65%); }
    .podium-card.rank-2 { order: 1; border-color: rgba(148,163,184,.25); background: linear-gradient(180deg, rgba(148,163,184,.08), var(--panel) 65%); }
    .podium-card.rank-3 { order: 3; border-color: rgba(205,124,74,.25); background: linear-gradient(180deg, rgba(205,124,74,.08), var(--panel) 65%); }
    .podium-medal { font-size: 30px; line-height: 1; margin-bottom: 6px; }
    .podium-card.rank-1 .podium-medal { font-size: 40px; }
    .podium-name { font-size: 15px; font-weight: 800; color: var(--text); line-height: 1.25; }
    .podium-card.rank-1 .podium-name { font-size: 18px; }
    .podium-team { font-size: 11px; color: var(--text-2); margin-top: 2px; min-height: 14px; }
    .podium-score { font-size: 30px; font-weight: 900; color: var(--amber); line-height: 1; margin-top: 14px; }
    .podium-card.rank-1 .podium-score { font-size: 40px; }
    .podium-score-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-3); margin: 3px 0 12px; }
    .podium-badges { display: flex; justify-content: center; gap: 5px; flex-wrap: wrap; }
    @media (max-width: 700px) {
      .hof-podium { grid-template-columns: 1fr; }
      .podium-card.rank-1, .podium-card.rank-2, .podium-card.rank-3 { order: initial; }
    }

    /* ── Lista (rank 4+) ─────────────────────────── */
    .hof-list { display: flex; flex-direction: column; gap: 8px; }
    .hof-row {
      display: flex; align-items: center; gap: 14px;
      background: var(--panel); border: 1px solid var(--border);
      border-radius: 10px; padding: 12px 16px;
      transition: border-color var(--t) var(--ease);
    }
    .hof-row:hover { border-color: var(--border-md); }
    .hof-row-rank { width: 26px; text-align: center; font-weight: 700; color: var(--text-3); font-size: 13px; flex-shrink: 0; }
    .hof-row-name { flex: 1; min-width: 0; }
    .hof-row-name .name { font-size: 14px; font-weight: 700; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hof-row-name .team { font-size: 11px; color: var(--text-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hof-row-badges { display: flex; gap: 5px; flex-wrap: wrap; justify-content: flex-end; max-width: 45%; }
    .hof-row-score { font-size: 19px; font-weight: 800; color: var(--amber); min-width: 34px; text-align: right; flex-shrink: 0; }
    @media (max-width: 560px) {
      .hof-row-badges { display: none; }
    }

    /* ── Empty / spinner ─────────────────────────── */
    .state-empty { padding: 48px 20px; text-align: center; color: var(--text-3); }
    .state-empty i { font-size: 36px; display: block; margin-bottom: 12px; }
    .state-empty p { font-size: 13px; max-width: 300px; margin: 0 auto; }

    .spinner { width: 28px; height: 28px; border: 3px solid var(--border-md); border-top-color: var(--red); border-radius: 50%; animation: spin .7s linear infinite; margin: 0 auto; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Responsive ──────────────────────────────── */
    @media (max-width: 991px) {
      :root { --sidebar-w: 0px; }
      .sidebar { transform: translateX(-260px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; width: 100%; padding-top: 54px; }
      .topbar { display: flex; }
      .page-hero, .content { padding-left: 16px; padding-right: 16px; }
      .page-hero { padding-top: 18px; }
    }
  </style>
</head>
<body>
<div class="app">

  <!-- ══════════════════════════════════════════════
       SIDEBAR
  ══════════════════════════════════════════════ -->
  <aside class="sidebar" id="sidebar">

    <div class="sb-brand">
      <div class="sb-logo">FBA</div>
      <div class="sb-brand-text">FBA Manager <span>Painel do GM</span></div>
    </div>

    <?php if ($team): ?>
    <div class="sb-team">
      <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
           alt="<?= htmlspecialchars($team['name'] ?? '') ?>"
           onerror="this.src='/img/default-team.png'">
      <div>
        <div class="sb-team-name"><?= htmlspecialchars(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')) ?></div>
        <div class="sb-team-league"><?= htmlspecialchars($userLeague) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($currentSeason): ?>
    <div class="sb-season">
      <div>
        <div class="sb-season-label">Temporada</div>
        <div class="sb-season-val"><?= $seasonDisplayYear ?></div>
      </div>
      <div style="text-align:right">
        <div class="sb-season-label">Sprint</div>
        <div class="sb-season-val"><?= (int)($currentSeason['sprint_number'] ?? 1) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <nav class="sb-nav">
      <div class="sb-section">Principal</div>
      <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
      <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
      <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
      <a href="/players.php"><i class="bi bi-person-lines-fill"></i> Jogadores</a>
      <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
      <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
            <a href="/mercado.php"><i class="bi bi-shop"></i> Mercado</a>
      <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
      <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
      <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>
            <a href="/tapas.php"><i class="bi bi-hand-index-thumb"></i> Tapas</a>

      <div class="sb-section">Liga</div>
      <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
      <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
      <a href="/hall-da-fama.php" class="active"><i class="bi bi-award-fill"></i> Hall da Fama</a>
      <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
      <a href="/mundo-fba.php"><i class="bi bi-globe2"></i> Mundo FBA</a>
            <a href="/estatisticas.php"><i class="bi bi-bar-chart-line-fill"></i> Estatísticas</a>
            <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
      <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>
            <a href="/thepathetic.php"><i class="bi bi-newspaper"></i> The Pathetic</a>

      <?php if ($isAdmin): ?>
      <div class="sb-section">Admin</div>
      <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>

      <?php endif; ?>

      <div class="sb-section">Conta</div>
      <a href="/settings.php"><i class="bi bi-gear-fill"></i> Minha Conta</a>
      <a href="/team-public-page.php"><i class="bi bi-globe2"></i> Página do Time</a>
    </nav>

    <div class="sb-footer">
      <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>"
           alt="<?= htmlspecialchars($user['name']) ?>"
           class="sb-avatar"
           onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name']) ?>&background=1c1c21&color=fc0025'">
      <span class="sb-username"><?= htmlspecialchars($user['name']) ?></span>
      <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </aside>

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
      <div>
        <div class="hero-eyebrow">Liga · <?= htmlspecialchars($userLeague) ?></div>
        <h1 class="hero-title">Hall da Fama</h1>
        <p class="hero-sub">Os GMs mais vitoriosos da história da liga</p>
      </div>
    </div>

    <div class="content">

      <!-- Filtro por liga -->
      <div class="filter-bar">
        <span class="filter-label"><i class="bi bi-funnel" style="color:var(--red)"></i> Liga:</span>
        <div class="filter-pills" id="filterPills">
          <button class="filter-pill active" data-value="ALL">Todas</button>
          <button class="filter-pill" data-value="ELITE">ELITE</button>
          <button class="filter-pill" data-value="NEXT">NEXT</button>
          <button class="filter-pill" data-value="RISE">RISE</button>
          <button class="filter-pill" data-value="ROOKIE">ROOKIE</button>
        </div>
      </div>

      <!-- Container -->
      <div id="hallOfFameContainer">
        <div class="state-empty">
          <div class="spinner" style="margin-bottom:16px"></div>
          <p>Carregando Hall da Fama…</p>
        </div>
      </div>

    </div>
  </main>
</div><!-- .app -->

<script>
  // ── Sidebar toggle ────────────────────────────────
  const sidebar   = document.getElementById('sidebar');
  const sbOverlay = document.getElementById('sbOverlay');
  const menuBtn   = document.getElementById('menuBtn');
  function openSidebar()  { sidebar.classList.add('open'); sbOverlay.classList.add('show'); }
  function closeSidebar() { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); }
  if (menuBtn)   menuBtn.addEventListener('click', openSidebar);
  if (sbOverlay) sbOverlay.addEventListener('click', closeSidebar);

  // ── Hall da Fama ──────────────────────────────────
  const HOF_LEAGUE_ORDER = { ELITE: 0, NEXT: 1, RISE: 2, ROOKIE: 3 };
  let hallOfFameGroups = [];
  let activeFilter = 'ALL';

  // Filtros em pills
  document.getElementById('filterPills').addEventListener('click', e => {
    const pill = e.target.closest('.filter-pill');
    if (!pill) return;
    document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');
    activeFilter = pill.dataset.value;
    renderHallOfFame(getFiltered());
  });

  function getFiltered() {
    if (activeFilter === 'ALL') return hallOfFameGroups;
    return hallOfFameGroups.filter(g => Number((g.leagues || {})[activeFilter]) > 0);
  }

  const PODIUM_MEDALS = ['🥇', '🥈', '🥉'];

  function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function leagueBadges(g) {
    return Object.entries(g.leagues || {})
      .filter(([lg]) => activeFilter === 'ALL' || lg === activeFilter)
      .sort((a, b) => (HOF_LEAGUE_ORDER[a[0]] ?? 9) - (HOF_LEAGUE_ORDER[b[0]] ?? 9))
      .map(([lg, titles]) => `<span class="hof-badge league${lg === g.current_league ? ' current' : ''}">${escHtml(lg)} ${titles}</span>`)
      .join('');
  }

  function headlineFor(g) {
    // "Todas": pontuação ponderada por liga (Elite pesa mais). Filtrado numa liga: título bruto daquela liga.
    return activeFilter === 'ALL' ? (Number(g.weighted_score) || 0) : (Number((g.leagues || {})[activeFilter]) || 0);
  }

  function renderHallOfFame(groups) {
    const container = document.getElementById('hallOfFameContainer');

    if (!groups.length) {
      container.innerHTML = `
        <div class="state-empty">
          <i class="bi bi-award"></i>
          <p>Nenhum GM no Hall da Fama${activeFilter !== 'ALL' ? ' para a liga ' + activeFilter : ''} ainda.</p>
        </div>
      `;
      return;
    }

    // A API já manda ordenado pela pontuação ponderada (Elite pesa mais). Quando um
    // filtro de liga específica está ativo, reordenamos pelo título bruto daquela liga.
    const sorted = activeFilter === 'ALL'
      ? groups
      : [...groups].sort((a, b) => (Number((b.leagues || {})[activeFilter]) || 0) - (Number((a.leagues || {})[activeFilter]) || 0));

    const podium = sorted.slice(0, 3);
    const rest = sorted.slice(3);

    const podiumHtml = podium.length ? `
      <div class="hof-podium">
        ${podium.map((g, idx) => {
          const gmName = escHtml(g.gm_name || 'GM não informado');
          const teams = escHtml((g.teams || []).join(' / '));
          return `
            <div class="podium-card rank-${idx + 1}">
              <div class="podium-medal">${PODIUM_MEDALS[idx]}</div>
              <div class="podium-name">${gmName}</div>
              <div class="podium-team">${teams}</div>
              <div class="podium-score">${headlineFor(g)}</div>
              <div class="podium-score-label">${activeFilter === 'ALL' ? 'pontos' : 'título' + (headlineFor(g) === 1 ? '' : 's') + ' na ' + activeFilter}</div>
              <div class="podium-badges">${leagueBadges(g)}</div>
            </div>
          `;
        }).join('')}
      </div>
    ` : '';

    const listHtml = rest.length ? `
      <div class="hof-list">
        ${rest.map((g, idx) => {
          const gmName = escHtml(g.gm_name || 'GM não informado');
          const teams = escHtml((g.teams || []).join(' / '));
          return `
            <div class="hof-row">
              <div class="hof-row-rank">${idx + 4}</div>
              <div class="hof-row-name">
                <div class="name">${gmName}</div>
                ${teams ? `<div class="team">${teams}</div>` : ''}
              </div>
              <div class="hof-row-badges">${leagueBadges(g)}</div>
              <div class="hof-row-score">${headlineFor(g)}</div>
            </div>
          `;
        }).join('')}
      </div>
    ` : '';

    container.innerHTML = podiumHtml + listHtml;
  }

  async function loadHallOfFame() {
    const container = document.getElementById('hallOfFameContainer');
    container.innerHTML = `
      <div class="state-empty">
        <div class="spinner" style="margin-bottom:16px"></div>
        <p>Carregando Hall da Fama…</p>
      </div>
    `;

    try {
      const resp = await fetch('/api/hall-of-fame.php');
      const data = await resp.json();
      if (!data.success) throw new Error(data.error || 'Falha ao carregar');

      hallOfFameGroups = Array.isArray(data.groups) ? data.groups : [];
      renderHallOfFame(getFiltered());
    } catch (e) {
      container.innerHTML = `
        <div class="state-empty" style="color:#ef4444">
          <i class="bi bi-exclamation-circle"></i>
          <p>Erro ao carregar Hall da Fama.</p>
        </div>
      `;
    }
  }

  loadHallOfFame();
</script>
<script src="/js/pwa.js"></script>
</body>
</html>
