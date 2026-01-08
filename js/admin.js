const api = async (path, options = {}) => {
  const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
  let body = {};
  try { body = await res.json(); } catch {}
  if (!res.ok) throw body;
  return body;
};

let currentLeagues = [];
let currentTeams = [];
let currentTrades = [];
let currentTeamDetails = null;

// Inicialização
async function init() {
  await loadLeagues();
  await loadTeamsForDropdown();
  
  // Event listeners
  document.getElementById('saveLeagueSettingsBtn').addEventListener('click', saveLeagueSettings);
  document.getElementById('teamSelectForRoster').addEventListener('change', function() {
    if (this.value) {
      loadTeamRoster(this.value);
    }
  });
  
  // Carregar dados iniciais das outras tabs
  document.getElementById('teams-tab').addEventListener('shown.bs.tab', () => loadTeams());
  document.getElementById('trades-tab').addEventListener('shown.bs.tab', () => loadTrades());
}

// ===== LIGAS =====
async function loadLeagues() {
  const container = document.getElementById('leaguesContainer');
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const data = await api('admin.php?action=leagues');
    currentLeagues = data.leagues || [];
    renderLeagues();
  } catch (err) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar ligas: ' + (err.error || 'Erro desconhecido') + '</div>';
  }
}

function renderLeagues() {
  const container = document.getElementById('leaguesContainer');
  container.innerHTML = '';
  
  currentLeagues.forEach(league => {
    const card = document.createElement('div');
    card.className = 'bg-dark-panel border-orange rounded p-4 mb-3';
    card.innerHTML = `
      <div class="row align-items-center">
        <div class="col-md-3">
          <h4 class="text-orange mb-1">${league.league}</h4>
          <small class="text-light-gray">${league.team_count} times</small>
        </div>
        <div class="col-md-3">
          <label class="form-label text-light-gray mb-1">CAP Mínimo</label>
          <input type="number" class="form-control bg-dark text-white border-orange" 
                 value="${league.cap_min}" 
                 data-league="${league.league}" 
                 data-field="cap_min" />
        </div>
        <div class="col-md-3">
          <label class="form-label text-light-gray mb-1">CAP Máximo</label>
          <input type="number" class="form-control bg-dark text-white border-orange" 
                 value="${league.cap_max}" 
                 data-league="${league.league}" 
                 data-field="cap_max" />
        </div>
        <div class="col-md-3">
          <div class="badge bg-gradient-orange fs-6 w-100 py-2">
            ${league.cap_min} - ${league.cap_max}
          </div>
        </div>
      </div>
    `;
    container.appendChild(card);
  });
}

async function saveLeagueSettings() {
  const inputs = document.querySelectorAll('#leaguesContainer input[data-league][data-field]');
  const groups = {};
  
  inputs.forEach(inp => {
    const lg = inp.dataset.league;
    groups[lg] = groups[lg] || { league: lg };
    groups[lg][inp.dataset.field] = parseInt(inp.value, 10);
  });

  const entries = Object.values(groups);
  const btn = document.getElementById('saveLeagueSettingsBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
  
  try {
    await Promise.all(entries.map(e => api('admin.php?action=league_settings', { 
      method: 'PUT', 
      body: JSON.stringify(e) 
    })));
    
    btn.classList.add('btn-success');
    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvo!';
    setTimeout(() => {
      btn.classList.remove('btn-success');
      btn.innerHTML = '<i class="bi bi-save2 me-1"></i>Salvar Configurações';
      btn.disabled = false;
    }, 2000);
  } catch (err) {
    alert('Erro ao salvar: ' + (err.error || 'Erro desconhecido'));
    btn.innerHTML = '<i class="bi bi-save2 me-1"></i>Salvar Configurações';
    btn.disabled = false;
  }
}

// ===== TIMES =====
async function loadTeams(league = null) {
  const container = document.getElementById('teamsContainer');
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const url = league ? `admin.php?action=teams&league=${league}` : 'admin.php?action=teams';
    const data = await api(url);
    currentTeams = data.teams || [];
    renderTeams();
  } catch (err) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar times: ' + (err.error || 'Erro desconhecido') + '</div>';
  }
}

function renderTeams() {
  const container = document.getElementById('teamsContainer');
  
  if (currentTeams.length === 0) {
    container.innerHTML = '<div class="alert alert-info">Nenhum time encontrado</div>';
    return;
  }
  
  container.innerHTML = `
    <div class="table-responsive">
      <table class="table table-dark table-hover">
        <thead>
          <tr>
            <th>Time</th>
            <th>Liga</th>
            <th>Conferência</th>
            <th>Divisão</th>
            <th>Proprietário</th>
            <th>CAP Top 8</th>
            <th>Jogadores</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
          ${currentTeams.map(team => `
            <tr>
              <td>
                <strong>${team.city} ${team.name}</strong>
              </td>
              <td><span class="badge bg-gradient-orange">${team.league}</span></td>
              <td>${team.conference || '-'}</td>
              <td>${team.division_name || '-'}</td>
              <td>
                <small>${team.owner_name}</small><br>
                <small class="text-light-gray">${team.owner_email}</small>
              </td>
              <td>${team.cap_top8}</td>
              <td>${team.player_count}</td>
              <td>
                <button class="btn btn-sm btn-outline-orange" onclick="editTeam(${team.id})">
                  <i class="bi bi-pencil-fill"></i>
                </button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function filterTeamsByLeague(league) {
  // Atualizar botões ativos
  document.querySelectorAll('#teams .btn-group button').forEach(btn => {
    btn.classList.remove('active', 'btn-orange');
    btn.classList.add('btn-outline-orange');
  });
  
  event.target.classList.add('active', 'btn-orange');
  event.target.classList.remove('btn-outline-orange');
  
  loadTeams(league);
}

function editTeam(teamId) {
  const team = currentTeams.find(t => t.id == teamId);
  if (!team) return;
  
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `
    <div class="modal-dialog">
      <div class="modal-content bg-dark-panel">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white">Editar Time</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label text-light-gray">Cidade</label>
            <input type="text" class="form-control bg-dark text-white border-orange" id="editTeamCity" value="${team.city}">
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Nome</label>
            <input type="text" class="form-control bg-dark text-white border-orange" id="editTeamName" value="${team.name}">
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Mascote</label>
            <input type="text" class="form-control bg-dark text-white border-orange" id="editTeamMascot" value="${team.mascot}">
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Conferência</label>
            <select class="form-select bg-dark text-white border-orange" id="editTeamConference">
              <option value="">Sem conferência</option>
              <option value="LESTE" ${team.conference === 'LESTE' ? 'selected' : ''}>LESTE</option>
              <option value="OESTE" ${team.conference === 'OESTE' ? 'selected' : ''}>OESTE</option>
            </select>
          </div>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-orange" onclick="saveTeamEdit(${teamId})">Salvar</button>
        </div>
      </div>
    </div>
  `;
  
  document.body.appendChild(modal);
  const bsModal = new bootstrap.Modal(modal);
  bsModal.show();
  
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function saveTeamEdit(teamId) {
  const city = document.getElementById('editTeamCity').value;
  const name = document.getElementById('editTeamName').value;
  const mascot = document.getElementById('editTeamMascot').value;
  const conference = document.getElementById('editTeamConference').value;
  
  try {
    await api('admin.php?action=team', {
      method: 'PUT',
      body: JSON.stringify({
        team_id: teamId,
        city, name, mascot, conference
      })
    });
    
    bootstrap.Modal.getInstance(document.querySelector('.modal')).hide();
    await loadTeams();
    alert('Time atualizado com sucesso!');
  } catch (err) {
    alert('Erro ao atualizar time: ' + (err.error || 'Erro desconhecido'));
  }
}

// ===== ELENCOS =====
async function loadTeamsForDropdown() {
  try {
    const data = await api('admin.php?action=teams');
    const teams = data.teams || [];
    const select = document.getElementById('teamSelectForRoster');
    
    teams.forEach(team => {
      const option = document.createElement('option');
      option.value = team.id;
      option.textContent = `${team.city} ${team.name} (${team.league})`;
      select.appendChild(option);
    });
  } catch (err) {
    console.error('Erro ao carregar times:', err);
  }
}

async function loadTeamRoster(teamId) {
  const container = document.getElementById('rosterContainer');
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const data = await api(`admin.php?action=team_details&team_id=${teamId}`);
    currentTeamDetails = data.team;
    renderRoster();
  } catch (err) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar elenco: ' + (err.error || 'Erro desconhecido') + '</div>';
  }
}

function renderRoster() {
  const container = document.getElementById('rosterContainer');
  const team = currentTeamDetails;
  
  container.innerHTML = `
    <div class="bg-dark-panel border-orange rounded p-3 mb-3">
      <h5 class="text-white mb-2">${team.city} ${team.name}</h5>
      <div class="row">
        <div class="col-md-6">
          <small class="text-light-gray">Liga: <span class="badge bg-gradient-orange">${team.league}</span></small>
        </div>
        <div class="col-md-6">
          <small class="text-light-gray">CAP Top 8: <strong class="text-orange">${team.cap_top8}</strong></small>
        </div>
      </div>
    </div>
    
    <div class="table-responsive">
      <table class="table table-dark table-hover">
        <thead>
          <tr>
            <th>Jogador</th>
            <th>Posição</th>
            <th>Idade</th>
            <th>OVR</th>
            <th>Papel</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
          ${team.players.map(player => `
            <tr>
              <td><strong>${player.name}</strong></td>
              <td>${player.position}</td>
              <td>${player.age}</td>
              <td><span class="badge ${player.ovr >= 80 ? 'bg-success' : player.ovr >= 70 ? 'bg-warning' : 'bg-secondary'}">${player.ovr}</span></td>
              <td>${player.role}</td>
              <td>
                <button class="btn btn-sm btn-outline-orange me-1" onclick="editPlayer(${player.id})">
                  <i class="bi bi-pencil-fill"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deletePlayer(${player.id})">
                  <i class="bi bi-trash-fill"></i>
                </button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
    
    ${team.picks && team.picks.length > 0 ? `
      <div class="mt-4">
        <h5 class="text-white mb-3">Picks do Draft</h5>
        <div class="table-responsive">
          <table class="table table-dark table-sm">
            <thead>
              <tr>
                <th>Temporada</th>
                <th>Rodada</th>
                <th>Time Original</th>
                <th>Notas</th>
              </tr>
            </thead>
            <tbody>
              ${team.picks.map(pick => `
                <tr>
                  <td>${pick.season_year}</td>
                  <td>${pick.round}ª Rodada</td>
                  <td>${pick.city} ${pick.team_name}</td>
                  <td><small>${pick.notes || '-'}</small></td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      </div>
    ` : ''}
  `;
}

function editPlayer(playerId) {
  const player = currentTeamDetails.players.find(p => p.id == playerId);
  if (!player) return;
  
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `
    <div class="modal-dialog">
      <div class="modal-content bg-dark-panel">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white">Editar Jogador</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <h6 class="text-white mb-3">${player.name}</h6>
          <div class="mb-3">
            <label class="form-label text-light-gray">Posição</label>
            <input type="text" class="form-control bg-dark text-white border-orange" id="editPlayerPosition" value="${player.position}">
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">OVR</label>
            <input type="number" class="form-control bg-dark text-white border-orange" id="editPlayerOvr" value="${player.ovr}" min="0" max="99">
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Papel</label>
            <select class="form-select bg-dark text-white border-orange" id="editPlayerRole">
              <option value="Titular" ${player.role === 'Titular' ? 'selected' : ''}>Titular</option>
              <option value="Banco" ${player.role === 'Banco' ? 'selected' : ''}>Banco</option>
              <option value="Outro" ${player.role === 'Outro' ? 'selected' : ''}>Outro</option>
              <option value="G-League" ${player.role === 'G-League' ? 'selected' : ''}>G-League</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Transferir para outro time</label>
            <select class="form-select bg-dark text-white border-orange" id="editPlayerTeam">
              <option value="">Manter no time atual</option>
            </select>
          </div>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-orange" onclick="savePlayerEdit(${playerId})">Salvar</button>
        </div>
      </div>
    </div>
  `;
  
  document.body.appendChild(modal);
  
  // Carregar times para transferência
  api('admin.php?action=teams').then(data => {
    const select = modal.querySelector('#editPlayerTeam');
    data.teams.forEach(team => {
      if (team.id != currentTeamDetails.id) {
        const option = document.createElement('option');
        option.value = team.id;
        option.textContent = `${team.city} ${team.name} (${team.league})`;
        select.appendChild(option);
      }
    });
  });
  
  const bsModal = new bootstrap.Modal(modal);
  bsModal.show();
  
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function savePlayerEdit(playerId) {
  const position = document.getElementById('editPlayerPosition').value;
  const ovr = parseInt(document.getElementById('editPlayerOvr').value);
  const role = document.getElementById('editPlayerRole').value;
  const teamId = document.getElementById('editPlayerTeam').value || null;
  
  const data = { player_id: playerId, position, ovr, role };
  if (teamId) data.team_id = teamId;
  
  try {
    await api('admin.php?action=player', {
      method: 'PUT',
      body: JSON.stringify(data)
    });
    
    bootstrap.Modal.getInstance(document.querySelector('.modal')).hide();
    await loadTeamRoster(currentTeamDetails.id);
    alert('Jogador atualizado com sucesso!');
  } catch (err) {
    alert('Erro ao atualizar jogador: ' + (err.error || 'Erro desconhecido'));
  }
}

async function deletePlayer(playerId) {
  if (!confirm('Tem certeza que deseja deletar este jogador? Esta ação não pode ser desfeita.')) return;
  
  try {
    await api(`admin.php?action=player&id=${playerId}`, { method: 'DELETE' });
    await loadTeamRoster(currentTeamDetails.id);
    alert('Jogador deletado com sucesso!');
  } catch (err) {
    alert('Erro ao deletar jogador: ' + (err.error || 'Erro desconhecido'));
  }
}

// ===== TRADES =====
async function loadTrades(status = 'all') {
  const container = document.getElementById('tradesContainer');
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const url = status === 'all' ? 'admin.php?action=trades' : `admin.php?action=trades&status=${status}`;
    const data = await api(url);
    currentTrades = data.trades || [];
    renderTrades();
  } catch (err) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar trades: ' + (err.error || 'Erro desconhecido') + '</div>';
  }
}

function renderTrades() {
  const container = document.getElementById('tradesContainer');
  
  if (currentTrades.length === 0) {
    container.innerHTML = '<div class="alert alert-info">Nenhuma trade encontrada</div>';
    return;
  }
  
  container.innerHTML = currentTrades.map(trade => {
    const statusBadge = {
      'pending': '<span class="badge bg-warning text-dark">Pendente</span>',
      'accepted': '<span class="badge bg-success">Aceita</span>',
      'rejected': '<span class="badge bg-danger">Rejeitada</span>',
      'cancelled': '<span class="badge bg-secondary">Cancelada</span>'
    }[trade.status] || '';
    
    return `
      <div class="bg-dark-panel border-orange rounded p-3 mb-3">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h5 class="text-white mb-1">
              ${trade.from_city} ${trade.from_name} 
              <i class="bi bi-arrow-right text-orange mx-2"></i> 
              ${trade.to_city} ${trade.to_name}
            </h5>
            <small class="text-light-gray">
              ${new Date(trade.created_at).toLocaleString('pt-BR')} | 
              Liga: <span class="badge bg-gradient-orange">${trade.from_league}</span>
            </small>
          </div>
          <div class="text-end">
            ${statusBadge}
            ${trade.status === 'pending' ? `
              <button class="btn btn-sm btn-outline-danger ms-2" onclick="cancelTrade(${trade.id})">
                <i class="bi bi-x-circle-fill"></i> Cancelar
              </button>
            ` : ''}
            ${trade.status === 'accepted' ? `
              <button class="btn btn-sm btn-outline-warning ms-2" onclick="revertTrade(${trade.id})">
                <i class="bi bi-arrow-counterclockwise"></i> Reverter
              </button>
            ` : ''}
          </div>
        </div>
        
        <div class="row">
          <div class="col-md-6">
            <h6 class="text-orange mb-2">${trade.from_city} ${trade.from_name} oferece:</h6>
            ${trade.offer_players.length > 0 ? `
              <ul class="list-unstyled">
                ${trade.offer_players.map(p => `
                  <li class="text-white"><i class="bi bi-person-fill text-orange"></i> ${p.name} (${p.position}, OVR ${p.ovr})</li>
                `).join('')}
              </ul>
            ` : ''}
            ${trade.offer_picks.length > 0 ? `
              <ul class="list-unstyled">
                ${trade.offer_picks.map(p => `
                  <li class="text-white"><i class="bi bi-calendar-check-fill text-orange"></i> ${p.season_year} ${p.round}ª Rodada (${p.city} ${p.team_name})</li>
                `).join('')}
              </ul>
            ` : ''}
            ${trade.offer_players.length === 0 && trade.offer_picks.length === 0 ? '<p class="text-light-gray">Nada</p>' : ''}
          </div>
          
          <div class="col-md-6">
            <h6 class="text-orange mb-2">${trade.to_city} ${trade.to_name} oferece:</h6>
            ${trade.request_players.length > 0 ? `
              <ul class="list-unstyled">
                ${trade.request_players.map(p => `
                  <li class="text-white"><i class="bi bi-person-fill text-orange"></i> ${p.name} (${p.position}, OVR ${p.ovr})</li>
                `).join('')}
              </ul>
            ` : ''}
            ${trade.request_picks.length > 0 ? `
              <ul class="list-unstyled">
                ${trade.request_picks.map(p => `
                  <li class="text-white"><i class="bi bi-calendar-check-fill text-orange"></i> ${p.season_year} ${p.round}ª Rodada (${p.city} ${p.team_name})</li>
                `).join('')}
              </ul>
            ` : ''}
            ${trade.request_players.length === 0 && trade.request_picks.length === 0 ? '<p class="text-light-gray">Nada</p>' : ''}
          </div>
        </div>
        
        ${trade.notes ? `<div class="mt-2"><small class="text-light-gray"><strong>Notas:</strong> ${trade.notes}</small></div>` : ''}
      </div>
    `;
  }).join('');
}

function filterTrades(status) {
  // Atualizar botões ativos
  document.querySelectorAll('#trades .btn-group button').forEach(btn => {
    btn.classList.remove('active', 'btn-orange');
    btn.classList.add('btn-outline-orange');
  });
  
  event.target.classList.add('active', 'btn-orange');
  event.target.classList.remove('btn-outline-orange');
  
  loadTrades(status);
}

async function cancelTrade(tradeId) {
  if (!confirm('Tem certeza que deseja cancelar esta trade?')) return;
  
  try {
    await api('admin.php?action=cancel_trade', {
      method: 'PUT',
      body: JSON.stringify({ trade_id: tradeId })
    });
    
    await loadTrades();
    alert('Trade cancelada com sucesso!');
  } catch (err) {
    alert('Erro ao cancelar trade: ' + (err.error || 'Erro desconhecido'));
  }
}

async function revertTrade(tradeId) {
  if (!confirm('Tem certeza que deseja REVERTER esta trade? Todos os jogadores e picks voltarão para os times originais.')) return;
  
  try {
    await api('admin.php?action=revert_trade', {
      method: 'PUT',
      body: JSON.stringify({ trade_id: tradeId })
    });
    
    await loadTrades();
    alert('Trade revertida com sucesso! Todos os itens voltaram para os times originais.');
  } catch (err) {
    alert('Erro ao reverter trade: ' + (err.error || 'Erro desconhecido'));
  }
}

// Inicializar quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', init);
