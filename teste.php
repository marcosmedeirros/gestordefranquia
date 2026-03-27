<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();
ensureTeamDirectiveProfileColumns($pdo);

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

$teamDirectiveProfile = null;
$teamDirectiveProfileUpdatedAt = null;
if ($team && !empty($team['directive_profile'])) {
    $decodedProfile = json_decode($team['directive_profile'], true);
    if (is_array($decodedProfile)) {
        $teamDirectiveProfile = $decodedProfile;
        $teamDirectiveProfileUpdatedAt = $team['directive_profile_updated_at'] ?? null;
    }
}

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

$stmtPlayers = $pdo->prepare('SELECT COUNT(*) as total, SUM(ovr) as total_ovr FROM players WHERE team_id = ?');
$stmtPlayers->execute([$team['id']]);
$stats = $stmtPlayers->fetch();

$totalPlayers = $stats['total'] ?? 0;
$avgOvr = $totalPlayers > 0 ? round($stats['total_ovr'] / $totalPlayers, 1) : 0;
$minPlayers = 13;
$maxPlayers = 15;
$playersOutOfRange = $totalPlayers < $minPlayers || $totalPlayers > $maxPlayers;

$stmtTitulares = $pdo->prepare("SELECT * FROM players WHERE team_id = ? AND role = 'Titular' ORDER BY ovr DESC");
$stmtTitulares->execute([$team['id']]);
$titulares = $stmtTitulares->fetchAll();

$teamCap = 0;
$stmtCap = $pdo->prepare('SELECT SUM(ovr) as cap FROM (SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8) as top_eight');
$stmtCap->execute([$team['id']]);
$capData = $stmtCap->fetch();
$teamCap = $capData['cap'] ?? 0;

$capMin = 0; $capMax = 999;
try {
    $stmtCapLimits = $pdo->prepare('SELECT cap_min, cap_max FROM league_settings WHERE league = ?');
    $stmtCapLimits->execute([$team['league']]);
    $capLimits = $stmtCapLimits->fetch();
    if ($capLimits) { $capMin = $capLimits['cap_min'] ?? 0; $capMax = $capLimits['cap_max'] ?? 999; }
} catch (Exception $e) {}

$capOk = $teamCap >= $capMin && $teamCap <= $capMax;

$editalData = null; $hasEdital = false;
try {
    $stmtEdital = $pdo->prepare('SELECT edital, edital_file FROM league_settings WHERE league = ?');
    $stmtEdital->execute([$team['league']]);
    $editalData = $stmtEdital->fetch();
    $hasEdital = $editalData && !empty($editalData['edital_file']);
} catch (Exception $e) {}

$activeDirectiveDeadline = null; $hasActiveDirectiveSubmission = false;
try {
    $nowBrasilia = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
    $stmtDirective = $pdo->prepare("SELECT * FROM directive_deadlines WHERE league = ? AND is_active = 1 AND deadline_date > ? ORDER BY deadline_date ASC LIMIT 1");
    $stmtDirective->execute([$team['league'], $nowBrasilia]);
    $activeDirectiveDeadline = $stmtDirective->fetch();
    if ($activeDirectiveDeadline && !empty($activeDirectiveDeadline['deadline_date'])) {
        try {
            $deadlineDateTime = new DateTime($activeDirectiveDeadline['deadline_date'], new DateTimeZone('America/Sao_Paulo'));
            $activeDirectiveDeadline['deadline_date_display'] = $deadlineDateTime->format('d/m/Y \à\s H:i');
        } catch (Exception $e) {
            $activeDirectiveDeadline['deadline_date_display'] = date('d/m/Y', strtotime($activeDirectiveDeadline['deadline_date']));
        }
    }
    if ($activeDirectiveDeadline && !empty($team['id'])) {
        $stmtHasDirective = $pdo->prepare("SELECT id FROM team_directives WHERE team_id = ? AND deadline_id = ? LIMIT 1");
        $stmtHasDirective->execute([(int)$team['id'], (int)$activeDirectiveDeadline['id']]);
        $hasActiveDirectiveSubmission = (bool)$stmtHasDirective->fetchColumn();
    }
} catch (Exception $e) {}

$currentSeason = null;
try {
    $stmtSeason = $pdo->prepare("SELECT s.season_number, s.year, s.status, sp.sprint_number, sp.start_year FROM seasons s INNER JOIN sprints sp ON s.sprint_id = sp.id WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed')) ORDER BY s.created_at DESC LIMIT 1");
    $stmtSeason->execute([$team['league']]);
    $currentSeason = $stmtSeason->fetch();
} catch (Exception $e) {}

$seasonDisplayYear = null;
if ($currentSeason && isset($currentSeason['start_year'], $currentSeason['season_number'])) {
    $seasonDisplayYear = (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1;
} elseif ($currentSeason && isset($currentSeason['year'])) {
    $seasonDisplayYear = (int)$currentSeason['year'];
}

$stmtAllPlayers = $pdo->prepare("SELECT id, name, position, role, ovr, age FROM players WHERE team_id = ? ORDER BY ovr DESC, name ASC");
$stmtAllPlayers->execute([$team['id']]);
$allPlayers = $stmtAllPlayers->fetchAll(PDO::FETCH_ASSOC);

$stmtPicks = $pdo->prepare("SELECT p.season_year, p.round, orig.city, orig.name AS team_name, p.original_team_id, p.team_id FROM picks p JOIN teams orig ON p.original_team_id = orig.id WHERE p.team_id = ? ORDER BY p.season_year ASC, p.round ASC");
$stmtPicks->execute([$team['id']]);
$teamPicks = $stmtPicks->fetchAll(PDO::FETCH_ASSOC);
$copySeasonYear = !empty($seasonDisplayYear) ? (int)$seasonDisplayYear : (int)date('Y');
$teamPicksForCopy = array_values(array_filter($teamPicks, fn($p) => (int)($p['season_year'] ?? 0) > $copySeasonYear));

function syncTeamTradeCounterDashboard(PDO $pdo, int $teamId): int {
    try {
        $stmt = $pdo->prepare('SELECT current_cycle, trades_cycle, trades_used FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 0;
        $currentCycle = (int)($row['current_cycle'] ?? 0);
        $tradesCycle = (int)($row['trades_cycle'] ?? 0);
        $tradesUsed = (int)($row['trades_used'] ?? 0);
        if ($currentCycle > 0 && $tradesCycle !== $currentCycle) {
            $pdo->prepare('UPDATE teams SET trades_used = 0, trades_cycle = ? WHERE id = ?')->execute([$currentCycle, $teamId]);
            return 0;
        }
        if ($currentCycle > 0 && $tradesCycle <= 0) $pdo->prepare('UPDATE teams SET trades_cycle = ? WHERE id = ?')->execute([$currentCycle, $teamId]);
        return $tradesUsed;
    } catch (Exception $e) { return 0; }
}
$tradesCount = syncTeamTradeCounterDashboard($pdo, (int)$team['id']);

$lastTrade = null; $lastTradeFromPlayers = []; $lastTradeToPlayers = []; $lastTradeFromPicks = []; $lastTradeToPicks = [];
try {
    $stmtLastTrade = $pdo->prepare("SELECT t.*, t1.city as from_city, t1.name as from_name, t1.photo_url as from_photo, t2.city as to_city, t2.name as to_name, t2.photo_url as to_photo, u1.name as from_owner, u2.name as to_owner FROM trades t JOIN teams t1 ON t.from_team_id = t1.id JOIN teams t2 ON t.to_team_id = t2.id LEFT JOIN users u1 ON t1.user_id = u1.id LEFT JOIN users u2 ON t2.user_id = u2.id WHERE t.status = 'accepted' AND t1.league = ? ORDER BY t.updated_at DESC LIMIT 1");
    $stmtLastTrade->execute([$team['league']]);
    $lastTrade = $stmtLastTrade->fetch();
    if ($lastTrade) {
        $q = fn($col) => $pdo->prepare("SELECT p.name, p.position, p.ovr FROM players p JOIN trade_items ti ON p.id = ti.player_id WHERE ti.trade_id = ? AND ti.from_team = $col AND ti.player_id IS NOT NULL");
        $q2 = fn($col) => $pdo->prepare("SELECT pk.season_year, pk.round FROM picks pk JOIN trade_items ti ON pk.id = ti.pick_id WHERE ti.trade_id = ? AND ti.from_team = $col AND ti.pick_id IS NOT NULL");
        foreach ([['lastTradeFromPlayers','TRUE'],['lastTradeToPlayers','FALSE']] as [$var, $col]) { $s = $q($col); $s->execute([$lastTrade['id']]); $$var = $s->fetchAll(); }
        foreach ([['lastTradeFromPicks','TRUE'],['lastTradeToPicks','FALSE']] as [$var, $col]) { $s = $q2($col); $s->execute([$lastTrade['id']]); $$var = $s->fetchAll(); }
    }
} catch (Exception $e) {}

$maxTrades = 3; $tradesEnabled = 1;
try {
    $s = $pdo->prepare('SELECT max_trades, trades_enabled FROM league_settings WHERE league = ?');
    $s->execute([$team['league']]); $r = $s->fetch();
    if ($r) { if (isset($r['max_trades'])) $maxTrades = (int)$r['max_trades']; if (isset($r['trades_enabled'])) $tradesEnabled = (int)$r['trades_enabled']; }
} catch (Exception $e) {}

$topRanking = [];
try {
    $s = $pdo->prepare("SELECT t.id, t.city, t.name, t.photo_url, t.ranking_points, u.name as owner_name FROM teams t LEFT JOIN users u ON t.user_id = u.id WHERE t.league = ? ORDER BY t.ranking_points DESC LIMIT 5");
    $s->execute([$team['league']]); $topRanking = $s->fetchAll();
} catch (Exception $e) {}

$latestRumor = null;
try {
    $s = $pdo->prepare('SELECT r.content, r.created_at, t.city, t.name, t.photo_url, u.name as gm_name FROM rumors r INNER JOIN teams t ON r.team_id = t.id INNER JOIN users u ON r.user_id = u.id WHERE r.league = ? ORDER BY r.created_at DESC LIMIT 1');
    $s->execute([$team['league']]); $latestRumor = $s->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$lastChampion = null; $lastRunnerUp = null; $lastMVP = null; $lastSprintInfo = null;
try {
    $s = $pdo->prepare("SELECT sh.*, t1.id as champion_id, t1.city as champion_city, t1.name as champion_name, t1.photo_url as champion_photo, u1.name as champion_owner, t2.id as runner_up_id, t2.city as runner_up_city, t2.name as runner_up_name, t2.photo_url as runner_up_photo, u2.name as runner_up_owner FROM season_history sh LEFT JOIN teams t1 ON sh.champion_team_id = t1.id LEFT JOIN users u1 ON t1.user_id = u1.id LEFT JOIN teams t2 ON sh.runner_up_team_id = t2.id LEFT JOIN users u2 ON t2.user_id = u2.id WHERE sh.league = ? ORDER BY sh.id DESC LIMIT 1");
    $s->execute([$team['league']]); $lastSprintInfo = $s->fetch();
    if ($lastSprintInfo) {
        if ($lastSprintInfo['champion_id']) $lastChampion = ['id'=>$lastSprintInfo['champion_id'],'city'=>$lastSprintInfo['champion_city'],'name'=>$lastSprintInfo['champion_name'],'photo_url'=>$lastSprintInfo['champion_photo'],'owner_name'=>$lastSprintInfo['champion_owner']];
        if ($lastSprintInfo['runner_up_id']) $lastRunnerUp = ['id'=>$lastSprintInfo['runner_up_id'],'city'=>$lastSprintInfo['runner_up_city'],'name'=>$lastSprintInfo['runner_up_name'],'photo_url'=>$lastSprintInfo['runner_up_photo'],'owner_name'=>$lastSprintInfo['runner_up_owner']];
        if (!empty($lastSprintInfo['mvp_player'])) {
            $lastMVP = ['name'=>$lastSprintInfo['mvp_player'],'position'=>null,'ovr'=>null,'team_city'=>null,'team_name'=>null];
            if (!empty($lastSprintInfo['mvp_team_id'])) { $sm = $pdo->prepare("SELECT city, name FROM teams WHERE id = ?"); $sm->execute([$lastSprintInfo['mvp_team_id']]); $mvpTeam = $sm->fetch(); if ($mvpTeam) { $lastMVP['team_city']=$mvpTeam['city']; $lastMVP['team_name']=$mvpTeam['name']; } }
        }
    }
} catch (Exception $e) {}

$activeInitDraftSession = null; $currentDraftPick = null; $nextDraftPick = null;
$remainingDraftPicks = 0; $initDraftTeamsPerRound = 0;
try {
    $s = $pdo->prepare("SELECT * FROM initdraft_sessions WHERE league = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
    $s->execute([$team['league']]); $activeInitDraftSession = $s->fetch(PDO::FETCH_ASSOC);
    if ($activeInitDraftSession) {
        $sid = (int)$activeInitDraftSession['id'];
        $s = $pdo->prepare("SELECT io.*, t.city, t.name AS team_name, t.photo_url, u.name AS owner_name FROM initdraft_order io JOIN teams t ON io.team_id = t.id LEFT JOIN users u ON t.user_id = u.id WHERE io.initdraft_session_id = ? AND io.picked_player_id IS NULL ORDER BY io.round ASC, io.pick_position ASC LIMIT 1");
        $s->execute([$sid]); $currentDraftPick = $s->fetch(PDO::FETCH_ASSOC);
        if ($currentDraftPick) {
            $s = $pdo->prepare("SELECT io.*, t.city, t.name AS team_name, t.photo_url FROM initdraft_order io JOIN teams t ON io.team_id = t.id WHERE io.initdraft_session_id = ? AND io.picked_player_id IS NULL ORDER BY io.round ASC, io.pick_position ASC LIMIT 1 OFFSET 1");
            $s->execute([$sid]); $nextDraftPick = $s->fetch(PDO::FETCH_ASSOC);
            $s = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND picked_player_id IS NULL'); $s->execute([$sid]); $remainingDraftPicks = (int)$s->fetchColumn();
            $s = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = 1'); $s->execute([$sid]); $initDraftTeamsPerRound = (int)$s->fetchColumn();
        }
    }
} catch (Exception $e) {}

$activeDraft = $activeInitDraftSession && $currentDraftPick;
$currentDraftOverallNumber = null; $nextDraftOverallNumber = null;
if ($currentDraftPick && $initDraftTeamsPerRound > 0) $currentDraftOverallNumber = (($currentDraftPick['round'] - 1) * $initDraftTeamsPerRound) + $currentDraftPick['pick_position'];
if ($nextDraftPick && $initDraftTeamsPerRound > 0) $nextDraftOverallNumber = (($nextDraftPick['round'] - 1) * $initDraftTeamsPerRound) + $nextDraftPick['pick_position'];

$capPct = $capMax > 0 ? min(100, round(($teamCap / $capMax) * 100)) : 0;
$tradesPct = $maxTrades > 0 ? min(100, round(($tradesCount / $maxTrades) * 100)) : 0;
$playersPct = $maxPlayers > 0 ? min(100, round(($totalPlayers / $maxPlayers) * 100)) : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="manifest" href="/manifest.json?v=3">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
    <title>Dashboard - FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
        /* ── Tokens ──────────────────────────────────── */
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
            --radius-xs:  6px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
        }

        /* ── Shell ───────────────────────────────────── */
        .app { display: flex; min-height: 100vh; }

        /* ── Sidebar ─────────────────────────────────── */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: var(--sidebar-w); height: 100vh;
            background: var(--panel);
            border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            z-index: 300;
            transition: transform var(--t) var(--ease);
            overflow-y: auto;
            scrollbar-width: none;
        }
        .sidebar::-webkit-scrollbar { display: none; }

        .sb-brand {
            padding: 22px 18px 18px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
            flex-shrink: 0;
        }
        .sb-logo {
            width: 34px; height: 34px; border-radius: 9px;
            background: var(--red);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 13px; color: #fff;
            flex-shrink: 0;
        }
        .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }

        /* Team card in sidebar */
        .sb-team {
            margin: 14px 14px 0;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px;
            display: flex; align-items: center; gap: 10px;
            flex-shrink: 0;
        }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
        .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }

        /* Season badge */
        .sb-season {
            margin: 10px 14px 0;
            background: var(--red-soft);
            border: 1px solid var(--border-red);
            border-radius: 8px;
            padding: 8px 12px;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        .sb-season-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); }
        .sb-season-val { font-size: 14px; font-weight: 700; color: var(--red); }

        /* Nav */
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
        .sb-nav a {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 10px; border-radius: var(--radius-sm);
            color: var(--text-2); font-size: 13px; font-weight: 500;
            text-decoration: none; margin-bottom: 2px;
            transition: all var(--t) var(--ease);
        }
        .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
        .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
        .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
        .sb-nav a.active i { color: var(--red); }

        /* Footer */
        .sb-footer {
            padding: 12px 14px;
            border-top: 1px solid var(--border);
            display: flex; align-items: center; gap: 10px;
            flex-shrink: 0;
        }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout {
            width: 26px; height: 26px; border-radius: 7px;
            background: transparent; border: 1px solid var(--border);
            color: var(--text-2); display: flex; align-items: center; justify-content: center;
            font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease);
            text-decoration: none; flex-shrink: 0;
        }
        .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        /* ── Topbar mobile ───────────────────────────── */
        .topbar {
            display: none; position: fixed; top: 0; left: 0; right: 0;
            height: 54px; background: var(--panel);
            border-bottom: 1px solid var(--border);
            align-items: center; padding: 0 16px; gap: 12px; z-index: 199;
        }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn {
            width: 34px; height: 34px; border-radius: 9px;
            background: var(--panel-2); border: 1px solid var(--border);
            color: var(--text); display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 17px;
        }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 199; }
        .sb-overlay.show { display: block; }

        /* ── Main ────────────────────────────────────── */
        .main {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
            width: calc(100% - var(--sidebar-w));
            display: flex; flex-direction: column;
        }

        /* ── Hero header ─────────────────────────────── */
        .dash-hero {
            padding: 32px 32px 0;
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 16px; flex-wrap: wrap;
        }
        .dash-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .dash-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
        .dash-sub { font-size: 13px; color: var(--text-2); margin-top: 3px; }

        .hero-badges { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding-top: 4px; }
        .hbadge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; border-radius: 999px;
            font-size: 12px; font-weight: 600;
            border: 1px solid var(--border-md);
            background: var(--panel);
        }
        .hbadge.red { background: var(--red-soft); border-color: var(--border-red); color: var(--red); }
        .hbadge.green { background: rgba(34,197,94,.10); border-color: rgba(34,197,94,.2); color: var(--green); }
        .hbadge.amber { background: rgba(245,158,11,.10); border-color: rgba(245,158,11,.2); color: var(--amber); }

        /* ── Alert banner (deadline) ─────────────────── */
        .deadline-banner {
            margin: 20px 32px 0;
            background: linear-gradient(90deg, rgba(252,0,37,.12), rgba(252,0,37,.04));
            border: 1px solid var(--border-red);
            border-left: 3px solid var(--red);
            border-radius: var(--radius-sm);
            padding: 14px 18px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            flex-wrap: wrap;
        }
        .deadline-left { display: flex; align-items: center; gap: 12px; }
        .deadline-icon { width: 36px; height: 36px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 16px; flex-shrink: 0; }
        .deadline-title { font-size: 14px; font-weight: 700; color: var(--text); }
        .deadline-sub { font-size: 12px; color: var(--text-2); }
        .deadline-sub strong { color: var(--red); }
        .deadline-btn {
            padding: 8px 16px; border-radius: 8px;
            background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 12px; font-weight: 600;
            cursor: pointer; transition: filter var(--t) var(--ease);
            text-decoration: none; white-space: nowrap;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .deadline-btn:hover { filter: brightness(1.1); color: #fff; }

        /* ── Draft live banner ───────────────────────── */
        .draft-banner {
            margin: 12px 32px 0;
            background: linear-gradient(90deg, rgba(59,130,246,.12), rgba(59,130,246,.04));
            border: 1px solid rgba(59,130,246,.25);
            border-left: 3px solid var(--blue);
            border-radius: var(--radius-sm);
            padding: 14px 18px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            flex-wrap: wrap;
        }
        .draft-banner-left { display: flex; align-items: center; gap: 12px; }
        .draft-banner-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid var(--blue); flex-shrink: 0; }
        .draft-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 8px; border-radius: 999px; background: rgba(59,130,246,.15); border: 1px solid rgba(59,130,246,.3); color: #93c5fd; font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; }
        .draft-banner-title { font-size: 14px; font-weight: 700; }
        .draft-banner-sub { font-size: 12px; color: var(--text-2); }

        /* ── Content grid ────────────────────────────── */
        .content { padding: 20px 32px 40px; flex: 1; }

        /* ── Stat cards row ──────────────────────────── */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }

        .stat-c {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            position: relative;
            overflow: hidden;
            transition: border-color var(--t) var(--ease), transform var(--t) var(--ease);
            text-decoration: none;
            display: block;
        }
        .stat-c::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
            background: var(--accent, var(--red));
            opacity: 0; transition: opacity var(--t) var(--ease);
        }
        .stat-c:hover { border-color: var(--border-md); transform: translateY(-2px); }
        .stat-c:hover::before { opacity: 1; }
        .stat-c-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; }
        .stat-c-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .stat-c-val { font-size: 28px; font-weight: 800; line-height: 1; color: var(--text); }
        .stat-c-label { font-size: 11px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; color: var(--text-2); margin-bottom: 6px; }
        .stat-c-note { font-size: 11px; color: var(--text-3); }
        .stat-c-bar { height: 3px; background: var(--panel-3); border-radius: 999px; overflow: hidden; margin-top: 8px; }
        .stat-c-fill { height: 100%; border-radius: 999px; background: var(--accent, var(--red)); transition: width .6s var(--ease); }
        .stat-c.warn .stat-c-val { color: #ef4444; }
        .stat-c.ok .stat-c-val { color: var(--green); }

        /* ── Main bento grid ─────────────────────────── */
        .bento { display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: auto; gap: 14px; }

        /* card base */
        .bc {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            display: flex; flex-direction: column;
        }
        .bc-head {
            padding: 16px 18px 14px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            flex-shrink: 0;
        }
        .bc-title { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .bc-title i { color: var(--red); font-size: 15px; }
        .bc-body { padding: 16px 18px; flex: 1; }
        .bc-foot { padding: 12px 18px; border-top: 1px solid var(--border); flex-shrink: 0; }

        .bc-link {
            font-size: 11px; font-weight: 600; letter-spacing: .3px;
            color: var(--text-2); text-decoration: none;
            display: inline-flex; align-items: center; gap: 4px;
            transition: color var(--t) var(--ease);
        }
        .bc-link:hover { color: var(--red); }

        /* ── Starters card (full width) ──────────────── */
        .span-3 { grid-column: span 3; }
        .span-2 { grid-column: span 2; }

        .starters-grid { display: flex; gap: 12px; flex-wrap: wrap; }
        .starter-chip {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 12px 14px;
            display: flex; align-items: center; gap: 12px;
            flex: 1; min-width: 160px;
            transition: border-color var(--t) var(--ease);
        }
        .starter-chip:hover { border-color: var(--border-red); }
        .starter-photo { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-md); background: var(--panel-3); flex-shrink: 0; }
        .starter-pos { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 18px; border-radius: 4px; background: var(--red-soft); color: var(--red); font-size: 9px; font-weight: 800; letter-spacing: .3px; text-transform: uppercase; }
        .starter-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .starter-ovr { font-size: 12px; color: var(--text-2); }

        /* ── Ranking list ────────────────────────────── */
        .rank-row {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 0;
            border-bottom: 1px solid var(--border);
            transition: all var(--t) var(--ease);
        }
        .rank-row:last-child { border-bottom: none; }
        .rank-row:hover { transform: translateX(3px); }
        .rank-num { width: 22px; font-size: 12px; font-weight: 800; color: var(--text-3); text-align: center; flex-shrink: 0; }
        .rank-num.gold { color: #f59e0b; }
        .rank-num.silver { color: #94a3b8; }
        .rank-num.bronze { color: #cd7c4a; }
        .rank-logo { width: 28px; height: 28px; border-radius: 7px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .rank-name { flex: 1; min-width: 0; }
        .rank-team { font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rank-owner { font-size: 11px; color: var(--text-2); }
        .rank-pts { font-size: 14px; font-weight: 800; color: var(--red); }
        .rank-pts-label { font-size: 10px; color: var(--text-3); }
        .rank-row.me { background: var(--red-soft); border-radius: 8px; padding: 9px 8px; margin: 0 -8px; border-bottom: none; }

        /* ── Rumor card ──────────────────────────────── */
        .rumor-bubble {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 14px 14px 14px 4px;
            padding: 14px 16px;
            font-size: 13px; line-height: 1.55; color: var(--text);
        }
        .rumor-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
        .rumor-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .rumor-team { font-size: 13px; font-weight: 600; }
        .rumor-gm { font-size: 11px; color: var(--text-2); }
        .rumor-date { margin-top: 10px; font-size: 11px; color: var(--text-3); display: flex; align-items: center; gap: 4px; }

        /* ── Trade card ──────────────────────────────── */
        .trade-teams { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
        .trade-team { flex: 1; text-align: center; }
        .trade-team img { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-md); display: block; margin: 0 auto 4px; }
        .trade-team-name { font-size: 11px; font-weight: 600; color: var(--text); }
        .trade-arrow { font-size: 18px; color: var(--red); flex-shrink: 0; }
        .trade-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .trade-col { background: var(--panel-2); border: 1px solid var(--border); border-radius: 9px; padding: 10px 12px; }
        .trade-col-label { font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: var(--text-3); margin-bottom: 8px; }
        .trade-item { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--text-2); margin-bottom: 5px; }
        .trade-item i { color: var(--red); font-size: 11px; flex-shrink: 0; }
        .trade-item strong { color: var(--text); }
        .trade-date { font-size: 11px; color: var(--text-3); text-align: center; margin-top: 10px; }

        /* ── League info card ────────────────────────── */
        .league-logo-img { width: 64px; height: 64px; object-fit: contain; display: block; margin: 0 auto 10px; }
        .league-stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
        .league-stat { background: var(--panel-2); border: 1px solid var(--border); border-radius: 9px; padding: 10px 12px; }
        .league-stat-label { font-size: 10px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; color: var(--text-3); margin-bottom: 4px; }
        .league-stat-val { font-size: 15px; font-weight: 700; color: var(--text); }

        /* ── Winners card ────────────────────────────── */
        .winner-row {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 9px;
            background: var(--panel-2); border: 1px solid var(--border);
            margin-bottom: 8px;
            transition: all var(--t) var(--ease);
        }
        .winner-row:last-child { margin-bottom: 0; }
        .winner-row:hover { transform: scale(1.01); }
        .winner-row.gold { border-color: rgba(245,158,11,.3); background: rgba(245,158,11,.06); }
        .winner-row.silver { border-color: rgba(148,163,184,.3); }
        .winner-row.mvp { border-color: var(--border-red); background: var(--red-soft); }
        .winner-icon { font-size: 18px; flex-shrink: 0; }
        .winner-logo { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .winner-title { font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: var(--text-3); }
        .winner-name { font-size: 12px; font-weight: 600; color: var(--text); }
        .winner-owner { font-size: 11px; color: var(--text-2); }

        /* ── Quick actions ───────────────────────────── */
        .quick-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .quick-btn {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 12px;
            text-align: center; cursor: pointer;
            text-decoration: none; color: var(--text);
            transition: all var(--t) var(--ease);
            display: block;
        }
        .quick-btn:hover { border-color: var(--border-red); background: var(--red-soft); color: var(--text); transform: translateY(-2px); }
        .quick-btn i { font-size: 20px; color: var(--red); display: block; margin-bottom: 6px; }
        .quick-btn-label { font-size: 11px; font-weight: 600; }

        /* ── Empty states ────────────────────────────── */
        .empty { padding: 24px 16px; text-align: center; color: var(--text-3); }
        .empty i { font-size: 28px; display: block; margin-bottom: 8px; }
        .empty p { font-size: 12px; }

        /* ── Footer strip ────────────────────────────── */
        .footer-strip {
            margin: 0 32px 32px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 12px 18px;
            display: flex; gap: 20px; flex-wrap: wrap; align-items: center;
        }
        .footer-item { font-size: 12px; color: var(--text-2); }
        .footer-item strong { color: var(--text); font-weight: 600; }

        /* ── Animations ──────────────────────────────── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .stat-c, .bc { animation: fadeUp .4s var(--ease) both; }

        /* ── Responsive ──────────────────────────────── */
        @media (max-width: 1100px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .bento { grid-template-columns: 1fr 1fr; }
            .span-3 { grid-column: span 2; }
        }
        @media (max-width: 860px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .dash-hero, .deadline-banner, .draft-banner, .content, .footer-strip { padding-left: 16px; padding-right: 16px; }
            .footer-strip { margin-left: 16px; margin-right: 16px; }
            .bento { grid-template-columns: 1fr; }
            .span-2, .span-3 { grid-column: span 1; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .quick-grid { grid-template-columns: repeat(3, 1fr); }
            .dash-hero { padding-top: 18px; }
        }
        @media (max-width: 480px) {
            .stats-row { grid-template-columns: 1fr 1fr; }
            .starters-grid { gap: 8px; }
            .starter-chip { min-width: calc(50% - 4px); }
        }

        /* Override bootstrap conflicts */
        .badge { font-family: var(--font); }
        a { color: inherit; }
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
                <span>Painel do GM</span>
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

        <?php if ($currentSeason): ?>
        <div class="sb-season">
            <div>
                <div class="sb-season-label">Temporada</div>
                <div class="sb-season-val"><?= $seasonDisplayYear ?></div>
            </div>
            <div style="text-align:right">
                <div class="sb-season-label">Sprint</div>
                <div class="sb-season-val"><?= (int)($currentSeason['sprint_number'] ?? 1) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <nav class="sb-nav">
            <div class="sb-section">Principal</div>
            <a href="/dashboard.php" class="active"><i class="bi bi-house-door-fill"></i> Dashboard</a>
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

            <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
            <div class="sb-section">Admin</div>
            <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
            <a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a>
            <?php endif; ?>

            <div class="sb-section">Conta</div>
            <a href="/settings.php"><i class="bi bi-gear-fill"></i> Configurações</a>
        </nav>

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

    <!-- ══════════════════════════════════════════════
         MAIN
    ══════════════════════════════════════════════ -->
    <main class="main">

        <!-- Hero header -->
        <div class="dash-hero">
            <div>
                <div class="dash-eyebrow">Dashboard · <?= htmlspecialchars($user['league']) ?></div>
                <h1 class="dash-title">Bem-vindo, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?> 👋</h1>
                <p class="dash-sub"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></p>
            </div>
            <div class="hero-badges">
                <span class="hbadge green">
                    <i class="bi bi-star-fill" style="font-size:10px"></i>
                    <?= (int)($team['ranking_points'] ?? 0) ?> pts
                </span>
                <span class="hbadge amber">
                    <i class="bi bi-coin" style="font-size:10px"></i>
                    <?= (int)($team['moedas'] ?? 0) ?> moedas
                </span>
                <button id="copyTeamBtn" class="hbadge" style="cursor:pointer;background:var(--panel-2);border-color:var(--border-md)">
                    <i class="bi bi-clipboard-check" style="font-size:10px"></i> Copiar time
                </button>
            </div>
        </div>

        <!-- Deadline banner -->
        <?php if ($activeDirectiveDeadline): ?>
        <a href="/diretrizes.php" class="deadline-banner text-decoration-none">
            <div class="deadline-left">
                <div class="deadline-icon">
                    <?php if ($hasActiveDirectiveSubmission): ?>
                    <i class="bi bi-check-circle-fill"></i>
                    <?php else: ?>
                    <i class="bi bi-clock-fill"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="deadline-title">
                        <?php if ($hasActiveDirectiveSubmission): ?>
                        <i class="bi bi-check2 me-1" style="color:var(--green)"></i>Rotação Enviada — Revisar
                        <?php else: ?>
                        Envio de Rotações Pendente
                        <?php endif; ?>
                    </div>
                    <div class="deadline-sub">
                        <?= htmlspecialchars($activeDirectiveDeadline['description'] ?? 'Diretrizes de jogo') ?> ·
                        Prazo: <strong><?= htmlspecialchars($activeDirectiveDeadline['deadline_date_display'] ?? '') ?></strong>
                    </div>
                </div>
            </div>
            <div class="deadline-btn">
                <?php if ($hasActiveDirectiveSubmission): ?>
                <i class="bi bi-search"></i> Revisar
                <?php else: ?>
                <i class="bi bi-send-fill"></i> Enviar agora
                <?php endif; ?>
            </div>
        </a>
        <?php endif; ?>

        <!-- Draft live banner -->
        <?php if ($activeDraft && $currentDraftPick): ?>
        <div class="draft-banner">
            <div class="draft-banner-left">
                <img class="draft-banner-avatar"
                     src="<?= htmlspecialchars($currentDraftPick['photo_url'] ?? '/img/default-team.png') ?>"
                     alt="" onerror="this.src='/img/default-team.png'">
                <div>
                    <div style="margin-bottom:4px"><span class="draft-badge"><i class="bi bi-broadcast-pin"></i> Draft ao vivo</span></div>
                    <div class="draft-banner-title"><?= htmlspecialchars($currentDraftPick['city'] . ' ' . $currentDraftPick['team_name']) ?> está na vez</div>
                    <div class="draft-banner-sub">
                        <?php if ($currentDraftOverallNumber): ?>Pick #<?= $currentDraftOverallNumber ?> · <?php endif; ?>
                        R<?= (int)$currentDraftPick['round'] ?> · Pick <?= (int)$currentDraftPick['pick_position'] ?> · <?= $remainingDraftPicks ?> restantes
                    </div>
                </div>
            </div>
            <?php if ($activeInitDraftSession && !empty($activeInitDraftSession['access_token'])): ?>
            <a href="/initdraftselecao.php?token=<?= htmlspecialchars($activeInitDraftSession['access_token']) ?>"
               style="padding:8px 14px;border-radius:8px;background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);color:#93c5fd;font-size:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                <i class="bi bi-trophy"></i> Sala do Draft
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── Content ── -->
        <div class="content">

            <!-- Stat cards -->
            <div class="stats-row">
                <a href="/my-roster.php" class="stat-c <?= $playersOutOfRange ? 'warn' : '' ?>"
                   style="--accent:<?= $playersOutOfRange ? '#ef4444' : 'var(--red)' ?>; animation-delay:.05s">
                    <div class="stat-c-top">
                        <div>
                            <div class="stat-c-label">Jogadores</div>
                            <div class="stat-c-val"><?= $totalPlayers ?></div>
                        </div>
                        <div class="stat-c-icon" style="background:rgba(252,0,37,.10)">
                            <i class="bi bi-people-fill" style="color:var(--red)"></i>
                        </div>
                    </div>
                    <div class="stat-c-note">Min <?= $minPlayers ?> · Max <?= $maxPlayers ?></div>
                    <div class="stat-c-bar"><div class="stat-c-fill" style="width:<?= $playersPct ?>%"></div></div>
                </a>

                <div class="stat-c <?= $capOk ? 'ok' : 'warn' ?>"
                     style="--accent:<?= $capOk ? 'var(--green)' : '#ef4444' ?>; animation-delay:.1s">
                    <div class="stat-c-top">
                        <div>
                            <div class="stat-c-label">CAP Top 8</div>
                            <div class="stat-c-val" style="color:<?= $capOk ? 'var(--green)' : '#ef4444' ?>"><?= $teamCap ?></div>
                        </div>
                        <div class="stat-c-icon" style="background:<?= $capOk ? 'rgba(34,197,94,.10)' : 'rgba(239,68,68,.10)' ?>">
                            <i class="bi bi-cash-stack" style="color:<?= $capOk ? 'var(--green)' : '#ef4444' ?>"></i>
                        </div>
                    </div>
                    <div class="stat-c-note">Faixa: <?= $capMin ?> – <?= $capMax ?></div>
                    <div class="stat-c-bar"><div class="stat-c-fill" style="width:<?= $capPct ?>%"></div></div>
                </div>

                <a href="/picks.php" class="stat-c" style="--accent:var(--green);animation-delay:.15s">
                    <div class="stat-c-top">
                        <div>
                            <div class="stat-c-label">Picks</div>
                            <div class="stat-c-val"><?= count($teamPicks) ?></div>
                        </div>
                        <div class="stat-c-icon" style="background:rgba(34,197,94,.10)">
                            <i class="bi bi-calendar-check-fill" style="color:var(--green)"></i>
                        </div>
                    </div>
                    <div class="stat-c-note">Próximas escolhas</div>
                    <div class="stat-c-bar"><div class="stat-c-fill" style="width:<?= min(100, count($teamPicks) * 10) ?>%"></div></div>
                </a>

                <a href="/trades.php" class="stat-c" style="--accent:var(--blue);animation-delay:.2s">
                    <div class="stat-c-top">
                        <div>
                            <div class="stat-c-label">Trades</div>
                            <div class="stat-c-val"><?= $tradesCount ?><span style="font-size:16px;color:var(--text-3);font-weight:400">/<?= $maxTrades ?></span></div>
                        </div>
                        <div class="stat-c-icon" style="background:rgba(59,130,246,.10)">
                            <i class="bi bi-arrow-left-right" style="color:var(--blue)"></i>
                        </div>
                    </div>
                    <div class="stat-c-note"><?= $tradesEnabled ? 'Trocas ativas' : '<span style="color:#ef4444">Bloqueadas</span>' ?></div>
                    <div class="stat-c-bar"><div class="stat-c-fill" style="width:<?= $tradesPct ?>%"></div></div>
                </a>
            </div>

            <!-- Bento grid -->
            <div class="bento">

                <!-- ── Starters ── (full width) -->
                <div class="bc span-3" style="animation-delay:.25s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-trophy"></i> Quinteto Titular</div>
                        <a href="/my-roster.php" class="bc-link">Gerenciar elenco <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="bc-body">
                        <?php if (count($titulares) > 0): ?>
                        <div class="starters-grid">
                            <?php foreach ($titulares as $i => $player):
                                $pn = $player['name'] ?? '';
                                $cp = trim((string)($player['foto_adicional'] ?? ''));
                                if ($cp && !preg_match('#^https?://#i', $cp)) $cp = '/' . ltrim($cp, '/');
                                $nbId = $player['nba_player_id'] ?? null;
                                $photo = $cp ?: ($nbId ? "https://cdn.nba.com/headshots/nba/latest/1040x760/{$nbId}.png" : "https://ui-avatars.com/api/?name=" . rawurlencode($pn) . "&background=1c1c21&color=fc0025&rounded=true&bold=true");
                            ?>
                            <div class="starter-chip" style="animation-delay:<?= .28 + $i * .04 ?>s">
                                <img class="starter-photo" src="<?= htmlspecialchars($photo) ?>"
                                     alt="<?= htmlspecialchars($pn) ?>"
                                     onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($pn) ?>&background=1c1c21&color=fc0025&rounded=true&bold=true'">
                                <div style="min-width:0">
                                    <div style="margin-bottom:4px"><span class="starter-pos"><?= htmlspecialchars($player['position']) ?></span></div>
                                    <div class="starter-name"><?= htmlspecialchars($pn) ?></div>
                                    <div class="starter-ovr">OVR <?= $player['ovr'] ?> · <?= $player['age'] ?>y</div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty">
                            <i class="bi bi-exclamation-circle"></i>
                            <p>Nenhum titular definido. <a href="/my-roster.php" style="color:var(--red)">Adicionar jogadores</a></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Ranking ── -->
                <div class="bc" style="animation-delay:.3s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-trophy-fill"></i> Top 5 Ranking</div>
                        <a href="/rankings.php" class="bc-link">Ver todos <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="bc-body" style="padding-top:8px;padding-bottom:8px">
                        <?php if (count($topRanking) > 0): ?>
                        <?php foreach ($topRanking as $idx => $rt): ?>
                        <div class="rank-row <?= $rt['id'] == $team['id'] ? 'me' : '' ?>">
                            <div class="rank-num <?= $idx === 0 ? 'gold' : ($idx === 1 ? 'silver' : ($idx === 2 ? 'bronze' : '')) ?>">
                                <?= $idx + 1 ?>
                            </div>
                            <img class="rank-logo"
                                 src="<?= htmlspecialchars($rt['photo_url'] ?? '/img/default-team.png') ?>"
                                 alt="" onerror="this.src='/img/default-team.png'">
                            <div class="rank-name">
                                <div class="rank-team"><?= htmlspecialchars($rt['city'] . ' ' . $rt['name']) ?></div>
                                <div class="rank-owner"><?= htmlspecialchars($rt['owner_name'] ?? '') ?></div>
                            </div>
                            <div style="text-align:right;flex-shrink:0">
                                <div class="rank-pts"><?= (int)$rt['ranking_points'] ?></div>
                                <div class="rank-pts-label">pts</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="empty"><i class="bi bi-trophy"></i><p>Ranking em breve</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Último Sprint ── -->
                <div class="bc" style="animation-delay:.34s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-award-fill"></i> Último Sprint</div>
                        <?php if ($lastSprintInfo): ?>
                        <span style="font-size:11px;color:var(--text-2)">Sprint <?= (int)($lastSprintInfo['sprint_number'] ?? 0) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="bc-body">
                        <?php if ($lastChampion || $lastRunnerUp || $lastMVP): ?>
                        <?php if ($lastChampion): ?>
                        <div class="winner-row gold">
                            <i class="bi bi-trophy-fill winner-icon" style="color:var(--amber)"></i>
                            <img class="winner-logo" src="<?= htmlspecialchars($lastChampion['photo_url'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
                            <div>
                                <div class="winner-title">Campeão</div>
                                <div class="winner-name"><?= htmlspecialchars($lastChampion['city'] . ' ' . $lastChampion['name']) ?></div>
                                <div class="winner-owner"><?= htmlspecialchars($lastChampion['owner_name'] ?? '') ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($lastRunnerUp): ?>
                        <div class="winner-row silver">
                            <i class="bi bi-award winner-icon" style="color:#94a3b8"></i>
                            <img class="winner-logo" src="<?= htmlspecialchars($lastRunnerUp['photo_url'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
                            <div>
                                <div class="winner-title">Vice-Campeão</div>
                                <div class="winner-name"><?= htmlspecialchars($lastRunnerUp['city'] . ' ' . $lastRunnerUp['name']) ?></div>
                                <div class="winner-owner"><?= htmlspecialchars($lastRunnerUp['owner_name'] ?? '') ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($lastMVP): ?>
                        <div class="winner-row mvp">
                            <i class="bi bi-star-fill winner-icon" style="color:var(--red)"></i>
                            <div>
                                <div class="winner-title">MVP</div>
                                <div class="winner-name"><?= htmlspecialchars($lastMVP['name']) ?></div>
                                <?php if (!empty($lastMVP['team_city'])): ?>
                                <div class="winner-owner"><?= htmlspecialchars($lastMVP['team_city'] . ' ' . $lastMVP['team_name']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="empty"><i class="bi bi-award"></i><p>Vencedores após o 1º sprint</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Info da Liga ── -->
                <div class="bc" style="animation-delay:.38s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-info-circle-fill"></i> Liga</div>
                        <?php if ($hasEdital): ?>
                        <a href="/api/edital.php?action=download_edital&league=<?= urlencode($team['league']) ?>"
                           class="bc-link" download><i class="bi bi-download me-1"></i>Edital</a>
                        <?php endif; ?>
                    </div>
                    <div class="bc-body">
                        <img src="/img/logo-<?= strtolower($user['league']) ?>.png"
                             alt="<?= htmlspecialchars($user['league']) ?>"
                             class="league-logo-img"
                             onerror="this.style.display='none'">
                        <div style="text-align:center;font-size:16px;font-weight:800;color:var(--red);margin-bottom:4px"><?= htmlspecialchars($user['league']) ?></div>
                        <div class="league-stat-grid">
                            <div class="league-stat">
                                <div class="league-stat-label">Ranking</div>
                                <div class="league-stat-val"><?= (int)($team['ranking_points'] ?? 0) ?></div>
                            </div>
                            <?php if ($currentSeason): ?>
                            <div class="league-stat">
                                <div class="league-stat-label">Temporada</div>
                                <div class="league-stat-val"><?= $seasonDisplayYear ?></div>
                            </div>
                            <div class="league-stat">
                                <div class="league-stat-label">Sprint</div>
                                <div class="league-stat-val"><?= (int)($currentSeason['sprint_number'] ?? 1) ?></div>
                            </div>
                            <?php endif; ?>
                            <div class="league-stat">
                                <div class="league-stat-label">CAP Faixa</div>
                                <div class="league-stat-val" style="font-size:12px"><?= $capMin ?>–<?= $capMax ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Último Rumor ── -->
                <div class="bc" style="animation-delay:.42s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-chat-left-text"></i> Último Rumor</div>
                        <a href="/trades.php" class="bc-link">Ver rumores <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="bc-body">
                        <?php if ($latestRumor): ?>
                        <div class="rumor-meta">
                            <img class="rumor-avatar"
                                 src="<?= htmlspecialchars($latestRumor['photo_url'] ?? '/img/default-team.png') ?>"
                                 alt="" onerror="this.src='/img/default-team.png'">
                            <div>
                                <div class="rumor-team"><?= htmlspecialchars(($latestRumor['city'] ?? '') . ' ' . ($latestRumor['name'] ?? '')) ?></div>
                                <div class="rumor-gm"><?= !empty($latestRumor['gm_name']) ? 'GM: ' . htmlspecialchars($latestRumor['gm_name']) : 'GM não informado' ?></div>
                            </div>
                        </div>
                        <div class="rumor-bubble"><?= nl2br(htmlspecialchars($latestRumor['content'])) ?></div>
                        <?php if (!empty($latestRumor['created_at'])): ?>
                        <div class="rumor-date"><i class="bi bi-clock"></i> <?= date('d/m/Y H:i', strtotime($latestRumor['created_at'])) ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="empty"><i class="bi bi-chat-left"></i><p>Nenhum rumor ainda</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Última Trade ── -->
                <div class="bc span-2" style="animation-delay:.46s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-arrow-left-right"></i> Última Trade</div>
                        <a href="/trades.php" class="bc-link">Ver todas <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="bc-body">
                        <?php if ($tradesEnabled == 0): ?>
                        <div class="empty" style="color:#ef4444">
                            <i class="bi bi-x-circle-fill"></i>
                            <p>Trades desativadas pelo administrador</p>
                        </div>
                        <?php elseif ($lastTrade): ?>
                        <div class="trade-teams">
                            <div class="trade-team">
                                <img src="<?= htmlspecialchars($lastTrade['from_photo'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
                                <div class="trade-team-name"><?= htmlspecialchars($lastTrade['from_city'] . ' ' . $lastTrade['from_name']) ?></div>
                            </div>
                            <i class="bi bi-arrow-left-right trade-arrow"></i>
                            <div class="trade-team">
                                <img src="<?= htmlspecialchars($lastTrade['to_photo'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
                                <div class="trade-team-name"><?= htmlspecialchars($lastTrade['to_city'] . ' ' . $lastTrade['to_name']) ?></div>
                            </div>
                        </div>
                        <div class="trade-cols">
                            <div class="trade-col">
                                <div class="trade-col-label">Enviou</div>
                                <?php foreach ($lastTradeFromPlayers as $p): ?>
                                <div class="trade-item"><i class="bi bi-person-fill"></i><div><strong><?= htmlspecialchars($p['name']) ?></strong> <span>(<?= $p['position'] ?> · <?= $p['ovr'] ?>)</span></div></div>
                                <?php endforeach; ?>
                                <?php foreach ($lastTradeFromPicks as $p): ?>
                                <div class="trade-item"><i class="bi bi-calendar-check"></i>Pick <?= $p['season_year'] ?> R<?= $p['round'] ?></div>
                                <?php endforeach; ?>
                                <?php if (!$lastTradeFromPlayers && !$lastTradeFromPicks): ?><div class="trade-item" style="color:var(--text-3)">—</div><?php endif; ?>
                            </div>
                            <div class="trade-col">
                                <div class="trade-col-label">Recebeu</div>
                                <?php foreach ($lastTradeToPlayers as $p): ?>
                                <div class="trade-item"><i class="bi bi-person-fill"></i><div><strong><?= htmlspecialchars($p['name']) ?></strong> <span>(<?= $p['position'] ?> · <?= $p['ovr'] ?>)</span></div></div>
                                <?php endforeach; ?>
                                <?php foreach ($lastTradeToPicks as $p): ?>
                                <div class="trade-item"><i class="bi bi-calendar-check"></i>Pick <?= $p['season_year'] ?> R<?= $p['round'] ?></div>
                                <?php endforeach; ?>
                                <?php if (!$lastTradeToPlayers && !$lastTradeToPicks): ?><div class="trade-item" style="color:var(--text-3)">—</div><?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($lastTrade['updated_at'])): ?>
                        <div class="trade-date">
                            <i class="bi bi-clock" style="margin-right:4px"></i>
                            <?php
                                $d = new DateTime($lastTrade['updated_at']);
                                $diff = (new DateTime())->diff($d);
                                if ($diff->days == 0) echo 'Hoje';
                                elseif ($diff->days == 1) echo 'Ontem';
                                elseif ($diff->days < 7) echo $diff->days . ' dias atrás';
                                else echo $d->format('d/m/Y');
                            ?>
                        </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="empty"><i class="bi bi-arrow-left-right"></i><p>Nenhuma trade realizada ainda</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Ações Rápidas ── -->
                <div class="bc" style="animation-delay:.5s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-lightning-fill"></i> Ações Rápidas</div>
                    </div>
                    <div class="bc-body">
                        <div class="quick-grid">
                            <a href="/diretrizes.php?mode=profile" class="quick-btn">
                                <i class="bi bi-clipboard-data"></i>
                                <div class="quick-btn-label">Diretrizes<?= $teamDirectiveProfile ? ' ✓' : '' ?></div>
                            </a>
                            <a href="/ouvidoria.php" class="quick-btn">
                                <i class="bi bi-chat-left-dots"></i>
                                <div class="quick-btn-label">Ouvidoria</div>
                            </a>
                            <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener" class="quick-btn">
                                <i class="bi bi-controller"></i>
                                <div class="quick-btn-label">FBA Games</div>
                            </a>
                            <a href="/my-roster.php" class="quick-btn">
                                <i class="bi bi-person-lines-fill"></i>
                                <div class="quick-btn-label">Elenco</div>
                            </a>
                            <a href="/trades.php" class="quick-btn">
                                <i class="bi bi-arrow-left-right"></i>
                                <div class="quick-btn-label">Trades</div>
                            </a>
                            <a href="/free-agency.php" class="quick-btn">
                                <i class="bi bi-coin"></i>
                                <div class="quick-btn-label">Free Agency</div>
                            </a>
                        </div>
                    </div>
                </div>

            </div><!-- /bento -->
        </div><!-- /content -->

        <!-- Footer strip -->
        <div class="footer-strip">
            <div class="footer-item"><strong>Time:</strong> <?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></div>
            <div class="footer-item"><strong>Liga:</strong> <?= htmlspecialchars($user['league']) ?></div>
            <?php if ($currentSeason): ?>
            <div class="footer-item"><strong>Temporada:</strong> <?= $seasonDisplayYear ?></div>
            <?php endif; ?>
            <div class="footer-item"><strong>CAP:</strong> <?= $teamCap ?> (<?= $capMin ?>–<?= $capMax ?>)</div>
            <div class="footer-item"><strong>Trades:</strong> <?= $tradesCount ?>/<?= $maxTrades ?></div>
        </div>

    </main>
</div>

<!-- Modal admin draft -->
<?php if (($user['user_type'] ?? '') === 'admin'): ?>
<div class="modal fade" id="adminInitDraftModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="background:var(--panel);border:1px solid var(--border-md);border-radius:var(--radius)">
            <div class="modal-header" style="border-color:var(--border)">
                <h5 class="modal-title" style="font-family:var(--font);font-weight:700">
                    <i class="bi bi-hand-index-thumb me-2" style="color:var(--red)"></i>Escolher jogador (Admin)
                </h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-dark table-sm align-middle mb-0">
                        <thead><tr><th>Jogador</th><th>Pos</th><th>OVR</th><th></th></tr></thead>
                        <tbody id="adminInitDraftPlayers"><tr><td colspan="4" class="text-center" style="color:var(--text-2)">Carregando...</td></tr></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="border-color:var(--border)">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/pwa.js"></script>
<script>
    /* ── Sidebar mobile ──────────────────────────── */
    const sidebar  = document.getElementById('sidebar');
    const sbOverlay = document.getElementById('sbOverlay');
    const menuBtn  = document.getElementById('menuBtn');
    menuBtn?.addEventListener('click', () => { sidebar.classList.toggle('open'); sbOverlay.classList.toggle('show'); });
    sbOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); });

    /* ── Stagger animation delays ────────────────── */
    document.querySelectorAll('.stat-c').forEach((el, i) => el.style.animationDelay = (i * 0.05 + 0.05) + 's');
    document.querySelectorAll('.bc').forEach((el, i) => el.style.animationDelay = (i * 0.04 + 0.25) + 's');

    /* ── Copy team ───────────────────────────────── */
    const rosterData = <?= json_encode($allPlayers) ?>;
    const picksData  = <?= json_encode($teamPicksForCopy) ?>;
    const teamMeta   = {
        name: <?= json_encode($team['city'] . ' ' . $team['name']) ?>,
        userName: <?= json_encode($user['name']) ?>,
        cap: <?= (int)$teamCap ?>,
        capMin: <?= (int)$capMin ?>,
        capMax: <?= (int)$capMax ?>,
        trades: <?= (int)$tradesCount ?>,
        maxTrades: <?= (int)$maxTrades ?>
    };

    function buildTeamSummary() {
        const positions = ['PG','SG','SF','PF','C'];
        const startersMap = {};
        positions.forEach(p => startersMap[p] = null);
        const fmt = age => (Number.isFinite(age) && age > 0) ? `${age}y` : '-';
        const fmtLine = (label, p) => p ? `${label}: ${p.name} - ${p.ovr ?? '-'} | ${fmt(p.age)}` : `${label}: -`;

        rosterData.filter(p => p.role === 'Titular').forEach(p => { if (positions.includes(p.position) && !startersMap[p.position]) startersMap[p.position] = p; });
        const bench   = rosterData.filter(p => p.role === 'Banco');
        const others  = rosterData.filter(p => p.role === 'Outro');
        const gleague = rosterData.filter(p => (p.role||'').toLowerCase() === 'g-league');
        const r1 = picksData.filter(pk => pk.round == 1).map(pk => `-${pk.season_year}${pk.original_team_id != pk.team_id ? ` (via ${pk.city} ${pk.team_name})` : ''} `);
        const r2 = picksData.filter(pk => pk.round == 2).map(pk => `-${pk.season_year}${pk.original_team_id != pk.team_id ? ` (via ${pk.city} ${pk.team_name})` : ''} `);

        return [
            `*${teamMeta.name}*`, teamMeta.userName, '',
            '_Starters_', ...positions.map(p => fmtLine(p, startersMap[p])), '',
            '_Bench_', ...(bench.length ? bench.map(p => `${p.position}: ${p.name} - ${p.ovr??'-'} | ${fmt(p.age)}`) : ['-']), '',
            '_Others_', ...(others.length ? others.map(p => `${p.position}: ${p.name} - ${p.ovr??'-'} | ${fmt(p.age)}`) : ['-']), '',
            '_G-League_', ...(gleague.length ? gleague.map(p => `${p.position}: ${p.name} - ${p.ovr??'-'} | ${fmt(p.age)}`) : ['-']), '',
            '_Picks 1º round_:', ...(r1.length ? r1 : ['-']), '',
            '_Picks 2º round_:', ...(r2.length ? r2 : ['-']), '',
            `_CAP_: ${teamMeta.capMin} / *${teamMeta.cap}* / ${teamMeta.capMax}`,
            `_Trades_: ${teamMeta.trades} / ${teamMeta.maxTrades}`
        ].join('\n');
    }

    document.getElementById('copyTeamBtn')?.addEventListener('click', async () => {
        const text = buildTeamSummary();
        try { await navigator.clipboard.writeText(text); alert('Time copiado!'); }
        catch { const t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t); alert('Time copiado!'); }
    });

    /* ── Admin draft ─────────────────────────────── */
    const INIT_DRAFT_SESSION_ID = <?= $activeInitDraftSession ? (int)$activeInitDraftSession['id'] : 'null' ?>;
    const IS_ADMIN_USER = <?= (($user['user_type'] ?? '') === 'admin') ? 'true' : 'false' ?>;
    const esc = v => String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');

    async function openAdminInitDraftModal() {
        if (!IS_ADMIN_USER || !INIT_DRAFT_SESSION_ID) return;
        await loadAdminInitDraftPlayers();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('adminInitDraftModal')).show();
    }

    async function loadAdminInitDraftPlayers() {
        const tbody = document.getElementById('adminInitDraftPlayers');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="4" class="text-center" style="color:var(--text-2)">Carregando...</td></tr>';
        try {
            const data = await fetch(`/api/initdraft.php?action=available_players&session_id=${INIT_DRAFT_SESSION_ID}`).then(r => r.json());
            if (!data.success) throw new Error(data.error || 'Falha');
            const players = data.players || [];
            tbody.innerHTML = players.length ? players.map(p => `<tr><td>${esc(p.name)}</td><td><span style="background:var(--red-soft);color:var(--red);padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700">${esc(p.position)}</span></td><td>${esc(p.ovr)}</td><td class="text-end"><button class="btn btn-sm btn-success" onclick="adminMakeInitDraftPick(${p.id},this)"><i class="bi bi-check2"></i></button></td></tr>`).join('') : '<tr><td colspan="4" style="text-align:center;color:var(--text-2)">Nenhum disponível</td></tr>';
        } catch(e) { tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;color:#ef4444">${e.message}</td></tr>`; }
    }

    async function adminMakeInitDraftPick(playerId, btn) {
        if (!IS_ADMIN_USER || !INIT_DRAFT_SESSION_ID || !confirm('Confirmar?')) return;
        btn?.classList.add('disabled');
        try {
            const data = await fetch('/api/initdraft.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'admin_make_pick',session_id:INIT_DRAFT_SESSION_ID,player_id:playerId}) }).then(r => r.json());
            if (!data.success) throw new Error(data.error || 'Falha');
            alert('Pick registrada!'); location.reload();
        } catch(e) { alert(e.message); btn?.classList.remove('disabled'); }
    }
</script>
</body>
</html>