<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

$currentSeasonYear = null;
try {
    $stmtSeason = $pdo->prepare('
        SELECT s.season_number, s.year, sp.start_year
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN (\'completed\'))
        ORDER BY s.created_at DESC LIMIT 1
    ');
    $stmtSeason->execute([$team['league']]);
    $season = $stmtSeason->fetch(PDO::FETCH_ASSOC);
    if ($season) {
        if (isset($season['start_year'], $season['season_number']))
            $currentSeasonYear = (int)$season['start_year'] + (int)$season['season_number'] - 1;
        elseif (isset($season['year']))
            $currentSeasonYear = (int)$season['year'];
    }
} catch (Exception $e) { $currentSeasonYear = null; }
$currentSeasonYear = $currentSeasonYear ?: (int)date('Y');

$stmtPicks = $pdo->prepare('
    SELECT p.*, orig.city AS original_city, orig.name AS original_name,
           last_owner.city AS last_owner_city, last_owner.name AS last_owner_name,
           swap_team.id AS swap_partner_team_id,
           swap_team.city AS swap_partner_city, swap_team.name AS swap_partner_name
    FROM picks p
    LEFT JOIN teams orig ON p.original_team_id = orig.id
    LEFT JOIN teams last_owner ON p.last_owner_team_id = last_owner.id
    LEFT JOIN picks swap_pick ON p.swap_pair_pick_id = swap_pick.id
    LEFT JOIN teams swap_team ON swap_pick.original_team_id = swap_team.id
    WHERE p.team_id = ? AND p.season_year > ?
    ORDER BY p.season_year, p.round
');
$stmtPicks->execute([$team['id'], $currentSeasonYear]);
$picks = $stmtPicks->fetchAll();

$stmtPicksAway = $pdo->prepare('
    SELECT p.*, current_owner.city AS current_city, current_owner.name AS current_name,
           swap_team.city AS swap_partner_city, swap_team.name AS swap_partner_name
    FROM picks p
    LEFT JOIN teams current_owner ON p.team_id = current_owner.id
    LEFT JOIN picks swap_pick ON p.swap_pair_pick_id = swap_pick.id
    LEFT JOIN teams swap_team ON swap_pick.original_team_id = swap_team.id
    WHERE p.original_team_id = ? AND p.team_id <> ? AND p.season_year > ?
    ORDER BY p.season_year, p.round
');
$stmtPicksAway->execute([$team['id'], $team['id'], $currentSeasonYear]);
$picksAway = $stmtPicksAway->fetchAll();

$picksByRound = ['1' => [], '2' => [], 'other' => []];
foreach ($picks as $pick) {
    $k = (string)(int)($pick['round'] ?? 0);
    if ($k === '1') $picksByRound['1'][] = $pick;
    elseif ($k === '2') $picksByRound['2'][] = $pick;
    else $picksByRound['other'][] = $pick;
}

function extractSwapTags(?string $notes): array
{
    $notes = (string)($notes ?? '');
    $tags = [];
    if (preg_match('/\bSB\b/i', $notes)) {
        $tags[] = 'SB';
    }
    if (preg_match('/\bSW\b/i', $notes)) {
        $tags[] = 'SW';
    }
    if (preg_match('/swap/i', $notes) && empty($tags)) {
        $tags = ['SB', 'SW'];
    }
    return $tags;
}

$totalPicks   = count($picks);
$ownPicks     = count(array_filter($picks, fn($p) => (int)$p['original_team_id'] === (int)$team['id']));
$tradedIn     = $totalPicks - $ownPicks;
$tradedAway   = count($picksAway);
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
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Picks - FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
        /* ── Tokens ──────────────────────────────── */
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
            --border-red: color-mix(in srgb, var(--red) 18%, transparent);
            --text: #111217;
            --text-2: #5b6270;
            --text-3: #657080;
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
        a { color: inherit; text-decoration: none; }

        .app { display: flex; min-height: 100vh; }

        /* ── Sidebar ─────────────────────────────── */
        .sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; transition: transform var(--t) var(--ease); overflow-y: auto; scrollbar-width: none; }
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
        .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); flex-shrink: 0; }
        .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        /* ── Topbar mobile ───────────────────────── */
        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 199; }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 199; }
        .sb-overlay.show { display: block; }

        /* ── Main ────────────────────────────────── */
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }

        /* ── Hero ────────────────────────────────── */
        .dash-hero { padding: 32px 32px 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .dash-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .dash-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
        .dash-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }

        /* ── Info banner ─────────────────────────── */
        .info-banner { margin: 16px 32px 0; background: rgba(59,130,246,.08); border: 1px solid rgba(59,130,246,.2); border-left: 3px solid var(--blue); border-radius: var(--radius-sm); padding: 12px 16px; display: flex; align-items: flex-start; gap: 10px; font-size: 12px; color: #93c5fd; }
        .info-banner i { font-size: 14px; flex-shrink: 0; margin-top: 1px; }

        /* ── Stats strip ─────────────────────────── */
        .stats-strip { display: flex; gap: 10px; padding: 16px 32px 0; flex-wrap: wrap; }
        .stat-pill { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 10px 16px; display: flex; align-items: center; gap: 10px; }
        .stat-pill-icon { width: 30px; height: 30px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; }
        .stat-pill-val { font-size: 17px; font-weight: 800; line-height: 1; }
        .stat-pill-label { font-size: 11px; color: var(--text-2); margin-top: 1px; }

        /* ── Content ─────────────────────────────── */
        .content { padding: 20px 32px 40px; flex: 1; }

        /* ── Section label ───────────────────────── */
        .section-label { font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--text-3); margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
        .section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

        /* ── Pick grid ───────────────────────────── */
        .picks-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-bottom: 24px; }

        /* ── Round panel ─────────────────────────── */
        .round-panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .round-head { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .round-badge { width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; flex-shrink: 0; }
        .round-badge.r1 { background: var(--red-soft); color: var(--red); }
        .round-badge.r2 { background: rgba(59,130,246,.12); color: var(--blue); }
        .round-title { font-size: 14px; font-weight: 700; flex: 1; }
        .round-count { font-size: 11px; color: var(--text-2); background: var(--panel-3); padding: 4px 8px; border-radius: 999px; }

        /* ── Pick row ────────────────────────────── */
        .pick-row { display: flex; align-items: center; gap: 12px; padding: 12px 18px; border-bottom: 1px solid var(--border); transition: background var(--t) var(--ease); }
        .pick-row:last-child { border-bottom: none; }
        .pick-row:hover { background: var(--panel-2); }

        .pick-year { font-size: 18px; font-weight: 800; color: var(--red); min-width: 44px; line-height: 1; }
        .pick-year.blue { color: var(--blue); }

        .pick-mid { flex: 1; min-width: 0; }
        .pick-round-tag { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; letter-spacing: .3px; margin-bottom: 4px; }
        .pick-round-tag.r1 { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }
        .pick-round-tag.r2 { background: rgba(59,130,246,.12); color: var(--blue); border: 1px solid rgba(59,130,246,.2); }

        .pick-origin { font-size: 12px; color: var(--text-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .pick-status { flex-shrink: 0; }
        .tag { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; }
        .tag.green  { background: rgba(34,197,94,.12);  color: var(--green); border: 1px solid rgba(34,197,94,.2); }
        .tag.blue   { background: rgba(59,130,246,.12); color: var(--blue);  border: 1px solid rgba(59,130,246,.2); }
        .tag.amber  { background: rgba(245,158,11,.12); color: var(--amber); border: 1px solid rgba(245,158,11,.2); }
        .tag.gray   { background: var(--panel-3);       color: var(--text-2); border: 1px solid var(--border); }
        .tag.red    { background: var(--red-soft);      color: var(--red);   border: 1px solid var(--border-red); }

        /* ── Away picks panel ────────────────────── */
        .away-panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .away-head { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .away-badge { width: 28px; height: 28px; border-radius: 7px; background: rgba(245,158,11,.12); color: var(--amber); display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
        .away-title { font-size: 14px; font-weight: 700; flex: 1; }

        .away-row { display: flex; align-items: center; gap: 12px; padding: 12px 18px; border-bottom: 1px solid var(--border); transition: background var(--t) var(--ease); }
        .away-row:last-child { border-bottom: none; }
        .away-row:hover { background: var(--panel-2); }
        .away-year { font-size: 17px; font-weight: 800; color: var(--amber); min-width: 40px; line-height: 1; }
        .away-mid { flex: 1; min-width: 0; }
        .away-team { font-size: 13px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .away-round { font-size: 11px; color: var(--text-2); margin-top: 2px; }

        /* ── Empty state ─────────────────────────── */
        .empty-r { padding: 32px 16px; text-align: center; color: var(--text-3); }
        .empty-r i { font-size: 26px; display: block; margin-bottom: 8px; }
        .empty-r p { font-size: 12px; }

        /* ── Animations ──────────────────────────── */
        @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .round-panel, .away-panel { animation: fadeUp .35s var(--ease) both; }

        /* ── Responsive ──────────────────────────── */
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .dash-hero, .info-banner, .stats-strip, .content { padding-left: 16px; padding-right: 16px; }
            .dash-hero { padding-top: 18px; }
            .picks-grid { grid-template-columns: 1fr; }
        }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>
<div class="app">

    <!-- ══════════ SIDEBAR ══════════ -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="sb-overlay" id="sbOverlay"></div>

    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Picks</em></div>
    </header>

    <!-- ══════════ MAIN ══════════ -->
    <main class="main">

        <!-- Hero -->
        <div class="dash-hero">
            <div>
                <div class="dash-eyebrow">Liga · <?= htmlspecialchars($user['league']) ?> · <?= $currentSeasonYear ?></div>
                <h1 class="dash-title">Minhas Picks</h1>
                <p class="dash-sub"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?> · picks geradas automaticamente pelo sistema</p>
            </div>
        </div>

        <!-- Stats strip -->
        <div class="stats-strip">
            <div class="stat-pill">
                <div class="stat-pill-icon" style="background:var(--red-soft);color:var(--red)"><i class="bi bi-calendar-check-fill"></i></div>
                <div>
                    <div class="stat-pill-val"><?= $totalPicks ?></div>
                    <div class="stat-pill-label">Picks comigo</div>
                </div>
            </div>
            <div class="stat-pill">
                <div class="stat-pill-icon" style="background:rgba(34,197,94,.10);color:var(--green)"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                    <div class="stat-pill-val"><?= $ownPicks ?></div>
                    <div class="stat-pill-label">Próprias</div>
                </div>
            </div>
            <div class="stat-pill">
                <div class="stat-pill-icon" style="background:rgba(59,130,246,.10);color:var(--blue)"><i class="bi bi-arrow-down-circle-fill"></i></div>
                <div>
                    <div class="stat-pill-val"><?= $tradedIn ?></div>
                    <div class="stat-pill-label">Recebidas via trade</div>
                </div>
            </div>
            <div class="stat-pill">
                <div class="stat-pill-icon" style="background:rgba(245,158,11,.10);color:var(--amber)"><i class="bi bi-arrow-up-circle-fill"></i></div>
                <div>
                    <div class="stat-pill-val"><?= $tradedAway ?></div>
                    <div class="stat-pill-label">Cedidas a outros</div>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="content">

            <!-- Picks por rodada -->
            <div class="section-label"><i class="bi bi-calendar-check"></i> Picks disponíveis</div>

            <div class="picks-grid">

                <!-- 1ª Rodada -->
                <div class="round-panel" style="animation-delay:.05s">
                    <div class="round-head">
                        <div class="round-badge r1">1</div>
                        <div class="round-title">1ª Rodada</div>
                        <span class="round-count"><?= count($picksByRound['1']) ?> pick<?= count($picksByRound['1']) !== 1 ? 's' : '' ?></span>
                    </div>
                    <?php if (empty($picksByRound['1'])): ?>
                    <div class="empty-r">
                        <i class="bi bi-calendar-x"></i>
                        <p>Nenhuma pick de 1ª rodada</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($picksByRound['1'] as $pick):
                        $isOwn = (int)$pick['original_team_id'] === (int)$team['id'];
                        $isAuto = !empty($pick['auto_generated']);
                        $st = $pick['swap_type'] ?? null;
                        $swapTags = $st ? [strtoupper($st)] : extractSwapTags($pick['notes'] ?? '');
                    ?>
                    <div class="pick-row">
                        <div class="pick-year"><?= (int)$pick['season_year'] ?></div>
                        <div class="pick-mid">
                            <div><span class="pick-round-tag r1">1ª Rodada</span></div>
                            <div class="pick-origin">
                                <?php if ($isOwn): ?>
                                    <i class="bi bi-check-circle-fill" style="color:var(--green);font-size:11px;margin-right:3px"></i>Própria
                                <?php else: ?>
                                    <i class="bi bi-arrow-down-circle-fill" style="color:var(--blue);font-size:11px;margin-right:3px"></i>via <?= htmlspecialchars($pick['original_city'] . ' ' . $pick['original_name']) ?>
                                <?php endif; ?>
                                <?php if (!empty($swapTags)): ?>
                                    <?php foreach ($swapTags as $tag): ?>
                                        <span class="tag gray" style="margin-left:6px"><?= htmlspecialchars($tag) ?></span>
                                    <?php endforeach; ?>
                                    <?php $swapPartner = trim(($pick['swap_partner_city'] ?? '') . ' ' . ($pick['swap_partner_name'] ?? '')); $isOwnSwap = !empty($pick['swap_partner_team_id']) && (int)$pick['swap_partner_team_id'] === (int)$team['id']; ?>
                                    <?php if ($swapPartner): ?><span style="font-size:11px;color:var(--text-2);margin-left:4px"><?= $isOwnSwap ? 'c/ própria' : 'c/ ' . htmlspecialchars($swapPartner) ?></span><?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="pick-status">
                            <?php if ($isOwn): ?>
                                <span class="tag green"><i class="bi bi-house-fill" style="font-size:9px"></i> Própria</span>
                            <?php else: ?>
                                <span class="tag blue"><i class="bi bi-arrow-down-circle" style="font-size:9px"></i> Recebida</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- 2ª Rodada -->
                <div class="round-panel" style="animation-delay:.10s">
                    <div class="round-head">
                        <div class="round-badge r2">2</div>
                        <div class="round-title">2ª Rodada</div>
                        <span class="round-count"><?= count($picksByRound['2']) ?> pick<?= count($picksByRound['2']) !== 1 ? 's' : '' ?></span>
                    </div>
                    <?php if (empty($picksByRound['2'])): ?>
                    <div class="empty-r">
                        <i class="bi bi-calendar-x"></i>
                        <p>Nenhuma pick de 2ª rodada</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($picksByRound['2'] as $pick):
                        $isOwn = (int)$pick['original_team_id'] === (int)$team['id'];
                        $isAuto = !empty($pick['auto_generated']);
                        $st = $pick['swap_type'] ?? null;
                        $swapTags = $st ? [strtoupper($st)] : extractSwapTags($pick['notes'] ?? '');
                    ?>
                    <div class="pick-row">
                        <div class="pick-year blue"><?= (int)$pick['season_year'] ?></div>
                        <div class="pick-mid">
                            <div><span class="pick-round-tag r2">2ª Rodada</span></div>
                            <div class="pick-origin">
                                <?php if ($isOwn): ?>
                                    <i class="bi bi-check-circle-fill" style="color:var(--green);font-size:11px;margin-right:3px"></i>Própria
                                <?php else: ?>
                                    <i class="bi bi-arrow-down-circle-fill" style="color:var(--blue);font-size:11px;margin-right:3px"></i>via <?= htmlspecialchars($pick['original_city'] . ' ' . $pick['original_name']) ?>
                                <?php endif; ?>
                                <?php if (!empty($swapTags)): ?>
                                    <?php foreach ($swapTags as $tag): ?>
                                        <span class="tag gray" style="margin-left:6px"><?= htmlspecialchars($tag) ?></span>
                                    <?php endforeach; ?>
                                    <?php $swapPartner = trim(($pick['swap_partner_city'] ?? '') . ' ' . ($pick['swap_partner_name'] ?? '')); $isOwnSwap = !empty($pick['swap_partner_team_id']) && (int)$pick['swap_partner_team_id'] === (int)$team['id']; ?>
                                    <?php if ($swapPartner): ?><span style="font-size:11px;color:var(--text-2);margin-left:4px"><?= $isOwnSwap ? 'c/ própria' : 'c/ ' . htmlspecialchars($swapPartner) ?></span><?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="pick-status">
                            <?php if ($isOwn): ?>
                                <span class="tag green"><i class="bi bi-house-fill" style="font-size:9px"></i> Própria</span>
                            <?php else: ?>
                                <span class="tag blue"><i class="bi bi-arrow-down-circle" style="font-size:9px"></i> Recebida</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Outras rodadas (se existir) -->
                <?php if (!empty($picksByRound['other'])): ?>
                <div class="round-panel" style="animation-delay:.15s;grid-column:span 2">
                    <div class="round-head">
                        <div class="round-badge" style="background:var(--panel-3);color:var(--text-2)"><i class="bi bi-three-dots" style="font-size:12px"></i></div>
                        <div class="round-title">Outras Rodadas</div>
                        <span class="round-count"><?= count($picksByRound['other']) ?> pick<?= count($picksByRound['other']) !== 1 ? 's' : '' ?></span>
                    </div>
                    <?php foreach ($picksByRound['other'] as $pick):
                        $isOwn = (int)$pick['original_team_id'] === (int)$team['id'];
                    ?>
                    <div class="pick-row">
                        <div class="pick-year" style="color:var(--text-2)"><?= (int)$pick['season_year'] ?></div>
                        <div class="pick-mid">
                            <div><span class="pick-round-tag" style="background:var(--panel-3);color:var(--text-2);border:1px solid var(--border)"><?= (int)$pick['round'] ?>ª Rodada</span></div>
                            <div class="pick-origin">
                                <?php if ($isOwn): ?>
                                    <i class="bi bi-check-circle-fill" style="color:var(--green);font-size:11px;margin-right:3px"></i>Própria
                                <?php else: ?>
                                    <i class="bi bi-arrow-down-circle-fill" style="color:var(--blue);font-size:11px;margin-right:3px"></i>via <?= htmlspecialchars($pick['original_city'] . ' ' . $pick['original_name']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="pick-status">
                            <span class="tag <?= $isOwn ? 'green' : 'blue' ?>"><?= $isOwn ? 'Própria' : 'Recebida' ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Picks cedidas a outros times -->
            <div class="section-label"><i class="bi bi-arrow-up-circle"></i> Picks cedidas a outros times</div>

            <div class="away-panel" style="animation-delay:.2s">
                <div class="away-head">
                    <div class="away-badge"><i class="bi bi-arrow-up-circle-fill"></i></div>
                    <div class="away-title">Picks do meu time com outros GMs</div>
                    <span class="round-count"><?= count($picksAway) ?> pick<?= count($picksAway) !== 1 ? 's' : '' ?></span>
                </div>

                <?php if (empty($picksAway)): ?>
                <div class="empty-r">
                    <i class="bi bi-check-circle" style="color:var(--green)"></i>
                    <p style="color:var(--green)">Todas as suas picks estão com você.</p>
                </div>
                <?php else: ?>
                <?php foreach ($picksAway as $pick): ?>
                <?php $st = $pick['swap_type'] ?? null; $swapTags = $st ? [strtoupper($st)] : extractSwapTags($pick['notes'] ?? ''); ?>
                <div class="away-row">
                    <div class="away-year"><?= (int)$pick['season_year'] ?></div>
                    <div class="away-mid">
                        <div class="away-team"><?= htmlspecialchars(trim(($pick['current_city'] ?? '') . ' ' . ($pick['current_name'] ?? ''))) ?: 'Não definido' ?></div>
                        <div class="away-round">
                            <?= (int)$pick['round'] ?>ª Rodada · cedida via trade
                            <?php if (!empty($swapTags)): ?>
                                <?php foreach ($swapTags as $tag): ?>
                                    <span class="tag gray" style="margin-left:6px"><?= htmlspecialchars($tag) ?></span>
                                <?php endforeach; ?>
                                <?php $swapPartner = trim(($pick['swap_partner_city'] ?? '') . ' ' . ($pick['swap_partner_name'] ?? '')); ?>
                                <?php if ($swapPartner): ?><span style="font-size:11px;color:var(--text-2);margin-left:4px">c/ <?= htmlspecialchars($swapPartner) ?></span><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="tag amber"><i class="bi bi-arrow-up-circle" style="font-size:9px"></i> Cedida</span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div><!-- /content -->
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/pwa.js"></script>
<script>
    const sidebar   = document.getElementById('sidebar');
    const sbOverlay = document.getElementById('sbOverlay');
    document.getElementById('menuBtn')?.addEventListener('click', () => { sidebar.classList.toggle('open'); sbOverlay.classList.toggle('show'); });
    sbOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); });

    // Theme
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

    document.querySelectorAll('.round-panel, .away-panel').forEach((el, i) => {
        el.style.animationDelay = (i * 0.06 + 0.05) + 's';
    });
</script>
</body>
</html>