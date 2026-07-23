<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

$isAdmin = hasAdminAccess($pdo, (int)$user['id']);
$userLeague = strtoupper($team['league'] ?? $user['league'] ?? 'ELITE');
$currentTeamId = (int)($team['id'] ?? 0);
$currentSeason = null;
$currentSeasonId = null;
$currentSeasonYear = (int)date('Y');
if (!empty($team['league'])) {
    try {
        $stmtSeason = $pdo->prepare('SELECT s.id AS season_id, s.season_number, s.year, sp.start_year, sp.sprint_number FROM seasons s LEFT JOIN sprints sp ON s.sprint_id = sp.id WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ("completed")) ORDER BY s.created_at DESC LIMIT 1');
        $stmtSeason->execute([$team['league']]);
        $currentSeason = $stmtSeason->fetch();
        $currentSeasonId = $currentSeason ? (int)($currentSeason['season_id'] ?? 0) : null;
        if ($currentSeason && isset($currentSeason['start_year'], $currentSeason['season_number'])) {
            $currentSeasonYear = (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1;
        } elseif ($currentSeason && isset($currentSeason['year'])) {
            $currentSeasonYear = (int)$currentSeason['year'];
        }
    } catch (Exception $e) { $currentSeason = null; }
}
$seasonDisplayYear = (string)$currentSeasonYear;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Rankings - FBA Manager</title>
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="/manifest.json?v=3">
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
    <link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
    
    <!-- Fonts & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* ── Tokens Universais ───────────────────────── */
        :root {
            --red:        #fc0025;
            --red-2:      color-mix(in srgb, var(--red) 85%, white);
            --red-soft:   color-mix(in srgb, var(--red) 10%, transparent);
            --bg:         #07070a;
            --panel:      #101013;
            --panel-2:    #16161a;
            --panel-3:    #1c1c21;
            --border:     rgba(255,255,255,.06);
            --border-md:  rgba(255,255,255,.10);
            --text:       #f0f0f3;
            --text-2:     #868690;
            --text-3:     #7d7d85;
            --amber:      #f59e0b;
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
            --text: #111217;
            --text-2: #5b6270;
            --text-3: #657080;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }
        a { text-decoration: none; color: inherit; }

        /* ── Shell (Sidebar & Topbar) ────────────────── */
        .app { display: flex; min-height: 100vh; }
        .sidebar {
            position: fixed; top: 0; left: 0; width: 260px; height: 100vh;
            background: var(--panel); border-right: 1px solid var(--border);
            display: flex; flex-direction: column; z-index: 300;
            transition: transform var(--t) var(--ease); overflow-y: auto;
        }
        .sidebar::-webkit-scrollbar { display: none; }
        .sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
        .sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; }
        .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); }
        .sb-team-name { font-size: 13px; font-weight: 600; line-height: 1.2; }
        .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
        
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
        .sb-nav a { font-family:'Inter',sans-serif; display: flex; align-items: center; gap: 10px; padding: 10px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; margin-bottom: 2px; transition: all var(--t) var(--ease); }
        .sb-nav a i { font-size: 15px; width: 18px; text-align: center; }
        .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
        .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
        .sb-theme-toggle { margin: 0 14px 12px; padding: 8px 10px; border-radius: 10px; border: 1px solid var(--border); background: var(--panel-2); color: var(--text); display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease); }
        .sb-theme-toggle:hover { border-color: var(--red); color: var(--red); }
        .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; border: 1px solid var(--border-md); }
        .sb-username { font-size: 12px; font-weight: 500; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout { width: 26px; height: 26px; border-radius: 7px; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; transition: all var(--t) var(--ease); }
        .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 260; }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; font-size: 17px; cursor: pointer; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
        .sb-overlay.show { display: block; }

        /* ── Main Content ────────────────────────────── */
        .main { margin-left: var(--sidebar-w); width: calc(100% - var(--sidebar-w)); min-height: 100vh; display: flex; flex-direction: column; }
        .content { padding: 0 32px 40px; flex: 1; }
        
        .dash-hero { padding: 32px 32px 24px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .dash-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .dash-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
        .dash-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }
        .hero-badges { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .hbadge { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid var(--border-md); background: var(--panel); color: var(--text); font-family: var(--font); cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; }
        .hbadge:hover { border-color: rgba(255,255,255,.25); color: var(--text); background: var(--panel-2); }
        .hbadge.red { background: var(--red-soft); border-color: var(--red); color: var(--red); }
        .hbadge.red:hover { background: var(--red); color: #fff; }

        /* ── Filtros (Pills) ─────────────────────────── */
        .filter-nav { display: flex; gap: 8px; margin-bottom: 24px; overflow-x: auto; padding-bottom: 4px; }
        .filter-nav::-webkit-scrollbar { display: none; }
        .filter-btn { padding: 8px 18px; border-radius: 99px; background: var(--panel); border: 1px solid var(--border); color: var(--text-2); font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease); white-space: nowrap; }
        .filter-btn:hover { border-color: var(--border-md); color: var(--text); }
        .filter-btn.active { background: var(--red); border-color: var(--red); color: #fff; }

        /* Copiar ranking p/ WhatsApp — separado dos filtros de liga */
        .wpp-btn { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
            border-radius: 99px; background: var(--panel); border: 1px solid var(--border);
            color: var(--text-2); font-size: 12px; font-weight: 600; cursor: pointer;
            transition: all var(--t) var(--ease); white-space: nowrap; font-family: inherit; }
        .wpp-btn:hover { border-color: #25d366; color: #25d366; }
        .wpp-btn i { font-size: 14px; }
        .wpp-btn.copied { background: rgba(37,211,102,.12); border-color: #25d366; color: #25d366; }
        @media (max-width: 640px) {
            .wpp-btn { margin-left: 0; }
            .wpp-btn span { display: none; }
        }

        /* ── Minimal Table ───────────────────────────── */
        .table-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; animation: fadeUp 0.4s var(--ease); }
        .m-table { width: 100%; border-collapse: collapse; text-align: left; }
        .m-table th { padding: 14px 18px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-3); border-bottom: 1px solid var(--border); background: var(--panel-2); }
        .m-table td { padding: 14px 18px; font-size: 13px; font-weight: 500; color: var(--text); border-bottom: 1px solid var(--border); vertical-align: middle; }
        .m-table tr:last-child td { border-bottom: none; }
        .m-table tbody tr { transition: background var(--t) var(--ease); }
        .m-table tbody tr:hover { background: var(--panel-2); }

        /* Highlights da Tabela */
        .rank-pos { font-size: 13px; font-weight: 800; color: var(--text-3); text-align: center; width: 24px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .rank-pos.gold { color: var(--amber); font-size: 15px; }
        .rank-pos.silver { color: #94a3b8; font-size: 15px; }
        /* Variacao de posicao em relacao ao fim da sprint anterior */
        .rk-var { display: inline-flex; align-items: center; gap: 2px; font-size: 11px; font-weight: 800;
            padding: 2px 6px; border-radius: 999px; line-height: 1; white-space: nowrap; }
        .rk-var i { font-size: 9px; }
        .rk-var.up   { color: var(--green); background: color-mix(in srgb, var(--green) 12%, transparent); }
        .rk-var.down { color: #ef4444; background: rgba(239,68,68,.12); }
        .rk-var.same { color: var(--text-3); background: var(--panel-2); }
        .rk-var.none { color: var(--text-3); background: transparent; opacity: .5; }
        .rk-legenda { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 11px;
            color: var(--text-3); margin-bottom: 12px; }
        .rank-pos.bronze { color: #cd7c4a; font-size: 15px; }
        .rank-shift { display: inline-flex; align-items: center; margin-left: 8px; font-size: 10px; font-weight: 700; }
        .rank-shift.up { color: #22c55e; }
        .rank-shift.down { color: #ef4444; }
        
        /* Destaque Time Atual */
        .row-me { background: var(--red-soft) !important; }
        .row-me td { border-bottom-color: color-mix(in srgb, var(--red) 10%, transparent); }
        .row-me .rank-pos { color: var(--red); }

        /* Destaques de subida/queda */
        .row-top {
            background: rgba(34,197,94,.08) !important;
        }
        .row-bottom {
            background: rgba(239,68,68,.08) !important;
        }

        .team-name-cell { font-weight: 700; font-size: 14px; display: block; }
        .team-gm-cell { font-size: 11px; color: var(--text-2); font-weight: 500; margin-top: 2px; }
        .league-badge { background: var(--panel-3); border: 1px solid var(--border-md); padding: 4px 8px; border-radius: 6px; font-size: 10px; font-weight: 700; color: var(--text-2); }

        /* ── Modal Customizado ───────────────────────── */
        .modal-content.minimal { background: var(--panel); border: 1px solid var(--border-md); border-radius: var(--radius); }
        .modal-header.minimal { border-bottom: 1px solid var(--border); padding: 18px 24px; }
        .modal-footer.minimal { border-top: 1px solid var(--border); padding: 18px 24px; }
        .modal-title { font-size: 15px; font-weight: 700; font-family: var(--font); color: var(--text); }
        .minimal-input { background: var(--panel-2); border: 1px solid var(--border-md); color: var(--text); border-radius: 8px; padding: 8px 12px; font-size: 13px; width: 100%; transition: border-color var(--t); }
        .minimal-input:focus { outline: none; border-color: var(--red); }
        .btn-minimal { padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all var(--t); }
        .btn-minimal.primary { background: var(--red); border: none; color: #fff; }
        .btn-minimal.primary:hover { filter: brightness(1.1); }
        .btn-minimal.secondary { background: transparent; border: 1px solid var(--border-md); color: var(--text); }
        .btn-minimal.secondary:hover { background: var(--panel-2); }

        /* ── Loader ── */
        .spinner { width: 32px; height: 32px; border: 3px solid var(--border-md); border-top-color: var(--red); border-radius: 50%; animation: spin 1s linear infinite; margin: 40px auto; }

        /* ── Histórico de Pontuação (modal) ─────────── */
        .pts-season { margin-bottom: 4px; }
        .pts-season-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 20px; background: var(--panel-2);
            border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 1;
        }
        .pts-season-label { font-size: 12px; font-weight: 700; color: var(--text); }
        .pts-season-count { font-size: 10px; color: var(--text-3); font-weight: 600; }
        .pts-row {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 20px; border-bottom: 1px solid var(--border);
            transition: background var(--t) var(--ease);
        }
        .pts-row:last-child { border-bottom: none; }
        .pts-row:hover { background: var(--panel-2); }
        .pts-rank { width: 22px; font-size: 11px; font-weight: 800; color: var(--text-3); text-align: center; flex-shrink: 0; }
        .pts-rank.g { color: var(--amber); }
        .pts-rank.s { color: #94a3b8; }
        .pts-rank.b { color: #cd7c4a; }
        .pts-team { flex: 1; font-size: 13px; font-weight: 600; color: var(--text); min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pts-bar-wrap { width: 80px; height: 5px; background: var(--panel-3); border-radius: 3px; flex-shrink: 0; }
        .pts-bar { height: 5px; border-radius: 3px; background: var(--red); transition: width .4s var(--ease); }
        .pts-val { font-size: 13px; font-weight: 800; color: var(--red); min-width: 42px; text-align: right; flex-shrink: 0; }
        .pts-empty { padding: 40px 20px; text-align: center; color: var(--text-3); font-size: 13px; }
        
        @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes spin { to { transform: rotate(360deg); } }
        .ranking-panel { animation: fadeUp .35s var(--ease) both; }

        /* ── Light theme overrides ───────────────────── */
        :root[data-theme="light"] {
            --bg:         #f4f6fb;
            --panel:      #ffffff;
            --panel-2:    #f0f2f8;
            --panel-3:    #e8ebf4;
            --border:     rgba(15,23,42,.09);
            --border-md:  rgba(15,23,42,.14);
            --border-red: color-mix(in srgb, var(--red) 20%, transparent);
            --text:       #111217;
            --text-2:     #5b6270;
            --text-3:     #657080;
        }
        [data-theme="light"] body { background: var(--bg); color: var(--text); }
        [data-theme="light"] .modal-content { background: var(--panel) !important; color: var(--text) !important; border-color: var(--border-md) !important; }
        [data-theme="light"] .modal-header { background: var(--panel-2) !important; border-color: var(--border) !important; }
        [data-theme="light"] .modal-footer { background: var(--panel-2) !important; border-color: var(--border) !important; }
        [data-theme="light"] .btn-close { filter: none; }
        [data-theme="light"] .form-control { background: var(--panel-2) !important; border-color: var(--border-md) !important; color: var(--text) !important; }
        [data-theme="light"] .table-dark { --bs-table-bg: var(--panel); --bs-table-color: var(--text); --bs-table-border-color: var(--border); }
        [data-theme="light"] .table-dark thead th { background: var(--panel-2); color: var(--text-3); border-color: var(--border) !important; }
        [data-theme="light"] .table-dark tbody td { border-color: var(--border) !important; }
        [data-theme="light"] .table-dark tbody tr:hover { background: var(--panel-2) !important; }

        /* ── Sidebar toggle (hidden — topbar handles mobile) ─ */
        .sidebar-toggle { display: none !important; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 199; }
        .sidebar-overlay.active, .sidebar-overlay.show { display: block; }

        /* ── Responsivo Histórico ───────────────────── */
        @media (max-width: 480px) {
            .pts-bar-wrap { display: none; }
            .pts-row { padding: 10px 14px; gap: 8px; }
            .pts-season-head { padding: 10px 14px; }
        }

        /* ── Responsivo ──────────────────────────────── */
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .dash-hero { padding: 24px 16px 16px; }
            .content { padding: 0 16px 30px; }
            .hide-mobile { display: none; }
            .m-table th, .m-table td { padding: 12px; }
            .dash-hero, .content { padding-left: 16px; padding-right: 16px; }
            .dash-hero { padding-top: 18px; }
            .podium { gap: 8px; }
            .podium-item { max-width: 130px; }
            .podium-logo { width: 40px; height: 40px; }
            .hide-mobile { display: none !important; }
        }
        @media (max-width: 600px) {
            .m-table th, .m-table td { padding: 10px 12px; font-size: 12px; }
            .rank-pos { font-size: 12px; }
            .rank-pos.gold, .rank-pos.silver, .rank-pos.bronze { font-size: 13px; }
            .rank-shift { font-size: 9px; margin-left: 6px; }
            .team-name-cell { font-size: 13px; }
            .team-gm-cell { font-size: 10px; }
            .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        }
        @media (max-width: 480px) {
            .podium { display: none; }
            .league-tabs { gap: 4px; }
            .league-tab { padding: 6px 12px; font-size: 11px; }
        }
    input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible,[tabindex]:focus-visible{outline:2px solid var(--red, #fc0025);outline-offset:2px;}
     (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; animation-delay: 0ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; transition-delay: 0ms !important; scroll-behavior: auto !important; } }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>

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
         MAIN CONTENT
    ══════════════════════════════════════════════ -->
    <main class="main">
        <div class="dash-hero">
            <div>
                <div class="dash-eyebrow">Liga · Classificação</div>
                <h1 class="dash-title">Rankings</h1>
                <p class="dash-sub">Acompanhe a pontuação e os títulos da sua liga.</p>
            </div>
            <div class="hero-badges">
                <a href="/hall-da-fama.php" class="hbadge"><i class="bi bi-award"></i> Hall da Fama</a>
                <button class="hbadge" id="btnVerHistorico" data-bs-toggle="modal" data-bs-target="#ptsHistoryModal">
                    <i class="bi bi-clock-history"></i> Ver Histórico
                </button>
                <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
                <button class="hbadge red" id="btnEditRanking" data-bs-toggle="modal" data-bs-target="#editRankingModal">
                    <i class="bi bi-pencil-square"></i> Editar Ranking
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="content">
            <!-- Filtros Minimalistas -->
            <div class="filter-nav" id="rankingFilters">
                <button type="button" class="filter-btn active" data-league="ELITE" onclick="loadRanking('ELITE')">ELITE</button>
                <button type="button" class="filter-btn" data-league="NEXT" onclick="loadRanking('NEXT')">NEXT</button>
                <button type="button" class="filter-btn" data-league="RISE" onclick="loadRanking('RISE')">RISE</button>
                <button type="button" class="filter-btn" data-league="ROOKIE" onclick="loadRanking('ROOKIE')">ROOKIE</button>
                <button type="button" class="wpp-btn" id="btnCopyWpp" onclick="copyRankingWpp()" title="Copia o ranking desta liga em texto, pronto para colar no WhatsApp">
                    <i class="bi bi-whatsapp"></i> <span>Copiar p/ WhatsApp</span>
                </button>
            </div>

            <!-- Fallback: aparece só se o navegador bloquear a cópia automática -->
            <div id="wppManual" style="display:none;margin-bottom:20px">
                <div style="font-size:11.5px;color:var(--text-3);margin-bottom:6px">
                    <i class="bi bi-info-circle"></i> Seu navegador bloqueou a cópia automática. O texto já está selecionado — use Ctrl+C (ou Cmd+C).
                </div>
                <textarea id="wppManualText" readonly rows="8"
                    style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:10px;padding:12px;font-size:12px;font-family:inherit;resize:vertical"></textarea>
            </div>

            <!-- Tabela Container -->
            <div id="rankingContainer">
                <div class="spinner"></div>
            </div>
        </div>
    </main>

    <!-- ══════════════════════════════════════════════
         MODAL DE EDIÇÃO (ADMIN)
    ══════════════════════════════════════════════ -->
    <!-- Modal Histórico de Pontuação -->
    <div class="modal fade" id="ptsHistoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-scrollable">
            <div class="modal-content minimal">
                <div class="modal-header minimal">
                    <h5 class="modal-title"><i class="bi bi-clock-history me-2" style="color:var(--red)"></i>Histórico de Pontuação · <span id="ptsHistoryLeague"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding:0; overflow-y:auto; max-height:70vh;" id="ptsHistoryBody">
                    <div class="text-center py-4"><div class="spinner" style="margin:32px auto"></div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Log do Time -->
    <div class="modal fade" id="teamLogModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-scrollable">
            <div class="modal-content minimal">
                <div class="modal-header minimal">
                    <h5 class="modal-title"><i class="bi bi-journal-text me-2" style="color:var(--red)"></i>Log · <span id="teamLogName"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding:0; overflow-y:auto; max-height:70vh;" id="teamLogBody">
                    <div class="text-center py-4"><div class="spinner" style="margin:32px auto"></div></div>
                </div>
            </div>
        </div>
    </div>

    <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
    <div class="modal fade" id="editRankingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content minimal">
                <div class="modal-header minimal">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2" style="color:var(--red)"></i>Editar Ranking – <span id="editRankingLeague"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding: 0;">
                    <div id="editRankingLoading" class="text-center py-4"><div class="spinner"></div></div>
                    
                    <div class="table-responsive" id="editRankingTableWrap" style="display:none;">
                        <table class="m-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th style="width: 140px; text-align:center">Títulos</th>
                                    <th style="width: 140px; text-align:center">Pontos</th>
                                </tr>
                            </thead>
                            <tbody id="editRankingBody"></tbody>
                        </table>
                    </div>
                    <div id="editRankingEmpty" class="text-center" style="display:none; padding: 40px; color: var(--text-3);">Sem times para esta liga.</div>
                </div>
                <div class="modal-footer minimal">
                    <button type="button" class="btn-minimal secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn-minimal primary" id="btnSaveRanking">Salvar Alterações</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    /* ── Lógica Visual Sidebar / Tema ── */
    const themeToggle = document.getElementById('themeToggle');
    const themeKey = 'fba-theme';
    const applyTheme = (theme) => {
        if (theme === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
            if(themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
        } else {
            document.documentElement.removeAttribute('data-theme');
            if(themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
        }
    };
    applyTheme(localStorage.getItem(themeKey) || 'dark');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            localStorage.setItem(themeKey, next);
            applyTheme(next);
        });
    }

    const sidebar = document.getElementById('sidebar');
    const menuBtn = document.getElementById('menuBtn');
    const sbOverlay = document.getElementById('sbOverlay');
    const closeSidebar = () => { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); };
    if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); sbOverlay.classList.add('show'); });
    if (sbOverlay) sbOverlay.addEventListener('click', closeSidebar);

    /* ── Lógica de Rankings ── */
    let userLeague = "<?= htmlspecialchars($user['league'] ?? 'ELITE') ?>".toUpperCase();
    if (userLeague.includes("?=")) userLeague = "ELITE"; // Fallback para o modo de preview
    
    const currentTeamId = parseInt("<?= (int)($team['id'] ?? 0) ?>", 10) || 0;
    let currentLeague = userLeague;
    let currentRanking = [];
    let comparadoCom = {};

    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    /**
     * Seta de variação de posição. rank_delta positivo = subiu (a posição
     * anterior era um número maior). Time sem referência anterior não mostra
     * nada, em vez de fingir que ficou parado.
     */
    function setaVariacao(team) {
        const d = Number(team.rank_delta || 0);
        const anterior = team.prev_position;
        if (!anterior) return '<span class="rk-var none" title="Sem posição anterior registrada">—</span>';
        if (d > 0) return `<span class="rk-var up" title="Subiu ${d} posição(ões) — antes era ${anterior}º"><i class="bi bi-caret-up-fill"></i>${d}</span>`;
        if (d < 0) return `<span class="rk-var down" title="Caiu ${Math.abs(d)} posição(ões) — antes era ${anterior}º"><i class="bi bi-caret-down-fill"></i>${Math.abs(d)}</span>`;
        return '<span class="rk-var same" title="Manteve a posição">=</span>';
    }
    const currentSeasonId   = <?= $currentSeasonId   ? (int)$currentSeasonId   : 'null' ?>;
    const currentSeasonYear = <?= (int)$currentSeasonYear ?>;

    function updateActiveButton(activeLeague) {
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.league === activeLeague);
        });
    }

    async function loadRanking(league = userLeague) {
        currentLeague = league.toUpperCase();
        updateActiveButton(currentLeague);

        const container = document.getElementById('rankingContainer');
        container.innerHTML = '<div class="spinner"></div>';

        // Esconde o texto manual da liga anterior, que ficaria desatualizado.
        const manualBox = document.getElementById('wppManual');
        if (manualBox) manualBox.style.display = 'none';

        try {
            const response = await fetch(`/api/history-points.php?action=get_ranking&league=${encodeURIComponent(currentLeague)}`);
            const data = await response.json();
            
            if (!data.success) throw new Error(data.error);

            const ranking = data.ranking[currentLeague] || [];
            currentRanking = ranking; // usado pelo "Copiar p/ WhatsApp"
            comparadoCom = data.compared_to || {};

            if (ranking.length === 0) {
                container.innerHTML = `
                    <div style="text-align:center; padding: 40px; color: var(--text-3); background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius);">
                        <i class="bi bi-bar-chart" style="font-size:24px; display:block; margin-bottom:8px"></i>
                        Nenhum dado de ranking disponível ainda para a liga ${currentLeague}.
                    </div>`;
                return;
            }

            // Gerar tabela HTML Minimalista
            const totalTeams = ranking.length;
            let rowsHtml = ranking.map((team, idx) => {
                const isMyTeam = currentTeamId && Number(team.team_id) === currentTeamId;
                const posClass = idx === 0 ? 'gold' : idx === 1 ? 'silver' : idx === 2 ? 'bronze' : '';
                const isElite = currentLeague === 'ELITE';
                const topLimit = isElite ? 1 : 4;
                const bottomLimit = 4;
                const isTop = idx < Math.min(topLimit, totalTeams);
                const isBottom = idx >= Math.max(totalTeams - bottomLimit, 0);
                const rowClass = [
                    isMyTeam ? 'row-me' : '',
                    isTop ? 'row-top' : '',
                    isBottom ? 'row-bottom' : ''
                ].filter(Boolean).join(' ');

                return `
                <tr class="${rowClass}">
                    <td>
                        <div style="display:flex;align-items:center;gap:7px">
                            <div class="rank-pos ${posClass}">${idx + 1}º</div>
                            ${setaVariacao(team)}
                        </div>
                    </td>
                    <td>
                        <span class="team-name-cell">${team.team_name}</span>
                        ${team.owner_name ? `<span class="team-gm-cell">GM: ${team.owner_name}</span>` : ''}
                    </td>
                    <td class="hide-mobile"><span class="league-badge">${team.league}</span></td>
                    <td style="text-align: center; color: var(--text-2); font-weight: 600;">${team.total_titles || 0}</td>
                    <td style="text-align: center; color: var(--red); font-weight: 800; font-size: 15px;">
                        ${team.total_points || 0}
                    </td>
                    <td style="text-align:center">
                        <button class="hbadge" style="padding:4px 8px;font-size:11px;gap:4px"
                            data-bs-toggle="modal" data-bs-target="#teamLogModal"
                            data-team-id="${team.team_id}"
                            data-team-name="${(team.team_name || '').replace(/"/g,'&quot;')}">
                            <i class="bi bi-journal-text"></i>
                        </button>
                    </td>
                </tr>`;
            }).join('');

            const refer = comparadoCom[currentLeague];
            container.innerHTML = `
                <div class="rk-legenda">
                    <span><span class="rk-var up"><i class="bi bi-caret-up-fill"></i>2</span> subiu</span>
                    <span><span class="rk-var down"><i class="bi bi-caret-down-fill"></i>1</span> caiu</span>
                    <span><span class="rk-var same">=</span> manteve</span>
                    <span style="margin-left:auto">${refer
                        ? 'Comparado com o fim da <strong>' + esc(refer) + '</strong>'
                        : 'Comparado com a última pontuação registrada'}</span>
                </div>
                <div class="table-card">
                    <div class="table-responsive">
                        <table class="m-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px; text-align: center;">Pos</th>
                                    <th>Franquia</th>
                                    <th class="hide-mobile" style="width: 100px;">Liga</th>
                                    <th style="width: 100px; text-align: center;"><i class="bi bi-trophy"></i> Títulos</th>
                                    <th style="width: 100px; text-align: center;"><i class="bi bi-star-fill" style="color:var(--amber)"></i> Pontos</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rowsHtml}
                            </tbody>
                        </table>
                    </div>
                </div>`;
        } catch (e) {
            console.error(e);
            container.innerHTML = `<div style="color: #ef4444; padding: 20px; background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); border-radius: 8px;">Erro ao carregar ranking: ${e.message || 'Desconhecido'}</div>`;
        }
    }

    /* ── Copiar ranking para o WhatsApp ── */

    // Monta o texto em si. No WhatsApp *texto* vira negrito.
    function buildRankingWppText() {
        const linhas = [];
        linhas.push(`*RANKING ${currentLeague}* 🏆`);
        linhas.push('');

        currentRanking.forEach((team, idx) => {
            const pos    = idx + 1;
            const medalha = pos === 1 ? '🥇' : pos === 2 ? '🥈' : pos === 3 ? '🥉' : `${pos}º`;
            const pontos  = Number(team.total_points || 0);
            const titulos = Number(team.total_titles || 0);

            let linha = `${medalha} ${team.team_name} — *${pontos}* pts`;
            if (titulos > 0) linha += ` · ${titulos}🏆`;
            linhas.push(linha);
        });

        linhas.push('');
        linhas.push(`_${currentRanking.length} franquias · fbabrasil.com.br_`);
        return linhas.join('\n');
    }

    async function copyRankingWpp() {
        const btn = document.getElementById('btnCopyWpp');
        const label = btn.querySelector('span');
        const icon  = btn.querySelector('i');

        if (!currentRanking.length) {
            alert('Não há ranking carregado para copiar.');
            return;
        }

        const texto = buildRankingWppText();
        let ok = false;
        try {
            // Só existe em contexto seguro (https ou localhost); no resto cai no fallback.
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(texto);
                ok = true;
            }
        } catch (e) { ok = false; }

        if (!ok) {
            const ta = document.createElement('textarea');
            ta.value = texto;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-1000px';
            document.body.appendChild(ta);
            ta.select();
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(ta);
        }

        // Último recurso: alguns navegadores bloqueiam a cópia automática.
        // Em vez de só avisar, mostra o texto pronto e já selecionado.
        const manual = document.getElementById('wppManual');
        const manualTa = document.getElementById('wppManualText');
        if (ok) {
            manual.style.display = 'none';
        } else {
            manualTa.value = texto;
            manual.style.display = 'block';
            manualTa.focus();
            manualTa.select();
        }

        const iconOriginal  = icon.className;
        const labelOriginal = label.textContent;
        btn.classList.toggle('copied', ok);
        icon.className  = ok ? 'bi bi-check-lg' : 'bi bi-clipboard';
        label.textContent = ok ? 'Copiado!' : 'Copie abaixo';
        setTimeout(() => {
            btn.classList.remove('copied');
            icon.className = iconOriginal;
            label.textContent = labelOriginal;
        }, 2000);
    }

    // Load initial
    document.addEventListener('DOMContentLoaded', () => loadRanking(userLeague));

    /* ── Histórico de Pontuação ── */
    const ptsHistoryModal = document.getElementById('ptsHistoryModal');
    const ptsHistoryBody  = document.getElementById('ptsHistoryBody');
    const ptsHistoryLeagueEl = document.getElementById('ptsHistoryLeague');

    ptsHistoryModal?.addEventListener('show.bs.modal', () => loadPtsHistory(currentLeague));

    async function loadPtsHistory(league) {
        ptsHistoryLeagueEl.textContent = league;
        ptsHistoryBody.innerHTML = '<div class="text-center py-4"><div class="spinner" style="margin:32px auto"></div></div>';
        try {
            const resp = await fetch(`/api/history-points.php?action=get_points_history&league=${encodeURIComponent(league)}`);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Falha ao carregar');

            const seasons = data.seasons || [];
            const filtered = seasons.filter(s => {
                if (currentSeasonId && parseInt(s.season_id || 0, 10) === currentSeasonId) return false;
                if (!currentSeasonId && currentSeasonYear && s.year && parseInt(s.year, 10) >= currentSeasonYear) return false;
                return true;
            });
            if (!filtered.length) {
                ptsHistoryBody.innerHTML = '<div class="pts-empty"><i class="bi bi-inbox" style="font-size:28px;display:block;margin-bottom:8px"></i>Nenhuma pontuação registrada ainda.</div>';
                return;
            }

            let html = '';
            filtered.forEach((s, si) => {
                const sprintLabel = s.sprint_number ? `Sprint ${s.sprint_number}` : '';
                const tempLabel   = s.season_number ? `Temp ${s.season_number}` : '';
                const yearLabel   = s.year ? ` · ${s.year}` : '';
                const title       = [sprintLabel, tempLabel].filter(Boolean).join(' · ') + yearLabel || `Temporada ${si + 1}`;

                const maxPts = Math.max(...s.teams.map(t => t.points), 1);

                const rows = s.teams.map((t, ti) => {
                    const rankCls = ti === 0 ? 'g' : ti === 1 ? 's' : ti === 2 ? 'b' : '';
                    const barW    = Math.round((t.points / maxPts) * 100);
                    const hasBkd  = (t.points_regular || 0) + (t.points_playoffs || 0) + (t.points_prizes || 0) > 0;
                    const bkd     = hasBkd ? `<div style="display:flex;gap:4px;flex-shrink:0;align-items:center">
                        <span title="Regular" style="font-size:9px;background:rgba(99,102,241,.15);color:#818cf8;border-radius:4px;padding:2px 6px;font-weight:700">R${t.points_regular||0}</span>
                        <span title="Playoffs" style="font-size:9px;background:color-mix(in srgb, var(--red) 12%, transparent);color:var(--red);border-radius:4px;padding:2px 6px;font-weight:700">PO${t.points_playoffs||0}</span>
                        <span title="Prêmios" style="font-size:9px;background:rgba(245,158,11,.12);color:#f59e0b;border-radius:4px;padding:2px 6px;font-weight:700">Pr${t.points_prizes||0}</span>
                    </div>` : '';
                    return `
                    <div class="pts-row">
                        <div class="pts-rank ${rankCls}">${ti + 1}</div>
                        <div class="pts-team">${t.team_name}</div>
                        ${bkd}
                        <div class="pts-bar-wrap"><div class="pts-bar" style="width:${barW}%"></div></div>
                        <div class="pts-val">${t.points}</div>
                    </div>`;
                }).join('');

                html += `
                <div class="pts-season">
                    <div class="pts-season-head">
                        <span class="pts-season-label">🏆 ${title}</span>
                        <span class="pts-season-count">${s.teams.length} times</span>
                    </div>
                    ${rows}
                </div>`;
            });
            ptsHistoryBody.innerHTML = html;
        } catch (e) {
            ptsHistoryBody.innerHTML = `<div class="pts-empty" style="color:#ef4444"><i class="bi bi-exclamation-circle" style="font-size:24px;display:block;margin-bottom:8px"></i>${e.message}</div>`;
        }
    }

    /* ── Log do Time ── */
    const teamLogModal  = document.getElementById('teamLogModal');
    const teamLogBody   = document.getElementById('teamLogBody');
    const teamLogNameEl = document.getElementById('teamLogName');

    teamLogModal?.addEventListener('show.bs.modal', (e) => {
        const btn = e.relatedTarget;
        if (!btn) return;
        loadTeamLog(parseInt(btn.dataset.teamId, 10), btn.dataset.teamName || '');
    });

    async function loadTeamLog(teamId, teamName) {
        teamLogNameEl.textContent = teamName;
        teamLogBody.innerHTML = '<div class="text-center py-4"><div class="spinner" style="margin:32px auto"></div></div>';
        try {
            const resp = await fetch(`/api/history-points.php?action=get_team_season_log&team_id=${teamId}`);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Falha ao carregar');

            const seasons = data.seasons || [];
            const filtered = seasons.filter(s => {
                if (currentSeasonId && parseInt(s.season_id || 0, 10) === currentSeasonId) return false;
                if (!currentSeasonId && currentSeasonYear && s.year && parseInt(s.year, 10) >= currentSeasonYear) return false;
                return true;
            });
            if (!filtered.length) {
                teamLogBody.innerHTML = '<div class="pts-empty"><i class="bi bi-inbox" style="font-size:28px;display:block;margin-bottom:8px"></i>Nenhuma temporada encontrada.</div>';
                return;
            }

            const total = filtered.reduce((acc, s) => acc + (s.points || 0), 0);
            let html = `<div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
                <span style="font-size:12px;color:var(--text-3)">Total acumulado</span>
                <span style="font-weight:800;color:var(--red);font-size:16px">${total} pts</span>
            </div>`;

            filtered.forEach((s) => {
                const sprintLabel = s.sprint_number ? `Sprint ${s.sprint_number}` : '';
                const tempLabel   = s.season_number ? `Temp ${s.season_number}` : '';
                const yearLabel   = s.year ? ` · ${s.year}` : '';
                const title   = [sprintLabel, tempLabel].filter(Boolean).join(' · ') + yearLabel || 'Temporada';
                const hasBkd2 = (s.points_regular || 0) + (s.points_playoffs || 0) + (s.points_prizes || 0) > 0;
                html += `
                <div class="pts-row" style="flex-wrap:wrap;gap:6px">
                    <div class="pts-team" style="flex:1">${title}</div>
                    ${hasBkd2 ? `<div style="display:flex;gap:4px;flex-shrink:0;align-items:center">
                        <span title="Regular" style="font-size:9px;background:rgba(99,102,241,.15);color:#818cf8;border-radius:4px;padding:2px 6px;font-weight:700">R${s.points_regular||0}</span>
                        <span title="Playoffs" style="font-size:9px;background:color-mix(in srgb, var(--red) 12%, transparent);color:var(--red);border-radius:4px;padding:2px 6px;font-weight:700">PO${s.points_playoffs||0}</span>
                        <span title="Prêmios" style="font-size:9px;background:rgba(245,158,11,.12);color:#f59e0b;border-radius:4px;padding:2px 6px;font-weight:700">Pr${s.points_prizes||0}</span>
                    </div>` : ''}
                    <div class="pts-val" style="color:var(--red);font-weight:800">${s.points} pts</div>
                </div>`;
            });
            teamLogBody.innerHTML = html;
        } catch (e) {
            teamLogBody.innerHTML = `<div class="pts-empty" style="color:#ef4444"><i class="bi bi-exclamation-circle" style="font-size:24px;display:block;margin-bottom:8px"></i>${e.message}</div>`;
        }
    }

    /* ── Editor Admin ── */
    <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
    const editModal = document.getElementById('editRankingModal');
    const editLeagueEl = document.getElementById('editRankingLeague');
    const editLoading = document.getElementById('editRankingLoading');
    const editWrap = document.getElementById('editRankingTableWrap');
    const editBody = document.getElementById('editRankingBody');
    const editEmpty = document.getElementById('editRankingEmpty');
    const btnSaveRanking = document.getElementById('btnSaveRanking');

    editModal?.addEventListener('show.bs.modal', async () => {
        editLeagueEl.textContent = currentLeague;
        editLoading.style.display = 'block';
        editWrap.style.display = 'none';
        editEmpty.style.display = 'none';
        editBody.innerHTML = '';

        try {
            const resp = await fetch(`/api/history-points.php?action=get_ranking&league=${encodeURIComponent(currentLeague)}`);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Falha ao carregar ranking');
            
            const rows = data.ranking[currentLeague] || [];
            if (!rows.length) {
                editEmpty.style.display = 'block';
                return;
            }
            
            rows.forEach(row => {
                editBody.innerHTML += `
                <tr data-team-id="${row.team_id}">
                    <td style="font-weight: 600; font-size: 13px;">${row.team_name}</td>
                    <td><input type="number" class="minimal-input js-edit-titles" value="${row.total_titles || 0}" min="0"></td>
                    <td><input type="number" class="minimal-input js-edit-points" value="${row.total_points || 0}" min="0"></td>
                </tr>`;
            });
            editWrap.style.display = 'block';
        } catch (e) {
            editEmpty.textContent = 'Erro ao carregar ranking para edição.';
            editEmpty.style.display = 'block';
        } finally {
            editLoading.style.display = 'none';
        }
    });

    btnSaveRanking?.addEventListener('click', async () => {
        const rows = Array.from(editBody.querySelectorAll('tr[data-team-id]'));
        const team_points = rows.map(tr => ({
            team_id: parseInt(tr.getAttribute('data-team-id'), 10),
            titles: parseInt(tr.querySelector('.js-edit-titles')?.value || '0', 10),
            points: parseInt(tr.querySelector('.js-edit-points')?.value || '0', 10)
        }));
        
        btnSaveRanking.disabled = true;
        btnSaveRanking.textContent = 'Salvando...';
        
        try {
            const resp = await fetch('/api/history-points.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'save_ranking_totals', league: currentLeague, team_points })
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Falha ao salvar');

            bootstrap.Modal.getInstance(editModal)?.hide();
            loadRanking(currentLeague);
        } catch (e) {
            alert(e.message || 'Erro ao salvar');
        } finally {
            btnSaveRanking.disabled = false;
            btnSaveRanking.textContent = 'Salvar Alterações';
        }
    });
    <?php endif; ?>
</script>
</body>
</html>