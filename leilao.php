<?php
session_start();
require_once 'backend/config.php';
require_once 'backend/db.php';
$pdo = db();
require_once 'backend/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? (($_SESSION['user_type'] ?? '') === 'admin');
$team_id = $_SESSION['team_id'] ?? null;
$league_id = $_SESSION['current_league_id'] ?? null;

if (!$team_id) {
    $select = ['id', 'league', 'name'];
    try {
        $stmtCheck = $pdo->prepare("SHOW COLUMNS FROM teams LIKE 'league_id'");
        $stmtCheck->execute();
        if ($stmtCheck->fetch()) {
            $select[] = 'league_id';
        }
    } catch (Exception $e) {
        // ignore
    }

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM teams WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $teamRow = $stmt->fetch();
    if ($teamRow) {
        $team_id = (int)$teamRow['id'];
        if (!$league_id && !empty($teamRow['league_id'])) {
            $league_id = (int)$teamRow['league_id'];
        } elseif (!$league_id && !empty($teamRow['league'])) {
            $stmtLeague = $pdo->prepare('SELECT id FROM leagues WHERE name = ? LIMIT 1');
            $stmtLeague->execute([$teamRow['league']]);
            $leagueRow = $stmtLeague->fetch();
            if ($leagueRow && !empty($leagueRow['id'])) {
                $league_id = (int)$leagueRow['id'];
            }
        }
    }
}

$team_name = '';
$team_sidebar = [];
if ($team_id) {
    $stmt = $pdo->prepare("SELECT id, name, city, photo_url, league FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team_sidebar = $stmt->fetch() ?: [];
    $team_name = $team_sidebar['name'] ?? '';
}

// Dados do usuário para a sidebar
$stmt = $pdo->prepare("SELECT id, name, photo_url, league, user_type FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch() ?: [];
$user['user_type'] = $user['user_type'] ?? ($_SESSION['user_type'] ?? 'jogador');

$leagues = [];
if ($is_admin) {
    $stmt = $pdo->query("SELECT id, name FROM leagues ORDER BY name");
    $leagues = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Leilão — FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
    <style>
        :root {
            --red: #fc0025; --red-2: #ff2a44; --red-soft: rgba(252,0,37,.10); --red-glow: rgba(252,0,37,.18);
            --bg: #07070a; --panel: #101013; --panel-2: #16161a; --panel-3: #1c1c21;
            --border: rgba(255,255,255,.06); --border-md: rgba(255,255,255,.10); --border-red: rgba(252,0,37,.22);
            --text: #f0f0f3; --text-2: #868690; --text-3: #48484f;
            --green: #22c55e; --amber: #f59e0b; --blue: #3b82f6;
            --sidebar-w: 260px; --font: 'Poppins', sans-serif;
            --radius: 14px; --radius-sm: 10px; --radius-xs: 6px;
            --ease: cubic-bezier(.2,.8,.2,1); --t: 200ms;
        }
        :root[data-theme="light"] {
            --bg: #f6f7fb; --panel: #ffffff; --panel-2: #f2f4f8; --panel-3: #e9edf4;
            --border: #e3e6ee; --border-md: #d7dbe6; --border-red: rgba(252,0,37,.18);
            --text: #111217; --text-2: #5b6270; --text-3: #8b93a5;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }
        .app { display: flex; min-height: 100vh; }
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }
        .page-hero { padding: 32px 32px 0; }
        .hero-eyebrow { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: var(--red); margin-bottom: 6px; }
        .hero-title { font-size: 26px; font-weight: 800; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 10px; }
        .hero-sub { font-size: 13px; color: var(--text-2); }
        .content { padding: 24px 32px 48px; flex: 1; }
        /* Topbar mobile */
        .topbar { display: none; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); padding: 0 16px; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 200; }
        .topbar-title { font-size: 15px; font-weight: 700; color: var(--text); }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { background: transparent; border: 1px solid var(--border); color: var(--text); width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 299; }
        .sb-overlay.show { display: block; }
        /* ── Sidebar ── */
        .sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; transition: transform var(--t) var(--ease); overflow-y: auto; scrollbar-width: none; }
        .sidebar::-webkit-scrollbar { display: none; }
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
        /* Tabs */
        .nav-tabs { border-bottom: 1px solid var(--border); gap: 0; }
        .nav-tabs .nav-link { background: transparent; border: none; color: var(--text-2); font-weight: 500; font-size: 13px; padding: 10px 16px; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: all var(--t) var(--ease); border-radius: 0; }
        .nav-tabs .nav-link:hover { color: var(--text); background: var(--panel-2); }
        .nav-tabs .nav-link.active { color: var(--red); border-bottom-color: var(--red); font-weight: 600; }
        /* Panel cards */
        .panel-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 16px; }
        .panel-card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .panel-card-title { font-size: 14px; font-weight: 600; color: var(--text); }
        .panel-card-icon { color: var(--red); font-size: 15px; }
        .panel-card-body { padding: 20px; }
        /* Forms */
        .form-control, .form-select { background: var(--panel-2); border: 1px solid var(--border); color: var(--text); border-radius: var(--radius-xs); font-size: 13px; }
        .form-control::placeholder { color: var(--text-3); }
        .form-control:focus, .form-select:focus { background: var(--panel-2); border-color: var(--red); color: var(--text); box-shadow: 0 0 0 3px var(--red-soft); }
        .form-label { font-size: 12px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }
        .form-check-label { color: var(--text-2); font-size: 13px; }
        .form-check-input:checked { background-color: var(--red); border-color: var(--red); }
        /* Buttons */
        .btn-orange { background: var(--red); border: none; color: #fff; font-weight: 600; font-size: 13px; border-radius: var(--radius-xs); padding: 8px 18px; transition: background var(--t); }
        .btn-orange:hover, .btn-orange:focus { background: var(--red-2); color: #fff; }
        .btn-orange:disabled { background: var(--panel-3); color: var(--text-3); }
        .btn-outline-orange { background: transparent; border: 1px solid var(--red); color: var(--red); font-weight: 600; font-size: 13px; border-radius: var(--radius-xs); padding: 8px 18px; transition: all var(--t); }
        .btn-outline-orange:hover { background: var(--red-soft); color: var(--red); }
        .btn-success { background: var(--green); border: none; color: #fff; font-weight: 600; font-size: 13px; border-radius: var(--radius-xs); padding: 8px 18px; }
        /* Badges */
        .badge-admin { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; }
        .badge-team { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 20px; }
        /* Modals */
        .modal-content { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); }
        .modal-header { border-bottom: 1px solid var(--border); padding: 16px 20px; }
        .modal-title { font-size: 15px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px; }
        .modal-footer { border-top: 1px solid var(--border); }
        .btn-close { filter: invert(1) grayscale(1); }
        /* list group */
        .list-group-item { background: var(--panel-2); border-color: var(--border); color: var(--text); font-size: 13px; }
        .list-group-item:hover { background: var(--panel-3); }
        /* alert info */
        .info-box { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-xs); padding: 12px 16px; font-size: 13px; color: var(--text-2); margin-bottom: 16px; }
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .page-hero { padding: 16px 16px 0; }
            .content { padding: 16px 16px 48px; }
        }
    </style>
</head>
<body>
<div class="app">

    <!-- ═══ SIDEBAR ═══════════════════════════════════════════════ -->
    <aside class="sidebar" id="sidebar">

        <div class="sb-team">
            <img src="<?= htmlspecialchars($team_sidebar['photo_url'] ?? '/img/default-team.png') ?>"
                 alt="<?= htmlspecialchars(($team_sidebar['city'] ?? '') . ' ' . ($team_sidebar['name'] ?? '')) ?>"
                 onerror="this.src='/img/default-team.png'">
            <div>
                <div class="sb-team-name"><?= htmlspecialchars(trim(($team_sidebar['city'] ?? '') . ' ' . ($team_sidebar['name'] ?? ''))) ?></div>
                <div class="sb-team-league"><?= htmlspecialchars($team_sidebar['league'] ?? ($user['league'] ?? '')) ?></div>
            </div>
        </div>

        <nav class="sb-nav">
            <div class="sb-section">Principal</div>
            <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
            <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
            <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
            <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
            <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
            <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
            <a href="/leilao.php" class="active"><i class="bi bi-hammer"></i> Leilão</a>
            <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>

            <div class="sb-section">Liga</div>
            <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
            <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
            <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
            <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
            <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>

            <?php if ($is_admin): ?>
            <div class="sb-section">Admin</div>
            <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
            <a href="/punicoes.php"><i class="bi bi-exclamation-triangle-fill"></i> Punições</a>
            <a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a>
            <?php endif; ?>

            <div class="sb-section">Conta</div>
            <a href="/settings.php"><i class="bi bi-gear-fill"></i> Configurações</a>
        </nav>

        <button class="sb-theme-toggle" type="button" id="themeToggle" data-theme-toggle>
            <i class="bi bi-moon"></i>
            <span>Modo escuro</span>
        </button>

        <div class="sb-footer">
            <img src="<?= htmlspecialchars($user['photo_url'] ?? '/img/default-avatar.png') ?>"
                 alt="<?= htmlspecialchars($user['name'] ?? '') ?>"
                 class="sb-avatar"
                 onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name'] ?? 'U') ?>&background=1c1c21&color=fc0025'">
            <span class="sb-username"><?= htmlspecialchars($user['name'] ?? '') ?></span>
            <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </aside>

    <!-- Overlay mobile -->
    <div class="sb-overlay" id="sbOverlay"></div>

    <main class="main">
        <!-- Topbar mobile -->
        <header class="topbar">
            <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
            <div class="topbar-title">FBA <em>Manager</em></div>
        </header>

        <div class="page-hero">
            <div class="hero-eyebrow">Liga · <?= htmlspecialchars($user['league'] ?? 'ELITE') ?></div>
            <h1 class="hero-title"><i class="bi bi-hammer" style="color:var(--red)"></i>Leilão</h1>
            <p class="hero-sub">Lances em tempo real para free agents disponíveis na liga</p>
        </div>

        <div class="content">
            <?php if (!empty($team_name) || $is_admin): ?>
            <div class="d-flex align-items-center gap-2 mb-4">
                <?php if (!empty($team_name)): ?>
                    <span class="badge-team"><?= htmlspecialchars($team_name) ?></span>
                <?php endif; ?>
                <?php if ($is_admin): ?>
                    <span class="badge-admin"><i class="bi bi-shield-lock-fill me-1"></i>Admin</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <ul class="nav nav-tabs mb-4" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" id="auction-active-tab" data-bs-toggle="tab" data-bs-target="#auction-active" type="button" role="tab">
                        <i class="bi bi-hammer me-1"></i>Leilões ativos
                    </button>
                </li>
                <?php if ($is_admin): ?>
                <li class="nav-item">
                    <button class="nav-link" id="auction-admin-tab" data-bs-toggle="tab" data-bs-target="#auction-admin" type="button" role="tab">
                        <i class="bi bi-shield-lock-fill me-1"></i>Admin
                    </button>
                </li>
                <?php endif; ?>
            </ul>

            <div class="tab-content">
                <!-- Leilões Ativos -->
                <div class="tab-pane fade show active" id="auction-active" role="tabpanel">
                    <div class="panel-card">
                        <div class="panel-card-header"><i class="bi bi-hammer panel-card-icon"></i><span class="panel-card-title">Leilões Ativos</span></div>
                        <div class="panel-card-body"><div id="leiloesAtivosContainer"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div></div>
                    </div>
                    <?php if ($team_id): ?>
                    <div class="panel-card">
                        <div class="panel-card-header"><i class="bi bi-inbox panel-card-icon"></i><span class="panel-card-title">Propostas Recebidas</span></div>
                        <div class="panel-card-body"><div id="propostasRecebidasContainer"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div></div>
                    </div>
                    <?php endif; ?>
                    <div class="panel-card">
                        <div class="panel-card-header"><i class="bi bi-clock-history panel-card-icon"></i><span class="panel-card-title">Histórico de Leilões</span></div>
                        <div class="panel-card-body"><div id="leiloesHistoricoContainer"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div></div>
                    </div>
                </div>

                <!-- Admin -->
                <?php if ($is_admin): ?>
                <div class="tab-pane fade" id="auction-admin" role="tabpanel">
                    <div class="panel-card">
                        <div class="panel-card-header"><i class="bi bi-hammer panel-card-icon"></i><span class="panel-card-title">Leilão Admin</span></div>
                        <div class="panel-card-body">
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Liga</label>
                                    <select id="selectLeague" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($leagues as $league): ?>
                                            <option value="<?= (int)$league['id'] ?>" data-league-name="<?= htmlspecialchars($league['name']) ?>"><?= htmlspecialchars($league['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-5 d-flex align-items-end gap-4 pb-1">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="auctionMode" id="auctionModeSearch" value="search" checked>
                                        <label class="form-check-label" for="auctionModeSearch">Buscar jogador</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="auctionMode" id="auctionModeCreate" value="create">
                                        <label class="form-check-label" for="auctionModeCreate">Criar jogador</label>
                                    </div>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button id="btnCadastrarLeilao" class="btn btn-orange w-100" disabled><i class="bi bi-play-fill me-1"></i>Iniciar 20min</button>
                                </div>
                            </div>
                            <div style="border-top:1px solid var(--border);padding-top:20px;">
                                <div id="auctionSearchArea">
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-8">
                                            <label class="form-label">Buscar jogador</label>
                                            <input type="text" id="auctionPlayerSearch" class="form-control" placeholder="Digite o nome...">
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end">
                                            <button class="btn btn-outline-orange w-100" id="auctionSearchBtn"><i class="bi bi-search me-1"></i>Buscar</button>
                                        </div>
                                    </div>
                                    <div id="auctionPlayerResults" style="display:none;"></div>
                                    <div id="auctionSelectedLabel" style="display:none;color:var(--text-2);font-size:13px;margin-top:8px;"></div>
                                    <input type="hidden" id="auctionSelectedPlayerId">
                                    <input type="hidden" id="auctionSelectedTeamId">
                                </div>
                                <div id="auctionCreateArea" style="display:none;">
                                    <p style="color:var(--text-3);font-size:12px;margin-bottom:16px;">O jogador será criado no leilão sem time.</p>
                                    <div class="row g-3">
                                        <div class="col-md-4"><label class="form-label">Nome</label><input type="text" id="auctionPlayerName" class="form-control" placeholder="Nome do jogador"></div>
                                        <div class="col-md-2"><label class="form-label">Posição</label><select id="auctionPlayerPosition" class="form-select"><option value="PG">PG</option><option value="SG">SG</option><option value="SF">SF</option><option value="PF">PF</option><option value="C">C</option></select></div>
                                        <div class="col-md-2"><label class="form-label">Idade</label><input type="number" id="auctionPlayerAge" class="form-control" value="25"></div>
                                        <div class="col-md-2"><label class="form-label">OVR</label><input type="number" id="auctionPlayerOvr" class="form-control" value="70"></div>
                                        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-orange w-100" type="button" id="btnCriarJogadorLeilao"><i class="bi bi-plus-circle me-1"></i>Criar</button></div>
                                    </div>
                                    <div class="mt-4">
                                        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-2);margin-bottom:10px;"><i class="bi bi-person-plus me-1" style="color:var(--red)"></i>Jogadores criados (sem time)</div>
                                        <div id="auctionTempList" style="color:var(--text-3);font-size:13px;">Nenhum jogador criado.</div>
                                    </div>
                                </div>
                            </div>
                            <div style="border-top:1px solid var(--border);padding-top:20px;margin-top:20px;">
                                <div id="adminLeiloesContainer"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Modal: Proposta de Troca -->
<div class="modal fade" id="modalProposta" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-send" style="color:var(--red)"></i>Enviar Proposta de Troca</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="leilaoIdProposta">
                <div class="info-box"><strong style="color:var(--text);">Jogador em leilão:</strong> <span id="jogadorLeilaoNome" style="color:var(--red);font-weight:600;"></span></div>
                <p style="font-size:13px;color:var(--text-2);margin-bottom:8px;">Selecione os jogadores que você oferece em troca:</p>
                <div id="meusJogadoresParaTroca" class="mb-3"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div>
                <p style="font-size:13px;color:var(--text-2);margin-bottom:8px;">Picks para oferecer (opcional):</p>
                <div id="minhasPicksParaTroca" class="mb-3"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div>
                <div class="mb-3">
                    <label class="form-label">O que vai dar na proposta</label>
                    <textarea id="notasProposta" class="form-control" rows="3" placeholder="Ex: 1 jogador + escolha de draft ou moedas"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Observações (opcional)</label>
                    <textarea id="obsProposta" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-orange" id="btnEnviarProposta"><i class="bi bi-send me-1"></i>Enviar Proposta</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Ver Propostas -->
<div class="modal fade" id="modalVerPropostas" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-inbox" style="color:var(--red)"></i>Propostas Recebidas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="leilaoIdVerPropostas">
                <div id="listaPropostasRecebidas"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;
    const userTeamId = <?= $team_id ? $team_id : 'null' ?>;
    const userTeamName = '<?= addslashes($team_name) ?>';
    const currentLeagueId = <?= $league_id ? $league_id : 'null' ?>;
</script>
<script src="/js/leilao.js"></script>
<script src="/js/pwa.js"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    const sbOverlay = document.getElementById('sbOverlay');
    const menuBtn = document.getElementById('menuBtn');
    if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); sbOverlay.classList.add('show'); });
    if (sbOverlay) sbOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); });
    // Theme
    const themeKey = 'fba-theme';
    const themeBtn = document.querySelector('[data-theme-toggle]');
    const applyTheme = (theme) => {
        if (theme === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
            if (themeBtn) { themeBtn.innerHTML = '<i class="bi bi-moon-fill"></i><span>Tema escuro</span>'; themeBtn.setAttribute('aria-pressed','true'); }
        } else {
            document.documentElement.removeAttribute('data-theme');
            if (themeBtn) { themeBtn.innerHTML = '<i class="bi bi-sun-fill"></i><span>Tema claro</span>'; themeBtn.setAttribute('aria-pressed','false'); }
        }
    };
    applyTheme(localStorage.getItem(themeKey) || 'dark');
    if (themeBtn) themeBtn.addEventListener('click', () => {
        const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        localStorage.setItem(themeKey, next);
        applyTheme(next);
    });
</script>
</body>
</html>
