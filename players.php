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
        const searchBtn = document.getElementById('playersSearchBtn');
        const loading = document.getElementById('playersLoading');
        const tableWrap = document.getElementById('playersTableWrap');
        const tableBody = document.getElementById('playersTableBody');
        const cardsWrap = document.getElementById('playersCardsWrap');
        const emptyState = document.getElementById('playersEmpty');
        const isMobile = () => window.matchMedia('(max-width: 768px)').matches;

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
            loading.style.display = 'block';
            tableWrap.style.display = 'none';
            cardsWrap.style.display = 'none';
            emptyState.style.display = 'none';
            tableBody.innerHTML = '';
            cardsWrap.innerHTML = '';

            const params = new URLSearchParams();
            params.set('action', 'list_players');
            if (query) params.set('query', query);
            if (position) params.set('position', position);

            try {
                const res = await fetch(`/api/team.php?${params.toString()}`);
                const data = await res.json();
                const players = data.players || [];

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
                }
            } catch (err) {
                emptyState.textContent = 'Erro ao carregar jogadores.';
                emptyState.style.display = 'block';
            } finally {
                loading.style.display = 'none';
            }
        }

        searchBtn.addEventListener('click', carregarJogadores);
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') carregarJogadores();
        });
        positionFilter.addEventListener('change', carregarJogadores);
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
