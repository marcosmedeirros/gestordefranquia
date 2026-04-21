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

$maxTrades = 10;
$tradesEnabled = 1;
if ($team) {
    $stmtSettings = $pdo->prepare('SELECT max_trades, trades_enabled FROM league_settings WHERE league = ?');
    $stmtSettings->execute([$team['league']]);
    $settings = $stmtSettings->fetch();
    $maxTrades = $settings['max_trades'] ?? 10;
    $tradesEnabled = $settings['trades_enabled'] ?? 1;
}

$currentSeasonYear = null;
if (!empty($team['league'])) {
  try {
    $stmtSeason = $pdo->prepare('SELECT s.season_number, s.year, sp.start_year FROM seasons s LEFT JOIN sprints sp ON s.sprint_id = sp.id WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ("completed")) ORDER BY s.created_at DESC LIMIT 1');
    $stmtSeason->execute([$team['league']]);
    $currentSeason = $stmtSeason->fetch();
    if ($currentSeason && isset($currentSeason['start_year'], $currentSeason['season_number'])) {
      $currentSeasonYear = (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1;
    } elseif ($currentSeason && isset($currentSeason['year'])) {
      $currentSeasonYear = (int)$currentSeason['year'];
    }
  } catch (Exception $e) { $currentSeasonYear = null; }
}
if (!$currentSeasonYear) $currentSeasonYear = (int)date('Y');

$tradeCount = (int)($team['trades_used'] ?? 0);
$tradesPct  = $maxTrades > 0 ? min(100, round(($tradeCount / $maxTrades) * 100)) : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <meta name="theme-color" content="#fc0025">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="FBA Manager">
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Trades - FBA Manager</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/styles.css">

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

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }
    a { color: inherit; text-decoration: none; }

    /* ── Shell ───────────────────────────────────── */
    .app { display: flex; min-height: 100vh; }

    /* ── Sidebar ─────────────────────────────────── */
    .sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-w); height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; transition: transform var(--t) var(--ease); overflow-y: auto; scrollbar-width: none; }
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
    .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
    .sb-nav a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
    .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
    .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
    .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
    .sb-nav a.active i { color: var(--red); }

    .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
    .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); flex-shrink: 0; }
    .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

    /* ── Topbar mobile ───────────────────────────── */
    .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 199; }
    .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
    .topbar-title em { color: var(--red); font-style: normal; }
    .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
    .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 199; }
    .sb-overlay.show { display: block; }

    /* ── Main ────────────────────────────────────── */
    .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }

    /* ── Hero header ─────────────────────────────── */
    .dash-hero { padding: 32px 32px 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .dash-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
    .dash-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
    .dash-sub { font-size: 13px; color: var(--text-2); margin-top: 3px; }

    .hero-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding-top: 4px; }

    /* ── Blocked banner ──────────────────────────── */
    .blocked-banner {
      margin: 16px 32px 0;
      background: rgba(239,68,68,.08);
      border: 1px solid rgba(239,68,68,.25);
      border-left: 3px solid #ef4444;
      border-radius: var(--radius-sm);
      padding: 12px 16px;
      display: flex; align-items: center; gap: 10px;
      font-size: 13px; color: #f87171;
    }

    /* ── Admin toggle bar ────────────────────────── */
    .admin-bar {
      margin: 16px 32px 0;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 12px 18px;
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      flex-wrap: wrap;
    }
    .admin-bar-label { font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
    .admin-bar-label i { color: var(--amber); }

    /* Toggle switch */
    .toggle-wrap { display: flex; align-items: center; gap: 10px; }
    .toggle-switch { position: relative; width: 40px; height: 22px; flex-shrink: 0; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-track { position: absolute; inset: 0; background: var(--panel-3); border: 1px solid var(--border-md); border-radius: 999px; cursor: pointer; transition: background var(--t) var(--ease); }
    .toggle-track::before { content: ''; position: absolute; left: 3px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; border-radius: 50%; background: var(--text-2); transition: all var(--t) var(--ease); }
    .toggle-switch input:checked + .toggle-track { background: var(--green); border-color: var(--green); }
    .toggle-switch input:checked + .toggle-track::before { left: calc(100% - 17px); background: #fff; }
    .toggle-label { font-size: 12px; font-weight: 600; color: var(--text-2); }

    /* ── Stats strip ─────────────────────────────── */
    .stats-strip { display: flex; gap: 10px; padding: 16px 32px 0; flex-wrap: wrap; }
    .stat-pill { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 10px 16px; display: flex; align-items: center; gap: 10px; }
    .stat-pill-icon { width: 30px; height: 30px; border-radius: 7px; background: var(--red-soft); display: flex; align-items: center; justify-content: center; color: var(--red); font-size: 13px; flex-shrink: 0; }
    .stat-pill-val { font-size: 16px; font-weight: 800; line-height: 1; }
    .stat-pill-label { font-size: 11px; color: var(--text-2); margin-top: 1px; }
    .stat-pill-bar { height: 3px; background: var(--panel-3); border-radius: 999px; overflow: hidden; margin-top: 6px; width: 80px; }
    .stat-pill-fill { height: 100%; background: var(--red); border-radius: 999px; transition: width .5s var(--ease); }

    /* ── Content ─────────────────────────────────── */
    .content { padding: 20px 32px 40px; flex: 1; }

    /* ── Tab nav ─────────────────────────────────── */
    .tab-nav {
      display: flex; gap: 2px;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 4px;
      margin-bottom: 18px;
      overflow-x: auto; scrollbar-width: none;
    }
    .tab-nav::-webkit-scrollbar { display: none; }
    .tab-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 8px 14px; border-radius: 8px;
      background: transparent; border: none;
      color: var(--text-2); font-family: var(--font); font-size: 12px; font-weight: 600;
      cursor: pointer; white-space: nowrap;
      transition: all var(--t) var(--ease);
    }
    .tab-btn i { font-size: 13px; }
    .tab-btn:hover { background: var(--panel-2); color: var(--text); }
    .tab-btn.active { background: var(--red-soft); color: var(--red); }
    .tab-btn .tab-count {
      display: inline-flex; align-items: center; justify-content: center;
      min-width: 18px; height: 18px; padding: 0 5px;
      background: var(--red); color: #fff; border-radius: 999px;
      font-size: 10px; font-weight: 700;
    }
    .tab-btn.active .tab-count { background: var(--red); }

    /* ── Tab panes ───────────────────────────────── */
    .tab-pane-r { display: none; animation: fadeUp .25s var(--ease) both; }
    .tab-pane-r.show { display: block; }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

    /* ── Panel ───────────────────────────────────── */
    .panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .panel-head { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 12px; background: var(--panel-2); flex-wrap: wrap; }
    .panel-title { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .panel-title i { color: var(--red); }
    .panel-body { padding: 18px 20px; }

    /* ── Filter bar ──────────────────────────────── */
    .filter-bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
    .f-search { flex: 1; min-width: 180px; position: relative; }
    .f-search i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-3); font-size: 13px; pointer-events: none; }
    .f-search input { width: 100%; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 10px 8px 30px; color: var(--text); font-family: var(--font); font-size: 13px; outline: none; transition: border-color var(--t) var(--ease); }
    .f-search input:focus { border-color: var(--red); }
    .f-search input::placeholder { color: var(--text-3); }
    .f-select { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 10px; color: var(--text); font-family: var(--font); font-size: 13px; outline: none; cursor: pointer; }
    .f-select:focus { border-color: var(--red); }
    .f-select option { background: var(--panel-2); }

    /* ── Trades list styles (for JS-rendered content) */
    /* Trade card — injected by trades.js */
    .trades-wrap { display: flex; flex-direction: column; gap: 12px; }

    /* ── Player card in trade-list ───────────────── */
    .player-card-r {
      background: var(--panel-2);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 14px 16px;
      display: flex; align-items: center; gap: 14px;
      transition: border-color var(--t) var(--ease), transform var(--t) var(--ease);
    }
    .player-card-r:hover { border-color: var(--border-red); transform: translateY(-1px); }
    .player-photo { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); background: var(--panel-3); flex-shrink: 0; }
    .player-pos { display: inline-flex; align-items: center; justify-content: center; padding: 2px 7px; border-radius: 4px; background: var(--red-soft); color: var(--red); font-size: 9px; font-weight: 800; letter-spacing: .3px; text-transform: uppercase; }
    .player-name-r { font-size: 13px; font-weight: 600; }
    .player-meta-r { font-size: 12px; color: var(--text-2); margin-top: 2px; }
    .player-ovr { font-size: 16px; font-weight: 800; color: var(--amber); line-height: 1; }
    .player-ovr-label { font-size: 10px; color: var(--text-3); text-align: center; }
    .player-team-logo { width: 24px; height: 24px; border-radius: 6px; object-fit: cover; border: 1px solid var(--border-md); }

    /* ── Empty / loading ─────────────────────────── */
    .empty-r { padding: 40px 20px; text-align: center; color: var(--text-3); }
    .empty-r i { font-size: 28px; display: block; margin-bottom: 10px; }
    .empty-r p { font-size: 13px; }
    .spinner-r { display: inline-block; width: 24px; height: 24px; border: 2px solid var(--border); border-top-color: var(--red); border-radius: 50%; animation: spin .6s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Rumor bubble ────────────────────────────── */
    .rumor-item { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 16px; margin-bottom: 10px; }
    .rumor-item:last-child { margin-bottom: 0; }
    .rumor-meta-r { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
    .rumor-avatar-r { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
    .rumor-team-r { font-size: 13px; font-weight: 600; }
    .rumor-gm-r { font-size: 11px; color: var(--text-2); }
    .rumor-bubble-r { background: var(--panel-3); border: 1px solid var(--border); border-radius: 10px 10px 10px 3px; padding: 12px 14px; font-size: 13px; line-height: 1.5; color: var(--text); }
    .rumor-date-r { font-size: 11px; color: var(--text-3); margin-top: 8px; display: flex; align-items: center; gap: 4px; }

    /* ── Tags ────────────────────────────────────── */
    .tag { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .tag.green { background: rgba(34,197,94,.12); color: var(--green); border: 1px solid rgba(34,197,94,.2); }
    .tag.red { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }
    .tag.amber { background: rgba(245,158,11,.12); color: var(--amber); border: 1px solid rgba(245,158,11,.2); }
    .tag.gray { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); }

    /* ── Buttons ─────────────────────────────────── */
    .btn-r { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: var(--radius-sm); font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid transparent; transition: all var(--t) var(--ease); text-decoration: none; white-space: nowrap; }
    .btn-r.primary { background: var(--red); color: #fff; border-color: var(--red); }
    .btn-r.primary:hover { filter: brightness(1.1); color: #fff; }
    .btn-r.ghost { background: transparent; color: var(--text-2); border-color: var(--border); }
    .btn-r.ghost:hover { background: var(--panel-2); border-color: var(--border-md); color: var(--text); }
    .btn-r.blue { background: rgba(59,130,246,.12); color: var(--blue); border-color: rgba(59,130,246,.2); }
    .btn-r.blue:hover { background: var(--blue); color: #fff; }
    .btn-r.disabled { opacity: .45; pointer-events: none; }
    .btn-r.lg { padding: 10px 20px; font-size: 13px; }

    /* ── Rumor composer ──────────────────────────── */
    .composer { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 16px; margin-bottom: 18px; }
    .composer textarea { width: 100%; background: var(--panel-3); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; color: var(--text); font-family: var(--font); font-size: 13px; resize: none; outline: none; min-height: 72px; transition: border-color var(--t) var(--ease); }
    .composer textarea:focus { border-color: var(--red); }
    .composer textarea::placeholder { color: var(--text-3); }
    .composer-footer { display: flex; justify-content: flex-end; margin-top: 10px; }

    /* ── Modal overrides ─────────────────────────── */
    .modal-content { background: var(--panel) !important; border: 1px solid var(--border-md) !important; border-radius: var(--radius) !important; font-family: var(--font); color: var(--text); }
    .modal-header { background: var(--panel-2) !important; border-color: var(--border) !important; padding: 16px 20px; border-radius: var(--radius) var(--radius) 0 0 !important; }
    .modal-title { font-size: 15px; font-weight: 700; }
    .modal-body { padding: 20px; }
    .modal-footer { background: var(--panel-2) !important; border-color: var(--border) !important; padding: 14px 20px; border-radius: 0 0 var(--radius) var(--radius) !important; }
    .btn-close-white { filter: invert(1); }

    /* Bootstrap form overrides */
    .form-control, .form-select { background: var(--panel-2) !important; border-color: var(--border) !important; color: var(--text) !important; font-family: var(--font); font-size: 13px; }
    .form-control:focus, .form-select:focus { border-color: var(--red) !important; box-shadow: 0 0 0 .2rem rgba(252,0,37,.15) !important; }
    .form-control::placeholder { color: var(--text-3) !important; }
    .form-label { font-size: 11px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; color: var(--text-2); }
    .form-select option { background: var(--panel-2); }
    .input-group-text { background: var(--panel-3) !important; border-color: var(--border) !important; color: var(--text-2) !important; }

    /* Legacy classes needed by trades.js / trade-list.js */
    .bg-dark-panel { background: var(--panel) !important; border: 1px solid var(--border); }
    .border-orange { border-color: var(--border-red) !important; }
    .text-orange { color: var(--red) !important; }
    .text-light-gray { color: var(--text-2) !important; }
    .bg-gradient-orange { background: linear-gradient(135deg, var(--red), var(--red-2)) !important; color: #fff !important; }
    .btn-orange { background: var(--red) !important; color: #fff !important; border: none !important; font-family: var(--font); font-weight: 600; }
    .btn-orange:hover { filter: brightness(1.1); color: #fff !important; }
    .btn-outline-orange { background: transparent; border: 1px solid var(--border-red); color: var(--red); font-family: var(--font); font-weight: 600; }
    .btn-outline-orange:hover { background: var(--red-soft); color: var(--red); }
    .card.bg-dark { background: var(--panel-2) !important; border-color: var(--border) !important; }
    .pick-selector { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; }
    .pick-options { max-height: 200px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; scrollbar-width: thin; scrollbar-color: var(--red) transparent; }
    .pick-option-card { display: flex; justify-content: space-between; align-items: center; background: var(--panel-3); border: 1px solid var(--border); border-radius: 8px; padding: 9px 12px; transition: border var(--t) var(--ease); }
    .pick-option-card:hover { border-color: var(--border-red); }
    .pick-option-card.is-selected { opacity: .5; }
    .selected-picks { display: flex; flex-direction: column; gap: 8px; }
    .selected-pick-card { display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; align-items: center; background: var(--red-soft); border: 1px solid var(--border-red); border-radius: 9px; padding: 10px 12px; }
    .pick-protection-select { background: var(--panel-3) !important; color: var(--text) !important; border: 1px solid var(--border) !important; border-radius: 7px; padding: 5px 8px; font-family: var(--font); font-size: 12px; }
    .pick-title { font-size: 13px; font-weight: 600; color: var(--text); }
    .pick-meta { font-size: 12px; color: var(--text-2); }
    .pick-empty-state { text-align: center; padding: 12px; background: var(--panel-3); border: 1px dashed var(--border); border-radius: 8px; color: var(--text-2); font-size: 12px; }
    .player-name { font-weight: 600; color: var(--text); font-size: 13px; }
    .player-meta { font-size: 12px; color: var(--text-2); }
    .team-chip { display: inline-flex; align-items: center; gap: 8px; background: var(--panel-3); border: 1px solid var(--border); border-radius: 999px; padding: 5px 12px; font-size: 12px; }
    .team-chip-badge { width: 28px; height: 28px; border-radius: 50%; background: var(--panel-2); border: 1px solid var(--border-md); display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 11px; color: var(--text); }
    .reaction-bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .reaction-chip { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; border: 1px solid var(--border); background: var(--panel-3); color: var(--text); font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); }
    .reaction-chip.active { border-color: var(--border-red); background: var(--red-soft); }
    .reaction-count { font-size: 11px; color: var(--text-2); }
    .trade-list-panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px; }
    #pick-swaps, .pick-swaps, .pick-swap { display: none !important; }

    /* ── Responsive ──────────────────────────────── */
    @media (max-width: 860px) {
      :root { --sidebar-w: 0px; }
      .sidebar { transform: translateX(-260px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; width: 100%; padding-top: 54px; }
      .topbar { display: flex; }
      .dash-hero, .blocked-banner, .admin-bar, .stats-strip, .content { padding-left: 16px; padding-right: 16px; }
      .dash-hero { padding-top: 18px; }
    }
  </style>
</head>
<body>
<div class="app">

  <!-- ══════════════ SIDEBAR ══════════════ -->
  <aside class="sidebar" id="sidebar">
    <div class="sb-brand">
      <div class="sb-logo">FBA</div>
      <div class="sb-brand-text">FBA Manager<span>Liga <?= htmlspecialchars($user['league']) ?></span></div>
    </div>

    <?php if ($team): ?>
    <div class="sb-team">
      <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
      <div>
        <div class="sb-team-name"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></div>
        <div class="sb-team-league"><?= htmlspecialchars($team['league']) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <nav class="sb-nav">
      <div class="sb-section">Principal</div>
      <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
      <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
      <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
      <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
      <a href="/trades.php" class="active"><i class="bi bi-arrow-left-right"></i> Trades</a>
      <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
      <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
      <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>

      <div class="sb-section">Liga</div>
      <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
      <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>

      <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
      <div class="sb-section">Admin</div>
      <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
      <a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a>
      <?php endif; ?>

      <div class="sb-section">Conta</div>
      <a href="/settings.php"><i class="bi bi-gear-fill"></i> Configurações</a>
    </nav>

    <div class="sb-footer">
      <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>"
           alt="<?= htmlspecialchars($user['name']) ?>" class="sb-avatar"
           onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name']) ?>&background=1c1c21&color=fc0025'">
      <span class="sb-username"><?= htmlspecialchars($user['name']) ?></span>
      <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </aside>

  <div class="sb-overlay" id="sbOverlay"></div>

  <!-- Topbar mobile -->
  <header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Trades</em></div>
  </header>

  <!-- ══════════════ MAIN ══════════════ -->
  <main class="main">

    <!-- Hero -->
    <div class="dash-hero">
      <div>
        <div class="dash-eyebrow">Liga · <?= htmlspecialchars($user['league']) ?></div>
        <h1 class="dash-title">Trades</h1>
        <p class="dash-sub">Negocie jogadores e picks com outras franquias</p>
      </div>
      <?php if ($teamId): ?>
      <div class="hero-actions">
        <?php if ($tradesEnabled): ?>
          <button class="btn-r primary lg <?= $tradeCount >= $maxTrades ? 'disabled' : '' ?>"
                  data-bs-toggle="modal" data-bs-target="#proposeTradeModal">
            <i class="bi bi-plus-circle"></i> Nova Trade
          </button>
          <button class="btn-r ghost lg <?= $tradeCount >= $maxTrades ? 'disabled' : '' ?>"
                  data-bs-toggle="modal" data-bs-target="#multiTradeModal">
            <i class="bi bi-people-fill"></i> Trade Múltipla
          </button>
        <?php else: ?>
          <button class="btn-r ghost lg disabled"><i class="bi bi-lock-fill"></i> Bloqueado</button>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Blocked banner -->
    <?php if (!$tradesEnabled): ?>
    <div class="blocked-banner">
      <i class="bi bi-lock-fill" style="font-size:16px;flex-shrink:0"></i>
      <span><strong>Trades desativadas</strong> — O administrador bloqueou as trocas temporariamente.</span>
    </div>
    <?php endif; ?>

    <!-- Admin toggle bar -->
    <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
    <div class="admin-bar">
      <div class="admin-bar-label">
        <i class="bi bi-shield-lock-fill"></i>
        Controle de Trades (Admin)
      </div>
      <div class="toggle-wrap">
        <label class="toggle-switch">
          <input type="checkbox" id="tradesStatusToggle" <?= $tradesEnabled ? 'checked' : '' ?>>
          <span class="toggle-track"></span>
        </label>
        <span class="toggle-label" id="toggleLabel"><?= $tradesEnabled ? 'Trocas abertas' : 'Trocas bloqueadas' ?></span>
        <span id="tradesStatusBadge" class="tag <?= $tradesEnabled ? 'green' : 'red' ?>">
          <?= $tradesEnabled ? 'Ativo' : 'Bloqueado' ?>
        </span>
      </div>
    </div>
    <?php endif; ?>

    <!-- Stats strip -->
    <?php if ($teamId): ?>
    <div class="stats-strip">
      <div class="stat-pill">
        <div class="stat-pill-icon"><i class="bi bi-arrow-left-right"></i></div>
        <div>
          <div class="stat-pill-val"><?= $tradeCount ?><span style="font-size:13px;color:var(--text-3);font-weight:400">/<?= $maxTrades ?></span></div>
          <div class="stat-pill-label">Trades usadas</div>
          <div class="stat-pill-bar"><div class="stat-pill-fill" style="width:<?= $tradesPct ?>%"></div></div>
        </div>
      </div>
      <?php if ($tradeCount >= $maxTrades): ?>
      <div class="tag red" style="font-size:12px;padding:8px 14px"><i class="bi bi-exclamation-circle-fill"></i> Limite atingido</div>
      <?php elseif ($tradeCount >= $maxTrades - 1): ?>
      <div class="tag amber" style="font-size:12px;padding:8px 14px"><i class="bi bi-exclamation-triangle-fill"></i> Última trade disponível</div>
      <?php endif; ?>
      <div class="stat-pill">
        <div class="stat-pill-icon" style="background:rgba(59,130,246,.10);color:var(--blue)"><i class="bi bi-calendar3"></i></div>
        <div>
          <div class="stat-pill-val"><?= $currentSeasonYear ?></div>
          <div class="stat-pill-label">Temporada atual</div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Content -->
    <div class="content">

      <?php if (!$teamId): ?>
      <div class="panel">
        <div class="panel-body">
          <div class="empty-r">
            <i class="bi bi-exclamation-circle"></i>
            <p>Você ainda não possui um time cadastrado.</p>
          </div>
        </div>
      </div>
      <?php else: ?>

      <!-- Tab nav -->
      <div class="tab-nav" id="tabNav">
        <button class="tab-btn active" data-tab="received" onclick="switchTab('received', this)">
          <i class="bi bi-inbox-fill"></i> Recebidas
          <span class="tab-count" id="countReceived" style="display:none">0</span>
        </button>
        <button class="tab-btn" data-tab="sent" onclick="switchTab('sent', this)">
          <i class="bi bi-send-fill"></i> Enviadas
        </button>
        <button class="tab-btn" data-tab="history" onclick="switchTab('history', this)">
          <i class="bi bi-clock-history"></i> Histórico
        </button>
        <button class="tab-btn" data-tab="league" onclick="switchTab('league', this)">
          <i class="bi bi-trophy"></i> Trocas Gerais
          <span class="tab-count" id="countLeague" style="display:none">0</span>
        </button>
        <button class="tab-btn" data-tab="rumors" onclick="switchTab('rumors', this)">
          <i class="bi bi-megaphone-fill"></i> Rumores
          <span class="tab-count" id="countRumors" style="display:none">0</span>
        </button>
        <button class="tab-btn" data-tab="trade-list" onclick="switchTab('trade-list', this)">
          <i class="bi bi-list-stars"></i> Trade List
          <span class="tab-count" id="countTradeList" style="display:none">0</span>
        </button>
      </div>

      <!-- Pane: Recebidas -->
      <div class="tab-pane-r show" id="pane-received">
        <div id="receivedTradesList">
          <div class="empty-r"><div class="spinner-r"></div></div>
        </div>
      </div>

      <!-- Pane: Enviadas -->
      <div class="tab-pane-r" id="pane-sent">
        <div id="sentTradesList">
          <div class="empty-r"><div class="spinner-r"></div></div>
        </div>
      </div>

      <!-- Pane: Histórico -->
      <div class="tab-pane-r" id="pane-history">
        <div id="historyTradesList">
          <div class="empty-r"><div class="spinner-r"></div></div>
        </div>
      </div>

      <!-- Pane: Trocas Gerais -->
      <div class="tab-pane-r" id="pane-league">
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title"><i class="bi bi-trophy"></i> Todas as trocas da liga</div>
            <span class="tag gray" id="leagueTradesCount">0 trocas</span>
          </div>
          <div class="panel-body">
            <div class="filter-bar">
              <div class="f-search">
                <i class="bi bi-search"></i>
                <input type="text" id="leagueTradesSearch" placeholder="Buscar jogador ou time...">
              </div>
              <select class="f-select" id="leagueTradesTeamFilter">
                <option value="">Todos os times</option>
              </select>
            </div>
            <div id="leagueTradesList">
              <div class="empty-r"><div class="spinner-r"></div></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Pane: Rumores -->
      <div class="tab-pane-r" id="pane-rumors">
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title"><i class="bi bi-megaphone-fill"></i> Rumores da Liga</div>
            <span class="tag gray" id="rumorsCount">0 rumores</span>
          </div>
          <div class="panel-body">

            <!-- Comentários Admin -->
            <div style="margin-bottom:20px">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                <span style="font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px">
                  <i class="bi bi-pin-angle-fill" style="color:var(--red)"></i> Comentários do Admin
                </span>
                <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
                <button class="btn-r ghost" id="addAdminCommentBtn" style="padding:5px 10px;font-size:11px">
                  <i class="bi bi-plus-lg"></i> Adicionar
                </button>
                <?php endif; ?>
              </div>
              <div id="adminCommentsList"></div>
            </div>

            <!-- Composer -->
            <div class="composer">
              <div style="font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">Seu rumor</div>
              <textarea id="rumorContent" placeholder="Ex.: Procuro SG com OVR 80+ ou vendo PF..."></textarea>
              <div class="composer-footer">
                <button class="btn-r primary" id="submitRumorBtn">
                  <i class="bi bi-megaphone-fill"></i> Publicar
                </button>
              </div>
            </div>

            <!-- Lista de rumores -->
            <div id="rumorsList">
              <div class="empty-r"><div class="spinner-r"></div></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Pane: Trade List -->
      <div class="tab-pane-r" id="pane-trade-list">
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title"><i class="bi bi-list-stars"></i> Jogadores disponíveis para troca</div>
            <span class="tag gray" id="countBadge">0 jogadores</span>
          </div>
          <div class="panel-body">
            <div class="filter-bar">
              <div class="f-search">
                <i class="bi bi-search"></i>
                <input type="text" id="searchInput" placeholder="Buscar por nome...">
              </div>
              <select class="f-select" id="sortSelect">
                <option value="ovr_desc">OVR ↓</option>
                <option value="ovr_asc">OVR ↑</option>
                <option value="name_asc">Nome A–Z</option>
                <option value="name_desc">Nome Z–A</option>
                <option value="age_asc">Idade ↑</option>
                <option value="age_desc">Idade ↓</option>
                <option value="position_asc">Posição A–Z</option>
                <option value="team_asc">Time A–Z</option>
              </select>
            </div>
            <div id="playersList">
              <div class="empty-r"><div class="spinner-r"></div></div>
            </div>
          </div>
        </div>
      </div>

      <?php endif; ?>
    </div><!-- /content -->
  </main>
</div>

<!-- ══════════════ MODALS ══════════════ -->

<!-- Modal: Propor Trade -->
<div class="modal fade" id="proposeTradeModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2" style="color:var(--red)"></i>Propor Trade</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="proposeTradeForm">
          <div class="mb-4">
            <label class="form-label">Para qual time?</label>
            <select class="form-select" id="targetTeam" required>
              <option value="">Selecione o time...</option>
            </select>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <div style="font-size:13px;font-weight:700;color:var(--red);margin-bottom:14px;display:flex;align-items:center;gap:6px">
                <i class="bi bi-arrow-up-circle-fill"></i> Você oferece
              </div>
              <div class="mb-3">
                <label class="form-label">Jogadores</label>
                <div class="pick-selector">
                  <div class="pick-options" id="offerPlayersOptions"></div>
                  <div class="selected-picks" id="offerPlayersSelected"></div>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">Picks</label>
                <div class="pick-selector">
                  <div class="pick-options" id="offerPicksOptions"></div>
                  <div class="selected-picks" id="offerPicksSelected"></div>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div style="font-size:13px;font-weight:700;color:var(--green);margin-bottom:14px;display:flex;align-items:center;gap:6px">
                <i class="bi bi-arrow-down-circle-fill"></i> Você quer
              </div>
              <div class="mb-3">
                <label class="form-label">Jogadores</label>
                <div class="pick-selector">
                  <div class="pick-options" id="requestPlayersOptions"></div>
                  <div class="selected-picks" id="requestPlayersSelected"></div>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">Picks</label>
                <div class="pick-selector">
                  <div class="pick-options" id="requestPicksOptions"></div>
                  <div class="selected-picks" id="requestPicksSelected"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- CAP Impact -->
          <div class="row g-3 mb-3" id="capImpactRow">
            <div class="col-md-6">
              <div style="background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                  <span style="font-size:13px;font-weight:600">Seu time</span>
                  <span class="tag gray" id="capMyDelta">±0</span>
                </div>
                <div style="font-size:12px;color:var(--text-2)">Atual: <strong style="color:var(--text)" id="capMyCurrent">—</strong></div>
                <div style="font-size:12px;color:var(--text-2)">Após trade: <strong style="color:var(--red)" id="capMyProjected">—</strong></div>
              </div>
            </div>
            <div class="col-md-6">
              <div style="background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                  <span style="font-size:13px;font-weight:600" id="capTargetLabel">Time alvo</span>
                  <span class="tag gray" id="capTargetDelta">±0</span>
                </div>
                <div style="font-size:12px;color:var(--text-2)">Atual: <strong style="color:var(--text)" id="capTargetCurrent">—</strong></div>
                <div style="font-size:12px;color:var(--text-2)">Após trade: <strong style="color:var(--red)" id="capTargetProjected">—</strong></div>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Mensagem (opcional)</label>
            <textarea class="form-control" id="tradeNotes" rows="2" placeholder="Adicione uma mensagem para o outro GM..."></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-r ghost" data-bs-dismiss="modal">Cancelar</button>
        <?php if ($tradesEnabled): ?>
        <button type="button" class="btn-r primary" id="submitTradeBtn">
          <i class="bi bi-send"></i> Enviar Proposta
        </button>
        <?php else: ?>
        <button type="button" class="btn-r ghost disabled"><i class="bi bi-lock-fill"></i> Bloqueado</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Trade Múltipla -->
<div class="modal fade" id="multiTradeModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-people-fill me-2" style="color:var(--red)"></i>Trade Múltipla</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="multiTradeForm">
          <div class="mb-3">
            <label class="form-label">Times participantes (máx. 7)</label>
            <div id="multiTradeTeamsList" class="d-flex flex-column gap-2"></div>
          </div>
          <div class="mb-3">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
              <label class="form-label mb-0">Itens da troca</label>
              <button type="button" class="btn-r ghost" id="addMultiTradeItemBtn" style="padding:5px 10px;font-size:11px">
                <i class="bi bi-plus-lg"></i> Adicionar item
              </button>
            </div>
            <div id="multiTradeItems"></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Mensagem (opcional)</label>
            <textarea class="form-control" id="multiTradeNotes" rows="2" placeholder="Mensagem para os GMs envolvidos..."></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-r ghost" data-bs-dismiss="modal">Cancelar</button>
        <?php if ($tradesEnabled): ?>
        <button type="button" class="btn-r primary" id="submitMultiTradeBtn">
          <i class="bi bi-send"></i> Enviar Trade Múltipla
        </button>
        <?php else: ?>
        <button type="button" class="btn-r ghost disabled"><i class="bi bi-lock-fill"></i> Bloqueado</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════ SCRIPTS ══════════════ -->
<script>
  window.__TEAM_ID__           = <?= $teamId ? (int)$teamId : 'null' ?>;
  window.__USER_LEAGUE__       = '<?= htmlspecialchars($user['league'], ENT_QUOTES) ?>';
  window.__CURRENT_SEASON_YEAR__ = <?= (int)$currentSeasonYear ?>;
  window.__TEAM_NAME__         = '<?= htmlspecialchars(trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')), ENT_QUOTES) ?>';
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/trades.js?v=20260309"></script>
<script src="/js/trade-list.js?v=20260130"></script>
<script src="/js/rumors.js?v=20260130"></script>
<script src="/js/pwa.js?v=20260130"></script>
<script>
  /* ── Sidebar mobile ──────────────────────────── */
  const sidebar   = document.getElementById('sidebar');
  const sbOverlay = document.getElementById('sbOverlay');
  document.getElementById('menuBtn')?.addEventListener('click', () => { sidebar.classList.toggle('open'); sbOverlay.classList.toggle('show'); });
  sbOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); });

  /* ── Tab switcher ────────────────────────────── */
  function switchTab(id, btn) {
    document.querySelectorAll('.tab-pane-r').forEach(p => p.classList.remove('show'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    const pane = document.getElementById('pane-' + id);
    if (pane) { pane.classList.add('show'); }
    if (btn) btn.classList.add('active');

    // Map tab id to legacy Bootstrap tab target for trades.js compatibility
    const legacyMap = {
      'received':   '#received',
      'sent':       '#sent',
      'history':    '#history',
      'league':     '#league',
      'rumors':     '#rumors',
      'trade-list': '#trade-list',
    };
    // Fire a custom event so trades.js listeners still work
    const legacyTarget = legacyMap[id];
    if (legacyTarget) {
      const fakeEvt = { target: { getAttribute: () => legacyTarget } };
      document.dispatchEvent(new CustomEvent('fba:tabSwitch', { detail: { tab: id, target: legacyTarget } }));
    }
  }

  /* Expose Bootstrap-style tab events for trades.js backward compat */
  document.addEventListener('fba:tabSwitch', (e) => {
    // trades.js listens for Bootstrap shown.bs.tab; we fake it
    const evt = new CustomEvent('shown.bs.tab', { detail: e.detail, bubbles: true });
    document.dispatchEvent(evt);
  });

  /* Also bridge Bootstrap tab API calls from trades.js */
  document.querySelectorAll('[data-bs-toggle="tab"]').forEach(el => {
    el.addEventListener('shown.bs.tab', () => {});
  });

  /* ── Admin trades toggle ─────────────────────── */
  (function(){
    const toggle = document.getElementById('tradesStatusToggle');
    const label  = document.getElementById('toggleLabel');
    const badge  = document.getElementById('tradesStatusBadge');
    const league = window.__USER_LEAGUE__;
    if (!toggle || !league) return;

    toggle.addEventListener('change', async (e) => {
      const enabled = e.target.checked ? 1 : 0;
      try {
        const res = await fetch('/api/admin.php?action=league_settings', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ league, trades_enabled: enabled })
        });
        const data = await res.json();
        if (!res.ok || data.success === false) throw new Error(data.error || 'Erro ao salvar');

        if (enabled) {
          label.textContent = 'Trocas abertas';
          badge.className = 'tag green';
          badge.textContent = 'Ativo';
        } else {
          label.textContent = 'Trocas bloqueadas';
          badge.className = 'tag red';
          badge.textContent = 'Bloqueado';
        }
      } catch (err) {
        alert('Erro ao atualizar status das trocas');
        e.target.checked = !e.target.checked;
      }
    });
  })();

  /* ── Update tab count badges ─────────────────── */
  /* trades.js / rumors.js call these after loading */
  window.updateTabCount = function(tab, count) {
    const map = { received: 'countReceived', league: 'countLeague', rumors: 'countRumors', 'trade-list': 'countTradeList' };
    const el = document.getElementById(map[tab]);
    if (!el) return;
    if (count > 0) { el.style.display = ''; el.textContent = count; } else { el.style.display = 'none'; }
  };
</script>
</body>
</html>