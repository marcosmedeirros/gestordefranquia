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
try {
    $stmtSeason = $pdo->prepare('
        SELECT s.season_number, s.year, sp.start_year
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
    }
} catch (Exception $e) {
    $currentSeasonYear = null;
}
$currentSeasonYear = $currentSeasonYear ?: (int)date('Y');

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
            --red-soft: rgba(252,0,37,.12);
            --red-glow: rgba(252,0,37,.22);
            --bg: #08080a;
            --panel: #111113;
            --panel-2: #18181b;
            --panel-3: #1f1f23;
            --border: rgba(255,255,255,.07);
            --border-strong: rgba(255,255,255,.12);
            --text: #f2f2f4;
            --text-2: #8a8a96;
            --text-3: #55555f;
            --radius: 16px;
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
            --text: #111217;
            --text-2: #5b6270;
            --text-3: #8b93a5;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html {
            -webkit-text-size-adjust: 100%;
        }

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
        .app-shell {
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ───────────────────────────────────── */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: var(--sidebar-w);
            height: 100vh;
            background: var(--panel);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            z-index: 200;
            transition: transform var(--t) var(--ease);
            padding-bottom: 12px;
            padding-top: env(safe-area-inset-top);
        }

        .sidebar-brand {
            padding: 24px 20px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-logo {
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

        .sidebar-brand-text {
            font-family: var(--font-display);
            font-weight: 800;
            font-size: 16px;
            color: var(--text);
            line-height: 1.1;
        }
        .sidebar-brand-text span {
            display: block;
            font-size: 11px;
            font-weight: 400;
            color: var(--text-2);
            font-family: var(--font-body);
        }

        /* My Team card in sidebar */
        .sidebar-myteam {
            margin: 16px 14px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-myteam img {
            width: 38px; height: 38px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid var(--border-strong);
            flex-shrink: 0;
        }
        .sidebar-myteam-info {
            flex: 1;
            min-width: 0;
        }
        .sidebar-myteam-name {
            font-weight: 600;
            font-size: 13px;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-myteam-sub {
            font-size: 11px;
            color: var(--text-2);
        }

        /* Nav */
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 8px 10px;
            scrollbar-width: none;
        }
        .sidebar-nav::-webkit-scrollbar { display: none; }

        .sidebar-nav-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-3);
            padding: 12px 10px 6px;
        }

        .sidebar-nav a {
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
        .sidebar-nav a i {
            font-size: 16px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }
        .sidebar-nav a:hover {
            background: var(--panel-2);
            color: var(--text);
        }
        .sidebar-nav a.active {
            background: var(--red-soft);
            color: var(--red);
            font-weight: 600;
        }
        .sidebar-nav a.active i { color: var(--red); }

        .sidebar-footer {
            padding: 14px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-theme-toggle {
            width: calc(100% - 28px);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 0 14px 12px;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--panel-2);
            color: var(--text);
            font-size: 12px;
            cursor: pointer;
            transition: all var(--t) var(--ease);
        }
        .sidebar-theme-toggle:hover {
            border-color: var(--red);
            color: var(--red);
        }
        .sidebar-user-avatar {
            width: 32px; height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid var(--border-strong);
            flex-shrink: 0;
        }
        .sidebar-user-name {
            font-size: 13px;
            font-weight: 500;
            color: var(--text);
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-logout {
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
        .sidebar-logout:hover {
            background: var(--red-soft);
            border-color: var(--red);
            color: var(--red);
        }

        /* ── Topbar mobile ─────────────────────────────── */
        .topbar {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0;
            height: calc(56px + env(safe-area-inset-top));
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            align-items: center;
            padding: env(safe-area-inset-top) 16px 0;
            gap: 12px;
            z-index: 199;
        }
        .topbar-brand {
            font-family: var(--font-display);
            font-weight: 800;
            font-size: 16px;
            color: var(--text);
            flex: 1;
        }
        .topbar-brand em { color: var(--red); font-style: normal; }
        .topbar-menu-btn {
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
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.65);
            backdrop-filter: blur(4px);
            z-index: 199;
        }
        .sidebar-overlay.active { display: block; }

        /* ── Main content ──────────────────────────────── */
        .main {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            width: calc(100% - var(--sidebar-w));
            padding-bottom: env(safe-area-inset-bottom);
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

        /* Grid view - Agora oculto por padrão */
        .teams-grid {
            display: none; 
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
        /* List View - Agora visível por padrão */
        .teams-list { display: block; }

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
            display: flex; align-items: center; gap: 4px;
            user-select: none;
        }
        .list-header-cell.sortable { cursor: pointer; }
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
            width: 100%;
        }

        /* ── Responsive ────────────────────────────────── */
        @media (max-width: 900px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: calc(56px + env(safe-area-inset-top)); }
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
<div class="app-shell">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-logo">FBA</div>
            <div class="sidebar-brand-text">
                FBA Manager
                <span>Liga <?= htmlspecialchars($user['league'] ?? '') ?></span>
            </div>
        </div>

        <?php if ($team): ?>
        <div class="sidebar-myteam">
            <img src="<?= htmlspecialchars(getTeamPhoto($team['photo_url'] ?? null)) ?>" alt="Meu Time">
            <div class="sidebar-myteam-info">
                <div class="sidebar-myteam-name"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></div>
                <div class="sidebar-myteam-sub"><?= (int)$team['player_count'] ?> jogadores</div>
            </div>
        </div>
        <?php endif; ?>

        <nav class="sidebar-nav">
            <div class="sidebar-nav-label">Principal</div>
            <a href="/dashboard.php"><i class="bi bi-house"></i> Home</a>
            <a href="/teams.php" class="active"><i class="bi bi-people-fill"></i> Times</a>
            <a href="/players.php"><i class="bi bi-person-badge"></i> Jogadores</a>
            <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trocas</a>
            <a href="/picks.php"><i class="bi bi-calendar2-event"></i> Picks</a>

            <div class="sidebar-nav-label">Liga</div>
            <a href="/rankings.php"><i class="bi bi-trophy"></i> Classificação</a>
            <a href="/free-agency.php"><i class="bi bi-person-plus"></i> Mercado Livre</a>
            <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
            <a href="/trades.php#rumores"><i class="bi bi-chat-dots"></i> Rumores</a>

            <div class="sidebar-nav-label">Admin</div>
            <a href="/admin.php"><i class="bi bi-gear"></i> Administração</a>
            <a href="/punicoes.php"><i class="bi bi-exclamation-triangle"></i> Punições</a>
        </nav>

        <div class="sidebar-footer">
            <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>" alt="<?= htmlspecialchars($user['name']) ?>" class="sidebar-user-avatar">
            <span class="sidebar-user-name"><?= htmlspecialchars($user['name']) ?></span>
            <a href="/logout" class="sidebar-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
        </div>

        <button class="sidebar-theme-toggle" id="themeToggle" type="button">
            <i class="bi bi-moon-stars-fill"></i>
            <span>Tema claro</span>
        </button>
    </aside>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <header class="topbar">
        <button class="topbar-menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-brand">FBA <em>Manager</em></div>
    </header>

    <main class="main">

        <div class="page-top">
            <div>
                <div class="page-eyebrow">Liga · <?= $currentSeasonYear ?></div>
                <h1 class="page-title">Times da Liga</h1>
                <p class="page-sub"><?= $teamsCount ?> franquias · temporada <?= $currentSeasonYear ?></p>
            </div>
        </div>

        <div class="stats-strip">
            <div class="stat-pill">
                <div class="stat-pill-icon"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="stat-pill-val"><?= $teamsCount ?></div>
                    <div class="stat-pill-label">Franquias</div>
                </div>
            </div>
            <div class="stat-pill">
                <div class="stat-pill-icon"><i class="bi bi-graph-up"></i></div>
                <div>
                    <div class="stat-pill-val"><?= number_format($averageCapTop8, 0, ',', '.') ?></div>
                    <div class="stat-pill-label">CAP Médio</div>
                </div>
            </div>
            <div class="stat-pill">
                <div class="stat-pill-icon"><i class="bi bi-bar-chart-fill"></i></div>
                <div>
                    <div class="stat-pill-val"><?= number_format($totalCapTop8, 0, ',', '.') ?></div>
                    <div class="stat-pill-label">CAP Total</div>
                </div>
            </div>
        </div>

        <div class="controls">
            <div class="search-wrap">
                <i class="bi bi-search search-icon"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Buscar time, cidade ou GM...">
            </div>
            <button class="sort-btn" id="capSortBtn">
                <i class="bi bi-sort-down"></i> CAP <span id="capSortLabel">↓</span>
            </button>
            <div class="view-toggle">
                <button class="view-btn" id="btnGrid" title="Grade"><i class="bi bi-grid-3x3-gap"></i></button>
                <button class="view-btn active" id="btnList" title="Lista"><i class="bi bi-list-ul"></i></button>
            </div>
        </div>

        <div class="content-area">

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

                <div id="gridEmpty" class="empty-state" style="display:<?= $teamsCount === 0 ? 'block' : 'none' ?>;grid-column:1/-1">
                    <i class="bi bi-search"></i>
                    <p>Nenhum time encontrado</p>
                </div>
            </div>

            <div class="teams-list" id="teamsList">
                <div class="list-header">
                    <div class="list-header-cell">Time</div>
                    <div class="list-header-cell sortable list-col-cap" id="listCapSort" style="justify-content:center;">CAP <i class="bi bi-chevron-down" style="font-size:10px"></i></div>
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

                <div id="listEmpty" class="empty-state" style="display:<?= $teamsCount === 0 ? 'block' : 'none' ?>">
                    <i class="bi bi-search"></i>
                    <p>Nenhum time encontrado</p>
                </div>
            </div>
        </div>

        <div class="footer-strip">
            <div class="footer-stat"><strong>CAP Total:</strong> <?= number_format($totalCapTop8, 0, ',', '.') ?></div>
            <div class="footer-stat"><strong>Média por time:</strong> <?= number_format($averageCapTop8, 1, ',', '.') ?></div>
            <div class="footer-stat"><strong>Faixa de CAP:</strong> <?= $capMin ?> – <?= $capMax ?></div>
            <div class="footer-stat"><strong>Trades por temporada:</strong> <?= $maxTrades ?></div>
        </div>

    </main>
</div>

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
                            <thead><tr><th>Ano</th><th>Rodada</th><th>Origem</th><th>Status</th></tr></thead>
                            <tbody id="picksList"></tbody>
                        </table>
                    </div>
                    <p style="font-size:12px;color:var(--text-2);margin:20px 0 8px">Picks trocadas</p>
                    <div class="table-responsive">
                        <table class="table table-dark mb-0">
                            <thead><tr><th>Ano</th><th>Rodada</th><th>Time atual</th></tr></thead>
                            <tbody id="picksAwayList"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/pwa.js"></script>
<script>
    const themeKey = 'fba-theme';
    const root = document.documentElement;
    const prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
    const savedTheme = localStorage.getItem(themeKey);
    const initialTheme = savedTheme || (prefersLight ? 'light' : 'dark');
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

    /* ── Utils ──────────────────────────────────────── */
    // Função para ignorar acentos na busca
    const normalizeStr = str => str ? str.normalize('NFD').replace(/[\u0300-\u036f]/g, "").toLowerCase() : '';

    /* ── Sidebar mobile ─────────────────────────────── */
    const sidebar  = document.getElementById('sidebar');
    const overlay  = document.getElementById('sidebarOverlay');
    const menuBtn  = document.getElementById('menuBtn');
    menuBtn?.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
    });
    overlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
    });

    /* ── View toggle ────────────────────────────────── */
    const grid     = document.getElementById('teamsGrid');
    const list     = document.getElementById('teamsList');
    const btnGrid  = document.getElementById('btnGrid');
    const btnList  = document.getElementById('btnList');

    // Botão de Grade muda o display pra "grid" e não pra "vazio" pra não herdar o CSS novo
    btnGrid.addEventListener('click', () => {
        grid.style.display = 'grid'; list.style.display = 'none';
        btnGrid.classList.add('active'); btnList.classList.remove('active');
    });
    
    // Botão de Lista muda o display pra "block"
    btnList.addEventListener('click', () => {
        grid.style.display = 'none'; list.style.display = 'block';
        btnList.classList.add('active'); btnGrid.classList.remove('active');
    });

    /* ── Search ─────────────────────────────────────── */
    document.getElementById('searchInput').addEventListener('input', function () {
        const term = normalizeStr(this.value.trim());
        let gVis = 0, lVis = 0;

        document.querySelectorAll('#teamsGrid .team-card').forEach(el => {
            const match = !term || normalizeStr(el.dataset.search).includes(term);
            el.style.display = match ? '' : 'none';
            if (match) gVis++;
        });
        document.querySelectorAll('#teamsList .list-row').forEach(el => {
            const match = !term || normalizeStr(el.dataset.search).includes(term);
            el.style.display = match ? '' : 'none';
            if (match) lVis++;
        });

        const totalTeams = <?= $teamsCount ?>;
        document.getElementById('gridEmpty').style.display = (gVis === 0 && (term !== '' || totalTeams === 0)) ? 'block' : 'none';
        document.getElementById('listEmpty').style.display = (lVis === 0 && (term !== '' || totalTeams === 0)) ? 'block' : 'none';
    });

    /* ── Sort by CAP ────────────────────────────────── */
    let capDir = 'desc';
    function sortByCap() {
        ['#teamsGrid', '#teamsList'].forEach(sel => {
            const parent = document.querySelector(sel);
            if (!parent) return;
            
            const isGrid = sel === '#teamsGrid';
            const itemSelector = isGrid ? '.team-card' : '.list-row';
            const items = [...parent.querySelectorAll(itemSelector)];
            const emptyState = document.getElementById(isGrid ? 'gridEmpty' : 'listEmpty');
            
            items.sort((a, b) => {
                const diff = (+a.dataset.cap) - (+b.dataset.cap);
                return capDir === 'asc' ? diff : -diff;
            });
            
            items.forEach(el => parent.appendChild(el));
            
            // Impede que o container de "Vazio" fique bagunçado no topo
            if (emptyState) {
                parent.appendChild(emptyState);
            }
        });
        
        document.getElementById('capSortLabel').textContent = capDir === 'asc' ? '↑' : '↓';
        
        const listCapIcon = document.querySelector('#listCapSort i');
        if (listCapIcon) {
            listCapIcon.className = capDir === 'asc' ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
        }
    }
    
    document.getElementById('capSortBtn').addEventListener('click', () => {
        capDir = capDir === 'asc' ? 'desc' : 'asc';
        sortByCap();
    });

    // Permite que clicar no cabeçalho "CAP" da visão de lista também ordene
    document.getElementById('listCapSort')?.addEventListener('click', () => {
        document.getElementById('capSortBtn').click();
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

            const baseYear = Number(currentSeasonYear) || 0;
            let picks = (data.picks || []).filter(pk => Number(pk.season_year) >= baseYear)
                                          .sort((a,b) => Number(a.season_year)-Number(b.season_year) || Number(a.round)-Number(b.round));

            if (!picks.length) {
                listEl.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--text-2)">Nenhuma pick futura</td></tr>';
            } else {
                picks.forEach(pk => {
                    const isOwn = Number(pk.team_id) === Number(pk.original_team_id);
                    const origin = isOwn ? 'Própria' : `Via ${pk.original_team_city} ${pk.original_team_name}`.trim();
                    const badge  = isOwn ? '<span class="badge-pill" style="background:rgba(22,163,74,.12);color:#4ade80">Própria</span>'
                                         : '<span class="badge-pill yellow">Recebida</span>';
                    listEl.innerHTML += `<tr><td>${pk.season_year}</td><td><span class="badge-pill gray">R${pk.round}</span></td><td>${origin}</td><td>${badge}</td></tr>`;
                });
            }

            let picksAway = (data.picks_away || []).filter(pk => Number(pk.season_year) >= baseYear)
                                                    .sort((a,b) => Number(a.season_year)-Number(b.season_year) || Number(a.round)-Number(b.round));
            if (!picksAway.length) {
                awayListEl.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--text-2)">Todas as picks estão com este time</td></tr>';
            } else {
                picksAway.forEach(pk => {
                    const cur = `${pk.current_team_city||''} ${pk.current_team_name||''}`.trim() || 'Não definido';
                    awayListEl.innerHTML += `<tr><td>${pk.season_year}</td><td><span class="badge-pill gray">R${pk.round}</span></td><td>${cur}</td></tr>`;
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
                `_CAP_: ${leagueCapMin} / *${teamInfo.cap_top8??0}* / ${leagueCapMax}`,
                `_Trades_: ${teamInfo.trades_used??0} / ${leagueMaxTrades}`, '',
                '_Starters_', ...positions.map(pos => fmtLine(pos, startersMap[pos])), '',
                '_Bench_', ...(bench.length ? bench.map(p => `${p.position}: ${p.name} - ${p.ovr??'-'} | ${fmt(p.age)}`) : ['-']), '',
                '_Others_', ...(others.length ? others.map(p => `${p.position}: ${p.name} - ${p.ovr??'-'} | ${fmt(p.age)}`) : ['-']), '',
                '_G-League_', ...(gleague.length ? gleague.map(p => `${p.position}: ${p.name} - ${p.ovr??'-'} | ${fmt(p.age)}`) : ['-']), '',
                '_Picks 1° round_:', ...(r1.length ? r1 : ['-']), '',
                '_Picks 2° round_:', ...(r2.length ? r2 : ['-'])
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