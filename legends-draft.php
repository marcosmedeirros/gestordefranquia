<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Preview de badges pro admin: mostra a tela de customização com um jogador
// de exemplo, sem precisar que o draft de verdade tenha terminado. Só o
// admin acessa (?preview_badges=1) e o botão de salvar fica desativado.
$isAdmin = hasAdminAccess($pdo, (int)$user['id']);
$previewBadges = $isAdmin && isset($_GET['preview_badges']);

$team_id = $_SESSION['team_id'] ?? null;
$team = [];
if ($team_id) {
    $stmt = $pdo->prepare("SELECT id, name, city, photo_url, league FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch() ?: [];
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
    <title>Draft de Lendas — FBA Manager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
    <style>
        :root {
            /* Draft de Lendas tem identidade própria em dourado, fixa — não segue a cor de destaque pessoal do usuário (ver override no fim deste bloco). */
            --red: #d4af37; --red-2: color-mix(in srgb, var(--red) 85%, white); --red-soft: color-mix(in srgb, var(--red) 10%, transparent); --red-glow: color-mix(in srgb, var(--red) 18%, transparent);
            --bg: #07070a; --panel: #101013; --panel-2: #16161a; --panel-3: #1c1c21;
            --border: rgba(255,255,255,.06); --border-md: rgba(255,255,255,.10); --border-red: color-mix(in srgb, var(--red) 22%, transparent);
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
        .page-hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin: 32px 32px 4px; }
        .page-hero-title { font-size: 26px; font-weight: 800; color: var(--text); margin: 0 32px 4px; display: flex; align-items: center; gap: 10px; }
        .page-hero-sub { font-size: 13px; color: var(--text-2); margin: 0 32px 18px; max-width: 720px; }
        .content { padding: 0 32px 48px; flex: 1; }
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

        .card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:16px;color:var(--text)}
        .card-head{padding:15px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:9px;flex-wrap:wrap}
        .card-head-left{display:flex;align-items:center;gap:9px}
        .card-head i{color:var(--red);font-size:15px}
        .card-head span{font-size:14px;font-weight:600}
        .card-body{padding:20px}

        .ld-regras{font-size:12.5px;color:var(--text-2);line-height:1.6}
        .ld-regras b{color:var(--text)}
        .ld-regras ul{margin:8px 0 0 18px;padding:0}

        .ld-vazio{text-align:center;padding:40px 16px;color:var(--text-3)}
        .ld-vazio i{font-size:30px;display:block;margin-bottom:10px}

        .ld-progress{height:6px;border-radius:999px;background:var(--panel-3);overflow:hidden;margin-bottom:16px}
        .ld-progress>div{height:100%;background:var(--red);transition:width .3s}

        .ld-turno{background:linear-gradient(135deg,var(--red-soft),transparent);border:1px solid var(--border-red);border-radius:var(--radius);padding:16px 20px;margin-bottom:16px}
        .ld-turno-label{font-size:11px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
        .ld-turno-gm{font-size:20px;font-weight:800}

        .ld-form-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
        .ld-form-row input, .ld-form-row select{background:var(--panel-2);border:1px solid var(--border);color:var(--text);border-radius:var(--radius-xs);font-size:13px;padding:9px 12px;font-family:var(--font)}
        .ld-form-row input{flex:1;min-width:200px}

        .ld-board{display:flex;flex-direction:column;gap:6px}
        .ld-row{display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:var(--radius-sm);background:var(--panel-2);border:1px solid var(--border);flex-wrap:wrap}
        .ld-row.atual{border-color:var(--border-red);background:var(--red-soft)}
        .ld-row.eu{border-color:color-mix(in srgb, var(--blue) 40%, transparent)}
        .ld-row-pick{width:30px;height:30px;border-radius:50%;background:var(--panel-3);color:var(--text-3);font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .ld-row-gm{font-size:13px;font-weight:600;width:180px;flex-shrink:0}
        .ld-row-jogador{flex:1 1 200px;min-width:0;font-size:13px;color:var(--text-2);word-break:break-word}
        .ld-row-jogador i.bi-star-fill{color:var(--red);font-size:11px;margin-right:6px}
        .ld-row-jogador b{color:var(--red);font-size:14.5px;font-weight:800;letter-spacing:.2px}
        .ld-row-meta{color:var(--text-2);font-size:12px;margin-left:4px}
        .ld-badge-tag{font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;flex-shrink:0}

        .ld-badges-cat{margin-bottom:16px}
        .ld-badges-cat-title{font-size:11px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:8px}
        .ld-badge-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);flex-wrap:wrap}
        .ld-badge-row:last-child{border-bottom:none}
        .ld-badge-nome{font-size:13px;font-weight:600;flex:1 1 auto;min-width:0}
        .ld-tier-picker{display:flex;gap:4px;flex-wrap:wrap}
        .ld-tier-btn{width:30px;height:26px;border-radius:6px;border:1px solid var(--border-md);background:var(--panel-3);color:var(--text-3);font-size:10px;font-weight:800;cursor:pointer;transition:all var(--t)}
        .ld-tier-btn.on{border-color:transparent;color:#fff}
        .ld-tier-btn[data-tier="bronze"].on{background:#a16207}
        .ld-tier-btn[data-tier="silver"].on{background:#94a3b8}
        .ld-tier-btn[data-tier="gold"].on{background:#eab308}
        .ld-tier-btn[data-tier="hof"].on{background:#a855f7}
        .ld-tier-btn[data-tier="legend"].on{background:#ef4444}

        .ld-tokens-bar{position:sticky;top:0;z-index:20;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap}
        .ld-tokens-num{font-family:'Oswald',sans-serif;font-size:22px;font-weight:800}
        .ld-tokens-num.over{color:#ef4444}

        .ld-legenda{display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:var(--text-2);margin-bottom:16px;padding:0 2px}
        .ld-legenda-letra{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:5px;font-size:9px;font-weight:800;color:#fff;margin-right:4px}
        .ld-legenda-letra[data-tier="bronze"]{background:#a16207}
        .ld-legenda-letra[data-tier="silver"]{background:#94a3b8}
        .ld-legenda-letra[data-tier="gold"]{background:#eab308}
        .ld-legenda-letra[data-tier="hof"]{background:#a855f7}
        .ld-legenda-letra[data-tier="legend"]{background:#ef4444}

        .btn-orange { background: var(--red); border: none; color: #fff; font-weight: 600; font-size: 13px; border-radius: var(--radius-xs); padding: 10px 20px; transition: background var(--t); cursor: pointer; }
        .btn-orange:hover, .btn-orange:focus { background: var(--red-2); color: #fff; }
        .btn-orange:disabled { background: var(--panel-3); color: var(--text-3); cursor: not-allowed; }
        .btn-ghost { background: transparent; border: 1px solid var(--border-md); color: var(--text-2); font-weight: 600; font-size: 12px; border-radius: var(--radius-xs); padding: 6px 12px; transition: all var(--t); cursor: pointer; }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

        .ld-btn-pular{ background:transparent;border:1px solid var(--border-md);color:var(--text-2);font-weight:600;font-size:13px;border-radius:var(--radius-xs);padding:9px 16px;cursor:pointer;transition:all var(--t) }
        .ld-btn-pular:hover{ border-color:rgba(239,68,68,.4);color:#ef4444;background:rgba(239,68,68,.06) }
        .ld-pulada{ color:var(--text-3);font-style:italic }
        .ld-btn-preencher{ background:transparent;border:1px solid var(--border-md);color:var(--text-2);font-weight:600;font-size:11px;border-radius:var(--radius-xs);padding:3px 10px;margin-left:8px;cursor:pointer;transition:all var(--t) }
        .ld-btn-preencher:hover{ border-color:var(--border-red);color:var(--red);background:var(--red-soft) }
        .ld-btn-desfazer, .ld-btn-despular{ background:transparent;border:1px solid var(--border-md);color:var(--text-3);border-radius:var(--radius-xs);padding:3px 7px;margin-left:8px;cursor:pointer;font-size:12px;line-height:1;transition:all var(--t) }
        .ld-btn-desfazer:hover, .ld-btn-despular:hover{ border-color:var(--border-red);color:var(--red);background:var(--red-soft) }

        .ld-modal-overlay{ display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:500;align-items:center;justify-content:center;padding:20px }
        .ld-modal-overlay.open{ display:flex }
        .ld-modal{ background:var(--panel);border:1px solid var(--border-md);border-radius:var(--radius);padding:22px;max-width:380px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.5) }
        .ld-modal-title{ font-size:16px;font-weight:800;margin-bottom:10px;display:flex;align-items:center;gap:8px;color:var(--text) }
        .ld-modal-title i{ color:#ef4444 }
        .ld-modal-text{ font-size:13px;color:var(--text-2);line-height:1.5;margin-bottom:18px }
        .ld-modal-actions{ display:flex;gap:10px;justify-content:flex-end }

        input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible,[tabindex]:focus-visible{outline:2px solid var(--red, #fc0025);outline-offset:2px;}
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; } }
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .page-hero-eyebrow, .page-hero-title, .page-hero-sub { margin-left: 16px; margin-right: 16px; }
            .content { padding: 0 16px 48px; }
            .ld-tokens-bar { top: 54px; }
        }
        @media (max-width: 480px) {
            .card-body { padding: 14px; }
            .ld-row-gm { width: auto; flex-basis: 100%; }
            .ld-row-jogador { flex-basis: 100%; }
            .ld-form-row input { min-width: 0; flex-basis: 100%; }
            .ld-tokens-bar { flex-direction: column; align-items: flex-start; }
            .ld-tokens-bar button { align-self: stretch; }
        }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
        /* Reforça o dourado por cima da cor de destaque pessoal (accent-color.php acima também usa !important). */
        :root{--red:#d4af37 !important}
    </style>
</head>
<body>
<div class="app">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="sb-overlay" id="sbOverlay"></div>

    <main class="main">
        <header class="topbar">
            <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
            <div class="topbar-title">FBA <em>Draft de Lendas</em></div>
        </header>

        <div class="page-hero-eyebrow">FBA · Roleta dos 32</div>
        <h1 class="page-hero-title"><i class="bi bi-stars" style="color:var(--red)"></i>Draft de Lendas</h1>
        <p class="page-hero-sub">Depois que a Roleta dos 32 termina, a ordem de escolha vira a ordem deste draft. Cada GM escolhe 1 jogador lenda (nome + posição), sempre OVR 80 e 19 anos. No fim, cada um customiza as badges do seu jogador com um orçamento de tokens.</p>

        <?php if ($previewBadges): ?>
        <div style="margin:0 32px 18px;padding:12px 16px;border-radius:10px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);color:var(--amber);font-size:12.5px">
            <i class="bi bi-eye-fill" style="margin-right:6px"></i> Modo preview (só admin) — jogador de exemplo, o botão "Salvar badges" não grava nada de verdade.
        </div>
        <?php endif; ?>

        <div class="content" id="ldContent">
            <div class="ld-vazio"><i class="bi bi-hourglass-split"></i><p>Carregando...</p></div>
        </div>
    </main>
</div>

<div class="ld-modal-overlay" id="ldPularModalOverlay">
    <div class="ld-modal">
        <div class="ld-modal-title"><i class="bi bi-skip-forward-fill"></i> Pular esta escolha?</div>
        <p class="ld-modal-text" id="ldPularModalText"></p>
        <div class="ld-modal-actions">
            <button type="button" class="btn-ghost" onclick="ldFecharModalPular()">Cancelar</button>
            <button type="button" class="btn-orange" id="btnLdConfirmarPular">Pular</button>
        </div>
    </div>
</div>

<div class="ld-modal-overlay" id="ldPreencherModalOverlay">
    <div class="ld-modal">
        <div class="ld-modal-title"><i class="bi bi-star-fill"></i> Escolher jogador</div>
        <p class="ld-modal-text" id="ldPreencherModalText"></p>
        <div class="ld-form-row" style="margin-top:0">
            <input type="text" id="ldPreencherNome" placeholder="Nome do jogador (lenda)" maxlength="150">
            <select id="ldPreencherPosicao">
                <option value="PG">PG</option>
                <option value="SG">SG</option>
                <option value="SF">SF</option>
                <option value="PF">PF</option>
                <option value="C">C</option>
            </select>
        </div>
        <div class="ld-modal-actions" style="margin-top:16px">
            <button type="button" class="btn-ghost" onclick="ldFecharModalPreencher()">Cancelar</button>
            <button type="button" class="btn-orange" id="btnLdConfirmarPreencher">Confirmar</button>
        </div>
    </div>
</div>

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
    window.SESSION_USER_ID = <?= (int)$user['id'] ?>;
    window.LD_PREVIEW_BADGES = <?= $previewBadges ? 'true' : 'false' ?>;
</script>
<script src="<?= assetUrl('/js/legends-draft.js') ?>"></script>
<script src="/js/pwa.js"></script>
</body>
</html>
