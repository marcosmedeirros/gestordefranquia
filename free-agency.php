<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user    = getUserSession();
$pdo     = db();
$is_admin = ($user['user_type'] ?? 'jogador') === 'admin';
$team_league = $user['league'];

// ── Temporada ────────────────────────────────────────────
$currentSeasonYear  = null;
$currentSeason      = null;
$seasonDisplayYear  = null;
try {
    $stmtSeason = $pdo->prepare('
        SELECT s.season_number, s.year, sp.start_year, sp.sprint_number
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN (\'completed\'))
        ORDER BY s.created_at DESC
        LIMIT 1
    ');
    $stmtSeason->execute([$user['league']]);
    $season = $stmtSeason->fetch(PDO::FETCH_ASSOC);
    if ($season) {
        if (isset($season['start_year'], $season['season_number'])) {
            $currentSeasonYear = (int)$season['start_year'] + (int)$season['season_number'] - 1;
        } elseif (isset($season['year'])) {
            $currentSeasonYear = (int)$season['year'];
        }
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
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);

$team_id           = $team ? (int)$team['id']                : null;
$team_name         = $team ? ($team['name']    ?? '')         : '';
$team_moedas       = $team ? (int)($team['moedas'] ?? 0)      : 0;
$team_roster_count = $team ? (int)($team['player_count'] ?? 0): 0;

// Propostas pendentes
$team_pending_offers = 0;
if ($team_id) {
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM free_agent_offers WHERE team_id = ? AND status = 'pending'");
    $stmtPending->execute([$team_id]);
    $team_pending_offers = (int)$stmtPending->fetchColumn();
}

// ── Ligas (admin) ─────────────────────────────────────────
$league_id    = $_SESSION['current_league_id'] ?? null;
$leagues      = [];
$leagues_admin= [];
if ($is_admin) {
    $stmt = $pdo->query("SELECT id, name FROM leagues ORDER BY name");
    $leagues_admin = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $leagues = array_map(static fn($l) => $l['name'], $leagues_admin);
    if (!$leagues) {
        $leagues       = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
        $leagues_admin = [];
    }
}
$default_admin_league = $team_league ?? ($leagues[0] ?? 'ELITE');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Free Agency - FBA Manager</title>

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
            height: 100%;
            background: var(--bg);
            color: var(--text);
            font-family: var(--font);
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
            margin: 14px 14px 0; background: var(--panel-2);
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-team-name  { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
        .sb-team-league{ font-size: 11px; color: var(--red); font-weight: 600; }
        .sb-season {
            margin: 10px 14px 0; background: var(--red-soft);
            border: 1px solid var(--border-red); border-radius: 8px;
            padding: 8px 12px; display: flex; align-items: center;
            justify-content: space-between; flex-shrink: 0;
        }
        .sb-season-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); }
        .sb-season-val   { font-size: 14px; font-weight: 700; color: var(--red); }
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
        .sb-nav a {
            display: flex; align-items: center; gap: 10px; padding: 9px 10px;
            border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px;
            font-weight: 500; text-decoration: none; margin-bottom: 2px;
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

        /* ── Topbar (mobile) ───────────────────────────── */
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
            color: var(--text); display: flex; align-items: center;
            justify-content: center; cursor: pointer; font-size: 17px;
        }
        .sb-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; display: none; }
        .sb-overlay.show { display: block; }

        /* ── Main ──────────────────────────────────────── */
        .main {
            margin-left: var(--sidebar-w);
            width: calc(100% - var(--sidebar-w));
            padding: 32px 40px 60px;
        }

        .page-top  { display: flex; align-items: flex-end; justify-content: space-between; gap: 18px; margin-bottom: 22px; }
        .page-eyebrow { font-size: 12px; letter-spacing: .2em; text-transform: uppercase; color: var(--text-3); margin-bottom: 8px; }
        .page-title   { font-size: 32px; font-family: var(--font); margin-bottom: 6px; }
        .page-sub     { color: var(--text-2); font-size: 14px; }

        /* ── Stats strip ───────────────────────────────── */
        .stats-strip { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 14px; margin-bottom: 26px; }
        .stat-pill {
            background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 16px 18px;
            display: flex; gap: 12px; align-items: center;
        }
        .stat-pill-icon {
            width: 42px; height: 42px; border-radius: 12px;
            background: var(--panel-2); border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center; color: var(--red);
        }
        .stat-pill-val   { font-weight: 700; font-size: 18px; font-family: var(--font); }
        .stat-pill-label { color: var(--text-2); font-size: 12px; }

        /* ── Tab nav ───────────────────────────────────── */
        .tab-nav { display: flex; gap: 6px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab-btn {
            background: var(--panel); border: 1px solid var(--border);
            color: var(--text-2); font-size: 13px; font-weight: 500;
            padding: 8px 16px; border-radius: 999px; cursor: pointer;
            transition: all var(--t) var(--ease);
            display: flex; align-items: center; gap: 6px;
        }
        .tab-btn:hover { background: var(--panel-2); color: var(--text); }
        .tab-btn.active { background: var(--red-soft); border-color: var(--border-red); color: var(--red); font-weight: 600; }

        /* ── Panel ─────────────────────────────────────── */
        .panel {
            background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 20px 22px;
        }
        .panel + .panel { margin-top: 16px; }
        .panel-header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; margin-bottom: 18px; flex-wrap: wrap;
        }
        .panel-title { font-family: var(--font); font-size: 16px; font-weight: 600; }
        .panel-sub   { color: var(--text-2); font-size: 12px; margin-top: 2px; }

        /* ── Fields ────────────────────────────────────── */
        .field { display: flex; flex-direction: column; gap: 6px; }
        .field label { font-size: 11px; color: var(--text-2); letter-spacing: .1em; text-transform: uppercase; font-weight: 600; }
        .field input,
        .field select,
        .field textarea {
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: 10px; padding: 10px 12px; color: var(--text);
            font-size: 14px; font-family: var(--font);
            transition: border-color var(--t) var(--ease);
        }
        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            outline: none; border-color: var(--border-red);
            box-shadow: 0 0 0 3px var(--red-soft);
        }
        .field input::placeholder { color: var(--text-3); }
        .field select option { background: var(--panel-2); }
        .field textarea { resize: vertical; }

        /* ── Form grid ─────────────────────────────────── */
        .fgrid { display: grid; gap: 14px; grid-template-columns: repeat(12, minmax(0,1fr)); }

        /* ── Buttons ───────────────────────────────────── */
        .btn-red {
            background: var(--red); color: #fff; border: none; border-radius: 10px;
            padding: 10px 20px; font-weight: 700; font-size: 14px; font-family: var(--font);
            cursor: pointer; transition: transform var(--t) var(--ease), box-shadow var(--t) var(--ease);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-red:hover    { transform: translateY(-1px); box-shadow: 0 10px 20px var(--red-glow); }
        .btn-red:disabled { opacity: .45; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-ghost {
            background: transparent; border: 1px solid var(--border); color: var(--text-2);
            border-radius: 10px; padding: 8px 14px; font-size: 13px; font-weight: 500;
            font-family: var(--font); cursor: pointer; transition: all var(--t) var(--ease);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); }
        .btn-ghost-red {
            background: var(--red-soft); border: 1px solid var(--border-red); color: var(--red);
            border-radius: 10px; padding: 8px 16px; font-size: 13px; font-weight: 600;
            font-family: var(--font); cursor: pointer; transition: all var(--t) var(--ease);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-ghost-red:hover { background: rgba(252,0,37,.18); }

        /* ── Hint box ──────────────────────────────────── */
        .hint-box {
            background: var(--red-soft); border: 1px solid var(--border-red);
            border-radius: 10px; padding: 10px 14px;
        }
        .hint-box p { font-size: 12px; color: var(--text-2); margin: 0; line-height: 1.5; }
        .hint-box p strong { color: var(--red); }

        /* ── Count badge ───────────────────────────────── */
        .cbadge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 22px; height: 22px; border-radius: 999px; font-size: 11px;
            font-weight: 700; background: var(--panel-3); color: var(--text-2); padding: 0 6px;
        }
        .cbadge.red { background: var(--red-soft); color: var(--red); }

        /* ── Divider ───────────────────────────────────── */
        .divider { border: none; border-top: 1px solid var(--border); margin: 18px 0; }

        /* ── Empty state ───────────────────────────────── */
        .empty-state { text-align: center; color: var(--text-2); padding: 32px 0; font-size: 14px; }

        /* ── Admin select ──────────────────────────────── */
        .admin-sel { display: flex; align-items: center; gap: 8px; }
        .admin-sel label { font-size: 12px; color: var(--text-2); white-space: nowrap; }
        .admin-sel select {
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: 8px; padding: 6px 10px; color: var(--text);
            font-size: 13px; font-family: var(--font);
        }

        /* ── Modal overrides ───────────────────────────── */
        .modal-content { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); }
        .modal-header  { border-bottom: 1px solid var(--border); padding: 16px 20px; }
        .modal-footer  { border-top:    1px solid var(--border); padding: 14px 20px; }
        .modal-title   { font-size: 16px; font-weight: 600; font-family: var(--font); }
        .modal-body    { padding: 20px; }

        /* ── Compat: classes usadas pelo JS gerado dinamicamente ── */
        .text-orange     { color: var(--red) !important; }
        .text-light-gray { color: var(--text-2) !important; }
        .text-muted      { color: var(--text-3) !important; }

        /* Bootstrap table-dark → tokens do tema */
        .table-dark {
            --bs-table-bg:          var(--panel-2);
            --bs-table-striped-bg:  var(--panel-3);
            --bs-table-hover-bg:    var(--panel-3);
            --bs-table-border-color:var(--border);
            --bs-table-color:       var(--text);
            color: var(--text);
        }
        .table-dark thead th {
            color: var(--text-3);
            font-size: 11px;
            letter-spacing: .15em;
            text-transform: uppercase;
            border-bottom-color: var(--border);
        }
        .table-dark tbody td { border-bottom-color: var(--border); }
        .table-responsive { overflow-x: auto; }

        /* Bootstrap card usado no JS do admin */
        .card.bg-dark {
            background: var(--panel-2) !important;
            border-color: var(--border-md) !important;
            color: var(--text) !important;
            border-radius: var(--radius-sm) !important;
        }
        .card-header.bg-dark {
            background: var(--panel-3) !important;
            border-color: var(--border) !important;
        }
        .border-secondary { border-color: var(--border-md) !important; }

        /* Selects Bootstrap dentro de conteúdo gerado pelo JS */
        .form-select, .form-control {
            background-color: var(--panel-2);
            border-color: var(--border);
            color: var(--text);
        }
        .form-select:focus, .form-control:focus {
            background-color: var(--panel-2);
            border-color: var(--border-red);
            color: var(--text);
            box-shadow: 0 0 0 3px var(--red-soft);
        }
        .form-select option { background: var(--panel-2); }

        /* Spinner de carregamento */
        .spinner-border { color: var(--red) !important; }

        /* ── Legacy hidden ─────────────────────────────── */
        .legacy-fa      { display: none !important; }
        .fa-admin-inline{ display: none; }

        /* ── Responsive ─────────────────────────────────  */
        @media (max-width: 992px) {
            .stats-strip { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .topbar { display: flex; }
            .main { margin-left: 0; width: 100%; padding: 54px 16px 40px; }
            .fgrid { grid-template-columns: 1fr 1fr; }
            .fgrid > * { grid-column: auto !important; }
        }
        @media (max-width: 560px) {
            .stats-strip { grid-template-columns: 1fr; }
            .fgrid { grid-template-columns: 1fr; }
            .fgrid > * { grid-column: 1 !important; }
            .page-title { font-size: 24px; }
            .tab-btn { font-size: 12px; padding: 7px 12px; }
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
            <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
            <a href="/players.php"><i class="bi bi-person-lines-fill"></i> Jogadores</a>
            <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
            <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
            <a href="/free-agency.php" class="active"><i class="bi bi-coin"></i> Free Agency</a>
            <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
            <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>

            <div class="sb-section">Liga</div>
            <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
            <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
            <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
            <a href="/mundo-fba.php"><i class="bi bi-globe2"></i> Mundo FBA</a>
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

    <!-- ══════════════════════════════════════
         MAIN
    ══════════════════════════════════════ -->
    <main class="main">

        <!-- Page header -->
        <div class="page-top">
            <div>
                <div class="page-eyebrow">Liga &mdash; <?= $currentSeasonYear ?></div>
                <h1 class="page-title">Free Agency</h1>
                <p class="page-sub">Envie lances para contratar jogadores dispensados</p>
            </div>
            <?php if ($is_admin): ?>
            <button class="btn-ghost-red" type="button" id="faViewApprovedBtn">
                <i class="bi bi-inbox-fill"></i> Quem está ganhando
            </button>
            <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="stats-strip">
            <div class="stat-pill">
                <div class="stat-pill-icon"><i class="bi bi-coin"></i></div>
                <div>
                    <div class="stat-pill-val"><?= number_format($team_moedas, 0, ',', '.') ?></div>
                    <div class="stat-pill-label">Moedas disponíveis</div>
                </div>
            </div>
            <div class="stat-pill">
                <div class="stat-pill-icon"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="stat-pill-val" id="statPendingOffers"><?= $team_pending_offers ?></div>
                    <div class="stat-pill-label">Propostas pendentes</div>
                </div>
            </div>
            <div class="stat-pill">
                <div class="stat-pill-icon"><i class="bi bi-person-badge"></i></div>
                <div>
                    <div class="stat-pill-val"><?= $team_roster_count ?><span style="font-size:13px;font-weight:400;color:var(--text-3);">/15</span></div>
                    <div class="stat-pill-label">Jogadores no elenco</div>
                </div>
            </div>
        </div>

        <!-- Tab nav -->
        <div class="tab-nav" id="freeAgencyTabs" role="tablist">
            <button class="tab-btn active" id="fa-players-tab"
                    data-bs-toggle="tab" data-bs-target="#fa-players" type="button" role="tab">
                <i class="bi bi-people-fill"></i> Free Agency
            </button>
            <!-- legacy hidden -->
            <button class="tab-btn legacy-fa" id="fa-active-auctions-tab"
                    data-bs-toggle="tab" data-bs-target="#fa-active-auctions" type="button" role="tab">
                <i class="bi bi-hammer"></i> Leilões ativos
            </button>
            <button class="tab-btn" id="fa-history-tab"
                    data-bs-toggle="tab" data-bs-target="#fa-history" type="button" role="tab">
                <i class="bi bi-clock-history"></i> Histórico
            </button>
            <?php if ($is_admin): ?>
            <!-- legacy hidden -->
            <button class="tab-btn legacy-fa" id="fa-auction-admin-tab"
                    data-bs-toggle="tab" data-bs-target="#fa-auction-admin" type="button" role="tab">
                <i class="bi bi-hammer"></i> Leilão Admin
            </button>
            <button class="tab-btn" id="fa-admin-tab"
                    data-bs-toggle="tab" data-bs-target="#fa-admin" type="button" role="tab">
                <i class="bi bi-shield-lock-fill"></i> FA Admin
            </button>
            <?php endif; ?>
        </div>

        <!-- ══════════════════════════════════
             TAB CONTENT
        ══════════════════════════════════ -->
        <div id="freeAgencyTabsContent" class="tab-content">

            <!-- ─── Tab: Free Agency ───────────────────── -->
            <div class="tab-pane fade show active" id="fa-players" role="tabpanel">

                <!-- Nova proposta -->
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <div class="panel-title">Nova Proposta</div>
                            <div class="panel-sub">Preencha os dados e envie seu lance</div>
                        </div>
                    </div>

                    <form id="faNewRequestForm">
                        <div class="fgrid">
                            <div class="field" style="grid-column: span 5;">
                                <label for="faNewPlayerName">Nome do jogador</label>
                                <input type="text" id="faNewPlayerName" placeholder="Ex: LeBron James" required>
                            </div>
                            <div class="field" style="grid-column: span 2;">
                                <label for="faNewPosition">Posição</label>
                                <select id="faNewPosition">
                                    <option value="PG">PG</option>
                                    <option value="SG">SG</option>
                                    <option value="SF">SF</option>
                                    <option value="PF">PF</option>
                                    <option value="C">C</option>
                                </select>
                            </div>
                            <div class="field" style="grid-column: span 2;">
                                <label for="faNewSecondary">Pos. Secundária</label>
                                <input type="text" id="faNewSecondary" placeholder="Opcional">
                            </div>
                            <div class="field" style="grid-column: span 1;">
                                <label for="faNewAge">Idade</label>
                                <input type="number" id="faNewAge" value="24" min="18" max="45">
                            </div>
                            <div class="field" style="grid-column: span 1;">
                                <label for="faNewOvr">OVR</label>
                                <input type="number" id="faNewOvr" value="70" min="40" max="99">
                            </div>
                            <div class="field" style="grid-column: span 1;">
                                <label for="faNewOffer">Moedas</label>
                                <input type="number" id="faNewOffer" value="1" min="1">
                            </div>
                            <div style="grid-column: span 9;">
                                <div class="hint-box">
                                    <p><strong>Atenção:</strong> Informe o nome exatamente como aparece no vídeo (ex: LeBron James, não L. James). Se o jogador já existir na FA, sua proposta será agrupada com as demais.</p>
                                </div>
                            </div>
                            <div style="grid-column: span 3; align-self: center;">
                                <button type="submit" class="btn-red" id="faNewSubmitBtn" style="width:100%;justify-content:center;padding:11px 20px;">
                                    <i class="bi bi-send"></i> Enviar proposta
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Minhas propostas -->
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title" style="display:flex;align-items:center;gap:8px;">
                            Minhas Propostas
                            <span class="cbadge" id="faNewMyCount">0</span>
                        </div>
                    </div>
                    <div id="faNewMyRequests">
                        <p class="empty-state">Carregando...</p>
                    </div>
                </div>

                <!-- Admin inline: quem está ganhando -->
                <div class="panel fa-admin-inline">
                    <div class="panel-header">
                        <div class="panel-title">Quem está ganhando</div>
                    </div>
                    <div id="faApprovedInline">
                        <p class="empty-state">Carregando...</p>
                    </div>
                </div>

                <!-- Elementos legados (ocultos) necessários para o JS não quebrar -->
                <div class="legacy-fa" aria-hidden="true">
                    <input type="text" id="faSearchInput">
                    <select id="faPositionFilter"></select>
                    <div id="freeAgentsContainer"></div>
                </div>

            </div><!-- /fa-players -->

            <!-- ─── Tab: Leilões ativos (legacy hidden) ── -->
            <div class="tab-pane fade legacy-fa" id="fa-active-auctions" role="tabpanel">
                <div class="panel">
                    <div class="panel-title">Leilões Ativos</div>
                    <div id="leiloesAtivosContainer" style="margin-top:14px;"><p class="empty-state">Carregando...</p></div>
                </div>
                <?php if ($team_id): ?>
                <div class="panel">
                    <div class="panel-title">Propostas Recebidas</div>
                    <div id="propostasRecebidasContainer" style="margin-top:14px;"><p class="empty-state">Carregando...</p></div>
                </div>
                <?php endif; ?>
                <div class="panel">
                    <div class="panel-title">Histórico de Leilões</div>
                    <div id="leiloesHistoricoContainer" style="margin-top:14px;"><p class="empty-state">Carregando...</p></div>
                </div>
            </div>

            <!-- ─── Tab: Histórico FA ──────────────────── -->
            <div class="tab-pane fade" id="fa-history" role="tabpanel">

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Histórico de Contratações</div>
                        <div class="admin-sel">
                            <select id="faHistorySeasonFilter">
                                <option value="">Todas as temporadas</option>
                            </select>
                        </div>
                    </div>
                    <div id="faHistoryContainer">
                        <p class="empty-state">Carregando...</p>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Dispensados Recentes</div>
                        <div class="d-flex gap-2 flex-wrap">
                            <select id="faWaiversSeasonFilter" style="background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:6px 10px;color:var(--text);font-size:13px;font-family:var(--font);">
                                <option value="">Todas temporadas</option>
                            </select>
                            <select id="faWaiversTeamFilter" style="background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:6px 10px;color:var(--text);font-size:13px;font-family:var(--font);">
                                <option value="">Todos os times</option>
                            </select>
                        </div>
                    </div>
                    <div id="faWaiversContainer">
                        <p class="empty-state">Carregando...</p>
                    </div>
                </div>

            </div><!-- /fa-history -->

            <?php if ($is_admin): ?>

            <!-- ─── Tab: Leilão Admin (legacy hidden) ──── -->
            <div class="tab-pane fade legacy-fa" id="fa-auction-admin" role="tabpanel">
                <div class="panel">
                    <div class="panel-title" style="margin-bottom:18px;">Leilão Admin</div>
                    <div class="fgrid" style="margin-bottom:16px;">
                        <div class="field" style="grid-column: span 3;">
                            <label for="selectLeague">Liga</label>
                            <select id="selectLeague">
                                <option value="">Selecione...</option>
                                <?php foreach ($leagues_admin as $league): ?>
                                    <option value="<?= (int)$league['id'] ?>" data-league-name="<?= htmlspecialchars($league['name']) ?>">
                                        <?= htmlspecialchars($league['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="grid-column: span 7; display:flex; align-items:center; gap:24px; padding-top:22px;">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="auctionMode" id="auctionModeSearch" value="search" checked>
                                <label class="form-check-label" for="auctionModeSearch" style="color:var(--text);font-size:13px;">Buscar jogador</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="auctionMode" id="auctionModeCreate" value="create">
                                <label class="form-check-label" for="auctionModeCreate" style="color:var(--text);font-size:13px;">Criar jogador</label>
                            </div>
                        </div>
                        <div style="grid-column: span 2; align-self: end;">
                            <button id="btnCadastrarLeilao" class="btn-red" disabled style="width:100%;justify-content:center;padding:11px;">
                                <i class="bi bi-play-fill"></i> Iniciar 20min
                            </button>
                        </div>
                    </div>
                    <hr class="divider">
                    <div id="auctionSearchArea">
                        <div class="fgrid">
                            <div class="field" style="grid-column: span 6;">
                                <label for="auctionPlayerSearch">Buscar jogador</label>
                                <input type="text" id="auctionPlayerSearch" placeholder="Digite o nome">
                            </div>
                            <div style="grid-column: span 2; align-self: end;">
                                <button class="btn-ghost" id="auctionSearchBtn" style="width:100%;justify-content:center;padding:11px;">
                                    <i class="bi bi-search"></i> Buscar
                                </button>
                            </div>
                        </div>
                        <div id="auctionPlayerResults" style="display:none;margin-top:10px;"></div>
                        <div id="auctionSelectedLabel" style="display:none;margin-top:8px;font-size:13px;color:var(--text-2);"></div>
                        <input type="hidden" id="auctionSelectedPlayerId">
                        <input type="hidden" id="auctionSelectedTeamId">
                    </div>
                    <div id="auctionCreateArea" style="display:none;">
                        <p style="font-size:12px;color:var(--text-2);margin-bottom:12px;">O jogador será criado no leilão e não precisa selecionar time.</p>
                        <div class="fgrid">
                            <div class="field" style="grid-column: span 4;">
                                <label for="auctionPlayerName">Nome</label>
                                <input type="text" id="auctionPlayerName" placeholder="Nome do jogador">
                            </div>
                            <div class="field" style="grid-column: span 2;">
                                <label for="auctionPlayerPosition">Posição</label>
                                <select id="auctionPlayerPosition">
                                    <option value="PG">PG</option>
                                    <option value="SG">SG</option>
                                    <option value="SF">SF</option>
                                    <option value="PF">PF</option>
                                    <option value="C">C</option>
                                </select>
                            </div>
                            <div class="field" style="grid-column: span 1;">
                                <label for="auctionPlayerAge">Idade</label>
                                <input type="number" id="auctionPlayerAge" value="25">
                            </div>
                            <div class="field" style="grid-column: span 1;">
                                <label for="auctionPlayerOvr">OVR</label>
                                <input type="number" id="auctionPlayerOvr" value="70">
                            </div>
                            <div style="grid-column: span 2; align-self: end;">
                                <button class="btn-ghost" type="button" id="btnCriarJogadorLeilao" style="width:100%;justify-content:center;padding:11px;">
                                    <i class="bi bi-plus-circle"></i> Criar
                                </button>
                            </div>
                        </div>
                        <div style="margin-top:16px;">
                            <p style="font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:8px;text-transform:uppercase;letter-spacing:.1em;">Jogadores criados (sem time)</p>
                            <div id="auctionTempList"><p style="color:var(--text-3);font-size:13px;">Nenhum jogador criado.</p></div>
                        </div>
                    </div>
                    <div id="adminLeiloesContainer" style="margin-top:16px;">
                        <p class="empty-state">Carregando...</p>
                    </div>
                </div>
            </div>

            <!-- ─── Tab: FA Admin ─────────────────────── -->
            <div class="tab-pane fade" id="fa-admin" role="tabpanel">

                <!-- Solicitações FA -->
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Solicitações Free Agency</div>
                        <div class="admin-sel">
                            <label for="faNewAdminLeague">Liga</label>
                            <select id="faNewAdminLeague">
                                <option value="ALL">Todas</option>
                                <?php foreach ($leagues_admin as $league): ?>
                                    <option value="<?= htmlspecialchars($league['name']) ?>" <?= $league['name'] === $default_admin_league ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($league['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div id="faNewAdminRequests">
                        <p class="empty-state">Carregando...</p>
                    </div>
                </div>

                <!-- Propostas pendentes (legacy) -->
                <div class="panel legacy-fa">
                    <div class="panel-header">
                        <div class="panel-title">Propostas Pendentes</div>
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div class="admin-sel">
                                <label for="adminLeagueSelect">Liga</label>
                                <select id="adminLeagueSelect" onchange="onAdminLeagueChange()">
                                    <option value="ALL">Todas</option>
                                    <?php foreach ($leagues_admin as $league): ?>
                                        <option value="<?= htmlspecialchars($league['name']) ?>"
                                                data-league-id="<?= (int)$league['id'] ?>"
                                                <?= $league['name'] === $default_admin_league ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($league['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-check form-switch" style="margin:0;">
                                <input class="form-check-input" type="checkbox" role="switch" id="faStatusToggle">
                                <label class="form-check-label" for="faStatusToggle" style="font-size:12px;color:var(--text-2);">Propostas</label>
                            </div>
                            <span id="faStatusBadge" class="cbadge">-</span>
                        </div>
                    </div>
                    <div id="adminOffersContainer">
                        <p class="empty-state">Carregando...</p>
                    </div>
                </div>

                <!-- Adicionar Free Agent (legacy) -->
                <div class="panel legacy-fa">
                    <div class="panel-header">
                        <div class="panel-title">Adicionar Free Agent</div>
                    </div>
                    <div class="fgrid">
                        <div class="field" style="grid-column: span 3;">
                            <label for="faLeague">Liga</label>
                            <select id="faLeague">
                                <option value="">Selecione...</option>
                                <?php foreach ($leagues as $league): ?>
                                    <option value="<?= htmlspecialchars($league) ?>"><?= htmlspecialchars($league) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field" style="grid-column: span 4;">
                            <label for="faPlayerName">Nome</label>
                            <input type="text" id="faPlayerName" placeholder="Nome do jogador">
                        </div>
                        <div class="field" style="grid-column: span 2;">
                            <label for="faPosition">Posição</label>
                            <select id="faPosition">
                                <option value="PG">PG</option>
                                <option value="SG">SG</option>
                                <option value="SF">SF</option>
                                <option value="PF">PF</option>
                                <option value="C">C</option>
                            </select>
                        </div>
                        <div class="field" style="grid-column: span 3;">
                            <label for="faSecondaryPosition">Pos. Secundária</label>
                            <input type="text" id="faSecondaryPosition" placeholder="Opcional">
                        </div>
                        <div class="field" style="grid-column: span 2;">
                            <label for="faAge">Idade</label>
                            <input type="number" id="faAge" value="25">
                        </div>
                        <div class="field" style="grid-column: span 2;">
                            <label for="faOvr">OVR</label>
                            <input type="number" id="faOvr" value="70">
                        </div>
                        <div style="grid-column: span 2; align-self: end;">
                            <button id="btnAddFreeAgent" class="btn-ghost" onclick="addFreeAgent()" style="width:100%;justify-content:center;padding:11px;">
                                <i class="bi bi-plus-circle"></i> Adicionar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Gerenciar (legacy) -->
                <div class="panel legacy-fa">
                    <div class="panel-title">Gerenciar Jogadores</div>
                    <div id="adminFreeAgentsContainer" style="margin-top:14px;"><p class="empty-state">Carregando...</p></div>
                </div>

                <!-- Histórico contratações (legacy) -->
                <div class="panel legacy-fa">
                    <div class="panel-title">Histórico de Contratações FA</div>
                    <div id="faContractsHistoryContainer" style="margin-top:14px;"><p class="empty-state">Carregando...</p></div>
                </div>

            </div><!-- /fa-admin -->

            <?php endif; ?>

        </div><!-- /freeAgencyTabsContent -->

    </main>
</div><!-- /app -->

<!-- ══════════════════════════════════════
     MODALS (legacy hidden)
══════════════════════════════════════ -->

<!-- Modal: Fazer Lance -->
<div class="modal fade legacy-fa" id="modalOffer" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-coin me-1"></i> Fazer Lance</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="freeAgentIdOffer">
                <p style="font-size:14px;color:var(--text-2);margin-bottom:16px;">
                    <strong style="color:var(--text);">Jogador:</strong> <span id="freeAgentNomeOffer"></span>
                </p>
                <div class="field">
                    <label for="offerAmount">Moedas do lance</label>
                    <input type="number" id="offerAmount" min="0" value="0">
                </div>
                <div class="field" style="margin-top:14px;">
                    <label for="offerPriority">Prioridade</label>
                    <select id="offerPriority">
                        <option value="1">1 — Alta</option>
                        <option value="2">2 — Média</option>
                        <option value="3">3 — Baixa</option>
                    </select>
                </div>
                <div class="hint-box" style="margin-top:16px;">
                    <p>Moedas disponíveis: <strong id="moedasDisponiveis"><?= $team_moedas ?></strong></p>
                    <p style="margin-top:4px;color:var(--text-3);">Informe 0 moedas para cancelar sua proposta.</p>
                </div>
            </div>
            <div class="modal-footer" style="gap:10px;">
                <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn-red" id="btnConfirmOffer">Confirmar Lance</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Enviar Proposta Leilão -->
<div class="modal fade legacy-fa" id="modalProposta" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-send me-1"></i> Enviar Proposta de Leilão</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:16px;">
                <input type="hidden" id="leilaoIdProposta">
                <p style="font-size:14px;color:var(--text-2);">
                    <strong style="color:var(--text);">Jogador em Leilão:</strong> <span id="jogadorLeilaoNome"></span>
                </p>
                <div>
                    <p style="font-size:12px;color:var(--text-2);margin-bottom:8px;text-transform:uppercase;letter-spacing:.08em;font-weight:600;">Jogadores para oferecer (opcional)</p>
                    <div id="meusJogadoresParaTroca"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div>
                </div>
                <div>
                    <p style="font-size:12px;color:var(--text-2);margin-bottom:8px;text-transform:uppercase;letter-spacing:.08em;font-weight:600;">Picks para oferecer (opcional)</p>
                    <div id="minhasPicksParaTroca"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div>
                </div>
                <div class="field">
                    <label for="notasProposta">O que vai dar na proposta</label>
                    <textarea id="notasProposta" rows="3" placeholder="Ex: 1 jogador + escolha de draft ou moedas"></textarea>
                </div>
            </div>
            <div class="modal-footer" style="gap:10px;">
                <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn-red" id="btnEnviarProposta">
                    <i class="bi bi-send"></i> Enviar Proposta
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Ver Propostas -->
<div class="modal fade legacy-fa" id="modalVerPropostas" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-inbox me-1"></i> Propostas Recebidas</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="leilaoIdVerPropostas">
                <div id="listaPropostasRecebidas"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Quem está ganhando -->
<div class="modal fade" id="faApprovedModal" tabindex="-1" style="z-index:2000;">
    <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-inbox-fill me-2"></i> Quem está ganhando</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="faApprovedList" style="color:var(--text-2);">Carregando...</div>
            </div>
            <div class="modal-footer">
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
    /* ── Variáveis para free-agency.js ─── */
    const isAdmin          = <?= $is_admin ? 'true' : 'false' ?>;
    const userTeamId       = <?= $team_id ?? 'null' ?>;
    const userTeamName     = '<?= addslashes($team_name) ?>';
    const userMoedas       = <?= $team_moedas ?>;
    const userRosterCount  = <?= (int)$team_roster_count ?>;
    let   userPendingOffers= <?= (int)$team_pending_offers ?>;
    const rosterLimit      = 15;
    const userLeague       = <?= $team_league ? "'" . addslashes($team_league) . "'" : 'null' ?>;
    const defaultAdminLeague = '<?= addslashes($default_admin_league) ?>';
    const currentLeagueId  = <?= $league_id ?? 'null' ?>;
    const leagueIdByName   = <?= json_encode(array_reduce($leagues_admin, static function ($c, $l) { $c[$l['name']] = (int)$l['id']; return $c; }, [])) ?>;
    const useNewFreeAgency = true;

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
    const sidebar  = document.getElementById('sidebar');
    const sbOverlay= document.getElementById('sbOverlay');
    const menuBtn  = document.getElementById('menuBtn');
    menuBtn?.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        sbOverlay.classList.toggle('show');
    });
    sbOverlay?.addEventListener('click', () => {
        sidebar.classList.remove('open');
        sbOverlay.classList.remove('show');
    });

    /* ── Tab switcher (Bootstrap + fallback manual) ─ */
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.dataset.bsTarget;
            if (!targetId) return;
            // sync active class on buttons
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            // manually show/hide panes (fallback if Bootstrap tab CSS isn't fully applied)
            document.querySelectorAll('#freeAgencyTabsContent .tab-pane').forEach(pane => {
                pane.classList.remove('show', 'active');
            });
            const target = document.querySelector(targetId);
            if (target) {
                target.classList.add('show', 'active');
            }
            // fire data loading for specific tabs
            const tabId = btn.id;
            if (tabId === 'fa-history-tab') {
                if (typeof carregarHistoricoNovaFA === 'function') carregarHistoricoNovaFA();
                if (typeof carregarHistoricoFA === 'function' && typeof useNewFreeAgency !== 'undefined' && !useNewFreeAgency) carregarHistoricoFA();
                if (typeof carregarDispensados === 'function') carregarDispensados();
            } else if (tabId === 'fa-admin-tab') {
                if (typeof carregarSolicitacoesNovaFA === 'function') carregarSolicitacoesNovaFA();
                if (typeof carregarFreeAgentsAdmin === 'function') carregarFreeAgentsAdmin();
                if (typeof carregarPropostasAdmin === 'function') carregarPropostasAdmin();
                if (typeof carregarHistoricoContratacoes === 'function') carregarHistoricoContratacoes();
            }
        });
        // also keep Bootstrap's shown.bs.tab for compatibility
        btn.addEventListener('shown.bs.tab', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        });
    });
</script>
<script src="js/free-agency.js?v=20260206-1"></script>
<script src="js/leilao.js"></script>
</body>
</html>
