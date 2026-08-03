<?php
/**
 * Uma loteria específica — sorteia as escolhas uma a uma, com peso na chance
 * (%) de cada participante. Ver é livre pra quem é da liga; sortear, editar,
 * reiniciar e excluir são do admin dela (a API confere de novo).
 */
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

$loteriaId = (int)($_GET['id'] ?? 0);
if (!$loteriaId) {
    header('Location: /loterias-aleatorias.php');
    exit;
}

$titulo = 'Loteria';
$ligaLoteria = null;
try {
    $stmtCheck = $pdo->prepare("SELECT titulo, league FROM loterias WHERE id = ?");
    $stmtCheck->execute([$loteriaId]);
    $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        header('Location: ' . (hasAdminAccess($pdo, $user_id) ? '/loterias-aleatorias.php' : '/dashboard.php'));
        exit;
    }
    $titulo = (string)$row['titulo'];
    $ligaLoteria = $row['league'] ? strtoupper((string)$row['league']) : null;
} catch (Throwable $e) {
    // Tabela ainda não existe neste banco — o JS mostra o erro certinho.
}

$ehAdminAqui = hasAdminAccess($pdo, $user_id);
if (!$ehAdminAqui) {
    $stmtLiga = $pdo->prepare('SELECT league FROM teams WHERE user_id = ? LIMIT 1');
    $stmtLiga->execute([$user_id]);
    $minhaLiga = strtoupper((string)($stmtLiga->fetchColumn() ?: ''));
    if ($ligaLoteria === null || $minhaLiga !== $ligaLoteria) {
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
    <title><?= htmlspecialchars($titulo) ?> — Loteria</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
    <style>
    <?php include __DIR__ . '/includes/painel-css.php'; ?>

        .lot-grid { display: grid; grid-template-columns: 1.15fr .85fr; gap: 16px; align-items: start; }
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .card-head { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .card-head-left { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--text); }
        .card-head-left i { color: var(--red); }
        .card-body { padding: 18px; }

        .lot-sortear {
            width: 100%; background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 15px; font-weight: 800; letter-spacing: .3px;
            border-radius: var(--radius-sm); padding: 15px; cursor: pointer;
            transition: background var(--t), transform .1s;
        }
        .lot-sortear:hover:not(:disabled) { background: var(--red-2); }
        .lot-sortear:active:not(:disabled) { transform: scale(.99); }
        .lot-sortear:disabled { background: var(--panel-3); color: var(--text-3); cursor: not-allowed; }

        /* Bolinha grande com o resultado do último sorteio. */
        .lot-bola {
            margin: 0 auto 16px; width: 100%; max-width: 340px; min-height: 96px;
            border-radius: var(--radius); border: 1px solid var(--border-md);
            background: var(--panel-2); display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 4px; padding: 16px; text-align: center;
        }
        .lot-bola.novo { border-color: var(--red); background: var(--red-soft); animation: lotPop 500ms var(--ease); }
        @keyframes lotPop { 0% { transform: scale(.9); opacity: .4 } 60% { transform: scale(1.03) } 100% { transform: scale(1); opacity: 1 } }
        .lot-bola-pick { font-size: 11px; font-weight: 800; letter-spacing: 1.2px; text-transform: uppercase; color: var(--red); }
        .lot-bola-nome { font-size: 19px; font-weight: 800; color: var(--text); line-height: 1.2; }
        .lot-bola-chance { font-size: 11.5px; color: var(--text-3); }

        .lot-urna { display: flex; flex-direction: column; gap: 6px; }
        .lot-urna-item {
            display: flex; align-items: center; gap: 10px; padding: 9px 12px;
            background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm);
        }
        .lot-urna-item img { width: 26px; height: 26px; border-radius: 6px; object-fit: cover; flex-shrink: 0; background: var(--panel-3); }
        .lot-urna-nome { flex: 1; min-width: 0; font-size: 13px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lot-urna-chance { font-size: 12.5px; font-weight: 800; color: var(--red); flex-shrink: 0; font-variant-numeric: tabular-nums; }
        .lot-urna-base { font-size: 10.5px; color: var(--text-3); flex-shrink: 0; }

        .lot-hist { display: flex; flex-direction: column; gap: 6px; }
        .lot-hist-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); }
        .lot-hist-pick {
            width: 30px; height: 30px; flex-shrink: 0; border-radius: 8px;
            background: var(--red-soft); color: var(--red);
            display: flex; align-items: center; justify-content: center;
            font-size: 12.5px; font-weight: 800; font-variant-numeric: tabular-nums;
        }
        .lot-hist-nome { flex: 1; min-width: 0; font-size: 13px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lot-hist-chance { font-size: 11px; color: var(--text-3); flex-shrink: 0; }

        .lot-aviso { font-size: 12px; color: var(--text-2); background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 11px 13px; display: flex; gap: 9px; align-items: flex-start; }
        .lot-aviso i { color: var(--amber); flex-shrink: 0; margin-top: 1px; }

        .lot-cfg-row { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; }
        .lot-cfg-row input, .lot-cfg-row select {
            flex: 1; min-width: 0; background: var(--panel-2); border: 1px solid var(--border);
            color: var(--text); border-radius: var(--radius-xs); padding: 8px 10px;
            font-family: var(--font); font-size: 13px;
        }
        .lot-chance-edit { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
        .lot-chance-edit .lot-urna-item input {
            width: 76px; flex-shrink: 0; background: var(--panel-3); border: 1px solid var(--border-md);
            color: var(--text); border-radius: var(--radius-xs); padding: 5px 8px;
            font-family: var(--font); font-size: 12.5px; font-weight: 700; text-align: right;
        }

        @media (max-width: 992px) { .lot-grid { grid-template-columns: 1fr; } }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body class="loteria-page">
<div class="app">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="sb-overlay" id="sbOverlay"></div>

    <main class="main">
        <header class="topbar">
            <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
            <div class="topbar-title">FBA <em>Loteria</em></div>
        </header>

        <div class="page-hero">
            <div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
                    <?php if ($ehAdminAqui): ?>
                    <a href="/loterias-aleatorias.php" class="btn-ghost" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px"><i class="bi bi-arrow-left"></i> Todas as loterias</a>
                    <a href="/admin.php#gestao" class="btn-ghost" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px"><i class="bi bi-sliders"></i> Voltar ao Admin</a>
                    <?php else: ?>
                    <a href="/dashboard.php" class="btn-ghost" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px"><i class="bi bi-arrow-left"></i> Voltar ao painel</a>
                    <?php endif; ?>
                </div>
                <div class="hero-eyebrow"><?= $ehAdminAqui ? 'Admin · Loterias' : 'Loteria da ' . htmlspecialchars($ligaLoteria ?? 'liga') ?></div>
                <h1 class="hero-title" id="ltTituloHero"><i class="bi bi-dice-3-fill" style="color:var(--red)"></i><span><?= htmlspecialchars($titulo) ?></span></h1>
                <p class="hero-sub">Cada sorteio define a próxima escolha. Quem tem mais chance sai mais cedo com mais frequência — mas nada é garantido.</p>
            </div>
            <?php if ($ehAdminAqui): ?>
            <div class="hero-actions">
                <button class="btn-ghost" id="ltDuplicar"><i class="bi bi-copy"></i> Duplicar</button>
                <button class="btn-ghost" id="ltReiniciar"><i class="bi bi-arrow-counterclockwise"></i> Reiniciar</button>
                <button class="btn-ghost" id="ltExcluir" style="border-color:rgba(239,68,68,.4);color:#ef4444"><i class="bi bi-trash"></i> Excluir</button>
            </div>
            <?php endif; ?>
        </div>

        <div class="content">
            <div class="lot-grid">
                <div>
                    <div class="card" style="margin-bottom:16px">
                        <div class="card-head">
                            <div class="card-head-left"><i class="bi bi-dice-3-fill"></i><span>Sorteio</span></div>
                            <span style="font-size:11px;color:var(--text-3)" id="ltProgresso"></span>
                        </div>
                        <div class="card-body">
                            <div class="lot-bola" id="ltBola">
                                <div class="lot-bola-nome" style="color:var(--text-3);font-size:14px;font-weight:600">Carregando...</div>
                            </div>
                            <button class="lot-sortear" id="ltSortear" disabled>Carregando...</button>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-head">
                            <div class="card-head-left"><i class="bi bi-list-ol"></i><span>Ordem definida</span></div>
                            <button type="button" class="btn-ghost" id="ltCopiar"><i class="bi bi-clipboard me-1"></i>Copiar ordem</button>
                        </div>
                        <div class="card-body">
                            <div class="lot-hist" id="ltHistorico"></div>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="card" style="margin-bottom:16px">
                        <div class="card-head">
                            <div class="card-head-left"><i class="bi bi-percent"></i><span>Ainda na urna</span></div>
                            <span style="font-size:11px;color:var(--text-3)">chance da próxima</span>
                        </div>
                        <div class="card-body">
                            <div class="lot-urna" id="ltUrna"></div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-head">
                            <div class="card-head-left"><i class="bi bi-gear-fill"></i><span>Configuração</span></div>
                        </div>
                        <div class="card-body" id="ltConfigBody"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    window.LOTERIA_ID = <?= (int)$loteriaId ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= assetUrl('/js/loteria-aleatoria.js') ?>"></script>
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
