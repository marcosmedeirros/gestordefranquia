<?php
session_start();
require_once 'backend/config.php';
require_once 'backend/db.php';
$pdo = db();
require_once 'backend/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$is_admin = hasAdminAccess($pdo, (int)$user_id);
$team_id = $_SESSION['team_id'] ?? null;
$league_id = $_SESSION['current_league_id'] ?? null;

if (!$team_id) {
    $select = ['id', 'league', 'name'];
    try {
        $stmtCheck = $pdo->prepare("SHOW COLUMNS FROM teams LIKE 'league_id'");
        $stmtCheck->execute();
        if ($stmtCheck->fetch()) {
            $select[] = 'league_id';
        }
    } catch (Exception $e) {
        // ignore
    }

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM teams WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $teamRow = $stmt->fetch();
    if ($teamRow) {
        $team_id = (int)$teamRow['id'];
        if (!$league_id && !empty($teamRow['league_id'])) {
            $league_id = (int)$teamRow['league_id'];
        } elseif (!$league_id && !empty($teamRow['league'])) {
            $stmtLeague = $pdo->prepare('SELECT id FROM leagues WHERE name = ? LIMIT 1');
            $stmtLeague->execute([$teamRow['league']]);
            $leagueRow = $stmtLeague->fetch();
            if ($leagueRow && !empty($leagueRow['id'])) {
                $league_id = (int)$leagueRow['id'];
            }
        }
    }
}

$team_name = '';
$team_sidebar = [];
if ($team_id) {
    $stmt = $pdo->prepare("SELECT id, name, city, photo_url, league FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team_sidebar = $stmt->fetch() ?: [];
    $team_name = $team_sidebar['name'] ?? '';
}

// Dados do usuário para a sidebar
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
    <title>Leilão — FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
    <style>
        :root {
            --red: #fc0025; --red-2: color-mix(in srgb, var(--red) 85%, white); --red-soft: color-mix(in srgb, var(--red) 10%, transparent); --red-glow: color-mix(in srgb, var(--red) 18%, transparent);
            --bg: #07070a; --panel: #101013; --panel-2: #16161a; --panel-3: #1c1c21;
            --border: rgba(255,255,255,.06); --border-md: rgba(255,255,255,.10); --border-red: color-mix(in srgb, var(--red) 22%, transparent);
            --text: #f0f0f3; --text-2: #868690; --text-3: #7d7d85;
            --green: #22c55e; --amber: #f59e0b; --blue: #3b82f6;
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
        .page-hero { padding: 32px 32px 0; }
        .hero-eyebrow { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: var(--red); margin-bottom: 6px; }
        .hero-title { font-size: 26px; font-weight: 800; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 10px; }
        .hero-sub { font-size: 13px; color: var(--text-2); }
        .content { padding: 24px 32px 48px; flex: 1; }
        /* Topbar mobile */
        .topbar { display: none; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); padding: 0 16px; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 200; }
        .topbar-title { font-size: 15px; font-weight: 700; color: var(--text); }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { background: transparent; border: 1px solid var(--border); color: var(--text); width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 299; }
        .sb-overlay.show { display: block; }
        /* ── Sidebar ── */
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
        /* Tabs */
        .nav-tabs { border-bottom: 1px solid var(--border); gap: 0; }
        .nav-tabs .nav-link { background: transparent; border: none; color: var(--text-2); font-weight: 500; font-size: 13px; padding: 10px 16px; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: all var(--t) var(--ease); border-radius: 0; }
        .nav-tabs .nav-link:hover { color: var(--text); background: var(--panel-2); }
        .nav-tabs .nav-link.active { color: var(--red); border-bottom-color: var(--red); font-weight: 600; }
        /* Panel cards */
        .panel-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 16px; }
        .panel-card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .panel-card-title { font-size: 14px; font-weight: 600; color: var(--text); }
        .panel-card-icon { color: var(--red); font-size: 15px; }
        .panel-card-body { padding: 20px; }
        /* Forms */
        .form-control, .form-select { background: var(--panel-2); border: 1px solid var(--border); color: var(--text); border-radius: var(--radius-xs); font-size: 13px; }
        .form-control::placeholder { color: var(--text-3); }
        .form-control:focus, .form-select:focus { background: var(--panel-2); border-color: var(--red); color: var(--text); box-shadow: 0 0 0 3px var(--red-soft); }
        .form-label { font-size: 12px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }
        .form-check-label { color: var(--text-2); font-size: 13px; }
        .form-check-input:checked { background-color: var(--red); border-color: var(--red); }
        /* Buttons */
        .btn-orange { background: var(--red); border: none; color: #fff; font-weight: 600; font-size: 13px; border-radius: var(--radius-xs); padding: 8px 18px; transition: background var(--t); }
        .btn-orange:hover, .btn-orange:focus { background: var(--red-2); color: #fff; }
        .btn-orange:disabled { background: var(--panel-3); color: var(--text-3); }
        .btn-outline-orange { background: transparent; border: 1px solid var(--red); color: var(--red); font-weight: 600; font-size: 13px; border-radius: var(--radius-xs); padding: 8px 18px; transition: all var(--t); }
        .btn-outline-orange:hover { background: var(--red-soft); color: var(--red); }
        .btn-success { background: var(--green); border: none; color: #fff; font-weight: 600; font-size: 13px; border-radius: var(--radius-xs); padding: 8px 18px; }
        /* Badges */
        .badge-admin { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; }
        .badge-team { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
        /* Modals */
        .modal-content { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); }
        .modal-header { border-bottom: 1px solid var(--border); padding: 16px 20px; }
        .modal-title { font-size: 15px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px; }
        .modal-footer { border-top: 1px solid var(--border); }
        .btn-close { filter: invert(1) grayscale(1); }
        /* list group */
        .list-group-item { background: var(--panel-2); border-color: var(--border); color: var(--text); font-size: 13px; }
        .list-group-item:hover { background: var(--panel-3); }
        /* alert info */
        .info-box { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-xs); padding: 12px 16px; font-size: 13px; color: var(--text-2); margin-bottom: 16px; }
        /* Mobile: tables & proposals */
        @media (max-width: 768px) {
            .leilao-page .table-responsive {
                border: 1px solid var(--border);
                border-radius: var(--radius);
                background: var(--panel);
                padding: 8px;
            }
            .leilao-page .table-responsive table {
                min-width: 0;
                border-collapse: separate;
                border-spacing: 0 10px;
            }
            .leilao-page .table-responsive thead { display: none; }
            .leilao-page .table-responsive tbody tr {
                display: block;
                background: var(--panel-2);
                border: 1px solid var(--border);
                border-radius: var(--radius-sm);
                padding: 10px 12px;
            }
            .leilao-page .table-responsive tbody td {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                padding: 6px 0;
                border: none;
                font-size: 13px;
            }
            .leilao-page .table-responsive tbody td::before {
                content: attr(data-label);
                font-size: 10px;
                letter-spacing: .6px;
                text-transform: uppercase;
                color: var(--text-3);
                font-weight: 700;
            }
            .leilao-page .auction-proposal-card {
                padding: 14px 14px !important;
                border-radius: var(--radius-sm) !important;
                box-shadow: 0 10px 26px rgba(0,0,0,.18);
            }
        }
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
<body class="leilao-page">
<div class="app">

    <!-- ═══ SIDEBAR ═══════════════════════════════════════════════ -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Overlay mobile -->
    <div class="sb-overlay" id="sbOverlay"></div>

    <main class="main">
        <!-- Topbar mobile -->
        <header class="topbar">
            <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
            <div class="topbar-title">FBA <em>Manager</em></div>
        </header>

        <div class="page-hero">
            <div class="hero-eyebrow">Liga · <?= htmlspecialchars($user['league'] ?? 'ELITE') ?></div>
            <h1 class="hero-title"><i class="bi bi-hammer" style="color:var(--red)"></i>Leilão</h1>
            <p class="hero-sub">Lances em tempo real para free agents disponíveis na liga</p>
        </div>

        <div class="content">
            <?php if ($is_admin): ?>
            <div style="display:flex;justify-content:flex-end;margin-bottom:14px">
                <button type="button" class="btn btn-orange" onclick="abrirCadastroManual()">
                    <i class="bi bi-plus-lg me-1"></i>Cadastrar leilão manualmente
                </button>
            </div>
            <?php endif; ?>
            <div class="panel-card">
                <div class="panel-card-header"><i class="bi bi-hammer panel-card-icon"></i><span class="panel-card-title">Leilões Ativos</span></div>
                <div class="panel-card-body"><div id="leiloesAtivosContainer"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div></div>
            </div>
            <?php if ($team_id): ?>
            <div class="panel-card">
                <div class="panel-card-header"><i class="bi bi-inbox panel-card-icon"></i><span class="panel-card-title">Propostas Recebidas</span></div>
                <div class="panel-card-body"><div id="propostasRecebidasContainer"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div></div>
            </div>
            <?php endif; ?>
            <div class="panel-card">
                <div class="panel-card-header"><i class="bi bi-clock-history panel-card-icon"></i><span class="panel-card-title">Histórico de Leilões</span></div>
                <div class="panel-card-body"><div id="leiloesHistoricoContainer"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div></div>
            </div>
        </div>
    </main>
</div>

<!-- Modal: Proposta de Troca -->
<div class="modal fade" id="modalProposta" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-send" style="color:var(--red)"></i>Enviar Proposta de Troca</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height:80vh;overflow-y:auto">
                <input type="hidden" id="leilaoIdProposta">
                <input type="hidden" id="leilaoSellerTeamId">
                <div class="info-box"><strong style="color:var(--text);">Jogador em leilão:</strong> <span id="jogadorLeilaoNome" style="color:var(--red);font-weight:600;"></span></div>

                <p style="font-size:13px;color:var(--text-2);margin-bottom:8px;">Selecione os jogadores que você oferece em troca:</p>
                <div id="meusJogadoresParaTroca" class="mb-3"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div>

                <p style="font-size:13px;color:var(--text-2);margin-bottom:4px;">Picks para oferecer <span style="font-size:11px;color:var(--text-3)">(selecione o swap se aplicável)</span>:</p>
                <div id="minhasPicksParaTroca" class="mb-3"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div>

                <!-- Oferta Personalizada -->
                <div style="border-top:1px solid var(--border);padding-top:14px;margin-bottom:14px">
                  <button type="button" id="btnToggleCustomOffer" onclick="toggleCustomOffer()"
                    style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 14px;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.25);border-radius:8px;color:#f59e0b;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--font);text-align:left;transition:.15s">
                    <i class="bi bi-stars"></i>
                    <span>Oferta Personalizada — solicitar itens do vendedor</span>
                    <i class="bi bi-chevron-down ms-auto" id="customOfferChevron"></i>
                  </button>
                  <div id="propostaCustomSection" style="display:none;margin-top:10px;padding:14px;background:rgba(245,158,11,.04);border:1px solid rgba(245,158,11,.15);border-radius:8px">
                    <p style="font-size:12px;color:var(--text-3);margin-bottom:14px">Selecione jogadores/picks do <strong style="color:var(--text)">time vendedor</strong> que você quer receber junto com o jogador leiloado:</p>
                    <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Jogadores do vendedor</div>
                    <div id="sellerPlayersParaTroca" class="mb-3"><p style="color:var(--text-3);font-size:13px">Carregando...</p></div>
                    <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Picks do vendedor</div>
                    <div id="sellerPicksParaTroca"><p style="color:var(--text-3);font-size:13px">Carregando...</p></div>
                  </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Observação <span style="color:var(--text-3);font-weight:400;">(opcional)</span></label>
                    <textarea id="obsProposta" class="form-control" rows="3" placeholder="Ex: estou oferecendo 2 picks + 1 jogador, aberto a negociar..."></textarea>
                    <input type="hidden" id="notasProposta">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-orange" id="btnEnviarProposta"><i class="bi bi-send me-1"></i>Enviar Proposta</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Ver Propostas -->
<div class="modal fade" id="modalVerPropostas" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-inbox" style="color:var(--red)"></i>Propostas Recebidas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="leilaoIdVerPropostas">
                <div id="listaPropostasRecebidas"><p style="color:var(--text-3);font-size:13px;">Carregando...</p></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<?php if ($is_admin): ?>
<!-- Modal: Cadastrar Leilão Manualmente (admin) -->
<div class="modal fade" id="modalCadastroManual" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-hammer" style="color:var(--red)"></i> Cadastrar Leilão Manualmente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height:80vh;overflow-y:auto">
                <p style="font-size:12px;color:var(--text-3);margin-bottom:14px">
                    Registra um leilão que já aconteceu: o jogador leiloado vai para o vencedor, e os jogadores/picks
                    enviados vão para o vendedor — como uma troca. Só aparecem times da liga.
                </p>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Time vendedor</label>
                        <select id="cmSeller" class="form-select" onchange="cmOnSellerChange()"><option value="">Carregando...</option></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Jogador leiloado <span style="color:var(--text-3);font-weight:400">(do vendedor)</span></label>
                        <select id="cmPlayer" class="form-select"><option value="">Selecione o vendedor primeiro</option></select>
                    </div>
                </div>

                <hr style="border-color:var(--border);margin:16px 0">

                <div class="mb-3">
                    <label class="form-label">Time vencedor</label>
                    <select id="cmWinner" class="form-select" onchange="cmOnWinnerChange()"><option value="">Carregando...</option></select>
                </div>

                <div class="mb-3">
                    <div style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Jogadores enviados pelo vencedor</div>
                    <div id="cmOfferPlayers"><p style="color:var(--text-3);font-size:13px">Selecione o vencedor primeiro</p></div>
                </div>
                <div class="mb-3">
                    <div style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Picks enviadas pelo vencedor</div>
                    <div id="cmOfferPicks"><p style="color:var(--text-3);font-size:13px">Selecione o vencedor primeiro</p></div>
                </div>

                <div class="mb-2">
                    <label class="form-label">Observação <span style="color:var(--text-3);font-weight:400">(opcional)</span></label>
                    <textarea id="cmObs" class="form-control" rows="2" placeholder="Ex: leilão do Discord em 20/07..."></textarea>
                </div>
                <div id="cmError" style="display:none;color:#ef4444;font-size:13px;margin-top:6px"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-orange" id="cmSubmit" onclick="enviarCadastroManual()"><i class="bi bi-check-lg me-1"></i>Cadastrar e executar</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;
    const userTeamId = <?= $team_id ? $team_id : 'null' ?>;
    const userTeamName = '<?= addslashes($team_name) ?>';
    const currentLeagueId = <?= $league_id ? $league_id : 'null' ?>;
</script>
<script src="/js/leilao.js"></script>
<script src="/js/pwa.js"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    const sbOverlay = document.getElementById('sbOverlay');
    const menuBtn = document.getElementById('menuBtn');
    if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); sbOverlay.classList.add('show'); });
    if (sbOverlay) sbOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); });
    // Theme
    const themeKey = 'fba-theme';
    const themeBtn = document.querySelector('[data-theme-toggle]');
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

<?php if ($is_admin): ?>
<script>
/* ── Cadastro manual de leilão (admin) ────────────────── */
function _cmEsc(s){ if(!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
let _cmTeams = [];

async function abrirCadastroManual(){
    const modal = new bootstrap.Modal(document.getElementById('modalCadastroManual'));
    document.getElementById('cmError').style.display = 'none';
    document.getElementById('cmObs').value = '';
    document.getElementById('cmPlayer').innerHTML = '<option value="">Selecione o vendedor primeiro</option>';
    document.getElementById('cmOfferPlayers').innerHTML = '<p style="color:var(--text-3);font-size:13px">Selecione o vencedor primeiro</p>';
    document.getElementById('cmOfferPicks').innerHTML = '<p style="color:var(--text-3);font-size:13px">Selecione o vencedor primeiro</p>';
    modal.show();
    try {
        const res = await fetch('api/leilao.php?action=league_teams');
        const data = await res.json();
        _cmTeams = data.success ? (data.teams || []) : [];
        const opts = '<option value="">Selecione...</option>' + _cmTeams.map(t => `<option value="${t.id}">${_cmEsc(t.team_name)}</option>`).join('');
        document.getElementById('cmSeller').innerHTML = opts;
        document.getElementById('cmWinner').innerHTML = opts;
    } catch(e) {
        document.getElementById('cmSeller').innerHTML = '<option value="">Erro ao carregar times</option>';
    }
}

async function cmOnSellerChange(){
    const sellerId = document.getElementById('cmSeller').value;
    const sel = document.getElementById('cmPlayer');
    if (!sellerId) { sel.innerHTML = '<option value="">Selecione o vendedor primeiro</option>'; return; }
    sel.innerHTML = '<option value="">Carregando...</option>';
    try {
        const res = await fetch(`api/leilao.php?action=seller_items&seller_team_id=${sellerId}`);
        const data = await res.json();
        const players = data.success ? (data.players || []) : [];
        sel.innerHTML = '<option value="">Selecione o jogador...</option>' +
            players.map(p => `<option value="${p.id}">${_cmEsc(p.name)} · ${_cmEsc(p.position||'')} · ${p.ovr||'?'} OVR</option>`).join('');
    } catch(e){ sel.innerHTML = '<option value="">Erro ao carregar</option>'; }
}

async function cmOnWinnerChange(){
    const winnerId = document.getElementById('cmWinner').value;
    const pc = document.getElementById('cmOfferPlayers');
    const kc = document.getElementById('cmOfferPicks');
    if (!winnerId) {
        pc.innerHTML = '<p style="color:var(--text-3);font-size:13px">Selecione o vencedor primeiro</p>';
        kc.innerHTML = '<p style="color:var(--text-3);font-size:13px">Selecione o vencedor primeiro</p>';
        return;
    }
    pc.innerHTML = '<p style="color:var(--text-3);font-size:13px">Carregando...</p>';
    kc.innerHTML = '';
    try {
        const res = await fetch(`api/leilao.php?action=seller_items&seller_team_id=${winnerId}`);
        const data = await res.json();
        const players = data.success ? (data.players || []) : [];
        const picks = data.success ? (data.picks || []) : [];
        const chip = (id, label, name) => `<label style="display:inline-flex;align-items:center;gap:7px;background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:7px 11px;margin:0 6px 6px 0;font-size:13px;cursor:pointer">
            <input type="checkbox" class="${name}" value="${id}"> <span>${label}</span></label>`;
        pc.innerHTML = players.length
            ? players.map(p => chip(p.id, `${_cmEsc(p.name)} <span style="color:var(--text-3)">· ${p.ovr||'?'} OVR</span>`, 'cm-offer-player')).join('')
            : '<p style="color:var(--text-3);font-size:13px">Sem jogadores.</p>';
        kc.innerHTML = picks.length
            ? picks.map(k => chip(k.id, `Pick ${k.season_year} R${k.round}`, 'cm-offer-pick')).join('')
            : '<p style="color:var(--text-3);font-size:13px">Sem picks.</p>';
    } catch(e){ pc.innerHTML = '<p style="color:#ef4444;font-size:13px">Erro ao carregar</p>'; }
}

async function enviarCadastroManual(){
    const errEl = document.getElementById('cmError');
    errEl.style.display = 'none';
    const seller = parseInt(document.getElementById('cmSeller').value, 10);
    const winner = parseInt(document.getElementById('cmWinner').value, 10);
    const player = parseInt(document.getElementById('cmPlayer').value, 10);
    const offeredPlayers = Array.from(document.querySelectorAll('.cm-offer-player:checked')).map(c => parseInt(c.value, 10));
    const offeredPicks   = Array.from(document.querySelectorAll('.cm-offer-pick:checked')).map(c => parseInt(c.value, 10));
    const obs = document.getElementById('cmObs').value.trim();

    const showErr = (m) => { errEl.textContent = m; errEl.style.display = 'block'; };
    if (!seller || !player) return showErr('Selecione o time vendedor e o jogador leiloado.');
    if (!winner) return showErr('Selecione o time vencedor.');
    if (seller === winner) return showErr('Vendedor e vencedor não podem ser o mesmo time.');
    if (!offeredPlayers.length && !offeredPicks.length) return showErr('O vencedor precisa enviar ao menos um jogador ou pick.');

    const btn = document.getElementById('cmSubmit');
    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Cadastrando...';
    try {
        const res = await fetch('api/leilao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'cadastrar_manual',
                seller_team_id: seller, winner_team_id: winner, auctioned_player_id: player,
                offered_player_ids: offeredPlayers, offered_pick_ids: offeredPicks, obs
            })
        });
        const data = await res.json();
        if (!data.success) return showErr(data.error || 'Erro ao cadastrar.');
        bootstrap.Modal.getInstance(document.getElementById('modalCadastroManual'))?.hide();
        if (typeof carregarHistoricoLeiloes === 'function') carregarHistoricoLeiloes();
        if (typeof carregarLeiloesAtivos === 'function') carregarLeiloesAtivos(true);
        alert('Leilão cadastrado e troca executada com sucesso!');
    } catch(e){
        showErr('Erro ao cadastrar o leilão.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}
</script>
<?php endif; ?>
</body>
</html>
