<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

// Só admin — não fica em menu nenhum, acesso só por quem tem o link.
if (!hasAdminAccess($pdo, (int)$user['id'])) {
    header('Location: /dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="theme-color" content="#d4af37">
    <meta name="robots" content="noindex, nofollow">
    <title>Badges — Draft de Lendas — FBA Manager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
    <style>
        :root {
            --red: #d4af37; --red-2: color-mix(in srgb, var(--red) 85%, white); --red-soft: color-mix(in srgb, var(--red) 10%, transparent); --red-glow: color-mix(in srgb, var(--red) 18%, transparent);
            --bg: #07070a; --panel: #101013; --panel-2: #16161a; --panel-3: #1c1c21;
            --border: rgba(255,255,255,.06); --border-md: rgba(255,255,255,.10); --border-red: color-mix(in srgb, var(--red) 22%, transparent);
            --text: #f0f0f3; --text-2: #868690; --text-3: #7d7d85;
            --green: #22c55e; --amber: #f59e0b; --blue: #3b82f6;
            --font: 'Montserrat', sans-serif;
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
        .main { min-height: 100vh; display: flex; flex-direction: column; }
        .page-hero { padding: 32px 32px 0; }
        .page-hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .page-hero-title { font-size: 26px; font-weight: 800; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 10px; }
        .page-hero-sub { font-size: 13px; color: var(--text-2); max-width: 720px; }
        .content { padding: 20px 32px 48px; flex: 1; }

        .lb-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .lb-stat { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 16px; }
        .lb-stat-val { font-size: 22px; font-weight: 800; color: var(--text); }
        .lb-stat-label { font-size: 11px; color: var(--text-3); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-top: 2px; }

        .lb-banner { background: var(--red-soft); border: 1px solid var(--border-red); border-radius: var(--radius-sm); padding: 12px 16px; margin-bottom: 20px; font-size: 12.5px; color: var(--text-2); display: flex; align-items: center; gap: 10px; }
        .lb-banner i { color: var(--red); font-size: 16px; flex-shrink: 0; }

        .lb-search { width: 100%; max-width: 340px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); border-radius: var(--radius-xs); font-size: 13px; padding: 9px 12px; font-family: var(--font); margin-bottom: 16px; }

        .lb-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 18px; margin-bottom: 10px; }
        .lb-card.vazio { border-color: color-mix(in srgb, #ef4444 30%, var(--border)); }
        .lb-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .lb-pick { width: 30px; height: 30px; border-radius: 50%; background: var(--panel-3); color: var(--text-3); font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .lb-photo { width: 30px; height: 30px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; background: var(--panel-3); }
        .lb-gm { font-size: 13px; font-weight: 700; color: var(--text); }
        .lb-team { font-size: 11px; color: var(--text-3); }
        .lb-jogador { flex: 1 1 200px; min-width: 0; font-size: 13px; color: var(--text-2); }
        .lb-jogador b { color: var(--red); font-size: 14px; font-weight: 800; }
        .lb-tokens { margin-left: auto; font-size: 11px; font-weight: 700; color: var(--text-3); background: var(--panel-3); border: 1px solid var(--border); border-radius: 999px; padding: 3px 10px; font-variant-numeric: tabular-nums; flex-shrink: 0; }
        .lb-tokens.zero { color: #ef4444; border-color: color-mix(in srgb, #ef4444 35%, transparent); background: rgba(239,68,68,.08); }

        .lb-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .lb-badge { font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; color: #fff; display: inline-flex; align-items: center; gap: 5px; }
        .lb-badge[data-tier="bronze"] { background: #a16207; }
        .lb-badge[data-tier="silver"] { background: #94a3b8; }
        .lb-badge[data-tier="gold"] { background: #eab308; color: #1c1c05; }
        .lb-badge[data-tier="hof"] { background: #a855f7; }
        .lb-badge[data-tier="legend"] { background: #ef4444; }
        .lb-badge-cat { opacity: .75; font-weight: 500; }

        .lb-empty-badges { font-size: 12px; color: var(--text-3); font-style: italic; margin-top: 8px; }

        .lb-vazio { text-align: center; padding: 60px 16px; color: var(--text-3); }
        .lb-vazio i { font-size: 32px; display: block; margin-bottom: 10px; }

        @media (max-width: 640px) {
            .page-hero { padding: 20px 16px 0; }
            .content { padding: 16px 16px 40px; }
            .lb-tokens { margin-left: 0; }
        }
    </style>
</head>
<body>
<main class="main">
    <div class="page-hero">
        <div class="page-hero-eyebrow">Admin · Draft de Lendas</div>
        <h1 class="page-hero-title"><i class="bi bi-award-fill" style="color:var(--red)"></i>Badges dos jogadores</h1>
        <p class="page-hero-sub">Como cada GM configurou as badges do próprio jogador lenda. Página só de leitura, acessível apenas por quem tem este link.</p>
    </div>
    <div class="content">
        <div id="lbBody"><div class="lb-vazio"><i class="bi bi-hourglass-split"></i><p>Carregando...</p></div></div>
    </div>
</main>

<script>
    const themeKey = 'fba-theme';
    document.documentElement.dataset.theme = localStorage.getItem(themeKey) || 'dark';
</script>
<script src="<?= assetUrl('/js/legends-draft-badges.js') ?>"></script>
</body>
</html>
