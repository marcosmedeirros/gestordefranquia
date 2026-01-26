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
                        <p class="text-muted mb-0 small">Edite manualmente ou utilize o sorteio animado.</p>
                    </div>
                    <button class="btn btn-outline-light btn-sm" onclick="openOrderModal()">
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
                                        <th class="text-end">Ação</th>
                                    </tr>
                                </thead>
                                <tbody id="poolTable"></tbody>
                            </table>
                        </div>
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
                <div class="lottery-stage mb-3" id="lotteryStage">
                    <div class="lottery-track" id="lotteryTrack"></div>
                </div>
                <div class="lottery-results" id="lotteryResults"></div>
                <div id="manualOrderList" class="d-grid gap-2"></div>
            </div>
            <div class="modal-footer border-secondary justify-content-between">
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-light" type="button" onclick="resetManualOrder()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Resetar
                    </button>
                    <button class="btn btn-outline-warning" type="button" onclick="randomizeOrder()">
                        <i class="bi bi-shuffle me-1"></i>Sortear Ordem
                    </button>
                </div>
                <button class="btn btn-success" type="button" onclick="submitManualOrder()">
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

    const state = {
        session: null,
        order: [],
        teams: [],
        pool: [],
        manualOrder: [],
        search: '',
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
        roundsContainer: document.getElementById('rounds'),
        roundsMeta: document.getElementById('roundsMeta'),
        feedback: document.getElementById('feedback'),
            lotteryStage: document.getElementById('lotteryStage'),
            lotteryTrack: document.getElementById('lotteryTrack'),
            lotteryResults: document.getElementById('lotteryResults'),
    };

    elements.tokenDisplay.textContent = TOKEN;

    document.getElementById('poolSearch').addEventListener('input', (event) => {
        state.search = event.target.value.toLowerCase();
        renderPool();
    });

    document.getElementById('addPlayerForm').addEventListener('submit', handleAddPlayer);
    document.getElementById('importCSVForm').addEventListener('submit', handleImportCSV);

    const orderModal = new bootstrap.Modal(document.getElementById('orderModal'));

    document.getElementById('orderModal').addEventListener('show.bs.modal', () => {
        renderManualOrderList();
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
            render();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    function render() {
        renderStats();
        renderActions();
        renderOrder();
        renderPool();
        renderRounds();
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

        elements.sessionSummary.innerHTML = `Liga: <strong>${session.league}</strong> · Temporada #${session.season_id}`;
        elements.progressLabel.textContent = `${drafted} de ${total} picks realizados`;
        elements.progressPercent.textContent = `${progress}%`;
        elements.progressBar.style.width = `${progress}%`;
    }

    function renderActions() {
        const session = state.session;
        if (!session) return;

        const buttons = [];

        buttons.push(`<button class="btn btn-outline-light btn-sm" onclick="openOrderModal()"><i class="bi bi-sliders me-1"></i>Ordem</button>`);

        if (session.status === 'setup') {
            buttons.push(`<button class="btn btn-success btn-sm" onclick="startDraft()"><i class="bi bi-play-circle me-1"></i>Iniciar Draft</button>`);
        }

        if (session.status === 'in_progress') {
            buttons.push(`<button class="btn btn-outline-info btn-sm" onclick="loadState()"><i class="bi bi-arrow-clockwise me-1"></i>Atualizar</button>`);
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
        elements.orderList.innerHTML = state.manualOrder
            .map((teamId, index) => {
                const team = teamsById[teamId] || {};
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
                        <div class="order-actions">
                            <button class="btn btn-outline-light btn-sm" ${index === 0 ? 'disabled' : ''} onclick="moveManualTeam(${index}, -1)"><i class="bi bi-arrow-up"></i></button>
                            <button class="btn btn-outline-light btn-sm" ${index === state.manualOrder.length - 1 ? 'disabled' : ''} onclick="moveManualTeam(${index}, 1)"><i class="bi bi-arrow-down"></i></button>
                        </div>
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
        elements.manualOrderList.innerHTML = state.manualOrder
            .map((teamId, index) => {
                const team = teamsById[teamId] || {};
                return `
                    <div class="order-list-item">
                        <div class="d-flex align-items-center gap-3">
                            <div class="order-rank">${index + 1}</div>
                            <div>
                                <strong>${team.city || ''} ${team.name || ''}</strong>
                                <div class="small text-muted">${team.owner_name || 'Sem GM'}</div>
                            </div>
                        </div>
                        <div class="order-actions">
                            <button class="btn btn-outline-light btn-sm" ${index === 0 ? 'disabled' : ''} onclick="moveManualTeam(${index}, -1)"><i class="bi bi-arrow-up"></i></button>
                            <button class="btn btn-outline-light btn-sm" ${index === state.manualOrder.length - 1 ? 'disabled' : ''} onclick="moveManualTeam(${index}, 1)"><i class="bi bi-arrow-down"></i></button>
                        </div>
                    </div>`;
            })
            .join('');
    }

        function resetLotteryView() {
            if (!elements.lotteryTrack || !elements.lotteryResults) return;
            const teams = state.teams || [];
            elements.lotteryResults.innerHTML = '';
            elements.lotteryTrack.innerHTML = teams
                .map((team) => `
                    <div class="lottery-ball">
                        <img src="${team.photo_url || '/img/default-team.png'}" alt="${team.name || 'Time'}" onerror="this.src='/img/default-team.png'">
                    </div>`)
                .join('');
        }

        function runLotteryAnimation(orderDetails = []) {
            if (!elements.lotteryTrack || !elements.lotteryResults) {
                return Promise.resolve();
            }

            const picks = orderDetails.length ? orderDetails : state.teams;
            elements.lotteryResults.innerHTML = '';
            elements.lotteryTrack.innerHTML = picks
                .map((team) => `
                    <div class="lottery-ball">
                        <img src="${team.photo_url || '/img/default-team.png'}" alt="${team.name || 'Time'}" onerror="this.src='/img/default-team.png'">
                    </div>`)
                .join('');

            const balls = Array.from(elements.lotteryTrack.querySelectorAll('.lottery-ball'));

            return new Promise((resolve) => {
                let index = 0;
                const revealNext = () => {
                    balls.forEach((ball) => ball.classList.remove('active'));
                    if (balls[index]) {
                        balls[index].classList.add('active');
                    }
                    const team = picks[index] || {};
                    elements.lotteryResults.insertAdjacentHTML(
                        'beforeend',
                        `<div class="lottery-result">
                            <span class="lottery-rank">${index + 1}</span>
                            <img src="${team.photo_url || '/img/default-team.png'}" alt="${team.name || 'Time'}" onerror="this.src='/img/default-team.png'">
                            <div>
                                <strong>${team.city || ''} ${team.name || ''}</strong>
                                <div class="small text-muted">${team.owner_name || 'Sem GM'}</div>
                            </div>
                        </div>`
                    );
                    index += 1;
                    if (index < picks.length) {
                        setTimeout(revealNext, 380);
                    } else {
                        setTimeout(() => {
                            balls.forEach((ball) => ball.classList.remove('active'));
                            resolve();
                        }, 420);
                    }
                };

                setTimeout(revealNext, 300);
            });
        }

    function renderPool() {
        const list = (state.pool || []).filter((player) => {
            if (!state.search) return true;
            const needle = state.search;
            return (
                (player.name || '').toLowerCase().includes(needle) ||
                (player.position || '').toLowerCase().includes(needle)
            );
        });

        if (!list.length) {
            elements.poolTable.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Nenhum jogador no pool.</td></tr>';
            return;
        }

        elements.poolTable.innerHTML = list
            .map((player, index) => {
                const drafted = player.draft_status === 'drafted';
                return `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${player.name}</td>
                        <td>${player.position}</td>
                        <td>${player.ovr}</td>
                        <td><span class="badge badge-${drafted ? 'drafted' : 'available'}">${drafted ? 'Drafted' : 'Disponível'}</span></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-success" ${drafted ? 'disabled' : ''} onclick="makePick(${player.id})">
                                <i class="bi bi-check2 me-1"></i>Escolher
                            </button>
                        </td>
                    </tr>`;
            })
            .join('');
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
        orderModal.show();
    }

    async function randomizeOrder() {
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'randomize_order', token: TOKEN }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao sortear ordem');
            state.manualOrder = data.order;
            renderManualOrderList();
            renderOrder();
            await runLotteryAnimation(data.order_details || []);
            showMessage('Ordem sorteada com sucesso.');
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function submitManualOrder() {
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'set_manual_order', token: TOKEN, team_ids: state.manualOrder }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao aplicar ordem');
            showMessage('Ordem atualizada com sucesso.');
            orderModal.hide();
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
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
