<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

// Garante coluna public_blocks
try {
    $pdo->query("SELECT public_blocks FROM teams LIMIT 0");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE teams ADD COLUMN public_blocks MEDIUMTEXT NULL"); } catch (PDOException $e2) {}
}

function isValidHexColor(?string $c): bool {
    if ($c === null) return false;
    return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $c);
}
function slugifyTeamName(string $name): string {
    $s = trim($name);
    if ($s === '') return '';
    $s = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s) ?: $s;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/','-',$s);
    $s = trim($s ?? '','-');
    return $s ?: '';
}

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;
if (!$team) { http_response_code(404); echo "Time não encontrado."; exit; }

// Módulos fixos
$fixedModules = [
    ['key'=>'roster',  'label'=>'Elenco (Jogadores)'],
    ['key'=>'lineup',  'label'=>'Escalação (Titulares)'],
    ['key'=>'titles',  'label'=>'Informações'],
    ['key'=>'picks',   'label'=>'Picks 1ª Rodada'],
    ['key'=>'ranking', 'label'=>'Posição no Ranking'],
    ['key'=>'ai_avgs', 'label'=>'Médias IA (Idade/OVR)'],
    ['key'=>'trades',  'label'=>'Trades Recentes'],
];
$fixedKeys = array_column($fixedModules,'key');

// Blocos customizados existentes
$customBlocks = [];
try {
    $raw = $team['public_blocks'] ?? '';
    if ($raw) {
        $dec = json_decode((string)$raw, true);
        if (is_array($dec)) $customBlocks = $dec;
    }
} catch (Exception $e) {}

$flash = null; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $publicEnabled = isset($_POST['public_enabled']) ? 1 : 0;
    $primary   = trim((string)($_POST['public_primary_color'] ?? ''));
    $secondary = trim((string)($_POST['public_secondary_color'] ?? ''));

    // Processar blocos submetidos
    $blocksIn  = $_POST['blocks'] ?? [];
    $savedBlocks = [];
    if (is_array($blocksIn)) {
        foreach ($blocksIn as $bid => $bdata) {
            $bid = preg_replace('/[^a-z0-9]/','',(string)$bid);
            if ($bid === '') continue;
            $savedBlocks[] = [
                'id'     => $bid,
                'label'  => substr(trim((string)($bdata['label'] ?? '')), 0, 120),
                'html'   => (string)($bdata['html'] ?? ''),
                'active' => !empty($bdata['active']),
            ];
        }
    }
    $customBlocks = $savedBlocks;

    // IDs válidos de blocos
    $validBlockKeys = array_map(fn($b) => 'block_'.$b['id'], $savedBlocks);
    $allValidKeys = array_merge($fixedKeys, $validBlockKeys);

    // Módulos ordenados
    $modulesRaw = (string)($_POST['public_modules'] ?? '');
    $decoded    = json_decode($modulesRaw, true);
    $modules    = is_array($decoded) ? $decoded : [];
    $modules    = array_values(array_filter($modules, fn($k) => is_string($k) && in_array($k, $allValidKeys, true)));
    $modules    = array_values(array_unique($modules));

    // Slug
    $baseSlug  = slugifyTeamName((string)($team['name'] ?? ''));
    if ($baseSlug === '') $baseSlug = 'time-'.(int)$team['id'];
    $candidate = $baseSlug; $i = 2;
    $stmtEx    = $pdo->prepare('SELECT id FROM teams WHERE public_slug = ? AND id <> ? LIMIT 1');
    while (true) {
        $stmtEx->execute([$candidate,(int)$team['id']]);
        if (!$stmtEx->fetchColumn()) break;
        $candidate = $baseSlug.'-'.$i; $i++;
    }

    $primarySave   = isValidHexColor($primary)   ? $primary   : null;
    $secondarySave = isValidHexColor($secondary)  ? $secondary : null;
    $modulesSave   = $modules  ? json_encode($modules,  JSON_UNESCAPED_SLASHES) : null;
    $blocksSave    = $savedBlocks ? json_encode($savedBlocks, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null;

    try {
        $pdo->prepare('UPDATE teams SET public_enabled=?,public_slug=?,public_primary_color=?,public_secondary_color=?,public_modules=?,public_blocks=? WHERE id=? LIMIT 1')
            ->execute([$publicEnabled,$candidate,$primarySave,$secondarySave,$modulesSave,$blocksSave,(int)$team['id']]);
        $stmtTeam->execute([$user['id']]);
        $team = $stmtTeam->fetch() ?: $team;
        // Re-read blocks
        $raw2 = $team['public_blocks'] ?? '';
        if ($raw2) { $dec2=json_decode((string)$raw2,true); if(is_array($dec2)) $customBlocks=$dec2; }
        $flash = 'Configuração salva com sucesso.';
    } catch (PDOException $e) {
        $flash = 'Erro ao salvar: '.$e->getMessage(); $flashType = 'danger';
    }
}

$publicUrl = !empty($team['public_slug']) ? '/times/'.$team['public_slug'] : null;

// Montar availableModules = fixos + blocos
$availableModules = $fixedModules;
foreach ($customBlocks as $b) {
    $availableModules[] = [
        'key'   => 'block_'.$b['id'],
        'label' => '📄 '.($b['label'] ?: 'Bloco sem título'),
        'block' => true,
        'bid'   => $b['id'],
    ];
}
$allKeys = array_column($availableModules,'key');

// Módulos existentes (respeitando blocos)
$existingModules = [];
if (!empty($team['public_modules'])) {
    $decodedEx = json_decode((string)$team['public_modules'], true);
    if (is_array($decodedEx))
        $existingModules = array_values(array_filter($decodedEx, fn($k) => is_string($k) && in_array($k, $allKeys, true)));
}
// Adiciona módulos fixos ausentes ao final
foreach ($fixedKeys as $fk) {
    if (!in_array($fk, $existingModules)) $existingModules[] = $fk;
}

$primaryValue   = $team['public_primary_color'] ?? '#fc0025';
if (!isValidHexColor($primaryValue))   $primaryValue   = '#fc0025';
$secondaryValue = $team['public_secondary_color'] ?? '#ff2a44';
if (!isValidHexColor($secondaryValue)) $secondaryValue = '#ff2a44';
$isEnabled = (int)($team['public_enabled'] ?? 0) === 1;

$labelByKey = array_column($availableModules, 'label', 'key');

// Mapa de blocos por id
$blockMap = [];
foreach ($customBlocks as $b) { if (!empty($b['id'])) $blockMap[$b['id']] = $b; }
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
            --red:       #fc0025; --red-2:#ff2a44;
            --red-soft:  rgba(252,0,37,.10); --red-glow:rgba(252,0,37,.18);
            --bg:        #07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
            --border:    rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
            --border-red:rgba(252,0,37,.22);
            --text:      #f0f0f3; --text-2:#868690; --text-3:#48484f;
            --green:     #22c55e; --amber:#f59e0b; --blue:#3b82f6;
            --sidebar-w: 260px; --font:'Poppins',sans-serif;
            --radius:14px; --radius-sm:10px; --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
        }
        :root[data-theme="light"] {
            --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
            --border:#e3e6ee; --border-md:#d7dbe6; --border-red:rgba(252,0,37,.18);
            --text:#111217; --text-2:#5b6270; --text-3:#8b93a5;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        html,body{height:100%;}
        body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;}
        .app{display:flex;min-height:100vh;}

        /* Sidebar */
        .sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;transition:transform var(--t) var(--ease);overflow-y:auto;scrollbar-width:none;}
        .sidebar::-webkit-scrollbar{display:none;}
        .sb-brand{padding:22px 18px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-shrink:0;}
        .sb-logo{width:34px;height:34px;border-radius:9px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#fff;flex-shrink:0;}
        .sb-brand-text{font-weight:700;font-size:15px;line-height:1.1;}
        .sb-brand-text span{display:block;font-size:11px;font-weight:400;color:var(--text-2);}
        .sb-team{margin:14px 14px 0;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;align-items:center;gap:10px;flex-shrink:0;}
        .sb-team img{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0;}
        .sb-team-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.2;}
        .sb-team-league{font-size:11px;color:var(--red);font-weight:600;}
        .sb-nav{flex:1;padding:12px 10px 8px;}
        .sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 5px;}
        .sb-nav a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease);}
        .sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0;}
        .sb-nav a:hover{background:var(--panel-2);color:var(--text);}
        .sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600;}
        .sb-nav a.active i{color:var(--red);}
        .sb-theme-toggle{margin:0 14px 12px;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--panel-2);color:var(--text);display:flex;align-items:center;justify-content:center;gap:8px;font-size:12px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease);}
        .sb-theme-toggle:hover{border-color:var(--border-red);color:var(--red);}
        .sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0;}
        .sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0;}
        .sb-username{font-size:12px;font-weight:500;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;flex-shrink:0;}
        .sb-logout:hover{background:var(--red-soft);border-color:var(--red);color:var(--red);}

        /* Topbar */
        .topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:240;}
        .topbar-title{font-weight:700;font-size:15px;flex:1;}
        .topbar-title em{color:var(--red);font-style:normal;}
        .menu-btn{width:34px;height:34px;border-radius:9px;background:var(--panel-2);border:1px solid var(--border);color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:17px;}
        .sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:250;}
        .sb-overlay.show{display:block;}

        /* Main */
        .main{margin-left:var(--sidebar-w);min-height:100vh;width:calc(100% - var(--sidebar-w));display:flex;flex-direction:column;}
        .page-hero{padding:32px 32px 0;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;}
        .page-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px;}
        .page-title{font-size:26px;font-weight:800;line-height:1.1;}
        .page-sub{font-size:13px;color:var(--text-2);margin-top:3px;}
        .content{padding:24px 32px 40px;flex:1;}

        /* Cards */
        .bc{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
        .bc-head{padding:15px 18px 13px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px;}
        .bc-title{font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
        .bc-title i{color:var(--red);font-size:15px;}
        .bc-body{padding:18px;}

        /* Module list */
        .module-item{display:flex;flex-direction:column;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--panel-2);transition:border-color var(--t) var(--ease);overflow:hidden;}
        .module-item:hover{border-color:var(--border-md);}
        .module-item.dragging{opacity:.5;border-color:var(--red);}
        .module-item.is-block{border-left:3px solid var(--red);}
        .module-item-row{display:flex;gap:10px;align-items:center;padding:10px 13px;}
        .module-item.is-block .module-item-row{cursor:pointer;user-select:none;}
        .module-handle{cursor:grab;color:var(--text-3);font-size:16px;flex-shrink:0;}
        .module-handle:active{cursor:grabbing;}

        /* Block editor inline */
        .block-editor{display:none;border-top:1px solid var(--border);padding:14px;background:var(--panel-3);-webkit-user-drag:none;}
        .block-editor.open{display:block;}
        .block-editor textarea{width:100%;min-height:140px;resize:vertical;font-family:monospace;font-size:12px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:10px;color:var(--text);outline:none;transition:border-color var(--t);}
        .block-editor textarea:focus{border-color:var(--red);}

        /* Form */
        .form-control,.form-select{background:var(--panel-2)!important;border-color:var(--border)!important;color:var(--text)!important;}
        .form-control:focus,.form-select:focus{border-color:var(--red)!important;box-shadow:0 0 0 3px rgba(252,0,37,.12)!important;}
        .form-control::placeholder{color:var(--text-3)!important;}
        .form-check-input{background-color:var(--panel-3)!important;border-color:var(--border-md)!important;}
        .form-check-input:checked{background-color:var(--red)!important;border-color:var(--red)!important;}
        .form-label{color:var(--text-2);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
        .form-control-color{width:46px!important;padding:4px!important;}

        /* Flash */
        .flash{border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;}
        .flash.success{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.25);color:var(--green);}
        .flash.danger{background:var(--red-soft);border:1px solid var(--border-red);color:var(--red);}

        /* Buttons */
        .btn-save{background:var(--red);border:none;color:#fff;font-family:var(--font);font-size:13px;font-weight:700;padding:10px 28px;border-radius:var(--radius-sm);cursor:pointer;transition:filter var(--t) var(--ease);}
        .btn-save:hover{filter:brightness(1.1);}
        .btn-outline{background:transparent;border:1px solid var(--border-md);color:var(--text);font-family:var(--font);font-size:13px;font-weight:600;padding:9px 20px;border-radius:var(--radius-sm);cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
        .btn-outline:hover{border-color:var(--border-red);color:var(--red);}
        .btn-ghost-sm{background:var(--panel-2);border:1px solid var(--border);color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;padding:6px 12px;border-radius:8px;cursor:pointer;transition:all var(--t) var(--ease);}
        .btn-ghost-sm:hover{border-color:var(--border-md);color:var(--text);}
        .btn-icon{width:28px;height:28px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--text-2);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:13px;transition:all var(--t) var(--ease);flex-shrink:0;}
        .btn-icon:hover{background:var(--red-soft);border-color:var(--red);color:var(--red);}
        .btn-icon.del:hover{background:rgba(239,68,68,.12);border-color:#ef4444;color:#ef4444;}

        /* Responsive */
        @media(max-width:992px){
            :root{--sidebar-w:0px;}
            .sidebar{transform:translateX(-260px);}
            .sidebar.open{transform:translateX(0);}
            .main{margin-left:0;width:100%;padding-top:54px;}
            .topbar{display:flex;}
            .page-hero,.content{padding-left:16px;padding-right:16px;}
            .page-hero{padding-top:18px;}
        }
        @media(max-width:640px){
            .page-title{font-size:20px;}
            .bc-head{flex-wrap:wrap;}
            .btn-save,.btn-outline{width:100%;justify-content:center;}
            .d-flex.justify-content-end{flex-direction:column-reverse;}
        }
        @media(max-width:400px){
            .content{padding:16px 12px 32px;}
            .page-hero{padding-left:12px;padding-right:12px;}
        }
        a{color:inherit;}
    </style>
</head>
<body>
<div class="app">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sb-brand">
            <div class="sb-logo">FBA</div>
            <div class="sb-brand-text">FBA Manager<span>Liga <?= htmlspecialchars($user['league']) ?></span></div>
        </div>
        <div class="sb-team">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
                 alt="" onerror="this.src='/img/default-team.png'">
            <div>
                <div class="sb-team-name"><?= htmlspecialchars(($team['city']??'').' '.($team['name']??'')) ?></div>
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
            <?php if(($user['user_type']??'jogador')==='admin'): ?>
            <div class="sb-section">Admin</div>
            <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
            <?php endif; ?>
            <div class="sb-section">Conta</div>
            <a href="/settings.php"><i class="bi bi-gear-fill"></i> Minha Conta</a>
            <a href="/team-public-page.php" class="active"><i class="bi bi-megaphone-fill"></i> Página do Time</a>
        </nav>
        <button class="sb-theme-toggle" type="button" id="themeToggle">
            <i class="bi bi-moon"></i><span>Modo escuro</span>
        </button>
        <div class="sb-footer">
            <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url']??null)) ?>"
                 class="sb-avatar" alt=""
                 onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name']) ?>&background=1c1c21&color=fc0025'">
            <span class="sb-username"><?= htmlspecialchars($user['name']) ?></span>
            <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </aside>

    <div class="sb-overlay" id="sbOverlay"></div>
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
                <p class="page-sub">Configure cores, módulos e blocos da sua landing page pública.</p>
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
            <div class="flash <?= $flashType==='danger'?'danger':'success' ?> mb-4">
                <i class="bi bi-<?= $flashType==='danger'?'exclamation-circle-fill':'check-circle-fill' ?>"></i>
                <?= htmlspecialchars($flash) ?>
            </div>
            <?php endif; ?>

            <form method="post" id="mainForm">
                <!-- Blocos — campos ocultos gerados dinamicamente pelo JS -->
                <div id="blockFields"></div>

                <div class="d-flex flex-column gap-3">

                    <!-- Ativo -->
                    <div style="display:flex;align-items:center;gap:14px;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="public_enabled" name="public_enabled"
                                   style="width:40px;height:22px;cursor:pointer"
                                   <?= $isEnabled?'checked':'' ?>>
                        </div>
                        <label for="public_enabled" style="font-size:13px;font-weight:600;color:var(--text);cursor:pointer;margin:0">
                            Página pública ativa
                        </label>
                    </div>

                    <!-- Cores -->
                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-title"><i class="bi bi-palette-fill"></i> Cores do Time</div>
                        </div>
                        <div class="bc-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Cor primária</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color" id="primaryPicker" value="<?= htmlspecialchars($primaryValue) ?>">
                                        <input type="text" class="form-control" name="public_primary_color" id="primaryText" value="<?= htmlspecialchars($primaryValue) ?>" placeholder="#RRGGBB" maxlength="7">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Cor secundária</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color" id="secondaryPicker" value="<?= htmlspecialchars($secondaryValue) ?>">
                                        <input type="text" class="form-control" name="public_secondary_color" id="secondaryText" value="<?= htmlspecialchars($secondaryValue) ?>" placeholder="#RRGGBB" maxlength="7">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div style="height:32px;border-radius:var(--radius-sm);background:linear-gradient(90deg,<?= htmlspecialchars($primaryValue) ?>,<?= htmlspecialchars($secondaryValue) ?>)" id="colorPreview"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Módulos + Blocos -->
                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-title"><i class="bi bi-grid-fill"></i> Módulos e Blocos</div>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                <span style="font-size:11px;color:var(--text-3)">Arraste para ordenar</span>
                                <button type="button" class="btn-ghost-sm" id="addBlock" style="color:var(--red);border-color:var(--border-red)">+ Novo bloco HTML</button>
                            </div>
                        </div>
                        <div class="bc-body">
                            <input type="hidden" name="public_modules" id="public_modules"
                                   value="<?= htmlspecialchars(json_encode($existingModules, JSON_UNESCAPED_SLASHES)) ?>">

                            <div id="modulesList" class="d-flex flex-column gap-2">
                                <?php foreach ($existingModules as $k):
                                    $label = $labelByKey[$k] ?? $k;
                                    $isBlock = str_starts_with($k, 'block_');
                                    $bid = $isBlock ? substr($k, 6) : null;
                                    $blockData = ($bid && isset($blockMap[$bid])) ? $blockMap[$bid] : null;
                                ?>
                                <div class="module-item <?= $isBlock?'is-block':'' ?>" draggable="true" data-key="<?= htmlspecialchars($k) ?>">
                                    <div class="module-item-row">
                                        <div class="module-handle"><i class="bi bi-grip-vertical"></i></div>
                                        <div class="flex-grow-1">
                                            <div class="form-check m-0">
                                                <input class="form-check-input module-check" type="checkbox" checked id="mod_<?= htmlspecialchars($k) ?>">
                                                <label class="form-check-label" for="mod_<?= htmlspecialchars($k) ?>"
                                                       style="font-size:13px;color:var(--text)">
                                                    <?= htmlspecialchars($label) ?>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="d-flex gap-1 align-items-center">
                                            <?php if ($isBlock): ?>
                                            <button type="button" class="btn-icon edit-block" data-bid="<?= htmlspecialchars($bid??'') ?>" title="Editar bloco"><i class="bi bi-pencil"></i></button>
                                            <button type="button" class="btn-icon del delete-block" data-bid="<?= htmlspecialchars($bid??'') ?>" title="Excluir bloco"><i class="bi bi-trash"></i></button>
                                            <?php else: ?>
                                            <button type="button" class="btn-ghost-sm move-up" title="Subir" style="padding:5px 8px"><i class="bi bi-arrow-up"></i></button>
                                            <button type="button" class="btn-ghost-sm move-down" title="Descer" style="padding:5px 8px"><i class="bi bi-arrow-down"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($isBlock && $blockData): ?>
                                    <div class="block-editor" id="editor_<?= htmlspecialchars($bid) ?>">
                                        <div class="row g-2">
                                            <div class="col-12">
                                                <label class="form-label">Título do bloco</label>
                                                <input type="text" class="form-control form-control-sm block-label-input"
                                                       data-bid="<?= htmlspecialchars($bid) ?>"
                                                       value="<?= htmlspecialchars($blockData['label']??'') ?>"
                                                       placeholder="Ex: Apresentação, Patrocinadores...">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Conteúdo HTML/Texto</label>
                                                <textarea class="block-html-input" data-bid="<?= htmlspecialchars($bid) ?>"
                                                          placeholder="Cole seu HTML aqui ou escreva o que quiser..."><?= htmlspecialchars($blockData['html']??'') ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Salvar -->
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
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── State ──────────────────────────────────────────────────
const fixedModuleKeys = <?= json_encode($fixedKeys, JSON_UNESCAPED_SLASHES) ?>;
const allFixedModules = <?= json_encode($fixedModules, JSON_UNESCAPED_SLASHES) ?>;

// Blocos iniciais vindos do PHP
let blocks = <?= json_encode(array_values($customBlocks), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

// ── Elements ───────────────────────────────────────────────
const sidebar    = document.getElementById('sidebar');
const overlay    = document.getElementById('sbOverlay');
const menuBtn    = document.getElementById('menuBtn');
const themeToggle= document.getElementById('themeToggle');
const modulesList= document.getElementById('modulesList');
const modulesHidden=document.getElementById('public_modules');
const blockFields= document.getElementById('blockFields');

// ── Sidebar mobile ─────────────────────────────────────────
if (menuBtn)  menuBtn.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('show'); });
if (overlay)  overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); });

// ── Theme ──────────────────────────────────────────────────
function applyTheme(t) {
    document.documentElement.dataset.theme = t;
    localStorage.setItem('fba-theme', t);
    const i = themeToggle.querySelector('i'), s = themeToggle.querySelector('span');
    if (i) i.className = t==='dark'?'bi bi-moon':'bi bi-sun';
    if (s) s.textContent = t==='dark'?'Modo escuro':'Modo claro';
}
applyTheme(localStorage.getItem('fba-theme')||'dark');
themeToggle.addEventListener('click', () => applyTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));

// ── Colors ─────────────────────────────────────────────────
function syncColor(pid, tid) {
    const picker = document.getElementById(pid), text = document.getElementById(tid);
    const upd = () => { const p=document.getElementById('primaryText').value, s=document.getElementById('secondaryText').value, pr=document.getElementById('colorPreview'); if(pr&&/^#[0-9a-fA-F]{6}$/.test(p)&&/^#[0-9a-fA-F]{6}$/.test(s)) pr.style.background=`linear-gradient(90deg,${p},${s})`; };
    picker.addEventListener('input', ()=>{ text.value=picker.value; upd(); });
    text.addEventListener('input', ()=>{ if(/^#[0-9a-fA-F]{6}$/.test(text.value)){ picker.value=text.value; upd(); } });
}
syncColor('primaryPicker','primaryText');
syncColor('secondaryPicker','secondaryText');

// ── Serialize modules ───────────────────────────────────────
function serializeModules() {
    const keys = Array.from(modulesList.querySelectorAll('.module-item'))
        .filter(el => el.querySelector('.module-check')?.checked)
        .map(el => el.getAttribute('data-key')).filter(Boolean);
    modulesHidden.value = JSON.stringify(keys);
}

// ── Serialize blocks to hidden inputs ──────────────────────
function serializeBlocks() {
    blockFields.innerHTML = '';
    blocks.forEach(b => {
        const mk = (n,v) => { const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; blockFields.appendChild(i); };
        mk(`blocks[${b.id}][label]`, b.label);
        mk(`blocks[${b.id}][html]`,  b.html);
        mk(`blocks[${b.id}][active]`,'1');
    });
}

// ── Build module item HTML (editor nested inside for blocks) ──
function buildModuleItem(key, label, isBlock, bid, blockLabel, blockHtml) {
    const div = document.createElement('div');
    div.className = 'module-item' + (isBlock?' is-block':'');
    div.setAttribute('draggable','true');
    div.setAttribute('data-key', key);
    const safeLabel = (blockLabel||'').replace(/"/g,'&quot;');
    const safeHtml  = (blockHtml||'').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    div.innerHTML = `
        <div class="module-item-row">
            <div class="module-handle"><i class="bi bi-grip-vertical"></i></div>
            <div class="flex-grow-1">
                <div class="form-check m-0">
                    <input class="form-check-input module-check" type="checkbox" checked id="mod_${key}">
                    <label class="form-check-label" for="mod_${key}" style="font-size:13px;color:var(--text)">${label}</label>
                </div>
            </div>
            <div class="d-flex gap-1 align-items-center">
                ${isBlock ? `
                    <button type="button" class="btn-icon edit-block" data-bid="${bid}" title="Editar"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="btn-icon del delete-block" data-bid="${bid}" title="Excluir"><i class="bi bi-trash"></i></button>
                ` : `
                    <button type="button" class="btn-ghost-sm move-up" style="padding:5px 8px"><i class="bi bi-arrow-up"></i></button>
                    <button type="button" class="btn-ghost-sm move-down" style="padding:5px 8px"><i class="bi bi-arrow-down"></i></button>
                `}
            </div>
        </div>
        ${isBlock ? `
        <div class="block-editor" id="editor_${bid}">
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label">Título do bloco</label>
                    <input type="text" class="form-control form-control-sm block-label-input" data-bid="${bid}"
                           value="${safeLabel}" placeholder="Ex: Apresentação, Patrocinadores...">
                </div>
                <div class="col-12">
                    <label class="form-label">Conteúdo HTML/Texto</label>
                    <textarea class="block-html-input" data-bid="${bid}" placeholder="Cole seu HTML aqui ou escreva o que quiser...">${safeHtml}</textarea>
                </div>
            </div>
        </div>
        ` : ''}
    `;
    return div;
}

// ── Live update block data from editor inputs ──────────────
modulesList.addEventListener('input', e => {
    const li = e.target.closest('.block-label-input');
    const hi = e.target.closest('.block-html-input');
    if (li) {
        const bid = li.dataset.bid;
        const b = blocks.find(x=>x.id===bid);
        if (b) { b.label = li.value; updateBlockLabel(bid, li.value); }
    }
    if (hi) {
        const bid = hi.dataset.bid;
        const b = blocks.find(x=>x.id===bid);
        if (b) b.html = hi.value;
    }
});

function updateBlockLabel(bid, label) {
    const item = modulesList.querySelector(`[data-key="block_${bid}"]`);
    if (item) { const lbl = item.querySelector('.form-check-label'); if (lbl) lbl.textContent = '📄 '+(label||'Bloco sem título'); }
}

// ── Module events ──────────────────────────────────────────
modulesList.addEventListener('change', e => { if(e.target?.classList.contains('module-check')) serializeModules(); });

modulesList.addEventListener('click', e => {
    // Move up/down (fixed modules)
    const btnUp   = e.target.closest('.move-up');
    const btnDown = e.target.closest('.move-down');
    if (btnUp || btnDown) {
        const item = e.target.closest('.module-item');
        if (!item) return;
        if (btnUp)   { const prev=item.previousElementSibling; if(prev) modulesList.insertBefore(item,prev); }
        else         { const next=item.nextElementSibling;     if(next) modulesList.insertBefore(next,item); }
        serializeModules(); return;
    }

    // Edit block button (pencil icon)
    const editBtn = e.target.closest('.edit-block');
    if (editBtn) {
        const item = editBtn.closest('.module-item');
        const editor = item?.querySelector('.block-editor');
        if (editor) editor.classList.toggle('open');
        return;
    }

    // Toggle editor by clicking anywhere on the block row (not buttons/inputs/labels)
    const row = e.target.closest('.module-item-row');
    if (row && !e.target.closest('button') && !e.target.closest('input') && !e.target.closest('label')) {
        const item = row.closest('.module-item');
        if (item?.classList.contains('is-block')) {
            const editor = item.querySelector('.block-editor');
            if (editor) editor.classList.toggle('open');
            return;
        }
    }

    // Delete block
    const delBtn = e.target.closest('.delete-block');
    if (delBtn) {
        if (!confirm('Excluir este bloco?')) return;
        const bid = delBtn.dataset.bid;
        blocks = blocks.filter(b => b.id !== bid);
        const item = modulesList.querySelector(`[data-key="block_${bid}"]`);
        if (item) item.remove(); // editor is nested inside, removed automatically
        serializeModules();
        serializeBlocks();
    }
});

// ── Add block ──────────────────────────────────────────────
document.getElementById('addBlock').addEventListener('click', () => {
    const bid = Math.random().toString(36).slice(2,10);
    const newBlock = {id: bid, label: '', html: '', active: true};
    blocks.push(newBlock);

    const item = buildModuleItem('block_'+bid, '📄 Bloco sem título', true, bid, '', '');
    // Open editor by default for new blocks
    item.querySelector('.block-editor')?.classList.add('open');
    modulesList.appendChild(item);

    serializeModules();
    serializeBlocks();
    item.querySelector('.block-label-input')?.focus();
});

// ── Drag and drop ──────────────────────────────────────────
let dragEl = null;
modulesList.addEventListener('dragstart', e => {
    if (e.target.closest('.block-editor')) { e.preventDefault(); return; }
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
modulesList.addEventListener('dragover', e => {
    e.preventDefault();
    const item = e.target.closest('.module-item');
    if (!dragEl || !item || item===dragEl) return;
    const rect = item.getBoundingClientRect();
    modulesList.insertBefore(dragEl, (e.clientY-rect.top)<rect.height/2 ? item : item.nextElementSibling);
});

// ── On submit: sync blocks ─────────────────────────────────
document.getElementById('mainForm').addEventListener('submit', () => {
    // Sync block data from DOM inputs one last time
    document.querySelectorAll('.block-label-input').forEach(el => {
        const b=blocks.find(x=>x.id===el.dataset.bid); if(b) b.label=el.value;
    });
    document.querySelectorAll('.block-html-input').forEach(el => {
        const b=blocks.find(x=>x.id===el.dataset.bid); if(b) b.html=el.value;
    });
    serializeBlocks();
});

// ── Init ───────────────────────────────────────────────────
serializeModules();
serializeBlocks();
</script>
</body>
</html>
