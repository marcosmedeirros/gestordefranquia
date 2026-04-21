<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
if (($user['user_type'] ?? 'jogador') !== 'admin') {
    header('Location: /dashboard.php');
    exit;
}

$pdo = db();

$team = null;
try {
    $s = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
    $s->execute([$user['id']]);
    $team = $s->fetch() ?: null;
} catch (Exception $e) {}

$currentSeason     = null;
$seasonDisplayYear = (int)date('Y');
try {
    $s = $pdo->prepare("
        SELECT s.season_number, s.year, sp.sprint_number, sp.start_year
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed'))
        ORDER BY s.created_at DESC LIMIT 1
    ");
    $s->execute([$user['league']]);
    $currentSeason = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($currentSeason) {
        $seasonDisplayYear = isset($currentSeason['start_year'], $currentSeason['season_number'])
            ? (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1
            : (int)($currentSeason['year'] ?? date('Y'));
    }
} catch (Exception $e) {}
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
    <title>Punições - FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
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
            --sidebar-w:  260px;
            --font:       'Poppins', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

        .app { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 260px; height: 100vh;
            background: var(--panel); border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            z-index: 300; overflow-y: auto; scrollbar-width: none;
            transition: transform var(--t) var(--ease);
        }
        .sidebar::-webkit-scrollbar { display: none; }
        .sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
        .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
        .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
        .sb-season { margin: 10px 14px 0; background: var(--red-soft); border: 1px solid var(--border-red); border-radius: 8px; padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .sb-season-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); }
        .sb-season-val { font-size: 14px; font-weight: 700; color: var(--red); }
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
        .sb-nav a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
        .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
        .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
        .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
        .sb-nav a.active i { color: var(--red); }
        .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; flex-shrink: 0; }
        .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        /* Topbar mobile */
        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 240; }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
        .sb-overlay.show { display: block; }

        /* Main */
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }
        .page-hero { padding: 28px 32px 20px; border-bottom: 1px solid var(--border); }
        .page-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .page-title { font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: var(--red); }
        .content { padding: 24px 32px 48px; flex: 1; }

        /* Panel cards */
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
        }
        .panel-head {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 8px;
        }
        .panel-head-title { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .panel-head-title i { color: var(--red); font-size: 15px; }
        .panel-body { padding: 18px; }

        /* Form fields override */
        .form-label { font-size: 12px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
        .form-control, .form-select {
            background: var(--panel-2) !important;
            border: 1px solid var(--border-md) !important;
            color: var(--text) !important;
            border-radius: var(--radius-sm) !important;
            font-family: var(--font);
            font-size: 13px;
            transition: border-color var(--t) var(--ease);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--border-red) !important;
            box-shadow: 0 0 0 3px var(--red-glow) !important;
            background: var(--panel-2) !important;
            color: var(--text) !important;
        }
        .form-control::placeholder { color: var(--text-3); }

        /* Submit button */
        .btn-submit {
            width: 100%; padding: 10px; border-radius: var(--radius-sm);
            background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 7px;
            transition: filter var(--t) var(--ease);
        }
        .btn-submit:hover { filter: brightness(1.1); }
        .btn-submit-outline {
            width: 100%; padding: 10px; border-radius: var(--radius-sm);
            background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 7px;
            transition: all var(--t) var(--ease);
        }
        .btn-submit-outline:hover { border-color: var(--border-red); color: var(--red); }

        /* Punishment card */
        .pun-item {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            margin-bottom: 10px;
            transition: border-color var(--t) var(--ease);
        }
        .pun-item:last-child { margin-bottom: 0; }
        .pun-item:hover { border-color: var(--border-md); }
        .pun-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 999px;
            font-size: 10px; font-weight: 700; letter-spacing: .5px;
            background: var(--red-soft); border: 1px solid var(--border-red); color: var(--red);
        }

        /* Bootstrap compat */
        .bg-dark-panel { background: var(--panel-2) !important; }
        .border-orange { border-color: var(--border-red) !important; }
        .text-orange { color: var(--red) !important; }
        .text-light-gray { color: var(--text-2) !important; }
        .btn-orange { background: var(--red); border-color: var(--red); color: #fff; font-family: var(--font); }
        .btn-orange:hover { filter: brightness(1.1); color: #fff; }
        .btn-outline-orange { border-color: var(--red); color: var(--red); font-family: var(--font); }
        .btn-outline-orange:hover { background: var(--red-soft); color: var(--red); }
        .bg-gradient-orange, .badge.bg-orange { background: var(--red) !important; }

        /* Responsive */
        @media (max-width: 991px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .topbar { display: flex; }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .page-hero { padding: 20px 16px 16px; }
            .content { padding: 16px 16px 40px; }
        }
    </style>
</head>
<body>

<div class="app">

    <aside class="sidebar" id="sidebar">
        <div class="sb-brand">
            <div class="sb-logo">FBA</div>
            <div class="sb-brand-text">
                FBA Manager
                <span>Painel do GM</span>
            </div>
        </div>

        <?php if ($team): ?>
        <div class="sb-team">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
                 alt="<?= htmlspecialchars($team['name'] ?? '') ?>"
                 onerror="this.src='/img/default-team.png'">
            <div>
                <div class="sb-team-name"><?= htmlspecialchars(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')) ?></div>
                <div class="sb-team-league"><?= htmlspecialchars($user['league']) ?></div>
            </div>
        </div>
        <?php endif; ?>

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
            <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
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

            <div class="sb-section">Admin</div>
            <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
            <a href="/punicoes.php" class="active"><i class="bi bi-exclamation-triangle-fill"></i> Punições</a>
            <a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a>

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

    <div class="sb-overlay" id="sbOverlay"></div>

    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Manager</em></div>
        <span style="font-size:11px;font-weight:700;color:var(--red)"><?= $seasonDisplayYear ?></span>
    </header>

    <main class="main">
        <div class="page-hero">
            <div class="page-eyebrow">Admin · <?= htmlspecialchars($user['league']) ?></div>
            <h1 class="page-title"><i class="bi bi-exclamation-triangle-fill"></i> Punições</h1>
        </div>

        <div class="content">
            <div class="row g-4">

                <!-- Coluna esquerda: formulários -->
                <div class="col-lg-4">

                    <!-- Nova punição -->
                    <div class="panel mb-3">
                        <div class="panel-head">
                            <span class="panel-head-title"><i class="bi bi-plus-circle-fill"></i> Nova punição</span>
                        </div>
                        <div class="panel-body">
                            <div class="mb-3">
                                <label class="form-label">Motivo</label>
                                <select id="punicaoMotive" class="form-select"></select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Liga</label>
                                <select id="punicaoLeague" class="form-select"></select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Time</label>
                                <select id="punicaoTeam" class="form-select"></select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Consequência</label>
                                <select id="punicaoType" class="form-select"></select>
                            </div>
                            <div class="mb-3" id="punicaoPickRow" style="display:none;">
                                <label class="form-label">Pick específica</label>
                                <select id="punicaoPick" class="form-select"></select>
                            </div>
                            <div class="mb-3" id="punicaoScopeRow" style="display:none;">
                                <label class="form-label">Temporada</label>
                                <select id="punicaoScope" class="form-select">
                                    <option value="current">Temporada atual</option>
                                    <option value="next">Próxima temporada</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Observações</label>
                                <textarea id="punicaoNotes" class="form-control" rows="3" placeholder="Detalhes ou contexto..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Data da punição (manual)</label>
                                <input type="datetime-local" id="punicaoDate" class="form-control" />
                            </div>
                            <button id="punicaoSubmit" class="btn-submit">
                                <i class="bi bi-check2-circle"></i> Registrar punição
                            </button>
                        </div>
                    </div>

                    <!-- Cadastrar motivo -->
                    <div class="panel mb-3">
                        <div class="panel-head">
                            <span class="panel-head-title"><i class="bi bi-tag-fill"></i> Cadastrar motivo</span>
                        </div>
                        <div class="panel-body">
                            <div class="mb-3">
                                <label class="form-label">Novo motivo</label>
                                <input type="text" id="newMotiveLabel" class="form-control" placeholder="Ex: Diretrizes erradas">
                            </div>
                            <button class="btn-submit-outline" id="newMotiveBtn">
                                <i class="bi bi-plus-circle"></i> Salvar motivo
                            </button>
                        </div>
                    </div>

                    <!-- Cadastrar consequência -->
                    <div class="panel">
                        <div class="panel-head">
                            <span class="panel-head-title"><i class="bi bi-lightning-fill"></i> Cadastrar consequência</span>
                        </div>
                        <div class="panel-body">
                            <div class="mb-3">
                                <label class="form-label">Nova consequência</label>
                                <input type="text" id="newPunishmentLabel" class="form-control" placeholder="Ex: Perda de pick específica">
                            </div>
                            <button class="btn-submit-outline" id="newPunishmentBtn">
                                <i class="bi bi-plus-circle"></i> Salvar consequência
                            </button>
                        </div>
                    </div>

                </div>

                <!-- Coluna direita: histórico -->
                <div class="col-lg-8">
                    <div class="panel">
                        <div class="panel-head" style="justify-content:space-between; flex-wrap:wrap; gap:10px;">
                            <span class="panel-head-title"><i class="bi bi-clock-history"></i> Histórico de punições</span>
                            <div class="d-flex gap-2 flex-wrap">
                                <select id="punicaoHistoryLeague" class="form-select form-select-sm" style="width:auto;min-width:120px">
                                    <option value="">Todas as ligas</option>
                                </select>
                                <select id="punicaoHistoryTeam" class="form-select form-select-sm" style="width:auto;min-width:140px">
                                    <option value="">Todos os times</option>
                                </select>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div id="punicoesList" style="color:var(--text-2);font-size:13px">
                                Selecione um time para ver as punições.
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

</div><!-- /.app -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Sidebar toggle
    (function () {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sbOverlay');
        const menuBtn = document.getElementById('menuBtn');
        if (!sidebar) return;
        const close = () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); };
        if (menuBtn) menuBtn.addEventListener('click', () => { const open = sidebar.classList.toggle('open'); overlay.classList.toggle('show', open); });
        if (overlay) overlay.addEventListener('click', close);
        document.querySelectorAll('.sb-nav a').forEach(a => a.addEventListener('click', close));
    })();
</script>
<script src="/js/punicoes.js"></script>
</body>
</html>
