const api = async (path, options = {}) => {
  const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
  let body = {};
  try { body = await res.json(); } catch {}
  if (!res.ok) throw body;
  return body;
};

let appState = { view: 'home', currentLeague: null, currentTeam: null, teamDetails: null };

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
<div class="col-md-6"><div class="action-card" onclick="showConfig()"><i class="bi bi-sliders"></i><h4>Configurações</h4><p>Configure CAP e regras das ligas</p></div></div></div>`;
  
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
    
    tc.innerHTML = trades.map(tr => {
      const badge = { pending: 'bg-warning text-dark', accepted: 'bg-success', rejected: 'bg-danger', cancelled: 'bg-secondary' }[tr.status];
      return `<div class="bg-dark-panel border-orange rounded p-3 mb-3"><div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
<div><h5 class="text-white mb-1">${tr.from_city} ${tr.from_name} <i class="bi bi-arrow-right text-orange mx-2"></i> ${tr.to_city} ${tr.to_name}</h5>
<small class="text-light-gray">${new Date(tr.created_at).toLocaleString('pt-BR')} | <span class="badge bg-gradient-orange">${tr.from_league}</span></small></div>
<div><span class="badge ${badge}">${tr.status}</span>
${tr.status === 'pending' ? `<button class="btn btn-sm btn-outline-danger ms-2" onclick="cancelTrade(${tr.id})">Cancelar</button>` : ''}
${tr.status === 'accepted' ? `<button class="btn btn-sm btn-outline-warning ms-2" onclick="revertTrade(${tr.id})">Reverter</button>` : ''}</div></div>
<div class="row"><div class="col-md-6"><h6 class="text-orange mb-2">${tr.from_city} ${tr.from_name} oferece:</h6>
${tr.offer_players.length > 0 ? `<ul class="list-unstyled">${tr.offer_players.map(p => `<li class="text-white mb-1"><i class="bi bi-person-fill text-orange"></i> ${p.name} (${p.position}, ${p.ovr})</li>`).join('')}</ul>` : '<p class="text-light-gray">Nada</p>'}</div>
<div class="col-md-6"><h6 class="text-orange mb-2">${tr.to_city} ${tr.to_name} oferece:</h6>
${tr.request_players.length > 0 ? `<ul class="list-unstyled">${tr.request_players.map(p => `<li class="text-white mb-1"><i class="bi bi-person-fill text-orange"></i> ${p.name} (${p.position}, ${p.ovr})</li>`).join('')}</ul>` : '<p class="text-light-gray">Nada</p>'}</div></div></div>`;
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
<div class="col-12"><label class="form-label text-light-gray mb-1">Edital da Liga (Regras, informações gerais)</label>
<textarea class="form-control bg-dark text-white border-orange" rows="4" data-league="${lg.league}" data-field="edital" placeholder="Digite as regras e informações gerais desta liga...">${lg.edital || ''}</textarea></div>
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
    data.teams.forEach(t => {
      if (t.id != appState.currentTeam.id) {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = `${t.city} ${t.name} (${t.league})`;
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
<input type="text" class="form-control bg-dark text-white border-orange" id="addPlayerPosition" placeholder="PG, SG, SF, PF, C"></div>
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Idade</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="addPlayerAge" value="25" min="18" max="45"></div>
</div>
<div class="row">
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">OVR</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="addPlayerOvr" value="70" min="0" max="99"></div>
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Papel</label>
<select class="form-select bg-dark text-white border-orange" id="addPlayerRole">
<option value="Titular">Titular</option>
<option value="Banco" selected>Banco</option>
<option value="Outro">Outro</option>
<option value="G-League">G-League</option></select></div>
</div></div>
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
    position: document.getElementById('addPlayerPosition').value.trim(),
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

document.addEventListener('DOMContentLoaded', init);
