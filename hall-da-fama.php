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
$isAdmin    = ($user['user_type'] ?? 'jogador') === 'admin';

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

    /* ── HOF grid ────────────────────────────────── */
    .hof-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 12px;
    }

    /* ── HOF card ────────────────────────────────── */
    .hof-card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 20px;
      display: flex; flex-direction: column; gap: 14px;
      transition: all var(--t) var(--ease);
      position: relative;
      overflow: hidden;
    }
    .hof-card::before {
      content: '';
      position: absolute; top: 0; left: 0; right: 0; height: 2px;
      background: var(--accent-top, transparent);
      transition: opacity var(--t) var(--ease);
    }
    .hof-card:hover { border-color: var(--border-md); transform: translateY(-2px); }
    .hof-card.top-1 { --accent-top: linear-gradient(90deg, #f59e0b, #fbbf24); border-color: rgba(245,158,11,.25); }
    .hof-card.top-2 { --accent-top: linear-gradient(90deg, #94a3b8, #cbd5e1); }
    .hof-card.top-3 { --accent-top: linear-gradient(90deg, #cd7c4a, #e09a68); }

    .hof-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }

    .hof-rank {
      width: 32px; height: 32px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; font-weight: 800; flex-shrink: 0;
    }
    .hof-rank.r1 { background: rgba(245,158,11,.15); color: #f59e0b; border: 1px solid rgba(245,158,11,.3); }
    .hof-rank.r2 { background: rgba(148,163,184,.12); color: #94a3b8; border: 1px solid rgba(148,163,184,.2); }
    .hof-rank.r3 { background: rgba(205,124,74,.12); color: #cd7c4a; border: 1px solid rgba(205,124,74,.2); }
    .hof-rank.rn { background: var(--panel-3); color: var(--text-3); border: 1px solid var(--border); }

    .hof-titles-block { text-align: right; }
    .hof-titles-num { font-size: 28px; font-weight: 800; line-height: 1; color: var(--amber); }
    .hof-titles-label { font-size: 10px; font-weight: 600; letter-spacing: .6px; text-transform: uppercase; color: var(--text-3); margin-top: 2px; }

    .hof-name { font-size: 15px; font-weight: 700; color: var(--text); line-height: 1.2; margin-bottom: 2px; }
    .hof-team { font-size: 12px; color: var(--text-2); }

    .hof-footer { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .hof-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 8px; border-radius: 999px;
      font-size: 10px; font-weight: 700;
    }
    .hof-badge.league { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }
    .hof-badge.active { background: rgba(34,197,94,.10); color: var(--green); border: 1px solid rgba(34,197,94,.2); }
    .hof-badge.inactive { background: var(--panel-3); color: var(--text-3); border: 1px solid var(--border); }

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
    @media (max-width: 480px) {
      .hof-grid { grid-template-columns: 1fr; }
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
      <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
      <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
      <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
      <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
      <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>

      <div class="sb-section">Liga</div>
      <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
      <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
      <a href="/hall-da-fama.php" class="active"><i class="bi bi-award-fill"></i> Hall da Fama</a>
      <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
      <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
      <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>

      <?php if ($isAdmin): ?>
      <div class="sb-section">Admin</div>
      <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
      <a href="/punicoes.php"><i class="bi bi-exclamation-triangle-fill"></i> Punições</a>
      <a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a>
      <?php endif; ?>

      <div class="sb-section">Conta</div>
      <a href="/settings.php"><i class="bi bi-gear-fill"></i> Configurações</a>
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
  let hallOfFameItems = [];
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
    if (activeFilter === 'ALL') return hallOfFameItems;
    return hallOfFameItems.filter(item => (item.league || '').toUpperCase() === activeFilter);
  }

  function rankClass(idx) {
    if (idx === 0) return 'r1';
    if (idx === 1) return 'r2';
    if (idx === 2) return 'r3';
    return 'rn';
  }

  function cardTopClass(idx) {
    if (idx === 0) return 'hof-card top-1';
    if (idx === 1) return 'hof-card top-2';
    if (idx === 2) return 'hof-card top-3';
    return 'hof-card';
  }

  function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function renderHallOfFame(items) {
    const container = document.getElementById('hallOfFameContainer');

    if (!items.length) {
      container.innerHTML = `
        <div class="state-empty">
          <i class="bi bi-award"></i>
          <p>Nenhum GM no Hall da Fama${activeFilter !== 'ALL' ? ' para a liga ' + activeFilter : ''} ainda.</p>
        </div>
      `;
      return;
    }

    // Ordenar por títulos desc (já deve vir ordenado da API, mas garantimos)
    const sorted = [...items].sort((a, b) => (Number(b.titles) || 0) - (Number(a.titles) || 0));

    container.innerHTML = `
      <div class="hof-grid">
        ${sorted.map((item, idx) => {
          const isActive   = Number(item.is_active) === 1;
          const titles     = Number(item.titles) || 0;
          const gmName     = escHtml(item.gm_name || 'GM não informado');
          const teamName   = escHtml(item.team_name || '');
          const league     = escHtml(item.league || '');

          return `
            <div class="${cardTopClass(idx)}">
              <div class="hof-card-top">
                <div style="display:flex;align-items:center;gap:10px">
                  <div class="hof-rank ${rankClass(idx)}">${idx + 1}</div>
                  <div>
                    <div class="hof-name">${gmName}</div>
                    ${isActive && teamName ? `<div class="hof-team">${teamName}</div>` : ''}
                  </div>
                </div>
                <div class="hof-titles-block">
                  <div class="hof-titles-num">${titles}</div>
                  <div class="hof-titles-label">${titles === 1 ? 'título' : 'títulos'}</div>
                </div>
              </div>
              <div class="hof-footer">
                ${league ? `<span class="hof-badge league">${league}</span>` : ''}
                <span class="hof-badge ${isActive ? 'active' : 'inactive'}">
                  <i class="bi bi-${isActive ? 'circle-fill' : 'circle'}" style="font-size:7px"></i>
                  ${isActive ? 'Ativo' : 'Inativo'}
                </span>
              </div>
            </div>
          `;
        }).join('')}
      </div>
    `;
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

      hallOfFameItems = Array.isArray(data.items) ? data.items : [];
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
