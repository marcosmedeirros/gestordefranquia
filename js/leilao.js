/**
 * Leilao de Jogadores - JavaScript
 * Sistema de trocas via leilao
 */

document.addEventListener('DOMContentLoaded', function() {
    // Carregar dados iniciais
    carregarLeiloesAtivos();
    
    if (userTeamId) {
        carregarPropostasRecebidas();
    }

    carregarHistoricoLeiloes();
    
    if (isAdmin) {
        carregarLeiloesAdmin();
        carregarPendentesCriados();
        setupAdminEvents();
    }
});

// Helper: parse JSON safely and surface server HTML errors as readable text
async function parseJsonSafe(response) {
    const text = await response.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        const snippet = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
        throw new Error(snippet || 'Resposta inválida do servidor');
    }
}

// ========== ADMIN FUNCTIONS ==========

function setupAdminEvents() {
    const selectLeague = document.getElementById('selectLeague');
    const btnCadastrar = document.getElementById('btnCadastrarLeilao');
    const searchInput = document.getElementById('auctionPlayerSearch');
    const searchResults = document.getElementById('auctionPlayerResults');
    const selectedLabel = document.getElementById('auctionSelectedLabel');
    const selectedPlayerIdInput = document.getElementById('auctionSelectedPlayerId');
    const selectedTeamIdInput = document.getElementById('auctionSelectedTeamId');
    const modeSearch = document.getElementById('auctionModeSearch');
    const modeCreate = document.getElementById('auctionModeCreate');
    const searchArea = document.getElementById('auctionSearchArea');
    const createArea = document.getElementById('auctionCreateArea');
    const createNameInput = document.getElementById('auctionPlayerName');
    const createPositionSelect = document.getElementById('auctionPlayerPosition');
    const createAgeInput = document.getElementById('auctionPlayerAge');
    const createOvrInput = document.getElementById('auctionPlayerOvr');
    // No origin team for created players
    const searchBtn = document.getElementById('auctionSearchBtn');
    const createBtn = document.getElementById('btnCriarJogadorLeilao');

    if (!selectLeague || !btnCadastrar) {
        return;
    }

    const setMode = () => {
        const isCreate = modeCreate?.checked;
        if (searchArea) searchArea.style.display = isCreate ? 'none' : 'block';
        if (createArea) createArea.style.display = isCreate ? 'block' : 'none';
        // Origin team is OPTIONAL for created players; only require name/position/age/ovr and league
        const createReady = !!(createNameInput?.value.trim() && createPositionSelect?.value && createAgeInput?.value && createOvrInput?.value);
        const hasLeague = !!selectLeague.value;
        btnCadastrar.disabled = isCreate ? !(createReady && hasLeague) : !(selectedPlayerIdInput?.value && hasLeague);
        if (createBtn) {
            createBtn.disabled = !(isCreate && createReady && hasLeague);
        }
    };

    modeSearch?.addEventListener('change', setMode);
    modeCreate?.addEventListener('change', setMode);

    selectLeague.addEventListener('change', async function() {
        btnCadastrar.disabled = true;
        if (selectedPlayerIdInput) selectedPlayerIdInput.value = '';
        if (selectedTeamIdInput) selectedTeamIdInput.value = '';
        if (selectedLabel) selectedLabel.style.display = 'none';
        // No origin team to load for created players
        setMode();
    });

    [createNameInput, createPositionSelect, createAgeInput, createOvrInput].forEach(input => {
        input?.addEventListener('input', setMode);
        input?.addEventListener('change', setMode);
    });

    btnCadastrar.addEventListener('click', cadastrarJogadorLeilao);
    createBtn?.addEventListener('click', criarJogadorParaLista);

    searchBtn?.addEventListener('click', async () => {
        const term = searchInput.value.trim();
        const leagueOption = selectLeague.options?.[selectLeague.selectedIndex];
        const leagueName = leagueOption?.dataset?.leagueName || '';
        if (term.length < 2) {
            if (searchResults) searchResults.style.display = 'none';
            if (selectedLabel) selectedLabel.style.display = 'none';
            return;
        }
        if (!leagueName) {
            if (searchResults) searchResults.style.display = 'none';
            if (selectedLabel) {
                selectedLabel.textContent = 'Selecione uma liga para buscar jogadores.';
                selectedLabel.style.display = 'block';
            }
            return;
        }

        try {
            const response = await fetch(`api/team.php?action=search_player&query=${encodeURIComponent(term)}&league=${encodeURIComponent(leagueName)}`);
            const data = await response.json();
            const players = data.players || [];

            if (!searchResults) return;
            if (!players.length) {
                searchResults.style.display = 'none';
                return;
            }

            searchResults.innerHTML = players.map(player => `
                <button type="button" class="list-group-item list-group-item-action" data-player-id="${player.id}" data-team-id="${player.team_id}" data-league="${player.league}">
                    ${player.name} - ${player.team_name} (${player.ovr || player.overall})
                </button>
            `).join('');
            searchResults.style.display = 'block';

            searchResults.querySelectorAll('button').forEach(btn => {
                btn.addEventListener('click', () => {
                    const teamId = btn.dataset.teamId;
                    const playerId = btn.dataset.playerId;
                    if (selectedPlayerIdInput) selectedPlayerIdInput.value = playerId;
                    if (selectedTeamIdInput) selectedTeamIdInput.value = teamId;
                    btnCadastrar.disabled = false;

                    searchResults.style.display = 'none';
                    searchInput.value = `${btn.textContent.trim()}`;
                    if (selectedLabel) {
                        selectedLabel.textContent = `Selecionado: ${btn.textContent.trim()}`;
                        selectedLabel.style.display = 'block';
                    }
                });
            });
        } catch (error) {
            if (searchResults) searchResults.style.display = 'none';
        }
    });

    setMode();
}

async function cadastrarJogadorLeilao() {
    const selectedPlayerId = document.getElementById('auctionSelectedPlayerId')?.value || '';
    const selectedTeamId = document.getElementById('auctionSelectedTeamId')?.value || '';
    const playerId = selectedPlayerId;
    const teamId = selectedTeamId;
    const leagueId = document.getElementById('selectLeague').value;
    const newPlayerEnabled = document.getElementById('auctionModeCreate')?.checked;
    
    if ((!playerId && !newPlayerEnabled) || !leagueId) {
        alert('Selecione liga e jogador');
        return;
    }

    const payload = {
        action: 'cadastrar',
        player_id: playerId || null,
        // For created players there is NO origin team; team_id comes only from search flow
        team_id: newPlayerEnabled ? null : (teamId || null),
        league_id: leagueId
    };

    if (newPlayerEnabled) {
        payload.new_player = {
            name: document.getElementById('auctionPlayerName')?.value || '',
            position: document.getElementById('auctionPlayerPosition')?.value || '',
            age: document.getElementById('auctionPlayerAge')?.value || '',
            ovr: document.getElementById('auctionPlayerOvr')?.value || ''
        };
    }
    
    try {
        const response = await fetch('api/leilao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        const data = await parseJsonSafe(response);
        
        if (data.success) {
            alert('Jogador cadastrado no leilao com sucesso!');
            // Limpar selecoes
            const selectLeague = document.getElementById('selectLeague');
            if (selectLeague) selectLeague.value = '';
            const searchInput = document.getElementById('auctionPlayerSearch');
            if (searchInput) searchInput.value = '';
            const searchResults = document.getElementById('auctionPlayerResults');
            if (searchResults) {
                searchResults.innerHTML = '';
                searchResults.style.display = 'none';
            }
            const selectedPlayerId = document.getElementById('auctionSelectedPlayerId');
            const selectedTeamId = document.getElementById('auctionSelectedTeamId');
            const selectedLabel = document.getElementById('auctionSelectedLabel');
            if (selectedPlayerId) selectedPlayerId.value = '';
            if (selectedTeamId) selectedTeamId.value = '';
            if (selectedLabel) selectedLabel.style.display = 'none';
            const modeSearch = document.getElementById('auctionModeSearch');
            if (modeSearch) modeSearch.checked = true;
            const btnCadastrarLeilao = document.getElementById('btnCadastrarLeilao');
            if (btnCadastrarLeilao) btnCadastrarLeilao.disabled = true;
            const playerName = document.getElementById('auctionPlayerName');
            if (playerName) playerName.value = '';
            const playerAge = document.getElementById('auctionPlayerAge');
            if (playerAge) playerAge.value = '25';
            const playerOvr = document.getElementById('auctionPlayerOvr');
            if (playerOvr) playerOvr.value = '70';
            
            // Recarregar listas
            carregarLeiloesAdmin();
            carregarLeiloesAtivos();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
            alert('Erro ao cadastrar jogador no leilao');
    }
}

async function criarJogadorParaLista() {
    const leagueId = document.getElementById('selectLeague')?.value;
    const name = document.getElementById('auctionPlayerName')?.value.trim();
    const position = document.getElementById('auctionPlayerPosition')?.value;
    const age = parseInt(document.getElementById('auctionPlayerAge')?.value || '0', 10);
    const ovr = parseInt(document.getElementById('auctionPlayerOvr')?.value || '0', 10);

    if (!leagueId) {
        alert('Selecione a liga antes de criar o jogador.');
        return;
    }
    if (!name || !position || !age || !ovr) {
        alert('Preencha nome, posição, idade e OVR para criar o jogador.');
        return;
    }

    try {
        // Primeiro cria os dados temporários do jogador (sem time)
        const resCreate = await fetch('api/leilao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'criar_jogador',
                league_id: leagueId,
                new_player: { name, position, age, ovr }
            })
        });
        const dataCreate = await parseJsonSafe(resCreate);
        if (!resCreate.ok || !dataCreate.success) {
            throw new Error(dataCreate.error || 'Erro ao criar jogador');
        }

        // Em seguida, cadastra como PENDENTE (sem iniciar) para aparecer na lista abaixo
        const resAuction = await fetch('api/leilao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'cadastrar',
                player_id: null,
                team_id: null,
                league_id: leagueId,
                new_player: { name, position, age, ovr },
                status: 'pendente'
            })
        });
        const dataAuction = await parseJsonSafe(resAuction);
        if (!resAuction.ok || !dataAuction.success) {
            throw new Error(dataAuction.error || 'Erro ao cadastrar jogador pendente');
        }

        // Feedback visual e limpeza
        const selectedLabel = document.getElementById('auctionSelectedLabel');
        if (selectedLabel) {
            selectedLabel.textContent = `Leilão iniciado: ${name} (${position}, OVR ${ovr})`;
            selectedLabel.style.display = 'block';
        }
        document.getElementById('auctionPlayerName').value = '';
        document.getElementById('auctionPlayerAge').value = '25';
        document.getElementById('auctionPlayerOvr').value = '70';

        alert('Jogador criado! Ele aparece abaixo como pendente.');

        // Recarregar listas para aparecer abaixo
        carregarLeiloesAdmin();
        carregarPendentesCriados();
        carregarLeiloesAtivos();
    } catch (error) {
        console.error(error);
        alert(error.message || 'Erro ao criar jogador');
    }
}

async function carregarPendentesCriados() {
    const container = document.getElementById('auctionTempList');
    if (!container) return;

    try {
        // Listar todos os jogadores criados especificamente para o leilão (sem time de origem)
    const res = await fetch('api/leilao.php?action=listar_temp');
    const data = await parseJsonSafe(res);
        const criados = data.leiloes || [];

        if (!criados.length) {
            container.innerHTML = '<p class="text-light-gray">Nenhum jogador criado.</p>';
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
        html += '<thead><tr><th>Jogador</th><th>Liga</th><th>Status</th><th>Ações</th></tr></thead><tbody>';
        criados.forEach(l => {
            const teamLabel = l.team_name || 'Sem time';
            const status = (l.status || '').toLowerCase();
            const statusBadge = status === 'pendente' ? '<span class="badge bg-warning">Pendente</span>'
                               : status === 'ativo' ? '<span class="badge bg-success">Ativo</span>'
                               : status === 'finalizado' ? '<span class="badge bg-secondary">Finalizado</span>'
                               : `<span class="badge bg-dark">${l.status || '—'}</span>`;
            html += `<tr>
                <td><strong class="text-orange">${l.player_name}</strong><br><small class="text-light-gray">${l.position} | OVR ${l.ovr}</small></td>
                <td>${l.league_name || '-'}</td>
                <td>${statusBadge} • ${teamLabel}</td>
                <td>
                    ${status === 'pendente' ? `
                        <button class="btn btn-sm btn-success me-2" onclick="iniciarLeilaoPendente(${l.id})">
                            <i class="bi bi-play-fill"></i> Iniciar 20min
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="removerLeilaoPendente(${l.id})">
                            <i class="bi bi-trash"></i> Remover
                        </button>
                    ` : status === 'ativo' ? `
                        <button class="btn btn-sm btn-info" onclick="verPropostasAdmin(${l.id})">
                            <i class="bi bi-eye"></i> Ver Propostas
                        </button>
                    ` : ''}
                </td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch (error) {
        console.error(error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar jogadores criados.</p>';
    }
}

async function iniciarLeilaoPendente(leilaoId) {
    try {
        const res = await fetch('api/leilao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'iniciar_leilao', leilao_id: leilaoId })
        });
        const data = await parseJsonSafe(res);
        if (!data.success) throw new Error(data.error || 'Erro ao iniciar leilão');
        carregarPendentesCriados();
        carregarLeiloesAdmin();
        carregarLeiloesAtivos();
    } catch (error) {
        alert(error.message || 'Erro ao iniciar leilão');
    }
}

async function removerLeilaoPendente(leilaoId) {
    if (!confirm('Remover este jogador pendente?')) return;
    try {
        const res = await fetch('api/leilao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'remover_temp', leilao_id: leilaoId })
        });
        const data = await parseJsonSafe(res);
        if (!data.success) throw new Error(data.error || 'Erro ao remover');
        carregarPendentesCriados();
        carregarLeiloesAdmin();
    } catch (error) {
        alert(error.message || 'Erro ao remover');
    }
}

async function carregarLeiloesAdmin() {
    const container = document.getElementById('adminLeiloesContainer');
    if (!container) return;
    
    try {
        const response = await fetch('api/leilao.php?action=listar_admin');
        const data = await response.json();
        
        if (data.success && data.leiloes && data.leiloes.length > 0) {
            let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
            html += '<thead><tr><th>Jogador</th><th>Time</th><th>Liga</th><th>Status</th><th>Propostas</th><th>Acoes</th></tr></thead><tbody>';
            
            data.leiloes.forEach(leilao => {
                const statusBadge = leilao.status === 'ativo' 
                    ? '<span class="badge bg-success">Ativo</span>'
                    : leilao.status === 'finalizado'
                    ? '<span class="badge bg-secondary">Finalizado</span>'
                    : '<span class="badge bg-warning">Pendente</span>';
                const teamLabel = leilao.team_name || 'Sem time';
                
                html += `<tr>
                    <td><strong class="text-orange">${leilao.player_name}</strong><br><small class="text-light-gray">${leilao.position} | OVR ${leilao.ovr}</small></td>
                    <td>${teamLabel}</td>
                    <td>${leilao.league_name}</td>
                    <td>${statusBadge}</td>
                    <td><span class="badge bg-info">${leilao.total_propostas || 0}</span></td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="verPropostasAdmin(${leilao.id})">
                            <i class="bi bi-eye"></i> Ver Propostas
                        </button>
                        ${leilao.status === 'ativo' ? `
                        <button class="btn btn-sm btn-danger" onclick="cancelarLeilao(${leilao.id})">
                            <i class="bi bi-x-lg"></i> Cancelar
                        </button>
                        ` : ''}
                    </td>
                </tr>`;
            });
            
            html += '</tbody></table></div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="text-muted">Nenhum leilao cadastrado.</p>';
        }
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar leiloes.</p>';
    }
}

async function cancelarLeilao(leilaoId) {
    if (!confirm('Tem certeza que deseja cancelar este leilao?')) return;
    
    try {
        const response = await fetch('api/leilao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'cancelar',
                leilao_id: leilaoId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Leilao cancelado!');
            carregarLeiloesAdmin();
            carregarLeiloesAtivos();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao cancelar leilao');
    }
}

// ========== USER FUNCTIONS ==========

async function carregarLeiloesAtivos() {
    const container = document.getElementById('leiloesAtivosContainer');
    if (!container) return;
    
    try {
        const url = currentLeagueId 
            ? `api/leilao.php?action=listar_ativos&league_id=${currentLeagueId}`
            : 'api/leilao.php?action=listar_ativos';
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success && data.leiloes && data.leiloes.length > 0) {
            let banner = '';
            if (typeof faStatusEnabled !== 'undefined' && !faStatusEnabled) {
                banner = '<div class="alert alert-warning py-2 mb-2"><strong>Período fechado:</strong> propostas temporariamente desativadas nesta liga.</div>';
            }
            let html = banner + '<div class="row g-3">';
            
            data.leiloes.forEach(leilao => {
                const isMyTeam = leilao.team_id == userTeamId;
                const cardClass = isMyTeam ? 'border-warning' : 'border-secondary';
                
                const teamLabel = leilao.team_name || 'Sem time';
                html += `
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="card bg-dark text-white ${cardClass}">
                        <div class="card-header bg-dark border-bottom border-secondary">
                            <strong class="text-orange">${leilao.player_name}</strong>
                            ${isMyTeam ? '<span class="badge bg-dark ms-2">Seu Jogador</span>' : ''}
                        </div>
                        <div class="card-body">
                            <p class="mb-1"><i class="bi bi-person"></i> ${leilao.position} | ${leilao.age} anos</p>
                            <p class="mb-1"><i class="bi bi-star-fill text-warning"></i> OVR: ${leilao.ovr}</p>
                            <p class="mb-1"><i class="bi bi-building"></i> Time: ${teamLabel}</p>
                            <p class="mb-2"><i class="bi bi-trophy"></i> Liga: ${leilao.league_name}</p>
                            ${leilao.data_fim ? `<p class="mb-2"><i class="bi bi-clock"></i> <span class="auction-timer" data-end-time="${leilao.data_fim}">20:00</span></p>` : ''}
                            <hr>
                            <p class="mb-2"><i class="bi bi-chat-dots"></i> Propostas: <span class="badge bg-info">${leilao.total_propostas || 0}</span></p>
                            ${!isMyTeam && userTeamId ? (() => {
                                const disabled = (typeof faStatusEnabled !== 'undefined' && !faStatusEnabled) ? 'disabled' : '';
                                const label = (typeof faStatusEnabled !== 'undefined' && !faStatusEnabled) ? 'Período fechado' : 'Enviar Proposta';
                                const onclick = (typeof faStatusEnabled !== 'undefined' && !faStatusEnabled) ? '' : `onclick=\"abrirModalProposta(${leilao.id}, '${leilao.player_name.replace(/'/g, "\\'")}')\"`;
                                return `<button class=\"btn btn-primary btn-sm w-100 auction-propose-btn\" ${disabled} ${onclick}><i class=\"bi bi-send\"></i> ${label}</button>`;
                            })() : ''}
                        </div>
                    </div>
                </div>`;
            });
            
            html += '</div>';
            container.innerHTML = html;
            iniciarCronometros();
        } else {
            container.innerHTML = '<p class="text-light-gray">Nenhum leilao em andamento.</p>';
        }
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar leiloes.</p>';
    }
}

async function carregarHistoricoLeiloes() {
    const container = document.getElementById('leiloesHistoricoContainer');
    if (!container) return;

    try {
        const url = currentLeagueId ? `api/leilao.php?action=historico&league_id=${currentLeagueId}` : 'api/leilao.php?action=historico';
        const response = await fetch(url);
        const data = await response.json();

        if (!data.success || !data.leiloes?.length) {
            container.innerHTML = '<p class="text-light-gray">Nenhum leilao finalizado.</p>';
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
        html += '<thead><tr><th>Jogador</th><th>Time Origem</th><th>Vencedor</th><th>Fim</th></tr></thead><tbody>';
        data.leiloes.forEach(item => {
            html += `<tr>
                <td><strong class="text-orange">${item.player_name}</strong></td>
                <td>${item.team_name}</td>
                <td>${item.winner_team_name || '-'}</td>
                <td>${item.data_fim || '-'}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch (error) {
        container.innerHTML = '<p class="text-danger">Erro ao carregar historico.</p>';
    }
}

async function carregarMinhasPropostas() {
    const container = document.getElementById('minhasPropostasContainer');
    if (!container) return;
    
    try {
        const response = await fetch('api/leilao.php?action=minhas_propostas');
        const data = await response.json();
        
        if (data.success && data.propostas && data.propostas.length > 0) {
            let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
            html += '<thead><tr><th>Jogador Desejado</th><th>Jogadores Oferecidos</th><th>Status</th><th>Data</th><th>Acoes</th></tr></thead><tbody>';
            
            data.propostas.forEach(proposta => {
                const statusBadge = proposta.status === 'pendente'
                    ? '<span class="badge bg-warning">Pendente</span>'
                    : proposta.status === 'aceita'
                    ? '<span class="badge bg-success">Aceita</span>'
                    : '<span class="badge bg-danger">Recusada</span>';
                
                const resendAction = proposta.status === 'recusada'
                    ? `<button class="btn btn-sm btn-outline-warning" onclick="abrirModalProposta(${proposta.leilao_id}, '${(proposta.player_name || '').replace(/'/g, "\\'")}')">
                            <i class="bi bi-arrow-repeat me-1"></i>Novo lance
                       </button>`
                    : '';

                html += `<tr>
                    <td><strong class="text-orange">${proposta.player_name}</strong><br><small class="text-light-gray">${proposta.team_name}</small></td>
                    <td>${proposta.jogadores_oferecidos || proposta.notas || 'N/A'}</td>
                    <td>${statusBadge}</td>
                    <td>${proposta.created_at}</td>
                    <td>${resendAction || '-'}</td>
                </tr>`;
            });
            
            html += '</tbody></table></div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="text-light-gray">Voce nao enviou nenhuma proposta.</p>';
        }
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar propostas.</p>';
    }
}

async function carregarPropostasRecebidas() {
    const container = document.getElementById('propostasRecebidasContainer');
    if (!container) return;
    
    try {
        const response = await fetch('api/leilao.php?action=propostas_recebidas');
        const data = await response.json();
        
        if (data.success && data.leiloes && data.leiloes.length > 0) {
            let html = '';
            
            data.leiloes.forEach(leilao => {
                html += `
                <div class="card bg-dark border border-secondary text-white mb-3">
                    <div class="card-header bg-dark border-bottom border-secondary d-flex justify-content-between align-items-center">
                        <span><strong>${leilao.player_name}</strong> - ${leilao.position} | OVR ${leilao.ovr}</span>
                        <span class="badge bg-info">${leilao.total_propostas} proposta(s)</span>
                    </div>
                    <div class="card-body">
                        <button class="btn btn-primary" onclick="verMinhasPropostasRecebidas(${leilao.id})">
                            <i class="bi bi-eye"></i> Ver e Escolher Proposta
                        </button>
                    </div>
                </div>`;
            });
            
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="text-light-gray">Nenhum jogador seu esta em leilao com propostas.</p>';
        }
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar propostas.</p>';
    }
}

// ========== MODAL PROPOSTA ==========

async function abrirModalProposta(leilaoId, playerName) {
    if (typeof faStatusEnabled !== 'undefined' && !faStatusEnabled) {
        alert('O período de propostas está fechado nesta liga.');
        return;
    }
    document.getElementById('leilaoIdProposta').value = leilaoId;
    document.getElementById('jogadorLeilaoNome').textContent = playerName;
    document.getElementById('notasProposta').value = '';
    
    // Carregar meus jogadores
    const container = document.getElementById('meusJogadoresParaTroca');
    container.innerHTML = '<p class="text-muted">Carregando seus jogadores...</p>';
    
    try {
        const response = await fetch(`api/team-players.php?team_id=${userTeamId}`);
        const data = await response.json();
        
        if (data.success && data.players && data.players.length > 0) {
            let html = '<div class="row">';
            
            data.players.forEach(player => {
                html += `
                <div class="col-md-6 mb-2">
                    <div class="form-check">
                        <input class="form-check-input player-checkbox" type="checkbox" value="${player.id}" id="player_${player.id}">
                        <label class="form-check-label" for="player_${player.id}">
                            <strong>${player.name}</strong> (${player.position}, OVR ${player.ovr || player.overall})
                        </label>
                    </div>
                </div>`;
            });
            
            html += '</div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="text-warning">Voce nao tem jogadores disponiveis para troca.</p>';
        }
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar jogadores.</p>';
    }
    
    // Carregar minhas picks
    const picksContainer = document.getElementById('minhasPicksParaTroca');
    if (picksContainer) {
        picksContainer.innerHTML = '<p class="text-muted">Carregando suas picks...</p>';
        try {
            const resP = await fetch('api/leilao.php?action=minhas_picks');
            const dataP = await parseJsonSafe(resP);
            const picks = (dataP.picks || []);
            if (picks.length) {
                let htmlP = '<div class="row">';
                picks.forEach(pk => {
                    const r = pk.round || pk.round_num || pk.rnd;
                    const label = `${pk.season_year} R${r}${pk.original_team_name ? ' • ' + pk.original_team_name : ''}`;
                    htmlP += `
                    <div class="col-md-6 mb-2">
                        <div class="form-check">
                            <input class="form-check-input pick-checkbox" type="checkbox" value="${pk.id}" id="pick_${pk.id}">
                            <label class="form-check-label" for="pick_${pk.id}">${label}</label>
                        </div>
                    </div>`;
                });
                htmlP += '</div>';
                picksContainer.innerHTML = htmlP;
            } else {
                picksContainer.innerHTML = '<p class="text-warning">Voce nao tem picks disponiveis.</p>';
            }
        } catch (e) {
            picksContainer.innerHTML = '<p class="text-danger">Erro ao carregar picks.</p>';
        }
    }

    // Abrir modal
    const modal = new bootstrap.Modal(document.getElementById('modalProposta'));
    modal.show();
}

document.getElementById('btnEnviarProposta')?.addEventListener('click', async function() {
    if (typeof faStatusEnabled !== 'undefined' && !faStatusEnabled) {
        alert('O período de propostas está fechado nesta liga.');
        return;
    }
    const leilaoId = document.getElementById('leilaoIdProposta').value;
    const notas = document.getElementById('notasProposta').value;
    const checkboxes = document.querySelectorAll('.player-checkbox:checked');
    const pickboxes = document.querySelectorAll('.pick-checkbox:checked');
    
    if (checkboxes.length === 0 && pickboxes.length === 0 && !notas.trim()) {
        alert('Informe uma mensagem ou selecione jogadores/picks para oferecer.');
        return;
    }
    
    const playerIds = Array.from(checkboxes).map(cb => cb.value);
    const pickIds = Array.from(pickboxes).map(cb => cb.value);
    
    try {
        const response = await fetch('api/leilao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'enviar_proposta',
                leilao_id: leilaoId,
                player_ids: playerIds,
                pick_ids: pickIds,
                notas: notas
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Proposta enviada com sucesso!');
            bootstrap.Modal.getInstance(document.getElementById('modalProposta')).hide();
            carregarLeiloesAtivos();
            carregarMinhasPropostas();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao enviar proposta');
    }
});

// ========== VER PROPOSTAS ==========

async function verMinhasPropostasRecebidas(leilaoId) {
    document.getElementById('leilaoIdVerPropostas').value = leilaoId;
    const container = document.getElementById('listaPropostasRecebidas');
    container.innerHTML = '<p class="text-muted">Carregando propostas...</p>';
    
    // Abrir modal
    const modal = new bootstrap.Modal(document.getElementById('modalVerPropostas'));
    modal.show();
    
    try {
    const response = await fetch(`api/leilao.php?action=ver_propostas&leilao_id=${leilaoId}`);
        const data = await response.json();
        
        if (data.success && data.propostas && data.propostas.length > 0) {
            let html = '';
            
            data.propostas.forEach(proposta => {
                html += `
                <div class="card bg-dark border border-secondary text-white mb-3 ${proposta.status === 'aceita' ? 'border-success' : ''}">
                    <div class="card-header bg-dark border-bottom border-secondary d-flex justify-content-between">
                        <span><strong>Proposta de:</strong> ${proposta.team_name}</span>
                        <span class="badge ${proposta.status === 'pendente' ? 'bg-warning' : proposta.status === 'aceita' ? 'bg-success' : 'bg-secondary'}">${proposta.status}</span>
                    </div>
                    <div class="card-body">
                        <h6>Jogadores oferecidos:</h6>
                        ${proposta.jogadores.length ? `
                        <ul>
                            ${proposta.jogadores.map(j => `<li><strong>${j.name}</strong> - ${j.position}, OVR ${j.overall || j.ovr}, ${j.age} anos</li>`).join('')}
                        </ul>` : '<p class="text-muted">Nenhum jogador ofertado.</p>'}
                        <h6 class="mt-3">Picks oferecidas:</h6>
                        ${proposta.picks && proposta.picks.length ? `
                        <ul>
                            ${proposta.picks.map(p => `<li>${p.season_year} R${p.round}${p.original_team_name ? ' • '+p.original_team_name : ''}</li>`).join('')}
                        </ul>` : '<p class="text-muted">Nenhuma pick ofertada.</p>'}
                        ${proposta.notas ? `<p class="text-muted"><strong>Observacoes:</strong> ${proposta.notas}</p>` : ''}
                        ${proposta.status === 'pendente' ? `
                        <div class="d-flex gap-2">
                            <button class="btn btn-success" onclick="aceitarProposta(${proposta.id})">
                                <i class="bi bi-check-lg"></i> Aceitar Proposta
                            </button>
                            <button class="btn btn-outline-danger" onclick="recusarProposta(${proposta.id})">
                                <i class="bi bi-x-lg"></i> Recusar
                            </button>
                        </div>
                        ` : ''}
                    </div>
                </div>`;
            });
            
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="text-light-gray">Nenhuma proposta recebida ainda.</p>';
        }
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar propostas.</p>';
    }
}

async function verPropostasAdmin(leilaoId) {
    verMinhasPropostasRecebidas(leilaoId);
}

function iniciarCronometros() {
    const timers = document.querySelectorAll('.auction-timer');
    if (!timers.length) return;

    const update = () => {
        const now = Date.now();
        timers.forEach(timer => {
            const endTime = timer.getAttribute('data-end-time');
            if (!endTime) return;
            let end = Number(timer.getAttribute('data-end-fixed') || 0);
            if (!end) {
                const normalized = endTime.replace(' ', 'T');
                const endLocal = new Date(normalized).getTime();
                const endUtc = new Date(normalized + 'Z').getTime();
                const target = 20 * 60 * 1000;
                const diffLocal = endLocal - now;
                const diffUtc = endUtc - now;
                const pickLocal = Math.abs(diffLocal - target) <= Math.abs(diffUtc - target);
                end = pickLocal ? endLocal : endUtc;
                if (Number.isNaN(end)) {
                    return;
                }
                if (end - now > target * 2) {
                    end = now + target;
                }
                timer.setAttribute('data-end-fixed', String(end));
            }
            const diff = end - now;
            if (diff <= 0) {
                timer.textContent = 'Encerrado';
                const card = timer.closest('.card');
                card?.querySelectorAll('.auction-propose-btn').forEach(btn => {
                    btn.disabled = true;
                    btn.classList.add('disabled');
                });
                return;
            }
            const totalSeconds = Math.floor(diff / 1000);
            const minutes = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
            const seconds = String(totalSeconds % 60).padStart(2, '0');
            timer.textContent = `${minutes}:${seconds}`;
        });
    };

    update();
    if (!window._leilaoTimerInterval) {
        window._leilaoTimerInterval = setInterval(update, 1000);
    }
}

async function aceitarProposta(propostaId) {
    if (!confirm('Tem certeza que deseja aceitar esta proposta? A troca sera processada.')) return;
    
    try {
        const response = await fetch('api/leilao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'aceitar_proposta',
                proposta_id: propostaId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Proposta aceita! A troca foi registrada e aguarda finalizacao pelo admin.');
            bootstrap.Modal.getInstance(document.getElementById('modalVerPropostas')).hide();
            carregarLeiloesAtivos();
            carregarPropostasRecebidas();
            if (isAdmin) carregarLeiloesAdmin();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao aceitar proposta');
    }
}

async function recusarProposta(propostaId) {
    if (!confirm('Tem certeza que deseja recusar esta proposta?')) return;
    
    try {
        const response = await fetch('api/leilao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'recusar_proposta',
                proposta_id: propostaId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Proposta recusada.');
            const leilaoId = document.getElementById('leilaoIdVerPropostas').value;
            verMinhasPropostasRecebidas(leilaoId);
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao recusar proposta');
    }
}
