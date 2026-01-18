/**
 * Leilao de Jogadores - JavaScript
 * Sistema de trocas via leilao
 */

document.addEventListener('DOMContentLoaded', function() {
    // Carregar dados iniciais
    carregarLeiloesAtivos();
    
    if (userTeamId) {
        carregarMinhasPropostas();
        carregarPropostasRecebidas();
    }
    
    if (isAdmin) {
        carregarLeiloesAdmin();
        setupAdminEvents();
    }
});

// ========== ADMIN FUNCTIONS ==========

function setupAdminEvents() {
    const selectLeague = document.getElementById('selectLeague');
    const selectTeam = document.getElementById('selectTeam');
    const selectPlayer = document.getElementById('selectPlayer');
    const btnCadastrar = document.getElementById('btnCadastrarLeilao');
    
    // Quando selecionar liga, carregar times
    selectLeague.addEventListener('change', async function() {
        const leagueId = this.value;
        selectTeam.innerHTML = '<option value="">Carregando...</option>';
        selectTeam.disabled = true;
        selectPlayer.innerHTML = '<option value="">Selecione primeiro um time...</option>';
        selectPlayer.disabled = true;
        btnCadastrar.disabled = true;
        
        if (!leagueId) {
            selectTeam.innerHTML = '<option value="">Selecione primeiro uma liga...</option>';
            return;
        }
        
        try {
            const response = await fetch(`api/teams.php?league_id=${leagueId}`);
            const data = await response.json();
            
            if (data.success && data.teams) {
                selectTeam.innerHTML = '<option value="">Selecione um time...</option>';
                data.teams.forEach(team => {
                    selectTeam.innerHTML += `<option value="${team.id}">${team.name}</option>`;
                });
                selectTeam.disabled = false;
            } else {
                selectTeam.innerHTML = '<option value="">Nenhum time encontrado</option>';
            }
        } catch (error) {
            console.error('Erro ao carregar times:', error);
            selectTeam.innerHTML = '<option value="">Erro ao carregar</option>';
        }
    });
    
    // Quando selecionar time, carregar jogadores
    selectTeam.addEventListener('change', async function() {
        const teamId = this.value;
        selectPlayer.innerHTML = '<option value="">Carregando...</option>';
        selectPlayer.disabled = true;
        btnCadastrar.disabled = true;
        
        if (!teamId) {
            selectPlayer.innerHTML = '<option value="">Selecione primeiro um time...</option>';
            return;
        }
        
        try {
            const response = await fetch(`api/team-players.php?team_id=${teamId}`);
            const data = await response.json();
            
            if (data.success && data.players) {
                selectPlayer.innerHTML = '<option value="">Selecione um jogador...</option>';
                data.players.forEach(player => {
                    selectPlayer.innerHTML += `<option value="${player.id}">${player.name} (${player.position}, ${player.age} anos, OVR ${player.overall})</option>`;
                });
                selectPlayer.disabled = false;
            } else {
                selectPlayer.innerHTML = '<option value="">Nenhum jogador encontrado</option>';
            }
        } catch (error) {
            console.error('Erro ao carregar jogadores:', error);
            selectPlayer.innerHTML = '<option value="">Erro ao carregar</option>';
        }
    });
    
    // Habilitar botao quando jogador selecionado
    selectPlayer.addEventListener('change', function() {
        btnCadastrar.disabled = !this.value;
    });
    
    // Cadastrar jogador no leilao
    btnCadastrar.addEventListener('click', cadastrarJogadorLeilao);
}

async function cadastrarJogadorLeilao() {
    const playerId = document.getElementById('selectPlayer').value;
    const teamId = document.getElementById('selectTeam').value;
    const leagueId = document.getElementById('selectLeague').value;
    const dataInicio = document.getElementById('dataInicio').value;
    const dataFim = document.getElementById('dataFim').value;
    
    if (!playerId || !teamId || !leagueId) {
        alert('Selecione liga, time e jogador');
        return;
    }
    
    try {
        const response = await fetch('api/leilao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'cadastrar',
                player_id: playerId,
                team_id: teamId,
                league_id: leagueId,
                data_inicio: dataInicio || null,
                data_fim: dataFim || null
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Jogador cadastrado no leilao com sucesso!');
            // Limpar selecoes
            document.getElementById('selectLeague').value = '';
            document.getElementById('selectTeam').innerHTML = '<option value="">Selecione primeiro uma liga...</option>';
            document.getElementById('selectTeam').disabled = true;
            document.getElementById('selectPlayer').innerHTML = '<option value="">Selecione primeiro um time...</option>';
            document.getElementById('selectPlayer').disabled = true;
            document.getElementById('btnCadastrarLeilao').disabled = true;
            document.getElementById('dataInicio').value = '';
            document.getElementById('dataFim').value = '';
            
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

async function carregarLeiloesAdmin() {
    const container = document.getElementById('adminLeiloesContainer');
    if (!container) return;
    
    try {
        const response = await fetch('api/leilao.php?action=listar_admin');
        const data = await response.json();
        
        if (data.success && data.leiloes && data.leiloes.length > 0) {
            let html = '<div class="table-responsive"><table class="table table-striped">';
            html += '<thead><tr><th>Jogador</th><th>Time</th><th>Liga</th><th>Status</th><th>Propostas</th><th>Acoes</th></tr></thead><tbody>';
            
            data.leiloes.forEach(leilao => {
                const statusBadge = leilao.status === 'ativo' 
                    ? '<span class="badge bg-success">Ativo</span>'
                    : leilao.status === 'finalizado'
                    ? '<span class="badge bg-secondary">Finalizado</span>'
                    : '<span class="badge bg-warning">Pendente</span>';
                
                html += `<tr>
                    <td><strong>${leilao.player_name}</strong><br><small>${leilao.position} | OVR ${leilao.overall}</small></td>
                    <td>${leilao.team_name}</td>
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
            let html = '<div class="row">';
            
            data.leiloes.forEach(leilao => {
                const isMyTeam = leilao.team_id == userTeamId;
                const cardClass = isMyTeam ? 'border-warning' : '';
                
                html += `
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="card ${cardClass}">
                        <div class="card-header ${isMyTeam ? 'bg-warning' : 'bg-light'}">
                            <strong>${leilao.player_name}</strong>
                            ${isMyTeam ? '<span class="badge bg-dark ms-2">Seu Jogador</span>' : ''}
                        </div>
                        <div class="card-body">
                            <p class="mb-1"><i class="bi bi-person"></i> ${leilao.position} | ${leilao.age} anos</p>
                            <p class="mb-1"><i class="bi bi-star-fill text-warning"></i> OVR: ${leilao.overall}</p>
                            <p class="mb-1"><i class="bi bi-building"></i> Time: ${leilao.team_name}</p>
                            <p class="mb-2"><i class="bi bi-trophy"></i> Liga: ${leilao.league_name}</p>
                            <hr>
                            <p class="mb-2"><i class="bi bi-chat-dots"></i> Propostas: <span class="badge bg-info">${leilao.total_propostas || 0}</span></p>
                            ${!isMyTeam && userTeamId ? `
                            <button class="btn btn-primary btn-sm w-100" onclick="abrirModalProposta(${leilao.id}, '${leilao.player_name}')">
                                <i class="bi bi-send"></i> Enviar Proposta
                            </button>
                            ` : ''}
                            ${isMyTeam ? `
                            <button class="btn btn-success btn-sm w-100" onclick="verMinhasPropostasRecebidas(${leilao.id})">
                                <i class="bi bi-inbox"></i> Ver Propostas (${leilao.total_propostas || 0})
                            </button>
                            ` : ''}
                        </div>
                    </div>
                </div>`;
            });
            
            html += '</div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="text-muted">Nenhum leilao em andamento.</p>';
        }
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar leiloes.</p>';
    }
}

async function carregarMinhasPropostas() {
    const container = document.getElementById('minhasPropostasContainer');
    if (!container) return;
    
    try {
        const response = await fetch('api/leilao.php?action=minhas_propostas');
        const data = await response.json();
        
        if (data.success && data.propostas && data.propostas.length > 0) {
            let html = '<div class="table-responsive"><table class="table">';
            html += '<thead><tr><th>Jogador Desejado</th><th>Jogadores Oferecidos</th><th>Status</th><th>Data</th></tr></thead><tbody>';
            
            data.propostas.forEach(proposta => {
                const statusBadge = proposta.status === 'pendente'
                    ? '<span class="badge bg-warning">Pendente</span>'
                    : proposta.status === 'aceita'
                    ? '<span class="badge bg-success">Aceita</span>'
                    : '<span class="badge bg-danger">Recusada</span>';
                
                html += `<tr>
                    <td><strong>${proposta.player_name}</strong><br><small>${proposta.team_name}</small></td>
                    <td>${proposta.jogadores_oferecidos || 'N/A'}</td>
                    <td>${statusBadge}</td>
                    <td>${proposta.created_at}</td>
                </tr>`;
            });
            
            html += '</tbody></table></div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="text-muted">Voce nao enviou nenhuma proposta.</p>';
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
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><strong>${leilao.player_name}</strong> - ${leilao.position} | OVR ${leilao.overall}</span>
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
            container.innerHTML = '<p class="text-muted">Nenhum jogador seu esta em leilao com propostas.</p>';
        }
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar propostas.</p>';
    }
}

// ========== MODAL PROPOSTA ==========

async function abrirModalProposta(leilaoId, playerName) {
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
                            <strong>${player.name}</strong> (${player.position}, OVR ${player.overall})
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
    
    // Abrir modal
    const modal = new bootstrap.Modal(document.getElementById('modalProposta'));
    modal.show();
}

document.getElementById('btnEnviarProposta')?.addEventListener('click', async function() {
    const leilaoId = document.getElementById('leilaoIdProposta').value;
    const notas = document.getElementById('notasProposta').value;
    const checkboxes = document.querySelectorAll('.player-checkbox:checked');
    
    if (checkboxes.length === 0) {
        alert('Selecione pelo menos um jogador para oferecer em troca.');
        return;
    }
    
    const playerIds = Array.from(checkboxes).map(cb => cb.value);
    
    try {
        const response = await fetch('api/leilao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'enviar_proposta',
                leilao_id: leilaoId,
                player_ids: playerIds,
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
                <div class="card mb-3 ${proposta.status === 'aceita' ? 'border-success' : ''}">
                    <div class="card-header d-flex justify-content-between">
                        <span><strong>Proposta de:</strong> ${proposta.team_name}</span>
                        <span class="badge ${proposta.status === 'pendente' ? 'bg-warning' : proposta.status === 'aceita' ? 'bg-success' : 'bg-secondary'}">${proposta.status}</span>
                    </div>
                    <div class="card-body">
                        <h6>Jogadores oferecidos:</h6>
                        <ul>
                            ${proposta.jogadores.map(j => `<li><strong>${j.name}</strong> - ${j.position}, OVR ${j.overall}, ${j.age} anos</li>`).join('')}
                        </ul>
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
            container.innerHTML = '<p class="text-muted">Nenhuma proposta recebida ainda.</p>';
        }
    } catch (error) {
        console.error('Erro:', error);
        container.innerHTML = '<p class="text-danger">Erro ao carregar propostas.</p>';
    }
}

async function verPropostasAdmin(leilaoId) {
    verMinhasPropostasRecebidas(leilaoId);
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
