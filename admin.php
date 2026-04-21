<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();
$user = getUserSession();
if (($user['user_type'] ?? 'jogador') !== 'admin') {
    header('Location: /dashboard.php');
    exit;
}
$pdo = db();

// ── Time do admin ─────────────────────────────────────
$stmtTeam = $pdo->prepare('SELECT t.*, t.photo_url, t.city FROM teams t WHERE t.user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC) ?: null;

// ── Temporada ─────────────────────────────────────────
$currentSeason     = null;
$seasonDisplayYear = null;
try {
    $league = $team['league'] ?? $user['league'] ?? 'ELITE';
    $stmtSeason = $pdo->prepare('
        SELECT s.season_number, s.year, sp.start_year, sp.sprint_number
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN (\'completed\'))
        ORDER BY s.created_at DESC LIMIT 1
    ');
    $stmtSeason->execute([$league]);
    $currentSeason = $stmtSeason->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($currentSeason) {
        $y = isset($currentSeason['start_year'], $currentSeason['season_number'])
            ? (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1
            : (int)($currentSeason['year'] ?? date('Y'));
        $seasonDisplayYear = (string)$y;
    }
} catch (Exception $e) {}
$seasonDisplayYear = $seasonDisplayYear ?: date('Y');

$userPhoto = getUserPhoto($user['photo_url'] ?? null);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Admin - FBA Manager</title>

    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0a0a0c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/icon-192.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css?v=20260225-2">

    <style>
        /* ── Design Tokens ─────────────────────────────── */
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
            --sidebar-w:  260px;
            --font:       'Poppins', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }
        :root[data-theme="light"] {
            --bg:       #f6f7fb;
            --panel:    #ffffff;
            --panel-2:  #f2f4f8;
            --panel-3:  #e9edf4;
            --border:   #e3e6ee;
            --border-md:#d7dbe6;
            --border-red:rgba(252,0,37,.18);
            --text:     #111217;
            --text-2:   #5b6270;
            --text-3:   #8b93a5;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { -webkit-text-size-adjust: 100%; }
        html, body { height: 100%; background: var(--bg); color: var(--text); font-family: var(--font); -webkit-font-smoothing: antialiased; }
        body { overflow-x: hidden; }
        a, button { -webkit-tap-highlight-color: transparent; }

        /* ── Sidebar ───────────────────────────────────── */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 260px; height: 100vh;
            background: var(--panel); border-right: 1px solid var(--border);
            display: flex; flex-direction: column; z-index: 300;
            transition: transform var(--t) var(--ease);
            overflow-y: auto; scrollbar-width: none;
        }
        .sidebar::-webkit-scrollbar { display: none; }
        .sb-brand {
            padding: 22px 18px 18px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px; flex-shrink: 0;
        }
        .sb-logo {
            width: 34px; height: 34px; border-radius: 9px; background: var(--red);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0;
        }
        .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .sb-team {
            margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 14px;
            display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
        .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
        .sb-season {
            margin: 10px 14px 0; background: var(--red-soft); border: 1px solid var(--border-red);
            border-radius: 8px; padding: 8px 12px;
            display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
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
        .sb-theme-toggle {
            margin: 0 14px 12px; padding: 8px 10px; border-radius: 10px;
            border: 1px solid var(--border); background: var(--panel-2); color: var(--text);
            display: flex; align-items: center; justify-content: center; gap: 8px;
            font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease);
            width: calc(100% - 28px);
        }
        .sb-theme-toggle:hover { border-color: var(--border-red); color: var(--red); }
        .sb-footer {
            padding: 12px 14px; border-top: 1px solid var(--border);
            display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout {
            width: 26px; height: 26px; border-radius: 7px; background: transparent;
            border: 1px solid var(--border); color: var(--text-2);
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease);
            text-decoration: none; flex-shrink: 0;
        }
        .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        /* ── Topbar ───────────────────────────────────── */
        .topbar {
            display: none; position: fixed; top: 0; left: 0; right: 0;
            height: 54px; background: var(--panel); border-bottom: 1px solid var(--border);
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

        /* ── Main ─────────────────────────────────────── */
        .main { margin-left: var(--sidebar-w); width: calc(100% - var(--sidebar-w)); padding: 32px 40px 60px; }
        .page-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 26px; flex-wrap: wrap; }
        .page-eyebrow { font-size: 12px; letter-spacing: .2em; text-transform: uppercase; color: var(--text-3); margin-bottom: 8px; }
        .page-title { font-size: 28px; font-family: var(--font); font-weight: 800; margin-bottom: 4px; }
        .page-title i { color: var(--red); }

        /* ── Breadcrumb ───────────────────────────────── */
        .breadcrumb { background: none; padding: 0; margin: 0; }
        .breadcrumb-item { font-size: 12px; color: var(--text-3); }
        .breadcrumb-item a { color: var(--text-2); text-decoration: none; }
        .breadcrumb-item a:hover { color: var(--red); }
        .breadcrumb-item.active { color: var(--text-2); }
        .breadcrumb-item + .breadcrumb-item::before { color: var(--text-3); }

        /* ── Panel ────────────────────────────────────── */
        .panel {
            background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 20px 22px 22px;
        }
        .panel + .panel { margin-top: 20px; }
        .panel-title { font-size: 16px; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .panel-title i { color: var(--red); }

        /* ── League Cards ─────────────────────────────── */
        .league-card {
            background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 22px 20px;
            cursor: pointer; transition: border-color var(--t) var(--ease), transform var(--t) var(--ease), box-shadow var(--t) var(--ease);
            height: 100%;
        }
        .league-card:hover { border-color: var(--border-red); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(252,0,37,.08); }
        .league-card h3 { font-size: 22px; font-weight: 800; color: var(--red); margin-bottom: 6px; font-family: var(--font); }
        .league-card p { font-size: 13px; margin-bottom: 10px; }

        /* ── Action Cards ─────────────────────────────── */
        .action-card {
            background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 20px;
            cursor: pointer; transition: border-color var(--t) var(--ease), transform var(--t) var(--ease);
            height: 100%; display: flex; flex-direction: column;
        }
        .action-card:hover { border-color: var(--border-red); transform: translateY(-2px); }
        .action-card > i { font-size: 26px; color: var(--red); margin-bottom: 10px; }
        .action-card h4 { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 5px; display: flex; align-items: center; gap: 8px; }
        .action-card p { font-size: 12px; color: var(--text-2); margin: 0; }

        /* ── Team Cards ───────────────────────────────── */
        .team-card {
            background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 14px;
            cursor: pointer; transition: border-color var(--t) var(--ease);
            height: 100%;
        }
        .team-card:hover { border-color: var(--border-red); }
        .team-card h5 { font-size: 13px; font-weight: 700; color: var(--text); margin: 0; line-height: 1.3; }
        .team-logo { width: 44px; height: 44px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border); flex-shrink: 0; }

        /* ── FA Card ──────────────────────────────────── */
        .fa-card {
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 14px;
            transition: border-color var(--t);
        }
        .fa-card:hover { border-color: var(--border-md); }

        /* ── Buttons ──────────────────────────────────── */
        .btn-back {
            background: transparent; border: 1px solid var(--border);
            color: var(--text-2); border-radius: 10px; padding: 8px 14px;
            font-size: 13px; cursor: pointer; transition: all var(--t) var(--ease);
            font-family: var(--font);
        }
        .btn-back:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }
        .btn-orange {
            background: var(--red); border: none; color: #fff;
            font-weight: 700; border-radius: 10px; padding: 9px 18px;
            font-size: 13px; cursor: pointer; font-family: var(--font);
            transition: transform var(--t) var(--ease), box-shadow var(--t) var(--ease);
        }
        .btn-orange:hover, .btn-orange:focus { transform: translateY(-1px); box-shadow: 0 8px 16px rgba(252,0,37,.24); color: #fff; }
        .btn-orange:disabled { opacity: .55; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-outline-orange {
            background: transparent; border: 1px solid var(--border-red);
            color: var(--red); font-weight: 600; border-radius: 10px; padding: 8px 16px;
            font-size: 13px; cursor: pointer; font-family: var(--font);
            transition: all var(--t) var(--ease);
        }
        .btn-outline-orange:hover, .btn-outline-orange.active, .btn-outline-orange:focus {
            background: var(--red-soft); color: var(--red); border-color: var(--red);
        }

        /* ── Admin check card ─────────────────────────── */
        .admin-check-card { border: 2px solid var(--border) !important; transition: border-color var(--t); }
        .admin-check-card.is-accepted { border-color: #25c677 !important; }

        /* ── Toast ────────────────────────────────────── */
        #adminToast {
            position: fixed; bottom: 24px; right: 24px; z-index: 9999;
            background: var(--panel); border: 1px solid var(--border);
            border-radius: 12px; padding: 12px 18px; font-size: 14px; font-weight: 500;
            color: var(--text); box-shadow: 0 8px 32px rgba(0,0,0,.4);
            display: none; align-items: center; gap: 10px; min-width: 240px;
            transform: translateY(8px); opacity: 0;
            transition: all .25s var(--ease);
        }
        #adminToast.show { display: flex; transform: translateY(0); opacity: 1; }
        #adminToast.toast-success { border-color: rgba(37,198,119,.3); }
        #adminToast.toast-success i { color: #25c677; }
        #adminToast.toast-danger  { border-color: var(--border-red); }
        #adminToast.toast-danger  i { color: var(--red); }
        #adminToast.toast-info    { border-color: var(--border-md); }
        #adminToast.toast-info    i { color: #2196f3; }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .topbar { display: flex; }
            .main { margin-left: 0; width: 100%; padding: 54px 16px 40px; }
        }

        /* ══════════════════════════════════════════════
           COMPAT OVERRIDES — admin.js generated HTML
        ══════════════════════════════════════════════ */

        /* Color utils */
        .text-orange      { color: var(--red)    !important; }
        .text-light-gray  { color: var(--text-2) !important; }

        /* Backgrounds */
        .bg-dark-panel    { background: var(--panel)   !important; border: 1px solid var(--border) !important; }
        .bg-dark          { background: var(--panel-2) !important; }
        .bg-gradient-orange { background: var(--red)   !important; color: #fff !important; }
        .bg-orange        { background: var(--red)     !important; color: #fff !important; }

        /* Borders */
        .border-orange    { border-color: var(--border-red) !important; }

        /* Bootstrap table-dark */
        .table-dark {
            --bs-table-bg: transparent;
            --bs-table-border-color: var(--border);
            --bs-table-color: var(--text);
            --bs-table-hover-bg: var(--panel-3);
            --bs-table-striped-bg: var(--panel-2);
        }
        .table-dark > :not(caption) > * > * { color: var(--text); border-color: var(--border); }
        .table-dark thead th {
            color: var(--text-3) !important; font-size: 11px;
            text-transform: uppercase; letter-spacing: .12em;
        }
        .table-dark tbody tr:hover > * { background: var(--panel-3); }

        /* Form controls */
        .form-select,
        .form-control {
            background-color: var(--panel-2) !important;
            border-color: var(--border) !important;
            color: var(--text) !important;
            border-radius: 10px !important;
        }
        .form-select:focus,
        .form-control:focus {
            border-color: var(--border-red) !important;
            box-shadow: 0 0 0 3px var(--red-soft) !important;
            background-color: var(--panel-2) !important;
            color: var(--text) !important;
        }
        .form-select option { background: var(--panel-2); color: var(--text); }
        .input-group-text {
            background-color: var(--panel-3) !important;
            border-color: var(--border) !important;
            color: var(--text-2) !important;
        }
        .form-control[readonly] {
            background-color: var(--panel-3) !important;
            color: var(--text-2) !important;
        }
        .form-check-input:checked {
            background-color: var(--red) !important;
            border-color: var(--red) !important;
        }
        .form-check-label { color: var(--text-2) !important; }

        /* Nav tabs */
        .nav-tabs { border-bottom-color: var(--border) !important; }
        .nav-tabs .nav-link {
            color: var(--text-2) !important;
            border-color: transparent !important;
            border-radius: 10px 10px 0 0 !important;
            font-size: 13px;
        }
        .nav-tabs .nav-link.active {
            background: var(--panel) !important;
            border-color: var(--border) var(--border) transparent !important;
            color: var(--text) !important;
            font-weight: 600 !important;
        }
        .nav-tabs .nav-link:hover { border-color: transparent !important; color: var(--text) !important; }
        .tab-content { padding-top: 16px; }

        /* Bootstrap cards from admin.js */
        .card {
            background: var(--panel) !important;
            border-color: var(--border) !important;
            border-radius: var(--radius) !important;
            color: var(--text) !important;
        }
        .card-header {
            background: var(--panel-2) !important;
            border-color: var(--border) !important;
        }
        .card-body { color: var(--text) !important; }

        /* Modals */
        .modal-content {
            background: var(--panel) !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius) !important;
            color: var(--text) !important;
        }
        .modal-content.bg-dark-panel { background: var(--panel) !important; }
        .modal-header,
        .modal-footer { border-color: var(--border) !important; }
        .modal-title  { color: var(--text) !important; }
        .btn-close-white { filter: invert(1) !important; }

        /* Alerts */
        .alert-info    { background: var(--panel-2)         !important; border-color: var(--border)     !important; color: var(--text) !important; }
        .alert-danger  { background: rgba(252,0,37,.10)     !important; border-color: var(--border-red) !important; color: var(--text) !important; }
        .alert-warning { background: rgba(255,193,7,.10)    !important; border-color: rgba(255,193,7,.3)!important; color: var(--text) !important; }
        .alert-success { background: rgba(37,198,119,.10)   !important; border-color: rgba(37,198,119,.3)!important; color: var(--text) !important; }

        /* Spinner */
        .spinner-border.text-orange { color: var(--red) !important; }

        /* Badges */
        .badge.bg-secondary  { background: var(--panel-3) !important; color: var(--text-2) !important; }
        .badge.bg-gradient-orange { background: var(--red) !important; }

        /* Bootstrap btn overrides used by admin.js */
        .btn-success     { background-color: #25c677 !important; border-color: #25c677 !important; }
        .btn-outline-success { border-color: #25c677 !important; color: #25c677 !important; }
        .btn-outline-success:hover { background-color: rgba(37,198,119,.12) !important; }
        .btn-outline-warning { border-color: #ffc107 !important; color: #ffc107 !important; }
        .btn-outline-warning:hover { background-color: rgba(255,193,7,.12) !important; }
        .btn-outline-light { border-color: var(--border-md) !important; color: var(--text) !important; }
        .btn-outline-light:hover { background-color: var(--panel-2) !important; color: var(--text) !important; }
        .btn-outline-primary { border-color: #2196f3 !important; color: #2196f3 !important; }
        .btn-outline-primary:hover { background: rgba(33,150,243,.12) !important; }
        .btn-secondary { background: var(--panel-3) !important; border-color: var(--border-md) !important; color: var(--text-2) !important; }
        .btn-secondary:hover { background: var(--panel-2) !important; color: var(--text) !important; }
        .btn-outline-danger:hover { background: rgba(252,0,37,.12) !important; }

        /* Form switch */
        .form-switch .form-check-input {
            background-color: var(--panel-3) !important;
            border-color: var(--border-md) !important;
        }
        .form-switch .form-check-input:checked { background-color: var(--red) !important; border-color: var(--red) !important; }

        /* Breadcrumb Bootstrap */
        .breadcrumb-item + .breadcrumb-item::before { color: var(--text-3); }

        /* HR */
        hr { border-color: var(--border) !important; opacity: 1 !important; }

        /* Text utils */
        .text-muted { color: var(--text-3) !important; }
        .text-white  { color: var(--text)   !important; }

        /* Pre */
        pre { color: var(--text-2) !important; font-size: 12px; }
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
                <span>Liga <?= htmlspecialchars($team['league'] ?? '') ?></span>
            </div>
        </div>

        <?php if ($team): ?>
        <div class="sb-team">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
                 alt="<?= htmlspecialchars($team['name'] ?? '') ?>"
                 onerror="this.src='/img/default-team.png'">
            <div>
                <div class="sb-team-name"><?= htmlspecialchars(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')) ?></div>
                <div class="sb-team-league"><?= htmlspecialchars($team['league'] ?? '') ?></div>
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
            <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
            <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
            <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>

            <div class="sb-section">Liga</div>
            <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
            <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
            <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
            <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
            <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>

            <div class="sb-section">Admin</div>
            <a href="/admin.php" class="active"><i class="bi bi-shield-lock-fill"></i> Admin</a>
            <a href="/punicoes.php"><i class="bi bi-exclamation-triangle-fill"></i> Punições</a>
            <a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a>

            <div class="sb-section">Conta</div>
            <a href="/settings.php"><i class="bi bi-gear-fill"></i> Configurações</a>
        </nav>

        <button class="sb-theme-toggle" type="button" id="themeToggle">
            <i class="bi bi-moon"></i>
            <span>Modo escuro</span>
        </button>

        <div class="sb-footer">
            <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>"
                 alt="<?= htmlspecialchars($user['name'] ?? '') ?>"
                 class="sb-avatar"
                 onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name'] ?? 'Admin') ?>&background=1c1c21&color=fc0025'">
            <span class="sb-username"><?= htmlspecialchars($user['name'] ?? '') ?></span>
            <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </aside>

    <!-- Overlay mobile -->
    <div class="sb-overlay" id="sbOverlay"></div>

    <!-- Topbar mobile -->
    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Admin</em></div>
        <?php if ($currentSeason): ?>
        <span style="font-size:11px;font-weight:700;color:var(--red)"><?= htmlspecialchars($seasonDisplayYear) ?></span>
        <?php endif; ?>
    </header>

    <!-- ── Main Content ──────────────────────────────── -->
    <main class="main" id="app-main">
    <div class="page-top">
        <div>
            <div class="page-eyebrow">Administração</div>
            <h1 class="page-title">
                <i class="bi bi-shield-lock-fill"></i>
                <span id="pageTitle">Painel Administrativo</span>
            </h1>
            <nav id="breadcrumbContainer" aria-label="breadcrumb" style="display:none; margin-top:6px;">
                <ol class="breadcrumb" id="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="#" onclick="showHome(); return false;">Admin</a>
                    </li>
                </ol>
            </nav>
        </div>
    </div>

    <!-- Conteúdo dinâmico renderizado por admin.js -->
    <div id="mainContainer"></div>
</main>

<!-- ── Toast de feedback ───────────────────────────── -->
<div id="adminToast">
    <i class="bi bi-check-circle-fill" id="adminToastIcon"></i>
    <span id="adminToastMsg"></span>
</div>

<!-- ── Scripts ────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* ── Theme ────────────────────────────────────────── */
(function () {
    const key = 'fba-theme';
    const root = document.documentElement;
    const saved = localStorage.getItem(key);
    const prefersLight = window.matchMedia?.('(prefers-color-scheme: light)').matches;
    root.dataset.theme = saved || (prefersLight ? 'light' : 'dark');
})();

document.addEventListener('DOMContentLoaded', function () {
    const key  = 'fba-theme';
    const root = document.documentElement;

    const setBtn = (btn, theme) => {
        if (!btn) return;
        const isLight = theme === 'light';
        btn.setAttribute('aria-pressed', String(isLight));
        btn.innerHTML = isLight
            ? '<i class="bi bi-moon-stars-fill"></i><span>Tema escuro</span>'
            : '<i class="bi bi-sun-fill"></i><span>Tema claro</span>';
    };

    document.querySelectorAll('#themeToggle').forEach(btn => {
        setBtn(btn, root.dataset.theme);
        btn.addEventListener('click', () => {
            const next = root.dataset.theme === 'light' ? 'dark' : 'light';
            root.dataset.theme = next;
            localStorage.setItem(key, next);
            document.querySelectorAll('#themeToggle').forEach(b => setBtn(b, next));
        });
    });

    /* ── Sidebar mobile ───────────────────────── */
    const sidebar   = document.getElementById('sidebar');
    const sbOverlay = document.getElementById('sbOverlay');
    const menuBtn   = document.getElementById('menuBtn');

    menuBtn?.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        sbOverlay.classList.toggle('show');
    });
    sbOverlay?.addEventListener('click', () => {
        sidebar.classList.remove('open');
        sbOverlay.classList.remove('show');
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
            sbOverlay.classList.remove('show');
        }
    });
    sidebar?.querySelectorAll('.sb-nav a').forEach(a => {
        a.addEventListener('click', () => {
            if (window.innerWidth <= 820) {
                sidebar.classList.remove('open');
                sbOverlay.classList.remove('show');
            }
        });
    });
});

/* ── showAlert (usado por admin.js e seasons.js) ──── */
function showAlert(type, message) {
    const toast   = document.getElementById('adminToast');
    const msgEl   = document.getElementById('adminToastMsg');
    const iconEl  = document.getElementById('adminToastIcon');
    if (!toast || !msgEl) return;

    const icons = {
        success: 'bi-check-circle-fill',
        danger:  'bi-x-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info:    'bi-info-circle-fill'
    };

    toast.className = '';
    toast.classList.add('show', `toast-${type}`);
    iconEl.className = `bi ${icons[type] || icons.info}`;
    msgEl.textContent = message;

    clearTimeout(toast._timeout);
    toast._timeout = setTimeout(() => {
        toast.classList.remove('show');
    }, 3500);
}
</script>

<script src="/js/admin.js?v=<?= time() ?>"></script>
<script src="/js/seasons.js?v=<?= time() ?>"></script>
<script src="/js/pwa.js"></script>
</div><!-- /.app -->
</body>
</html>
