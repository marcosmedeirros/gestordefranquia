<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

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
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);

$stmtTeams = $pdo->prepare('SELECT id, city, name FROM teams WHERE league = ? ORDER BY city ASC, name ASC');
$stmtTeams->execute([$user['league']]);
$leagueTeams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC) ?: [];

$playersTotal = 0;
$playersAvgOvr = 0;

try {
	$stmtPlayers = $pdo->prepare('
		SELECT COUNT(*) as total, AVG(p.ovr) as avg_ovr
		FROM players p
		INNER JOIN teams t ON t.id = p.team_id
		WHERE t.league = ?
	');
	$stmtPlayers->execute([$user['league']]);
	$playersRow = $stmtPlayers->fetch(PDO::FETCH_ASSOC) ?: [];
	$playersTotal = (int)($playersRow['total'] ?? 0);
	$playersAvgOvr = (float)($playersRow['avg_ovr'] ?? 0);
} catch (Exception $e) {
	$playersTotal = 0;
	$playersAvgOvr = 0;
}


$whatsappDefaultMessage = rawurlencode('Olá! Podemos conversar sobre nossas franquias na FBA?');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<?php include __DIR__ . '/includes/head-pwa.php'; ?>
	<title>Jogadores - FBA Manager</title>

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
			--border-md: rgba(255,255,255,.10);
			--border-red: rgba(252,0,37,.22);
			--text: #f0f0f3;
			--text-2: #868690;
			--text-3: #48484f;
			--sidebar-w: 260px;
			--font-display: 'Poppins', sans-serif;
			--font-body: 'Poppins', sans-serif;
			--radius: 14px;
			--radius-sm: 10px;
			--radius-xs: 6px;
			--ease: cubic-bezier(.2,.8,.2,1);
			--t: 200ms;
		}

		:root[data-theme="light"] {
			--bg: #f6f7fb;
			--panel: #ffffff;
			--panel-2: #f2f4f8;
			--panel-3: #e9edf4;
			--border: #e3e6ee;
			--border-md: #d7dbe6;
			--border-red: rgba(252,0,37,.18);
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
		.app { display: flex; min-height: 100vh; }

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

		/* ── Topbar ───────────────────────────────────── */
		.topbar {
			display: none; position: fixed; top: 0; left: 0; right: 0;
			height: 54px; background: var(--panel);
			border-bottom: 1px solid var(--border);
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
		.sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 199; }
		.sb-overlay { z-index: 250; }
		.sb-overlay.show { display: block; }

		/* ── Main ─────────────────────────────────────── */
		.main {
			margin-left: var(--sidebar-w);
			width: calc(100% - var(--sidebar-w));
			padding: 32px 40px 60px;
		}

		.page-top { display: flex; align-items: flex-end; justify-content: space-between; gap: 18px; margin-bottom: 22px; }
		.page-eyebrow { font-size: 12px; letter-spacing: .2em; text-transform: uppercase; color: var(--text-3); margin-bottom: 8px; }
		.page-title { font-size: 32px; font-family: var(--font-display); margin-bottom: 6px; }
		.page-sub { color: var(--text-2); font-size: 14px; }

		.stats-strip { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 14px; margin-bottom: 26px; }
		.stat-pill {
			background: var(--panel);
			border: 1px solid var(--border);
			border-radius: var(--radius-sm);
			padding: 16px 18px;
			display: flex; gap: 12px; align-items: center;
		}
		.stat-pill-icon {
			width: 42px; height: 42px; border-radius: 12px;
			background: var(--panel-2); border: 1px solid var(--border);
			display: flex; align-items: center; justify-content: center; color: var(--red);
		}
		.stat-pill-val { font-weight: 700; font-size: 18px; font-family: var(--font-display); }
		.stat-pill-label { color: var(--text-2); font-size: 12px; }

		.panel {
			background: var(--panel);
			border: 1px solid var(--border);
			border-radius: var(--radius);
			padding: 18px 20px 20px;
		}
		.panel + .panel { margin-top: 20px; }
		.panel-title { font-family: var(--font-display); font-size: 18px; margin-bottom: 14px; }

		.filters-grid {
			display: grid;
			grid-template-columns: repeat(12, minmax(0,1fr));
			gap: 12px;
		}
		.field { display: flex; flex-direction: column; gap: 6px; }
		.field label { font-size: 12px; color: var(--text-2); letter-spacing: .08em; text-transform: uppercase; }
		.field input, .field select {
			background: var(--panel-2);
			border: 1px solid var(--border);
			border-radius: 10px;
			padding: 10px 12px;
			color: var(--text);
			font-size: 14px;
		}
		.field input::placeholder { color: var(--text-3); }
		.btn-action {
			border: none; background: var(--red); color: #fff; font-weight: 700;
			border-radius: 12px; padding: 12px 14px; width: 100%;
			transition: transform var(--t) var(--ease), box-shadow var(--t) var(--ease);
		}
		.btn-action:hover { transform: translateY(-1px); box-shadow: 0 12px 20px rgba(252,0,37,.18); }

		.players-table { width: 100%; border-collapse: collapse; font-size: 14px; }
		.players-table thead th {
			text-transform: uppercase; font-size: 11px; letter-spacing: .18em; color: var(--text-3);
			padding: 12px 10px; text-align: left; border-bottom: 1px solid var(--border);
		}
		.players-table tbody td { padding: 12px 10px; border-bottom: 1px solid var(--border); }
		.players-table tbody tr:hover { background: var(--panel-2); }

		.badge-ovr {
			display: inline-flex; align-items: center; justify-content: center;
			padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700;
		}
		.ovr-high { background: rgba(37,198,119,.18); color: #25c677; }
		.ovr-mid { background: rgba(33,133,208,.18); color: #2196f3; }
		.ovr-low { background: rgba(255,193,7,.18); color: #ffc107; }
		.ovr-base { background: rgba(130,130,138,.18); color: #9aa0ac; }

		.players-cards {
			display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px;
		}
		.player-card {
			background: var(--panel-2);
			border: 1px solid var(--border);
			border-radius: var(--radius-sm);
			padding: 14px;
			display: flex; flex-direction: column; gap: 12px;
		}
		.player-card-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
		.player-card-header img { width: 44px; height: 44px; border-radius: 50%; border: 1px solid var(--border); object-fit: cover; }
		.player-card-name { font-weight: 700; }
		.player-card-team { color: var(--text-2); font-size: 12px; }
		.player-card-body { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 10px; }
		.player-card-stat { font-size: 12px; color: var(--text-2); }
		.player-card-stat strong { display: block; color: var(--text); font-size: 13px; }
		.player-card-actions { display: flex; gap: 8px; flex-wrap: wrap; }
		.player-card-actions .btn { flex: 1 1 120px; }

		.btn-outline {
			border: 1px solid var(--border);
			background: transparent;
			color: var(--text);
			border-radius: 10px;
			padding: 8px 10px;
			font-size: 12px;
			font-weight: 600;
		}
		.btn-outline.success { border-color: rgba(37,198,119,.4); color: #25c677; }
		.btn-outline.info { border-color: rgba(33,133,208,.4); color: #3da0ff; }
		.btn-outline.warning { border-color: rgba(255,193,7,.5); color: #ffc107; }

		.btn-trade-action {
			background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
			color: #000;
			font-weight: 700;
			border: none;
			border-radius: 10px;
			padding: 8px 10px;
			font-size: 12px;
		}

		.text-light-gray { color: var(--text-2); }
		.empty-state { text-align: center; color: var(--text-2); padding: 30px 0; }

		.pagination-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 16px; }
		.pagination-bar .btn-outline { padding: 6px 12px; }

		.modal-content {
			background: var(--panel);
			border: 1px solid var(--border);
			border-radius: var(--radius-sm);
		}
		.modal-header { border-bottom: 1px solid var(--border); }
		.modal-title { font-family: var(--font-display); }
		.modal-body h6 { color: var(--red); }
		.modal-body .card-mini { background: var(--panel-2); border: 1px solid var(--border); border-radius: 10px; padding: 10px; }

		/* ── Responsive ──────────────────────────────── */
		@media (max-width: 1100px) {
			.filters-grid { grid-template-columns: repeat(6, minmax(0,1fr)); }
		}
		@media (max-width: 992px) {
			.stats-strip { grid-template-columns: repeat(2, minmax(0,1fr)); }
			.sidebar { transform: translateX(-260px); }
			.sidebar.open { transform: translateX(0); }
			.topbar { display: flex; }
			.main { margin-left: 0; width: 100%; padding: 54px 16px 40px; }
			.filters-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
		}
		@media (max-width: 560px) {
			.stats-strip { grid-template-columns: 1fr; }
			.filters-grid { grid-template-columns: 1fr; }
		}

		.sb-overlay {
			position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px);
			z-index: 250; display: none;
		}
		.sb-overlay.show { display: block; }
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


	<main class="main">

		<div class="page-top">
			<div>
				<div class="page-eyebrow">Liga - <?= $currentSeasonYear ?></div>
				<h1 class="page-title">Jogadores da Liga</h1>
				<p class="page-sub"><?= number_format($playersTotal, 0, ',', '.') ?> atletas cadastrados</p>
			</div>
		</div>

		<div class="stats-strip">
			<div class="stat-pill">
				<div class="stat-pill-icon"><i class="bi bi-person-badge"></i></div>
				<div>
					<div class="stat-pill-val"><?= number_format($playersTotal, 0, ',', '.') ?></div>
					<div class="stat-pill-label">Jogadores</div>
				</div>
			</div>
			<div class="stat-pill">
				<div class="stat-pill-icon"><i class="bi bi-graph-up"></i></div>
				<div>
					<div class="stat-pill-val"><?= number_format($playersAvgOvr, 1, ',', '.') ?></div>
					<div class="stat-pill-label">OVR médio</div>
				</div>
			</div>
		</div>

		<div class="panel">
			<div class="panel-title">Filtros</div>
			<div class="filters-grid">
				<div class="field" style="grid-column: span 4;">
					<label for="playersSearchInput">Buscar por nome</label>
					<input type="text" id="playersSearchInput" placeholder="Digite o nome do jogador">
				</div>
				<div class="field" style="grid-column: span 2;">
					<label for="playersPositionFilter">Posicao</label>
					<select id="playersPositionFilter">
						<option value="">Todas</option>
						<option value="PG">PG</option>
						<option value="SG">SG</option>
						<option value="SF">SF</option>
						<option value="PF">PF</option>
						<option value="C">C</option>
					</select>
				</div>
				<div class="field" style="grid-column: span 2;">
					<label for="playersOvrMin">OVR minimo</label>
					<input type="number" id="playersOvrMin" placeholder="70">
				</div>
				<div class="field" style="grid-column: span 2;">
					<label for="playersOvrMax">OVR maximo</label>
					<input type="number" id="playersOvrMax" placeholder="99">
				</div>
				<div class="field" style="grid-column: span 2;">
					<label for="playersAgeMin">Idade min.</label>
					<input type="number" id="playersAgeMin" placeholder="18">
				</div>
				<div class="field" style="grid-column: span 2;">
					<label for="playersAgeMax">Idade max.</label>
					<input type="number" id="playersAgeMax" placeholder="40">
				</div>
				<div class="field" style="grid-column: span 4;">
					<label for="playersTeamFilter">Time</label>
					<select id="playersTeamFilter">
						<option value="">Todos</option>
						<?php foreach ($leagueTeams as $teamItem): ?>
							<option value="<?= (int)$teamItem['id'] ?>"><?= htmlspecialchars($teamItem['city'] . ' ' . $teamItem['name']) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="field" style="grid-column: span 2; align-self: end;">
					<button class="btn-action" id="playersSearchBtn"><i class="bi bi-search me-1"></i>Buscar</button>
				</div>
			</div>
		</div>

		<div class="panel">
			<div class="panel-title">Jogadores da Liga</div>

			<div id="playersLoading" class="text-center py-4">
				<div class="spinner-border" role="status" style="color: var(--red);"></div>
			</div>
			<div class="table-responsive" id="playersTableWrap" style="display:none;">
				<table class="players-table">
					<thead>
						<tr>
							<th>Jogador</th>
							<th>OVR</th>
							<th>Idade</th>
							<th>Posicao</th>
							<th>Posicao Sec.</th>
							<th>Time</th>
							<th>Contato</th>
							<th>Acoes</th>
						</tr>
					</thead>
					<tbody id="playersTableBody"></tbody>
				</table>
			</div>
			<div id="playersCardsWrap" class="players-cards" style="display:none;"></div>
			<div id="playersEmpty" class="empty-state" style="display:none;">Nenhum jogador encontrado.</div>
			<div id="playersPagination" class="pagination-bar" style="display:none;">
				<div class="text-light-gray" id="playersPaginationInfo"></div>
				<div class="d-flex gap-2">
					<button class="btn-outline" id="playersPrevPage"><i class="bi bi-chevron-left"></i> Anterior</button>
					<button class="btn-outline" id="playersNextPage">Proximo <i class="bi bi-chevron-right"></i></button>
				</div>
			</div>
		</div>
	</main>
</div>

<div class="modal fade" id="playerDetailsModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="playerDetailsTitle">Detalhes do Jogador</h5>
				<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
			</div>
			<div class="modal-body" id="playerDetailsContent"></div>
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

	const sidebar  = document.getElementById('sidebar');
	const sbOverlay  = document.getElementById('sbOverlay');
	const menuBtn  = document.getElementById('menuBtn');
	menuBtn?.addEventListener('click', () => {
		sidebar.classList.toggle('open');
		sbOverlay.classList.toggle('show');
	});
	sbOverlay.addEventListener('click', () => {
		sidebar.classList.remove('open');
		sbOverlay.classList.remove('show');
	});

	const defaultMessage = '<?= $whatsappDefaultMessage ?>';
	const searchInput = document.getElementById('playersSearchInput');
	const positionFilter = document.getElementById('playersPositionFilter');
	const ovrMinInput = document.getElementById('playersOvrMin');
	const ovrMaxInput = document.getElementById('playersOvrMax');
	const ageMinInput = document.getElementById('playersAgeMin');
	const ageMaxInput = document.getElementById('playersAgeMax');
	const teamFilter = document.getElementById('playersTeamFilter');
	const searchBtn = document.getElementById('playersSearchBtn');
	const loading = document.getElementById('playersLoading');
	const tableWrap = document.getElementById('playersTableWrap');
	const tableBody = document.getElementById('playersTableBody');
	const cardsWrap = document.getElementById('playersCardsWrap');
	const emptyState = document.getElementById('playersEmpty');
	const paginationWrap = document.getElementById('playersPagination');
	const paginationInfo = document.getElementById('playersPaginationInfo');
	const prevPageBtn = document.getElementById('playersPrevPage');
	const nextPageBtn = document.getElementById('playersNextPage');
	const isMobile = () => window.matchMedia('(max-width: 992px)').matches;
	let currentPage = 1;
	let totalPages = 1;
	const perPage = 50;

	function getPlayerPhotoUrl(player) {
		const customPhoto = (player.foto_adicional || '').toString().trim();
		if (customPhoto) {
			return customPhoto;
		}
		return player.nba_player_id
			? `https://cdn.nba.com/headshots/nba/latest/1040x760/${player.nba_player_id}.png`
			: `https://ui-avatars.com/api/?name=${encodeURIComponent(player.name)}&background=121212&color=fc0025&rounded=true&bold=true`;
	}

	function getOvrClass(ovr) {
		if (ovr >= 85) return 'ovr-high';
		if (ovr >= 78) return 'ovr-mid';
		if (ovr >= 72) return 'ovr-low';
		return 'ovr-base';
	}

	function renderPlayerCard(p, whatsappLink, teamName) {
		const ovr = Number(p.ovr || 0);
		const photoUrl = getPlayerPhotoUrl(p);
		return `
			<div class="player-card">
				<div class="player-card-header">
					<div class="d-flex align-items-center gap-2">
						<img src="${photoUrl}" alt="${p.name}" onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=fc0025&rounded=true&bold=true'">
						<div>
							<div class="player-card-name">${p.name}</div>
							<div class="player-card-team">${teamName}</div>
						</div>
					</div>
					<span class="badge-ovr ${getOvrClass(ovr)}">${p.ovr}</span>
				</div>
				<div class="player-card-body">
					<div class="player-card-stat"><strong>${p.age ?? '-'}</strong>Idade</div>
					<div class="player-card-stat"><strong>${p.position ?? '-'}</strong>Posicao</div>
					<div class="player-card-stat"><strong>${p.secondary_position ?? '-'}</strong>Posicao Sec.</div>
					<div class="player-card-stat"><strong>${p.team_name ?? '-'}</strong>Time</div>
				</div>
				<div class="player-card-actions">
					${whatsappLink ? `<a class="btn-outline success" href="${whatsappLink}" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> Falar</a>` : '<span class="text-light-gray">Sem contato</span>'}
					<button class="btn-outline info" type="button" onclick="openPlayerDetails(${p.id})"><i class="bi bi-info-circle"></i> Detalhes</button>
					<a class="btn-trade-action" href="/trades.php?player=${p.id}&team=${p.team_id}" title="Propor trade por este jogador"><i class="bi bi-arrow-left-right"></i> Trocar</a>
				</div>
			</div>
		`;
	}

	async function carregarJogadores() {
		const query = searchInput.value.trim();
		const position = positionFilter.value;
		const ovrMin = ovrMinInput.value;
		const ovrMax = ovrMaxInput.value;
		const ageMin = ageMinInput.value;
		const ageMax = ageMaxInput.value;
		const teamId = teamFilter.value;

		loading.style.display = 'block';
		tableWrap.style.display = 'none';
		cardsWrap.style.display = 'none';
		emptyState.style.display = 'none';
		paginationWrap.style.display = 'none';
		tableBody.innerHTML = '';
		cardsWrap.innerHTML = '';

		const params = new URLSearchParams();
		params.set('action', 'list_players');
		if (query) params.set('query', query);
		if (position) params.set('position', position);
		if (ovrMin) params.set('ovr_min', ovrMin);
		if (ovrMax) params.set('ovr_max', ovrMax);
		if (ageMin) params.set('age_min', ageMin);
		if (ageMax) params.set('age_max', ageMax);
		if (teamId) params.set('team_id', teamId);
		params.set('page', currentPage);
		params.set('per_page', perPage);

		try {
			const res = await fetch(`/api/team.php?${params.toString()}`);
			const data = await res.json();
			const players = data.players || [];
			const pagination = data.pagination || { page: 1, per_page: perPage, total: players.length, total_pages: 1 };
			totalPages = pagination.total_pages || 1;

			if (!players.length) {
				emptyState.style.display = 'block';
			} else {
				const mobile = isMobile();
				players.forEach(p => {
					const teamName = `${p.city ?? ''} ${p.team_name ?? ''}`.trim();
					const whatsappLink = p.owner_phone_whatsapp
						? `https://api.whatsapp.com/send/?phone=${encodeURIComponent(p.owner_phone_whatsapp)}&text=${defaultMessage}&type=phone_number&app_absent=0`
						: '';

					if (mobile) {
						cardsWrap.innerHTML += renderPlayerCard(p, whatsappLink, teamName || '-');
					} else {
						const ovr = Number(p.ovr || 0);
						const photoUrl = getPlayerPhotoUrl(p);
						tableBody.innerHTML += `
							<tr>
								<td>
									<div class="d-flex align-items-center gap-2">
										<img src="${photoUrl}" alt="${p.name}" style="width: 34px; height: 34px; border-radius: 50%; border: 1px solid var(--border);" onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=fc0025&rounded=true&bold=true'">
										<strong>${p.name}</strong>
									</div>
								</td>
								<td><span class="badge-ovr ${getOvrClass(ovr)}">${p.ovr}</span></td>
								<td>${p.age ?? '-'}</td>
								<td>${p.position ?? '-'}</td>
								<td>${p.secondary_position ?? '-'}</td>
								<td>${teamName || '-'}</td>
								<td>
									${whatsappLink ? `<a class="btn-outline success" href="${whatsappLink}" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> Falar</a>` : '<span class="text-light-gray">Sem contato</span>'}
								</td>
								<td>
									<button class="btn-outline info" type="button" onclick="openPlayerDetails(${p.id})"><i class="bi bi-info-circle"></i> Detalhes</button>
									<a class="btn-trade-action" href="/trades.php?player=${p.id}&team=${p.team_id}" title="Propor trade por este jogador"><i class="bi bi-arrow-left-right"></i> Trocar</a>
								</td>
							</tr>
						`;
					}
				});
				if (mobile) {
					cardsWrap.style.display = 'grid';
				} else {
					tableWrap.style.display = 'block';
				}

				if (pagination.total > perPage) {
					paginationInfo.textContent = `Pagina ${pagination.page} de ${pagination.total_pages} - ${pagination.total} jogadores`;
					paginationWrap.style.display = 'flex';
					prevPageBtn.disabled = pagination.page <= 1;
					nextPageBtn.disabled = pagination.page >= pagination.total_pages;
				}
			}
		} catch (err) {
			emptyState.textContent = 'Erro ao carregar jogadores.';
			emptyState.style.display = 'block';
		} finally {
			loading.style.display = 'none';
		}
	}

	searchBtn.addEventListener('click', () => { currentPage = 1; carregarJogadores(); });
	searchInput.addEventListener('keydown', (e) => {
		if (e.key === 'Enter') { currentPage = 1; carregarJogadores(); }
	});
	positionFilter.addEventListener('change', () => { currentPage = 1; carregarJogadores(); });
	ovrMinInput.addEventListener('change', () => { currentPage = 1; carregarJogadores(); });
	ovrMaxInput.addEventListener('change', () => { currentPage = 1; carregarJogadores(); });
	ageMinInput.addEventListener('change', () => { currentPage = 1; carregarJogadores(); });
	ageMaxInput.addEventListener('change', () => { currentPage = 1; carregarJogadores(); });
	teamFilter.addEventListener('change', () => { currentPage = 1; carregarJogadores(); });
	prevPageBtn.addEventListener('click', () => {
		if (currentPage > 1) {
			currentPage -= 1;
			carregarJogadores();
		}
	});
	nextPageBtn.addEventListener('click', () => {
		if (currentPage < totalPages) {
			currentPage += 1;
			carregarJogadores();
		}
	});
	carregarJogadores();

	let lastIsMobile = isMobile();
	let resizeTimer;
	window.addEventListener('resize', () => {
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(() => {
			const currentIsMobile = isMobile();
			if (lastIsMobile !== currentIsMobile) {
				lastIsMobile = currentIsMobile;
				carregarJogadores();
			}
		}, 500);
	});

	const detailsModalEl = document.getElementById('playerDetailsModal');
	const detailsModal = detailsModalEl ? new bootstrap.Modal(detailsModalEl) : null;

	async function openPlayerDetails(playerId) {
		if (!detailsModalEl) return;
		const content = document.getElementById('playerDetailsContent');
		const title = document.getElementById('playerDetailsTitle');
		if (content) {
			content.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status" style="color: var(--red);"></div></div>';
		}
		if (title) title.textContent = 'Detalhes do Jogador';

		detailsModal?.show();

		try {
			const res = await fetch(`/api/team.php?action=player_details&player_id=${playerId}`);
			const data = await res.json();
			if (!data || data.error) {
				if (content) content.innerHTML = `<div class="alert alert-danger">${data.error || 'Erro ao carregar detalhes.'}</div>`;
				return;
			}

			const player = data.player || {};
			if (title) title.textContent = player.name || 'Detalhes do Jogador';

			const transfers = Array.isArray(data.transfers) ? data.transfers : [];
			const ovrTimeline = Array.isArray(data.ovr_timeline) ? data.ovr_timeline : [];

			const transferHtml = transfers.length
				? transfers.map((t) => `
					<div class="d-flex flex-column gap-1 border-bottom border-secondary py-2">
						<div>${t.year || '-'}: ${t.from_team} -> ${t.to_team}</div>
					</div>
				`).join('')
				: '<div class="text-light-gray">Nenhuma trade encontrada.</div>';

			const ovrHtml = ovrTimeline.length
				? ovrTimeline.map((o) => `
					<div class="d-flex justify-content-between border-bottom border-secondary py-2">
						<div>Idade ${o.age ?? '-'}</div>
						<div style="color: var(--red); font-weight: 700;">OVR ${o.ovr ?? '-'}</div>
					</div>
				`).join('')
				: '<div class="text-light-gray">Sem historico de OVR registrado.</div>';

			if (content) {
				content.innerHTML = `
					<div class="mb-3">
						<div class="text-light-gray">Time atual</div>
						<div style="font-weight:700;">${player.team_name || '-'}</div>
					</div>
					<div class="row g-3 mb-3">
						<div class="col-6 col-md-3">
							<div class="card-mini text-center">
								<div class="text-light-gray small">OVR</div>
								<div style="color: var(--red); font-weight: 700;">${player.ovr ?? '-'}</div>
							</div>
						</div>
						<div class="col-6 col-md-3">
							<div class="card-mini text-center">
								<div class="text-light-gray small">Idade</div>
								<div style="font-weight: 700;">${player.age ?? '-'}</div>
							</div>
						</div>
						<div class="col-6 col-md-3">
							<div class="card-mini text-center">
								<div class="text-light-gray small">Posicao</div>
								<div style="font-weight: 700;">${player.position ?? '-'}</div>
							</div>
						</div>
						<div class="col-6 col-md-3">
							<div class="card-mini text-center">
								<div class="text-light-gray small">Pos. Sec.</div>
								<div style="font-weight: 700;">${player.secondary_position || '-'}</div>
							</div>
						</div>
					</div>

					<div class="mb-3">
						<h6>Transferencias</h6>
						${transferHtml}
					</div>
					<div class="mb-3">
						<h6>OVR por idade</h6>
						${ovrHtml}
					</div>
				`;
			}
		} catch (err) {
			if (content) content.innerHTML = '<div class="alert alert-danger">Erro ao carregar detalhes.</div>';
		}
	}
</script>
</body>
</html>
