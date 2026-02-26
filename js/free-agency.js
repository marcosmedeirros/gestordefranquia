/**
 * Free Agency - JavaScript
 * Propostas com moedas e aprovacao do admin
 */

const isNewFaEnabled = typeof useNewFreeAgency !== 'undefined' ? !!useNewFreeAgency : false;
let faHistoryTeamSort = null;
let faWaiversTeamSort = null;

function sortIndicator(direction) {
    if (direction === 'asc') return ' <i class="bi bi-caret-up-fill"></i>';
    if (direction === 'desc') return ' <i class="bi bi-caret-down-fill"></i>';
    return '';
}

function sortByTeamName(list, getName, direction) {
    if (!direction) return list;
    const sorted = [...list];
    sorted.sort((a, b) => {
        const nameA = (getName(a) || '').toLowerCase();
        const nameB = (getName(b) || '').toLowerCase();
        if (nameA === nameB) return 0;
        return direction === 'asc' ? nameA.localeCompare(nameB) : nameB.localeCompare(nameA);
    });
    return sorted;
}

window.toggleFaHistoryTeamSort = function() {
    faHistoryTeamSort = faHistoryTeamSort === 'asc' ? 'desc' : 'asc';
    if (isNewFaEnabled) {
        carregarHistoricoNovaFA();
    } else {
        carregarHistoricoFA();
    }
};

window.toggleFaWaiversTeamSort = function() {
    faWaiversTeamSort = faWaiversTeamSort === 'asc' ? 'desc' : 'asc';
    if (window.__faWaiversCache) {
        renderWaiversList(window.__faWaiversCache);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    console.log('🚀 Free Agency JS carregado');
    console.log('🔐 isAdmin:', isAdmin);
    console.log('🏀 userLeague:', userLeague);
    console.log('🎯 defaultAdminLeague:', defaultAdminLeague);
    
    const adminLeagueSelect = document.getElementById('adminLeagueSelect');
    if (adminLeagueSelect && defaultAdminLeague) {
        adminLeagueSelect.value = defaultAdminLeague;
        console.log('✅ adminLeagueSelect configurado com:', defaultAdminLeague);
    }
    const newAdminLeagueSelect = document.getElementById('faNewAdminLeague');
    if (newAdminLeagueSelect && defaultAdminLeague) {
        newAdminLeagueSelect.value = defaultAdminLeague;
    }
    const faLeagueSelect = document.getElementById('faLeague');
    if (faLeagueSelect && defaultAdminLeague) {
        faLeagueSelect.value = defaultAdminLeague;
    }

    if (isNewFaEnabled) {
        initNewFreeAgency();
        return;
    }

    // Buscar status da liga ativa (lista) e carregar listas
    refreshFaStatus(getActiveLeague(), false).then(() => carregarFreeAgents());
    carregarHistoricoFA();
    carregarDispensados();

    document.getElementById('faSearchInput')?.addEventListener('input', () => {
        renderFreeAgents();
    });
    document.getElementById('faPositionFilter')?.addEventListener('change', () => {
        renderFreeAgents();
    });

    if (isAdmin) {
        console.log('👑 Configurando modo admin...');
        setupAdminEvents();
        // Atualiza UI com status atual da liga selecionada no admin
        refreshFaStatus(getAdminLeague(), true);
        carregarFreeAgentsAdmin();
        carregarPropostasAdmin();
        carregarHistoricoContratacoes();
        const newFaAdminLeagueSelect = document.getElementById('faNewAdminLeague');
        if (newFaAdminLeagueSelect) {
            newFaAdminLeagueSelect.addEventListener('change', () => {
                window.onAdminLeagueChange?.();
                carregarSolicitacoesNovaFA();
            });
            carregarSolicitacoesNovaFA();
        }
        
        // Listener para quando a aba FA Admin for exibida
        const faAdminTab = document.getElementById('fa-admin-tab');
        if (faAdminTab) {
            console.log('📑 Adicionando listener na aba FA Admin');
            faAdminTab.addEventListener('shown.bs.tab', () => {
                console.log('👁️ Aba FA Admin foi aberta, recarregando dados...');
                carregarFreeAgentsAdmin();
                carregarPropostasAdmin();
                carregarHistoricoContratacoes();
                if (document.getElementById('faNewAdminRequests')) {
                    carregarSolicitacoesNovaFA();
                }
            });
        }
    }

    const historyTab = document.getElementById('fa-history-tab');
    if (historyTab) {
        historyTab.addEventListener('shown.bs.tab', () => {
            carregarHistoricoFA();
            carregarDispensados();
        });
    }
});

function getActiveLeague() {
    if (userLeague) return userLeague;
    const adminLeagueSelect = document.getElementById('adminLeagueSelect');
    return adminLeagueSelect?.value || defaultAdminLeague || null;
}

function initNewFreeAgency() {
    const form = document.getElementById('faNewRequestForm');
    if (form) {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            submitNewFaRequest();
        });
    }
    const submitBtn = document.getElementById('faNewSubmitBtn');
    if (submitBtn) {
        submitBtn.addEventListener('click', (event) => {
            event.preventDefault();
            submitNewFaRequest();
        });
    }

    const approvedBtn = document.getElementById('faViewApprovedBtn');
    if (approvedBtn) {
        approvedBtn.addEventListener('click', () => {
            openFaApprovedModal();
        });
    }

    const inlineEl = document.getElementById('faApprovedInline');
    if (inlineEl) {
        renderAdminRequests(inlineEl);
    }

        carregarLimitesNovaFA();
    carregarMinhasPropostasNovaFA();
    carregarHistoricoNovaFA();

    const historyTab = document.getElementById('fa-history-tab');
    if (historyTab) {
        historyTab.addEventListener('shown.bs.tab', () => {
            carregarHistoricoNovaFA();
            carregarDispensados();
        });
    }

    if (isAdmin) {
        const newLeagueSelect = document.getElementById('faNewAdminLeague');
        if (newLeagueSelect) {
            newLeagueSelect.addEventListener('change', () => {
                carregarSolicitacoesNovaFA();
            });
        }
        carregarSolicitacoesNovaFA();
        const faAdminTab = document.getElementById('fa-admin-tab');
        if (faAdminTab) {
            faAdminTab.addEventListener('shown.bs.tab', () => {
                carregarSolicitacoesNovaFA();
            });
        }
    }
}

async function openFaApprovedModal() {
    const listEl = document.getElementById('faApprovedList');
    if (listEl) {
        listEl.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-orange"></div></div>';
    }

    const modalEl = document.getElementById('faApprovedModal');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }

    await renderAdminRequests(listEl);
}

async function renderAdminRequests(targetEl) {
    if (!targetEl) return;
    try {
        if (!isAdmin) {
            targetEl.innerHTML = '<div class="text-light-gray">Somente administradores podem ver essas solicitações.</div>';
            return;
        }
        let league = getActiveLeague();
        if (!league && defaultAdminLeague) {
            league = defaultAdminLeague;
        }
        if (!league) {
            targetEl.innerHTML = '<div class="text-light-gray">Nenhuma liga selecionada.</div>';
            return;
        }

        const response = await fetch(`api/free-agency.php?action=admin_new_fa_requests&league=${encodeURIComponent(league)}`);
        const data = await response.json();
        if (!data.success || !Array.isArray(data.requests)) {
            targetEl.innerHTML = '<div class="text-danger">Erro ao carregar solicitações.</div>';
            return;
        }

        if (!data.requests.length) {
            targetEl.innerHTML = '<div class="text-light-gray">Nenhuma solicitação pendente.</div>';
            return;
        }

        const html = data.requests.map(group => {
            const request = group.request || {};
            const offers = Array.isArray(group.offers) ? group.offers : [];
            const topOffer = offers[0] || null;
            const teamName = topOffer?.team_name || 'Time não informado';
            return `
                <div class="border border-secondary rounded p-3 mb-2">
                    <div class="text-white fw-bold">${request.player_name || 'Jogador'}</div>
                    <div class="text-light-gray small">Maior proposta atual: ${teamName}</div>
                </div>
            `;
        }).join('');
        targetEl.innerHTML = html;
    } catch (error) {
        targetEl.innerHTML = '<div class="text-danger">Erro ao carregar solicitações.</div>';
    }
}

    async function carregarLimitesNovaFA() {
        const badge = document.getElementById('faNewMyCount');
        const form = document.getElementById('faNewRequestForm');
        if (!badge && !form) return;

        try {
            const response = await fetch('api/free-agency.php?action=new_fa_limits');
            const data = await response.json();
            if (!data.success) return;
            const remaining = data.remaining ?? 0;
            const limit = data.limit ?? 3;

            const counter = document.createElement('span');
            const isBlocked = remaining <= 0;
            counter.className = isBlocked ? 'badge bg-danger' : 'badge bg-success';
            counter.textContent = isBlocked
                ? `Limite de contratações atingido (${remaining}/${limit})`
                : `Contratacoes restantes: ${remaining}/${limit}`;

            const existing = document.getElementById('faNewRemainingBadge');
            if (existing) {
                existing.textContent = counter.textContent;
                existing.className = counter.className;
            } else if (form) {
                counter.id = 'faNewRemainingBadge';
                const wrapper = document.createElement('div');
                wrapper.className = 'col-12';
                wrapper.appendChild(counter);
                form.appendChild(wrapper);
            }

            window.__faRemainingSignings = remaining;

            if (form) {
                const inputs = form.querySelectorAll('input, select, button');
                inputs.forEach((input) => {
                    if (input.id === 'faNewRemainingBadge') return;
                    if (input.type === 'submit' || input.id === 'faNewSubmitBtn' || input.tagName === 'INPUT' || input.tagName === 'SELECT') {
                        input.disabled = isBlocked;
                    }
                });
            }
        } catch (error) {
            // silencioso
        }
    }

async function submitNewFaRequest() {
    let league = getActiveLeague();
    if (!league) {
        league = document.getElementById('faNewAdminLeague')?.value
            || document.getElementById('faLeague')?.value
            || null;
    }
    if (!league) {
        alert('Nenhuma liga definida.');
        return;
    }

    const name = document.getElementById('faNewPlayerName')?.value.trim();
    const position = document.getElementById('faNewPosition')?.value || 'PG';
    const secondary = document.getElementById('faNewSecondary')?.value.trim();
    const age = parseInt(document.getElementById('faNewAge')?.value, 10);
    const ovr = parseInt(document.getElementById('faNewOvr')?.value, 10);
    const amount = parseInt(document.getElementById('faNewOffer')?.value, 10);

    if (!name) {
        alert('Informe o nome do jogador.');
        return;
    }

    if (!Number.isFinite(amount) || amount <= 0) {
        alert('Informe o valor da proposta (moedas).');
        return;
    }

    const payload = {
        action: 'request_player',
        league,
        name,
        position,
        secondary_position: secondary || null,
        age: Number.isFinite(age) ? age : 24,
        ovr: Number.isFinite(ovr) ? ovr : 70,
        amount
    };

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao enviar proposta.');
            return;
        }

        alert('Proposta enviada!');
        document.getElementById('faNewRequestForm')?.reset();
        document.getElementById('faNewOffer').value = '1';
        carregarMinhasPropostasNovaFA();
        if (isAdmin) {
            carregarSolicitacoesNovaFA();
        }
    } catch (error) {
        console.error('Erro ao enviar proposta:', error);
        alert('Erro ao enviar proposta.');
    }
}

async function carregarMinhasPropostasNovaFA() {
    const container = document.getElementById('faNewMyRequests');
    const countBadge = document.getElementById('faNewMyCount');
    if (!container) return;

    try {
        const response = await fetch('api/free-agency.php?action=my_fa_requests');
        const data = await response.json();
        if (!data.success || !Array.isArray(data.requests)) {
            container.innerHTML = '<p class="text-light-gray">Nenhuma proposta registrada.</p>';
            if (countBadge) countBadge.textContent = '0';
            return;
        }

        const requests = data.requests;
        if (countBadge) countBadge.textContent = String(requests.length);
        if (requests.length === 0) {
            container.innerHTML = '<p class="text-light-gray">Nenhuma proposta registrada.</p>';
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
        html += '<thead><tr><th>Jogador</th><th>OVR</th><th>Proposta</th><th>Status</th><th>Temporada</th><th>Acoes</th></tr></thead><tbody>';
        requests.forEach(item => {
            const statusLabel = formatNewFaStatus(item.status);
            const season = item.season_year ? `Temp ${item.season_year}` : '-';
            const remaining = typeof window.__faRemainingSignings === 'number' ? window.__faRemainingSignings : null;
            const isBlocked = remaining !== null && remaining <= 0;
            const isPending = item.status === 'pending' && !isBlocked;
            html += `<tr>
                <td><strong class="text-orange">${item.player_name}</strong><div class="small text-light-gray">${item.position}${item.secondary_position ? '/' + item.secondary_position : ''}</div></td>
                <td>${item.ovr ?? '-'}</td>
                <td>${item.amount ?? 0} moedas</td>
                <td>${statusLabel}</td>
                <td>${season}</td>
                <td>
                    ${isPending ? `
                        <div class=\"d-flex gap-2 flex-wrap\">
                            <button class=\"btn btn-sm btn-outline-light\" onclick=\"editarPropostaNovaFA(${item.offer_id}, ${item.amount})\">
                                <i class=\"bi bi-pencil\"></i>
                            </button>
                            <button class=\"btn btn-sm btn-outline-danger\" onclick=\"excluirPropostaNovaFA(${item.offer_id})\">
                                <i class=\"bi bi-trash\"></i>
                            </button>
                        </div>
                    ` : '<span class="text-light-gray">-</span>'}
                </td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
        carregarLimitesNovaFA();
    } catch (error) {
        container.innerHTML = '<p class="text-danger">Erro ao carregar propostas.</p>';
    }
}

async function carregarHistoricoNovaFA() {
    const container = document.getElementById('faHistoryContainer');
    if (!container) return;

    const league = getActiveLeague();
    if (!league) {
        container.innerHTML = '<p class="text-muted">Nenhuma liga definida.</p>';
        return;
    }

    const seasonFilter = document.getElementById('faHistorySeasonFilter');
    const seasonValue = seasonFilter?.value || '';
    const query = new URLSearchParams({
        action: 'new_fa_history',
        league
    });
    if (seasonValue) {
        query.append('season_year', seasonValue);
    }

    try {
        const response = await fetch(`api/free-agency.php?${query.toString()}`);
        const data = await response.json();
        if (!data.success || !Array.isArray(data.history) || data.history.length === 0) {
            container.innerHTML = '<p class="text-light-gray">Nenhuma contratacao registrada.</p>';
            return;
        }

        if (seasonFilter && !seasonFilter.dataset.loaded) {
            const seasons = [...new Set(data.history.map(item => item.season_year).filter(Boolean))].sort((a, b) => b - a);
            seasons.forEach(season => {
                const option = document.createElement('option');
                option.value = season;
                option.textContent = `Temp ${season}`;
                seasonFilter.appendChild(option);
            });
            seasonFilter.dataset.loaded = '1';
            seasonFilter.addEventListener('change', () => carregarHistoricoNovaFA());
        }

        const sortedHistory = sortByTeamName(
            data.history,
            (item) => item.team_name ? `${item.team_city} ${item.team_name}` : '',
            faHistoryTeamSort
        );

        let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
        html += '<thead><tr><th>Jogador</th><th>OVR</th><th><button type="button" class="btn btn-link p-0 text-white" onclick="toggleFaHistoryTeamSort()">Time' + sortIndicator(faHistoryTeamSort) + '</button></th><th>Temporada</th></tr></thead><tbody>';
        sortedHistory.forEach(item => {
            const teamName = item.team_name ? `${item.team_city} ${item.team_name}` : '-';
            const seasonLabel = item.season_year ? `Temp ${item.season_year}` : '-';
            html += `<tr>
                <td><strong class="text-orange">${item.player_name}</strong></td>
                <td>${item.ovr ?? '-'}</td>
                <td>${teamName}</td>
                <td>${seasonLabel}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch (error) {
        container.innerHTML = '<p class="text-danger">Erro ao carregar historico.</p>';
    }
}

async function carregarSolicitacoesNovaFA() {
    const container = document.getElementById('faNewAdminRequests');
    if (!container) return;

    const league = getAdminLeague();
    if (!league) {
        container.innerHTML = '<p class="text-muted">Selecione uma liga.</p>';
        return;
    }

    try {
        const response = await fetch(`api/free-agency.php?action=admin_new_fa_requests&league=${encodeURIComponent(league)}`);
        const data = await response.json();
        if (!data.success || !Array.isArray(data.requests) || data.requests.length === 0) {
            container.innerHTML = '<p class="text-white">Nenhuma solicitacao pendente.</p>';
            return;
        }

        let html = '';
        data.requests.forEach(group => {
            const request = group.request;
            const offers = group.offers || [];
            html += '<div class="card bg-dark border border-secondary mb-3 text-white">';
            html += '<div class="card-header bg-dark border-bottom border-secondary">';
            html += `<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong class="text-orange">${request.player_name}</strong>
                    <span class="text-light-gray ms-2">${request.position}${request.secondary_position ? '/' + request.secondary_position : ''} • OVR ${request.ovr}</span>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge bg-info">${offers.length} propostas</span>
                    <button class="btn btn-sm btn-outline-danger" onclick="recusarSolicitacaoNovaFA(${request.id})">
                        <i class="bi bi-x-circle me-1"></i>Recusar todas
                    </button>
                </div>
            </div>`;
            html += '</div>';
            html += '<div class="card-body">';
            html += `<div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label for="faNewOfferSelect-${request.id}" class="form-label">Selecionar time vencedor</label>
                    <select id="faNewOfferSelect-${request.id}" class="form-select form-select-sm">
                        <option value="">Selecione...</option>
                        ${offers.map(offer => {
                            const remaining = offer.remaining_signings != null ? ` | restam ${offer.remaining_signings}` : '';
                            return `<option value="${offer.id}">${offer.team_name} - ${offer.amount} moedas${remaining}</option>`;
                        }).join('')}
                    </select>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-success w-100" onclick="aprovarSolicitacaoNovaFA(${request.id})">
                        <i class="bi bi-check-lg me-1"></i>Aprovar
                    </button>
                </div>
            </div>`;
            html += '</div></div>';
        });
        container.innerHTML = html;
    } catch (error) {
        container.innerHTML = '<p class="text-danger">Erro ao carregar solicitacoes.</p>';
    }
}

window.aprovarSolicitacaoNovaFA = async function(requestId) {
    const select = document.getElementById(`faNewOfferSelect-${requestId}`);
    const offerId = select?.value;
    if (!offerId) {
        alert('Selecione uma proposta.');
        return;
    }

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'admin_assign_request', offer_id: Number(offerId) })
        });
        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao aprovar.');
            return;
        }
        alert(data.message || 'Contratacao realizada.');
        carregarSolicitacoesNovaFA();
        carregarHistoricoNovaFA();
        carregarMinhasPropostasNovaFA();
        carregarLimitesNovaFA();
    } catch (error) {
        alert('Erro ao aprovar.');
    }
};

window.recusarSolicitacaoNovaFA = async function(requestId) {
    if (!confirm('Recusar todas as propostas para este jogador?')) return;
    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'admin_reject_request', request_id: Number(requestId) })
        });
        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao recusar.');
            return;
        }
        carregarSolicitacoesNovaFA();
        carregarMinhasPropostasNovaFA();
    } catch (error) {
        alert('Erro ao recusar.');
    }
};

window.editarPropostaNovaFA = async function(offerId, currentAmount) {
    const novoValor = prompt('Atualize o valor da proposta (moedas):', currentAmount);
    if (novoValor === null) return;
    const amount = parseInt(novoValor, 10);
    if (!Number.isFinite(amount) || amount <= 0) {
        alert('Valor invalido.');
        return;
    }

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_request_offer', offer_id: Number(offerId), amount })
        });
        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao atualizar proposta.');
            return;
        }
        carregarMinhasPropostasNovaFA();
        if (isAdmin) {
            carregarSolicitacoesNovaFA();
        }
    } catch (error) {
        alert('Erro ao atualizar proposta.');
    }
};

window.excluirPropostaNovaFA = async function(offerId) {
    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'cancel_request_offer', offer_id: Number(offerId) })
        });
        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao excluir proposta.');
            return;
        }
        carregarMinhasPropostasNovaFA();
        if (isAdmin) {
            carregarSolicitacoesNovaFA();
        }
    } catch (error) {
        alert('Erro ao excluir proposta.');
    }
};

function formatNewFaStatus(status) {
    switch (status) {
        case 'accepted':
        case 'assigned':
            return '<span class="badge bg-success">Contratado</span>';
        case 'rejected':
            return '<span class="badge bg-danger">Recusado</span>';
        default:
            return '<span class="badge bg-warning text-dark">Pendente</span>';
    }
}

async function carregarDispensados() {
    const container = document.getElementById('faWaiversContainer');
    if (!container) return;

    const league = getActiveLeague();
    if (!league) {
        container.innerHTML = '<p class="text-muted">Nenhuma liga definida.</p>';
        return;
    }

    try {
        const response = await fetch(`api/free-agency.php?action=waivers&league=${encodeURIComponent(league)}`);
        const data = await response.json();
        if (!data.success) {
            container.innerHTML = '<p class="text-danger">Erro ao carregar dispensas.</p>';
            return;
        }
        const waivers = data.waivers || [];
        if (!waivers.length) {
            container.innerHTML = '<p class="text-light-gray">Nenhum jogador dispensado recentemente.</p>';
            return;
        }

        const seasonFilter = document.getElementById('faWaiversSeasonFilter');
        const teamFilter = document.getElementById('faWaiversTeamFilter');

        if (seasonFilter && !seasonFilter.dataset.loaded) {
            const seasons = [...new Set(waivers.map(item => item.season_year).filter(Boolean))].sort((a, b) => b - a);
            seasons.forEach(season => {
                const option = document.createElement('option');
                option.value = season;
                option.textContent = `Temp ${season}`;
                seasonFilter.appendChild(option);
            });
            seasonFilter.dataset.loaded = '1';
            seasonFilter.addEventListener('change', () => renderWaiversList(waivers));
        }

        if (teamFilter && !teamFilter.dataset.loaded) {
            const teams = [...new Set(waivers.map(item => item.original_team_name).filter(Boolean))].sort();
            teams.forEach(team => {
                const option = document.createElement('option');
                option.value = team;
                option.textContent = team;
                teamFilter.appendChild(option);
            });
            teamFilter.dataset.loaded = '1';
            teamFilter.addEventListener('change', () => renderWaiversList(waivers));
        }

        window.__faWaiversCache = waivers;
        renderWaiversList(waivers);
    } catch (error) {
        container.innerHTML = '<p class="text-danger">Erro ao carregar dispensas.</p>';
    }
}

function renderWaiversList(waivers) {
    const container = document.getElementById('faWaiversContainer');
    if (!container) return;
    const seasonFilter = document.getElementById('faWaiversSeasonFilter');
    const teamFilter = document.getElementById('faWaiversTeamFilter');
    const seasonValue = seasonFilter?.value || '';
    const teamValue = teamFilter?.value || '';

    const filtered = waivers.filter(item => {
        if (seasonValue && String(item.season_year) !== seasonValue) return false;
        if (teamValue && item.original_team_name !== teamValue) return false;
        return true;
    });

    if (!filtered.length) {
        container.innerHTML = '<p class="text-light-gray">Nenhum jogador dispensado encontrado.</p>';
        return;
    }

    const sorted = sortByTeamName(
        filtered,
        (item) => item.original_team_name || '',
        faWaiversTeamSort
    );

    let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
    html += '<thead><tr><th>Jogador</th><th>Temporada</th><th><button type="button" class="btn btn-link p-0 text-white" onclick="toggleFaWaiversTeamSort()">Time' + sortIndicator(faWaiversTeamSort) + '</button></th></tr></thead><tbody>';
    sorted.forEach(item => {
        const teamName = item.original_team_name || '-';
        let seasonLabel = '-';
        if (item.season_number) {
            seasonLabel = `Temp #${item.season_number}`;
            if (item.season_year) {
                seasonLabel += ` (${item.season_year})`;
            }
        } else if (item.season_year) {
            seasonLabel = `Temp ${item.season_year}`;
        }
        html += `<tr>
            <td><strong class="text-orange">${item.name}</strong></td>
            <td>${seasonLabel}</td>
            <td>${teamName}</td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    container.innerHTML = html;
}

let freeAgentsCache = [];

// Função global para o onchange inline do select
window.onAdminLeagueChange = function() {
    console.log('🎯🎯🎯 onAdminLeagueChange CHAMADA! 🎯🎯🎯');
    const adminLeagueSelect = document.getElementById('adminLeagueSelect');
    console.log('Nova liga selecionada:', adminLeagueSelect?.value);
    // Atualiza status para a liga selecionada no admin
    refreshFaStatus(getAdminLeague(), true);
    carregarFreeAgentsAdmin();
    carregarPropostasAdmin();
    carregarHistoricoContratacoes();
    if (!userLeague) {
        carregarFreeAgents();
    }
    const seasonFilter = document.getElementById('faWaiversSeasonFilter');
    const teamFilter = document.getElementById('faWaiversTeamFilter');
    if (seasonFilter) {
        seasonFilter.innerHTML = '<option value="">Todas temporadas</option>';
        delete seasonFilter.dataset.loaded;
    }
    if (teamFilter) {
        teamFilter.innerHTML = '<option value="">Todos os times</option>';
        delete teamFilter.dataset.loaded;
    }
    const faHistoryFilter = document.getElementById('faHistorySeasonFilter');
    if (faHistoryFilter) {
        faHistoryFilter.innerHTML = '<option value="">Todas temporadas</option>';
        delete faHistoryFilter.dataset.loaded;
    }
    carregarSolicitacoesNovaFA();
    carregarDispensados();
};

function getAdminLeague() {
    const adminLeagueSelect = document.getElementById('adminLeagueSelect');
    if (adminLeagueSelect?.value) {
        return adminLeagueSelect.value;
    }
    const newAdminLeagueSelect = document.getElementById('faNewAdminLeague');
    if (newAdminLeagueSelect?.value) {
        return newAdminLeagueSelect.value;
    }
    return defaultAdminLeague || null;
}

async function carregarHistoricoFA() {
    const container = document.getElementById('faHistoryContainer');
    if (!container) return;

    const league = getActiveLeague();
    if (!league) {
        container.innerHTML = '<p class="text-muted">Nenhuma liga definida.</p>';
        return;
    }

    try {
        const response = await fetch(`api/free-agency.php?action=contracts&league=${encodeURIComponent(league)}`);
        const data = await response.json();

        if (!data.success || !data.contracts?.length) {
            container.innerHTML = '<p class="text-light-gray">Nenhuma contratação registrada.</p>';
            return;
        }

        const sortedContracts = sortByTeamName(
            data.contracts,
            (item) => item.team_name ? `${item.team_city} ${item.team_name}` : '',
            faHistoryTeamSort
        );

        let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
        html += '<thead><tr><th>Jogador</th><th>OVR</th><th><button type="button" class="btn btn-link p-0 text-white" onclick="toggleFaHistoryTeamSort()">Time' + sortIndicator(faHistoryTeamSort) + '</button></th><th>Ano</th></tr></thead><tbody>';
        sortedContracts.forEach(item => {
            const teamName = item.team_name ? `${item.team_city} ${item.team_name}` : '-';
            const year = item.season_year || (item.waived_at ? item.waived_at.toString().slice(0, 4) : '-');
            html += `<tr>
                <td><strong class="text-orange">${item.name}</strong></td>
                <td>${item.ovr}</td>
                <td>${teamName}</td>
                <td>${year}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch (error) {
        container.innerHTML = '<p class="text-danger">Erro ao carregar historico.</p>';
    }
}

// ========== ADMIN ==========

function setupAdminEvents() {
    console.log('🎬 Configurando eventos admin...');
    const adminLeagueSelect = document.getElementById('adminLeagueSelect');
    const btnAddFreeAgent = document.getElementById('btnAddFreeAgent');
    console.log('📋 adminLeagueSelect encontrado:', adminLeagueSelect);
    console.log('🔘 btnAddFreeAgent encontrado:', btnAddFreeAgent);
    
    if (btnAddFreeAgent) {
        console.log('✅ Registrando evento click no btnAddFreeAgent');
        btnAddFreeAgent.addEventListener('click', addFreeAgent);
    } else {
        console.error('❌ btnAddFreeAgent não encontrado!');
    }
    
    if (adminLeagueSelect) {
        console.log('✅ Adicionando listener ao adminLeagueSelect');
        adminLeagueSelect.addEventListener('change', (e) => {
            console.log('🔄🔄🔄 EVENTO CHANGE DISPARADO! 🔄🔄🔄');
            console.log('🔄 Liga mudou! Nova liga:', adminLeagueSelect.value);
            console.log('🔄 Event target:', e.target.value);
            carregarFreeAgentsAdmin();
            carregarPropostasAdmin();
            carregarHistoricoContratacoes();
            if (!userLeague) {
                carregarFreeAgents();
            }
        });
    } else {
        console.error('❌ adminLeagueSelect não encontrado!');
    }
}

// Função global para adicionar Free Agent
window.addFreeAgent = async function() {
    console.log('➕ addFreeAgent chamada');
    const league = document.getElementById('faLeague').value;
    const name = document.getElementById('faPlayerName').value.trim();
    const position = document.getElementById('faPosition').value;
    const secondaryPosition = document.getElementById('faSecondaryPosition').value.trim();
    const age = parseInt(document.getElementById('faAge').value, 10);
    const ovr = parseInt(document.getElementById('faOvr').value, 10);

    console.log('📝 Dados coletados:', { league, name, position, secondaryPosition, age, ovr });

    if (!league || !name) {
        alert('Preencha liga e nome do jogador');
        return;
    }

    try {
        const payload = {
            action: 'add_player',
            league,
            name,
            position,
            secondary_position: secondaryPosition || null,
            age: Number.isFinite(age) ? age : 25,
            ovr: Number.isFinite(ovr) ? ovr : 70
        };
        console.log('📤 Enviando:', payload);
        
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        console.log('📥 Resposta recebida:', data);
        
        if (!data.success) {
            alert(data.error || 'Erro ao adicionar jogador');
            return;
        }

        alert('Jogador adicionado com sucesso!');
        document.getElementById('faPlayerName').value = '';
        document.getElementById('faLeague').value = defaultAdminLeague || '';
        document.getElementById('faSecondaryPosition').value = '';
        document.getElementById('faPosition').value = 'PG';
        document.getElementById('faAge').value = '25';
        document.getElementById('faOvr').value = '70';
        carregarFreeAgentsAdmin();
        carregarFreeAgents();
    } catch (error) {
        console.error('❌ Erro:', error);
        alert('Erro ao adicionar jogador');
    }
};

async function carregarFreeAgentsAdmin() {
    const container = document.getElementById('adminFreeAgentsContainer');
    if (!container) return;

    const league = getAdminLeague();
    console.log('🔍 League selecionada no admin:', league);
    if (!league) {
        container.innerHTML = '<p class="text-muted">Selecione uma liga.</p>';
        return;
    }

    try {
        const url = `api/free-agency.php?action=admin_free_agents&league=${encodeURIComponent(league)}`;
        console.log('📡 Fazendo request para:', url);
        const response = await fetch(url);
        const data = await response.json();
        console.log('📦 Dados recebidos:', data);

        if (!data.success || !data.players?.length) {
            container.innerHTML = '<p class="text-muted">Nenhum jogador cadastrado.</p>';
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
        html += '<thead><tr><th>Jogador</th><th>OVR</th><th>Propostas</th><th>Acoes</th></tr></thead><tbody>';

        data.players.forEach(player => {
            const seasonLabel = player.season_year
                ? `Temp: ${player.season_year}`
                : (player.season_number ? `Temp #${player.season_number}` : 'Temp: -');
            html += '<tr>';
            html += `<td><strong class="text-orange">${player.name}</strong><div class="small text-light-gray">${player.position}${player.secondary_position ? '/' + player.secondary_position : ''} • ${player.age} anos • ${seasonLabel}</div></td>`;
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

            html += '<div class="card bg-dark border border-secondary mb-3 text-white">';
            html += '<div class="card-header bg-dark border-bottom border-secondary">';
            html += `<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong class="text-orange">${player.name}</strong>
                    <span class="text-light-gray ms-2">${player.position}${player.secondary_position ? '/' + player.secondary_position : ''} • OVR ${player.ovr}</span>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge bg-info">${offers.length} propostas</span>
                    <button class="btn btn-sm btn-outline-danger" onclick="recusarTodasPropostas(${player.id})">
                        <i class="bi bi-x-circle me-1"></i>Recusar todas
                    </button>
                </div>
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

async function carregarHistoricoContratacoes() {
    const container = document.getElementById('faContractsHistoryContainer');
    if (!container) return;

    const league = getAdminLeague();
    if (!league) {
        container.innerHTML = '<p class="text-muted">Selecione uma liga.</p>';
        return;
    }

    try {
        const response = await fetch(`api/free-agency.php?action=admin_contracts&league=${encodeURIComponent(league)}`);
        const data = await response.json();

        if (!data.success || !data.contracts?.length) {
            container.innerHTML = '<p class="text-light-gray">Nenhuma contratacao registrada.</p>';
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
        html += '<thead><tr><th>Jogador</th><th>OVR</th><th>Time</th><th>Ano</th></tr></thead><tbody>';
        data.contracts.forEach(item => {
            const teamName = item.team_name ? `${item.team_city} ${item.team_name}` : '-';
            const year = item.season_year || (item.waived_at ? item.waived_at.toString().slice(0, 4) : '-');
            html += `<tr>
                <td><strong class="text-orange">${item.name}</strong></td>
                <td>${item.ovr}</td>
                <td>${teamName}</td>
                <td>${year}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch (error) {
        container.innerHTML = '<p class="text-danger">Erro ao carregar historico.</p>';
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

async function recusarTodasPropostas(playerId) {
    if (!confirm('Recusar todas as propostas deste jogador?')) return;

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reject_all_offers', free_agent_id: playerId })
        });

        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao recusar propostas');
            return;
        }

        carregarPropostasAdmin();
        carregarFreeAgentsAdmin();
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao recusar propostas');
    }
}

async function encerrarSemVencedor(playerId) {
    if (!confirm('Encerrar este leilao sem vencedor? As propostas pendentes serao recusadas.')) return;

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'close_without_winner', free_agent_id: playerId })
        });

        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao encerrar leilao');
            return;
        }

        carregarPropostasAdmin();
        carregarFreeAgentsAdmin();
        carregarFreeAgents();
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao encerrar leilao');
    }
}

// ========== USER ==========

let faStatusEnabled = true;

async function refreshFaStatus(league, isAdminContext = false) {
    if (!league) {
        faStatusEnabled = true;
        updateFaStatusUI(isAdminContext);
        return true;
    }
    try {
        const resp = await fetch(`api/free-agency.php?action=fa_status&league=${encodeURIComponent(league)}`);
        const data = await resp.json();
        faStatusEnabled = !!(data && data.success ? data.enabled : (data.enabled ?? true));
    } catch (e) {
        faStatusEnabled = true;
    }
    updateFaStatusUI(isAdminContext);
    return faStatusEnabled;
}

function updateFaStatusUI(isAdminContext = false) {
    // Admin header toggle + badge
    const toggle = document.getElementById('faStatusToggle');
    const badge = document.getElementById('faStatusBadge');
    if (isAdminContext && toggle) toggle.checked = !!faStatusEnabled;
    if (isAdminContext && badge) {
        if (faStatusEnabled) {
            badge.className = 'badge bg-success ms-1';
            badge.textContent = 'Propostas: abertas';
        } else {
            badge.className = 'badge bg-danger ms-1';
            badge.textContent = 'Propostas: fechadas';
        }
    }
}

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

        if (!data.success) {
            container.innerHTML = '<p class="text-danger">Erro ao carregar.</p>';
            return;
        }

        freeAgentsCache = Array.isArray(data.players) ? data.players : [];
        renderFreeAgents();
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar.</p>';
    }
}

function renderFreeAgents() {
    const container = document.getElementById('freeAgentsContainer');
    if (!container) return;

    const term = (document.getElementById('faSearchInput')?.value || '').trim().toLowerCase();
    const position = (document.getElementById('faPositionFilter')?.value || '').trim().toUpperCase();

    const filtered = freeAgentsCache.filter(player => {
        const matchesName = !term || (player.name || '').toLowerCase().includes(term);
        const matchesPos = !position || (player.position || '').toUpperCase() === position;
        return matchesName && matchesPos;
    });

    // Banner de período fechado
    let banner = '';
    if (!faStatusEnabled) {
        banner = '<div class="alert alert-warning py-2 mb-2"><strong>Período fechado:</strong> propostas temporariamente desativadas nesta liga.</div>';
    }

    if (!filtered.length) {
        container.innerHTML = banner + '<p class="text-muted">Nenhum jogador disponivel.</p>';
        return;
    }

    // Usar layout de cards responsivo
    let html = '<div class="row g-2 g-md-3">';
    filtered.forEach(player => {
        html += '<div class="col-12 col-sm-6 col-xl-4">';
        html += '<div class="card bg-dark border border-secondary h-100">';
        html += `<div class="card-body p-2 p-md-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <h6 class="mb-1 text-orange fw-bold" style="font-size: 0.95rem;">${player.name}</h6>
                    <small class="text-light-gray">
                        ${player.position}${player.secondary_position ? '/' + player.secondary_position : ''} • ${player.age} anos
                    </small>
                </div>
                <span class="badge bg-warning text-dark fs-6">${player.ovr}</span>
            </div>`;
        if (player.original_team_name) {
            html += `<small class="text-muted d-block mb-2"><i class="bi bi-arrow-left me-1"></i>Ex: ${player.original_team_name}</small>`;
        }
        if (player.my_offer_amount) {
            html += `<div class="badge bg-info mb-2 w-100">Seu lance: ${player.my_offer_amount} moedas</div>`;
        }
        const disabledAttr = faStatusEnabled ? '' : 'disabled';
        const label = faStatusEnabled ? (player.my_offer_amount ? 'Atualizar' : 'Proposta') : 'Período fechado';
        html += `<button class="btn btn-orange btn-sm w-100" ${disabledAttr} onclick="${faStatusEnabled ? `handleFreeAgencyOffer(${player.id}, '${player.name.replace(/'/g, "\\'")}', ${player.my_offer_amount ?? 0})` : ''}">
            <i class="bi bi-send me-1"></i>${label}
        </button>`;
        html += '</div></div></div>';
    });
    html += '</div>';
    container.innerHTML = banner + html;
}


// ========== MODAL PROPOSTA ==========

function abrirModalOferta(playerId, playerName, currentAmount = 1) {
    document.getElementById('freeAgentIdOffer').value = playerId;
    document.getElementById('freeAgentNomeOffer').textContent = playerName;
    document.getElementById('offerAmount').value = currentAmount ?? 0;
    document.getElementById('offerAmount').min = 0;

    const modal = new bootstrap.Modal(document.getElementById('modalOffer'));
    modal.show();
}

function handleFreeAgencyOffer(playerId, playerName, currentAmount = 1) {
    if (!userTeamId) {
        alert('Voce precisa ter um time para enviar proposta.');
        return;
    }
    abrirModalOferta(playerId, playerName, currentAmount);
}

document.getElementById('btnConfirmOffer')?.addEventListener('click', async () => {
    if (!faStatusEnabled) {
        alert('O período de propostas está fechado nesta liga.');
        return;
    }
    const playerId = document.getElementById('freeAgentIdOffer').value;
    const amount = parseInt(document.getElementById('offerAmount').value, 10);
    if (!Number.isFinite(amount) || amount < 0) {
        alert('Informe uma quantidade valida de moedas.');
        return;
    }

    // Cancelamento com 0 moedas
    if (amount === 0) {
        if (!confirm('Cancelar sua proposta para este jogador?')) return;
    } else {
        if (amount > userMoedas) {
            alert('Voce nao tem moedas suficientes.');
            return;
        }
    }

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'place_offer', free_agent_id: playerId, amount })
        });

        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao enviar lance');
            return;
        }

        bootstrap.Modal.getInstance(document.getElementById('modalOffer'))?.hide();
        carregarFreeAgents();
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao enviar lance');
    }
});

const openAdminTabBtn = document.getElementById('btnOpenAdminTab');
if (openAdminTabBtn) {
    openAdminTabBtn.addEventListener('click', () => {
        const adminTab = document.getElementById('fa-admin-tab');
        if (adminTab) {
            const tab = new bootstrap.Tab(adminTab);
            tab.show();
        }
    });
}

// Toggle admin para abrir/fechar período
document.getElementById('faStatusToggle')?.addEventListener('change', async (e) => {
    const league = getAdminLeague();
    if (!league) return;
    const enabled = e.target.checked ? 1 : 0;
    try {
        const resp = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'set_fa_status', league, enabled })
        });
        const data = await resp.json();
        if (!data.success) {
            alert(data.error || 'Falha ao atualizar status');
            // reverter UI
            e.target.checked = !enabled;
            return;
        }
        faStatusEnabled = !!data.enabled;
        updateFaStatusUI(true);
        // refletir na lista
        // Atualiza status da lista também (liga ativa)
        refreshFaStatus(getActiveLeague(), false).then(() => {
            carregarFreeAgents();
            if (typeof carregarLeiloesAtivos === 'function') {
                carregarLeiloesAtivos();
            }
        });
    } catch (err) {
        alert('Erro ao atualizar status');
        e.target.checked = !enabled;
    }
});
