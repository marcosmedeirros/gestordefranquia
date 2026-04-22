<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

// ── Temporada ────────────────────────────────────────────
$currentSeasonYear = null;
$currentSeason     = null;
$seasonDisplayYear = null;
try {
    $stmtSeason = $pdo->prepare('
        SELECT s.season_number, s.year, sp.start_year, sp.sprint_number
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN (\'completed\'))
        ORDER BY s.created_at DESC LIMIT 1
    ');
    $stmtSeason->execute([$user['league']]);
    $season = $stmtSeason->fetch(PDO::FETCH_ASSOC);
    if ($season) {
        $currentSeasonYear = isset($season['start_year'], $season['season_number'])
            ? (int)$season['start_year'] + (int)$season['season_number'] - 1
            : (int)($season['year'] ?? date('Y'));
        $currentSeason = $season;
    }
} catch (Exception $e) {}
$currentSeasonYear = $currentSeasonYear ?: (int)date('Y');
$seasonDisplayYear = (string)$currentSeasonYear;

// ── Time do usuário ──────────────────────────────────────
$stmtTeam = $pdo->prepare('
    SELECT t.*, COUNT(p.id) as player_count
    FROM teams t
    LEFT JOIN players p ON p.team_id = t.id
    WHERE t.user_id = ?
    GROUP BY t.id
    ORDER BY player_count DESC, t.id DESC
');
$stmtTeam->execute([$user['id']]);
$team   = $stmtTeam->fetch(PDO::FETCH_ASSOC) ?: null;
$teamId = $team ? (int)$team['id'] : null;

// ── CAP limits ───────────────────────────────────────────
$capMin = 0;
$capMax = 999;
if ($team && !empty($team['league'])) {
    try {
        $stmtCap = $pdo->prepare('SELECT cap_min, cap_max FROM league_settings WHERE league = ?');
        $stmtCap->execute([$team['league']]);
        $capLimits = $stmtCap->fetch();
        if ($capLimits) {
            $capMin = (int)($capLimits['cap_min'] ?? 0);
            $capMax = (int)($capLimits['cap_max'] ?? 999);
        }
    } catch (Exception $e) {}
}

// ── Contagem de jogadores ────────────────────────────────
$playerCount = 0;
if ($teamId) {
    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM players WHERE team_id = ?');
    $stmtCount->execute([$teamId]);
    $playerCount = (int)$stmtCount->fetchColumn();
}

$canAddPlayers = in_array(strtoupper((string)($team['league'] ?? '')), ['ELITE', 'NEXT'], true);
$is_admin = ($user['user_type'] ?? 'jogador') === 'admin';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Meu Elenco - FBA Manager</title>

    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0a0a0c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/icon-192.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css?v=20260411">

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
            --bg:         #f6f7fb;
            --panel:      #ffffff;
            --panel-2:    #f2f4f8;
            --panel-3:    #e9edf4;
            --border:     #e3e6ee;
            --border-md:  #d7dbe6;
            --border-red: rgba(252,0,37,.18);
            --text:       #111217;
            --text-2:     #5b6270;
            --text-3:     #8b93a5;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { -webkit-text-size-adjust: 100%; }
        html, body {
            height: 100%; background: var(--bg);
            color: var(--text); font-family: var(--font);
            -webkit-font-smoothing: antialiased;
        }
        body { overflow-x: hidden; }
        a, button { -webkit-tap-highlight-color: transparent; }

        /* ── Layout ────────────────────────────────────── */
        .app { display: flex; min-height: 100vh; }

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
        .sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
        .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-team-name  { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
        .sb-team-league{ font-size: 11px; color: var(--red); font-weight: 600; }
        .sb-season { margin: 10px 14px 0; background: var(--red-soft); border: 1px solid var(--border-red); border-radius: 8px; padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .sb-season-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); }
        .sb-season-val   { font-size: 14px; font-weight: 700; color: var(--red); }
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
        .sb-nav a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
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

        /* ── Topbar (mobile) ───────────────────────────── */
        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 240; }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
        .sb-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; display: none; }
        .sb-overlay.show { display: block; }

        /* ── Main ──────────────────────────────────────── */
        .main { margin-left: var(--sidebar-w); width: calc(100% - var(--sidebar-w)); padding: 32px 40px 60px; }
        .page-top { display: flex; align-items: flex-end; justify-content: space-between; gap: 18px; margin-bottom: 22px; flex-wrap: wrap; }
        .page-eyebrow { font-size: 12px; letter-spacing: .2em; text-transform: uppercase; color: var(--text-3); margin-bottom: 8px; }
        .page-title   { font-size: 32px; font-family: var(--font); margin-bottom: 6px; }
        .page-sub     { color: var(--text-2); font-size: 14px; }

        /* ── Stats strip ───────────────────────────────── */
        .stats-strip { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 14px; margin-bottom: 26px; }
        .stat-pill { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px 18px; display: flex; gap: 12px; align-items: center; }
        .stat-pill-icon { width: 42px; height: 42px; border-radius: 12px; background: var(--panel-2); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; color: var(--red); flex-shrink: 0; }
        .stat-pill-val   { font-weight: 700; font-size: 18px; font-family: var(--font); }
        .stat-pill-label { color: var(--text-2); font-size: 12px; }
        .cap-bonus-label { color: var(--text-3); font-size: 11px; margin-left: 6px; }
        .loyal-badge { background: rgba(34,197,94,.12); color: #22c55e; border: 1px solid rgba(34,197,94,.35); font-size: 10px; padding: 2px 6px; border-radius: 999px; }

        /* ── Panel ─────────────────────────────────────── */
        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 22px; }
        .panel + .panel { margin-top: 16px; }
        .panel-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
        .panel-title { font-family: var(--font); font-size: 16px; font-weight: 600; }
        .panel-sub   { color: var(--text-2); font-size: 12px; margin-top: 2px; }

        /* ── Form fields ───────────────────────────────── */
        .field { display: flex; flex-direction: column; gap: 6px; }
        .field label { font-size: 11px; color: var(--text-2); letter-spacing: .1em; text-transform: uppercase; font-weight: 600; }
        .field input, .field select {
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: 10px; padding: 10px 12px; color: var(--text);
            font-size: 14px; font-family: var(--font);
            transition: border-color var(--t) var(--ease);
        }
        .field input:focus, .field select:focus {
            outline: none; border-color: var(--border-red);
            box-shadow: 0 0 0 3px var(--red-soft);
        }
        .field input::placeholder { color: var(--text-3); }
        .field select option { background: var(--panel-2); }
        .fgrid { display: grid; gap: 14px; grid-template-columns: repeat(12, minmax(0,1fr)); }

        /* ── Buttons ───────────────────────────────────── */
        .btn-red { background: var(--red); color: #fff; border: none; border-radius: 10px; padding: 10px 20px; font-weight: 700; font-size: 14px; font-family: var(--font); cursor: pointer; transition: transform var(--t) var(--ease), box-shadow var(--t) var(--ease); display: inline-flex; align-items: center; gap: 6px; }
        .btn-red:hover { transform: translateY(-1px); box-shadow: 0 10px 20px var(--red-glow); }
        .btn-red:disabled { opacity: .45; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text-2); border-radius: 10px; padding: 8px 14px; font-size: 13px; font-weight: 500; font-family: var(--font); cursor: pointer; transition: all var(--t) var(--ease); display: inline-flex; align-items: center; gap: 6px; }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); }
        .btn-ghost-blue { background: rgba(13,202,240,.10); border: 1px solid rgba(13,202,240,.25); color: #0dcaf0; border-radius: 10px; padding: 8px 14px; font-size: 13px; font-weight: 600; font-family: var(--font); cursor: pointer; transition: all var(--t) var(--ease); display: inline-flex; align-items: center; gap: 6px; }
        .btn-ghost-blue:hover { background: rgba(13,202,240,.18); }

        /* ── Collapse toggle ───────────────────────────── */
        .collapse-toggle { background: transparent; border: 1px solid var(--border); color: var(--text-2); border-radius: 8px; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--t) var(--ease); flex-shrink: 0; }
        .collapse-toggle:hover { border-color: var(--border-red); color: var(--red); }
        .collapse-toggle[aria-expanded="false"] i { transform: rotate(-90deg); }
        .collapse-toggle i { transition: transform var(--t) var(--ease); display: block; }

        /* ── Toolbar ───────────────────────────────────── */
        .toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .toolbar-sep { flex: 1; }
        .search-wrap { position: relative; }
        .search-wrap input { padding-left: 36px; }
        .search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-3); font-size: 14px; pointer-events: none; }

        /* ── Table ─────────────────────────────────────── */
        .roster-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .roster-table thead th {
            text-transform: uppercase; font-size: 11px; letter-spacing: .15em; color: var(--text-3);
            padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border);
            cursor: pointer; white-space: nowrap; user-select: none;
        }
        .roster-table thead th.sortable:hover { color: var(--text-2); }
        .roster-table thead th[data-sort="name"]     { width: 30%; }
        .roster-table thead th[data-sort="position"] { width: 10%; }
        .roster-table thead th[data-sort="ovr"]      { width: 8%; }
        .roster-table thead th[data-sort="age"]      { width: 8%; }
        .roster-table thead th[data-sort="role"]     { width: 12%; }
        .roster-table tbody td { padding: 11px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .roster-table tbody tr:hover { background: var(--panel-2); }
        .roster-table tbody tr:last-child td { border-bottom: none; }

        /* ── Roster sections (quinteto JS-generated) ───── */
        .roster-sections { display: flex; flex-direction: column; gap: 1.5rem; }
        .roster-section h5 {
            letter-spacing: .12em; text-transform: uppercase; font-size: 10px;
            font-weight: 700; color: var(--text-3); margin-bottom: 14px; padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
        }
        .roster-divider { width: min(320px,80%); margin: 0 auto; border-color: var(--border); opacity: .5; }

        /* ── Roster card (JS quinteto) ─────────────────── */
        .roster-card {
            background: var(--panel-2) !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-sm) !important;
            box-shadow: none !important;
            transition: transform var(--t) var(--ease), box-shadow var(--t) var(--ease) !important;
        }
        .roster-card:hover {
            transform: translateY(-3px) !important;
            box-shadow: 0 12px 28px var(--red-glow) !important;
            border-color: var(--border-red) !important;
        }
        /* Override Bootstrap card classes used by JS */
        .card.border-orange { border-color: var(--border-red) !important; }
        .card-body { background: transparent; }

        /* ── Bench list (JS generated) ─────────────────── */
        .list-group-item.bg-transparent {
            background: transparent !important;
            border-color: var(--border) !important;
            color: var(--text) !important;
            padding: 10px 0 !important;
        }
        .list-group-item.bg-transparent + .list-group-item.bg-transparent {
            border-top: 1px solid var(--border);
        }

        /* ── Mobile cards (JS generated) ──────────────── */
        .roster-mobile-card {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
        }
        .roster-mobile-actions { display: flex; flex-wrap: wrap; gap: 6px; }
        .roster-mobile-actions .btn { flex: 1 1 auto; min-width: 40px; }

        /* ── Status/loading area ───────────────────────── */
        #players-status { text-align: center; padding: 32px 0; }

        /* ── Compat overrides for JS classes ───────────── */
        .text-orange     { color: var(--red) !important; }
        .text-light-gray { color: var(--text-2) !important; }
        .text-muted      { color: var(--text-3) !important; }
        .text-danger     { color: #f55 !important; }
        .spinner-border  { color: var(--red) !important; }

        /* Bootstrap form elements → tema */
        .form-control, .form-select {
            background-color: var(--panel-2) !important;
            border-color: var(--border) !important;
            color: var(--text) !important;
            font-family: var(--font);
        }
        .form-control:focus, .form-select:focus {
            background-color: var(--panel-2) !important;
            border-color: var(--border-red) !important;
            color: var(--text) !important;
            box-shadow: 0 0 0 3px var(--red-soft) !important;
        }
        .form-control::placeholder { color: var(--text-3) !important; }
        .form-select option { background: var(--panel-2); }
        .form-check-input { background-color: var(--panel-3); border-color: var(--border-md); }
        .form-check-input:checked { background-color: var(--red); border-color: var(--red); }
        .form-check-label { color: var(--text-2); font-size: 13px; }

        /* Bootstrap table-dark → tema */
        .table-dark {
            --bs-table-bg:          var(--panel-2);
            --bs-table-hover-bg:    var(--panel-3);
            --bs-table-border-color:var(--border);
            --bs-table-color:       var(--text);
            color: var(--text);
        }
        .table-dark thead th { color: var(--text-3); font-size: 11px; letter-spacing: .15em; text-transform: uppercase; border-bottom-color: var(--border); cursor: pointer; }
        .table-dark tbody td { border-bottom-color: var(--border); vertical-align: middle; }
        .table-responsive { overflow-x: auto; }

        /* Modal */
        .modal-content { background: var(--panel) !important; border: 1px solid var(--border) !important; border-radius: var(--radius) !important; color: var(--text); }
        .modal-header  { border-bottom: 1px solid var(--border) !important; padding: 16px 20px; }
        .modal-footer  { border-top: 1px solid var(--border) !important; padding: 14px 20px; }
        .modal-title   { font-size: 16px; font-weight: 600; font-family: var(--font); color: var(--text); }
        .modal-body    { padding: 20px; }
        .btn-close-white { filter: invert(1); }

        /* Empty state */
        .empty-state { text-align: center; color: var(--text-2); padding: 32px 0; font-size: 14px; }

        /* ── Responsive ────────────────────────────────── */
        @media (max-width: 1100px) { .stats-strip { grid-template-columns: repeat(2, minmax(0,1fr)); } }
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .topbar { display: flex; }
            .main { margin-left: 0; width: 100%; padding: 54px 16px 40px; }
            .fgrid { grid-template-columns: 1fr 1fr; }
            #players-table-wrapper, #players-grid { display: none !important; }
            .roster-mobile-cards { display: flex !important; flex-direction: column; gap: 10px; }
        }
        @media (min-width: 993px) {
            .roster-mobile-cards { display: none !important; }
        }
        @media (max-width: 560px) {
            .stats-strip { grid-template-columns: 1fr 1fr; }
            .fgrid { grid-template-columns: 1fr; }
            .page-title { font-size: 24px; }
        }
    </style>
</head>
<body>
<div class="app">

    <!-- ══════════════════════════════════════
         SIDEBAR
    ══════════════════════════════════════ -->
    <aside class="sidebar" id="sidebar">
        <div class="sb-brand">
            <div class="sb-logo">FBA</div>
            <div class="sb-brand-text">FBA Manager <span>Liga <?= htmlspecialchars($user['league']) ?></span></div>
        </div>

        <?php if ($team): ?>
        <div class="sb-team">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
                 alt="<?= htmlspecialchars($team['name'] ?? '') ?>"
                 onerror="this.src='/img/default-team.png'">
            <div>
                <div class="sb-team-name"><?= htmlspecialchars(trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''))) ?></div>
                <div class="sb-team-league"><?= htmlspecialchars($user['league']) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <nav class="sb-nav">
            <div class="sb-section">Principal</div>
            <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
            <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
            <a href="/my-roster.php" class="active"><i class="bi bi-person-fill"></i> Meu Elenco</a>
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

            <?php if ($is_admin): ?>
            <div class="sb-section">Admin</div>
            <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
            <a href="/punicoes.php"><i class="bi bi-exclamation-triangle-fill"></i> Punições</a>
            <a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a>
            <?php endif; ?>

            <div class="sb-section">Conta</div>
            <a href="/settings.php"><i class="bi bi-gear-fill"></i> Configurações</a>
        </nav>

        <button class="sb-theme-toggle" type="button" id="themeToggle">
            <i class="bi bi-moon"></i><span>Modo escuro</span>
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

    <div class="sb-overlay" id="sbOverlay"></div>

    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Manager</em></div>
        <?php if ($currentSeason): ?>
        <span style="font-size:11px;font-weight:700;color:var(--red)"><?= $seasonDisplayYear ?></span>
        <?php endif; ?>
    </header>

    <!-- ══════════════════════════════════════
         MAIN
    ══════════════════════════════════════ -->
    <main class="main">

        <!-- Page header -->
        <div class="page-top">
            <div>
                <div class="page-eyebrow">Liga &mdash; <?= $currentSeasonYear ?></div>
                <h1 class="page-title">Meu Elenco</h1>
                <p class="page-sub"><?= $team ? htmlspecialchars(trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''))) : 'Sem time' ?></p>
            </div>
        </div>

        <?php if (!$teamId): ?>
        <div style="background:var(--panel);border:1px solid rgba(255,193,7,.3);border-radius:var(--radius);padding:20px 24px;color:#ffc107;">
            <i class="bi bi-exclamation-triangle me-2"></i>Você ainda não possui um time. Crie um no onboarding.
        </div>
        <?php else: ?>

        <!-- Stats strip -->
        <div class="stats-strip">
            <div class="stat-pill">
                <div class="stat-pill-icon"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="stat-pill-val" id="total-players"><?= $playerCount ?></div>
                    <div class="stat-pill-label">Jogadores</div>
                </div>
            </div>
            <div class="stat-pill">
                <div class="stat-pill-icon"><i class="bi bi-graph-up"></i></div>
                <div>
                    <div class="stat-pill-val" id="cap-top8">—</div>
                    <div class="stat-pill-label">CAP <span id="cap-bonus-label" class="cap-bonus-label"></span></div>
                </div>
            </div>
            <div class="stat-pill">
                <div class="stat-pill-icon"><i class="bi bi-hand-thumbs-down"></i></div>
                <div>
                    <div class="stat-pill-val" id="waivers-count">— / —</div>
                    <div class="stat-pill-label">Dispensas</div>
                </div>
            </div>
            <div class="stat-pill">
                <div class="stat-pill-icon"><i class="bi bi-person-check-fill"></i></div>
                <div>
                    <div class="stat-pill-val" id="signings-count">— / —</div>
                    <div class="stat-pill-label">Contratações</div>
                </div>
            </div>
        </div>

        <?php if ($canAddPlayers): ?>
        <!-- Panel: Adicionar Jogador (somente ELITE/NEXT) -->
        <div class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title">Adicionar Jogador</div>
                    <div class="panel-sub">Cadastre reforços enquanto a temporada está em andamento</div>
                </div>
                <button class="collapse-toggle" type="button"
                        data-bs-toggle="collapse" data-bs-target="#addPlayerCollapse"
                        aria-expanded="false" aria-controls="addPlayerCollapse">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>
            <div class="collapse" id="addPlayerCollapse">
                <form id="form-player">
                    <div class="fgrid">
                        <div class="field" style="grid-column: span 4;">
                            <label for="field-name">Nome</label>
                            <input type="text" name="name" id="field-name" placeholder="Ex: John Doe" required>
                        </div>
                        <div class="field" style="grid-column: span 2;">
                            <label for="field-position">Posição</label>
                            <select name="position" id="field-position" required>
                                <option value="">Selecione</option>
                                <option value="PG">PG</option>
                                <option value="SG">SG</option>
                                <option value="SF">SF</option>
                                <option value="PF">PF</option>
                                <option value="C">C</option>
                            </select>
                        </div>
                        <div class="field" style="grid-column: span 2;">
                            <label for="field-secondary">Pos. Secundária</label>
                            <select name="secondary_position" id="field-secondary">
                                <option value="">Nenhuma</option>
                                <option value="PG">PG</option>
                                <option value="SG">SG</option>
                                <option value="SF">SF</option>
                                <option value="PF">PF</option>
                                <option value="C">C</option>
                            </select>
                        </div>
                        <div class="field" style="grid-column: span 1;">
                            <label for="field-age">Idade</label>
                            <input type="number" name="age" id="field-age" min="16" max="45" required>
                        </div>
                        <div class="field" style="grid-column: span 1;">
                            <label for="field-ovr">OVR</label>
                            <input type="number" name="ovr" id="field-ovr" min="40" max="99" required>
                        </div>
                        <div class="field" style="grid-column: span 2;">
                            <label for="field-role">Função</label>
                            <select name="role" id="field-role" required>
                                <option value="Titular">Titular</option>
                                <option value="Banco">Banco</option>
                                <option value="Outro">Outro</option>
                                <option value="G-League">G-League</option>
                            </select>
                        </div>
                        <div style="grid-column: span 4; display:flex; align-items:center; gap:10px; padding-top:18px;">
                            <div class="form-check" style="margin:0;">
                                <input class="form-check-input" type="checkbox" id="available_for_trade" name="available_for_trade" checked>
                                <label class="form-check-label" for="available_for_trade">Disponível para troca</label>
                            </div>
                        </div>
                        <div style="grid-column: span 4; align-self: end;">
                            <button type="submit" class="btn-red" id="btn-add-player" style="width:100%;justify-content:center;padding:11px;">
                                <i class="bi bi-cloud-upload"></i> Cadastrar Jogador
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Panel: Jogadores -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Jogadores</div>
                <div class="toolbar">
                    <!-- Busca -->
                    <div class="search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text" id="players-search" class="field" placeholder="Buscar por nome / posição…"
                               style="background:var(--panel-2);border:1px solid var(--border);border-radius:10px;padding:8px 12px 8px 36px;color:var(--text);font-size:13px;font-family:var(--font);width:220px;">
                    </div>
                    <!-- Filtro função -->
                    <select id="players-role-filter"
                            style="background:var(--panel-2);border:1px solid var(--border);border-radius:10px;padding:8px 12px;color:var(--text);font-size:13px;font-family:var(--font);">
                        <option value="">Todas as funções</option>
                        <option value="Titular">Titular</option>
                        <option value="Banco">Banco</option>
                        <option value="G-League">G-League</option>
                        <option value="Outro">Outro</option>
                    </select>
                    <!-- Sort -->
                    <select id="sort-select"
                            style="background:var(--panel-2);border:1px solid var(--border);border-radius:10px;padding:8px 12px;color:var(--text);font-size:13px;font-family:var(--font);">
                        <option value="role">Ordenar: Função</option>
                        <option value="name">Ordenar: Nome</option>
                        <option value="ovr">Ordenar: OVR</option>
                        <option value="position">Ordenar: Posição</option>
                        <option value="age">Ordenar: Idade</option>
                    </select>
                    <div class="toolbar-sep"></div>
                    <!-- IA -->
                    <button id="btn-ai-analysis" class="btn-ghost-blue" type="button">
                        <i class="bi bi-robot"></i> Análise IA
                    </button>
                    <!-- Refresh -->
                    <button id="btn-refresh-players" class="btn-ghost" type="button">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
            </div>

            <!-- Loading -->
            <div id="players-status">
                <div class="spinner-border" role="status" style="width:2rem;height:2rem;"></div>
                <p style="color:var(--text-2);font-size:13px;margin-top:12px;">Carregando jogadores…</p>
            </div>

            <!-- Tabela desktop -->
            <div id="players-table-wrapper" style="display:none;">
                <div class="table-responsive">
                    <table class="roster-table table table-dark table-hover align-middle mb-0" id="players-table">
                        <thead>
                            <tr>
                                <th data-sort="name"     class="sortable">Jogador</th>
                                <th data-sort="position" class="sortable">Posição</th>
                                <th data-sort="ovr"      class="sortable">OVR</th>
                                <th data-sort="age"      class="sortable">Idade</th>
                                <th data-sort="role"     class="sortable">Função</th>
                                <th>Transferência</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="players-table-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- Grid quinteto (desktop) -->
            <div id="players-grid" class="roster-sections" style="display:none;"></div>

            <!-- Cards mobile -->
            <div id="players-mobile-cards" class="roster-mobile-cards"></div>
        </div>

        <?php endif; ?>
    </main>
</div><!-- /app -->

<!-- ══════════════════════════════════════
     MODAIS
══════════════════════════════════════ -->

<!-- Modal: Editar Jogador -->
<div class="modal fade" id="editPlayerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2" style="color:var(--red)"></i>Editar Jogador</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-player-id">
                <div class="fgrid">
                    <div class="field" style="grid-column: span 6;">
                        <label for="edit-name">Nome</label>
                        <input type="text" id="edit-name" required>
                    </div>
                    <div class="field" style="grid-column: span 6;" id="edit-foto-adicional-wrap">
                        <label for="edit-foto-adicional">Foto do Jogador</label>
                        <input type="file" id="edit-foto-adicional" class="form-control" accept="image/*">
                        <div style="margin-top:10px;">
                            <img id="edit-foto-preview" src="/img/default-avatar.png" alt="Preview"
                                 style="width:56px;height:56px;object-fit:cover;border-radius:50%;border:2px solid var(--border-red);background:var(--panel-3);">
                        </div>
                    </div>
                    <div class="field" style="grid-column: span 2;">
                        <label for="edit-age">Idade</label>
                        <input type="number" id="edit-age" min="16" max="50" required>
                    </div>
                    <div class="field" style="grid-column: span 2;">
                        <label for="edit-position">Posição</label>
                        <select id="edit-position" required>
                            <option value="PG">PG</option>
                            <option value="SG">SG</option>
                            <option value="SF">SF</option>
                            <option value="PF">PF</option>
                            <option value="C">C</option>
                        </select>
                    </div>
                    <div class="field" style="grid-column: span 2;">
                        <label for="edit-secondary-position">Pos. Sec.</label>
                        <select id="edit-secondary-position">
                            <option value="">Nenhuma</option>
                            <option value="PG">PG</option>
                            <option value="SG">SG</option>
                            <option value="SF">SF</option>
                            <option value="PF">PF</option>
                            <option value="C">C</option>
                        </select>
                    </div>
                    <div class="field" style="grid-column: span 2;">
                        <label for="edit-ovr">OVR</label>
                        <input type="number" id="edit-ovr" min="40" max="99" required>
                    </div>
                    <div class="field" style="grid-column: span 4;">
                        <label for="edit-role">Função</label>
                        <select id="edit-role" required>
                            <option value="Titular">Titular</option>
                            <option value="Banco">Banco</option>
                            <option value="Outro">Outro</option>
                            <option value="G-League">G-League</option>
                        </select>
                    </div>
                    <div style="grid-column: span 6; display:flex; align-items:center; gap:10px; padding-top:6px;">
                        <div class="form-check" style="margin:0;">
                            <input class="form-check-input" type="checkbox" id="edit-available">
                            <label class="form-check-label" for="edit-available">Disponível para troca</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="gap:10px;">
                <button class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn-red" id="btn-save-edit"><i class="bi bi-save2"></i> Salvar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Dispensar Jogador -->
<div class="modal fade" id="waivePlayerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-x me-2" style="color:var(--red)"></i>Dispensar Jogador</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p style="font-size:14px;color:var(--text-2);margin-bottom:10px;">
                    Se você dispensar <strong style="color:var(--text);" id="waive-player-name">jogador</strong>,
                    seu CAP Top 8 vai ser <strong style="color:var(--red);" id="waive-player-cap">0</strong>.
                </p>
                <p style="font-size:13px;color:var(--text-2);margin:0;" id="waive-cap-status">Você vai ficar dentro do cap.</p>
            </div>
            <div class="modal-footer" style="gap:10px;">
                <button class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn-red" id="btn-confirm-waive"><i class="bi bi-person-x"></i> Dispensar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Análise IA -->
<div class="modal fade" id="aiAnalysisModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-color:rgba(13,202,240,.3) !important;">
            <div class="modal-header" style="background:rgba(13,202,240,.12);border-bottom-color:rgba(13,202,240,.2) !important;">
                <h5 class="modal-title" style="color:#0dcaf0;"><i class="bi bi-robot me-2"></i>Relatório do Assistente Técnico</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="ai-loading" class="text-center py-5">
                    <div class="spinner-border" role="status" style="width:3rem;height:3rem;color:#0dcaf0 !important;"></div>
                    <h5 style="color:var(--text);margin-top:16px;font-size:16px;">A IA está analisando seu elenco…</h5>
                    <p style="color:var(--text-2);font-size:13px;">Avaliando idades, OVR e equilíbrio tático das posições…</p>
                </div>
                <div id="ai-results" style="display:none;">
                    <h6 style="color:#25c677;font-weight:700;margin-bottom:10px;"><i class="bi bi-arrow-up-circle me-1"></i>Pontos Fortes</h6>
                    <ul id="ai-strengths" style="color:var(--text);font-size:14px;margin-bottom:24px;padding-left:20px;"></ul>
                    <h6 style="color:var(--red);font-weight:700;margin-bottom:10px;"><i class="bi bi-arrow-down-circle me-1"></i>Pontos de Atenção</h6>
                    <ul id="ai-weaknesses" style="color:var(--text);font-size:14px;padding-left:20px;"></ul>
                </div>
            </div>
            <div class="modal-footer" style="border-top-color:rgba(13,202,240,.2) !important;">
                <button type="button" class="btn-ghost" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════
     SCRIPTS
══════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/pwa.js"></script>
<script>
    window.__TEAM_ID__ = <?= $teamId ? (int)$teamId : 'null' ?>;
    window.__CAP_MIN__ = <?= (int)$capMin ?>;
    window.__CAP_MAX__ = <?= (int)$capMax ?>;

    /* ── Tema ─────────────────────────── */
    const themeKey = 'fba-theme';
    const root = document.documentElement;
    const savedTheme = localStorage.getItem(themeKey)
        || (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    root.dataset.theme = savedTheme;
    const themeToggle = document.getElementById('themeToggle');
    const updateToggle = t => {
        if (!themeToggle) return;
        themeToggle.innerHTML = t === 'light'
            ? '<i class="bi bi-moon-stars-fill"></i><span>Tema escuro</span>'
            : '<i class="bi bi-sun-fill"></i><span>Tema claro</span>';
    };
    updateToggle(savedTheme);
    themeToggle?.addEventListener('click', () => {
        const next = root.dataset.theme === 'light' ? 'dark' : 'light';
        root.dataset.theme = next;
        localStorage.setItem(themeKey, next);
        updateToggle(next);
    });

    /* ── Sidebar mobile ───────────────── */
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

    /* ── Collapse chevron rotation ────── */
    document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(btn => {
        btn.addEventListener('shown.bs.collapse', () => btn.setAttribute('aria-expanded', 'true'));
        btn.addEventListener('hidden.bs.collapse', () => btn.setAttribute('aria-expanded', 'false'));
    });
</script>
<script src="/js/my-roster-v2.js?v=20260323-1"></script>
</body>
</html>
