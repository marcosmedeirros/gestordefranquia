<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

function isValidHexColor(?string $c): bool
{
    if ($c === null) return false;
    return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $c);
}

function slugifyTeamName(string $name): string
{
    $s = trim($name);
    if ($s === '') return '';
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s ?? '', '-');
    return $s ?: '';
}

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;

if (!$team) {
    http_response_code(404);
    echo "Time não encontrado.";
    exit;
}

$availableModules = [
    ['key' => 'roster',   'label' => 'Elenco (Jogadores)'],
    ['key' => 'lineup',   'label' => 'Escalação (Titulares)'],
    ['key' => 'titles',   'label' => 'Títulos'],
    ['key' => 'picks',    'label' => 'Picks'],
    ['key' => 'ranking',  'label' => 'Posição no Ranking'],
    ['key' => 'ai_avgs',  'label' => 'Médias IA (Idade/OVR)'],
    ['key' => 'trades',   'label' => 'Trades Feitas'],
];
$moduleKeys = array_map(fn($m) => $m['key'], $availableModules);

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $publicEnabled = isset($_POST['public_enabled']) ? 1 : 0;
    $primary = trim((string)($_POST['public_primary_color'] ?? ''));
    $secondary = trim((string)($_POST['public_secondary_color'] ?? ''));

    $modulesRaw = (string)($_POST['public_modules'] ?? '');
    $decoded = json_decode($modulesRaw, true);
    $modules = is_array($decoded) ? $decoded : [];
    $modules = array_values(array_filter($modules, fn($k) => is_string($k) && in_array($k, $moduleKeys, true)));
    $modules = array_values(array_unique($modules));

    $baseSlug = slugifyTeamName((string)($team['name'] ?? ''));
    if ($baseSlug === '') $baseSlug = 'time-' . (int)$team['id'];
    $candidate = $baseSlug;
    $i = 2;
    $stmtExists = $pdo->prepare('SELECT id FROM teams WHERE public_slug = ? AND id <> ? LIMIT 1');
    while (true) {
        $stmtExists->execute([$candidate, (int)$team['id']]);
        if (!$stmtExists->fetchColumn()) break;
        $candidate = $baseSlug . '-' . $i;
        $i++;
    }

    $primaryToSave = isValidHexColor($primary) ? $primary : null;
    $secondaryToSave = isValidHexColor($secondary) ? $secondary : null;
    $modulesToSave = $modules ? json_encode($modules, JSON_UNESCAPED_SLASHES) : null;

    try {
        $stmtUpdate = $pdo->prepare('
            UPDATE teams
            SET public_enabled = ?,
                public_slug = ?,
                public_primary_color = ?,
                public_secondary_color = ?,
                public_modules = ?
            WHERE id = ?
            LIMIT 1
        ');
        $stmtUpdate->execute([
            $publicEnabled,
            $candidate,
            $primaryToSave,
            $secondaryToSave,
            $modulesToSave,
            (int)$team['id'],
        ]);
        $stmtTeam->execute([$user['id']]);
        $team = $stmtTeam->fetch() ?: $team;
        $flash = 'Configuração salva com sucesso.';
    } catch (PDOException $e) {
        $flash = 'Erro ao salvar: ' . htmlspecialchars($e->getMessage());
        $flashType = 'danger';
    }
}

$publicUrl = null;
if (!empty($team['public_slug'])) {
    $publicUrl = '/times/' . $team['public_slug'];
}

$existingModules = [];
if (!empty($team['public_modules'])) {
    $decodedExisting = json_decode((string)$team['public_modules'], true);
    if (is_array($decodedExisting)) {
        $existingModules = array_values(array_filter($decodedExisting, fn($k) => is_string($k) && in_array($k, $moduleKeys, true)));
    }
}
if (!$existingModules) {
    $existingModules = $moduleKeys;
}

$primaryValue = $team['public_primary_color'] ?? '#fc0025';
if (!isValidHexColor($primaryValue)) $primaryValue = '#fc0025';
$secondaryValue = $team['public_secondary_color'] ?? '#ff2a44';
if (!isValidHexColor($secondaryValue)) $secondaryValue = '#ff2a44';
$isEnabled = (int)($team['public_enabled'] ?? 0) === 1;

$labelByKey = [];
foreach ($availableModules as $m) $labelByKey[$m['key']] = $m['label'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#fc0025">
    <title>Página Pública — FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
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
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

        .app { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 260px; height: 100vh;
            background: var(--panel);
            border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            z-index: 300;
            transition: transform var(--t) var(--ease);
            overflow-y: auto;
            scrollbar-width: none;
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

        /* Topbar mobile */
        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 240; }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
        .sb-overlay.show { display: block; }

        /* Main */
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }

        /* Page header */
        .page-hero { padding: 32px 32px 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .page-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .page-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
        .page-sub { font-size: 13px; color: var(--text-2); margin-top: 3px; }

        /* Content */
        .content { padding: 24px 32px 40px; flex: 1; }

        /* Cards */
        .bc { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .bc-head { padding: 16px 18px 14px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .bc-title { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .bc-title i { color: var(--red); font-size: 15px; }
        .bc-body { padding: 18px; }

        /* Module list */
        .module-item {
            display: flex; gap: 12px; align-items: center;
            padding: 11px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--panel-2);
            transition: border-color var(--t) var(--ease);
            cursor: default;
        }
        .module-item:hover { border-color: var(--border-md); }
        .module-item.dragging { opacity: .55; border-color: var(--red); }
        .module-handle { cursor: grab; color: var(--text-3); font-size: 16px; flex-shrink: 0; }
        .module-handle:active { cursor: grabbing; }

        /* Form controls inherit panel bg */
        .form-control, .form-select { background: var(--panel-2) !important; border-color: var(--border) !important; color: var(--text) !important; }
        .form-control:focus, .form-select:focus { border-color: var(--red) !important; box-shadow: 0 0 0 3px rgba(252,0,37,.12) !important; }
        .form-control::placeholder { color: var(--text-3) !important; }
        .form-check-input { background-color: var(--panel-3) !important; border-color: var(--border-md) !important; }
        .form-check-input:checked { background-color: var(--red) !important; border-color: var(--red) !important; }
        .form-check-input:focus { box-shadow: 0 0 0 3px rgba(252,0,37,.12) !important; }
        .form-label { color: var(--text-2); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
        .form-control-color { width: 46px !important; padding: 4px !important; }

        /* Flash alert */
        .flash { border-radius: var(--radius-sm); padding: 12px 16px; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
        .flash.success { background: rgba(34,197,94,.10); border: 1px solid rgba(34,197,94,.25); color: var(--green); }
        .flash.danger { background: var(--red-soft); border: 1px solid var(--border-red); color: var(--red); }

        /* Save button */
        .btn-save { background: var(--red); border: none; color: #fff; font-family: var(--font); font-size: 13px; font-weight: 700; padding: 10px 28px; border-radius: var(--radius-sm); cursor: pointer; transition: filter var(--t) var(--ease); }
        .btn-save:hover { filter: brightness(1.1); }

        .btn-outline { background: transparent; border: 1px solid var(--border-md); color: var(--text); font-family: var(--font); font-size: 13px; font-weight: 600; padding: 9px 20px; border-radius: var(--radius-sm); cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-outline:hover { border-color: var(--border-red); color: var(--red); }

        .btn-ghost-sm { background: var(--panel-2); border: 1px solid var(--border); color: var(--text-2); font-family: var(--font); font-size: 12px; font-weight: 600; padding: 6px 12px; border-radius: 8px; cursor: pointer; transition: all var(--t) var(--ease); }
        .btn-ghost-sm:hover { border-color: var(--border-md); color: var(--text); }

        /* Responsive */
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .page-hero, .content { padding-left: 16px; padding-right: 16px; }
            .page-hero { padding-top: 18px; }
        }
        @media (max-width: 640px) {
            .page-title { font-size: 20px; }
            .page-sub { font-size: 12px; }
            .bc-head { flex-direction: column; align-items: flex-start; gap: 8px; }
            .btn-ghost-sm { padding: 8px 12px; font-size: 13px; }
            .module-item { padding: 10px 12px; }
            .btn-save, .btn-outline { width: 100%; justify-content: center; }
            .d-flex.justify-content-end { flex-direction: column-reverse; }
        }
        @media (max-width: 400px) {
            .content { padding: 16px 12px 32px; }
            .page-hero { padding-left: 12px; padding-right: 12px; }
            .bc-body { padding: 14px 12px; }
        }

        a { color: inherit; }
    </style>
</head>
<body>
<div class="app">

    <!-- SIDEBAR -->
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
                 alt="<?= htmlspecialchars($team['name'] ?? '') ?>"
                 onerror="this.src='/img/default-team.png'">
            <div>
                <div class="sb-team-name"><?= htmlspecialchars(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')) ?></div>
                <div class="sb-team-league"><?= htmlspecialchars($user['league']) ?></div>
            </div>
        </div>

        <nav class="sb-nav">
            <div class="sb-section">Principal</div>
            <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
            <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
            <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
            <a href="/players.php"><i class="bi bi-person-lines-fill"></i> Jogadores</a>
            <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
            <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
            <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
            <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
            <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>

            <div class="sb-section">Liga</div>
            <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
            <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
            <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
            <a href="/mundo-fba.php"><i class="bi bi-globe2"></i> Mundo FBA</a>
            <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
            <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>

            <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
            <div class="sb-section">Admin</div>
            <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
            <?php endif; ?>

            <div class="sb-section">Conta</div>
            <a href="/settings.php"><i class="bi bi-gear-fill"></i> Minha Conta</a>
            <a href="/team-public-page.php" class="active"><i class="bi bi-megaphone-fill"></i> Página do Time</a>
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
    </header>

    <!-- MAIN -->
    <main class="main">

        <div class="page-hero">
            <div>
                <div class="page-eyebrow">Configurações · Página Pública</div>
                <h1 class="page-title">Página do Time</h1>
                <p class="page-sub">Configure cores e módulos da sua landing page pública.</p>
            </div>
            <?php if ($publicUrl && $isEnabled): ?>
            <div style="padding-top:4px">
                <a class="btn-outline" href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" rel="noopener">
                    <i class="bi bi-box-arrow-up-right"></i> Abrir página
                </a>
            </div>
            <?php endif; ?>
        </div>

        <div class="content">

            <?php if ($flash): ?>
            <div class="flash <?= $flashType === 'danger' ? 'danger' : 'success' ?> mb-4">
                <i class="bi bi-<?= $flashType === 'danger' ? 'exclamation-circle-fill' : 'check-circle-fill' ?>"></i>
                <?= htmlspecialchars($flash) ?>
            </div>
            <?php endif; ?>

            <form method="post">
                <div class="d-flex flex-column gap-3">

                    <!-- Enabled -->
                    <div style="display:flex;align-items:center;gap:14px;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="public_enabled" name="public_enabled"
                                   style="width:40px;height:22px;cursor:pointer"
                                   <?= $isEnabled ? 'checked' : '' ?>>
                        </div>
                        <label for="public_enabled" style="font-size:13px;font-weight:600;color:var(--text);cursor:pointer;margin:0">
                            Página pública ativa
                        </label>
                    </div>

                    <!-- Colors -->
                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-title"><i class="bi bi-palette-fill"></i> Cores do Time</div>
                        </div>
                        <div class="bc-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Cor primária</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color"
                                               id="primaryPicker" value="<?= htmlspecialchars($primaryValue) ?>" title="Primária">
                                        <input type="text" class="form-control" name="public_primary_color"
                                               id="primaryText" value="<?= htmlspecialchars($primaryValue) ?>" placeholder="#RRGGBB" maxlength="7">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Cor secundária</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color"
                                               id="secondaryPicker" value="<?= htmlspecialchars($secondaryValue) ?>" title="Secundária">
                                        <input type="text" class="form-control" name="public_secondary_color"
                                               id="secondaryText" value="<?= htmlspecialchars($secondaryValue) ?>" placeholder="#RRGGBB" maxlength="7">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div style="height:36px;border-radius:var(--radius-sm);background:linear-gradient(90deg,<?= htmlspecialchars($primaryValue) ?>,<?= htmlspecialchars($secondaryValue) ?>)" id="colorPreview"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modules -->
                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-title"><i class="bi bi-grid-fill"></i> Módulos</div>
                            <div style="display:flex;align-items:center;gap:8px">
                                <span style="font-size:11px;color:var(--text-3)">Arraste para ordenar</span>
                                <button type="button" class="btn-ghost-sm" id="addAllModules">+ Adicionar todos</button>
                            </div>
                        </div>
                        <div class="bc-body">
                            <input type="hidden" name="public_modules" id="public_modules"
                                   value="<?= htmlspecialchars(json_encode($existingModules, JSON_UNESCAPED_SLASHES)) ?>">

                            <div id="modulesList" class="d-flex flex-column gap-2">
                                <?php foreach ($existingModules as $k): $label = $labelByKey[$k] ?? $k; ?>
                                <div class="module-item" draggable="true" data-key="<?= htmlspecialchars($k) ?>">
                                    <div class="module-handle"><i class="bi bi-grip-vertical"></i></div>
                                    <div class="flex-grow-1">
                                        <div class="form-check m-0">
                                            <input class="form-check-input module-check" type="checkbox" checked
                                                   id="mod_<?= htmlspecialchars($k) ?>">
                                            <label class="form-check-label fw-500" for="mod_<?= htmlspecialchars($k) ?>"
                                                   style="font-size:13px;color:var(--text)">
                                                <?= htmlspecialchars($label) ?>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <button type="button" class="btn-ghost-sm move-up" title="Subir" style="padding:5px 9px">
                                            <i class="bi bi-arrow-up"></i>
                                        </button>
                                        <button type="button" class="btn-ghost-sm move-down" title="Descer" style="padding:5px 9px">
                                            <i class="bi bi-arrow-down"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="d-flex justify-content-end gap-2">
                        <?php if ($publicUrl): ?>
                        <a class="btn-outline" href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" rel="noopener">
                            <i class="bi bi-eye"></i> Visualizar
                        </a>
                        <?php endif; ?>
                        <button type="submit" class="btn-save">Salvar configurações</button>
                    </div>

                </div>
            </form>
        </div>
    </main>

</div><!-- /.app -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const allModules = <?= json_encode($availableModules, JSON_UNESCAPED_SLASHES) ?>;
    const modulesList = document.getElementById('modulesList');
    const modulesHidden = document.getElementById('public_modules');

    // Sidebar mobile
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sbOverlay');
    const menuBtn = document.getElementById('menuBtn');
    if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('show'); });
    if (overlay) overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); });

    // Theme toggle
    const themeToggle = document.getElementById('themeToggle');
    function applyTheme(t) {
        document.documentElement.dataset.theme = t;
        localStorage.setItem('fba-theme', t);
        if (themeToggle) {
            themeToggle.querySelector('i').className = t === 'dark' ? 'bi bi-moon' : 'bi bi-sun';
            themeToggle.querySelector('span').textContent = t === 'dark' ? 'Modo escuro' : 'Modo claro';
        }
    }
    applyTheme(localStorage.getItem('fba-theme') || 'dark');
    if (themeToggle) themeToggle.addEventListener('click', () => {
        applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
    });

    // Color pickers
    function syncColor(pickerId, textId) {
        const picker = document.getElementById(pickerId);
        const text = document.getElementById(textId);
        const updatePreview = () => {
            const p = document.getElementById('primaryText').value;
            const s = document.getElementById('secondaryText').value;
            const preview = document.getElementById('colorPreview');
            if (preview && /^#[0-9a-fA-F]{6}$/.test(p) && /^#[0-9a-fA-F]{6}$/.test(s)) {
                preview.style.background = `linear-gradient(90deg,${p},${s})`;
            }
        };
        picker.addEventListener('input', () => { text.value = picker.value; updatePreview(); });
        text.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) { picker.value = text.value; updatePreview(); }
        });
    }
    syncColor('primaryPicker', 'primaryText');
    syncColor('secondaryPicker', 'secondaryText');

    // Modules serialization
    function serializeModules() {
        const keys = Array.from(modulesList.querySelectorAll('.module-item'))
            .filter(el => el.querySelector('.module-check')?.checked)
            .map(el => el.getAttribute('data-key'))
            .filter(Boolean);
        modulesHidden.value = JSON.stringify(keys);
    }

    modulesList.addEventListener('change', (e) => {
        if (e.target && e.target.classList.contains('module-check')) serializeModules();
    });

    modulesList.addEventListener('click', (e) => {
        const btnUp = e.target.closest('.move-up');
        const btnDown = e.target.closest('.move-down');
        if (!btnUp && !btnDown) return;
        const item = e.target.closest('.module-item');
        if (!item) return;
        if (btnUp) {
            const prev = item.previousElementSibling;
            if (prev) modulesList.insertBefore(item, prev);
        } else {
            const next = item.nextElementSibling;
            if (next) modulesList.insertBefore(next, item);
        }
        serializeModules();
    });

    // Drag-and-drop
    let dragEl = null;
    modulesList.addEventListener('dragstart', (e) => {
        const item = e.target.closest('.module-item');
        if (!item) return;
        dragEl = item;
        item.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });
    modulesList.addEventListener('dragend', () => {
        if (dragEl) dragEl.classList.remove('dragging');
        dragEl = null;
        serializeModules();
    });
    modulesList.addEventListener('dragover', (e) => {
        e.preventDefault();
        const item = e.target.closest('.module-item');
        if (!dragEl || !item || item === dragEl) return;
        const rect = item.getBoundingClientRect();
        const before = (e.clientY - rect.top) < rect.height / 2;
        modulesList.insertBefore(dragEl, before ? item : item.nextElementSibling);
    });

    // Add all missing modules
    document.getElementById('addAllModules').addEventListener('click', () => {
        const existing = new Set(Array.from(modulesList.querySelectorAll('.module-item')).map(el => el.getAttribute('data-key')));
        for (const m of allModules) {
            if (existing.has(m.key)) continue;
            const el = document.createElement('div');
            el.className = 'module-item';
            el.setAttribute('draggable', 'true');
            el.setAttribute('data-key', m.key);
            el.innerHTML = `
                <div class="module-handle"><i class="bi bi-grip-vertical"></i></div>
                <div class="flex-grow-1">
                    <div class="form-check m-0">
                        <input class="form-check-input module-check" type="checkbox" checked id="mod_${m.key}">
                        <label class="form-check-label" for="mod_${m.key}" style="font-size:13px;color:var(--text)">${m.label}</label>
                    </div>
                </div>
                <div class="d-flex gap-1">
                    <button type="button" class="btn-ghost-sm move-up" title="Subir" style="padding:5px 9px"><i class="bi bi-arrow-up"></i></button>
                    <button type="button" class="btn-ghost-sm move-down" title="Descer" style="padding:5px 9px"><i class="bi bi-arrow-down"></i></button>
                </div>
            `;
            modulesList.appendChild(el);
        }
        serializeModules();
    });

    serializeModules();
</script>
</body>
</html>
