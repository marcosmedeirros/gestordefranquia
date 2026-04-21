<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();

// Apenas admin acessa esta página
if (($user['user_type'] ?? 'jogador') !== 'admin') {
    header('Location: /dashboard.php');
    exit;
}

$pdo = db();

$team = null;
try {
    $stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
    $stmtTeam->execute([$user['id']]);
    $team = $stmtTeam->fetch() ?: null;
} catch (Exception $e) {}

$currentSeason   = null;
$seasonDisplayYear = null;
try {
    $stmtSeason = $pdo->prepare("
        SELECT s.season_number, s.year, sp.sprint_number, sp.start_year
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed'))
        ORDER BY s.created_at DESC LIMIT 1
    ");
    $stmtSeason->execute([$user['league']]);
    $currentSeason = $stmtSeason->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($currentSeason) {
        $seasonDisplayYear = isset($currentSeason['start_year'], $currentSeason['season_number'])
            ? (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1
            : (int)($currentSeason['year'] ?? date('Y'));
    }
} catch (Exception $e) {}
$seasonDisplayYear = $seasonDisplayYear ?: (int)date('Y');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="manifest" href="/manifest.json?v=3">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
    <title>Temporadas - FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
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
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

        /* Shell */
        .app { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 260px; height: 100vh;
            background: var(--panel);
            border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            z-index: 300;
            transition: transform var(--t) var(--ease);
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

        /* Topbar mobile */
        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 260; }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
        .sb-overlay.show { display: block; }

        /* Main */
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }

        /* Page header */
        .page-hero { padding: 28px 32px 0; border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 0; }
        .page-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .page-title { font-size: 22px; font-weight: 800; }

        /* Content */
        .content { padding: 24px 32px 48px; flex: 1; }

        /* Card base */
        .bc { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .bc-head { padding: 16px 18px 14px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .bc-title { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .bc-title i { color: var(--red); font-size: 15px; }
        .season-top-grid { display: grid; grid-template-columns: 1fr auto; gap: 16px; margin-bottom: 16px; align-items: start; }
        @media (max-width: 576px) { .season-top-grid { grid-template-columns: 1fr; } }
        .bc-body { padding: 16px 18px; }

        /* League grid */
        .league-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
        .league-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 20px;
            cursor: pointer;
            transition: border-color var(--t) var(--ease), transform var(--t) var(--ease);
            text-align: center;
            text-decoration: none;
            display: block;
        }
        .league-card:hover { border-color: var(--border-red); transform: translateY(-3px); }
        .league-card-name { font-size: 20px; font-weight: 800; color: var(--text); margin-bottom: 6px; }
        .league-card-sub { font-size: 12px; color: var(--text-2); }
        .league-card-badge { display: inline-flex; align-items: center; gap: 5px; margin-top: 12px; padding: 4px 12px; border-radius: 999px; background: var(--red-soft); border: 1px solid var(--border-red); color: var(--red); font-size: 11px; font-weight: 600; }

        /* Back button */
        .btn-back { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; cursor: pointer; transition: all var(--t) var(--ease); margin-bottom: 20px; }
        .btn-back:hover { border-color: var(--border-red); color: var(--red); }

        /* Action button */
        .btn-primary-red { padding: 9px 20px; border-radius: 9px; background: var(--red); border: none; color: #fff; font-family: var(--font); font-size: 13px; font-weight: 600; cursor: pointer; transition: filter var(--t) var(--ease); display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary-red:hover { filter: brightness(1.1); color: #fff; }
        .btn-outline-red { padding: 9px 20px; border-radius: 9px; background: transparent; border: 1px solid var(--border-red); color: var(--red); font-family: var(--font); font-size: 13px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease); display: inline-flex; align-items: center; gap: 6px; }
        .btn-outline-red:hover { background: var(--red-soft); }

        /* Override Bootstrap dark bg for modals/tables */
        .modal-content.bg-dark { background: var(--panel) !important; border: 1px solid var(--border) !important; }
        .modal-header { border-color: var(--border) !important; }
        .modal-footer { border-color: var(--border) !important; }
        .table-dark { --bs-table-bg: var(--panel-2); --bs-table-border-color: var(--border); color: var(--text); }
        .table-dark th { color: var(--text-2) !important; font-size: 11px; text-transform: uppercase; letter-spacing: .6px; font-weight: 700; }
        .bg-dark-panel { background: var(--panel-2) !important; }
        .bg-dark { background: var(--panel-2) !important; }
        .border-orange { border-color: var(--border-red) !important; }
        .text-orange { color: var(--red) !important; }
        .text-light-gray { color: var(--text-2) !important; }
        .btn-orange { background: var(--red); border-color: var(--red); color: #fff; font-family: var(--font); font-weight: 600; border-radius: 9px !important; }
        .btn-orange:hover { filter: brightness(1.1); color: #fff; }
        .btn-outline-orange { border-color: var(--border-red); color: var(--red); font-family: var(--font); font-weight: 600; border-radius: 9px !important; }
        .btn-outline-orange:hover { background: var(--red-soft); color: var(--red); }
        .bg-gradient-orange, .badge.bg-orange { background: var(--red) !important; }
        .spinner-border.text-orange { color: var(--red) !important; }
        /* Cards */
        .card { background: var(--panel-2) !important; border-color: var(--border) !important; border-radius: var(--radius) !important; }
        .card-body { padding: 16px 18px; }
        .card-header { background: transparent !important; border-color: var(--border) !important; padding: 12px 18px; font-weight: 700; font-size: 13px; }
        /* Form controls */
        .form-control.bg-dark, .form-select.bg-dark {
            background: var(--panel-3) !important; border-color: var(--border-md) !important; color: var(--text) !important; font-family: var(--font);
        }
        .form-control.bg-dark:focus, .form-select.bg-dark:focus {
            border-color: var(--border-red) !important; box-shadow: 0 0 0 2px var(--red-soft) !important; background: var(--panel-3) !important;
        }
        .form-control.bg-dark::placeholder { color: var(--text-3) !important; }
        .form-control.border-warning, .form-select.border-warning { border-color: rgba(245,158,11,.4) !important; }
        .form-control.border-success, .form-select.border-success { border-color: rgba(34,197,94,.4) !important; }
        .form-control.text-success { color: var(--green) !important; }
        .input-group-text { background: var(--panel-3) !important; border-color: var(--border-md) !important; color: var(--text-2) !important; }
        /* Buttons */
        .btn { font-family: var(--font); font-weight: 600; border-radius: 9px !important; transition: all .2s ease !important; }
        .btn-success { background: var(--green) !important; border-color: var(--green) !important; color: #fff !important; }
        .btn-success:hover { background: #16a34a !important; border-color: #16a34a !important; }
        .btn-success:disabled { background: rgba(34,197,94,.3) !important; border-color: transparent !important; }
        .btn-outline-success { border-color: rgba(34,197,94,.5) !important; color: var(--green) !important; background: transparent !important; }
        .btn-outline-success:hover { background: rgba(34,197,94,.1) !important; }
        .btn-danger { background: var(--red) !important; border-color: var(--red) !important; color: #fff !important; }
        .btn-danger:hover { filter: brightness(1.12); }
        .btn-outline-danger { border-color: var(--border-red) !important; color: var(--red) !important; background: transparent !important; }
        .btn-outline-danger:hover { background: var(--red-soft) !important; }
        .btn-warning { background: var(--amber) !important; border-color: var(--amber) !important; color: #000 !important; }
        .btn-warning:hover { background: #d97706 !important; border-color: #d97706 !important; }
        .btn-outline-warning { border-color: rgba(245,158,11,.5) !important; color: var(--amber) !important; background: transparent !important; }
        .btn-outline-warning:hover { background: rgba(245,158,11,.1) !important; }
        .btn-primary { background: var(--blue) !important; border-color: var(--blue) !important; color: #fff !important; }
        .btn-primary:hover { background: #2563eb !important; border-color: #2563eb !important; }
        .btn-outline-primary { border-color: rgba(59,130,246,.5) !important; color: var(--blue) !important; background: transparent !important; }
        .btn-outline-primary:hover { background: rgba(59,130,246,.1) !important; }
        .btn-secondary { background: var(--panel-3) !important; border-color: var(--border) !important; color: var(--text-2) !important; }
        .btn-secondary:hover { background: var(--panel-2) !important; color: var(--text) !important; border-color: var(--border-md) !important; }
        .btn-outline-light { border-color: var(--border-md) !important; color: var(--text-2) !important; background: transparent !important; }
        .btn-outline-light:hover { background: var(--panel-2) !important; color: var(--text) !important; }
        .btn-info { background: var(--blue) !important; border-color: var(--blue) !important; color: #fff !important; }
        .btn-info:hover { background: #2563eb !important; }
        /* Alerts */
        .alert { border-radius: var(--radius-sm) !important; border: none !important; border-left: 3px solid transparent !important; font-size: 13px; padding: 12px 16px; font-family: var(--font); }
        .alert-info { background: rgba(59,130,246,.1) !important; color: #93c5fd !important; border-left-color: var(--blue) !important; }
        .alert-success { background: rgba(34,197,94,.1) !important; color: #86efac !important; border-left-color: var(--green) !important; }
        .alert-danger { background: rgba(252,0,37,.08) !important; color: #fca5a5 !important; border-left-color: var(--red) !important; }
        .alert-warning { background: rgba(245,158,11,.1) !important; color: #fcd34d !important; border-left-color: var(--amber) !important; }
        .alert-secondary { background: var(--panel-3) !important; color: var(--text-2) !important; border-left-color: var(--border-md) !important; }
        /* Badges */
        .badge { font-family: var(--font); font-weight: 700; padding: 3px 8px; border-radius: 999px !important; font-size: 10px; letter-spacing: .3px; }
        .badge.bg-success { background: rgba(34,197,94,.15) !important; color: #86efac !important; }
        .badge.bg-info { background: rgba(59,130,246,.15) !important; color: #93c5fd !important; }
        .badge.bg-warning { background: rgba(245,158,11,.15) !important; color: var(--amber) !important; }
        .badge.bg-warning.text-dark { color: var(--amber) !important; }
        .badge.bg-secondary { background: var(--panel-3) !important; color: var(--text-2) !important; }
        .badge.bg-danger { background: var(--red-soft) !important; color: var(--red) !important; }
        .badge.bg-primary { background: rgba(59,130,246,.15) !important; color: #93c5fd !important; }
        /* List groups */
        .list-group-item { background: transparent !important; border-color: var(--border) !important; color: var(--text) !important; font-family: var(--font); font-size: 13px; padding: 10px 16px; }
        .list-group-item.bg-dark { background: transparent !important; }
        /* Card colored headers */
        .card-header.bg-danger { background: rgba(252,0,37,.12) !important; color: var(--red) !important; }
        .card-header.bg-primary { background: rgba(59,130,246,.12) !important; color: #93c5fd !important; }
        .card-header.bg-warning { background: rgba(245,158,11,.12) !important; color: var(--amber) !important; }
        .card-header.bg-info { background: rgba(59,130,246,.12) !important; color: #93c5fd !important; }
        .card-header.bg-success { background: rgba(34,197,94,.12) !important; color: #86efac !important; }
        .card.border-danger { border-color: rgba(252,0,37,.25) !important; }
        .card.border-primary { border-color: rgba(59,130,246,.25) !important; }
        .card.border-warning { border-color: rgba(245,158,11,.25) !important; }
        .card.border-info { border-color: rgba(59,130,246,.25) !important; }
        .card.border-success { border-color: rgba(34,197,94,.25) !important; }
        /* Matchup */
        .matchup { background: var(--panel-3) !important; border-radius: var(--radius-sm) !important; }
        /* Draft order item */
        .draft-order-item { background: var(--panel-3) !important; border-radius: var(--radius-sm) !important; }
        .draft-order-item:hover { border-color: var(--border-red); }

        /* Responsive */
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .topbar { display: flex; }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .league-grid { grid-template-columns: repeat(2, 1fr); }
            .page-hero { padding: 20px 16px 16px; }
            .content { padding: 16px 16px 40px; }
        }
        @media (max-width: 575px) {
            .league-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

<div class="app">

    <aside class="sidebar" id="sidebar">
        <div class="sb-brand">
            <div class="sb-logo">FBA</div>
            <div class="sb-brand-text">
                FBA Manager
                <span>Liga <?= htmlspecialchars($user['league']) ?></span>
            </div>
        </div>

        <?php if ($team): ?>
        <div class="sb-team">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
                 alt="<?= htmlspecialchars($team['name'] ?? '') ?>"
                 onerror="this.src='/img/default-team.png'">
            <div>
                <div class="sb-team-name"><?= htmlspecialchars(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')) ?></div>
                <div class="sb-team-league"><?= htmlspecialchars($user['league']) ?></div>
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
            <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
            <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
            <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>

            <div class="sb-section">Admin</div>
            <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
            <a href="/punicoes.php"><i class="bi bi-exclamation-triangle-fill"></i> Punições</a>
            <a href="/temporadas.php" class="active"><i class="bi bi-calendar3"></i> Temporadas</a>

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
        <span style="font-size:11px;font-weight:700;color:var(--red)"><?= $seasonDisplayYear ?></span>
    </header>

    <main class="main">
        <div class="page-hero">
            <div class="page-eyebrow">Admin · <?= htmlspecialchars($user['league']) ?></div>
            <h1 class="page-title"><i class="bi bi-calendar3" style="color:var(--red);margin-right:10px"></i>Gerenciar Temporadas</h1>
        </div>

        <div class="content">
            <div id="mainContainer">
                <div class="text-center py-5">
                    <div class="spinner-border" style="color:var(--red)"></div>
                </div>
            </div>
        </div>
    </main>

</div><!-- /.app -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // API helper
    const api = async (path, options = {}) => {
      const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
      let body = {};
      try { body = await res.json(); } catch {}
      if (!res.ok) throw body;
      return body;
    };

    let currentLeague = null;
    let currentSeasonData = null;
    let currentSeasonId = null;
    let timerInterval = null;

    // ========== TELA INICIAL COM AS 4 LIGAS ==========
    async function showLeaguesOverview() {
      const container = document.getElementById('mainContainer');
      const leagues = [
        { name: 'ELITE',  sub: '20 temporadas por sprint' },
        { name: 'NEXT',   sub: '21 temporadas por sprint' },
        { name: 'RISE',   sub: '15 temporadas por sprint' },
        { name: 'ROOKIE', sub: '10 temporadas por sprint' },
      ];
      container.innerHTML = `
        <p style="font-size:13px;color:var(--text-2);margin-bottom:20px;">Selecione uma liga para gerenciar suas temporadas:</p>
        <div class="league-grid">
          ${leagues.map(l => `
            <div class="league-card" onclick="showLeagueManagement('${l.name}')">
              <div class="league-card-name">${l.name}</div>
              <div class="league-card-sub">${l.sub}</div>
              <div class="league-card-badge"><i class="bi bi-gear-fill"></i> Gerenciar</div>
            </div>
          `).join('')}
        </div>
      `;
    }

    // ========== TELA DE GERENCIAMENTO DE UMA LIGA ==========
    async function showLeagueManagement(league) {
      currentLeague = league;
      
      try {
        // Buscar temporada atual
        const data = await api(`seasons.php?action=current_season&league=${league}`);
        currentSeasonData = data.season;
        
        const container = document.getElementById('mainContainer');
        
        if (!currentSeasonData) {
          container.innerHTML = `
            <button class="btn-back" onclick="showLeaguesOverview()">
              <i class="bi bi-arrow-left"></i> Voltar
            </button>
            <div style="text-align:center;padding:56px 16px;">
              <i class="bi bi-calendar-plus" style="font-size:40px;color:var(--red);display:block;margin-bottom:14px;"></i>
              <div style="font-size:18px;font-weight:800;color:var(--text);margin-bottom:8px;">Nenhuma temporada ativa</div>
              <p style="font-size:13px;color:var(--text-2);margin-bottom:24px;">Inicie uma nova temporada para a liga <strong style="color:var(--red);">${league}</strong></p>
              <button class="btn-primary-red" onclick="startNewSeason('${league}')">
                <i class="bi bi-play-fill"></i> Iniciar Nova Temporada
              </button>
            </div>
          `;
        } else {
          // Temporada ativa - mostrar contador e opções
          await renderActiveSeasonView(league, currentSeasonData);
        }
      } catch (e) {
        console.error(e);
        alert('Erro ao carregar dados da liga');
      }
    }

    // ========== RENDERIZAR TELA DE TEMPORADA ATIVA ==========
  async function renderActiveSeasonView(league, season) {
      const container = document.getElementById('mainContainer');
      const sprintStartYear = resolveSprintStartYearFromSeason(season);
      // Corrigir exibição do ano: usar fórmula start_year + season_number - 1 quando possível
      const displayedYear = (sprintStartYear && season?.season_number)
        ? (Number(sprintStartYear) + Number(season.season_number) - 1)
        : Number(season.year);
      
      // Verificar se sprint acabou
      const maxSeasons = getMaxSeasonsForLeague(league);
      const sprintCompleted = season.season_number >= maxSeasons;
      
      // Decidir ação principal da temporada (primeiro ano: configurar draft inicial)
      let primaryActionHTML = '';
      if (!sprintCompleted) {
        if (Number(season.season_number) === 1) {
          try {
            const initResp = await api(`initdraft.php?action=session_for_season&season_id=${season.id}`);
            const session = initResp.session;
            if (!session) {
              primaryActionHTML = `<button class="btn-primary-red" style="width:100%;justify-content:center;padding:11px;" onclick="createInitDraft(${season.id})"><i class="bi bi-gear"></i> Configurar Draft Inicial</button>`;
            } else if (session.status !== 'completed') {
              const url = `/initdraft.php?token=${session.access_token}`;
              primaryActionHTML = `<a class="btn-primary-red" style="width:100%;justify-content:center;padding:11px;" target="_blank" href="${url}"><i class="bi bi-link-45deg"></i> Abrir Draft Inicial</a>`;
            } else {
              primaryActionHTML = `<button class="btn-outline-red" style="width:100%;justify-content:center;padding:11px;" onclick="advanceToNextSeason('${league}')"><i class="bi bi-skip-forward"></i> Avançar para Próxima Temporada</button>`;
            }
          } catch (e) {
            primaryActionHTML = `<button class="btn-outline-red" style="width:100%;justify-content:center;padding:11px;" onclick="advanceToNextSeason('${league}')"><i class="bi bi-skip-forward"></i> Avançar para Próxima Temporada</button>`;
          }
        } else {
          primaryActionHTML = `<button class="btn-outline-red" style="width:100%;justify-content:center;padding:11px;" onclick="advanceToNextSeason('${league}')"><i class="bi bi-skip-forward"></i> Avançar para Próxima Temporada</button>`;
        }
      }

      const pct = ((season.season_number / maxSeasons) * 100).toFixed(0);
      container.innerHTML = `
        <button class="btn-back" onclick="showLeaguesOverview()">
          <i class="bi bi-arrow-left"></i> Voltar
        </button>

        <div class="season-top-grid">

          <!-- Card principal da liga -->
          <div class="bc">
            <div class="bc-head">
              <div class="bc-title"><i class="bi bi-calendar3"></i> Liga ${league}</div>
              <span style="display:inline-flex;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700;background:var(--red-soft);color:var(--red);border:1px solid var(--border-red);">Ano ${displayedYear}</span>
            </div>
            <div class="bc-body">
              <div style="font-size:13px;color:var(--text-2);margin-bottom:4px;">Temporada <strong style="color:var(--text);">${String(season.season_number).padStart(2,'0')}</strong> de ${maxSeasons}</div>
              <div style="font-size:13px;color:var(--text-2);margin-bottom:16px;">Sprint iniciado em <strong style="color:var(--text);">${sprintStartYear || '??'}</strong></div>
              ${sprintCompleted ? `
                <div style="padding:12px 14px;background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.2);border-radius:10px;color:#4ade80;font-size:13px;margin-bottom:12px;">
                  <i class="bi bi-check-circle"></i> <strong>Sprint Completo!</strong> Todas as ${maxSeasons} temporadas foram concluídas.
                </div>
                <div style="padding:12px 14px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:10px;color:#f59e0b;font-size:13px;margin-bottom:14px;">
                  <i class="bi bi-exclamation-triangle"></i> Antes de iniciar um novo sprint, você precisa resetar os times.
                </div>
                <button style="width:100%;justify-content:center;padding:11px;background:rgba(220,38,38,.12);border:1px solid rgba(220,38,38,.3);color:#f87171;border-radius:10px;font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;" onclick="confirmResetTeams('${league}')">
                  <i class="bi bi-trash3"></i> Resetar Times
                </button>
              ` : `
                <div style="font-size:12px;color:var(--text-3);margin-bottom:10px;">Temporada iniciada em: <span style="color:var(--text-2);">${new Date(season.created_at).toLocaleString('pt-BR')}</span></div>
                ${primaryActionHTML}
              `}
            </div>
          </div>

          <!-- Card progresso -->
          <div class="bc">
            <div class="bc-head">
              <div class="bc-title"><i class="bi bi-bar-chart-fill"></i> Progresso do Sprint</div>
            </div>
            <div class="bc-body">
              <div style="background:var(--panel-3);border-radius:6px;height:10px;overflow:hidden;margin-bottom:10px;">
                <div style="height:100%;width:${pct}%;background:var(--red);border-radius:6px;transition:width .5s;"></div>
              </div>
              <div style="font-size:12px;color:var(--text-2);">${pct}%</div>
              <div style="font-size:13px;margin-top:6px;"><strong style="color:var(--red);">${season.season_number}</strong> <span style="color:var(--text-2);">de ${maxSeasons} temporadas</span></div>
            </div>
          </div>

        </div>

        <!-- GERENCIAR DRAFT -->
        <div class="bc" style="margin-bottom:14px;">
          <div class="bc-head">
            <div class="bc-title"><i class="bi bi-trophy"></i> Gerenciar Draft</div>
          </div>
          <div class="bc-body" style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn-primary-red" onclick="showDraftManagement(${season.id}, '${league}')">
              <i class="bi bi-people"></i> Jogadores do Draft
            </button>
            <button class="btn-outline-red" onclick="showDraftSessionManagement(${season.id}, '${league}')">
              <i class="bi bi-list-ol"></i> Configurar Sessão
            </button>
            <button style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;background:rgba(22,163,74,.10);border:1px solid rgba(22,163,74,.22);color:#4ade80;font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;" onclick="showDraftHistory('${league}')">
              <i class="bi bi-clock-history"></i> Histórico
            </button>
          </div>
        </div>

        <!-- CADASTRO DE HISTÓRICO -->
        <div class="bc" style="margin-bottom:14px;">
          <div class="bc-head">
            <div class="bc-title"><i class="bi bi-award"></i> Cadastro de Histórico</div>
          </div>
          <div class="bc-body">
            <p style="font-size:13px;color:var(--text-2);margin-bottom:14px;">Registre os resultados da temporada ${String(season.season_number).padStart(2, '0')}</p>
            <button class="btn-primary-red" onclick="showHistoryForm(${season.id}, '${league}')">
              <i class="bi bi-pencil"></i> Cadastrar Histórico da Temporada
            </button>
          </div>
        </div>

        <!-- MOEDAS -->
        <div class="bc">
          <div class="bc-head">
            <div class="bc-title"><i class="bi bi-coin"></i> Gerenciar Moedas da Temporada</div>
          </div>
          <div class="bc-body">
            <p style="font-size:13px;color:var(--text-2);margin-bottom:14px;">Defina quantas moedas cada time terá nesta temporada.</p>
            <button class="btn-outline-red" onclick="showSeasonCoinsForm(${season.id}, '${league}')">
              <i class="bi bi-pencil-square"></i> Editar Moedas
            </button>
          </div>
        </div>
      `;
      
      // Iniciar contador se temporada ativa
      if (!sprintCompleted) {
        startTimer(season.created_at);
      }
    }

    // ========== CONTADOR DE TEMPO ==========
    function startTimer(startDate) {
      if (timerInterval) clearInterval(timerInterval);
      
      const start = new Date(startDate).getTime();
      
      timerInterval = setInterval(() => {
        const now = new Date().getTime();
        const diff = now - start;
        
        const hours = Math.floor(diff / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
        
        const timerEl = document.getElementById('timer');
        if (timerEl) {
          timerEl.textContent = 
            String(hours).padStart(2, '0') + ':' + 
            String(minutes).padStart(2, '0') + ':' + 
            String(seconds).padStart(2, '0');
        }
      }, 1000);
    }

      // ========== MOEDAS DA TEMPORADA ==========
      async function showSeasonCoinsForm(seasonId, league) {
        const container = document.getElementById('mainContainer');
        container.innerHTML = '<div style="text-align:center;padding:48px 0"><div class="spinner-border" style="color:var(--red)"></div></div>';

        try {
          const data = await api(`seasons.php?action=season_coins&season_id=${seasonId}`);
          const teams = data.teams || [];

          container.innerHTML = `
            <button class="btn-back" onclick="showLeagueManagement('${league}')">
              <i class="bi bi-arrow-left"></i>Voltar
            </button>
            <div class="bc" style="margin-bottom:20px">
              <div class="bc-head">
                <div class="bc-title"><i class="bi bi-coin"></i>Moedas da Temporada</div>
              </div>
              <form id="coinsForm">
                <div style="overflow-x:auto">
                  <table style="width:100%;border-collapse:collapse;font-family:var(--font);font-size:13px">
                    <thead>
                      <tr style="border-bottom:1px solid var(--border)">
                        <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700">Time</th>
                        <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700;width:160px">Moedas</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${teams.map(t => `
                        <tr style="border-bottom:1px solid var(--border)">
                          <td style="padding:12px 18px;color:var(--text);font-weight:600">${t.city} ${t.name}</td>
                          <td style="padding:8px 18px">
                            <input type="number"
                              name="moedas_${t.id}"
                              value="${t.moedas}"
                              min="0"
                              style="width:110px;background:var(--panel-3);border:1px solid var(--border-md);border-radius:8px;padding:6px 10px;color:var(--green);font-family:var(--font);font-size:13px;font-weight:600;outline:none">
                          </td>
                        </tr>
                      `).join('')}
                    </tbody>
                  </table>
                </div>
                <div style="padding:16px 18px;border-top:1px solid var(--border)">
                  <button type="submit" class="btn-primary-red">
                    <i class="bi bi-save"></i>Salvar Moedas
                  </button>
                </div>
              </form>
            </div>
          `;

          document.getElementById('coinsForm').onsubmit = async function(e) {
            e.preventDefault();
            const form = e.target;
            const updates = [];
            teams.forEach(t => {
              updates.push({
                team_id: t.id,
                moedas: parseInt(form[`moedas_${t.id}`].value, 10) || 0
              });
            });
            try {
              await api('seasons.php?action=season_coins&season_id=' + seasonId, {
                method: 'POST',
                body: JSON.stringify({ updates })
              });
              alert('Moedas atualizadas!');
              showSeasonCoinsForm(seasonId, league);
            } catch (err) {
              alert('Erro ao salvar moedas: ' + (err.error || 'Desconhecido'));
            }
          };
        } catch (e) {
          container.innerHTML = `<div style="padding:16px;background:var(--red-soft);border:1px solid var(--border-red);border-radius:var(--radius-sm);color:var(--red);font-size:13px">Erro ao carregar times: ${e.error || 'Desconhecido'}</div>`;
        }
      }

    // ========== HELPERS ==========
    function getMaxSeasonsForLeague(league) {
      switch(league) {
        case 'ELITE': return 20;
        case 'NEXT': return 21;
        case 'RISE': return 15;
        case 'ROOKIE': return 10;
        default: return 10;
    }
}

    function resolveSprintStartYearFromSeason(season) {
      if (!season) return null;
      if (season.start_year) return Number(season.start_year);
      if (season.year && season.season_number) {
        return Number(season.year) - Number(season.season_number) + 1;
      }
      return null;
    }

    function promptForStartYear(defaultYear) {
      const fallback = defaultYear ?? new Date().getFullYear();
      const input = prompt('Informe o ano inicial do sprint (ex: 2016):', fallback);
      if (input === null) return null;
      const parsed = parseInt(input, 10);
      if (!parsed || parsed < 1900) {
        alert('Ano inválido. Informe um número como 2016.');
        return null;
      }
      return parsed;
    }

    // ========== INICIAR NOVA TEMPORADA ==========
    async function startNewSeason(league) {
      const fallbackStart = resolveSprintStartYearFromSeason(currentSeasonData) ?? new Date().getFullYear();
      const startYear = promptForStartYear(fallbackStart);
      if (!startYear) return;
      const seasonYear = startYear;
      if (!confirm(`Iniciar uma nova temporada para a liga ${league} com sprint começando em ${startYear}?`)) return;

      try {
        const resp = await api('seasons.php?action=create_season', {
          method: 'POST',
          body: JSON.stringify({ league, season_year: seasonYear, start_year: startYear })
        });

        alert('Nova temporada iniciada com sucesso!');
        // Buscar temporada atual para verificar se é a 1ª do sprint
        const seasonData = await api(`seasons.php?action=current_season&league=${league}`);
        const season = seasonData.season;
        // Se for a primeira temporada do sprint, iniciar fluxo do Draft Inicial automaticamente
        if (season && Number(season.season_number) === 1) {
          try {
            const initResp = await api('initdraft.php', {
              method: 'POST',
              body: JSON.stringify({ action: 'create_session', season_id: season.id, total_rounds: 5 })
            });
            const url = `/initdraft.php?token=${initResp.token}`;
            window.open(url, '_blank');
          } catch (e) {
            console.warn('Falha ao criar sessão do Draft Inicial automaticamente:', e);
          }
        }
        showLeagueManagement(league);
      } catch (e) {
        alert('Erro ao iniciar temporada: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== AVANÇAR PARA PRÓXIMA TEMPORADA ==========
    async function advanceToNextSeason(league) {
      if (!currentSeasonData) {
        return startNewSeason(league);
      }

      let sprintStart = resolveSprintStartYearFromSeason(currentSeasonData);
      if (!sprintStart) {
        sprintStart = promptForStartYear(new Date().getFullYear());
      }
      if (!sprintStart) return;

      const nextSeasonNumber = Number(currentSeasonData.season_number || 0) + 1;
      const seasonYear = sprintStart + nextSeasonNumber - 1;
      if (!confirm(`Avançar para a próxima temporada da liga ${league} (Temporada ${String(nextSeasonNumber).padStart(2, '0')} - ano ${seasonYear})?`)) return;

      try {
        const resp = await api('seasons.php?action=create_season', {
          method: 'POST',
          body: JSON.stringify({ league, season_year: seasonYear, start_year: sprintStart })
        });

        alert('Avançado para próxima temporada!');
        // Buscar temporada atual para decidir se é a 1ª do sprint (caso novo sprint tenha sido criado)
        const seasonData = await api(`seasons.php?action=current_season&league=${league}`);
        const season = seasonData.season;
        if (season && Number(season.season_number) === 1) {
          try {
            const initResp = await api('initdraft.php', {
              method: 'POST',
              body: JSON.stringify({ action: 'create_session', season_id: season.id, total_rounds: 5 })
            });
            const url = `/initdraft.php?token=${initResp.token}`;
            window.open(url, '_blank');
          } catch (e) {
            console.warn('Falha ao criar sessão do Draft Inicial automaticamente (novo sprint):', e);
          }
        }
        showLeagueManagement(league);
      } catch (e) {
        alert('Erro ao avançar: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== RESETAR SPRINT (NOVO CICLO) ==========
    // ========== RESETAR TIMES (MANTER PONTOS) ==========
    async function confirmResetTeams(league) {
      if (!confirm(`ATENÇÃO! Isso irá LIMPAR todos os jogadores, picks, trades e histórico da liga ${league}.\n\nAPENAS os pontos do ranking serão mantidos.\n\nConfirma?`)) return;
      if (!confirm('Tem CERTEZA ABSOLUTA? Esta ação não pode ser desfeita!')) return;
      
      try {
        await api('seasons.php?action=reset_teams', {
          method: 'POST',
          body: JSON.stringify({ league })
        });
        
        alert('Times resetados com sucesso! Os pontos do ranking foram mantidos.');
        showLeagueManagement(league);
      } catch (e) {
        alert('Erro ao resetar times: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== GERENCIAR DRAFT ==========
    async function showDraftManagement(seasonId, league) {
      console.log('showDraftManagement called - Version with Import Button');
      currentSeasonId = seasonId;
      currentLeague = league;
      const container = document.getElementById('mainContainer');
      
      try {
        // Buscar jogadores do draft
        const data = await api(`seasons.php?action=draft_players&season_id=${seasonId}`);
        const players = data.players || [];
        const available = players.filter(p => p.draft_status === 'available');
        const drafted = players.filter(p => p.draft_status === 'drafted');
        
        // Buscar dados da temporada
        const seasonData = await api(`seasons.php?action=current_season&league=${league}`);
        const season = seasonData.season;
        const sprintStartYear = resolveSprintStartYearFromSeason(season);
        const draftDisplayedYear = (sprintStartYear && season?.season_number)
          ? (Number(sprintStartYear) + Number(season.season_number) - 1)
          : Number(season.year);
        
        // Buscar times da liga
        const teamsData = await api(`admin.php?action=teams&league=${league}`);
        const teams = teamsData.teams || [];
        
        console.log('Rendering with season:', season);
        
        container.innerHTML = `
          <button class="btn-back" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left"></i>Voltar
          </button>

          <div style="display:grid;grid-template-columns:1fr auto auto;gap:12px;margin-bottom:20px;align-items:stretch">
            <div class="bc">
              <div class="bc-body" style="padding:14px 18px">
                <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:2px">Draft — Temporada ${season.season_number}</div>
                <div style="font-size:12px;color:var(--text-2)">${league} · Sprint ${season.sprint_number || '?'} · Ano ${draftDisplayedYear}</div>
              </div>
            </div>
            <button class="btn-primary-red" onclick="showAddDraftPlayerModal()">
              <i class="bi bi-plus-circle"></i>Adicionar
            </button>
            <button class="btn-outline-red" onclick="showImportCSVModal(${season.id}, '${league}', ${season.season_number})">
              <i class="bi bi-file-earmark-arrow-up"></i>Importar CSV
            </button>
          </div>

          <div class="bc" style="margin-bottom:20px">
            <div class="bc-head">
              <div class="bc-title"><i class="bi bi-people-fill"></i>Disponíveis para Draft (${available.length})</div>
            </div>
            ${available.length === 0 ? `
              <div style="text-align:center;padding:48px 16px;color:var(--text-3)">
                <i class="bi bi-inbox" style="font-size:36px;display:block;margin-bottom:10px"></i>
                <div style="font-size:13px">Nenhum jogador disponível</div>
              </div>
            ` : `
              <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-family:var(--font);font-size:13px">
                  <thead>
                    <tr style="border-bottom:1px solid var(--border)">
                      <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700">#</th>
                      <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700">Nome</th>
                      <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700">Pos</th>
                      <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700">OVR</th>
                      <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700;min-width:220px">Draftar para Time</th>
                      <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700;width:130px">Ações</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${available.map((p, idx) => `
                      <tr style="border-bottom:1px solid var(--border)">
                        <td style="padding:10px 18px;color:var(--text-3)">${idx + 1}</td>
                        <td style="padding:10px 18px;color:var(--text);font-weight:600">${p.name}</td>
                        <td style="padding:10px 18px">
                          <span style="display:inline-flex;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;background:var(--red-soft);color:var(--red)">${p.position || 'N/A'}</span>
                        </td>
                        <td style="padding:10px 18px">
                          <span style="display:inline-flex;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;background:rgba(34,197,94,.12);color:#86efac">OVR ${p.ovr}</span>
                        </td>
                        <td style="padding:8px 18px">
                          <select id="team-${p.id}" style="width:100%;background:var(--panel-3);border:1px solid var(--border-md);border-radius:8px;padding:6px 10px;color:var(--text);font-family:var(--font);font-size:12px">
                            <option value="">Selecione o time...</option>
                            ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                          </select>
                        </td>
                        <td style="padding:8px 18px">
                          <div style="display:flex;gap:6px">
                            <button onclick="draftPlayer(${p.id})" title="Draftar"
                              style="padding:5px 10px;border-radius:7px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#86efac;cursor:pointer;font-size:12px">
                              <i class="bi bi-check-lg"></i>
                            </button>
                            <button onclick="deleteDraftPlayer(${p.id})" title="Remover"
                              style="padding:5px 10px;border-radius:7px;background:var(--red-soft);border:1px solid var(--border-red);color:var(--red);cursor:pointer;font-size:12px">
                              <i class="bi bi-trash"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                    `).join('')}
                  </tbody>
                </table>
              </div>
            `}
          </div>

          ${drafted.length > 0 ? `
            <div class="bc">
              <div class="bc-head">
                <div class="bc-title"><i class="bi bi-check-circle-fill" style="color:var(--green)"></i>Já Draftados (${drafted.length})</div>
              </div>
              <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-family:var(--font);font-size:13px">
                  <thead>
                    <tr style="border-bottom:1px solid var(--border)">
                      <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700">Pick</th>
                      <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700">Nome</th>
                      <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700">Pos</th>
                      <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700">OVR</th>
                      <th style="padding:10px 18px;color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.7px;font-weight:700">Time</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${drafted.map(p => `
                      <tr style="border-bottom:1px solid var(--border)">
                        <td style="padding:10px 18px">
                          <span style="display:inline-flex;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;background:rgba(34,197,94,.12);color:#86efac">Pick #${p.draft_order}</span>
                        </td>
                        <td style="padding:10px 18px;color:var(--text);font-weight:600">${p.name}</td>
                        <td style="padding:10px 18px">
                          <span style="display:inline-flex;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;background:var(--red-soft);color:var(--red)">${p.position}</span>
                        </td>
                        <td style="padding:10px 18px">
                          <span style="display:inline-flex;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;background:rgba(34,197,94,.12);color:#86efac">OVR ${p.ovr}</span>
                        </td>
                        <td style="padding:10px 18px;color:var(--text-2)">${p.team_name || 'N/A'}</td>
                      </tr>
                    `).join('')}
                  </tbody>
                </table>
              </div>
            </div>
          ` : ''}
        `;
      } catch (e) {
        container.innerHTML = `
          <button class="btn-back" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left"></i>Voltar
          </button>
          <div style="padding:14px 16px;background:var(--red-soft);border:1px solid var(--border-red);border-radius:var(--radius-sm);color:var(--red);font-size:13px">Erro ao carregar jogadores: ${e.error || 'Desconhecido'}</div>
        `;
      }
    }
    
    // Adicionar jogador ao draft
    function showAddDraftPlayerModal() {
      const fldStyle = 'width:100%;background:var(--panel-3);border:1px solid var(--border-md);border-radius:8px;padding:8px 12px;color:var(--text);font-family:var(--font);font-size:13px;outline:none';
      const lblStyle = 'display:block;font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:6px';
      const modal = document.createElement('div');
      modal.innerHTML = `
        <div class="modal fade" id="addPlayerModal" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius)">
              <div class="modal-header" style="border-bottom:1px solid var(--border);padding:16px 20px">
                <h5 class="modal-title" style="font-family:var(--font);font-weight:700;color:var(--text);font-size:15px">
                  <i class="bi bi-person-plus-fill" style="color:var(--red);margin-right:8px"></i>Adicionar Jogador ao Draft
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body" style="padding:20px">
                <form id="addPlayerForm" onsubmit="submitAddPlayer(event)">
                  <div style="margin-bottom:14px"><label style="${lblStyle}">Nome</label>
                    <input type="text" style="${fldStyle}" name="name" required placeholder="Nome do jogador">
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
                    <div><label style="${lblStyle}">Posição</label>
                      <select style="${fldStyle}" name="position" required>
                        <option value="">Selecione...</option>
                        <option value="PG">PG - Armador</option>
                        <option value="SG">SG - Ala-Armador</option>
                        <option value="SF">SF - Ala</option>
                        <option value="PF">PF - Ala-Pivô</option>
                        <option value="C">C - Pivô</option>
                      </select>
                    </div>
                    <div><label style="${lblStyle}">Posição Secundária</label>
                      <select style="${fldStyle}" name="secondary_position">
                        <option value="">Nenhuma</option>
                        <option value="PG">PG - Armador</option>
                        <option value="SG">SG - Ala-Armador</option>
                        <option value="SF">SF - Ala</option>
                        <option value="PF">PF - Ala-Pivô</option>
                        <option value="C">C - Pivô</option>
                      </select>
                    </div>
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
                    <div><label style="${lblStyle}">Idade</label>
                      <input type="number" style="${fldStyle}" name="age" min="18" max="40" required placeholder="Ex: 22">
                    </div>
                    <div><label style="${lblStyle}">OVR</label>
                      <input type="number" style="${fldStyle}" name="ovr" min="1" max="99" required placeholder="Ex: 78">
                    </div>
                  </div>
                  <button type="submit" class="btn-primary-red" style="width:100%;justify-content:center">
                    <i class="bi bi-plus-circle"></i>Adicionar Jogador
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('addPlayerModal')).show();
      document.getElementById('addPlayerModal').addEventListener('hidden.bs.modal', () => {
        modal.remove();
      });
    }
    
    async function submitAddPlayer(event) {
      event.preventDefault();
  const form = event.target;
  const formData = new FormData(form);
  const secondaryPosition = formData.get('secondary_position');
      
      try {
        await api('seasons.php?action=add_draft_player', {
          method: 'POST',
          body: JSON.stringify({
            season_id: currentSeasonId,
            name: formData.get('name'),
            age: formData.get('age'),
            position: formData.get('position'),
            secondary_position: secondaryPosition,
            ovr: formData.get('ovr'),
            photo_url: null
          })
        });
        
        bootstrap.Modal.getOrCreateInstance(document.getElementById('addPlayerModal')).hide();
        
        // Recarregar a lista
        showDraftManagement(currentSeasonId, currentLeague);
        
        alert('Jogador adicionado com sucesso!');
      } catch (e) {
        alert('Erro ao adicionar jogador: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== IMPORTAR CSV ==========
    function showImportCSVModal(seasonId, league, seasonNumber) {
      const modal = document.createElement('div');
      modal.innerHTML = `
        <div class="modal fade" id="importCSVModal" tabindex="-1">
          <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius)">
              <div class="modal-header" style="border-bottom:1px solid var(--border);padding:16px 20px">
                <h5 class="modal-title" style="font-family:var(--font);font-weight:700;color:var(--text);font-size:15px">
                  <i class="bi bi-file-earmark-arrow-up" style="color:var(--red);margin-right:8px"></i>Importar Jogadores via CSV
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body" style="padding:20px">
                <div style="padding:10px 14px;background:var(--red-soft);border-left:3px solid var(--red);border-radius:var(--radius-sm);font-size:13px;color:var(--text-2);margin-bottom:16px">
                  <strong style="color:var(--text)">Temporada:</strong> ${league} — Temporada ${seasonNumber}
                </div>

                <div class="bc" style="margin-bottom:16px">
                  <div class="bc-head"><div class="bc-title"><i class="bi bi-info-circle"></i>Formato do CSV</div></div>
                  <div class="bc-body">
                    <p style="font-size:12px;color:var(--text-2);margin-bottom:10px">
                      O arquivo deve ter as colunas: <code style="background:var(--panel-3);padding:2px 6px;border-radius:5px;color:var(--green);font-size:11px">nome,posicao,idade,ovr</code>
                    </p>
                    <div style="background:var(--panel-3);border-radius:8px;padding:10px 14px;margin-bottom:12px">
                      <code style="font-size:11px;color:var(--text-2);white-space:pre;display:block">nome,posicao,idade,ovr
LeBron James,SF,39,96
Stephen Curry,PG,35,95</code>
                    </div>
                    <button class="btn-outline-red" style="font-size:12px;padding:6px 14px" onclick="downloadCSVTemplate()">
                      <i class="bi bi-download"></i>Baixar Template
                    </button>
                  </div>
                </div>

                <form id="importCSVForm" onsubmit="submitImportCSV(event, ${seasonId})">
                  <div style="margin-bottom:16px">
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:6px">Selecione o arquivo CSV</label>
                    <input type="file" id="csvFileInput" accept=".csv" required
                      style="width:100%;background:var(--panel-3);border:1px solid var(--border-md);border-radius:8px;padding:8px 12px;color:var(--text);font-family:var(--font);font-size:13px">
                  </div>
                  <button type="submit" class="btn-primary-red" style="width:100%;justify-content:center">
                    <i class="bi bi-upload"></i>Importar Jogadores
                  </button>
                </form>

                <div id="importResult" class="mt-3" style="display:none"></div>
              </div>
            </div>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('importCSVModal')).show();
      document.getElementById('importCSVModal').addEventListener('hidden.bs.modal', () => {
        modal.remove();
      });
    }

    async function submitImportCSV(event, seasonId) {
      event.preventDefault();
      
      const fileInput = document.getElementById('csvFileInput');
      const file = fileInput.files[0];
      
      if (!file) {
        alert('Selecione um arquivo CSV');
        return;
      }
      
      const formData = new FormData();
      formData.append('csv_file', file);
      formData.append('season_id', seasonId);
      
      const resultDiv = document.getElementById('importResult');
      const alertBase = 'padding:12px 14px;border-radius:var(--radius-sm);font-size:13px;font-family:var(--font);border-left:3px solid transparent';
      resultDiv.style.display = 'block';
      resultDiv.innerHTML = `<div style="${alertBase};background:rgba(59,130,246,.1);border-left-color:var(--blue);color:#93c5fd"><i class="bi bi-hourglass-split" style="margin-right:6px"></i>Importando...</div>`;

      try {
        const response = await fetch('/api/import-draft-players.php', {
          method: 'POST',
          body: formData
        });

        const data = await response.json();

        if (response.ok && data.success) {
          resultDiv.innerHTML = `<div style="${alertBase};background:rgba(34,197,94,.1);border-left-color:var(--green);color:#86efac"><i class="bi bi-check-circle" style="margin-right:6px"></i>${data.message}</div>`;

          setTimeout(() => {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('importCSVModal')).hide();
            showDraftManagement(currentSeasonId, currentLeague);
          }, 2000);
        } else {
          let errorMsg = data.error || 'Erro desconhecido';
          if (data.file && data.line) {
            errorMsg += ` (${data.file}:${data.line})`;
          }
          resultDiv.innerHTML = `<div style="${alertBase};background:var(--red-soft);border-left-color:var(--red);color:#fca5a5"><i class="bi bi-x-circle" style="margin-right:6px"></i>Erro: ${errorMsg}</div>`;
        }
      } catch (e) {
        console.error('Erro na importação:', e);
        resultDiv.innerHTML = `<div style="${alertBase};background:var(--red-soft);border-left-color:var(--red);color:#fca5a5"><i class="bi bi-x-circle" style="margin-right:6px"></i>Erro: ${e.message || 'Desconhecido'}</div>`;
      }
    }

    function downloadCSVTemplate() {
      const csv = 'nome,posicao,idade,ovr\\nLeBron James,SF,39,96\\nStephen Curry,PG,35,95\\nKevin Durant,PF,35,94\\n';
      const blob = new Blob([csv], { type: 'text/csv' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'template-draft-players.csv';
      a.click();
      window.URL.revokeObjectURL(url);
    }
    
    async function deleteDraftPlayer(playerId) {
      if (!confirm('Deseja realmente remover este jogador do draft?')) return;
      
      try {
        await api('seasons.php?action=delete_draft_player', {
          method: 'POST',
          body: JSON.stringify({ player_id: playerId })
        });
        
        showDraftManagement(currentSeasonId, currentLeague);
        alert('Jogador removido com sucesso!');
      } catch (e) {
        alert('Erro ao remover jogador: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function draftPlayer(playerId) {
      const teamSelect = document.getElementById(`team-${playerId}`);
      const teamId = teamSelect.value;
      
      if (!teamId) {
        alert('Por favor, selecione um time para draftar este jogador.');
        return;
      }
      
      try {
        await api('seasons.php?action=assign_draft_pick', {
          method: 'POST',
          body: JSON.stringify({
            player_id: playerId,
            team_id: teamId
          })
        });
        
        showDraftManagement(currentSeasonId, currentLeague);
        alert('Jogador draftado com sucesso!');
      } catch (e) {
        alert('Erro ao draftar jogador: ' + (e.error || 'Desconhecido'));
      }
    }
    
    // Modal para draftar no mobile
    async function showDraftModal(playerId, playerName) {
      const teamsData = await api(`admin.php?action=teams&league=${currentLeague}`);
      const teams = teamsData.teams || [];

      const modal = document.createElement('div');
      modal.innerHTML = `
        <div class="modal fade" id="draftModal" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius)">
              <div class="modal-header" style="border-bottom:1px solid var(--border);padding:16px 20px">
                <h5 class="modal-title" style="font-family:var(--font);font-weight:700;color:var(--text);font-size:15px">
                  <i class="bi bi-trophy-fill" style="color:var(--red);margin-right:8px"></i>Draftar ${playerName}
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body" style="padding:20px">
                <label style="display:block;font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:6px">Selecione o Time</label>
                <select id="modalTeamSelect"
                  style="width:100%;background:var(--panel-3);border:1px solid var(--border-md);border-radius:8px;padding:8px 12px;color:var(--text);font-family:var(--font);font-size:13px;outline:none">
                  <option value="">Selecione...</option>
                  ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                </select>
              </div>
              <div class="modal-footer" style="border-top:1px solid var(--border);padding:14px 20px;display:flex;gap:8px;justify-content:flex-end">
                <button type="button" class="btn-back" style="margin-bottom:0" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn-primary-red" onclick="confirmDraft(${playerId})">
                  <i class="bi bi-check-lg"></i>Draftar
                </button>
              </div>
            </div>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('draftModal')).show();
      document.getElementById('draftModal').addEventListener('hidden.bs.modal', () => {
        modal.remove();
      });
    }
    
    async function confirmDraft(playerId) {
      const teamId = document.getElementById('modalTeamSelect').value;
      
      if (!teamId) {
        alert('Por favor, selecione um time.');
        return;
      }
      
      try {
        await api('seasons.php?action=assign_draft_pick', {
          method: 'POST',
          body: JSON.stringify({
            player_id: playerId,
            team_id: teamId
          })
        });
        
        bootstrap.Modal.getOrCreateInstance(document.getElementById('draftModal')).hide();
        showDraftManagement(currentSeasonId, currentLeague);
        alert('Jogador draftado com sucesso!');
      } catch (e) {
        alert('Erro ao draftar jogador: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== CADASTRO DE HISTÓRICO ==========
    // Estado global do sistema de playoffs
    let playoffState = {
      step: 1,
      seasonId: null,
      league: null,
      teams: [],
      standings: { LESTE: [], OESTE: [] },
      bracket: { LESTE: [], OESTE: [] },
      matches: [],
      awards: {}
    };

    async function showHistoryForm(seasonId, league) {
      const container = document.getElementById('mainContainer');
      playoffState.seasonId = seasonId;
      playoffState.league = league;
      playoffState.step = 1;
      
      try {
        // Buscar times da liga
        const teamsData = await api(`admin.php?action=teams&league=${league}`);
        playoffState.teams = teamsData.teams || [];
        
        // Separar por conferência
        const teamsLeste = playoffState.teams.filter(t => t.conference === 'LESTE');
        const teamsOeste = playoffState.teams.filter(t => t.conference === 'OESTE');
        
        // Verificar se há bracket existente
        let existingBracket = null;
        try {
          const bracketData = await fetch(`/api/playoffs.php?action=bracket&season_id=${seasonId}&league=${league}`);
          const bracketResult = await bracketData.json();
          if (bracketResult.success && bracketResult.bracket && bracketResult.bracket.length > 0) {
            existingBracket = bracketResult.bracket;
          }
        } catch (e) {}
        
        if (existingBracket) {
          playoffState.bracket.LESTE = existingBracket.filter(b => b.conference === 'LESTE');
          playoffState.bracket.OESTE = existingBracket.filter(b => b.conference === 'OESTE');

          // Carregar partidas para checar estado do play-in
          try {
            const matchesData = await fetch(`/api/playoffs.php?action=matches&season_id=${seasonId}&league=${league}`);
            const matchesResult = await matchesData.json();
            playoffState.matches = matchesResult.matches || [];
          } catch (e) { playoffState.matches = []; }

          const hasPlayIn = existingBracket.some(b => Number(b.seed) >= 9);
          const piDoneLeste = playoffState.matches.some(m => m.conference === 'LESTE' && m.round === 'play_in' && Number(m.match_number) === 3 && m.winner_id);
          const piDoneOeste = playoffState.matches.some(m => m.conference === 'OESTE' && m.round === 'play_in' && Number(m.match_number) === 3 && m.winner_id);

          if (!hasPlayIn || (piDoneLeste && piDoneOeste)) {
            // Play-In concluído ou formato antigo → ir direto ao bracket
            playoffState.step = 3;
            renderPlayoffStep2();
          } else {
            // Play-In em andamento
            playoffState.step = 2;
            renderPlayInStep();
          }
        } else {
          // Não existe - mostrar seleção de classificação
          renderPlayoffStep1(teamsLeste, teamsOeste);
        }
      } catch (e) {
        alert('Erro ao carregar times: ' + (e.error || 'Desconhecido'));
      }
    }

    // PASSO 1: Definir classificação da temporada regular (1-8 por conferência)
    function renderPlayoffStep1(teamsLeste, teamsOeste) {
      const container = document.getElementById('mainContainer');

      container.innerHTML = `
        <button class="btn-back" onclick="showLeagueManagement('${playoffState.league}')">
          <i class="bi bi-arrow-left"></i>Voltar
        </button>

        <div class="bc" style="margin-bottom:16px">
          <div class="bc-body" style="padding:16px 18px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
              <i class="bi bi-trophy-fill" style="color:var(--red);font-size:18px"></i>
              <div style="font-size:16px;font-weight:800;color:var(--text)">Playoffs — Temporada ${String(currentSeasonData.season_number).padStart(2, '0')}</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <span style="padding:2px 10px;border-radius:999px;background:var(--red-soft);border:1px solid var(--border-red);color:var(--red);font-size:11px;font-weight:700">Passo 1 de 5</span>
              <span style="font-size:12px;color:var(--text-2)">Defina a classificação da temporada regular (1º ao 10º lugar)</span>
            </div>
          </div>
        </div>

        <div style="padding:12px 14px;background:rgba(59,130,246,.08);border-left:3px solid var(--blue);border-radius:var(--radius-sm);font-size:13px;color:#93c5fd;margin-bottom:20px">
          <i class="bi bi-info-circle" style="margin-right:6px"></i>
          <strong>Pontos por Classificação:</strong> 1º +4pts &nbsp;|&nbsp; 2º ao 4º +3pts &nbsp;|&nbsp; 5º ao 6º +2pts &nbsp;|&nbsp; 7º ao 8º +1pt (Play-In) &nbsp;|&nbsp; 9º ao 10º 0pts (Play-In)
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
          <div class="bc">
            <div class="bc-head" style="border-bottom-color:rgba(252,0,37,.25)">
              <div class="bc-title" style="color:var(--red)"><i class="bi bi-geo-alt-fill"></i>Conferência LESTE</div>
            </div>
            <div class="bc-body">${renderStandingsSelectors('LESTE', teamsLeste)}</div>
          </div>
          <div class="bc">
            <div class="bc-head" style="border-bottom-color:rgba(59,130,246,.25)">
              <div class="bc-title" style="color:#93c5fd"><i class="bi bi-geo-alt-fill" style="color:#93c5fd"></i>Conferência OESTE</div>
            </div>
            <div class="bc-body">${renderStandingsSelectors('OESTE', teamsOeste)}</div>
          </div>
        </div>

        <button class="btn-primary-red" onclick="submitStandings()" style="width:100%;justify-content:center;padding:12px">
          <i class="bi bi-arrow-right"></i>Prosseguir para Play-In
        </button>
      `;
    }

    function renderStandingsSelectors(conference, teams) {
      let html = '';
      for (let i = 1; i <= 10; i++) {
        const isPlayIn = i >= 7;
        const pointsLabel = i === 1 ? '+4pts' : (i <= 4 ? '+3pts' : (i <= 6 ? '+2pts' : (i <= 8 ? '+1pt' : '0pts')));
        const badgeBg = i === 1 ? 'rgba(245,158,11,.15)' : (i <= 4 ? 'rgba(34,197,94,.12)' : (i <= 6 ? 'rgba(59,130,246,.12)' : 'var(--panel-3)'));
        const badgeColor = i === 1 ? 'var(--amber)' : (i <= 4 ? '#86efac' : (i <= 6 ? '#93c5fd' : 'var(--text-3)'));
        const ptsBg = isPlayIn ? 'rgba(245,158,11,.10)' : 'var(--red-soft)';
        const ptsColor = isPlayIn ? 'var(--amber)' : 'var(--red)';
        const divider = i === 7 ? `<div style="margin:10px 0 8px;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--amber);display:flex;align-items:center;gap:6px"><i class="bi bi-lightning-charge-fill"></i>Play-In Tournament</div>` : '';

        html += `${divider}
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
            <span style="min-width:28px;text-align:center;padding:2px 6px;border-radius:999px;background:${badgeBg};color:${badgeColor};font-size:10px;font-weight:700">${i}º</span>
            <select id="standing_${conference}_${i}" onchange="updateStandingSelectors('${conference}')"
              style="flex:1;background:var(--panel-3);border:1px solid var(--border-md);border-radius:8px;padding:6px 10px;color:var(--text);font-family:var(--font);font-size:12px;outline:none">
              <option value="">Selecione o ${i}º lugar</option>
              ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
            </select>
            <span style="padding:2px 6px;border-radius:999px;background:${ptsBg};color:${ptsColor};font-size:10px;font-weight:700;white-space:nowrap">${pointsLabel}</span>
          </div>
        `;
      }
      return html;
    }

    function updateStandingSelectors(conference) {
      const selected = [];
      for (let i = 1; i <= 10; i++) {
        const select = document.getElementById(`standing_${conference}_${i}`);
        if (select && select.value) {
          selected.push(select.value);
        }
      }

      // Desabilitar opções já selecionadas em outros selects
      for (let i = 1; i <= 10; i++) {
        const select = document.getElementById(`standing_${conference}_${i}`);
        if (select) {
          const currentValue = select.value;
          Array.from(select.options).forEach(opt => {
            if (opt.value && opt.value !== currentValue) {
              opt.disabled = selected.includes(opt.value);
            }
          });
        }
      }
    }

    async function submitStandings() {
      // Validar seleções
      const standings = { LESTE: [], OESTE: [] };

      for (const conf of ['LESTE', 'OESTE']) {
        for (let i = 1; i <= 10; i++) {
          const select = document.getElementById(`standing_${conf}_${i}`);
          if (!select || !select.value) {
            alert(`Por favor, selecione todos os 10 times da conferência ${conf}`);
            return;
          }
          standings[conf].push({ team_id: parseInt(select.value), seed: i });
        }
      }

      playoffState.standings = standings;

      try {
        const response = await fetch('/api/playoffs.php?action=setup_bracket', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            season_id: playoffState.seasonId,
            league: playoffState.league,
            standings: standings
          })
        });
        const result = await response.json();
        if (!result.success) throw new Error(result.error);

        // Buscar bracket criado
        const bracketData = await fetch(`/api/playoffs.php?action=bracket&season_id=${playoffState.seasonId}&league=${playoffState.league}`);
        const bracketResult = await bracketData.json();
        playoffState.bracket.LESTE = bracketResult.bracket.filter(b => b.conference === 'LESTE');
        playoffState.bracket.OESTE = bracketResult.bracket.filter(b => b.conference === 'OESTE');

        // Buscar partidas (jogos de play-in criados)
        const matchesData = await fetch(`/api/playoffs.php?action=matches&season_id=${playoffState.seasonId}&league=${playoffState.league}`);
        const matchesResult = await matchesData.json();
        playoffState.matches = matchesResult.matches || [];

        playoffState.step = 2;
        renderPlayInStep();
      } catch (e) {
        alert('Erro ao criar Play-In: ' + (e.message || 'Desconhecido'));
      }
    }

    // PASSO 2: Play-In Tournament
    function renderPlayInStep() {
      const container = document.getElementById('mainContainer');
      container.innerHTML = `
        <button class="btn-back" onclick="showLeagueManagement('${playoffState.league}')">
          <i class="bi bi-arrow-left"></i>Voltar
        </button>

        <div class="bc" style="margin-bottom:16px">
          <div class="bc-body" style="padding:16px 18px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
              <i class="bi bi-lightning-charge-fill" style="color:var(--amber);font-size:18px"></i>
              <div style="font-size:16px;font-weight:800;color:var(--text)">Play-In Tournament — Temporada ${String(currentSeasonData.season_number).padStart(2,'0')}</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <span style="padding:2px 10px;border-radius:999px;background:rgba(245,158,11,.15);border:1px solid var(--amber);color:var(--amber);font-size:11px;font-weight:700">Passo 2 de 5</span>
              <span style="font-size:12px;color:var(--text-2)">Registre os resultados do Play-In para definir os seeds 7 e 8</span>
            </div>
          </div>
        </div>

        <div style="padding:12px 14px;background:rgba(245,158,11,.08);border-left:3px solid var(--amber);border-radius:var(--radius-sm);font-size:12px;color:#fcd34d;margin-bottom:20px">
          <i class="bi bi-info-circle" style="margin-right:6px"></i>
          <strong>Jogo 1:</strong> 7º vs 8º → vencedor = 7º seed &nbsp;|&nbsp;
          <strong>Jogo 2:</strong> 9º vs 10º → vencedor avança &nbsp;|&nbsp;
          <strong>Jogo 3:</strong> Perdedor J1 vs Vencedor J2 → vencedor = 8º seed
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
          <div class="bc">
            <div class="bc-head" style="border-bottom-color:rgba(252,0,37,.25)">
              <div class="bc-title" style="color:var(--red)"><i class="bi bi-lightning-charge-fill"></i>Play-In LESTE</div>
            </div>
            <div class="bc-body">${renderPlayInConference('LESTE')}</div>
          </div>
          <div class="bc">
            <div class="bc-head" style="border-bottom-color:rgba(59,130,246,.25)">
              <div class="bc-title" style="color:#93c5fd"><i class="bi bi-lightning-charge-fill" style="color:#93c5fd"></i>Play-In OESTE</div>
            </div>
            <div class="bc-body">${renderPlayInConference('OESTE')}</div>
          </div>
        </div>

        <button class="btn-primary-red" id="btnPlayInNext" onclick="proceedFromPlayIn()"
          style="width:100%;justify-content:center;padding:12px;${isPlayInComplete() ? '' : 'opacity:.5;cursor:not-allowed;'}"
          ${isPlayInComplete() ? '' : 'disabled'}>
          <i class="bi bi-arrow-right"></i>Prosseguir para o Bracket
        </button>
      `;
    }

    function getPlayInTeamName(conference, seed) {
      const entry = playoffState.bracket[conference]?.find(b => Number(b.seed) === seed);
      return entry ? getTeamInfo(entry.team_id) : 'TBD';
    }

    function getPlayInMatch(conference, matchNum) {
      return playoffState.matches.find(m => m.conference === conference && m.round === 'play_in' && Number(m.match_number) === matchNum);
    }

    function isPlayInComplete() {
      const lM3 = getPlayInMatch('LESTE', 3);
      const oM3 = getPlayInMatch('OESTE', 3);
      return !!(lM3?.winner_id && oM3?.winner_id);
    }

    function renderPlayInConference(conference) {
      const m1 = getPlayInMatch(conference, 1);
      const m2 = getPlayInMatch(conference, 2);
      const m3 = getPlayInMatch(conference, 3);

      const seed7Name = getPlayInTeamName(conference, 7);
      const seed8Name = getPlayInTeamName(conference, 8);
      const seed9Name = getPlayInTeamName(conference, 9);
      const seed10Name = getPlayInTeamName(conference, 10);

      const m3t1Name = m3?.team1_id ? getTeamInfo(m3.team1_id) : 'Perdedor J1';
      const m3t2Name = m3?.team2_id ? getTeamInfo(m3.team2_id) : 'Vencedor J2';
      const m3Ready = !!(m3?.team1_id && m3?.team2_id);

      const renderPIMatchup = (label, outcome, t1Id, t1Name, t2Id, t2Name, match, matchNum, ready = true) => {
        const w = match?.winner_id;
        const t1Win = w && w == t1Id;
        const t2Win = w && w == t2Id;
        const canClick = ready && t1Id && t2Id;
        const btnBase = 'flex:1;padding:7px 10px;border-radius:8px;font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;border:1px solid;text-align:center';
        const t1Bg = t1Win ? 'rgba(34,197,94,.15)' : 'var(--panel-3)';
        const t1Bd = t1Win ? 'rgba(34,197,94,.4)' : 'var(--border)';
        const t1Cl = t1Win ? '#86efac' : 'var(--text-2)';
        const t2Bg = t2Win ? 'rgba(34,197,94,.15)' : 'var(--panel-3)';
        const t2Bd = t2Win ? 'rgba(34,197,94,.4)' : 'var(--border)';
        const t2Cl = t2Win ? '#86efac' : 'var(--text-2)';
        return `
          <div style="margin-bottom:12px">
            <div style="font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--amber);margin-bottom:6px">${label} <span style="color:var(--text-3);font-weight:400">— ${outcome}</span></div>
            <div style="display:flex;align-items:center;gap:6px;background:var(--panel-3);border-radius:10px;padding:8px">
              <button style="${btnBase} background:${t1Bg};border-color:${t1Bd};color:${t1Cl};${!canClick?'opacity:.5;cursor:not-allowed':''}"
                onclick="${canClick ? `selectPlayInWinner('${conference}',${matchNum},${t1Id},${t1Id},${t2Id||'null'})` : ''}"
                ${!canClick?'disabled':''}>${t1Name}</button>
              <span style="font-size:10px;font-weight:700;color:var(--text-3)">vs</span>
              <button style="${btnBase} background:${t2Bg};border-color:${t2Bd};color:${t2Cl};${!canClick?'opacity:.5;cursor:not-allowed':''}"
                onclick="${canClick ? `selectPlayInWinner('${conference}',${matchNum},${t2Id},${t1Id||'null'},${t2Id})` : ''}"
                ${!canClick?'disabled':''}>${t2Name}</button>
            </div>
            ${w ? `<div style="font-size:11px;color:#86efac;margin-top:4px;padding:0 4px"><i class="bi bi-check-circle-fill" style="margin-right:4px"></i>Vencedor: <strong>${getTeamInfo(w)}</strong></div>` : ''}
          </div>`;
      };

      return `
        ${renderPIMatchup('Jogo 1', '7º vs 8º — Vencedor → 7º seed', m1?.team1_id, seed7Name, m1?.team2_id, seed8Name, m1, 1)}
        ${renderPIMatchup('Jogo 2', '9º vs 10º — Perdedor eliminado', m2?.team1_id, seed9Name, m2?.team2_id, seed10Name, m2, 2)}
        ${renderPIMatchup('Jogo 3', 'Perdedor J1 vs Vencedor J2 → Vencedor = 8º seed', m3?.team1_id, m3t1Name, m3?.team2_id, m3t2Name, m3, 3, m3Ready)}
        ${m1?.winner_id && m2?.winner_id && m3?.winner_id ? `
          <div style="margin-top:8px;padding:10px 12px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:8px;font-size:12px">
            <div style="color:#86efac;font-weight:700;margin-bottom:4px"><i class="bi bi-check-circle-fill" style="margin-right:5px"></i>Play-In ${conference} concluído!</div>
            <div style="color:var(--text-2)">7º seed: <strong style="color:var(--text)">${getTeamInfo(m1.winner_id)}</strong> &nbsp;|&nbsp; 8º seed: <strong style="color:var(--text)">${getTeamInfo(m3.winner_id)}</strong></div>
          </div>` : ''}
      `;
    }

    async function selectPlayInWinner(conference, matchNum, winnerId, team1Id, team2Id) {
      if (!winnerId) return;
      try {
        const response = await fetch('/api/playoffs.php?action=record_result', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            season_id: playoffState.seasonId,
            league: playoffState.league,
            conference,
            round: 'play_in',
            match_number: matchNum,
            team1_id: team1Id,
            team2_id: team2Id,
            winner_id: winnerId
          })
        });
        const result = await response.json();
        if (!result.success) throw new Error(result.error);

        // Recarregar partidas e re-renderizar
        const matchesData = await fetch(`/api/playoffs.php?action=matches&season_id=${playoffState.seasonId}&league=${playoffState.league}`);
        const matchesResult = await matchesData.json();
        playoffState.matches = matchesResult.matches || [];
        renderPlayInStep();
      } catch (e) {
        alert('Erro ao registrar resultado: ' + (e.message || 'Desconhecido'));
      }
    }

    async function proceedFromPlayIn() {
      if (!isPlayInComplete()) return;

      // Buscar bracket atualizado (pode ter mudado após play-in)
      const bracketData = await fetch(`/api/playoffs.php?action=bracket&season_id=${playoffState.seasonId}&league=${playoffState.league}`);
      const bracketResult = await bracketData.json();
      playoffState.bracket.LESTE = bracketResult.bracket.filter(b => b.conference === 'LESTE');
      playoffState.bracket.OESTE = bracketResult.bracket.filter(b => b.conference === 'OESTE');

      playoffState.step = 3;
      renderPlayoffStep2();
    }

    // PASSO 3: Bracket de Playoffs (selecionar vencedores)
    async function renderPlayoffStep2() {
      const container = document.getElementById('mainContainer');
      
      // Buscar partidas existentes
      try {
        const matchesData = await fetch(`/api/playoffs.php?action=matches&season_id=${playoffState.seasonId}&league=${playoffState.league}`);
        const matchesResult = await matchesData.json();
        playoffState.matches = matchesResult.matches || [];
      } catch (e) {
        playoffState.matches = [];
      }
      
      container.innerHTML = `
        <button class="btn-back" onclick="showLeagueManagement('${playoffState.league}')">
          <i class="bi bi-arrow-left"></i>Voltar
        </button>

        <div class="bc" style="margin-bottom:16px">
          <div class="bc-body" style="padding:16px 18px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
              <i class="bi bi-diagram-3-fill" style="color:var(--red);font-size:18px"></i>
              <div style="font-size:16px;font-weight:800;color:var(--text)">Bracket de Playoffs — Temporada ${String(currentSeasonData.season_number).padStart(2, '0')}</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <span style="padding:2px 10px;border-radius:999px;background:var(--red-soft);border:1px solid var(--border-red);color:var(--red);font-size:11px;font-weight:700">Passo 3 de 5</span>
              <span style="font-size:12px;color:var(--text-2)">Clique em cada confronto para selecionar o vencedor</span>
            </div>
          </div>
        </div>

        <div style="padding:12px 14px;background:rgba(59,130,246,.08);border-left:3px solid var(--blue);border-radius:var(--radius-sm);font-size:13px;color:#93c5fd;margin-bottom:20px">
          <i class="bi bi-info-circle" style="margin-right:6px"></i>
          <strong>Pontos Playoffs:</strong> 1ª Rodada +1pt &nbsp;|&nbsp; 2ª Rodada +2pts &nbsp;|&nbsp; Final Conferência +3pts &nbsp;|&nbsp; Vice +2pts &nbsp;|&nbsp; Campeão +5pts
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
          <div class="bc">
            <div class="bc-head" style="border-bottom-color:rgba(252,0,37,.25)">
              <div class="bc-title" style="color:var(--red)"><i class="bi bi-trophy-fill"></i>Playoffs LESTE</div>
            </div>
            <div class="bc-body">${renderBracket('LESTE')}</div>
          </div>
          <div class="bc">
            <div class="bc-head" style="border-bottom-color:rgba(59,130,246,.25)">
              <div class="bc-title" style="color:#93c5fd"><i class="bi bi-trophy-fill" style="color:#93c5fd"></i>Playoffs OESTE</div>
            </div>
            <div class="bc-body">${renderBracket('OESTE')}</div>
          </div>
        </div>

        <div class="bc" style="margin-bottom:20px">
          <div class="bc-head" style="border-bottom-color:rgba(245,158,11,.25)">
            <div class="bc-title" style="color:var(--amber)"><i class="bi bi-trophy-fill" style="color:var(--amber)"></i>FINAIS DA LIGA</div>
          </div>
          <div class="bc-body" style="text-align:center">
            ${renderFinals()}
          </div>
        </div>

        <button class="btn-primary-red" onclick="goToStep3()" id="btnStep3" disabled style="width:100%;justify-content:center;padding:12px">
          <i class="bi bi-arrow-right"></i>Prosseguir para Prêmios Individuais
        </button>
      `;
      
      checkFinalsComplete();
    }

    function renderBracket(conference) {
      const bracket = playoffState.bracket[conference];
      if (!bracket || bracket.length === 0) {
        return '<p class="text-muted">Bracket não configurado</p>';
      }

      // Organizar por seed
      const teamsBySeed = {};
      bracket.forEach(b => { teamsBySeed[b.seed] = b; });

      // Sobrescrever seeds 7 e 8 com os vencedores do Play-In
      const piM1 = getPlayInMatch(conference, 1);
      const piM3 = getPlayInMatch(conference, 3);
      if (piM1?.winner_id) teamsBySeed[7] = { team_id: piM1.winner_id, seed: 7 };
      if (piM3?.winner_id) teamsBySeed[8] = { team_id: piM3.winner_id, seed: 8 };
      
      // Formato: 1v8, 4v5, 3v6, 2v7
      const firstRoundMatchups = [
        { match: 1, seeds: [1, 8] },
        { match: 2, seeds: [4, 5] },
        { match: 3, seeds: [3, 6] },
        { match: 4, seeds: [2, 7] }
      ];
      
      let html = `<div class="bracket-container">`;
      
      // PRIMEIRA RODADA
      html += `<div class="mb-4"><h6 class="text-warning mb-3">1ª Rodada (+1pt)</h6>`;
      firstRoundMatchups.forEach(m => {
        const team1 = teamsBySeed[m.seeds[0]];
        const team2 = teamsBySeed[m.seeds[1]];
        const match = getMatch(conference, 'first_round', m.match);
        html += renderMatchup(conference, 'first_round', m.match, team1, team2, match?.winner_id);
      });
      html += `</div>`;
      
      // SEMIFINAIS
      html += `<div class="mb-4"><h6 class="text-info mb-3">Semifinais (+2pts)</h6>`;
      html += renderMatchup(conference, 'semifinals', 1, null, null, getMatch(conference, 'semifinals', 1)?.winner_id, 'Vencedor 1v8', 'Vencedor 4v5');
      html += renderMatchup(conference, 'semifinals', 2, null, null, getMatch(conference, 'semifinals', 2)?.winner_id, 'Vencedor 3v6', 'Vencedor 2v7');
      html += `</div>`;
      
      // FINAL DA CONFERÊNCIA
      html += `<div class="mb-4"><h6 class="text-success mb-3">Final da Conferência (+3pts)</h6>`;
      html += renderMatchup(conference, 'conference_finals', 1, null, null, getMatch(conference, 'conference_finals', 1)?.winner_id, 'Vencedor Semi 1', 'Vencedor Semi 2');
      html += `</div>`;
      
      html += `</div>`;
      return html;
    }

    function getMatch(conference, round, matchNumber) {
      return playoffState.matches.find(m => 
        m.conference === conference && 
        m.round === round && 
        m.match_number === matchNumber
      );
    }

    function getTeamInfo(teamId) {
      const team = playoffState.teams.find(t => t.id == teamId);
      return team ? `${team.city} ${team.name}` : 'TBD';
    }

    function renderMatchup(conference, round, matchNumber, team1, team2, winnerId, placeholder1 = null, placeholder2 = null) {
      const t1Name = team1 ? `(${team1.seed}) ${getTeamInfo(team1.team_id)}` : placeholder1;
      const t2Name = team2 ? `(${team2.seed}) ${getTeamInfo(team2.team_id)}` : placeholder2;
      const t1Id = team1 ? team1.team_id : null;
      const t2Id = team2 ? team2.team_id : null;
      
      // Para rodadas avançadas, buscar vencedores anteriores
      let actualT1Id = t1Id, actualT2Id = t2Id;
      let actualT1Name = t1Name, actualT2Name = t2Name;
      
      if (round === 'semifinals') {
        if (matchNumber === 1) {
          const prev1 = getMatch(conference, 'first_round', 1);
          const prev2 = getMatch(conference, 'first_round', 2);
          if (prev1?.winner_id) { actualT1Id = prev1.winner_id; actualT1Name = getTeamInfo(prev1.winner_id); }
          if (prev2?.winner_id) { actualT2Id = prev2.winner_id; actualT2Name = getTeamInfo(prev2.winner_id); }
        } else {
          const prev3 = getMatch(conference, 'first_round', 3);
          const prev4 = getMatch(conference, 'first_round', 4);
          if (prev3?.winner_id) { actualT1Id = prev3.winner_id; actualT1Name = getTeamInfo(prev3.winner_id); }
          if (prev4?.winner_id) { actualT2Id = prev4.winner_id; actualT2Name = getTeamInfo(prev4.winner_id); }
        }
      } else if (round === 'conference_finals') {
        const semi1 = getMatch(conference, 'semifinals', 1);
        const semi2 = getMatch(conference, 'semifinals', 2);
        if (semi1?.winner_id) { actualT1Id = semi1.winner_id; actualT1Name = getTeamInfo(semi1.winner_id); }
        if (semi2?.winner_id) { actualT2Id = semi2.winner_id; actualT2Name = getTeamInfo(semi2.winner_id); }
      }
      
      const canSelect = actualT1Id && actualT2Id;
      const t1Win = winnerId == actualT1Id;
      const t2Win = winnerId == actualT2Id;
      const t1Bg = t1Win ? 'rgba(34,197,94,.15)' : 'var(--panel-3)';
      const t1Border = t1Win ? 'rgba(34,197,94,.4)' : 'var(--border)';
      const t1Color = t1Win ? '#86efac' : 'var(--text-2)';
      const t2Bg = t2Win ? 'rgba(34,197,94,.15)' : 'var(--panel-3)';
      const t2Border = t2Win ? 'rgba(34,197,94,.4)' : 'var(--border)';
      const t2Color = t2Win ? '#86efac' : 'var(--text-2)';
      const btnBase = 'flex:1;padding:6px 10px;border-radius:8px;font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s ease;border:1px solid';
      const disabledExtra = !canSelect ? 'opacity:.5;cursor:not-allowed;' : '';

      return `
        <div style="background:var(--panel-3);border-radius:var(--radius-sm);padding:8px;margin-bottom:6px">
          <div style="display:flex;align-items:center;gap:6px">
            <button style="${btnBase} ${t1Border};background:${t1Bg};color:${t1Color};${disabledExtra}"
                    onclick="${canSelect ? `selectWinner('${conference}', '${round}', ${matchNumber}, ${actualT1Id})` : ''}"
                    ${!canSelect ? 'disabled' : ''}>
              ${actualT1Name || 'TBD'}
            </button>
            <span style="font-size:10px;font-weight:700;color:var(--text-3);flex-shrink:0">vs</span>
            <button style="${btnBase} ${t2Border};background:${t2Bg};color:${t2Color};${disabledExtra}"
                    onclick="${canSelect ? `selectWinner('${conference}', '${round}', ${matchNumber}, ${actualT2Id})` : ''}"
                    ${!canSelect ? 'disabled' : ''}>
              ${actualT2Name || 'TBD'}
            </button>
          </div>
        </div>
      `;
    }

    function renderFinals() {
      const lesteChamp = getMatch('LESTE', 'conference_finals', 1);
      const oesteChamp = getMatch('OESTE', 'conference_finals', 1);
      const finalsMatch = getMatch('FINALS', 'finals', 1);

      const lesteTeam = lesteChamp?.winner_id ? getTeamInfo(lesteChamp.winner_id) : 'Campeão LESTE';
      const oesteTeam = oesteChamp?.winner_id ? getTeamInfo(oesteChamp.winner_id) : 'Campeão OESTE';
      const canSelect = lesteChamp?.winner_id && oesteChamp?.winner_id;

      const lesteWin = finalsMatch?.winner_id == lesteChamp?.winner_id && finalsMatch?.winner_id;
      const oesteWin = finalsMatch?.winner_id == oesteChamp?.winner_id && finalsMatch?.winner_id;
      const btnBase = 'padding:12px 20px;border-radius:10px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:all .15s ease;border:1px solid;display:flex;align-items:center;gap:8px';
      const disabledExtra = !canSelect ? 'opacity:.5;cursor:not-allowed;' : '';

      const lesteBg = lesteWin ? 'rgba(245,158,11,.2)' : 'var(--panel-3)';
      const lesteBorder = lesteWin ? 'var(--amber)' : 'var(--border)';
      const lesteColor = lesteWin ? 'var(--amber)' : 'var(--text-2)';
      const oesteBg = oesteWin ? 'rgba(245,158,11,.2)' : 'var(--panel-3)';
      const oesteBorder = oesteWin ? 'var(--amber)' : 'var(--border)';
      const oesteColor = oesteWin ? 'var(--amber)' : 'var(--text-2)';

      return `
        <div style="padding:16px 0">
          <div style="display:flex;justify-content:center;align-items:center;gap:16px;flex-wrap:wrap">
            <button style="${btnBase} background:${lesteBg};border-color:${lesteBorder};color:${lesteColor};${disabledExtra}"
                    onclick="${canSelect ? `selectFinalWinner(${lesteChamp?.winner_id})` : ''}"
                    ${!canSelect ? 'disabled' : ''}>
              <i class="bi bi-trophy-fill"></i>${lesteTeam}
            </button>
            <span style="font-size:16px;font-weight:900;color:var(--amber)">VS</span>
            <button style="${btnBase} background:${oesteBg};border-color:${oesteBorder};color:${oesteColor};${disabledExtra}"
                    onclick="${canSelect ? `selectFinalWinner(${oesteChamp?.winner_id})` : ''}"
                    ${!canSelect ? 'disabled' : ''}>
              ${oesteTeam}<i class="bi bi-trophy-fill"></i>
            </button>
          </div>
          ${finalsMatch?.winner_id ? `
            <div style="margin-top:16px;text-align:center">
              <span style="display:inline-flex;align-items:center;gap:8px;padding:8px 20px;border-radius:999px;background:rgba(245,158,11,.15);border:1px solid var(--amber);color:var(--amber);font-size:14px;font-weight:800">
                <i class="bi bi-trophy-fill"></i>CAMPEÃO: ${getTeamInfo(finalsMatch.winner_id)}
              </span>
            </div>
          ` : ''}
        </div>
      `;
    }

    async function selectWinner(conference, round, matchNumber, winnerId) {
      if (!winnerId) return;
      
      try {
        // Determinar os times do confronto
        let team1Id, team2Id;
        const bracket = playoffState.bracket[conference];
        
        if (round === 'first_round') {
          const matchups = [[1,8], [4,5], [3,6], [2,7]];
          const seeds = matchups[matchNumber - 1];
          const getSeedTeamId = (seed) => {
            if (seed === 7) return getPlayInMatch(conference, 1)?.winner_id || bracket.find(b => b.seed == 7)?.team_id;
            if (seed === 8) return getPlayInMatch(conference, 3)?.winner_id || bracket.find(b => b.seed == 8)?.team_id;
            return bracket.find(b => b.seed == seed)?.team_id;
          };
          team1Id = getSeedTeamId(seeds[0]);
          team2Id = getSeedTeamId(seeds[1]);
        } else if (round === 'semifinals') {
          if (matchNumber === 1) {
            team1Id = getMatch(conference, 'first_round', 1)?.winner_id;
            team2Id = getMatch(conference, 'first_round', 2)?.winner_id;
          } else {
            team1Id = getMatch(conference, 'first_round', 3)?.winner_id;
            team2Id = getMatch(conference, 'first_round', 4)?.winner_id;
          }
        } else if (round === 'conference_finals') {
          team1Id = getMatch(conference, 'semifinals', 1)?.winner_id;
          team2Id = getMatch(conference, 'semifinals', 2)?.winner_id;
        }
        
        const response = await fetch('/api/playoffs.php?action=record_result', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            season_id: playoffState.seasonId,
            league: playoffState.league,
            conference: conference,
            round: round,
            match_number: matchNumber,
            team1_id: team1Id,
            team2_id: team2Id,
            winner_id: winnerId
          })
        });
        const result = await response.json();
        
        if (!result.success) {
          throw new Error(result.error);
        }
        
        // Recarregar a tela
        renderPlayoffStep2();
      } catch (e) {
        alert('Erro ao registrar resultado: ' + (e.message || 'Desconhecido'));
      }
    }

    async function selectFinalWinner(winnerId) {
      if (!winnerId) return;
      
      const lesteChamp = getMatch('LESTE', 'conference_finals', 1);
      const oesteChamp = getMatch('OESTE', 'conference_finals', 1);
      
      try {
        const response = await fetch('/api/playoffs.php?action=record_result', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            season_id: playoffState.seasonId,
            league: playoffState.league,
            conference: 'FINALS',
            round: 'finals',
            match_number: 1,
            team1_id: lesteChamp.winner_id,
            team2_id: oesteChamp.winner_id,
            winner_id: winnerId
          })
        });
        const result = await response.json();
        
        if (!result.success) {
          throw new Error(result.error);
        }
        
        renderPlayoffStep2();
      } catch (e) {
        alert('Erro ao registrar campeão: ' + (e.message || 'Desconhecido'));
      }
    }

    function checkFinalsComplete() {
      const finalsMatch = getMatch('FINALS', 'finals', 1);
      const btn = document.getElementById('btnStep3');
      if (btn) {
        btn.disabled = !finalsMatch?.winner_id;
      }
    }

    function goToStep3() {
      playoffState.step = 4;
      renderPlayoffStep3();
    }

    // PASSO 4: Prêmios Individuais
    function renderPlayoffStep3() {
      const container = document.getElementById('mainContainer');
      const teams = playoffState.teams;

      container.innerHTML = `
        <button class="btn-back" onclick="renderPlayoffStep2()">
          <i class="bi bi-arrow-left"></i>Voltar ao Bracket
        </button>

        <div class="bc" style="margin-bottom:16px">
          <div class="bc-body" style="padding:16px 18px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
              <i class="bi bi-award-fill" style="color:var(--amber);font-size:18px"></i>
              <div style="font-size:16px;font-weight:800;color:var(--text)">Prêmios Individuais — Temporada ${String(currentSeasonData.season_number).padStart(2,'0')}</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <span style="padding:2px 10px;border-radius:999px;background:var(--red-soft);border:1px solid var(--border-red);color:var(--red);font-size:11px;font-weight:700">Passo 4 de 5</span>
              <span style="font-size:12px;color:var(--text-2)">Registre os prêmios individuais (+1pt para o time de cada vencedor)</span>
            </div>
          </div>
        </div>

        <div class="bc" style="margin-bottom:20px">
          <div class="bc-body">
            <form id="awardsForm">
              ${[
                { icon:'bi-star-fill', color:'var(--amber)', name:'mvp', label:'MVP' },
                { icon:'bi-shield-fill', color:'#38bdf8', name:'dpoy', label:'DPOY' },
                { icon:'bi-graph-up-arrow', color:'#4ade80', name:'mip', label:'MIP' },
                { icon:'bi-person-plus-fill', color:'#818cf8', name:'sixth_man', label:'6º Homem' },
                { icon:'bi-star-half', color:'var(--amber)', name:'roy', label:'ROY' }
              ].map(award => `
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
                  <div>
                    <label style="font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-2);display:block;margin-bottom:5px">
                      <i class="bi ${award.icon}" style="color:${award.color};margin-right:5px"></i>${award.label} <span style="color:var(--text-3);font-weight:400">(+1pt)</span>
                    </label>
                    <input type="text" class="form-control" name="${award.name}_player" placeholder="Nome do jogador">
                  </div>
                  <div>
                    <label style="font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-2);display:block;margin-bottom:5px">Time do ${award.label}</label>
                    <select class="form-select" name="${award.name}_team_id">
                      <option value="">Selecione o time</option>
                      ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                    </select>
                  </div>
                </div>
              `).join('')}
            </form>
          </div>
        </div>

        <button class="btn-primary-red" onclick="goToStep4()" style="width:100%;justify-content:center;padding:12px">
          <i class="bi bi-arrow-right"></i>Revisar e Finalizar
        </button>
      `;
    }

    function goToStep4() {
      const form = document.getElementById('awardsForm');
      const formData = new FormData(form);

      playoffState.awards = {
        mvp_player: formData.get('mvp_player') || null,
        mvp_team_id: formData.get('mvp_team_id') || null,
        dpoy_player: formData.get('dpoy_player') || null,
        dpoy_team_id: formData.get('dpoy_team_id') || null,
        mip_player: formData.get('mip_player') || null,
        mip_team_id: formData.get('mip_team_id') || null,
        sixth_man_player: formData.get('sixth_man_player') || null,
        sixth_man_team_id: formData.get('sixth_man_team_id') || null,
        roy_player: formData.get('roy_player') || null,
        roy_team_id: formData.get('roy_team_id') || null
      };

      playoffState.step = 5;
      renderPlayoffStep4();
    }

    // PASSO 4: Revisão e Finalização
    async function renderPlayoffStep4() {
      const container = document.getElementById('mainContainer');
      const finalsMatch = getMatch('FINALS', 'finals', 1);
      const lesteChamp = getMatch('LESTE', 'conference_finals', 1);
      const oesteChamp = getMatch('OESTE', 'conference_finals', 1);
      
      const champion = finalsMatch?.winner_id;
      const runnerUp = champion == lesteChamp?.winner_id ? oesteChamp?.winner_id : lesteChamp?.winner_id;
      
      container.innerHTML = `
        <button class="btn-back" onclick="renderPlayoffStep3()">
          <i class="bi bi-arrow-left"></i>Voltar aos Prêmios
        </button>

        <div class="bc" style="margin-bottom:16px">
          <div class="bc-body" style="padding:16px 18px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
              <i class="bi bi-check-circle-fill" style="color:#4ade80;font-size:18px"></i>
              <div style="font-size:16px;font-weight:800;color:var(--text)">Revisão Final — Temporada ${String(currentSeasonData.season_number).padStart(2,'0')}</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <span style="padding:2px 10px;border-radius:999px;background:var(--red-soft);border:1px solid var(--border-red);color:var(--red);font-size:11px;font-weight:700">Passo 5 de 5</span>
              <span style="font-size:12px;color:var(--text-2)">Revise os dados e clique em Finalizar para calcular todos os pontos</span>
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
          <div class="bc">
            <div class="bc-head" style="border-bottom-color:rgba(245,158,11,.25)">
              <div class="bc-title" style="color:var(--amber)"><i class="bi bi-trophy-fill"></i>Resultado dos Playoffs</div>
            </div>
            <div class="bc-body" style="font-size:13px;display:flex;flex-direction:column;gap:8px">
              <div><span style="color:var(--text-2)">Campeão (+5pts):</span> <strong>${champion ? getTeamInfo(champion) : '—'}</strong></div>
              <div><span style="color:var(--text-2)">Vice (+2pts):</span> <strong>${runnerUp ? getTeamInfo(runnerUp) : '—'}</strong></div>
              <div><span style="color:var(--text-2)">Final LESTE (+3pts):</span> <strong>${lesteChamp?.winner_id ? getTeamInfo(lesteChamp.winner_id) : '—'}</strong></div>
              <div><span style="color:var(--text-2)">Final OESTE (+3pts):</span> <strong>${oesteChamp?.winner_id ? getTeamInfo(oesteChamp.winner_id) : '—'}</strong></div>
            </div>
          </div>
          <div class="bc">
            <div class="bc-head" style="border-bottom-color:rgba(59,130,246,.25)">
              <div class="bc-title" style="color:#93c5fd"><i class="bi bi-award-fill"></i>Prêmios Individuais</div>
            </div>
            <div class="bc-body" style="font-size:13px;display:flex;flex-direction:column;gap:8px">
              ${[['mvp','MVP'],['dpoy','DPOY'],['mip','MIP'],['sixth_man','6º Homem'],['roy','ROY']].map(([k,l]) =>
                playoffState.awards[`${k}_player`] ? `<div><span style="color:var(--text-2)">${l}:</span> <strong>${playoffState.awards[`${k}_player`]}</strong></div>` : ''
              ).join('') || `<div style="color:var(--text-3)">Nenhum prêmio registrado</div>`}
            </div>
          </div>
        </div>

        <div class="bc" style="margin-bottom:20px">
          <div class="bc-head"><div class="bc-title"><i class="bi bi-calculator-fill"></i>Sistema de Pontuação desta Temporada</div></div>
          <div class="bc-body" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;font-size:12px">
            <div>
              <div style="font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--amber);margin-bottom:8px">Playoffs</div>
              ${[['Campeão','+5pts'],['Vice-Campeão','+2pts'],['Final de Conf.','+3pts'],['Semifinais','+2pts'],['1ª Rodada','+1pt']].map(([l,p]) => `<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid var(--border)"><span style="color:var(--text-2)">${l}</span><strong style="color:var(--amber)">${p}</strong></div>`).join('')}
            </div>
            <div>
              <div style="font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:#93c5fd;margin-bottom:8px">Classificação</div>
              ${[['1º lugar','+4pts'],['2º a 4º','+3pts'],['5º a 6º','+2pts'],['7º a 8º (Play-In)','+1pt'],['9º a 10º (Play-In)','0pts']].map(([l,p]) => `<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid var(--border)"><span style="color:var(--text-2)">${l}</span><strong style="color:#93c5fd">${p}</strong></div>`).join('')}
            </div>
            <div>
              <div style="font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:#4ade80;margin-bottom:8px">Prêmios</div>
              ${['MVP','DPOY','MIP','6º Homem','ROY'].map(l => `<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid var(--border)"><span style="color:var(--text-2)">${l}</span><strong style="color:#4ade80">+1pt</strong></div>`).join('')}
            </div>
          </div>
        </div>

        <button class="btn-primary-red" onclick="finalizePlayoffs()" id="btnFinalize" style="width:100%;justify-content:center;padding:12px;background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.4);color:#4ade80">
          <i class="bi bi-check-circle-fill"></i>Finalizar e Calcular Pontos
        </button>
      `;
    }

    async function finalizePlayoffs() {
      const btn = document.getElementById('btnFinalize');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="margin-right:8px"></span>Processando...';
      
      try {
        // 1. Salvar prêmios individuais
        await fetch('/api/playoffs.php?action=save_awards', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            season_id: playoffState.seasonId,
            league: playoffState.league,
            awards: playoffState.awards
          })
        });
        
        // 2. Finalizar e calcular pontos
        const response = await fetch('/api/playoffs.php?action=finalize', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            season_id: playoffState.seasonId,
            league: playoffState.league
          })
        });
        const result = await response.json();
        
        if (!result.success) {
          throw new Error(result.error);
        }
        
        alert('Playoffs finalizados com sucesso! Todos os pontos foram calculados e aplicados.');
        showLeagueManagement(playoffState.league);
      } catch (e) {
        alert('Erro ao finalizar playoffs: ' + (e.message || 'Desconhecido'));
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle-fill" style="margin-right:6px"></i>Finalizar e Calcular Pontos';
      }
    }

    // ========== REGISTRAR PONTOS DA TEMPORADA (MANUAL) ==========
    async function showSeasonPointsForm(seasonId, league) {
      const container = document.getElementById('mainContainer');

      try {
        // Buscar times com pontos atuais desta temporada
        const pointsResp = await fetch(`/api/history-points.php?action=get_teams_for_points&season_id=${seasonId}&league=${league}`);
        const pointsData = await pointsResp.json();
        if (!pointsData.success) throw new Error(pointsData.error || 'Erro ao carregar pontos');
        const teams = pointsData.teams || [];

        container.innerHTML = `
          <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left me-2"></i>Voltar
          </button>

          <div class="card bg-dark-panel border-warning" style="border-radius: 15px;">
            <div class="card-body">
              <h3 class="text-white mb-4">
                <i class="bi bi-bar-chart-steps text-warning me-2"></i>
                Pontos da Temporada ${String(currentSeasonData.season_number).padStart(2, '0')}
              </h3>

              <form id="pointsForm" onsubmit="saveSeasonPoints(event, ${seasonId}, '${league}')">
                <div class="table-responsive">
                  <table class="table table-dark table-hover">
                    <thead>
                      <tr>
                        <th>Time</th>
                        <th style="width: 120px;">Pontos</th>
                        <th class="d-none d-md-table-cell">Observação (opcional)</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${teams.map(t => `
                        <tr>
                          <td>
                            <div class="d-flex align-items-center gap-2">
                              <img src="${t.photo_url || '/img/default-team.png'}" alt="${t.team_name}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">
                              <span>${t.team_name}</span>
                            </div>
                          </td>
                          <td>
                            <input type="number" class="form-control bg-dark text-white border-warning" name="points_${t.id}" value="${Number(t.current_points || 0)}" min="0" />
                          </td>
                          <td class="d-none d-md-table-cell">
                            <input type="text" class="form-control bg-dark text-white border-warning" name="reason_${t.id}" placeholder="Ex: desempenho regular, bônus" />
                          </td>
                        </tr>
                      `).join('')}
                    </tbody>
                  </table>
                </div>

                <div class="d-grid gap-2">
                  <button type="submit" class="btn btn-warning">
                    <i class="bi bi-save me-2"></i>Salvar Pontos (Editar)
                  </button>
                </div>
              </form>
            </div>
          </div>
        `;
      } catch (e) {
        alert('Erro ao carregar times: ' + (e.error || 'Desconhecido'));
      }
    }

    async function saveSeasonPoints(event, seasonId, league) {
      event.preventDefault();
      const form = event.target;

      // Montar payload
      const teamPoints = [];
      const formData = new FormData(form);
      for (const [key, value] of formData.entries()) {
        if (key.startsWith('points_')) {
          const teamId = Number(key.replace('points_', ''));
          const points = Number(value || 0);
          teamPoints.push({ team_id: teamId, points });
        }
      }

      try {
        await fetch('/api/history-points.php?action=save_season_points', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ 
            season_id: seasonId, 
            league: league,
            team_points: teamPoints 
          })
        }).then(res => res.json()).then(data => {
          if (!data.success) throw new Error(data.error);
        });
        
        alert('Pontos salvos com sucesso!');
        showLeagueManagement(league);
      } catch (e) {
        alert('Erro ao salvar pontos: ' + (e.message || 'Desconhecido'));
      }
    }

    // ========== SALVAR HISTÓRICO ==========
    // ========== GERENCIAR SESSÃO DE DRAFT ==========
    async function showDraftSessionManagement(seasonId, league) {
      currentSeasonId = seasonId;
      currentLeague = league;
      const container = document.getElementById('mainContainer');
      
      container.innerHTML = `
        <div class="text-center py-5">
          <div class="spinner-border text-orange"></div>
          <p class="text-light-gray mt-2">Carregando sessão de draft...</p>
        </div>
      `;
      
      try {
        // Verificar se já existe uma sessão de draft para esta temporada
        const draftData = await api(`draft.php?action=active_draft&league=${league}`);
        const session = draftData.draft;
        
        // Buscar times da liga
        const teamsData = await api(`admin.php?action=teams&league=${league}`);
        const teams = teamsData.teams || [];
        
        if (session && session.season_id == seasonId) {
          // Já existe sessão - mostrar configuração
          await renderDraftSessionConfig(session, teams, league);
        } else {
          // Não existe sessão - mostrar botão para criar
          renderCreateDraftSession(seasonId, league, teams);
        }
      } catch (e) {
        container.innerHTML = `
          <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left me-2"></i>Voltar
          </button>
          <div class="alert alert-danger">Erro: ${e.error || 'Desconhecido'}</div>
        `;
      }
    }
    
    function renderCreateDraftSession(seasonId, league, teams) {
      const container = document.getElementById('mainContainer');
      
      container.innerHTML = `
        <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
          <i class="bi bi-arrow-left me-2"></i>Voltar
        </button>
        
        <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
          <div class="card-body text-center py-5">
            <i class="bi bi-trophy text-orange display-1 mb-4"></i>
            <h3 class="text-white mb-3">Criar Sessão de Draft</h3>
            <p class="text-light-gray mb-4">
              Crie uma nova sessão de draft para a temporada atual.<br>
              O draft terá 2 rodadas com ordem snake (a ordem inverte na 2ª rodada).
            </p>
            <button class="btn btn-orange btn-lg" onclick="createDraftSession(${seasonId}, '${league}')">
              <i class="bi bi-plus-circle me-2"></i>Criar Sessão de Draft
            </button>
          </div>
        </div>
      `;
    }
    
    async function createDraftSession(seasonId, league) {
      try {
        const result = await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'create_session',
            season_id: seasonId
          })
        });
        
        alert('Sessão de draft criada com sucesso!');
        showDraftSessionManagement(seasonId, league);
      } catch (e) {
        alert('Erro ao criar sessão: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function renderDraftSessionConfig(session, teams, league) {
      const container = document.getElementById('mainContainer');
      
      // Buscar ordem do draft se existir
      let orderData = { order: [] };
      try {
        orderData = await api(`draft.php?action=draft_order&draft_session_id=${session.id}`);
      } catch (e) {}
      
      const picks = orderData.order || [];
      const round1Picks = picks.filter(p => p.round == 1);
      const round2Picks = picks.filter(p => p.round == 2);
      
      const statusBadge = {
        'setup': '<span class="badge bg-warning">Configurando</span>',
        'in_progress': '<span class="badge bg-success">Em Andamento</span>',
        'completed': '<span class="badge bg-secondary">Concluído</span>'
      };
      
      container.innerHTML = `
        <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
          <i class="bi bi-arrow-left me-2"></i>Voltar
        </button>
        
        <!-- Status da Sessão -->
        <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h4 class="text-white mb-0">
                <i class="bi bi-trophy text-orange me-2"></i>
                Sessão de Draft #${session.id}
              </h4>
              ${statusBadge[session.status]}
            </div>
            <div class="row g-3">
              <div class="col-md-4">
                <div class="bg-dark p-3 rounded">
                  <small class="text-light-gray d-block">Temporada</small>
                  <strong class="text-white">${session.season_number || 'N/A'}</strong>
                </div>
              </div>
              <div class="col-md-4">
                <div class="bg-dark p-3 rounded">
                  <small class="text-light-gray d-block">Rodada Atual</small>
                  <strong class="text-orange">${session.current_round || 1}</strong>
                </div>
              </div>
              <div class="col-md-4">
                <div class="bg-dark p-3 rounded">
                  <small class="text-light-gray d-block">Pick Atual</small>
                  <strong class="text-orange">${session.current_pick || 1}</strong>
                </div>
              </div>
            </div>
            
            ${session.status === 'setup' ? `
              <div class="mt-4 d-flex gap-2 flex-wrap">
                <button class="btn btn-success" onclick="startDraftSession(${session.id}, '${league}')" ${round1Picks.length === 0 ? 'disabled' : ''}>
                  <i class="bi bi-play-fill me-2"></i>Iniciar Draft
                </button>
                <button class="btn btn-danger" onclick="deleteDraftSession(${session.id}, '${league}')">
                  <i class="bi bi-trash me-2"></i>Excluir Sessão
                </button>
              </div>
              ${round1Picks.length === 0 ? `
                <div class="alert alert-warning mt-3 mb-0">
                  <i class="bi bi-exclamation-triangle me-2"></i>
                  Configure a ordem do draft antes de iniciar.
                </div>
              ` : ''}
            ` : session.status === 'in_progress' ? `
              <div class="mt-4 d-flex gap-2 flex-wrap">
                <a href="/drafts.php" class="btn btn-orange">
                  <i class="bi bi-eye me-2"></i>Ver Draft em Andamento
                </a>
                <button class="btn btn-outline-warning" onclick="showAdminPickPanel(${session.id}, ${session.season_id})">
                  <i class="bi bi-shield-lock me-2"></i>Escolher Jogador (Admin)
                </button>
              </div>
            ` : ''}
          </div>
        </div>
        
        <!-- Configurar Ordem -->
        ${session.status === 'setup' ? `
          <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
            <div class="card-header bg-transparent border-orange">
              <h5 class="text-white mb-0">
                <i class="bi bi-list-ol text-orange me-2"></i>
                Definir Ordem do Draft
              </h5>
            </div>
            <div class="card-body">
              <p class="text-light-gray mb-3">
                Arraste os times para definir a ordem da 1ª rodada. A 2ª rodada terá ordem invertida (snake).
              </p>
              <div class="mb-3">
                <label class="text-white mb-2">Selecione os times na ordem do draft:</label>
                <div id="draftOrderList" class="border border-secondary rounded p-2" style="min-height: 100px;">
                  ${round1Picks.length > 0 ? 
                    round1Picks.map((p, idx) => `
                      <div class="draft-order-item bg-dark p-2 mb-2 rounded d-flex justify-content-between align-items-center" data-team-id="${p.original_team_id}" data-pick-id="${p.id}">
                        <span>
                          <strong class="text-orange">#${idx + 1}</strong>
                          <span class="text-white ms-2">${p.team_city} ${p.team_name}</span>
                          ${p.traded_from_team_id ? '<span class="badge bg-info ms-2">Trocada</span>' : ''}
                        </span>
                        <button class="btn btn-sm btn-outline-danger" onclick="removeFromDraftOrder(${p.id}, ${session.id}, '${league}')">
                          <i class="bi bi-x"></i>
                        </button>
                      </div>
                    `).join('') 
                    : '<p class="text-light-gray text-center my-3">Nenhum time adicionado ainda</p>'
                  }
                </div>
              </div>
              
              <div class="mb-3">
                <label class="text-white mb-2">Adicionar time à ordem:</label>
                <div class="form-check form-switch mb-2">
                  <input class="form-check-input" type="checkbox" id="allowDraftRepeat">
                  <label class="form-check-label text-light-gray" for="allowDraftRepeat">Permitir repetir time na ordem</label>
                </div>
                <div class="input-group">
                  <select class="form-select bg-dark text-white border-orange" id="addTeamSelect">
                    <option value="">Selecione um time...</option>
                    ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                  <button class="btn btn-orange" onclick="addTeamToDraftOrder(${session.id}, '${league}')">
                    <i class="bi bi-plus"></i> Adicionar
                  </button>
                </div>
              </div>
              
              <button class="btn btn-outline-success" onclick="autoGenerateDraftOrder(${session.id}, '${league}', ${JSON.stringify(teams).replace(/"/g, '&quot;')})">
                <i class="bi bi-magic me-2"></i>Gerar Ordem Automática (${teams.length} times)
              </button>
            </div>
          </div>
        ` : ''}
        
        <!-- Visualizar Ordem -->
        ${round1Picks.length > 0 ? `
          <div class="row g-4">
            <div class="col-md-6">
              <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
                <div class="card-header bg-transparent border-orange">
                  <h5 class="text-white mb-0">
                    <i class="bi bi-1-circle-fill text-orange me-2"></i>
                    1ª Rodada
                  </h5>
                </div>
                <div class="card-body p-0">
                  <ul class="list-group list-group-flush bg-transparent">
                    ${round1Picks.map((p, idx) => `
                      <li class="list-group-item bg-dark text-white d-flex justify-content-between align-items-center">
                        <span>
                          <strong class="text-orange me-2">#${idx + 1}</strong>
                          ${p.team_city} ${p.team_name}
                        </span>
                        ${p.picked_player_id ? `<span class="badge bg-success">${p.player_name}</span>` : 
                          p.traded_from_team_id ? '<span class="badge bg-info">Trocada</span>' : ''}
                      </li>
                    `).join('')}
                  </ul>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
                <div class="card-header bg-transparent border-orange">
                  <h5 class="text-white mb-0">
                    <i class="bi bi-2-circle-fill text-orange me-2"></i>
                    2ª Rodada (Snake)
                  </h5>
                </div>
                <div class="card-body p-0">
                  <ul class="list-group list-group-flush bg-transparent">
                    ${round2Picks.map((p, idx) => `
                      <li class="list-group-item bg-dark text-white d-flex justify-content-between align-items-center">
                        <span>
                          <strong class="text-orange me-2">#${idx + 1}</strong>
                          ${p.team_city} ${p.team_name}
                        </span>
                        ${p.picked_player_id ? `<span class="badge bg-success">${p.player_name}</span>` : 
                          p.traded_from_team_id ? '<span class="badge bg-info">Trocada</span>' : ''}
                      </li>
                    `).join('')}
                  </ul>
                </div>
              </div>
            </div>
          </div>
        ` : ''}
      `;
    }

    // ========== ADMIN: ESCOLHER JOGADOR NA VEZ ATUAL ==========
    async function showAdminPickPanel(draftSessionId, seasonId) {
      const container = document.getElementById('mainContainer');
      const orderData = await api(`draft.php?action=draft_order&draft_session_id=${draftSessionId}`);
      const session = orderData.session || {};
      const currentRound = session.current_round;
      const currentPickPos = session.current_pick;

      // Buscar pick atual sem jogador
      let currentPick = null;
      const picks = orderData.order || [];
      for (const p of picks) {
        if (p.round == currentRound && p.pick_position == currentPickPos && !p.picked_player_id) {
          currentPick = p;
          break;
        }
      }

      // Buscar jogadores disponíveis
      const playersData = await api(`draft.php?action=available_players&season_id=${seasonId}`);
      const players = playersData.players || [];

      container.innerHTML = `
        <button class="btn btn-back mb-4" onclick="showDraftSessionManagement(${session.season_id}, '${currentLeague || ''}')">
          <i class="bi bi-arrow-left me-2"></i>Voltar
        </button>

        <div class="card bg-dark-panel border-warning" style="border-radius: 15px;">
          <div class="card-header bg-transparent border-warning">
            <h5 class="text-white mb-0">
              <i class="bi bi-shield-lock text-warning me-2"></i>
              Escolher Jogador (Admin)
            </h5>
          </div>
          <div class="card-body">
            ${currentPick ? `
              <div class="bg-dark p-3 rounded border border-warning text-white">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <div><small class="text-light-gray">Rodada</small> <strong class="text-orange">${currentRound}</strong></div>
                    <div><small class="text-light-gray">Pick</small> <strong class="text-orange">${currentPickPos}</strong></div>
                    <div><small class="text-light-gray">Time</small> <strong class="text-white">${currentPick.team_city} ${currentPick.team_name}</strong></div>
                  </div>
                  <span class="badge bg-info">Vez do Time</span>
                </div>
              </div>
            ` : `
              <div class="alert alert-secondary">Nenhuma pick pendente no momento.</div>
            `}

            <div class="mb-3">
              <input type="text" id="adminPickSearch" class="form-control bg-dark text-white border-warning" placeholder="Buscar jogador por nome ou posição..." oninput="filterAdminPickList()" />
            </div>

            <div class="table-responsive">
              <table class="table table-dark table-hover" id="adminPickTable">
                <thead>
                  <tr>
                    <th>Jogador</th>
                    <th style="width:80px">Pos</th>
                    <th style="width:80px">OVR</th>
                    <th style="width:160px"></th>
                  </tr>
                </thead>
                <tbody>
                  ${players.map(pl => `
                    <tr>
                      <td class="text-white">${pl.name}</td>
                      <td>${pl.position}</td>
                      <td>${pl.ovr}</td>
                      <td>
                        <button class="btn btn-warning btn-sm" onclick="adminMakePick(${draftSessionId}, ${pl.id})" ${currentPick ? '' : 'disabled'}>
                          <i class="bi bi-check2-circle me-1"></i>Escolher
                        </button>
                      </td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      `;
    }

    function filterAdminPickList() {
      const term = (document.getElementById('adminPickSearch').value || '').toLowerCase();
      const rows = document.querySelectorAll('#adminPickTable tbody tr');
      rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
      });
    }

    async function adminMakePick(draftSessionId, playerId) {
      if (!confirm('Confirmar escolha deste jogador nesta pick?')) return;
      try {
        const res = await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'make_pick',
            draft_session_id: draftSessionId,
            player_id: playerId
          })
        });
        alert(res.message || 'Jogador escolhido com sucesso');
        // Voltar para a gestão da sessão
        showDraftSessionManagement(currentSeasonId, currentLeague);
      } catch (e) {
        alert('Erro ao escolher jogador: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function addTeamToDraftOrder(sessionId, league) {
      const select = document.getElementById('addTeamSelect');
      const teamId = select.value;
      const allowRepeat = document.getElementById('allowDraftRepeat')?.checked;
      
      if (!teamId) {
        alert('Selecione um time');
        return;
      }

      if (!allowRepeat) {
        const existing = document.querySelector(`#draftOrderList [data-team-id="${teamId}"]`);
        if (existing) {
          alert('Este time já está na ordem. Ative "Permitir repetir" para adicionar novamente.');
          return;
        }
      }
      
      try {
        await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'add_to_order',
            draft_session_id: sessionId,
            team_id: teamId
          })
        });
        
        showDraftSessionManagement(currentSeasonId, league);
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function removeFromDraftOrder(pickId, sessionId, league) {
      if (!confirm('Remover este time da ordem?')) return;
      
      try {
        await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'remove_from_order',
            pick_id: pickId,
            draft_session_id: sessionId
          })
        });
        
        showDraftSessionManagement(currentSeasonId, league);
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function autoGenerateDraftOrder(sessionId, league, teams) {
      if (!confirm(`Gerar ordem automática com ${teams.length} times? Isso substituirá a ordem atual.`)) return;
      
      try {
        // Primeiro limpar a ordem existente
        await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'clear_order',
            draft_session_id: sessionId
          })
        });
        
        // Adicionar cada time na ordem
        for (let i = 0; i < teams.length; i++) {
          await api('draft.php', {
            method: 'POST',
            body: JSON.stringify({
              action: 'add_to_order',
              draft_session_id: sessionId,
              team_id: teams[i].id
            })
          });
        }
        
        alert('Ordem gerada com sucesso!');
        showDraftSessionManagement(currentSeasonId, league);
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function startDraftSession(sessionId, league) {
      if (!confirm('Iniciar o draft? Os usuários poderão fazer suas picks.')) return;
      
      try {
        await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'start_draft',
            draft_session_id: sessionId
          })
        });
        
        alert('Draft iniciado!');
        showDraftSessionManagement(currentSeasonId, league);
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function deleteDraftSession(sessionId, league) {
      if (!confirm('Tem certeza que deseja excluir esta sessão de draft?')) return;
      
      try {
        await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'delete_session',
            draft_session_id: sessionId
          })
        });
        
        alert('Sessão excluída!');
        showDraftSessionManagement(currentSeasonId, league);
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== HISTÓRICO DE DRAFTS ==========
    async function showDraftHistory(league) {
      currentLeague = league;
      const container = document.getElementById('mainContainer');
      container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
      
      try {
        // Buscar todas temporadas com drafts
        const data = await api(`draft.php?action=draft_history&league=${league}`);
        const seasons = data.seasons || [];
        
        container.innerHTML = `
          <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left me-2"></i>Voltar
          </button>
          
          <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
            <div class="card-body">
              <h4 class="text-white mb-0">
                <i class="bi bi-clock-history text-orange me-2"></i>
                Histórico de Drafts - ${league}
              </h4>
            </div>
          </div>
          
          ${seasons.length === 0 ? `
            <div class="alert alert-info bg-dark border-orange text-white">
              <i class="bi bi-info-circle me-2"></i>
              Nenhuma temporada encontrada com histórico de draft.
            </div>
          ` : `
            <div class="accordion" id="draftHistoryAccordion">
              ${seasons.map((s, idx) => `
                <div class="accordion-item bg-dark border-orange mb-2" style="border-radius: 10px;">
                  <h2 class="accordion-header">
                    <button class="accordion-button collapsed bg-dark-panel text-white" type="button" 
                            data-bs-toggle="collapse" data-bs-target="#collapse${s.id}"
                            onclick="loadDraftSeasonDetails(${s.id})">
                      <span class="me-3">
                        <span class="badge bg-gradient-orange me-2">T${s.season_number}</span>
                        Ano ${s.year}
                      </span>
                      <span class="badge ${s.has_snapshot || s.draft_status === 'completed' ? 'bg-success' : (s.draft_status ? 'bg-warning text-dark' : 'bg-secondary')}">
                        ${s.has_snapshot || s.draft_status === 'completed' ? 'Finalizado' : (s.draft_status === 'in_progress' ? 'Em Andamento' : (s.draft_status === 'setup' ? 'Configurando' : 'Sem Draft'))}
                      </span>
                    </button>
                  </h2>
                  <div id="collapse${s.id}" class="accordion-collapse collapse">
                    <div class="accordion-body" id="draftDetails${s.id}">
                      <div class="text-center py-3">
                        <div class="spinner-border spinner-border-sm text-orange"></div>
                        <span class="ms-2 text-light-gray">Carregando...</span>
                      </div>
                    </div>
                  </div>
                </div>
              `).join('')}
            </div>
          `}
        `;
      } catch (e) {
        container.innerHTML = `
          <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left me-2"></i>Voltar
          </button>
          <div class="alert alert-danger">Erro ao carregar histórico: ${e.error || 'Desconhecido'}</div>
        `;
      }
    }
    
    async function loadDraftSeasonDetails(seasonId) {
      const container = document.getElementById(`draftDetails${seasonId}`);
      
      // Se já tem conteúdo carregado (não é o spinner), não recarregar
      if (!container.innerHTML.includes('spinner-border')) return;
      
      try {
        const data = await api(`draft.php?action=draft_history&season_id=${seasonId}`);
        const draftOrder = data.draft_order || [];
        
        if (draftOrder.length === 0) {
          container.innerHTML = `
            <div class="text-center text-light-gray py-3">
              <i class="bi bi-inbox display-4"></i>
              <p class="mt-2">Nenhuma escolha registrada nesta temporada.</p>
            </div>
          `;
          return;
        }
        
        // Agrupar por rodada
        const rounds = {};
        draftOrder.forEach(pick => {
          const r = pick.round || 1;
          if (!rounds[r]) rounds[r] = [];
          rounds[r].push(pick);
        });
        
        let html = '';
        for (const [round, picks] of Object.entries(rounds)) {
          html += `
            <h6 class="text-orange mt-3 mb-2"><i class="bi bi-trophy-fill me-2"></i>Rodada ${round}</h6>
            <div class="table-responsive">
              <table class="table table-dark table-sm table-hover mb-0">
                <thead>
                  <tr>
                    <th style="width: 60px;">Pick</th>
                    <th>Time</th>
                    <th>Jogador Escolhido</th>
                    <th class="d-none d-md-table-cell">Pos</th>
                    <th class="d-none d-md-table-cell">OVR</th>
                  </tr>
                </thead>
                <tbody>
                  ${picks.map(p => `
                    <tr>
                      <td><span class="badge bg-orange">#${p.pick_position}</span></td>
                      <td>
                        <strong class="text-white">${p.team_city || ''} ${p.team_name || ''}</strong>
                        ${p.traded_from_team_id ? `<br><small class="text-muted">via ${p.traded_from_city || ''} ${p.traded_from_name || ''}</small>` : ''}
                      </td>
                      <td>
                        ${p.player_name ? `
                          <span class="text-success fw-bold">${p.player_name}</span>
                        ` : `
                          <span class="text-muted">-</span>
                        `}
                      </td>
                      <td class="d-none d-md-table-cell">
                        ${p.player_position ? `<span class="badge bg-secondary">${p.player_position}</span>` : '-'}
                      </td>
                      <td class="d-none d-md-table-cell">
                        ${p.player_ovr ? `<span class="badge bg-success">${p.player_ovr}</span>` : '-'}
                      </td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
          `;
        }
        
        container.innerHTML = html;
      } catch (e) {
        container.innerHTML = `
          <div class="alert alert-danger mb-0">Erro ao carregar detalhes: ${e.error || 'Desconhecido'}</div>
        `;
      }
    }

    // Carregar ao iniciar
    document.addEventListener('DOMContentLoaded', () => {
      showLeaguesOverview();
    });
    
    // Limpar timer ao sair
    window.addEventListener('beforeunload', () => {
      if (timerInterval) clearInterval(timerInterval);
    });
    // Sidebar toggle
    (function () {
        const sidebar  = document.getElementById('sidebar');
        const overlay  = document.getElementById('sbOverlay');
        const menuBtn  = document.getElementById('menuBtn');
        if (!sidebar) return;
        const close = () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); };
        if (menuBtn)  menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); overlay.classList.add('show'); });
        if (overlay)  overlay.addEventListener('click', close);
        document.querySelectorAll('.sb-nav a').forEach(a => a.addEventListener('click', close));
    })();
  </script>
  <script src="/js/pwa.js"></script>
  <script>
    async function createInitDraft(seasonId) {
      const total_rounds = 5;
      try {
        const resp = await api('initdraft.php', {
          method: 'POST',
          body: JSON.stringify({ action: 'create_session', season_id: seasonId, total_rounds })
        });
        const url = `/initdraft.php?token=${resp.token}`;
        window.open(url, '_blank');
      } catch (e) {
        alert(e?.error || e?.message || 'Erro ao criar draft inicial');
      }
    }

    // Theme
    (() => {
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
    })();
  </script>
</body>
</html>
