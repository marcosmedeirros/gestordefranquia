<?php
/**
 * Central das Loterias Aleatórias — lista as loterias da liga e abre o modal
 * de criação (participantes + chance de cada um). O sorteio em si acontece em
 * loteria-aleatoria.php.
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

$user_id = $_SESSION['user_id'];
if (!hasAdminAccess($pdo, (int)$user_id)) {
    header('Location: /dashboard.php');
    exit;
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
    <title>Loterias — FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
    <style>
    <?php include __DIR__ . '/includes/painel-css.php'; ?>

        /* ── Lista de participantes com a chance de cada um ── */
        .lot-lista { display: flex; flex-direction: column; gap: 6px; margin-top: 10px; }
        .lot-row {
            display: flex; align-items: center; gap: 8px;
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 7px 10px;
        }
        .lot-row-nome {
            flex: 1; min-width: 0; font-size: 12.5px; font-weight: 600; color: var(--text);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .lot-row input {
            width: 76px; flex-shrink: 0; background: var(--panel-3);
            border: 1px solid var(--border-md); color: var(--text);
            border-radius: var(--radius-xs); padding: 5px 8px;
            font-family: var(--font); font-size: 12.5px; font-weight: 700; text-align: right;
        }
        .lot-row-pct { font-size: 12px; color: var(--text-3); flex-shrink: 0; }
        .lot-row button {
            background: transparent; border: none; color: var(--text-3);
            width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px;
        }
        .lot-row button:hover { background: var(--red-soft); color: var(--red); }
        .lot-soma { margin-top: 10px; font-size: 12px; font-weight: 700; display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
        .lot-soma.ok { color: var(--green); }
        .lot-soma.alerta { color: var(--amber); }
        .lot-soma small { font-weight: 400; color: var(--text-3); }
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
            <div class="topbar-title">FBA <em>Admin</em></div>
        </header>

        <div class="page-hero">
            <div>
                <div class="hero-eyebrow">Admin · Gestão</div>
                <h1 class="hero-title"><i class="bi bi-dice-3-fill" style="color:var(--red)"></i>Loterias</h1>
                <p class="hero-sub">Monte a loteria com quem você quiser e a chance (%) de cada um. Cada sorteio define a próxima escolha — quem sai primeiro leva a escolha 1.</p>
            </div>
            <div class="hero-actions">
                <a class="btn-ghost" href="/admin.php#gestao"><i class="bi bi-arrow-left me-1"></i>Voltar ao Admin</a>
                <button type="button" class="btn-orange" id="btnNovaLoteriaTop"><i class="bi bi-plus-lg me-1"></i>Nova Loteria</button>
            </div>
        </div>

        <div class="content">
            <div class="roleta-grid" id="loteriaGrid">
                <div class="empty"><p>Carregando...</p></div>
            </div>
        </div>
    </main>
</div>

<!-- Modal: criar loteria -->
<div class="modal fade" id="modalNovaLoteria" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--panel);border:1px solid var(--border);color:var(--text)">
      <div class="modal-header" style="border-color:var(--border)">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2" style="color:var(--red)"></i>Nova Loteria</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body rl-modal-body">
        <div class="mb-3">
          <div class="form-label">Título</div>
          <input type="text" id="ltTitulo" class="form-control" placeholder="Ex: Loteria da Temporada 6" style="background:var(--panel-2);border:1px solid var(--border);color:var(--text)">
        </div>

        <div class="mb-3">
          <div class="form-label">Liga</div>
          <select id="ltLiga" class="form-control" style="background:var(--panel-2);border:1px solid var(--border);color:var(--text)">
            <?php foreach (getAdminLeagues($pdo, (int)$user_id) as $lgOpt): ?>
            <option value="<?= htmlspecialchars($lgOpt) ?>"><?= htmlspecialchars($lgOpt) ?></option>
            <?php endforeach; ?>
          </select>
          <small style="color:var(--text-3);font-size:11px">A loteria aparece no painel dos GMs desta liga, e só o admin dela pode sortear.</small>
        </div>

        <div class="form-label">Tipo de participante</div>
        <div class="rl-tipo-tabs">
          <div class="rl-tipo-tab" data-tipo="gms">GMs</div>
          <div class="rl-tipo-tab active" data-tipo="times">Times</div>
          <div class="rl-tipo-tab" data-tipo="personalizado">Personalizado</div>
        </div>

        <div id="ltBuscaWrap">
          <div class="form-label">Adicionar participantes</div>
          <div class="rl-autocomplete">
            <input type="text" id="ltBusca" class="form-control" placeholder="Digite o nome do GM ou do time..." style="background:var(--panel-2);border:1px solid var(--border);color:var(--text)" autocomplete="off">
            <div class="rl-autocomplete-results" id="ltBuscaResultados"></div>
          </div>
        </div>
        <div id="ltPersonalizadoWrap" style="display:none">
          <div class="form-label">Adicionar participante</div>
          <div style="display:flex;gap:8px">
            <input type="text" id="ltNomeLivre" class="form-control" placeholder="Nome do participante" style="background:var(--panel-2);border:1px solid var(--border);color:var(--text)">
            <button type="button" class="btn-orange" id="btnAddNomeLivreLt" style="padding:8px 16px"><i class="bi bi-plus-lg"></i></button>
          </div>
        </div>

        <div class="lot-lista" id="ltLista"></div>
        <div class="lot-soma" id="ltSoma"></div>

        <label class="rl-check-row">
          <input type="checkbox" id="ltNotificar" checked style="width:16px;height:16px">
          <span><span style="display:block">Notificar a cada escolha definida</span><small>Manda push pros participantes cadastrados avisando quem levou cada escolha.</small></span>
        </label>
      </div>
      <div class="modal-footer" style="border-color:var(--border)">
        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-orange" id="btnCriarLoteria">Criar loteria</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= assetUrl('/js/loterias-aleatorias.js') ?>"></script>
<script src="<?= assetUrl('/js/pwa.js') ?>"></script>
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
