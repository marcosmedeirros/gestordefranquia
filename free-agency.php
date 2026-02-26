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
$is_admin = $_SESSION['is_admin'] ?? (($_SESSION['user_type'] ?? '') === 'admin');
$team_id = $_SESSION['team_id'] ?? null;
$league_id = $_SESSION['current_league_id'] ?? null;

$team_name = '';
$team_moedas = 0;
$team_league = null;
if ($team_id) {
    $stmt = $pdo->prepare("SELECT name, moedas, league FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch();
    $team_name = $team['name'] ?? '';
    $team_moedas = (int)($team['moedas'] ?? 0);
    $team_league = $team['league'] ?? null;
}
$team_league = $team_league ?? ($_SESSION['user_league'] ?? null);

if (!$team_id && $user_id) {
    $stmt = $pdo->prepare("SELECT id, name, moedas, league FROM teams WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $team = $stmt->fetch();
    if ($team) {
        $team_id = (int)$team['id'];
        $team_name = $team['name'] ?? $team_name;
        $team_moedas = (int)($team['moedas'] ?? $team_moedas);
        $team_league = $team['league'] ?? $team_league;
    }
}
$team_league = $team_league ?? ($_SESSION['user_league'] ?? null);

$leagues = [];
$leagues_admin = [];
if ($is_admin) {
    $stmt = $pdo->query("SELECT id, name FROM leagues ORDER BY name");
    $leagues_admin = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $leagues = array_map(static function ($league) {
        return $league['name'];
    }, $leagues_admin);
    if (!$leagues) {
        $leagues = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
        $leagues_admin = [];
    }
}
$default_admin_league = $team_league ?? ($leagues[0] ?? 'ELITE');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Free Agency - FBA Brasil</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300..700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
    <style>
        .free-agency-page .dashboard-content,
        .free-agency-page .tab-content,
        .free-agency-page .tab-pane {
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .free-agency-page .table-responsive {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
        }

        .free-agency-page .table {
            width: 100%;
            table-layout: fixed;
        }

        .free-agency-page .table th,
        .free-agency-page .table td {
            white-space: normal;
            word-break: break-word;
        }

        .free-agency-page [style*="min-width"] {
            min-width: 0 !important;
            width: 100% !important;
        }

        .free-agency-page .free-agency-tabs .nav-link {
            padding: 10px 16px;
            font-size: 0.85rem;
            border-radius: 999px;
        }
        .free-agency-page .free-agency-tabs {
            gap: 6px;
        }
        .free-agency-page .dashboard-content .card-header {
            border-radius: 16px 16px 0 0;
        }
        .free-agency-page .dashboard-content .card-body {
            border-radius: 0 0 16px 16px;
        }
        @media (max-width: 576px) {
            .free-agency-page .dashboard-content {
                padding: 1.5rem 1rem 2rem;
            }
            .free-agency-page .free-agency-header h1 {
                font-size: 1.2rem !important;
            }
            .free-agency-page .free-agency-header .badge {
                font-size: 0.68rem !important;
                padding: 0.25rem 0.5rem;
            }
            .free-agency-page .free-agency-tabs {
                padding-bottom: 0.25rem;
            }
            .free-agency-page .free-agency-tabs .nav-link {
                padding: 8px 12px;
                font-size: 0.78rem;
                border-width: 0;
                background: rgba(255,255,255,0.05);
            }
            .free-agency-page .dashboard-content .card-header,
            .free-agency-page .dashboard-content .card-body {
                padding: 0.85rem 1rem;
            }
            .free-agency-page .stack-mobile > [class^="col"],
            .free-agency-page .stack-mobile > [class*=" col"] {
                flex: 0 0 100%;
                max-width: 100%;
            }
            .free-agency-page .stack-mobile .btn {
                width: 100%;
            }
            .free-agency-page .stack-mobile .form-label {
                margin-bottom: 0.25rem;
                font-size: 0.75rem;
            }
        }

        @media (max-width: 768px) {
            .free-agency-page .tab-pane,
            .free-agency-page .card,
            .free-agency-page .card-body {
                max-width: 100%;
                overflow-x: hidden;
            }

            .free-agency-page .form-select[style*="min-width"],
            .free-agency-page .form-control[style*="min-width"] {
                min-width: 0 !important;
                width: 100% !important;
            }
        }

        .legacy-fa {
            display: none !important;
        }

        .fa-new-card .form-control,
        .fa-new-card .form-select {
            background: var(--fba-dark-bg);
            color: var(--fba-text);
            border-color: var(--fba-border);
        }

        .fa-new-card .form-control:focus,
        .fa-new-card .form-select:focus {
            border-color: var(--fba-orange);
            box-shadow: 0 0 0 0.25rem rgba(241, 117, 7, 0.25);
        }

        .fa-section-title {
            font-size: 0.95rem;
            font-weight: 600;
        }

        @media (max-width: 576px) {
            .fa-header-actions {
                width: 100%;
            }
            .fa-header-actions .btn {
                width: 100%;
            }
        }

        .fa-approved-modal {
            z-index: 2000;
        }

        .fa-approved-modal + .modal-backdrop {
            z-index: 1995;
        }
    </style>
</head>
<body class="free-agency-page">
    <!-- Botão Hamburguer para Mobile -->
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="bi bi-list fs-4"></i>
    </button>
    
    <!-- Overlay para fechar sidebar no mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="d-flex">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div class="dashboard-content">
            <div class="mb-4 free-agency-header">
                <div class="d-flex flex-column flex-md-row flex-wrap justify-content-between align-items-start align-items-md-center gap-2 gap-md-3">
                    <h1 class="text-white fw-bold mb-0" style="font-size: 1.5rem;">
                        <i class="bi bi-coin text-orange me-2"></i>Free Agency
                    </h1>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($team_league): ?>
                            <span class="badge bg-dark border border-warning text-warning" style="font-size: 0.75rem;">
                                <i class="bi bi-trophy me-1"></i><?= htmlspecialchars($team_league) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($team_id): ?>
                            <span class="badge bg-warning text-dark" style="font-size: 0.75rem;">
                                <i class="bi bi-coin me-1"></i><?= $team_moedas ?> moedas
                            </span>
                        <?php endif; ?>
                        <?php if ($is_admin): ?>
                            <span class="badge bg-danger" style="font-size: 0.75rem;">Admin</span>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="text-light-gray mb-0 mt-2" style="font-size: 0.85rem;">
                    Envie lances com moedas para contratar jogadores dispensados.
                </p>
            </div>

            <ul class="nav nav-tabs nav-tabs-scroll mb-4 free-agency-tabs" id="freeAgencyTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active text-nowrap" id="fa-players-tab" data-bs-toggle="tab" data-bs-target="#fa-players" type="button" role="tab">
                        <i class="bi bi-people-fill me-1"></i><span class="d-none d-sm-inline">Free Agency</span><span class="d-sm-none">FA</span>
                    </button>
                </li>
                <li class="nav-item legacy-fa" role="presentation">
                    <button class="nav-link text-nowrap" id="fa-active-auctions-tab" data-bs-toggle="tab" data-bs-target="#fa-active-auctions" type="button" role="tab">
                        <i class="bi bi-hammer me-1"></i><span class="d-none d-sm-inline">Leiloes ativos</span><span class="d-sm-none">Leilões</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link text-nowrap" id="fa-history-tab" data-bs-toggle="tab" data-bs-target="#fa-history" type="button" role="tab">
                        <i class="bi bi-clock-history me-1"></i><span class="d-none d-sm-inline">Historico FA</span><span class="d-sm-none">Histórico</span>
                    </button>
                </li>
                <?php if ($is_admin): ?>
                <li class="nav-item legacy-fa" role="presentation">
                    <button class="nav-link text-nowrap" id="fa-auction-admin-tab" data-bs-toggle="tab" data-bs-target="#fa-auction-admin" type="button" role="tab">
                        <i class="bi bi-hammer me-1"></i><span class="d-none d-sm-inline">Leilao admin</span><span class="d-sm-none">Admin</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link text-nowrap" id="fa-admin-tab" data-bs-toggle="tab" data-bs-target="#fa-admin" type="button" role="tab">
                        <i class="bi bi-shield-lock-fill me-1"></i><span class="d-none d-sm-inline">FA Admin</span><span class="d-sm-none">Config</span>
                    </button>
                </li>
                <?php endif; ?>
            </ul>

            <div class="tab-content" id="freeAgencyTabsContent">
                <div class="tab-pane fade show active" id="fa-players" role="tabpanel">
                    <div class="card bg-dark-panel border-orange mb-4 fa-new-card">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <div class="d-flex flex-column flex-sm-row flex-wrap align-items-start align-items-sm-center justify-content-between gap-2">
                                <h5 class="mb-0 text-white"><i class="bi bi-person-plus-fill text-orange me-2"></i>Free Agency</h5>
                                <div class="d-flex flex-wrap align-items-center gap-2 fa-header-actions">
                                    <span class="badge bg-warning text-dark">Criar jogador + enviar proposta</span>
                                    <button class="btn btn-sm btn-danger text-white" type="button" id="faViewApprovedBtn">
                                        <i class="bi bi-inbox-fill me-1"></i>QUE ESTA GANHANDO
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <form id="faNewRequestForm" class="row g-3 stack-mobile">
                                <div class="col-md-4">
                                    <label for="faNewPlayerName" class="form-label">Nome do jogador</label>
                                    <input type="text" id="faNewPlayerName" class="form-control" placeholder="Ex: John Doe" required>
                                </div>
                                <div class="col-md-2">
                                    <label for="faNewPosition" class="form-label">Posicao</label>
                                    <select id="faNewPosition" class="form-select">
                                        <option value="PG">PG</option>
                                        <option value="SG">SG</option>
                                        <option value="SF">SF</option>
                                        <option value="PF">PF</option>
                                        <option value="C">C</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="faNewSecondary" class="form-label">Posicao Sec.</label>
                                    <input type="text" id="faNewSecondary" class="form-control" placeholder="Opcional">
                                </div>
                                <div class="col-md-2">
                                    <label for="faNewAge" class="form-label">Idade</label>
                                    <input type="number" id="faNewAge" class="form-control" value="24" min="18" max="45">
                                </div>
                                <div class="col-md-2">
                                    <label for="faNewOvr" class="form-label">OVR</label>
                                    <input type="number" id="faNewOvr" class="form-control" value="70" min="40" max="99">
                                </div>
                                <div class="col-md-3">
                                    <label for="faNewOffer" class="form-label">Moedas da proposta</label>
                                    <input type="number" id="faNewOffer" class="form-control" value="1" min="1">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-orange w-100" id="faNewSubmitBtn">
                                        <i class="bi bi-send me-1"></i>Enviar proposta
                                    </button>
                                </div>
                                <div class="col-12">
                                    <small class="text-warning d-block">ENVIAR NOME EXATAMENTE COMO ESTA ESCRITO NO VIDEO (EX: Lebron James (Não L. James))</small>
                                    <small class="text-light-gray">Se o jogador ja existir na FA, sua proposta sera agrupada com as demais.</small>
                                </div>
                            </form>

                            <div class="mt-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="text-white fa-section-title mb-0"><i class="bi bi-inbox-fill text-orange me-2"></i>Minhas propostas</h6>
                                    <span class="badge bg-secondary" id="faNewMyCount">0</span>
                                </div>
                                <div id="faNewMyRequests">
                                    <p class="text-muted">Carregando...</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-dark-panel border-orange mb-4 legacy-fa">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <h5 class="mb-0 text-white"><i class="bi bi-people-fill text-orange me-2"></i>Jogadores Disponiveis</h5>
                                <?php if ($is_admin): ?>
                                    <button class="btn btn-sm btn-outline-orange" type="button" id="btnOpenAdminTab">
                                        <i class="bi bi-plus-circle me-1"></i>Adicionar Jogador
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-2 align-items-end mb-3 stack-mobile">
                                <div class="col-8 col-md-6">
                                    <label for="faSearchInput" class="form-label d-none d-md-block">Buscar jogador</label>
                                    <input type="text" id="faSearchInput" class="form-control" placeholder="🔍 Buscar jogador...">
                                </div>
                                <div class="col-4 col-md-3">
                                    <label for="faPositionFilter" class="form-label d-none d-md-block">Posicao</label>
                                    <select id="faPositionFilter" class="form-select">
                                        <option value="">Pos.</option>
                                        <option value="PG">PG</option>
                                        <option value="SG">SG</option>
                                        <option value="SF">SF</option>
                                        <option value="PF">PF</option>
                                        <option value="C">C</option>
                                    </select>
                                </div>
                            </div>
                            <div id="freeAgentsContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade legacy-fa" id="fa-active-auctions" role="tabpanel">
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-hammer text-orange me-2"></i>Leiloes Ativos</h5>
                        </div>
                        <div class="card-body">
                            <div id="leiloesAtivosContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>

                    <?php if ($team_id): ?>
                    <div class="card bg-dark-panel border-orange legacy-fa">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-inbox text-orange me-2"></i>Propostas Recebidas</h5>
                        </div>
                        <div class="card-body">
                            <div id="propostasRecebidasContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="card bg-dark-panel border-orange mt-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-clock-history text-orange me-2"></i>Historico de Leiloes</h5>
                        </div>
                        <div class="card-body">
                            <div id="leiloesHistoricoContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="fa-history" role="tabpanel">
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <h5 class="mb-0 text-white"><i class="bi bi-clock-history text-orange me-2"></i>Historico da Free Agency</h5>
                                <div class="d-flex gap-2">
                                    <select id="faHistorySeasonFilter" class="form-select form-select-sm" style="min-width: 140px;">
                                        <option value="">Todas temporadas</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-light-gray small mb-2">Filtre por temporada para localizar contratações específicas.</p>
                            <div id="faHistoryContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-dark-panel border-orange">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <h5 class="mb-0 text-white"><i class="bi bi-person-x text-orange me-2"></i>Dispensados Recentes</h5>
                                <div class="d-flex flex-wrap gap-2">
                                    <select id="faWaiversSeasonFilter" class="form-select form-select-sm" style="min-width: 140px;">
                                        <option value="">Todas temporadas</option>
                                    </select>
                                    <select id="faWaiversTeamFilter" class="form-select form-select-sm" style="min-width: 180px;">
                                        <option value="">Todos os times</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="faWaiversContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($is_admin): ?>
                <div class="tab-pane fade legacy-fa" id="fa-auction-admin" role="tabpanel">
                    <div class="card bg-dark-panel border-orange">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-hammer text-orange me-2"></i>Leilao admin</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 align-items-end stack-mobile">
                                <div class="col-md-3">
                                    <label for="selectLeague" class="form-label">Liga</label>
                                    <select id="selectLeague" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($leagues_admin as $league): ?>
                                            <option value="<?= (int)$league['id'] ?>" data-league-name="<?= htmlspecialchars($league['name']) ?>">
                                                <?= htmlspecialchars($league['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-7">
                                    <div class="d-flex flex-wrap gap-3 align-items-center text-white mt-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="auctionMode" id="auctionModeSearch" value="search" checked>
                                            <label class="form-check-label" for="auctionModeSearch">Buscar jogador</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="auctionMode" id="auctionModeCreate" value="create">
                                            <label class="form-check-label" for="auctionModeCreate">Criar jogador</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2 mt-4">
                                    <button id="btnCadastrarLeilao" class="btn btn-orange w-100" disabled>
                                        <i class="bi bi-play-fill me-1"></i>Iniciar 20min
                                    </button>
                                </div>
                            </div>

                            <div class="border-top border-secondary mt-3 pt-3">
                                <div id="auctionSearchArea">
                                    <div class="row g-2 align-items-end stack-mobile">
                                        <div class="col-md-6">
                                            <label for="auctionPlayerSearch" class="form-label">Buscar jogador</label>
                                            <input type="text" id="auctionPlayerSearch" class="form-control" placeholder="Digite o nome">
                                        </div>
                                        <div class="col-md-2">
                                            <button class="btn btn-outline-orange w-100" id="auctionSearchBtn">
                                                <i class="bi bi-search"></i> Buscar
                                            </button>
                                        </div>
                                    </div>
                                    <div class="list-group mt-2" id="auctionPlayerResults" style="display:none;"></div>
                                    <div class="text-light-gray mt-2" id="auctionSelectedLabel" style="display:none;"></div>
                                    <input type="hidden" id="auctionSelectedPlayerId">
                                    <input type="hidden" id="auctionSelectedTeamId">
                                </div>

                                <div id="auctionCreateArea" style="display:none;">
                                    <div class="row g-2 stack-mobile">
                                        <div class="col-12">
                                            <div class="text-light-gray small mb-1">O jogador sera criado no leilao e nao precisa selecionar time.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="auctionPlayerName" class="form-label">Nome</label>
                                            <input type="text" id="auctionPlayerName" class="form-control" placeholder="Nome">
                                        </div>
                                        <div class="col-md-2">
                                            <label for="auctionPlayerPosition" class="form-label">Posicao</label>
                                            <select id="auctionPlayerPosition" class="form-select">
                                                <option value="PG">PG</option>
                                                <option value="SG">SG</option>
                                                <option value="SF">SF</option>
                                                <option value="PF">PF</option>
                                                <option value="C">C</option>
                                            </select>
                                        </div>
                                        <div class="col-md-1">
                                            <label for="auctionPlayerAge" class="form-label">Idade</label>
                                            <input type="number" id="auctionPlayerAge" class="form-control" value="25">
                                        </div>
                                        <div class="col-md-1">
                                            <label for="auctionPlayerOvr" class="form-label">OVR</label>
                                            <input type="number" id="auctionPlayerOvr" class="form-control" value="70">
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button class="btn btn-success w-100" type="button" id="btnCriarJogadorLeilao">
                                                <i class="bi bi-plus-circle me-1"></i>Criar jogador
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <h6 class="text-white mb-2"><i class="bi bi-person-plus text-orange me-2"></i>Jogadores criados (sem time)</h6>
                                        <div id="auctionTempList"><p class="text-light-gray">Nenhum jogador criado.</p></div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3">
                                <div id="adminLeiloesContainer">
                                    <p class="text-muted">Carregando...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="fa-admin" role="tabpanel">
                    <div class="card bg-dark-panel border-orange mb-4 fa-new-card">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <h5 class="mb-0 text-white"><i class="bi bi-shield-check text-orange me-2"></i>Solicitacoes Free Agency</h5>
                                <div class="d-flex align-items-center gap-2">
                                    <label for="faNewAdminLeague" class="text-light-gray">Liga</label>
                                    <select id="faNewAdminLeague" class="form-select form-select-sm" style="min-width: 140px;">
                                        <option value="ALL">Todas</option>
                                        <?php foreach ($leagues_admin as $league): ?>
                                            <option value="<?= htmlspecialchars($league['name']) ?>" <?= $league['name'] === $default_admin_league ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($league['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="badge bg-info text-dark">Selecione o time vencedor</span>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="faNewAdminRequests">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-dark-panel border-orange mb-4 legacy-fa">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <h5 class="mb-0 text-white"><i class="bi bi-inbox-fill text-orange me-2"></i>Propostas Pendentes</h5>
                                <div class="d-flex align-items-center gap-2">
                                    <label for="adminLeagueSelect" class="text-light-gray">Liga</label>
                                    <select id="adminLeagueSelect" class="form-select form-select-sm" style="min-width: 140px;" onchange="onAdminLeagueChange()">
                                        <option value="ALL">Todas</option>
                                        <?php foreach ($leagues_admin as $league): ?>
                                            <option value="<?= htmlspecialchars($league['name']) ?>" data-league-id="<?= (int)$league['id'] ?>" <?= $league['name'] === $default_admin_league ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($league['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-check form-switch ms-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="faStatusToggle">
                                        <label class="form-check-label text-light-gray" for="faStatusToggle">Propostas</label>
                                    </div>
                                    <span id="faStatusBadge" class="badge bg-secondary ms-1" style="font-size: 0.7rem;">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="adminOffersContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-dark-panel border-orange mb-4 legacy-fa">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-gear-fill text-orange me-2"></i>Adicionar Free Agent</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 stack-mobile">
                                <div class="col-md-3">
                                    <label for="faLeague" class="form-label">Liga</label>
                                    <select id="faLeague" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($leagues as $league): ?>
                                            <option value="<?= htmlspecialchars($league) ?>"><?= htmlspecialchars($league) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="faPlayerName" class="form-label">Nome</label>
                                    <input type="text" id="faPlayerName" class="form-control" placeholder="Nome do jogador">
                                </div>
                                <div class="col-md-2">
                                    <label for="faPosition" class="form-label">Posicao</label>
                                    <select id="faPosition" class="form-select">
                                        <option value="PG">PG</option>
                                        <option value="SG">SG</option>
                                        <option value="SF">SF</option>
                                        <option value="PF">PF</option>
                                        <option value="C">C</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="faSecondaryPosition" class="form-label">Posicao Secundaria</label>
                                    <input type="text" id="faSecondaryPosition" class="form-control" placeholder="Opcional">
                                </div>
                                <div class="col-md-2">
                                    <label for="faAge" class="form-label">Idade</label>
                                    <input type="number" id="faAge" class="form-control" value="25">
                                </div>
                                <div class="col-md-2">
                                    <label for="faOvr" class="form-label">OVR</label>
                                    <input type="number" id="faOvr" class="form-control" value="70">
                                </div>
                                <div class="col-12">
                                    <button id="btnAddFreeAgent" class="btn btn-orange" onclick="addFreeAgent()">
                                        <i class="bi bi-plus-circle me-1"></i>Adicionar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-dark-panel border-orange legacy-fa">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-list-ul text-orange me-2"></i>Gerenciar Jogadores</h5>
                        </div>
                        <div class="card-body">
                            <div id="adminFreeAgentsContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-dark-panel border-orange mt-4 legacy-fa">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-clock-history text-orange me-2"></i>Historico de Contratacoes FA</h5>
                        </div>
                        <div class="card-body">
                            <div id="faContractsHistoryContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>

                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal fade legacy-fa" id="modalOffer" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-coin"></i> Fazer Lance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="freeAgentIdOffer">
                    <div class="alert alert-info">
                        <strong>Jogador:</strong> <span id="freeAgentNomeOffer"></span>
                    </div>
                    <div class="mb-3">
                        <label for="offerAmount" class="form-label">Moedas do lance</label>
                        <input type="number" id="offerAmount" class="form-control" min="0" value="0">
                    </div>
                    <div class="alert alert-warning">
                        Moedas disponiveis: <strong id="moedasDisponiveis"><?= $team_moedas ?></strong><br>
                        <small class="text-dark">Dica: informe <strong>0 moedas</strong> para <strong>cancelar</strong> sua proposta.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmOffer">Confirmar Lance</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade legacy-fa" id="modalProposta" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-send"></i> Enviar Proposta de Leilao</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="leilaoIdProposta">
                    <div class="alert alert-info">
                        <strong>Jogador em Leilao:</strong> <span id="jogadorLeilaoNome"></span>
                    </div>
                    <h6 class="text-light-gray">Selecione jogadores para oferecer (opcional):</h6>
                    <div id="meusJogadoresParaTroca" class="mb-3">
                        <p class="text-muted">Carregando...</p>
                    </div>
                        <h6 class="text-light-gray">Selecione picks para oferecer (opcional):</h6>
                        <div id="minhasPicksParaTroca" class="mb-3">
                            <p class="text-muted">Carregando...</p>
                        </div>
                    <div class="mb-3">
                        <label for="notasProposta" class="form-label">O que vai dar na proposta</label>
                        <textarea id="notasProposta" class="form-control" rows="3" placeholder="Ex: 1 jogador + escolha de draft ou moedas"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-orange" id="btnEnviarProposta">
                        <i class="bi bi-send"></i> Enviar Proposta
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade legacy-fa" id="modalVerPropostas" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-inbox"></i> Propostas Recebidas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="leilaoIdVerPropostas">
                    <div id="listaPropostasRecebidas">
                        <p class="text-muted">Carregando...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade fa-approved-modal" id="faApprovedModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
            <div class="modal-content bg-dark border-orange">
                <div class="modal-header border-orange">
                    <h5 class="modal-title text-white"><i class="bi bi-inbox-fill me-2 text-orange"></i>Solicitações recebidas</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="faApprovedList" class="text-light-gray">Carregando...</div>
                </div>
                <div class="modal-footer border-orange">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;
        const userTeamId = <?= $team_id ? $team_id : 'null' ?>;
        const userTeamName = '<?= addslashes($team_name) ?>';
        const userMoedas = <?= $team_moedas ?>;
        const userLeague = <?= $team_league ? "'" . addslashes($team_league) . "'" : 'null' ?>;
        const defaultAdminLeague = '<?= addslashes($default_admin_league) ?>';
        const currentLeagueId = <?= $league_id ? $league_id : 'null' ?>;
        const leagueIdByName = <?= json_encode(array_reduce($leagues_admin, function ($carry, $league) { $carry[$league['name']] = (int)$league['id']; return $carry; }, [])) ?>;
        const useNewFreeAgency = true;
    </script>
    <script src="js/sidebar.js"></script>
    <script src="js/free-agency.js?v=20260206-1"></script>
    <script src="js/leilao.js"></script>
</body>
</html>
