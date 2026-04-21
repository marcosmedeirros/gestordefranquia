<?php
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/auth.php';

$token = $_GET['token'] ?? null;
if (!$token) {
    http_response_code(403);
    echo 'Token inválido.';
    exit;
}

$pdo = db();
$user = getUserSession();
$isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
$userTeamId = null;
if ($user && isset($user['id'])) {
    $stmtTeam = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? LIMIT 1');
    $stmtTeam->execute([$user['id']]);
    $userTeamId = $stmtTeam->fetchColumn() ?: null;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Draft Inicial — Seleção</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* ── Tokens ───────────────────────────────────── */
        :root {
            --red:        #fc0025;
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
            --font:       'Poppins', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        /* ── Layout ───────────────────────────────────── */
        .app-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 20px 48px; }

        /* ── Topbar ───────────────────────────────────── */
        .app-topbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 14px; flex-wrap: wrap;
            padding: 14px 20px;
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            margin-bottom: 24px;
        }
        .app-topbar-left { display: flex; align-items: center; gap: 12px; }
        .app-logo { width: 32px; height: 32px; border-radius: 8px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; color: #fff; flex-shrink: 0; }
        .app-title { font-size: 15px; font-weight: 700; line-height: 1.1; }
        .app-title span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--text-2); text-decoration: none; transition: color var(--t) var(--ease); }
        .back-link:hover { color: var(--red); }

        /* ── Hero ─────────────────────────────────────── */
        .hero {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 28px;
            margin-bottom: 20px;
        }
        .hero-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--red); margin-bottom: 6px; }
        .hero-title { font-size: clamp(1.4rem, 2.5vw, 1.8rem); font-weight: 800; margin-bottom: 4px; }
        .hero-sub { font-size: 13px; color: var(--text-2); }

        /* ── Stat grid ────────────────────────────────── */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
            margin-top: 18px;
        }
        .stat-card {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
        }
        .stat-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); margin-bottom: 6px; }
        .stat-value { font-size: 1.1rem; font-weight: 700; }

        /* ── Panel card ───────────────────────────────── */
        .panel-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            height: 100%;
        }
        .panel-card-head {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .panel-card-title { font-size: 13px; font-weight: 700; }
        .panel-card-sub { font-size: 11px; color: var(--text-2); margin-top: 2px; }
        .panel-card-body { padding: 16px 18px; }

        /* ── Pick card ────────────────────────────────── */
        .pick-card {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px;
        }
        .pick-card.current-pick-highlight {
            border-color: var(--border-red);
            background: rgba(252,0,37,.06);
        }
        .pick-card.next-pick-highlight {
            background: var(--panel-3);
            border-color: var(--border);
        }
        .pick-card.compact {
            padding: 8px 12px;
            border-radius: 8px;
        }
        .pick-card-lg { padding: 16px; }
        .pick-card-sm { padding: 10px 14px; opacity: .9; }
        .pick-flash { animation: pickFlash 1.2s ease-in-out; }
        @keyframes pickFlash {
            0%   { box-shadow: 0 0 0 rgba(252,0,37,0); }
            30%  { box-shadow: 0 0 22px rgba(252,0,37,.45); }
            100% { box-shadow: 0 0 0 rgba(252,0,37,0); }
        }

        .pick-logo {
            width: 40px; height: 40px; border-radius: 50%;
            object-fit: cover; border: 1px solid var(--border-md);
            flex-shrink: 0;
        }

        /* ── Order list ───────────────────────────────── */
        .order-item {
            display: flex; align-items: center; gap: 10px;
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 8px 12px; margin-bottom: 6px;
        }
        .order-item.order-highlight {
            border-color: var(--border-red);
            background: rgba(252,0,37,.07);
        }
        .order-item.order-next {
            border-style: dashed;
            border-color: rgba(255,255,255,.12);
        }
        .order-rank {
            width: 28px; height: 28px; border-radius: 50%;
            background: var(--red-soft); border: 1px solid var(--border-red);
            display: grid; place-items: center;
            font-weight: 700; font-size: 12px; color: var(--red); flex-shrink: 0;
        }
        .team-chip { display: flex; align-items: center; gap: 8px; }
        .team-chip img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .team-chip-name { font-size: 13px; font-weight: 600; line-height: 1.2; }
        .team-chip-gm { font-size: 11px; color: var(--text-2); }

        /* ── Pick summary (reaction bar) ──────────────── */
        .pick-summary {
            background: var(--panel-3); border: 1px solid var(--border);
            border-radius: 8px; padding: 8px 10px; margin-top: 6px;
            font-size: 12px;
        }
        .pick-summary-name { font-weight: 600; color: var(--text); }
        .pick-summary-meta { color: var(--red); font-size: 11px; }
        .reaction-bar { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 6px; }
        .reaction-chip {
            display: inline-flex; align-items: center; gap: 3px;
            padding: 2px 7px; border-radius: 999px; font-size: 11px;
            border: 1px solid var(--border-md);
            background: var(--panel-2); color: var(--text);
            cursor: pointer; user-select: none;
            transition: all var(--t) var(--ease);
        }
        .reaction-chip:hover { border-color: var(--border-red); background: var(--red-soft); }
        .reaction-chip.active { background: var(--red-soft); border-color: var(--border-red); }
        .reaction-count { color: var(--text-2); }

        /* ── Status pill ──────────────────────────────── */
        .status-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
        .status-pill.setup       { background: rgba(245,158,11,.12); color: var(--amber); border: 1px solid rgba(245,158,11,.25); }
        .status-pill.in_progress { background: rgba(34,197,94,.12);  color: var(--green); border: 1px solid rgba(34,197,94,.25); }
        .status-pill.completed   { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); }

        /* ── Badges ───────────────────────────────────── */
        .badge-available { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; background: rgba(34,197,94,.10); color: var(--green); border: 1px solid rgba(34,197,94,.2); }
        .badge-drafted   { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; background: var(--panel-3); color: var(--text-3); border: 1px solid var(--border); }

        /* ── Buttons ──────────────────────────────────── */
        .btn-red {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px;
            background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 12px; font-weight: 600;
            cursor: pointer; transition: filter var(--t) var(--ease); text-decoration: none;
        }
        .btn-red:hover { filter: brightness(1.12); color: #fff; }
        .btn-ghost {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px;
            background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
            font-family: var(--font); font-size: 12px; font-weight: 600;
            cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none;
        }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }
        .btn-green {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px;
            background: var(--green); border: none; color: #fff;
            font-family: var(--font); font-size: 12px; font-weight: 600;
            cursor: pointer; transition: filter var(--t) var(--ease);
        }
        .btn-green:hover { filter: brightness(1.1); }
        .btn-amber {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px;
            background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.3); color: var(--amber);
            font-family: var(--font); font-size: 12px; font-weight: 600;
            cursor: pointer; transition: all var(--t) var(--ease);
        }
        .btn-amber:hover { background: rgba(245,158,11,.2); }

        /* ── Search/Filter inputs ─────────────────────── */
        .search-input, .filter-select {
            background: var(--panel-2); border: 1px solid var(--border-md);
            border-radius: 8px; padding: 8px 12px;
            color: var(--text); font-family: var(--font); font-size: 13px;
            outline: none; transition: border-color var(--t) var(--ease);
            width: 100%;
        }
        .search-input:focus, .filter-select:focus { border-color: var(--red); }
        .search-input::placeholder { color: var(--text-3); }
        .filter-select option { background: var(--panel-2); }
        .filter-check { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-2); cursor: pointer; }
        .filter-check input { accent-color: var(--red); }

        /* ── Data table ───────────────────────────────── */
        .data-table-wrap { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .data-table th {
            font-size: 10px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase;
            color: var(--text-3); padding: 10px 12px; border-bottom: 1px solid var(--border);
            text-align: left; white-space: nowrap; background: var(--panel-2);
        }
        .data-table th.sortable { cursor: pointer; user-select: none; }
        .data-table th.sortable:hover { color: var(--text-2); }
        .data-table th.sortable.active { color: var(--text); }
        .data-table th.sortable .sort-indicator { margin-left: 4px; font-size: .8em; }
        .data-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); color: var(--text-2); vertical-align: middle; }
        .data-table td.td-name { font-weight: 600; color: var(--text); }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover td { background: var(--panel-3); }

        /* ── Pagination ───────────────────────────────── */
        .pag-wrap .pagination { margin: 0; }
        .pag-wrap .page-link { background: var(--panel-2); border-color: var(--border); color: var(--text-2); font-family: var(--font); font-size: 12px; }
        .pag-wrap .page-link:hover { background: var(--panel-3); color: var(--text); }
        .pag-wrap .page-item.active .page-link { background: var(--red); border-color: var(--red); color: #fff; }
        .pag-wrap .page-item.disabled .page-link { opacity: .4; }

        /* ── Roster grid ──────────────────────────────── */
        .roster-card {
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 14px;
        }
        .roster-list { list-style: none; padding: 0; margin: 0; font-size: 12px; color: var(--text-2); }
        .roster-list li { padding: 4px 0; border-bottom: 1px solid var(--border); }
        .roster-list li:last-child { border-bottom: none; }
        .roster-player-pos { color: var(--red); font-weight: 600; }

        /* ── Empty state ──────────────────────────────── */
        .state-empty { padding: 24px 16px; text-align: center; color: var(--text-3); font-size: 13px; }

        /* ── TV mode ──────────────────────────────────── */
        body.tv-mode .app-wrap { max-width: 1600px; }
        body.tv-mode .hero-title { font-size: clamp(2rem, 3.5vw, 2.8rem); }
        body.tv-mode .pick-card { padding: 18px; font-size: 1.05rem; }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 768px) {
            .app-wrap { padding: 16px 14px 40px; }
            .hero { padding: 18px 20px; }
        }
        @media (max-width: 576px) {
            #poolTableEl thead { display: none; }
            #poolTableEl tbody tr {
                display: flex; flex-direction: column;
                gap: 3px; padding: 10px 12px;
                border-bottom: 1px solid var(--border);
            }
            #poolTableEl td { width: 100%; padding: 0; border: 0; }
            #poolTableEl td:first-child { display: none; }
        }
    </style>
</head>
<body>

<!-- Topbar -->
<div class="app-topbar">
    <div class="app-topbar-left">
        <div class="app-logo">FBA</div>
        <div class="app-title">Sala de Seleção <span>Draft Inicial</span></div>
    </div>
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <span id="leagueName" style="font-size:12px;font-weight:700;color:var(--text-2)"></span>
        <button class="btn-ghost" onclick="loadState()"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>
        <button class="btn-ghost" id="toggleSoundButton"><i class="bi bi-volume-mute"></i> Som</button>
        <button class="btn-ghost" id="toggleTvButton"><i class="bi bi-fullscreen"></i> TV</button>
        <?php if ($isAdmin): ?>
        <button class="btn-amber" id="openRoundNowButton" onclick="adminOpenNextRoundNow()">
            <i class="bi bi-lightning-charge"></i> Abrir rodada
        </button>
        <?php endif; ?>
        <a href="dashboard.php" class="back-link"><i class="bi bi-arrow-left"></i> Dashboard</a>
    </div>
</div>

<div class="app-wrap">

    <!-- Hero -->
    <section class="hero">
        <div class="hero-eyebrow">Draft Inicial</div>
        <h1 class="hero-title">Sala de Seleção</h1>
        <p class="hero-sub">Acompanhe picks, ordem e elencos em montagem.</p>
        <div class="stat-grid" id="statGrid"></div>
    </section>

    <!-- Main grid -->
    <div class="row g-4">

        <!-- Left column: picks + order -->
        <div class="col-lg-4">
            <div class="panel-card mb-4">
                <div class="panel-card-head">
                    <div>
                        <div class="panel-card-title">Pick Atual</div>
                        <div class="panel-card-sub" id="clockBanner"></div>
                    </div>
                </div>
                <div class="panel-card-body">
                    <div id="currentPickCard"></div>
                    <div style="margin:14px 0 10px;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3)">Próximo Pick</div>
                    <div id="nextPickCard"></div>
                </div>
            </div>

            <div class="panel-card">
                <div class="panel-card-head">
                    <div class="panel-card-title">Ordem do Draft</div>
                </div>
                <div class="panel-card-body" id="orderList" style="min-height:60px">
                    <div class="state-empty">Carregando…</div>
                </div>
            </div>
        </div>

        <!-- Right column: pool + rosters -->
        <div class="col-lg-8">
            <div class="panel-card mb-4">
                <div class="panel-card-head">
                    <div class="panel-card-title">Jogadores do Pool</div>
                    <span style="font-size:11px;color:var(--text-2)" id="poolMeta"></span>
                </div>
                <div class="panel-card-body">
                    <div class="d-flex flex-column flex-md-row gap-2 mb-3">
                        <input type="text" id="poolSearch" class="search-input" placeholder="Buscar jogador…">
                        <select id="poolPositionFilter" class="filter-select" style="max-width:160px">
                            <option value="">Todas as posições</option>
                            <option value="PG">PG</option>
                            <option value="SG">SG</option>
                            <option value="SF">SF</option>
                            <option value="PF">PF</option>
                            <option value="C">C</option>
                        </select>
                        <label class="filter-check" style="white-space:nowrap">
                            <input type="checkbox" id="poolOnlyAvailable" checked>
                            Disponíveis
                        </label>
                    </div>
                    <div class="data-table-wrap">
                        <table class="data-table" id="poolTableEl">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th class="sortable" data-sort="name">Jogador <span class="sort-indicator"></span></th>
                                    <th>Pos</th>
                                    <th class="sortable" data-sort="ovr">OVR <span class="sort-indicator"></span></th>
                                    <th class="sortable" data-sort="age">Idade <span class="sort-indicator"></span></th>
                                    <th style="text-align:right">Ação</th>
                                </tr>
                            </thead>
                            <tbody id="poolTable"></tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2 pag-wrap" id="poolPagination"></div>
                </div>
            </div>

            <div class="panel-card">
                <div class="panel-card-head">
                    <div class="panel-card-title">Elencos em Montagem</div>
                    <span style="font-size:11px;color:var(--text-2)" id="rosterMeta"></span>
                </div>
                <div class="panel-card-body">
                    <div class="row g-3" id="rosterGrid">
                        <div class="state-empty">Nenhum elenco montado ainda.</div>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div><!-- .app-wrap -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const TOKEN = '<?php echo htmlspecialchars($token, ENT_QUOTES); ?>';
    const API_URL = 'api/initdraft.php';
    const USER_TEAM_ID = <?php echo $userTeamId ? (int)$userTeamId : 'null'; ?>;
    const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;

        const state = {
            session: null,
            order: [],
            teams: [],
            pool: [],
        };

        const elements = {
            leagueName: document.getElementById('leagueName'),
            statGrid: document.getElementById('statGrid'),
            clockBanner: document.getElementById('clockBanner'),
            currentPickCard: document.getElementById('currentPickCard'),
            nextPickCard: document.getElementById('nextPickCard'),
            orderList: document.getElementById('orderList'),
            poolTable: document.getElementById('poolTable'),
            poolMeta: document.getElementById('poolMeta'),
            rosterGrid: document.getElementById('rosterGrid'),
            rosterMeta: document.getElementById('rosterMeta'),
            toggleSoundButton: document.getElementById('toggleSoundButton'),
            toggleTvButton: document.getElementById('toggleTvButton'),
            poolSearch: document.getElementById('poolSearch'),
            poolPositionFilter: document.getElementById('poolPositionFilter'),
            poolPagination: document.getElementById('poolPagination'),
        };

        const uiState = {
            soundEnabled: false,
            lastPickId: null,
            poolSearch: '',
            poolPosition: '',
            poolOnlyAvailable: true,
            poolSortField: 'ovr',
            poolSortAsc: false,
            poolPage: 1,
            poolPageSize: 15,
            clockTickInterval: null,
            clockPickId: null,
            clockDeadlineMs: null,
        };

        function parseSqlDatetimeToMs(value) {
            if (!value) return null;
            // Expecting YYYY-MM-DD HH:mm:ss
            // Treat as local time (browser). Since server uses America/Sao_Paulo and most users are too, this is OK.
            const normalized = String(value).trim().replace(' ', 'T');
            const date = new Date(normalized);
            const ms = date.getTime();
            return Number.isFinite(ms) ? ms : null;
        }

        // Relógio removido (sistema antigo sem timer)

        function teamLabel(pick) {
            if (!pick) return '—';
            return `${pick.team_city || ''} ${pick.team_name || ''}`.trim();
        }

        function renderStats() {
            const session = state.session;
            if (!session) return;

            elements.leagueName.textContent = session.league || '-';
            const drafted = state.order.filter((pick) => pick.picked_player_id).length;
            const total = state.order.length || (session.total_rounds ?? 0) * (state.teams.length || 0);
            const progress = total ? Math.round((drafted / total) * 100) : 0;
            const currentPick = state.order.find((pick) => !pick.picked_player_id);
            const nextPick = state.order.find((pick, idx) => !pick.picked_player_id && idx > state.order.indexOf(currentPick));

            const statusLabel2 = { setup: 'Configuração', in_progress: 'Em andamento', completed: 'Concluído' }[session.status] || session.status || '—';
            elements.statGrid.innerHTML = `
                <div class="stat-card">
                    <div class="stat-label">Status</div>
                    <div class="status-pill ${session.status}">${statusLabel2}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Rodada</div>
                    <div class="stat-value">${session.current_round ?? '—'} / ${session.total_rounds ?? '—'}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Time Atual</div>
                    <div class="stat-value" style="font-size:.95rem">${currentPick ? teamLabel(currentPick) : '—'}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Progresso</div>
                    <div class="stat-value">${drafted} / ${total} <span style="font-size:.8rem;color:var(--text-2)">(${progress}%)</span></div>
                </div>
            `;
        }

        function renderPickCard(target, pick, label, highlightClass = '') {
            if (!pick) {
                target.innerHTML = `<div class="state-empty" style="padding:12px 0;font-size:12px">Nenhuma pick disponível.</div>`;
                return;
            }
            target.innerHTML = `
                <div class="pick-card ${highlightClass}">
                    <div class="d-flex align-items-center gap-3">
                        <img class="pick-logo" src="${pick.team_photo || '/img/default-team.png'}" alt="${pick.team_name || 'Time'}" onerror="this.src='/img/default-team.png'">
                        <div class="pick-rank">${pick.pick_position}</div>
                        <div>
                            <div style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--red)">${label}</div>
                            <div style="font-weight:700;font-size:14px">${teamLabel(pick)}</div>
                            <div style="font-size:11px;color:var(--text-2)">GM: ${pick.team_owner || 'Sem GM'}</div>
                            <div style="font-size:11px;color:var(--red)">Rodada ${pick.round}</div>
                        </div>
                    </div>
                </div>
            `;
        }

        function renderOrderList(currentPick, nextPick) {
            if (!state.order.length) {
                elements.orderList.innerHTML = '<div class="state-empty">Ordem ainda não definida.</div>';
                return;
            }
            const displayRound = Number(state.session?.current_round || 1);
            const roundPicks = state.order
                .filter((pick) => pick.round === displayRound)
                .sort((a, b) => a.pick_position - b.pick_position);
            elements.orderList.innerHTML = roundPicks
                .map((pick, index) => {
                    const picked = !!pick.picked_player_id;
                    const reactions = Array.isArray(pick.reactions) ? pick.reactions : [];
                    const mineEmoji = reactions.find(r => r.mine)?.emoji || null;
                    const emojiList = ['👍','❤️','😂','😮','😢','😡'];
                    const countsMap = Object.fromEntries(reactions.map(r => [r.emoji, r.count]));

                    const chips = emojiList.map(e => {
                        const cnt = countsMap[e] || 0;
                        const activeClass = mineEmoji === e ? 'reaction-chip active' : 'reaction-chip';
                        const enc = encodeURIComponent(e);
                        return `<span class="${activeClass}" onclick="toggleReaction(${pick.id}, '${enc}')">${e} <span class="reaction-count">${cnt}</span></span>`;
                    }).join(' ');

                    const pickSummary = picked ? `
                        <div class="pick-summary">
                            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                                <div>
                                    <span class="pick-summary-name">${pick.player_name}</span>
                                    <span class="pick-summary-meta"> (${pick.player_position ?? ''} · ${pick.player_ovr ?? '-'}${pick.player_age ? '/' + pick.player_age + 'y' : ''})</span>
                                </div>
                                <div class="reaction-bar">${chips}</div>
                            </div>
                        </div>
                    ` : '';

                    const isCurrentTeam = currentPick && pick.team_id === currentPick.team_id;
                    const isNextTeam = nextPick && pick.team_id === nextPick.team_id;
                    return `
                        <div class="order-item ${isCurrentTeam ? 'order-highlight' : ''} ${isNextTeam && !isCurrentTeam ? 'order-next' : ''}" style="flex-direction:column;align-items:stretch">
                            <div class="d-flex align-items-center gap-2">
                                <div class="order-rank">${index + 1}</div>
                                <div class="team-chip">
                                    <img src="${pick.team_photo || '/img/default-team.png'}" alt="${pick.team_name || 'Time'}" onerror="this.src='/img/default-team.png'">
                                    <div>
                                        <div class="team-chip-name">${teamLabel(pick)}</div>
                                        <div class="team-chip-gm">${pick.team_owner || 'Sem GM'}</div>
                                    </div>
                                </div>
                            </div>
                            ${pickSummary}
                        </div>
                    `;
                })
                .join('');
        }

        function renderPool(currentPick) {
            const pool = state.pool || [];
            const search = uiState.poolSearch.trim();
            const positionFilter = uiState.poolPosition;
            const filtered = pool.filter((player) => {
                const matchesSearch = !search || (player.name || '').toLowerCase().includes(search);
                const matchesPosition = !positionFilter || player.position === positionFilter;
                const matchesAvailability = !uiState.poolOnlyAvailable || (player.draft_status !== 'drafted');
                return matchesSearch && matchesPosition && matchesAvailability;
            });

            // Ordenação (clique no cabeçalho): default OVR desc
            const sortField = uiState.poolSortField || 'ovr';
            const asc = !!uiState.poolSortAsc;
            filtered.sort((a, b) => {
                let cmp = 0;
                if (sortField === 'ovr') {
                    cmp = (Number(a.ovr) || 0) - (Number(b.ovr) || 0);
                } else if (sortField === 'age') {
                    cmp = (Number(a.age) || 0) - (Number(b.age) || 0);
                } else if (sortField === 'name') {
                    const av = (a.name || '').toString().toLowerCase();
                    const bv = (b.name || '').toString().toLowerCase();
                    cmp = av.localeCompare(bv);
                }
                return asc ? cmp : -cmp;
            });

            const total = filtered.length;
            const totalPages = Math.max(1, Math.ceil(total / uiState.poolPageSize));
            if (uiState.poolPage > totalPages) {
                uiState.poolPage = totalPages;
            }
            const startIndex = (uiState.poolPage - 1) * uiState.poolPageSize;
            const pageItems = filtered.slice(startIndex, startIndex + uiState.poolPageSize);

            elements.poolMeta.textContent = `${total} jogadores`;
            if (!pageItems.length) {
                elements.poolTable.innerHTML = '<tr><td colspan="6" class="state-empty" style="padding:20px">Nenhum jogador disponível.</td></tr>';
                elements.poolPagination.innerHTML = '';
                updatePoolSortIndicators();
                return;
            }

            const canPick = state.session?.status === 'in_progress' && (IS_ADMIN || (currentPick && USER_TEAM_ID && currentPick.team_id === USER_TEAM_ID));
            elements.poolTable.innerHTML = pageItems
                .map((player, index) => {
                    const drafted = player.draft_status === 'drafted';
                    const action = (!drafted && canPick)
                        ? `<button class="btn-green" style="padding:4px 12px;font-size:11px" onclick="makePick(${player.id})"><i class="bi bi-check2"></i> Escolher</button>`
                        : '<span style="color:var(--text-3)">—</span>';
                    return `
                        <tr>
                            <td>${startIndex + index + 1}</td>
                            <td class="td-name">${player.name}</td>
                            <td>${player.position}</td>
                            <td style="font-weight:700;color:var(--text)">${player.ovr}</td>
                            <td>${player.age || '—'}</td>
                            <td style="text-align:right">${action}</td>
                        </tr>
                    `;
                })
                .join('');

            elements.poolPagination.innerHTML = `
                <span style="font-size:11px;color:var(--text-2)">Pág. ${uiState.poolPage} de ${totalPages}</span>
                <div class="d-flex gap-2">
                    <button class="btn-ghost" style="padding:4px 10px;font-size:11px" ${uiState.poolPage === 1 ? 'disabled' : ''} onclick="changePoolPage(${uiState.poolPage - 1})">← Anterior</button>
                    <button class="btn-ghost" style="padding:4px 10px;font-size:11px" ${uiState.poolPage === totalPages ? 'disabled' : ''} onclick="changePoolPage(${uiState.poolPage + 1})">Próxima →</button>
                </div>
            `;
            updatePoolSortIndicators();
        }

        function changePoolPage(page) {
            uiState.poolPage = page;
            const currentPick = state.order.find((pick) => !pick.picked_player_id);
            renderPool(currentPick);
        }

        function renderRosters() {
            const picks = state.order.filter((pick) => pick.picked_player_id);
            const grouped = {};
            picks.forEach((pick) => {
                const key = pick.team_id;
                if (!grouped[key]) {
                    grouped[key] = {
                        team: pick,
                        players: []
                    };
                }
                grouped[key].players.push(pick);
            });

            const teams = Object.values(grouped);
            elements.rosterMeta.textContent = `${teams.length} times com picks`;

            if (!teams.length) {
                elements.rosterGrid.innerHTML = '<div class="state-empty">Nenhum elenco montado ainda.</div>';
                return;
            }

            elements.rosterGrid.innerHTML = teams
                .map((group) => {
                    const roster = group.players
                        .map((pick) => {
                            const ovr = (pick.player_ovr ?? '—');
                            const age = (pick.player_age != null && pick.player_age !== '') ? `${pick.player_age}y` : '—';
                            return `<li>${pick.player_name} <span class="roster-player-pos">${pick.player_position ?? ''} · ${ovr}/${age}</span></li>`;
                        })
                        .join('');
                    return `
                        <div class="col-md-6 col-xl-4">
                            <div class="roster-card h-100">
                                <div class="team-chip mb-3">
                                    <img src="${group.team.team_photo || '/img/default-team.png'}" alt="${group.team.team_name || 'Time'}" onerror="this.src='/img/default-team.png'">
                                    <div>
                                        <div class="team-chip-name">${teamLabel(group.team)}</div>
                                        <div class="team-chip-gm">${group.team.team_owner || 'Sem GM'}</div>
                                    </div>
                                </div>
                                <ul class="roster-list">${roster}</ul>
                            </div>
                        </div>
                    `;
                })
                .join('');
        }

        async function loadState() {
            try {
                const [stateRes, poolRes] = await Promise.all([
                    fetch(`${API_URL}?action=state&token=${TOKEN}`).then((r) => r.json()),
                    fetch(`${API_URL}?action=pool&token=${TOKEN}`).then((r) => r.json()),
                ]);
                if (!stateRes.success) throw new Error(stateRes.error || 'Erro ao carregar estado');
                state.session = stateRes.session;
                state.order = stateRes.order || [];
                state.teams = stateRes.teams || [];
                state.pool = poolRes.success ? poolRes.players : [];
                renderStats();
                const currentPick = state.order.find((pick) => !pick.picked_player_id);
                const nextPick = state.order.find((pick, idx) => !pick.picked_player_id && idx > state.order.indexOf(currentPick));
                handlePickChange(currentPick);
                // sem relógio
                renderPickCard(elements.currentPickCard, currentPick, 'Pick Atual', 'current-pick-highlight pick-card-lg');
                renderPickCard(elements.nextPickCard, nextPick, 'Próximo', 'next-pick-highlight pick-card-sm');
                renderOrderList(currentPick, nextPick);
                renderPool(currentPick);
                renderRosters();
            } catch (error) {
                elements.poolTable.innerHTML = `<tr><td colspan="6" style="color:#ef4444;padding:16px;text-align:center">${error.message}</td></tr>`;
            }
        }

        function setupAutoRefresh() {
            const isMobile = window.matchMedia('(max-width: 768px)').matches;
            if (!isMobile) {
                setInterval(() => {
                    if (document.visibilityState === 'visible') {
                        loadState();
                    }
                }, 10000);
            }

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    loadState();
                }
            });
        }

        async function makePick(playerId) {
            if (!confirm('Confirmar a escolha deste jogador?')) return;
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'make_pick', token: TOKEN, player_id: playerId })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao registrar pick');
                await loadState();
            } catch (error) {
                alert(error.message);
            }
        }

        async function adminOpenNextRoundNow() {
            if (!IS_ADMIN) return;
            if (!confirm('Abrir rodada imediatamente?')) return;
            try {
                const sessionId = state.session?.id;
                if (!sessionId) throw new Error('Sessão não carregada');
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'admin_open_next_round_now', session_id: sessionId })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Falha ao abrir rodada');
                await loadState();
            } catch (error) {
                alert(error.message);
            }
        }

        async function reactPick(pickId, emoji) {
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'react_pick', token: TOKEN, pick_id: pickId, emoji })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao reagir');
                await loadState();
            } catch (error) {
                alert(error.message);
            }
        }

        async function removeReaction(pickId) {
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'remove_reaction', token: TOKEN, pick_id: pickId })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao remover reação');
                await loadState();
            } catch (error) {
                alert(error.message);
            }
        }

        async function toggleReaction(pickId, emoji) {
            try {
                const emo = decodeURIComponent(emoji);
                // Descobre reação atual do usuário nessa pick
                const pick = state.order.find(p => p.id === pickId);
                const mineEmoji = (pick && Array.isArray(pick.reactions)) ? (pick.reactions.find(r => r.mine)?.emoji || null) : null;
                if (mineEmoji === emo) {
                    await removeReaction(pickId);
                } else {
                    await reactPick(pickId, emo);
                }
            } catch (error) {
                alert(error.message);
            }
        }

        function handlePickChange(currentPick) {
            const pickId = currentPick?.id || null;
            if (pickId && uiState.lastPickId && pickId !== uiState.lastPickId) {
                elements.currentPickCard.classList.remove('pick-flash');
                void elements.currentPickCard.offsetWidth;
                elements.currentPickCard.classList.add('pick-flash');
                if (uiState.soundEnabled) {
                    playBeep();
                }
            }
            if (pickId) {
                uiState.lastPickId = pickId;
            }
        }

        function playBeep() {
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.value = 740;
                gain.gain.value = 0.06;
                oscillator.connect(gain);
                gain.connect(audioCtx.destination);
                oscillator.start();
                oscillator.stop(audioCtx.currentTime + 0.25);
                oscillator.onended = () => audioCtx.close();
            } catch (error) {
                console.warn('Audio não disponível');
            }
        }

        function toggleSound() {
            uiState.soundEnabled = !uiState.soundEnabled;
            const btn = elements.toggleSoundButton;
            if (btn) {
                btn.style.color = uiState.soundEnabled ? 'var(--amber)' : '';
                btn.style.borderColor = uiState.soundEnabled ? 'rgba(245,158,11,.4)' : '';
                const icon = btn.querySelector('i');
                if (icon) {
                    icon.classList.toggle('bi-volume-mute', !uiState.soundEnabled);
                    icon.classList.toggle('bi-volume-up', uiState.soundEnabled);
                }
            }
        }

        function toggleTvMode() {
            document.body.classList.toggle('tv-mode');
            const isTv = document.body.classList.contains('tv-mode');
            if (isTv && document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else if (!isTv && document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            }
        }

        elements.poolSearch?.addEventListener('input', (event) => {
            uiState.poolSearch = event.target.value.toLowerCase();
            uiState.poolPage = 1;
            const currentPick = state.order.find((pick) => !pick.picked_player_id);
            renderPool(currentPick);
        });

        elements.poolPositionFilter?.addEventListener('change', (event) => {
            uiState.poolPosition = event.target.value;
            uiState.poolPage = 1;
            const currentPick = state.order.find((pick) => !pick.picked_player_id);
            renderPool(currentPick);
        });

        document.querySelector('#poolTableEl thead')?.addEventListener('click', (e) => {
            const th = e.target.closest('th.sortable');
            if (!th) return;
            const field = th.dataset.sort;
            if (!field) return;
            if (uiState.poolSortField === field) {
                uiState.poolSortAsc = !uiState.poolSortAsc; // alterna
            } else {
                uiState.poolSortField = field;
                uiState.poolSortAsc = false; // primeiro clique: desc
            }
            uiState.poolPage = 1;
            const currentPick = state.order.find((pick) => !pick.picked_player_id);
            renderPool(currentPick);
        });

        document.getElementById('poolOnlyAvailable')?.addEventListener('change', (e) => {
            uiState.poolOnlyAvailable = !!e.target.checked;
            uiState.poolPage = 1;
            const currentPick = state.order.find((pick) => !pick.picked_player_id);
            renderPool(currentPick);
        });

        function updatePoolSortIndicators() {
            const thead = document.querySelector('#poolTableEl thead');
            if (!thead) return;
            thead.querySelectorAll('th.sortable').forEach(th => {
                th.classList.remove('active');
                const span = th.querySelector('.sort-indicator');
                if (span) span.textContent = '';
                const field = th.dataset.sort;
                if (field === uiState.poolSortField) {
                    th.classList.add('active');
                    const indicator = th.querySelector('.sort-indicator');
                    if (indicator) indicator.textContent = uiState.poolSortAsc ? '▲' : '▼';
                }
            });
        }

        elements.toggleSoundButton?.addEventListener('click', toggleSound);
        elements.toggleTvButton?.addEventListener('click', toggleTvMode);

    setupAutoRefresh();
    loadState();
    </script>
</body>
</html>
