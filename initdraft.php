<?php
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/auth.php';

$token = $_GET['token'] ?? null;
if (!$token) {
    http_response_code(403);
    echo 'Token inválido.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Draft Inicial</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --initdraft-bg: #000000;
            --initdraft-panel: #141414;
            --initdraft-panel-alt: #1a1a1a;
            --initdraft-border: #2a2a2a;
            --initdraft-muted: #cfcfcf;
            --initdraft-orange: #fc0025;
            --initdraft-green: #38d07d;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', system-ui, -apple-system, sans-serif;
            background:
                radial-gradient(circle at 20% 25%, rgba(252, 0, 37, 0.14), transparent 55%),
                radial-gradient(circle at 85% 70%, rgba(252, 0, 37, 0.1), transparent 55%),
                linear-gradient(135deg, #000000 0%, #0a0a0c 55%, #000000 100%);
            color: #fff;
            min-height: 100vh;
        }

        .initdraft-app {
            max-width: 1200px;
        }

        .card-dark {
            background: var(--initdraft-panel);
            border: 1px solid var(--initdraft-border);
            border-radius: 20px;
        }

        .hero-card {
            background: linear-gradient(120deg, rgba(252, 0, 37, 0.25), rgba(7, 7, 13, 0.85));
            border-radius: 28px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .hero-card h1 {
            font-size: clamp(1.75rem, 3vw, 2.5rem);
            margin-bottom: 0.5rem;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.75rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-setup {
            background: rgba(255, 193, 7, 0.18);
            color: #ffc107;
        }

        .status-in_progress {
            background: rgba(56, 208, 125, 0.18);
            color: var(--initdraft-green);
        }

        .status-completed {
            background: rgba(173, 181, 189, 0.2);
            color: #adb5bd;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
            margin-top: 1.25rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 1rem;
        }

        .stat-label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--initdraft-muted);
            margin-bottom: 0.4rem;
        }

        .stat-value {
            font-size: 1.4rem;
            font-weight: 600;
        }

        .session-card {
            background: var(--initdraft-panel-alt);
            border-radius: 22px;
            padding: 1.5rem;
            border: 1px solid var(--initdraft-border);
            margin-bottom: 1.5rem;
        }

        .progress-wrapper {
            margin-top: 1rem;
        }

        .progress {
            height: 0.55rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.1);
        }

        .progress-bar {
            background: linear-gradient(90deg, var(--initdraft-orange), #ff2a44);
        }

        .table-dark {
            --bs-table-bg: transparent;
            --bs-table-striped-bg: rgba(255, 255, 255, 0.04);
            color: #fff;
        }

        .table thead th {
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            color: var(--initdraft-muted);
        }

        .table-responsive {
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.1);
        }

        .team-chip {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .team-chip img {
            width: 38px;
            height: 38px;
            object-fit: cover;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .order-list-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            justify-content: space-between;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 0.75rem 1rem;
        }

        .order-list-item + .order-list-item {
            margin-top: 0.5rem;
        }

        .order-rank {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            display: grid;
            place-items: center;
            font-weight: 600;
            color: var(--initdraft-orange);
        }

        .order-actions button {
            border-radius: 999px;
            width: 34px;
            height: 34px;
        }

        .badge-status {
            font-size: 0.75rem;
            padding: 0.25rem 0.55rem;
            border-radius: 999px;
        }

        .badge-available {
            background: rgba(56, 208, 125, 0.18);
            color: var(--initdraft-green);
        }

        .badge-drafted {
            background: rgba(255, 255, 255, 0.08);
            color: #adb5bd;
        }

        .search-input {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #fff;
        }

        .search-input:focus {
            border-color: var(--initdraft-orange);
            box-shadow: none;
            background: rgba(0, 0, 0, 0.35);
            color: #fff;
        }

        .text-muted {
            color: #c5cada !important;
        }

        .alert-warning,
        .alert-secondary,
        .alert-info,
        .alert-danger,
        .alert-success {
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.08);
        }

        .form-control,
        .form-select {
            background: rgba(0, 0, 0, 0.35);
            border-color: rgba(255, 255, 255, 0.18);
            color: #fff;
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        .badge.bg-secondary,
        .badge.bg-success,
        .badge.bg-danger,
        .badge.bg-info,
        .badge.bg-warning {
            color: #fff;
        }

        .lottery-stage {
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 1rem;
            min-height: 120px;
        }

        .lottery-track {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
            align-items: center;
        }

        .lottery-ball {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(252, 0, 37, 0.18);
            border: 2px solid rgba(252, 0, 37, 0.6);
            display: grid;
            place-items: center;
            transition: transform 200ms ease, box-shadow 200ms ease;
            animation: floatBall 2.8s ease-in-out infinite;
        }

        .lottery-ball img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            background: #0a0a0c;
        }

        .lottery-ball.active {
            transform: scale(1.1);
            box-shadow: 0 0 18px rgba(252, 0, 37, 0.6);
        }

        .lottery-results {
            display: grid;
            gap: 0.5rem;
        }

        .lottery-result {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 0.6rem 0.8rem;
        }

        .order-mode-active {
            background: rgba(252, 0, 37, 0.16);
            border-color: rgba(252, 0, 37, 0.6) !important;
            color: #fff !important;
        }

        .manual-order-row {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 0.75rem;
            align-items: center;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 0.6rem 0.8rem;
        }

        .manual-position-select {
            width: 72px;
        }

        @media (max-width: 576px) {
            .manual-order-row {
                grid-template-columns: 1fr;
                align-items: flex-start;
            }
            .manual-position-select {
                width: 100%;
            }
        }

        .lottery-result img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            background: #0a0a0c;
        }

        .lottery-rank {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(252, 0, 37, 0.2);
            border: 1px solid rgba(252, 0, 37, 0.6);
            display: grid;
            place-items: center;
            font-weight: 600;
            color: #fff;
        }

        @keyframes floatBall {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }

        .form-label,
        .modal-title,
        .card h5,
        .card h6,
        .card h4,
        .card h3,
        .card h2,
        .card h1 {
            color: #fff;
        }

        .nav-tabs .nav-link {
            color: #ddd;
            border: none;
            border-bottom: 2px solid transparent;
        }

        .nav-tabs .nav-link.active {
            color: #fff;
            border-color: var(--initdraft-orange);
            background: transparent;
        }

        /* Paginação */
        .pagination {
            --bs-pagination-bg: #fff;
            --bs-pagination-color: #000;
            --bs-pagination-hover-bg: #e9ecef;
            --bs-pagination-hover-color: #000;
            --bs-pagination-active-bg: var(--initdraft-orange);
            --bs-pagination-active-color: #fff;
            --bs-pagination-disabled-bg: #e9ecef;
            --bs-pagination-disabled-color: #6c757d;
        }

        @media (max-width: 768px) {
            .hero-card {
                padding: 1.5rem;
            }
            .order-list-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .order-actions {
                width: 100%;
                display: flex;
                justify-content: flex-end;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
<div class="initdraft-app container py-4">
    <div class="mb-3">
        <a href="dashboard.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar ao Dashboard
        </a>
    </div>

    <div id="feedback"></div>

    <section class="hero-card" id="heroSection">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-start">
            <div>
                <p class="text-uppercase text-warning fw-semibold mb-2">Painel do Draft Inicial</p>
                <h1 class="mb-1">Draft Inicial</h1>
                <p class="mb-0 text-light">Configure a ordem, acompanhe as rodadas e registre cada pick em um layout otimizado para qualquer tela.</p>
            </div>
            <div class="text-md-end">
                <p class="text-uppercase small text-muted mb-1">Token de Acesso</p>
                <div class="d-flex align-items-center gap-2">
                    <code id="tokenDisplay" class="text-break"></code>
                    <button class="btn btn-outline-warning btn-sm" onclick="copyToken()">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="stat-grid" id="statGrid"></div>
    </section>

    <section class="session-card">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-start">
            <div>
                <h5 class="mb-1">Status da Sessão</h5>
                <div id="sessionSummary" class="text-muted"></div>
            </div>
            <div class="d-flex flex-wrap gap-2" id="actionButtons"></div>
        </div>
        <div class="progress-wrapper">
            <div class="d-flex justify-content-between mb-1 small text-muted">
                <span id="progressLabel"></span>
                <span id="progressPercent"></span>
            </div>
            <div class="progress">
                <div class="progress-bar" id="progressBar" role="progressbar" style="width: 0%"></div>
            </div>
        </div>
    </section>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card-dark h-100 p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="mb-1">Ordem da 1ª Rodada</h5>
                        <p class="text-muted mb-0 small" id="orderEditHint">Edite manualmente ou utilize o sorteio animado.</p>
                    </div>
                    <button class="btn btn-outline-light btn-sm" id="orderEditButton" onclick="openOrderModal()">
                        <i class="bi bi-sliders me-2"></i>Editar
                    </button>
                </div>
                <div id="orderList" class="small"></div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card-dark p-4 h-100">
                <ul class="nav nav-tabs mb-3" id="contentTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="players-tab" data-bs-toggle="tab" data-bs-target="#players" type="button" role="tab">Jogadores</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="rounds-tab" data-bs-toggle="tab" data-bs-target="#rounds-pane" type="button" role="tab">Rodadas</button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="players" role="tabpanel" aria-labelledby="players-tab">
                        <div class="d-flex flex-column flex-md-row gap-3 justify-content-between align-items-md-center mb-3">
                            <div>
                                <h5 class="mb-1">Jogadores do Pool</h5>
                                <p class="text-muted mb-0 small">Importe via CSV, adicione manualmente e escolha jogadores em tempo real.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#importCSVModal">
                                    <i class="bi bi-file-earmark-arrow-up me-1"></i>Importar CSV
                                </button>
                                <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#addPlayerModal">
                                    <i class="bi bi-person-plus me-1"></i>Novo Jogador
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <input type="text" id="poolSearch" class="form-control search-input" placeholder="Filtrar por nome ou posição" />
                        </div>
                        <div class="table-responsive" id="poolWrapper">
                            <table class="table table-dark table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Jogador</th>
                                        <th>Posição</th>
                                        <th>OVR</th>
                                        <th>Status</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="poolTable"></tbody>
                            </table>
                        </div>
                        <div id="poolPagination" class="d-flex justify-content-center mt-3"></div>
                    </div>
                    <div class="tab-pane fade" id="rounds-pane" role="tabpanel" aria-labelledby="rounds-tab">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <h5 class="mb-0">Rodadas e Picks</h5>
                            <span class="text-muted small" id="roundsMeta"></span>
                        </div>
                        <div id="rounds"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Order Modal -->
<div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-dark text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">Configurar Ordem do Draft</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small">
                    Utilize os botões para ajustar manualmente ou clique em "Sortear" para gerar uma ordem aleatória estilo lottery. O formato snake será aplicado automaticamente nas demais rodadas.
                </div>
                <div class="d-flex flex-column flex-md-row gap-2 mb-3">
                    <button class="btn btn-outline-light flex-fill" type="button" id="orderModeManual" onclick="setOrderMode('manual')">
                        <i class="bi bi-list-ol me-1"></i>Ordenar manualmente
                    </button>
                    <button class="btn btn-outline-warning flex-fill" type="button" id="orderModeLottery" onclick="setOrderMode('lottery')">
                        <i class="bi bi-shuffle me-1"></i>Sorteio (loteria)
                    </button>
                </div>
                <div id="lotterySection" class="d-none">
                    <div class="lottery-stage mb-3" id="lotteryStage">
                        <div class="text-center text-muted" id="lotteryPlaceholder">Clique em Sorteio para iniciar.</div>
                        <div class="lottery-track" id="lotteryTrack"></div>
                    </div>
                    <div class="lottery-results" id="lotteryResults"></div>
                </div>
                <div id="manualSection" class="d-none">
                    <div class="text-light-gray small mb-2">Defina a posição de cada time antes de aplicar.</div>
                    <div id="manualOrderList" class="d-grid gap-2"></div>
                </div>
            </div>
            <div class="modal-footer border-secondary justify-content-between">
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-light" type="button" id="resetOrderButton" onclick="resetManualOrder()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Resetar
                    </button>
                    <button class="btn btn-outline-warning" type="button" id="lotteryButton" onclick="randomizeOrder()">
                        <i class="bi bi-shuffle me-1"></i>Iniciar sorteio
                    </button>
                </div>
                <button class="btn btn-success" type="button" id="applyOrderButton" onclick="submitManualOrder()">
                    <i class="bi bi-check2-circle me-1"></i>Aplicar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Player Modal -->
<div class="modal fade" id="addPlayerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-white">
            <form id="addPlayerForm">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Adicionar Jogador</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Nome</label>
                            <input type="text" name="name" class="form-control" required />
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Posição</label>
                            <select name="position" class="form-select">
                                <option value="PG">PG</option>
                                <option value="SG">SG</option>
                                <option value="SF" selected>SF</option>
                                <option value="PF">PF</option>
                                <option value="C">C</option>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label">Idade</label>
                            <input type="number" name="age" min="16" max="45" class="form-control" required />
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label">OVR</label>
                            <input type="number" name="ovr" min="40" max="99" class="form-control" required />
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Player Modal -->
<div class="modal fade" id="editPlayerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-white">
            <form id="editPlayerForm">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Editar Jogador</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="player_id" id="editPlayerId" />
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Nome</label>
                            <input type="text" name="name" id="editPlayerName" class="form-control" required />
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Posição</label>
                            <select name="position" id="editPlayerPosition" class="form-select">
                                <option value="PG">PG</option>
                                <option value="SG">SG</option>
                                <option value="SF">SF</option>
                                <option value="PF">PF</option>
                                <option value="C">C</option>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label">Idade</label>
                            <input type="number" name="age" id="editPlayerAge" min="16" max="45" class="form-control" required />
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label">OVR</label>
                            <input type="number" name="ovr" id="editPlayerOvr" min="40" max="99" class="form-control" required />
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import CSV Modal -->
<div class="modal fade" id="importCSVModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-white">
            <form id="importCSVForm" enctype="multipart/form-data">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Importar Jogadores via CSV</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Formato: <code>name,position,age,ovr</code>. Utilize o template para evitar erros.</p>
                    <div class="mb-3">
                        <label class="form-label">Arquivo CSV</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required />
                    </div>
                    <button type="button" class="btn btn-outline-warning btn-sm" onclick="downloadCSVTemplate()">
                        <i class="bi bi-download me-1"></i>Baixar Template
                    </button>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Importar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const TOKEN = '<?php echo htmlspecialchars($token, ENT_QUOTES); ?>';
    const API_URL = 'api/initdraft.php';
    const LOTTERY_STORAGE_KEY = `initdraft_lottery_${TOKEN}`;
    const LOTTERY_BALL_COUNT = 30;

    const state = {
        session: null,
        order: [],
        teams: [],
        pool: [],
        manualOrder: [],
        search: '',
        poolPage: 1,
        poolPerPage: 15,
        lotteryDrawn: false,
        lotteryQueue: [],
        lotteryIndex: 0,
        orderMode: 'manual',
    };

    const elements = {
        tokenDisplay: document.getElementById('tokenDisplay'),
        statGrid: document.getElementById('statGrid'),
        sessionSummary: document.getElementById('sessionSummary'),
        actionButtons: document.getElementById('actionButtons'),
        progressLabel: document.getElementById('progressLabel'),
        progressPercent: document.getElementById('progressPercent'),
        progressBar: document.getElementById('progressBar'),
        orderList: document.getElementById('orderList'),
        manualOrderList: document.getElementById('manualOrderList'),
        poolTable: document.getElementById('poolTable'),
        poolPagination: document.getElementById('poolPagination'),
        roundsContainer: document.getElementById('rounds'),
        roundsMeta: document.getElementById('roundsMeta'),
        feedback: document.getElementById('feedback'),
        lotteryStage: document.getElementById('lotteryStage'),
        lotteryTrack: document.getElementById('lotteryTrack'),
        lotteryResults: document.getElementById('lotteryResults'),
        lotteryButton: document.getElementById('lotteryButton'),
        lotterySection: document.getElementById('lotterySection'),
        manualSection: document.getElementById('manualSection'),
        orderModeManual: document.getElementById('orderModeManual'),
        orderModeLottery: document.getElementById('orderModeLottery'),
        lotteryPlaceholder: document.getElementById('lotteryPlaceholder'),
        applyOrderButton: document.getElementById('applyOrderButton'),
        orderEditButton: document.getElementById('orderEditButton'),
        orderEditHint: document.getElementById('orderEditHint'),
        resetOrderButton: document.getElementById('resetOrderButton'),
    };

    elements.tokenDisplay.textContent = TOKEN;
    state.lotteryDrawn = localStorage.getItem(LOTTERY_STORAGE_KEY) === '1';

    document.getElementById('poolSearch').addEventListener('input', (event) => {
        state.search = event.target.value.toLowerCase();
        state.poolPage = 1; // Reset para primeira página ao buscar
        renderPool();
    });

    document.getElementById('addPlayerForm').addEventListener('submit', handleAddPlayer);
    document.getElementById('editPlayerForm').addEventListener('submit', handleEditPlayer);
    document.getElementById('importCSVForm').addEventListener('submit', handleImportCSV);

    const orderModal = new bootstrap.Modal(document.getElementById('orderModal'));

    document.getElementById('orderModal').addEventListener('show.bs.modal', () => {
        renderManualOrderList();
        setOrderMode('select');
        resetLotteryView();
        updateLotteryButton();
    });

    function showMessage(message, type = 'success') {
        elements.feedback.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>`;
    }

    function copyToken() {
        navigator.clipboard.writeText(TOKEN).then(() => showMessage('Token copiado para a área de transferência.'));
    }

    function shuffle(list) {
        const array = [...list];
        for (let i = array.length - 1; i > 0; i -= 1) {
            const j = Math.floor(Math.random() * (i + 1));
            [array[i], array[j]] = [array[j], array[i]];
        }
        return array;
    }

    function openDraftViewer() {
        window.open(`initdraftselecao.php?token=${encodeURIComponent(TOKEN)}`, '_blank');
    }

    async function loadState() {
        try {
            const [stateRes, poolRes] = await Promise.all([
                fetch(`${API_URL}?action=state&token=${TOKEN}`).then((r) => r.json()),
                fetch(`${API_URL}?action=pool&token=${TOKEN}`).then((r) => r.json()),
            ]);

            if (!stateRes.success) throw new Error(stateRes.error || 'Erro ao carregar sessão');
            state.session = stateRes.session;
            state.order = stateRes.order || [];
            state.teams = stateRes.teams || [];
            state.pool = poolRes.success ? poolRes.players : [];
            state.manualOrder = getRoundOneOrder();
            if (state.order.length) {
                state.lotteryDrawn = true;
            }
            render();
            return true;
        } catch (error) {
            showMessage(error.message, 'danger');
            return false;
        }
    }

    function render() {
        renderStats();
        renderActions();
        updateOrderEditVisibility();
        renderOrder();
        renderPool();
        renderRounds();
        updateLotteryButton();
    }

    function updateOrderEditVisibility() {
        const canEdit = !state.lotteryDrawn;
        elements.orderEditButton?.classList.toggle('d-none', !canEdit);
        if (elements.orderEditHint) {
            elements.orderEditHint.textContent = canEdit
                ? 'Edite manualmente ou utilize o sorteio animado.'
                : 'Ordem definida (não editável).';
        }
    }

    function setOrderMode(mode) {
        const isManual = mode === 'manual';
        const isLottery = mode === 'lottery';
        state.orderMode = mode;
        elements.manualSection?.classList.toggle('d-none', !isManual);
        elements.lotterySection?.classList.toggle('d-none', !isLottery);
        elements.orderModeManual?.classList.toggle('order-mode-active', isManual);
        elements.orderModeLottery?.classList.toggle('order-mode-active', isLottery);
        elements.lotteryButton?.classList.toggle('d-none', !isLottery);
        elements.resetOrderButton?.classList.toggle('d-none', !isManual);
        if (elements.applyOrderButton) {
            const hideApply = !isManual && (!isLottery || !state.lotteryDrawn);
            elements.applyOrderButton.classList.toggle('d-none', hideApply);
        }
        if (isManual) {
            renderManualOrderList();
        } else if (isLottery) {
            resetLotteryView();
        }
        updateLotteryButton();
    }

    function renderStats() {
        const session = state.session;
        if (!session) return;

        const order = state.order || [];
        const drafted = order.filter((pick) => pick.picked_player_id).length;
        const total = order.length || (session.total_rounds ?? 0) * (state.teams.length || 0);
        const progress = total ? Math.round((drafted / total) * 100) : 0;
        const nextPick = order.find((pick) => !pick.picked_player_id);
        const statusLabel = { setup: 'Configuração', in_progress: 'Em andamento', completed: 'Concluído' }[session.status] || 'Status';

        elements.statGrid.innerHTML = `
            <div class="stat-card">
                <p class="stat-label">Status</p>
                <div class="status-pill status-${session.status}">${statusLabel}</div>
            </div>
            <div class="stat-card">
                <p class="stat-label">Rodada Atual</p>
                <p class="stat-value">${session.current_round ?? '-'}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Próximo Time</p>
                <p class="stat-value">${formatTeamLabel(nextPick)}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Total de Rodadas</p>
                <p class="stat-value">${session.total_rounds}</p>
            </div>
        `;

    elements.sessionSummary.innerHTML = `Liga: <strong>${session.league}</strong>`;
        elements.progressLabel.textContent = `${drafted} de ${total} picks realizados`;
        elements.progressPercent.textContent = `${progress}%`;
        elements.progressBar.style.width = `${progress}%`;
    }

    function renderActions() {
        const session = state.session;
        if (!session) return;

        const buttons = [];

        if (!state.lotteryDrawn) {
            buttons.push(`<button class="btn btn-outline-light btn-sm" onclick="openOrderModal()"><i class="bi bi-sliders me-1"></i>Ordem</button>`);
        }

        if (session.status === 'setup') {
            buttons.push(`<button class="btn btn-success btn-sm" onclick="startDraft()"><i class="bi bi-play-circle me-1"></i>Iniciar Draft</button>`);
        }

        if (session.status === 'in_progress') {
            buttons.push(`<button class="btn btn-outline-info btn-sm" onclick="loadState()"><i class="bi bi-arrow-clockwise me-1"></i>Atualizar</button>`);
            buttons.push(`<button class="btn btn-outline-light btn-sm" onclick="openDraftViewer()"><i class="bi bi-eye me-1"></i>Ver página do draft</button>`);
            buttons.push(`<button class="btn btn-danger btn-sm" onclick="finalizeDraft()"><i class="bi bi-flag me-1"></i>Finalizar</button>`);
        }

        if (session.status === 'completed') {
            buttons.push(`<span class="badge bg-success">Draft concluído</span>`);
        }

        elements.actionButtons.innerHTML = buttons.join('');
    }

    function renderOrder() {
        if (!state.manualOrder.length) {
            elements.orderList.innerHTML = '<p class="text-muted mb-0">Defina a ordem para desbloquear o draft.</p>';
            return;
        }

        const teamsById = Object.fromEntries(state.teams.map((team) => [team.id, team]));
        const allowEdit = !state.lotteryDrawn;
        elements.orderList.innerHTML = state.manualOrder
            .map((teamId, index) => {
                const team = teamsById[teamId] || {};
                const actionButtons = allowEdit
                    ? `<div class="order-actions">
                            <button class="btn btn-outline-light btn-sm" ${index === 0 ? 'disabled' : ''} onclick="moveManualTeam(${index}, -1)"><i class="bi bi-arrow-up"></i></button>
                            <button class="btn btn-outline-light btn-sm" ${index === state.manualOrder.length - 1 ? 'disabled' : ''} onclick="moveManualTeam(${index}, 1)"><i class="bi bi-arrow-down"></i></button>
                        </div>`
                    : '';
                return `
                    <div class="order-list-item">
                        <div class="d-flex align-items-center gap-3 flex-grow-1">
                            <div class="order-rank">${index + 1}</div>
                            <div class="team-chip">
                                <img src="${team.photo_url || '/img/default-team.png'}" alt="${team.name || 'Time'}" onerror="this.src='/img/default-team.png'">
                                <div>
                                    <strong>${team.city || ''} ${team.name || ''}</strong>
                                    <div class="small text-muted">${team.owner_name || 'Sem GM'}</div>
                                </div>
                            </div>
                        </div>
                        ${actionButtons}
                    </div>`;
            })
            .join('');
    }

    function renderManualOrderList() {
        if (!state.manualOrder.length) {
            elements.manualOrderList.innerHTML = '<div class="text-muted">Carregando...</div>';
            return;
        }
        const teamsById = Object.fromEntries(state.teams.map((team) => [team.id, team]));
        const total = state.manualOrder.length;
        const options = Array.from({ length: total }, (_, idx) => idx + 1);
        elements.manualOrderList.innerHTML = state.manualOrder
            .map((teamId, index) => {
                const team = teamsById[teamId] || {};
                return `
                    <div class="manual-order-row">
                        <select class="form-select form-select-sm manual-position-select" onchange="updateManualOrderPosition(${teamId}, this.value)">
                            ${options.map((pos) => `<option value="${pos}" ${pos === index + 1 ? 'selected' : ''}>#${pos}</option>`).join('')}
                        </select>
                        <div class="d-flex align-items-center gap-2">
                            <img src="${team.photo_url || '/img/default-team.png'}" alt="${team.name || 'Time'}" onerror="this.src='/img/default-team.png'" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
                            <div>
                                <strong>${team.city || ''} ${team.name || ''}</strong>
                                <div class="small text-muted">${team.owner_name || 'Sem GM'}</div>
                            </div>
                        </div>
                        <div class="text-muted small">${team.id}</div>
                    </div>`;
            })
            .join('');
    }

    function updateManualOrderPosition(teamId, position) {
        const newPos = parseInt(position, 10);
        if (!Number.isFinite(newPos)) return;
        const index = state.manualOrder.indexOf(parseInt(teamId, 10));
        if (index === -1) return;
        const updated = [...state.manualOrder];
        const [removed] = updated.splice(index, 1);
        updated.splice(newPos - 1, 0, removed);
        state.manualOrder = updated;
        renderManualOrderList();
        renderOrder();
    }

    function resetLotteryView() {
        if (!elements.lotteryTrack || !elements.lotteryResults) return;
        elements.lotteryResults.innerHTML = '';
        elements.lotteryTrack.innerHTML = '';
        elements.lotteryPlaceholder?.classList.remove('d-none');
        state.lotteryQueue = [];
        state.lotteryIndex = 0;
    }

        function buildBallTeams(teams = []) {
            if (!teams.length) {
                return Array.from({ length: LOTTERY_BALL_COUNT }, () => ({ photo_url: '/img/default-team.png' }));
            }
            const filled = [];
            for (let i = 0; i < LOTTERY_BALL_COUNT; i += 1) {
                filled.push(teams[i % teams.length]);
            }
            return filled;
        }

        function updateLotteryButton() {
            if (!elements.lotteryButton) return;
            elements.lotteryButton.disabled = state.lotteryDrawn;
            if (state.lotteryDrawn) {
                elements.lotteryButton.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Sorteio concluído';
                return;
            }
            elements.lotteryButton.innerHTML = state.lotteryQueue.length
                ? '<i class="bi bi-shuffle me-1"></i>Sortear próximo'
                : '<i class="bi bi-shuffle me-1"></i>Iniciar sorteio';
        }

    function startLottery(orderDetails = []) {
        if (!elements.lotteryTrack || !elements.lotteryResults) return;
        state.lotteryQueue = orderDetails.length ? orderDetails : state.teams;
        state.lotteryIndex = 0;
        elements.lotteryResults.innerHTML = '';
        const ballTeams = buildBallTeams(state.teams);
        elements.lotteryTrack.innerHTML = ballTeams
            .map((team) => `
                <div class="lottery-ball">
                    <img src="${team.photo_url || '/img/default-team.png'}" alt="${team.name || 'Time'}" onerror="this.src='/img/default-team.png'">
                </div>`)
            .join('');

        elements.lotteryPlaceholder?.classList.add('d-none');
        updateLotteryButton();
    }

    async function drawNextLottery() {
        if (!elements.lotteryTrack || !elements.lotteryResults) return;
        if (!state.lotteryQueue.length) return;
        if (state.lotteryIndex >= state.lotteryQueue.length) {
            state.lotteryDrawn = true;
            localStorage.setItem(LOTTERY_STORAGE_KEY, '1');
            updateLotteryButton();
            showMessage('Sorteio concluído.');
            return;
        }

        const balls = Array.from(elements.lotteryTrack.querySelectorAll('.lottery-ball'));
        balls.forEach((ball) => ball.classList.remove('active'));
        if (balls.length) {
            const ballIndex = Math.floor(Math.random() * balls.length);
            balls[ballIndex]?.classList.add('active');
        }

        const team = state.lotteryQueue[state.lotteryIndex] || {};
        elements.lotteryResults.insertAdjacentHTML(
            'beforeend',
            `<div class="lottery-result">
                <span class="lottery-rank">${state.lotteryIndex + 1}</span>
                <img src="${team.photo_url || '/img/default-team.png'}" alt="${team.name || 'Time'}" onerror="this.src='/img/default-team.png'">
                <div>
                    <strong>${team.city || ''} ${team.name || ''}</strong>
                    <div class="small text-muted">${team.owner_name || 'Sem GM'}</div>
                </div>
            </div>`
        );

        state.lotteryIndex += 1;
        if (state.lotteryIndex >= state.lotteryQueue.length) {
            state.lotteryDrawn = true;
            localStorage.setItem(LOTTERY_STORAGE_KEY, '1');
            if (elements.applyOrderButton) {
                elements.applyOrderButton.classList.remove('d-none');
            }
            try {
                if (state.session?.total_rounds) {
                    await submitLotteryOrder();
                    showMessage('Sorteio concluído e ordem salva automaticamente.');
                } else {
                    showMessage('Sorteio concluído. Clique em Aplicar para definir o número de rodadas e salvar.', 'info');
                }
            } catch (error) {
                showMessage(error.message || 'Erro ao salvar ordem do sorteio', 'danger');
            }
        }
        updateLotteryButton();
    }

    async function submitLotteryOrder() {
        if (!state.session?.total_rounds) {
            throw new Error('Defina o total de rodadas clicando em Aplicar.');
        }
        if (!state.lotteryQueue.length) {
            throw new Error('Nenhuma ordem sorteada.');
        }
        const teamIds = state.lotteryQueue.map((team) => team.id).filter(Boolean);
        const res = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'set_manual_order', token: TOKEN, team_ids: teamIds }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Erro ao salvar ordem do sorteio');
        state.manualOrder = teamIds;
        renderManualOrderList();
        renderOrder();
    }

    function renderPool() {
        // Filtrar jogadores
        const filtered = (state.pool || []).filter((player) => {
            if (!state.search) return true;
            const needle = state.search;
            return (
                (player.name || '').toLowerCase().includes(needle) ||
                (player.position || '').toLowerCase().includes(needle)
            );
        });

        if (!filtered.length) {
            elements.poolTable.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Nenhum jogador no pool.</td></tr>';
            elements.poolPagination.innerHTML = '';
            return;
        }

        // Paginação
        const totalPages = Math.ceil(filtered.length / state.poolPerPage);
        if (state.poolPage > totalPages) state.poolPage = totalPages;
        const start = (state.poolPage - 1) * state.poolPerPage;
        const end = start + state.poolPerPage;
        const paginated = filtered.slice(start, end);

        // Renderizar tabela
        elements.poolTable.innerHTML = paginated
            .map((player, index) => {
                const globalIndex = start + index + 1;
                const drafted = player.draft_status === 'drafted';
                const canDelete = state.session?.status === 'setup' && !drafted;
                const canEdit = state.session?.status === 'setup' && !drafted;
                
                const deleteBtn = canDelete
                    ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteInitDraftPlayer(${player.id}, '${(player.name || '').replace(/'/g, "\\'")}')">
                        <i class="bi bi-trash"></i>
                    </button>`
                    : '';
                
                const editBtn = canEdit
                    ? `<button class="btn btn-sm btn-outline-warning" onclick="openEditPlayer(${player.id})">
                        <i class="bi bi-pencil"></i>
                    </button>`
                    : '';

                const actions = (editBtn || deleteBtn) ? `${editBtn} ${deleteBtn}` : '<span class="text-muted">-</span>';

                return `
                    <tr>
                        <td>${globalIndex}</td>
                        <td>${player.name}</td>
                        <td>${player.position}</td>
                        <td>${player.ovr}</td>
                        <td><span class="badge badge-${drafted ? 'drafted' : 'available'}">${drafted ? 'Drafted' : 'Disponível'}</span></td>
                        <td class="text-end">${actions}</td>
                    </tr>`;
            })
            .join('');

        // Renderizar paginação
        renderPoolPagination(totalPages, filtered.length);
    }

    function renderPoolPagination(totalPages, totalItems) {
        if (totalPages <= 1) {
            elements.poolPagination.innerHTML = '';
            return;
        }

        const maxButtons = 5;
        let startPage = Math.max(1, state.poolPage - Math.floor(maxButtons / 2));
        let endPage = Math.min(totalPages, startPage + maxButtons - 1);
        
        if (endPage - startPage < maxButtons - 1) {
            startPage = Math.max(1, endPage - maxButtons + 1);
        }

        let html = '<nav><ul class="pagination pagination-sm mb-0">';
        
        // Botão anterior
        html += `<li class="page-item ${state.poolPage === 1 ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="changePoolPage(${state.poolPage - 1}); return false;">&laquo;</a>
        </li>`;

        // Primeira página
        if (startPage > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" onclick="changePoolPage(1); return false;">1</a></li>`;
            if (startPage > 2) {
                html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }

        // Páginas numeradas
        for (let i = startPage; i <= endPage; i++) {
            html += `<li class="page-item ${i === state.poolPage ? 'active' : ''}">
                <a class="page-link" href="#" onclick="changePoolPage(${i}); return false;">${i}</a>
            </li>`;
        }

        // Última página
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            html += `<li class="page-item"><a class="page-link" href="#" onclick="changePoolPage(${totalPages}); return false;">${totalPages}</a></li>`;
        }

        // Botão próximo
        html += `<li class="page-item ${state.poolPage === totalPages ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="changePoolPage(${state.poolPage + 1}); return false;">&raquo;</a>
        </li>`;

        html += '</ul></nav>';
        html += `<div class="text-muted small mt-2 text-center">${totalItems} jogadores encontrados</div>`;
        
        elements.poolPagination.innerHTML = html;
    }

    function changePoolPage(page) {
        const totalPages = Math.ceil((state.pool || []).filter((player) => {
            if (!state.search) return true;
            const needle = state.search;
            return (
                (player.name || '').toLowerCase().includes(needle) ||
                (player.position || '').toLowerCase().includes(needle)
            );
        }).length / state.poolPerPage);
        
        if (page < 1 || page > totalPages) return;
        state.poolPage = page;
        renderPool();
    }

    function renderRounds() {
        if (!state.order.length) {
            elements.roundsContainer.innerHTML = '<div class="text-muted">Nenhuma ordem configurada ainda.</div>';
            elements.roundsMeta.textContent = '';
            return;
        }

        const grouped = state.order.reduce((acc, pick) => {
            acc[pick.round] = acc[pick.round] || [];
            acc[pick.round].push(pick);
            return acc;
        }, {});

        elements.roundsMeta.textContent = `${Object.keys(grouped).length} rodadas · ${state.order.length} picks`;

        const roundsHtml = Object.keys(grouped)
            .sort((a, b) => a - b)
            .map((round) => {
                const picks = grouped[round].sort((a, b) => a.pick_position - b.pick_position);
                const rows = picks
                    .map((pick) => {
                        const player = pick.player_name ? `${pick.player_name} (${pick.player_position ?? ''} - ${pick.player_ovr ?? '-'})` : '<span class="text-muted">—</span>';
                        return `
                            <tr class="${pick.picked_player_id ? 'table-success' : ''}">
                                <td class="fw-semibold">${pick.pick_position}</td>
                                <td>
                                    <div class="team-chip">
                                        <img src="${pick.team_photo || '/img/default-team.png'}" alt="${pick.team_name}" onerror="this.src='/img/default-team.png'">
                                        <div>
                                            <strong>${pick.team_city || ''} ${pick.team_name || ''}</strong>
                                            <div class="small text-muted">${pick.team_owner || ''}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>${player}</td>
                                <td class="text-end">${pick.picked_player_id ? '<i class="bi bi-check2-circle text-success"></i>' : ''}</td>
                            </tr>`;
                    })
                    .join('');
                return `
                    <div class="card-section mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 text-uppercase text-muted">Rodada ${round}</h6>
                            <span class="badge bg-secondary">${picks.length} picks</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-dark table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Pick</th>
                                        <th>Time</th>
                                        <th>Jogador</th>
                                        <th class="text-end">Status</th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                    </div>`;
            })
            .join('');

        elements.roundsContainer.innerHTML = roundsHtml;
    }

    function getRoundOneOrder() {
        if (!state.order.length) {
            return state.teams.map((team) => team.id);
        }
        return state.order
            .filter((pick) => pick.round === 1)
            .sort((a, b) => a.pick_position - b.pick_position)
            .map((pick) => pick.team_id);
    }

    function moveManualTeam(index, delta) {
        const newIndex = index + delta;
        if (newIndex < 0 || newIndex >= state.manualOrder.length) return;
        const updated = [...state.manualOrder];
        const [removed] = updated.splice(index, 1);
        updated.splice(newIndex, 0, removed);
        state.manualOrder = updated;
        renderManualOrderList();
        renderOrder();
    }

    function resetManualOrder() {
        state.manualOrder = getRoundOneOrder();
        renderManualOrderList();
        renderOrder();
    }

    function openOrderModal() {
        renderManualOrderList();
        resetLotteryView();
        setOrderMode('select');
        orderModal.show();
    }

    async function randomizeOrder() {
        if (state.lotteryDrawn) {
            showMessage('O sorteio já foi realizado. Você pode ajustar a ordem manualmente.', 'warning');
            return;
        }
        if (!state.teams.length) {
            const loaded = await loadState();
            if (!loaded || !state.teams.length) {
                showMessage('Sem times para sortear.', 'warning');
                return;
            }
        }
        try {
            if (!state.lotteryQueue.length) {
                setOrderMode('lottery');
                const orderDetails = shuffle([...state.teams]);
                state.manualOrder = orderDetails.map((team) => team.id);
                renderManualOrderList();
                renderOrder();
                startLottery(orderDetails);
                updateLotteryButton();
            }

            await drawNextLottery();
            updateLotteryButton();
            state.order = state.lotteryQueue.slice(0, state.lotteryIndex).map((team, index) => ({
                ...team,
                position: index + 1,
            }));
            if (state.lotteryDrawn) {
                showMessage('Ordem sorteada com sucesso.');
            }
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function submitManualOrder() {
        try {
            // 1. Extrair ordem da loteria ou manual
            if (state.orderMode === 'lottery' && state.lotteryQueue.length) {
                state.manualOrder = state.lotteryQueue.map((team) => team.id).filter(Boolean);
            }
            if (!state.manualOrder.length) {
                state.manualOrder = getRoundOneOrder();
            }
            if (!state.manualOrder.length) {
                showMessage('Defina a ordem antes de aplicar.', 'warning');
                return;
            }

            // 2. PRIMEIRO perguntar o número de rodadas e salvar
            const roundsOk = await ensureTotalRounds();
            if (!roundsOk) return;

            // 3. DEPOIS salvar a ordem (que vai gerar as picks usando total_rounds já salvo)
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'set_manual_order', token: TOKEN, team_ids: state.manualOrder }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao aplicar ordem');

            // 4. Marcar loteria como concluída e atualizar UI
            state.lotteryDrawn = true;
            updateOrderEditVisibility();
            
            // 5. Recarregar estado (agora com as picks geradas)
            await loadState();
            
            // 6. Fechar modal e mostrar sucesso
            orderModal.hide();
            showMessage('Ordem e rodadas definidas com sucesso!', 'success');
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function ensureTotalRounds() {
        const currentRounds = state.session?.total_rounds ?? '';
        const inputRounds = prompt('Quantas rodadas o draft terá?', currentRounds);
        if (inputRounds === null) {
            return false;
        }
        const roundsValue = parseInt(inputRounds, 10);
        if (Number.isNaN(roundsValue) || roundsValue < 1 || roundsValue > 10) {
            showMessage('Informe um número de rodadas entre 1 e 10.', 'warning');
            return false;
        }

        if (!state.session || roundsValue !== state.session.total_rounds) {
            const roundsRes = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'set_total_rounds', token: TOKEN, total_rounds: roundsValue }),
            });
            const roundsData = await roundsRes.json();
            if (!roundsData.success) throw new Error(roundsData.error || 'Erro ao atualizar rodadas');
            if (state.session) {
                state.session.total_rounds = roundsData.total_rounds;
            }
            renderStats();
        }

        return true;
    }

    async function startDraft() {
        if (!confirm('Deseja iniciar o draft?')) return;
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'start', token: TOKEN }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao iniciar');
            showMessage('Draft iniciado.');
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function finalizeDraft() {
        if (!confirm('Deseja finalizar o draft? Certifique-se de que todas as picks foram feitas.')) return;
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'finalize', token: TOKEN }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao finalizar');
            showMessage('Draft finalizado com sucesso.');
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function makePick(playerId) {
        if (!confirm('Confirmar pick deste jogador?')) return;
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'make_pick', token: TOKEN, player_id: playerId }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Falha ao registrar pick');
            showMessage('Pick registrada.');
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function deleteInitDraftPlayer(playerId, playerName) {
        if (!confirm(`Remover ${playerName} do draft inicial?`)) return;
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_player', token: TOKEN, player_id: playerId }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao remover jogador');
            showMessage('Jogador removido do pool.');
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function handleAddPlayer(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        const payload = Object.fromEntries(formData.entries());
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'add_player', token: TOKEN, ...payload }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao adicionar jogador');
            showMessage('Jogador adicionado ao pool.');
            event.target.reset();
            bootstrap.Modal.getInstance(document.getElementById('addPlayerModal')).hide();
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    function openEditPlayer(playerId) {
        const player = state.pool.find(p => p.id === playerId);
        if (!player) {
            showMessage('Jogador não encontrado.', 'warning');
            return;
        }
        
        document.getElementById('editPlayerId').value = player.id;
        document.getElementById('editPlayerName').value = player.name || '';
        document.getElementById('editPlayerPosition').value = player.position || 'SF';
        document.getElementById('editPlayerAge').value = player.age || 19;
        document.getElementById('editPlayerOvr').value = player.ovr || 70;
        
        new bootstrap.Modal(document.getElementById('editPlayerModal')).show();
    }

    async function handleEditPlayer(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        const payload = Object.fromEntries(formData.entries());
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'edit_player', token: TOKEN, ...payload }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao editar jogador');
            showMessage('Jogador atualizado com sucesso.');
            bootstrap.Modal.getInstance(document.getElementById('editPlayerModal')).hide();
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function handleImportCSV(event) {
        event.preventDefault();
        const form = event.target;
        const fileInput = form.querySelector('input[type="file"]');
        if (!fileInput.files.length) {
            showMessage('Selecione um arquivo CSV.', 'warning');
            return;
        }
        const formData = new FormData(form);
        formData.append('action', 'import_csv');
        formData.append('token', TOKEN);

        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao importar CSV');
            showMessage(`Importação concluída: ${data.imported} jogadores.`);
            form.reset();
            bootstrap.Modal.getInstance(document.getElementById('importCSVModal')).hide();
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    function downloadCSVTemplate() {
        const csv = 'name,position,age,ovr\nJohn Doe,SF,22,75';
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'initdraft-template.csv';
        link.click();
        URL.revokeObjectURL(url);
    }

    function formatTeamLabel(pick) {
        if (!pick) return '—';
        return `${pick.team_city ?? ''} ${pick.team_name ?? ''}`.trim() || '—';
    }

    loadState();
</script>
</body>
</html>
