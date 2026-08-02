<?php
session_start();
require_once 'backend/config.php';
require_once 'backend/db.php';
$pdo = db();
require_once 'backend/auth.php';
require_once 'backend/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$roletaId = (int)($_GET['id'] ?? 0);
if (!$roletaId) {
    header('Location: /roleta.php');
    exit;
}
$stmtCheck = $pdo->prepare("SELECT id, league FROM roletas WHERE id = ?");
$stmtCheck->execute([$roletaId]);
$roletaRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
if (!$roletaRow) {
    header('Location: ' . (hasAdminAccess($pdo, $user_id) ? '/roleta.php' : '/dashboard.php'));
    exit;
}

// GM da liga acompanha o sorteio (é pra isso que serve o card do dashboard);
// girar e editar seguem restritos ao admin da liga, checado na API.
$ligaRoleta = $roletaRow['league'] ? strtoupper((string)$roletaRow['league']) : null;
if (!hasAdminAccess($pdo, $user_id)) {
    $stmtLiga = $pdo->prepare('SELECT league FROM teams WHERE user_id = ? LIMIT 1');
    $stmtLiga->execute([$user_id]);
    $minhaLiga = strtoupper((string)($stmtLiga->fetchColumn() ?: ''));
    if ($ligaRoleta === null || $minhaLiga !== $ligaRoleta) {
        header('Location: /dashboard.php');
        exit;
    }
}

$team_id = $_SESSION['team_id'] ?? null;

$team = [];
if ($team_id) {
    $stmt = $pdo->prepare("SELECT id, name, city, photo_url, league FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch() ?: [];
}

$stmt = $pdo->prepare("SELECT id, name, photo_url, league, user_type, accent_color FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch() ?: [];
$user['user_type'] = $user['user_type'] ?? ($_SESSION['user_type'] ?? 'jogador');

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
    <title>Editar Roleta — FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
    <style>
        :root {
            --red: #fc0025; --red-2: color-mix(in srgb, var(--red) 85%, white); --red-soft: color-mix(in srgb, var(--red) 10%, transparent); --red-glow: color-mix(in srgb, var(--red) 18%, transparent);
            --bg: #07070a; --panel: #101013; --panel-2: #16161a; --panel-3: #1c1c21;
            --border: rgba(255,255,255,.06); --border-md: rgba(255,255,255,.10); --border-strong: var(--border-md); --border-red: color-mix(in srgb, var(--red) 22%, transparent);
            --text: #f0f0f3; --text-2: #868690; --text-3: #7d7d85;
            --green: #22c55e; --amber: #f59e0b; --blue: #3b82f6; --purple: #a855f7;
            --sidebar-w: 260px; --font: 'Montserrat', sans-serif;
            --radius: 14px; --radius-sm: 10px; --radius-xs: 6px;
            --ease: cubic-bezier(.2,.8,.2,1); --t: 200ms;
        }
        :root[data-theme="light"] {
            --bg: #f6f7fb; --panel: #ffffff; --panel-2: #f2f4f8; --panel-3: #e9edf4;
            --border: #e3e6ee; --border-md: #d7dbe6; --border-red: color-mix(in srgb, var(--red) 18%, transparent);
            --text: #111217; --text-2: #5b6270; --text-3: #657080;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }
        .app { display: flex; min-height: 100vh; }
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }
        .page-hero { padding: 32px 32px 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .hero-title { font-size: 26px; font-weight: 800; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 10px; }
        .hero-sub { font-size: 13px; color: var(--text-2); max-width: 640px; }
        .hero-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .content { padding: 18px 32px 48px; flex: 1; }
        .topbar { display: none; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); padding: 0 16px; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 200; }
        .topbar-title { font-size: 15px; font-weight: 700; color: var(--text); }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { background: transparent; border: 1px solid var(--border); color: var(--text); width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 299; }
        .sb-overlay.show { display: block; }

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
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
        .sb-nav a { font-family:'Inter',sans-serif; display: flex; align-items: center; gap: 10px; padding: 10px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
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

        .rt-chip{display:inline-flex;align-items:center;gap:6px;background:var(--panel-2);border:1px solid var(--border);border-radius:999px;padding:5px 12px;font-size:12px;font-weight:600;color:var(--text-2)}
        .rt-chip.ok{background:color-mix(in srgb,var(--green) 12%,transparent);border-color:color-mix(in srgb,var(--green) 32%,transparent);color:var(--green)}
        .rt-chip.next{background:var(--red-soft);border-color:var(--border-red);color:var(--red)}
        .rt-chip.lock{background:color-mix(in srgb,var(--amber) 12%,transparent);border-color:color-mix(in srgb,var(--amber) 32%,transparent);color:var(--amber)}
        #rtResumo{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}

        .grid{display:grid;grid-template-columns:1.05fr .95fr;gap:20px;align-items:start}
        @media(max-width:992px){.grid{grid-template-columns:1fr}}

        .card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:16px;color:var(--text)}
        .card-head{padding:15px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:9px}
        .card-head-left{display:flex;align-items:center;gap:9px}
        .card-head i{color:var(--red);font-size:15px}
        .card-head span{font-size:14px;font-weight:600}
        .card-body{padding:20px}

        .roda-area{display:flex;flex-direction:column;align-items:center;gap:20px}
        .roda-palco{position:relative;width:min(360px,86vw);height:min(360px,86vw)}
        .roda-ponteiro{position:absolute;top:-6px;left:50%;transform:translateX(-50%);width:0;height:0;z-index:5;border-left:14px solid transparent;border-right:14px solid transparent;border-top:22px solid var(--red);filter:drop-shadow(0 2px 4px rgba(0,0,0,.45))}
        .roda{width:100%;height:100%;border-radius:50%;position:relative;overflow:hidden;border:6px solid var(--panel-3);box-shadow:0 0 0 2px var(--border-md),0 20px 50px -20px rgba(0,0,0,.6);transition:transform 4.2s cubic-bezier(.12,.72,.15,1)}
        .rt-fatias{position:absolute;inset:0;border-radius:50%}
        .rt-rotulo{position:absolute;top:50%;left:50%;width:50%;height:2px;transform-origin:0 50%;display:flex;align-items:center;justify-content:flex-end;padding-right:10px}
        .rt-rotulo span{font-weight:800;color:#fff;white-space:nowrap;text-shadow:0 1px 3px rgba(0,0,0,.55)}
        .rt-roda-vazia{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;color:var(--text-3);font-size:13px;padding:24px}
        .roda-hub{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:48px;height:48px;border-radius:50%;background:var(--panel);border:3px solid var(--red);z-index:4;display:flex;align-items:center;justify-content:center;color:var(--red);font-size:17px}

        .btn-girar{background:var(--red);border:none;color:#fff;font-family:var(--font);font-weight:700;font-size:14px;border-radius:var(--radius-sm);padding:12px 28px;cursor:pointer;transition:background var(--t)}
        .btn-girar:hover:not(:disabled){background:var(--red-2)}
        .btn-girar:disabled{background:var(--panel-3);color:var(--text-3);cursor:not-allowed}
        .btn-ghost{background:transparent;border:1px solid var(--border-md);color:var(--text-2);font-family:var(--font);font-weight:600;font-size:12px;border-radius:var(--radius-xs);padding:7px 13px;cursor:pointer;transition:all var(--t)}
        .btn-ghost:hover{border-color:var(--border-red);color:var(--red);background:var(--red-soft)}
        .btn-ghost.danger:hover{border-color:#ef4444;color:#ef4444;background:rgba(239,68,68,.1)}

        #rtAnuncio{min-height:0}
        #rtAnuncio.mostrar .rt-anuncio-box{animation:anuncioEntra .55s var(--ease)}
        @keyframes anuncioEntra{0%{opacity:0;transform:scale(.86)}60%{transform:scale(1.04)}100%{opacity:1;transform:scale(1)}}
        .rt-anuncio-box{background:linear-gradient(135deg,var(--red-soft),transparent);border:1px solid var(--border-red);border-radius:var(--radius);padding:16px 20px;text-align:center;margin-bottom:16px}
        .rt-anuncio-pick{font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:var(--red)}
        .rt-anuncio-time{font-size:24px;font-weight:800;margin-top:2px}

        .rt-urna-item{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:var(--radius-sm);background:var(--panel-2);border:1px solid var(--border);margin-bottom:6px}
        .rt-urna-item img{width:30px;height:30px;border-radius:8px;object-fit:contain;background:var(--panel-3);flex-shrink:0}
        .rt-urna-txt{min-width:0;flex:1}
        .rt-urna-gm{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .rt-urna-remove{background:transparent;border:none;color:var(--text-3);cursor:pointer;font-size:14px;flex-shrink:0}
        .rt-urna-remove:hover{color:#ef4444}

        .rt-hist-linha{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--radius-sm);background:var(--panel-2);border:1px solid var(--border);margin-bottom:7px;flex-wrap:wrap}
        .rt-hist-saida{font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;min-width:62px;flex-shrink:0}
        .rt-hist-pick{font-family:var(--font);font-size:12px;font-weight:800;color:var(--red);background:var(--red-soft);border:1px solid var(--border-red);border-radius:999px;padding:3px 10px;flex-shrink:0}
        .rt-hist-logo{width:28px;height:28px;border-radius:7px;object-fit:contain;background:var(--panel-3);flex-shrink:0}
        .rt-hist-time{flex:1;min-width:120px;font-size:13px;font-weight:600;line-height:1.25}
        .rt-hist-hora{font-size:11px;color:var(--text-3);flex-shrink:0}

        .rt-vazio{text-align:center;padding:26px 16px;color:var(--text-3)}
        .rt-vazio i{font-size:26px;display:block;margin-bottom:8px}
        .rt-vazio p{font-size:12px}
        .scroll{max-height:380px;overflow-y:auto}

        .edit-locked-msg{font-size:12px;color:var(--text-3);display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm)}
        .rl-autocomplete { position: relative; }
        .rl-autocomplete-results { position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: var(--panel-2); border: 1px solid var(--border-md); border-radius: var(--radius-sm); max-height: 220px; overflow-y: auto; z-index: 50; box-shadow: 0 12px 28px rgba(0,0,0,.3); display: none; }
        .rl-autocomplete-results.show { display: block; }
        .rl-ac-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; cursor: pointer; font-size: 13px; }
        .rl-ac-item:hover { background: var(--panel-3); }
        .rl-ac-item img { width: 26px; height: 26px; border-radius: 6px; object-fit: cover; flex-shrink: 0; background: var(--panel-3); }
        .rl-ac-empty { padding: 10px 12px; font-size: 12px; color: var(--text-3); }
        .rl-check-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; color: var(--text); margin: 0; }
        .form-control { background: var(--panel-2); border: 1px solid var(--border); color: var(--text); border-radius: var(--radius-xs); font-size: 13px; }
        .form-control:focus { background: var(--panel-2); border-color: var(--red); color: var(--text); box-shadow: 0 0 0 3px var(--red-soft); }

        input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible,[tabindex]:focus-visible{outline:2px solid var(--red, #fc0025);outline-offset:2px;}
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; animation-delay: 0ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; transition-delay: 0ms !important; scroll-behavior: auto !important; } }
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .page-hero { padding: 16px 16px 0; }
            .content { padding: 16px 16px 48px; }
        }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body class="roleta-page">
<div class="app">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="sb-overlay" id="sbOverlay"></div>

    <main class="main">
        <header class="topbar">
            <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
            <div class="topbar-title">FBA <em>Admin</em></div>
        </header>

        <div class="page-hero">
            <div>
                <?php /* Esta página também é aberta por GM comum (pelo card do
                         painel), então o caminho de volta muda conforme quem entra. */ ?>
                <?php $ehAdminAqui = hasAdminAccess($pdo, $user_id); ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
                    <?php if ($ehAdminAqui): ?>
                    <a href="/roleta.php" class="btn-ghost" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px"><i class="bi bi-arrow-left"></i> Todas as roletas</a>
                    <a href="/admin.php#gestao" class="btn-ghost" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px"><i class="bi bi-sliders"></i> Voltar ao Admin</a>
                    <?php else: ?>
                    <a href="/dashboard.php" class="btn-ghost" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px"><i class="bi bi-arrow-left"></i> Voltar ao painel</a>
                    <?php endif; ?>
                </div>
                <div class="hero-eyebrow"><?= $ehAdminAqui ? 'Admin · Roletas' : 'Roleta da ' . htmlspecialchars($ligaRoleta ?? 'liga') ?></div>
                <h1 class="hero-title" id="rlTituloHero"><i class="bi bi-record-circle" style="color:var(--red)"></i><span>Carregando...</span></h1>
                <p class="hero-sub">Cada giro tira um participante da urna. Quem sai primeiro fica com a pior posição, até sobrar só 1.</p>
            </div>
            <?php /* Quem só acompanha não vê ação nenhuma — a API barra do mesmo
                     jeito, mas botão que sempre dá erro não deve nem aparecer. */ ?>
            <?php if ($ehAdminAqui): ?>
            <div class="hero-actions">
                <button class="btn-ghost" id="rtCriarDraft" style="display:none"><i class="bi bi-shuffle"></i> <span>Criar draft dessa ordem</span></button>
                <button class="btn-ghost" id="rtReiniciar"><i class="bi bi-arrow-counterclockwise"></i> Reiniciar</button>
                <button class="btn-ghost danger" id="rlExcluir"><i class="bi bi-trash"></i> Excluir roleta</button>
            </div>
            <?php endif; ?>
        </div>

        <div class="content">
            <div id="rtResumo"></div>
            <div id="rtAnuncio"></div>

            <div class="grid">
                <div>
                    <div class="card">
                        <div class="card-head"><div class="card-head-left"><i class="bi bi-record-circle"></i><span>Roleta</span></div></div>
                        <div class="card-body">
                            <div class="roda-area">
                                <div class="roda-palco">
                                    <div class="roda-ponteiro"></div>
                                    <div class="roda" id="rtRoda"></div>
                                    <div class="roda-hub"><i class="bi bi-dice-5-fill"></i></div>
                                </div>
                                <button class="btn-girar" id="rtGirar" disabled>Carregando...</button>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-head"><div class="card-head-left"><i class="bi bi-gear"></i><span>Configurações</span></div></div>
                        <div class="card-body" id="rlConfigBody">Carregando...</div>
                    </div>

                    <div class="card">
                        <div class="card-head"><div class="card-head-left"><i class="bi bi-inbox"></i><span>Ainda na urna</span></div></div>
                        <div class="card-body scroll"><div id="rtUrna"></div></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div class="card-head-left"><i class="bi bi-list-ol"></i><span>Ordem de saída</span></div>
                        <button type="button" class="btn-ghost" onclick="reCopiarTudo(this)"><i class="bi bi-clipboard me-1"></i>Copiar tudo</button>
                    </div>
                    <div class="card-body"><div id="rtHistorico"></div></div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    const ROLETA_ID = <?= (int)$roletaId ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= assetUrl('/js/roleta-editar.js') ?>"></script>
<script src="/js/pwa.js"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    const sbOverlay = document.getElementById('sbOverlay');
    const menuBtn = document.getElementById('menuBtn');
    if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); sbOverlay.classList.add('show'); });
    if (sbOverlay) sbOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); });
    const themeKey = 'fba-theme';
    const themeBtn = document.getElementById('themeToggle');
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
