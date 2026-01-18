/**
 * Free Agency - JavaScript
 * Sistema de moedas para contratacao de jogadores
 */

document.addEventListener('DOMContentLoaded', function() {
    carregarFreeAgents();
    
    if (userTeamId) {
        carregarMeusLances();
    }
    
    if (isAdmin) {
        carregarFreeAgentsAdmin();
        setupAdminEvents();
    }
});

// ========== ADMIN FUNCTIONS ==========

function setupAdminEvents() {
    document.getElementById('btnAddFreeAgent')?.addEventListener('click', addFreeAgent);
}

async function addFreeAgent() {
    const leagueId = document.getElementById('faLeague').value;
    const name = document.getElementById('faPlayerName').value.trim();
    const position = document.getElementById('faPosition').value;
    const age = document.getElementById('faAge').value;
    const overall = document.getElementById('faOverall').value;
    const minBid = document.getElementById('faMinBid').value;
    
    if (!leagueId || !name) {
        alert('Preencha liga e nome do jogador');
        return;
    }
    
    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add_player',
                league_id: leagueId,
                name: name,
                position: position,
                age: age,
                overall: overall,
                min_bid: minBid
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Jogador adicionado!');
            document.getElementById('faPlayerName').value = '';
            carregarFreeAgentsAdmin();
            carregarFreeAgents();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao adicionar jogador');
    }
}

async function carregarFreeAgentsAdmin() {
    const container = document.getElementById('adminFreeAgentsContainer');
    if (!container) return;
    
    try {
        const response = await fetch('api/free-agency.php?action=list_admin');
        const data = await response.json();
        
        if (data.success && data.players && data.players.length > 0) {
            let html = '<div class="table-responsive"><table class="table table-sm">';
            html += '<thead><tr><th>Jogador</th><th>Liga</th><th>Lances</th><th>Acoes</th></tr></thead><tbody>';
            
            data.players.forEach(player => {
                html += '<tr>';
                html += '<td><strong>' + player.name + '</strong> (' + player.position + ', OVR ' + player.overall + ')</td>';
                html += '<td>' + (player.league_name || 'N/A') + '</td>';
                html += '<td><span class="badge bg-info">' + (player.total_bids || 0) + '</span></td>';
                html += '<td>';
                html += '<button class="btn btn-sm btn-success me-1" onclick="abrirModalVencedor(' + player.id + ', \'' + player.name + '\')">';
                html += '<i class="bi bi-trophy"></i></button>';
                html += '<button class="btn btn-sm btn-danger" onclick="removerFreeAgent(' + player.id + ')">';
                html += '<i class="bi bi-trash"></i></button>';
                html += '</td></tr>';
            });
            
            html += '</tbody></table></div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="text-muted">Nenhum jogador cadastrado.</p>';
        }
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
            body: JSON.stringify({
                action: 'remove_player',
                player_id: playerId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            carregarFreeAgentsAdmin();
            carregarFreeAgents();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
    }
}

// ========== USER FUNCTIONS ==========

async function carregarFreeAgents() {
    const container = document.getElementById('freeAgentsContainer');
    if (!container) return;
    
    try {
        const url = currentLeagueId 
            ? 'api/free-agency.php?action=list&league_id=' + currentLeagueId
            : 'api/free-agency.php?action=list';
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success && data.players && data.players.length > 0) {
            let html = '<div class="row">';
            
            data.players.forEach(player => {
                html += '<div class="col-md-6 col-lg-4 mb-3">';
                html += '<div class="card h-100">';
                html += '<div class="card-header bg-light">';
                html += '<strong>' + player.name + '</strong>';
                html += '</div>';
                html += '<div class="card-body">';
                html += '<p class="mb-1"><i class="bi bi-person"></i> ' + player.position + ' | ' + player.age + ' anos</p>';
                html += '<p class="mb-1"><i class="bi bi-star-fill text-warning"></i> OVR: ' + player.overall + '</p>';
                html += '<p class="mb-1"><i class="bi bi-trophy"></i> Liga: ' + (player.league_name || 'N/A') + '</p>';
                html += '<hr>';
                html += '<p class="mb-1"><i class="bi bi-coin"></i> Lance Min: ' + player.min_bid + '</p>';
                html += '<p class="mb-2"><i class="bi bi-hand-index"></i> Maior Lance: <span class="badge bg-warning">' + (player.max_bid || 0) + '</span></p>';
                
                if (userTeamId) {
                    html += '<button class="btn btn-primary btn-sm w-100" onclick="abrirModalLance(' + player.id + ', \'' + player.name + '\', ' + player.min_bid + ', ' + (player.max_bid || 0) + ')">';
                    html += '<i class="bi bi-coin"></i> Fazer Lance</button>';
                }
                
                html += '</div></div></div>';
            });
            
            html += '</div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="text-muted">Nenhum jogador disponivel.</p>';
        }
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar.</p>';
    }
}

async function carregarMeusLances() {
    const container = document.getElementById('meusLancesContainer');
    if (!container) return;
    
    try {
        const response = await fetch('api/free-agency.php?action=my_bids');
        const data = await response.json();
        
        if (data.success && data.bids && data.bids.length > 0) {
            let html = '<div class="table-responsive"><table class="table">';
            html += '<thead><tr><th>Jogador</th><th>Meu Lance</th><th>Maior Lance</th><th>Status</th></tr></thead><tbody>';
            
            data.bids.forEach(bid => {
                const isWinning = bid.amount >= bid.max_bid;
                const statusBadge = isWinning 
                    ? '<span class="badge bg-success">Vencendo</span>'
                    : '<span class="badge bg-danger">Perdendo</span>';
                
                html += '<tr>';
                html += '<td><strong>' + bid.player_name + '</strong></td>';
                html += '<td>' + bid.amount + ' moedas</td>';
                html += '<td>' + bid.max_bid + ' moedas</td>';
                html += '<td>' + statusBadge + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table></div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="text-muted">Voce nao fez nenhum lance.</p>';
        }
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar.</p>';
    }
}

// ========== MODAL LANCE ==========

function abrirModalLance(playerId, playerName, minBid, maxBid) {
    document.getElementById('freeAgentIdLance').value = playerId;
    document.getElementById('freeAgentNomeLance').textContent = playerName;
    document.getElementById('lanceMinimo').textContent = minBid;
    document.getElementById('maiorLance').textContent = maxBid;
    document.getElementById('valorLance').value = Math.max(minBid, maxBid + 1);
    document.getElementById('valorLance').min = minBid;
    
    const modal = new bootstrap.Modal(document.getElementById('modalLance'));
    modal.show();
}

document.getElementById('btnConfirmarLance')?.addEventListener('click', async function() {
    const playerId = document.getElementById('freeAgentIdLance').value;
    const amount = parseInt(document.getElementById('valorLance').value);
    const minBid = parseInt(document.getElementById('lanceMinimo').textContent);
    
    if (amount < minBid) {
        alert('Lance deve ser maior ou igual ao lance minimo');
        return;
    }
    
    if (amount > userMoedas) {
        alert('Voce nao tem moedas suficientes');
        return;
    }
    
    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'place_bid',
                player_id: playerId,
                amount: amount
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Lance registrado!');
            bootstrap.Modal.getInstance(document.getElementById('modalLance')).hide();
            carregarFreeAgents();
            carregarMeusLances();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao registrar lance');
    }
});

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
        
        if (data.success && data.bids && data.bids.length > 0) {
            let html = '<div class="table-responsive"><table class="table">';
            html += '<thead><tr><th>Time</th><th>Lance</th><th>Acao</th></tr></thead><tbody>';
            
            data.bids.forEach(bid => {
                html += '<tr>';
                html += '<td><strong>' + bid.team_name + '</strong></td>';
                html += '<td>' + bid.amount + ' moedas</td>';
                html += '<td>';
                html += '<button class="btn btn-sm btn-success" onclick="confirmarVencedor(' + playerId + ', ' + bid.team_id + ', ' + bid.amount + ')">';
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
