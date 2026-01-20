// Gerenciamento de Temporadas e Sprints
const seasonsState = {
    currentLeague: null,
    currentSeason: null,
    draftPlayers: []
};

function resolveStartYear(season) {
    if (!season) return null;
    if (season.start_year) return Number(season.start_year);
    if (season.year && season.season_number) {
        return Number(season.year) - Number(season.season_number) + 1;
    }
    return null;
}

function promptStartYear(defaultYear) {
    const fallback = defaultYear ?? new Date().getFullYear();
    const input = prompt('Informe o ano inicial do sprint (ex: 2016):', fallback);
    if (input === null) return null;
    const parsed = parseInt(input, 10);
    if (!parsed || parsed < 1900) {
        alert('Ano inválido. Informe um número como 2016.');
        return null;
    }
    return parsed;
}

// ========== TELA PRINCIPAL DE TEMPORADAS ==========
async function showSeasonsManagement() {
    console.log('showSeasonsManagement chamada!'); // Debug
    
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
                <div class="card bg-dark-panel border-orange">
                    <div class="card-body">
                        <h5 class="text-orange mb-2">
                            <i class="bi bi-calendar-check"></i> Temporadas
                        </h5>
                        <p class="text-light-gray mb-0">Cada liga possui um número específico de temporadas por sprint (ciclo).</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-body">
                        <h5 class="text-orange mb-2">
                            <i class="bi bi-people"></i> Picks Automáticas
                        </h5>
                        <p class="text-light-gray mb-0">Ao criar uma temporada, são geradas automaticamente 2 picks (1ª e 2ª rodada) para cada time.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-dark-panel border-orange">
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
    const currentSeason = await loadCurrentSeason(league);
    const startYear = resolveStartYear(currentSeason) ?? promptStartYear(new Date().getFullYear());
    if (!startYear) return;

    const nextSeasonNumber = Number(currentSeason?.season_number || 0) + 1;
    const seasonYear = startYear + nextSeasonNumber - 1;

    if (!confirm(`Criar temporada ${String(nextSeasonNumber).padStart(2, '0')} para a liga ${league} (ano ${seasonYear})?`)) {
        return;
    }
    
    try {
        const data = await api('seasons.php?action=create_season', {
            method: 'POST',
            body: JSON.stringify({ league, season_year: seasonYear, start_year: startYear })
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
    
    if (!season) {
        const container = document.getElementById('mainContainer');
        container.innerHTML = `
            <div class="mb-4">
                <button class="btn btn-back" onclick="showSeasonsManagement()">
                    <i class="bi bi-arrow-left"></i> Voltar
                </button>
            </div>
            <div class="alert alert-info" style="border-radius: 15px;">
                <i class="bi bi-info-circle me-2"></i>
                Nenhuma temporada ativa para a liga ${league}. Crie uma temporada primeiro.
            </div>
        `;
        return;
    }

    const container = document.getElementById('mainContainer');
    container.innerHTML = `
        <div class="mb-4">
            <button class="btn btn-back" onclick="showSeasonsManagement()">
                <i class="bi bi-arrow-left"></i> Voltar
            </button>
        </div>
        
        <div class="row g-3 mb-4">
            <div class="col-md-8">
                <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
                    <div class="card-body">
                        <h4 class="text-white mb-1">Draft - Temporada ${season.season_number}</h4>
                        <p class="text-light-gray mb-0">${league} | Sprint ${season.sprint_number || '?'} | Ano ${season.year}</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <button class="btn btn-orange w-100 h-100" onclick="showAddDraftPlayerModal(${season.id})" style="border-radius: 15px;">
                    <i class="bi bi-plus-circle me-1"></i>Adicionar Jogador ao Draft
                </button>
            </div>
        </div>

        <!-- Nav Tabs -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link active" id="draft-tab" data-bs-toggle="tab" href="#draft-panel">
                    <i class="bi bi-trophy me-1"></i>Jogadores do Draft
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="history-tab" data-bs-toggle="tab" href="#history-panel">
                    <i class="bi bi-clock-history me-1"></i>Cadastrar Histórico
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content">
            <div class="tab-pane fade show active" id="draft-panel">
                <div id="draftPlayersContainer">
                    <div class="text-center py-4">
                        <div class="spinner-border text-orange"></div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade" id="history-panel">
                <div id="historyContainer">
                    ${renderHistoryForm(season.id, league)}
                </div>
            </div>
        </div>
    `;
    
    loadDraftPlayers(season.id);
}

// ========== FORMULÁRIO DE HISTÓRICO ==========
function renderHistoryForm(seasonId, league) {
    return `
        <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
            <div class="card-body">
                <h5 class="text-white mb-4">
                    <i class="bi bi-pencil-square text-orange me-2"></i>
                    Cadastrar Resultados da Temporada
                </h5>
                
                <form id="formSeasonHistory" onsubmit="saveSeasonHistory(event, ${seasonId})">
                    <h6 class="text-orange mb-3">1. Classificação da Temporada Regular</h6>
                    <div class="mb-4" id="standingsContainer">
                        <button type="button" class="btn btn-sm btn-outline-orange" onclick="loadTeamsForStandings('${league}')">
                            <i class="bi bi-download me-1"></i>Carregar Times da Liga
                        </button>
                    </div>

                    <h6 class="text-orange mb-3">2. Resultados dos Playoffs</h6>
                    <div class="mb-3">
                        <label class="form-label text-light-gray">Campeão</label>
                        <select class="form-select bg-dark text-white border-orange" name="champion_team_id" required style="border-radius: 15px;">
                            <option value="">Selecione o campeão...</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-light-gray">Vice-Campeão</label>
                        <select class="form-select bg-dark text-white border-orange" name="runnerup_team_id" required style="border-radius: 15px;">
                            <option value="">Selecione o vice...</option>
                        </select>
                    </div>

                    <h6 class="text-orange mb-3">3. Eliminados por Fase (apenas perdedores)</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label text-light-gray">1ª Rodada (1 ponto)</label>
                            <select class="form-select bg-dark text-white border-orange" name="first_round_losses" multiple style="border-radius: 12px; min-height: 120px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-light-gray">2ª Rodada (3 pontos)</label>
                            <select class="form-select bg-dark text-white border-orange" name="second_round_losses" multiple style="border-radius: 12px; min-height: 120px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-light-gray">Final de Conferência (6 pontos)</label>
                            <select class="form-select bg-dark text-white border-orange" name="conference_final_losses" multiple style="border-radius: 12px; min-height: 120px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                    </div>

                    <h6 class="text-orange mb-3">4. Premiações</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">MVP (Time)</label>
                            <select class="form-select bg-dark text-white border-orange" name="mvp_team_id" style="border-radius: 15px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">MVP (Jogador)</label>
                            <input type="text" class="form-control bg-dark text-white border-orange" name="mvp_player_name" placeholder="Nome do jogador" style="border-radius: 15px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">DPOY (Time)</label>
                            <select class="form-select bg-dark text-white border-orange" name="dpoy_team_id" style="border-radius: 15px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">DPOY (Jogador)</label>
                            <input type="text" class="form-control bg-dark text-white border-orange" name="dpoy_player_name" placeholder="Nome do jogador" style="border-radius: 15px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">MIP (Time)</label>
                            <select class="form-select bg-dark text-white border-orange" name="mip_team_id" style="border-radius: 15px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">MIP (Jogador)</label>
                            <input type="text" class="form-control bg-dark text-white border-orange" name="mip_player_name" placeholder="Nome do jogador" style="border-radius: 15px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">6th Man (Time)</label>
                            <select class="form-select bg-dark text-white border-orange" name="sixth_man_team_id" style="border-radius: 15px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">6th Man (Jogador)</label>
                            <input type="text" class="form-control bg-dark text-white border-orange" name="sixth_man_player_name" placeholder="Nome do jogador" style="border-radius: 15px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">ROY (Time)</label>
                            <select class="form-select bg-dark text-white border-orange" name="roy_team_id" style="border-radius: 15px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">ROY (Jogador)</label>
                            <input type="text" class="form-control bg-dark text-white border-orange" name="roy_player_name" placeholder="Nome do jogador" style="border-radius: 15px;">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-orange" style="border-radius: 15px;">
                            <i class="bi bi-save me-1"></i>Salvar Histórico
                        </button>
                        <button type="button" class="btn btn-outline-orange" onclick="loadDraftPlayers(${seasonId})" style="border-radius: 15px;">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    `;
}

async function loadTeamsForStandings(league) {
    try {
        const data = await api(`admin.php?action=teams&league=${league}`);
        const teams = data.teams || [];
        
        const container = document.getElementById('standingsContainer');
        container.innerHTML = `
            <div class="table-responsive">
                <table class="table table-dark">
                    <thead>
                        <tr>
                            <th>Posição</th>
                            <th>Time</th>
                            <th>V</th>
                            <th>D</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${teams.map((t, idx) => `
                            <tr>
                                <td><input type="number" class="form-control form-control-sm bg-dark text-white" name="position_${t.id}" value="${idx + 1}" min="1" style="width: 70px; border-radius: 10px;"></td>
                                <td>${t.city} ${t.name}</td>
                                <td><input type="number" class="form-control form-control-sm bg-dark text-white" name="wins_${t.id}" value="0" min="0" style="width: 70px; border-radius: 10px;"></td>
                                <td><input type="number" class="form-control form-control-sm bg-dark text-white" name="losses_${t.id}" value="0" min="0" style="width: 70px; border-radius: 10px;"></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;

        // Popular selects de premiações
        const selects = document.querySelectorAll('select[name$="_team_id"]');
        selects.forEach(select => {
            select.innerHTML = '<option value="">Selecione...</option>' + 
                teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('');
        });

        // Popular multi-selects de eliminados por fase
        ['first_round_losses','second_round_losses','conference_final_losses'].forEach(name => {
            const select = document.querySelector(`select[name="${name}"]`);
            if (select) {
                select.innerHTML = '<option value="">Selecione...</option>' +
                    teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('');
            }
        });
    } catch (e) {
        alert('Erro ao carregar times: ' + (e.error || 'Desconhecido'));
    }
}

function saveSeasonHistory(event, seasonId) {
    event.preventDefault();
    const form = event.target;
    const getMulti = (name) => {
        const select = form.querySelector(`select[name="${name}"]`);
        if (!select) return [];
        return Array.from(select.selectedOptions).map(o => o.value).filter(Boolean);
    };

    const champion = form.champion_team_id.value;
    const runnerUp = form.runnerup_team_id.value;
    const firstRound = getMulti('first_round_losses');
    const secondRound = getMulti('second_round_losses');
    const confFinal = getMulti('conference_final_losses');

    if (!champion || !runnerUp) {
        alert('Selecione campeão e vice.');
        return;
    }
    if (champion === runnerUp) {
        alert('Campeão e vice não podem ser iguais.');
        return;
    }

    const allEliminated = [...firstRound, ...secondRound, ...confFinal];
    const hasDuplicates = new Set(allEliminated).size !== allEliminated.length;
    if (hasDuplicates) {
        alert('Um time não pode aparecer em mais de uma fase eliminada.');
        return;
    }
    if (allEliminated.includes(champion) || allEliminated.includes(runnerUp)) {
        alert('Não inclua campeão ou vice nas listas de eliminados.');
        return;
    }

    const payload = {
        season_id: seasonId,
        champion,
        runner_up: runnerUp,
        first_round_losses: firstRound,
        second_round_losses: secondRound,
        conference_final_losses: confFinal,
        mvp: form.mvp_player_name.value || null,
        mvp_team_id: form.mvp_team_id.value || null,
        dpoy: form.dpoy_player_name.value || null,
        dpoy_team_id: form.dpoy_team_id.value || null,
        mip: form.mip_player_name.value || null,
        mip_team_id: form.mip_team_id.value || null,
        sixth_man: form.sixth_man_player_name.value || null,
        sixth_man_team_id: form.sixth_man_team_id.value || null,
        roy: form.roy_player_name?.value || null,
        roy_team_id: form.roy_team_id?.value || null
    };

    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Salvando...';

    api('seasons.php?action=save_history', {
        method: 'POST',
        body: JSON.stringify(payload)
    }).then(() => {
        alert('Histórico salvo! Pontuação atualizada.');
        loadDraftPlayers(seasonId);
    }).catch(e => {
        alert('Erro ao salvar histórico: ' + (e.error || 'Desconhecido'));
    }).finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

// ========== GERENCIAR DRAFT (continuação) ==========

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
                                <div class="card bg-dark-panel border-orange h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <span class="badge bg-orange">${p.position}</span>
                                            <span class="badge bg-success">OVR ${p.ovr}</span>
                                        </div>
                                        <h6 class="text-white mb-1">${p.name}</h6>
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
        const seasonId = seasonsState.currentSeason ? seasonsState.currentSeason.id : null;
        if (seasonId) {
            loadDraftPlayers(seasonId);
        }
        alert('Jogador removido do draft!');
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

// Expor funções para o escopo global (necessário para onclick no HTML)
window.showSeasonsManagement = showSeasonsManagement;
window.createNewSeason = createNewSeason;
window.showDraftManagement = showDraftManagement;
window.deleteDraftPlayer = deleteDraftPlayer;
