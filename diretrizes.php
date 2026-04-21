<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();
ensureTeamDirectiveProfileColumns($pdo);

$team = null;
try {
    $s = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
    $s->execute([$user['id']]);
    $team = $s->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {}

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

// Temporada atual (sidebar badge)
$currentSeason     = null;
$seasonDisplayYear = (int)date('Y');
try {
    $s = $pdo->prepare("
        SELECT s.season_number, s.year, sp.sprint_number, sp.start_year
        FROM seasons s INNER JOIN sprints sp ON s.sprint_id = sp.id
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

// Prazo ativo
$nowBrasilia = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
$deadline    = null;
try {
    $s = $pdo->prepare("SELECT * FROM directive_deadlines WHERE league = ? AND is_active = 1 AND deadline_date > ? ORDER BY deadline_date ASC LIMIT 1");
    $s->execute([$team['league'], $nowBrasilia]);
    $deadline = $s->fetch() ?: null;
} catch (Exception $e) {}

$forceProfileMode    = isset($_GET['mode']) && $_GET['mode'] === 'profile';
$directiveMode       = ($forceProfileMode || !$deadline) ? 'profile' : 'deadline';
$deadlineDisplay     = null;
$deadlineIso         = null;
$hasDirectiveSubmission = false;

if ($deadline && !empty($deadline['deadline_date'])) {
    try {
        $dt = new DateTime($deadline['deadline_date'], new DateTimeZone('America/Sao_Paulo'));
        $deadlineDisplay = $dt->format('d/m/Y \à\s H:i');
        $deadlineIso     = $dt->format(DateTime::ATOM);
    } catch (Exception $e) {
        $deadlineDisplay = date('d/m/Y', strtotime($deadline['deadline_date']));
    }
}
if ($deadline && !empty($team['id'])) {
    try {
        $s = $pdo->prepare("SELECT id FROM team_directives WHERE team_id = ? AND deadline_id = ? LIMIT 1");
        $s->execute([(int)$team['id'], (int)$deadline['id']]);
        $hasDirectiveSubmission = (bool)$s->fetchColumn();
    } catch (Exception $e) {}
}

// Jogadores
$players = [];
try {
    $s = $pdo->prepare("SELECT id, name, position, ovr, age, role FROM players WHERE team_id = ? ORDER BY ovr DESC");
    $s->execute([$team['id']]);
    $players = $s->fetchAll();
} catch (Exception $e) {}

$playerCount  = count($players);
$gleagueSlots = $playerCount >= 15 ? 2 : ($playerCount >= 14 ? 1 : 0);

// Temporada para limite de minutos
$seasonStatus = null;
try {
    $s = $pdo->prepare("SELECT season_number, year, status FROM seasons WHERE league = ? AND status IN ('draft','regular','playoffs') ORDER BY created_at DESC LIMIT 1");
    $s->execute([$team['league']]);
    $row = $s->fetch();
    $seasonStatus = $row['status'] ?? null;
} catch (Exception $e) {}

$isEliteOrNext = in_array(($team['league'] ?? ''), ['ELITE', 'NEXT'], true);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="manifest" href="/manifest.json?v=3">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
    <title>Diretrizes - FBA Manager</title>

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

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

        .app { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; overflow-y: auto; scrollbar-width: none; transition: transform var(--t) var(--ease); }
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
        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 260; }
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
        .page-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }
        .content { padding: 24px 32px 56px; flex: 1; }

        /* Banner alerts */
        .banner {
            border-radius: var(--radius-sm); padding: 14px 18px; margin-bottom: 20px;
            display: flex; align-items: flex-start; gap: 12px; font-size: 13px;
            border: 1px solid transparent;
        }
        .banner i { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
        .banner-title { font-weight: 700; font-size: 13px; margin-bottom: 2px; }
        .banner-sub { color: var(--text-2); font-size: 12px; }
        .banner-sub strong { color: var(--text); }
        .banner.warn  { background: rgba(245,158,11,.08); border-color: rgba(245,158,11,.25); }
        .banner.warn  i { color: var(--amber); }
        .banner.warn  .banner-title { color: var(--amber); }
        .banner.info  { background: var(--red-soft); border-color: var(--border-red); }
        .banner.info  i { color: var(--red); }
        .banner.info  .banner-title { color: var(--red); }
        .banner.rules { background: rgba(245,158,11,.06); border-color: rgba(245,158,11,.18); border-left: 3px solid var(--amber); }
        .banner.rules .banner-title { color: var(--amber); }

        /* Section cards */
        .sc { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 16px; }
        .sc-head { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .sc-icon { width: 30px; height: 30px; border-radius: 8px; background: var(--red-soft); border: 1px solid var(--border-red); display: flex; align-items: center; justify-content: center; color: var(--red); font-size: 14px; flex-shrink: 0; }
        .sc-title { font-size: 13px; font-weight: 700; }
        .sc-body { padding: 20px; }

        /* Form fields */
        .field-label { font-size: 11px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); margin-bottom: 7px; display: block; }
        .field-hint  { font-size: 11px; color: var(--text-3); margin-top: 5px; }
        .form-control, .form-select {
            background: var(--panel-2) !important; border: 1px solid var(--border-md) !important;
            color: var(--text) !important; border-radius: var(--radius-sm) !important;
            font-family: var(--font); font-size: 13px;
            transition: border-color var(--t) var(--ease);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--border-red) !important;
            box-shadow: 0 0 0 3px var(--red-glow) !important;
            background: var(--panel-2) !important; color: var(--text) !important;
        }
        .form-control::placeholder { color: var(--text-3); }
        .form-select option, .form-control option { background: var(--panel-2); }
        textarea.form-control { resize: vertical; }

        /* Bench checkbox items */
        .bench-item {
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 10px 12px;
            display: flex; align-items: center; gap: 8px; cursor: pointer;
            transition: border-color var(--t) var(--ease);
        }
        .bench-item:hover { border-color: var(--border-md); }
        .bench-item input[type="checkbox"]:checked ~ * { color: var(--text); }
        .bench-item:has(input:checked) { border-color: var(--border-red); background: var(--red-soft); }
        .bench-item input { width: 16px; height: 16px; accent-color: var(--red); flex-shrink: 0; }
        .bench-item-name { font-size: 12px; font-weight: 600; }
        .bench-item-meta { font-size: 11px; color: var(--text-2); }
        .bench-count-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 999px;
            background: var(--red-soft); border: 1px solid var(--border-red);
            color: var(--red); font-size: 11px; font-weight: 700;
        }

        /* Range slider */
        .form-range { accent-color: var(--red); }
        .range-labels { display: flex; justify-content: space-between; font-size: 11px; color: var(--text-3); margin-top: 4px; }
        .range-val { text-align: center; font-size: 13px; font-weight: 700; color: var(--red); margin-top: 2px; }

        /* Info note */
        .info-note {
            background: rgba(245,158,11,.06); border: 1px solid rgba(245,158,11,.18);
            border-radius: var(--radius-sm); padding: 12px 14px;
            font-size: 12px; color: var(--text-2); margin-bottom: 16px;
        }
        .info-note strong { color: var(--text); }
        .info-note.blue { background: rgba(59,130,246,.06); border-color: rgba(59,130,246,.18); }

        /* Submit bar */
        .submit-bar {
            display: flex; align-items: center; justify-content: flex-end; gap: 12px;
            padding-top: 8px;
        }
        .btn-cancel {
            padding: 10px 20px; border-radius: var(--radius-sm);
            background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
            font-family: var(--font); font-size: 13px; font-weight: 500;
            cursor: pointer; transition: all var(--t) var(--ease);
        }
        .btn-cancel:hover { border-color: var(--border-red); color: var(--red); }
        .btn-submit {
            padding: 11px 28px; border-radius: var(--radius-sm);
            background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 13px; font-weight: 700;
            cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
            transition: filter var(--t) var(--ease);
        }
        .btn-submit:hover { filter: brightness(1.1); }

        /* Bootstrap compat */
        .bg-dark-panel { background: var(--panel-2) !important; }
        .border-orange { border-color: var(--border-red) !important; }
        .text-orange { color: var(--red) !important; }
        .text-light-gray { color: var(--text-2) !important; }
        .btn-orange { background: var(--red); border-color: var(--red); color: #fff; font-family: var(--font); }
        .btn-orange:hover { filter: brightness(1.1); color: #fff; }

        /* Responsive */
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .topbar { display: flex; }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .page-hero { padding: 20px 16px 16px; }
            .content { padding: 16px 16px 48px; }
        }
    </style>
</head>
<body>

<div class="app">

    <aside class="sidebar" id="sidebar">
        <div class="sb-brand">
            <div class="sb-logo">FBA</div>
            <div class="sb-brand-text">FBA Manager<span>Liga <?= htmlspecialchars($user['league']) ?></span></div>
        </div>

        <div class="sb-team">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
                 alt="<?= htmlspecialchars($team['name']) ?>" onerror="this.src='/img/default-team.png'">
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
            <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
            <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
            <a href="/diretrizes.php" class="active"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
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
                 alt="<?= htmlspecialchars($user['name']) ?>" class="sb-avatar"
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
            <div class="page-eyebrow">Liga · <?= htmlspecialchars($user['league']) ?></div>
            <h1 class="page-title"><i class="bi bi-clipboard-data"></i> Diretrizes de Jogo</h1>
            <p class="page-sub">Configure seu quinteto, banco e estratégias de jogo.</p>
        </div>

        <div class="content">

            <?php if (!$deadline): ?>
            <div class="banner warn">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div><div class="banner-title">Sem prazo ativo</div><div class="banner-sub">Não há prazo ativo no momento. Você pode salvar a diretriz base do seu time.</div></div>
            </div>
            <?php endif; ?>

            <?php if ($deadline && $directiveMode === 'profile'): ?>
            <div class="banner warn">
                <i class="bi bi-info-circle-fill"></i>
                <div><div class="banner-title">Diretriz base</div><div class="banner-sub">Você está editando a diretriz base do time. Para enviar no prazo, use o card de envio no dashboard.</div></div>
            </div>
            <?php endif; ?>

            <?php if ($deadline && $directiveMode === 'deadline'): ?>
            <div class="banner info">
                <i class="bi bi-calendar-event-fill"></i>
                <div>
                    <div class="banner-title"><?= htmlspecialchars($deadline['description'] ?? 'Prazo de envio') ?></div>
                    <div class="banner-sub">Até <strong><?= htmlspecialchars($deadlineDisplay ?? '') ?></strong> (Horário de Brasília)</div>
                </div>
            </div>
            <?php endif; ?>

            <div class="banner rules">
                <i class="bi bi-exclamation-triangle-fill" style="color:var(--amber)"></i>
                <div>
                    <div class="banner-title" style="color:var(--amber)">Regras de Minutagem</div>
                    <div class="banner-sub" style="line-height:1.8">
                        Titulares: mín. <strong>25 min</strong> &nbsp;·&nbsp;
                        Reservas: mín. <strong>5 min</strong> &nbsp;·&nbsp;
                        Se sem 3 jogadores 85+, top-5 OVRs precisam de <strong>25+ min</strong> &nbsp;·&nbsp;
                        Total exato: <strong>240 min</strong> &nbsp;·&nbsp;
                        Máximo: <strong>40 min</strong> (regular) / <strong>45 min</strong> (playoffs)
                    </div>
                </div>
            </div>

            <form id="form-diretrizes">
                <input type="hidden" id="deadline-id" value="<?= ($directiveMode === 'deadline' && $deadline) ? (int)$deadline['id'] : '' ?>">

                <!-- Quinteto Titular -->
                <div class="sc">
                    <div class="sc-head">
                        <div class="sc-icon"><i class="bi bi-trophy"></i></div>
                        <span class="sc-title">Quinteto Titular <span style="color:var(--text-3);font-weight:400">(5 jogadores)</span></span>
                    </div>
                    <div class="sc-body">
                        <div class="row g-3">
                            <?php
                            $positions = ['PG', 'SG', 'SF', 'PF', 'C'];
                            for ($i = 1; $i <= 5; $i++):
                                $pos = $positions[$i - 1];
                            ?>
                            <div class="col-md-4 col-sm-6">
                                <label class="field-label">Titular <?= $pos ?></label>
                                <select class="form-select" name="starter_<?= $i ?>_id" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($players as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-ovr="<?= $p['ovr'] ?>">
                                        <?= htmlspecialchars($p['name']) ?> (<?= $p['ovr'] ?>/<?= htmlspecialchars($p['age'] ?? '?') ?>) — <?= $p['position'] ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <!-- Banco -->
                <div class="sc">
                    <div class="sc-head">
                        <div class="sc-icon"><i class="bi bi-people"></i></div>
                        <span class="sc-title">Banco <span style="color:var(--text-3);font-weight:400">(selecione quantos quiser)</span></span>
                    </div>
                    <div class="sc-body">
                        <p style="font-size:12px;color:var(--text-2);margin-bottom:16px">Cada jogador selecionado deve jogar no mínimo 5 minutos.</p>
                        <div class="row g-2" id="bench-players-container">
                            <?php foreach ($players as $p): ?>
                            <div class="col-md-4 col-sm-6">
                                <label class="bench-item">
                                    <input class="bench-player-checkbox" type="checkbox" name="bench_players[]" value="<?= $p['id'] ?>" id="bench_<?= $p['id'] ?>">
                                    <div>
                                        <div class="bench-item-name"><?= htmlspecialchars($p['name']) ?></div>
                                        <div class="bench-item-meta"><?= $p['position'] ?> · OVR <?= $p['ovr'] ?> · <?= htmlspecialchars($p['age'] ?? '?') ?> anos</div>
                                    </div>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:14px">
                            <span class="bench-count-badge"><i class="bi bi-person-check"></i> <span id="bench-count">0</span> selecionados</span>
                        </div>
                    </div>
                </div>

                <!-- Estilos de Jogo -->
                <div class="sc">
                    <div class="sc-head">
                        <div class="sc-icon"><i class="bi bi-gear"></i></div>
                        <span class="sc-title">Estilo de Jogo</span>
                    </div>
                    <div class="sc-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="field-label">Estilo de Jogo</label>
                                <select class="form-select" name="game_style">
                                    <option value="balanced">Balanced</option>
                                    <option value="triangle">Triangle</option>
                                    <option value="grit_grind">Grit &amp; Grind</option>
                                    <option value="pace_space">Pace &amp; Space</option>
                                    <option value="perimeter_centric">Perimeter Centric</option>
                                    <option value="post_centric">Post Centric</option>
                                    <option value="seven_seconds">Seven Seconds</option>
                                    <option value="defense">Defense</option>
                                    <option value="franchise_player">Melhor esquema pro Franchise Player</option>
                                    <option value="most_stars">Maior nº de Estrelas</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="field-label">Estilo de Ataque</label>
                                <select class="form-select" name="offense_style">
                                    <option value="no_preference">No Preference</option>
                                    <option value="pick_roll">Pick &amp; Roll Offense</option>
                                    <option value="neutral">Neutral Offensive Focus</option>
                                    <option value="play_through_star">Play Through Star</option>
                                    <option value="get_to_basket">Get to The Basket</option>
                                    <option value="get_shooters_open">Get Shooters Open</option>
                                    <option value="feed_post">Feed The Post</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="field-label">Estilo de Rotação</label>
                                <select class="form-select" name="rotation_style">
                                    <option value="auto">Automática</option>
                                    <option value="manual">Manual</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="field-label">Tempo de Ataque</label>
                                <select class="form-select" name="pace">
                                    <option value="no_preference">No Preference</option>
                                    <option value="patient">Patient Offense</option>
                                    <option value="average">Average Tempo</option>
                                    <option value="shoot_at_will">Shoot at Will</option>
                                </select>
                            </div>
                            <?php if ($isEliteOrNext): ?>
                            <div class="col-md-6">
                                <label class="field-label">Modelo técnico</label>
                                <select class="form-select" name="technical_model">
                                    <option value="">Selecione...</option>
                                    <option value="HC">HC</option>
                                    <option value="FBA 14">FBA 14</option>
                                    <option value="Michael Stauffer">Michael Stauffer</option>
                                    <option value="Joe Mazzulla">Joe Mazzulla</option>
                                    <option value="Mark Daigneault">Mark Daigneault</option>
                                    <option value="Greg Popovich">Greg Popovich</option>
                                    <option value="Phil Jackson">Phil Jackson</option>
                                    <option value="Steve Kerr">Steve Kerr</option>
                                    <option value="Rick Carlisle">Rick Carlisle</option>
                                    <option value="Erik Spoelstra">Erik Spoelstra</option>
                                    <option value="Mike D'Antoni">Mike D'Antoni</option>
                                    <option value="Nick Nurse">Nick Nurse</option>
                                </select>
                                <p class="field-hint" id="technical-model-remaining"></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($isEliteOrNext): ?>
                <!-- Playbook -->
                <div class="sc">
                    <div class="sc-head">
                        <div class="sc-icon"><i class="bi bi-journal-text"></i></div>
                        <span class="sc-title">Playbook</span>
                    </div>
                    <div class="sc-body">
                        <label class="field-label">Descreva as jogadas do time</label>
                        <textarea class="form-control" name="playbook" rows="4" placeholder="Descreva o playbook do time..."></textarea>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Configurações Defensivas -->
                <div class="sc">
                    <div class="sc-head">
                        <div class="sc-icon"><i class="bi bi-shield"></i></div>
                        <span class="sc-title">Defesa e Rebotes</span>
                    </div>
                    <div class="sc-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="field-label">Agressividade Defensiva</label>
                                <select class="form-select" name="offensive_aggression">
                                    <option value="physical">Play Physical Defense</option>
                                    <option value="no_preference" selected>No Preference</option>
                                    <option value="conservative">Conservative Defense</option>
                                    <option value="neutral">Neutral Defensive Aggression</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="field-label">Rebote Ofensivo</label>
                                <select class="form-select" name="offensive_rebound">
                                    <option value="limit_transition">Limit Transition</option>
                                    <option value="no_preference" selected>No Preference</option>
                                    <option value="crash_glass">Crash Offensive Glass</option>
                                    <option value="some_crash">Some Crash, Others Get Back</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="field-label">Rebote Defensivo</label>
                                <select class="form-select" name="defensive_rebound">
                                    <option value="run_transition">Run in Transition</option>
                                    <option value="crash_glass">Crash Defensive Glass</option>
                                    <option value="some_crash">Some Crash Others Run</option>
                                    <option value="no_preference" selected>No Preference</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="field-label">Defensive Focus</label>
                                <select class="form-select" name="defensive_focus">
                                    <option value="no_preference" selected>No Preference</option>
                                    <option value="neutral">Neutral Defensive Focus</option>
                                    <option value="protect_paint">Protect the Paint</option>
                                    <option value="limit_perimeter">Limit Perimeter Shots</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Rotação e Foco -->
                <div class="sc" id="rotation-config-card">
                    <div class="sc-head">
                        <div class="sc-icon"><i class="bi bi-sliders"></i></div>
                        <span class="sc-title">Rotação e Foco</span>
                    </div>
                    <div class="sc-body">
                        <div class="row g-4">
                            <div class="col-md-6" id="rotation-players-field">
                                <label class="field-label">Jogadores na Rotação</label>
                                <select class="form-select" name="rotation_players">
                                    <?php for ($i = 8; $i <= 15; $i++): ?>
                                    <option value="<?= $i ?>" <?= $i == 10 ? 'selected' : '' ?>><?= $i ?> jogadores</option>
                                    <?php endfor; ?>
                                </select>
                                <p class="field-hint">Quantidade de jogadores que entram em quadra</p>
                            </div>
                            <div class="col-md-6" id="veteran-focus-field">
                                <label class="field-label">% Foco em Jogadores Jovens</label>
                                <input type="range" class="form-range" name="veteran_focus" id="veteran_focus" min="0" max="100" value="50">
                                <div class="range-labels"><span>Veteranos (0%)</span><span>Jovens (100%)</span></div>
                                <div class="range-val"><span id="veteran_focus-value">50</span>%</div>
                                <p class="field-hint">Define prioridade de minutos entre jovens e veteranos</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Minutagem por Jogador -->
                <div class="sc" id="player-minutes-card">
                    <div class="sc-head">
                        <div class="sc-icon"><i class="bi bi-clock"></i></div>
                        <span class="sc-title">Minutagem por Jogador</span>
                    </div>
                    <div class="sc-body">
                        <div class="info-note" style="margin-bottom:16px">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>Atenção:</strong> Titulares precisam de mín. <strong>25min</strong>. Reservas mín. <strong>5min</strong>.
                            Se não tiver 3 jogadores 85+, os <strong>5 maiores OVRs devem ter 25+ min</strong>.
                        </div>
                        <p style="font-size:12px;color:var(--text-2);margin-bottom:16px">Minutos por jogo para cada jogador (mín. 5; máx. 40 na regular / 45 nos playoffs)</p>
                        <div id="player-minutes-container" class="row g-3">
                            <!-- Preenchido dinamicamente via JS -->
                        </div>
                    </div>
                </div>

                <!-- G-League -->
                <?php if ($gleagueSlots > 0): ?>
                <div class="sc">
                    <div class="sc-head">
                        <div class="sc-icon"><i class="bi bi-arrow-down-circle"></i></div>
                        <span class="sc-title">G-League <span style="color:var(--text-3);font-weight:400">(opcional)</span></span>
                    </div>
                    <div class="sc-body">
                        <div class="info-note blue" style="margin-bottom:16px">
                            <i class="bi bi-info-circle me-1"></i>
                            Elenco com <strong><?= $playerCount ?></strong> jogadores — você pode enviar até <strong><?= $gleagueSlots ?></strong> jogador(es) para a G-League.
                            <span style="color:var(--text-3)"> · 15+ = 2 vagas | 14 = 1 vaga</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="field-label">Jogador G-League 1</label>
                                <select class="form-select" name="gleague_1_id">
                                    <option value="">Nenhum</option>
                                    <?php foreach ($players as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> — <?= $p['position'] ?> (<?= $p['ovr'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($gleagueSlots >= 2): ?>
                            <div class="col-md-6">
                                <label class="field-label">Jogador G-League 2</label>
                                <select class="form-select" name="gleague_2_id">
                                    <option value="">Nenhum</option>
                                    <?php foreach ($players as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> — <?= $p['position'] ?> (<?= $p['ovr'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Observações -->
                <div class="sc">
                    <div class="sc-head">
                        <div class="sc-icon"><i class="bi bi-chat-text"></i></div>
                        <span class="sc-title">Observações</span>
                    </div>
                    <div class="sc-body">
                        <textarea class="form-control" name="notes" rows="4" placeholder="Observações adicionais sobre sua estratégia..."></textarea>
                    </div>
                </div>

                <div class="submit-bar">
                    <button type="button" class="btn-cancel" onclick="window.location.href='/dashboard.php'">Cancelar</button>
                    <button type="submit" class="btn-submit">
                        <?php if ($directiveMode === 'profile'): ?>
                            <i class="bi bi-save2"></i> Salvar Diretriz do Time
                        <?php elseif ($hasDirectiveSubmission): ?>
                            <i class="bi bi-arrow-repeat"></i> Atualizar Diretrizes
                        <?php else: ?>
                            <i class="bi bi-send"></i> Enviar Diretrizes
                        <?php endif; ?>
                    </button>
                </div>

            </form>
        </div>
    </main>

</div><!-- /.app -->

<script>
    window.__DEADLINE_ID__    = <?= ($directiveMode === 'deadline' && $deadline) ? (int)$deadline['id'] : 'null' ?>;
    window.__SEASON_STATUS__  = <?= json_encode($seasonStatus) ?>;
    window.__DEADLINE_PHASE__ = <?= json_encode($deadline['phase'] ?? null) ?>;
    window.__DEADLINE_ISO__   = <?= json_encode($deadlineIso) ?>;
    window.__DIRECTIVE_MODE__ = <?= json_encode($directiveMode) ?>;

    // Sidebar toggle
    (function () {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sbOverlay');
        const menuBtn = document.getElementById('menuBtn');
        if (!sidebar) return;
        const close = () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); };
        if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); overlay.classList.add('show'); });
        if (overlay) overlay.addEventListener('click', close);
        document.querySelectorAll('.sb-nav a').forEach(a => a.addEventListener('click', close));
    })();

    // Range value display
    const rangeEl = document.getElementById('veteran_focus');
    const rangeVal = document.getElementById('veteran_focus-value');
    if (rangeEl && rangeVal) {
        rangeEl.addEventListener('input', () => rangeVal.textContent = rangeEl.value);
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/diretrizes.js?v=<?= time() ?>"></script>
<script src="/js/pwa.js"></script>
<script>
    const themeKey = 'fba-theme';
    const themeToggle = document.getElementById('themeToggle');
    const applyTheme = (theme) => {
        if (theme === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
            if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
            return;
        }
        document.documentElement.removeAttribute('data-theme');
        if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
    };
    applyTheme(localStorage.getItem(themeKey) || 'dark');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            localStorage.setItem(themeKey, next);
            applyTheme(next);
        });
    }
</script>
</body>
</html>
