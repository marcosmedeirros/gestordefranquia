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
$isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';

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
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />

  <style>
    /* ── Tokens ──────────────────────────────────── */
    :root {
      --red:        #fc0025;
      --red-2:      #ff2a44;
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
      --border-red: rgba(252,0,37,.18);
      --text: #111217;
      --text-2: #5b6270;
      --text-3: #8b93a5;
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
    .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
    .sb-nav a {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 10px; border-radius: var(--radius-sm);
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
    .hero-sub { font-size: 13px; color: var(--text-2); margin-top: 3px; }
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
    .pick-badge { display: inline-flex; padding: 2px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; }
    .pick-badge.done { background: rgba(34,197,94,.15); color: var(--green); border: 1px solid rgba(34,197,94,.25); }
    .pick-badge.pending { background: var(--panel-3); color: var(--text-3); border: 1px solid var(--border); }
    .pick-badge.active { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }

    .pick-team { font-size: 12px; font-weight: 600; text-align: center; color: var(--text); margin-bottom: 6px; }
    .pick-via { font-size: 10px; color: var(--amber); display: flex; align-items: center; justify-content: center; gap: 3px; margin-bottom: 4px; }

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
    .draft-status-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }

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
      display: inline-flex; align-items: center; gap: 5px;
      padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700;
    }
    .draft-status-pill.setup  { background: rgba(245,158,11,.12); color: var(--amber); border: 1px solid rgba(245,158,11,.25); }
    .draft-status-pill.active { background: rgba(34,197,94,.12); color: var(--green); border: 1px solid rgba(34,197,94,.25); }
    .draft-status-pill.done   { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); }

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
      padding: 9px 18px; border-radius: 9px;
      background: var(--red); border: none; color: #fff;
      font-family: var(--font); font-size: 13px; font-weight: 600;
      cursor: pointer; transition: filter var(--t) var(--ease);
      text-decoration: none;
    }
    .btn-red:hover { filter: brightness(1.1); color: #fff; }
    .btn-red:disabled { opacity: .5; cursor: not-allowed; }

    .btn-ghost {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 9px 18px; border-radius: 9px;
      background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
      font-family: var(--font); font-size: 13px; font-weight: 600;
      cursor: pointer; transition: all var(--t) var(--ease);
      text-decoration: none;
    }
    .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

    .btn-ghost-sm {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 6px 12px; border-radius: 7px;
      background: transparent; border: 1px solid var(--border); color: var(--text-2);
      font-family: var(--font); font-size: 12px; font-weight: 600;
      cursor: pointer; transition: all var(--t) var(--ease);
      text-decoration: none;
    }
    .btn-ghost-sm:hover { border-color: var(--border-red); color: var(--red); }

    .btn-green {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 9px 18px; border-radius: 9px;
      background: var(--green); border: none; color: #fff;
      font-family: var(--font); font-size: 13px; font-weight: 600;
      cursor: pointer; transition: filter var(--t) var(--ease);
    }
    .btn-green:hover { filter: brightness(1.1); }

    /* ── Empty / info states ─────────────────────── */
    .state-empty {
      padding: 32px 20px;
      text-align: center; color: var(--text-3);
    }
    .state-empty i { font-size: 32px; display: block; margin-bottom: 10px; }
    .state-empty p { font-size: 13px; }

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
    .info-note.blue {
      background: rgba(59,130,246,.08);
      border-color: rgba(59,130,246,.2);
      border-left-color: var(--blue);
    }

    /* ── Form controls (modal) ───────────────────── */
    .field-label { font-size: 12px; font-weight: 600; color: var(--text-2); margin-bottom: 5px; display: block; }
    .field-input {
      width: 100%;
      background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: 8px; padding: 9px 12px;
      color: var(--text); font-family: var(--font); font-size: 13px;
      outline: none; transition: border-color var(--t) var(--ease);
    }
    .field-input:focus { border-color: var(--red); }
    .field-input::placeholder { color: var(--text-3); }

    /* ── Player select card ──────────────────────── */
    .player-chip {
      background: var(--panel-2);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 12px;
      text-align: center;
      cursor: pointer;
      transition: all var(--t) var(--ease);
    }
    .player-chip:hover { border-color: var(--border-red); transform: translateY(-2px); background: var(--panel-3); }
    .player-chip-name { font-size: 13px; font-weight: 600; margin-bottom: 5px; }
    .player-chip-pos { display: inline-flex; padding: 2px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); margin-right: 4px; }
    .player-chip-ovr { display: inline-flex; padding: 2px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; background: rgba(34,197,94,.1); color: var(--green); border: 1px solid rgba(34,197,94,.2); }
    .player-chip-age { font-size: 11px; color: var(--text-2); margin-top: 5px; }

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

    /* ── Responsive ──────────────────────────────── */
    @media (max-width: 992px) {
      :root { --sidebar-w: 0px; }
      .sidebar { transform: translateX(-260px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; width: 100%; padding-top: 54px; }
      .topbar { display: flex; }
      .page-hero, .content { padding-left: 16px; padding-right: 16px; }
      .page-hero { padding-top: 18px; }
      .draft-status-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 600px) {
      .pick-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
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
      <div class="sb-brand-text">
        FBA Manager
        <span>Liga <?= htmlspecialchars($userLeague) ?></span>
      </div>
    </div>

    <div class="sb-team">
      <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
           alt="<?= htmlspecialchars($team['name']) ?>"
           onerror="this.src='/img/default-team.png'">
      <div>
        <div class="sb-team-name"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></div>
        <div class="sb-team-league"><?= htmlspecialchars($userLeague) ?></div>
      </div>
    </div>

    <nav class="sb-nav">
      <div class="sb-section">Principal</div>
      <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
      <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
      <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
      <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
      <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
      <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
      <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
      <a href="/drafts.php" class="active"><i class="bi bi-trophy"></i> Draft</a>

      <div class="sb-section">Liga</div>
      <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
      <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
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

    <button class="sb-theme-toggle" type="button" id="themeToggle">
      <i class="bi bi-moon"></i>
      <span>Modo escuro</span>
    </button>

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

    <!-- Hero header -->
    <div class="page-hero">
      <div>
        <div class="hero-eyebrow">Draft · <?= htmlspecialchars($userLeague) ?></div>
        <h1 class="hero-title">Draft</h1>
        <p class="hero-sub">Ordem de seleção de jogadores da liga</p>
      </div>
      <?php if ($isAdmin): ?>
      <div class="hero-actions">
        <button class="btn-ghost" onclick="openAddDraftPlayerModal()">
          <i class="bi bi-person-plus"></i> Adicionar jogador
        </button>
        <button class="btn-ghost" onclick="toggleHistoryView()">
          <i class="bi bi-clock-history"></i>
          <span id="viewToggleText">Ver Histórico</span>
        </button>
      </div>
      <?php endif; ?>
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
      <?php if ($isAdmin): ?>
      <div id="historyView" style="display: none;">
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
        <div id="historyContainer">
          <div class="state-empty">
            <i class="bi bi-hourglass-split"></i>
            <p>Carregando histórico...</p>
          </div>
        </div>
      </div>
      <?php endif; ?>

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

<!-- Modal: Adicionar jogador ao draft (Admin) -->
<div class="modal fade" id="addDraftPlayerModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title"><i class="bi bi-person-plus"></i> Novo jogador do draft</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="addDraftPlayerForm">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div style="grid-column:1/-1">
              <label class="field-label">Nome</label>
              <input type="text" class="field-input" name="name" required placeholder="Nome do jogador">
            </div>
            <div>
              <label class="field-label">Posição</label>
              <input type="text" class="field-input" name="position" maxlength="3" placeholder="PG" required>
            </div>
            <div>
              <label class="field-label">Idade</label>
              <input type="number" class="field-input" name="age" min="16" max="50" required placeholder="22">
            </div>
            <div>
              <label class="field-label">OVR</label>
              <input type="number" class="field-input" name="ovr" min="40" max="99" required placeholder="75">
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-red" onclick="submitAddDraftPlayer()">
          <i class="bi bi-check2-circle"></i> Adicionar jogador
        </button>
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

  const api = async (path, options = {}) => {
    const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
    let body = {};
    try { body = await res.json(); } catch {}
    if (!res.ok) throw body;
    return body;
  };

  async function loadDraft() {
    try {
      const draftData = await api(`draft.php?action=active_draft&league=${userLeague}`);

      if (!draftData.draft) {
        document.getElementById('draftContainer').innerHTML = `
          <div class="state-empty">
            <i class="bi bi-trophy"></i>
            <p>Não há draft ativo para a liga ${userLeague} no momento.${isAdmin ? '<br><small>Use a página de Temporadas para criar uma sessão de draft.</small>' : ''}</p>
          </div>
        `;
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
    const showRound2Admin = isAdmin && session.status === 'in_progress' && session.current_round == 2;
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
        currentPickLabel = `${currentPickInfo.team_city} ${currentPickInfo.team_name}`;
      }
    } else if (session.status === 'setup') {
      currentPickLabel = 'Aguardando início';
    } else {
      currentPickLabel = 'Draft concluído';
    }

    let html = '';

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
          <button class="btn-green" onclick="openPickModal()">
            <i class="bi bi-person-plus"></i> Fazer Minha Pick
          </button>
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
      </div>
    `;

    if (isAdmin && session.status === 'setup') {
      html += `
        <div class="info-note" style="margin-bottom:20px">
          <strong>Admin:</strong> Configure a ordem do draft na página de <a href="/temporadas.php" style="color:var(--red)">Temporadas</a> e inicie quando estiver pronto.
        </div>
      `;
    }

    // Round 1
    html += `
      <div class="panel" style="margin-bottom:14px">
        <div class="round-head">
          <div class="round-badge">1</div>
          <span class="round-title">1ª Rodada</span>
          <span class="round-count">${round1Picks.length} picks</span>
        </div>
        <div class="panel-body">
          <div class="pick-grid">
            ${round1Picks.map((p, idx) => renderPickCard(p, session, idx + 1)).join('')}
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

    if (showRound2Admin) {
      html += `
        <div class="r2-panel">
          <div class="r2-panel-title"><i class="bi bi-person-check"></i> Registrar pick da 2ª Rodada (Admin)</div>
          <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end">
            <div>
              <label class="field-label">Time</label>
              <select class="field-input" id="round2TeamSelect">
                <option value="">Selecione o time…</option>
              </select>
            </div>
            <div>
              <label class="field-label">Jogador disponível</label>
              <select class="field-input" id="round2PlayerSelect">
                <option value="">Selecione o jogador…</option>
              </select>
            </div>
            <div>
              <button class="btn-red" style="padding:9px 14px" onclick="submitRound2Pick()"><i class="bi bi-check2-circle"></i> Enviar</button>
            </div>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-top:12px;margin-bottom:8px">
            <span style="font-size:11px;color:var(--text-2)">Jogadores disponíveis</span>
            <span style="font-size:11px;color:var(--text-2)" id="round2PlayersCount">0</span>
          </div>
          <div style="margin-bottom:10px">
            <button class="btn-ghost-sm" onclick="openAddDraftPlayerModal()"><i class="bi bi-person-plus"></i> Adicionar novo jogador ao draft</button>
          </div>
          <div id="round2PlayersList" class="pick-grid"></div>
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
              <div class="pick-team">${pick.team_city} ${pick.team_name}</div>
              <div class="pick-result">
                <div class="pick-result-name">${pick.player_name}</div>
              </div>
            </div>
          `).join('')}
        </div>
      `;
    } else if (!showRound2Admin) {
      html += `<div class="state-empty"><i class="bi bi-clock"></i><p>Nenhuma pick registrada na 2ª rodada.</p></div>`;
    }

    html += `</div></div>`;

    document.getElementById('draftContainer').innerHTML = html;

    const finalizeContainer = document.getElementById('finalizeDraftContainer');
    if (finalizeContainer) {
      finalizeContainer.style.display = (session.status === 'in_progress') ? 'block' : 'none';
    }

    if (showRound2Admin) {
      initRound2AdminPanel(round2Raw);
    }
  }

  function renderPickCard(pick, session, displayNum) {
    const isCurrent  = session.status === 'in_progress' &&
                       pick.round == session.current_round &&
                       pick.pick_position == session.current_pick &&
                       !pick.picked_player_id;
    const isCompleted = pick.picked_player_id !== null;
    const isMyPick    = parseInt(pick.team_id) === userTeamId;
    const canTradePick = session.status === 'in_progress' && !isCompleted && (isAdmin || isMyPick);

    let cls = 'pick-card';
    if (isCurrent)   cls += ' current';
    if (isCompleted) cls += ' completed';
    if (isMyPick)    cls += ' my-pick';

    return `
      <div class="${cls}">
        <div class="pick-num">
          <span class="pick-badge ${isCompleted ? 'done' : isCurrent ? 'active' : 'pending'}">#${pick.pick_position}</span>
          ${canTradePick ? `<button class="pick-trade-btn" title="Trocar pick" onclick="openTradePickModal(${pick.id}, ${pick.round}, ${pick.pick_position}, ${pick.team_id}, '${(pick.team_city + ' ' + pick.team_name).replace(/'/g, "\\'")}')"><i class="bi bi-arrow-left-right"></i></button>` : ''}
        </div>
        <div class="pick-team">${pick.team_city} ${pick.team_name}</div>
        ${pick.traded_from_team_id ? `<div class="pick-via"><i class="bi bi-arrow-right"></i> via ${pick.traded_from_city || ''} ${pick.traded_from_name || ''}</div>` : ''}
        ${isCompleted ? `
          <div class="pick-result">
            <div class="pick-result-name">${pick.player_name}</div>
            <div class="pick-result-meta">${pick.player_position} · OVR ${pick.player_ovr}</div>
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
    if (!confirm(`Confirmar troca da pick #${currentPickForTrade.pickPosition} (rodada ${currentPickForTrade.round})?`)) return;
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

  function renderAvailablePlayers(players, allowPick) {
    const container = document.getElementById('availablePlayers');
    document.getElementById('availablePlayersCount').textContent = players.length;
    if (!players.length) {
      container.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-person-x"></i><p>Nenhum jogador encontrado</p></div>';
      return;
    }
    container.innerHTML = players.map(p => `
      <div class="player-chip${allowPick ? '' : ''}" ${allowPick ? `onclick="makePick(${p.id}, '${p.name.replace(/'/g, "\\'")}')"` : ''} style="${allowPick ? 'cursor:pointer' : ''}">
        <div class="player-chip-name">${p.name}</div>
        <div>
          <span class="player-chip-pos">${p.position}</span>
          <span class="player-chip-ovr">OVR ${p.ovr}</span>
        </div>
        <div class="player-chip-age">${p.age} anos</div>
      </div>
    `).join('');
  }

  function initRound2AdminPanel(round2PicksRaw) {
    if (!currentDraftSession) return;
    const teamSelect = document.getElementById('round2TeamSelect');
    const playerSelect = document.getElementById('round2PlayerSelect');
    const playersList = document.getElementById('round2PlayersList');
    if (!teamSelect || !playerSelect || !playersList) return;
    teamSelect.innerHTML = '<option value="">Selecione o time…</option>';
    const teamMap = new Map(round2PicksRaw.map(p => [String(p.team_id), `${p.team_city} ${p.team_name}`]));
    Array.from(teamMap.entries()).sort((a, b) => a[1].localeCompare(b[1])).forEach(([id, label]) => {
      const opt = document.createElement('option'); opt.value = id; opt.textContent = label; teamSelect.appendChild(opt);
    });
    refreshRound2Players();
  }

  async function refreshRound2Players() {
    if (!currentDraftSession) return;
    const playerSelect = document.getElementById('round2PlayerSelect');
    const playersList = document.getElementById('round2PlayersList');
    const playersCount = document.getElementById('round2PlayersCount');
    if (!playerSelect || !playersList) return;
    playerSelect.innerHTML = '<option value="">Selecione o jogador…</option>';
    playersList.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-hourglass-split"></i></div>';
    try {
      const data = await api(`draft.php?action=available_players&season_id=${currentDraftSession.season_id}`);
      const players = data.players || [];
      if (playersCount) playersCount.textContent = players.length;
      players.forEach(p => {
        const opt = document.createElement('option'); opt.value = p.id; opt.textContent = `${p.name} (${p.position}) - OVR ${p.ovr}`; playerSelect.appendChild(opt);
      });
      if (!players.length) { playersList.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-person-x"></i><p>Nenhum jogador disponível.</p></div>'; return; }
      playersList.innerHTML = players.map(p => `
        <div class="player-chip">
          <div class="player-chip-name">${p.name}</div>
          <div><span class="player-chip-pos">${p.position}</span> <span class="player-chip-ovr">OVR ${p.ovr}</span></div>
        </div>
      `).join('');
    } catch (e) {
      playersList.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><i class="bi bi-exclamation-circle"></i><p>Erro: ${e.error || 'Desconhecido'}</p></div>`;
    }
  }

  async function submitRound2Pick() {
    if (!currentDraftSession) return;
    const teamSelect = document.getElementById('round2TeamSelect');
    const playerSelect = document.getElementById('round2PlayerSelect');
    if (!teamSelect || !playerSelect) return;
    const teamId = teamSelect.value;
    const playerId = playerSelect.value;
    if (!teamId) { alert('Selecione o time.'); return; }
    if (!playerId) { alert('Selecione o jogador.'); return; }
    try {
      const result = await api('draft.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'make_pick', draft_session_id: currentDraftSession.id, round: 2, player_id: parseInt(playerId, 10), team_id: parseInt(teamId, 10) })
      });
      alert(result.message || result.error || 'Pick registrada!');
      await loadDraft();
    } catch (e) { alert('Erro: ' + (e.error || e.message || 'Desconhecido')); }
  }

  function openAddDraftPlayerModal() {
    if (!currentDraftSession) {
      alert('Nenhum draft ativo no momento.');
      return;
    }
    const modalEl = document.getElementById('addDraftPlayerModal');
    if (!modalEl) return;
    const form = document.getElementById('addDraftPlayerForm');
    if (form) form.reset();
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }

  async function submitAddDraftPlayer() {
    if (!currentDraftSession) return;
    const form = document.getElementById('addDraftPlayerForm');
    if (!form) return;
    const formData = new FormData(form);
    const payload = {
      action: 'add_draft_player',
      draft_session_id: currentDraftSession.id,
      name: String(formData.get('name') || '').trim(),
      position: String(formData.get('position') || '').trim().toUpperCase(),
      age: Number(formData.get('age')),
      ovr: Number(formData.get('ovr'))
    };
    if (!payload.name || !payload.position || !payload.age || !payload.ovr) { alert('Preencha todos os campos.'); return; }
    try {
      const result = await api('draft.php', { method: 'POST', body: JSON.stringify(payload) });
      alert(result.message || 'Jogador adicionado!');
      bootstrap.Modal.getOrCreateInstance(document.getElementById('addDraftPlayerModal')).hide();
      refreshRound2Players();
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  async function finalizeDraft() {
    if (!currentDraftSession) return;
    if (!confirm('Finalizar o draft agora?')) return;
    try {
      const result = await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'finalize_draft', draft_session_id: currentDraftSession.id }) });
      alert(result.message || 'Draft finalizado!');
      loadDraft();
    } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
  }

  async function makePick(playerId, playerName) {
    if (!confirm(`Confirma a escolha de ${playerName}?`)) return;
    try {
      const result = await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'make_pick', draft_session_id: currentDraftSession.id, player_id: playerId }) });
      alert(result.message);
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
      const leagueSelector = document.getElementById('leagueSelector');
      if (leagueSelector) {
        leagueSelector.value = userLeague;
        selectedLeague = userLeague;
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
    const teamFullName = (pick.team_city + ' ' + pick.team_name).replace(/'/g, "\\'");

    return `
      <div class="pick-card${isCompleted ? ' completed' : ''}${canEdit ? ' clickable' : ''}"
           ${canEdit ? `onclick="openFillPastPickModal(${pick.id}, '${teamFullName}', ${draftSessionId})"` : ''}>
        <div class="pick-num">
          <span class="pick-badge ${isCompleted ? 'done' : 'pending'}">#${pick.pick_position}</span>
        </div>
        <div class="pick-team">${pick.team_city} ${pick.team_name}</div>
        ${pick.traded_from_team_id ? `<div class="pick-via"><i class="bi bi-arrow-right"></i> via ${pick.traded_from_city || ''} ${pick.traded_from_name || ''}</div>` : ''}
        ${isCompleted ? `
          <div class="pick-result">
            <div class="pick-result-name">${pick.player_name || 'Jogador Desconhecido'}</div>
            ${pick.player_position ? `<div class="pick-result-meta">${pick.player_position}${pick.player_ovr ? ' · OVR ' + pick.player_ovr : ''}</div>` : ''}
          </div>
        ` : `
          <div class="pick-waiting">
            <span>${canEdit ? 'Clique para preencher' : 'Aguardando'}</span>
            ${canEdit ? '<i class="bi bi-pencil" style="color:var(--red);display:block;margin-top:3px;font-size:12px"></i>' : ''}
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
      <div class="player-chip" onclick="fillPastPick(${p.id}, '${(p.name || '').replace(/'/g, "\\'")}')" style="cursor:pointer">
        <div class="player-chip-name">${p.name || 'Sem nome'}</div>
        <div><span class="player-chip-pos">${p.position || 'N/A'}</span> <span class="player-chip-ovr">OVR ${p.ovr || '0'}</span></div>
        <div class="player-chip-age">${p.age || '?'} anos</div>
      </div>
    `).join('');
  }

  async function fillPastPick(playerId, playerName) {
    if (!confirm(`Confirma preencher esta pick com ${playerName}?`)) return;
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

  loadDraft();
</script>
<script src="/js/pwa.js"></script>
</body>
</html>
