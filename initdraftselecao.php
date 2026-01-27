<?php
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/auth.php';

$token = $_GET['token'] ?? null;
if (!$token) {
    http_response_code(403);
    echo 'Token inválido.';
    exit;
}

$pdo = db();
$user = getUserSession();
$isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
$userTeamId = null;
if ($user && isset($user['id'])) {
    $stmtTeam = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? LIMIT 1');
    $stmtTeam->execute([$user['id']]);
    $userTeamId = $stmtTeam->fetchColumn() ?: null;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Draft Inicial - Seleção</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --draft-bg: #000000;
            --draft-panel: #141414;
            --draft-panel-alt: #1a1a1a;
            --draft-border: #2a2a2a;
            --draft-muted: #cfcfcf;
            --draft-primary: #fc0025;
            --draft-green: #38d07d;
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

        .draft-app {
            max-width: 1280px;
        }

        .card-dark {
            background: var(--draft-panel);
            border: 1px solid var(--draft-border);
            border-radius: 18px;
        }

        .hero {
            background: linear-gradient(120deg, rgba(252, 0, 37, 0.25), rgba(7, 7, 13, 0.85));
            border-radius: 24px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 1rem;
        }

        .stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--draft-muted);
            margin-bottom: 0.4rem;
        }

        .team-chip {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .team-chip img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .pick-card {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 1rem;
        }

        .current-pick-highlight {
            border-color: rgba(252, 0, 37, 0.7);
            box-shadow: 0 0 18px rgba(252, 0, 37, 0.35);
        }

        .next-pick-highlight {
            border-color: rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.04);
        }

        .pick-rank {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(252, 0, 37, 0.2);
            border: 1px solid rgba(252, 0, 37, 0.6);
            display: grid;
            place-items: center;
            font-weight: 600;
        }

        .table-dark {
            --bs-table-bg: transparent;
            --bs-table-striped-bg: rgba(255, 255, 255, 0.04);
            color: #fff;
        }

        .badge-available {
            background: rgba(56, 208, 125, 0.18);
            color: var(--draft-green);
        }

        .badge-drafted {
            background: rgba(255, 255, 255, 0.08);
            color: #adb5bd;
        }

        .order-highlight {
            border: 1px solid rgba(252, 0, 37, 0.6);
            background: rgba(252, 0, 37, 0.12);
            border-radius: 12px;
            padding: 0.35rem 0.5rem;
        }

        .order-next {
            border: 1px dashed rgba(255, 255, 255, 0.25);
            border-radius: 12px;
            padding: 0.35rem 0.5rem;
        }

        .accent-red {
            color: #FC062A !important;
        }

        .pick-flash {
            animation: pickFlash 1.2s ease-in-out;
        }

        @keyframes pickFlash {
            0% { box-shadow: 0 0 0 rgba(252, 0, 37, 0); }
            30% { box-shadow: 0 0 28px rgba(252, 0, 37, 0.6); }
            100% { box-shadow: 0 0 0 rgba(252, 0, 37, 0); }
        }

        .tv-mode body,
        body.tv-mode {
            background: #000000;
        }

        body.tv-mode .draft-app {
            max-width: 1600px;
        }

        body.tv-mode .hero {
            padding: 2.5rem;
        }

        body.tv-mode h1 {
            font-size: clamp(2.4rem, 4vw, 3.2rem);
        }

        body.tv-mode .stat-card {
            padding: 1.4rem;
        }

        body.tv-mode .pick-card {
            padding: 1.4rem;
            font-size: 1.05rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4 draft-app">
        <div class="mb-3">
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Voltar ao Dashboard
            </a>
        </div>
        <header class="hero">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                <div>
                    <p class="text-uppercase text-warning fw-semibold mb-2">Draft Inicial</p>
                    <h1 class="mb-2">Sala de Seleção</h1>
                    <p class="mb-0 text-light">Acompanhe o andamento do draft, picks atuais e elencos montados.</p>
                </div>
                <div class="text-lg-end">
                    <p class="text-uppercase small text-muted mb-1">Liga</p>
                    <h4 id="leagueName" class="mb-2">-</h4>
                    <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                        <button class="btn btn-outline-light btn-sm" type="button" onclick="loadState()">
                            <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
                        </button>
                        <button class="btn btn-outline-warning btn-sm" type="button" id="toggleSoundButton">
                            <i class="bi bi-volume-up me-1"></i>Som
                        </button>
                        <button class="btn btn-outline-light btn-sm" type="button" id="toggleTvButton">
                            <i class="bi bi-fullscreen me-1"></i>Modo TV
                        </button>
                    </div>
                </div>
            </div>
            <div class="row g-3 mt-4" id="statGrid"></div>
        </header>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card-dark p-4 mb-4">
                    <h5 class="mb-3">Pick Atual</h5>
                    <div id="currentPickCard"></div>
                    <hr class="border-secondary my-4">
                    <h6 class="text-uppercase text-white">Próximo Pick</h6>
                    <div id="nextPickCard" class="mt-3"></div>
                </div>
                <div class="card-dark p-4">
                    <h5 class="mb-3">Ordem do Draft</h5>
                    <div id="orderList" class="small"></div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card-dark p-4 mb-4">
                    <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                        <h5 class="mb-0">Jogadores do Pool</h5>
                        <span class="text-muted" id="poolMeta"></span>
                    </div>
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-7">
                            <input type="text" id="poolSearch" class="form-control" placeholder="Buscar jogador" />
                        </div>
                        <div class="col-md-5">
                            <select id="poolPositionFilter" class="form-select">
                                <option value="">Todas as posições</option>
                                <option value="PG">PG</option>
                                <option value="SG">SG</option>
                                <option value="SF">SF</option>
                                <option value="PF">PF</option>
                                <option value="C">C</option>
                            </select>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Jogador</th>
                                    <th>Posição</th>
                                    <th>OVR</th>
                                    <th>Idade</th>
                                    <th class="text-end">Ação</th>
                                </tr>
                            </thead>
                            <tbody id="poolTable"></tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2" id="poolPagination"></div>
                </div>

                <div class="card-dark p-4">
                    <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                        <h5 class="mb-0">Elencos em Montagem</h5>
                        <span class="text-muted" id="rosterMeta"></span>
                    </div>
                    <div class="row g-3" id="rosterGrid"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const TOKEN = '<?php echo htmlspecialchars($token, ENT_QUOTES); ?>';
    const API_URL = 'api/initdraft.php';
    const USER_TEAM_ID = <?php echo $userTeamId ? (int)$userTeamId : 'null'; ?>;
    const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;

        const state = {
            session: null,
            order: [],
            teams: [],
            pool: [],
        };

        const elements = {
            leagueName: document.getElementById('leagueName'),
            statGrid: document.getElementById('statGrid'),
            currentPickCard: document.getElementById('currentPickCard'),
            nextPickCard: document.getElementById('nextPickCard'),
            orderList: document.getElementById('orderList'),
            poolTable: document.getElementById('poolTable'),
            poolMeta: document.getElementById('poolMeta'),
            rosterGrid: document.getElementById('rosterGrid'),
            rosterMeta: document.getElementById('rosterMeta'),
            toggleSoundButton: document.getElementById('toggleSoundButton'),
            toggleTvButton: document.getElementById('toggleTvButton'),
            poolSearch: document.getElementById('poolSearch'),
            poolPositionFilter: document.getElementById('poolPositionFilter'),
            poolPagination: document.getElementById('poolPagination'),
        };

        const uiState = {
            soundEnabled: false,
            lastPickId: null,
            poolSearch: '',
            poolPosition: '',
            poolPage: 1,
            poolPageSize: 15,
        };

        function teamLabel(pick) {
            if (!pick) return '—';
            return `${pick.team_city || ''} ${pick.team_name || ''}`.trim();
        }

        function renderStats() {
            const session = state.session;
            if (!session) return;

            elements.leagueName.textContent = session.league || '-';
            const drafted = state.order.filter((pick) => pick.picked_player_id).length;
            const total = state.order.length || (session.total_rounds ?? 0) * (state.teams.length || 0);
            const progress = total ? Math.round((drafted / total) * 100) : 0;
            const currentPick = state.order.find((pick) => !pick.picked_player_id);
            const nextPick = state.order.find((pick, idx) => !pick.picked_player_id && idx > state.order.indexOf(currentPick));

            elements.statGrid.innerHTML = `
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-label">Status</div>
                        <div>${session.status || '-'}</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-label">Rodada Atual</div>
                        <div>${session.current_round ?? '-'}</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-label">Pick Atual</div>
                        <div>${currentPick ? `${currentPick.round}.${currentPick.pick_position}` : '-'}</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-label">Progresso</div>
                        <div>${drafted} / ${total} (${progress}%)</div>
                    </div>
                </div>
            `;
        }

        function renderPickCard(target, pick, label, highlightClass = '') {
            if (!pick) {
                target.innerHTML = `<div class="text-muted">Nenhuma pick disponível.</div>`;
                return;
            }
            target.innerHTML = `
                <div class="pick-card ${highlightClass}">
                    <div class="d-flex align-items-center gap-3">
                        <div class="pick-rank">${pick.pick_position}</div>
                        <div>
                            <div class="small text-muted">${label}</div>
                            <div class="fw-semibold">${teamLabel(pick)}</div>
                            <div class="small text-muted">Rodada ${pick.round}</div>
                        </div>
                    </div>
                </div>
            `;
        }

        function renderOrderList(currentPick, nextPick) {
            if (!state.order.length) {
                elements.orderList.innerHTML = '<div class="text-muted">Ordem ainda não definida.</div>';
                return;
            }
            const roundOne = state.order.filter((pick) => pick.round === 1).sort((a, b) => a.pick_position - b.pick_position);
            elements.orderList.innerHTML = roundOne
                .map((pick, index) => `
                    <div class="d-flex align-items-center justify-content-between gap-3 mb-2 ${currentPick && pick.team_id === currentPick.team_id ? 'order-highlight' : ''} ${nextPick && pick.team_id === nextPick.team_id ? 'order-next' : ''}">
                        <div class="d-flex align-items-center gap-2">
                            <span class="pick-rank" style="width:32px;height:32px;">${index + 1}</span>
                            <div class="team-chip">
                                <img src="${pick.team_photo || '/img/default-team.png'}" alt="${pick.team_name || 'Time'}" onerror="this.src='/img/default-team.png'">
                                <div>
                                    <strong>${teamLabel(pick)}</strong>
                                    <div class="small accent-red">${pick.team_owner || 'Sem GM'}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                `)
                .join('');
        }

        function renderPool(currentPick) {
            const pool = state.pool || [];
            const search = uiState.poolSearch.trim();
            const positionFilter = uiState.poolPosition;
            const filtered = pool.filter((player) => {
                const matchesSearch = !search || (player.name || '').toLowerCase().includes(search);
                const matchesPosition = !positionFilter || player.position === positionFilter;
                return matchesSearch && matchesPosition;
            });

            const total = filtered.length;
            const totalPages = Math.max(1, Math.ceil(total / uiState.poolPageSize));
            if (uiState.poolPage > totalPages) {
                uiState.poolPage = totalPages;
            }
            const startIndex = (uiState.poolPage - 1) * uiState.poolPageSize;
            const pageItems = filtered.slice(startIndex, startIndex + uiState.poolPageSize);

            elements.poolMeta.textContent = `${total} jogadores`;
            if (!pageItems.length) {
                elements.poolTable.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Nenhum jogador disponível.</td></tr>';
                elements.poolPagination.innerHTML = '';
                return;
            }

            const canPick = state.session?.status === 'in_progress' && (IS_ADMIN || (currentPick && USER_TEAM_ID && currentPick.team_id === USER_TEAM_ID));
            elements.poolTable.innerHTML = pageItems
                .map((player, index) => {
                    const drafted = player.draft_status === 'drafted';
                    const action = (!drafted && canPick)
                        ? `<button class="btn btn-sm btn-success" onclick="makePick(${player.id})"><i class="bi bi-check2 me-1"></i>Escolher</button>`
                        : '<span class="text-muted">-</span>';
                    return `
                        <tr>
                            <td>${startIndex + index + 1}</td>
                            <td>${player.name}</td>
                            <td>${player.position}</td>
                            <td>${player.ovr}</td>
                            <td>${player.age || '-'}</td>
                            <td class="text-end">${action}</td>
                        </tr>
                    `;
                })
                .join('');

            elements.poolPagination.innerHTML = `
                <div class="text-white">Página ${uiState.poolPage} de ${totalPages}</div>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-light" ${uiState.poolPage === 1 ? 'disabled' : ''} onclick="changePoolPage(${uiState.poolPage - 1})">Anterior</button>
                    <button class="btn btn-sm btn-outline-light" ${uiState.poolPage === totalPages ? 'disabled' : ''} onclick="changePoolPage(${uiState.poolPage + 1})">Próxima</button>
                </div>
            `;
        }

        function changePoolPage(page) {
            uiState.poolPage = page;
            const currentPick = state.order.find((pick) => !pick.picked_player_id);
            renderPool(currentPick);
        }

        function renderRosters() {
            const picks = state.order.filter((pick) => pick.picked_player_id);
            const grouped = {};
            picks.forEach((pick) => {
                const key = pick.team_id;
                if (!grouped[key]) {
                    grouped[key] = {
                        team: pick,
                        players: []
                    };
                }
                grouped[key].players.push(pick);
            });

            const teams = Object.values(grouped);
            elements.rosterMeta.textContent = `${teams.length} times com picks`;

            if (!teams.length) {
                elements.rosterGrid.innerHTML = '<div class="text-light">Nenhum elenco montado ainda.</div>';
                return;
            }

            elements.rosterGrid.innerHTML = teams
                .map((group) => {
                    const roster = group.players
                        .map((pick) => `<li>${pick.player_name} <span class="accent-red">(${pick.player_position ?? ''} • ${pick.player_ovr ?? '-'})</span></li>`)
                        .join('');
                    return `
                        <div class="col-md-6 col-xl-4">
                            <div class="card-dark p-3 h-100">
                                <div class="team-chip mb-2">
                                    <img src="${group.team.team_photo || '/img/default-team.png'}" alt="${group.team.team_name || 'Time'}" onerror="this.src='/img/default-team.png'">
                                    <div>
                                        <strong>${teamLabel(group.team)}</strong>
                                        <div class="small accent-red">${group.team.team_owner || 'Sem GM'}</div>
                                    </div>
                                </div>
                                <ul class="small ps-3 mb-0 text-light">${roster}</ul>
                            </div>
                        </div>
                    `;
                })
                .join('');
        }

        async function loadState() {
            try {
                const [stateRes, poolRes] = await Promise.all([
                    fetch(`${API_URL}?action=state&token=${TOKEN}`).then((r) => r.json()),
                    fetch(`${API_URL}?action=pool&token=${TOKEN}`).then((r) => r.json()),
                ]);
                if (!stateRes.success) throw new Error(stateRes.error || 'Erro ao carregar estado');
                state.session = stateRes.session;
                state.order = stateRes.order || [];
                state.teams = stateRes.teams || [];
                state.pool = poolRes.success ? poolRes.players : [];
                renderStats();
                const currentPick = state.order.find((pick) => !pick.picked_player_id);
                const nextPick = state.order.find((pick, idx) => !pick.picked_player_id && idx > state.order.indexOf(currentPick));
                handlePickChange(currentPick);
                renderPickCard(elements.currentPickCard, currentPick, 'Pick Atual', 'current-pick-highlight');
                renderPickCard(elements.nextPickCard, nextPick, 'Próximo', 'next-pick-highlight');
                renderOrderList(currentPick, nextPick);
                renderPool(currentPick);
                renderRosters();
            } catch (error) {
                elements.poolTable.innerHTML = `<tr><td colspan="6" class="text-danger">${error.message}</td></tr>`;
            }
        }

        function setupAutoRefresh() {
            const isMobile = window.matchMedia('(max-width: 768px)').matches;
            if (!isMobile) {
                setInterval(() => {
                    if (document.visibilityState === 'visible') {
                        loadState();
                    }
                }, 10000);
            }

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    loadState();
                }
            });
        }

        async function makePick(playerId) {
            if (!confirm('Confirmar a escolha deste jogador?')) return;
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'make_pick', token: TOKEN, player_id: playerId })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao registrar pick');
                await loadState();
            } catch (error) {
                alert(error.message);
            }
        }

        function handlePickChange(currentPick) {
            const pickId = currentPick?.id || null;
            if (pickId && uiState.lastPickId && pickId !== uiState.lastPickId) {
                elements.currentPickCard.classList.remove('pick-flash');
                void elements.currentPickCard.offsetWidth;
                elements.currentPickCard.classList.add('pick-flash');
                if (uiState.soundEnabled) {
                    playBeep();
                }
            }
            if (pickId) {
                uiState.lastPickId = pickId;
            }
        }

        function playBeep() {
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.value = 740;
                gain.gain.value = 0.06;
                oscillator.connect(gain);
                gain.connect(audioCtx.destination);
                oscillator.start();
                oscillator.stop(audioCtx.currentTime + 0.25);
                oscillator.onended = () => audioCtx.close();
            } catch (error) {
                console.warn('Audio não disponível');
            }
        }

        function toggleSound() {
            uiState.soundEnabled = !uiState.soundEnabled;
            elements.toggleSoundButton?.classList.toggle('btn-warning', uiState.soundEnabled);
            elements.toggleSoundButton?.classList.toggle('btn-outline-warning', !uiState.soundEnabled);
            elements.toggleSoundButton?.querySelector('i')?.classList.toggle('bi-volume-mute', !uiState.soundEnabled);
            elements.toggleSoundButton?.querySelector('i')?.classList.toggle('bi-volume-up', uiState.soundEnabled);
        }

        function toggleTvMode() {
            document.body.classList.toggle('tv-mode');
            const isTv = document.body.classList.contains('tv-mode');
            if (isTv && document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else if (!isTv && document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            }
        }

        elements.poolSearch?.addEventListener('input', (event) => {
            uiState.poolSearch = event.target.value.toLowerCase();
            uiState.poolPage = 1;
            const currentPick = state.order.find((pick) => !pick.picked_player_id);
            renderPool(currentPick);
        });

        elements.poolPositionFilter?.addEventListener('change', (event) => {
            uiState.poolPosition = event.target.value;
            uiState.poolPage = 1;
            const currentPick = state.order.find((pick) => !pick.picked_player_id);
            renderPool(currentPick);
        });

        elements.toggleSoundButton?.addEventListener('click', toggleSound);
        elements.toggleTvButton?.addEventListener('click', toggleTvMode);

    setupAutoRefresh();
    loadState();
    </script>
</body>
</html>
