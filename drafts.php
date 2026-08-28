<?php
session_start();
require_once 'backend/auth.php';
require_once 'backend/db.php';
require_once 'backend/helpers.php';

requireAuth();

$user = getUserSession();
$pdo = db();

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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <meta name="theme-color" content="#fc0025" />
  <title>Draft - FBA Manager</title>

  <?php include __DIR__ . '/includes/head-pwa.php'; ?>

  <link rel="icon" type="image/png" href="/img/fba-logo.png?v=3" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <!-- O selo de condição de pick (protegida/swap): mesmo arquivo que o resto
       do site usa, porque esta página tem CSS próprio e não carrega o styles.css. -->
  <link rel="stylesheet" href="/css/pick-cond.css" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

  <style>
    /* ── Tokens ──────────────────────────────────── */
    :root {
      --red:        #fc0025;
      --red-2:      color-mix(in srgb, var(--red) 85%, white);
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
    body {
      font-family: var(--font);
      background: var(--bg);
      color: var(--text);
      -webkit-font-smoothing: antialiased;
    }

    /* ── Shell ───────────────────────────────────── */
    .app { display: flex; min-height: 100vh; }

    /* ── Sidebar ─────────────────────────────────── */
    .sidebar {
      position: fixed; top: 0; left: 0;
      width: 260px; height: 100vh;
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
    .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
    .sb-nav a { font-family:'Inter',sans-serif;
      display: flex; align-items: center; gap: 10px;
      padding: 10px 10px; border-radius: var(--radius-sm);
      color: var(--text-2); font-size: 13px; font-weight: 500;
      text-decoration: none; margin-bottom: 2px;
      transition: all var(--t) var(--ease);
    }
    .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
    .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
    .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
    .sb-nav a.active i { color: var(--red); }

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

    /* ── Topbar mobile ───────────────────────────── */
    .topbar {
      display: none; position: fixed; top: 0; left: 0; right: 0;
      height: 54px; background: var(--panel);
      border-bottom: 1px solid var(--border);
      align-items: center; padding: 0 16px; gap: 12px; z-index: 240;
    }
    .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
    .topbar-title em { color: var(--red); font-style: normal; }
    .menu-btn {
      width: 34px; height: 34px; border-radius: 9px;
      background: var(--panel-2); border: 1px solid var(--border);
      color: var(--text); display: flex; align-items: center; justify-content: center;
      cursor: pointer; font-size: 17px;
    }
    .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
    .sb-overlay.show { display: block; }

    /* ── Main ────────────────────────────────────── */
    .main {
      margin-left: var(--sidebar-w);
      min-height: 100vh;
      width: calc(100% - var(--sidebar-w));
      display: flex; flex-direction: column;
    }

    /* ── Page hero ───────────────────────────────── */
    .page-hero {
      padding: 32px 32px 0;
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: 16px; flex-wrap: wrap;
    }
    .hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
    .hero-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
    .hero-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }
    .hero-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding-top: 4px; }

    /* ── Content ─────────────────────────────────── */
    .content { padding: 20px 32px 40px; flex: 1; }

    /* ── Panel ───────────────────────────────────── */
    .panel {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
    }
    .panel-head {
      padding: 16px 18px;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between; gap: 8px;
    }
    .panel-title { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .panel-title i { color: var(--red); font-size: 15px; }
    .panel-body { padding: 18px; }

    /* ── Pick cards ──────────────────────────────── */
    .pick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }

    .pick-card {
      background: var(--panel-2);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 12px;
      transition: all var(--t) var(--ease);
    }
    .pick-card.current {
      border-color: var(--red);
      box-shadow: 0 0 0 2px var(--red-glow);
      animation: pulsePick 2s infinite;
    }
    .pick-card.completed { opacity: .8; }
    .pick-card.my-pick { background: var(--red-soft); border-color: var(--border-red); }
    .pick-card.clickable { cursor: pointer; }
    .pick-card.clickable:hover { border-color: var(--border-red); transform: translateY(-2px); }

    @keyframes pulsePick {
      0%, 100% { box-shadow: 0 0 0 0 var(--red-glow); }
      50%       { box-shadow: 0 0 0 8px transparent; }
    }

    .pick-num { font-size: 10px; font-weight: 700; color: var(--text-3); margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between; }
    .pick-badge { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; }
    .pick-badge.done { background: rgba(34,197,94,.15); color: var(--green); border: 1px solid rgba(34,197,94,.25); }
    .pick-badge.pending { background: var(--panel-3); color: var(--text-3); border: 1px solid var(--border); }
    .pick-badge.active { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }

    .pick-team { font-size: 12px; font-weight: 600; text-align: center; color: var(--text); margin-bottom: 6px; }
    .pick-via { font-size: 10px; color: var(--amber); display: flex; align-items: center; justify-content: center; gap: 4px; margin-bottom: 4px; }

    .pick-result {
      background: rgba(34,197,94,.08);
      border: 1px solid rgba(34,197,94,.15);
      border-radius: 7px;
      padding: 6px 8px;
      text-align: center;
    }
    .pick-result-name { font-size: 11px; font-weight: 700; color: var(--green); }
    .pick-result-meta { font-size: 10px; color: var(--text-2); margin-top: 2px; }

    .pick-waiting {
      background: var(--panel-3);
      border: 1px solid var(--border);
      border-radius: 7px;
      padding: 6px 8px;
      text-align: center;
    }
    .pick-waiting span { font-size: 10px; color: var(--text-3); }

    .pick-trade-btn {
      display: flex; align-items: center; justify-content: center;
      width: 22px; height: 22px; border-radius: 5px;
      background: transparent; border: 1px solid var(--border);
      color: var(--text-2); font-size: 11px; cursor: pointer;
      transition: all var(--t) var(--ease);
    }
    .pick-trade-btn:hover { border-color: var(--amber); color: var(--amber); background: rgba(245,158,11,.08); }

    /* ── Round header ────────────────────────────── */
    .round-head {
      display: flex; align-items: center; gap: 10px;
      padding: 14px 18px;
      border-bottom: 1px solid var(--border);
    }
    .round-badge {
      width: 28px; height: 28px; border-radius: 8px;
      background: var(--red-soft); border: 1px solid var(--border-red);
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 800; color: var(--red);
      flex-shrink: 0;
    }
    .round-title { font-size: 14px; font-weight: 700; }
    .round-count { margin-left: auto; font-size: 11px; color: var(--text-2); }

    /* ── Status header ───────────────────────────── */
    .draft-status-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }

    .status-card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 16px 18px;
    }
    .status-card.highlight { border-color: var(--border-red); background: var(--red-soft); }
    .status-card.turn { border-color: var(--green); background: rgba(34,197,94,.06); }

    .status-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
    .status-label i { font-size: 12px; color: var(--red); }
    .status-val { font-size: 15px; font-weight: 700; }
    .status-sub { font-size: 11px; color: var(--text-2); margin-top: 2px; }

    .draft-status-pill {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700;
    }
    .draft-status-pill.setup  { background: rgba(245,158,11,.12); color: var(--amber); border: 1px solid rgba(245,158,11,.25); }
    .draft-status-pill.active { background: rgba(34,197,94,.12); color: var(--green); border: 1px solid rgba(34,197,94,.25); }
    .draft-status-pill.done   { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); }

    .pun-badge {
      display: inline-flex; align-items: center;
      padding: 2px 8px; border-radius: 999px;
      font-size: 10px; font-weight: 700;
      background: var(--panel-3); color: var(--text-3);
      border: 1px solid var(--border);
    }
    .pun-badge.pending { background: rgba(245,158,11,.12); color: var(--amber); border-color: rgba(245,158,11,.3); }
    .pun-badge.won      { background: rgba(34,197,94,.12); color: var(--green); border-color: rgba(34,197,94,.3); }
    .pun-badge.lost     { background: rgba(239,68,68,.12); color: var(--red); border-color: rgba(239,68,68,.3); }

    /* ── My turn banner ──────────────────────────── */
    .my-turn-banner {
      background: linear-gradient(90deg, rgba(34,197,94,.12), rgba(34,197,94,.04));
      border: 1px solid rgba(34,197,94,.3);
      border-left: 3px solid var(--green);
      border-radius: var(--radius-sm);
      padding: 14px 18px;
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 20px;
    }
    .my-turn-left { display: flex; align-items: center; gap: 12px; }
    .my-turn-icon { width: 36px; height: 36px; border-radius: 9px; background: var(--green); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; flex-shrink: 0; }
    .my-turn-title { font-size: 15px; font-weight: 800; color: var(--green); }
    .my-turn-sub { font-size: 12px; color: var(--text-2); }

    /* ── Round 2 admin panel ─────────────────────── */
    .r2-panel {
      background: var(--panel-2);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 18px;
      margin-bottom: 14px;
    }
    .r2-panel-title { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
    .r2-panel-title i { color: var(--red); }

    /* ── History cards ───────────────────────────── */
    .history-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
    .hist-card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 18px;
      transition: all var(--t) var(--ease);
    }
    .hist-card:hover { border-color: var(--border-red); transform: translateY(-2px); }
    .hist-card-head { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; }
    .hist-season { font-size: 15px; font-weight: 700; }
    .hist-league { font-size: 11px; color: var(--text-2); margin-top: 2px; }

    /* ── League selector ─────────────────────────── */
    .league-sel-bar {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 14px 18px;
      display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
      margin-bottom: 18px;
    }
    .league-sel-label { font-size: 12px; font-weight: 600; color: var(--text-2); flex-shrink: 0; }

    /* ── Finalize bar ────────────────────────────── */
    .finalize-bar {
      background: var(--panel);
      border: 1px solid var(--border-red);
      border-radius: var(--radius-sm);
      padding: 16px 18px;
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      flex-wrap: wrap;
      margin-top: 20px;
    }
    .finalize-title { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .finalize-title i { color: var(--red); }
    .finalize-sub { font-size: 11px; color: var(--text-2); }

    /* ── Buttons ─────────────────────────────────── */
    .btn-red {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 10px 18px; border-radius: 9px;
      background: var(--red); border: none; color: #fff;
      font-family: var(--font); font-size: 13px; font-weight: 600;
      cursor: pointer; transition: filter var(--t) var(--ease);
      text-decoration: none;
    }
    .btn-red:hover { filter: brightness(1.1); color: #fff; }
    .btn-red:disabled { opacity: .5; cursor: not-allowed; }

    .btn-ghost {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 10px 18px; border-radius: 9px;
      background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
      font-family: var(--font); font-size: 13px; font-weight: 600;
      cursor: pointer; transition: all var(--t) var(--ease);
      text-decoration: none;
    }
    .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

    .btn-ghost-sm {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 12px; border-radius: 7px;
      background: transparent; border: 1px solid var(--border); color: var(--text-2);
      font-family: var(--font); font-size: 12px; font-weight: 600;
      cursor: pointer; transition: all var(--t) var(--ease);
      text-decoration: none;
    }
    .btn-ghost-sm:hover { border-color: var(--border-red); color: var(--red); }

    .btn-green {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 10px 18px; border-radius: 9px;
      background: var(--green); border: none; color: #fff;
      font-family: var(--font); font-size: 13px; font-weight: 600;
      cursor: pointer; transition: filter var(--t) var(--ease);
    }
    .btn-green:hover { filter: brightness(1.1); }

    /* ── Empty / info states ─────────────────────── */
    .state-empty {
      padding: 24px 16px;
      text-align: center; color: var(--text-3);
    }
    .state-empty i { font-size: 28px; display: block; margin-bottom: 8px; }
    .state-empty p { font-size: 12px; }

    .info-note {
      background: rgba(245,158,11,.08);
      border: 1px solid rgba(245,158,11,.2);
      border-left: 3px solid var(--amber);
      border-radius: 8px;
      padding: 12px 14px;
      font-size: 12px; color: var(--text-2);
      margin-bottom: 16px;
    }
    .info-note strong { color: var(--text); }

    /* Mock do draft (status "configurando"): a classe na ordem, com o time
       que pegaria cada pick. */
    .mock-list { display: flex; flex-direction: column; gap: 6px; }
    .mock-row {
      display: grid;
      grid-template-columns: 46px 1fr 1fr;
      align-items: center;
      gap: 12px;
      padding: 9px 12px;
      background: var(--panel-2, rgba(255,255,255,.02));
      border: 1px solid var(--border);
      border-radius: 8px;
    }
    .mock-pick {
      font-weight: 800; font-size: 13px; color: var(--amber);
      font-variant-numeric: tabular-nums;
    }
    /* A rodada só aparece quando não é a 1ª — na 1ª o "R1" em toda linha
       seria ruído, já que é o caso normal. */
    .mock-pick-r {
      display: block; font-size: 9px; font-weight: 700;
      color: var(--text-3); letter-spacing: .5px;
    }
    .mock-player-name { font-size: 13px; font-weight: 700; color: var(--text); }
    .mock-player-pos  { font-size: 11px; color: var(--text-3); margin-top: 1px; }
    .mock-team {
      min-width: 0;
      display: flex; align-items: center; justify-content: flex-end; gap: 9px;
    }
    /* O escudo fica DEPOIS do nome no fim da linha — na leitura da direita
       pra esquerda ele é a âncora visual, e antes do texto empurraria o nome
       pro meio da linha. */
    .mock-team-logo   { order: 2; width: 26px; height: 26px; object-fit: contain; flex: none; }
    .mock-team-txt    { order: 1; min-width: 0; text-align: right; }
    .mock-team-name   { font-size: 13px; font-weight: 600; color: var(--text-2); }
    .mock-selos       {
      display: flex; flex-wrap: wrap; gap: 4px; justify-content: flex-end; margin-top: 3px;
    }
    .mock-team-via    {
      font-size: 11px; color: var(--text-3); margin-top: 1px;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    /* No celular a linha vira duas: jogador em cima, time embaixo — com três
       colunas de 1fr o nome do time quebrava no meio. */
    @media (max-width: 560px) {
      .mock-row { grid-template-columns: 38px 1fr; row-gap: 4px; }
      .mock-team { grid-column: 2; justify-content: flex-start; }
      .mock-team-logo { order: 1; width: 22px; height: 22px; }
      .mock-team-txt  { order: 2; text-align: left; }
      .mock-selos     { justify-content: flex-start; }
    }
    .info-note.blue {
      background: rgba(59,130,246,.08);
      border-color: rgba(59,130,246,.2);
      border-left-color: var(--blue);
    }

    /* ── Form controls (modal) ───────────────────── */
    .field-label { font-size: 12px; font-weight: 600; color: var(--text-2); margin-bottom: 6px; display: block; }
    .field-input {
      width: 100%;
      background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: 8px; padding: 10px 12px;
      color: var(--text); font-family: var(--font); font-size: 13px;
      outline: none; transition: border-color var(--t) var(--ease);
    }
    .field-input:focus { border-color: var(--red); }
    .field-input::placeholder { color: var(--text-3); }

    /* ── Player select card ──────────────────────── */
    .player-chip {
      position: relative;
      background: var(--panel-2);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 12px;
      text-align: center;
      cursor: pointer;
      transition: all var(--t) var(--ease);
    }
    .player-chip:hover { border-color: var(--border-red); transform: translateY(-2px); background: var(--panel-3); }
    .player-chip-name { font-size: 13px; font-weight: 600; margin-bottom: 6px; }
    .player-chip-pos { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); margin-right: 4px; }
    .player-chip-ovr { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; background: rgba(34,197,94,.1); color: var(--green); border: 1px solid rgba(34,197,94,.2); }
    .player-chip-age { font-size: 11px; color: var(--text-2); margin-top: 6px; }
    /* Número da ordem (pick_hint) — destacado, sempre no mesmo canto, mesma
       posição fixa do jogador na lista, saia ele ou não (ver .drafted). */
    .player-chip-order {
      position: absolute; top: -8px; left: -8px;
      width: 22px; height: 22px; border-radius: 50%;
      background: var(--red); color: #fff;
      font-size: 11px; font-weight: 800;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 2px 6px -1px rgba(0,0,0,.4);
    }
    /* Jogador já draftado: continua na posição/ordem dele, só fica cinza. */
    .player-chip.drafted { cursor: not-allowed; opacity: .5; filter: grayscale(.6); }
    .player-chip.drafted:hover { transform: none; border-color: var(--border); background: var(--panel-2); }
    .player-chip.drafted .player-chip-order { background: var(--text-3); }
    .player-chip-drafted-tag { font-size: 10px; font-weight: 700; color: var(--text-3); margin-top: 4px; text-transform: uppercase; letter-spacing: .3px; }

    /* ── Modal overrides ─────────────────────────── */
    .modal-content {
      background: var(--panel);
      border: 1px solid var(--border-md);
      border-radius: var(--radius);
      color: var(--text);
      font-family: var(--font);
    }
    .modal-header {
      border-bottom: 1px solid var(--border);
      padding: 18px 20px;
    }
    .modal-header .modal-title { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .modal-header .modal-title i { color: var(--red); }
    .modal-body { padding: 20px; }
    .modal-footer { border-top: 1px solid var(--border); padding: 14px 20px; gap: 8px; }

    /* ── Mock Draft Card ────────────────────────────── */
    .mock-badge {
      font-size: 10px; font-weight: 700; padding: 2px 8px;
      border-radius: 99px;
    }
    .mock-badge.on  { background: rgba(34,197,94,.15); color: var(--green); border: 1px solid rgba(34,197,94,.3); }
    .mock-badge.off { background: var(--panel-2); color: var(--text-3); border: 1px solid var(--border); }
    .mock-queue-list {
      display: flex; flex-direction: column; gap: 6px;
      margin-bottom: 10px;
    }
    .mock-queue-empty { font-size: 12px; color: var(--text-3); padding: 8px 0; }
    .mock-queue-item {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 10px;
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: 8px;
    }
    .mock-queue-num { font-weight: 700; font-size: 11px; color: var(--text-3); width: 16px; flex-shrink: 0; text-align: center; }
    .mock-queue-name { flex: 1; font-size: 12px; font-weight: 600; }
    .mock-queue-meta { font-size: 11px; color: var(--text-2); white-space: nowrap; }
    .mock-queue-del {
      background: none; border: none; cursor: pointer;
      color: var(--text-3); padding: 2px 4px; border-radius: 4px;
      font-size: 13px; line-height: 1;
      transition: color var(--t) var(--ease);
    }
    .mock-queue-del:hover { color: var(--red); }

    /* ── Responsive ──────────────────────────────── */
    @media (max-width: 992px) {
      :root { --sidebar-w: 0px; }
      .sidebar { transform: translateX(-260px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; width: 100%; padding-top: 54px; }
      .topbar { display: flex; }
      .page-hero, .content { padding-left: 16px; padding-right: 16px; }
      .page-hero { padding-top: 18px; }
      .draft-status-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 600px) {
      .pick-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
    }
  input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible,[tabindex]:focus-visible{outline:2px solid var(--red, #fc0025);outline-offset:2px;}
  @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; animation-delay: 0ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; transition-delay: 0ms !important; scroll-behavior: auto !important; } }
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

    <!-- Hero header -->
    <div class="page-hero">
      <div>
        <div class="hero-eyebrow">Draft · <?= htmlspecialchars($userLeague) ?></div>
        <h1 class="hero-title">Draft</h1>
        <p class="hero-sub">Ordem de seleção de jogadores da liga</p>
      </div>
      <div class="hero-actions">
        <button class="btn-ghost" onclick="openBigBoardModal()">
          <i class="bi bi-list-ol"></i>
          <span>Ver Jogadores</span>
        </button>
        <?php if ($isAdmin): ?>
        <button class="btn-ghost" onclick="openAdminMocksModal()">
          <i class="bi bi-eye-fill"></i>
          <span>Ver Mocks</span>
        </button>
        <?php endif; ?>
        <button class="btn-ghost" onclick="toggleHistoryView()">
          <i class="bi bi-clock-history"></i>
          <span id="viewToggleText">Ver Histórico</span>
        </button>
      </div>
    </div>

    <div class="content">

      <!-- View do Draft Ativo -->
      <div id="activeDraftView">
        <div id="draftContainer">
          <div class="state-empty">
            <i class="bi bi-hourglass-split"></i>
            <p>Carregando draft...</p>
          </div>
        </div>
      </div>

      <!-- View do Histórico de Drafts (oculta por padrão) -->
      <div id="historyView" style="display: none;">
        <?php if ($isAdmin): ?>
        <div class="league-sel-bar">
          <span class="league-sel-label"><i class="bi bi-funnel" style="color:var(--red)"></i> Liga:</span>
          <select id="leagueSelector" class="field-input" style="max-width:200px" onchange="loadHistoryForLeague()">
            <option value="ELITE">ELITE</option>
            <option value="NEXT">NEXT</option>
            <option value="RISE">RISE</option>
            <option value="ROOKIE">ROOKIE</option>
          </select>
          <span id="selectedLeagueBadge" style="font-size:11px;font-weight:700;color:var(--red);margin-left:auto"></span>
        </div>
        <?php endif; ?>
        <div id="historyContainer">
          <div class="state-empty">
            <i class="bi bi-hourglass-split"></i>
            <p>Carregando histórico...</p>
          </div>
        </div>
      </div>

      <!-- Finalizar Draft (Admin) -->
      <?php if ($isAdmin): ?>
      <div id="finalizeDraftContainer" style="display: none;">
        <div class="finalize-bar">
          <div>
            <div class="finalize-title"><i class="bi bi-flag-fill"></i> Finalizar Draft</div>
            <div class="finalize-sub">Use quando a seleção estiver concluída.</div>
          </div>
          <button class="btn-red" onclick="finalizeDraft()">
            <i class="bi bi-check2-circle"></i> Finalizar Draft
          </button>
        </div>
      </div>
      <?php endif; ?>

      <?php /* CONFERÊNCIA DAS PICKS, no fim da página.
               Uma pick comprada que não chega ao dono só aparece na hora de
               escolher, quando já é tarde. Aqui dá pra perguntar antes — e
               perguntar não muda nada: o botão só compara a ordem com a
               tabela de picks e conta o que achou. */ ?>
      <div class="finalize-bar" style="margin-top:18px">
        <div>
          <div class="finalize-title"><i class="bi bi-clipboard-check"></i> Revisar picks</div>
          <div class="finalize-sub">Confere se cada escolha está com o dono certo — trocas, swaps e proteções. Não altera nada.</div>
        </div>
        <button class="btn-ghost" id="btnRevisarPicks" onclick="revisarPicks()">
          <i class="bi bi-search"></i> Revisar picks
        </button>
      </div>
      <div id="revisaoPicks" style="display:none;margin-top:12px"></div>

    </div><!-- .content -->
  </main>
</div><!-- .app -->

<!-- ══════════════════════════════════════════════
     MODAIS
══════════════════════════════════════════════ -->

<!-- Modal: Escolher jogador (round 1) -->
<div class="modal fade" id="pickModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-person-plus"></i> <span id="pickModalTitle">Escolher Jogador</span></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="playerSearch" class="field-input mb-3" placeholder="Buscar jogador por nome ou posição…">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <span style="font-size:12px;color:var(--text-2)">Jogadores disponíveis</span>
          <span style="font-size:12px;color:var(--text-2)" id="availablePlayersCount">0</span>
        </div>
        <div id="availablePlayers" class="pick-grid">
          <div class="state-empty" style="grid-column:1/-1">
            <i class="bi bi-hourglass-split"></i><p>Carregando…</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Ver Jogadores — todos os jogadores da temporada, ordenados (tipo mock 2K); já
     draftado continua na posição dele, só fica cinza (não reordena/some da lista) -->
<div class="modal fade" id="bigBoardModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-list-ol"></i> Ver Jogadores</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="bigBoardSearch" class="field-input mb-3" placeholder="Buscar jogador por nome ou posição…">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <span style="font-size:12px;color:var(--text-2)">Todos os jogadores, em ordem</span>
          <span style="font-size:12px;color:var(--text-2)" id="bigBoardCount">0</span>
        </div>
        <div id="bigBoardPlayers" class="pick-grid">
          <div class="state-empty" style="grid-column:1/-1">
            <i class="bi bi-hourglass-split"></i><p>Carregando…</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: escolher o mock de uma pick da 2ª rodada -->
<div class="modal fade" id="round2MockModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-send-check"></i> Mock — <span id="round2MockPickLabel"></span></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="round2MockSearch" class="field-input mb-3" placeholder="Buscar jogador por nome ou posição…">
        <div id="round2MockPlayers" class="pick-grid">
          <div class="state-empty" style="grid-column:1/-1">
            <i class="bi bi-hourglass-split"></i><p>Carregando…</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Preencher pick passada (Admin) -->
<div class="modal fade" id="fillPastPickModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-pencil-square"></i> Preencher Pick — <span id="fillPickTeamName"></span></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="pastPlayerSearch" class="field-input mb-3" placeholder="Buscar jogador…">
        <div id="pastPlayersDropdown" class="pick-grid">
          <div class="state-empty" style="grid-column:1/-1">
            <i class="bi bi-hourglass-split"></i><p>Carregando…</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Trocar pick -->
<div class="modal fade" id="tradePickModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-arrow-left-right"></i> Trocar Pick</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:13px;color:var(--text-2);margin-bottom:14px" id="tradePickInfo">Pick selecionada</p>
        <label class="field-label">Novo time dono da pick</label>
        <select id="tradePickTeamSelect" class="field-input">
          <option value="">Selecione o time…</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-red" onclick="submitTradePick()">
          <i class="bi bi-check2-circle"></i> Confirmar troca
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Admin — Ver Mocks dos Times -->
<div class="modal fade" id="adminMocksModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-eye-fill me-2" style="color:var(--amber)"></i>Mocks dos Times</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="adminMocksBody">
        <div style="display:flex;align-items:center;justify-content:center;padding:40px">
          <div class="spinner-border" role="status" style="color:var(--red);width:1.8rem;height:1.8rem"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Mock Draft — Gerenciar fila -->
<div class="modal fade" id="mockManageModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-list-stars"></i> Mock Draft — Minha Fila</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:12px;color:var(--text-2);margin-bottom:14px">
          Defina até 8 jogadores em ordem de preferência. Com auto-pick ativo, o sistema escolhe o primeiro disponível após 30 min na sua vez.
        </p>

        <!-- Fila atual -->
        <div style="margin-bottom:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <span style="font-size:12px;font-weight:700;color:var(--text-2)">FILA ATUAL <span id="mockQueueCountBadge" style="color:var(--text-3)"></span></span>
          </div>
          <div id="mockQueueListModal" class="mock-queue-list" style="min-height:40px"></div>
        </div>

        <!-- Adicionar jogador -->
        <div style="border-top:1px solid var(--border);padding-top:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <span style="font-size:12px;font-weight:700;color:var(--text-2)">ADICIONAR DA LISTA</span>
            <span style="font-size:11px;color:var(--text-3)" id="mockAvailableCount">0 disponíveis</span>
          </div>
          <input type="text" id="mockPlayerSearch" class="field-input mb-3" placeholder="Buscar por nome ou posição…">
          <div id="mockAvailablePlayers" class="pick-grid">
            <div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // ── Sidebar toggle ────────────────────────────────
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

  // ── Draft JS ──────────────────────────────────────
  const userLeague = '<?= $userLeague ?>';
  const userTeamId = <?= (int)$team['id'] ?>;
  const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
  let currentDraftSession = null;
  let availablePlayersList = [];
  let allPlayersList = [];
  let refreshInterval = null;
  let currentView = 'active'; // 'active' ou 'history'
  let currentPickForFill = null;
  let currentDraftSessionForFill = null;
  let currentSeasonIdView = null;
  let currentDraftStatusView = null;
  let selectedLeague = userLeague;
  let currentDraftPicks = [];
  let currentPickForTrade = null;
  let allowPickSelections = true;

  const esc = s => (s || '').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  const api = async (path, options = {}) => {
    const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
    let body = {};
    try { body = await res.json(); } catch {}
    if (!res.ok) throw body;
    if (body && body.success === false) throw body;
    return body;
  };

  async function loadDraft() {
    try {
      const draftData = await api(`draft.php?action=active_draft&league=${userLeague}`);

      if (!draftData.draft) {
        // Sem draft ativo — mostra histórico automaticamente
        if (currentView === 'active') {
          currentView = 'history';
          document.getElementById('activeDraftView').style.display = 'none';
          document.getElementById('historyView').style.display = 'block';
          const toggleText = document.getElementById('viewToggleText');
          if (toggleText) toggleText.textContent = 'Ver Draft Ativo';
          selectedLeague = userLeague;
          const leagueSel = document.getElementById('leagueSelector');
          if (leagueSel) leagueSel.value = userLeague;
          const badge = document.getElementById('selectedLeagueBadge');
          if (badge) badge.textContent = userLeague;
          loadHistory();
        }
        return;
      }

      currentDraftSession = draftData.draft;
      const orderData = await api(`draft.php?action=draft_order&draft_session_id=${currentDraftSession.id}`);
      const picks = orderData.order || [];
      const session = orderData.session;
      currentDraftPicks = picks;
      renderDraft(session, picks);

      const isAdminRound2 = isAdmin && session.status === 'in_progress' && Number(session.current_round) === 2;
      if (session.status === 'in_progress' && !isAdminRound2) {
        if (refreshInterval) clearInterval(refreshInterval);
        refreshInterval = setInterval(loadDraft, 10000);
        checkAutopick();
      } else {
        if (refreshInterval) clearInterval(refreshInterval);
      }
    } catch (e) {
      console.error(e);
      document.getElementById('draftContainer').innerHTML = `
        <div class="state-empty" style="color:#ef4444">
          <i class="bi bi-exclamation-circle"></i>
          <p>Erro ao carregar draft: ${e.error || 'Desconhecido'}</p>
        </div>
      `;
    }
  }

  function initTooltips() {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
      bootstrap.Tooltip.getOrCreateInstance(el, { trigger: 'hover focus' });
    });
  }

  function statusPill(status) {
    const map = {
      'setup':       '<span class="draft-status-pill setup"><i class="bi bi-gear-fill"></i> Configurando</span>',
      'in_progress': '<span class="draft-status-pill active"><i class="bi bi-play-fill"></i> Em Andamento</span>',
      'completed':   '<span class="draft-status-pill done"><i class="bi bi-check2"></i> Concluído</span>'
    };
    return map[status] || map['completed'];
  }

  function renderDraft(session, picks) {
    const round1Picks = picks.filter(p => p.round == 1);
    const round2Raw   = picks.filter(p => p.round == 2);
    const round1OrderMap = new Map(round1Picks.map(p => [String(p.team_id), Number(p.pick_position)]));
    const round2Picks = [...round2Raw].sort((a, b) => {
      const aOrder = round1OrderMap.get(String(a.team_id)) ?? a.pick_position;
      const bOrder = round1OrderMap.get(String(b.team_id)) ?? b.pick_position;
      return aOrder - bOrder;
    });

    let currentPickInfo = null;
    if (session.status === 'in_progress') {
      const allPicks = [...round1Picks, ...round2Raw];
      currentPickInfo = allPicks.find(p => p.round == session.current_round && p.pick_position == session.current_pick && !p.picked_player_id);
    }

    const isMyTurn = currentPickInfo && parseInt(currentPickInfo.team_id) === userTeamId && session.current_round == 1;
    const showRound2Team = session.status === 'in_progress' && session.current_round == 2 && userTeamId;
    // Relógio da 1ª rodada (admin agenda em js/admin.js): antes da hora marcada, só um aviso
    // informativo; depois dela, uma contagem regressiva de 5min pra pick atual (o backend já
    // faz o autopick pela ordem geral se ninguém escolher a tempo — isso aqui é só exibição).
    const round1ClockInfo = (session.status === 'in_progress' && Number(session.current_round) === 1 && session.round1_clock_start_at)
      ? getRound1ClockInfo(session)
      : null;
    const round2History = round2Raw
      .filter(p => p.picked_player_id)
      .sort((a, b) => {
        const aPos = round1OrderMap.get(String(a.team_id)) ?? a.pick_position;
        const bPos = round1OrderMap.get(String(b.team_id)) ?? b.pick_position;
        return aPos - bPos;
      });

    // Status grid
    let currentPickLabel = '—';
    if (session.status === 'in_progress') {
      if (Number(session.current_round) === 2) {
        currentPickLabel = '2ª rodada';
      } else if (currentPickInfo) {
        currentPickLabel = `${esc(currentPickInfo.team_city)} ${esc(currentPickInfo.team_name)}`;
      }
    } else if (session.status === 'setup') {
      currentPickLabel = 'Aguardando início';
    } else {
      currentPickLabel = 'Draft concluído';
    }

    let html = '';

    // Resultado final — draft encerrado
    if (session.status === 'completed') {
      const totalPicked = picks.filter(p => p.picked_player_id).length;
      html += `
        <div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-left:3px solid var(--green);border-radius:var(--radius-sm);padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:14px">
            <div style="font-size:28px;line-height:1">🏆</div>
            <div>
              <div style="font-size:16px;font-weight:800;color:var(--green)">Draft Encerrado</div>
              <div style="font-size:12px;color:var(--text-2);margin-top:2px">Temporada ${session.season_number} · ${session.year} · ${totalPicked} picks registradas</div>
            </div>
          </div>
          <button class="btn-ghost-sm" onclick="toggleHistoryView()"><i class="bi bi-clock-history"></i> Ver todos os drafts</button>
        </div>
      `;
    }

    // My turn banner
    if (isMyTurn) {
      html += `
        <div class="my-turn-banner">
          <div class="my-turn-left">
            <div class="my-turn-icon">🎉</div>
            <div>
              <div class="my-turn-title">É a sua vez!</div>
              <div class="my-turn-sub">Rodada ${session.current_round} · Pick #${session.current_pick}</div>
            </div>
          </div>
          <button class="btn-green" onclick="openPickModal()"><i class="bi bi-person-plus"></i> Fazer Pick</button>
        </div>
      `;
    }

    // Status cards
    html += `
      <div class="draft-status-grid">
        <div class="status-card">
          <div class="status-label"><i class="bi bi-calendar3"></i> Temporada</div>
          <div class="status-val">T${session.season_number || currentDraftSession.season_number} · ${session.year || currentDraftSession.year}</div>
          <div class="status-sub">${userLeague}</div>
        </div>
        <div class="status-card">
          <div class="status-label"><i class="bi bi-activity"></i> Status</div>
          <div class="status-val">${statusPill(session.status)}</div>
          <div class="status-sub">${session.status === 'in_progress' ? `Rodada ${session.current_round} · Pick ${session.current_pick}` : ''}</div>
        </div>
        <div class="status-card">
          <div class="status-label"><i class="bi bi-cursor"></i> Vez de</div>
          <div class="status-val" style="font-size:13px">${currentPickLabel}</div>
          ${session.status === 'in_progress' && !isMyTurn ? `<button class="btn-ghost-sm" style="margin-top:8px" onclick="openOptionsModal()"><i class="bi bi-eye"></i> Ver disponíveis</button>` : ''}
        </div>
        <div class="status-card" id="mockCardContainer" style="cursor:default">
          <div class="status-label" style="display:flex;align-items:center;gap:5px">
            <i class="bi bi-list-stars" style="color:var(--amber)"></i> Mock Draft
            <span tabindex="0" data-bs-toggle="tooltip" data-bs-placement="bottom"
              title="Selecione até 8 jogadores em ordem de prioridade. Com auto-pick ativo, o sistema escolhe imediatamente o primeiro disponível da fila quando chegar sua vez. Se nenhum estiver disponível, aguarda você escolher."
              style="color:var(--text-3);cursor:help;line-height:1">
              <i class="bi bi-info-circle"></i>
            </span>
          </div>
          <div id="mockCardBody" style="font-size:12px;color:var(--text-3)">Carregando…</div>
        </div>
      </div>
    `;

    if (isAdmin && session.status === 'setup') {
      html += `
        <div class="info-note" style="margin-bottom:20px">
          <strong>Admin:</strong> Configure a ordem do draft na página de <strong>admin</strong> e inicie quando estiver pronto.
        </div>
      `;
    }

    // Draft ainda sendo configurado: no lugar das rodadas, o mock.
    //
    // Os cards de 1ª e 2ª rodada saem da tela porque nesse momento eles são
    // duas caixas vazias — a ordem só existe depois da loteria, e até lá não
    // há uma única pick pra mostrar. O que existe é a classe, e é ela que a
    // liga quer ver.
    if (session.status === 'setup') {
      html += `
        <div class="panel" id="setupPreviewPanel">
          <div class="round-head">
            <div class="round-badge"><i class="bi bi-list-stars"></i></div>
            <span class="round-title">Mock do Draft</span>
            <span class="round-count" id="setupPreviewCount"></span>
          </div>
          <div class="panel-body">
            <div id="setupPreviewBody" class="state-empty">
              <i class="bi bi-hourglass-split"></i><p>Carregando a classe…</p>
            </div>
          </div>
        </div>
      `;
      document.getElementById('draftContainer').innerHTML = html;
      loadMockCard(session);
      initTooltips();
      const fc = document.getElementById('finalizeDraftContainer');
      if (fc) fc.style.display = 'none';
      loadSetupPreview();
      return;
    }

    // Round 1
    let round1ClockHtml = '';
    if (round1ClockInfo) {
      if (!round1ClockInfo.armed) {
        const d = new Date(round1ClockInfo.clockStartMs);
        const dateLabel = d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        round1ClockHtml = `<div style="font-size:12px;color:var(--text-2);margin-bottom:12px"><i class="bi bi-clock"></i> Escolhas livres até ${dateLabel} — depois disso, 5min por pick.</div>`;
      } else {
        round1ClockHtml = `<div id="round1ClockCountdown" style="font-size:13px;font-weight:700;margin-bottom:12px"></div>`;
      }
    }
    html += `
      <div class="panel" style="margin-bottom:14px">
        <div class="round-head">
          <div class="round-badge">1</div>
          <span class="round-title">1ª Rodada</span>
          <span class="round-count">${round1Picks.length} picks</span>
        </div>
        <div class="panel-body">
          ${round1ClockHtml}
          <div class="pick-grid">
            ${round1Picks.map(p => renderPickCard(p, session)).join('')}
          </div>
        </div>
      </div>
    `;

    // Round 2
    html += `<div class="panel">`;
    html += `
      <div class="round-head">
        <div class="round-badge">2</div>
        <span class="round-title">2ª Rodada</span>
        <span class="round-count">${round2History.length} registradas</span>
      </div>
      <div class="panel-body">
    `;

    if (showRound2Team) {
      html += `
        <div class="r2-panel" style="margin-bottom:14px">
          <div class="r2-panel-title"><i class="bi bi-send-check"></i> Mock da 2ª rodada</div>
          <p style="font-size:12px;color:var(--text-2);margin-bottom:6px">
            20 minutos depois da última pick da 1ª rodada, o sistema resolve sozinho: cada pick com
            mock leva o jogador escolhido, se ele ainda estiver disponível. É opcional — quem não
            enviar mock fica em aberto pro admin preencher depois.
          </p>
          <div id="round2Countdown" style="font-size:13px;font-weight:700;margin-bottom:12px"></div>
          <div id="round2Board"><div class="state-empty"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div></div>
        </div>
      `;
    }

    if (round2History.length > 0) {
      html += `
        <div style="margin-bottom:10px;display:flex;align-items:center;gap:8px">
          <span style="font-size:13px;font-weight:700">Histórico da 2ª rodada</span>
          <span style="font-size:11px;color:var(--text-2)">(${round2History.length})</span>
        </div>
        <div class="pick-grid">
          ${round2History.map(pick => `
            <div class="pick-card completed">
              <div class="pick-num"><span class="pick-badge done"><i class="bi bi-check2"></i> R2</span></div>
              <div class="pick-team">${esc(pick.team_city)} ${esc(pick.team_name)}</div>
              <div class="pick-result">
                <div class="pick-result-name">${esc(pick.player_name)}</div>
              </div>
            </div>
          `).join('')}
        </div>
      `;
    } else {
      html += `<div class="state-empty"><i class="bi bi-clock"></i><p>Nenhuma pick registrada na 2ª rodada.</p></div>`;
    }

    html += `</div></div>`;

    document.getElementById('draftContainer').innerHTML = html;

    loadMockCard(session);
    initTooltips();

    const finalizeContainer = document.getElementById('finalizeDraftContainer');
    if (finalizeContainer) {
      finalizeContainer.style.display = (session.status === 'in_progress') ? 'block' : 'none';
    }

    if (showRound2Team) {
      loadRound2Board();
    }
    if (round1ClockInfo && round1ClockInfo.armed && round1ClockInfo.deadlineMs) {
      startRound1Countdown(round1ClockInfo.deadlineMs);
    }
  }

  /**
   * O mock de antes da loteria: a classe na ordem, e quem pegaria cada pick.
   *
   * A ordem dos times é PROJEÇÃO — sai do power ranking de trás pra frente,
   * como o 2K faz antes do sorteio. Está escrito na tela justamente pra
   * ninguém confundir com a ordem de verdade, que só nasce da campanha.
   *
   * Do calouro só saem ordem, nome e posição. OVR e idade ficam de fora
   * porque todo mundo entra no pool com o mesmo 60/18 — mostrar esses
   * números seria inventar uma informação que ainda não existe.
   */
  async function loadSetupPreview() {
    const body  = document.getElementById('setupPreviewBody');
    const count = document.getElementById('setupPreviewCount');
    if (!body) return;

    let d;
    try {
      const r = await fetch(`api/draft.php?action=draft_preview&league=${encodeURIComponent(userLeague)}`);
      d = await r.json();
      if (!d.success) throw new Error(d.error || 'não deu');
    } catch (e) {
      body.className = 'state-empty';
      body.innerHTML = `<i class="bi bi-exclamation-triangle"></i><p>Não foi possível carregar o mock.</p>`;
      return;
    }

    const pool = d.pool || [], proj = d.projecao || [];
    if (!pool.length) {
      body.className = 'state-empty';
      body.innerHTML = `<i class="bi bi-inbox"></i><p>A classe deste draft ainda não tem jogadores.</p>`;
      return;
    }

    if (count) count.textContent = `${pool.length} calouros`;

    const linha = (j, p) => `
      <div class="mock-row">
        <div class="mock-pick">${p
          ? (Number(p.rodada) > 1
              ? `<span class="mock-pick-r">R${p.rodada}</span>#${p.pick_na_rodada}`
              : '#' + p.pick_na_rodada)
          : '—'}</div>
        <div class="mock-player">
          <div class="mock-player-name">${esc(j.name)}</div>
          <div class="mock-player-pos">${esc(j.position || '')}</div>
        </div>
        <div class="mock-team">
          ${p ? `
            <img class="mock-team-logo" src="${esc(p.dono_logo || '/img/default-team.png')}"
                 alt="" onerror="this.src='/img/default-team.png'">
            <div class="mock-team-txt">
              <div class="mock-team-name">${esc(p.dono_nome)}</div>
              ${p.trocada
                ? `<div class="mock-team-via"><i class="bi bi-arrow-left-right"></i> via ${esc(p.team_name)}</div>`
                : `<div class="mock-team-via">${esc(p.owner_name || '')}</div>`}
              ${(p.selos || []).length ? `
                <div class="mock-selos">${p.selos.map(s => `
                  <span class="pick-cond ${esc(s.classe)}" title="${esc(s.titulo)}">${esc(s.texto)}${
                    // O parceiro do swap só entra quando acrescenta algo: ele
                    // costuma ser o próprio dono ou o time de origem, e aí
                    // "Anaheim Wyverns · Swap SB c/ Anaheim Wyverns" só repete
                    // o que já está escrito duas linhas acima.
                    s.classe === 'swap' && p.swap_com
                      && p.swap_com !== p.dono_nome && p.swap_com !== p.team_name
                      ? ' c/ ' + esc(p.swap_com) : ''
                  }</span>`).join('')}</div>` : ''}
            </div>
          ` : `<div class="mock-team-txt"><div class="mock-team-via">sem pick projetada</div></div>`}
        </div>
      </div>`;

    body.className = '';
    body.innerHTML = `
      <div class="info-note" style="margin-bottom:14px">
        <strong>Projeção.</strong> A ordem dos times vem do power ranking — o mais fraco na
        frente. A ordem de verdade só sai depois da loteria. Quem aparece é o
        <b>dono da pick hoje</b>${proj.some(p => p.trocada)
          ? ', com o time de origem no "via"' : ''}${proj.some(p => (p.selos || []).length)
          ? ', e as condições de swap e proteção estão marcadas' : ''}.
      </div>
      <div class="mock-list">
        ${pool.map((j, i) => linha(j, proj[i] || null)).join('')}
      </div>
    `;
  }

  // Calcula, a partir da sessão, se o relógio da 1ª rodada já está armado e (se sim) o
  // instante em que a pick atual estoura — mesma fórmula usada no backend
  // (check_autopick/runAutopickForSession): maior entre início da pick e a hora marcada,
  // mais 5 minutos.
  function getRound1ClockInfo(session) {
    const clockStartMs = new Date(String(session.round1_clock_start_at).replace(' ', 'T')).getTime();
    const armed = Date.now() >= clockStartMs;
    if (!armed) return { armed: false, clockStartMs };
    if (!session.current_pick_started_at) return { armed: true, deadlineMs: null };
    const pickStartedMs = new Date(String(session.current_pick_started_at).replace(' ', 'T')).getTime();
    return { armed: true, deadlineMs: Math.max(pickStartedMs, clockStartMs) + 5 * 60 * 1000 };
  }

  let round1CountdownInterval = null;
  function startRound1Countdown(deadlineMs) {
    clearInterval(round1CountdownInterval);
    const el = document.getElementById('round1ClockCountdown');
    if (!el) return;
    const tick = () => {
      const remaining = Math.floor((deadlineMs - Date.now()) / 1000);
      if (remaining <= 0) {
        el.innerHTML = '⏱ Prazo esgotado — resolvendo…';
        el.style.color = 'var(--text-3)';
        clearInterval(round1CountdownInterval);
        return;
      }
      const m = String(Math.floor(remaining / 60)).padStart(2, '0');
      const s = String(remaining % 60).padStart(2, '0');
      el.innerHTML = `⏱ Escolha atual: ${m}:${s} (senão, melhor disponível pela ordem é escolhido)`;
      el.style.color = remaining < 60 ? '#ef4444' : 'var(--amber)';
    };
    tick();
    round1CountdownInterval = setInterval(tick, 1000);
  }

  function renderPickCard(pick, session) {
    const isCurrent  = session.status === 'in_progress' &&
                       pick.round == session.current_round &&
                       pick.pick_position == session.current_pick &&
                       !pick.picked_player_id;
    const isCompleted = pick.picked_player_id !== null;
    const isMyPick    = parseInt(pick.team_id) === userTeamId;
    const canTradePick = session.status === 'in_progress' && !isCompleted && (isAdmin || isMyPick);
    const canAdminPick   = isAdmin && session.status === 'in_progress' && !isCompleted;
    const canAdminRevert = isAdmin && session.status === 'in_progress' && isCompleted;
    // Permite voltar/adiantar o ponteiro do draft para uma escolha ainda aberta
    const canAdminSetCurrent = isAdmin && session.status === 'in_progress' && !isCompleted && !isCurrent;

    let cls = 'pick-card';
    if (isCurrent)   cls += ' current';
    if (isCompleted) cls += ' completed';
    if (isMyPick)    cls += ' my-pick';

    return `
      <div class="${cls}">
        <div class="pick-num">
          <span class="pick-badge ${isCompleted ? 'done' : isCurrent ? 'active' : 'pending'}">#${pick.pick_position}</span>
          <div style="display:flex;gap:3px">
            ${canTradePick ? `<button class="pick-trade-btn" title="Trocar pick" onclick="openTradePickModal(${pick.id}, ${pick.round}, ${pick.pick_position}, ${pick.team_id}, '${esc((pick.team_city + ' ' + pick.team_name).replace(/\\/g, '\\\\').replace(/'/g, "\\'"))}')"><i class="bi bi-arrow-left-right"></i></button>` : ''}
            ${canAdminPick   ? `<button class="pick-trade-btn" title="Escolher jogador (Admin)" style="border-color:rgba(245,158,11,.4);color:var(--amber)" onclick="openAdminPickForSlot(${pick.id}, ${pick.round}, ${pick.pick_position}, '${esc((pick.team_city + ' ' + pick.team_name).replace(/\\/g, '\\\\').replace(/'/g, "\\'"))}')"><i class="bi bi-person-plus-fill"></i></button>` : ''}
            ${canAdminRevert ? `<button class="pick-trade-btn" title="Reverter pick (Admin)" style="border-color:rgba(239,68,68,.35);color:#ef4444" onclick="revertPick(${pick.id}, '${pick.player_name ? esc(pick.player_name.replace(/\\/g, '\\\\').replace(/'/g, "\\'")) : ''}')"><i class="bi bi-arrow-counterclockwise"></i></button>` : ''}
            ${canAdminSetCurrent ? `<button class="pick-trade-btn" title="Definir como escolha atual (Admin)" style="border-color:rgba(96,165,250,.4);color:#60a5fa" onclick="setCurrentPick(${pick.round}, ${pick.pick_position})"><i class="bi bi-crosshair"></i></button>` : ''}
          </div>
        </div>
        <div class="pick-team">${esc(pick.team_city)} ${esc(pick.team_name)}</div>
        ${pick.traded_from_team_id ? `<div class="pick-via"><i class="bi bi-arrow-right"></i> via ${esc(pick.traded_from_city || '')} ${esc(pick.traded_from_name || '')}</div>` : ''}
        ${isCompleted ? `
          <div class="pick-result">
            <div class="pick-result-name">${esc(pick.player_name)}</div>
            <div class="pick-result-meta">${esc(pick.player_position)}</div>
          </div>
        ` : `
          <div class="pick-waiting">
            <span>${isCurrent ? 'Escolhendo…' : 'Aguardando'}</span>
          </div>
        `}
      </div>
    `;
  }

  async function openTradePickModal(pickId, round, pickPosition, currentTeamId, currentTeamName) {
    if (!currentDraftSession) return;

    currentPickForTrade = {
      pickId: Number(pickId),
      round: Number(round),
      pickPosition: Number(pickPosition),
      currentTeamId: Number(currentTeamId)
    };

    const info = document.getElementById('tradePickInfo');
    if (info) info.textContent = `Rodada ${round} - Pick #${pickPosition} atualmente com ${currentTeamName}`;

    const select = document.getElementById('tradePickTeamSelect');
    if (select) {
      select.innerHTML = '<option value="">Carregando...</option>';
      const teamsById = new Map();
      const league = currentDraftSession.league || userLeague;
      try {
        const data = await api(`draft.php?action=league_teams&league=${encodeURIComponent(league)}`);
        (data.teams || []).forEach(t => teamsById.set(Number(t.id), `${t.city} ${t.name}`));
      } catch (e) {
        currentDraftPicks.forEach(p => {
          teamsById.set(Number(p.team_id), `${p.team_city} ${p.team_name}`);
          if (p.original_team_id) teamsById.set(Number(p.original_team_id), `${p.original_city} ${p.original_name}`);
        });
      }
      select.innerHTML = '<option value="">Selecione o time…</option>';
      Array.from(teamsById.entries())
        .filter(([teamId]) => teamId !== Number(currentTeamId))
        .sort((a, b) => a[1].localeCompare(b[1]))
        .forEach(([teamId, label]) => {
          const opt = document.createElement('option');
          opt.value = String(teamId); opt.textContent = label;
          select.appendChild(opt);
        });
    }
    new bootstrap.Modal(document.getElementById('tradePickModal')).show();
  }

  async function submitTradePick() {
    if (!currentDraftSession || !currentPickForTrade) return;
    const select = document.getElementById('tradePickTeamSelect');
    const toTeamId = Number(select?.value || 0);
    if (!toTeamId) { alert('Selecione o time que vai receber a pick.'); return; }
    if (!await confirmarSite(`Confirmar troca da pick #${currentPickForTrade.pickPosition} (rodada ${currentPickForTrade.round})?`)) return;
    try {
      const result = await api('draft.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'trade_pick', draft_session_id: currentDraftSession.id, pick_id: currentPickForTrade.pickId, to_team_id: toTeamId })
      });
      alert(result.message || 'Pick trocada com sucesso!');
      bootstrap.Modal.getInstance(document.getElementById('tradePickModal')).hide();
      currentPickForTrade = null;
      await loadDraft();
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  async function openPickModal()    { await openPlayersModal(true); }
  async function openOptionsModal() { await openPlayersModal(false); }

  async function openPlayersModal(allowPick) {
    if (!currentDraftSession) return;
    allowPickSelections = allowPick;
    document.getElementById('pickModalTitle').textContent = allowPick ? 'Escolher Jogador' : 'Jogadores disponíveis';
    new bootstrap.Modal(document.getElementById('pickModal')).show();

    const container = document.getElementById('availablePlayers');
    container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div>';

    try {
      const data = await api(`draft.php?action=available_players&season_id=${currentDraftSession.season_id}`);
      availablePlayersList = data.players || [];
      renderAvailablePlayers(availablePlayersList, allowPickSelections);
      const searchInput = document.getElementById('playerSearch');
      if (searchInput) {
        searchInput.value = '';
        searchInput.oninput = e => {
          const q = e.target.value.toLowerCase();
          renderAvailablePlayers(availablePlayersList.filter(p => p.name.toLowerCase().includes(q) || p.position.toLowerCase().includes(q)), allowPickSelections);
        };
      }
    } catch (e) {
      container.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><i class="bi bi-exclamation-circle"></i><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    }
  }

  function renderAvailablePlayers(players, allowPick, containerId, countElId) {
    containerId = containerId || 'availablePlayers';
    countElId = countElId || 'availablePlayersCount';
    const container = document.getElementById(containerId);
    const countEl = document.getElementById(countElId);
    if (countEl) countEl.textContent = players.length;
    if (!players.length) {
      container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-person-x"></i><p>Nenhum jogador encontrado</p></div>';
      return;
    }
    container.innerHTML = players.map(p => {
      const drafted = p.draft_status === 'drafted';
      const clickable = allowPick && !drafted;
      return `
      <div class="player-chip${drafted ? ' drafted' : ''}" ${clickable ? `onclick="makePick(${p.id}, '${esc(p.name.replace(/\\/g, '\\\\').replace(/'/g, "\\'"))}')"` : ''} style="${clickable ? 'cursor:pointer' : ''}">
        ${p.pick_hint ? `<span class="player-chip-order">${p.pick_hint}</span>` : ''}
        <div class="player-chip-name">${esc(p.name)}</div>
        <div><span class="player-chip-pos">${esc(p.position)}</span></div>
        ${drafted ? `<div class="player-chip-drafted-tag">Draftado</div>` : ''}
      </div>
    `;
    }).join('');
  }

  let bigBoardPlayersList = [];
  async function openBigBoardModal() {
    if (!currentDraftSession) { alert('Nenhum draft ativo no momento.'); return; }
    new bootstrap.Modal(document.getElementById('bigBoardModal')).show();

    const container = document.getElementById('bigBoardPlayers');
    container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div>';

    try {
      // board_players traz TODOS os jogadores da temporada (disponíveis + já
      // draftados) na ordem fixa (pick_hint) — diferente de available_players,
      // que só traz quem ainda não saiu. É o que permite o já draftado
      // continuar aparecendo cinza na posição dele, sem reordenar os outros.
      const data = await api(`draft.php?action=board_players&season_id=${currentDraftSession.season_id}`);
      bigBoardPlayersList = data.players || [];
      renderAvailablePlayers(bigBoardPlayersList, false, 'bigBoardPlayers', 'bigBoardCount');
      const searchInput = document.getElementById('bigBoardSearch');
      if (searchInput) {
        searchInput.value = '';
        searchInput.oninput = e => {
          const q = e.target.value.toLowerCase();
          renderAvailablePlayers(bigBoardPlayersList.filter(p => p.name.toLowerCase().includes(q) || p.position.toLowerCase().includes(q)), false, 'bigBoardPlayers', 'bigBoardCount');
        };
      }
    } catch (e) {
      container.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><i class="bi bi-exclamation-circle"></i><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    }
  }

  // ---- 2ª rodada: board de picks + mock por pick + relógio de 20min ----
  let round2CountdownInterval = null;
  let round2MockDraftOrderId = null;
  let round2MockPlayersList = [];

  async function loadRound2Board() {
    if (!currentDraftSession) return;
    const box = document.getElementById('round2Board');
    if (!box) return;
    try {
      const data = await api(`draft.php?action=round2_board&draft_session_id=${currentDraftSession.id}`);
      renderRound2Board(data.picks || []);
      startRound2Countdown(data.round2_mock_deadline);
    } catch (e) {
      box.innerHTML = `<div class="state-empty" style="color:#ef4444"><i class="bi bi-exclamation-circle"></i><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    }
  }

  function renderRound2Board(picks) {
    const box = document.getElementById('round2Board');
    if (!box) return;
    if (!picks.length) { box.innerHTML = '<div class="state-empty"><i class="bi bi-clock"></i><p>Nenhuma pick de 2ª rodada.</p></div>'; return; }
    box.innerHTML = picks.map(p => {
      const resolved = !!p.picked_player_id;
      let right;
      if (resolved) {
        right = `<span class="pun-badge" style="background:rgba(34,197,94,.12);color:#22c55e;border-color:rgba(34,197,94,.3)">Resolvida</span>`;
      } else if (p.is_own) {
        right = p.mock_player
          ? `<span style="font-size:12px;color:var(--text)">${esc(p.mock_player.name)}</span>
             <button class="btn-ghost" style="padding:3px 9px;font-size:11px" onclick="openRound2MockPicker(${p.draft_order_id}, ${p.pick_position})">Trocar</button>
             <button class="btn-ghost" style="padding:3px 9px;font-size:11px;color:#ef4444" onclick="cancelRound2Mock(${p.draft_order_id})"><i class="bi bi-x"></i></button>`
          : `<button class="btn-red" style="padding:6px 14px;font-size:12px" onclick="openRound2MockPicker(${p.draft_order_id}, ${p.pick_position})"><i class="bi bi-send"></i> Deixar mock</button>`;
      } else {
        right = p.has_mock
          ? `<span style="font-size:11px;color:var(--text-3)"><i class="bi bi-check-circle"></i> Mock enviado</span>`
          : `<span style="font-size:11px;color:var(--text-3)">Sem mock</span>`;
      }
      return `
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 10px;border-radius:8px;background:var(--panel-2);margin-bottom:6px;${resolved ? 'opacity:.6' : ''}">
          <div style="font-size:13px;color:var(--text)">#${p.pick_position} · ${esc(p.team_name)}${p.is_own ? ' <span style="color:var(--red);font-size:11px;font-weight:700">(você)</span>' : ''}</div>
          <div style="display:flex;align-items:center;gap:6px">${right}</div>
        </div>`;
    }).join('');
  }

  function startRound2Countdown(deadlineIso) {
    clearInterval(round2CountdownInterval);
    const el = document.getElementById('round2Countdown');
    if (!el) return;
    if (!deadlineIso) { el.textContent = ''; return; }
    const deadlineTs = new Date(String(deadlineIso).replace(' ', 'T')).getTime();
    const tick = () => {
      const remaining = Math.floor((deadlineTs - Date.now()) / 1000);
      if (remaining <= 0) {
        el.innerHTML = '⏱ Prazo esgotado — resolvendo…';
        el.style.color = 'var(--text-3)';
        clearInterval(round2CountdownInterval);
        return;
      }
      const m = String(Math.floor(remaining / 60)).padStart(2, '0');
      const s = String(remaining % 60).padStart(2, '0');
      el.innerHTML = `⏱ Resolve em ${m}:${s}`;
      el.style.color = remaining < 120 ? '#ef4444' : 'var(--amber)';
    };
    tick();
    round2CountdownInterval = setInterval(tick, 1000);
  }

  function openRound2MockPicker(draftOrderId, pickPosition) {
    if (!currentDraftSession) return;
    round2MockDraftOrderId = draftOrderId;
    const label = document.getElementById('round2MockPickLabel');
    if (label) label.textContent = `Pick #${pickPosition}`;
    new bootstrap.Modal(document.getElementById('round2MockModal')).show();

    const container = document.getElementById('round2MockPlayers');
    container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div>';
    api(`draft.php?action=available_players&season_id=${currentDraftSession.season_id}`).then(data => {
      round2MockPlayersList = data.players || [];
      renderRound2MockPlayers(round2MockPlayersList);
      const searchInput = document.getElementById('round2MockSearch');
      if (searchInput) {
        searchInput.value = '';
        searchInput.oninput = e => {
          const q = e.target.value.toLowerCase();
          renderRound2MockPlayers(round2MockPlayersList.filter(p => p.name.toLowerCase().includes(q) || p.position.toLowerCase().includes(q)));
        };
      }
    }).catch(e => {
      container.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    });
  }

  function renderRound2MockPlayers(players) {
    const container = document.getElementById('round2MockPlayers');
    if (!container) return;
    if (!players.length) { container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-person-x"></i><p>Nenhum jogador encontrado</p></div>'; return; }
    container.innerHTML = players.map(p => `
      <div class="player-chip" style="cursor:pointer" onclick="chooseRound2Mock(${p.id})">
        <div class="player-chip-name">${esc(p.name)}</div>
        <div><span class="player-chip-pos">${esc(p.position)}</span></div>
      </div>
    `).join('');
  }

  async function chooseRound2Mock(playerId) {
    if (!round2MockDraftOrderId) return;
    try {
      await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'submit_round2_mock', draft_order_id: round2MockDraftOrderId, player_id: playerId }) });
      bootstrap.Modal.getInstance(document.getElementById('round2MockModal'))?.hide();
      await loadRound2Board();
    } catch (e) { alert('Erro: ' + (e.error || e.message || 'Desconhecido')); }
  }

  async function cancelRound2Mock(draftOrderId) {
    if (!await confirmarSite('Remover o mock dessa pick?')) return;
    try {
      await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'cancel_round2_mock', draft_order_id: draftOrderId }) });
      await loadRound2Board();
    } catch (e) { alert('Erro: ' + (e.error || e.message || 'Desconhecido')); }
  }

  /**
   * Confere a ordem contra a tabela de picks e conta o que achou.
   *
   * Não corrige: quem lê decide o que fazer. Uma divergência aqui costuma
   * significar que a ordem foi aplicada antes de uma troca — e nesse caso
   * reaplicar a ordem da loteria resolve.
   */
  async function revisarPicks() {
    const box = document.getElementById('revisaoPicks');
    const btn = document.getElementById('btnRevisarPicks');
    box.style.display = 'block';
    // Sem draft montado não há ordem pra conferir — e "erro" seria a
    // resposta errada pra uma pergunta que ainda não faz sentido.
    if (!currentDraftSession || !currentDraftSession.id) {
      box.innerHTML = '<div style="padding:14px;font-size:13px;color:var(--text-3)">'
        + 'Não há draft montado nesta liga agora — nada a conferir.</div>';
      return;
    }
    box.innerHTML = '<div style="padding:14px;color:var(--text-3);font-size:13px">Conferindo…</div>';
    btn.disabled = true;
    try {
      const d = await api(`draft.php?action=conferir_picks&draft_session_id=${currentDraftSession.id}`);
      const bloco = (titulo, cor, linhas) => linhas.length ? `
        <div style="border:1px solid ${cor}40;background:${cor}14;border-radius:12px;padding:13px 15px;margin-bottom:10px">
          <div style="font-weight:700;font-size:13px;color:${cor};margin-bottom:7px">${titulo} (${linhas.length})</div>
          <div style="font-size:12.5px;line-height:1.75;color:var(--text-2)">${linhas.join('<br>')}</div>
        </div>` : '';

      const partes = [];

      partes.push(bloco('Escolhas com o dono errado', '#fc0025',
        d.divergencias.map(x => `<b>${x.rodada}ª rodada, pick ${x.pick}</b> (de ${esc(x.origem_nome)}): `
          + `está com <b>${esc(x.esta_com_nome)}</b>, deveria ser <b>${esc(x.deveria_nome)}</b>`
          + (x.swap ? ' — por swap' : ''))));

      partes.push(bloco('Time dono de mais de uma vaga na mesma rodada', '#fc0025',
        (d.origem_repetida || []).map(x => `<b>${esc(x.time_nome)}</b> aparece como dono das picks `
          + `${x.picks.join(', ')} na ${x.rodada}ª rodada — cada time é dono de uma só`)));

      partes.push(bloco('Proteções ainda não resolvidas', '#f59e0b',
        d.protecoes.map(x => `<b>Pick ${x.pick}</b> (de ${esc(x.origem_nome)}, com ${esc(x.dono_nome)}) — proteção ${esc(x.protecao)}`)));

      partes.push(bloco('Sem pick cadastrada — a vaga fica com o time de origem', '#f59e0b',
        d.sem_pick.map(x => `<b>${x.rodada}ª rodada, pick ${x.pick}</b> — ${esc(x.origem_nome)} não tem pick deste ano na tabela`)));

      partes.push(bloco('Swaps resolvidos', '#22c55e',
        d.swaps.map(x => `<b>${esc(x.melhor_dono_nome)}</b> ficou com a pick ${x.melhor_pick} (de ${esc(x.melhor_de_nome)}) e `
          + `<b>${esc(x.pior_dono_nome)}</b> com a ${x.pior_pick} (de ${esc(x.pior_de_nome)})`)));

      const temProblema = d.divergencias.length || d.sem_pick.length || d.protecoes.length;
      const resumo = `<div style="font-size:12px;color:var(--text-3);margin-bottom:10px">
          Draft de <b>${d.ano}</b> · ${d.vagas} escolhas conferidas</div>`;

      box.innerHTML = resumo + (temProblema
        ? partes.join('')
        : `<div style="border:1px solid #22c55e40;background:#22c55e14;border-radius:12px;padding:14px 16px">
             <div style="font-weight:700;font-size:13px;color:#22c55e">Está tudo certo</div>
             <div style="font-size:12.5px;color:var(--text-2);margin-top:4px">
               Cada escolha está com o dono da pick. Trocas e swaps conferidos.</div>
           </div>` + partes.join(''));
    } catch (e) {
      box.innerHTML = `<div style="padding:14px;color:#fca5a5;font-size:13px">Erro: ${e.error || 'não deu pra conferir'}</div>`;
    } finally {
      btn.disabled = false;
    }
  }

  async function finalizeDraft() {
    if (!currentDraftSession) return;
    if (!await confirmarSite('Finalizar o draft agora?')) return;
    try {
      const result = await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'finalize_draft', draft_session_id: currentDraftSession.id }) });
      alert(result.message || 'Draft finalizado!');
      loadDraft();
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  async function revertPick(pickId, playerName) {
    if (!currentDraftSession) return;
    if (!await confirmarSite(`Reverter a escolha de ${playerName}? O jogador voltará ao pool e a pick ficará disponível novamente.`)) return;
    try {
      const result = await api('draft.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'revert_pick', pick_id: pickId })
      });
      await loadDraft();
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  async function setCurrentPick(round, pickPosition) {
    if (!currentDraftSession) return;
    if (!await confirmarSite(`Voltar o draft para a escolha #${pickPosition} da rodada ${round}?\n\nO relógio da escolha reinicia e o time dessa posição passa a ser a vez.`)) return;
    try {
      const result = await api('draft.php', {
        method: 'POST',
        body: JSON.stringify({
          action: 'set_current_pick',
          draft_session_id: currentDraftSession.id,
          round: Number(round),
          pick_position: Number(pickPosition)
        })
      });
      if (result && result.success === false) { alert(result.error || 'Não foi possível definir a escolha atual'); return; }
      await loadDraft();
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  let adminPickTargetId = null;
  let adminPickTargetRound = null;

  async function openAdminPickForSlot(pickId, round, pickPosition, teamName) {
    if (!currentDraftSession) return;
    adminPickTargetId = pickId;
    adminPickTargetRound = round;
    allowPickSelections = true;
    document.getElementById('pickModalTitle').textContent = `Admin · Pick #${pickPosition} — ${teamName}`;
    new bootstrap.Modal(document.getElementById('pickModal')).show();

    const container = document.getElementById('availablePlayers');
    container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div>';
    try {
      const data = await api(`draft.php?action=available_players&season_id=${currentDraftSession.season_id}`);
      availablePlayersList = data.players || [];
      renderAvailablePlayers(availablePlayersList, true);
      const searchInput = document.getElementById('playerSearch');
      if (searchInput) {
        searchInput.value = '';
        searchInput.oninput = e => {
          const q = e.target.value.toLowerCase();
          renderAvailablePlayers(availablePlayersList.filter(p => p.name.toLowerCase().includes(q) || p.position.toLowerCase().includes(q)), true);
        };
      }
    } catch (e) {
      container.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><i class="bi bi-exclamation-circle"></i><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    }
  }

  async function makePick(playerId, playerName) {
    if (!await confirmarSite(`Confirma a escolha de ${playerName}?`)) return;
    const payload = { action: 'make_pick', draft_session_id: currentDraftSession.id, player_id: playerId };
    if (adminPickTargetId !== null) {
      payload.pick_id = adminPickTargetId;
      payload.round   = adminPickTargetRound;
    }
    try {
      const result = await api('draft.php', { method: 'POST', body: JSON.stringify(payload) });
      alert(result.message || 'Pick realizada!');
      adminPickTargetId = null;
      adminPickTargetRound = null;
      bootstrap.Modal.getInstance(document.getElementById('pickModal')).hide();
      loadDraft();
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  function toggleHistoryView() {
    if (currentView === 'active') {
      currentView = 'history';
      document.getElementById('activeDraftView').style.display = 'none';
      document.getElementById('historyView').style.display = 'block';
      document.getElementById('viewToggleText').textContent = 'Ver Draft Ativo';
      if (refreshInterval) clearInterval(refreshInterval);
      selectedLeague = userLeague;
      const leagueSelector = document.getElementById('leagueSelector');
      if (leagueSelector) {
        leagueSelector.value = userLeague;
        document.getElementById('selectedLeagueBadge').textContent = userLeague;
      }
      loadHistory();
    } else {
      currentView = 'active';
      document.getElementById('activeDraftView').style.display = 'block';
      document.getElementById('historyView').style.display = 'none';
      document.getElementById('viewToggleText').textContent = 'Ver Histórico';
      loadDraft();
    }
  }

  function loadHistoryForLeague() {
    const leagueSelector = document.getElementById('leagueSelector');
    selectedLeague = leagueSelector.value;
    document.getElementById('selectedLeagueBadge').textContent = selectedLeague;
    loadHistory();
  }

  async function loadHistory() {
    try {
      const data = await api(`draft.php?action=draft_history&league=${selectedLeague}`);
      const seasons = data.seasons || [];
      if (!seasons.length) {
        document.getElementById('historyContainer').innerHTML = `
          <div class="state-empty"><i class="bi bi-inbox"></i><p>Nenhum histórico de draft encontrado para a liga ${selectedLeague}.</p></div>
        `;
        return;
      }
      renderHistory(seasons);
    } catch (e) {
      console.error(e);
      document.getElementById('historyContainer').innerHTML = `
        <div class="state-empty" style="color:#ef4444"><i class="bi bi-exclamation-circle"></i><p>Erro ao carregar histórico: ${e.error || 'Desconhecido'}</p></div>
      `;
    }
  }

  function renderHistory(seasons) {
    const pillMap = {
      'in_progress': '<span class="draft-status-pill active"><i class="bi bi-play-fill"></i> Em Andamento</span>',
      'completed':   '<span class="draft-status-pill done"><i class="bi bi-check2"></i> Concluído</span>',
      'setup':       '<span class="draft-status-pill setup"><i class="bi bi-gear-fill"></i> Configurando</span>'
    };
    const html = `<div class="history-grid">` + seasons.map(season => `
      <div class="hist-card">
        <div class="hist-card-head">
          <div>
            <div class="hist-season">T${season.season_number} · Ano ${season.year}</div>
            <div class="hist-league">Liga: ${season.league}</div>
          </div>
          ${pillMap[season.draft_status] || '<span class="draft-status-pill done">Sem Draft</span>'}
        </div>
        ${season.draft_session_id
          ? `<button class="btn-ghost" style="width:100%" onclick="viewDraftHistory(${season.id}, '${season.draft_status}', ${season.draft_session_id})"><i class="bi bi-eye"></i> Ver Ordem do Draft</button>`
          : `<div style="font-size:12px;color:var(--text-3);text-align:center">Sem sessão de draft</div>`
        }
      </div>
    `).join('') + `</div>`;
    document.getElementById('historyContainer').innerHTML = html;
  }

  async function viewDraftHistory(seasonId, draftStatus, draftSessionId) {
    currentSeasonIdView = seasonId;
    currentDraftStatusView = draftStatus;
    currentDraftSessionForFill = draftSessionId;
    try {
      const data = await api(`draft.php?action=draft_history&season_id=${seasonId}`);
      let order = data.draft_order || [];
      const season = data.season;
      if (draftSessionId) {
        try {
          const orderData = await api(`draft.php?action=draft_order&draft_session_id=${draftSessionId}`);
          if (orderData && orderData.order && orderData.order.length > 0) order = orderData.order;
        } catch (err) { console.warn('Fallback: Não foi possível carregar a ordem em tempo real.', err); }
      }
      if (!order.length) { alert('Nenhuma ordem de draft encontrada para esta temporada'); return; }
      renderHistoricalDraft(season, order, draftStatus, draftSessionId);
    } catch (e) {
      console.error(e);
      alert('Erro ao carregar draft: ' + (e.error || 'Desconhecido'));
    }
  }

  function renderHistoricalDraft(season, picks, draftStatus, draftSessionId) {
    const round1Picks = picks.filter(p => p.round == 1);
    const round2Picks = picks.filter(p => p.round == 2);
    const pillMap = {
      'setup': '<span class="draft-status-pill setup"><i class="bi bi-gear-fill"></i> Configurando</span>',
      'in_progress': '<span class="draft-status-pill active"><i class="bi bi-play-fill"></i> Em Andamento</span>',
      'completed': '<span class="draft-status-pill done"><i class="bi bi-check2"></i> Concluído</span>'
    };

    document.getElementById('historyContainer').innerHTML = `
      <div style="margin-bottom:16px">
        <button class="btn-ghost-sm" onclick="loadHistory()"><i class="bi bi-arrow-left"></i> Voltar ao Histórico</button>
      </div>
      <div class="panel" style="margin-bottom:14px">
        <div class="panel-head">
          <span class="panel-title"><i class="bi bi-calendar3"></i> Temporada ${season.season_number} · Ano ${season.year} · ${season.league}</span>
          ${pillMap[draftStatus] || pillMap['completed']}
        </div>
      </div>
      ${isAdmin ? `<div class="info-note blue" style="margin-bottom:14px"><strong>Admin:</strong> Você pode preencher picks vazias clicando nos cards "Aguardando".</div>` : ''}
      <div class="panel" style="margin-bottom:14px">
        <div class="round-head"><div class="round-badge">1</div><span class="round-title">1ª Rodada</span><span class="round-count">${round1Picks.length} picks</span></div>
        <div class="panel-body">
          <div class="pick-grid">${round1Picks.map(p => renderHistoricalPickCard(p, draftStatus, draftSessionId)).join('')}</div>
        </div>
      </div>
      <div class="panel">
        <div class="round-head"><div class="round-badge">2</div><span class="round-title">2ª Rodada</span><span class="round-count">${round2Picks.length} picks</span></div>
        <div class="panel-body">
          <div class="pick-grid">${round2Picks.map(p => renderHistoricalPickCard(p, draftStatus, draftSessionId)).join('')}</div>
        </div>
      </div>
    `;
  }

  function renderHistoricalPickCard(pick, draftStatus, draftSessionId) {
    const isCompleted = pick.picked_player_id !== null;
    const canEdit = isAdmin && !isCompleted;
    const teamFullName = esc((pick.team_city + ' ' + pick.team_name).replace(/\\/g, '\\\\').replace(/'/g, "\\'"));

    return `
      <div class="pick-card${isCompleted ? ' completed' : ''}${canEdit ? ' clickable' : ''}"
           ${canEdit ? `onclick="openFillPastPickModal(${pick.id}, '${teamFullName}', ${draftSessionId})"` : ''}>
        <div class="pick-num">
          <span class="pick-badge ${isCompleted ? 'done' : 'pending'}">#${pick.pick_position}</span>
        </div>
        <div class="pick-team">${esc(pick.team_city)} ${esc(pick.team_name)}</div>
        ${pick.traded_from_team_id ? `<div class="pick-via"><i class="bi bi-arrow-right"></i> via ${esc(pick.traded_from_city || '')} ${esc(pick.traded_from_name || '')}</div>` : ''}
        ${isCompleted ? `
          <div class="pick-result">
            <div class="pick-result-name">${esc(pick.player_name || 'Jogador Desconhecido')}</div>
            ${pick.player_position ? `<div class="pick-result-meta">${esc(pick.player_position)}</div>` : ''}
          </div>
        ` : `
          <div class="pick-waiting">
            <span>${canEdit ? 'Clique para preencher' : 'Aguardando'}</span>
            ${canEdit ? '<i class="bi bi-pencil" style="color:var(--red);display:block;margin-top:4px;font-size:12px"></i>' : ''}
          </div>
        `}
      </div>
    `;
  }

  async function openFillPastPickModal(pickId, teamName, draftSessionId) {
    currentPickForFill = pickId;
    currentDraftSessionForFill = draftSessionId;
    document.getElementById('fillPickTeamName').textContent = teamName;
    new bootstrap.Modal(document.getElementById('fillPastPickModal')).show();

    const container = document.getElementById('pastPlayersDropdown');
    container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i></div>';

    try {
      const data = await api(`draft.php?action=available_players_for_past_draft&draft_session_id=${draftSessionId}`);
      allPlayersList = data.players || [];
      renderPastPlayers(allPlayersList);
      const searchInput = document.getElementById('pastPlayerSearch');
      searchInput.value = '';
      searchInput.addEventListener('input', e => {
        const q = e.target.value.toLowerCase();
        renderPastPlayers(allPlayersList.filter(p => p.name.toLowerCase().includes(q) || (p.position && p.position.toLowerCase().includes(q))));
      });
    } catch (e) {
      container.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><i class="bi bi-exclamation-circle"></i><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    }
  }

  function renderPastPlayers(players) {
    const container = document.getElementById('pastPlayersDropdown');
    if (!players.length) {
      container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-person-x"></i><p>Nenhum jogador disponível no draft pool</p></div>';
      return;
    }
    container.innerHTML = players.map(p => `
      <div class="player-chip" onclick="fillPastPick(${p.id}, '${esc((p.name || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'"))}')" style="cursor:pointer">
        <div class="player-chip-name">${esc(p.name || 'Sem nome')}</div>
        <div><span class="player-chip-pos">${esc(p.position || 'N/A')}</span></div>
      </div>
    `).join('');
  }

  async function fillPastPick(playerId, playerName) {
    if (!await confirmarSite(`Confirma preencher esta pick com ${playerName}?`)) return;
    try {
      const result = await api('draft.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'fill_past_pick', pick_id: currentPickForFill, player_id: playerId, draft_session_id: currentDraftSessionForFill })
      });
      alert(result.message);
      bootstrap.Modal.getInstance(document.getElementById('fillPastPickModal')).hide();
      if (currentSeasonIdView && currentDraftStatusView && currentDraftSessionForFill) {
        viewDraftHistory(currentSeasonIdView, currentDraftStatusView, currentDraftSessionForFill);
      } else {
        loadHistory();
      }
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  // ── Mock Draft ──────────────────────────────────────
  let mockQueue      = [];
  let mockIsActive   = false;
  let mockAllPlayers = [];

  async function loadMockCard(session) {
    const body = document.getElementById('mockCardBody');
    if (!body || !currentDraftSession) return;
    try {
      const data = await api(`draft-mock.php?action=get&draft_session_id=${currentDraftSession.id}`);
      mockQueue    = data.queue    || [];
      mockIsActive = data.is_active || false;
    } catch (e) {
      console.warn('Erro ao carregar mock:', e);
    }
    renderMockCard(session);
  }

  function renderMockCard(session) {
    const body = document.getElementById('mockCardBody');
    if (!body) return;

    const allPicks = currentDraftPicks || [];
    const currentPickInfo = session.status === 'in_progress'
      ? allPicks.find(p => p.round == session.current_round && p.pick_position == session.current_pick && !p.picked_player_id)
      : null;
    const isMyTurn = currentPickInfo && parseInt(currentPickInfo.team_id) === userTeamId && Number(session.current_round) === 1;

    // Resumo compacto dentro do status-card
    const topLine = mockIsActive
      ? `<span class="mock-badge on">Auto ON</span>`
      : `<span class="mock-badge off">Auto OFF</span>`;

    const queueSummary = mockQueue.length > 0
      ? mockQueue.slice(0, 3).map((item, i) => `<div style="font-size:11px;color:var(--text-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${i+1}. ${esc(item.player_name)}</div>`).join('') +
        (mockQueue.length > 3 ? `<div style="font-size:10px;color:var(--text-3)">+${mockQueue.length - 3} mais</div>` : '')
      : `<div style="font-size:11px;color:var(--text-3)">Sem jogadores</div>`;

    body.innerHTML = `
      <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
        ${topLine}
        <div class="form-check form-switch mb-0" style="margin-left:auto">
          <input class="form-check-input" type="checkbox" role="switch" style="width:1.8em;height:.9em"
            id="mockActiveToggle" ${mockIsActive ? 'checked' : ''}
            onchange="toggleMock(this.checked)">
        </div>
      </div>
      ${queueSummary}
      <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
        <button class="btn-ghost-sm" style="font-size:10px;padding:4px 8px" onclick="openMockManageModal()">
          <i class="bi bi-pencil"></i> Gerenciar fila
        </button>
      </div>
    `;
  }


  async function toggleMock(isActive) {
    if (!currentDraftSession) return;
    try {
      const data = await api('draft-mock.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'toggle', draft_session_id: currentDraftSession.id, is_active: isActive })
      });
      mockIsActive = data.is_active;
      const badge = document.querySelector('.mock-badge');
      if (badge) {
        badge.className = `mock-badge ${mockIsActive ? 'on' : 'off'}`;
        badge.textContent = mockIsActive ? 'Auto-pick ON' : 'Auto-pick OFF';
      }
    } catch (e) {
      alert('Erro: ' + (e.error || 'Desconhecido'));
    }
  }

  async function openMockManageModal() {
    if (!currentDraftSession) return;
    new bootstrap.Modal(document.getElementById('mockManageModal')).show();
    renderMockQueueInModal();
    const container = document.getElementById('mockAvailablePlayers');
    const countEl   = document.getElementById('mockAvailableCount');
    container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div>';
    try {
      const data = await api(`draft.php?action=available_players&season_id=${currentDraftSession.season_id}`);
      const queueIds = new Set(mockQueue.map(m => Number(m.player_id)));
      mockAllPlayers = (data.players || []).filter(p => !queueIds.has(Number(p.id)));
      if (countEl) countEl.textContent = `${mockAllPlayers.length} disponíveis`;
      const search = document.getElementById('mockPlayerSearch');
      if (search) {
        search.value = '';
        search.oninput = e => {
          const q = e.target.value.toLowerCase();
          renderMockPlayerList(mockAllPlayers.filter(p => p.name.toLowerCase().includes(q) || p.position.toLowerCase().includes(q)));
        };
      }
      renderMockPlayerList(mockAllPlayers);
    } catch (e) {
      container.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    }
  }

  function renderMockQueueInModal() {
    const listEl  = document.getElementById('mockQueueListModal');
    const countEl = document.getElementById('mockQueueCountBadge');
    if (!listEl) return;
    if (countEl) countEl.textContent = `(${mockQueue.length}/8)`;
    if (!mockQueue.length) {
      listEl.innerHTML = '<div class="mock-queue-empty">Nenhum jogador na fila.</div>';
      return;
    }
    listEl.innerHTML = mockQueue.map((item, idx) => `
      <div class="mock-queue-item">
        <span class="mock-queue-num">${idx + 1}</span>
        <span class="mock-queue-name">${esc(item.player_name)}</span>
        <span class="mock-queue-meta">${esc(item.player_position)}</span>
        <button class="mock-queue-del" onclick="removeFromMockQueue(${item.player_id})" title="Remover"><i class="bi bi-x-lg"></i></button>
      </div>`).join('');
  }

  function renderMockPlayerList(players) {
    const container = document.getElementById('mockAvailablePlayers');
    const countEl   = document.getElementById('mockAvailableCount');
    if (countEl) countEl.textContent = `${players.length} disponíveis`;
    if (!players.length) {
      container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-person-x"></i><p>Nenhum jogador disponível</p></div>';
      return;
    }
    container.innerHTML = players.map(p => `
      <div class="player-chip" onclick="addPlayerToMockQueue(${p.id}, '${esc(p.name.replace(/\\/g,'\\\\').replace(/'/g,"\\'"))}', '${esc(p.position.replace(/\\/g,'\\\\').replace(/'/g,"\\'"))}', ${p.ovr})" style="cursor:pointer">
        <div class="player-chip-name">${esc(p.name)}</div>
        <div><span class="player-chip-pos">${esc(p.position)}</span></div>
      </div>`).join('');
  }

  async function addPlayerToMockQueue(playerId, playerName, position, ovr) {
    if (!currentDraftSession) return;
    if (mockQueue.length >= 8) { alert('Máximo 8 jogadores no mock.'); return; }
    mockQueue.push({ player_id: playerId, player_name: playerName, player_position: position, player_ovr: ovr });
    // Remove da lista de disponíveis no modal
    mockAllPlayers = mockAllPlayers.filter(p => Number(p.id) !== Number(playerId));
    renderMockQueueInModal();
    renderMockPlayerList(mockAllPlayers);
    try {
      await api('draft-mock.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'save', draft_session_id: currentDraftSession.id, player_ids: mockQueue.map(m => m.player_id) })
      });
      renderMockCard(currentDraftSession);
    } catch (e) {
      mockQueue.pop();
      mockAllPlayers.unshift({ id: playerId, name: playerName, position, ovr });
      renderMockQueueInModal();
      renderMockPlayerList(mockAllPlayers);
      alert('Erro: ' + (e.error || 'Desconhecido'));
    }
  }

  async function removeFromMockQueue(playerId) {
    if (!currentDraftSession) return;
    const removed = mockQueue.find(m => Number(m.player_id) === Number(playerId));
    mockQueue = mockQueue.filter(m => Number(m.player_id) !== Number(playerId));
    // Recoloca o jogador na lista do modal se estiver aberto
    if (removed) mockAllPlayers.unshift({ id: removed.player_id, name: removed.player_name, position: removed.player_position, ovr: removed.player_ovr });
    renderMockQueueInModal();
    renderMockPlayerList(mockAllPlayers);
    try {
      await api('draft-mock.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'save', draft_session_id: currentDraftSession.id, player_ids: mockQueue.map(m => m.player_id) })
      });
      renderMockCard(currentDraftSession);
    } catch (e) {
      alert('Erro: ' + (e.error || 'Desconhecido'));
    }
  }

  async function checkAutopick() {
    if (!currentDraftSession || currentDraftSession.status !== 'in_progress') return;
    try {
      const result = await api(`draft-mock.php?action=check_autopick&draft_session_id=${currentDraftSession.id}`);
      if (result.autopicked) await loadDraft();
    } catch (e) {
      console.warn('check_autopick:', e);
    }
  }

  async function openAdminMocksModal() {
    const modal = new bootstrap.Modal(document.getElementById('adminMocksModal'));
    const body  = document.getElementById('adminMocksBody');
    body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:40px"><div class="spinner-border" role="status" style="color:var(--red);width:1.8rem;height:1.8rem"></div></div>';
    modal.show();
    if (!currentDraftSession) {
      body.innerHTML = '<div style="padding:24px;color:var(--text-3);text-align:center">Nenhum draft ativo encontrado.</div>';
      return;
    }
    try {
      const data = await api(`draft-mock.php?action=admin_all_mocks&draft_session_id=${currentDraftSession.id}`);
      const mocks = data.mocks || [];
      if (!mocks.length) {
        body.innerHTML = '<div style="padding:24px;color:var(--text-3);text-align:center;font-size:13px"><i class="bi bi-inbox" style="display:block;font-size:28px;margin-bottom:8px"></i>Nenhum time configurou mock ainda.</div>';
        return;
      }
      body.innerHTML = mocks.map(m => `
        <div style="margin-bottom:16px;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden">
          <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid var(--border);background:var(--panel-3)">
            <span style="font-size:13px;font-weight:700;color:var(--text);flex:1">${esc(m.team_name)}</span>
            <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;${m.is_active ? 'background:rgba(34,197,94,.15);color:var(--green);border:1px solid rgba(34,197,94,.3)' : 'background:var(--panel-3);color:var(--text-3);border:1px solid var(--border)'}">
              ${m.is_active ? 'Auto ON' : 'Auto OFF'}
            </span>
          </div>
          ${m.queue.length ? `
            <div>
              ${m.queue.map((q, i) => `
                <div style="display:flex;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid var(--border);${q.draft_status === 'drafted' ? 'opacity:.4;text-decoration:line-through' : ''}">
                  <span style="font-size:11px;font-weight:700;color:var(--text-3);width:18px;text-align:center">${i + 1}</span>
                  <span style="font-size:12px;font-weight:600;color:var(--text);flex:1">${esc(q.player_name)}</span>
                  <span style="font-size:11px;color:var(--text-2)">${esc(q.position)}</span>
                  ${q.draft_status === 'drafted' ? '<span style="font-size:10px;color:var(--text-3)">Já draftado</span>' : ''}
                </div>`).join('')}
            </div>` : `<div style="padding:12px 14px;font-size:12px;color:var(--text-3)">Fila vazia</div>`}
        </div>`).join('');
    } catch (e) {
      body.innerHTML = `<div style="padding:16px;color:#fca5a5">Erro: ${e.error || 'Desconhecido'}</div>`;
    }
  }

  document.getElementById('pickModal').addEventListener('hidden.bs.modal', () => {
    adminPickTargetId = null;
    adminPickTargetRound = null;
  });

  loadDraft();
</script>
<script src="<?= assetUrl('/js/pwa.js') ?>"></script>
</body>
</html>
