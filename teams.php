<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

$stmtSettings = $pdo->prepare('SELECT cap_min, cap_max, max_trades FROM league_settings WHERE league = ?');
$stmtSettings->execute([$user['league']]);
$leagueSettings = $stmtSettings->fetch(PDO::FETCH_ASSOC) ?: ['cap_min' => 0, 'cap_max' => 0, 'max_trades' => 3];
$capMin = (int)($leagueSettings['cap_min'] ?? 0);
$capMax = (int)($leagueSettings['cap_max'] ?? 0);
$maxTrades = (int)($leagueSettings['max_trades'] ?? 3);

$currentSeasonYear = null;
$currentSprintNumber = null;
$currentSeason = null;
$seasonDisplayYear = null;
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
        if (isset($season['sprint_number'])) {
            $currentSprintNumber = (int)$season['sprint_number'];
        }
        $currentSeason = $season;
    }
} catch (Exception $e) {
    $currentSeasonYear = null;
}
$currentSeasonYear = $currentSeasonYear ?: (int)date('Y');
$seasonDisplayYear = (string)$currentSeasonYear;

$stmtTeam = $pdo->prepare('
    SELECT t.*, COUNT(p.id) as player_count
    FROM teams t
    LEFT JOIN players p ON p.team_id = t.id
    WHERE t.user_id = ?
    GROUP BY t.id
    ORDER BY player_count DESC, t.id DESC
');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

$stmt = $pdo->prepare('
    SELECT t.id, t.city, t.name, t.mascot, t.photo_url, t.user_id, t.tapas,
             u.name AS owner_name, u.phone AS owner_phone, u.photo_url AS owner_photo,
             (SELECT COUNT(*) FROM team_punishments tp WHERE tp.team_id = t.id AND tp.reverted_at IS NULL) as punicoes_count
    FROM teams t
    INNER JOIN users u ON u.id = t.user_id
    WHERE t.league = ?
    ORDER BY t.city ASC, t.name ASC
');
$stmt->execute([$user['league']]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($teams as &$t) {
    if (empty($t['owner_name'])) {
        $t['owner_name'] = 'N/A';
    }
    $rawPhone = $t['owner_phone'] ?? '';
    $normalizedPhone = $rawPhone !== '' ? normalizeBrazilianPhone($rawPhone) : null;
    if (!$normalizedPhone && $rawPhone !== '') {
        $digits = preg_replace('/\D+/', '', $rawPhone);
        if ($digits !== '') {
            $normalizedPhone = str_starts_with($digits, '55') ? $digits : '55' . $digits;
        }
    }
    $t['owner_phone_display'] = $rawPhone !== '' ? formatBrazilianPhone($rawPhone) : null;
    $t['owner_phone_whatsapp'] = $normalizedPhone;

    $playerStmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM players WHERE team_id = ?');
    $playerStmt->execute([$t['id']]);
    $t['total_players'] = (int)$playerStmt->fetch()['cnt'];

    $capStmt = $pdo->prepare('SELECT SUM(ovr) as cap FROM (SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8) as top8');
    $capStmt->execute([$t['id']]);
    $capResult = $capStmt->fetch();
    $t['cap_top8'] = (int)($capResult['cap'] ?? 0);
}
unset($t);

$totalCapTop8 = 0;
foreach ($teams as $t) {
    $totalCapTop8 += (int)($t['cap_top8'] ?? 0);
}
$teamsCount = count($teams);
$averageCapTop8 = $teamsCount > 0 ? round($totalCapTop8 / $teamsCount, 1) : 0;

$whatsappDefaultMessage = rawurlencode('Olá! Podemos conversar sobre nossas franquias na FBA?');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Times - FBA Manager</title>

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
            --red: #fc0025;
            --red-2: #ff2a44;
            --red-soft: rgba(252,0,37,.10);
            --red-glow: rgba(252,0,37,.18);
            --bg: #07070a;
            --panel: #101013;
            --panel-2: #16161a;
            --panel-3: #1c1c21;
            --border: rgba(255,255,255,.06);
            --border-strong: rgba(255,255,255,.10);
            --border-red: rgba(252,0,37,.22);
            --text: #f0f0f3;
            --text-2: #868690;
            --text-3: #48484f;
            --radius: 14px;
            --radius-sm: 10px;
            --radius-xs: 6px;
            --sidebar-w: 260px;
            --font-display: 'Poppins', sans-serif;
            --font-body: 'Poppins', sans-serif;
            --ease: cubic-bezier(.2,.8,.2,1);
            --t: 200ms;
        }

        :root[data-theme="light"] {
            --bg: #f6f7fb;
            --panel: #ffffff;
            --panel-2: #f2f4f8;
            --panel-3: #e9edf4;
            --border: #e3e6ee;
            --border-strong: #d7dbe6;
            --border-red: rgba(252,0,37,.18);
            --text: #111217;
            --text-2: #5b6270;
            --text-3: #8b93a5;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { -webkit-text-size-adjust: 100%; }
        html, body {
            height: 100%;
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-body);
            -webkit-font-smoothing: antialiased;
        }
        body { overflow-x: hidden; }
        a, button { -webkit-tap-highlight-color: transparent; }

        /* ── Layout Shell ──────────────────────────────── */
        .app {
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ───────────────────────────────────── */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: 260px;
            height: 100vh;
            background: var(--panel);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            z-index: 300;
            overflow-y: auto;
            scrollbar-width: none;
            transition: transform var(--t) var(--ease);
        }
        .sidebar-brand, .sb-brand {
            padding: 24px 20px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-logo, .sb-logo {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: var(--red);
            display: flex; align-items: center; justify-content: center;
            font-family: var(--font-display);
            font-weight: 800;
            font-size: 14px;
            color: #fff;
            letter-spacing: -0.5px;
            flex-shrink: 0;
        }

        .sidebar-brand-text, .sb-brand-text {
            font-family: var(--font-display);
            font-weight: 800;
            font-size: 16px;
            color: var(--text);
            line-height: 1.1;
        }
        .sidebar-brand-text span, .sb-brand-text span {
            display: block;
            font-size: 11px;
            font-weight: 400;
            color: var(--text-2);
            font-family: var(--font-body);
        }

        /* My Team card in sidebar */
        .sidebar-myteam, .sb-team {
            margin: 16px 14px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-myteam img, .sb-team img {
            width: 38px; height: 38px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid var(--border-strong);
            flex-shrink: 0;
        }
        .sidebar-myteam-info, .sb-team-info {
            flex: 1;
            min-width: 0;
        }
        .sidebar-myteam-name, .sb-team-name {
            font-weight: 600;
            font-size: 13px;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-myteam-sub, .sb-team-league {
            font-size: 11px;
            color: var(--text-2);
        }

        /* Nav */
        .sidebar-nav, .sb-nav {
            flex: 1;
            overflow-y: auto;
            padding: 8px 10px;
            scrollbar-width: none;
        }
        .sidebar-nav::-webkit-scrollbar, .sb-nav::-webkit-scrollbar { display: none; }

        .sidebar-nav-label, .sb-section {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-3);
            padding: 12px 10px 6px;
        }

        .sidebar-nav a, .sb-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 10px;
            border-radius: var(--radius-sm);
            color: var(--text-2);
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all var(--t) var(--ease);
            margin-bottom: 2px;
        }
        .sidebar-nav a i, .sb-nav a i {
            font-size: 16px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }
        .sidebar-nav a:hover, .sb-nav a:hover {
            background: var(--panel-2);
            color: var(--text);
        }
        .sidebar-nav a.active, .sb-nav a.active {
            background: var(--red-soft);
            color: var(--red);
            font-weight: 600;
        }
        .sidebar-nav a.active i, .sb-nav a.active i { color: var(--red); }

        .sidebar-footer, .sb-footer {
            padding: 14px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-theme-toggle, .sb-theme-toggle {
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
        .sidebar-theme-toggle:hover, .sb-theme-toggle:hover {
            border-color: var(--border-red);
            color: var(--red);
        }
        .sidebar-user-avatar, .sb-avatar {
            width: 32px; height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid var(--border-strong);
            flex-shrink: 0;
        }
        .sidebar-user-name, .sb-username {
            font-size: 13px;
            font-weight: 500;
            color: var(--text);
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-logout, .sb-logout {
            width: 28px; height: 28px;
            border-radius: 8px;
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-2);
            display: flex; align-items: center; justify-content: center;
            font-size: 13px;
            cursor: pointer;
            transition: all var(--t) var(--ease);
            text-decoration: none;
            flex-shrink: 0;
        }
        .sidebar-logout:hover, .sb-logout:hover {
            background: var(--red-soft);
            border-color: var(--red);
            color: var(--red);
        }

        /* ── Topbar mobile ─────────────────────────────── */
        .topbar {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 56px;
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            align-items: center;
            padding: 0 16px;
            gap: 12px;
            z-index: 199;
        }
        .topbar-brand, .topbar-title {
            font-family: var(--font-display);
            font-weight: 800;
            font-size: 16px;
            color: var(--text);
            flex: 1;
        }
        .topbar-brand em, .topbar-title em { color: var(--red); font-style: normal; }
        .topbar-menu-btn, .menu-btn {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            color: var(--text);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 18px;
        }

        /* Overlay */
        .sidebar-overlay, .sb-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.65);
            backdrop-filter: blur(4px);
            z-index: 250;
        }
        .sidebar-overlay.active, .sb-overlay.show { display: block; }

        .sb-season {
            margin: 0 14px 8px;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            background: var(--panel-2);
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .sb-season-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-3);
            font-weight: 600;
        }
        .sb-season-val {
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
        }

        /* ── Main content ──────────────────────────────── */
        .main {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            width: calc(100% - var(--sidebar-w));
        }

        /* ── Page Header ───────────────────────────────── */
        .page-top {
            padding: 32px 32px 0;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .page-eyebrow {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: var(--red);
            margin-bottom: 6px;
        }

        .page-title {
            font-family: var(--font-display);
            font-size: 28px;
            font-weight: 800;
            color: var(--text);
            line-height: 1.1;
        }

        .page-sub {
            font-size: 13px;
            color: var(--text-2);
            margin-top: 4px;
        }

        /* ── Stats Strip ───────────────────────────────── */
        .stats-strip {
            display: flex;
            gap: 12px;
            padding: 24px 32px 0;
            flex-wrap: wrap;
        }

        .stat-pill {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-pill-icon {
            width: 32px; height: 32px;
            border-radius: 8px;
            background: var(--red-soft);
            display: flex; align-items: center; justify-content: center;
            color: var(--red);
            font-size: 14px;
            flex-shrink: 0;
        }

        .stat-pill-val {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 17px;
            color: var(--text);
            line-height: 1;
        }

        .stat-pill-label {
            font-size: 11px;
            color: var(--text-2);
            margin-top: 2px;
        }

        /* ── Search + Controls ─────────────────────────── */
        .controls {
            padding: 20px 32px 0;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-wrap {
            flex: 1;
            min-width: 220px;
            position: relative;
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-3);
            font-size: 15px;
            pointer-events: none;
        }

        .search-input {
            width: 100%;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 14px 10px 36px;
            color: var(--text);
            font-family: var(--font-body);
            font-size: 14px;
            transition: border-color var(--t) var(--ease);
            outline: none;
        }
        .search-input::placeholder { color: var(--text-3); }
        .search-input:focus { border-color: var(--red); }

        .view-toggle {
            display: flex;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }
        .view-btn {
            width: 38px; height: 38px;
            display: flex; align-items: center; justify-content: center;
            background: transparent;
            border: none;
            color: var(--text-3);
            font-size: 15px;
            cursor: pointer;
            transition: all var(--t) var(--ease);
        }
        .view-btn.active { background: var(--red-soft); color: var(--red); }

        .sort-btn {
            height: 38px;
            padding: 0 14px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-2);
            font-size: 13px;
            font-family: var(--font-body);
            cursor: pointer;
            display: flex; align-items: center; gap: 6px;
            transition: all var(--t) var(--ease);
            white-space: nowrap;
        }
        .sort-btn:hover { border-color: var(--red); color: var(--red); }

        /* ── Teams Grid ────────────────────────────────── */
        .content-area {
            padding: 20px 32px 40px;
            flex: 1;
        }

        /* Grid view */
        .teams-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 14px;
        }

        .team-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: border-color var(--t) var(--ease), transform var(--t) var(--ease), box-shadow var(--t) var(--ease);
            position: relative;
            cursor: default;
        }

        .team-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--red), transparent);
            opacity: 0;
            transition: opacity var(--t) var(--ease);
        }

        .team-card:hover {
            border-color: var(--border-strong);
            transform: translateY(-2px);
            box-shadow: 0 16px 40px rgba(0,0,0,.4);
        }
        .team-card:hover::before { opacity: 1; }

        .team-card-header {
            padding: 18px 18px 14px;
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 1px solid var(--border);
        }

        .team-logos {
            position: relative;
            width: 52px;
            height: 44px;
            flex-shrink: 0;
        }

        .team-logo-main {
            width: 44px; height: 44px;
            border-radius: 10px;
            object-fit: cover;
            border: 1px solid var(--border-strong);
            background: var(--panel-2);
        }

        .team-logo-owner {
            width: 22px; height: 22px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--panel);
            background: var(--panel-2);
            position: absolute;
            bottom: -3px; right: -3px;
        }

        .team-info { flex: 1; min-width: 0; }

        .team-city {
            font-size: 11px;
            color: var(--text-2);
            font-weight: 500;
        }

        .team-name {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 15px;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        }

        .team-owner-row {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 4px;
        }

        .team-owner-name {
            font-size: 12px;
            color: var(--text-2);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Stats row inside card */
        .team-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0;
        }

        .team-stat {
            padding: 12px 10px;
            text-align: center;
            border-right: 1px solid var(--border);
        }
        .team-stat:last-child { border-right: none; }

        .team-stat-val {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 17px;
            color: var(--text);
            line-height: 1;
        }
        .team-stat-val.red { color: var(--red); }
        .team-stat-val.yellow { color: #f59e0b; }

        .team-stat-label {
            font-size: 10px;
            color: var(--text-2);
            margin-top: 3px;
            letter-spacing: .3px;
        }

        /* CAP bar */
        .cap-bar-wrap {
            padding: 12px 18px;
            border-top: 1px solid var(--border);
        }

        .cap-bar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }

        .cap-label { font-size: 11px; color: var(--text-2); }
        .cap-value { font-size: 12px; font-weight: 600; color: var(--text); }

        .cap-track {
            height: 4px;
            background: var(--panel-3);
            border-radius: 999px;
            overflow: hidden;
        }

        .cap-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--red), var(--red-2));
            transition: width .6s var(--ease);
        }

        /* Actions */
        .team-actions {
            padding: 12px 18px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 6px;
        }

        .btn-action {
            flex: 1;
            height: 32px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text-2);
            font-family: var(--font-body);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 5px;
            transition: all var(--t) var(--ease);
            text-decoration: none;
        }
        .btn-action:hover {
            background: var(--panel-2);
            border-color: var(--border-strong);
            color: var(--text);
        }
        .btn-action.primary {
            background: var(--red-soft);
            border-color: rgba(252,0,37,.2);
            color: var(--red);
        }
        .btn-action.primary:hover {
            background: var(--red);
            color: #fff;
        }
        .btn-action.green {
            background: rgba(22,163,74,.10);
            border-color: rgba(22,163,74,.2);
            color: #4ade80;
        }
        .btn-action.green:hover {
            background: #16a34a;
            color: #fff;
        }

        /* ── List View ─────────────────────────────────── */
        .teams-list { display: none; }

        .list-header {
            display: grid;
            grid-template-columns: 1fr 80px 80px 80px 80px 160px;
            gap: 0;
            padding: 10px 18px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm) var(--radius-sm) 0 0;
        }

        .list-header-cell {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: var(--text-3);
            cursor: pointer;
            display: flex; align-items: center; gap: 4px;
            user-select: none;
        }
        .list-header-cell:hover { color: var(--text-2); }
        .list-header-cell.sortable:hover { color: var(--red); }

        .list-row {
            display: grid;
            grid-template-columns: 1fr 80px 80px 80px 80px 160px;
            gap: 0;
            padding: 12px 18px;
            border: 1px solid var(--border);
            border-top: none;
            background: var(--panel);
            align-items: center;
            transition: background var(--t) var(--ease);
        }
        .list-row:last-child { border-radius: 0 0 var(--radius-sm) var(--radius-sm); }
        .list-row:hover { background: var(--panel-2); }

        .list-team-cell {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .list-team-logo {
            width: 36px; height: 36px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid var(--border-strong);
            flex-shrink: 0;
        }

        .list-team-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .list-team-owner {
            font-size: 12px;
            color: var(--text-2);
        }

        .list-cell {
            font-size: 14px;
            color: var(--text);
            font-weight: 500;
        }

        .list-actions {
            display: flex;
            gap: 4px;
            justify-content: flex-end;
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 22px;
            padding: 0 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-pill.red { background: var(--red-soft); color: var(--red); }
        .badge-pill.yellow { background: rgba(245,158,11,.12); color: #f59e0b; }
        .badge-pill.gray { background: var(--panel-3); color: var(--text-2); }

        /* ── Empty state ───────────────────────────────── */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: var(--text-3);
        }
        .empty-state i { font-size: 36px; margin-bottom: 12px; display: block; }
        .empty-state p { font-size: 14px; }

        /* ── Footer strip ──────────────────────────────── */
        .footer-strip {
            margin: 0 32px 32px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 20px;
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .footer-stat {
            font-size: 13px;
            color: var(--text-2);
        }
        .footer-stat strong { color: var(--text); font-weight: 600; }

        /* ── Modals ────────────────────────────────────── */
        .modal-content {
            background: var(--panel) !important;
            border: 1px solid var(--border-strong) !important;
            border-radius: var(--radius) !important;
            color: var(--text);
        }
        .modal-header {
            border-bottom: 1px solid var(--border) !important;
            padding: 18px 22px;
            background: var(--panel-2) !important;
            border-radius: var(--radius) var(--radius) 0 0 !important;
        }
        .modal-title { font-family: var(--font-display); font-weight: 700; font-size: 16px; }
        .modal-body { padding: 20px 22px; }
        .modal-footer {
            border-top: 1px solid var(--border) !important;
            padding: 14px 22px;
            background: var(--panel-2) !important;
            border-radius: 0 0 var(--radius) var(--radius) !important;
        }

        .table-dark {
            --bs-table-bg: transparent !important;
            --bs-table-color: var(--text);
            --bs-table-border-color: var(--border);
        }
        .table-dark thead tr {
            background: var(--panel-2);
        }
        .table-dark thead th {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: var(--text-2);
            border-bottom: 1px solid var(--border) !important;
            padding: 10px 14px;
        }
        .table-dark tbody td {
            border-color: var(--border);
            padding: 10px 14px;
            font-size: 13px;
            vertical-align: middle;
        }
        .table-dark tbody tr:hover { background: var(--panel-2) !important; }

        .spinner-border.text-red { color: var(--red) !important; }

        /* Copy modal textarea */
        #copyTeamTextarea {
            background: var(--panel-2) !important;
            border-color: var(--border) !important;
            color: var(--text) !important;
            font-family: monospace;
            font-size: 12.5px;
            line-height: 1.6;
        }

        /* ── Responsive ────────────────────────────────── */
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .page-top, .stats-strip, .controls, .content-area { padding-left: 16px; padding-right: 16px; }
            .footer-strip { margin: 0 16px 24px; }
            .teams-grid { grid-template-columns: 1fr; }
            .list-header, .list-row {
                grid-template-columns: 1fr 60px 60px 120px;
            }
            .list-col-tapas, .list-col-punicoes { display: none; }
            .page-top { padding-top: 20px; }
        }

        @media (max-width: 500px) {

            .team-stats { grid-template-columns: repeat(2, 1fr); }
            .team-stat:nth-child(2) { border-right: none; }
            .team-stat:nth-child(3) { border-top: 1px solid var(--border); }
        }

        /* Mobile list view — flex row, show only Team + CAP + Actions */
        @media (max-width: 600px) {
            .list-header { display: none; }
            .list-row {
                display: flex;
                align-items: center;
                padding: 10px 12px;
                gap: 8px;
            }
            .list-team-cell { flex: 1; min-width: 0; }
            .list-team-cell > div { min-width: 0; overflow: hidden; }
            .list-team-logo { width: 32px; height: 32px; }
            .list-team-name { font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .list-team-owner { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .list-cell { display: none; }
            .list-row > .list-cell:nth-child(2) { display: flex; flex-shrink: 0; align-items: center; }
            .list-actions { flex-shrink: 0; gap: 4px; }
        }

        /* Stagger animation */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .team-card, .list-row {
            animation: fadeUp .35s var(--ease) both;
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
                <span>Liga <?= htmlspecialchars($user['league']) ?></span>
            </div>
        </div>

        <div class="sb-team">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
                 alt="<?= htmlspecialchars($team['name']) ?>"
                 onerror="this.src='/img/default-team.png'">
            <div>
                <div class="sb-team-name"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></div>
                <div class="sb-team-league"><?= htmlspecialchars($user['league']) ?></div>
            </div>
        </div>

        <nav class="sb-nav">
            <div class="sb-section">Principal</div>
            <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
            <a href="/teams.php" class="active"><i class="bi bi-people-fill"></i> Times</a>
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

            <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
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

    <!-- ═══ MAIN ═══════════════════════════════════════════════ -->
    <main class="main">

        <!-- Page Header -->
        <div class="page-top">
            <div>
                <div class="page-eyebrow">Liga · <?= $currentSeasonYear ?></div>
                <h1 class="page-title">Times da Liga</h1>
                <p class="page-sub"><?= $teamsCount ?> franquias · temporada <?= $currentSeasonYear ?></p>
            </div>
        </div>

        <!-- Controls -->
        <div class="controls">
            <div class="search-wrap">
                <i class="bi bi-search search-icon"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Buscar time, cidade ou GM...">
            </div>
            <button class="sort-btn" id="capSortBtn">
                <i class="bi bi-sort-down"></i> CAP <span id="capSortLabel">↓</span>
            </button>
            <div class="view-toggle">
                <button class="view-btn active" id="btnGrid" title="Grade"><i class="bi bi-grid-3x3-gap"></i></button>
                <button class="view-btn" id="btnList" title="Lista"><i class="bi bi-list-ul"></i></button>
            </div>
        </div>

        <!-- Content Area -->
        <div class="content-area">

            <!-- ── GRID VIEW ── -->
            <div class="teams-grid" id="teamsGrid">
                <?php foreach ($teams as $i => $t):
                    $hasContact = !empty($t['owner_phone_whatsapp']);
                    $waLink = $hasContact
                        ? 'https://api.whatsapp.com/send/?phone=' . rawurlencode($t['owner_phone_whatsapp']) . "&text={$whatsappDefaultMessage}&type=phone_number&app_absent=0"
                        : null;
                    $capPct = $capMax > 0 ? min(100, round(($t['cap_top8'] / $capMax) * 100)) : 0;
                    $searchKey = strtolower(($t['city'] ?? '') . ' ' . ($t['name'] ?? '') . ' ' . ($t['owner_name'] ?? ''));
                ?>
                <div class="team-card" data-search="<?= htmlspecialchars($searchKey) ?>" data-cap="<?= (int)$t['cap_top8'] ?>" style="animation-delay:<?= $i * 0.04 ?>s">
                    <div class="team-card-header">
                        <div class="team-logos">
                            <img class="team-logo-main"
                                 src="<?= htmlspecialchars(getTeamPhoto($t['photo_url'] ?? null)) ?>"
                                 alt="<?= htmlspecialchars($t['name']) ?>"
                                 onerror="this.src='https://ui-avatars.com/api/?name=FBA&background=1f1f23&color=fc0025'">
                            <img class="team-logo-owner"
                                 src="<?= htmlspecialchars(getUserPhoto($t['owner_photo'] ?? null)) ?>"
                                 alt="<?= htmlspecialchars($t['owner_name'] ?? 'GM') ?>"
                                 onerror="this.src='https://ui-avatars.com/api/?name=GM&background=1f1f23&color=8a8a96'">
                        </div>
                        <div class="team-info">
                            <div class="team-city"><?= htmlspecialchars($t['city']) ?></div>
                            <div class="team-name"><?= htmlspecialchars($t['name']) ?></div>
                            <div class="team-owner-row">
                                <i class="bi bi-person" style="font-size:11px;color:var(--text-3)"></i>
                                <span class="team-owner-name"><?= htmlspecialchars($t['owner_name']) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="team-stats">
                        <div class="team-stat">
                            <div class="team-stat-val red"><?= (int)$t['cap_top8'] ?></div>
                            <div class="team-stat-label">CAP</div>
                        </div>
                        <div class="team-stat">
                            <div class="team-stat-val"><?= (int)$t['total_players'] ?></div>
                            <div class="team-stat-label">Jog.</div>
                        </div>
                        <div class="team-stat">
                            <div class="team-stat-val yellow"><?= (int)($t['tapas'] ?? 0) ?></div>
                            <div class="team-stat-label">Tapas</div>
                        </div>
                        <div class="team-stat">
                            <div class="team-stat-val"><?= (int)($t['punicoes_count'] ?? 0) ?></div>
                            <div class="team-stat-label">Pun.</div>
                        </div>
                    </div>

                    <?php if ($capMax > 0): ?>
                    <div class="cap-bar-wrap">
                        <div class="cap-bar-header">
                            <span class="cap-label">CAP — <?= $capMin ?> / <?= $capMax ?></span>
                            <span class="cap-value"><?= $capPct ?>%</span>
                        </div>
                        <div class="cap-track">
                            <div class="cap-fill" style="width:<?= $capPct ?>%"></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="team-actions">
                        <button class="btn-action primary" onclick="verJogadores(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['city'] . ' ' . $t['name'])) ?>')">
                            <i class="bi bi-eye"></i> Ver
                        </button>
                        <button class="btn-action" onclick="verPicks(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['city'] . ' ' . $t['name'])) ?>')">
                            <i class="bi bi-calendar-event"></i> Picks
                        </button>
                        <button class="btn-action" onclick="copiarTime(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['city'] . ' ' . $t['name'])) ?>')">
                            <i class="bi bi-clipboard-check"></i>
                        </button>
                        <?php if ($hasContact): ?>
                        <a class="btn-action green" href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noopener">
                            <i class="bi bi-whatsapp"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div id="gridEmpty" class="empty-state" style="display:none;grid-column:1/-1">
                    <i class="bi bi-search"></i>
                    <p>Nenhum time encontrado</p>
                </div>
            </div>

            <!-- ── LIST VIEW ── -->
            <div class="teams-list" id="teamsList">
                <div class="list-header">
                    <div class="list-header-cell">Time</div>
                    <div class="list-header-cell sortable list-col-cap" id="listCapSort" style="justify-content:center;">CAP <span id="listCapSortLabel">↓</span></div>
                    <div class="list-header-cell" style="justify-content:center;">Jog.</div>
                    <div class="list-header-cell list-col-tapas" style="justify-content:center;">Tapas</div>
                    <div class="list-header-cell list-col-punicoes" style="justify-content:center;">Pun.</div>
                    <div class="list-header-cell" style="justify-content:flex-end;">Ações</div>
                </div>
                <?php foreach ($teams as $i => $t):
                    $hasContact = !empty($t['owner_phone_whatsapp']);
                    $waLink = $hasContact
                        ? 'https://api.whatsapp.com/send/?phone=' . rawurlencode($t['owner_phone_whatsapp']) . "&text={$whatsappDefaultMessage}&type=phone_number&app_absent=0"
                        : null;
                    $searchKey = strtolower(($t['city'] ?? '') . ' ' . ($t['name'] ?? '') . ' ' . ($t['owner_name'] ?? ''));
                ?>
                <div class="list-row" data-search="<?= htmlspecialchars($searchKey) ?>" data-cap="<?= (int)$t['cap_top8'] ?>" style="animation-delay:<?= $i * 0.02 ?>s">
                    <div class="list-team-cell">
                        <img class="list-team-logo"
                             src="<?= htmlspecialchars(getTeamPhoto($t['photo_url'] ?? null)) ?>"
                             alt="<?= htmlspecialchars($t['name']) ?>"
                             onerror="this.src='https://ui-avatars.com/api/?name=FBA&background=1f1f23&color=fc0025'">
                        <div>
                            <div class="list-team-name"><?= htmlspecialchars($t['city'] . ' ' . $t['name']) ?></div>
                            <div class="list-team-owner"><?= htmlspecialchars($t['owner_name']) ?></div>
                        </div>
                    </div>
                    <div class="list-cell" style="text-align:center">
                        <span class="badge-pill red"><?= (int)$t['cap_top8'] ?></span>
                    </div>
                    <div class="list-cell" style="text-align:center"><?= (int)$t['total_players'] ?></div>
                    <div class="list-cell list-col-tapas" style="text-align:center">
                        <span class="badge-pill yellow"><?= (int)($t['tapas'] ?? 0) ?></span>
                    </div>
                    <div class="list-cell list-col-punicoes" style="text-align:center">
                        <span class="badge-pill gray"><?= (int)($t['punicoes_count'] ?? 0) ?></span>
                    </div>
                    <div class="list-actions">
                        <button class="btn-action primary" style="flex:initial;padding:0 10px;" onclick="verJogadores(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['city'] . ' ' . $t['name'])) ?>')">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button class="btn-action" style="flex:initial;padding:0 10px;" onclick="verPicks(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['city'] . ' ' . $t['name'])) ?>')">
                            <i class="bi bi-calendar-event"></i>
                        </button>
                        <button class="btn-action" style="flex:initial;padding:0 10px;" onclick="copiarTime(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['city'] . ' ' . $t['name'])) ?>')">
                            <i class="bi bi-clipboard-check"></i>
                        </button>
                        <?php if ($hasContact): ?>
                        <a class="btn-action green" style="flex:initial;padding:0 10px;" href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noopener">
                            <i class="bi bi-whatsapp"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div id="listEmpty" class="empty-state" style="display:none">
                    <i class="bi bi-search"></i>
                    <p>Nenhum time encontrado</p>
                </div>
            </div>
        </div>

        <!-- Footer strip -->
        <div class="footer-strip">
            <div class="footer-stat"><strong>CAP Total:</strong> <?= number_format($totalCapTop8, 0, ',', '.') ?></div>
            <div class="footer-stat"><strong>Média por time:</strong> <?= number_format($averageCapTop8, 1, ',', '.') ?></div>
            <div class="footer-stat"><strong>Faixa de CAP:</strong> <?= $capMin ?> – <?= $capMax ?></div>
            <div class="footer-stat"><strong>Trades por temporada:</strong> <?= $maxTrades ?></div>
        </div>

    </main>
</div>

<!-- ═══ MODAIS ══════════════════════════════════════════════ -->

<!-- Modal Jogadores -->
<div class="modal fade" id="playersModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="loading" class="text-center py-4">
                    <div class="spinner-border text-red" role="status"></div>
                </div>
                <div id="content" style="display:none">
                    <div class="table-responsive">
                        <table class="table table-dark mb-0">
                            <thead>
                                <tr>
                                    <th>Jogador</th>
                                    <th>OVR</th>
                                    <th>Idade</th>
                                    <th>Posição</th>
                                    <th>Função</th>
                                </tr>
                            </thead>
                            <tbody id="playersList"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Picks -->
<div class="modal fade" id="picksModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="picksModalTitle"></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="picksLoading" class="text-center py-4">
                    <div class="spinner-border text-red" role="status"></div>
                </div>
                <div id="picksContent" style="display:none">
                    <p style="font-size:12px;color:var(--text-2);margin-bottom:8px">Picks com o time</p>
                    <div class="table-responsive">
                        <table class="table table-dark mb-0">
                            <thead><tr><th>Ano</th><th>1a rodada</th><th>2a rodada</th></tr></thead>
                            <tbody id="picksList"></tbody>
                        </table>
                    </div>
                    <p style="font-size:12px;color:var(--text-2);margin:20px 0 8px">Picks trocadas</p>
                    <div class="table-responsive">
                        <table class="table table-dark mb-0">
                            <thead><tr><th>Ano</th><th>1a rodada</th><th>2a rodada</th></tr></thead>
                            <tbody id="picksAwayList"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Copiar Time -->
<div class="modal fade" id="copyTeamModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clipboard-check me-2" style="color:var(--red)"></i>Copiar Time</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--text-2);margin-bottom:10px">Toque e segure para copiar o texto.</p>
                <textarea id="copyTeamTextarea" rows="8" readonly></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-action" data-bs-dismiss="modal" style="flex:initial;padding:8px 18px;">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══ SCRIPTS ══════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/pwa.js"></script>
<script>
    const themeKey = 'fba-theme';
    const root = document.documentElement;
    const savedTheme = localStorage.getItem(themeKey);
    const initialTheme = savedTheme || 'dark';
    root.dataset.theme = initialTheme;

    const themeToggle = document.getElementById('themeToggle');
    const updateThemeToggle = (theme) => {
        if (!themeToggle) return;
        const isLight = theme === 'light';
        themeToggle.innerHTML = isLight
            ? '<i class="bi bi-moon-stars-fill"></i><span>Tema escuro</span>'
            : '<i class="bi bi-sun-fill"></i><span>Tema claro</span>';
    };
    updateThemeToggle(initialTheme);
    themeToggle?.addEventListener('click', () => {
        const nextTheme = root.dataset.theme === 'light' ? 'dark' : 'light';
        root.dataset.theme = nextTheme;
        localStorage.setItem(themeKey, nextTheme);
        updateThemeToggle(nextTheme);
    });

    const leagueCapMin    = <?= (int)$capMin ?>;
    const leagueCapMax    = <?= (int)$capMax ?>;
    const leagueMaxTrades = <?= (int)$maxTrades ?>;
    const currentSeasonYear = <?= $currentSeasonYear ? (int)$currentSeasonYear : 'null' ?>;

    /* ── Sidebar mobile ─────────────────────────────── */
    const sidebar  = document.getElementById('sidebar');
    const overlay  = document.getElementById('sbOverlay');
    const menuBtn  = document.getElementById('menuBtn');
    const closeSidebar = () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
    };
    menuBtn?.addEventListener('click', () => {
        const willOpen = !sidebar.classList.contains('open');
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show', willOpen);
    });
    overlay?.addEventListener('click', closeSidebar);
    if (window.innerWidth <= 900) {
        document.querySelectorAll('.sb-nav a').forEach((link) => {
            link.addEventListener('click', closeSidebar);
        });
    }

    /* ── View toggle ────────────────────────────────── */
    const grid     = document.getElementById('teamsGrid');
    const list     = document.getElementById('teamsList');
    const btnGrid  = document.getElementById('btnGrid');
    const btnList  = document.getElementById('btnList');
    let   isGrid   = true;

    btnGrid.addEventListener('click', () => {
        isGrid = true;
        grid.style.display = ''; list.style.display = 'none';
        btnGrid.classList.add('active'); btnList.classList.remove('active');
    });
    btnList.addEventListener('click', () => {
        isGrid = false;
        grid.style.display = 'none'; list.style.display = 'block';
        btnList.classList.add('active'); btnGrid.classList.remove('active');
    });

    /* ── Search ─────────────────────────────────────── */
    document.getElementById('searchInput').addEventListener('input', function () {
        const term = this.value.toLowerCase().trim();
        let gVis = 0, lVis = 0;

        document.querySelectorAll('#teamsGrid .team-card').forEach(el => {
            const match = !term || el.dataset.search.includes(term);
            el.style.display = match ? '' : 'none';
            if (match) gVis++;
        });
        document.querySelectorAll('#teamsList .list-row').forEach(el => {
            const match = !term || el.dataset.search.includes(term);
            el.style.display = match ? '' : 'none';
            if (match) lVis++;
        });

        document.getElementById('gridEmpty').style.display = (gVis === 0 && term) ? 'block' : 'none';
        document.getElementById('listEmpty').style.display = (lVis === 0 && term) ? 'block' : 'none';
    });

    /* ── Sort by CAP ────────────────────────────────── */
    let capDir = 'desc';
    function updateCapSortIndicators() {
        const label = capDir === 'asc' ? '↑' : '↓';
        const gridLabel = document.getElementById('capSortLabel');
        const listLabel = document.getElementById('listCapSortLabel');
        if (gridLabel) gridLabel.textContent = label;
        if (listLabel) listLabel.textContent = label;
    }
    function sortByCap() {
        ['#teamsGrid .team-card', '#teamsList .list-row'].forEach(sel => {
            const parent = document.querySelector(sel.split(' ')[0]);
            if (!parent) return;
            const items = [...parent.querySelectorAll(sel.includes('team-card') ? '.team-card' : '.list-row')];
            items.sort((a, b) => {
                const diff = (+a.dataset.cap) - (+b.dataset.cap);
                return capDir === 'asc' ? diff : -diff;
            });
            items.forEach(el => parent.appendChild(el));
        });
        updateCapSortIndicators();
    }
    document.getElementById('capSortBtn').addEventListener('click', () => {
        capDir = capDir === 'asc' ? 'desc' : 'asc';
        sortByCap();
    });
    document.getElementById('listCapSort')?.addEventListener('click', () => {
        capDir = capDir === 'asc' ? 'desc' : 'asc';
        sortByCap();
    });
    sortByCap(); // default sort on load

    /* ── Ver Jogadores ──────────────────────────────── */
    async function verJogadores(teamId, teamName) {
        const titleEl   = document.getElementById('modalTitle');
        const loadingEl = document.getElementById('loading');
        const contentEl = document.getElementById('content');
        const tbody     = document.getElementById('playersList');

        titleEl.textContent = 'Elenco: ' + teamName;
        loadingEl.style.display = 'block';
        loadingEl.innerHTML = '<div class="spinner-border text-red" role="status"></div>';
        contentEl.style.display = 'none';

        new bootstrap.Modal(document.getElementById('playersModal')).show();
        try {
            const data = await fetch(`/api/team-players.php?team_id=${teamId}`).then(r => r.json());
            if (!data.success || !Array.isArray(data.players)) throw new Error();
            tbody.innerHTML = '';
            if (!data.players.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="color:var(--text-2)">Nenhum jogador</td></tr>';
            } else {
                data.players.forEach(p => {
                    const photo = (p.foto_adicional || '').trim()
                        || (p.nba_player_id ? `https://cdn.nba.com/headshots/nba/latest/1040x760/${p.nba_player_id}.png`
                            : `https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=1f1f23&color=fc0025&rounded=true&bold=true`);
                    tbody.innerHTML += `<tr>
                        <td><div style="display:flex;align-items:center;gap:10px">
                            <img src="${photo}" style="width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-strong)"
                                 onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=1f1f23&color=fc0025&rounded=true&bold=true'">
                            <strong>${p.name}</strong>
                        </div></td>
                        <td><span class="badge-pill yellow">${p.ovr}</span></td>
                        <td>${p.age}</td>
                        <td>${p.position}</td>
                        <td>${p.role}</td>
                    </tr>`;
                });
            }
            loadingEl.style.display = 'none';
            contentEl.style.display = 'block';
        } catch {
            loadingEl.innerHTML = '<div style="color:#ef4444;text-align:center">Erro ao carregar jogadores</div>';
        }
    }

    /* ── Ver Picks ──────────────────────────────────── */
    async function verPicks(teamId, teamName) {
        const titleEl    = document.getElementById('picksModalTitle');
        const loadingEl  = document.getElementById('picksLoading');
        const contentEl  = document.getElementById('picksContent');
        const listEl     = document.getElementById('picksList');
        const awayListEl = document.getElementById('picksAwayList');

        titleEl.textContent = 'Picks: ' + teamName;
        loadingEl.style.display = 'block';
        loadingEl.innerHTML = '<div class="spinner-border text-red" role="status"></div>';
        contentEl.style.display = 'none';
        listEl.innerHTML = ''; awayListEl.innerHTML = '';

        new bootstrap.Modal(document.getElementById('picksModal')).show();
        try {
            const data = await fetch(`/api/picks.php?team_id=${teamId}&include_away=1`).then(r => r.json());
            if (data.error) throw new Error(data.error);

            const baseYear = (Number(currentSeasonYear) || 0) + 1;
            let picks = (data.picks || []).filter(pk => Number(pk.season_year) >= baseYear)
                                          .sort((a,b) => Number(a.season_year)-Number(b.season_year) || Number(a.round)-Number(b.round));

            const groupByYear = (items, renderItem) => {
                const grouped = new Map();
                items.forEach((pk) => {
                    const year = String(pk.season_year);
                    if (!grouped.has(year)) grouped.set(year, { r1: [], r2: [] });
                    const roundNumber = parseInt(String(pk.round ?? ''), 10);
                    const bucket = roundNumber === 2 ? 'r2' : 'r1';
                    grouped.get(year)[bucket].push(renderItem(pk));
                });
                return grouped;
            };

            const getSwapTags = (pick) => {
                const swapType = String(pick?.swap_type || '').toUpperCase().trim();
                if (swapType === 'SB' || swapType === 'SW') {
                    return `<span class="badge-pill gray" style="margin-left:6px">${swapType}</span>`;
                }
                const text = String(pick?.notes || '');
                const tags = [];
                if (/\bSB\b/i.test(text)) tags.push('SB');
                if (/\bSW\b/i.test(text)) tags.push('SW');
                if (/swap/i.test(text) && tags.length === 0) tags.push('SB', 'SW');
                if (!tags.length) return '';
                return tags.map(tag => `<span class="badge-pill gray" style="margin-left:6px">${tag}</span>`).join('');
            };

            const renderPickWithTeam = (pk) => {
                const isOwn = Number(pk.team_id) === Number(pk.original_team_id);
                const swapTags = getSwapTags(pk);
                if (isOwn) {
                    return `<div class="mb-1"><span class="badge-pill" style="background:rgba(22,163,74,.12);color:#4ade80">Própria</span>${swapTags}</div>`;
                }
                const origin = `Via ${pk.original_team_city} ${pk.original_team_name}`.trim();
                return `
                    <div class="mb-1" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <span class="badge-pill yellow">Recebida</span>
                        <small style="color:var(--text-2)">${origin}</small>
                        ${swapTags}
                    </div>
                `;
            };

            if (!picks.length) {
                listEl.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--text-2)">Nenhuma pick futura</td></tr>';
            } else {
                const grouped = groupByYear(picks, renderPickWithTeam);
                const years = Array.from(grouped.keys()).sort((a, b) => Number(a) - Number(b));
                years.forEach((year) => {
                    const entry = grouped.get(year);
                    const round1 = entry.r1.length ? entry.r1.join('') : '<span style="color:var(--text-2)">-</span>';
                    const round2 = entry.r2.length ? entry.r2.join('') : '<span style="color:var(--text-2)">-</span>';
                    listEl.innerHTML += `<tr><td>${year}</td><td>${round1}</td><td>${round2}</td></tr>`;
                });
            }

            let picksAway = (data.picks_away || []).filter(pk => Number(pk.season_year) >= baseYear)
                                                    .sort((a,b) => Number(a.season_year)-Number(b.season_year) || Number(a.round)-Number(b.round));
            if (!picksAway.length) {
                awayListEl.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--text-2)">Todas as picks estão com este time</td></tr>';
            } else {
                const renderPickAway = (pk) => {
                    const cur = `${pk.current_team_city||''} ${pk.current_team_name||''}`.trim() || 'Não definido';
                    const swapTags = getSwapTags(pk);
                    return `<div class="mb-1" style="color:var(--text-2)">${cur}${swapTags}</div>`;
                };
                const groupedAway = groupByYear(picksAway, renderPickAway);
                const yearsAway = Array.from(groupedAway.keys()).sort((a, b) => Number(a) - Number(b));
                yearsAway.forEach((year) => {
                    const entry = groupedAway.get(year);
                    const round1 = entry.r1.length ? entry.r1.join('') : '<span style="color:var(--text-2)">-</span>';
                    const round2 = entry.r2.length ? entry.r2.join('') : '<span style="color:var(--text-2)">-</span>';
                    awayListEl.innerHTML += `<tr><td>${year}</td><td>${round1}</td><td>${round2}</td></tr>`;
                });
            }

            loadingEl.style.display = 'none';
            contentEl.style.display = 'block';
        } catch (err) {
            loadingEl.innerHTML = `<div style="color:#ef4444;text-align:center">Erro: ${err.message || 'Desconhecido'}</div>`;
        }
    }

    /* ── Copiar Time ────────────────────────────────── */
    async function copiarTime(teamId, teamName) {
        try {
            const [teamRes, playersRes, picksRes] = await Promise.all([
                fetch(`/api/team.php?id=${teamId}`),
                fetch(`/api/team-players.php?team_id=${teamId}`),
                fetch(`/api/picks.php?team_id=${teamId}`)
            ]);
            const [teamData, playersData, picksData] = await Promise.all([teamRes.json(), playersRes.json(), picksRes.json()]);

            if (!teamData || teamData.error) throw new Error(teamData.error || 'Erro ao carregar time');
            const teamInfo = (teamData.teams && teamData.teams[0]) ? teamData.teams[0] : null;
            if (!teamInfo) throw new Error('Time não encontrado');

            const roster = playersData.players || [];
            let picks = (picksData.picks || []).filter(pk => Number(pk.season_year) > Number(currentSeasonYear));

            const positions = ['PG','SG','SF','PF','C'];
            const startersMap = {};
            positions.forEach(pos => startersMap[pos] = null);
            roster.filter(p => p.role === 'Titular').forEach(p => {
                if (positions.includes(p.position) && !startersMap[p.position]) startersMap[p.position] = p;
            });

            const fmt = (age) => (Number.isFinite(age) && age > 0) ? `${age}y` : '-';
            const fmtLine = (label, p) => p ? `${label}: ${p.name} - ${p.ovr ?? '-'} | ${fmt(p.age)}` : `${label}: -`;

            const bench  = roster.filter(p => p.role === 'Banco');
            const others = roster.filter(p => p.role === 'Outro');
            const gleague = roster.filter(p => (p.role||'').toLowerCase() === 'g-league');

            const r1 = picks.filter(pk => pk.round == 1).map(pk => {
                const via = pk.last_owner_city ? `${pk.last_owner_city} ${pk.last_owner_name}` : `${pk.original_team_city} ${pk.original_team_name}`;
                return `-${pk.season_year}${pk.original_team_id != pk.team_id ? ` (via ${via})` : ''} `;
            });
            const r2 = picks.filter(pk => pk.round == 2).map(pk => {
                const via = pk.last_owner_city ? `${pk.last_owner_city} ${pk.last_owner_name}` : `${pk.original_team_city} ${pk.original_team_name}`;
                return `-${pk.season_year}${pk.original_team_id != pk.team_id ? ` (via ${via})` : ''} `;
            });

            const lines = [
                `*${teamName}*`, teamInfo.owner_name || '-', '',
                '_Starters_', ...positions.map(pos => fmtLine(pos, startersMap[pos])), '',
                '_Bench_', ...(bench.length ? bench.map(p => `${p.position}: ${p.name} - ${p.ovr??'-'} | ${fmt(p.age)}`) : ['-']), '',
                '_Others_', ...(others.length ? others.map(p => `${p.position}: ${p.name} - ${p.ovr??'-'} | ${fmt(p.age)}`) : ['-']), '',
                '_G-League_', ...(gleague.length ? gleague.map(p => `${p.position}: ${p.name} - ${p.ovr??'-'} | ${fmt(p.age)}`) : ['-']), '',
                '_Picks 1° round_:', ...(r1.length ? r1 : ['-']), '',
                '_Picks 2° round_:', ...(r2.length ? r2 : ['-']), '',
                `_CAP_: ${leagueCapMin} / *${teamInfo.cap_top8??0}* / ${leagueCapMax}`,
                `_Trades_: ${teamInfo.trades_used??0} / ${leagueMaxTrades}`
            ];

            const text = lines.join('\n');
            try {
                await navigator.clipboard.writeText(text);
                alert('Time copiado!');
            } catch {
                const textarea = document.getElementById('copyTeamTextarea');
                textarea.value = text;
                new bootstrap.Modal(document.getElementById('copyTeamModal')).show();
                setTimeout(() => { textarea.focus(); textarea.select(); }, 150);
            }
        } catch (err) {
            alert(err.message || 'Erro ao copiar time');
        }
    }
</script>
</body>
</html>