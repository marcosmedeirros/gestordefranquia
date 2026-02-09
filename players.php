<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário para a sidebar
$stmtTeam = $pdo->prepare('SELECT t.* FROM teams t WHERE t.user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);

$whatsappDefaultMessage = rawurlencode('Olá! Podemos conversar sobre nossas franquias na FBA?');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Jogadores - FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css">
    <style>
        .btn-trade-action {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            color: #000;
            font-weight: 600;
            border: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
        }

        .btn-trade-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 193, 7, 0.5);
            color: #000;
        }

        .player-card-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .player-card-actions .btn {
            flex: 1;
            min-width: 100px;
        }

        /* Forçar texto branco nos cards mobile */
        .player-card,
        .player-card * {
            color: #ffffff !important;
        }

        .player-card strong {
            color: #ffffff !important;
            font-weight: 600;
        }

        .player-card .text-light-gray {
            color: #cfcfcf !important;
        }

        .player-card-stat strong {
            color: #fc0025 !important;
        }

        .player-card .badge {
            color: #fff !important;
        }

        .player-card .bg-warning {
            color: #000 !important;
        }
    </style>
</head>
<body>
    <!-- Botão Hamburguer para Mobile -->
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="bi bi-list fs-4"></i>
    </button>
    
    <!-- Overlay para fechar sidebar no mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="d-flex">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div class="dashboard-content">
            <div class="mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <h1 class="text-white fw-bold mb-0">
                        <i class="bi bi-people-fill text-orange me-2"></i>Jogadores
                    </h1>
                    <span class="badge bg-dark border border-warning text-warning">
                        <i class="bi bi-trophy me-1"></i><?= htmlspecialchars($user['league'] ?? 'ELITE') ?>
                    </span>
                </div>
                <p class="text-light-gray mb-0">Lista completa de jogadores da sua liga.</p>
            </div>

            <div class="card bg-dark-panel border-orange">
                <div class="card-header bg-dark border-bottom border-orange">
                    <h5 class="mb-0 text-white"><i class="bi bi-filter me-2 text-orange"></i>Filtros</h5>
                </div>
                <div class="card-body">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label for="playersSearchInput" class="form-label">Buscar por nome</label>
                            <input type="text" id="playersSearchInput" class="form-control" placeholder="Digite o nome do jogador">
                        </div>
                        <div class="col-md-3">
                            <label for="playersPositionFilter" class="form-label">Posicao</label>
                            <select id="playersPositionFilter" class="form-select">
                                <option value="">Todas</option>
                                <option value="PG">PG</option>
                                <option value="SG">SG</option>
                                <option value="SF">SF</option>
                                <option value="PF">PF</option>
                                <option value="C">C</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">OVR (mín / máx)</label>
                            <div class="d-flex gap-2">
                                <input type="number" id="playersOvrMin" class="form-control" placeholder="Min" min="40" max="99">
                                <input type="number" id="playersOvrMax" class="form-control" placeholder="Max" min="40" max="99">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Idade (mín / máx)</label>
                            <div class="d-flex gap-2">
                                <input type="number" id="playersAgeMin" class="form-control" placeholder="Min" min="16" max="45">
                                <input type="number" id="playersAgeMax" class="form-control" placeholder="Max" min="16" max="45">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label for="playersTeamFilter" class="form-label">Time</label>
                            <select id="playersTeamFilter" class="form-select">
                                <option value="">Todos</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-orange w-100" id="playersSearchBtn">
                                <i class="bi bi-search me-1"></i>Buscar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card bg-dark-panel border-orange mt-4">
                <div class="card-header bg-dark border-bottom border-orange">
                    <h5 class="mb-0 text-white"><i class="bi bi-list-ul text-orange me-2"></i>Jogadores da Liga</h5>
                </div>
                <div class="card-body">
                    <div id="playersLoading" class="text-center py-4">
                        <div class="spinner-border text-orange" role="status"></div>
                    </div>
                    <div class="table-responsive" id="playersTableWrap" style="display:none;">
                        <table class="table table-dark table-hover mb-0">
                            <thead style="background: var(--fba-brand); color: #000;">
                                <tr>
                                    <th>Jogador</th>
                                    <th>OVR</th>
                                    <th>Idade</th>
                                    <th>Posicao</th>
                                    <th>Time</th>
                                    <th>Contato</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody id="playersTableBody"></tbody>
                        </table>
                    </div>
                    <!-- Render móvel (cards) -->
                    <div id="playersCardsWrap" class="player-card-mobile" style="display:none;"></div>
                    <div id="playersEmpty" class="text-center text-light-gray" style="display:none;">Nenhum jogador encontrado.</div>
                    <div id="playersPagination" class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3" style="display:none;">
                        <div class="text-light-gray" id="playersPaginationInfo"></div>
                        <div class="btn-group">
                            <button class="btn btn-outline-light btn-sm" id="playersPrevPage">
                                <i class="bi bi-chevron-left"></i> Anterior
                            </button>
                            <button class="btn btn-outline-light btn-sm" id="playersNextPage">
                                Próximo <i class="bi bi-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/sidebar.js"></script>
    <script>
        const defaultMessage = '<?= $whatsappDefaultMessage ?>';
        const searchInput = document.getElementById('playersSearchInput');
        const positionFilter = document.getElementById('playersPositionFilter');
    const ovrMinInput = document.getElementById('playersOvrMin');
    const ovrMaxInput = document.getElementById('playersOvrMax');
    const ageMinInput = document.getElementById('playersAgeMin');
    const ageMaxInput = document.getElementById('playersAgeMax');
    const teamFilter = document.getElementById('playersTeamFilter');
        const searchBtn = document.getElementById('playersSearchBtn');
        const loading = document.getElementById('playersLoading');
        const tableWrap = document.getElementById('playersTableWrap');
        const tableBody = document.getElementById('playersTableBody');
        const cardsWrap = document.getElementById('playersCardsWrap');
        const emptyState = document.getElementById('playersEmpty');
    const paginationWrap = document.getElementById('playersPagination');
    const paginationInfo = document.getElementById('playersPaginationInfo');
    const prevPageBtn = document.getElementById('playersPrevPage');
    const nextPageBtn = document.getElementById('playersNextPage');
        const isMobile = () => window.matchMedia('(max-width: 768px)').matches;
    let currentPage = 1;
    let totalPages = 1;
    const perPage = 50;

        function renderPlayerCard(p, whatsappLink, teamName){
            const ovr = Number(p.ovr || 0);
            let ovrClass = 'bg-success';
            if (ovr >= 85) ovrClass = 'bg-success';
            else if (ovr >= 78) ovrClass = 'bg-info';
            else if (ovr >= 72) ovrClass = 'bg-warning text-dark';
            else ovrClass = 'bg-secondary';

            return `
                <div class="player-card">
                    <div class="player-card-header">
                        <div>
                            <strong>${p.name}</strong>
                            <div class="text-light-gray">${teamName}</div>
                        </div>
                        <span class="badge ${ovrClass}">${p.ovr}</span>
                    </div>
                    <div class="player-card-body">
                        <div class="player-card-stat"><strong>Idade</strong>${p.age ?? '-'}</div>
                        <div class="player-card-stat"><strong>Posição</strong>${p.position ?? '-'}</div>
                    </div>
                    <div class="player-card-actions">
                        ${whatsappLink ? `<a class="btn btn-outline-success" href="${whatsappLink}" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> Falar</a>` : '<span class="text-muted">Sem contato</span>'}
                        <a class="btn btn-trade-action" href="/trades.php?player=${p.id}&team=${p.team_id}" title="Propor trade por este jogador">
                            <i class="bi bi-arrow-left-right"></i> Trocar
                        </a>
                    </div>
                </div>
            `;
        }
        async function carregarJogadores() {
            const query = searchInput.value.trim();
            const position = positionFilter.value;
            const ovrMin = ovrMinInput.value;
            const ovrMax = ovrMaxInput.value;
            const ageMin = ageMinInput.value;
            const ageMax = ageMaxInput.value;
            const teamId = teamFilter.value;
            loading.style.display = 'block';
            tableWrap.style.display = 'none';
            cardsWrap.style.display = 'none';
            emptyState.style.display = 'none';
            paginationWrap.style.display = 'none';
            tableBody.innerHTML = '';
            cardsWrap.innerHTML = '';

            const params = new URLSearchParams();
            params.set('action', 'list_players');
            if (query) params.set('query', query);
            if (position) params.set('position', position);
            if (ovrMin) params.set('ovr_min', ovrMin);
            if (ovrMax) params.set('ovr_max', ovrMax);
            if (ageMin) params.set('age_min', ageMin);
            if (ageMax) params.set('age_max', ageMax);
            if (teamId) params.set('team_id', teamId);
            params.set('page', currentPage);
            params.set('per_page', perPage);

            try {
                const res = await fetch(`/api/team.php?${params.toString()}`);
                const data = await res.json();
                const players = data.players || [];
                const pagination = data.pagination || { page: 1, per_page: perPage, total: players.length, total_pages: 1 };
                totalPages = pagination.total_pages || 1;

                if (!players.length) {
                    emptyState.style.display = 'block';
                } else {
                    const mobile = isMobile();
                    players.forEach(p => {
                        const teamName = `${p.city} ${p.team_name}`;
                        const whatsappLink = p.owner_phone_whatsapp
                            ? `https://api.whatsapp.com/send/?phone=${encodeURIComponent(p.owner_phone_whatsapp)}&text=${defaultMessage}&type=phone_number&app_absent=0`
                            : '';

                        if (mobile) {
                            cardsWrap.innerHTML += renderPlayerCard(p, whatsappLink, teamName);
                        } else {
                            const ovr = Number(p.ovr || 0);
                            let ovrClass = 'bg-success';
                            if (ovr >= 85) ovrClass = 'bg-success';
                            else if (ovr >= 78) ovrClass = 'bg-info';
                            else if (ovr >= 72) ovrClass = 'bg-warning text-dark';
                            else ovrClass = 'bg-secondary';

                            tableBody.innerHTML += `
                                <tr>
                                    <td><strong>${p.name}</strong></td>
                                    <td><span class="badge ${ovrClass}">${p.ovr}</span></td>
                                    <td>${p.age}</td>
                                    <td>${p.position}</td>
                                    <td>${teamName}</td>
                                    <td>
                                        ${whatsappLink ? `
                                        <a class="btn btn-sm btn-outline-success" href="${whatsappLink}" target="_blank" rel="noopener">
                                            <i class="bi bi-whatsapp"></i> Falar
                                        </a>` : '<span class="text-muted">Sem contato</span>'}
                                    </td>
                                    <td>
                                        <a class="btn btn-sm btn-trade-action" href="/trades.php?player=${p.id}&team=${p.team_id}" title="Propor trade por este jogador">
                                            <i class="bi bi-arrow-left-right"></i> Trocar
                                        </a>
                                    </td>
                                </tr>
                            `;
                        }
                    });
                    if (mobile) {
                        cardsWrap.style.display = 'block';
                    } else {
                        tableWrap.style.display = 'block';
                    }

                    if (pagination.total > perPage) {
                        paginationInfo.textContent = `Página ${pagination.page} de ${pagination.total_pages} • ${pagination.total} jogadores`;
                        paginationWrap.style.display = 'flex';
                        prevPageBtn.disabled = pagination.page <= 1;
                        nextPageBtn.disabled = pagination.page >= pagination.total_pages;
                    }
                }
            } catch (err) {
                emptyState.textContent = 'Erro ao carregar jogadores.';
                emptyState.style.display = 'block';
            } finally {
                loading.style.display = 'none';
            }
        }

        async function carregarTimesFiltro() {
            try {
                const res = await fetch('/api/team.php');
                const data = await res.json();
                const teams = data.teams || [];
                teamFilter.innerHTML = '<option value="">Todos</option>' + teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('');
            } catch (err) {
                console.warn('Erro ao carregar times:', err);
            }
        }

        searchBtn.addEventListener('click', () => { currentPage = 1; carregarJogadores(); });
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { currentPage = 1; carregarJogadores(); }
        });
    positionFilter.addEventListener('change', () => { currentPage = 1; carregarJogadores(); });
    ovrMinInput.addEventListener('change', () => { currentPage = 1; carregarJogadores(); });
    ovrMaxInput.addEventListener('change', () => { currentPage = 1; carregarJogadores(); });
    ageMinInput.addEventListener('change', () => { currentPage = 1; carregarJogadores(); });
    ageMaxInput.addEventListener('change', () => { currentPage = 1; carregarJogadores(); });
        teamFilter.addEventListener('change', () => { currentPage = 1; carregarJogadores(); });
        prevPageBtn.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage -= 1;
                carregarJogadores();
            }
        });
        nextPageBtn.addEventListener('click', () => {
            if (currentPage < totalPages) {
                currentPage += 1;
                carregarJogadores();
            }
        });
        carregarTimesFiltro();
        carregarJogadores();
        
        // Re-render layout apenas se mudar de mobile para desktop ou vice-versa
        let lastIsMobile = isMobile();
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                const currentIsMobile = isMobile();
                // Só recarrega se mudou de mobile para desktop ou vice-versa
                if (lastIsMobile !== currentIsMobile) {
                    lastIsMobile = currentIsMobile;
                    carregarJogadores();
                }
            }, 500);
        });
    </script>
    <script src="/js/sidebar.js"></script>
</body>
</html>
