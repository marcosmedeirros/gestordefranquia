<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

$currentSeasonYear = null;
try {
    $stmtSeason = $pdo->prepare('
        SELECT s.season_number, s.year, sp.start_year
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN (\'completed\'))
        ORDER BY s.created_at DESC LIMIT 1
    ');
    $stmtSeason->execute([$user['league']]);
    $season = $stmtSeason->fetch(PDO::FETCH_ASSOC);
    if ($season) {
        $currentSeasonYear = isset($season['start_year'], $season['season_number'])
            ? (int)$season['start_year'] + (int)$season['season_number'] - 1
            : (int)($season['year'] ?? date('Y'));
    }
} catch (Exception $e) {}
$currentSeasonYear = $currentSeasonYear ?: (int)date('Y');

$stmtTeam = $pdo->prepare('SELECT t.*, COUNT(p.id) as player_count FROM teams t LEFT JOIN players p ON p.team_id = t.id WHERE t.user_id = ? GROUP BY t.id ORDER BY player_count DESC, t.id DESC');
$stmtTeam->execute([$user['id']]);
$myTeam = $stmtTeam->fetch(PDO::FETCH_ASSOC) ?: null;

$stmtTeams = $pdo->prepare('SELECT id, city, name FROM teams WHERE league = ? ORDER BY city ASC, name ASC');
$stmtTeams->execute([$user['league']]);
$leagueTeams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC) ?: [];

$is_admin = hasAdminAccess($pdo, (int)$user['id']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Mercado de Trades - FBA Manager</title>

    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0a0a0c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="/img/icon-192.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css?v=20260411">

    <style>
        :root {
            --red: #fc0025; --red-soft: rgba(252,0,37,.10); --red-glow: rgba(252,0,37,.18);
            --bg: #07070a; --panel: #101013; --panel-2: #16161a; --panel-3: #1c1c21;
            --border: rgba(255,255,255,.06); --border-md: rgba(255,255,255,.10); --border-red: rgba(252,0,37,.22);
            --text: #f0f0f3; --text-2: #868690; --text-3: #48484f;
            --sidebar-w: 260px; --font: 'Poppins', sans-serif; --radius: 14px; --radius-sm: 10px;
            --ease: cubic-bezier(.2,.8,.2,1); --t: 200ms;
        }
        :root[data-theme="light"] {
            --bg: #f6f7fb; --panel: #ffffff; --panel-2: #f2f4f8; --panel-3: #e9edf4;
            --border: #e3e6ee; --border-md: #d7dbe6; --border-red: rgba(252,0,37,.18);
            --text: #111217; --text-2: #5b6270; --text-3: #8b93a5;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { -webkit-text-size-adjust: 100%; }
        html, body { height: 100%; background: var(--bg); color: var(--text); font-family: var(--font); -webkit-font-smoothing: antialiased; }
        body { overflow-x: hidden; }
        a, button { -webkit-tap-highlight-color: transparent; }

        .app { display: flex; min-height: 100vh; }
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
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
        .sb-nav a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
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

        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 240; }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
        .sb-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; display: none; }
        .sb-overlay.show { display: block; }

        .main { margin-left: var(--sidebar-w); width: calc(100% - var(--sidebar-w)); padding: 32px 40px 60px; }
        .page-top { display: flex; align-items: flex-end; justify-content: space-between; gap: 18px; margin-bottom: 22px; flex-wrap: wrap; }
        .page-eyebrow { font-size: 12px; letter-spacing: .2em; text-transform: uppercase; color: var(--text-3); margin-bottom: 8px; }
        .page-title { font-size: 32px; font-family: var(--font); margin-bottom: 6px; }
        .page-sub { color: var(--text); font-size: 14px; }

        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 22px; }
        .panel + .panel { margin-top: 16px; }
        .panel-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
        .panel-title { font-family: var(--font); font-size: 16px; font-weight: 600; }

        .field { display: flex; flex-direction: column; gap: 6px; }
        .field label { font-size: 11px; color: var(--text-2); letter-spacing: .1em; text-transform: uppercase; font-weight: 600; }
        .field input, .field select { background: var(--panel-2); border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; color: var(--text); font-size: 14px; font-family: var(--font); transition: border-color var(--t) var(--ease); }
        .field input:focus, .field select:focus { outline: none; border-color: var(--border-red); box-shadow: 0 0 0 3px var(--red-soft); }
        .field input::placeholder { color: var(--text-3); }
        .field select option { background: var(--panel-2); }

        .btn-red { background: var(--red); color: #fff; border: none; border-radius: 10px; padding: 9px 18px; font-weight: 700; font-size: 13px; font-family: var(--font); cursor: pointer; transition: transform var(--t) var(--ease), box-shadow var(--t) var(--ease); display: inline-flex; align-items: center; gap: 6px; }
        .btn-red:hover { transform: translateY(-1px); box-shadow: 0 10px 20px var(--red-glow); }
        .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text-2); border-radius: 10px; padding: 8px 14px; font-size: 13px; font-weight: 500; font-family: var(--font); cursor: pointer; transition: all var(--t) var(--ease); display: inline-flex; align-items: center; gap: 6px; }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); }

        /* Market cards grid */
        .market-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
        .market-card { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px; display: flex; flex-direction: column; gap: 12px; transition: border-color var(--t) var(--ease), transform var(--t) var(--ease); }
        .market-card:hover { border-color: var(--border-red); transform: translateY(-2px); }
        .mc-header { display: flex; align-items: center; gap: 10px; }
        .mc-photo { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-red); background: var(--panel-3); flex-shrink: 0; }
        .mc-name { font-weight: 700; font-size: 14px; line-height: 1.2; }
        .mc-team { font-size: 11px; color: var(--text-2); margin-top: 2px; }
        .mc-ovr { font-size: 22px; font-weight: 900; color: var(--red); line-height: 1; margin-left: auto; flex-shrink: 0; }
        .mc-stats { display: flex; gap: 10px; flex-wrap: wrap; }
        .mc-stat { display: flex; flex-direction: column; align-items: center; background: var(--panel-3); border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; flex: 1; min-width: 50px; }
        .mc-stat-val { font-weight: 700; font-size: 13px; }
        .mc-stat-label { font-size: 9px; color: var(--text-3); text-transform: uppercase; letter-spacing: .6px; font-weight: 600; margin-top: 1px; }
        .mc-tag { display: inline-flex; align-items: center; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; }
        .mc-footer { display: flex; gap: 8px; }
        .mc-footer .btn-red { flex: 1; justify-content: center; }

        .badge-ovr { display: inline-flex; align-items: center; justify-content: center; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .ovr-high { background: rgba(34,197,94,.18); color: #22c55e; }
        .ovr-mid { background: rgba(59,130,246,.18); color: #3b82f6; }
        .ovr-low { background: rgba(245,158,11,.18); color: #f59e0b; }
        .ovr-base { background: rgba(130,130,138,.18); color: #9aa0ac; }

        /* Filter bar */
        .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px; }
        .filter-bar .field { flex: 1; min-width: 140px; }
        .filter-bar .field input, .filter-bar .field select { width: 100%; }

        #market-loading { text-align: center; padding: 48px; }
        #market-empty { text-align: center; padding: 48px; color: var(--text-2); font-size: 14px; display: none; }
        #market-count { font-size: 12px; color: var(--text-2); margin-bottom: 14px; }

        /* Mobile list */
        .mpl-item { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--border); }
        .mpl-item:last-child { border-bottom: none; }
        .mpl-main { flex: 1; min-width: 0; }
        .mpl-name { font-weight: 600; font-size: 14px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .mpl-meta { font-size: 12px; color: var(--text-2); margin-top: 2px; }
        .mpl-right { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex-shrink: 0; }
        .mpl-actions { display: flex; gap: 5px; }
        .mpl-btn { padding: 5px 10px; border-radius: 8px; font-size: 11px; font-weight: 600; border: 1px solid var(--border); background: var(--panel-2); color: var(--text-2); text-decoration: none; cursor: pointer; white-space: nowrap; }
        .mpl-btn.trade { background: var(--red); color: #fff; border-color: var(--red); }

        .modal-content { background: var(--panel) !important; border: 1px solid var(--border) !important; border-radius: var(--radius) !important; color: var(--text); }
        .modal-header { border-bottom: 1px solid var(--border) !important; padding: 16px 20px; }
        .modal-footer { border-top: 1px solid var(--border) !important; padding: 14px 20px; }
        .modal-title { font-size: 16px; font-weight: 600; font-family: var(--font); color: var(--text); }
        .modal-body { padding: 20px; }
        .btn-close-white { filter: invert(1); }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .topbar { display: flex; }
            .main { margin-left: 0; width: 100%; padding: 54px 16px 40px; }
            .market-grid { display: block; }
        }
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar" id="sidebar">
        <div class="sb-brand">
            <div class="sb-logo">FBA</div>
            <div class="sb-brand-text">FBA Manager <span>Liga <?= htmlspecialchars($user['league']) ?></span></div>
        </div>
        <?php if ($myTeam): ?>
        <div class="sb-team">
            <img src="<?= htmlspecialchars($myTeam['photo_url'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
            <div>
                <div class="sb-team-name"><?= htmlspecialchars(trim(($myTeam['city'] ?? '') . ' ' . ($myTeam['name'] ?? ''))) ?></div>
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
            <a href="/mercado.php" class="active"><i class="bi bi-shop"></i> Mercado</a>
            <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
            <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
            <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>
            <a href="/tapas.php"><i class="bi bi-hand-index-thumb"></i> Tapas</a>
            <div class="sb-section">Liga</div>
            <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
            <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
            <a href="/hall-da-fama.php"><i class="bi bi-award-fill"></i> Hall da Fama</a>
            <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
            <a href="/mundo-fba.php"><i class="bi bi-globe2"></i> Mundo FBA</a>
            <a href="/estatisticas.php"><i class="bi bi-bar-chart-line-fill"></i> Estatísticas</a>
            <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
            <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>
            <a href="/thepathetic.php"><i class="bi bi-newspaper"></i> The Pathetic</a>
            <?php if ($is_admin): ?>
            <div class="sb-section">Admin</div>
            <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
            <?php endif; ?>
            <div class="sb-section">Conta</div>
            <a href="/settings.php"><i class="bi bi-gear-fill"></i> Minha Conta</a>
        </nav>
        <button class="sb-theme-toggle" type="button" id="themeToggle">
            <i class="bi bi-moon"></i><span>Modo escuro</span>
        </button>
        <div class="sb-footer">
            <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>" alt="" class="sb-avatar" onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name']) ?>&background=1c1c21&color=fc0025'">
            <span class="sb-username"><?= htmlspecialchars($user['name']) ?></span>
            <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </aside>

    <div class="sb-overlay" id="sbOverlay"></div>

    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Manager</em></div>
        <span style="font-size:11px;font-weight:700;color:var(--red)"><?= $currentSeasonYear ?></span>
    </header>

    <main class="main">
        <div class="page-top">
            <div>
                <div class="page-eyebrow">Liga &mdash; <?= $currentSeasonYear ?></div>
                <h1 class="page-title">Mercado de Trades</h1>
                <p class="page-sub">Jogadores disponíveis e feed da liga</p>
            </div>
        </div>

        <!-- Tabs nav -->
        <ul class="nav-tabs-mkt" id="mktTabNav" style="display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:20px;list-style:none;padding:0">
            <li>
                <button class="mkt-tab active" data-tab="jogadores" style="background:none;border:none;border-bottom:2px solid var(--red);color:var(--red);font-weight:600;font-size:13px;padding:10px 18px;cursor:pointer;font-family:var(--font)">
                    <i class="bi bi-people-fill me-1"></i>Jogadores
                </button>
            </li>
            <li>
                <button class="mkt-tab" data-tab="feed" style="background:none;border:none;border-bottom:2px solid transparent;color:var(--text-2);font-weight:500;font-size:13px;padding:10px 18px;cursor:pointer;font-family:var(--font)">
                    <i class="bi bi-chat-text-fill me-1"></i>Rumor
                </button>
            </li>
        </ul>

        <!-- Tab: Jogadores -->
        <div id="tab-jogadores">
        <div class="panel">
            <div class="filter-bar">
                <div class="field">
                    <label>Buscar</label>
                    <input type="text" id="mktSearch" placeholder="Nome do jogador…">
                </div>
                <div class="field" style="max-width:150px">
                    <label>Posição</label>
                    <select id="mktPosition">
                        <option value="">Todas</option>
                        <option value="PG">PG</option>
                        <option value="SG">SG</option>
                        <option value="SF">SF</option>
                        <option value="PF">PF</option>
                        <option value="C">C</option>
                    </select>
                </div>
                <div class="field" style="max-width:200px">
                    <label>Time</label>
                    <select id="mktTeam">
                        <option value="">Todos</option>
                        <?php foreach ($leagueTeams as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['city'] . ' ' . $t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="max-width:100px">
                    <label>OVR mín.</label>
                    <input type="number" id="mktOvrMin" min="40" max="99" placeholder="—">
                </div>
                <div class="field" style="max-width:90px">
                    <label>Idade máx.</label>
                    <input type="number" id="mktAgeMax" min="18" max="45" placeholder="—">
                </div>
            </div>

            <div id="market-count"></div>

            <div id="market-loading">
                <div class="spinner-border" role="status" style="width:2rem;height:2rem;color:var(--red)"></div>
                <p style="color:var(--text-2);font-size:13px;margin-top:12px">Carregando mercado…</p>
            </div>

            <div id="market-grid" class="market-grid" style="display:none"></div>
            <div id="market-list" style="display:none"></div>
            <div id="market-empty">Nenhum jogador disponível para trade no momento.</div>
        </div>
        </div>

        <!-- Tab: Feed -->
        <div id="tab-feed" style="display:none">
        <div class="panel">
            <div class="panel-header" style="margin-bottom:16px">
                <span class="panel-title"><i class="bi bi-chat-text-fill me-2" style="color:var(--red)"></i>Rumores</span>
                <span id="feed-count" style="font-size:12px;color:var(--text-2)"></span>
            </div>

            <!-- Post composer -->
            <div style="background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:20px">
                <textarea id="feedComposer" rows="2" placeholder="Compartilhe um rumor, procure jogadores ou escreva para a liga…"
                    style="width:100%;background:transparent;border:none;color:var(--text);font-family:var(--font);font-size:14px;resize:vertical;outline:none;min-height:60px"></textarea>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px">
                    <span id="feedCharCount" style="font-size:11px;color:var(--text-3)">0 / 500</span>
                    <button class="btn-red" id="feedPostBtn" style="padding:7px 18px;font-size:13px">
                        <i class="bi bi-send-fill me-1"></i>Publicar
                    </button>
                </div>
            </div>

            <!-- Posts list -->
            <div id="feed-loading" style="text-align:center;padding:32px">
                <div class="spinner-border" role="status" style="width:1.5rem;height:1.5rem;color:var(--red)"></div>
            </div>
            <div id="feed-list"></div>
            <div id="feed-empty" style="display:none;text-align:center;padding:32px;color:var(--text-2);font-size:13px">
                <i class="bi bi-chat-dots" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4"></i>
                Nenhuma mensagem ainda. Seja o primeiro a postar!
            </div>
        </div>
        </div>
    </main>
</div>

<!-- Modal: Detalhes do Jogador -->
<div class="modal fade" id="playerDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="playerDetailsTitle">Detalhes</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="playerDetailsContent"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/pwa.js"></script>
<script>
(function () {
    const MY_TEAM_ID = <?= $myTeam ? (int)$myTeam['id'] : 'null' ?>;

    /* ── Theme ─────────────────────────── */
    const themeKey = 'fba-theme';
    const root = document.documentElement;
    const savedTheme = localStorage.getItem(themeKey) || (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    root.dataset.theme = savedTheme;
    const themeToggle = document.getElementById('themeToggle');
    const updateToggle = t => { if (!themeToggle) return; themeToggle.innerHTML = t === 'light' ? '<i class="bi bi-moon-stars-fill"></i><span>Tema escuro</span>' : '<i class="bi bi-sun-fill"></i><span>Tema claro</span>'; };
    updateToggle(savedTheme);
    themeToggle?.addEventListener('click', () => { const n = root.dataset.theme === 'light' ? 'dark' : 'light'; root.dataset.theme = n; localStorage.setItem(themeKey, n); updateToggle(n); });

    /* ── Sidebar ───────────────────────── */
    const sidebar = document.getElementById('sidebar');
    const sbOverlay = document.getElementById('sbOverlay');
    document.getElementById('menuBtn')?.addEventListener('click', () => { sidebar.classList.toggle('open'); sbOverlay.classList.toggle('show'); });
    sbOverlay?.addEventListener('click', () => { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); });

    /* ── Helpers ───────────────────────── */
    const isMobile = () => window.matchMedia('(max-width: 992px)').matches;

    function getOvrClass(ovr) {
        if (ovr >= 85) return 'ovr-high';
        if (ovr >= 78) return 'ovr-mid';
        if (ovr >= 72) return 'ovr-low';
        return 'ovr-base';
    }

    function getPhotoUrl(p) {
        if ((p.foto_adicional || '').trim()) return p.foto_adicional.trim();
        return p.nba_player_id
            ? `https://cdn.nba.com/headshots/nba/latest/1040x760/${p.nba_player_id}.png`
            : `https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=fc0025&rounded=true&bold=true`;
    }

    function tradeUrl(p) {
        return `/trades.php?player=${p.id}&team=${p.team_id}`;
    }

    function renderCard(p) {
        const ovr = Number(p.ovr || 0);
        const photo = getPhotoUrl(p);
        const teamName = `${p.city || ''} ${p.team_name || ''}`.trim();
        const isMyTeam = MY_TEAM_ID && (int(p.team_id) === int(MY_TEAM_ID));
        const deltaHtml = p.ovr_delta > 0
            ? `<span style="font-size:10px;color:#22c55e;font-weight:700;margin-left:4px">+${p.ovr_delta}</span>`
            : p.ovr_delta < 0
                ? `<span style="font-size:10px;color:#ef4444;font-weight:700;margin-left:4px">${p.ovr_delta}</span>` : '';
        const tagHtml = p.player_tag ? `<span class="mc-tag" style="border:1px solid ${(p.player_tag_color||'#3b82f6')}55;background:${(p.player_tag_color||'#3b82f6')}18;color:${p.player_tag_color||'#3b82f6'}">${p.player_tag}</span>` : '';

        return `<div class="market-card">
            <div class="mc-header">
                <img class="mc-photo" src="${photo}" alt="${p.name}" onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=fc0025&rounded=true&bold=true'">
                <div style="flex:1;min-width:0">
                    <div class="mc-name">${p.name} ${tagHtml}</div>
                    <div class="mc-team">${teamName}</div>
                </div>
                <div style="text-align:right;flex-shrink:0">
                    <span class="badge-ovr ${getOvrClass(ovr)}">${ovr}</span>${deltaHtml}
                </div>
            </div>
            <div class="mc-stats">
                <div class="mc-stat"><span class="mc-stat-val">${p.position || '—'}</span><span class="mc-stat-label">POS</span></div>
                <div class="mc-stat"><span class="mc-stat-val">${p.age || '—'}</span><span class="mc-stat-label">IDADE</span></div>
                ${p.secondary_position ? `<div class="mc-stat"><span class="mc-stat-val">${p.secondary_position}</span><span class="mc-stat-label">SEC</span></div>` : ''}
            </div>
            <div class="mc-footer">
                <button class="btn-ghost" style="flex:0 0 auto" onclick="openDetails(${p.id})"><i class="bi bi-info-circle"></i></button>
                ${!isMyTeam ? `<a class="btn-red" href="${tradeUrl(p)}" style="flex:1;justify-content:center"><i class="bi bi-arrow-left-right"></i> Propor Trade</a>` : `<span style="flex:1;text-align:center;font-size:12px;color:var(--text-2);align-self:center">Seu time</span>`}
            </div>
        </div>`;
    }

    function int(v) { return parseInt(v, 10); }

    function renderListItem(p) {
        const ovr = Number(p.ovr || 0);
        const teamName = `${p.city || ''} ${p.team_name || ''}`.trim();
        const isMyTeam = MY_TEAM_ID && (int(p.team_id) === int(MY_TEAM_ID));
        return `<div class="mpl-item">
            <div class="mpl-main">
                <div class="mpl-name">${p.name}</div>
                <div class="mpl-meta">${p.position || '—'} · ${p.age || '—'}a · ${teamName}</div>
            </div>
            <div class="mpl-right">
                <span class="badge-ovr ${getOvrClass(ovr)}">${ovr}</span>
                <div class="mpl-actions">
                    <button class="mpl-btn" onclick="openDetails(${p.id})"><i class="bi bi-info-circle"></i></button>
                    ${!isMyTeam ? `<a class="mpl-btn trade" href="${tradeUrl(p)}"><i class="bi bi-arrow-left-right"></i> Trade</a>` : '<span class="mpl-btn" style="opacity:.5">Seu time</span>'}
                </div>
            </div>
        </div>`;
    }

    let _debounceTimer;
    function scheduleLoad() { clearTimeout(_debounceTimer); _debounceTimer = setTimeout(load, 280); }

    async function load() {
        const search  = document.getElementById('mktSearch').value.trim();
        const pos     = document.getElementById('mktPosition').value;
        const teamId  = document.getElementById('mktTeam').value;
        const ovrMin  = document.getElementById('mktOvrMin').value;

        document.getElementById('market-loading').style.display = '';
        document.getElementById('market-grid').style.display  = 'none';
        document.getElementById('market-list').style.display  = 'none';
        document.getElementById('market-empty').style.display = 'none';
        document.getElementById('market-count').textContent   = '';

        const ageMax  = document.getElementById('mktAgeMax').value;

        const params = new URLSearchParams({ action: 'list_players', available_for_trade: '1', per_page: '200' });
        if (search)  params.set('query', search);
        if (pos)     params.set('position', pos);
        if (teamId)  params.set('team_id', teamId);
        if (ovrMin)  params.set('ovr_min', ovrMin);
        if (ageMax)  params.set('age_max', ageMax);

        try {
            const res  = await fetch(`/api/team.php?${params}`);
            const data = await res.json();
            const players = data.players || [];

            document.getElementById('market-loading').style.display = 'none';

            if (!players.length) {
                document.getElementById('market-empty').style.display = '';
                return;
            }

            document.getElementById('market-count').textContent = `${players.length} jogador${players.length !== 1 ? 'es' : ''} disponível${players.length !== 1 ? 'is' : ''} para trade`;

            if (isMobile()) {
                const list = document.getElementById('market-list');
                list.innerHTML = players.map(renderListItem).join('');
                list.style.display = '';
            } else {
                const grid = document.getElementById('market-grid');
                grid.innerHTML = players.map(renderCard).join('');
                grid.style.display = '';
            }
        } catch {
            document.getElementById('market-loading').style.display = 'none';
            document.getElementById('market-empty').style.display = '';
            document.getElementById('market-empty').textContent = 'Erro ao carregar o mercado.';
        }
    }

    document.getElementById('mktSearch').addEventListener('input', scheduleLoad);
    document.getElementById('mktPosition').addEventListener('change', load);
    document.getElementById('mktTeam').addEventListener('change', load);
    document.getElementById('mktOvrMin').addEventListener('input', scheduleLoad);
    document.getElementById('mktAgeMax').addEventListener('input', scheduleLoad);

    let _resizeTimer;
    window.addEventListener('resize', () => { clearTimeout(_resizeTimer); _resizeTimer = setTimeout(load, 400); });

    /* ── Tabs ──────────────────────────── */
    const MY_USER_ID = <?= (int)$user['id'] ?>;
    const IS_ADMIN   = <?= $is_admin ? 'true' : 'false' ?>;
    document.querySelectorAll('.mkt-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.mkt-tab').forEach(b => {
                b.style.borderBottomColor = 'transparent';
                b.style.color = 'var(--text-2)';
                b.style.fontWeight = '500';
            });
            btn.style.borderBottomColor = 'var(--red)';
            btn.style.color = 'var(--red)';
            btn.style.fontWeight = '600';

            const tab = btn.dataset.tab;
            document.getElementById('tab-jogadores').style.display = tab === 'jogadores' ? '' : 'none';
            document.getElementById('tab-feed').style.display      = tab === 'feed'      ? '' : 'none';

            if (tab === 'feed') { loadFeed(); }
        });
    });

    /* ── Feed ──────────────────────────── */
    const composer  = document.getElementById('feedComposer');
    const charCount = document.getElementById('feedCharCount');
    composer?.addEventListener('input', () => {
        const n = composer.value.length;
        charCount.textContent = `${n} / 500`;
        charCount.style.color = n > 480 ? 'var(--red)' : 'var(--text-3)';
    });

    document.getElementById('feedPostBtn')?.addEventListener('click', async () => {
        const content = composer.value.trim();
        if (!content) return;
        const btn = document.getElementById('feedPostBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';
        try {
            const res  = await fetch('/api/market.php?action=feed_post', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content })
            });
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || 'Erro');
            composer.value = '';
            charCount.textContent = '0 / 500';
            if (data.post) prependFeedPost(data.post);
            document.getElementById('feed-empty').style.display = 'none';
            const countEl = document.getElementById('feed-count');
            if (countEl) {
                const cur = parseInt(countEl.textContent) || 0;
                countEl.textContent = `${cur + 1} mensagens`;
            }
        } catch (e) { alert(e.message || 'Erro ao publicar'); }
        finally { btn.disabled = false; btn.innerHTML = '<i class="bi bi-send-fill me-1"></i>Publicar'; }
    });

    function getUserPhoto(url) {
        return url && url.trim() ? url.trim() : null;
    }

    function formatDate(str) {
        if (!str) return '';
        const d = new Date(str.replace(' ', 'T'));
        if (isNaN(d)) return str;
        const now = new Date();
        const diff = Math.floor((now - d) / 1000);
        if (diff < 60)    return 'agora mesmo';
        if (diff < 3600)  return `${Math.floor(diff/60)}min atrás`;
        if (diff < 86400) return `${Math.floor(diff/3600)}h atrás`;
        if (diff < 604800) return `${Math.floor(diff/86400)}d atrás`;
        return d.toLocaleDateString('pt-BR');
    }

    function renderFeedPost(post) {
        const teamLabel = post.team_city || post.team_name
            ? `${post.team_city || ''} ${post.team_name || ''}`.trim()
            : null;
        const photoUrl = getUserPhoto(post.user_photo);
        const avatarHtml = photoUrl
            ? `<img src="${photoUrl}" alt="" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0" onerror="this.style.display='none';this.nextSibling.style.display='flex'">`
            : '';
        const initials = (post.user_name || 'U').charAt(0).toUpperCase();
        const canDelete = IS_ADMIN || (int(post.user_id) === MY_USER_ID);
        return `<div class="feed-post" id="fpost-${post.id}" style="display:flex;gap:10px;padding:14px 0;border-bottom:1px solid var(--border)">
            <div style="flex-shrink:0;position:relative">
                ${avatarHtml}
                <div style="width:38px;height:38px;border-radius:50%;background:var(--panel-3);border:1px solid var(--border-md);display:${photoUrl ? 'none' : 'flex'};align-items:center;justify-content:center;font-weight:700;font-size:14px;color:var(--red)">${initials}</div>
            </div>
            <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:4px">
                    <span style="font-weight:600;font-size:13px;color:var(--text)">${post.user_name || 'Usuário'}</span>
                    ${teamLabel ? `<span style="font-size:11px;color:var(--red);font-weight:600">${teamLabel}</span>` : ''}
                    <span style="font-size:11px;color:var(--text-3)">${formatDate(post.created_at)}</span>
                </div>
                <div style="font-size:14px;color:var(--text);line-height:1.5;white-space:pre-wrap;word-break:break-word">${escHtml(post.content)}</div>
            </div>
            ${canDelete ? `<button onclick="deleteFeedPost(${post.id})" title="Apagar" style="flex-shrink:0;background:none;border:none;cursor:pointer;color:var(--text-3);font-size:14px;padding:4px 6px;border-radius:6px;transition:color .15s" onmouseover="this.style.color='var(--red)'" onmouseout="this.style.color='var(--text-3)'"><i class="bi bi-trash"></i></button>` : ''}
        </div>`;
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function prependFeedPost(post) {
        const list = document.getElementById('feed-list');
        list.insertAdjacentHTML('afterbegin', renderFeedPost(post));
    }

    async function loadFeed() {
        const loading = document.getElementById('feed-loading');
        const list    = document.getElementById('feed-list');
        const empty   = document.getElementById('feed-empty');
        loading.style.display = '';
        list.innerHTML = '';
        empty.style.display = 'none';
        try {
            const res  = await fetch('/api/market.php?action=feed_posts&limit=50');
            const data = await res.json();
            const posts = data.posts || [];
            loading.style.display = 'none';
            const countEl = document.getElementById('feed-count');
            if (countEl) countEl.textContent = posts.length ? `${posts.length} mensagens` : '';
            if (!posts.length) { empty.style.display = ''; return; }
            list.innerHTML = posts.map(renderFeedPost).join('');
        } catch {
            loading.style.display = 'none';
            empty.style.display = '';
            empty.textContent = 'Erro ao carregar o feed.';
        }
    }

    window.deleteFeedPost = async function(postId) {
        if (!confirm('Apagar esta mensagem?')) return;
        try {
            const res  = await fetch('/api/market.php?action=feed_delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ post_id: postId })
            });
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || 'Erro');
            document.getElementById(`fpost-${postId}`)?.remove();
        } catch (e) { alert(e.message || 'Erro ao apagar'); }
    };

    load();

    /* ── Player details modal ──────────── */
    const detailsModalEl = document.getElementById('playerDetailsModal');
    const detailsModal = detailsModalEl ? new bootstrap.Modal(detailsModalEl) : null;

    window.openDetails = async function(playerId) {
        const content = document.getElementById('playerDetailsContent');
        const titleEl = document.getElementById('playerDetailsTitle');
        if (content) content.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:48px"><div class="spinner-border" style="color:var(--red);width:2rem;height:2rem;"></div></div>';
        if (titleEl) titleEl.textContent = 'Detalhes';
        detailsModal?.show();
        try {
            const res  = await fetch(`/api/team.php?action=player_details&player_id=${playerId}`);
            const data = await res.json();
            const player = data.player || {};
            if (titleEl) titleEl.textContent = player.name || 'Detalhes';
            const transfers = Array.isArray(data.transfers) ? data.transfers : [];
            const seasonLog = Array.isArray(data.season_log) ? data.season_log : [];

            const seasonLogHtml = seasonLog.length
                ? seasonLog.map((s, si) => {
                    const label = (s.season_number ? `Temp ${s.season_number}` : '') + (s.year ? ` · ${s.year}` : '') || `Temporada ${si+1}`;
                    const d = si > 0 ? ((parseInt(s.ovr)||0) - (parseInt(seasonLog[si-1].ovr)||0)) : 0;
                    const dHtml = d > 0 && si > 0 ? `<span style="font-size:10px;color:#22c55e;font-weight:700;margin-left:6px">+${d}</span>` : d < 0 && si > 0 ? `<span style="font-size:10px;color:#ef4444;font-weight:700;margin-left:6px">${d}</span>` : '';
                    return `<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border)"><div><div style="font-size:12px;font-weight:600">${label}</div><div style="font-size:11px;color:var(--text-2)">${s.team_name||'-'} · ${s.age??'-'}a</div></div><div style="display:flex;align-items:center"><span style="color:var(--red);font-weight:800;font-size:15px">${s.ovr??'-'}</span>${dHtml}</div></div>`;
                }).join('')
                : '<div style="font-size:13px;color:var(--text-3);padding:8px 0">Nenhum snapshot registrado.</div>';

            const transferHtml = transfers.length
                ? transfers.map(t => `<div style="padding:8px 0;border-bottom:1px solid var(--border)"><div style="font-size:12px;font-weight:600">${t.from_team} <span style="color:var(--text-3)">→</span> ${t.to_team}</div>${t.year ? `<div style="font-size:11px;color:var(--text-3)">${t.year}</div>` : ''}</div>`).join('')
                : '<div style="font-size:13px;color:var(--text-3);padding:8px 0">Nenhuma trade encontrada.</div>';

            const photo = getPhotoUrl(player);
            const isMyTeam = MY_TEAM_ID && (int(player.team_id) === int(MY_TEAM_ID));
            if (content) content.innerHTML = `
                <div style="background:var(--panel-2);padding:20px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:16px">
                    <img src="${photo}" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid var(--border-red);flex-shrink:0;background:var(--panel-3)" onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(player.name||'P')}&background=121212&color=fc0025&rounded=true&bold=true'">
                    <div style="flex:1;min-width:0">
                        <div style="font-size:18px;font-weight:800;line-height:1.2">${player.name||'-'}</div>
                        <div style="font-size:12px;color:var(--text-2);margin-top:2px">${player.team_name||'-'}</div>
                        <div style="font-size:30px;font-weight:900;color:var(--red);line-height:1;margin-top:4px">${player.ovr??'-'}</div>
                    </div>
                    ${!isMyTeam ? `<a href="${tradeUrl(player)}" class="btn-red" style="flex-shrink:0"><i class="bi bi-arrow-left-right"></i> Propor Trade</a>` : ''}
                </div>
                <div style="padding:16px 22px">
                    <div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:10px">Evolução</div>
                    ${seasonLogHtml}
                </div>
                <div style="padding:0 22px 22px">
                    <div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:10px">Transferências</div>
                    ${transferHtml}
                </div>`;
        } catch {
            if (content) content.innerHTML = '<div style="padding:20px;color:var(--red)">Erro ao carregar detalhes.</div>';
        }
    };
})();
</script>
</body>
</html>
