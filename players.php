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
</head>
<body>
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
                        <div class="col-md-2">
                            <div class="d-flex justify-content-end align-items-center gap-2">
                                <i class="bi bi-star-fill text-warning"></i>
                                <span class="text-light-gray">Favoritos (máx 5)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card bg-dark-panel border-orange mt-4">
                <div class="card-header bg-dark border-bottom border-orange">
                    <h5 class="mb-0 text-white"><i class="bi bi-list-ul text-orange me-2"></i>Jogadores da Liga</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-8">
                            <div id="playersLoading" class="text-center py-4">
                                <div class="spinner-border text-orange" role="status"></div>
                            </div>
                            <div class="table-responsive" id="playersTableWrap" style="display:none;">
                                <table class="table table-dark table-hover mb-0">
                                    <thead style="background: var(--fba-orange); color: #000;">
                                        <tr>
                                            <th style="width:40px;"></th>
                                            <th>Jogador</th>
                                            <th>OVR</th>
                                            <th>Idade</th>
                                            <th>Posicao</th>
                                            <th>Time</th>
                                            <th>Contato</th>
                                        </tr>
                                    </thead>
                                    <tbody id="playersTableBody"></tbody>
                                </table>
                            </div>
                            <div id="playersEmpty" class="text-center text-light-gray" style="display:none;">Nenhum jogador encontrado.</div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card bg-dark border border-warning h-100">
                                <div class="card-header bg-dark border-bottom border-warning d-flex align-items-center justify-content-between">
                                    <span class="text-white"><i class="bi bi-star-fill text-warning me-2"></i>Favoritos</span>
                                    <small class="text-light-gray" id="favCounter"></small>
                                </div>
                                <div class="card-body" id="favoritesList">
                                    <p class="text-light-gray mb-0">Nenhum favorito ainda.</p>
                                </div>
                            </div>
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
        const searchBtn = document.getElementById('playersSearchBtn');
        const loading = document.getElementById('playersLoading');
        const tableWrap = document.getElementById('playersTableWrap');
        const tableBody = document.getElementById('playersTableBody');
        const emptyState = document.getElementById('playersEmpty');
        const favoritesList = document.getElementById('favoritesList');
        const favCounter = document.getElementById('favCounter');
        let favorites = new Set();

        async function carregarJogadores() {
            const query = searchInput.value.trim();
            const position = positionFilter.value;
            loading.style.display = 'block';
            tableWrap.style.display = 'none';
            emptyState.style.display = 'none';
            tableBody.innerHTML = '';

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
                    players.forEach(p => {
                        const teamName = `${p.city} ${p.team_name}`;
                        const whatsappLink = p.owner_phone_whatsapp
                            ? `https://api.whatsapp.com/send/?phone=${encodeURIComponent(p.owner_phone_whatsapp)}&text=${defaultMessage}&type=phone_number&app_absent=0`
                            : '';
                        const isFav = favorites.has(String(p.id)) || !!p.is_favorite;
                        const starClass = isFav ? 'text-warning' : 'text-light-gray';
                        tableBody.innerHTML += `
                            <tr>
                                <td class="text-center">
                                    <button class="btn btn-link p-0 text-decoration-none fav-btn ${starClass}" data-player-id="${p.id}" title="Favorito">
                                        <i class="bi ${isFav ? 'bi-star-fill' : 'bi-star'} fs-5"></i>
                                    </button>
                                </td>
                                <td><strong>${p.name}</strong></td>
                                <td><span class="badge bg-warning text-dark">${p.ovr}</span></td>
                                <td>${p.age}</td>
                                <td>${p.position}</td>
                                <td>${teamName}</td>
                                <td>
                                    ${whatsappLink ? `
                                    <a class="btn btn-sm btn-outline-success" href="${whatsappLink}" target="_blank" rel="noopener">
                                        <i class="bi bi-whatsapp"></i> Falar
                                    </a>` : '<span class="text-muted">Sem contato</span>'}
                                </td>
                            </tr>
                        `;
                    });
                    bindFavoriteButtons();
                    tableWrap.style.display = 'block';
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

        function renderFavoritesList(list) {
            favorites = new Set(list.map(f => String(f.player_id)));
            favCounter.textContent = `${favorites.size}/5`;
            if (!list.length) {
                favoritesList.innerHTML = '<p class="text-light-gray mb-0">Nenhum favorito ainda.</p>';
                return;
            }
            favoritesList.innerHTML = list.map(f => `
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-secondary">
                    <div>
                        <strong class="text-white">${f.name}</strong>
                        <div class="text-light-gray small">${f.position} | OVR ${f.ovr} — ${f.city} ${f.team_name}</div>
                    </div>
                    <button class="btn btn-sm btn-outline-danger" onclick="toggleFavorite(${f.player_id}, false)">
                        <i class="bi bi-star-fill"></i>
                    </button>
                </div>
            `).join('');
        }

        async function loadFavorites() {
            try {
                const res = await fetch('/api/team.php?action=favorites');
                const data = await res.json();
                renderFavoritesList(data.favorites || []);
            } catch (err) {
                favoritesList.innerHTML = '<p class="text-danger mb-0">Erro ao carregar favoritos.</p>';
            }
        }

        async function toggleFavorite(playerId, setFavorite) {
            try {
                const res = await fetch('/api/team.php?action=toggle_favorite', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ player_id: playerId, favorite: setFavorite })
                });
                const data = await res.json();
                if (!res.ok || data.error) {
                    throw new Error(data.error || 'Erro ao atualizar favorito');
                }
                await loadFavorites();
                await carregarJogadores();
            } catch (err) {
                alert(err.message || 'Erro ao atualizar favorito');
            }
        }

        function bindFavoriteButtons() {
            document.querySelectorAll('.fav-btn').forEach(btn => {
                btn.onclick = () => {
                    const playerId = btn.dataset.playerId;
                    const isFav = favorites.has(String(playerId));
                    if (!isFav && favorites.size >= 5) {
                        alert('Limite de 5 favoritos atingido. Remova um para adicionar outro.');
                        return;
                    }
                    toggleFavorite(playerId, !isFav);
                };
            });
        }

        loadFavorites().then(carregarJogadores);

        
    </script>
</body>
</html>
