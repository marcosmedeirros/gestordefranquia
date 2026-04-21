<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;
$teamId = $team['id'] ?? null;
$league = $team['league'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Trade List — FBA Manager</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#07070a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/icon-192.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ── Tokens ──────────────────────────────────── */
        :root {
            --red:        #fc0025;
            --red-soft:   rgba(252,0,37,.10);
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
            /* Legado (usado pelo trade-list.js via var()) */
            --fba-card-bg:    #16161a;
            --fba-border:     rgba(255,255,255,.06);
            --fba-text:       #f0f0f3;
            --fba-text-muted: #868690;
            --fba-dark-bg:    #1c1c21;
            --fba-orange:     #fc0025;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; min-height: 100vh; }

        /* ── Layout ──────────────────────────────────── */
        .app-wrap { max-width: 960px; margin: 0 auto; padding: 24px 20px 56px; }

        /* ── Topbar ──────────────────────────────────── */
        .app-topbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 14px; flex-wrap: wrap;
            padding: 14px 20px;
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            margin-bottom: 28px;
        }
        .app-topbar-left { display: flex; align-items: center; gap: 12px; }
        .app-logo { width: 32px; height: 32px; border-radius: 8px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; color: #fff; flex-shrink: 0; }
        .app-title { font-size: 15px; font-weight: 700; line-height: 1.1; }
        .app-title span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--text-2); text-decoration: none; transition: color var(--t) var(--ease); }
        .back-link:hover { color: var(--red); }

        /* ── Page header ──────────────────────────────── */
        .page-head { margin-bottom: 22px; }
        .page-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--red); margin-bottom: 5px; }
        .page-title { font-size: 1.5rem; font-weight: 800; margin-bottom: 4px; }
        .page-sub { font-size: 13px; color: var(--text-2); }

        /* ── Panel card ──────────────────────────────── */
        .panel-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .panel-card-head {
            padding: 14px 18px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .panel-card-title { font-size: 13px; font-weight: 700; }
        .panel-card-body { padding: 16px 18px; }

        /* ── Count badge ─────────────────────────────── */
        .count-badge {
            display: inline-flex; padding: 3px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 700;
            background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border);
        }

        /* ── Search / Sort ───────────────────────────── */
        .search-input, .sort-select {
            background: var(--panel-2); border: 1px solid var(--border-md);
            border-radius: 8px; padding: 9px 12px;
            color: var(--text); font-family: var(--font); font-size: 13px;
            outline: none; transition: border-color var(--t) var(--ease); width: 100%;
        }
        .search-input:focus, .sort-select:focus { border-color: var(--red); }
        .search-input::placeholder { color: var(--text-3); }
        .sort-select option { background: var(--panel-2); }

        /* ── Player cards (renderizados pelo trade-list.js) ── */
        .player-card {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            margin-bottom: 8px;
            transition: border-color var(--t) var(--ease);
        }
        .player-card:last-child { margin-bottom: 0; }
        .player-card:hover { border-color: rgba(255,255,255,.14); }

        .player-name { font-weight: 600; color: var(--text); font-size: 14px; margin-bottom: 3px; }
        .player-meta { font-size: 12px; color: var(--text-2); }

        .team-chip {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--panel-3); border: 1px solid var(--border);
            padding: 5px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 500; color: var(--text-2);
            white-space: nowrap; flex-shrink: 0;
        }
        .team-chip img { width: 22px; height: 22px; border-radius: 50%; object-fit: cover; }
        .team-chip-badge {
            width: 22px; height: 22px; border-radius: 50%;
            background: var(--red-soft); border: 1px solid var(--border-red);
            display: grid; place-items: center;
            font-size: 9px; font-weight: 800; color: var(--red); flex-shrink: 0;
        }

        /* ── Loading spinner (Bootstrap override) ────── */
        .spinner-border { color: var(--red) !important; }

        /* ── Alert overrides (Bootstrap) ─────────────── */
        .alert { border-radius: var(--radius-sm) !important; font-size: 13px !important; font-family: var(--font) !important; }
        .alert-danger { background: rgba(239,68,68,.09) !important; border-color: rgba(239,68,68,.2) !important; color: #ef4444 !important; }
        .alert-info   { background: rgba(59,130,246,.09) !important; border-color: rgba(59,130,246,.2) !important; color: var(--blue) !important; }

        /* ── Empty / loading state ────────��──────────── */
        .state-empty { padding: 28px 16px; text-align: center; color: var(--text-3); font-size: 13px; }

        /* ── Responsive ──────────────────────────────── */
        @media (max-width: 768px) {
            .app-wrap { padding: 16px 14px 40px; }
            .player-card > .d-flex { flex-direction: column; gap: 10px; align-items: flex-start !important; }
            .team-chip { align-self: flex-start; }
        }
    </style>
</head>
<body>

<!-- Topbar -->
<div class="app-topbar">
    <div class="app-topbar-left">
        <div class="app-logo">FBA</div>
        <div class="app-title">Trade List <span>Jogadores disponíveis para troca</span></div>
    </div>
    <a href="trades.php" class="back-link"><i class="bi bi-arrow-left"></i> Trades</a>
</div>

<div class="app-wrap">

    <div class="page-head">
        <div class="page-eyebrow">Trades · <?= htmlspecialchars($league) ?></div>
        <h1 class="page-title">Trade List</h1>
        <p class="page-sub">Jogadores de outros times disponíveis para negociação na sua liga.</p>
    </div>

    <div class="panel-card">
        <div class="panel-card-head">
            <div class="panel-card-title">Jogadores</div>
            <span class="count-badge" id="countBadge">—</span>
        </div>
        <div class="panel-card-body">
            <div class="d-flex flex-column flex-md-row gap-2 mb-4">
                <input type="text" id="searchInput" class="search-input" placeholder="Procurar por nome…">
                <select id="sortSelect" class="sort-select" style="max-width:240px">
                    <option value="ovr_desc">OVR (Maior primeiro)</option>
                    <option value="ovr_asc">OVR (Menor primeiro)</option>
                    <option value="name_asc">Nome (A–Z)</option>
                    <option value="name_desc">Nome (Z–A)</option>
                    <option value="age_asc">Idade (Menor primeiro)</option>
                    <option value="age_desc">Idade (Maior primeiro)</option>
                    <option value="position_asc">Posição (A–Z)</option>
                    <option value="position_desc">Posição (Z–A)</option>
                    <option value="team_asc">Time (A–Z)</option>
                    <option value="team_desc">Time (Z–A)</option>
                </select>
            </div>
            <div id="playersList">
                <div class="state-empty">Carregando…</div>
            </div>
        </div>
    </div>

</div><!-- .app-wrap -->

<script>
window.__USER_LEAGUE__ = '<?= htmlspecialchars($league, ENT_QUOTES) ?>';
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/trade-list.js"></script>
<script src="/js/pwa.js"></script>
</body>
</html>
