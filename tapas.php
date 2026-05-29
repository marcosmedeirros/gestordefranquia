<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user     = getUserSession();
$pdo      = db();
$is_admin = hasAdminAccess($pdo, (int)$user['id']);

$stmtTeam = $pdo->prepare('SELECT t.*, COUNT(p.id) as player_count FROM teams t LEFT JOIN players p ON p.team_id = t.id WHERE t.user_id = ? GROUP BY t.id ORDER BY player_count DESC, t.id DESC');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
$team_id = $team ? (int)$team['id'] : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Tapas - FBA Manager</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0a0a0c">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css?v=20260225-2">
    <style>
        :root {
            --red: #fc0025; --red-2: #ff2a44; --red-soft: rgba(252,0,37,.10);
            --bg: #07070a; --panel: #101013; --panel-2: #16161a; --panel-3: #1c1c21;
            --border: rgba(255,255,255,.06); --border-md: rgba(255,255,255,.10);
            --text: #f0f0f3; --text-2: #868690; --text-3: #48484f;
            --sidebar-w: 260px; --font: 'Poppins', sans-serif;
            --radius: 14px; --radius-sm: 10px; --ease: cubic-bezier(.2,.8,.2,1); --t: 200ms;
        }
        :root[data-theme="light"] {
            --bg: #f6f7fb; --panel: #fff; --panel-2: #f2f4f8; --panel-3: #e9edf4;
            --border: #e3e6ee; --border-md: #d7dbe6; --text: #111217; --text-2: #5b6270; --text-3: #8b93a5;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; background: var(--bg); color: var(--text); font-family: var(--font); -webkit-font-smoothing: antialiased; }
        body { overflow-x: hidden; }
        a, button { -webkit-tap-highlight-color: transparent; }
        .app { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; transition: transform var(--t) var(--ease); overflow-y: auto; scrollbar-width: none; }
        .sidebar::-webkit-scrollbar { display: none; }
        .sb-brand { display: flex; align-items: center; gap: 10px; padding: 20px 16px 12px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
        .sb-logo { width: 34px; height: 34px; background: var(--red); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 800; color: #fff; flex-shrink: 0; }
        .sb-brand-text { font-size: 13px; font-weight: 700; color: var(--text); line-height: 1.25; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-3); }
        .sb-team { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
        .sb-team img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-team-name { font-size: 12px; font-weight: 600; color: var(--text); line-height: 1.3; }
        .sb-team-league { font-size: 10px; color: var(--text-3); }
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 5px; }
        .sb-nav a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 500; color: var(--text-2); text-decoration: none; transition: background var(--t), color var(--t); }
        .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
        .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
        .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
        .sb-nav a.active i { color: var(--red); }
        .sb-theme-toggle { margin: 8px 10px; padding: 9px 10px; background: none; border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-family: var(--font); cursor: pointer; display: flex; align-items: center; gap: 8px; width: calc(100% - 20px); }
        .sb-footer { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-top: 1px solid var(--border); flex-shrink: 0; }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; }
        .sb-username { font-size: 12px; font-weight: 600; color: var(--text); flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout { color: var(--text-3); font-size: 16px; text-decoration: none; flex-shrink: 0; }
        .sb-logout:hover { color: var(--red); }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 299; }

        /* Topbar */
        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 48px; background: var(--panel); border-bottom: 1px solid var(--border); z-index: 200; align-items: center; gap: 12px; padding: 0 16px; }
        .menu-btn { background: none; border: none; color: var(--text); font-size: 22px; cursor: pointer; padding: 4px; }
        .topbar-title { font-size: 15px; font-weight: 700; color: var(--text); flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }

        /* Main */
        .main { margin-left: 260px; width: calc(100% - 260px); padding: 36px 28px 60px; min-height: 100vh; }
        .page-title { font-size: 28px; font-weight: 800; color: var(--text); }
        .page-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; color: var(--text-3); margin-bottom: 4px; }

        /* Cards/Panels */
        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; }
        .panel-title { font-size: 14px; font-weight: 700; color: var(--text); }
        .panel-sub { font-size: 12px; color: var(--text-3); margin-top: 2px; }
        .panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }

        .stat-card { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px 20px; text-align: center; }
        .stat-val { font-size: 32px; font-weight: 800; color: #f97316; line-height: 1; }
        .stat-label { font-size: 11px; color: var(--text-3); margin-top: 4px; font-weight: 500; letter-spacing: .5px; text-transform: uppercase; }

        .btn-primary-fba { background: var(--red); color: #fff; border: none; border-radius: var(--radius-sm); padding: 10px 20px; font-size: 13px; font-weight: 700; font-family: var(--font); cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: opacity .2s; }
        .btn-primary-fba:hover { opacity: .85; }
        .btn-primary-fba:disabled { opacity: .4; cursor: not-allowed; }
        .btn-ghost-sm { background: none; border: 1px solid var(--border-md); border-radius: 8px; color: var(--text-2); font-size: 12px; font-weight: 600; font-family: var(--font); padding: 6px 12px; cursor: pointer; transition: background .15s, color .15s; display: inline-flex; align-items: center; gap: 5px; }
        .btn-ghost-sm:hover { background: var(--panel-2); color: var(--text); }

        .request-row { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); }
        .badge-tapa { display: inline-flex; align-items: center; gap: 4px; background: rgba(249,115,22,.15); color: #f97316; border: 1px solid rgba(249,115,22,.35); border-radius: 999px; font-size: 10px; font-weight: 700; padding: 2px 7px; }
        .badge-approved { display: inline-flex; align-items: center; gap: 4px; background: rgba(34,197,94,.12); color: #22c55e; border: 1px solid rgba(34,197,94,.3); border-radius: 999px; font-size: 10px; font-weight: 700; padding: 2px 7px; }
        .badge-rejected { display: inline-flex; align-items: center; gap: 4px; background: rgba(239,68,68,.12); color: #ef4444; border: 1px solid rgba(239,68,68,.3); border-radius: 999px; font-size: 10px; font-weight: 700; padding: 2px 7px; }
        .badge-pending { display: inline-flex; align-items: center; gap: 4px; background: rgba(245,158,11,.12); color: #f59e0b; border: 1px solid rgba(245,158,11,.3); border-radius: 999px; font-size: 10px; font-weight: 700; padding: 2px 7px; }
        .badge-badge { display: inline-flex; align-items: center; gap: 4px; background: rgba(139,92,246,.15); color: #a78bfa; border: 1px solid rgba(139,92,246,.35); border-radius: 999px; font-size: 10px; font-weight: 700; padding: 2px 7px; }

        .player-tapa-row { display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); }

        /* Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.7); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: var(--panel); border: 1px solid var(--border-md); border-radius: var(--radius); padding: 24px; width: 100%; max-width: 420px; margin: 16px; }
        .modal-title { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .fba-select { width: 100%; background: var(--panel-2); border: 1px solid var(--border-md); border-radius: var(--radius-sm); color: var(--text); font-size: 13px; font-family: var(--font); padding: 10px 12px; outline: none; }
        .fba-select:focus { border-color: #f97316; }
        .modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .sb-overlay.open { display: block; }
            .topbar { display: flex; }
            .main { margin-left: 0; width: 100%; padding: 64px 16px 40px; }
        }
    </style>
</head>
<body>
<div class="app">

    <aside class="sidebar" id="sidebar">
        <div class="sb-brand">
            <div class="sb-logo">FBA</div>
            <div class="sb-brand-text">FBA Manager <span>Liga <?= htmlspecialchars($user['league'] ?? '') ?></span></div>
        </div>
        <?php if ($team): ?>
        <div class="sb-team">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
            <div>
                <div class="sb-team-name"><?= htmlspecialchars(trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''))) ?></div>
                <div class="sb-team-league"><?= htmlspecialchars($user['league'] ?? '') ?></div>
            </div>
        </div>
        <?php endif; ?>
        <nav class="sb-nav">
            <div class="sb-section">Principal</div>
            <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
            <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
            <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
            <a href="/players.php"><i class="bi bi-person-lines-fill"></i> Jogadores</a>
            <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
            <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
            <a href="/mercado.php"><i class="bi bi-shop"></i> Mercado</a>
            <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
            <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
            <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>
            <a href="/tapas.php" class="active"><i class="bi bi-hand-index-thumb"></i> Tapas</a>

            <div class="sb-section">Liga</div>
            <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
            <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
            <a href="/hall-da-fama.php"><i class="bi bi-award-fill"></i> Hall da Fama</a>
            <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
            <a href="/mundo-fba.php"><i class="bi bi-globe2"></i> Mundo FBA</a>
            <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
            <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>
            <a href="/thepathetic.php"><i class="bi bi-newspaper"></i> The Pathetic</a>

            <?php if ($is_admin): ?>
            <div class="sb-section">Admin</div>
            <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
            <?php endif; ?>

            <div class="sb-section">Conta</div>
            <a href="/settings.php"><i class="bi bi-gear-fill"></i> Minha Conta</a>
            <a href="/team-public-page.php"><i class="bi bi-globe2"></i> Página do Time</a>
        </nav>
        <button class="sb-theme-toggle" type="button" id="themeToggle">
            <i class="bi bi-moon"></i><span>Modo escuro</span>
        </button>
        <div class="sb-footer">
            <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>" alt="" class="sb-avatar" onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name'] ?? '') ?>&background=1c1c21&color=fc0025'">
            <span class="sb-username"><?= htmlspecialchars($user['name'] ?? '') ?></span>
            <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </aside>

    <div class="sb-overlay" id="sbOverlay"></div>

    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Manager</em></div>
    </header>

    <main class="main">
        <div style="margin-bottom:24px">
            <div class="page-eyebrow">Liga &mdash; <?= htmlspecialchars($user['league'] ?? '') ?></div>
            <h1 class="page-title"><i class="bi bi-hand-index-thumb" style="color:#f97316;font-size:.85em"></i> Tapas</h1>
        </div>

        <?php if (!$team): ?>
        <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:32px;text-align:center;color:var(--text-3)">
            Você não possui um time cadastrado.
        </div>
        <?php else: ?>

        <!-- Stat strip -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px">
            <div class="stat-card">
                <div class="stat-val" id="tapasCount">—</div>
                <div class="stat-label">Tapas disponíveis</div>
            </div>
            <div class="stat-card">
                <div class="stat-val" style="color:var(--text-3)" id="tapasUsed">—</div>
                <div class="stat-label">Tapas usados</div>
            </div>
            <div class="stat-card">
                <div class="stat-val" style="color:#f59e0b" id="tapasRequests">—</div>
                <div class="stat-label">Solicitações</div>
            </div>
        </div>

        <!-- Action -->
        <div style="margin-bottom:24px">
            <button class="btn-primary-fba" id="btnSolicitar" onclick="openSolicitarModal()" disabled>
                <i class="bi bi-hand-index-thumb"></i> Solicitar Tapa
            </button>
            <span id="semTapasMsg" style="display:none;margin-left:12px;font-size:12px;color:var(--text-3)">
                Sem tapas disponíveis no momento.
            </span>
        </div>

        <!-- Histórico do time (acordeão) -->
        <div id="historicoAccordion" style="margin-bottom:24px;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
            <button onclick="toggleHistorico()" type="button" id="historicoToggleBtn"
                style="width:100%;display:flex;align-items:center;justify-content:space-between;padding:16px 20px;background:none;border:none;color:var(--text);font-family:var(--font);cursor:pointer;text-align:left">
                <span style="display:flex;align-items:center;gap:8px;font-size:14px;font-weight:700">
                    <i class="bi bi-clock-history" style="color:#f97316"></i>
                    Histórico do Time
                    <span id="historicoCount" style="font-size:11px;font-weight:600;background:rgba(249,115,22,.15);color:#f97316;border-radius:999px;padding:1px 8px">0</span>
                </span>
                <i class="bi bi-chevron-down" id="historicoChevron" style="font-size:13px;color:var(--text-3);transition:transform .2s"></i>
            </button>
            <div id="historicoBody" style="display:none;padding:0 20px 16px">
                <div id="historicoList"><div style="text-align:center;padding:16px;color:var(--text-3);font-size:13px">Nenhum tapa ou badge concedido ainda.</div></div>
            </div>
        </div>

        <!-- Minhas solicitações -->
        <div class="panel" style="margin-bottom:24px">
            <div class="panel-header">
                <div>
                    <div class="panel-title"><i class="bi bi-clock-history" style="color:#f59e0b"></i> Minhas Solicitações</div>
                    <div class="panel-sub">Histórico de solicitações do seu time</div>
                </div>
            </div>
            <div id="myRequestsList">
                <div style="text-align:center;padding:24px;color:var(--text-3)"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
        </div>

        <!-- Jogadores que levaram tapa na liga -->
        <div class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title"><i class="bi bi-people-fill" style="color:#f97316"></i> Tapas na Liga</div>
                    <div class="panel-sub">Jogadores que receberam tapa ou badge</div>
                </div>
            </div>
            <div id="leagueRecipientsList">
                <div style="text-align:center;padding:24px;color:var(--text-3)"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
        </div>

        <?php endif; ?>
    </main>
</div>

<!-- Modal Solicitar Tapa -->
<div class="modal-overlay" id="solicitarModal">
    <div class="modal-box">
        <div class="modal-title"><i class="bi bi-hand-index-thumb" style="color:#f97316"></i> Solicitar Tapa</div>
        <div style="margin-bottom:14px">
            <label style="font-size:12px;color:var(--text-2);font-weight:600;display:block;margin-bottom:6px">Jogador</label>
            <select class="fba-select" id="playerSelect">
                <option value="">Carregando...</option>
            </select>
        </div>
        <div style="margin-bottom:14px">
            <label style="font-size:12px;color:var(--text-2);font-weight:600;display:block;margin-bottom:6px">Tipo</label>
            <div style="display:flex;gap:8px">
                <button id="solBtnTapa" onclick="setSolType('tapa')" type="button"
                    style="flex:1;padding:10px;border-radius:9px;border:1px solid rgba(249,115,22,.5);background:rgba(249,115,22,.15);color:#f97316;font-weight:700;font-size:13px;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px">
                    <i class="bi bi-hand-index-thumb"></i> Tapa
                </button>
                <button id="solBtnBadge" onclick="setSolType('badge')" type="button"
                    style="flex:1;padding:10px;border-radius:9px;border:1px solid var(--border-md);background:none;color:var(--text-2);font-weight:700;font-size:13px;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px">
                    <i class="bi bi-award"></i> Badge
                </button>
            </div>
        </div>
        <div id="solBadgeRow" style="display:none;margin-bottom:14px">
            <label style="font-size:12px;color:var(--text-2);font-weight:600;display:block;margin-bottom:6px">Nome do badge</label>
            <input id="solBadgeName" type="text" placeholder="Ex: Veterano, MVP..." class="fba-select" style="border-radius:9px">
        </div>
        <input type="hidden" id="solActionType" value="tapa">
        <div style="font-size:12px;color:var(--text-3);background:rgba(249,115,22,.08);border:1px solid rgba(249,115,22,.2);border-radius:8px;padding:10px 12px;margin-bottom:4px">
            <i class="bi bi-info-circle" style="color:#f97316"></i>
            A solicitação será enviada ao admin para aprovação.
        </div>
        <div class="modal-actions">
            <button class="btn-ghost-sm" onclick="closeSolicitarModal()">Cancelar</button>
            <button class="btn-primary-fba" onclick="submitSolicitar()">Enviar Solicitação</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/theme.js"></script>
<script>
const teamId   = <?= json_encode($team_id) ?>;
const myLeague = <?= json_encode($user['league'] ?? 'ELITE') ?>;

let myRoster = [];

async function apiFetch(url, opts) {
    const r = await fetch(url, opts);
    const d = await r.json();
    if (!r.ok || d.success === false) throw d;
    return d;
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderStatusBadge(req) {
    if (req.status === 'pending') return '<span class="badge-pending"><i class="bi bi-clock"></i> Pendente</span>';
    if (req.status === 'rejected') return '<span class="badge-rejected"><i class="bi bi-x-circle"></i> Rejeitado</span>';
    if (req.action_type === 'tapa') return '<span class="badge-approved"><i class="bi bi-check-circle"></i> Tapa dado!</span>';
    if (req.action_type === 'badge') return '<span class="badge-badge"><i class="bi bi-award"></i> Badge: ' + escHtml(req.badge_name) + '</span>';
    return '<span class="badge-approved"><i class="bi bi-check-circle"></i> Aprovado</span>';
}

async function loadMyStatus() {
    try {
        const d = await apiFetch('/api/tapas.php?action=my_status');
        document.getElementById('tapasCount').textContent = d.tapas;
        document.getElementById('tapasUsed').textContent  = d.tapas_used;
        const reqs = d.requests || [];
        document.getElementById('tapasRequests').textContent = reqs.filter(r => r.status === 'pending').length;

        const btn = document.getElementById('btnSolicitar');
        const msg = document.getElementById('semTapasMsg');
        if (d.tapas > 0) {
            btn.disabled = false;
            msg.style.display = 'none';
        } else {
            btn.disabled = true;
            msg.style.display = 'inline';
        }

        // pending/history split
        const pending  = reqs.filter(r => r.status === 'pending');
        const others   = reqs.filter(r => r.status !== 'pending');
        const approved = reqs.filter(r => r.status === 'approved');

        const list = document.getElementById('myRequestsList');
        const displayed = pending.length ? pending : others.slice(0, 5);
        if (!reqs.length) {
            list.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-3);font-size:13px">Nenhuma solicitação ainda.</div>';
        } else {
            list.innerHTML = displayed.map(req => `
                <div class="request-row" style="margin-bottom:8px">
                    <i class="bi bi-person-fill" style="color:#f97316;font-size:16px;flex-shrink:0"></i>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:600;font-size:13px;color:var(--text)">${escHtml(req.player_name)}</div>
                        <div style="font-size:11px;color:var(--text-3)">${new Date(req.created_at).toLocaleDateString('pt-BR')}
                            &bull; ${req.action_type === 'badge' ? `<span style="color:#a78bfa"><i class="bi bi-award"></i> ${escHtml(req.badge_name)}</span>` : '<span style="color:#f97316"><i class="bi bi-hand-index-thumb"></i> Tapa</span>'}
                        </div>
                    </div>
                    ${renderStatusBadge(req)}
                </div>`).join('');
        }

        // histórico acordeão
        const countEl = document.getElementById('historicoCount');
        const hlist   = document.getElementById('historicoList');
        countEl.textContent = approved.length;
        if (!approved.length) {
            hlist.innerHTML = '<div style="text-align:center;padding:16px;color:var(--text-3);font-size:13px">Nenhum tapa ou badge concedido ainda.</div>';
        } else {
            hlist.innerHTML = approved.map(r => `
                <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border)">
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:600;font-size:13px;color:var(--text)">${escHtml(r.player_name)}</div>
                        <div style="font-size:11px;color:var(--text-3)">${new Date(r.processed_at || r.created_at).toLocaleDateString('pt-BR')}</div>
                    </div>
                    ${r.action_type === 'badge'
                        ? `<span class="badge-badge"><i class="bi bi-award"></i> ${escHtml(r.badge_name)}</span>`
                        : `<span class="badge-tapa"><i class="bi bi-hand-index-thumb"></i> Tapa</span>`}
                </div>`).join('');
        }
    } catch (e) {
        document.getElementById('myRequestsList').innerHTML = '<div style="color:#ef4444;font-size:12px;padding:12px">Erro ao carregar dados.</div>';
    }
}

async function loadLeagueRecipients() {
    try {
        const d = await apiFetch('/api/tapas.php?action=league_recipients&league=' + encodeURIComponent(myLeague));
        const players = d.players || [];
        const list = document.getElementById('leagueRecipientsList');
        if (!players.length) {
            list.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-3);font-size:13px">Nenhum jogador com tapa ou badge na liga ainda.</div>';
            return;
        }
        list.innerHTML = players.map(p => `
            <div class="player-tapa-row" style="margin-bottom:8px">
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600;font-size:13px;color:var(--text)">
                        ${escHtml(p.name)}
                        ${p.tapa_count > 0 ? `<span style="display:inline-flex;align-items:center;gap:3px;margin-left:6px;font-size:11px;color:#f97316"><i class="bi bi-hand-index-thumb"></i>${p.tapa_count > 1 ? 'x' + p.tapa_count : ''}</span>` : ''}
                        ${p.badge_name ? `<span class="badge-badge" style="margin-left:6px"><i class="bi bi-award"></i> ${escHtml(p.badge_name)}</span>` : ''}
                    </div>
                    <div style="font-size:11px;color:var(--text-3)">${escHtml(p.position)} &bull; OVR ${p.ovr} &bull; ${escHtml(p.city)} ${escHtml(p.team_name)}</div>
                </div>
            </div>`).join('');
    } catch (e) {
        document.getElementById('leagueRecipientsList').innerHTML = '<div style="color:#ef4444;font-size:12px;padding:12px">Erro ao carregar.</div>';
    }
}

async function loadRoster() {
    try {
        const r = await fetch('/api/tapas.php?action=my_status');
        // roster comes from my-roster endpoint
        const rr = await fetch('/api/admin.php?action=team_players&team_id=' + teamId);
        if (rr.ok) {
            const d = await rr.json();
            myRoster = d.players || [];
        }
    } catch(e) {}
    // fallback: use PHP-rendered roster
    if (!myRoster.length) {
        myRoster = <?= json_encode(
            (function() use ($pdo, $team_id) {
                if (!$team_id) return [];
                $s = $pdo->prepare("SELECT id, name, position, ovr FROM players WHERE team_id = ? ORDER BY ovr DESC, name ASC");
                $s->execute([$team_id]);
                return $s->fetchAll(PDO::FETCH_ASSOC);
            })()
        ) ?>;
    }
}

function setSolType(type) {
    document.getElementById('solActionType').value = type;
    const isBadge = type === 'badge';
    document.getElementById('solBadgeRow').style.display = isBadge ? 'block' : 'none';
    const btnT = document.getElementById('solBtnTapa');
    const btnB = document.getElementById('solBtnBadge');
    btnT.style.background  = !isBadge ? 'rgba(249,115,22,.15)' : 'none';
    btnT.style.color       = !isBadge ? '#f97316' : 'var(--text-2)';
    btnT.style.borderColor = !isBadge ? 'rgba(249,115,22,.5)' : 'var(--border-md)';
    btnB.style.background  = isBadge ? 'rgba(139,92,246,.15)' : 'none';
    btnB.style.color       = isBadge ? '#a78bfa' : 'var(--text-2)';
    btnB.style.borderColor = isBadge ? 'rgba(139,92,246,.4)' : 'var(--border-md)';
}

function openSolicitarModal() {
    const sel = document.getElementById('playerSelect');
    sel.innerHTML = myRoster.length
        ? '<option value="">Selecione um jogador...</option>' + myRoster.map(p => `<option value="${p.id}">${p.name} (${p.position} · OVR ${p.ovr})</option>`).join('')
        : '<option value="">Sem jogadores no elenco</option>';
    setSolType('tapa');
    document.getElementById('solBadgeName').value = '';
    document.getElementById('solicitarModal').classList.add('open');
}
function closeSolicitarModal() {
    document.getElementById('solicitarModal').classList.remove('open');
}

async function submitSolicitar() {
    const playerId   = document.getElementById('playerSelect').value;
    const actionType = document.getElementById('solActionType').value;
    const badgeName  = document.getElementById('solBadgeName').value.trim();
    if (!playerId) { alert('Selecione um jogador.'); return; }
    if (actionType === 'badge' && !badgeName) { alert('Digite o nome do badge.'); return; }
    try {
        await apiFetch('/api/tapas.php?action=request_tapa', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ player_id: parseInt(playerId), action_type: actionType, badge_name: badgeName })
        });
        closeSolicitarModal();
        await loadMyStatus();
        await loadLeagueRecipients();
    } catch(e) {
        alert(e.error || 'Erro ao enviar solicitação');
    }
}

function toggleHistorico() {
    const body    = document.getElementById('historicoBody');
    const chevron = document.getElementById('historicoChevron');
    const open    = body.style.display === 'none';
    body.style.display = open ? 'block' : 'none';
    chevron.style.transform = open ? 'rotate(180deg)' : '';
}

// sidebar mobile
document.getElementById('menuBtn')?.addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sbOverlay').classList.toggle('open');
});
document.getElementById('sbOverlay')?.addEventListener('click', () => {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sbOverlay').classList.remove('open');
});

// theme toggle
const themeBtn = document.getElementById('themeToggle');
const themeIcon = themeBtn?.querySelector('i');
const themeText = themeBtn?.querySelector('span');
function applyTheme(t) {
    document.documentElement.dataset.theme = t;
    localStorage.setItem('fba-theme', t);
    if (themeIcon) themeIcon.className = t === 'dark' ? 'bi bi-moon' : 'bi bi-sun';
    if (themeText) themeText.textContent = t === 'dark' ? 'Modo escuro' : 'Modo claro';
}
applyTheme(localStorage.getItem('fba-theme') || 'dark');
themeBtn?.addEventListener('click', () => applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'));

// close modal on overlay click
document.getElementById('solicitarModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeSolicitarModal();
});

// init
<?php if ($team_id): ?>
Promise.all([loadMyStatus(), loadLeagueRecipients(), loadRoster()]);
<?php endif; ?>
</script>
</body>
</html>
