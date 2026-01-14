const api = async (path, options = {}) => {
  const res = await fetch(`/api/${path}`, {
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    credentials: 'same-origin',
    ...options,
  });
  const text = await res.text();
  let body = {};
  if (text) {
    try {
      body = JSON.parse(text);
    } catch {
      body = { error: text };
    }
  }
  if (!res.ok || body.success === false) {
    const message = body.error || body.message || 'Erro desconhecido';
    throw { ...body, error: message };
  }
  return body;
};

let appState = { view: 'home', currentLeague: null, currentTeam: null, teamDetails: null, currentFAleague: 'ELITE' };
let adminFreeAgents = [];
const freeAgencyTeamsCache = {};

async function init() { showHome(); }

function updateBreadcrumb() {
  const breadcrumb = document.getElementById('breadcrumb');
  const breadcrumbContainer = document.getElementById('breadcrumbContainer');
  const pageTitle = document.getElementById('pageTitle');
  
  breadcrumb.innerHTML = '<li class="breadcrumb-item"><a href="#" onclick="showHome(); return false;">Admin</a></li>';
  
  if (appState.view === 'home') {
    breadcrumbContainer.style.display = 'none';
    pageTitle.textContent = 'Painel Administrativo';
  } else {
    breadcrumbContainer.style.display = 'block';
    if (appState.view === 'league' && appState.currentLeague) {
      breadcrumb.innerHTML += `<li class="breadcrumb-item active">${appState.currentLeague}</li>`;
      pageTitle.textContent = `Liga ${appState.currentLeague}`;
    } else if (appState.view === 'team' && appState.currentTeam) {
      breadcrumb.innerHTML += `<li class="breadcrumb-item"><a href="#" onclick="showLeague('${appState.currentLeague}'); return false;">${appState.currentLeague}</a></li>`;
      breadcrumb.innerHTML += `<li class="breadcrumb-item active">${appState.currentTeam.city} ${appState.currentTeam.name}</li>`;
      pageTitle.textContent = `${appState.currentTeam.city} ${appState.currentTeam.name}`;
    } else if (appState.view === 'trades') {
      breadcrumb.innerHTML += '<li class="breadcrumb-item active">Trades</li>';
      pageTitle.textContent = 'Gerenciar Trades';
    } else if (appState.view === 'config') {
      breadcrumb.innerHTML += '<li class="breadcrumb-item active">Configurações</li>';
      pageTitle.textContent = 'Configurações das Ligas';
    } else if (appState.view === 'seasons') {
      breadcrumb.innerHTML += '<li class="breadcrumb-item active">Temporadas</li>';
      pageTitle.textContent = 'Gerenciar Temporadas';
    } else if (appState.view === 'ranking') {
      breadcrumb.innerHTML += '<li class="breadcrumb-item active">Rankings</li>';
      pageTitle.textContent = 'Rankings Globais';
    } else if (appState.view === 'freeagency') {
      breadcrumb.innerHTML += '<li class="breadcrumb-item active">Free Agency</li>';
      pageTitle.textContent = 'Aprovar Free Agency';
    }
  }
}

async function showHome() {
  appState.view = 'home';
  updateBreadcrumb();
  
  const container = document.getElementById('mainContainer');
  container.innerHTML = `<div class="row g-4 mb-4"><div class="col-12"><h3 class="text-white mb-3"><i class="bi bi-trophy-fill text-orange me-2"></i>Ligas</h3></div>
<div class="col-md-6 col-lg-3"><div class="league-card" onclick="showLeague('ELITE')"><h3>ELITE</h3><p class="text-light-gray mb-2">Liga Elite</p><span class="badge bg-gradient-orange" id="elite-teams">Ver mais</span></div></div>
<div class="col-md-6 col-lg-3"><div class="league-card" onclick="showLeague('NEXT')"><h3>NEXT</h3><p class="text-light-gray mb-2">Liga Next</p><span class="badge bg-gradient-orange" id="next-teams">Ver mais</span></div></div>
<div class="col-md-6 col-lg-3"><div class="league-card" onclick="showLeague('RISE')"><h3>RISE</h3><p class="text-light-gray mb-2">Liga Rise</p><span class="badge bg-gradient-orange" id="rise-teams">Ver mais</span></div></div>
<div class="col-md-6 col-lg-3"><div class="league-card" onclick="showLeague('ROOKIE')"><h3>ROOKIE</h3><p class="text-light-gray mb-2">Liga Rookie</p><span class="badge bg-gradient-orange" id="rookie-teams">Ver mais</span></div></div></div>
<div class="row g-4"><div class="col-12"><h3 class="text-white mb-3"><i class="bi bi-gear-fill text-orange me-2"></i>Ações</h3></div>
<div class="col-md-6"><div class="action-card" onclick="showTrades()"><i class="bi bi-arrow-left-right"></i><h4>Trades</h4><p>Gerencie todas as trocas</p></div></div>
<div class="col-md-6"><div class="action-card" onclick="showConfig()"><i class="bi bi-sliders"></i><h4>Configurações</h4><p>Configure CAP e regras das ligas</p></div></div>
<div class="col-md-6"><div class="action-card" onclick="showDirectives()"><i class="bi bi-clipboard-check"></i><h4>Diretrizes</h4><p>Gerencie prazos e visualize diretrizes</p></div></div></div>`;
  
  try {
    const data = await api('admin.php?action=leagues');
    (data.leagues || []).forEach(league => {
      const el = document.getElementById(`${league.league.toLowerCase()}-teams`);
      if (el) el.textContent = `${league.team_count} ${league.team_count === 1 ? 'time' : 'times'}`;
    });
  } catch (e) {}
}

async function showLeague(league) {
  appState.view = 'league';
  appState.currentLeague = league;
  updateBreadcrumb();
  
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const data = await api(`admin.php?action=teams&league=${league}`);
    const teams = data.teams || [];
    container.innerHTML = `<div class="mb-4"><button class="btn btn-back" onclick="showHome()"><i class="bi bi-arrow-left"></i> Voltar</button></div>
<div class="row g-3">${teams.map(t => `<div class="col-md-6 col-lg-4 col-xl-3"><div class="team-card" onclick="showTeam(${t.id})">
<div class="d-flex align-items-center"><img src="${t.photo_url || '/img/default-team.png'}" class="team-logo me-3"><div class="flex-grow-1">
<h5 class="mb-0">${t.city}</h5><h5 class="mb-0">${t.name}</h5><small class="text-muted">${t.owner_name}</small></div></div>
<hr class="my-2" style="border-color:var(--fba-border);"><div class="d-flex justify-content-between">
<small class="text-light-gray"><i class="bi bi-people-fill text-orange me-1"></i>${t.player_count}</small>
<small class="text-light-gray"><i class="bi bi-star-fill text-orange me-1"></i>${t.cap_top8}</small></div></div></div>`).join('')}</div>`;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar times</div>';
  }
}

async function showTeam(teamId) {
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const data = await api(`admin.php?action=team_details&team_id=${teamId}`);
    appState.teamDetails = data.team;
    appState.currentTeam = data.team;
    appState.view = 'team';
    updateBreadcrumb();
    
    const t = data.team;
    container.innerHTML = `<div class="mb-4"><button class="btn btn-back" onclick="showLeague('${t.league}')"><i class="bi bi-arrow-left"></i> Voltar</button></div>
<div class="bg-dark-panel border-orange rounded p-4 mb-4"><div class="row align-items-center">
<div class="col-md-2 text-center"><img src="${t.photo_url || '/img/default-team.png'}" class="img-fluid rounded-circle border border-orange" style="max-width:100px;"></div>
<div class="col-md-6"><h2 class="text-white mb-2">${t.city} ${t.name}</h2><p class="text-light-gray mb-1"><strong>Proprietário:</strong> ${t.owner_name}</p>
<p class="text-light-gray mb-0"><strong>Liga:</strong> <span class="badge bg-gradient-orange">${t.league}</span></p></div>
<div class="col-md-4 text-end"><button class="btn btn-outline-orange mb-2 w-100" onclick="editTeam(${t.id})"><i class="bi bi-pencil-fill me-2"></i>Editar</button>
<div class="bg-dark rounded p-3"><h4 class="text-orange mb-0">${t.cap_top8}</h4><small class="text-light-gray">CAP Top 8</small></div></div></div></div>
<ul class="nav nav-tabs mb-3"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#roster-tab">Elenco (${t.players.length})</button></li>
<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#picks-tab">Picks (${t.picks ? t.picks.length : 0})</button></li></ul>
<div class="tab-content">
<div class="tab-pane fade show active" id="roster-tab">
<div class="d-flex justify-content-between mb-3">
<h5 class="text-white mb-0">Jogadores</h5>
<button class="btn btn-sm btn-orange" onclick="addPlayer(${t.id})"><i class="bi bi-plus-circle me-1"></i>Adicionar Jogador</button>
</div>
<div class="table-responsive"><table class="table table-dark table-hover">
<thead><tr><th>Jogador</th><th>Pos</th><th>Idade</th><th>OVR</th><th>Papel</th><th>Ações</th></tr></thead>
<tbody>${t.players.map(p => `<tr><td><strong>${p.name}</strong></td><td>${p.position}</td><td>${p.age}</td>
<td><span class="badge ${p.ovr >= 80 ? 'bg-success' : p.ovr >= 70 ? 'bg-warning text-dark' : 'bg-secondary'}">${p.ovr}</span></td><td>${p.role}</td>
<td><button class="btn btn-sm btn-outline-orange me-1" onclick="editPlayer(${p.id})"><i class="bi bi-pencil-fill"></i></button>
<button class="btn btn-sm btn-outline-danger" onclick="deletePlayer(${p.id})"><i class="bi bi-trash-fill"></i></button></td></tr>`).join('')}</tbody></table></div>
</div>
<div class="tab-pane fade" id="picks-tab">
<div class="d-flex justify-content-between mb-3">
<h5 class="text-white mb-0">Picks</h5>
<button class="btn btn-sm btn-orange" onclick="addPick(${t.id})"><i class="bi bi-plus-circle me-1"></i>Adicionar Pick</button>

  function setFreeAgencyLeague(league) {
    appState.currentFAleague = league;
    loadAdminFreeAgents(league);
    loadFreeAgencyOffers(league);
  }

  function refreshAdminFreeAgency() {
    const league = appState.currentFAleague || 'ELITE';
    setFreeAgencyLeague(league);
  }

</div>
${t.picks && t.picks.length > 0 ? `<div class="table-responsive"><table class="table table-dark"><thead><tr><th>Temporada</th><th>Rodada</th><th>Time Original</th><th>Ações</th></tr></thead>
<tbody>${t.picks.map(p => `<tr><td>${p.season_year}</td><td>${p.round}ª</td><td>${p.city} ${p.team_name}</td>
<td><button class="btn btn-sm btn-outline-orange me-1" onclick="editPick(${p.id})"><i class="bi bi-pencil-fill"></i></button>
<button class="btn btn-sm btn-outline-danger" onclick="deletePick(${p.id})"><i class="bi bi-trash-fill"></i></button></td></tr>`).join('')}</tbody></table></div>` : '<div class="text-center py-5 text-light-gray">Nenhum pick</div>'}
</div></div>`;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar time</div>';
  }
}

async function showTrades(status = 'all') {
  appState.view = 'trades';
  updateBreadcrumb();
  
  const container = document.getElementById('mainContainer');
  container.innerHTML = `<div class="mb-4"><button class="btn btn-back" onclick="showHome()"><i class="bi bi-arrow-left"></i> Voltar</button></div>
<div class="d-flex justify-content-between mb-3 flex-wrap gap-2"><h4 class="text-white mb-0">Filtrar</h4>
<div class="btn-group flex-wrap">
<button class="btn btn-outline-orange btn-sm ${status === 'pending' ? 'active' : ''}" onclick="showTrades('pending')">Pendentes</button>
<button class="btn btn-outline-orange btn-sm ${status === 'accepted' ? 'active' : ''}" onclick="showTrades('accepted')">Aceitas</button>
<button class="btn btn-outline-orange btn-sm ${status === 'all' ? 'active' : ''}" onclick="showTrades('all')">Todas</button></div></div>
<div id="tradesListContainer"><div class="text-center py-4"><div class="spinner-border text-orange"></div></div></div>`;
  
  try {
    const url = status === 'all' ? 'admin.php?action=trades' : `admin.php?action=trades&status=${status}`;
    const data = await api(url);
    const trades = data.trades || [];
    const tc = document.getElementById('tradesListContainer');
    
    if (trades.length === 0) {
      tc.innerHTML = '<div class="text-center py-5 text-light-gray">Nenhuma trade</div>';
      return;
    }
    
    const renderTradeAssets = (players = [], picks = []) => {
      const playerItems = players.map(p => `<li class="text-white mb-1"><i class="bi bi-person-fill text-orange"></i> ${p.name} (${p.position}, ${p.ovr})</li>`).join('');
      const pickItems = picks.map(pk => {
        const roundNumber = parseInt(pk.round, 10);
        const roundLabel = Number.isNaN(roundNumber) ? `${pk.round}ª rodada` : `${roundNumber}ª rodada`;
        const seasonLabel = pk.season_year ? `${pk.season_year}` : 'Temporada indefinida';
        const originalTeam = `${pk.city} ${pk.team_name}`;
        return `<li class="text-white mb-1"><i class="bi bi-ticket-detailed text-orange"></i> ${seasonLabel} ${roundLabel} - ${originalTeam}</li>`;
      }).join('');
      const content = playerItems + pickItems;
      return content ? `<ul class="list-unstyled mb-0">${content}</ul>` : '<p class="text-light-gray">Nada</p>';
    };

    tc.innerHTML = trades.map(tr => {
      const badge = { pending: 'bg-warning text-dark', accepted: 'bg-success', rejected: 'bg-danger', cancelled: 'bg-secondary' }[tr.status];
      return `<div class="bg-dark-panel border-orange rounded p-3 mb-3"><div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
<div><h5 class="text-white mb-1">${tr.from_city} ${tr.from_name} <i class="bi bi-arrow-right text-orange mx-2"></i> ${tr.to_city} ${tr.to_name}</h5>
<small class="text-light-gray">${new Date(tr.created_at).toLocaleString('pt-BR')} | <span class="badge bg-gradient-orange">${tr.from_league}</span></small></div>
<div><span class="badge ${badge}">${tr.status}</span>
${tr.status === 'pending' ? `<button class="btn btn-sm btn-outline-danger ms-2" onclick="cancelTrade(${tr.id})">Cancelar</button>` : ''}
${tr.status === 'accepted' ? `<button class="btn btn-sm btn-outline-warning ms-2" onclick="revertTrade(${tr.id})">Reverter</button>` : ''}</div></div>
<div class="row"><div class="col-md-6"><h6 class="text-orange mb-2">${tr.from_city} ${tr.from_name} oferece:</h6>
${renderTradeAssets(tr.offer_players || [], tr.offer_picks || [])}</div>
<div class="col-md-6"><h6 class="text-orange mb-2">${tr.to_city} ${tr.to_name} oferece:</h6>
${renderTradeAssets(tr.request_players || [], tr.request_picks || [])}</div></div></div>`;
    }).join('');
  } catch (e) {
    document.getElementById('tradesListContainer').innerHTML = '<div class="alert alert-danger">Erro</div>';
  }
}

async function showConfig() {
  appState.view = 'config';
  updateBreadcrumb();
  
  const container = document.getElementById('mainContainer');
  container.innerHTML = `<div class="mb-4"><button class="btn btn-back" onclick="showHome()"><i class="bi bi-arrow-left"></i> Voltar</button></div>
<div class="d-flex justify-content-between mb-3"><h4 class="text-white mb-0">Configurações das Ligas</h4>
<button class="btn btn-orange" id="saveConfigBtn"><i class="bi bi-save2 me-1"></i>Salvar Tudo</button></div>
<div id="configContainer"><div class="text-center py-4"><div class="spinner-border text-orange"></div></div></div>`;
  
  try {
    const data = await api('admin.php?action=leagues');
    document.getElementById('configContainer').innerHTML = (data.leagues || []).map(lg => `
<div class="bg-dark-panel border-orange rounded p-4 mb-4">
<div class="row mb-3">
<div class="col-12"><h4 class="text-orange mb-1">${lg.league}</h4><small class="text-light-gray">${lg.team_count} ${lg.team_count === 1 ? 'time' : 'times'}</small></div>
</div>
<div class="row g-3 mb-3">
<div class="col-md-3"><label class="form-label text-light-gray mb-1">CAP Mínimo</label>
<input type="number" class="form-control bg-dark text-white border-orange" value="${lg.cap_min}" data-league="${lg.league}" data-field="cap_min" /></div>
<div class="col-md-3"><label class="form-label text-light-gray mb-1">CAP Máximo</label>
<input type="number" class="form-control bg-dark text-white border-orange" value="${lg.cap_max}" data-league="${lg.league}" data-field="cap_max" /></div>
<div class="col-md-3"><label class="form-label text-light-gray mb-1">Máx. Trocas/Temporada</label>
<input type="number" class="form-control bg-dark text-white border-orange" value="${lg.max_trades || 3}" data-league="${lg.league}" data-field="max_trades" /></div>
<div class="col-md-3 d-flex align-items-end"><div class="badge bg-gradient-orange fs-6 w-100 py-2">${lg.cap_min} - ${lg.cap_max} CAP</div></div>
</div>
<div class="row">
<div class="col-12"><label class="form-label text-light-gray mb-1">Edital da Liga (PDF/Word)</label>
<div class="input-group">
<input type="file" class="form-control bg-dark text-white border-orange" id="edital_file_${lg.league}" accept=".pdf,.doc,.docx" />
<button class="btn btn-orange" onclick="uploadEdital('${lg.league}')"><i class="bi bi-upload me-1"></i>Upload</button>
</div>
${lg.edital_file ? `<div class="mt-2 d-flex align-items-center gap-2">
<span class="text-success flex-grow-1"><i class="bi bi-file-earmark-check"></i> ${lg.edital_file}</span>
<a href="/api/edital.php?action=download_edital&league=${lg.league}" class="btn btn-sm btn-outline-light" download target="_blank">
<i class="bi bi-download me-1"></i>Baixar
</a>
<button class="btn btn-sm btn-outline-danger" onclick="deleteEdital('${lg.league}')"><i class="bi bi-trash"></i></button>
</div>` : '<small class="text-light-gray mt-1">Nenhum arquivo enviado</small>'}
</div>
</div>
</div>`).join('');
    
    document.getElementById('saveConfigBtn').addEventListener('click', saveLeagueSettings);
  } catch (e) {}
}

async function saveLeagueSettings() {
  const inputs = document.querySelectorAll('#configContainer input[data-league], #configContainer textarea[data-league]');
  const groups = {};
  inputs.forEach(inp => {
    const lg = inp.dataset.league;
    groups[lg] = groups[lg] || { league: lg };
    const value = inp.dataset.field === 'edital' ? inp.value : parseInt(inp.value);
    groups[lg][inp.dataset.field] = value;
  });
  
  const btn = document.getElementById('saveConfigBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
  
  try {
    await Promise.all(Object.values(groups).map(e => api('admin.php?action=league_settings', { method: 'PUT', body: JSON.stringify(e) })));
    btn.classList.add('btn-success');
    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvo!';
    setTimeout(() => {
      btn.classList.remove('btn-success');
      btn.innerHTML = '<i class="bi bi-save2 me-1"></i>Salvar';
      btn.disabled = false;
    }, 2000);
  } catch (e) {
    alert('Erro ao salvar');
    btn.innerHTML = '<i class="bi bi-save2 me-1"></i>Salvar';
    btn.disabled = false;
  }
}

function editTeam(teamId) {
  const t = appState.currentTeam;
  if (!t || t.id != teamId) return;
  
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Editar Time</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="mb-3"><label class="form-label text-light-gray">Cidade</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="editTeamCity" value="${t.city}"></div>
<div class="mb-3"><label class="form-label text-light-gray">Nome</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="editTeamName" value="${t.name}"></div>
<div class="mb-3"><label class="form-label text-light-gray">Conferência</label>
<select class="form-select bg-dark text-white border-orange" id="editTeamConference">
<option value="">Sem conferência</option><option value="LESTE" ${t.conference === 'LESTE' ? 'selected' : ''}>LESTE</option>
<option value="OESTE" ${t.conference === 'OESTE' ? 'selected' : ''}>OESTE</option></select></div></div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="saveTeamEdit(${teamId})">Salvar</button></div></div></div>`;
  
  document.body.appendChild(modal);
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function saveTeamEdit(teamId) {
  try {
    await api('admin.php?action=team', {
      method: 'PUT',
      body: JSON.stringify({
        team_id: teamId,
        city: document.getElementById('editTeamCity').value,
        name: document.getElementById('editTeamName').value,
        conference: document.getElementById('editTeamConference').value
      })
    });
    bootstrap.Modal.getInstance(document.querySelector('.modal')).hide();
    await showTeam(teamId);
    alert('Atualizado!');
  } catch (e) { alert('Erro'); }
}

function editPlayer(playerId) {
  const p = appState.teamDetails.players.find(p => p.id == playerId);
  if (!p) return;
  
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Editar ${p.name}</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="mb-3"><label class="form-label text-light-gray">Posição</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="editPlayerPosition" value="${p.position}"></div>
<div class="mb-3"><label class="form-label text-light-gray">OVR</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="editPlayerOvr" value="${p.ovr}" min="0" max="99"></div>
<div class="mb-3"><label class="form-label text-light-gray">Papel</label>
<select class="form-select bg-dark text-white border-orange" id="editPlayerRole">
<option value="Titular" ${p.role === 'Titular' ? 'selected' : ''}>Titular</option>
<option value="Banco" ${p.role === 'Banco' ? 'selected' : ''}>Banco</option>
<option value="Outro" ${p.role === 'Outro' ? 'selected' : ''}>Outro</option>
<option value="G-League" ${p.role === 'G-League' ? 'selected' : ''}>G-League</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Transferir</label>
<select class="form-select bg-dark text-white border-orange" id="editPlayerTeam"><option value="">Manter no time</option></select></div></div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="savePlayerEdit(${playerId})">Salvar</button></div></div></div>`;
  
  document.body.appendChild(modal);
  api('admin.php?action=teams').then(data => {
    const select = modal.querySelector('#editPlayerTeam');
    const currentLeague = appState.currentTeam.league;
    data.teams.forEach(t => {
      // Apenas times da mesma liga, exceto o time atual
      if (t.id != appState.currentTeam.id && t.league === currentLeague) {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = `${t.city} ${t.name}`;
        select.appendChild(opt);
      }
    });
  });
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function savePlayerEdit(playerId) {
  const data = { player_id: playerId, position: document.getElementById('editPlayerPosition').value,
    ovr: parseInt(document.getElementById('editPlayerOvr').value), role: document.getElementById('editPlayerRole').value };
  const teamId = document.getElementById('editPlayerTeam').value;
  if (teamId) data.team_id = teamId;
  
  try {
    await api('admin.php?action=player', { method: 'PUT', body: JSON.stringify(data) });
    bootstrap.Modal.getInstance(document.querySelector('.modal')).hide();
    await showTeam(appState.currentTeam.id);
    alert('Atualizado!');
  } catch (e) { alert('Erro'); }
}

async function deletePlayer(playerId) {
  if (!confirm('Deletar jogador?')) return;
  try {
    await api(`admin.php?action=player&id=${playerId}`, { method: 'DELETE' });
    await showTeam(appState.currentTeam.id);
    alert('Deletado!');
  } catch (e) { alert('Erro'); }
}

async function cancelTrade(tradeId) {
  if (!confirm('Cancelar trade?')) return;
  try {
    await api('admin.php?action=cancel_trade', { method: 'PUT', body: JSON.stringify({ trade_id: tradeId }) });
    await showTrades();
    alert('Cancelada!');
  } catch (e) { alert('Erro'); }
}

async function revertTrade(tradeId) {
  if (!confirm('REVERTER trade? Jogadores voltarão aos times originais.')) return;
  try {
    await api('admin.php?action=revert_trade', { method: 'PUT', body: JSON.stringify({ trade_id: tradeId }) });
    await showTrades();
    alert('Revertida!');
  } catch (e) { alert('Erro'); }
}

function addPlayer(teamId) {
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Adicionar Jogador</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="mb-3"><label class="form-label text-light-gray">Nome</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="addPlayerName" placeholder="Nome completo do jogador"></div>
<div class="row">
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Posição</label>
<select class="form-select bg-dark text-white border-orange" id="addPlayerPosition">
<option value="PG">PG</option>
<option value="SG">SG</option>
<option value="SF">SF</option>
<option value="PF">PF</option>
<option value="C">C</option>
</select></div>
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Pos. Secundária</label>
<select class="form-select bg-dark text-white border-orange" id="addPlayerSecondaryPosition">
<option value="">Nenhuma</option>
<option value="PG">PG</option>
<option value="SG">SG</option>
<option value="SF">SF</option>
<option value="PF">PF</option>
<option value="C">C</option>
</select></div>
</div>
<div class="row">
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Idade</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="addPlayerAge" value="25" min="18" max="45"></div>
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">OVR</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="addPlayerOvr" value="70" min="0" max="99"></div>
</div>
<div class="mb-3"><label class="form-label text-light-gray">Papel</label>
<select class="form-select bg-dark text-white border-orange" id="addPlayerRole">
<option value="Titular">Titular</option>
<option value="Banco" selected>Banco</option>
<option value="Outro">Outro</option>
<option value="G-League">G-League</option></select></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="saveNewPlayer(${teamId})">Adicionar</button></div></div></div>`;
  
  document.body.appendChild(modal);
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function saveNewPlayer(teamId) {
  const data = {
    team_id: teamId,
    name: document.getElementById('addPlayerName').value.trim(),
    position: document.getElementById('addPlayerPosition').value,
    secondary_position: document.getElementById('addPlayerSecondaryPosition').value || null,
    age: parseInt(document.getElementById('addPlayerAge').value),
    ovr: parseInt(document.getElementById('addPlayerOvr').value),
    role: document.getElementById('addPlayerRole').value
  };
  
  if (!data.name || !data.position) {
    alert('Nome e posição são obrigatórios!');
    return;
  }
  
  try {
    await api('admin.php?action=player', { method: 'POST', body: JSON.stringify(data) });
    bootstrap.Modal.getInstance(document.querySelector('.modal')).hide();
    await showTeam(teamId);
    alert('Jogador adicionado!');
  } catch (e) { 
    alert('Erro ao adicionar jogador: ' + (e.error || 'Desconhecido')); 
  }
}

function addPick(teamId) {
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Adicionar Pick</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="mb-3"><label class="form-label text-light-gray">Temporada</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="addPickYear" value="${new Date().getFullYear()}" min="2025"></div>
<div class="mb-3"><label class="form-label text-light-gray">Rodada</label>
<select class="form-select bg-dark text-white border-orange" id="addPickRound">
<option value="1">1ª Rodada</option>
<option value="2">2ª Rodada</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Time Original</label>
<select class="form-select bg-dark text-white border-orange" id="addPickOriginalTeam">
<option value="">Carregando...</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Observações (opcional)</label>
<textarea class="form-control bg-dark text-white border-orange" id="addPickNotes" rows="2" placeholder="Informações adicionais sobre este pick"></textarea></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="saveNewPick(${teamId})">Adicionar</button></div></div></div>`;
  
  document.body.appendChild(modal);
  
  // Carregar times para seleção
  api('admin.php?action=teams').then(data => {
    const select = modal.querySelector('#addPickOriginalTeam');
    select.innerHTML = '<option value="">Selecione o time original</option>';
    data.teams.forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name} (${t.league})`;
      if (t.id == teamId) opt.selected = true;
      select.appendChild(opt);
    });
  });
  
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function saveNewPick(teamId) {
  const data = {
    team_id: teamId,
    original_team_id: parseInt(document.getElementById('addPickOriginalTeam').value),
    season_year: parseInt(document.getElementById('addPickYear').value),
    round: document.getElementById('addPickRound').value,
    notes: document.getElementById('addPickNotes').value.trim() || null
  };
  
  if (!data.original_team_id) {
    alert('Selecione o time original!');
    return;
  }
  
  try {
    await api('admin.php?action=pick', { method: 'POST', body: JSON.stringify(data) });
    bootstrap.Modal.getInstance(document.querySelector('.modal')).hide();
    await showTeam(teamId);
    alert('Pick adicionado!');
  } catch (e) { 
    alert('Erro ao adicionar pick: ' + (e.error || 'Desconhecido')); 
  }
}

function editPick(pickId) {
  const p = appState.teamDetails.picks.find(pk => pk.id == pickId);
  if (!p) return;
  
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Editar Pick</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="mb-3"><label class="form-label text-light-gray">Temporada</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="editPickYear" value="${p.season_year}" min="2025"></div>
<div class="mb-3"><label class="form-label text-light-gray">Rodada</label>
<select class="form-select bg-dark text-white border-orange" id="editPickRound">
<option value="1" ${p.round == 1 ? 'selected' : ''}>1ª Rodada</option>
<option value="2" ${p.round == 2 ? 'selected' : ''}>2ª Rodada</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Time Original</label>
<select class="form-select bg-dark text-white border-orange" id="editPickOriginalTeam">
<option value="">Carregando...</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Observações (opcional)</label>
<textarea class="form-control bg-dark text-white border-orange" id="editPickNotes" rows="2">${p.notes || ''}</textarea></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="savePickEdit(${pickId})">Salvar</button></div></div></div>`;
  
  document.body.appendChild(modal);
  
  // Carregar times para seleção
  api('admin.php?action=teams').then(data => {
    const select = modal.querySelector('#editPickOriginalTeam');
    select.innerHTML = '';
    data.teams.forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name} (${t.league})`;
      if (t.id == p.original_team_id) opt.selected = true;
      select.appendChild(opt);
    });
  });
  
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function savePickEdit(pickId) {
  const data = {
    pick_id: pickId,
    team_id: appState.currentTeam.id,
    original_team_id: parseInt(document.getElementById('editPickOriginalTeam').value),
    season_year: parseInt(document.getElementById('editPickYear').value),
    round: document.getElementById('editPickRound').value,
    notes: document.getElementById('editPickNotes').value.trim() || null
  };
  
  if (!data.original_team_id) {
    alert('Selecione o time original!');
    return;
  }
  
  try {
    await api('admin.php?action=pick', { method: 'PUT', body: JSON.stringify(data) });
    bootstrap.Modal.getInstance(document.querySelector('.modal')).hide();
    await showTeam(appState.currentTeam.id);
    alert('Pick atualizado!');
  } catch (e) { 
    alert('Erro ao atualizar pick: ' + (e.error || 'Desconhecido')); 
  }
}

async function deletePick(pickId) {
  if (!confirm('Deletar este pick?')) return;
  try {
    await api(`admin.php?action=pick&id=${pickId}`, { method: 'DELETE' });
    await showTeam(appState.currentTeam.id);
    alert('Pick deletado!');
  } catch (e) { alert('Erro ao deletar pick!'); }
}

// Função para upload de edital
async function uploadEdital(league) {
  const fileInput = document.getElementById(`edital_file_${league}`);
  const file = fileInput.files[0];
  
  if (!file) {
    alert('Selecione um arquivo primeiro!');
    return;
  }
  
  // Validação de tipo de arquivo
  const allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
  if (!allowedTypes.includes(file.type)) {
    alert('Apenas arquivos PDF ou Word são permitidos!');
    return;
  }
  
  // Validação de tamanho (10MB)
  if (file.size > 10 * 1024 * 1024) {
    alert('Arquivo muito grande! Máximo: 10MB');
    return;
  }
  
  const formData = new FormData();
  formData.append('file', file);
  formData.append('league', league);
  
  try {
    const response = await fetch('api/edital.php?action=upload_edital', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      alert('Edital enviado com sucesso!');
      showConfig(); // Recarrega para mostrar o arquivo
    } else {
      alert('Erro: ' + (result.error || 'Falha no upload'));
    }
  } catch (e) {
    alert('Erro ao enviar arquivo: ' + e.message);
  }
}

// Função para deletar edital
async function deleteEdital(league) {
  if (!confirm('Tem certeza que deseja remover o edital desta liga?')) return;
  
  try {
    const response = await fetch('api/edital.php?action=delete_edital', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ league })
    });
    
    const result = await response.json();
    
    if (result.success) {
      alert('Edital removido!');
      showConfig(); // Recarrega
    } else {
      alert('Erro: ' + (result.error || 'Falha ao remover'));
    }
  } catch (e) {
    alert('Erro ao remover arquivo: ' + e.message);
  }
}

document.addEventListener('DOMContentLoaded', init);

// ========== DIRETRIZES ==========
function formatDeadlineDateTime(value) {
  if (!value) return '-';
  try {
    return new Intl.DateTimeFormat('pt-BR', {
      timeZone: 'America/Sao_Paulo',
      dateStyle: 'short',
      timeStyle: 'short'
    }).format(new Date(value));
  } catch (e) {
    try {
      return new Date(value).toLocaleString('pt-BR');
    } catch (err) {
      return value;
    }
  }
}

async function showDirectives() {
  appState.view = 'directives';
  updateBreadcrumb();
  
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const data = await api('diretrizes.php?action=list_deadlines_admin');
    const deadlines = data.deadlines || [];
    
    container.innerHTML = `
      <div class="mb-4">
        <button class="btn btn-back" onclick="showHome()"><i class="bi bi-arrow-left"></i> Voltar</button>
        <button class="btn btn-orange float-end" onclick="showCreateDeadlineModal()">
          <i class="bi bi-plus-circle me-2"></i>Criar Prazo
        </button>
      </div>
      
      <div class="card bg-dark-panel border-orange">
        <div class="card-header bg-transparent border-orange">
          <h5 class="text-white mb-0"><i class="bi bi-calendar-event me-2"></i>Prazos de Diretrizes</h5>
        </div>
        <div class="card-body">
          ${deadlines.length === 0 ? 
            '<p class="text-light-gray text-center py-4">Nenhum prazo configurado</p>' :
            `<div class="table-responsive"><table class="table table-dark">
              <thead><tr>
                <th>Liga</th><th>Prazo (Horário de Brasília)</th><th>Descrição</th><th>Fase</th><th>Status</th><th>Envios</th><th>Ações</th>
              </tr></thead>
              <tbody>${deadlines.map(d => `
                <tr>
                  <td><span class="badge bg-gradient-orange">${d.league}</span></td>
                  <td>${formatDeadlineDateTime(d.deadline_date_iso || d.deadline_date)}</td>
                  <td>${d.description || '-'}</td>
                  <td>${(d.phase || 'regular') === 'playoffs' ? '<span class="badge bg-danger">Playoffs</span>' : '<span class="badge bg-info">Regular</span>'}</td>
                  <td>${d.is_active ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>'}</td>
                  <td><span class="badge bg-info">${d.submissions_count} time(s)</span></td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="viewDirectives(${d.id}, '${d.league}')" title="Ver diretrizes">
                      <i class="bi bi-eye"></i> Ver
                    </button>
                    <button class="btn btn-sm btn-outline-${d.is_active ? 'warning' : 'success'}" onclick="toggleDeadlineStatus(${d.id}, ${d.is_active})" title="${d.is_active ? 'Desativar' : 'Ativar'}">
                      <i class="bi bi-toggle-${d.is_active ? 'on' : 'off'}"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteDeadline(${d.id}, '${d.league}')" title="Excluir prazo">
                      <i class="bi bi-trash"></i>
                    </button>
                  </td>
                </tr>
              `).join('')}</tbody>
            </table></div>`
          }
        </div>
      </div>
    `;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar prazos</div>';
  }
}

function showCreateDeadlineModal() {
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `
    <div class="modal-dialog">
      <div class="modal-content bg-dark-panel border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white">Criar Prazo de Diretrizes</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label text-white">Liga</label>
            <select class="form-select bg-dark text-white border-orange" id="deadline-league">
              <option value="ELITE">ELITE</option>
              <option value="NEXT">NEXT</option>
              <option value="RISE">RISE</option>
              <option value="ROOKIE">ROOKIE</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label text-white">Data do Prazo</label>
            <input type="date" class="form-control bg-dark text-white border-orange" id="deadline-date" required>
          </div>
          <div class="mb-3">
            <label class="form-label text-white">Horário limite (Horário de São Paulo)</label>
            <input type="time" class="form-control bg-dark text-white border-orange" id="deadline-time" value="23:59" required>
            <small class="text-light-gray">O prazo será salvo considerando o fuso America/Sao_Paulo.</small>
          </div>
          <div class="mb-3">
            <label class="form-label text-white">Descrição</label>
            <input type="text" class="form-control bg-dark text-white border-orange" id="deadline-description" 
                   placeholder="Ex: Diretrizes da Rodada 1">
          </div>
          <div class="mb-3">
            <label class="form-label text-white">Fase</label>
            <select class="form-select bg-dark text-white border-orange" id="deadline-phase">
              <option value="regular" selected>Temporada Regular (máx 40 min)</option>
              <option value="playoffs">Playoffs (máx 45 min)</option>
            </select>
            <small class="text-light-gray">Define o limite máximo de minutagem por jogador no formulário.</small>
          </div>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-orange" onclick="createDeadline()">Criar</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  const bsModal = new bootstrap.Modal(modal);
  bsModal.show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function createDeadline() {
  const league = document.getElementById('deadline-league').value;
  const date = document.getElementById('deadline-date').value;
  const time = document.getElementById('deadline-time').value;
  const description = document.getElementById('deadline-description').value;
  const phase = document.getElementById('deadline-phase').value;
  
  if (!date) {
    alert('Preencha a data');
    return;
  }
  if (!time) {
    alert('Preencha o horário');
    return;
  }
  
  try {
    await api('diretrizes.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'create_deadline', league, deadline_date: date, deadline_time: time, description, phase })
    });
    alert('Prazo criado com sucesso!');
    const modalEl = document.querySelector('.modal.show') || document.querySelector('.modal');
    if (modalEl) {
      const modalInstance = bootstrap.Modal.getInstance(modalEl);
      if (modalInstance) {
        modalInstance.hide();
      }
    }
    showDirectives();
  } catch (e) {
    alert('Erro ao criar prazo: ' + (e.error || e.message));
  }
}

async function toggleDeadlineStatus(id, currentStatus) {
  try {
    await api('diretrizes.php', {
      method: 'PUT',
      body: JSON.stringify({ id, is_active: currentStatus ? 0 : 1 })
    });
    showDirectives();
  } catch (e) {
    alert('Erro ao atualizar status');
  }
}

async function deleteDeadline(id, league) {
  const confirmMsg = `Tem certeza que deseja excluir este prazo de diretrizes da liga ${league}?\n\nTodas as diretrizes enviadas para este prazo também serão excluídas!`;
  if (!confirm(confirmMsg)) return;
  
  try {
    await api('diretrizes.php', {
      method: 'DELETE',
      body: JSON.stringify({ id })
    });
    alert('Prazo excluído com sucesso!');
    showDirectives();
  } catch (e) {
    alert('Erro ao excluir prazo: ' + (e.error || e.message));
  }
}

async function viewDirectives(deadlineId, league) {
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const data = await api(`diretrizes.php?action=view_all_directives_admin&deadline_id=${deadlineId}`);
    const directives = data.directives || [];
    
    // Mapear labels para os novos valores
    const gameStyleLabels = {
      'balanced': 'Balanced', 'triangle': 'Triangle', 'grit_grind': 'Grit & Grind',
      'pace_space': 'Pace & Space', 'perimeter_centric': 'Perimeter Centric',
      'post_centric': 'Post Centric', 'seven_seconds': 'Seven Seconds',
      'defense': 'Defense', 'defensive_focus': 'Defensive Focus',
      'franchise_player': 'Franchise Player', 'most_stars': 'Maior nº de Estrelas'
    };
    const offenseStyleLabels = {
      'no_preference': 'No Preference', 'pick_roll': 'Pick & Roll',
      'neutral': 'Neutral Focus', 'play_through_star': 'Play Through Star',
      'get_to_basket': 'Get to Basket', 'get_shooters_open': 'Get Shooters Open', 'feed_post': 'Feed Post'
    };
    const paceLabels = {
      'no_preference': 'No Preference', 'patient': 'Patient', 'average': 'Average', 'shoot_at_will': 'Shoot at Will'
    };
    const defAggrLabels = {
      'physical': 'Physical', 'no_preference': 'No Preference', 'conservative': 'Conservative', 'neutral': 'Neutral'
    };
    const offRebLabels = {
      'limit_transition': 'Limit Transition', 'no_preference': 'No Preference', 
      'crash_glass': 'Crash Offensive Glass', 'some_crash': 'Some Crash, Others Get Back'
    };
    const defRebLabels = {
      'run_transition': 'Run in Transition', 'crash_glass': 'Crash Defensive Glass', 
      'some_crash': 'Some Crash Others Run', 'no_preference': 'No Preference'
    };
    const rotationLabels = { 'manual': 'Manual', 'auto': 'Automática' };
    
    container.innerHTML = `
      <div class="mb-4">
        <button class="btn btn-back" onclick="showDirectives()"><i class="bi bi-arrow-left"></i> Voltar</button>
      </div>
      
      <div class="card bg-dark-panel border-orange">
        <div class="card-header bg-transparent border-orange">
          <h5 class="text-white mb-0"><i class="bi bi-clipboard-data me-2"></i>Diretrizes Enviadas - Liga ${league}</h5>
        </div>
        <div class="card-body">
          ${directives.length === 0 ? 
            '<p class="text-light-gray text-center py-4">Nenhuma diretriz enviada ainda</p>' :
            directives.map(d => {
              const pm = d.player_minutes || {};
              const starters = [1,2,3,4,5].map(i => {
                const id = d['starter_' + i + '_id'];
                const m = id && pm[id] ? `${pm[id]} min` : '';
                const name = d['starter_' + i + '_name'] || '?';
                const pos = d['starter_' + i + '_pos'] || '?';
                return `<li>${name} (${pos}) ${m ? ' - ' + m : ''}</li>`;
              }).join('');
              const bench = [1,2,3].map(i => {
                const id = d['bench_' + i + '_id'];
                const m = id && pm[id] ? `${pm[id]} min` : '';
                const name = d['bench_' + i + '_name'] || '?';
                const pos = d['bench_' + i + '_pos'] || '?';
                return `<li>${name} (${pos}) ${m ? ' - ' + m : ''}</li>`;
              }).join('');
              return `
              <div class="card bg-dark mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <div>
                    <h6 class="text-white mb-0">${d.city} ${d.team_name}</h6>
                    <small class="text-light-gray">Enviado em ${new Date(d.submitted_at).toLocaleString('pt-BR')}</small>
                  </div>
                  <button class="btn btn-sm btn-outline-danger" onclick="deleteDirective(${d.id}, ${deadlineId}, '${league}')">
                    <i class="bi bi-trash"></i> Excluir
                  </button>
                </div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-md-6">
                      <h6 class="text-orange">Quinteto Titular</h6>
                      <ul class="text-light-gray">
                        ${starters}
                      </ul>
                    </div>
                    <div class="col-md-6">
                      <h6 class="text-orange">Banco</h6>
                      <ul class="text-light-gray">
                        ${bench}
                      </ul>
                    </div>
                    <div class="col-12 mt-3">
                      <h6 class="text-orange">Estilo de Jogo</h6>
                      <div class="row text-light-gray small">
                        <div class="col-md-4">Game Style: ${gameStyleLabels[d.game_style] || d.game_style}</div>
                        <div class="col-md-4">Offense Style: ${offenseStyleLabels[d.offense_style] || d.offense_style}</div>
                        <div class="col-md-4">Rotação: ${rotationLabels[d.rotation_style] || d.rotation_style}</div>
                      </div>
                    </div>
                    <div class="col-12 mt-3">
                      <h6 class="text-orange">Configurações</h6>
                      <div class="row text-light-gray small">
                        <div class="col-md-3">Tempo Ataque: ${paceLabels[d.pace] || d.pace}</div>
                        <div class="col-md-3">Agress. Def.: ${defAggrLabels[d.offensive_aggression] || d.offensive_aggression}</div>
                        <div class="col-md-3">Reb. Ofensivo: ${offRebLabels[d.offensive_rebound] || d.offensive_rebound}</div>
                        <div class="col-md-3">Reb. Defensivo: ${defRebLabels[d.defensive_rebound] || d.defensive_rebound}</div>
                      </div>
                    </div>
                    <div class="col-12 mt-3">
                      <h6 class="text-orange">Rotação e Foco</h6>
                      <div class="row text-light-gray small">
                        <div class="col-md-6">Jogadores na Rotação: ${d.rotation_players || 10}</div>
                        <div class="col-md-6">Foco Veteranos: ${d.veteran_focus || 50}%</div>
                      </div>
                    </div>
                    ${d.notes ? `<div class="col-12 mt-3"><h6 class="text-orange">Observações</h6><p class="text-light-gray">${d.notes}</p></div>` : ''}
                  </div>
                </div>
              </div>
            `;
            }).join('')
          }
        </div>
      </div>
    `;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar diretrizes</div>';
  }
}

// Função para excluir diretriz
async function deleteDirective(directiveId, deadlineId, league) {
  if (!confirm('Tem certeza que deseja excluir esta diretriz? O time terá que enviar novamente.')) return;
  
  try {
    await api('diretrizes.php', {
      method: 'DELETE',
      body: JSON.stringify({ action: 'delete_directive', directive_id: directiveId })
    });
    alert('Diretriz excluída com sucesso');
    viewDirectives(deadlineId, league);
  } catch (e) {
    alert(e.error || 'Erro ao excluir diretriz');
  }
}

// ========== FREE AGENCY ADMIN ==========
async function showFreeAgency() {
  appState.view = 'freeagency';
  updateBreadcrumb();
  
  const container = document.getElementById('mainContainer');
  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="showHome()"><i class="bi bi-arrow-left"></i> Voltar</button>
    </div>
    
    <div class="row mb-4">
      <div class="col-12 d-flex flex-wrap gap-3 justify-content-between align-items-center">
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-outline-orange active" onclick="setFreeAgencyLeague('ELITE')" id="btn-fa-ELITE">ELITE</button>
          <button class="btn btn-outline-orange" onclick="setFreeAgencyLeague('NEXT')" id="btn-fa-NEXT">NEXT</button>
          <button class="btn btn-outline-orange" onclick="setFreeAgencyLeague('RISE')" id="btn-fa-RISE">RISE</button>
          <button class="btn btn-outline-orange" onclick="setFreeAgencyLeague('ROOKIE')" id="btn-fa-ROOKIE">ROOKIE</button>
        </div>
        <button class="btn btn-orange" onclick="openCreateFreeAgentModal()">
          <i class="bi bi-plus-circle me-1"></i>Novo Free Agent
        </button>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-6">
        <div class="bg-dark-panel border-orange rounded p-4 h-100">
          <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center">
            <div>
              <h4 class="text-white mb-1">Jogadores disponíveis</h4>
              <small class="text-light-gray" id="faAvailableCount">--</small>
            </div>
            <div class="flex-grow-1" style="min-width:200px;">
              <input type="text" class="form-control bg-dark text-white border-orange" id="faAvailableSearch" placeholder="Buscar por nome ou posição">
            </div>
          </div>
          <div id="faAvailableContainer" class="mt-3">
            <div class="text-center py-4"><div class="spinner-border text-orange"></div></div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="bg-dark-panel border-orange rounded p-4 h-100">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="text-white mb-0">Propostas pendentes</h4>
            <button class="btn btn-outline-orange btn-sm" onclick="refreshAdminFreeAgency()">
              <i class="bi bi-arrow-repeat"></i>
            </button>
          </div>
          <div id="faOffersContainer">
            <div class="text-center py-4"><div class="spinner-border text-orange"></div></div>
          </div>
        </div>
      </div>
    </div>
  `;

  document.getElementById('faAvailableSearch')?.addEventListener('input', (event) => {
    renderAdminFreeAgents(event.target.value);
  });
  
  setFreeAgencyLeague('ELITE');
}

async function loadAdminFreeAgents(league) {
  const container = document.getElementById('faAvailableContainer');
  if (!container) return;
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange"></div></div>';
  try {
  const data = await api(`admin.php?action=free_agents&league=${league}`);
  adminFreeAgents = (data.free_agents || []).map(fa => ({ ...fa, id: Number(fa.id) }));
    const countEl = document.getElementById('faAvailableCount');
    if (countEl) {
      const qty = adminFreeAgents.length;
      countEl.textContent = `${qty} jogador${qty === 1 ? '' : 'es'}`;
    }
    const searchValue = document.getElementById('faAvailableSearch')?.value || '';
    renderAdminFreeAgents(searchValue);
  } catch (e) {
    adminFreeAgents = [];
    const countEl = document.getElementById('faAvailableCount');
    if (countEl) countEl.textContent = '0 jogadores';
    container.innerHTML = `<div class="alert alert-danger">Erro ao carregar jogadores livres: ${e.error || 'Desconhecido'}</div>`;
  }
}

function renderAdminFreeAgents(filterTerm = '') {
  const container = document.getElementById('faAvailableContainer');
  if (!container) return;
  if (!adminFreeAgents.length) {
    container.innerHTML = '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Nenhum jogador disponível nesta liga.</div>';
    return;
  }

  const term = filterTerm.trim().toLowerCase();
  const filtered = term ? adminFreeAgents.filter(fa => {
    const haystack = `${fa.name} ${fa.position} ${fa.secondary_position || ''}`.toLowerCase();
    return haystack.includes(term);
  }) : adminFreeAgents;

  if (filtered.length === 0) {
    container.innerHTML = '<div class="alert alert-warning"><i class="bi bi-search"></i> Nenhum jogador encontrado com este filtro.</div>';
    return;
  }

  container.innerHTML = filtered.map(fa => {
    const posDisplay = fa.secondary_position ? `${fa.position}/${fa.secondary_position}` : fa.position;
    const origin = fa.original_team_name ? `<small class="text-light-gray d-block">Ex: ${fa.original_team_name}</small>` : '';
    const pending = fa.pending_offers > 0 ? `<small class="text-warning d-block"><i class="bi bi-clock me-1"></i>${fa.pending_offers} proposta(s)</small>` : '';
    return `
      <div class="fa-card mb-3">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <h5 class="text-white mb-1">${fa.name}</h5>
            <div class="text-light-gray small">${posDisplay} | ${fa.age} anos</div>
            ${origin}
            ${pending}
          </div>
          <div class="text-end">
            <span class="badge bg-secondary">OVR ${fa.ovr}</span>
            <div class="d-flex flex-column gap-2 mt-2">
              <button class="btn btn-sm btn-outline-light" onclick="openAssignFreeAgentModal(${fa.id})">
                <i class="bi bi-check2-circle"></i> Definir Time
              </button>
              <button class="btn btn-sm btn-outline-danger" onclick="deleteFreeAgent(${fa.id})" title="Remover">
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

async function deleteFreeAgent(freeAgentId) {
  if (!confirm('Remover este free agent da lista?')) return;
  const league = appState.currentFAleague || 'ELITE';
  try {
  await api(`admin.php?action=free_agent&id=${freeAgentId}`, { method: 'DELETE' });
  alert('Free agent removido.');
  refreshAdminFreeAgency();
  } catch (e) {
    alert(e.error || 'Erro ao remover free agent');
  }
}

async function loadTeamsForFreeAgency(league) {
  if (freeAgencyTeamsCache[league]) return freeAgencyTeamsCache[league];
  const data = await api(`admin.php?action=free_agent_teams&league=${league}`);
  const teams = data.teams || [];
  freeAgencyTeamsCache[league] = teams;
  return teams;
}

async function openAssignFreeAgentModal(freeAgentId) {
  const league = appState.currentFAleague || 'ELITE';
  const freeAgent = adminFreeAgents.find(fa => fa.id === freeAgentId);
  if (!freeAgent) return;

  const teams = await loadTeamsForFreeAgency(league);
  if (teams.length === 0) {
    alert('Nenhum time encontrado para esta liga.');
    return;
  }

  const selectId = `assignTeamSelect-${freeAgentId}`;
  const modalId = `assignFreeAgentModal-${freeAgentId}`;
  const options = teams.map(team => `<option value="${team.id}">${team.city} ${team.name}</option>`).join('');

  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = modalId;
  modal.innerHTML = `
    <div class="modal-dialog">
      <div class="modal-content bg-dark-panel">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white">Definir destino - ${freeAgent.name}</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-light-gray mb-3">Selecione o time que receberá este jogador.</p>
          <div class="mb-3">
            <label class="form-label text-light-gray">Time</label>
            <select class="form-select bg-dark text-white border-orange" id="${selectId}">
              ${options}
            </select>
          </div>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-orange" onclick="assignFreeAgent(${freeAgentId})">
            <i class="bi bi-check2-circle me-1"></i>Confirmar
          </button>
        </div>
      </div>
    </div>`;

  document.body.appendChild(modal);
  const modalInstance = new bootstrap.Modal(modal);
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
  modalInstance.show();
}

async function assignFreeAgent(freeAgentId) {
  const league = appState.currentFAleague || 'ELITE';
  const select = document.getElementById(`assignTeamSelect-${freeAgentId}`);
  const teamId = parseInt(select?.value || '', 10);
  if (!teamId) {
    alert('Selecione um time válido.');
    return;
  }
  try {
    await api('admin.php?action=free_agent_assign', {
      method: 'POST',
      body: JSON.stringify({ free_agent_id: freeAgentId, team_id: teamId })
    });
    bootstrap.Modal.getInstance(document.getElementById(`assignFreeAgentModal-${freeAgentId}`))?.hide();
    refreshAdminFreeAgency();
  } catch (e) {
    alert(e.error || 'Erro ao definir time');
  }
}

async function loadFreeAgencyOffers(league) {
  appState.currentFAleague = league;
  // Atualizar botões ativos
  document.querySelectorAll('[id^="btn-fa-"]').forEach(btn => btn.classList.remove('active'));
  document.getElementById(`btn-fa-${league}`).classList.add('active');
  
  const container = document.getElementById('faOffersContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
  
  try {
  const data = await api(`admin.php?action=free_agent_offers&league=${league}`);
    const players = data.players || [];
    
    if (players.length === 0) {
      container.innerHTML = `
        <div class="text-center py-5">
          <i class="bi bi-person-x display-1 text-muted"></i>
          <p class="text-light-gray mt-3">Nenhuma proposta pendente na liga ${league}</p>
        </div>
      `;
      return;
    }
    
    container.innerHTML = players.map(item => {
      const player = item.player;
      const offers = item.offers;
      
      return `
        <div class="card mb-4" style="background: var(--fba-panel); border: 1px solid var(--fba-border);">
          <div class="card-header d-flex justify-content-between align-items-center" style="background: rgba(241,117,7,0.1); border-bottom: 1px solid var(--fba-border);">
            <div>
              <h5 class="mb-0 text-white">${player.name}</h5>
              <small class="text-light-gray">
                ${player.position} | OVR ${player.ovr} | ${player.age} anos
                ${player.original_team ? `| Ex: ${player.original_team}` : ''}
              </small>
            </div>
            <span class="badge bg-orange">${offers.length} proposta(s)</span>
          </div>
          <div class="card-body">
            <h6 class="text-orange mb-3">Times interessados:</h6>
            <div class="row g-2">
              ${offers.map(offer => `
                <div class="col-md-6 col-lg-4">
                  <div class="p-3 rounded" style="background: var(--fba-dark); border: 1px solid var(--fba-border);">
                    <div class="d-flex justify-content-between align-items-center">
                      <div>
                        <h6 class="mb-1 text-white">${offer.team_name}</h6>
                        ${offer.notes ? `<small class="text-light-gray">${offer.notes}</small>` : ''}
                      </div>
                      <button class="btn btn-success btn-sm" onclick="approveFreeAgentOffer(${player.id}, ${offer.id}, ${offer.team_id})">
                        <i class="bi bi-check-lg"></i> Aprovar
                      </button>
                    </div>
                  </div>
                </div>
              `).join('')}
            </div>
            <div class="mt-3">
              <button class="btn btn-outline-danger btn-sm" onclick="rejectAllOffers(${player.id}, '${league}')">
                <i class="bi bi-x-lg"></i> Rejeitar Todas as Propostas
              </button>
            </div>
          </div>
        </div>
      `;
    }).join('');
    
  } catch (e) {
    container.innerHTML = `<div class="alert alert-danger">Erro ao carregar propostas: ${e.error || 'Desconhecido'}</div>`;
  }
}

async function approveFreeAgentOffer(playerId, offerId, teamId) {
  if (!confirm('Aprovar esta contratação? O jogador será transferido para o time selecionado.')) return;
  
  try {
    await api('free-agency.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'approve',
        offer_id: offerId
      })
    });
    
    alert('Contratação aprovada com sucesso!');
    refreshAdminFreeAgency();
  } catch (e) {
    alert('Erro ao aprovar: ' + (e.error || 'Desconhecido'));
  }
}

async function rejectAllOffers(playerId, league) {
  if (!confirm('Rejeitar TODAS as propostas para este jogador? Ele continuará disponível na Free Agency.')) return;
  
  try {
    await api('free-agency.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'reject_all',
        free_agent_id: playerId
      })
    });
    
    alert('Todas as propostas foram rejeitadas.');
    refreshAdminFreeAgency();
  } catch (e) {
    alert('Erro ao rejeitar: ' + (e.error || 'Desconhecido'));
  }
}

function openCreateFreeAgentModal() {
  const league = appState.currentFAleague || 'ELITE';
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'createFreeAgentModal';
  modal.innerHTML = `
    <div class="modal-dialog">
      <div class="modal-content bg-dark-panel">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white">Novo Free Agent (${league})</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label text-light-gray">Nome</label>
            <input type="text" class="form-control bg-dark text-white border-orange" id="faName" placeholder="Nome do jogador">
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label text-light-gray">Idade</label>
              <input type="number" class="form-control bg-dark text-white border-orange" id="faAge" min="16" max="45" value="25">
            </div>
            <div class="col-md-6">
              <label class="form-label text-light-gray">OVR</label>
              <input type="number" class="form-control bg-dark text-white border-orange" id="faOvr" min="40" max="99" value="70">
            </div>
          </div>
          <div class="row g-3 mt-1">
            <div class="col-md-6">
              <label class="form-label text-light-gray">Posição</label>
              <select class="form-select bg-dark text-white border-orange" id="faPosition">
                <option value="PG">PG - Armador</option>
                <option value="SG">SG - Ala-Armador</option>
                <option value="SF">SF - Ala</option>
                <option value="PF">PF - Ala-Pivô</option>
                <option value="C">C - Pivô</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label text-light-gray">Posição Secundária</label>
              <select class="form-select bg-dark text-white border-orange" id="faSecondary">
                <option value="">Nenhuma</option>
                <option value="PG">PG - Armador</option>
                <option value="SG">SG - Ala-Armador</option>
                <option value="SF">SF - Ala</option>
                <option value="PF">PF - Ala-Pivô</option>
                <option value="C">C - Pivô</option>
              </select>
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label text-light-gray">Ex time (opcional)</label>
            <input type="text" class="form-control bg-dark text-white border-orange" id="faOriginal" placeholder="Ex: Cidade Time">
          </div>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-orange" onclick="submitCreateFreeAgent()">
            <i class="bi bi-check2-circle me-1"></i>Cadastrar
          </button>
        </div>
      </div>
    </div>`;

  document.body.appendChild(modal);
  const modalInstance = new bootstrap.Modal(modal);
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
  modalInstance.show();
}

async function submitCreateFreeAgent() {
  const league = appState.currentFAleague || 'ELITE';
  const payload = {
    league,
    name: document.getElementById('faName').value.trim(),
    age: parseInt(document.getElementById('faAge').value, 10),
    ovr: parseInt(document.getElementById('faOvr').value, 10),
    position: document.getElementById('faPosition').value,
    secondary_position: document.getElementById('faSecondary').value || null,
    original_team_name: document.getElementById('faOriginal').value.trim()
  };

  if (!payload.name || !payload.age || !payload.ovr || !payload.position) {
    alert('Preencha nome, idade, OVR e posição.');
    return;
  }

  try {
    await api('admin.php?action=free_agent', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    const modalEl = document.getElementById('createFreeAgentModal');
    if (modalEl) {
      bootstrap.Modal.getInstance(modalEl)?.hide();
    }
    alert('Free agent criado!');
    refreshAdminFreeAgency();
  } catch (e) {
    alert('Erro ao criar free agent: ' + (e.error || 'Desconhecido'));
  }
}
