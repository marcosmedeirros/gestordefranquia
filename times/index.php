<?php
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';

$pdo = db();

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
    http_response_code(404);
    echo "Time não encontrado.";
    exit;
}

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE public_slug = ? AND public_enabled = 1 LIMIT 1');
$stmtTeam->execute([$slug]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);

if (!$team) {
    http_response_code(404);
    echo "Time não encontrado ou página desativada.";
    exit;
}

$moduleKeys = ['roster','lineup','titles','picks','ranking','ai_avgs','trades'];
$modules = [];
if (!empty($team['public_modules'])) {
    $decoded = json_decode((string)$team['public_modules'], true);
    if (is_array($decoded)) {
        $modules = array_values(array_filter($decoded, fn($k) => is_string($k) && in_array($k, $moduleKeys, true)));
    }
}
if (!$modules) $modules = $moduleKeys;

$primary   = $team['public_primary_color']   ?: '#fc0025';
$secondary = $team['public_secondary_color'] ?: '#ff2a44';

$teamId = (int)$team['id'];
$league = (string)($team['league'] ?? '');
$teamCity = (string)($team['city'] ?? '');
$teamName = (string)($team['name'] ?? '');
$teamLogo = $team['photo_url'] ?: '/img/default-team.png';
$teamDisplayName = trim($teamCity . ' ' . $teamName);

// Players
$players  = [];
$starters = [];
if (in_array('roster', $modules) || in_array('lineup', $modules) || in_array('ai_avgs', $modules)) {
    $s = $pdo->prepare('SELECT * FROM players WHERE team_id = ? ORDER BY ovr DESC, age ASC, name ASC');
    $s->execute([$teamId]);
    $players  = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $starters = array_values(array_filter($players, fn($p) => ($p['role'] ?? '') === 'Titular'));
}

// Titles
$championships = 0;
if (in_array('titles', $modules)) {
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM season_history WHERE champion_team_id = ?');
        $s->execute([$teamId]);
        $championships = (int)$s->fetchColumn();
    } catch (Exception $e) {}
}

// Picks with original team names
$picks = [];
if (in_array('picks', $modules)) {
    try {
        $s = $pdo->prepare('
            SELECT p.season_year, p.round, p.notes, COALESCE(t.city, \'\') AS orig_city, COALESCE(t.name, \'\') AS orig_name, p.original_team_id
            FROM picks p
            LEFT JOIN teams t ON t.id = p.original_team_id
            WHERE p.team_id = ?
            ORDER BY p.season_year ASC, p.round ASC, p.id ASC
        ');
        $s->execute([$teamId]);
        $picks = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $s = $pdo->prepare('SELECT season_year, round, notes, original_team_id FROM picks WHERE team_id = ? ORDER BY season_year ASC, round ASC');
        $s->execute([$teamId]);
        $picks = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

// Ranking
$rankingPosition = null;
$rankingPoints   = null;
if (in_array('ranking', $modules) && $league !== '') {
    try {
        $s = $pdo->prepare('SELECT id, ranking_points FROM teams WHERE league = ? ORDER BY ranking_points DESC, id ASC');
        $s->execute([$league]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $pos  = 1;
        foreach ($rows as $r) {
            if ((int)$r['id'] === $teamId) {
                $rankingPosition = $pos;
                $rankingPoints   = (int)($r['ranking_points'] ?? 0);
                break;
            }
            $pos++;
        }
    } catch (Exception $e) {}
}

// Averages
$avgAge = null;
$avgOvr = null;
if (in_array('ai_avgs', $modules) && $players) {
    $sumAge = $sumOvr = $n = 0;
    foreach ($players as $p) { $sumAge += (int)($p['age'] ?? 0); $sumOvr += (int)($p['ovr'] ?? 0); $n++; }
    if ($n > 0) { $avgAge = $sumAge / $n; $avgOvr = $sumOvr / $n; }
}

// Trades
$trades = [];
if (in_array('trades', $modules)) {
    try {
        $s = $pdo->prepare("
            SELECT t.id, t.updated_at, t.created_at,
                   tf.city AS from_city, tf.name AS from_name, tf.photo_url AS from_photo,
                   tt.city AS to_city,   tt.name AS to_name,   tt.photo_url AS to_photo
            FROM trades t
            LEFT JOIN teams tf ON tf.id = t.from_team_id
            LEFT JOIN teams tt ON tt.id = t.to_team_id
            WHERE t.status = 'accepted'
              AND (t.from_team_id = ? OR t.to_team_id = ?)
            ORDER BY t.updated_at DESC, t.id DESC
            LIMIT 10
        ");
        $s->execute([$teamId, $teamId]);
        $trades = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}
}

// Picks grouped by year
$picksByYear = [];
foreach ($picks as $pk) {
    $yr = (int)($pk['season_year'] ?? 0);
    $picksByYear[$yr][] = $pk;
}
ksort($picksByYear);

// OVR color helper
function ovrColor(int $ovr): string {
    if ($ovr >= 90) return '#f59e0b';
    if ($ovr >= 80) return '#22c55e';
    if ($ovr >= 70) return '#3b82f6';
    return '#868690';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="<?= htmlspecialchars($primary) ?>">
    <title><?= htmlspecialchars($teamDisplayName) ?> — FBA</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --p:   <?= htmlspecialchars($primary) ?>;
            --s:   <?= htmlspecialchars($secondary) ?>;
            --bg:  #080809;
            --c1:  #111114;
            --c2:  #17171b;
            --c3:  #1f1f24;
            --bd:  rgba(255,255,255,.07);
            --bd2: rgba(255,255,255,.12);
            --tx:  #f0f0f3;
            --tx2: #8a8a96;
            --tx3: #46464f;
            --r:   16px;
            --rs:  10px;
            --rx:  6px;
            --f:   'Poppins', system-ui, sans-serif;
            --ease: cubic-bezier(.2,.8,.2,1);
            --t: 200ms;
            --ps: color-mix(in srgb, var(--p) 14%, transparent);
            --pb: color-mix(in srgb, var(--p) 30%, transparent);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: var(--f);
            background: var(--bg);
            color: var(--tx);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* ── BG glow ── */
        .bg-glow {
            position: fixed; inset: 0; pointer-events: none; z-index: 0;
            background:
                radial-gradient(ellipse 800px 500px at 10% -5%, color-mix(in srgb, var(--p) 18%, transparent), transparent 60%),
                radial-gradient(ellipse 600px 400px at 90% -5%, color-mix(in srgb, var(--s) 12%, transparent), transparent 60%);
        }

        /* ── Layout ── */
        .wrap { position: relative; z-index: 1; max-width: 1000px; margin: 0 auto; padding: 0 16px 60px; }

        /* ── HERO ── */
        .hero {
            margin-top: 0;
            padding: 48px 40px 40px;
            border-bottom: 1px solid var(--bd);
            background: linear-gradient(160deg,
                color-mix(in srgb, var(--p) 10%, transparent) 0%,
                transparent 60%
            );
            display: flex; align-items: center; gap: 28px;
            flex-wrap: wrap;
        }
        .hero-logo {
            width: 100px; height: 100px;
            border-radius: 999px;
            object-fit: cover;
            border: 3px solid var(--p);
            box-shadow: 0 0 40px color-mix(in srgb, var(--p) 35%, transparent);
            flex-shrink: 0;
        }
        .hero-info { flex: 1; min-width: 0; }
        .hero-league {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--ps);
            border: 1px solid var(--pb);
            color: var(--p);
            font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase;
            padding: 3px 10px; border-radius: 999px;
            margin-bottom: 10px;
        }
        .hero-name {
            font-size: clamp(24px, 5vw, 38px);
            font-weight: 900;
            letter-spacing: -.03em;
            line-height: 1.05;
            background: linear-gradient(120deg, var(--tx) 40%, var(--p));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .hero-city {
            font-size: 14px; color: var(--tx2); margin-top: 6px; font-weight: 500;
        }
        .hero-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
        .pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 999px;
            font-size: 11px; font-weight: 600;
            background: var(--c2); border: 1px solid var(--bd2);
            color: var(--tx2);
        }
        .pill.accent { background: var(--ps); border-color: var(--pb); color: var(--p); }

        /* ── FBA badge ── */
        .fba-badge {
            padding: 8px 16px;
            border: 1px solid var(--bd);
            border-radius: 999px;
            font-size: 11px; font-weight: 700; letter-spacing: .5px;
            color: var(--tx3);
            align-self: flex-start;
            margin-top: 4px;
        }

        /* ── Section title ── */
        .section {
            margin-top: 32px;
        }
        .section-head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 14px;
        }
        .section-title {
            display: flex; align-items: center; gap: 10px;
            font-size: 13px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .8px; color: var(--tx2);
        }
        .section-title i { color: var(--p); font-size: 16px; }
        .section-count {
            font-size: 11px; font-weight: 600;
            background: var(--c2); border: 1px solid var(--bd);
            padding: 2px 9px; border-radius: 999px; color: var(--tx3);
        }

        /* ── Stat row ── */
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
        .stat-card {
            background: var(--c1);
            border: 1px solid var(--bd);
            border-radius: var(--r);
            padding: 20px;
            position: relative; overflow: hidden;
            transition: border-color var(--t) var(--ease), transform var(--t) var(--ease);
        }
        .stat-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, var(--p), var(--s));
        }
        .stat-card:hover { border-color: var(--bd2); transform: translateY(-2px); }
        .stat-icon {
            width: 36px; height: 36px; border-radius: 9px;
            background: var(--ps);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; color: var(--p); margin-bottom: 14px;
        }
        .stat-val { font-size: 32px; font-weight: 900; line-height: 1; color: var(--tx); }
        .stat-val.accent { color: var(--p); }
        .stat-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--tx2); margin-top: 4px; }
        .stat-sub { font-size: 11px; color: var(--tx3); margin-top: 2px; }

        /* ── Player grid ── */
        .player-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
        .player-card {
            background: var(--c1);
            border: 1px solid var(--bd);
            border-radius: var(--r);
            padding: 16px;
            display: flex; flex-direction: column; gap: 8px;
            transition: border-color var(--t) var(--ease), transform var(--t) var(--ease);
            position: relative; overflow: hidden;
        }
        .player-card:hover { border-color: var(--pb); transform: translateY(-2px); }
        .player-card-top { display: flex; align-items: center; gap: 10px; }
        .player-photo {
            width: 44px; height: 44px; border-radius: 50%;
            object-fit: cover; border: 2px solid var(--bd2);
            background: var(--c3); flex-shrink: 0;
        }
        .player-pos-badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 26px; height: 20px; border-radius: 5px;
            background: var(--ps); color: var(--p);
            font-size: 9px; font-weight: 800; letter-spacing: .3px; text-transform: uppercase;
        }
        .player-name { font-size: 13px; font-weight: 700; color: var(--tx); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .player-meta { font-size: 11px; color: var(--tx2); }
        .player-ovr {
            position: absolute; top: 12px; right: 12px;
            font-size: 15px; font-weight: 900;
        }
        .starter-ribbon {
            position: absolute; top: 0; left: 0;
            width: 3px; height: 100%;
            background: linear-gradient(180deg, var(--p), var(--s));
            border-radius: var(--r) 0 0 var(--r);
        }

        /* ── Table style ── */
        .ftable { width: 100%; border-collapse: collapse; }
        .ftable th {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .8px; color: var(--tx3);
            padding: 0 12px 12px; text-align: left; border-bottom: 1px solid var(--bd);
        }
        .ftable td {
            padding: 12px; font-size: 13px; color: var(--tx2);
            border-bottom: 1px solid var(--bd);
        }
        .ftable tr:last-child td { border-bottom: none; }
        .ftable tr:hover td { background: var(--c2); }
        .ftable td strong { color: var(--tx); font-weight: 600; }

        /* ── Picks cards ── */
        .picks-year-group { margin-bottom: 20px; }
        .picks-year-label {
            font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
            color: var(--p); margin-bottom: 8px; padding-left: 4px;
        }
        .picks-row { display: flex; flex-wrap: wrap; gap: 8px; }
        .pick-chip {
            display: inline-flex; flex-direction: column;
            background: var(--c2); border: 1px solid var(--bd);
            border-radius: var(--rs); padding: 10px 14px;
            min-width: 110px;
            transition: border-color var(--t) var(--ease);
        }
        .pick-chip:hover { border-color: var(--pb); }
        .pick-round { font-size: 15px; font-weight: 900; color: var(--tx); }
        .pick-origin { font-size: 11px; color: var(--tx2); margin-top: 2px; }
        .pick-own { color: var(--p); }

        /* ── Trade cards ── */
        .trade-list { display: flex; flex-direction: column; gap: 10px; }
        .trade-card {
            background: var(--c1);
            border: 1px solid var(--bd);
            border-radius: var(--r);
            padding: 14px 18px;
            display: flex; align-items: center; gap: 14px;
            transition: border-color var(--t) var(--ease);
        }
        .trade-card:hover { border-color: var(--bd2); }
        .trade-team-block { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
        .trade-logo {
            width: 36px; height: 36px; border-radius: 9px;
            object-fit: cover; border: 1px solid var(--bd2);
            background: var(--c3); flex-shrink: 0;
        }
        .trade-tname { font-size: 13px; font-weight: 600; color: var(--tx); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .trade-arrow-icon { color: var(--p); font-size: 18px; flex-shrink: 0; }
        .trade-date { font-size: 11px; color: var(--tx3); white-space: nowrap; flex-shrink: 0; }

        /* ── Card wrapper ── */
        .card-box {
            background: var(--c1);
            border: 1px solid var(--bd);
            border-radius: var(--r);
            overflow: hidden;
        }
        .card-box-inner { padding: 20px; }

        /* ── Footer ── */
        .footer {
            text-align: center; padding: 32px 0 0;
            font-size: 12px; color: var(--tx3);
            border-top: 1px solid var(--bd);
            margin-top: 40px;
            display: flex; flex-direction: column; align-items: center; gap: 6px;
        }
        .footer-logo {
            width: 28px; height: 28px; border-radius: 7px;
            background: var(--ps); color: var(--p);
            font-size: 10px; font-weight: 900;
            display: flex; align-items: center; justify-content: center;
        }

        /* ── Responsive ── */
        @media (max-width: 640px) {
            .hero { padding: 28px 20px; gap: 18px; }
            .hero-logo { width: 72px; height: 72px; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .player-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
            .trade-date { display: none; }
            .card-box-inner { padding: 16px; }
            .wrap { padding: 0 12px 48px; }
        }
        @media (max-width: 400px) {
            .player-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<div class="bg-glow"></div>

<!-- HERO -->
<div style="background: var(--c1); border-bottom: 1px solid var(--bd);">
    <div class="wrap" style="padding-top: 0; padding-bottom: 0;">
        <div class="hero">
            <img src="<?= htmlspecialchars($teamLogo) ?>"
                 class="hero-logo"
                 alt="<?= htmlspecialchars($teamDisplayName) ?>"
                 onerror="this.src='/img/default-team.png'">
            <div class="hero-info">
                <?php if ($league !== ''): ?>
                <div class="hero-league">
                    <i class="bi bi-shield-fill" style="font-size:8px"></i>
                    <?= htmlspecialchars($league) ?>
                </div>
                <?php endif; ?>
                <div class="hero-name"><?= htmlspecialchars($teamDisplayName) ?></div>
                <?php if ($teamCity !== ''): ?>
                <div class="hero-city"><i class="bi bi-geo-alt" style="font-size:12px"></i> <?= htmlspecialchars($teamCity) ?></div>
                <?php endif; ?>
                <div class="hero-pills">
                    <?php if ($championships > 0): ?>
                    <span class="pill accent"><i class="bi bi-trophy-fill" style="font-size:10px"></i> <?= $championships ?> título<?= $championships > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                    <?php if ($rankingPosition): ?>
                    <span class="pill"><i class="bi bi-bar-chart-fill" style="font-size:10px"></i> #<?= $rankingPosition ?> no ranking</span>
                    <?php endif; ?>
                    <?php if ($players): ?>
                    <span class="pill"><i class="bi bi-people-fill" style="font-size:10px"></i> <?= count($players) ?> jogadores</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="fba-badge">FBA Brasil</div>
        </div>
    </div>
</div>

<div class="wrap">

    <!-- STAT CARDS (titles, ranking, averages inline) -->
    <?php
    $hasStats = in_array('titles', $modules) || in_array('ranking', $modules) || in_array('ai_avgs', $modules);
    if ($hasStats):
    ?>
    <div class="section">
        <div class="stats-row">
            <?php if (in_array('titles', $modules)): ?>
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-trophy-fill"></i></div>
                <div class="stat-val accent"><?= (int)$championships ?></div>
                <div class="stat-label">Títulos</div>
                <div class="stat-sub">Campeonatos ganhos</div>
            </div>
            <?php endif; ?>

            <?php if (in_array('ranking', $modules)): ?>
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-bar-chart-fill"></i></div>
                <div class="stat-val"><?= $rankingPosition ? '#' . $rankingPosition : '—' ?></div>
                <div class="stat-label">Ranking</div>
                <div class="stat-sub"><?= $rankingPoints !== null ? $rankingPoints . ' pts' : 'Sem dados' ?></div>
            </div>
            <?php endif; ?>

            <?php if (in_array('ai_avgs', $modules)): ?>
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-activity"></i></div>
                <div class="stat-val"><?= $avgOvr !== null ? number_format($avgOvr, 1, ',', '.') : '—' ?></div>
                <div class="stat-label">OVR Médio</div>
                <div class="stat-sub">Média do elenco</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-calendar3"></i></div>
                <div class="stat-val"><?= $avgAge !== null ? number_format($avgAge, 1, ',', '.') : '—' ?></div>
                <div class="stat-label">Idade Média</div>
                <div class="stat-sub">Média do elenco</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- LINEUP -->
    <?php if (in_array('lineup', $modules)): ?>
    <div class="section">
        <div class="section-head">
            <div class="section-title"><i class="bi bi-star-fill"></i> Escalação</div>
            <span class="section-count"><?= count($starters) ?> titulares</span>
        </div>
        <?php if (!$starters): ?>
        <div class="card-box"><div class="card-box-inner" style="color:var(--tx3);font-size:13px;text-align:center;padding:32px">Sem titulares cadastrados.</div></div>
        <?php else: ?>
        <div class="player-grid">
            <?php foreach ($starters as $p):
                $ovr  = (int)($p['ovr'] ?? 0);
                $photo = $p['photo_url'] ?? '';
            ?>
            <div class="player-card">
                <div class="starter-ribbon"></div>
                <div class="player-ovr" style="color:<?= ovrColor($ovr) ?>"><?= $ovr ?></div>
                <div class="player-card-top">
                    <?php if ($photo): ?>
                    <img src="<?= htmlspecialchars($photo) ?>" class="player-photo" alt="" onerror="this.style.display='none'">
                    <?php endif; ?>
                    <div>
                        <div class="player-pos-badge"><?= htmlspecialchars($p['position'] ?? '') ?></div>
                    </div>
                </div>
                <div>
                    <div class="player-name"><?= htmlspecialchars($p['name'] ?? '') ?></div>
                    <div class="player-meta"><?= (int)($p['age'] ?? 0) ?> anos</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ROSTER -->
    <?php if (in_array('roster', $modules)): ?>
    <div class="section">
        <div class="section-head">
            <div class="section-title"><i class="bi bi-people-fill"></i> Elenco Completo</div>
            <span class="section-count"><?= count($players) ?> jogadores</span>
        </div>
        <?php if (!$players): ?>
        <div class="card-box"><div class="card-box-inner" style="color:var(--tx3);font-size:13px;text-align:center;padding:32px">Sem jogadores cadastrados.</div></div>
        <?php else: ?>
        <div class="card-box">
            <div style="overflow-x:auto">
                <table class="ftable">
                    <thead>
                        <tr>
                            <th style="padding-left:20px">Jogador</th>
                            <th>Pos</th>
                            <th>Role</th>
                            <th style="text-align:right">Idade</th>
                            <th style="text-align:right;padding-right:20px">OVR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($players as $p):
                            $ovr  = (int)($p['ovr'] ?? 0);
                            $isTitular = ($p['role'] ?? '') === 'Titular';
                        ?>
                        <tr>
                            <td style="padding-left:20px">
                                <strong><?= htmlspecialchars($p['name'] ?? '') ?></strong>
                            </td>
                            <td><span class="player-pos-badge"><?= htmlspecialchars($p['position'] ?? '') ?></span></td>
                            <td>
                                <?php if ($isTitular): ?>
                                <span style="font-size:11px;font-weight:700;color:var(--p)">Titular</span>
                                <?php else: ?>
                                <span style="font-size:11px;color:var(--tx3)"><?= htmlspecialchars($p['role'] ?? '') ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right"><?= (int)($p['age'] ?? 0) ?></td>
                            <td style="text-align:right;padding-right:20px">
                                <strong style="color:<?= ovrColor($ovr) ?>"><?= $ovr ?></strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- PICKS -->
    <?php if (in_array('picks', $modules)): ?>
    <div class="section">
        <div class="section-head">
            <div class="section-title"><i class="bi bi-calendar-check-fill"></i> Picks de Draft</div>
            <span class="section-count"><?= count($picks) ?> picks</span>
        </div>
        <?php if (!$picks): ?>
        <div class="card-box"><div class="card-box-inner" style="color:var(--tx3);font-size:13px;text-align:center;padding:32px">Sem picks cadastradas.</div></div>
        <?php else: ?>
        <?php foreach ($picksByYear as $yr => $yearPicks): ?>
        <div class="picks-year-group">
            <div class="picks-year-label"><?= (int)$yr ?></div>
            <div class="picks-row">
                <?php foreach ($yearPicks as $pk):
                    $origFull = trim(($pk['orig_city'] ?? '') . ' ' . ($pk['orig_name'] ?? ''));
                    if ($origFull === '') $origFull = '#' . (int)($pk['original_team_id'] ?? 0);
                    $isOwn = (int)($pk['original_team_id'] ?? 0) === $teamId;
                    $roundLabel = 'R' . htmlspecialchars((string)($pk['round'] ?? ''));
                ?>
                <div class="pick-chip">
                    <div class="pick-round"><?= $roundLabel ?></div>
                    <div class="pick-origin <?= $isOwn ? 'pick-own' : '' ?>">
                        <?= $isOwn ? 'Própria' : htmlspecialchars($origFull) ?>
                    </div>
                    <?php if (!empty($pk['notes'])): ?>
                    <div style="font-size:10px;color:var(--tx3);margin-top:4px"><?= htmlspecialchars($pk['notes']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- TRADES -->
    <?php if (in_array('trades', $modules)): ?>
    <div class="section">
        <div class="section-head">
            <div class="section-title"><i class="bi bi-arrow-left-right"></i> Trades Recentes</div>
            <span class="section-count"><?= count($trades) ?></span>
        </div>
        <?php if (!$trades): ?>
        <div class="card-box"><div class="card-box-inner" style="color:var(--tx3);font-size:13px;text-align:center;padding:32px">Nenhuma trade aceita ainda.</div></div>
        <?php else: ?>
        <div class="trade-list">
            <?php foreach ($trades as $tr):
                $dateRaw = $tr['updated_at'] ?? $tr['created_at'] ?? '';
                $dateStr = '';
                if ($dateRaw) {
                    try { $dateStr = (new DateTime($dateRaw))->format('d/m/Y'); } catch (Exception $e) {}
                }
                $fromFull = trim(($tr['from_city'] ?? '') . ' ' . ($tr['from_name'] ?? ''));
                $toFull   = trim(($tr['to_city']   ?? '') . ' ' . ($tr['to_name']   ?? ''));
            ?>
            <div class="trade-card">
                <div class="trade-team-block">
                    <img src="<?= htmlspecialchars($tr['from_photo'] ?? '/img/default-team.png') ?>"
                         class="trade-logo" alt="" onerror="this.src='/img/default-team.png'">
                    <div class="trade-tname"><?= htmlspecialchars($fromFull) ?></div>
                </div>
                <div class="trade-arrow-icon"><i class="bi bi-arrow-left-right"></i></div>
                <div class="trade-team-block">
                    <img src="<?= htmlspecialchars($tr['to_photo'] ?? '/img/default-team.png') ?>"
                         class="trade-logo" alt="" onerror="this.src='/img/default-team.png'">
                    <div class="trade-tname"><?= htmlspecialchars($toFull) ?></div>
                </div>
                <?php if ($dateStr): ?>
                <div class="trade-date"><?= $dateStr ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- FOOTER -->
    <div class="footer">
        <div class="footer-logo">FBA</div>
        <span>Página pública gerada pelo <strong style="color:var(--tx2)">FBA Manager</strong></span>
        <span><?= htmlspecialchars('/times/' . $slug) ?></span>
    </div>

</div><!-- /.wrap -->
</body>
</html>
