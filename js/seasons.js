// Gerenciamento de Temporadas e Sprints
const seasonsState = {
    currentLeague: null,
    currentSeason: null,
    draftPlayers: []
};

// ========== TELA PRINCIPAL DE TEMPORADAS ==========
async function showSeasonsManagement() {
    // Atualizar breadcrumb
    appState.view = 'seasons';
    updateBreadcrumb();
    
    const container = document.getElementById('mainContainer');
    container.innerHTML = `
        <div class="row g-4 mb-4">
            <div class="col-12">
                <h3 class="text-white mb-3">
                    <i class="bi bi-calendar3 text-orange me-2"></i>
                    Gerenciar Temporadas
                </h3>
            </div>
            
            <!-- ELITE -->
            <div class="col-md-6 col-lg-3">
                <div class="league-card" style="cursor: default;">
                    <h3>ELITE</h3>
                    <p class="text-light-gray mb-2">20 temporadas por sprint</p>
                    <button class="btn btn-sm btn-outline-orange w-100 mb-2" onclick="createNewSeason('ELITE')">
                        <i class="bi bi-plus-circle me-1"></i>Nova Temporada
                    </button>
                    <button class="btn btn-sm btn-orange w-100" onclick="showDraftManagement(null, 'ELITE')">
                        <i class="bi bi-trophy me-1"></i>Gerenciar Draft
                    </button>
                </div>
            </div>
            
            <!-- NEXT -->
            <div class="col-md-6 col-lg-3">
                <div class="league-card" style="cursor: default;">
                    <h3>NEXT</h3>
                    <p class="text-light-gray mb-2">15 temporadas por sprint</p>
                    <button class="btn btn-sm btn-outline-orange w-100 mb-2" onclick="createNewSeason('NEXT')">
                        <i class="bi bi-plus-circle me-1"></i>Nova Temporada
                    </button>
                    <button class="btn btn-sm btn-orange w-100" onclick="showDraftManagement(null, 'NEXT')">
                        <i class="bi bi-trophy me-1"></i>Gerenciar Draft
                    </button>
                </div>
            </div>
            
            <!-- RISE -->
            <div class="col-md-6 col-lg-3">
                <div class="league-card" style="cursor: default;">
                    <h3>RISE</h3>
                    <p class="text-light-gray mb-2">10 temporadas por sprint</p>
                    <button class="btn btn-sm btn-outline-orange w-100 mb-2" onclick="createNewSeason('RISE')">
                        <i class="bi bi-plus-circle me-1"></i>Nova Temporada
                    </button>
                    <button class="btn btn-sm btn-orange w-100" onclick="showDraftManagement(null, 'RISE')">
                        <i class="bi bi-trophy me-1"></i>Gerenciar Draft
                    </button>
                </div>
            </div>
            
            <!-- ROOKIE -->
            <div class="col-md-6 col-lg-3">
                <div class="league-card" style="cursor: default;">
                    <h3>ROOKIE</h3>
                    <p class="text-light-gray mb-2">10 temporadas por sprint</p>
                    <button class="btn btn-sm btn-outline-orange w-100 mb-2" onclick="createNewSeason('ROOKIE')">
                        <i class="bi bi-plus-circle me-1"></i>Nova Temporada
                    </button>
                    <button class="btn btn-sm btn-orange w-100" onclick="showDraftManagement(null, 'ROOKIE')">
                        <i class="bi bi-trophy me-1"></i>Gerenciar Draft
                    </button>
                </div>
            </div>
        </div>
        
        <div class="row g-4">
            <div class="col-12">
                <h3 class="text-white mb-3">
                    <i class="bi bi-info-circle text-orange me-2"></i>
                    Informações do Sistema
                </h3>
            </div>
            <div class="col-md-4">
                <div class="card bg-dark-elevated border-0">
                    <div class="card-body">
                        <h5 class="text-orange mb-2">
                            <i class="bi bi-calendar-check"></i> Temporadas
                        </h5>
                        <p class="text-light-gray mb-0">Cada liga possui um número específico de temporadas por sprint (ciclo).</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-dark-elevated border-0">
                    <div class="card-body">
                        <h5 class="text-orange mb-2">
                            <i class="bi bi-people"></i> Picks Automáticas
                        </h5>
                        <p class="text-light-gray mb-0">Ao criar uma temporada, são geradas automaticamente 2 picks (1ª e 2ª rodada) para cada time.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-dark-elevated border-0">
                    <div class="card-body">
                        <h5 class="text-orange mb-2">
                            <i class="bi bi-bar-chart"></i> Ranking Acumulativo
                        </h5>
                        <p class="text-light-gray mb-0">Os pontos do ranking são acumulativos e nunca resetam. Use "Rankings" no menu para visualizar.</p>
                    </div>
                </div>
            </div>
        </div>
    `;
}

// ========== BUSCAR TEMPORADA ATUAL ==========
async function loadCurrentSeason(league) {
    try {
        const data = await api(`seasons.php?action=current_season&league=${league}`);
        seasonsState.currentSeason = data.season;
        return data.season;
    } catch (e) {
        console.error('Erro ao carregar temporada:', e);
        return null;
    }
}

// ========== CRIAR NOVA TEMPORADA ==========
async function createNewSeason(league) {
    if (!confirm(`Criar nova temporada para a liga ${league}?\n\nIsso irá:\n- Criar uma nova temporada\n- Gerar picks automaticamente (1ª e 2ª rodada) para todos os times`)) {
        return;
    }
    
    try {
        const data = await api('seasons.php?action=create_season', {
            method: 'POST',
            body: JSON.stringify({ league })
        });
        
        alert(data.message);
        showSeasonsManagement();
    } catch (e) {
        alert('Erro ao criar temporada: ' + (e.error || 'Desconhecido'));
    }
}

// ========== GERENCIAR DRAFT ==========
async function showDraftManagement(seasonId, league) {
    seasonsState.currentLeague = league;
    const season = await loadCurrentSeason(league);
    
    const container = document.getElementById('mainContainer');
    container.innerHTML = `
        <div class="mb-4">
            <button class="btn btn-back" onclick="showSeasonsManagement()">
                <i class="bi bi-arrow-left"></i> Voltar
            </button>
        </div>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="text-white mb-1">Draft - Temporada ${season?.season_number || '?'}</h4>
                <small class="text-light-gray">${league} | Sprint ${season?.sprint_number || '?'}</small>
            </div>
            <button class="btn btn-orange" onclick="showAddDraftPlayerModal(${seasonId})">
                <i class="bi bi-plus-circle me-1"></i>Adicionar Jogador
            </button>
        </div>
        
        <div id="draftPlayersContainer">
            <div class="text-center py-4">
                <div class="spinner-border text-orange"></div>
            </div>
        </div>
    `;
    
    loadDraftPlayers(seasonId);
}

async function loadDraftPlayers(seasonId) {
    try {
        const data = await api(`seasons.php?action=draft_players&season_id=${seasonId}`);
        seasonsState.draftPlayers = data.players;
        renderDraftPlayers(data.players);
    } catch (e) {
        document.getElementById('draftPlayersContainer').innerHTML = `
            <div class="alert alert-danger">Erro ao carregar jogadores: ${e.error}</div>
        `;
    }
}

function renderDraftPlayers(players) {
    const available = players.filter(p => p.draft_status === 'available');
    const drafted = players.filter(p => p.draft_status === 'drafted');
    
    const container = document.getElementById('draftPlayersContainer');
    container.innerHTML = `
        <div class="row g-3">
            <div class="col-md-8">
                <div class="bg-dark-panel border-orange rounded p-4">
                    <h5 class="text-white mb-3">
                        <i class="bi bi-people-fill me-2 text-orange"></i>
                        Disponíveis (${available.length})
                    </h5>
                    <div class="row g-3">
                        ${available.map(p => `
                            <div class="col-md-4">
                                <div class="card bg-dark text-white h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <span class="badge bg-orange">${p.position}</span>
                                            <span class="badge bg-success">OVR ${p.ovr}</span>
                                        </div>
                                        <h6 class="mb-1">${p.name}</h6>
                                        <p class="text-light-gray small mb-2">${p.age} anos</p>
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-sm btn-outline-warning flex-fill" onclick="editDraftPlayer(${p.id})">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger flex-fill" onclick="deleteDraftPlayer(${p.id})">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `).join('') || '<p class="text-light-gray">Nenhum jogador disponível</p>'}
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bg-dark-panel border-success rounded p-4">
                    <h5 class="text-white mb-3">
                        <i class="bi bi-check-circle-fill me-2 text-success"></i>
                        Draftados (${drafted.length})
                    </h5>
                    ${drafted.map(p => `
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-dark rounded">
                            <div>
                                <div class="text-white small">${p.name}</div>
                                <div class="text-light-gray" style="font-size: 0.75rem;">Pick #${p.draft_order}</div>
                            </div>
                            <span class="badge bg-success">${p.ovr}</span>
                        </div>
                    `).join('') || '<p class="text-light-gray small">Nenhum ainda</p>'}
                </div>
            </div>
        </div>
    `;
}

// ========== MODAL ADICIONAR JOGADOR ==========
function showAddDraftPlayerModal(seasonId) {
    const modalHtml = `
        <div class="modal fade" id="addDraftPlayerModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content bg-dark-panel border-orange">
                    <div class="modal-header border-orange">
                        <h5 class="modal-title text-white">Adicionar Jogador ao Draft</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="formAddDraftPlayer">
                            <input type="hidden" name="season_id" value="${seasonId}">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label text-light-gray">Nome</label>
                                    <input type="text" class="form-control bg-dark text-white border-orange" name="name" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-light-gray">Idade</label>
                                    <input type="number" class="form-control bg-dark text-white border-orange" name="age" min="18" max="40" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-light-gray">Posição</label>
                                    <select class="form-select bg-dark text-white border-orange" name="position" required>
                                        <option value="Armador">Armador</option>
                                        <option value="Ala-Armador">Ala-Armador</option>
                                        <option value="Ala">Ala</option>
                                        <option value="Ala-Pivô">Ala-Pivô</option>
                                        <option value="Pivô">Pivô</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-light-gray">OVR</label>
                                    <input type="number" class="form-control bg-dark text-white border-orange" name="ovr" min="1" max="99" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label text-light-gray">URL da Foto</label>
                                    <input type="url" class="form-control bg-dark text-white border-orange" name="photo_url" placeholder="https://...">
                                </div>
                                <div class="col-12">
                                    <label class="form-label text-light-gray">Biografia</label>
                                    <textarea class="form-control bg-dark text-white border-orange" name="bio" rows="2"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-light-gray">Pontos Fortes</label>
                                    <textarea class="form-control bg-dark text-white border-orange" name="strengths" rows="2"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-light-gray">Pontos Fracos</label>
                                    <textarea class="form-control bg-dark text-white border-orange" name="weaknesses" rows="2"></textarea>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer border-orange">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-orange" onclick="submitDraftPlayer()">Adicionar</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = new bootstrap.Modal(document.getElementById('addDraftPlayerModal'));
    modal.show();
    
    document.getElementById('addDraftPlayerModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

async function submitDraftPlayer() {
    const form = document.getElementById('formAddDraftPlayer');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    
    try {
        await api('seasons.php?action=add_draft_player', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        
        bootstrap.Modal.getInstance(document.getElementById('addDraftPlayerModal')).hide();
        loadDraftPlayers(data.season_id);
        alert('Jogador adicionado ao draft!');
    } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
    }
}

async function deleteDraftPlayer(id) {
    if (!confirm('Remover este jogador do draft?')) return;
    
    try {
        await api(`seasons.php?action=delete_draft_player&id=${id}`, { method: 'DELETE' });
        loadDraftPlayers(seasonsState.currentSeason.id);
    } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
    }
}

// ========== RANKING ==========
async function showRankingPage(type = 'global') {
    // Atualizar breadcrumb
    appState.view = 'ranking';
    updateBreadcrumb();
    
    const container = document.getElementById('mainContainer');
    container.innerHTML = `
        <div class="mb-4">
            <h4 class="text-white mb-3">
                <i class="bi bi-trophy-fill me-2 text-orange"></i>
                Ranking ${type === 'global' ? 'Geral' : 'por Liga'}
            </h4>
            <div class="btn-group mb-3">
                <button class="btn ${type === 'global' ? 'btn-orange' : 'btn-outline-orange'}" onclick="showRankingPage('global')">Geral</button>
                <button class="btn ${type === 'elite' ? 'btn-orange' : 'btn-outline-orange'}" onclick="showRankingPage('elite')">ELITE</button>
                <button class="btn ${type === 'next' ? 'btn-orange' : 'btn-outline-orange'}" onclick="showRankingPage('next')">NEXT</button>
                <button class="btn ${type === 'rise' ? 'btn-orange' : 'btn-outline-orange'}" onclick="showRankingPage('rise')">RISE</button>
                <button class="btn ${type === 'rookie' ? 'btn-orange' : 'btn-outline-orange'}" onclick="showRankingPage('rookie')">ROOKIE</button>
            </div>
        </div>
        <div id="rankingContainer">
            <div class="text-center py-4">
                <div class="spinner-border text-orange"></div>
            </div>
        </div>
    `;
    
    try {
        const endpoint = type === 'global' 
            ? 'seasons.php?action=global_ranking'
            : `seasons.php?action=league_ranking&league=${type.toUpperCase()}`;
        
        const data = await api(endpoint);
        renderRanking(data.ranking);
    } catch (e) {
        document.getElementById('rankingContainer').innerHTML = `
            <div class="alert alert-danger">Erro ao carregar ranking</div>
        `;
    }
}

function renderRanking(ranking) {
    const container = document.getElementById('rankingContainer');
    container.innerHTML = `
        <div class="table-responsive">
            <table class="table table-dark table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Time</th>
                        <th>Liga</th>
                        <th>Pontos</th>
                        <th>Temporadas</th>
                        <th>🏆 Títulos</th>
                        <th>🥈 Vices</th>
                        <th>⭐ Prêmios</th>
                    </tr>
                </thead>
                <tbody>
                    ${ranking.map((team, idx) => `
                        <tr>
                            <td><strong class="text-orange">${idx + 1}º</strong></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="${team.photo_url || '/img/default-team.png'}" 
                                         alt="${team.team_name}" 
                                         style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                    <span>${team.city} ${team.team_name}</span>
                                </div>
                            </td>
                            <td><span class="badge bg-gradient-orange">${team.league}</span></td>
                            <td><strong class="text-warning">${team.total_points || 0}</strong></td>
                            <td>${team.seasons_played || 0}</td>
                            <td>${team.championships || 0}</td>
                            <td>${team.runner_ups || 0}</td>
                            <td>${team.total_awards || 0}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}
