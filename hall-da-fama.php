<?php
session_start();
require_once 'backend/auth.php';
require_once 'backend/db.php';
require_once 'backend/helpers.php';

requireAuth();

$user = getUserSession();
$pdo  = db();

/* timeDaTela: no observador, o hall precisa ser o da liga observada. */
require_once __DIR__ . '/backend/observador.php';
$team = timeDaTela($pdo, (int)$user['id']);

$userLeague = $team['league'] ?? ($user['league'] ?? 'ELITE');

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
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

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
      --sidebar-w:  260px;
      --font:       'Montserrat', sans-serif;
      --radius:     14px;
      --radius-sm:  10px;
      --ease:       cubic-bezier(.2,.8,.2,1);
      --t:          200ms;
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
    .hero-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }

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
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 12px; border-radius: 999px;
      font-family: var(--font); font-size: 12px; font-weight: 600;
      border: 1px solid var(--border-md); background: var(--panel-2); color: var(--text-2);
      cursor: pointer; transition: all var(--t) var(--ease);
    }
    .filter-pill:hover { border-color: var(--border-red); color: var(--red); }
    .filter-pill.active { background: var(--red-soft); border-color: var(--border-red); color: var(--red); }

    /* ── HOF badges (compartilhado pódio + lista) ──── */
    .hof-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 4px 8px; border-radius: 999px;
      font-size: 10px; font-weight: 700;
    }
    .hof-badge.league { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border-md); }
    .hof-badge.league.current { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }

    /* Badges de título em destaque (número grande) usados no pódio */
    .title-badge {
      display: inline-flex; flex-direction: column; align-items: center; gap: 2px;
      padding: 8px 14px; border-radius: 12px;
      background: var(--panel-3); border: 1px solid var(--border-md);
    }
    .title-badge.current { background: var(--red-soft); border-color: var(--border-red); }
    .title-badge .num { font-size: 24px; font-weight: 900; line-height: 1; color: var(--amber); }
    .title-badge .lg { font-size: 9px; font-weight: 700; letter-spacing: .5px; color: var(--text-2); }
    .title-badge.current .lg { color: var(--red); }

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
    .podium-titles { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
    .podium-card.rank-1 .title-badge .num { font-size: 30px; }
    .podium-card.rank-1 .title-badge { padding: 10px 16px; }
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
    .hof-row-badges { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; max-width: 60%; }
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

    /* ── Editor aberto do Hall ───────────────────── */
    .hof-ed { margin-top: 34px; border-top: 1px solid var(--border-md); padding-top: 20px; }
    .hof-ed-open {
      display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
      background: transparent; color: var(--text-2); border: 1px solid var(--border-md);
      border-radius: 9px; padding: 9px 15px; font-size: 12.5px; font-weight: 600;
      font-family: inherit; transition: border-color .15s, color .15s;
    }
    .hof-ed-open:hover { border-color: var(--red); color: var(--text); }
    .hof-ed-hint { font-size: 11.5px; color: var(--text-3); margin: 8px 0 0; }

    .hof-ed-body { display: none; margin-top: 18px; }
    .hof-ed.aberto .hof-ed-body { display: block; }

    .hof-ed-grid { display: flex; flex-direction: column; gap: 8px; }
    .hof-ed-row {
      display: grid; grid-template-columns: 1fr 1fr 110px 74px auto;
      gap: 8px; align-items: center;
      background: var(--panel); border: 1px solid var(--border-sm);
      border-radius: 10px; padding: 9px 10px;
    }
    .hof-ed-row.novo { border-color: color-mix(in srgb, var(--red) 40%, transparent); }
    .hof-ed-row.sujo { border-color: #d1a237; }

    .hof-ed-row input, .hof-ed-row select {
      width: 100%; min-width: 0; background: var(--panel-2); color: var(--text);
      border: 1px solid var(--border-sm); border-radius: 7px;
      padding: 7px 9px; font-size: 12.5px; font-family: inherit;
    }
    .hof-ed-row input:focus, .hof-ed-row select:focus { outline: none; border-color: var(--red); }
    .hof-ed-acoes { display: flex; gap: 5px; }
    .hof-ed-btn {
      background: var(--panel-2); color: var(--text-2); border: 1px solid var(--border-sm);
      border-radius: 7px; width: 32px; height: 32px; cursor: pointer;
      display: inline-flex; align-items: center; justify-content: center; font-size: 13px;
    }
    .hof-ed-btn:hover { color: var(--text); border-color: var(--border-md); }
    .hof-ed-btn.salvar:hover { color: #4ade80; border-color: #4ade80; }
    .hof-ed-btn.apagar:hover { color: #ef4444; border-color: #ef4444; }
    .hof-ed-btn[disabled] { opacity: .35; cursor: default; }

    .hof-ed-rotulo {
      display: grid; grid-template-columns: 1fr 1fr 110px 74px 69px; gap: 8px;
      padding: 0 10px 4px; font-size: 10.5px; text-transform: uppercase;
      letter-spacing: .06em; color: var(--text-3);
    }
    .hof-ed-busca {
      width: 100%; background: var(--panel-2); color: var(--text);
      border: 1px solid var(--border-sm); border-radius: 9px;
      padding: 9px 12px; font-size: 13px; font-family: inherit; margin-bottom: 12px;
    }
    .hof-ed-busca:focus { outline: none; border-color: var(--red); }
    .hof-ed-filtros { display: grid; grid-template-columns: 1fr 160px 200px; gap: 8px; }
    .hof-ed-filtros .hof-ed-busca { margin-bottom: 12px; }
    @media (max-width: 720px) {
      /* Empilhado: três controles em 375px dão 110px cada, e o nome do
         time não cabe em nenhum deles. */
      .hof-ed-filtros { grid-template-columns: 1fr; gap: 0; }
      .hof-ed-filtros .hof-ed-busca { margin-bottom: 8px; }
    }
    .hof-ed-corte { font-size: 11.5px; color: var(--text-3); padding: 8px 2px 0; }
    .hof-ed-msg { font-size: 12px; margin-top: 10px; min-height: 16px; }
    .hof-ed-msg.erro { color: #ef4444; }
    .hof-ed-msg.ok { color: #4ade80; }

    .hof-ed-log { margin-top: 22px; }
    .hof-ed-log h4 {
      font-size: 11px; text-transform: uppercase; letter-spacing: .07em;
      color: var(--text-3); margin: 0 0 8px;
    }
    .hof-ed-log ul { margin: 0; padding: 0; }
    .hof-ed-log li {
      list-style: none; font-size: 12px; color: var(--text-2);
      padding: 5px 0; border-bottom: 1px solid var(--border-sm);
    }
    .hof-ed-log .quando { color: var(--text-3); font-size: 11px; }

    @media (max-width: 720px) {
      /* Empilhado: cinco colunas em 375px dão 40px cada, e nenhum nome cabe. */
      .hof-ed-rotulo { display: none; }
      .hof-ed-row { grid-template-columns: 1fr 1fr; gap: 7px; padding: 10px; }
      .hof-ed-row .campo-gm { grid-column: 1 / -1; }
      .hof-ed-acoes { grid-column: 1 / -1; justify-content: flex-end; }
    }
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

      <!-- ══════════════════════════════════════════════
           EDITOR DO HALL — aberto a qualquer GM logado.
           Fica no fim da página de propósito: quem vem aqui vem ver o Hall;
           editar é a exceção, e exceção não abre a tela.
           ══════════════════════════════════════════════ -->
      <section class="hof-ed" id="hofEd">
        <button class="hof-ed-open" type="button" id="hofEdOpen">
          <i class="bi bi-pencil-square"></i> Editar Hall
        </button>
        <p class="hof-ed-hint">Qualquer GM pode corrigir e incluir. Toda alteração fica registrada com o nome de quem fez.</p>

        <div class="hof-ed-body">
          <div class="hof-ed-filtros">
            <input class="hof-ed-busca" id="hofEdBusca" type="search" placeholder="Buscar GM ou time…" autocomplete="off">
            <select class="hof-ed-busca" id="hofEdLiga">
              <option value="">Todas as ligas</option>
              <option value="ELITE">ELITE</option>
              <option value="NEXT">NEXT</option>
              <option value="RISE">RISE</option>
              <option value="ROOKIE">ROOKIE</option>
              <option value="__SEM__">Sem liga (histórico)</option>
            </select>
            <select class="hof-ed-busca" id="hofEdTime">
              <option value="">Todos os times</option>
            </select>
          </div>
          <div class="hof-ed-rotulo">
            <span>GM</span><span>Time</span><span>Liga</span><span>Títulos</span><span></span>
          </div>
          <div class="hof-ed-grid" id="hofEdGrid"></div>
          <div class="hof-ed-msg" id="hofEdMsg"></div>

          <div class="hof-ed-log" id="hofEdLog"></div>
        </div>
      </section>

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

  function sortedLeagueEntries(g) {
    return Object.entries(g.leagues || {})
      .filter(([lg]) => activeFilter === 'ALL' || lg === activeFilter)
      .sort((a, b) => (HOF_LEAGUE_ORDER[a[0]] ?? 9) - (HOF_LEAGUE_ORDER[b[0]] ?? 9));
  }

  function leagueBadges(g) {
    return sortedLeagueEntries(g)
      .map(([lg, titles]) => `<span class="hof-badge league${lg === g.current_league ? ' current' : ''}">${escHtml(lg)} ${titles}</span>`)
      .join('');
  }

  // Números grandes de título (sem pontuação calculada) — um badge por liga que o GM tem título.
  function titleBadgesLarge(g) {
    return sortedLeagueEntries(g)
      .map(([lg, titles]) => `
        <span class="title-badge${lg === g.current_league ? ' current' : ''}">
          <span class="num">${titles}</span>
          <span class="lg">${escHtml(lg)}</span>
        </span>
      `).join('');
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
              <div class="podium-titles">${titleBadgesLarge(g)}</div>
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

  // ── Editor do Hall ────────────────────────────────
  //
  // Aberto a qualquer GM logado, a pedido. O que segura a mão é o registro:
  // api/hall-editar.php grava quem mexeu e o que havia antes, e as últimas
  // alterações aparecem aqui embaixo. Quem apagar sem querer não perde nada —
  // o conteúdo antigo está no log.
  const LIGAS_HOF = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

  const hofEd     = document.getElementById('hofEd');
  const hofEdGrid = document.getElementById('hofEdGrid');
  const hofEdMsg  = document.getElementById('hofEdMsg');
  const hofEdLog  = document.getElementById('hofEdLog');
  let hofEdCarregado = false;

  document.getElementById('hofEdOpen').addEventListener('click', () => {
    hofEd.classList.toggle('aberto');
    // Só busca quando abre de verdade: quem nunca clica não paga a consulta.
    if (hofEd.classList.contains('aberto') && !hofEdCarregado) carregarEditor();
  });

  function hofMsg(texto, tipo = '') {
    hofEdMsg.textContent = texto;
    hofEdMsg.className = 'hof-ed-msg ' + tipo;
  }

  function linhaEditor(l) {
    const novo = !l.id;
    const div = document.createElement('div');
    div.className = 'hof-ed-row' + (novo ? ' novo' : '');
    div.dataset.id = l.id || '';
    div.innerHTML = `
      <input class="campo-gm" data-c="gm_name" value="${escHtml(l.gm_name || '')}" placeholder="Nome do GM" maxlength="255">
      <input data-c="team_name" value="${escHtml(l.team_name || '')}" placeholder="Time (opcional)" maxlength="255">
      <select data-c="league">
        <option value="">— sem liga —</option>
        ${LIGAS_HOF.map(lg => `<option value="${lg}"${l.league === lg ? ' selected' : ''}>${lg}</option>`).join('')}
      </select>
      <input data-c="titles" type="number" min="1" max="99" value="${Number(l.titles) || 1}">
      <div class="hof-ed-acoes">
        <button class="hof-ed-btn salvar" type="button" title="${novo ? 'Incluir' : 'Salvar'}">
          <i class="bi bi-${novo ? 'plus-lg' : 'check-lg'}"></i>
        </button>
        ${novo ? '' : '<button class="hof-ed-btn apagar" type="button" title="Apagar"><i class="bi bi-trash3"></i></button>'}
      </div>
    `;

    // Marca a linha como mexida: sem isso não dá pra saber, olhando, quais
    // ainda faltam salvar — e o editor tem uma linha por registro.
    div.querySelectorAll('[data-c]').forEach(el => {
      el.addEventListener('input',  () => { if (!novo) div.classList.add('sujo'); });
      el.addEventListener('change', () => { if (!novo) div.classList.add('sujo'); });
    });

    div.querySelector('.salvar').addEventListener('click', () => salvarLinha(div, novo));
    div.querySelector('.apagar')?.addEventListener('click', () => apagarLinha(div, l));
    return div;
  }

  function valoresDaLinha(div) {
    const v = {};
    div.querySelectorAll('[data-c]').forEach(el => { v[el.dataset.c] = el.value; });
    return v;
  }

  function travarLinha(div, travado) {
    div.querySelectorAll('button, input, select').forEach(el => { el.disabled = travado; });
  }

  async function enviar(metodo, corpo) {
    const r = await fetch('/api/hall-editar.php', {
      method: metodo,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(corpo),
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok || !d.success) throw new Error(d.error || 'Não deu pra salvar.');
    return d;
  }

  async function salvarLinha(div, novo) {
    const v = valoresDaLinha(div);
    if (!v.gm_name.trim()) { hofMsg('O nome do GM é obrigatório.', 'erro'); return; }

    travarLinha(div, true);
    try {
      if (novo) await enviar('POST', v);
      else      await enviar('PUT', { id: Number(div.dataset.id), ...v });
      await carregarEditor();
      hofMsg(novo ? 'Incluído.' : 'Salvo.', 'ok');
      // O pódio lá em cima muda junto — deixar a lista velha na tela faria
      // parecer que a edição não pegou.
      loadHallOfFame();
    } catch (e) {
      hofMsg(e.message, 'erro');
      travarLinha(div, false);
    }
  }

  async function apagarLinha(div, l) {
    const quem = l.gm_name || 'esse registro';
    if (!await confirmarSite(`Apagar ${quem} — ${l.titles} título(s)${l.league ? ' da ' + l.league : ''}?`)) return;

    travarLinha(div, true);
    try {
      await enviar('DELETE', { id: Number(div.dataset.id) });
      await carregarEditor();
      hofMsg('Apagado. Ficou registrado no histórico abaixo.', 'ok');
      loadHallOfFame();
    } catch (e) {
      hofMsg(e.message, 'erro');
      travarLinha(div, false);
    }
  }

  function quandoLegivel(iso) {
    const d = new Date(String(iso || '').replace(' ', 'T'));
    if (isNaN(d)) return '';
    return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  }

  function renderLog(historico) {
    if (!historico || !historico.length) { hofEdLog.innerHTML = ''; return; }
    const nome = j => { try { return (JSON.parse(j) || {}).gm_name || ''; } catch { return ''; } };
    hofEdLog.innerHTML = `
      <h4>Últimas alterações</h4>
      <ul>${historico.map(h => `
        <li>
          <strong>${escHtml(h.user_nome || 'alguém')}</strong>
          ${escHtml(h.acao)} <em>${escHtml(nome(h.depois) || nome(h.antes) || 'um registro')}</em>
          <span class="quando">· ${escHtml(quandoLegivel(h.criado_em))}</span>
        </li>`).join('')}</ul>
    `;
  }

  // Sem nenhum filtro a lista para nas primeiras: são 67 registros hoje, e
  // mostrar todos empilhados dá quase 12 mil pixels de rolagem no celular pra
  // uma tela em que quase sempre se vem mexer numa linha só. Filtrando o teto
  // sobe — quem filtrou já disse o que quer ver.
  const HOF_ED_SEM_FILTRO = 12;
  const HOF_ED_FILTRADO   = 25;
  let hofEdLinhas = [];

  ['hofEdBusca', 'hofEdLiga', 'hofEdTime'].forEach(id => {
    const el = document.getElementById(id);
    el.addEventListener('input',  renderLinhasEditor);
    el.addEventListener('change', renderLinhasEditor);
  });

  /**
   * Enche o filtro de time com os times que EXISTEM no Hall.
   *
   * Não é a lista de times da liga: metade dos registros é histórica, com
   * nome de time que não existe mais. Um seletor com os times de hoje não
   * acharia justamente as linhas antigas, que são as que precisam de conserto.
   */
  function encherFiltroTimes() {
    const sel = document.getElementById('hofEdTime');
    const atual = sel.value;
    const times = [...new Set(hofEdLinhas.map(l => (l.team_name || '').trim()).filter(Boolean))]
      .sort((a, b) => a.localeCompare(b, 'pt-BR'));
    sel.innerHTML = '<option value="">Todos os times</option>'
      + times.map(t => `<option value="${escHtml(t)}">${escHtml(t)}</option>`).join('');
    if (times.includes(atual)) sel.value = atual;
  }

  function renderLinhasEditor() {
    const busca = document.getElementById('hofEdBusca').value.trim().toLowerCase();
    const liga  = document.getElementById('hofEdLiga').value;
    const time  = document.getElementById('hofEdTime').value;
    const filtrando = !!(busca || liga || time);

    const achados = hofEdLinhas.filter(l => {
      if (busca
          && !String(l.gm_name   || '').toLowerCase().includes(busca)
          && !String(l.team_name || '').toLowerCase().includes(busca)) return false;
      // "Sem liga" é um filtro de verdade: são os títulos de antes das
      // divisões, e é justamente neles que falta informação.
      if (liga === '__SEM__' && l.league) return false;
      if (liga && liga !== '__SEM__' && l.league !== liga) return false;
      if (time && (l.team_name || '').trim() !== time) return false;
      return true;
    });

    const teto    = filtrando ? HOF_ED_FILTRADO : HOF_ED_SEM_FILTRO;
    const mostrar = achados.slice(0, teto);

    hofEdGrid.innerHTML = '';
    // A linha em branco vem PRIMEIRO: incluir é o que traz a maioria aqui, e
    // no fim da lista ela ficava a milhares de pixels de distância.
    hofEdGrid.appendChild(linhaEditor({}));
    mostrar.forEach(l => hofEdGrid.appendChild(linhaEditor(l)));

    const aviso = txt => {
      const d = document.createElement('div');
      d.className = 'hof-ed-corte';
      d.textContent = txt;
      hofEdGrid.appendChild(d);
    };

    if (achados.length > mostrar.length) {
      aviso(`Mostrando ${mostrar.length} de ${achados.length}. Filtre mais pra achar os outros.`);
    } else if (!achados.length) {
      aviso('Nada com esses filtros. A linha de cima inclui um novo.');
    }
  }

  async function carregarEditor() {
    hofEdGrid.innerHTML = '<div class="state-empty" style="padding:20px"><div class="spinner"></div></div>';
    try {
      const r = await fetch('/api/hall-editar.php');
      const d = await r.json();
      if (!d.success) throw new Error(d.error || 'Falha ao carregar');

      hofEdCarregado = true;
      hofEdLinhas = d.linhas || [];
      encherFiltroTimes();
      renderLinhasEditor();
      renderLog(d.historico);
      hofMsg('');
    } catch (e) {
      hofEdGrid.innerHTML = '';
      hofMsg('Erro ao carregar o editor.', 'erro');
    }
  }
</script>
<script src="<?= assetUrl('/js/pwa.js') ?>"></script>
</body>
</html>
