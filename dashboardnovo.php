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
if ($team && !empty($team['directive_profile'])) {
	$decodedProfile = json_decode($team['directive_profile'], true);
	if (is_array($decodedProfile)) {
		$teamDirectiveProfile = $decodedProfile;
	}
}

if (!$team) {
	header('Location: /onboarding.php');
	exit;
}

$stmtPlayers = $pdo->prepare('SELECT COUNT(*) as total, SUM(ovr) as total_ovr FROM players WHERE team_id = ?');
$stmtPlayers->execute([$team['id']]);
$stats = $stmtPlayers->fetch();

$totalPlayers = (int)($stats['total'] ?? 0);
$avgOvr = $totalPlayers > 0 ? round(($stats['total_ovr'] ?? 0) / $totalPlayers, 1) : 0;
$minPlayers = 13;
$maxPlayers = 15;
$playersOutOfRange = $totalPlayers < $minPlayers || $totalPlayers > $maxPlayers;
$playersColor = $playersOutOfRange ? '#ff4444' : 'inherit';

$stmtTitulares = $pdo->prepare("SELECT * FROM players WHERE team_id = ? AND role = 'Titular' ORDER BY ovr DESC");
$stmtTitulares->execute([$team['id']]);
$titulares = $stmtTitulares->fetchAll();

$stmtCap = $pdo->prepare('
	SELECT SUM(ovr) as cap FROM (
		SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8
	) as top_eight
');
$stmtCap->execute([$team['id']]);
$capData = $stmtCap->fetch();
$teamCap = (int)($capData['cap'] ?? 0);

$capMin = 0;
$capMax = 999;
try {
	$stmtCapLimits = $pdo->prepare('SELECT cap_min, cap_max FROM league_settings WHERE league = ?');
	$stmtCapLimits->execute([$team['league']]);
	$capLimits = $stmtCapLimits->fetch();
	if ($capLimits) {
		$capMin = (int)($capLimits['cap_min'] ?? 0);
		$capMax = (int)($capLimits['cap_max'] ?? 999);
	}
} catch (Exception $e) {
}

$capColor = '#00ff00';
if ($teamCap < $capMin || $teamCap > $capMax) {
	$capColor = '#ff4444';
}

$averageCapTop8 = 0;
$leagueAvgOvr = 0;
try {
	$stmtLeagueTeams = $pdo->prepare('SELECT id FROM teams WHERE league = ?');
	$stmtLeagueTeams->execute([$team['league']]);
	$leagueTeams = $stmtLeagueTeams->fetchAll(PDO::FETCH_COLUMN);

	$leagueCapTotal = 0;
	$leagueTeamsCount = 0;
	foreach ($leagueTeams as $leagueTeamId) {
		$stmtCapTeam = $pdo->prepare('SELECT SUM(ovr) as cap FROM (SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8) as top8');
		$stmtCapTeam->execute([(int)$leagueTeamId]);
		$capRow = $stmtCapTeam->fetch(PDO::FETCH_ASSOC);
		$leagueCapTotal += (int)($capRow['cap'] ?? 0);
		$leagueTeamsCount++;
	}
	$averageCapTop8 = $leagueTeamsCount > 0 ? round($leagueCapTotal / $leagueTeamsCount, 1) : 0;

	$stmtLeagueOvr = $pdo->prepare('SELECT AVG(p.ovr) as avg_ovr FROM players p INNER JOIN teams t ON t.id = p.team_id WHERE t.league = ?');
	$stmtLeagueOvr->execute([$team['league']]);
	$leagueOvrRow = $stmtLeagueOvr->fetch(PDO::FETCH_ASSOC) ?: [];
	$leagueAvgOvr = (float)($leagueOvrRow['avg_ovr'] ?? 0);
} catch (Exception $e) {
	$averageCapTop8 = 0;
	$leagueAvgOvr = 0;
}

$editalData = null;
$hasEdital = false;
try {
	$stmtEdital = $pdo->prepare('SELECT edital, edital_file FROM league_settings WHERE league = ?');
	$stmtEdital->execute([$team['league']]);
	$editalData = $stmtEdital->fetch();
	$hasEdital = $editalData && !empty($editalData['edital_file']);
} catch (Exception $e) {
}

$activeDirectiveDeadline = null;
$hasActiveDirectiveSubmission = false;
try {
	$nowBrasilia = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
	$stmtDirective = $pdo->prepare("
		SELECT * FROM directive_deadlines 
		WHERE league = ? AND is_active = 1 AND deadline_date > ?
		ORDER BY deadline_date ASC LIMIT 1
	");
	$stmtDirective->execute([$team['league'], $nowBrasilia]);
	$activeDirectiveDeadline = $stmtDirective->fetch();
	if ($activeDirectiveDeadline && !empty($activeDirectiveDeadline['deadline_date'])) {
		try {
			$deadlineDateTime = new DateTime($activeDirectiveDeadline['deadline_date'], new DateTimeZone('America/Sao_Paulo'));
			$activeDirectiveDeadline['deadline_date_display'] = $deadlineDateTime->format('d/m/Y H:i');
		} catch (Exception $e) {
			$activeDirectiveDeadline['deadline_date_display'] = date('d/m/Y', strtotime($activeDirectiveDeadline['deadline_date']));
		}
	}
	if ($activeDirectiveDeadline && !empty($team['id'])) {
		$stmtHasDirective = $pdo->prepare("SELECT id FROM team_directives WHERE team_id = ? AND deadline_id = ? LIMIT 1");
		$stmtHasDirective->execute([(int)$team['id'], (int)$activeDirectiveDeadline['id']]);
		$hasActiveDirectiveSubmission = (bool)$stmtHasDirective->fetchColumn();
	}
} catch (Exception $e) {
}

$currentSeason = null;
try {
	$stmtSeason = $pdo->prepare("
		SELECT s.season_number, s.year, s.status, sp.sprint_number, sp.start_year
		FROM seasons s
		INNER JOIN sprints sp ON s.sprint_id = sp.id
		WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed'))
		ORDER BY s.created_at DESC 
		LIMIT 1
	");
	$stmtSeason->execute([$team['league']]);
	$currentSeason = $stmtSeason->fetch();
} catch (Exception $e) {
	$currentSeason = null;
}

$seasonDisplayYear = null;
if ($currentSeason && isset($currentSeason['start_year']) && isset($currentSeason['season_number'])) {
	$seasonDisplayYear = (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1;
} elseif ($currentSeason && isset($currentSeason['year'])) {
	$seasonDisplayYear = (int)$currentSeason['year'];
}

$stmtAllPlayers = $pdo->prepare("SELECT id, name, position, role, ovr, age FROM players WHERE team_id = ? ORDER BY ovr DESC, name ASC");
$stmtAllPlayers->execute([$team['id']]);
$allPlayers = $stmtAllPlayers->fetchAll(PDO::FETCH_ASSOC);

$stmtPicks = $pdo->prepare("
	SELECT p.season_year, p.round, 
		   orig.city, orig.name AS team_name,
		   p.original_team_id, p.team_id
	FROM picks p
	JOIN teams orig ON p.original_team_id = orig.id
	WHERE p.team_id = ?
	ORDER BY p.season_year ASC, p.round ASC
");
$stmtPicks->execute([$team['id']]);
$teamPicks = $stmtPicks->fetchAll(PDO::FETCH_ASSOC);
$teamPicksForCopy = $teamPicks;
$copySeasonYear = !empty($seasonDisplayYear) ? (int)$seasonDisplayYear : (int)date('Y');
$teamPicksForCopy = array_values(array_filter($teamPicks, function ($pick) use ($copySeasonYear) {
	return (int)($pick['season_year'] ?? 0) > $copySeasonYear;
}));

function syncTeamTradeCounterDashboard(PDO $pdo, int $teamId): int
{
	try {
		$stmt = $pdo->prepare('SELECT current_cycle, trades_cycle, trades_used FROM teams WHERE id = ?');
		$stmt->execute([$teamId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			return 0;
		}

		$currentCycle = (int)($row['current_cycle'] ?? 0);
		$tradesCycle = (int)($row['trades_cycle'] ?? 0);
		$tradesUsed = (int)($row['trades_used'] ?? 0);

		if ($currentCycle > 0 && $tradesCycle !== $currentCycle) {
			$pdo->prepare('UPDATE teams SET trades_used = 0, trades_cycle = ? WHERE id = ?')
				->execute([$currentCycle, $teamId]);
			return 0;
		}

		if ($currentCycle > 0 && $tradesCycle <= 0) {
			$pdo->prepare('UPDATE teams SET trades_cycle = ? WHERE id = ?')
				->execute([$currentCycle, $teamId]);
		}

		return $tradesUsed;
	} catch (Exception $e) {
		return 0;
	}
}

$tradesCount = syncTeamTradeCounterDashboard($pdo, (int)$team['id']);

$lastTrade = null;
$lastTradeFromPlayers = [];
$lastTradeToPlayers = [];
$lastTradeFromPicks = [];
$lastTradeToPicks = [];
try {
	$stmtLastTrade = $pdo->prepare("
		SELECT 
			t.*,
			t1.city as from_city, t1.name as from_name, t1.photo_url as from_photo,
			t2.city as to_city, t2.name as to_name, t2.photo_url as to_photo,
			u1.name as from_owner, u2.name as to_owner
		FROM trades t
		JOIN teams t1 ON t.from_team_id = t1.id
		JOIN teams t2 ON t.to_team_id = t2.id
		LEFT JOIN users u1 ON t1.user_id = u1.id
		LEFT JOIN users u2 ON t2.user_id = u2.id
		WHERE t.status = 'accepted' AND t1.league = ?
		ORDER BY t.updated_at DESC
		LIMIT 1
	");
	$stmtLastTrade->execute([$team['league']]);
	$lastTrade = $stmtLastTrade->fetch();

	if ($lastTrade) {
		$stmtFromPlayers = $pdo->prepare('
			SELECT p.name, p.position, p.ovr 
			FROM players p
			JOIN trade_items ti ON p.id = ti.player_id
			WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.player_id IS NOT NULL
		');
		$stmtFromPlayers->execute([$lastTrade['id']]);
		$lastTradeFromPlayers = $stmtFromPlayers->fetchAll();

		$stmtFromPicks = $pdo->prepare('
			SELECT pk.season_year, pk.round 
			FROM picks pk
			JOIN trade_items ti ON pk.id = ti.pick_id
			WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.pick_id IS NOT NULL
		');
		$stmtFromPicks->execute([$lastTrade['id']]);
		$lastTradeFromPicks = $stmtFromPicks->fetchAll();

		$stmtToPlayers = $pdo->prepare('
			SELECT p.name, p.position, p.ovr 
			FROM players p
			JOIN trade_items ti ON p.id = ti.player_id
			WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.player_id IS NOT NULL
		');
		$stmtToPlayers->execute([$lastTrade['id']]);
		$lastTradeToPlayers = $stmtToPlayers->fetchAll();

		$stmtToPicks = $pdo->prepare('
			SELECT pk.season_year, pk.round 
			FROM picks pk
			JOIN trade_items ti ON pk.id = ti.pick_id
			WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.pick_id IS NOT NULL
		');
		$stmtToPicks->execute([$lastTrade['id']]);
		$lastTradeToPicks = $stmtToPicks->fetchAll();
	}
} catch (Exception $e) {
}

$maxTrades = 3;
$tradesEnabled = 1;
try {
	$stmtMaxTrades = $pdo->prepare('SELECT max_trades, trades_enabled FROM league_settings WHERE league = ?');
	$stmtMaxTrades->execute([$team['league']]);
	$rowMax = $stmtMaxTrades->fetch();
	if ($rowMax) {
		if (isset($rowMax['max_trades'])) {
			$maxTrades = (int)$rowMax['max_trades'];
		}
		if (isset($rowMax['trades_enabled'])) {
			$tradesEnabled = (int)$rowMax['trades_enabled'];
		}
	}
} catch (Exception $e) {
}

$activeInitDraftSession = null;
$currentDraftPick = null;
$nextDraftPick = null;
$remainingDraftPicks = 0;
$initDraftTeamsPerRound = 0;
try {
	$stmtInitSession = $pdo->prepare("SELECT * FROM initdraft_sessions WHERE league = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
	$stmtInitSession->execute([$team['league']]);
	$activeInitDraftSession = $stmtInitSession->fetch(PDO::FETCH_ASSOC);

	if ($activeInitDraftSession) {
		$sessionId = (int)$activeInitDraftSession['id'];

		$stmtCurrentPick = $pdo->prepare("
			SELECT io.*, t.city, t.name AS team_name, t.photo_url, u.name AS owner_name
			FROM initdraft_order io
			JOIN teams t ON io.team_id = t.id
			LEFT JOIN users u ON t.user_id = u.id
			WHERE io.initdraft_session_id = ?
			  AND io.picked_player_id IS NULL
			ORDER BY io.round ASC, io.pick_position ASC
			LIMIT 1
		");
		$stmtCurrentPick->execute([$sessionId]);
		$currentDraftPick = $stmtCurrentPick->fetch(PDO::FETCH_ASSOC);

		if ($currentDraftPick) {
			$stmtNextPick = $pdo->prepare("
				SELECT io.*, t.city, t.name AS team_name, t.photo_url
				FROM initdraft_order io
				JOIN teams t ON io.team_id = t.id
				WHERE io.initdraft_session_id = ?
				  AND io.picked_player_id IS NULL
				ORDER BY io.round ASC, io.pick_position ASC
				LIMIT 1 OFFSET 1
			");
			$stmtNextPick->execute([$sessionId]);
			$nextDraftPick = $stmtNextPick->fetch(PDO::FETCH_ASSOC);

			$stmtRemaining = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND picked_player_id IS NULL');
			$stmtRemaining->execute([$sessionId]);
			$remainingDraftPicks = (int)$stmtRemaining->fetchColumn();

			$stmtTeamsPerRound = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = 1');
			$stmtTeamsPerRound->execute([$sessionId]);
			$initDraftTeamsPerRound = (int)$stmtTeamsPerRound->fetchColumn();
		}
	}
} catch (Exception $e) {
}

$activeDraft = $activeInitDraftSession && $currentDraftPick;
$currentDraftOverallNumber = null;
$nextDraftOverallNumber = null;
if ($currentDraftPick && $initDraftTeamsPerRound > 0) {
	$currentDraftOverallNumber = (($currentDraftPick['round'] - 1) * $initDraftTeamsPerRound) + $currentDraftPick['pick_position'];
}
if ($nextDraftPick && $initDraftTeamsPerRound > 0) {
	$nextDraftOverallNumber = (($nextDraftPick['round'] - 1) * $initDraftTeamsPerRound) + $nextDraftPick['pick_position'];
}

$capDelta = $teamCap - $averageCapTop8;
$ovrDelta = $avgOvr - $leagueAvgOvr;
$capDeltaClass = $capDelta > 0 ? 'up' : ($capDelta < 0 ? 'down' : 'neutral');
$ovrDeltaClass = $ovrDelta > 0 ? 'up' : ($ovrDelta < 0 ? 'down' : 'neutral');
$playerStatus = $playersOutOfRange ? 'Fora da faixa' : 'Dentro da faixa';

$topRanking = [];
try {
	$stmtTopRanking = $pdo->prepare("
		SELECT t.id, t.city, t.name, t.photo_url, t.ranking_points, u.name as owner_name
		FROM teams t
		LEFT JOIN users u ON t.user_id = u.id
		WHERE t.league = ?
		ORDER BY t.ranking_points DESC
		LIMIT 5
	");
	$stmtTopRanking->execute([$team['league']]);
	$topRanking = $stmtTopRanking->fetchAll();
} catch (Exception $e) {
}

$latestRumor = null;
try {
	$stmtLatestRumor = $pdo->prepare('
		SELECT r.content, r.created_at, t.city, t.name, t.photo_url, u.name as gm_name
		FROM rumors r
		INNER JOIN teams t ON r.team_id = t.id
		INNER JOIN users u ON r.user_id = u.id
		WHERE r.league = ?
		ORDER BY r.created_at DESC
		LIMIT 1
	');
	$stmtLatestRumor->execute([$team['league']]);
	$latestRumor = $stmtLatestRumor->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

$lastChampion = null;
$lastRunnerUp = null;
$lastMVP = null;
$lastSprintInfo = null;
try {
	$stmtLastSprint = $pdo->prepare("
		SELECT sh.*, 
			   t1.id as champion_id, t1.city as champion_city, t1.name as champion_name, 
			   t1.photo_url as champion_photo, u1.name as champion_owner,
			   t2.id as runner_up_id, t2.city as runner_up_city, t2.name as runner_up_name,
			   t2.photo_url as runner_up_photo, u2.name as runner_up_owner
		FROM season_history sh
		LEFT JOIN teams t1 ON sh.champion_team_id = t1.id
		LEFT JOIN users u1 ON t1.user_id = u1.id
		LEFT JOIN teams t2 ON sh.runner_up_team_id = t2.id
		LEFT JOIN users u2 ON t2.user_id = u2.id
		WHERE sh.league = ?
		ORDER BY sh.id DESC
		LIMIT 1
	");
	$stmtLastSprint->execute([$team['league']]);
	$lastSprintInfo = $stmtLastSprint->fetch();

	if ($lastSprintInfo) {
		if ($lastSprintInfo['champion_id']) {
			$lastChampion = [
				'id' => $lastSprintInfo['champion_id'],
				'city' => $lastSprintInfo['champion_city'],
				'name' => $lastSprintInfo['champion_name'],
				'photo_url' => $lastSprintInfo['champion_photo'],
				'owner_name' => $lastSprintInfo['champion_owner']
			];
		}

		if ($lastSprintInfo['runner_up_id']) {
			$lastRunnerUp = [
				'id' => $lastSprintInfo['runner_up_id'],
				'city' => $lastSprintInfo['runner_up_city'],
				'name' => $lastSprintInfo['runner_up_name'],
				'photo_url' => $lastSprintInfo['runner_up_photo'],
				'owner_name' => $lastSprintInfo['runner_up_owner']
			];
		}

		if (!empty($lastSprintInfo['mvp_player'])) {
			$lastMVP = [
				'name' => $lastSprintInfo['mvp_player'],
				'position' => null,
				'ovr' => null,
				'team_city' => null,
				'team_name' => null
			];

			if (!empty($lastSprintInfo['mvp_team_id'])) {
				$stmtMvpTeam = $pdo->prepare("SELECT city, name FROM teams WHERE id = ?");
				$stmtMvpTeam->execute([$lastSprintInfo['mvp_team_id']]);
				$mvpTeam = $stmtMvpTeam->fetch();
				if ($mvpTeam) {
					$lastMVP['team_city'] = $mvpTeam['city'];
					$lastMVP['team_name'] = $mvpTeam['name'];
				}
			}
		}
	}
} catch (Exception $e) {
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
	<meta name="theme-color" content="#fc0025">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="apple-mobile-web-app-title" content="FBA Manager">
	<meta name="mobile-web-app-capable" content="yes">
	<link rel="manifest" href="/manifest.json?v=3">
	<link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
	<title>Dashboard - FBA Manager</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/css/styles.css?v=20260225-2" />

	<style>
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

		.app-shell { display: flex; min-height: 100vh; }

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
			color: #fff; font-family: var(--font-display); font-weight: 700;
			letter-spacing: .5px;
		}

		.sidebar-brand-text { font-weight: 700; font-family: var(--font-display); line-height: 1.1; }
		.sidebar-brand-text span { display: block; font-size: 12px; color: var(--text-2); font-weight: 500; }

		.sidebar-myteam {
			margin: 16px 18px 8px;
			padding: 14px;
			border-radius: var(--radius-sm);
			background: var(--panel-2);
			display: flex;
			align-items: center;
			gap: 12px;
			border: 1px solid var(--border);
		}

		.sidebar-myteam img {
			width: 44px; height: 44px; border-radius: 12px; object-fit: cover; border: 1px solid var(--border);
		}

		.sidebar-myteam-name { font-weight: 600; font-size: 14px; }
		.sidebar-myteam-sub { font-size: 12px; color: var(--text-2); }

		.sidebar-nav {
			overflow: auto;
			padding: 8px 10px 0;
			flex: 1;
			-ms-overflow-style: none;
			scrollbar-width: none;
		}
		.sidebar-nav::-webkit-scrollbar { display: none; }
		.sidebar-nav-label {
			font-size: 11px; letter-spacing: .16em; text-transform: uppercase;
			color: var(--text-3); padding: 10px 12px 6px;
		}
		.sidebar-nav a {
			display: flex; align-items: center; gap: 10px;
			padding: 10px 12px; margin: 2px 4px;
			border-radius: 10px; color: var(--text-2); text-decoration: none;
			transition: background var(--t) var(--ease), color var(--t) var(--ease);
			font-size: 14px; font-weight: 500;
		}
		.sidebar-nav a i { font-size: 16px; }
		.sidebar-nav a:hover { background: var(--panel-2); color: var(--text); }
		.sidebar-nav a.active { background: var(--panel-2); color: var(--text); border: 1px solid var(--border); }
		.sidebar-nav a.active i { color: var(--red); }

		.sidebar-footer {
			margin: 8px 16px 10px;
			padding: 10px 12px;
			background: var(--panel-2);
			border-radius: var(--radius-sm);
			display: flex; align-items: center; gap: 10px;
			border: 1px solid var(--border);
		}
		.sidebar-user-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border); }
		.sidebar-user-name { flex: 1; font-weight: 600; font-size: 14px; }
		.sidebar-logout { color: var(--text-2); text-decoration: none; }

		.sidebar-theme-toggle {
			margin: 0 16px 10px;
			padding: 10px 12px;
			border-radius: var(--radius-sm);
			border: 1px solid var(--border);
			background: var(--panel-2);
			color: var(--text);
			display: flex; align-items: center; gap: 8px;
			font-weight: 600; font-size: 13px;
		}

		.topbar {
			position: fixed; left: var(--sidebar-w); right: 0; top: 0;
			height: calc(64px + env(safe-area-inset-top)); z-index: 120;
			background: rgba(8,8,10,.9);
			backdrop-filter: blur(14px);
			border-bottom: 1px solid var(--border);
			display: none; align-items: center; padding: env(safe-area-inset-top) 20px 0; gap: 12px;
		}
		:root[data-theme="light"] .topbar { background: rgba(246,247,251,.92); }
		.topbar-menu-btn {
			width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--border);
			background: var(--panel-2); color: var(--text); display: inline-flex; align-items: center; justify-content: center;
		}
		.topbar-brand { font-family: var(--font-display); font-weight: 700; letter-spacing: .3px; }
		.topbar-brand em { color: var(--red); font-style: normal; }

		.main {
			margin-left: var(--sidebar-w);
			width: calc(100% - var(--sidebar-w));
			padding: calc(32px + env(safe-area-inset-top)) 40px calc(60px + env(safe-area-inset-bottom));
		}

		.page-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 22px; }
		.page-title { font-size: 32px; font-family: var(--font-display); margin-bottom: 6px; }
		.page-sub { color: var(--text-2); font-size: 14px; }
		.page-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: flex-end; }

		.badge-soft {
			border-radius: 999px;
			padding: 8px 12px;
			background: var(--panel-2);
			border: 1px solid var(--border);
			font-weight: 700; font-size: 13px;
			color: var(--text);
		}

		.btn-ghost {
			border: 1px solid var(--border);
			background: var(--panel-2);
			color: var(--text);
			border-radius: 10px;
			padding: 8px 12px;
			font-size: 13px;
			font-weight: 600;
		}

		.kpi-grid {
			display: grid; grid-template-columns: repeat(6, minmax(0,1fr)); gap: 12px; margin-bottom: 20px;
		}
		.kpi-card {
			background: var(--panel);
			border: 1px solid var(--border);
			border-radius: 12px;
			padding: 14px 16px;
			display: flex; flex-direction: column; gap: 6px;
			min-height: 96px;
		}
		.kpi-label {
			color: var(--text-2);
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: .14em;
		}
		.kpi-value {
			font-family: var(--font-display);
			font-weight: 700;
			font-size: 22px;
		}
		.kpi-meta { color: var(--text-3); font-size: 12px; }

		.compare-grid {
			display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 12px; margin-bottom: 20px;
		}
		.compare-item {
			background: var(--panel-2);
			border: 1px solid var(--border);
			border-radius: 12px;
			padding: 14px 16px;
		}
		.compare-title { font-size: 12px; color: var(--text-2); margin-bottom: 8px; }
		.compare-value { font-weight: 700; font-size: 16px; }
		.delta { font-size: 12px; font-weight: 600; }
		.delta.up { color: #25c677; }
		.delta.down { color: #ff6b6b; }
		.delta.neutral { color: var(--text-2); }

		.panel {
			background: var(--panel);
			border: 1px solid var(--border);
			border-radius: var(--radius);
			padding: 18px 20px 20px;
		}
		.panel + .panel { margin-top: 20px; }
		.panel-title { font-family: var(--font-display); font-size: 18px; margin-bottom: 14px; }

		.action-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 14px; }
		.action-card {
			background: var(--panel-2);
			border: 1px solid var(--border);
			border-radius: var(--radius-sm);
			padding: 18px;
			text-align: center;
			transition: transform var(--t) var(--ease), box-shadow var(--t) var(--ease);
		}
		.action-card:hover { transform: translateY(-3px); box-shadow: 0 12px 20px rgba(0,0,0,.2); }
		.action-card i { font-size: 32px; color: var(--red); }
		.action-card h5 { margin-top: 12px; font-size: 16px; }
		.action-card p { color: var(--text-2); font-size: 13px; }

		.callout {
			border-radius: var(--radius);
			border: 1px solid rgba(252,0,37,.35);
			background: linear-gradient(135deg, rgba(252,0,37,.12), var(--panel));
			padding: 18px 20px;
		}

		.grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 16px; }
		.grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 16px; }

		.mini-card {
			background: var(--panel-2);
			border: 1px solid var(--border);
			border-radius: 10px;
			padding: 12px;
		}

		.trade-items { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 10px; }
		.trade-box { background: var(--panel-2); border: 1px solid var(--border); border-radius: 10px; padding: 10px; min-height: 120px; }
		.trade-box small { color: var(--text-2); }

		.ranking-item {
			display: flex; align-items: center; gap: 10px;
			padding: 10px; border-radius: 10px;
			background: var(--panel-2); border: 1px solid var(--border);
			margin-bottom: 10px;
		}

		.starter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
		.starter-card { background: var(--panel-2); border: 1px solid var(--border); border-radius: 12px; padding: 12px; text-align: center; }
		.starter-card img { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 2px solid var(--red); background: #1a1a1a; }

		.sidebar-overlay {
			position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 110; opacity: 0; pointer-events: none; transition: opacity var(--t) var(--ease);
		}
		.sidebar-overlay.active { opacity: 1; pointer-events: all; }

		@media (max-width: 1100px) {
			.kpi-grid { grid-template-columns: repeat(3, minmax(0,1fr)); }
			.compare-grid { grid-template-columns: 1fr; }
			.grid-3 { grid-template-columns: 1fr; }
		}
		@media (max-width: 900px) {
			.kpi-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
			.action-grid { grid-template-columns: 1fr; }
			.grid-2 { grid-template-columns: 1fr; }
		}
		@media (max-width: 820px) {
			.sidebar { transform: translateX(-100%); }
			.sidebar.open { transform: translateX(0); }
			.topbar { display: flex; }
			.main { margin-left: 0; width: 100%; padding: calc(100px + env(safe-area-inset-top)) 24px calc(40px + env(safe-area-inset-bottom)); }
		}
		@media (max-width: 600px) {
			.kpi-grid { grid-template-columns: 1fr; }
			.page-top { flex-direction: column; align-items: flex-start; }
			.page-actions { justify-content: flex-start; }
			.trade-items { grid-template-columns: 1fr; }
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

		<div class="sidebar-myteam">
			<img src="<?= htmlspecialchars(getTeamPhoto($team['photo_url'] ?? null)) ?>" alt="Meu Time">
			<div class="sidebar-myteam-info">
				<div class="sidebar-myteam-name"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></div>
				<div class="sidebar-myteam-sub"><?= (int)$team['player_count'] ?> jogadores</div>
			</div>
		</div>

		<nav class="sidebar-nav">
			<div class="sidebar-nav-label">Principal</div>
			<a href="/dashboard.php" class="active"><i class="bi bi-house"></i> Home</a>
			<a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
			<a href="/players.php"><i class="bi bi-person-badge"></i> Jogadores</a>
			<a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trocas</a>
			<a href="/picks.php"><i class="bi bi-calendar2-event"></i> Picks</a>

			<div class="sidebar-nav-label">Liga</div>
			<a href="/rankings.php"><i class="bi bi-trophy"></i> Classificacao</a>
			<a href="/free-agency.php"><i class="bi bi-person-plus"></i> Mercado Livre</a>
			<a href="/leilao.php"><i class="bi bi-hammer"></i> Leilao</a>
			<a href="/trades.php#rumores"><i class="bi bi-chat-dots"></i> Rumores</a>

			<div class="sidebar-nav-label">Admin</div>
			<a href="/admin.php"><i class="bi bi-gear"></i> Administracao</a>
			<a href="/punicoes.php"><i class="bi bi-exclamation-triangle"></i> Punicoes</a>
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
				<h1 class="page-title">Dashboard</h1>
				<p class="page-sub">Bem-vindo ao painel do <?= htmlspecialchars($team['name']) ?><?php if ($currentSeason): ?> - Temporada <?= (int)$seasonDisplayYear ?><?php endif; ?></p>
			</div>
			<div class="page-actions">
				<button class="btn-ghost" id="copyTeamBtn"><i class="bi bi-clipboard-check me-1"></i>Copiar time</button>
				<span class="badge-soft"><i class="bi bi-star-fill me-1" style="color: var(--red);"></i><?= (int)($team['ranking_points'] ?? 0) ?> pts</span>
				<span class="badge-soft"><i class="bi bi-coin me-1" style="color: #ffc107;"></i><?= (int)($team['moedas'] ?? 0) ?> moedas</span>
				<?php if ($currentSeason): ?>
				<span class="badge-soft"><i class="bi bi-calendar3 me-1" style="color: var(--red);"></i>Temporada <?= (int)$seasonDisplayYear ?></span>
				<?php else: ?>
				<span class="badge-soft"><i class="bi bi-calendar-x me-1"></i>Sem temporada ativa</span>
				<?php endif; ?>
			</div>
		</div>

		<div class="kpi-grid">
			<a href="/my-roster.php" class="text-decoration-none" style="color: inherit;">
				<div class="kpi-card">
					<div class="kpi-label">Jogadores</div>
					<div class="kpi-value" style="color: <?= $playersColor ?>;"><?= $totalPlayers ?></div>
					<div class="kpi-meta" style="color: <?= $playersColor ?>;">Min <?= $minPlayers ?> - Max <?= $maxPlayers ?></div>
				</div>
			</a>
			<div class="kpi-card">
				<div class="kpi-label">CAP Top8</div>
				<div class="kpi-value" style="color: <?= $capColor ?>;"><?= (int)$teamCap ?></div>
				<div class="kpi-meta" style="color: <?= $capColor ?>;">Min <?= $capMin ?> - Max <?= $capMax ?></div>
			</div>
			<a href="/trades.php" class="text-decoration-none" style="color: inherit;">
				<div class="kpi-card">
					<div class="kpi-label">Trades</div>
					<div class="kpi-value"><?= (int)$tradesCount ?>/<?= (int)$maxTrades ?></div>
					<div class="kpi-meta">Realizadas</div>
				</div>
			</a>
			<a href="/picks.php" class="text-decoration-none" style="color: inherit;">
				<div class="kpi-card">
					<div class="kpi-label">Picks</div>
					<div class="kpi-value"><?= count($teamPicks) ?></div>
					<div class="kpi-meta">Proximas escolhas</div>
				</div>
			</a>
			<div class="kpi-card">
				<div class="kpi-label">OVR medio</div>
				<div class="kpi-value"><?= number_format($avgOvr, 1, ',', '.') ?></div>
				<div class="kpi-meta">Seu elenco</div>
			</div>
			<div class="kpi-card">
				<div class="kpi-label">Ranking</div>
				<div class="kpi-value"><?= (int)($team['ranking_points'] ?? 0) ?></div>
				<div class="kpi-meta">Pontos</div>
			</div>
		</div>

		<div class="compare-grid">
			<div class="compare-item">
				<div class="compare-title">CAP vs media da liga</div>
				<div class="compare-value">Media: <?= number_format($averageCapTop8, 1, ',', '.') ?></div>
				<div class="delta <?= $capDeltaClass ?>"><?= ($capDelta >= 0 ? '+' : '') . number_format($capDelta, 1, ',', '.') ?></div>
			</div>
			<div class="compare-item">
				<div class="compare-title">OVR medio vs liga</div>
				<div class="compare-value">Media: <?= number_format($leagueAvgOvr, 1, ',', '.') ?></div>
				<div class="delta <?= $ovrDeltaClass ?>"><?= ($ovrDelta >= 0 ? '+' : '') . number_format($ovrDelta, 1, ',', '.') ?></div>
			</div>
			<div class="compare-item">
				<div class="compare-title">Elenco</div>
				<div class="compare-value">Faixa: <?= $minPlayers ?> - <?= $maxPlayers ?></div>
				<div class="delta <?= $playersOutOfRange ? 'down' : 'up' ?>"><?= $playerStatus ?></div>
			</div>
		</div>

		<?php if ($activeDirectiveDeadline): ?>
		<a href="/diretrizes.php" class="text-decoration-none" style="color: inherit;">
			<div class="callout" style="margin-bottom: 20px;">
				<div style="display:flex; align-items:center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
					<div>
						<div style="font-weight: 700; font-family: var(--font-display);">Envio de rotacoes</div>
						<div style="color: var(--text-2); font-size: 13px;"><?= htmlspecialchars($activeDirectiveDeadline['description'] ?? 'Diretrizes de jogo') ?></div>
						<div style="color: #ff4444; font-size: 13px; font-weight: 700; margin-top: 6px;">
							<i class="bi bi-clock-fill me-1"></i>Prazo: <?= htmlspecialchars($activeDirectiveDeadline['deadline_date_display'] ?? '') ?>
						</div>
					</div>
					<div class="btn-ghost">
						<?php if ($hasActiveDirectiveSubmission): ?>
							<i class="bi bi-search me-1"></i>Revisar
						<?php else: ?>
							<i class="bi bi-arrow-right-circle me-1"></i>Enviar rotacao
						<?php endif; ?>
					</div>
				</div>
			</div>
		</a>
		<?php endif; ?>

		<div class="panel">
			<div class="panel-title"><i class="bi bi-lightning-fill me-2" style="color: var(--red);"></i>Acoes rapidas</div>
			<div class="action-grid">
				<a href="/diretrizes.php?mode=profile" class="text-decoration-none" style="color: inherit;">
					<div class="action-card">
						<i class="bi bi-clipboard-data"></i>
						<h5>Diretrizes do Time</h5>
						<p><?= $teamDirectiveProfile ? 'Diretriz base salva' : 'Crie sua diretriz base' ?></p>
					</div>
				</a>
				<a href="/ouvidoria.php" class="text-decoration-none" style="color: inherit;">
					<div class="action-card">
						<i class="bi bi-chat-left-dots" style="color: #25c677;"></i>
						<h5>Ouvidoria</h5>
						<p>Envie mensagem anonima</p>
					</div>
				</a>
				<a href="https://games.fbabrasil.com.br/auth/login.php" class="text-decoration-none" target="_blank" rel="noopener" style="color: inherit;">
					<div class="action-card">
						<i class="bi bi-controller" style="color: #ffc107;"></i>
						<h5>FBA GAMES</h5>
						<p>Acesse os mini jogos</p>
					</div>
				</a>
			</div>
		</div>

		<?php if ($activeDraft && $currentDraftPick): ?>
		<div class="callout" style="margin-top: 20px;">
			<div style="display:flex; align-items:center; gap: 16px; flex-wrap: wrap;">
				<img src="<?= htmlspecialchars($currentDraftPick['photo_url'] ?? '/img/default-team.png') ?>" alt="<?= htmlspecialchars($currentDraftPick['city'] . ' ' . $currentDraftPick['team_name']) ?>" class="rounded-circle" style="width:70px;height:70px;object-fit:cover;border:2px solid var(--red);">
				<div style="flex: 1; min-width: 200px;">
					<div style="color: var(--text-2); font-size: 12px; text-transform: uppercase; letter-spacing: .12em;">Na vez agora</div>
					<div style="font-weight: 700; font-size: 18px;"><?= htmlspecialchars($currentDraftPick['city'] . ' ' . $currentDraftPick['team_name']) ?></div>
					<div style="color: var(--text-2); font-size: 13px;">
						<?php if ($currentDraftOverallNumber): ?>Pick geral #<?= (int)$currentDraftOverallNumber ?> - <?php endif; ?>Rodada <?= (int)$currentDraftPick['round'] ?>, Pick <?= (int)$currentDraftPick['pick_position'] ?>
						<?php if (!empty($currentDraftPick['owner_name'])): ?> - Manager: <?= htmlspecialchars($currentDraftPick['owner_name']) ?><?php endif; ?>
					</div>
				</div>
				<div style="flex: 1; min-width: 200px;">
					<?php if ($nextDraftPick): ?>
						<div style="color: var(--text-2); font-size: 12px; text-transform: uppercase; letter-spacing: .12em;">Proxima pick</div>
						<div style="font-weight: 700;"><?php if ($nextDraftOverallNumber): ?>Pick geral #<?= (int)$nextDraftOverallNumber ?> - <?php endif; ?>R<?= (int)$nextDraftPick['round'] ?> P<?= (int)$nextDraftPick['pick_position'] ?> - <?= htmlspecialchars($nextDraftPick['city'] . ' ' . $nextDraftPick['team_name']) ?></div>
					<?php else: ?>
						<div style="color: var(--text-2); font-size: 13px;">Ultima pick desta rodada.</div>
					<?php endif; ?>
					<div style="color: var(--text-2); font-size: 12px; margin-top: 4px;"><i class="bi bi-list-ol me-1"></i><?= (int)$remainingDraftPicks ?> picks restantes</div>
				</div>
				<div style="display:flex; flex-direction: column; gap: 8px;">
					<?php if ($activeInitDraftSession && !empty($activeInitDraftSession['access_token'])): ?>
					<a href="/initdraftselecao.php?token=<?= htmlspecialchars($activeInitDraftSession['access_token']) ?>" class="btn-ghost">
						<i class="bi bi-trophy me-1"></i>Abrir sala do draft
					</a>
					<?php endif; ?>
					<?php if (($user['user_type'] ?? '') === 'admin' && $activeInitDraftSession): ?>
					<button class="btn-ghost" type="button" onclick="openAdminInitDraftModal()">
						<i class="bi bi-hand-index-thumb me-1"></i>Escolher como admin
					</button>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<div class="grid-2" style="margin-top: 20px;">
			<div class="panel">
				<div class="panel-title"><i class="bi bi-chat-left-text me-2" style="color: var(--red);"></i>Ultimo rumor</div>
				<?php if ($latestRumor): ?>
					<div style="display:flex; gap: 12px;">
						<img src="<?= htmlspecialchars($latestRumor['photo_url'] ?? '/img/default-team.png') ?>" alt="<?= htmlspecialchars(($latestRumor['city'] ?? '') . ' ' . ($latestRumor['name'] ?? 'Time')) ?>" class="rounded-circle" style="width:60px;height:60px;object-fit:cover;border:2px solid var(--red);">
						<div>
							<div style="font-weight: 700;"><?= htmlspecialchars(($latestRumor['city'] ?? '') . ' ' . ($latestRumor['name'] ?? '')) ?></div>
							<div style="color: var(--text-2); font-size: 12px;">GM: <?= htmlspecialchars($latestRumor['gm_name'] ?? 'Nao informado') ?></div>
							<div style="margin-top: 8px; font-size: 14px;">
								<?= nl2br(htmlspecialchars($latestRumor['content'])) ?>
							</div>
							<?php if (!empty($latestRumor['created_at'])): ?>
								<div style="color: var(--text-2); font-size: 12px; margin-top: 6px;"><i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($latestRumor['created_at'])) ?></div>
							<?php endif; ?>
						</div>
					</div>
				<?php else: ?>
					<div class="text-center" style="color: var(--text-2); padding: 24px 0;">
						<i class="bi bi-chat-left-text" style="font-size: 36px;"></i>
						<div style="margin-top: 10px; font-weight: 700;">Nenhum rumor por aqui</div>
						<small>Aguarde os proximos rumores da liga</small>
					</div>
				<?php endif; ?>
			</div>

			<div class="panel">
				<div class="panel-title"><i class="bi bi-arrow-left-right me-2" style="color: var(--red);"></i>Trades</div>
				<?php if ($tradesEnabled == 0): ?>
					<div class="text-center" style="color: #ff4444; padding: 24px 0;">
						<i class="bi bi-x-circle-fill" style="font-size: 36px;"></i>
						<div style="margin-top: 10px; font-weight: 700;">Trades desativadas</div>
						<small style="color: var(--text-2);">Bloqueado pelo administrador</small>
					</div>
				<?php elseif ($lastTrade): ?>
					<div style="display:flex; align-items:center; justify-content: space-between; gap: 12px; margin-bottom: 14px;">
						<div style="text-align:center;">
							<img src="<?= htmlspecialchars($lastTrade['from_photo'] ?? '/img/default-team.png') ?>" alt="<?= htmlspecialchars($lastTrade['from_city'] . ' ' . $lastTrade['from_name']) ?>" class="rounded-circle" style="width:50px;height:50px;object-fit:cover;border:2px solid var(--red);">
							<div style="font-size: 12px; margin-top: 6px;">
								<?= htmlspecialchars($lastTrade['from_city'] . ' ' . $lastTrade['from_name']) ?>
							</div>
						</div>
						<i class="bi bi-arrow-left-right" style="font-size: 20px; color: var(--red);"></i>
						<div style="text-align:center;">
							<img src="<?= htmlspecialchars($lastTrade['to_photo'] ?? '/img/default-team.png') ?>" alt="<?= htmlspecialchars($lastTrade['to_city'] . ' ' . $lastTrade['to_name']) ?>" class="rounded-circle" style="width:50px;height:50px;object-fit:cover;border:2px solid var(--red);">
							<div style="font-size: 12px; margin-top: 6px;">
								<?= htmlspecialchars($lastTrade['to_city'] . ' ' . $lastTrade['to_name']) ?>
							</div>
						</div>
					</div>
					<div class="trade-items">
						<div class="trade-box">
							<small>Enviou:</small>
							<?php if (count($lastTradeFromPlayers) > 0): ?>
								<?php foreach ($lastTradeFromPlayers as $player): ?>
									<div style="font-size: 12px; margin-top: 4px;">
										<i class="bi bi-person-fill" style="color: var(--red);"></i>
										<?= htmlspecialchars($player['name']) ?>
										<span style="color: var(--text-2);">(<?= $player['position'] ?> - <?= $player['ovr'] ?>)</span>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
							<?php if (count($lastTradeFromPicks) > 0): ?>
								<?php foreach ($lastTradeFromPicks as $pick): ?>
									<div style="font-size: 12px; margin-top: 4px;">
										<i class="bi bi-calendar-check" style="color: var(--red);"></i>
										Pick <?= $pick['season_year'] ?> - R<?= $pick['round'] ?>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
							<?php if (count($lastTradeFromPlayers) == 0 && count($lastTradeFromPicks) == 0): ?>
								<div style="color: var(--text-2); font-size: 12px;">-</div>
							<?php endif; ?>
						</div>
						<div class="trade-box">
							<small>Enviou:</small>
							<?php if (count($lastTradeToPlayers) > 0): ?>
								<?php foreach ($lastTradeToPlayers as $player): ?>
									<div style="font-size: 12px; margin-top: 4px;">
										<i class="bi bi-person-fill" style="color: var(--red);"></i>
										<?= htmlspecialchars($player['name']) ?>
										<span style="color: var(--text-2);">(<?= $player['position'] ?> - <?= $player['ovr'] ?>)</span>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
							<?php if (count($lastTradeToPicks) > 0): ?>
								<?php foreach ($lastTradeToPicks as $pick): ?>
									<div style="font-size: 12px; margin-top: 4px;">
										<i class="bi bi-calendar-check" style="color: var(--red);"></i>
										Pick <?= $pick['season_year'] ?> - R<?= $pick['round'] ?>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
							<?php if (count($lastTradeToPlayers) == 0 && count($lastTradeToPicks) == 0): ?>
								<div style="color: var(--text-2); font-size: 12px;">-</div>
							<?php endif; ?>
						</div>
					</div>
					<div style="text-align:center; margin-top: 8px; color: var(--text-2); font-size: 12px;">
						<?php
						if (!empty($lastTrade['updated_at'])) {
							$tradeDate = new DateTime($lastTrade['updated_at']);
							$now = new DateTime();
							$diff = $now->diff($tradeDate);

							if ($diff->days == 0) {
								echo "Hoje";
							} elseif ($diff->days == 1) {
								echo "Ontem";
							} elseif ($diff->days < 7) {
								echo $diff->days . " dias atras";
							} else {
								echo $tradeDate->format('d/m/Y');
							}
						}
						?>
					</div>
				<?php else: ?>
					<div class="text-center" style="color: var(--text-2); padding: 24px 0;">
						<i class="bi bi-arrow-left-right" style="font-size: 36px;"></i>
						<div style="margin-top: 10px; font-weight: 700;">Nenhuma trade realizada</div>
						<small>Seja o primeiro a trocar</small>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="grid-3" style="margin-top: 20px;">
			<div class="panel" style="text-align:center;">
				<div style="margin-bottom: 10px;">
					<img src="/img/logo-<?= strtolower($user['league']) ?>.png" alt="<?= htmlspecialchars($user['league']) ?>" style="height: 70px; width: auto; object-fit: contain;">
				</div>
				<div style="font-weight: 700; color: var(--red); margin-bottom: 10px;"><?= htmlspecialchars($user['league']) ?></div>
				<div class="grid-2" style="gap: 10px;">
					<div class="mini-card">
						<div style="font-size: 12px; color: var(--text-2);">Ranking</div>
						<div style="font-weight: 700;"><?= (int)($team['ranking_points'] ?? 0) ?></div>
					</div>
					<?php if ($currentSeason): ?>
					<div class="mini-card">
						<div style="font-size: 12px; color: var(--text-2);">Temporada</div>
						<div style="font-weight: 700;"><?= (int)$seasonDisplayYear ?></div>
					</div>
					<div class="mini-card">
						<div style="font-size: 12px; color: var(--text-2);">Sprint</div>
						<div style="font-weight: 700;"><?= (int)($currentSeason['sprint_number'] ?? 1) ?></div>
					</div>
					<?php endif; ?>
					<div class="mini-card" style="grid-column: <?= $currentSeason ? 'span 1' : 'span 2' ?>;">
						<div style="font-size: 12px; color: var(--text-2);">CAP</div>
						<div style="font-weight: 700;"><?= $capMin ?>-<?= $capMax ?></div>
					</div>
				</div>
				<?php if ($hasEdital): ?>
				<div style="margin-top: 12px;">
					<a href="/api/edital.php?action=download_edital&league=<?= urlencode($team['league']) ?>" class="btn-ghost" download>
						<i class="bi bi-download me-1"></i>Edital
					</a>
				</div>
				<?php endif; ?>
			</div>

			<div class="panel">
				<div class="panel-title"><i class="bi bi-trophy-fill me-2" style="color: var(--red);"></i>Top 5 Ranking</div>
				<?php if (count($topRanking) > 0): ?>
					<?php foreach ($topRanking as $index => $rankTeam): ?>
						<div class="ranking-item" style="<?= $rankTeam['id'] == $team['id'] ? 'border-color: var(--red);' : '' ?>">
							<span class="badge" style="background: var(--panel-3); color: var(--text);"><?= $index + 1 ?>o</span>
							<img src="<?= htmlspecialchars($rankTeam['photo_url'] ?? '/img/default-team.png') ?>" alt="<?= htmlspecialchars($rankTeam['city'] . ' ' . $rankTeam['name']) ?>" class="rounded-circle" style="width: 35px; height: 35px; object-fit: cover;">
							<div style="flex: 1;">
								<div style="font-weight: 700; font-size: 13px;"><?= htmlspecialchars($rankTeam['city'] . ' ' . $rankTeam['name']) ?></div>
								<div style="color: var(--text-2); font-size: 12px;"><?= htmlspecialchars($rankTeam['owner_name'] ?? '') ?></div>
							</div>
							<div style="text-align: right;">
								<div style="font-weight: 700; color: var(--red);"><?= (int)$rankTeam['ranking_points'] ?></div>
								<div style="color: var(--text-2); font-size: 11px;">pts</div>
							</div>
						</div>
					<?php endforeach; ?>
				<?php else: ?>
					<div class="text-center" style="color: var(--text-2); padding: 24px 0;">
						<i class="bi bi-trophy" style="font-size: 36px;"></i>
						<div style="margin-top: 10px;">Ranking em breve</div>
					</div>
				<?php endif; ?>
			</div>

			<div class="panel">
				<div class="panel-title"><i class="bi bi-award-fill me-2" style="color: var(--red);"></i>Ultimo sprint</div>
				<?php if ($lastChampion || $lastRunnerUp || $lastMVP): ?>
					<?php if ($lastSprintInfo): ?>
					<div style="text-align: center; color: var(--text-2); font-size: 12px; margin-bottom: 8px;">
						Sprint <?= (int)($lastSprintInfo['sprint_number'] ?? 0) ?>
						<?php if (!empty($lastSprintInfo['start_year'])): ?> - Temporada <?= (int)$lastSprintInfo['start_year'] ?><?php endif; ?>
					</div>
					<?php endif; ?>

					<?php if ($lastChampion): ?>
					<div class="mini-card" style="border-color: #ffc107; margin-bottom: 10px;">
						<div style="display:flex; align-items:center; gap: 10px;">
							<i class="bi bi-trophy-fill" style="color: #ffc107; font-size: 20px;"></i>
							<img src="<?= htmlspecialchars($lastChampion['photo_url'] ?? '/img/default-team.png') ?>" alt="<?= htmlspecialchars($lastChampion['city'] . ' ' . $lastChampion['name']) ?>" class="rounded-circle" style="width: 40px; height: 40px; object-fit: cover;">
							<div>
								<div style="font-size: 12px; color: #ffc107; font-weight: 700;">CAMPEAO</div>
								<div style="font-size: 13px; font-weight: 700;"><?= htmlspecialchars($lastChampion['city'] . ' ' . $lastChampion['name']) ?></div>
								<div style="color: var(--text-2); font-size: 11px;"><?= htmlspecialchars($lastChampion['owner_name'] ?? '') ?></div>
							</div>
						</div>
					</div>
					<?php endif; ?>

					<?php if ($lastRunnerUp): ?>
					<div class="mini-card" style="margin-bottom: 10px;">
						<div style="display:flex; align-items:center; gap: 10px;">
							<i class="bi bi-award" style="color: #9aa0ac; font-size: 20px;"></i>
							<img src="<?= htmlspecialchars($lastRunnerUp['photo_url'] ?? '/img/default-team.png') ?>" alt="<?= htmlspecialchars($lastRunnerUp['city'] . ' ' . $lastRunnerUp['name']) ?>" class="rounded-circle" style="width: 40px; height: 40px; object-fit: cover;">
							<div>
								<div style="font-size: 12px; color: #9aa0ac; font-weight: 700;">VICE</div>
								<div style="font-size: 13px; font-weight: 700;"><?= htmlspecialchars($lastRunnerUp['city'] . ' ' . $lastRunnerUp['name']) ?></div>
								<div style="color: var(--text-2); font-size: 11px;"><?= htmlspecialchars($lastRunnerUp['owner_name'] ?? '') ?></div>
							</div>
						</div>
					</div>
					<?php endif; ?>

					<?php if ($lastMVP): ?>
					<div class="mini-card" style="border-color: var(--red);">
						<div style="display:flex; align-items:center; gap: 10px;">
							<i class="bi bi-star-fill" style="color: var(--red); font-size: 20px;"></i>
							<div>
								<div style="font-size: 12px; color: var(--red); font-weight: 700;">MVP</div>
								<div style="font-size: 13px; font-weight: 700;"><?= htmlspecialchars($lastMVP['name']) ?></div>
								<div style="color: var(--text-2); font-size: 11px;">
									<?php if (!empty($lastMVP['team_city']) && !empty($lastMVP['team_name'])): ?>
										<?= htmlspecialchars($lastMVP['team_city'] . ' ' . $lastMVP['team_name']) ?>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>
					<?php endif; ?>
				<?php else: ?>
					<div class="text-center" style="color: var(--text-2); padding: 24px 0;">
						<i class="bi bi-award" style="font-size: 36px;"></i>
						<div style="margin-top: 10px; font-weight: 700;">Temporada nao iniciada</div>
						<small>Vencedores aparecem apos o primeiro sprint</small>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="panel" style="margin-top: 20px;">
			<div class="panel-title"><i class="bi bi-trophy me-2" style="color: var(--red);"></i>Quinteto titular</div>
			<?php if (count($titulares) > 0): ?>
				<div class="starter-grid">
					<?php foreach ($titulares as $player): ?>
						<?php
							$playerName = $player['name'] ?? '';
							$customPhoto = trim((string)($player['foto_adicional'] ?? ''));
							if ($customPhoto !== '' && !preg_match('#^https?://#i', $customPhoto)) {
								$customPhoto = '/' . ltrim($customPhoto, '/');
							}
							$nbaPlayerId = $player['nba_player_id'] ?? null;
							$playerPhoto = $customPhoto !== ''
								? $customPhoto
								: ($nbaPlayerId
									? 'https://cdn.nba.com/headshots/nba/latest/1040x760/' . rawurlencode((string)$nbaPlayerId) . '.png'
									: 'https://ui-avatars.com/api/?name=' . rawurlencode($playerName) . '&background=121212&color=fc0025&rounded=true&bold=true');
						?>
						<div class="starter-card">
							<img src="<?= htmlspecialchars($playerPhoto) ?>" alt="<?= htmlspecialchars($playerName) ?>" onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($playerName) ?>&background=121212&color=fc0025&rounded=true&bold=true'">
							<div style="margin-top: 8px; font-weight: 700; font-size: 13px;"><?= htmlspecialchars($playerName) ?></div>
							<div style="color: var(--text-2); font-size: 12px;"><?= htmlspecialchars($player['position']) ?> - OVR <?= (int)$player['ovr'] ?></div>
							<div style="color: var(--text-2); font-size: 12px;"><?= (int)$player['age'] ?> anos</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else: ?>
				<div class="text-center" style="color: var(--text-2); padding: 24px 0;">
					<i class="bi bi-exclamation-circle" style="font-size: 36px;"></i>
					<div style="margin-top: 10px;">Sem titulares no momento</div>
					<a href="/my-roster.php" class="btn-ghost" style="display:inline-flex; margin-top: 12px;">
						<i class="bi bi-plus-circle me-1"></i>Gerenciar elenco
					</a>
				</div>
			<?php endif; ?>
		</div>
	</main>
</div>

<?php if (($user['user_type'] ?? '') === 'admin'): ?>
<div class="modal fade" id="adminInitDraftModal" tabindex="-1">
	<div class="modal-dialog modal-lg modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><i class="bi bi-hand-index-thumb me-2" style="color: var(--red);"></i>Escolher jogador (Admin)</h5>
				<button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
			</div>
			<div class="modal-body">
				<p style="color: var(--text-2); font-size: 12px;">Use apenas quando o time nao estiver disponivel.</p>
				<div class="table-responsive">
					<table class="table table-dark table-sm align-middle mb-0">
						<thead>
							<tr>
								<th>Jogador</th>
								<th>Pos</th>
								<th>OVR</th>
								<th></th>
							</tr>
						</thead>
						<tbody id="adminInitDraftPlayers">
							<tr>
								<td colspan="4" class="text-center text-light-gray">Carregando...</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

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

	const rosterData = <?= json_encode($allPlayers) ?>;
	const picksData = <?= json_encode($teamPicksForCopy) ?>;
	const teamMeta = {
		name: <?= json_encode($team['city'] . ' ' . $team['name']) ?>,
		city: <?= json_encode($team['city']) ?>,
		teamName: <?= json_encode($team['name']) ?>,
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
		positions.forEach(pos => startersMap[pos] = null);

		const formatAge = (age) => (Number.isFinite(age) && age > 0) ? `${age}y` : '-';

		const formatLine = (label, player) => {
			if (!player) return `${label}: -`;
			const ovr = player.ovr ?? '-';
			const age = player.age ?? '-';
			return `${label}: ${player.name} - ${ovr} | ${formatAge(age)}`;
		};

		const starters = rosterData.filter(p => p.role === 'Titular');
		starters.forEach(p => {
			if (positions.includes(p.position) && !startersMap[p.position]) {
				startersMap[p.position] = p;
			}
		});

		const benchPlayers = rosterData.filter(p => p.role === 'Banco');
		const othersPlayers = rosterData.filter(p => p.role === 'Outro');
		const gleaguePlayers = rosterData.filter(p => (p.role || '').toLowerCase() === 'g-league');

		const round1Years = picksData.filter(pk => pk.round == 1).map(pk => {
			const isTraded = (pk.original_team_id != pk.team_id);
			return `-${pk.season_year}${isTraded ? ` (via ${pk.city} ${pk.team_name})` : ''} `;
		});
		const round2Years = picksData.filter(pk => pk.round == 2).map(pk => {
			const isTraded = (pk.original_team_id != pk.team_id);
			return `-${pk.season_year}${isTraded ? ` (via ${pk.city} ${pk.team_name})` : ''} `;
		});

		const lines = [];
		lines.push(`*${teamMeta.name}*`);
		lines.push(teamMeta.userName);
		lines.push('');
		lines.push('_Starters_');
		positions.forEach(pos => {
			lines.push(formatLine(pos, startersMap[pos]));
		});
		lines.push('');
		lines.push('_Bench_');
		if (benchPlayers.length) {
			benchPlayers.forEach(p => {
				const ovr = p.ovr ?? '-';
				const age = p.age ?? '-';
				lines.push(`${p.position}: ${p.name} - ${ovr} | ${formatAge(age)}`);
			});
		} else {
			lines.push('-');
		}
		lines.push('');
		lines.push('_Others_');
		if (othersPlayers.length) {
			othersPlayers.forEach(p => {
				const ovr = p.ovr ?? '-';
				const age = p.age ?? '-';
				lines.push(`${p.position}: ${p.name} - ${ovr} | ${formatAge(age)}`);
			});
		} else {
			lines.push('-');
		}
		lines.push('');
		lines.push('_G-League_');
		if (gleaguePlayers.length) {
			gleaguePlayers.forEach(p => {
				const ovr = p.ovr ?? '-';
				const age = p.age ?? '-';
				lines.push(`${p.position}: ${p.name} - ${ovr} | ${formatAge(age)}`);
			});
		} else {
			lines.push('-');
		}
		lines.push('');
		lines.push('_Picks 1st round_:');
		lines.push(...(round1Years.length ? round1Years : ['-']));
		lines.push('');
		lines.push('_Picks 2nd round_:');
		lines.push(...(round2Years.length ? round2Years : ['-']));
		lines.push('');
		lines.push(`_CAP_: ${teamMeta.capMin} / *${teamMeta.cap}* / ${teamMeta.capMax}`);
		lines.push(`_Trades_: ${teamMeta.trades} / ${teamMeta.maxTrades}`);

		return lines.join('\n');
	}

	document.getElementById('copyTeamBtn')?.addEventListener('click', async () => {
		const text = buildTeamSummary();
		try {
			await navigator.clipboard.writeText(text);
			alert('Time copiado para a area de transferencia.');
		} catch (err) {
			const textarea = document.createElement('textarea');
			textarea.value = text;
			document.body.appendChild(textarea);
			textarea.select();
			document.execCommand('copy');
			document.body.removeChild(textarea);
			alert('Time copiado para a area de transferencia.');
		}
	});

	const INIT_DRAFT_SESSION_ID = <?= $activeInitDraftSession ? (int)$activeInitDraftSession['id'] : 'null'; ?>;
	const IS_ADMIN_USER = <?= (($user['user_type'] ?? '') === 'admin') ? 'true' : 'false'; ?>;
	const escapeHtml = (value = '') => String(value)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');

	async function openAdminInitDraftModal() {
		if (!IS_ADMIN_USER) return;
		if (!INIT_DRAFT_SESSION_ID) {
			alert('Nenhum draft inicial ativo.');
			return;
		}
		await loadAdminInitDraftPlayers();
		const modalEl = document.getElementById('adminInitDraftModal');
		if (!modalEl) return;
		const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
		modal.show();
	}

	async function loadAdminInitDraftPlayers() {
		const tbody = document.getElementById('adminInitDraftPlayers');
		if (!tbody || !INIT_DRAFT_SESSION_ID) return;
		tbody.innerHTML = '<tr><td colspan="4" class="text-center text-light-gray">Carregando jogadores...</td></tr>';
		try {
			const res = await fetch(`/api/initdraft.php?action=available_players&session_id=${INIT_DRAFT_SESSION_ID}`);
			const data = await res.json();
			if (!data.success) throw new Error(data.error || 'Falha ao buscar jogadores');
			const players = data.players || [];
			if (players.length === 0) {
				tbody.innerHTML = '<tr><td colspan="4" class="text-center text-light-gray">Nenhum jogador disponivel.</td></tr>';
				return;
			}
			tbody.innerHTML = players.map(p => `
				<tr>
					<td>${escapeHtml(p.name)}</td>
					<td><span class="badge" style="background: var(--red);">${escapeHtml(p.position)}</span></td>
					<td>${escapeHtml(p.ovr)}</td>
					<td class="text-end">
						<button class="btn btn-sm btn-success" onclick="adminMakeInitDraftPick(${p.id}, this)">
							<i class="bi bi-check2"></i>
						</button>
					</td>
				</tr>
			`).join('');
		} catch (err) {
			tbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger">${err.message}</td></tr>`;
		}
	}

	async function adminMakeInitDraftPick(playerId, buttonEl) {
		if (!IS_ADMIN_USER || !INIT_DRAFT_SESSION_ID) return;
		if (!confirm('Confirmar escolha deste jogador?')) return;
		buttonEl?.classList.add('disabled');
		try {
			const res = await fetch('/api/initdraft.php', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ action: 'admin_make_pick', session_id: INIT_DRAFT_SESSION_ID, player_id: playerId })
			});
			const data = await res.json();
			if (!data.success) throw new Error(data.error || 'Falha ao registrar pick');
			alert('Pick registrada com sucesso.');
			location.reload();
		} catch (err) {
			alert(err.message);
			buttonEl?.classList.remove('disabled');
		}
	}
</script>
</body>
</html>
