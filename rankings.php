<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

$isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
$userLeague = strtoupper($team['league'] ?? $user['league'] ?? 'ELITE');
$currentTeamId = (int)($team['id'] ?? 0);
$currentSeason = null;
$currentSeasonYear = (int)date('Y');
if (!empty($team['league'])) {
    try {
        $stmtSeason = $pdo->prepare('SELECT s.season_number, s.year, sp.start_year, sp.sprint_number FROM seasons s LEFT JOIN sprints sp ON s.sprint_id = sp.id WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ("completed")) ORDER BY s.created_at DESC LIMIT 1');
        $stmtSeason->execute([$team['league']]);
        $currentSeason = $stmtSeason->fetch();
        if ($currentSeason && isset($currentSeason['start_year'], $currentSeason['season_number'])) {
            $currentSeasonYear = (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1;
        } elseif ($currentSeason && isset($currentSeason['year'])) {
            $currentSeasonYear = (int)$currentSeason['year'];
        }
    } catch (Exception $e) { $currentSeason = null; }
}
$seasonDisplayYear = (string)$currentSeasonYear;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Rankings - FBA Manager</title>
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="/manifest.json?v=3">
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
    <link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
    
    <!-- Fonts & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        /* ── Tokens Universais ───────────────────────── */
        :root {
            --red:        #fc0025;
            --red-2:      #ff2a44;
            --red-soft:   rgba(252,0,37,.10);
            --bg:         #07070a;
            --panel:      #101013;
            --panel-2:    #16161a;
            --panel-3:    #1c1c21;
            --border:     rgba(255,255,255,.06);
            --border-md:  rgba(255,255,255,.10);
            --text:       #f0f0f3;
            --text-2:     #868690;
            --text-3:     #48484f;
            --amber:      #f59e0b;
            --sidebar-w:  260px;
            --font:       'Poppins', sans-serif;
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
            --text: #111217;
            --text-2: #5b6270;
            --text-3: #8b93a5;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }
        a { text-decoration: none; color: inherit; }

        /* ── Shell (Sidebar & Topbar) ────────────────── */
        .app { display: flex; min-height: 100vh; }
        .sidebar {
            position: fixed; top: 0; left: 0; width: 260px; height: 100vh;
            background: var(--panel); border-right: 1px solid var(--border);
            display: flex; flex-direction: column; z-index: 300;
            transition: transform var(--t) var(--ease); overflow-y: auto;
        }
        .sidebar::-webkit-scrollbar { display: none; }
        .sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
        .sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; }
        .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); }
        .sb-team-name { font-size: 13px; font-weight: 600; line-height: 1.2; }
        .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
        
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
        .sb-nav a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; margin-bottom: 2px; transition: all var(--t) var(--ease); }
        .sb-nav a i { font-size: 15px; width: 18px; text-align: center; }
        .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
        .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
        .sb-theme-toggle { margin: 0 14px 12px; padding: 8px 10px; border-radius: 10px; border: 1px solid var(--border); background: var(--panel-2); color: var(--text); display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease); }
        .sb-theme-toggle:hover { border-color: var(--red); color: var(--red); }
        .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; border: 1px solid var(--border-md); }
        .sb-username { font-size: 12px; font-weight: 500; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout { width: 26px; height: 26px; border-radius: 7px; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; transition: all var(--t) var(--ease); }
        .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 260; }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; font-size: 17px; cursor: pointer; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
        .sb-overlay.show { display: block; }

        /* ── Main Content ────────────────────────────── */
        .main { margin-left: var(--sidebar-w); width: calc(100% - var(--sidebar-w)); min-height: 100vh; display: flex; flex-direction: column; }
        .content { padding: 0 32px 40px; flex: 1; }
        
        .dash-hero { padding: 32px 32px 24px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .dash-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .dash-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
        .dash-sub { font-size: 13px; color: var(--text-2); margin-top: 3px; }
        .hero-badges { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .hbadge { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid var(--border-md); background: var(--panel); transition: all var(--t) var(--ease); }
        .hbadge:hover { border-color: var(--text-3); color: var(--text); }
        .hbadge.red { background: var(--red-soft); border-color: var(--red); color: var(--red); }
        .hbadge.red:hover { background: var(--red); color: #fff; }

        /* ── Filtros (Pills) ─────────────────────────── */
        .filter-nav { display: flex; gap: 8px; margin-bottom: 24px; overflow-x: auto; padding-bottom: 4px; }
        .filter-nav::-webkit-scrollbar { display: none; }
        .filter-btn { padding: 8px 18px; border-radius: 99px; background: var(--panel); border: 1px solid var(--border); color: var(--text-2); font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease); white-space: nowrap; }
        .filter-btn:hover { border-color: var(--border-md); color: var(--text); }
        .filter-btn.active { background: var(--red); border-color: var(--red); color: #fff; }

        /* ── Minimal Table ───────────────────────────── */
        .table-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; animation: fadeUp 0.4s var(--ease); }
        .m-table { width: 100%; border-collapse: collapse; text-align: left; }
        .m-table th { padding: 14px 18px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-3); border-bottom: 1px solid var(--border); background: var(--panel-2); }
        .m-table td { padding: 14px 18px; font-size: 13px; font-weight: 500; color: var(--text); border-bottom: 1px solid var(--border); vertical-align: middle; }
        .m-table tr:last-child td { border-bottom: none; }
        .m-table tbody tr { transition: background var(--t) var(--ease); }
        .m-table tbody tr:hover { background: var(--panel-2); }

        /* Highlights da Tabela */
        .rank-pos { font-size: 13px; font-weight: 800; color: var(--text-3); text-align: center; width: 24px; }
        .rank-pos.gold { color: var(--amber); font-size: 15px; }
        .rank-pos.silver { color: #94a3b8; font-size: 15px; }
        .rank-pos.bronze { color: #cd7c4a; font-size: 15px; }
        
        /* Destaque Time Atual */
        .row-me { background: var(--red-soft) !important; }
        .row-me td { border-bottom-color: rgba(252,0,37,.1); }
        .row-me .rank-pos { color: var(--red); }

        .team-name-cell { font-weight: 700; font-size: 14px; display: block; }
        .team-gm-cell { font-size: 11px; color: var(--text-2); font-weight: 500; margin-top: 2px; }
        .league-badge { background: var(--panel-3); border: 1px solid var(--border-md); padding: 4px 8px; border-radius: 6px; font-size: 10px; font-weight: 700; color: var(--text-2); }

        /* ── Modal Customizado ───────────────────────── */
        .modal-content.minimal { background: var(--panel); border: 1px solid var(--border-md); border-radius: var(--radius); }
        .modal-header.minimal { border-bottom: 1px solid var(--border); padding: 18px 24px; }
        .modal-footer.minimal { border-top: 1px solid var(--border); padding: 18px 24px; }
        .modal-title { font-size: 15px; font-weight: 700; font-family: var(--font); color: var(--text); }
        .minimal-input { background: var(--panel-2); border: 1px solid var(--border-md); color: var(--text); border-radius: 8px; padding: 8px 12px; font-size: 13px; width: 100%; transition: border-color var(--t); }
        .minimal-input:focus { outline: none; border-color: var(--red); }
        .btn-minimal { padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all var(--t); }
        .btn-minimal.primary { background: var(--red); border: none; color: #fff; }
        .btn-minimal.primary:hover { filter: brightness(1.1); }
        .btn-minimal.secondary { background: transparent; border: 1px solid var(--border-md); color: var(--text); }
        .btn-minimal.secondary:hover { background: var(--panel-2); }

        /* ── Loader ── */
        .spinner { width: 32px; height: 32px; border: 3px solid var(--border-md); border-top-color: var(--red); border-radius: 50%; animation: spin 1s linear infinite; margin: 40px auto; }
        
        @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes spin { to { transform: rotate(360deg); } }
        .ranking-panel { animation: fadeUp .35s var(--ease) both; }

        /* ── Light theme overrides ───────────────────── */
        :root[data-theme="light"] {
            --bg:         #f4f6fb;
            --panel:      #ffffff;
            --panel-2:    #f0f2f8;
            --panel-3:    #e8ebf4;
            --border:     rgba(15,23,42,.09);
            --border-md:  rgba(15,23,42,.14);
            --border-red: rgba(252,0,37,.20);
            --text:       #111217;
            --text-2:     #5b6270;
            --text-3:     #9ca0ae;
        }
        [data-theme="light"] body { background: var(--bg); color: var(--text); }
        [data-theme="light"] .modal-content { background: var(--panel) !important; color: var(--text) !important; border-color: var(--border-md) !important; }
        [data-theme="light"] .modal-header { background: var(--panel-2) !important; border-color: var(--border) !important; }
        [data-theme="light"] .modal-footer { background: var(--panel-2) !important; border-color: var(--border) !important; }
        [data-theme="light"] .btn-close { filter: none; }
        [data-theme="light"] .form-control { background: var(--panel-2) !important; border-color: var(--border-md) !important; color: var(--text) !important; }
        [data-theme="light"] .table-dark { --bs-table-bg: var(--panel); --bs-table-color: var(--text); --bs-table-border-color: var(--border); }
        [data-theme="light"] .table-dark thead th { background: var(--panel-2); color: var(--text-3); border-color: var(--border) !important; }
        [data-theme="light"] .table-dark tbody td { border-color: var(--border) !important; }
        [data-theme="light"] .table-dark tbody tr:hover { background: var(--panel-2) !important; }

        /* ── Sidebar toggle (hidden — topbar handles mobile) ─ */
        .sidebar-toggle { display: none !important; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 199; }
        .sidebar-overlay.active, .sidebar-overlay.show { display: block; }

        /* ── Responsivo ──────────────────────────────── */
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .dash-hero { padding: 24px 16px 16px; }
            .content { padding: 0 16px 30px; }
            .hide-mobile { display: none; }
            .m-table th, .m-table td { padding: 12px; }
            .dash-hero, .content { padding-left: 16px; padding-right: 16px; }
            .dash-hero { padding-top: 18px; }
            .podium { gap: 8px; }
            .podium-item { max-width: 130px; }
            .podium-logo { width: 40px; height: 40px; }
            .hide-mobile { display: none !important; }
        }
        @media (max-width: 480px) {
            .podium { display: none; }
            .league-tabs { gap: 4px; }
            .league-tab { padding: 6px 12px; font-size: 11px; }
        }
    </style>
</head>
<body>

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
            <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
            <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
            <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
            <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
            <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
            <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
            <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>

            <div class="sb-section">Liga</div>
            <a href="/rankings.php" class="active"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
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



    <!-- ══════════════════════════════════════════════
         MAIN CONTENT
    ══════════════════════════════════════════════ -->
    <main class="main">
        <div class="dash-hero">
            <div>
                <div class="dash-eyebrow">Liga · Classificação</div>
                <h1 class="dash-title">Rankings</h1>
                <p class="dash-sub">Acompanhe a pontuação e os títulos da sua liga.</p>
            </div>
            <div class="hero-badges">
                <a href="/hall-da-fama.php" class="hbadge"><i class="bi bi-award"></i> Hall da Fama</a>
                <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
                <button class="hbadge red" id="btnEditRanking" data-bs-toggle="modal" data-bs-target="#editRankingModal">
                    <i class="bi bi-pencil-square"></i> Editar Ranking
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="content">
            <!-- Filtros Minimalistas -->
            <div class="filter-nav" id="rankingFilters">
                <button type="button" class="filter-btn active" data-league="ELITE" onclick="loadRanking('ELITE')">ELITE</button>
                <button type="button" class="filter-btn" data-league="NEXT" onclick="loadRanking('NEXT')">NEXT</button>
                <button type="button" class="filter-btn" data-league="RISE" onclick="loadRanking('RISE')">RISE</button>
                <button type="button" class="filter-btn" data-league="ROOKIE" onclick="loadRanking('ROOKIE')">ROOKIE</button>
            </div>

            <!-- Tabela Container -->
            <div id="rankingContainer">
                <div class="spinner"></div>
            </div>
        </div>
    </main>

    <!-- ══════════════════════════════════════════════
         MODAL DE EDIÇÃO (ADMIN)
    ══════════════════════════════════════════════ -->
    <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
    <div class="modal fade" id="editRankingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content minimal">
                <div class="modal-header minimal">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2" style="color:var(--red)"></i>Editar Ranking – <span id="editRankingLeague"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding: 0;">
                    <div id="editRankingLoading" class="text-center py-4"><div class="spinner"></div></div>
                    
                    <div class="table-responsive" id="editRankingTableWrap" style="display:none;">
                        <table class="m-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th style="width: 140px; text-align:center">Títulos</th>
                                    <th style="width: 140px; text-align:center">Pontos</th>
                                </tr>
                            </thead>
                            <tbody id="editRankingBody"></tbody>
                        </table>
                    </div>
                    <div id="editRankingEmpty" class="text-center" style="display:none; padding: 40px; color: var(--text-3);">Sem times para esta liga.</div>
                </div>
                <div class="modal-footer minimal">
                    <button type="button" class="btn-minimal secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn-minimal primary" id="btnSaveRanking">Salvar Alterações</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    /* ── Lógica Visual Sidebar / Tema ── */
    const themeToggle = document.getElementById('themeToggle');
    const themeKey = 'fba-theme';
    const applyTheme = (theme) => {
        if (theme === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
            if(themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
        } else {
            document.documentElement.removeAttribute('data-theme');
            if(themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
        }
    };
    applyTheme(localStorage.getItem(themeKey) || 'dark');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            localStorage.setItem(themeKey, next);
            applyTheme(next);
        });
    }

    const sidebar = document.getElementById('sidebar');
    const menuBtn = document.getElementById('menuBtn');
    const sbOverlay = document.getElementById('sbOverlay');
    const closeSidebar = () => { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); };
    if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); sbOverlay.classList.add('show'); });
    if (sbOverlay) sbOverlay.addEventListener('click', closeSidebar);

    /* ── Lógica de Rankings ── */
    let userLeague = "<?= htmlspecialchars($user['league'] ?? 'ELITE') ?>".toUpperCase();
    if (userLeague.includes("?=")) userLeague = "ELITE"; // Fallback para o modo de preview
    
    const currentTeamId = parseInt("<?= (int)($team['id'] ?? 0) ?>", 10) || 0;
    let currentLeague = userLeague;

    function updateActiveButton() {
        document.querySelectorAll('.filter-btn').forEach(btn => {
            if (btn.dataset.league === currentLeague) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    }

    async function loadRanking(league = userLeague) {
        currentLeague = league.toUpperCase();
        updateActiveButton();

        const container = document.getElementById('rankingContainer');
        container.innerHTML = '<div class="spinner"></div>';

        try {
            const response = await fetch(`/api/history-points.php?action=get_ranking&league=${encodeURIComponent(currentLeague)}`);
            const data = await response.json();
            
            if (!data.success) throw new Error(data.error);

            const ranking = data.ranking[currentLeague] || [];

            if (ranking.length === 0) {
                container.innerHTML = `
                    <div style="text-align:center; padding: 40px; color: var(--text-3); background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius);">
                        <i class="bi bi-bar-chart" style="font-size:24px; display:block; margin-bottom:8px"></i>
                        Nenhum dado de ranking disponível ainda para a liga ${currentLeague}.
                    </div>`;
                return;
            }

            // Gerar tabela HTML Minimalista
            let rowsHtml = ranking.map((team, idx) => {
                const isMyTeam = currentTeamId && Number(team.team_id) === currentTeamId;
                const posClass = idx === 0 ? 'gold' : idx === 1 ? 'silver' : idx === 2 ? 'bronze' : '';
                const rowClass = isMyTeam ? 'row-me' : '';

                return `
                <tr class="${rowClass}">
                    <td><div class="rank-pos ${posClass}">${idx + 1}º</div></td>
                    <td>
                        <span class="team-name-cell">${team.team_name}</span>
                        ${team.owner_name ? `<span class="team-gm-cell">GM: ${team.owner_name}</span>` : ''}
                    </td>
                    <td class="hide-mobile"><span class="league-badge">${team.league}</span></td>
                    <td style="text-align: center; color: var(--text-2); font-weight: 600;">${team.total_titles || 0}</td>
                    <td style="text-align: center; color: var(--red); font-weight: 800; font-size: 15px;">${team.total_points || 0}</td>
                </tr>`;
            }).join('');

            container.innerHTML = `
                <div class="table-card">
                    <div class="table-responsive">
                        <table class="m-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px; text-align: center;">Pos</th>
                                    <th>Franquia</th>
                                    <th class="hide-mobile" style="width: 100px;">Liga</th>
                                    <th style="width: 100px; text-align: center;"><i class="bi bi-trophy"></i> Títulos</th>
                                    <th style="width: 100px; text-align: center;"><i class="bi bi-star-fill" style="color:var(--amber)"></i> Pontos</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rowsHtml}
                            </tbody>
                        </table>
                    </div>
                </div>`;
        } catch (e) {
            console.error(e);
            container.innerHTML = `<div style="color: #ef4444; padding: 20px; background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); border-radius: 8px;">Erro ao carregar ranking: ${e.message || 'Desconhecido'}</div>`;
        }
    }

    // Load initial
    document.addEventListener('DOMContentLoaded', () => loadRanking(userLeague));

    /* ── Editor Admin ── */
    <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
    const editModal = document.getElementById('editRankingModal');
    const editLeagueEl = document.getElementById('editRankingLeague');
    const editLoading = document.getElementById('editRankingLoading');
    const editWrap = document.getElementById('editRankingTableWrap');
    const editBody = document.getElementById('editRankingBody');
    const editEmpty = document.getElementById('editRankingEmpty');
    const btnSaveRanking = document.getElementById('btnSaveRanking');

    editModal?.addEventListener('show.bs.modal', async () => {
        editLeagueEl.textContent = currentLeague;
        editLoading.style.display = 'block';
        editWrap.style.display = 'none';
        editEmpty.style.display = 'none';
        editBody.innerHTML = '';

        try {
            const resp = await fetch(`/api/history-points.php?action=get_ranking&league=${encodeURIComponent(currentLeague)}`);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Falha ao carregar ranking');
            
            const rows = data.ranking[currentLeague] || [];
            if (!rows.length) {
                editEmpty.style.display = 'block';
                return;
            }
            
            rows.forEach(row => {
                editBody.innerHTML += `
                <tr data-team-id="${row.team_id}">
                    <td style="font-weight: 600; font-size: 13px;">${row.team_name}</td>
                    <td><input type="number" class="minimal-input js-edit-titles" value="${row.total_titles || 0}" min="0"></td>
                    <td><input type="number" class="minimal-input js-edit-points" value="${row.total_points || 0}" min="0"></td>
                </tr>`;
            });
            editWrap.style.display = 'block';
        } catch (e) {
            editEmpty.textContent = 'Erro ao carregar ranking para edição.';
            editEmpty.style.display = 'block';
        } finally {
            editLoading.style.display = 'none';
        }
    });

    btnSaveRanking?.addEventListener('click', async () => {
        const rows = Array.from(editBody.querySelectorAll('tr[data-team-id]'));
        const team_points = rows.map(tr => ({
            team_id: parseInt(tr.getAttribute('data-team-id'), 10),
            titles: parseInt(tr.querySelector('.js-edit-titles')?.value || '0', 10),
            points: parseInt(tr.querySelector('.js-edit-points')?.value || '0', 10)
        }));
        
        btnSaveRanking.disabled = true;
        btnSaveRanking.textContent = 'Salvando...';
        
        try {
            const resp = await fetch('/api/history-points.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'save_ranking_totals', league: currentLeague, team_points })
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Falha ao salvar');

            bootstrap.Modal.getInstance(editModal)?.hide();
            loadRanking(currentLeague);
        } catch (e) {
            alert(e.message || 'Erro ao salvar');
        } finally {
            btnSaveRanking.disabled = false;
            btnSaveRanking.textContent = 'Salvar Alterações';
        }
    });
    <?php endif; ?>
</script>
</body>
</html>