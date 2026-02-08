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

let appState = {
  view: 'home',
  currentLeague: null,
  currentTeam: null,
  teamDetails: null,
  currentFAleague: 'ELITE',
  tradeFilters: { league: 'ALL', status: 'all' }
};
let adminFreeAgents = [];
const freeAgencyTeamsCache = {};

function updateTradeFilter(nextFilters = {}) {
  appState.tradeFilters = {
    ...appState.tradeFilters,
    ...nextFilters
  };
  showTrades(appState.tradeFilters.status || 'all');
}

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
      breadcrumb.innerHTML += '<li class="breadcrumb-item active">Leilões</li>';
      pageTitle.textContent = 'Gerenciar Leilões';
    } else if (appState.view === 'coins') {
      breadcrumb.innerHTML += '<li class="breadcrumb-item active">Moedas</li>';
      pageTitle.textContent = 'Gerenciar Moedas';
    } else if (appState.view === 'userApprovals') {
      breadcrumb.innerHTML += '<li class="breadcrumb-item active">Aprovação de Usuários</li>';
      pageTitle.textContent = 'Aprovar Usuários';
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
<div class="col-md-6"><div class="action-card" onclick="showUserApprovals()"><i class="bi bi-person-check"></i><h4>Aprovar Usuários <span class="badge bg-danger" id="pending-users-count" style="display:none;">0</span></h4><p>Aprovar ou rejeitar novos cadastros</p></div></div>
<div class="col-md-6"><div class="action-card" onclick="showTrades()"><i class="bi bi-arrow-left-right"></i><h4>Trades</h4><p>Gerencie todas as trocas</p></div></div>
<div class="col-md-6"><div class="action-card" onclick="showConfig()"><i class="bi bi-sliders"></i><h4>Configurações</h4><p>Configure CAP e regras das ligas</p></div></div>
<div class="col-md-6"><div class="action-card" onclick="showDirectives()"><i class="bi bi-clipboard-check"></i><h4>Diretrizes</h4><p>Gerencie prazos e visualize diretrizes</p></div></div>
<div class="col-md-6"><div class="action-card" onclick="showSeasonsManagement()"><i class="bi bi-calendar3"></i><h4>Temporadas</h4><p>Inicie temporadas e acompanhe o draft inicial</p></div></div></div>`;
  
  try {
    const data = await api('admin.php?action=leagues');
    (data.leagues || []).forEach(league => {
      const el = document.getElementById(`${league.league.toLowerCase()}-teams`);
      if (el) el.textContent = `${league.team_count} ${league.team_count === 1 ? 'time' : 'times'}`;
    });
  } catch (e) {}
  
  // Carregar contagem de usuários pendentes
  try {
    const approvalData = await api('user-approval.php');
    const pendingCount = (approvalData.users || []).length;
    const badge = document.getElementById('pending-users-count');
    if (badge && pendingCount > 0) {
      badge.textContent = pendingCount;
      badge.style.display = 'inline-block';
    }
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

async function showTrades(status = appState.tradeFilters.status || 'all') {
  appState.view = 'trades';
  appState.tradeFilters.status = status;
  updateBreadcrumb();
  
  const container = document.getElementById('mainContainer');
  const leagueFilter = (appState.tradeFilters.league || 'ALL').toUpperCase();

  const leagueOptions = [
    { value: 'ALL', label: 'Todas as ligas' },
    { value: 'ELITE', label: 'ELITE' },
    { value: 'NEXT', label: 'NEXT' },
    { value: 'RISE', label: 'RISE' },
    { value: 'ROOKIE', label: 'ROOKIE' }
  ];

  container.innerHTML = `<div class="mb-4"><button class="btn btn-back" onclick="showHome()"><i class="bi bi-arrow-left"></i> Voltar</button></div>
<div class="d-flex justify-content-between mb-3 flex-wrap gap-2 align-items-start">
  <div>
    <h4 class="text-white mb-1">Filtrar</h4>
    <div class="d-flex flex-wrap gap-2">
      <select class="form-select form-select-sm bg-dark text-white border-orange" style="min-width: 180px;" onchange="updateTradeFilter({ league: this.value })">
        ${leagueOptions.map(opt => `<option value="${opt.value}" ${opt.value === leagueFilter ? 'selected' : ''}>${opt.label}</option>`).join('')}
      </select>
    </div>
  </div>
  <div class="btn-group flex-wrap">
    <button class="btn btn-outline-orange btn-sm ${status === 'pending' ? 'active' : ''}" onclick="showTrades('pending')">Pendentes</button>
    <button class="btn btn-outline-orange btn-sm ${status === 'accepted' ? 'active' : ''}" onclick="showTrades('accepted')">Aceitas</button>
    <button class="btn btn-outline-orange btn-sm ${status === 'all' ? 'active' : ''}" onclick="showTrades('all')">Todas</button>
  </div>
</div>
<div id="tradesListContainer"><div class="text-center py-4"><div class="spinner-border text-orange"></div></div></div>`;
  
  try {
    let url = 'admin.php?action=trades';
    if (status !== 'all') {
      url += `&status=${status}`;
    }
    if (leagueFilter && leagueFilter !== 'ALL') {
      url += `&league=${encodeURIComponent(leagueFilter)}`;
    }
    const data = await api(url);
    const trades = data.trades || [];
    const tc = document.getElementById('tradesListContainer');
    
    if (trades.length === 0) {
      tc.innerHTML = '<div class="text-center py-5 text-light-gray">Nenhuma trade</div>';
      return;
    }
    
    const formatAdminTradePlayer = (player) => {
      if (!player) return '';
      const name = player.name || 'Jogador (dispensado)';
      const position = player.position || '-';
      const ovr = player.ovr ?? '?';
      const age = player.age ?? '?';
      return `${name} (${position}, ${ovr}/${age})`;
    };

    const renderTradeAssets = (players = [], picks = []) => {
      const playerItems = players.map(p => `<li class="text-white mb-1"><i class="bi bi-person-fill text-orange"></i> ${formatAdminTradePlayer(p)}</li>`).join('');
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
      const acceptedKey = `admin_trade_accept_${tr.id}`;
      const isAccepted = localStorage.getItem(acceptedKey) === '1';
      return `<div class="bg-dark-panel admin-check-card ${isAccepted ? 'is-accepted' : ''} rounded p-3 mb-3" data-trade-id="${tr.id}"><div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
<div><h5 class="text-white mb-1">${tr.from_city} ${tr.from_name} <i class="bi bi-arrow-right text-orange mx-2"></i> ${tr.to_city} ${tr.to_name}</h5>
<small class="text-light-gray">${new Date(tr.created_at).toLocaleString('pt-BR')} | <span class="badge bg-gradient-orange">${tr.from_league}</span></small></div>
<div class="d-flex align-items-center gap-2"><span class="badge ${badge}">${tr.status}</span>
<div class="form-check form-switch m-0">
  <input class="form-check-input" type="checkbox" role="switch" ${isAccepted ? 'checked' : ''} onchange="toggleAdminTradeAccept(${tr.id}, this.checked)">
  <label class="form-check-label text-light-gray">No Game?</label>
</div>
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

function toggleAdminTradeAccept(tradeId, checked) {
  const key = `admin_trade_accept_${tradeId}`;
  if (checked) {
    localStorage.setItem(key, '1');
  } else {
    localStorage.removeItem(key);
  }
  const card = document.querySelector(`[data-trade-id="${tradeId}"]`);
  if (card) {
    card.classList.toggle('is-accepted', checked);
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
<div class="row mb-3">
<div class="col-md-6">
<label class="form-label text-light-gray mb-2">Status das Trocas</label>
<div class="d-flex gap-2">
<button class="btn ${(lg.trades_enabled ?? 1) == 1 ? 'btn-success' : 'btn-outline-success'} flex-grow-1" 
  onclick="toggleTrades('${lg.league}', 1)" id="tradesOnBtn_${lg.league}">
<i class="bi bi-check-circle me-1"></i>Trocas Ativas
</button>
<button class="btn ${(lg.trades_enabled ?? 1) == 0 ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1" 
  onclick="toggleTrades('${lg.league}', 0)" id="tradesOffBtn_${lg.league}">
<i class="bi bi-x-circle me-1"></i>Trocas Bloqueadas
</button>
</div>
<small class="text-light-gray mt-1 d-block">
${(lg.trades_enabled ?? 1) == 1 ? '✅ Usuários podem propor e aceitar trades' : '🚫 Botão de trade desativado para esta liga'}
</small>
</div>
</div>
<div class="row mb-3">
<div class="col-md-6">
<label class="form-label text-light-gray mb-2">Status da Free Agency</label>
<div class="d-flex gap-2">
<button class="btn ${(lg.fa_enabled ?? 1) == 1 ? 'btn-success' : 'btn-outline-success'} flex-grow-1" 
  onclick="toggleFA('${lg.league}', 1)" id="faOnBtn_${lg.league}">
<i class="bi bi-check-circle me-1"></i>FA Ativa
</button>
<button class="btn ${(lg.fa_enabled ?? 1) == 0 ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1" 
  onclick="toggleFA('${lg.league}', 0)" id="faOffBtn_${lg.league}">
<i class="bi bi-x-circle me-1"></i>FA Bloqueada
</button>
</div>
<small class="text-light-gray mt-1 d-block">
${(lg.fa_enabled ?? 1) == 1 ? '✅ Usuários podem enviar propostas na FA' : '🚫 Botão de enviar proposta desativado na FA'}
</small>
</div>
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

function formatDirectiveTimestamp(value) {
  if (!value) return '-';
  try {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';
    return date.toLocaleString('pt-BR');
  } catch (e) {
    return '-';
  }
}

function normalizeDirectiveMinutes(raw) {
  if (!raw) return {};
  if (typeof raw === 'string') {
    try {
      raw = JSON.parse(raw);
    } catch (e) {
      return {};
    }
  }
  if (Array.isArray(raw)) {
    return raw.reduce((acc, row) => {
      if (row && row.player_id) acc[row.player_id] = row.minutes_per_game;
      return acc;
    }, {});
  }
  if (typeof raw === 'object') return raw;
  return {};
}

function normalizeDirectivePlayerInfo(raw) {
  if (!raw) return {};
  if (typeof raw === 'string') {
    try {
      raw = JSON.parse(raw);
    } catch (e) {
      return {};
    }
  }
  if (Array.isArray(raw)) {
    return raw.reduce((acc, row) => {
      if (row && row.player_id) {
        acc[row.player_id] = {
          name: row.player_name || row.name || '?',
          position: row.player_position || row.position || '?'
        };
      }
      return acc;
    }, {});
  }
  if (typeof raw === 'object') return raw;
  return {};
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
  const data = await api(`diretrizes.php?action=view_all_directives_admin&deadline_id=${deadlineId}&league=${encodeURIComponent(league)}&all=1`);
  const directives = Array.isArray(data.directives) ? data.directives.filter(Boolean) : [];
  const fallbackNotice = data.fallback ? '<div class="alert alert-info mb-3">Mostrando diretrizes recentes da liga (prazo sem envios).</div>' : '';
    
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
    const defFocusLabels = {
      'no_preference': 'No Preference', 'neutral': 'Neutral Defensive Focus',
      'protect_paint': 'Protect the Paint', 'limit_perimeter': 'Limit Perimeter Shots'
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
          ${fallbackNotice}
          ${directives.length === 0 ? 
            '<p class="text-light-gray text-center py-4">Nenhuma diretriz enviada ainda</p>' :
            directives.map(d => {
              const updatedAt = d.updated_at || null;
              const submittedAt = d.submitted_at || d.created_at || null;
              const isEdited = !!(updatedAt && submittedAt && new Date(updatedAt).getTime() > new Date(submittedAt).getTime());
              const directiveKey = `admin_directive_accept_${d.id}`;
              const isAccepted = !isEdited && localStorage.getItem(directiveKey) === '1';
              const pm = normalizeDirectiveMinutes(d.player_minutes);
              const playerInfo = normalizeDirectivePlayerInfo(d.player_info);
              const isManualRotation = d.rotation_style === 'manual';
              
              // Coletar IDs dos titulares
              const starterIds = [];
              for (let i = 1; i <= 5; i++) {
                const id = d['starter_' + i + '_id'];
                if (id) starterIds.push(parseInt(id));
              }
              
              const starters = [1,2,3,4,5].map(i => {
                const id = d['starter_' + i + '_id'];
                // Só mostrar minutos se rotação for manual
                const m = isManualRotation && id && pm[id] ? `${pm[id]} min` : '';
                const name = d['starter_' + i + '_name'] || '?';
                const pos = d['starter_' + i + '_pos'] || '?';
                return `<li>${name} (${pos})${m ? ' - ' + m : ''}</li>`;
              }).join('');
              
              // Banco dinâmico: pegar dos player_minutes os que não são titulares
              const benchItems = [];
              Object.keys(pm).forEach(playerId => {
                const id = parseInt(playerId);
                if (!starterIds.includes(id)) {
                  // Usar player_info para pegar nome e posição
                  let name = '?', pos = '?';
                  if (playerInfo[id]) {
                    name = playerInfo[id].name || '?';
                    pos = playerInfo[id].position || '?';
                  } else {
                    // Fallback para bench_X columns (compatibilidade)
                    for (let i = 1; i <= 3; i++) {
                      if (parseInt(d['bench_' + i + '_id']) === id) {
                        name = d['bench_' + i + '_name'] || '?';
                        pos = d['bench_' + i + '_pos'] || '?';
                        break;
                      }
                    }
                  }
                  // Só mostrar minutos se rotação for manual
                  const minLabel = isManualRotation ? ` - ${pm[playerId]} min` : '';
                  benchItems.push(`<li>${name} (${pos})${minLabel}</li>`);
                }
              });
              const bench = benchItems.length > 0 ? benchItems.join('') : '<li class="text-light-gray">Nenhum jogador no banco</li>';
              
              return `
              <div class="card bg-dark mb-3 admin-check-card ${isAccepted ? 'is-accepted' : ''}" data-directive-id="${d.id}">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <div>
                    <h6 class="text-white mb-0">${d.city} ${d.team_name}</h6>
                    <small class="text-light-gray">Enviado em ${formatDirectiveTimestamp(submittedAt || d.submitted_at)}${isEdited ? ' • EDITADO' : ''}</small>
                  </div>
                  <div class="d-flex align-items-center gap-2">
                    ${!isEdited ? `<div class="form-check form-switch m-0">
                      <input class="form-check-input" type="checkbox" role="switch" ${isAccepted ? 'checked' : ''} onchange="toggleAdminDirectiveAccept(${d.id}, this.checked)">
                      <label class="form-check-label text-light-gray">Aceita</label>
                    </div>` : ''}
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteDirective(${d.id}, ${deadlineId}, '${league}')">
                      <i class="bi bi-trash"></i> Excluir
                    </button>
                  </div>
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
                      <h6 class="text-orange">Banco (${benchItems.length} jogadores)</h6>
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
                      <div class="row text-light-gray small mt-2">
                        <div class="col-md-3">Defensive Focus: ${defFocusLabels[d.defensive_focus] || d.defensive_focus || 'No Preference'}</div>
                      </div>
                    </div>
                    ${isManualRotation ? `<div class="col-12 mt-3">
                      <h6 class="text-orange">Rotação e Foco</h6>
                      <div class="row text-light-gray small">
                        <div class="col-md-6">Jogadores na Rotação: ${d.rotation_players || 10}</div>
                        <div class="col-md-6">Foco Veteranos: ${d.veteran_focus || 50}%</div>
                      </div>
                    </div>` : ''}
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
    container.innerHTML = `<div class="alert alert-danger">Erro ao carregar diretrizes: ${e.error || e.message || 'Desconhecido'}</div>`;
  }
}

function toggleAdminDirectiveAccept(directiveId, checked) {
  const key = `admin_directive_accept_${directiveId}`;
  if (checked) {
    localStorage.setItem(key, '1');
  } else {
    localStorage.removeItem(key);
  }
  const card = document.querySelector(`[data-directive-id="${directiveId}"]`);
  if (card) {
    card.classList.toggle('is-accepted', checked);
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
function setFreeAgencyLeague(league) {
  appState.currentFAleague = league;
  // Atualizar botões ativos
  document.querySelectorAll('[id^="btn-fa-"]').forEach(btn => btn.classList.remove('active'));
  const activeBtn = document.getElementById(`btn-fa-${league}`);
  if (activeBtn) activeBtn.classList.add('active');
  // Carregar dados
  loadActiveAuctions();
  loadAdminFreeAgents(league);
  loadFreeAgencyOffers(league);
}

function refreshAdminFreeAgency() {
  const league = appState.currentFAleague || 'ELITE';
  setFreeAgencyLeague(league);
}

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

    <!-- Seção de Leilões Ativos -->
    <div class="row mb-4">
      <div class="col-12">
        <div class="bg-dark-panel border-orange rounded p-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="text-white mb-0"><i class="bi bi-hammer text-orange me-2"></i>Leilões Ativos</h4>
            <button class="btn btn-outline-orange btn-sm" onclick="loadActiveAuctions()">
              <i class="bi bi-arrow-repeat"></i>
            </button>
          </div>
          <div id="activeAuctionsContainer">
            <div class="text-center py-4"><div class="spinner-border text-orange"></div></div>
          </div>
        </div>
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
  loadActiveAuctions();
  
  // Atualizar leilões a cada 30 segundos
  if (window.auctionInterval) clearInterval(window.auctionInterval);
  window.auctionInterval = setInterval(() => {
    if (appState.view === 'freeagency') {
      loadActiveAuctions();
    } else {
      clearInterval(window.auctionInterval);
    }
  }, 30000);
}

// ============================================
// SISTEMA DE LEILÃO - FUNÇÕES
// ============================================

let activeAuctions = [];

async function loadActiveAuctions() {
  const container = document.getElementById('activeAuctionsContainer');
  if (!container) return;
  
  const league = appState.currentFAleague || 'ELITE';
  
  try {
    const data = await api(`free-agency.php?action=active_auctions&league=${league}`);
    activeAuctions = data.auctions || [];
    renderActiveAuctions();
  } catch (e) {
    container.innerHTML = `<div class="alert alert-danger">Erro ao carregar leilões: ${e.error || 'Desconhecido'}</div>`;
  }
}

function renderActiveAuctions() {
  const container = document.getElementById('activeAuctionsContainer');
  if (!container) return;
  
  if (!activeAuctions.length) {
    container.innerHTML = '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Nenhum leilão ativo no momento. Use o botão "Iniciar Leilão" em um jogador para começar.</div>';
    return;
  }
  
  container.innerHTML = `
    <div class="table-responsive">
      <table class="table table-dark table-hover mb-0">
        <thead>
          <tr>
            <th>Jogador</th>
            <th>Pos</th>
            <th>OVR</th>
            <th>Idade</th>
            <th>Lance Atual</th>
            <th>Vencedor</th>
            <th>Tempo</th>
            <th>Status</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
          ${activeAuctions.map(auction => {
            const isActive = auction.status === 'active';
            const secondsRemaining = parseInt(auction.seconds_remaining) || 0;
            const timeDisplay = isActive ? formatAuctionTime(secondsRemaining) : 'Encerrado';
            const timeClass = secondsRemaining <= 60 ? 'text-danger' : (secondsRemaining <= 300 ? 'text-warning' : 'text-success');
            const statusBadge = isActive 
              ? '<span class="badge bg-success"><i class="bi bi-broadcast me-1"></i>Ativo</span>'
              : '<span class="badge bg-secondary">Finalizado</span>';
            
            return `
              <tr>
                <td class="text-white fw-bold">${auction.player_name}</td>
                <td>${auction.player_position}</td>
                <td><span class="badge bg-secondary">${auction.player_ovr}</span></td>
                <td>${auction.player_age}</td>
                <td class="text-orange fw-bold">${auction.current_bid || 0} pts</td>
                <td>${auction.winning_team_name || '<span class="text-muted">-</span>'}</td>
                <td class="${timeClass} fw-bold">${timeDisplay}</td>
                <td>${statusBadge}</td>
                <td>
                  ${isActive ? `
                    <button class="btn btn-sm btn-success me-1" onclick="finalizeAuction(${auction.id})" title="Finalizar">
                      <i class="bi bi-check-lg"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="cancelAuction(${auction.id})" title="Cancelar">
                      <i class="bi bi-x-lg"></i>
                    </button>
                  ` : `
                    <span class="text-muted small">-</span>
                  `}
                </td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function formatAuctionTime(seconds) {
  if (seconds <= 0) return 'Encerrado';
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

async function startAuction(freeAgentId, playerName) {
  const duration = prompt(`Duração do leilão para ${playerName} (em minutos):`, '20');
  if (!duration) return;
  
  const durationInt = parseInt(duration);
  if (isNaN(durationInt) || durationInt < 1 || durationInt > 60) {
    alert('Duração inválida. Use um valor entre 1 e 60 minutos.');
    return;
  }
  
  const league = appState.currentFAleague || 'ELITE';
  
  try {
    const data = await api('free-agency.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'start_auction',
        free_agent_id: freeAgentId,
        duration: durationInt,
        min_bid: 1,
        league: league
      })
    });
    
    alert(data.message || 'Leilão iniciado!');
    loadActiveAuctions();
    loadAdminFreeAgents(league);
  } catch (e) {
    alert(e.error || 'Erro ao iniciar leilão');
  }
}

async function finalizeAuction(auctionId) {
  if (!confirm('Finalizar este leilão agora? O vencedor atual (se houver) receberá o jogador.')) return;
  
  try {
    const data = await api('free-agency.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'finalize_auction',
        auction_id: auctionId
      })
    });
    
    alert(data.message || 'Leilão finalizado!');
    loadActiveAuctions();
    loadAdminFreeAgents(appState.currentFAleague || 'ELITE');
  } catch (e) {
    alert(e.error || 'Erro ao finalizar leilão');
  }
}

async function cancelAuction(auctionId) {
  if (!confirm('Cancelar este leilão? Nenhum jogador será transferido.')) return;
  
  try {
    const data = await api('free-agency.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'cancel_auction',
        auction_id: auctionId
      })
    });
    
    alert(data.message || 'Leilão cancelado!');
    loadActiveAuctions();
  } catch (e) {
    alert(e.error || 'Erro ao cancelar leilão');
  }
}

async function processExpiredAuctions() {
  const league = appState.currentFAleague || 'ELITE';
  
  try {
    const data = await api('free-agency.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'process_expired_auctions',
        league: league
      })
    });
    
    alert(data.message || 'Leilões processados!');
    loadActiveAuctions();
    loadAdminFreeAgents(league);
  } catch (e) {
    alert(e.error || 'Erro ao processar leilões');
  }
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

  // Verificar quais jogadores já tem leilão ativo
  const auctionedPlayerIds = activeAuctions
    .filter(a => a.status === 'active')
    .map(a => parseInt(a.free_agent_id));

  container.innerHTML = filtered.map(fa => {
    const posDisplay = fa.secondary_position ? `${fa.position}/${fa.secondary_position}` : fa.position;
    const origin = fa.original_team_name ? `<small class="text-light-gray d-block">Ex: ${fa.original_team_name}</small>` : '';
    const pending = fa.pending_offers > 0 ? `<small class="text-warning d-block"><i class="bi bi-clock me-1"></i>${fa.pending_offers} proposta(s)</small>` : '';
    const hasActiveAuction = auctionedPlayerIds.includes(fa.id);
    
    return `
      <div class="fa-card mb-3">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <h5 class="text-white mb-1">${fa.name}</h5>
            <div class="text-light-gray small">${posDisplay} | ${fa.age} anos</div>
            ${origin}
            ${pending}
            ${hasActiveAuction ? '<small class="text-success d-block"><i class="bi bi-broadcast me-1"></i>Leilão ativo</small>' : ''}
          </div>
          <div class="text-end">
            <span class="badge bg-secondary">OVR ${fa.ovr}</span>
            <div class="d-flex flex-column gap-2 mt-2">
              ${!hasActiveAuction ? `
                <button class="btn btn-sm btn-orange" onclick="startAuction(${fa.id}, '${fa.name.replace(/'/g, "\\'")}')">
                  <i class="bi bi-hammer"></i> Iniciar Leilão
                </button>
              ` : ''}
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

// ========== MOEDAS ==========
let coinsLeague = 'ELITE';

async function showCoins() {
  appState.view = 'coins';
  updateBreadcrumb();
  
  const container = document.getElementById('mainContainer');
  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="showHome()"><i class="bi bi-arrow-left"></i> Voltar</button>
    </div>
    
    <div class="row mb-4">
      <div class="col-md-8">
        <ul class="nav nav-tabs" id="coinsLeagueTabs">
          <li class="nav-item">
            <button class="nav-link ${coinsLeague === 'ELITE' ? 'active' : ''}" onclick="changeCoinsLeague('ELITE')">ELITE</button>
          </li>
          <li class="nav-item">
            <button class="nav-link ${coinsLeague === 'NEXT' ? 'active' : ''}" onclick="changeCoinsLeague('NEXT')">NEXT</button>
          </li>
          <li class="nav-item">
            <button class="nav-link ${coinsLeague === 'RISE' ? 'active' : ''}" onclick="changeCoinsLeague('RISE')">RISE</button>
          </li>
          <li class="nav-item">
            <button class="nav-link ${coinsLeague === 'ROOKIE' ? 'active' : ''}" onclick="changeCoinsLeague('ROOKIE')">ROOKIE</button>
          </li>
        </ul>
      </div>
      <div class="col-md-4 text-end">
        <button class="btn btn-orange" onclick="openBulkCoinsModal()">
          <i class="bi bi-people-fill me-2"></i>Distribuir para Liga
        </button>
      </div>
    </div>
    
    <div id="coinsContainer">
      <div class="text-center py-5"><div class="spinner-border text-orange"></div></div>
    </div>
    
    <!-- Modal Adicionar Moedas -->
    <div class="modal fade" id="addCoinsModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content bg-dark-panel border-orange">
          <div class="modal-header border-orange">
            <h5 class="modal-title text-white"><i class="bi bi-coin text-orange me-2"></i>Gerenciar Moedas</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="coinsTeamId">
            <div class="mb-3">
              <label class="form-label text-light-gray">Time</label>
              <input type="text" class="form-control bg-dark text-white" id="coinsTeamName" readonly>
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Saldo Atual</label>
              <div class="input-group">
                <span class="input-group-text bg-dark text-orange border-orange"><i class="bi bi-coin"></i></span>
                <input type="text" class="form-control bg-dark text-white" id="coinsCurrentBalance" readonly>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Operação</label>
              <select class="form-select bg-dark text-white border-secondary" id="coinsOperation">
                <option value="add">Adicionar</option>
                <option value="remove">Remover</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Quantidade</label>
              <input type="number" class="form-control bg-dark text-white border-secondary" id="coinsAmount" min="1" value="100">
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Motivo</label>
              <input type="text" class="form-control bg-dark text-white border-secondary" id="coinsReason" placeholder="Ex: Prêmio de temporada">
            </div>
          </div>
          <div class="modal-footer border-orange">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-orange" onclick="submitCoins()">Confirmar</button>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Modal Distribuir em Massa -->
    <div class="modal fade" id="bulkCoinsModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content bg-dark-panel border-orange">
          <div class="modal-header border-orange">
            <h5 class="modal-title text-white"><i class="bi bi-people-fill text-orange me-2"></i>Distribuir Moedas</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-info bg-dark border-orange text-white">
              <i class="bi bi-info-circle me-2"></i>
              Esta ação adicionará moedas para TODOS os times da liga selecionada.
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Liga</label>
              <select class="form-select bg-dark text-white border-secondary" id="bulkCoinsLeague">
                <option value="ELITE">ELITE</option>
                <option value="NEXT">NEXT</option>
                <option value="RISE">RISE</option>
                <option value="ROOKIE">ROOKIE</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Quantidade por Time</label>
              <input type="number" class="form-control bg-dark text-white border-secondary" id="bulkCoinsAmount" min="1" value="100">
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Motivo</label>
              <input type="text" class="form-control bg-dark text-white border-secondary" id="bulkCoinsReason" placeholder="Ex: Início de temporada">
            </div>
          </div>
          <div class="modal-footer border-orange">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-orange" onclick="submitBulkCoins()">Distribuir</button>
          </div>
        </div>
      </div>
    </div>
  `;
  
  loadCoinsTeams();
}

function changeCoinsLeague(league) {
  coinsLeague = league;
  showCoins();
}

async function loadCoinsTeams() {
  const container = document.getElementById('coinsContainer');
  
  try {
    const data = await api(`admin.php?action=coins&league=${coinsLeague}`);
    const teams = data.teams || [];
    
    if (teams.length === 0) {
      container.innerHTML = '<div class="alert alert-info bg-dark border-orange text-white">Nenhum time encontrado nesta liga.</div>';
      return;
    }
    
    const totalCoins = teams.reduce((sum, t) => sum + parseInt(t.moedas), 0);
    
    container.innerHTML = `
      <div class="row mb-3">
        <div class="col-md-6">
          <div class="bg-dark-panel border-orange rounded p-3">
            <h6 class="text-light-gray mb-1">Total de Moedas na Liga</h6>
            <h3 class="text-orange mb-0"><i class="bi bi-coin me-2"></i>${totalCoins.toLocaleString()}</h3>
          </div>
        </div>
        <div class="col-md-6">
          <div class="bg-dark-panel border-orange rounded p-3">
            <h6 class="text-light-gray mb-1">Times</h6>
            <h3 class="text-white mb-0"><i class="bi bi-people-fill me-2 text-orange"></i>${teams.length}</h3>
          </div>
        </div>
      </div>
      
      <div class="table-responsive">
        <table class="table table-dark table-hover">
          <thead>
            <tr>
              <th>Time</th>
              <th>Proprietário</th>
              <th class="text-end">Moedas</th>
              <th class="text-center">Ações</th>
            </tr>
          </thead>
          <tbody>
            ${teams.map(t => `
              <tr>
                <td><strong>${t.city} ${t.name}</strong></td>
                <td class="text-light-gray">${t.owner_name}</td>
                <td class="text-end">
                  <span class="badge ${parseInt(t.moedas) > 0 ? 'bg-success' : 'bg-secondary'} fs-6">
                    <i class="bi bi-coin me-1"></i>${parseInt(t.moedas).toLocaleString()}
                  </span>
                </td>
                <td class="text-center">
                  <button class="btn btn-sm btn-outline-orange me-1" onclick="openCoinsModal(${t.id}, '${t.city} ${t.name}', ${t.moedas})" title="Gerenciar moedas">
                    <i class="bi bi-coin"></i>
                  </button>
                  <button class="btn btn-sm btn-outline-secondary" onclick="showCoinsHistory(${t.id}, '${t.city} ${t.name}')" title="Ver histórico">
                    <i class="bi bi-clock-history"></i>
                  </button>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar times: ' + (e.error || 'Desconhecido') + '</div>';
  }
}

function openCoinsModal(teamId, teamName, currentBalance) {
  document.getElementById('coinsTeamId').value = teamId;
  document.getElementById('coinsTeamName').value = teamName;
  document.getElementById('coinsCurrentBalance').value = parseInt(currentBalance).toLocaleString();
  document.getElementById('coinsOperation').value = 'add';
  document.getElementById('coinsAmount').value = 100;
  document.getElementById('coinsReason').value = '';
  
  new bootstrap.Modal(document.getElementById('addCoinsModal')).show();
}

function openBulkCoinsModal() {
  document.getElementById('bulkCoinsLeague').value = coinsLeague;
  document.getElementById('bulkCoinsAmount').value = 100;
  document.getElementById('bulkCoinsReason').value = '';
  
  new bootstrap.Modal(document.getElementById('bulkCoinsModal')).show();
}

async function submitCoins() {
  const teamId = document.getElementById('coinsTeamId').value;
  const operation = document.getElementById('coinsOperation').value;
  const amount = parseInt(document.getElementById('coinsAmount').value);
  const reason = document.getElementById('coinsReason').value.trim() || 'Ajuste administrativo';
  
  if (!teamId || !amount || amount <= 0) {
    alert('Preencha uma quantidade válida.');
    return;
  }
  
  try {
    const result = await api('admin.php?action=coins', {
      method: 'POST',
      body: JSON.stringify({ team_id: teamId, operation, amount, reason })
    });
    
    bootstrap.Modal.getInstance(document.getElementById('addCoinsModal'))?.hide();
    alert(result.message);
    loadCoinsTeams();
  } catch (e) {
    alert('Erro: ' + (e.error || 'Desconhecido'));
  }
}

async function submitBulkCoins() {
  const league = document.getElementById('bulkCoinsLeague').value;
  const amount = parseInt(document.getElementById('bulkCoinsAmount').value);
  const reason = document.getElementById('bulkCoinsReason').value.trim() || 'Distribuição de moedas';
  
  if (!amount || amount <= 0) {
    alert('Preencha uma quantidade válida.');
    return;
  }
  
  if (!confirm(`Tem certeza que deseja adicionar ${amount} moedas para TODOS os times da liga ${league}?`)) {
    return;
  }
  
  try {
    const result = await api('admin.php?action=coins_bulk', {
      method: 'POST',
      body: JSON.stringify({ league, amount, reason })
    });
    
    bootstrap.Modal.getInstance(document.getElementById('bulkCoinsModal'))?.hide();
    alert(result.message);
    
    // Atualizar para a liga que foi distribuída
    coinsLeague = league;
    showCoins();
  } catch (e) {
    alert('Erro: ' + (e.error || 'Desconhecido'));
  }
}

async function showCoinsHistory(teamId, teamName) {
  const container = document.getElementById('coinsContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const data = await api(`admin.php?action=coins_log&team_id=${teamId}`);
    const logs = data.logs || [];
    
    let html = `
      <div class="mb-3">
        <button class="btn btn-back" onclick="loadCoinsTeams()"><i class="bi bi-arrow-left"></i> Voltar para lista</button>
      </div>
      <div class="bg-dark-panel border-orange rounded p-3 mb-3">
        <h5 class="text-white mb-0"><i class="bi bi-clock-history text-orange me-2"></i>Histórico de Moedas: ${teamName}</h5>
      </div>
    `;
    
    if (logs.length === 0) {
      html += '<div class="alert alert-info bg-dark border-orange text-white">Nenhum histórico encontrado.</div>';
    } else {
      html += `
        <div class="table-responsive">
          <table class="table table-dark table-hover">
            <thead>
              <tr>
                <th>Data</th>
                <th>Tipo</th>
                <th class="text-end">Alteração</th>
                <th class="text-end">Saldo</th>
                <th>Motivo</th>
              </tr>
            </thead>
            <tbody>
              ${logs.map(log => {
                const date = new Date(log.created_at);
                const dateStr = date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
                const typeLabels = {
                  'admin_add': '<span class="badge bg-success">Adição Admin</span>',
                  'admin_remove': '<span class="badge bg-danger">Remoção Admin</span>',
                  'admin_bulk': '<span class="badge bg-info">Distribuição</span>',
                  'fa_bid': '<span class="badge bg-warning text-dark">Lance FA</span>',
                  'fa_win': '<span class="badge bg-primary">Vitória FA</span>',
                  'fa_refund': '<span class="badge bg-secondary">Reembolso FA</span>'
                };
                return `
                  <tr>
                    <td class="text-light-gray">${dateStr}</td>
                    <td>${typeLabels[log.type] || log.type}</td>
                    <td class="text-end">
                      <span class="${parseInt(log.amount) >= 0 ? 'text-success' : 'text-danger'}">
                        ${parseInt(log.amount) >= 0 ? '+' : ''}${parseInt(log.amount).toLocaleString()}
                      </span>
                    </td>
                    <td class="text-end">${parseInt(log.balance_after).toLocaleString()}</td>
                    <td class="text-light-gray">${log.reason || '-'}</td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>
      `;
    }
    
    container.innerHTML = html;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar histórico: ' + (e.error || 'Desconhecido') + '</div>';
  }
}

// ========================================
// APROVAÇÃO DE USUÁRIOS
// ========================================

async function showUserApprovals() {
  appState.view = 'userApprovals';
  updateBreadcrumb();
  
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-orange" role="status"></div></div>';
  
  try {
    const data = await api('user-approval.php');
    const users = data.users || [];
    
    let html = `
      <div class="row">
        <div class="col-12">
          <h2 class="text-white mb-4">
            <i class="bi bi-person-check text-orange me-2"></i>
            Aprovação de Usuários
          </h2>
        </div>
      </div>
    `;
    
    if (users.length === 0) {
      html += `
        <div class="alert alert-info">
          <i class="bi bi-info-circle me-2"></i>
          Não há usuários aguardando aprovação.
        </div>
      `;
    } else {
      html += `
        <div class="row g-4">
          ${users.map(user => {
            const createdDate = new Date(user.created_at);
            const dateStr = createdDate.toLocaleDateString('pt-BR') + ' ' + 
                          createdDate.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
            
            return `
              <div class="col-md-6 col-lg-4">
                <div class="card bg-dark-panel border-orange h-100">
                  <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                      <div class="bg-gradient-orange rounded-circle d-flex align-items-center justify-content-center" 
                           style="width: 50px; height: 50px; min-width: 50px;">
                        <i class="bi bi-person-fill text-white fs-4"></i>
                      </div>
                      <div class="ms-3 flex-grow-1">
                        <h5 class="text-white mb-1">${user.username}</h5>
                        <p class="text-light-gray mb-0 small">
                          <i class="bi bi-clock me-1"></i>${dateStr}
                        </p>
                      </div>
                    </div>
                    
                    <div class="mb-3">
                      <p class="text-light-gray mb-1 small">
                        <i class="bi bi-envelope me-2"></i>${user.email}
                      </p>
                    </div>
                    
                    <div class="d-flex gap-2">
                      <button class="btn btn-success flex-fill" onclick="approveUser(${user.id}, '${user.username}')">
                        <i class="bi bi-check-circle me-1"></i>Aprovar
                      </button>
                      <button class="btn btn-danger flex-fill" onclick="rejectUser(${user.id}, '${user.username}')">
                        <i class="bi bi-x-circle me-1"></i>Rejeitar
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      `;
    }
    
    container.innerHTML = html;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar usuários: ' + (e.error || 'Desconhecido') + '</div>';
  }
}

async function toggleTrades(league, enabled) {
  try {
    await api('admin.php?action=league_settings', {
      method: 'PUT',
      body: JSON.stringify({
        league: league,
        trades_enabled: enabled
      })
    });
    
    // Atualiza os botões visualmente
    const onBtn = document.getElementById(`tradesOnBtn_${league}`);
    const offBtn = document.getElementById(`tradesOffBtn_${league}`);
    
    if (enabled == 1) {
      onBtn.className = 'btn btn-success flex-grow-1';
      offBtn.className = 'btn btn-outline-danger flex-grow-1';
    } else {
      onBtn.className = 'btn btn-outline-success flex-grow-1';
      offBtn.className = 'btn btn-danger flex-grow-1';
    }
    
    showAlert('success', `Trocas ${enabled == 1 ? 'ativadas' : 'desativadas'} para a liga ${league}!`);
  } catch (e) {
    showAlert('danger', 'Erro ao atualizar status de trades');
  }
}

async function toggleFA(league, enabled) {
  try {
    await api('admin.php?action=league_settings', {
      method: 'PUT',
      body: JSON.stringify({
        league: league,
        fa_enabled: enabled
      })
    });

    const onBtn = document.getElementById(`faOnBtn_${league}`);
    const offBtn = document.getElementById(`faOffBtn_${league}`);

    if (enabled == 1) {
      onBtn.className = 'btn btn-success flex-grow-1';
      offBtn.className = 'btn btn-outline-danger flex-grow-1';
    } else {
      onBtn.className = 'btn btn-outline-success flex-grow-1';
      offBtn.className = 'btn btn-danger flex-grow-1';
    }

    showAlert('success', `Free Agency ${enabled == 1 ? 'ativada' : 'desativada'} para a liga ${league}!`);
  } catch (e) {
    showAlert('danger', 'Erro ao atualizar status da Free Agency');
  }
}

async function approveUser(userId, username) {
  if (!confirm(`Deseja aprovar o usuário "${username}"?`)) return;
  
  try {
    const result = await api('user-approval.php', {
      method: 'PUT',
      body: JSON.stringify({
        user_id: userId,
        action: 'approve'
      })
    });
    
    if (result.success) {
      showAlert('success', `Usuário "${username}" aprovado com sucesso!`);
      await showUserApprovals(); // Recarrega a lista
      updatePendingUsersCount(); // Atualiza o badge no home
    }
  } catch (e) {
    showAlert('danger', 'Erro ao aprovar usuário: ' + (e.error || 'Desconhecido'));
  }
}

async function rejectUser(userId, username) {
  if (!confirm(`Deseja REJEITAR e EXCLUIR o usuário "${username}"?\n\nEsta ação não pode ser desfeita!`)) return;
  
  try {
    const result = await api('user-approval.php', {
      method: 'PUT',
      body: JSON.stringify({
        user_id: userId,
        action: 'reject'
      })
    });
    
    if (result.success) {
      showAlert('success', `Usuário "${username}" rejeitado e removido.`);
      await showUserApprovals(); // Recarrega a lista
      updatePendingUsersCount(); // Atualiza o badge no home
    }
  } catch (e) {
    showAlert('danger', 'Erro ao rejeitar usuário: ' + (e.error || 'Desconhecido'));
  }
}

async function updatePendingUsersCount() {
  try {
    const approvalData = await api('user-approval.php');
    const pendingCount = (approvalData.users || []).length;
    const badge = document.getElementById('pending-users-count');
    if (badge) {
      if (pendingCount > 0) {
        badge.textContent = pendingCount;
        badge.style.display = 'inline-block';
      } else {
        badge.style.display = 'none';
      }
    }
  } catch (e) {
    console.error('Erro ao atualizar contagem de usuários pendentes:', e);
  }
}


