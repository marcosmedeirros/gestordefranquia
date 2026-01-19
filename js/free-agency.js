/**
 * Free Agency - JavaScript
 * Propostas com moedas e aprovacao do admin
 */

document.addEventListener('DOMContentLoaded', () => {
    const adminLeagueSelect = document.getElementById('adminLeagueSelect');
    if (adminLeagueSelect && defaultAdminLeague) {
        adminLeagueSelect.value = defaultAdminLeague;
    }

    carregarFreeAgents();

    if (userTeamId) {
        carregarMinhasPropostas();
    }

    if (isAdmin) {
        setupAdminEvents();
        carregarFreeAgentsAdmin();
        carregarPropostasAdmin();
    }
});

function getActiveLeague() {
    if (userLeague) return userLeague;
    const adminLeagueSelect = document.getElementById('adminLeagueSelect');
    return adminLeagueSelect?.value || defaultAdminLeague || null;
}

function getAdminLeague() {
    const adminLeagueSelect = document.getElementById('adminLeagueSelect');
    return adminLeagueSelect?.value || defaultAdminLeague || null;
}

// ========== ADMIN ==========

function setupAdminEvents() {
    document.getElementById('btnAddFreeAgent')?.addEventListener('click', addFreeAgent);
    document.getElementById('adminLeagueSelect')?.addEventListener('change', () => {
        carregarFreeAgentsAdmin();
        carregarPropostasAdmin();
        if (!userLeague) {
            carregarFreeAgents();
        }
    });
}

async function addFreeAgent() {
    const league = document.getElementById('faLeague').value;
    const name = document.getElementById('faPlayerName').value.trim();
    const position = document.getElementById('faPosition').value;
    const secondaryPosition = document.getElementById('faSecondaryPosition').value.trim();
    const age = parseInt(document.getElementById('faAge').value, 10);
    const ovr = parseInt(document.getElementById('faOvr').value, 10);

    if (!league || !name) {
        alert('Preencha liga e nome do jogador');
        return;
    }

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add_player',
                league,
                name,
                position,
                secondary_position: secondaryPosition || null,
                age: Number.isFinite(age) ? age : 25,
                ovr: Number.isFinite(ovr) ? ovr : 70
            })
        });

        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao adicionar jogador');
            return;
        }

        document.getElementById('faPlayerName').value = '';
        document.getElementById('faSecondaryPosition').value = '';
        carregarFreeAgentsAdmin();
        carregarFreeAgents();
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao adicionar jogador');
    }
}

async function carregarFreeAgentsAdmin() {
    const container = document.getElementById('adminFreeAgentsContainer');
    if (!container) return;

    const league = getAdminLeague();
    if (!league) {
        container.innerHTML = '<p class="text-muted">Selecione uma liga.</p>';
        return;
    }

    try {
        const response = await fetch(`api/free-agency.php?action=admin_free_agents&league=${encodeURIComponent(league)}`);
        const data = await response.json();

        if (!data.success || !data.players?.length) {
            container.innerHTML = '<p class="text-muted">Nenhum jogador cadastrado.</p>';
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
        html += '<thead><tr><th>Jogador</th><th>OVR</th><th>Propostas</th><th>Acoes</th></tr></thead><tbody>';

        data.players.forEach(player => {
            html += '<tr>';
            html += `<td><strong class="text-orange">${player.name}</strong><div class="small text-light-gray">${player.position}${player.secondary_position ? '/' + player.secondary_position : ''} • ${player.age} anos</div></td>`;
            html += `<td>${player.ovr}</td>`;
            html += `<td><span class="badge bg-info">${player.pending_offers || 0}</span></td>`;
            html += '<td>';
            html += `<button class="btn btn-sm btn-outline-danger" onclick="removerFreeAgent(${player.id})"><i class="bi bi-trash"></i></button>`;
            html += '</td></tr>';
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar.</p>';
    }
}

async function removerFreeAgent(playerId) {
    if (!confirm('Remover este jogador da Free Agency?')) return;

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'remove_player', player_id: playerId })
        });

        const data = await response.json();
        if (data.success) {
            carregarFreeAgentsAdmin();
            carregarFreeAgents();
        } else {
            alert(data.error || 'Erro ao remover jogador');
        }
    } catch (error) {
        console.error('Erro:', error);
    }
}

async function carregarPropostasAdmin() {
    const container = document.getElementById('adminOffersContainer');
    if (!container) return;

    const league = getAdminLeague();
    if (!league) {
        container.innerHTML = '<p class="text-muted">Selecione uma liga.</p>';
        return;
    }

    try {
        const response = await fetch(`api/free-agency.php?action=admin_offers&league=${encodeURIComponent(league)}`);
        const data = await response.json();

        if (!data.success || !data.players?.length) {
            container.innerHTML = '<p class="text-muted">Nenhuma proposta pendente.</p>';
            return;
        }

        let html = '';
        data.players.forEach(group => {
            const player = group.player;
            const offers = group.offers || [];

            html += '<div class="card bg-dark-elevated border mb-3">';
            html += '<div class="card-header bg-dark">';
            html += `<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong class="text-orange">${player.name}</strong>
                    <span class="text-light-gray ms-2">${player.position}${player.secondary_position ? '/' + player.secondary_position : ''} • OVR ${player.ovr}</span>
                </div>
                <span class="badge bg-info">${offers.length} propostas</span>
            </div>`;
            html += '</div>';
            html += '<div class="card-body">';
            if (player.original_team) {
                html += `<div class="small text-light-gray mb-2">Dispensado por: ${player.original_team}</div>`;
            }
            html += `<div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label for="offerSelect-${player.id}" class="form-label">Selecionar time</label>
                    <select id="offerSelect-${player.id}" class="form-select form-select-sm">
                        <option value="">Selecione...</option>
                        ${offers.map(offer => `<option value="${offer.id}" data-team-id="${offer.team_id}">
                            ${offer.team_name} - ${offer.amount} moedas
                        </option>`).join('')}
                    </select>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-success w-100" onclick="aprovarProposta(${player.id})">
                        <i class="bi bi-check-lg me-1"></i>Aprovar
                    </button>
                </div>
            </div>`;
            html += '</div></div>';
        });

        container.innerHTML = html;
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar.</p>';
    }
}

async function aprovarProposta(playerId) {
    const select = document.getElementById(`offerSelect-${playerId}`);
    const offerId = select?.value;

    if (!offerId) {
        alert('Selecione uma proposta.');
        return;
    }

    if (!confirm('Confirmar este time como vencedor? As moedas serao descontadas.')) return;

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'approve_offer', offer_id: offerId })
        });

        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao aprovar proposta');
            return;
        }

        carregarPropostasAdmin();
        carregarFreeAgentsAdmin();
        carregarFreeAgents();
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao aprovar proposta');
    }
}

// ========== USER ==========

async function carregarFreeAgents() {
    const container = document.getElementById('freeAgentsContainer');
    if (!container) return;

    const league = getActiveLeague();
    if (!league) {
        container.innerHTML = '<p class="text-muted">Nenhuma liga definida.</p>';
        return;
    }

    try {
        const response = await fetch(`api/free-agency.php?action=list&league=${encodeURIComponent(league)}`);
        const data = await response.json();

        if (!data.success || !data.players?.length) {
            container.innerHTML = '<p class="text-muted">Nenhum jogador disponivel.</p>';
            return;
        }

        let html = '<div class="row g-3">';
        data.players.forEach(player => {
            html += '<div class="col-md-6 col-xl-4">';
            html += '<div class="card bg-dark-elevated border h-100">';
            html += `<div class="card-header bg-dark border-bottom border-secondary">
                <strong class="text-orange">${player.name}</strong>
            </div>`;
            html += '<div class="card-body">';
            html += `<div class="mb-2 text-light-gray">
                <i class="bi bi-person me-1"></i>${player.position}${player.secondary_position ? '/' + player.secondary_position : ''} • ${player.age} anos
            </div>`;
            html += `<div class="mb-2"><i class="bi bi-star-fill text-warning me-1"></i>OVR ${player.ovr}</div>`;
            if (player.original_team_name) {
                html += `<div class="small text-light-gray mb-2">Dispensado por: ${player.original_team_name}</div>`;
            }
            if (player.my_offer_amount) {
                html += `<div class="badge bg-info mb-2">Sua proposta: ${player.my_offer_amount} moedas</div>`;
            }
            if (userTeamId) {
                html += `<button class="btn btn-orange btn-sm w-100" onclick="abrirModalOferta(${player.id}, '${player.name.replace(/'/g, "\\'")}', ${player.my_offer_amount || 1})">
                    <i class="bi bi-send me-1"></i>${player.my_offer_amount ? 'Atualizar' : 'Enviar'} proposta
                </button>`;
            }
            html += '</div></div></div>';
        });
        html += '</div>';
        container.innerHTML = html;
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar.</p>';
    }
}

async function carregarMinhasPropostas() {
    const container = document.getElementById('myOffersContainer');
    if (!container) return;

    try {
        const response = await fetch('api/free-agency.php?action=my_offers');
        const data = await response.json();

        if (!data.success || !data.offers?.length) {
            container.innerHTML = '<p class="text-muted">Voce ainda nao enviou propostas.</p>';
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
        html += '<thead><tr><th>Jogador</th><th>Proposta</th><th>Status</th></tr></thead><tbody>';

        data.offers.forEach(offer => {
            const statusMap = {
                pending: '<span class="badge bg-warning text-dark">Pendente</span>',
                accepted: '<span class="badge bg-success">Aceita</span>',
                rejected: '<span class="badge bg-danger">Recusada</span>'
            };
            html += '<tr>';
            html += `<td><strong class="text-orange">${offer.player_name}</strong><div class="small text-light-gray">${offer.position} • OVR ${offer.ovr}</div></td>`;
            html += `<td>${offer.amount} moedas</td>`;
            html += `<td>${statusMap[offer.status] || offer.status}</td>`;
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar.</p>';
    }
}

// ========== MODAL PROPOSTA ==========

function abrirModalOferta(playerId, playerName, currentAmount = 1) {
    document.getElementById('freeAgentIdOffer').value = playerId;
    document.getElementById('freeAgentNomeOffer').textContent = playerName;
    document.getElementById('offerAmount').value = currentAmount;
    document.getElementById('offerAmount').min = 1;

    const modal = new bootstrap.Modal(document.getElementById('modalOffer'));
    modal.show();
}

document.getElementById('btnConfirmOffer')?.addEventListener('click', async () => {
    const playerId = document.getElementById('freeAgentIdOffer').value;
    const amount = parseInt(document.getElementById('offerAmount').value, 10);

    if (!Number.isFinite(amount) || amount <= 0) {
        alert('Informe uma quantidade valida de moedas.');
        return;
    }

    if (amount > userMoedas) {
        alert('Voce nao tem moedas suficientes.');
        return;
    }

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'place_offer', free_agent_id: playerId, amount })
        });

        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao enviar proposta');
            return;
        }

        bootstrap.Modal.getInstance(document.getElementById('modalOffer'))?.hide();
        carregarFreeAgents();
        carregarMinhasPropostas();
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao enviar proposta');
    }
});
<<<<<<< HEAD
=======

// ========== MODAL VENCEDOR (ADMIN) ==========

async function abrirModalVencedor(playerId, playerName) {
    document.getElementById('freeAgentIdVencedor').value = playerId;
    document.getElementById('freeAgentNomeVencedor').textContent = playerName;
    
    const container = document.getElementById('listLancesVencedor');
    container.innerHTML = '<p class="text-muted">Carregando...</p>';
    
    const modal = new bootstrap.Modal(document.getElementById('modalEscolherVencedor'));
    modal.show();
    
    try {
        const response = await fetch('api/free-agency.php?action=get_bids&player_id=' + playerId);
        const data = await response.json();

        // Buscar quantos jogadores cada time já contratou na FA
        let teamSignings = {};
        if (data.success && data.bids && data.bids.length > 0) {
            // Buscar IDs dos times
            const teamIds = data.bids.map(bid => bid.team_id);
            // Buscar contagem para cada time
            const signingsResp = await fetch('api/free-agency.php?action=fa_signings_count&team_ids=' + teamIds.join(','));
            const signingsData = await signingsResp.json();
            if (signingsData.success && signingsData.counts) {
                teamSignings = signingsData.counts;
            }
        }

        if (data.success && data.bids && data.bids.length > 0) {
            let html = '<div class="table-responsive"><table class="table">';
            html += '<thead><tr><th>Time</th><th>Lance</th><th>Contratados</th><th>Acao</th></tr></thead><tbody>';

            data.bids.forEach(bid => {
                const signCount = teamSignings[bid.team_id] || 0;
                html += '<tr>';
                html += '<td><strong>' + bid.team_name + '</strong></td>';
                html += '<td>' + bid.amount + ' moedas</td>';
                html += '<td>' + signCount + ' / 3</td>';
                html += '<td>';
                html += '<button class="btn btn-sm btn-success" onclick="confirmarVencedor(' + playerId + ', ' + bid.team_id + ', ' + bid.amount + ')"' + (signCount >= 3 ? ' disabled' : '') + '>';
                html += '<i class="bi bi-check-lg"></i> Selecionar</button>';
                html += '</td></tr>';
            });

            html += '</tbody></table></div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="text-muted">Nenhum lance recebido.</p>';
        }
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar.</p>';
    }
}

async function confirmarVencedor(playerId, teamId, amount) {
    if (!confirm('Confirmar este time como vencedor? O jogador sera transferido e as moedas descontadas.')) return;
    
    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'select_winner',
                player_id: playerId,
                team_id: teamId,
                amount: amount
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Vencedor confirmado! Jogador transferido.');
            bootstrap.Modal.getInstance(document.getElementById('modalEscolherVencedor')).hide();
            carregarFreeAgentsAdmin();
            carregarFreeAgents();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao confirmar vencedor');
    }
}
>>>>>>> ea3fbb41ab30e4781ba6b0f9b99d37c202a32aae
