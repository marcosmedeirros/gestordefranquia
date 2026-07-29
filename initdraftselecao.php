<?php
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/auth.php';

$token = $_GET['token'] ?? null;
if (!$token) {
    http_response_code(403);
    echo 'Token inválido.';
    exit;
}

$pdo = db();
$user = getUserSession();
$isAdmin = ($user && isset($user['id'])) ? hasAdminAccess($pdo, (int)$user['id']) : false;
$userTeamId = null;
$team = null;
if ($user && isset($user['id'])) {
    $stmtTeam = $pdo->prepare('SELECT id, city, name, photo_url, league FROM teams WHERE user_id = ? LIMIT 1');
    $stmtTeam->execute([$user['id']]);
    $team = $stmtTeam->fetch(PDO::FETCH_ASSOC) ?: null;
    $userTeamId = $team['id'] ?? null;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <meta name="theme-color" content="#fc0025">
    <title>Draft Inicial — Sala de Seleção</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* ── Tokens ───────────────────────────────────── */
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
            --font:       'Montserrat', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
            --sidebar-w:  260px;
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

        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        /* ── Layout ───────────────────────────────────── */
        .app-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 20px 48px; }

        /* ── App shell + menu lateral ─────────────────── */
        .app { display: flex; min-height: 100vh; }
        .sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; overflow-y: auto; scrollbar-width: none; transition: transform var(--t) var(--ease); }
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
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
        .sb-overlay.show { display: block; }
        .menu-btn { display: none; width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); align-items: center; justify-content: center; cursor: pointer; font-size: 17px; margin-right: 4px; }
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; }
            .menu-btn { display: flex; }
        }

        /* ── Topbar ───────────────────────────────────── */
        .app-topbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 14px; flex-wrap: wrap;
            padding: 14px 20px;
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            margin-bottom: 24px;
        }
        .app-topbar-left { display: flex; align-items: center; gap: 12px; }
        .app-logo { width: 32px; height: 32px; border-radius: 8px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; color: #fff; flex-shrink: 0; }
        .app-title { font-size: 15px; font-weight: 700; line-height: 1.1; }
        .app-title span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--text-2); text-decoration: none; transition: color var(--t) var(--ease); }
        .back-link:hover { color: var(--red); }

        .token-display { display: flex; align-items: center; gap: 6px; background: var(--panel-2); border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; }
        .token-display code { font-size: 11px; color: var(--text-3); max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .token-copy-btn { background: transparent; border: none; color: var(--text-3); cursor: pointer; font-size: 12px; padding: 0; transition: color var(--t) var(--ease); }
        .token-copy-btn:hover { color: var(--red); }

        /* ── Feedback ─────────────────────────────────── */
        .fb-alert { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 500; margin-bottom: 12px; }
        .fb-alert.success { background: rgba(34,197,94,.10); border: 1px solid rgba(34,197,94,.2); color: var(--green); }
        .fb-alert.danger  { background: rgba(239,68,68,.10);  border: 1px solid rgba(239,68,68,.2);  color: #ef4444; }
        .fb-alert.warning { background: rgba(245,158,11,.10); border: 1px solid rgba(245,158,11,.2); color: var(--amber); }
        .fb-alert.info    { background: rgba(59,130,246,.10); border: 1px solid rgba(59,130,246,.2); color: var(--blue); }
        .fb-close { background: none; border: none; color: inherit; cursor: pointer; font-size: 15px; opacity: .7; }
        .fb-close:hover { opacity: 1; }

        /* ── Hero ─────────────────────────────────────── */
        .hero {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 28px;
            margin-bottom: 20px;
        }
        .hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 6px; }
        .hero-title { font-size: 26px; font-weight: 800; line-height: 1.1; margin-bottom: 4px; }
        .hero-sub { font-size: 13px; color: var(--text-2); }

        /* ── Stat grid ────────────────────────────────── */
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-top: 18px; }
        .stat-card { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 16px; }
        .stat-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); margin-bottom: 6px; }
        .stat-value { font-size: 1.1rem; font-weight: 700; }

        /* ── Admin panel ──────────────────────────────── */
        .admin-panel { background: var(--panel); border: 1px solid var(--border-red); border-radius: var(--radius); margin-bottom: 20px; overflow: hidden; }
        .admin-panel-head { padding: 16px 22px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .admin-panel-title { font-size: 13px; font-weight: 800; display: flex; align-items: center; gap: 8px; }
        .admin-panel-title i { color: var(--red); }
        .admin-panel-body { padding: 20px 22px; }
        .prog-bar-wrap { height: 6px; background: var(--panel-3); border-radius: 999px; overflow: hidden; margin-top: 12px; }
        .prog-bar-fill { height: 100%; background: linear-gradient(90deg, var(--red), color-mix(in srgb, var(--red) 85%, white)); border-radius: 999px; transition: width .5s ease; }
        .adm-section-title { font-size: 11px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase; color: var(--text-3); margin: 4px 0 10px; }

        /* ── Panel card ───────────────────────────────── */
        .panel-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; height: 100%; }
        .panel-card-head { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .panel-card-title { font-size: 13px; font-weight: 700; }
        .panel-card-sub { font-size: 11px; color: var(--text-2); margin-top: 2px; }
        .panel-card-body { padding: 16px 18px; }

        /* keyframe compartilhada com .clock-flash (sala nova) */
        @keyframes pickFlash {
            0%   { box-shadow: 0 0 0 color-mix(in srgb, var(--red) 0%, transparent); }
            30%  { box-shadow: 0 0 22px color-mix(in srgb, var(--red) 45%, transparent); }
            100% { box-shadow: 0 0 0 color-mix(in srgb, var(--red) 0%, transparent); }
        }

        /* ── Order list ───────────────────────────────── */
        .order-rank { width: 28px; height: 28px; border-radius: 50%; background: var(--red-soft); border: 1px solid var(--border-red); display: grid; place-items: center; font-weight: 700; font-size: 12px; color: var(--red); flex-shrink: 0; }
        .team-chip { display: flex; align-items: center; gap: 8px; }
        .team-chip img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .team-chip-name { font-size: 13px; font-weight: 600; line-height: 1.2; }
        .team-chip-gm { font-size: 11px; color: var(--text-2); }

        .order-actions { display: flex; gap: 4px; flex-shrink: 0; }
        .order-btn { width: 28px; height: 28px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; transition: all var(--t) var(--ease); }
        .order-btn:hover { border-color: var(--border-md); color: var(--text); }
        .order-btn:disabled { opacity: .3; cursor: not-allowed; }

        .manual-order-row { display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 12px; margin-bottom: 6px; }
        .manual-position-select { width: 68px; }

        /* ── Reaction bar ──────────────────────────────── */
        .reaction-bar { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
        .reaction-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 999px; font-size: 11px; border: 1px solid var(--border-md); background: var(--panel-2); color: var(--text); cursor: pointer; user-select: none; transition: all var(--t) var(--ease); }
        .reaction-chip:hover { border-color: var(--border-red); background: var(--red-soft); }
        .reaction-chip.active { background: var(--red-soft); border-color: var(--border-red); }
        .reaction-count { color: var(--text-2); }

        /* ── Status pill ──────────────────────────────── */
        .status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
        .status-pill.setup       { background: rgba(245,158,11,.12); color: var(--amber); border: 1px solid rgba(245,158,11,.25); }
        .status-pill.in_progress { background: rgba(34,197,94,.12);  color: var(--green); border: 1px solid rgba(34,197,94,.25); }
        .status-pill.completed   { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); }

        /* ── Badges ───────────────────────────────────── */
        .badge-available { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; background: rgba(34,197,94,.10); color: var(--green); border: 1px solid rgba(34,197,94,.2); }
        .badge-drafted   { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; background: var(--panel-3); color: var(--text-3); border: 1px solid var(--border); }

        /* ── Buttons ──────────────────────────────────── */
        .btn-red { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; background: var(--red); border: none; color: #fff; font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; transition: filter var(--t) var(--ease); text-decoration: none; }
        .btn-red:hover { filter: brightness(1.12); color: #fff; }
        .btn-red:disabled { opacity: .5; cursor: not-allowed; }
        .btn-ghost { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; background: transparent; border: 1px solid var(--border-md); color: var(--text-2); font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }
        .btn-ghost:disabled { opacity: .4; cursor: not-allowed; }
        .btn-green { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; background: var(--green); border: none; color: #fff; font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; transition: filter var(--t) var(--ease); }
        .btn-green:hover { filter: brightness(1.1); }
        .btn-green:disabled { opacity: .5; cursor: not-allowed; }
        .btn-amber { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.3); color: var(--amber); font-family: var(--font); font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease); }
        .btn-amber:hover { background: rgba(245,158,11,.2); }
        .btn-amber:disabled { opacity: .4; cursor: not-allowed; }
        .btn-sm-icon { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); }
        .btn-sm-icon:hover { border-color: var(--border-md); color: var(--text); }
        .btn-sm-icon.danger:hover { border-color: rgba(239,68,68,.4); color: #ef4444; background: rgba(239,68,68,.08); }
        .btn-sm-icon.amber:hover { border-color: rgba(245,158,11,.4); color: var(--amber); background: rgba(245,158,11,.08); }

        /* ── Search/Filter inputs ─────────────────────── */
        .search-input, .filter-select { background: var(--panel-2); border: 1px solid var(--border-md); border-radius: 8px; padding: 8px 12px; color: var(--text); font-family: var(--font); font-size: 13px; outline: none; transition: border-color var(--t) var(--ease); width: 100%; }
        .search-input:focus, .filter-select:focus { border-color: var(--red); }
        .search-input::placeholder { color: var(--text-3); }
        .filter-select option { background: var(--panel-2); }
        .filter-check { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-2); cursor: pointer; }
        .filter-check input { accent-color: var(--red); }

        /* ── Form fields ──────────────────────────────── */
        .field-label { font-size: 12px; font-weight: 600; color: var(--text-2); margin-bottom: 6px; display: block; }
        .field-input { width: 100%; background: var(--panel-2); border: 1px solid var(--border-md); border-radius: 8px; padding: 10px 12px; color: var(--text); font-family: var(--font); font-size: 13px; outline: none; transition: border-color var(--t) var(--ease); }
        .field-input:focus { border-color: var(--red); }
        .field-input::placeholder { color: var(--text-3); }

        /* ── Data table ───────────────────────────────── */
        .data-table-wrap { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .data-table th { font-size: 10px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase; color: var(--text-3); padding: 10px 12px; border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; background: var(--panel-2); }
        .data-table th.sortable { cursor: pointer; user-select: none; }
        .data-table th.sortable:hover { color: var(--text-2); }
        .data-table th.sortable.active { color: var(--text); }
        .data-table th.sortable .sort-indicator { margin-left: 4px; font-size: .8em; }
        .data-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); color: var(--text-2); vertical-align: middle; }
        .data-table td.td-name { font-weight: 600; color: var(--text); }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover td { background: var(--panel-3); }

        /* ── Pagination ───────────────────────────────── */
        .pag-wrap .pagination { margin: 0; }
        .pag-wrap .page-link { background: var(--panel-2); border-color: var(--border); color: var(--text-2); font-family: var(--font); font-size: 12px; }
        .pag-wrap .page-link:hover { background: var(--panel-3); color: var(--text); }
        .pag-wrap .page-item.active .page-link { background: var(--red); border-color: var(--red); color: #fff; }
        .pag-wrap .page-item.disabled .page-link { opacity: .4; }

        /* ── Roster grid ──────────────────────────────── */
        .roster-card { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; }
        .roster-list { list-style: none; padding: 0; margin: 0; font-size: 12px; color: var(--text-2); }
        .roster-list li { padding: 4px 0; border-bottom: 1px solid var(--border); }
        .roster-list li:last-child { border-bottom: none; }

        /* ── Empty state ──────────────────────────────── */
        .state-empty { padding: 24px 16px; text-align: center; color: var(--text-3); }
        .state-empty i { font-size: 28px; display: block; margin-bottom: 8px; }
        .state-empty p { font-size: 12px; margin: 0; }

        /* ── Bootstrap tabs / modal overrides ─────────── */
        .nav-tabs { border-bottom: 1px solid var(--border); gap: 0; }
        .nav-tabs .nav-link { color: var(--text-2); border: none; border-bottom: 2px solid transparent; border-radius: 0; font-family: var(--font); font-size: 13px; font-weight: 600; padding: 10px 16px; margin-bottom: -1px; transition: all var(--t) var(--ease); }
        .nav-tabs .nav-link.active { color: var(--red); border-bottom-color: var(--red); background: transparent; }
        .nav-tabs .nav-link:hover { color: var(--text); background: var(--panel-2); }
        .modal-content { background: var(--panel); border: 1px solid var(--border-md); border-radius: var(--radius); color: var(--text); font-family: var(--font); }
        .modal-header { border-bottom: 1px solid var(--border); padding: 18px 20px; }
        .modal-header .modal-title { font-size: 14px; font-weight: 700; }
        .modal-body { padding: 20px; }
        .modal-footer { border-top: 1px solid var(--border); padding: 14px 20px; gap: 8px; }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 768px) {
            .app-wrap { padding: 16px 14px 40px; }
            .hero { padding: 18px 20px; }
            .manual-order-row { grid-template-columns: 1fr; }
            .manual-position-select { width: 100%; }
        }
        @media (max-width: 576px) {
            #poolTableEl thead { display: none; }
            #poolTableEl tbody tr { display: flex; flex-direction: column; gap: 4px; padding: 10px 12px; border-bottom: 1px solid var(--border); }
            #poolTableEl td { width: 100%; padding: 0; border: 0; }
            #poolTableEl td:first-child { display: none; }
        }
        input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible,[tabindex]:focus-visible{outline:2px solid var(--red, #fc0025);outline-offset:2px;}
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; animation-delay: 0ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; transition-delay: 0ms !important; scroll-behavior: auto !important; } }
        /* ══════ Draft room (nova sala) ══════ */
        .pos-badge { display:inline-flex; align-items:center; justify-content:center; min-width:34px; padding:2px 7px; border-radius:6px; font-size:11px; font-weight:800; letter-spacing:.5px; border:1px solid transparent; }
        .pos-PG { background:rgba(59,130,246,.14); color:#60a5fa; border-color:rgba(59,130,246,.3); }
        .pos-SG { background:rgba(6,182,212,.14);  color:#22d3ee; border-color:rgba(6,182,212,.3); }
        .pos-SF { background:rgba(34,197,94,.14);  color:#4ade80; border-color:rgba(34,197,94,.3); }
        .pos-PF { background:rgba(245,158,11,.14); color:#fbbf24; border-color:rgba(245,158,11,.3); }
        .pos-C  { background:rgba(244,63,94,.14);  color:#fb7185; border-color:rgba(244,63,94,.3); }

        .ovr-chip { display:inline-flex; flex-direction:column; align-items:center; justify-content:center; width:46px; height:46px; border-radius:12px; background:var(--panel-3); border:1px solid var(--border-md); flex-shrink:0; }
        .ovr-chip .ovr-num { font-size:18px; font-weight:800; line-height:1; }
        .ovr-chip .ovr-lbl { font-size:8px; font-weight:700; letter-spacing:.5px; color:var(--text-3); margin-top:1px; }
        .ovr-elite .ovr-num { color:#fbbf24; } .ovr-elite { border-color:rgba(245,158,11,.4); }
        .ovr-good  .ovr-num { color:#4ade80; }
        .ovr-mid   .ovr-num { color:var(--text); }
        .ovr-low   .ovr-num { color:var(--text-2); }

        /* ── Clock board (na vez / próximo / última) ── */
        .clock-board { display:grid; grid-template-columns: 1.4fr 1fr 1.1fr; gap:14px; margin-bottom:20px; }
        .clock-cell { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); padding:16px 18px; position:relative; overflow:hidden; }
        .clock-cell .cell-label { font-size:10px; font-weight:800; letter-spacing:1.2px; text-transform:uppercase; color:var(--text-3); margin-bottom:10px; display:flex; align-items:center; gap:6px; }
        .clock-now { border-color:var(--border-red); background:linear-gradient(135deg, color-mix(in srgb, var(--red) 12%, var(--panel)), var(--panel) 60%); }
        .clock-now .cell-label { color:var(--red); }
        .clock-now::after { content:""; position:absolute; inset:0; border-radius:var(--radius); box-shadow:0 0 0 1px var(--border-red) inset; animation:clockPulse 2.4s var(--ease) infinite; pointer-events:none; }
        @keyframes clockPulse { 0%,100%{ box-shadow:0 0 0 1px var(--border-red) inset, 0 0 0 color-mix(in srgb,var(--red) 0%,transparent);} 50%{ box-shadow:0 0 0 1px var(--border-red) inset, 0 0 26px color-mix(in srgb,var(--red) 22%,transparent);} }
        .clock-team { display:flex; align-items:center; gap:14px; }
        .clock-team img { width:60px; height:60px; border-radius:14px; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
        .clock-team-name { font-size:20px; font-weight:800; line-height:1.1; }
        .clock-team-gm { font-size:12px; color:var(--text-2); margin-top:2px; }
        .clock-pick-tag { display:inline-flex; align-items:center; gap:6px; margin-top:8px; font-size:12px; font-weight:700; color:var(--red); background:var(--red-soft); border:1px solid var(--border-red); border-radius:999px; padding:3px 10px; }
        .clock-next-list { display:flex; flex-direction:column; gap:8px; }
        .clock-next-item { display:flex; align-items:center; gap:9px; }
        .clock-next-item img { width:30px; height:30px; border-radius:8px; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
        .clock-next-rank { width:20px; font-size:11px; font-weight:700; color:var(--text-3); text-align:center; flex-shrink:0; }
        .clock-next-name { font-size:13px; font-weight:600; line-height:1.15; }
        .clock-next-meta { font-size:10px; color:var(--text-3); }
        .clock-last { }
        .clock-last-player { font-size:16px; font-weight:800; }
        .clock-last-team { font-size:12px; color:var(--text-2); margin-top:2px; }
        .clock-last-empty { font-size:13px; color:var(--text-3); }
        .clock-flash { animation:pickFlash 1.2s ease-in-out; }
        .board-progress { grid-column:1 / -1; }
        .board-progress-bar { height:8px; background:var(--panel-3); border-radius:999px; overflow:hidden; }
        .board-progress-fill { height:100%; background:linear-gradient(90deg,var(--red),color-mix(in srgb,var(--red) 80%,#fff)); border-radius:999px; transition:width .5s var(--ease); }

        /* ── Filters ── */
        .filter-chip { display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border-radius:999px; font-size:12px; font-weight:700; cursor:pointer; user-select:none; border:1px solid var(--border-md); background:var(--panel-2); color:var(--text-2); transition:all var(--t) var(--ease); }
        .filter-chip:hover { color:var(--text); border-color:var(--border-red); }
        .filter-chip.active { background:var(--red-soft); border-color:var(--border-red); color:var(--red); }

        /* ── Pool as cards ── */
        .pool-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:10px; }
        .player-card { position:relative; display:flex; align-items:center; gap:12px; background:var(--panel-2); border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px; cursor:pointer; transition:all var(--t) var(--ease); }
        .player-card:hover { border-color:var(--border-red); transform:translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,.25); }
        .player-card.is-drafted { opacity:.5; }
        .player-card .pc-body { flex:1; min-width:0; }
        .player-card .pc-name { font-size:14px; font-weight:700; line-height:1.15; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .player-card .pc-meta { display:flex; align-items:center; gap:6px; margin-top:6px; font-size:11px; color:var(--text-2); }
        .player-card .pc-pick { width:100%; margin-top:10px; justify-content:center; }
        .player-card-wrap { display:flex; flex-direction:column; }
        .pc-rank { position:absolute; top:8px; right:10px; font-size:10px; font-weight:700; color:var(--text-3); }

        /* ── Snake board ── */
        .snake-round { margin-bottom:16px; }
        .snake-round-head { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
        .snake-round-title { font-size:11px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; color:var(--text-2); }
        .snake-dir { font-size:10px; color:var(--text-3); display:inline-flex; align-items:center; gap:3px; }
        .snake-pick { display:flex; align-items:center; gap:10px; background:var(--panel-2); border:1px solid var(--border); border-radius:9px; padding:8px 10px; margin-bottom:5px; }
        .snake-pick.is-current { border-color:var(--border-red); background:color-mix(in srgb,var(--red) 8%,transparent); }
        .snake-pick.is-done { }
        .snake-num { width:30px; height:30px; border-radius:8px; background:var(--panel-3); border:1px solid var(--border-md); display:grid; place-items:center; font-size:11px; font-weight:800; color:var(--text-2); flex-shrink:0; }
        .snake-pick.is-current .snake-num { background:var(--red-soft); border-color:var(--border-red); color:var(--red); }
        .snake-body { flex:1; min-width:0; }
        .snake-team { font-size:12px; font-weight:600; color:var(--text-2); }
        .snake-player { font-size:13px; font-weight:700; display:flex; align-items:center; gap:6px; }
        .snake-onclock { font-size:11px; font-weight:700; color:var(--red); display:inline-flex; align-items:center; gap:5px; }
        .snake-onclock .dot { width:7px; height:7px; border-radius:50%; background:var(--red); animation:clockPulse 1.6s infinite; }
        .snake-react { display:flex; gap:4px; flex-wrap:wrap; margin-top:5px; }

        /* ── Roster cards ── */
        .roster-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .roster-badges { display:flex; gap:6px; }
        .roster-stat { font-size:10px; font-weight:700; color:var(--text-3); background:var(--panel-3); border:1px solid var(--border); border-radius:6px; padding:2px 7px; }
        .roster-list li { display:flex; align-items:center; gap:8px; }
        .roster-list li .rl-ovr { margin-left:auto; font-weight:700; color:var(--text); font-size:12px; }

        /* Grid denso de elencos (30-32 times num card só) */
        .rosters-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(215px, 1fr)); gap:12px; align-items:start; }
        .rosters-grid .roster-card { padding:12px; margin:0; }
        .rosters-grid .roster-head { margin-bottom:8px; }
        .rosters-grid .team-chip img { width:28px; height:28px; }
        .rosters-grid .team-chip-name { font-size:12px; }
        .rosters-grid .team-chip-gm { font-size:10px; }
        .rosters-grid .roster-list { font-size:11px; }
        .rosters-grid .roster-list li { padding:3px 0; }
        .rosters-grid .roster-empty { font-size:11px; color:var(--text-3); padding:4px 0; }

        /* ── Player detail (offcanvas) ── */
        .pd-offcanvas { background:var(--panel); color:var(--text); border-left:1px solid var(--border-md); width:380px; }
        .pd-hero { display:flex; align-items:center; gap:14px; padding:18px; border-bottom:1px solid var(--border); }
        .pd-hero img { width:72px; height:72px; border-radius:16px; object-fit:cover; border:1px solid var(--border-md); background:var(--panel-2); }
        .pd-name { font-size:20px; font-weight:800; line-height:1.1; }
        .pd-sub { font-size:12px; color:var(--text-2); margin-top:4px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .pd-section { padding:14px 18px; border-bottom:1px solid var(--border); }
        .pd-section h6 { font-size:10px; font-weight:800; letter-spacing:.8px; text-transform:uppercase; color:var(--text-3); margin-bottom:8px; }
        .pd-section p { font-size:13px; color:var(--text-2); margin:0; line-height:1.5; }
        .pd-tag { display:inline-block; font-size:11px; padding:3px 9px; border-radius:999px; background:var(--panel-2); border:1px solid var(--border-md); color:var(--text); margin:0 4px 4px 0; }

        @media (max-width: 900px) {
            .clock-board { grid-template-columns:1fr; }
            .pool-grid { grid-template-columns:1fr 1fr; }
        }
        @media (max-width: 560px) { .pool-grid { grid-template-columns:1fr; } .pd-offcanvas { width:100%; } }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>

<div class="app">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>
<main class="main">

<!-- Topbar -->
<div class="app-topbar">
    <div class="app-topbar-left">
        <button class="menu-btn" id="menuBtn" aria-label="Abrir menu"><i class="bi bi-list"></i></button>
        <div class="app-logo">FBA</div>
        <div class="app-title">Sala de Seleção <span>Draft Inicial</span></div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span id="leagueName" style="font-size:12px;font-weight:700;color:var(--text-2)"></span>
        <button class="btn-ghost" onclick="loadState()"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>
        <button class="btn-ghost" id="toggleSoundButton"><i class="bi bi-volume-mute"></i> Som</button>
        <?php if ($isAdmin): ?>
        <div class="token-display" title="Token de acesso">
            <i class="bi bi-key" style="font-size:12px;color:var(--text-3)"></i>
            <code id="tokenDisplay"></code>
            <button class="token-copy-btn" onclick="copyToken()" title="Copiar token"><i class="bi bi-clipboard"></i></button>
        </div>
        <button class="btn-red" id="toggleAdminButton" onclick="toggleAdminPanel()"><i class="bi bi-sliders"></i> Painel Admin</button>
        <?php endif; ?>
        <a href="dashboard.php" class="back-link"><i class="bi bi-arrow-left"></i> Dashboard</a>
    </div>
</div>

<div class="app-wrap">

    <div id="feedback"></div>

    <!-- Hero -->
    <section class="hero">
        <div class="hero-eyebrow">Draft Inicial</div>
        <h1 class="hero-title">Sala de Seleção</h1>
        <p class="hero-sub">Acompanhe as picks, o pool de jogadores e os elencos se formando em tempo real.</p>
        <div class="stat-grid" id="statGrid"></div>
    </section>

    <?php if ($isAdmin): ?>
    <!-- Admin panel -->
    <section class="admin-panel d-none" id="adminPanel">
        <div class="admin-panel-head">
            <div style="flex:1;min-width:220px">
                <div class="admin-panel-title"><i class="bi bi-shield-lock"></i> Painel do Admin</div>
                <div class="d-flex justify-content-between mb-1 mt-2" style="font-size:12px;color:var(--text-2)">
                    <span id="admProgressLabel"></span>
                    <span id="admProgressPercent"></span>
                </div>
                <div class="prog-bar-wrap"><div class="prog-bar-fill" id="admProgressBar" style="width:0%"></div></div>
            </div>
            <button class="btn-ghost" onclick="toggleAdminPanel()"><i class="bi bi-x-lg"></i> Fechar</button>
        </div>
        <div class="admin-panel-body">
            <ul class="nav nav-tabs mb-3" id="adminTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#adm-order" type="button" role="tab">Ordem &amp; Início</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adm-players" type="button" role="tab">Jogadores</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adm-live" type="button" role="tab">Agendamento &amp; Controle</button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Tab: Ordem & Início -->
                <div class="tab-pane fade show active" id="adm-order" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-md-5">
                            <div class="adm-section-title">Total de rodadas</div>
                            <div class="d-flex gap-2 align-items-center mb-4">
                                <input type="number" id="admTotalRounds" class="field-input" min="1" max="10" style="max-width:100px">
                                <button class="btn-ghost" onclick="saveTotalRounds()"><i class="bi bi-check2"></i> Salvar</button>
                            </div>
                            <div class="adm-section-title">Iniciar</div>
                            <div id="admStartArea"></div>
                        </div>
                        <div class="col-md-7">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <div class="adm-section-title mb-0">Ordem da 1ª rodada</div>
                                <div class="d-flex gap-2">
                                    <button class="btn-ghost" id="admShuffleBtn" onclick="shuffleOrder()"><i class="bi bi-shuffle"></i> Sortear</button>
                                    <button class="btn-ghost" id="admResetOrderBtn" onclick="resetManualOrder()"><i class="bi bi-arrow-counterclockwise"></i> Resetar</button>
                                    <button class="btn-green" id="admSaveOrderBtn" onclick="saveManualOrder()"><i class="bi bi-check2-circle"></i> Salvar ordem</button>
                                </div>
                            </div>
                            <div id="admOrderHint" style="font-size:11px;color:var(--text-2);margin-bottom:10px"></div>
                            <div id="admManualOrderList" style="max-height:420px;overflow-y:auto"></div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Jogadores -->
                <div class="tab-pane fade" id="adm-players" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <div style="font-size:14px;font-weight:700">Pool de jogadores</div>
                            <div style="font-size:11px;color:var(--text-2);margin-top:2px">Adicionar, editar e remover só é permitido durante a configuração.</div>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button class="btn-amber" data-bs-toggle="modal" data-bs-target="#importCSVModal"><i class="bi bi-file-earmark-arrow-up"></i> Importar CSV</button>
                            <button class="btn-red" data-bs-toggle="modal" data-bs-target="#addPlayerModal"><i class="bi bi-person-plus"></i> Novo Jogador</button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <input type="text" id="admPoolSearch" class="search-input" placeholder="Filtrar por nome ou posição…">
                    </div>
                    <div class="data-table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th><th>Jogador</th><th>Pos</th><th>OVR</th><th>Idade</th><th>Status</th><th style="text-align:right">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="admPoolTable"></tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2 pag-wrap" id="admPoolPagination"></div>
                </div>

                <!-- Tab: Agendamento & Controle -->
                <div class="tab-pane fade" id="adm-live" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-md-7">
                            <div class="adm-section-title">Agendamento (1 rodada por dia)</div>
                            <p style="font-size:12px;color:var(--text-2);margin-bottom:12px">00:01 (Brasília) libera a rodada do dia. Sem relógio: as picks avançam quando alguém escolhe.</p>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="field-label">Dia 01 (DD/MM/AAAA)</label>
                                    <input type="text" id="admDailyStart" class="field-input" placeholder="dd/mm/aaaa">
                                </div>
                                <div class="col-sm-6">
                                    <label class="field-label">Previsão de término</label>
                                    <input type="text" id="admDailyEnd" class="field-input" readonly style="opacity:.6">
                                </div>
                                <div class="col-12">
                                    <button class="btn-amber" onclick="saveDailySchedule()"><i class="bi bi-calendar-check"></i> Salvar agendamento</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="adm-section-title">Controle ao vivo</div>
                            <div class="d-flex flex-column gap-2" style="max-width:260px">
                                <button class="btn-amber" onclick="adminOpenNextRoundNow()"><i class="bi bi-lightning-charge"></i> Abrir rodada agora</button>
                                <button class="btn-red" onclick="finalizeDraft()"><i class="bi bi-flag"></i> Finalizar draft</button>
                            </div>
                            <p style="font-size:11px;color:var(--text-2);margin-top:10px">Como admin, você pode registrar a pick de qualquer time pelo botão <strong>Escolher</strong> na tabela do pool.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Draft board: na vez / a seguir / última escolha -->
    <section class="clock-board" id="clockBoard">
        <div class="clock-cell clock-now"><div class="cell-label">Na vez</div><div class="state-empty" style="padding:8px 0"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div></div>
        <div class="clock-cell"><div class="cell-label">A seguir</div></div>
        <div class="clock-cell"><div class="cell-label">Última escolha</div></div>
    </section>

    <div class="row g-4">
        <!-- Pool de jogadores (cards + filtros) -->
        <div class="col-lg-7">
            <div class="panel-card">
                <div class="panel-card-head">
                    <div class="panel-card-title"><i class="bi bi-people-fill" style="color:var(--red);margin-right:6px"></i>Pool de Jogadores</div>
                    <span style="font-size:11px;color:var(--text-2)" id="poolMeta"></span>
                </div>
                <div class="panel-card-body">
                    <input type="text" id="poolSearch" class="search-input mb-3" placeholder="Buscar jogador por nome…">
                    <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                        <div class="d-flex flex-wrap gap-2" id="posChips">
                            <span class="filter-chip active" data-pos="">Todos</span>
                            <span class="filter-chip" data-pos="PG">PG</span>
                            <span class="filter-chip" data-pos="SG">SG</span>
                            <span class="filter-chip" data-pos="SF">SF</span>
                            <span class="filter-chip" data-pos="PF">PF</span>
                            <span class="filter-chip" data-pos="C">C</span>
                        </div>
                        <div class="ms-auto d-flex gap-2 align-items-center flex-wrap">
                            <select id="poolSortSelect" class="filter-select" style="width:auto">
                                <option value="ovr">OVR ↓</option>
                                <option value="ovr_asc">OVR ↑</option>
                                <option value="age">Idade ↓</option>
                                <option value="age_asc">Idade ↑</option>
                                <option value="name">Nome A-Z</option>
                            </select>
                            <label class="filter-check" style="white-space:nowrap"><input type="checkbox" id="poolOnlyAvailable" checked> Disponíveis</label>
                        </div>
                    </div>
                    <div class="pool-grid" id="poolGrid"><div class="state-empty"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div></div>
                    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2 pag-wrap" id="poolPagination"></div>
                </div>
            </div>
        </div>

        <!-- Ordem (snake) -->
        <div class="col-lg-5">
            <div class="panel-card">
                <div class="panel-card-head">
                    <div class="panel-card-title"><i class="bi bi-list-ol" style="color:var(--red);margin-right:6px"></i>Ordem do Draft (Snake)</div>
                </div>
                <div class="panel-card-body">
                    <div id="snakeBoard" style="max-height:720px;overflow-y:auto"><div class="state-empty"><i class="bi bi-hourglass-split"></i><p>Carregando…</p></div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Elencos em montagem (todos os times, full width) -->
    <div class="panel-card mt-4">
        <div class="panel-card-head">
            <div class="panel-card-title"><i class="bi bi-people-fill" style="color:var(--red);margin-right:6px"></i>Elencos em Montagem</div>
            <span style="font-size:11px;color:var(--text-2)" id="rosterMeta"></span>
        </div>
        <div class="panel-card-body">
            <div class="rosters-grid" id="rosterGrid"><div class="state-empty"><i class="bi bi-inbox"></i><p>Nenhum elenco montado ainda.</p></div></div>
        </div>
    </div>

</div><!-- .app-wrap -->

</main>
</div><!-- .app -->

<!-- Detalhe do jogador -->
<div class="offcanvas offcanvas-end pd-offcanvas" tabindex="-1" id="playerDetail" aria-labelledby="playerDetailLabel">
    <div id="playerDetailBody"></div>
</div>

<?php if ($isAdmin): ?>
<!-- ══════ Modais Admin ══════ -->

<!-- Add Player Modal -->
<div class="modal fade" id="addPlayerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="addPlayerForm">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar Jogador</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="field-label">Nome</label>
                            <input type="text" name="name" class="field-input" required placeholder="Nome completo">
                        </div>
                        <div class="col-sm-6">
                            <label class="field-label">Posição</label>
                            <select name="position" class="field-input">
                                <option value="PG">PG</option>
                                <option value="SG">SG</option>
                                <option value="SF" selected>SF</option>
                                <option value="PF">PF</option>
                                <option value="C">C</option>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="field-label">Idade</label>
                            <input type="number" name="age" min="16" max="45" class="field-input" required placeholder="22">
                        </div>
                        <div class="col-sm-3">
                            <label class="field-label">OVR</label>
                            <input type="number" name="ovr" min="40" max="99" class="field-input" required placeholder="75">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn-red">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Player Modal -->
<div class="modal fade" id="editPlayerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editPlayerForm">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Jogador</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="player_id" id="editPlayerId">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="field-label">Nome</label>
                            <input type="text" name="name" id="editPlayerName" class="field-input" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="field-label">Posição</label>
                            <select name="position" id="editPlayerPosition" class="field-input">
                                <option value="PG">PG</option>
                                <option value="SG">SG</option>
                                <option value="SF">SF</option>
                                <option value="PF">PF</option>
                                <option value="C">C</option>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="field-label">Idade</label>
                            <input type="number" name="age" id="editPlayerAge" min="16" max="45" class="field-input" required>
                        </div>
                        <div class="col-sm-3">
                            <label class="field-label">OVR</label>
                            <input type="number" name="ovr" id="editPlayerOvr" min="40" max="99" class="field-input" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn-red">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import CSV Modal -->
<div class="modal fade" id="importCSVModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="importCSVForm" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Importar Jogadores via CSV</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p style="font-size:12px;color:var(--text-2);margin-bottom:14px">Formato: <code style="color:var(--red)">name,position,age,ovr</code>. Use o template para evitar erros.</p>
                    <div class="mb-3">
                        <label class="field-label">Arquivo CSV</label>
                        <input type="file" name="csv_file" class="field-input" accept=".csv" required style="padding:6px 10px;cursor:pointer">
                    </div>
                    <button type="button" class="btn-ghost" onclick="downloadCSVTemplate()"><i class="bi bi-download"></i> Baixar Template</button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn-red">Importar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const TOKEN = '<?php echo htmlspecialchars($token, ENT_QUOTES); ?>';
        const API_URL = 'api/initdraft.php';
        const USER_TEAM_ID = <?php echo $userTeamId ? (int)$userTeamId : 'null'; ?>;
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;

        const state = {
            session: null,
            order: [],
            teams: [],
            pool: [],
            canEditOrder: false,
        };

        const adminState = {
            manualOrder: [],
            orderDirty: false,
            poolSearch: '',
            poolPage: 1,
            poolPageSize: 12,
            autoOpened: false,
        };

        const uiState = {
            soundEnabled: false,
            lastPickId: null,
            poolSearch: '',
            poolPosition: '',
            poolOnlyAvailable: true,
            poolSort: 'ovr',
            poolPage: 1,
            poolPageSize: 16,
        };

        const elements = {
            leagueName: document.getElementById('leagueName'),
            statGrid: document.getElementById('statGrid'),
            clockBoard: document.getElementById('clockBoard'),
            snakeBoard: document.getElementById('snakeBoard'),
            poolGrid: document.getElementById('poolGrid'),
            poolMeta: document.getElementById('poolMeta'),
            rosterGrid: document.getElementById('rosterGrid'),
            rosterMeta: document.getElementById('rosterMeta'),
            toggleSoundButton: document.getElementById('toggleSoundButton'),
            poolSearch: document.getElementById('poolSearch'),
            poolSortSelect: document.getElementById('poolSortSelect'),
            poolPagination: document.getElementById('poolPagination'),
            feedback: document.getElementById('feedback'),
        };

        // ── Helpers ─────────────────────────────────────
        const esc = s => (s || '').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

        function teamLabel(pick) {
            if (!pick) return '—';
            return `${esc(pick.team_city || '')} ${esc(pick.team_name || '')}`.trim();
        }

        function showMessage(message, type = 'success') {
            elements.feedback.innerHTML = `
                <div class="fb-alert ${type}">
                    <span>${message}</span>
                    <button class="fb-close" onclick="this.parentElement.remove()"><i class="bi bi-x"></i></button>
                </div>`;
            setTimeout(() => { const el = elements.feedback.firstElementChild; if (el) el.remove(); }, 5000);
        }

        // ── Helpers de exibição ─────────────────────────
        const POS_LIST = ['PG','SG','SF','PF','C'];
        function posClass(p) { return POS_LIST.includes(p) ? `pos-${p}` : 'pos-SF'; }
        function ovrClass(v) { v = Number(v) || 0; return v >= 85 ? 'ovr-elite' : v >= 78 ? 'ovr-good' : v >= 68 ? 'ovr-mid' : 'ovr-low'; }
        function roundSize() { return state.order.filter(p => Number(p.round) === 1).length || state.teams.length || 0; }
        function globalPickNo(pick) { const rs = roundSize() || 1; return (Number(pick.round) - 1) * rs + Number(pick.pick_position); }
        function reactionChips(pick) {
            const reactions = Array.isArray(pick.reactions) ? pick.reactions : [];
            const mine = reactions.find(r => r.mine)?.emoji || null;
            const counts = Object.fromEntries(reactions.map(r => [r.emoji, r.count]));
            return ['👍','❤️','😂','😮','😢','😡'].map(e => {
                const cnt = counts[e] || 0;
                const cls = mine === e ? 'reaction-chip active' : 'reaction-chip';
                return `<span class="${cls}" onclick="event.stopPropagation();toggleReaction(${pick.id}, '${encodeURIComponent(e)}')">${e}${cnt ? ' <span class="reaction-count">' + cnt + '</span>' : ''}</span>`;
            }).join('');
        }

        // ── Hero stats ──────────────────────────────────
        function renderStats() {
            const session = state.session;
            if (!session) return;
            elements.leagueName.textContent = session.league || '-';
            const drafted = state.order.filter((p) => p.picked_player_id).length;
            const total = state.order.length || (session.total_rounds ?? 0) * (state.teams.length || 0);
            const progress = total ? Math.round((drafted / total) * 100) : 0;
            const currentPick = getCurrentPick();
            const statusLabel = { setup: 'Configuração', in_progress: 'Em andamento', completed: 'Concluído' }[session.status] || session.status || '—';
            elements.statGrid.innerHTML = `
                <div class="stat-card"><div class="stat-label">Status</div><div class="status-pill ${session.status}">${statusLabel}</div></div>
                <div class="stat-card"><div class="stat-label">Rodada</div><div class="stat-value">${session.current_round ?? '—'} / ${session.total_rounds ?? '—'}</div></div>
                <div class="stat-card"><div class="stat-label">Na vez</div><div class="stat-value" style="font-size:.95rem">${currentPick ? teamLabel(currentPick) : '—'}</div></div>
                <div class="stat-card"><div class="stat-label">Progresso</div><div class="stat-value">${drafted} / ${total} <span style="font-size:.8rem;color:var(--text-2)">(${progress}%)</span></div></div>
            `;
        }

        // ── Clock board (na vez / a seguir / última) ────
        function renderClockBoard(currentPick) {
            const board = elements.clockBoard;
            if (!board) return;
            const drafted = state.order.filter((p) => p.picked_player_id).length;
            const total = state.order.length || 0;
            const progress = total ? Math.round((drafted / total) * 100) : 0;

            // Na vez
            let nowHtml;
            if (currentPick) {
                nowHtml = `
                    <div class="cell-label"><span class="snake-onclock"><span class="dot"></span></span> Na vez</div>
                    <div class="clock-team">
                        <img src="${currentPick.team_photo || '/img/default-team.png'}" alt="${esc(currentPick.team_name || '')}" onerror="this.src='/img/default-team.png'">
                        <div>
                            <div class="clock-team-name">${teamLabel(currentPick)}</div>
                            <div class="clock-team-gm">GM: ${esc(currentPick.team_owner || 'Sem GM')}</div>
                            <span class="clock-pick-tag"><i class="bi bi-hourglass-split"></i> Rodada ${currentPick.round} · Pick ${currentPick.pick_position} · #${globalPickNo(currentPick)} geral</span>
                        </div>
                    </div>`;
            } else {
                const done = state.session?.status === 'completed';
                nowHtml = `<div class="cell-label">Na vez</div><div class="state-empty" style="padding:10px 0"><i class="bi ${done ? 'bi-trophy' : 'bi-hourglass-split'}"></i><p>${done ? 'Draft concluído 🏆' : 'Aguardando início'}</p></div>`;
            }

            // A seguir (próximos 4)
            const upcoming = state.order.filter((p) => !p.picked_player_id).slice(1, 6);
            const nextHtml = `
                <div class="cell-label"><i class="bi bi-arrow-down-up"></i> A seguir</div>
                <div class="clock-next-list">
                    ${upcoming.length ? upcoming.map((p) => `
                        <div class="clock-next-item">
                            <span class="clock-next-rank">#${globalPickNo(p)}</span>
                            <img src="${p.team_photo || '/img/default-team.png'}" onerror="this.src='/img/default-team.png'" alt="">
                            <div><div class="clock-next-name">${teamLabel(p)}</div><div class="clock-next-meta">R${p.round} · Pick ${p.pick_position}</div></div>
                        </div>`).join('') : '<div class="state-empty" style="padding:6px 0"><i class="bi bi-inbox"></i><p>Sem próximos.</p></div>'}
                </div>`;

            // Última escolha
            const done = state.order.filter((p) => p.picked_player_id);
            const last = done.length ? done.reduce((a, b) => globalPickNo(b) > globalPickNo(a) ? b : a) : null;
            const lastHtml = `
                <div class="cell-label"><i class="bi bi-check2-circle"></i> Última escolha</div>
                ${last ? `
                    <div class="d-flex align-items-center gap-3">
                        <div class="ovr-chip ${ovrClass(last.player_ovr)}"><span class="ovr-num">${last.player_ovr ?? '—'}</span><span class="ovr-lbl">OVR</span></div>
                        <div style="min-width:0">
                            <div class="clock-last-player">${esc(last.player_name || '—')}</div>
                            <div class="clock-last-team"><span class="pos-badge ${posClass(last.player_position)}">${esc(last.player_position || '')}</span> → ${teamLabel(last)}</div>
                        </div>
                    </div>` : '<div class="clock-last-empty">Nenhuma escolha ainda.</div>'}`;

            board.innerHTML = `
                <div class="clock-cell clock-now" id="clockNowCell">${nowHtml}</div>
                <div class="clock-cell">${nextHtml}</div>
                <div class="clock-cell">${lastHtml}</div>
                <div class="clock-cell board-progress">
                    <div class="d-flex justify-content-between mb-2" style="font-size:11px;color:var(--text-2)">
                        <span>${drafted} de ${total} escolhas feitas</span><span style="color:var(--red);font-weight:700">${progress}%</span>
                    </div>
                    <div class="board-progress-bar"><div class="board-progress-fill" style="width:${progress}%"></div></div>
                </div>`;
        }

        // ── Snake board (ordem + escolhas) ──────────────
        function renderSnakeBoard(currentPick) {
            const el = elements.snakeBoard;
            if (!el) return;
            if (!state.order.length) { el.innerHTML = '<div class="state-empty"><i class="bi bi-inbox"></i><p>Ordem ainda não definida.</p></div>'; return; }
            const rounds = [...new Set(state.order.map((p) => Number(p.round)))].sort((a, b) => a - b);
            el.innerHTML = rounds.map((r) => {
                const picks = state.order.filter((p) => Number(p.round) === r).sort((a, b) => a.pick_position - b.pick_position);
                const even = r % 2 === 0;
                const rows = picks.map((pick) => {
                    const picked = !!pick.picked_player_id;
                    const isCurrent = currentPick && pick.id === currentPick.id;
                    let body;
                    if (picked) {
                        body = `
                            <div class="snake-team">${teamLabel(pick)}</div>
                            <div class="snake-player"><span class="pos-badge ${posClass(pick.player_position)}">${esc(pick.player_position || '')}</span> ${esc(pick.player_name)} <span style="color:var(--text-3);font-weight:600">${pick.player_ovr ?? ''}</span></div>
                            <div class="snake-react">${reactionChips(pick)}</div>`;
                    } else if (isCurrent) {
                        body = `<div class="snake-team">${teamLabel(pick)}</div><div class="snake-onclock"><span class="dot"></span> Escolhendo agora…</div>`;
                    } else {
                        body = `<div class="snake-team">${teamLabel(pick)}</div><div style="font-size:12px;color:var(--text-3)">Aguardando</div>`;
                    }
                    return `
                        <div class="snake-pick ${isCurrent ? 'is-current' : ''} ${picked ? 'is-done' : ''}">
                            <div class="snake-num">${globalPickNo(pick)}</div>
                            <img src="${pick.team_photo || '/img/default-team.png'}" onerror="this.src='/img/default-team.png'" alt="" style="width:30px;height:30px;border-radius:8px;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0">
                            <div class="snake-body">${body}</div>
                        </div>`;
                }).join('');
                return `
                    <div class="snake-round">
                        <div class="snake-round-head">
                            <span class="snake-round-title">Rodada ${r}</span>
                            <span class="snake-dir">${even ? '<i class="bi bi-arrow-left"></i> snake' : '<i class="bi bi-arrow-right"></i>'}</span>
                        </div>
                        ${rows}
                    </div>`;
            }).join('');
        }

        // ── Pool (cards + filtros + detalhe) ────────────
        function poolFilteredSorted() {
            const search = uiState.poolSearch.trim();
            const pos = uiState.poolPosition;
            const filtered = (state.pool || []).filter((p) => {
                const okS = !search || (p.name || '').toLowerCase().includes(search);
                const okP = !pos || p.position === pos || p.secondary_position === pos;
                const okA = !uiState.poolOnlyAvailable || p.draft_status !== 'drafted';
                return okS && okP && okA;
            });
            const [field, dir] = (uiState.poolSort || 'ovr').split('_');
            const asc = dir === 'asc';
            filtered.sort((a, b) => {
                let c = 0;
                if (field === 'ovr') c = (Number(a.ovr) || 0) - (Number(b.ovr) || 0);
                else if (field === 'age') c = (Number(a.age) || 0) - (Number(b.age) || 0);
                else if (field === 'name') return (a.name || '').toLowerCase().localeCompare((b.name || '').toLowerCase());
                return asc ? c : -c;
            });
            return filtered;
        }

        function renderPool(currentPick) {
            const grid = elements.poolGrid;
            if (!grid) return;
            const filtered = poolFilteredSorted();
            const total = filtered.length;
            const totalPages = Math.max(1, Math.ceil(total / uiState.poolPageSize));
            if (uiState.poolPage > totalPages) uiState.poolPage = totalPages;
            const start = (uiState.poolPage - 1) * uiState.poolPageSize;
            const items = filtered.slice(start, start + uiState.poolPageSize);

            elements.poolMeta.textContent = `${total} jogador${total === 1 ? '' : 'es'}`;
            if (!items.length) { grid.innerHTML = '<div class="state-empty" style="grid-column:1/-1"><i class="bi bi-search"></i><p>Nenhum jogador encontrado.</p></div>'; elements.poolPagination.innerHTML = ''; return; }

            const canPick = state.session?.status === 'in_progress' && (IS_ADMIN || (currentPick && USER_TEAM_ID && currentPick.team_id === USER_TEAM_ID));
            grid.innerHTML = items.map((p, i) => {
                const drafted = p.draft_status === 'drafted';
                const sec = (p.secondary_position && POS_LIST.includes(p.secondary_position)) ? `<span class="pos-badge ${posClass(p.secondary_position)}">${p.secondary_position}</span>` : '';
                const pickBtn = (!drafted && canPick) ? `<button class="btn-green pc-pick" onclick="event.stopPropagation();makePick(${p.id}, this)"><i class="bi bi-check2"></i> Escolher</button>` : '';
                const draftedTag = drafted ? '<span class="badge-drafted">Draftado</span>' : '';
                return `
                    <div class="player-card-wrap">
                        <div class="player-card ${drafted ? 'is-drafted' : ''}" onclick="openPlayerDetail(${p.id})">
                            <span class="pc-rank">#${start + i + 1}</span>
                            <div class="ovr-chip ${ovrClass(p.ovr)}"><span class="ovr-num">${p.ovr ?? '—'}</span><span class="ovr-lbl">OVR</span></div>
                            <div class="pc-body">
                                <div class="pc-name">${esc(p.name)}</div>
                                <div class="pc-meta"><span class="pos-badge ${posClass(p.position)}">${esc(p.position || '')}</span>${sec}<span>${p.age ? p.age + ' anos' : '—'}</span>${draftedTag}</div>
                            </div>
                        </div>
                        ${pickBtn}
                    </div>`;
            }).join('');

            elements.poolPagination.innerHTML = totalPages <= 1 ? '' : `
                <span style="font-size:11px;color:var(--text-2)">Pág. ${uiState.poolPage} de ${totalPages}</span>
                <div class="d-flex gap-2">
                    <button class="btn-ghost" style="padding:4px 10px;font-size:11px" ${uiState.poolPage === 1 ? 'disabled' : ''} onclick="changePoolPage(${uiState.poolPage - 1})">← Anterior</button>
                    <button class="btn-ghost" style="padding:4px 10px;font-size:11px" ${uiState.poolPage === totalPages ? 'disabled' : ''} onclick="changePoolPage(${uiState.poolPage + 1})">Próxima →</button>
                </div>`;
        }

        function changePoolPage(page) { uiState.poolPage = page; renderPool(getCurrentPick()); }

        function openPlayerDetail(playerId) {
            const p = (state.pool || []).find((x) => x.id === playerId);
            if (!p) return;
            const body = document.getElementById('playerDetailBody');
            const currentPick = getCurrentPick();
            const canPick = state.session?.status === 'in_progress' && (IS_ADMIN || (currentPick && USER_TEAM_ID && currentPick.team_id === USER_TEAM_ID));
            const drafted = p.draft_status === 'drafted';
            const teamsById = Object.fromEntries(state.teams.map((t) => [t.id, t]));
            const byTeam = drafted && p.drafted_by_team_id ? teamsById[p.drafted_by_team_id] : null;
            const sec = (p.secondary_position && POS_LIST.includes(p.secondary_position)) ? `<span class="pos-badge ${posClass(p.secondary_position)}">${p.secondary_position}</span>` : '';
            const section = (title, val) => val ? `<div class="pd-section"><h6>${esc(title)}</h6><p>${esc(val)}</p></div>` : '';
            body.innerHTML = `
                <div class="pd-hero">
                    <div class="offcanvas-header p-0" style="position:absolute;top:10px;right:12px"><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button></div>
                    ${p.photo_url ? `<img src="${p.photo_url}" onerror="this.style.display='none'" alt="">` : `<div class="ovr-chip ${ovrClass(p.ovr)}" style="width:72px;height:72px;border-radius:16px"><span class="ovr-num" style="font-size:26px">${p.ovr ?? '—'}</span><span class="ovr-lbl">OVR</span></div>`}
                    <div style="min-width:0">
                        <div class="pd-name">${esc(p.name)}</div>
                        <div class="pd-sub"><span class="pos-badge ${posClass(p.position)}">${esc(p.position || '')}</span>${sec}<span>${p.age ? p.age + ' anos' : ''}</span><span>OVR ${p.ovr ?? '—'}</span></div>
                    </div>
                </div>
                <div class="pd-section"><h6>Status</h6><p>${drafted ? ('Draftado' + (byTeam ? ' por ' + esc((byTeam.city || '') + ' ' + (byTeam.name || '')) : '')) : 'Disponível no pool'}</p></div>
                ${section('Bio', p.bio)}
                ${section('Pontos fortes', p.strengths)}
                ${section('Pontos fracos', p.weaknesses)}
                ${(!drafted && canPick) ? `<div class="pd-section"><button class="btn-green" style="width:100%;justify-content:center" onclick="makePick(${p.id}, this)"><i class="bi bi-check2-circle"></i> Escolher ${esc(p.name)}</button></div>` : ''}
            `;
            const oc = bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('playerDetail'));
            oc.show();
        }

        // ── Elencos (rosters) — todos os times da liga ──
        function renderRosters() {
            const teams = (state.teams || []).slice().sort((a, b) =>
                `${a.city || ''} ${a.name || ''}`.localeCompare(`${b.city || ''} ${b.name || ''}`));
            const byTeam = {};
            state.order.filter((p) => p.picked_player_id).forEach((pick) => {
                (byTeam[pick.team_id] = byTeam[pick.team_id] || []).push(pick);
            });
            const totalPicks = state.order.filter((p) => p.picked_player_id).length;
            if (elements.rosterMeta) elements.rosterMeta.textContent = `${teams.length} times · ${totalPicks} escolhas`;
            if (!teams.length) { elements.rosterGrid.innerHTML = '<div class="state-empty"><i class="bi bi-inbox"></i><p>Nenhum time na liga.</p></div>'; return; }

            elements.rosterGrid.innerHTML = teams.map((team) => {
                const players = (byTeam[team.id] || []).slice().sort((a, b) => (Number(b.player_ovr) || 0) - (Number(a.player_ovr) || 0));
                const ovrs = players.map((p) => Number(p.player_ovr) || 0).filter(Boolean);
                const avg = ovrs.length ? Math.round(ovrs.reduce((a, b) => a + b, 0) / ovrs.length) : null;
                const body = players.length
                    ? `<ul class="roster-list">${players.map((pick) => {
                        const age = (pick.player_age != null && pick.player_age !== '') ? `${pick.player_age}a` : '';
                        return `<li><span class="pos-badge ${posClass(pick.player_position)}">${esc(pick.player_position || '')}</span> <span style="color:var(--text)">${esc(pick.player_name)}</span> <span style="color:var(--text-3);font-size:11px">${age}</span> <span class="rl-ovr">${pick.player_ovr ?? '—'}</span></li>`;
                    }).join('')}</ul>`
                    : '<div class="roster-empty">Sem escolhas ainda</div>';
                return `
                    <div class="roster-card">
                        <div class="roster-head">
                            <div class="team-chip">
                                <img src="${team.photo_url || '/img/default-team.png'}" onerror="this.src='/img/default-team.png'" alt="">
                                <div><div class="team-chip-name">${esc(team.city || '')} ${esc(team.name || '')}</div><div class="team-chip-gm">${esc(team.owner_name || 'Sem GM')}</div></div>
                            </div>
                            <div class="roster-badges"><span class="roster-stat">${players.length}</span>${avg != null ? `<span class="roster-stat">Ø${avg}</span>` : ''}</div>
                        </div>
                        ${body}
                    </div>`;
            }).join('');
        }

        function getCurrentPick() {
            return state.order.find((pick) => !pick.picked_player_id);
        }

        // ── Load & refresh ──────────────────────────────
        async function loadState(fromAuto = false) {
            try {
                const [stateRes, poolRes] = await Promise.all([
                    fetch(`${API_URL}?action=state&token=${TOKEN}`).then((r) => r.json()),
                    fetch(`${API_URL}?action=pool&token=${TOKEN}`).then((r) => r.json()),
                ]);
                if (!stateRes.success) throw new Error(stateRes.error || 'Erro ao carregar estado');
                state.session = stateRes.session;
                state.order = stateRes.order || [];
                state.teams = stateRes.teams || [];
                state.pool = poolRes.success ? poolRes.players : [];
                state.canEditOrder = !!stateRes.can_edit_order;

                renderStats();
                const currentPick = getCurrentPick();
                handlePickChange(currentPick);
                renderClockBoard(currentPick);
                renderSnakeBoard(currentPick);
                renderPool(currentPick);
                renderRosters();

                if (IS_ADMIN) renderAdmin(fromAuto);
            } catch (error) {
                if (elements.poolGrid) elements.poolGrid.innerHTML = `<div class="state-empty" style="grid-column:1/-1;color:#ef4444"><i class="bi bi-exclamation-triangle-fill"></i><p>${esc(error.message)}</p></div>`;
            }
        }

        function setupAutoRefresh() {
            const isMobile = window.matchMedia('(max-width: 768px)').matches;
            if (!isMobile) {
                setInterval(() => {
                    if (document.visibilityState === 'visible') loadState(true);
                }, 10000);
            }
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') loadState(true);
            });
        }

        // ── Picks & reactions (live) ────────────────────
        async function makePick(playerId, btn) {
            if (!confirm('Confirmar a escolha deste jogador?')) return;
            if (btn && btn.disabled) return;
            const originalHtml = btn ? btn.innerHTML : null;
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Enviando...';
            }
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'make_pick', token: TOKEN, player_id: playerId })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao registrar pick');
                await loadState();
            } catch (error) {
                alert(error.message);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            }
        }

        async function reactPick(pickId, emoji) {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'react_pick', token: TOKEN, pick_id: pickId, emoji })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao reagir');
            await loadState();
        }

        async function removeReaction(pickId) {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'remove_reaction', token: TOKEN, pick_id: pickId })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao remover reação');
            await loadState();
        }

        async function toggleReaction(pickId, emoji) {
            try {
                const emo = decodeURIComponent(emoji);
                const pick = state.order.find(p => p.id === pickId);
                const mineEmoji = (pick && Array.isArray(pick.reactions)) ? (pick.reactions.find(r => r.mine)?.emoji || null) : null;
                if (mineEmoji === emo) {
                    await removeReaction(pickId);
                } else {
                    await reactPick(pickId, emo);
                }
            } catch (error) {
                alert(error.message);
            }
        }

        function handlePickChange(currentPick) {
            const pickId = currentPick?.id || null;
            if (pickId && uiState.lastPickId && pickId !== uiState.lastPickId) {
                const cell = document.getElementById('clockNowCell');
                if (cell) {
                    cell.classList.remove('clock-flash');
                    void cell.offsetWidth;
                    cell.classList.add('clock-flash');
                }
                if (uiState.soundEnabled) playBeep();
            }
            if (pickId) uiState.lastPickId = pickId;
        }

        function playBeep() {
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.value = 740;
                gain.gain.value = 0.06;
                oscillator.connect(gain);
                gain.connect(audioCtx.destination);
                oscillator.start();
                oscillator.stop(audioCtx.currentTime + 0.25);
                oscillator.onended = () => audioCtx.close();
            } catch (error) {
                console.warn('Audio não disponível');
            }
        }

        function toggleSound() {
            uiState.soundEnabled = !uiState.soundEnabled;
            const btn = elements.toggleSoundButton;
            if (btn) {
                btn.style.color = uiState.soundEnabled ? 'var(--amber)' : '';
                btn.style.borderColor = uiState.soundEnabled ? 'rgba(245,158,11,.4)' : '';
                const icon = btn.querySelector('i');
                if (icon) {
                    icon.classList.toggle('bi-volume-mute', !uiState.soundEnabled);
                    icon.classList.toggle('bi-volume-up', uiState.soundEnabled);
                }
            }
        }

        // ── Pool filters/sort (live) ────────────────────
        elements.poolSearch?.addEventListener('input', (event) => {
            uiState.poolSearch = event.target.value.toLowerCase();
            uiState.poolPage = 1;
            renderPool(getCurrentPick());
        });

        document.getElementById('posChips')?.addEventListener('click', (e) => {
            const chip = e.target.closest('.filter-chip');
            if (!chip) return;
            uiState.poolPosition = chip.dataset.pos || '';
            document.querySelectorAll('#posChips .filter-chip').forEach((c) => c.classList.toggle('active', c === chip));
            uiState.poolPage = 1;
            renderPool(getCurrentPick());
        });

        elements.poolSortSelect?.addEventListener('change', (e) => {
            uiState.poolSort = e.target.value;
            uiState.poolPage = 1;
            renderPool(getCurrentPick());
        });

        document.getElementById('poolOnlyAvailable')?.addEventListener('change', (e) => {
            uiState.poolOnlyAvailable = !!e.target.checked;
            uiState.poolPage = 1;
            renderPool(getCurrentPick());
        });

        elements.toggleSoundButton?.addEventListener('click', toggleSound);

<?php if ($isAdmin): ?>
        // ══════════════════════════════════════════════
        //  ADMIN
        // ══════════════════════════════════════════════
        const admElements = {
            tokenDisplay: document.getElementById('tokenDisplay'),
            panel: document.getElementById('adminPanel'),
            toggleBtn: document.getElementById('toggleAdminButton'),
            progressLabel: document.getElementById('admProgressLabel'),
            progressPercent: document.getElementById('admProgressPercent'),
            progressBar: document.getElementById('admProgressBar'),
            totalRounds: document.getElementById('admTotalRounds'),
            startArea: document.getElementById('admStartArea'),
            orderHint: document.getElementById('admOrderHint'),
            manualOrderList: document.getElementById('admManualOrderList'),
            shuffleBtn: document.getElementById('admShuffleBtn'),
            resetOrderBtn: document.getElementById('admResetOrderBtn'),
            saveOrderBtn: document.getElementById('admSaveOrderBtn'),
            poolSearch: document.getElementById('admPoolSearch'),
            poolTable: document.getElementById('admPoolTable'),
            poolPagination: document.getElementById('admPoolPagination'),
            dailyStart: document.getElementById('admDailyStart'),
            dailyEnd: document.getElementById('admDailyEnd'),
        };

        if (admElements.tokenDisplay) admElements.tokenDisplay.textContent = TOKEN;

        function copyToken() {
            navigator.clipboard.writeText(TOKEN).then(() => showMessage('Token copiado para a área de transferência.'));
        }

        function toggleAdminPanel() {
            if (!admElements.panel) return;
            const willOpen = admElements.panel.classList.contains('d-none');
            admElements.panel.classList.toggle('d-none');
            admElements.toggleBtn?.classList.toggle('btn-red', !willOpen);
            admElements.toggleBtn?.classList.toggle('btn-ghost', willOpen);
            if (willOpen) admElements.panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function renderAdmin(fromAuto = false) {
            renderAdminProgress();
            renderStartArea();
            renderAdmPool();
            syncScheduleInputs();
            // Não sobrescrever a ordem em edição durante um refresh automático
            if (!adminState.orderDirty) {
                adminState.manualOrder = getRoundOneOrder();
                renderManualOrder();
            }
            updateOrderControls();

            // Na primeira carga, abre o painel automaticamente se ainda está em configuração
            if (!adminState.autoOpened) {
                adminState.autoOpened = true;
                if (state.session?.status === 'setup') toggleAdminPanel();
            }
        }

        function syncScheduleInputs() {
            const session = state.session;
            if (!session || !admElements.dailyStart) return;
            if (document.activeElement === admElements.dailyStart) return;
            admElements.dailyStart.value = formatDateBr(session.daily_schedule_start_date || '');
            refreshScheduleEnd();
        }

        function renderAdminProgress() {
            const session = state.session;
            if (!session) return;
            const drafted = state.order.filter((p) => p.picked_player_id).length;
            const total = state.order.length || (session.total_rounds ?? 0) * (state.teams.length || 0);
            const progress = total ? Math.round((drafted / total) * 100) : 0;
            admElements.progressLabel.textContent = `${drafted} de ${total} picks · Liga ${session.league}`;
            admElements.progressPercent.textContent = `${progress}%`;
            admElements.progressBar.style.width = `${progress}%`;
            if (admElements.totalRounds && document.activeElement !== admElements.totalRounds) {
                admElements.totalRounds.value = session.total_rounds ?? '';
            }
        }

        function renderStartArea() {
            const session = state.session;
            if (!session || !admElements.startArea) return;
            if (session.status === 'setup') {
                const ready = state.order.length > 0;
                admElements.startArea.innerHTML = ready
                    ? `<button class="btn-green" onclick="startDraft()"><i class="bi bi-play-fill"></i> Iniciar draft</button>`
                    : `<div style="font-size:12px;color:var(--text-2)">Defina e salve a ordem para liberar o início.</div>`;
            } else if (session.status === 'in_progress') {
                admElements.startArea.innerHTML = `<span class="status-pill in_progress">Draft em andamento</span>`;
            } else {
                admElements.startArea.innerHTML = `<span class="status-pill completed">Draft concluído</span>`;
            }
        }

        function getRoundOneOrder() {
            if (!state.order.length) return state.teams.map((team) => team.id);
            return state.order
                .filter((pick) => pick.round === 1)
                .sort((a, b) => a.pick_position - b.pick_position)
                .map((pick) => pick.team_id);
        }

        function updateOrderControls() {
            const canEdit = state.canEditOrder;
            [admElements.shuffleBtn, admElements.resetOrderBtn, admElements.saveOrderBtn].forEach(b => { if (b) b.disabled = !canEdit; });
            if (admElements.orderHint) {
                admElements.orderHint.textContent = canEdit
                    ? 'Ajuste a posição de cada time e clique em Salvar ordem. As demais rodadas seguem o formato snake.'
                    : 'Ordem bloqueada após a primeira pick.';
            }
        }

        function renderManualOrder() {
            if (!admElements.manualOrderList) return;
            if (!adminState.manualOrder.length) {
                admElements.manualOrderList.innerHTML = '<div class="state-empty"><i class="bi bi-inbox"></i><p>Sem times na liga.</p></div>';
                return;
            }
            const teamsById = Object.fromEntries(state.teams.map((team) => [team.id, team]));
            const total = adminState.manualOrder.length;
            const canEdit = state.canEditOrder;
            const options = Array.from({ length: total }, (_, idx) => idx + 1);
            admElements.manualOrderList.innerHTML = adminState.manualOrder
                .map((teamId, index) => {
                    const team = teamsById[teamId] || {};
                    const controls = canEdit ? `
                        <select class="field-input manual-position-select" onchange="updateManualOrderPosition(${teamId}, this.value)">
                            ${options.map((pos) => `<option value="${pos}" ${pos === index + 1 ? 'selected' : ''}>#${pos}</option>`).join('')}
                        </select>` : `<div class="order-rank">${index + 1}</div>`;
                    return `
                        <div class="manual-order-row">
                            ${controls}
                            <div class="d-flex align-items-center gap-2">
                                <img src="${team.photo_url || '/img/default-team.png'}" alt="${esc(team.name || 'Time')}" onerror="this.src='/img/default-team.png'" style="width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md)">
                                <div>
                                    <div class="team-chip-name">${esc(team.city || '')} ${esc(team.name || '')}</div>
                                    <div class="team-chip-gm">${esc(team.owner_name || 'Sem GM')}</div>
                                </div>
                            </div>
                            <div class="order-actions">
                                <button class="order-btn" ${!canEdit || index === 0 ? 'disabled' : ''} onclick="moveManualTeam(${index}, -1)"><i class="bi bi-arrow-up"></i></button>
                                <button class="order-btn" ${!canEdit || index === total - 1 ? 'disabled' : ''} onclick="moveManualTeam(${index}, 1)"><i class="bi bi-arrow-down"></i></button>
                            </div>
                        </div>`;
                })
                .join('');
        }

        function updateManualOrderPosition(teamId, position) {
            const newPos = parseInt(position, 10);
            if (!Number.isFinite(newPos)) return;
            const index = adminState.manualOrder.indexOf(parseInt(teamId, 10));
            if (index === -1) return;
            const updated = [...adminState.manualOrder];
            const [removed] = updated.splice(index, 1);
            updated.splice(newPos - 1, 0, removed);
            adminState.manualOrder = updated;
            adminState.orderDirty = true;
            renderManualOrder();
        }

        function moveManualTeam(index, delta) {
            const newIndex = index + delta;
            if (newIndex < 0 || newIndex >= adminState.manualOrder.length) return;
            const updated = [...adminState.manualOrder];
            const [removed] = updated.splice(index, 1);
            updated.splice(newIndex, 0, removed);
            adminState.manualOrder = updated;
            adminState.orderDirty = true;
            renderManualOrder();
        }

        function shuffleOrder() {
            if (!state.canEditOrder) return;
            const arr = [...adminState.manualOrder];
            for (let i = arr.length - 1; i > 0; i -= 1) {
                const j = Math.floor(Math.random() * (i + 1));
                [arr[i], arr[j]] = [arr[j], arr[i]];
            }
            adminState.manualOrder = arr;
            adminState.orderDirty = true;
            renderManualOrder();
            showMessage('Ordem sorteada. Revise e clique em Salvar ordem.', 'info');
        }

        function resetManualOrder() {
            adminState.manualOrder = getRoundOneOrder();
            adminState.orderDirty = false;
            renderManualOrder();
        }

        async function saveManualOrder() {
            if (!state.canEditOrder) { showMessage('A ordem não pode mais ser alterada após a primeira pick.', 'warning'); return; }
            if (!adminState.manualOrder.length) { showMessage('Defina a ordem antes de salvar.', 'warning'); return; }
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set_manual_order', token: TOKEN, team_ids: adminState.manualOrder }),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao salvar ordem');
                adminState.orderDirty = false;
                showMessage('Ordem salva com sucesso.');
                await loadState();
            } catch (error) {
                showMessage(error.message, 'danger');
            }
        }

        async function saveTotalRounds() {
            const value = parseInt(admElements.totalRounds?.value, 10);
            if (Number.isNaN(value) || value < 1 || value > 10) { showMessage('Informe um número de rodadas entre 1 e 10.', 'warning'); return; }
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set_total_rounds', token: TOKEN, total_rounds: value }),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao atualizar rodadas');
                showMessage('Total de rodadas atualizado. Salve a ordem novamente para reconstruir as rodadas.', 'info');
                await loadState();
            } catch (error) {
                showMessage(error.message, 'danger');
            }
        }

        async function startDraft() {
            if (!confirm('Deseja iniciar o draft?')) return;
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'start', token: TOKEN }),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao iniciar');
                showMessage('Draft iniciado.');
                await loadState();
            } catch (error) {
                showMessage(error.message, 'danger');
            }
        }

        async function finalizeDraft() {
            if (!confirm('Deseja finalizar o draft? Certifique-se de que todas as picks foram feitas.')) return;
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'finalize', token: TOKEN }),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao finalizar');
                showMessage('Draft finalizado com sucesso.');
                await loadState();
            } catch (error) {
                showMessage(error.message, 'danger');
            }
        }

        async function adminOpenNextRoundNow() {
            if (!confirm('Abrir rodada imediatamente?')) return;
            try {
                const sessionId = state.session?.id;
                if (!sessionId) throw new Error('Sessão não carregada');
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'admin_open_next_round_now', session_id: sessionId })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Falha ao abrir rodada');
                showMessage('Rodada aberta.');
                await loadState();
            } catch (error) {
                showMessage(error.message, 'danger');
            }
        }

        // ── Agendamento (datas) ─────────────────────────
        function formatDateBr(isoDate) {
            if (!isoDate) return '';
            const [y, m, d] = isoDate.split('-');
            if (!y || !m || !d) return '';
            return `${d}/${m}/${y}`;
        }
        function parseDateBrToIso(brDate) {
            if (!brDate) return '';
            const parts = brDate.split('/');
            if (parts.length !== 3) return '';
            const [d, m, y] = parts.map((p) => p.trim());
            if (!d || !m || !y) return '';
            return `${y}-${m.padStart(2, '0')}-${d.padStart(2, '0')}`;
        }
        function computeScheduleEndDate(startDate, totalRounds) {
            if (!startDate) return '';
            const rounds = parseInt(totalRounds, 10);
            if (Number.isNaN(rounds) || rounds < 1) return '';
            const base = new Date(`${startDate}T00:00:00-03:00`);
            if (Number.isNaN(base.getTime())) return '';
            base.setDate(base.getDate() + (rounds - 1));
            const y = base.getFullYear();
            const m = String(base.getMonth() + 1).padStart(2, '0');
            const d = String(base.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        function refreshScheduleEnd() {
            const iso = parseDateBrToIso(admElements.dailyStart?.value || '');
            const computed = computeScheduleEndDate(iso, state.session?.total_rounds);
            if (admElements.dailyEnd) admElements.dailyEnd.value = formatDateBr(computed) || '—';
        }

        admElements.dailyStart?.addEventListener('input', refreshScheduleEnd);

        async function saveDailySchedule() {
            try {
                const startDate = parseDateBrToIso(admElements.dailyStart?.value || '');
                if (!startDate) { showMessage('Informe a data no formato dd/mm/aaaa.', 'warning'); return; }
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set_daily_schedule', token: TOKEN, enabled: 1, start_date: startDate }),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao salvar agendamento');
                showMessage('Agendamento salvo. A rodada do dia abre às 00:01 (Brasília) a partir do Dia 01.');
                await loadState();
            } catch (error) {
                showMessage(error.message, 'danger');
            }
        }

        // ── Admin pool management ───────────────────────
        admElements.poolSearch?.addEventListener('input', (e) => {
            adminState.poolSearch = e.target.value.toLowerCase();
            adminState.poolPage = 1;
            renderAdmPool();
        });

        function admFilteredPool() {
            const needle = adminState.poolSearch.trim();
            return (state.pool || []).filter((player) => {
                if (!needle) return true;
                return (player.name || '').toLowerCase().includes(needle) || (player.position || '').toLowerCase().includes(needle);
            });
        }

        function renderAdmPool() {
            if (!admElements.poolTable) return;
            const filtered = admFilteredPool();
            const isSetup = state.session?.status === 'setup';

            if (!filtered.length) {
                admElements.poolTable.innerHTML = '<tr><td colspan="7" class="state-empty"><i class="bi bi-inbox"></i><p>Nenhum jogador no pool.</p></td></tr>';
                admElements.poolPagination.innerHTML = '';
                return;
            }

            const totalPages = Math.max(1, Math.ceil(filtered.length / adminState.poolPageSize));
            if (adminState.poolPage > totalPages) adminState.poolPage = totalPages;
            const start = (adminState.poolPage - 1) * adminState.poolPageSize;
            const pageItems = filtered.slice(start, start + adminState.poolPageSize);

            admElements.poolTable.innerHTML = pageItems
                .map((player, index) => {
                    const drafted = player.draft_status === 'drafted';
                    const canManage = isSetup && !drafted;
                    const editBtn = canManage ? `<button class="btn-sm-icon amber" onclick="openEditPlayer(${player.id})"><i class="bi bi-pencil"></i></button>` : '';
                    const delBtn = canManage ? `<button class="btn-sm-icon danger" onclick="deleteInitDraftPlayer(${player.id}, '${esc((player.name || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'"))}')"><i class="bi bi-trash"></i></button>` : '';
                    const actions = (editBtn || delBtn) ? `<div style="display:flex;gap:4px;justify-content:flex-end">${editBtn} ${delBtn}</div>` : '<span style="color:var(--text-3)">—</span>';
                    return `
                        <tr>
                            <td>${start + index + 1}</td>
                            <td class="td-name">${esc(player.name)}</td>
                            <td>${esc(player.position)}</td>
                            <td style="font-weight:700;color:var(--text)">${player.ovr}</td>
                            <td>${player.age ?? '—'}</td>
                            <td><span class="${drafted ? 'badge-drafted' : 'badge-available'}">${drafted ? 'Drafted' : 'Disponível'}</span></td>
                            <td style="text-align:right">${actions}</td>
                        </tr>`;
                })
                .join('');

            admElements.poolPagination.innerHTML = `
                <span style="font-size:11px;color:var(--text-2)">${filtered.length} jogadores · Pág. ${adminState.poolPage} de ${totalPages}</span>
                <div class="d-flex gap-2">
                    <button class="btn-ghost" style="padding:4px 10px;font-size:11px" ${adminState.poolPage === 1 ? 'disabled' : ''} onclick="changeAdmPoolPage(${adminState.poolPage - 1})">← Anterior</button>
                    <button class="btn-ghost" style="padding:4px 10px;font-size:11px" ${adminState.poolPage === totalPages ? 'disabled' : ''} onclick="changeAdmPoolPage(${adminState.poolPage + 1})">Próxima →</button>
                </div>`;
        }

        function changeAdmPoolPage(page) {
            adminState.poolPage = page;
            renderAdmPool();
        }

        async function deleteInitDraftPlayer(playerId, playerName) {
            if (!confirm(`Remover ${playerName} do draft inicial?`)) return;
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_player', token: TOKEN, player_id: playerId }),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao remover jogador');
                showMessage('Jogador removido do pool.');
                await loadState();
            } catch (error) {
                showMessage(error.message, 'danger');
            }
        }

        function openEditPlayer(playerId) {
            const player = state.pool.find(p => p.id === playerId);
            if (!player) { showMessage('Jogador não encontrado.', 'warning'); return; }
            document.getElementById('editPlayerId').value = player.id;
            document.getElementById('editPlayerName').value = player.name || '';
            document.getElementById('editPlayerPosition').value = player.position || 'SF';
            document.getElementById('editPlayerAge').value = player.age || 19;
            document.getElementById('editPlayerOvr').value = player.ovr || 70;
            new bootstrap.Modal(document.getElementById('editPlayerModal')).show();
        }

        async function handleAddPlayer(event) {
            event.preventDefault();
            const payload = Object.fromEntries(new FormData(event.target).entries());
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'add_player', token: TOKEN, ...payload }),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao adicionar jogador');
                showMessage('Jogador adicionado ao pool.');
                event.target.reset();
                bootstrap.Modal.getInstance(document.getElementById('addPlayerModal'))?.hide();
                await loadState();
            } catch (error) {
                showMessage(error.message, 'danger');
            }
        }

        async function handleEditPlayer(event) {
            event.preventDefault();
            const payload = Object.fromEntries(new FormData(event.target).entries());
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'edit_player', token: TOKEN, ...payload }),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao editar jogador');
                showMessage('Jogador atualizado com sucesso.');
                bootstrap.Modal.getInstance(document.getElementById('editPlayerModal'))?.hide();
                await loadState();
            } catch (error) {
                showMessage(error.message, 'danger');
            }
        }

        async function handleImportCSV(event) {
            event.preventDefault();
            const form = event.target;
            const fileInput = form.querySelector('input[type="file"]');
            if (!fileInput.files.length) { showMessage('Selecione um arquivo CSV.', 'warning'); return; }
            const formData = new FormData(form);
            formData.append('action', 'import_csv');
            formData.append('token', TOKEN);
            try {
                const res = await fetch(API_URL, { method: 'POST', body: formData });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao importar CSV');
                showMessage(`Importação concluída: ${data.imported} jogadores.`);
                form.reset();
                bootstrap.Modal.getInstance(document.getElementById('importCSVModal'))?.hide();
                await loadState();
            } catch (error) {
                showMessage(error.message, 'danger');
            }
        }

        function downloadCSVTemplate() {
            const csv = 'name,position,age,ovr\nJohn Doe,SF,22,75';
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'initdraft-template.csv';
            link.click();
            URL.revokeObjectURL(url);
        }

        document.getElementById('addPlayerForm')?.addEventListener('submit', handleAddPlayer);
        document.getElementById('editPlayerForm')?.addEventListener('submit', handleEditPlayer);
        document.getElementById('importCSVForm')?.addEventListener('submit', handleImportCSV);
<?php endif; ?>

        // ── Menu lateral (toggle mobile) ────────────────
        (function () {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sbOverlay');
            const menuBtn = document.getElementById('menuBtn');
            if (!sidebar) return;
            const close = () => { sidebar.classList.remove('open'); if (overlay) overlay.classList.remove('show'); };
            if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); if (overlay) overlay.classList.add('show'); });
            if (overlay) overlay.addEventListener('click', close);
            document.querySelectorAll('.sb-nav a').forEach((a) => a.addEventListener('click', close));
        })();

        // ── Alternar tema (botão do sidebar) ────────────
        (function () {
            const themeKey = 'fba-theme';
            const themeToggle = document.getElementById('themeToggle');
            const applyTheme = (theme) => {
                if (theme === 'light') {
                    document.documentElement.setAttribute('data-theme', 'light');
                    if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
                } else {
                    document.documentElement.removeAttribute('data-theme');
                    if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
                }
            };
            applyTheme(localStorage.getItem(themeKey) || 'dark');
            if (themeToggle) themeToggle.addEventListener('click', () => {
                const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
                localStorage.setItem(themeKey, next);
                applyTheme(next);
            });
        })();

        setupAutoRefresh();
        loadState();
    </script>
</body>
</html>
