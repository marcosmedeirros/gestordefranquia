<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();
$user = getUserSession();
$pdo = db();

$isGlobalAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
$isGamesAdmin  = hasGamesAdminAccess($pdo, (int)$user['id']);
$adminLeagues  = getAdminLeagues($pdo, (int)$user['id']);

// Admin do Games também entra aqui — só que enxerga apenas a aba Games.
if (!$isGlobalAdmin && empty($adminLeagues) && !$isGamesAdmin) {
    header('Location: /dashboard.php');
    exit;
}

// ── Time do admin ─────────────────────────────────────
$stmtTeam = $pdo->prepare('SELECT t.*, t.photo_url, t.city FROM teams t WHERE t.user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC) ?: null;

// ── Liga IDs (para leilões) ────────────────────────────
$leagueIdByNamePhp = [];
try {
    $stmtLeagues = $pdo->query('SELECT id, name FROM leagues');
    foreach ($stmtLeagues->fetchAll(PDO::FETCH_ASSOC) as $lg) {
        $leagueIdByNamePhp[$lg['name']] = (int)$lg['id'];
    }
} catch (Exception $e) {}

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
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css?v=20260721">

    <style>
        /* ── Design Tokens ─────────────────────────────── */
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
            --border-strong: var(--border-md);
            --sidebar-w:  260px;
            --font:       'Montserrat', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --radius-xs:  6px;
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
            --border-red:color-mix(in srgb, var(--red) 18%, transparent);
            --text:     #111217;
            --text-2:   #5b6270;
            --text-3:   #657080;
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
            box-shadow: 0 1px 3px rgba(0,0,0,.18), 0 8px 24px -18px rgba(0,0,0,.5);
        }
        .panel + .panel { margin-top: 20px; }
        .panel-title { font-family: 'Oswald', var(--font); font-size: 16px; font-weight: 700; letter-spacing: .3px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .panel-title i { color: var(--red); }

        /* ── League Cards ─────────────────────────────── */
        .league-card {
            background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 22px 20px;
            cursor: pointer; transition: border-color var(--t) var(--ease), transform var(--t) var(--ease), box-shadow var(--t) var(--ease);
            height: 100%;
        }
        .league-card:hover { border-color: var(--border-red); transform: translateY(-2px); box-shadow: 0 8px 24px color-mix(in srgb, var(--red) 8%, transparent); }
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
        .action-card h4 { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
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
            font-weight: 700; border-radius: 10px; padding: 10px 18px;
            font-size: 13px; cursor: pointer; font-family: var(--font);
            transition: transform var(--t) var(--ease), box-shadow var(--t) var(--ease);
        }
        .btn-orange:hover, .btn-orange:focus { transform: translateY(-1px); box-shadow: 0 8px 16px color-mix(in srgb, var(--red) 24%, transparent); color: #fff; }
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

        /* ── Quick Nav ────────────────────────────────── */
        .admin-quicknav {
            display: flex; align-items: center; justify-content: center; gap: 4px;
            padding: 18px 40px 0; border-bottom: 1px solid var(--border); margin-bottom: 0;
        }
        .admin-qnav-btn {
            background: transparent; border: none; border-bottom: 2px solid transparent;
            color: var(--text-2); font-size: 13px; font-weight: 500; font-family: var(--font);
            padding: 10px 16px; cursor: pointer; transition: all var(--t) var(--ease);
            display: flex; align-items: center; gap: 8px; margin-bottom: -1px;
        }
        .admin-qnav-btn:hover { color: var(--text); }
        .admin-qnav-btn.active { color: var(--red); border-bottom-color: var(--red); font-weight: 600; }

        /* ── Panel components ────────────────────────── */
        .panel-header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; margin-bottom: 18px; flex-wrap: wrap;
        }
        .panel-sub { color: var(--text-2); font-size: 12px; margin-top: 2px; }
        .empty-state { text-align: center; color: var(--text-2); padding: 32px 0; font-size: 14px; }
        .btn-ghost {
            background: transparent; border: 1px solid var(--border); color: var(--text-2);
            border-radius: 10px; padding: 8px 14px; font-size: 13px; font-weight: 500;
            font-family: var(--font); cursor: pointer; transition: all var(--t) var(--ease);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); }
        .admin-sel { display: flex; align-items: center; gap: 8px; }
        .admin-sel label { font-size: 12px; color: var(--text-2); white-space: nowrap; }
        .admin-sel select {
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: 8px; padding: 6px 10px; color: var(--text);
            font-size: 13px; font-family: var(--font);
        }

        /* ── League Landing Page ──────────────────────── */
        .league-hero {
            background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 24px 28px;
            margin-bottom: 14px; display: flex; align-items: center;
            gap: 32px; flex-wrap: wrap;
        }
        .league-hero-name { font-size: 38px; font-weight: 900; letter-spacing: -1px; color: var(--text); line-height: 1; }
        .league-hero-name small { display: block; font-size: 10px; font-weight: 700; letter-spacing: .2em; text-transform: uppercase; color: var(--red); margin-bottom: 6px; }
        .league-hero-stats { display: flex; gap: 32px; }
        .league-hero-stat { display: flex; flex-direction: column; gap: 4px; }
        .league-hero-stat-val { font-size: 24px; font-weight: 800; color: var(--text); line-height: 1; }
        .league-hero-stat-lbl { font-size: 11px; color: var(--text-3); font-weight: 500; }
        .league-hero-tools { margin-left: auto; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .league-search-wrap {
            display: flex; align-items: center;
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: 10px; overflow: hidden;
            transition: border-color var(--t) var(--ease);
        }
        .league-search-wrap:focus-within { border-color: var(--border-red); }
        .league-search-wrap input {
            background: transparent; border: none; outline: none;
            padding: 8px 12px; font-size: 13px; color: var(--text);
            font-family: var(--font); min-width: 185px;
        }
        .league-search-wrap input::placeholder { color: var(--text-3); }
        .league-search-wrap button {
            background: transparent; border: none; border-left: 1px solid var(--border);
            color: var(--text-2); padding: 8px 12px; cursor: pointer; font-size: 14px;
            transition: color var(--t) var(--ease);
        }
        .league-search-wrap button:hover { color: var(--red); }

        /* ── Action Grid / Tiles ──────────────────────── */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(128px, 1fr));
            gap: 10px; margin-bottom: 14px;
        }
        .action-tile {
            background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 18px 16px;
            display: flex; flex-direction: column; align-items: flex-start;
            gap: 12px; cursor: pointer; position: relative; overflow: hidden;
            transition: border-color var(--t) var(--ease), transform var(--t) var(--ease), box-shadow var(--t) var(--ease);
            text-align: left; font-family: var(--font);
        }
        .action-tile:hover {
            border-color: var(--border-red);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,.14);
        }
        .action-tile-icon {
            width: 40px; height: 40px; border-radius: 11px;
            display: flex; align-items: center; justify-content: center; font-size: 18px;
        }
        .action-tile-label { font-size: 12px; font-weight: 600; color: var(--text); line-height: 1.35; }
        .action-tile-badge {
            position: absolute; top: 9px; right: 9px;
            background: var(--red); color: #fff; font-size: 10px; font-weight: 700;
            min-width: 18px; height: 18px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center; padding: 0 6px;
        }
        .search-results-panel {
            background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 12px 16px;
            margin-bottom: 14px; font-size: 13px;
        }
        .search-result-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 8px 0; border-bottom: 1px solid var(--border);
        }
        .search-result-row:last-child { border-bottom: none; }

        /* ── Punições ─────────────────────────────────── */
        .pun-field-label {
            font-size: 11px; font-weight: 600; color: var(--text-2);
            text-transform: uppercase; letter-spacing: .08em; margin-bottom: 6px;
        }
        /* Onde a dispensa parou. A cor separa o que ainda está em jogo (lance
           aberto) do que já acabou, sem precisar ler a palavra. */
        .disp-sit {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; white-space: nowrap; padding: 2px 8px;
            border-radius: 999px; background: var(--panel-2);
            border: 1px solid var(--border); color: var(--text-3);
        }
        .disp-sit[data-sit="no waiver"] {
            color: #f59e0b; border-color: rgba(245,158,11,.35); background: rgba(245,158,11,.10);
        }
        .disp-sit[data-sit="levado no lance"],
        .disp-sit[data-sit="contratado"] {
            color: #3b82f6; border-color: rgba(59,130,246,.35); background: rgba(59,130,246,.10);
        }

        .pun-card {
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 14px 16px; margin-bottom: 8px;
            transition: border-color var(--t) var(--ease);
        }
        .pun-card:last-child { margin-bottom: 0; }
        .pun-card:hover { border-color: var(--border-md); }
        .pun-card-reverted { opacity: .55; }
        .pun-card-head {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 12px; margin-bottom: 6px;
        }
        .pun-card-title { font-size: 13px; font-weight: 600; color: var(--text); }
        .pun-card-sub { font-size: 11px; color: var(--text-3); margin-top: 2px; }
        .pun-card-meta { font-size: 11px; color: var(--text-3); }
        .pun-badge {
            display: inline-flex; align-items: center; padding: 2px 10px;
            border-radius: 999px; font-size: 10px; font-weight: 700; white-space: nowrap;
        }
        .pun-badge-on  { background: color-mix(in srgb, var(--red) 10%, transparent); border: 1px solid color-mix(in srgb, var(--red) 20%, transparent); color: var(--red); }
        .pun-badge-off { background: var(--panel-3); border: 1px solid var(--border); color: var(--text-3); }

        /* ── Card de punição (remodelado) ───────────────── */
        .pun-v2 { display:flex; overflow:hidden; background:var(--panel-2); border:1px solid var(--border);
            border-radius:12px; margin-bottom:10px; transition:border-color var(--t) var(--ease), box-shadow var(--t) var(--ease); }
        .pun-v2:last-child { margin-bottom:0; }
        .pun-v2:hover { border-color:var(--border-md); box-shadow:0 4px 16px -10px rgba(0,0,0,.6); }
        .pun-v2-bar { width:4px; background:var(--red); flex-shrink:0; }
        .pun-v2.is-reverted { opacity:.6; }
        .pun-v2.is-reverted .pun-v2-bar { background:var(--text-3); }
        .pun-v2-body { flex:1; min-width:0; padding:13px 15px; }
        .pun-v2-top { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
        .pun-v2-team { display:flex; align-items:center; gap:9px; min-width:0; }
        .pun-v2-logo { width:32px; height:32px; border-radius:9px; background:var(--panel-3); border:1px solid var(--border-md);
            display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; color:var(--red); flex-shrink:0; }
        .pun-v2-teamname { display:block; font-size:13.5px; font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .pun-v2-league { display:block; font-size:10px; font-weight:700; color:var(--text-3); letter-spacing:.5px; }
        .pun-v2-actions { display:flex; align-items:center; gap:8px; flex-shrink:0; }
        .pun-v2-chips { display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; }
        .pun-chip { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; padding:3px 9px;
            border-radius:999px; background:var(--panel-3); border:1px solid var(--border); color:var(--text-2); }
        .pun-chip.type { background:color-mix(in srgb, var(--red) 12%, transparent); border-color:var(--border-red); color:var(--red); }
        .pun-v2-motive { font-size:12.5px; color:var(--text-2); margin-top:9px; line-height:1.45; }
        .pun-v2-date { font-size:10.5px; color:var(--text-3); margin-top:9px; display:flex; align-items:center; gap:5px; }
        @media (max-width: 640px) {
            .pun-v2-top { flex-direction:column; gap:8px; }
            .pun-v2-actions { width:100%; }
        }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .topbar { display: flex; }
            .main { margin-left: 0; width: 100%; padding: 54px 16px 40px; }

            /* Quick nav — scroll horizontal */
            .admin-quicknav {
                margin: 0 -16px;
                padding: 0 6px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                justify-content: flex-start;
            }
            .admin-quicknav::-webkit-scrollbar { display: none; }
            .admin-qnav-btn { padding: 10px 14px; font-size: 12px; flex-shrink: 0; }

            /* Page header */
            .page-top { margin-bottom: 14px; }
            .page-title { font-size: 21px; }
            .page-eyebrow { font-size: 10px; }

            /* League hero — colapsa verticalmente */
            .league-hero {
                padding: 16px;
                gap: 14px;
                flex-direction: column;
                align-items: flex-start;
            }
            .league-hero-name { font-size: 26px; letter-spacing: -.5px; }
            .league-hero-stats { gap: 18px; flex-wrap: wrap; }
            .league-hero-stat-val { font-size: 20px; }
            .league-hero-tools {
                margin-left: 0;
                width: 100%;
                flex-wrap: wrap;
            }
            .league-search-wrap { flex: 1; min-width: 0; }
            .league-search-wrap input { min-width: 0; flex: 1; width: 100%; }

            /* Action grid */
            .action-grid { grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 8px; }
            .action-tile { padding: 14px 12px; gap: 10px; }
            .action-tile-icon { width: 36px; height: 36px; font-size: 16px; }
            .action-tile-label { font-size: 11px; }

            /* Panel */
            .panel { padding: 16px 14px 18px; }
            .panel-header { gap: 10px; }

            /* Modais — ocupa a tela toda no mobile */
            .modal-dialog { margin: 8px !important; max-width: calc(100% - 16px) !important; }
            .modal-body { max-height: 70vh; overflow-y: auto; }
            .modal-footer { flex-wrap: wrap; gap: 8px; }
            .modal-footer .btn { flex: 1; min-width: 100px; }

            /* iOS: impede zoom automático em inputs com font < 16px */
            .form-control, .form-select,
            input[type="number"], input[type="text"],
            input[type="email"], textarea, select {
                font-size: 16px !important;
            }

            /* Tabelas geradas dinamicamente */
            #mainContainer .table { font-size: 12px; }
            #mainContainer .table td,
            #mainContainer .table th { padding: 8px 8px; white-space: nowrap; }

            /* Admin selector */
            .admin-sel { flex-wrap: wrap; }
            .admin-sel select { flex: 1; min-width: 0; }

            /* Touch targets mínimos */
            .btn-orange, .btn-ghost, .btn-outline-orange, .btn-back { min-height: 40px; }

            /* Nomes longos não quebram layout */
            .team-card h5, .action-card h4 { word-break: break-word; }
        }

        @media (max-width: 480px) {
            .main { padding: 54px 12px 32px; }
            .admin-quicknav { margin: 0 -12px; }
            .league-hero { padding: 14px 12px; gap: 12px; }
            .league-hero-name { font-size: 22px; }
            .league-hero-stats { gap: 14px; }
            .league-hero-stat-val { font-size: 18px; }
            .action-grid { grid-template-columns: repeat(auto-fill, minmax(84px, 1fr)); gap: 8px; }
            .action-tile { padding: 12px 10px; }
            .panel { padding: 14px 12px 16px; }

            /* Panel header — empilha título e botão */
            .panel-header { flex-direction: column; align-items: flex-start; }
            .panel-header > *:last-child { align-self: stretch; }
            .panel-header .btn-orange,
            .panel-header .btn-ghost,
            .panel-header > div[style*="flex"] { width: 100%; justify-content: center; }

            /* Pun card — empilha cabeçalho */
            .pun-card-head { flex-direction: column; gap: 6px; }

            /* Sidebar team name longa */
            .sb-team-name { word-break: break-word; }

            /* Modais — mais altura em telas pequenas */
            .modal-body { max-height: 78vh; }
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

        /* ── Pontos e Moedas (aba Games): cartao no celular, grade no desktop ──
           Uma marcacao so pros dois tamanhos. No celular cada GM e um cartao
           com os campos rotulados; a partir de 992px os rotulos somem, o
           cabecalho aparece e as mesmas divs viram colunas alinhadas. */
        .gu-list { display: flex; flex-direction: column; gap: 10px; }
        .gu-head { display: none; }

        .gu-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 12px;
            padding: 14px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
        }
        .gu-gm {
            grid-column: 1 / -1;
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px; min-width: 0;
        }
        .gu-nome { font-weight: 700; line-height: 1.25; word-break: break-word; }
        .gu-mail {
            font-size: 12px; color: var(--text-2);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .gu-liga {
            flex: none; font-size: 10px; font-weight: 700; letter-spacing: .1em;
            padding: 4px 9px; border-radius: 999px;
            background: var(--panel-3); border: 1px solid var(--border-md); color: var(--text-2);
        }

        .gu-campo { display: flex; flex-direction: column; gap: 5px; min-width: 0; margin: 0; }
        .gu-lab {
            font-size: 10px; letter-spacing: .1em; text-transform: uppercase;
            color: var(--text-3); font-weight: 700;
        }
        /* O input precisa caber o numero inteiro — era o que cortava "50" em "5C". */
        .gu-input {
            width: 100%; min-width: 0;
            background: var(--panel); border: 1px solid var(--border-md);
            color: var(--text); border-radius: var(--radius-xs);
            padding: 9px 10px; font-size: 15px; font-variant-numeric: tabular-nums;
        }
        .gu-input:focus {
            outline: none; border-color: var(--border-red);
            box-shadow: 0 0 0 3px var(--red-soft);
        }
        .gu-mini { justify-content: flex-start; }
        .gu-num { font-size: 15px; color: var(--text); }
        .gu-acao { grid-column: 1 / -1; }

        @media (min-width: 992px) {
            .gu-list { gap: 0; }
            .gu-head, .gu-row {
                display: grid;
                grid-template-columns: minmax(0, 1fr) 118px 118px 78px 104px 108px;
                gap: 14px; align-items: center;
            }
            .gu-head {
                padding: 10px 14px;
                background: var(--panel-2);
                border: 1px solid var(--border); border-bottom: none;
                border-radius: var(--radius-sm) var(--radius-sm) 0 0;
                font-family: 'Oswald', var(--font);
                font-size: 11px; letter-spacing: .12em; text-transform: uppercase;
                color: var(--text-3);
            }
            .gu-row {
                background: transparent;
                border: 1px solid var(--border); border-top: none; border-radius: 0;
                padding: 10px 14px;
            }
            .gu-row:last-child { border-radius: 0 0 var(--radius-sm) var(--radius-sm); }
            .gu-row:hover { background: var(--panel-3); }
            .gu-gm, .gu-acao { grid-column: auto; }
            .gu-lab { display: none; }   /* quem rotula agora e o cabecalho */
        }

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

        /* ── Tabelas do admin: mais respiro, cabeçalho destacado, hover suave ── */
        #mainContainer .table { border-collapse: separate; border-spacing: 0; margin-bottom: 0; }
        #mainContainer .table > thead > tr > th {
            background: var(--panel-2);
            padding: 12px 14px;
            font-family: 'Oswald', var(--font);
            font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .1em;
            color: var(--text-3) !important;
            border-bottom: 1px solid var(--border) !important;
            white-space: nowrap;
        }
        #mainContainer .table > thead > tr > th:first-child { border-top-left-radius: 10px; }
        #mainContainer .table > thead > tr > th:last-child  { border-top-right-radius: 10px; }
        #mainContainer .table > tbody > tr > td {
            padding: 11px 14px; vertical-align: middle;
            border-top: 1px solid var(--border) !important;
        }
        #mainContainer .table > tbody > tr { transition: background var(--t) var(--ease); }
        #mainContainer .table > tbody > tr:hover > td { background: var(--panel-3); }
        /* container rolável arredondado para tabelas largas */
        #mainContainer .table-responsive { border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
        #mainContainer .table-responsive > .table > thead > tr > th { border-radius: 0; }

        /* ── Mobile: tabelas largas não podem ficar cortadas ── */
        @media (max-width: 640px) {
            /* baseline: qualquer tabela larga rola na horizontal em vez de cortar colunas */
            #mainContainer .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }

            /* Gestão de usuários: a tabela vira cards empilhados (nada de coluna cortada) */
            .gestao-table, .gestao-table thead, .gestao-table tbody,
            .gestao-table th, .gestao-table td, .gestao-table tr { display: block; width: 100%; }
            .gestao-table { min-width: 0 !important; }
            .gestao-table thead { display: none; }
            .gestao-table tbody tr {
                margin-bottom: 12px; border: 1px solid var(--border); border-radius: 12px;
                padding: 10px 12px; background: var(--panel-2);
            }
            .gestao-table tbody tr:last-child { margin-bottom: 0; }
            .gestao-table td {
                border: none !important; padding: 5px 0;
                display: flex; align-items: center; gap: 8px;
            }
            .gestao-table td::before {
                content: attr(data-label); font-size: 10px; font-weight: 700; letter-spacing: .5px;
                text-transform: uppercase; color: var(--text-3); min-width: 92px; flex-shrink: 0;
            }
            .gestao-table td.gestao-actions { justify-content: flex-end; padding-top: 8px; }
            .gestao-table td.gestao-actions::before { content: none; }
        }

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

        /* ── CONFIGURAÇÕES DA LIGA ──────────────────────────────────────
           Era um flex-wrap só, com tudo dentro: os quatro números do CAP, os
           três campos de vídeo, o selo do CAP Range e os três botões de
           janela. Como wrap não respeita assunto, o selo do CAP caía no meio
           dos vídeos e os botões de Trades, Free Agency e Tática paravam cada
           um numa linha — três controles idênticos em três lugares
           diferentes, que é o que fazia a tela parecer bagunçada mesmo
           estando completa.

           Agora são três blocos com assunto: REGRAS (o que é número),
           JANELAS (o que abre e fecha) e VÍDEOS (o que é link). E dentro de
           Janelas, cada linha carrega o botão E o horário agendado da MESMA
           coisa — antes o botão de fechar trades e o agendamento de fechar
           trades ficavam a meia tela um do outro. */
        .lgcfg { display: flex; flex-direction: column; gap: 20px; }
        .lgcfg-bloco { display: flex; flex-direction: column; gap: 10px; }
        .lgcfg-titulo {
            display: flex; align-items: center; gap: 7px;
            font-size: 10px; font-weight: 800; letter-spacing: .9px;
            text-transform: uppercase; color: var(--text-3);
        }
        .lgcfg-titulo::after {
            content: ''; flex: 1; height: 1px; background: var(--border);
        }

        /* Regras: os números em grade, todos do mesmo tamanho. Antes cada um
           tinha a sua largura e o olho não achava a coluna. */
        .lgcfg-nums {
            display: grid; gap: 10px;
            grid-template-columns: repeat(auto-fit, minmax(128px, 1fr));
        }
        .lgcfg-campo { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
        .lgcfg-campo > label {
            font-size: 11px; font-weight: 600; color: var(--text-2);
            display: flex; align-items: center; gap: 5px;
        }
        .lgcfg-campo input.form-control { width: 100%; }
        .lgcfg-faixa {
            display: flex; flex-direction: column; justify-content: center;
            background: var(--red-soft); border: 1px solid var(--border-red);
            border-radius: var(--radius-sm); padding: 8px 14px; text-align: center;
        }
        .lgcfg-faixa b { font-size: 15px; font-weight: 800; color: var(--red); line-height: 1.1; }
        .lgcfg-faixa span { font-size: 10px; color: var(--text-3); }

        /* Janelas: uma linha por coisa que abre e fecha. */
        .lgcfg-janelas {
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            overflow: hidden; background: var(--panel-2);
        }
        .lgcfg-janela {
            display: grid; align-items: center; gap: 10px 14px;
            /* A coluna dos botoes e FIXA. Com auto, cada linha media a
               largura do proprio par de botoes — "Ativas/Bloqueadas" e mais
               largo que "Aberta/Fechada" — e o "fecha sozinho em" comecava
               em tres lugares diferentes, uma escadinha em tres linhas que
               deviam ser identicas. */
            grid-template-columns: minmax(120px, 170px) 200px 1fr;
            padding: 12px 14px;
        }
        .lgcfg-janela + .lgcfg-janela { border-top: 1px solid var(--border); }
        .lgcfg-jn { display: flex; align-items: center; gap: 8px; min-width: 0; }
        .lgcfg-jn i { font-size: 14px; flex: none; }
        .lgcfg-jn b { font-size: 12.5px; font-weight: 700; color: var(--text); white-space: nowrap; }
        .lgcfg-acoes { display: flex; align-items: center; gap: 6px; }
        .lgcfg-acoes .btn { flex: 1; white-space: nowrap; }
        .lgcfg-acoes .btn { font-size: 11px; padding: 4px 11px; }

        /* O selo de estado. Vira classe e não style inline porque os toggles
           reescreviam o cssText inteiro do elemento — qualquer regra daqui
           era apagada no primeiro clique. */
        .lgcfg-selo {
            font-size: 10px; font-weight: 700; padding: 2px 8px;
            border-radius: 999px; white-space: nowrap; flex: none;
        }
        .lgcfg-selo.on  { background: color-mix(in srgb, var(--green) 15%, transparent);
                          color: var(--green); border: 1px solid color-mix(in srgb, var(--green) 28%, transparent); }
        .lgcfg-selo.off { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }

        /* O agendamento, encostado na janela a que pertence. */
        .lgcfg-agenda { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .lgcfg-agenda-linha { display: flex; align-items: center; gap: 6px; }
        .lgcfg-agenda-linha > span {
            font-size: 11px; color: var(--text-3); white-space: nowrap; flex: none;
        }
        .lgcfg-agenda input { max-width: 200px; min-width: 0; }
        .lgcfg-agenda .btn { padding: 4px 8px; font-size: 11px; flex: none; }
        .lgcfg-falta { font-size: 10.5px; color: var(--text-3); min-height: 14px; }
        .lgcfg-falta.passou { color: var(--red); font-weight: 600; }

        .lgcfg-videos { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); }

        @media (max-width: 760px) {
            /* No celular a linha da janela vira três andares: nome, botões,
               agendamento. Em grade de uma coluna só, sem nada apertado. */
            .lgcfg-janela { grid-template-columns: 1fr; gap: 9px; }
            .lgcfg-agenda input { max-width: none; flex: 1; }
        }

        /* Bootstrap cards from admin.js */
        .card {
            background: var(--panel) !important;
            border-color: var(--border) !important;
            border-radius: var(--radius) !important;
            color: var(--text) !important;
            box-shadow: 0 1px 3px rgba(0,0,0,.16), 0 10px 28px -20px rgba(0,0,0,.55) !important;
            transition: border-color var(--t) var(--ease), transform var(--t) var(--ease), box-shadow var(--t) var(--ease);
        }
        .card-header {
            background: var(--panel-2) !important;
            border-color: var(--border) !important;
            font-weight: 600;
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
        .alert-danger  { background: color-mix(in srgb, var(--red) 10%, transparent)     !important; border-color: var(--border-red) !important; color: var(--text) !important; }
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
        .btn-outline-danger:hover { background: color-mix(in srgb, var(--red) 12%, transparent) !important; }

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
        <div class="topbar-title">FBA <em>Admin</em></div>
        <?php if ($currentSeason): ?>
        <span style="font-size:11px;font-weight:700;color:var(--red)"><?= htmlspecialchars($seasonDisplayYear) ?></span>
        <?php endif; ?>
    </header>

    <!-- ── Main Content ──────────────────────────────── -->
    <main class="main" id="app-main">

    <nav class="admin-quicknav" id="adminQuicknav">
        <?php foreach ($adminLeagues as $lg): ?>
        <button class="admin-qnav-btn" id="qnav-<?= strtolower(htmlspecialchars($lg)) ?>" onclick="showLeague('<?= htmlspecialchars($lg) ?>')">
            <i class="bi bi-trophy-fill"></i> <?= htmlspecialchars($lg) ?>
        </button>
        <?php endforeach; ?>
        <?php if ($isGlobalAdmin): ?>
        <button class="admin-qnav-btn" id="qnav-gestao" onclick="showGestao()">
            <i class="bi bi-people-fill"></i> Gestão
        </button>
        <?php endif; ?>
        <?php if ($isGamesAdmin): ?>
        <button class="admin-qnav-btn" id="qnav-games" onclick="showGamesAdmin()">
            <i class="bi bi-controller"></i> Games
        </button>
        <?php endif; ?>
    </nav>

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

<script>
window.ADMIN_LEAGUES    = <?= json_encode(array_values($adminLeagues)) ?>;
window.IS_GLOBAL_ADMIN  = <?= $isGlobalAdmin ? 'true' : 'false' ?>;
window.IS_GAMES_ADMIN   = <?= $isGamesAdmin ? 'true' : 'false' ?>;
/* variáveis necessárias para free-agency.js */
const isAdmin          = <?= $isGlobalAdmin || !empty($adminLeagues) ? 'true' : 'false' ?>;
const userLeague       = <?= $team ? "'" . addslashes($team['league'] ?? '') . "'" : 'null' ?>;
const defaultAdminLeague = '<?= addslashes($adminLeagues[0] ?? '') ?>';
const userTeamId       = <?= $team ? (int)$team['id'] : 'null' ?>;
const userTeamName     = '<?= addslashes($team['name'] ?? '') ?>';
const userMoedas       = 0;
const userRosterCount  = 0;
let   userPendingOffers= 0;
const rosterLimit      = 15;
const currentLeagueId  = null;
const leagueIdByName   = <?= json_encode($leagueIdByNamePhp) ?>;
const useNewFreeAgency = true;
</script>
<script src="/js/admin.js?v=<?= time() ?>"></script>
<script src="/js/free-agency.js?v=<?= time() ?>"></script>
<script src="/js/seasons.js?v=<?= time() ?>"></script>
<script src="/js/punicoes.js?v=<?= time() ?>"></script>
<script src="<?= assetUrl('/js/pwa.js') ?>"></script>
</div><!-- /.app -->
</body>
</html>
