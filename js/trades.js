const api = async (path, options = {}) => {
  const res = await fetch(`/api/${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });
  let body = {};
  try { body = await res.json(); } catch {}
  if (!res.ok) throw body;
  return body;
};

let myTeamId = window.__TEAM_ID__;
let myLeague = window.__USER_LEAGUE__;
let allTeams = [];
let myPlayers = [];
let myPicks = [];

const formatTradePlayerDisplay = (player) => {
  if (!player) return '';
  const name = player.name || 'Jogador';
  const position = player.position || '-';
  const ovr = player.ovr ?? '?';
  const age = player.age ?? '?';
  return `${name} (${position}, ${ovr}/${age})`;
};

function clearCounterProposalState() {
  const modalEl = document.getElementById('proposeTradeModal');
  if (modalEl && modalEl.dataset.counterTo) {
    delete modalEl.dataset.counterTo;
  }
  const targetSelect = document.getElementById('targetTeam');
  if (targetSelect) {
    targetSelect.disabled = false;
  }
  
  // Limpar todas as seleções visuais
  document.querySelectorAll('.trade-selectable-item.selected').forEach(item => {
    item.classList.remove('selected');
    const checkIcon = item.querySelector('.trade-item-check i');
    if (checkIcon) checkIcon.style.display = 'none';
  });
  
  // Limpar selects escondidos
  ['offerPlayers', 'offerPicks', 'requestPlayers', 'requestPicks'].forEach(id => {
    const select = document.getElementById(id);
    if (select) {
      Array.from(select.options).forEach(opt => opt.selected = false);
    }
  });
}

async function init() {
  if (!myTeamId) return;
  
  // Carregar times da liga
  await loadTeams();
  
  // Carregar meus jogadores e picks
  await loadMyAssets();
  
  // Carregar trades
  loadTrades('received');
  loadTrades('sent');
  loadTrades('history');
  loadTrades('league');
  
  // Event listeners
  document.getElementById('submitTradeBtn').addEventListener('click', submitTrade);
  document.getElementById('targetTeam').addEventListener('change', onTargetTeamChange);

  const tradeModalEl = document.getElementById('proposeTradeModal');
  if (tradeModalEl) {
    tradeModalEl.addEventListener('hidden.bs.modal', () => {
      clearCounterProposalState();
    });
  }

  // Verificar se há jogador pré-selecionado na URL
  const urlParams = new URLSearchParams(window.location.search);
  const preselectedPlayerId = urlParams.get('player');
  const preselectedTeamId = urlParams.get('team');
  
  if (preselectedPlayerId && preselectedTeamId) {
    // Abrir modal automaticamente com o jogador e time pré-selecionado
    setTimeout(async () => {
      await openTradeWithPreselectedPlayer(preselectedPlayerId, preselectedTeamId);
    }, 500);
  }
}

async function openTradeWithPreselectedPlayer(playerId, teamId) {
  try {
    // Selecionar o time
    const targetTeamSelect = document.getElementById('targetTeam');
    targetTeamSelect.value = teamId;
    
    // Carregar jogadores do time alvo
    await onTargetTeamChange({ target: targetTeamSelect });
    
    // Aguardar um pouco para garantir que os cards foram renderizados
    setTimeout(() => {
      // Encontrar e selecionar o card do jogador
      const requestPlayersContainer = document.getElementById('requestPlayersContainer');
      const playerCard = requestPlayersContainer.querySelector(`.trade-selectable-item[data-id="${playerId}"]`);
      
      if (playerCard && !playerCard.classList.contains('unavailable')) {
        // Simular clique no card
        playerCard.click();
        
        // Scroll até o jogador
        playerCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        
        // Adicionar efeito visual temporário
        playerCard.style.animation = 'pulse 0.5s ease 3';
      }
      
      // Abrir o modal
      const modal = new bootstrap.Modal(document.getElementById('proposeTradeModal'));
      modal.show();
      
      // Adicionar nota sugerindo a trade
      document.getElementById('tradeNotes').value = 'Olá! Tenho interesse neste jogador. Vamos negociar?';
      
      // Focar no container de jogadores oferecidos para facilitar a seleção
      setTimeout(() => {
        const offerPlayersContainer = document.getElementById('offerPlayersContainer');
        if (offerPlayersContainer) {
          offerPlayersContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }, 500);
    }, 400);
  } catch (err) {
    console.error('Erro ao pré-selecionar jogador:', err);
    alert('Erro ao preparar a proposta de trade. Por favor, tente novamente.');
  }
}

async function loadTeams() {
  try {
    const data = await api('teams.php');
    allTeams = data.teams || [];
    
    console.log('Times carregados:', allTeams.length, 'Meu time:', myTeamId, 'Minha liga:', myLeague);
    
    // Preencher select de times (exceto o meu, apenas da mesma liga)
    const select = document.getElementById('targetTeam');
    select.innerHTML = '<option value="">Selecione...</option>';
    
    const filteredTeams = allTeams.filter(t => {
      const teamId = parseInt(t.id);
      const myId = parseInt(myTeamId);
      return teamId !== myId && t.league === myLeague;
    });
    
    console.log('Times filtrados para trade:', filteredTeams.length);
    
    filteredTeams.forEach(t => {
      const option = document.createElement('option');
      option.value = t.id;
      option.textContent = `${t.city} ${t.name}`;
      select.appendChild(option);
    });
  } catch (err) {
    console.error('Erro ao carregar times:', err);
  }
}

async function loadMyAssets() {
  try {
    // Meus jogadores disponíveis para troca
    const playersData = await api(`players.php?team_id=${myTeamId}`);
    myPlayers = playersData.players || [];
    
    console.log('Meus jogadores carregados:', myPlayers.length);
    
    // Renderizar jogadores como cards clicáveis
    renderSelectablePlayers('offer', myPlayers);
    
    // Minhas picks
    const picksData = await api(`picks.php?team_id=${myTeamId}`);
    myPicks = picksData.picks || [];
    
    console.log('Minhas picks:', myPicks.length);
    
    // Renderizar picks como cards clicáveis
    renderSelectablePicks('offer', myPicks);
  } catch (err) {
    console.error('Erro ao carregar assets:', err);
  }
}

function renderSelectablePlayers(type, players) {
  const containerId = type === 'offer' ? 'offerPlayersContainer' : 'requestPlayersContainer';
  const container = document.getElementById(containerId);
  const countBadge = document.getElementById(`${type}PlayersCount`);
  
  if (!container) return;
  
  container.innerHTML = '';
  
  if (players.length === 0) {
    container.innerHTML = `
      <div class="text-center text-muted py-4">
        <i class="bi bi-inbox mb-2" style="font-size: 2rem;"></i>
        <p class="mb-0">Nenhum jogador disponível</p>
      </div>
    `;
    if (countBadge) countBadge.textContent = '0';
    return;
  }
  
  if (countBadge) countBadge.textContent = players.length;
  
  players.forEach(player => {
    const isAvailable = player.available_for_trade;
    const itemDiv = document.createElement('div');
    itemDiv.className = `trade-selectable-item ${!isAvailable ? 'unavailable' : ''}`;
    itemDiv.dataset.id = player.id;
    itemDiv.dataset.type = 'player';
    
    itemDiv.innerHTML = `
      <div class="trade-item-check">
        <i class="bi bi-check" style="display: none;"></i>
      </div>
      <div class="trade-item-icon-small">
        <i class="bi bi-person-fill"></i>
      </div>
      <div class="trade-item-info">
        <div class="trade-item-name-small">${player.name || 'Jogador'}</div>
        <div class="trade-item-meta-small">
          <span class="badge bg-dark">${player.position || '-'}</span>
          <span>${player.age ?? '?'} anos</span>
          ${!isAvailable ? '<span class="badge bg-warning text-dark">Bloqueado</span>' : ''}
        </div>
      </div>
      <div class="trade-item-ovr-small">${player.ovr ?? '?'}</div>
    `;
    
    if (isAvailable) {
      itemDiv.addEventListener('click', () => toggleTradeItem(itemDiv, type, 'player'));
    }
    
    container.appendChild(itemDiv);
  });
}

function renderSelectablePicks(type, picks) {
  const containerId = type === 'offer' ? 'offerPicksContainer' : 'requestPicksContainer';
  const container = document.getElementById(containerId);
  const countBadge = document.getElementById(`${type}PicksCount`);
  
  if (!container) return;
  
  container.innerHTML = '';
  
  if (picks.length === 0) {
    container.innerHTML = `
      <div class="text-center text-muted py-4">
        <i class="bi bi-inbox mb-2" style="font-size: 2rem;"></i>
        <p class="mb-0">Nenhuma pick disponível</p>
      </div>
    `;
    if (countBadge) countBadge.textContent = '0';
    return;
  }
  
  if (countBadge) countBadge.textContent = picks.length;
  
  picks.forEach(pick => {
    const itemDiv = document.createElement('div');
    itemDiv.className = 'trade-selectable-item';
    itemDiv.dataset.id = pick.id;
    itemDiv.dataset.type = 'pick';
    
    const originLabel = pick.last_owner_city && pick.last_owner_name
      ? `${pick.last_owner_city} ${pick.last_owner_name}`
      : (pick.original_team_name || 'Time');
    
    itemDiv.innerHTML = `
      <div class="trade-item-check">
        <i class="bi bi-check" style="display: none;"></i>
      </div>
      <div class="trade-item-icon-small pick">
        <i class="bi bi-trophy-fill"></i>
      </div>
      <div class="trade-item-info">
        <div class="trade-item-name-small">Pick ${pick.season_year}</div>
        <div class="trade-item-meta-small">
          <span class="badge bg-warning text-dark">${pick.round}ª Rodada</span>
          <span class="text-muted small">de ${originLabel}</span>
        </div>
      </div>
    `;
    
    itemDiv.addEventListener('click', () => toggleTradeItem(itemDiv, type, 'pick'));
    
    container.appendChild(itemDiv);
  });
}

function toggleTradeItem(itemDiv, type, itemType) {
  if (itemDiv.classList.contains('unavailable')) return;
  
  const isSelected = itemDiv.classList.contains('selected');
  const itemId = itemDiv.dataset.id;
  const checkIcon = itemDiv.querySelector('.trade-item-check i');
  
  if (isSelected) {
    itemDiv.classList.remove('selected');
    checkIcon.style.display = 'none';
    
    // Remover do select escondido
    const selectId = type === 'offer' 
      ? (itemType === 'player' ? 'offerPlayers' : 'offerPicks')
      : (itemType === 'player' ? 'requestPlayers' : 'requestPicks');
    const select = document.getElementById(selectId);
    const option = select.querySelector(`option[value="${itemId}"]`);
    if (option) option.selected = false;
  } else {
    itemDiv.classList.add('selected');
    checkIcon.style.display = 'block';
    
    // Adicionar ao select escondido
    const selectId = type === 'offer' 
      ? (itemType === 'player' ? 'offerPlayers' : 'offerPicks')
      : (itemType === 'player' ? 'requestPlayers' : 'requestPicks');
    const select = document.getElementById(selectId);
    const option = select.querySelector(`option[value="${itemId}"]`);
    if (option) option.selected = true;
  }
}

async function onTargetTeamChange(e) {
  const teamId = e.target.value;
  if (!teamId) {
    // Limpar containers
    const requestPlayersContainer = document.getElementById('requestPlayersContainer');
    const requestPicksContainer = document.getElementById('requestPicksContainer');
    
    if (requestPlayersContainer) {
      requestPlayersContainer.innerHTML = `
        <div class="text-center text-muted py-4">
          <i class="bi bi-inbox mb-2" style="font-size: 2rem;"></i>
          <p class="mb-0">Selecione um time primeiro</p>
        </div>
      `;
    }
    
    if (requestPicksContainer) {
      requestPicksContainer.innerHTML = `
        <div class="text-center text-muted py-4">
          <i class="bi bi-inbox mb-2" style="font-size: 2rem;"></i>
          <p class="mb-0">Selecione um time primeiro</p>
        </div>
      `;
    }
    
    return;
  }
  
  try {
    // Carregar jogadores do time alvo
    const playersData = await api(`players.php?team_id=${teamId}`);
    const players = playersData.players || [];
    
    console.log('Jogadores do time alvo carregados:', players.length);
    
    // Renderizar jogadores
    renderSelectablePlayers('request', players);
    
    // Popular select escondido para compatibilidade
    const selectPlayers = document.getElementById('requestPlayers');
    selectPlayers.innerHTML = '';
    players.forEach(p => {
      const option = document.createElement('option');
      option.value = p.id;
      option.textContent = formatTradePlayerDisplay(p);
      selectPlayers.appendChild(option);
    });
    
    // Carregar picks do time alvo
    const picksData = await api(`picks.php?team_id=${teamId}`);
    const picks = picksData.picks || [];
    
    console.log('Picks do time alvo:', picks.length);
    
    // Renderizar picks
    renderSelectablePicks('request', picks);
    
    // Popular select escondido para compatibilidade
    const selectPicks = document.getElementById('requestPicks');
    selectPicks.innerHTML = '';
    picks.forEach(p => {
      const option = document.createElement('option');
      option.value = p.id;
      const originLabel = p.last_owner_city && p.last_owner_name
        ? `${p.last_owner_city} ${p.last_owner_name}`
        : (p.original_team_name || 'Time');
      option.textContent = `${p.season_year} - ${p.round}ª rodada (de ${originLabel})`;
      selectPicks.appendChild(option);
    });
  } catch (err) {
    console.error('Erro ao carregar assets do time:', err);
  }
}

async function submitTrade() {
  const targetTeam = document.getElementById('targetTeam').value;
  const offerPlayers = Array.from(document.getElementById('offerPlayers').selectedOptions).map(o => o.value);
  const offerPicks = Array.from(document.getElementById('offerPicks').selectedOptions).map(o => o.value);
  const requestPlayers = Array.from(document.getElementById('requestPlayers').selectedOptions).map(o => o.value);
  const requestPicks = Array.from(document.getElementById('requestPicks').selectedOptions).map(o => o.value);
  const notes = document.getElementById('tradeNotes').value;
  const modalEl = document.getElementById('proposeTradeModal');
  const counterTo = modalEl && modalEl.dataset.counterTo ? parseInt(modalEl.dataset.counterTo, 10) : null;
  
  if (!targetTeam) {
    return alert('Selecione um time.');
  }
  
  if (offerPlayers.length === 0 && offerPicks.length === 0) {
    return alert('Você precisa oferecer algo (jogadores ou picks).');
  }
  
  if (requestPlayers.length === 0 && requestPicks.length === 0) {
    return alert('Você precisa pedir algo em troca (jogadores ou picks).');
  }
  
  try {
    const payload = {
      to_team_id: parseInt(targetTeam),
      offer_players: offerPlayers.map(id => parseInt(id)),
      offer_picks: offerPicks.map(id => parseInt(id)),
      request_players: requestPlayers.map(id => parseInt(id)),
      request_picks: requestPicks.map(id => parseInt(id)),
      notes
    };
    if (counterTo) {
      payload.counter_to_trade_id = counterTo;
    }

    await api('trades.php', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    
    alert('Proposta de trade enviada!');
    bootstrap.Modal.getInstance(document.getElementById('proposeTradeModal')).hide();
    clearCounterProposalState();
    document.getElementById('proposeTradeForm').reset();
    loadTrades('sent');
    loadTrades('received');
    loadTrades('history');
    loadTrades('league');
  } catch (err) {
    alert(err.error || 'Erro ao enviar trade');
  }
}

async function loadTrades(type) {
  const container = document.getElementById(`${type}TradesList`);
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const data = await api(`trades.php?type=${type}`);
    const trades = data.trades || [];
    
    if (trades.length === 0) {
      container.innerHTML = '<div class="text-center text-light-gray py-4">Nenhuma trade encontrada</div>';
      if (type === 'league') {
        const badge = document.getElementById('leagueTradesCount');
        if (badge) badge.textContent = '0 trocas';
      }
      return;
    }
    
    container.innerHTML = '';
    trades.forEach(trade => {
      const card = createTradeCard(trade, type);
      container.appendChild(card);
    });

    if (type === 'league') {
      const badge = document.getElementById('leagueTradesCount');
      if (badge) {
        badge.textContent = `${trades.length} ${trades.length === 1 ? 'trade' : 'trocas'}`;
      }
    }
  } catch (err) {
    container.innerHTML = '<div class="text-center text-danger py-4">Erro ao carregar trades</div>';
  }
}

function createTradeCard(trade, type) {
  const card = document.createElement('div');
  card.className = 'trade-card-modern mb-4';
  
  const statusBadge = {
    'pending': '<span class="badge bg-warning text-dark px-3 py-2"><i class="bi bi-clock-fill me-1"></i>Pendente</span>',
    'accepted': '<span class="badge bg-success px-3 py-2"><i class="bi bi-check-circle-fill me-1"></i>Aceita</span>',
    'rejected': '<span class="badge bg-danger px-3 py-2"><i class="bi bi-x-circle-fill me-1"></i>Rejeitada</span>',
    'cancelled': '<span class="badge bg-secondary px-3 py-2"><i class="bi bi-dash-circle-fill me-1"></i>Cancelada</span>',
    'countered': '<span class="badge bg-info px-3 py-2"><i class="bi bi-arrow-repeat me-1"></i>Contraproposta</span>'
  }[trade.status];
  
  const fromTeam = `${trade.from_city} ${trade.from_name}`;
  const toTeam = `${trade.to_city} ${trade.to_name}`;
  
  // Verificar se tem observação de resposta
  const responseNotes = trade.response_notes ? `
    <div class="trade-response-note">
      <div class="d-flex align-items-center gap-2 mb-2">
        <i class="bi bi-chat-dots-fill text-warning"></i>
        <strong class="text-warning">Resposta do GM:</strong>
      </div>
      <p class="mb-0 text-light-gray">${trade.response_notes}</p>
    </div>
  ` : '';
  
  // Criar HTML dos itens com visual melhorado
  const renderPlayerItem = (p) => `
    <div class="trade-item-card">
      <div class="d-flex align-items-center gap-3">
        <div class="trade-item-icon">
          <i class="bi bi-person-fill"></i>
        </div>
        <div class="flex-grow-1">
          <div class="trade-item-name">${p.name || 'Jogador'}</div>
          <div class="trade-item-meta">
            <span class="badge bg-dark">${p.position || '-'}</span>
            <span class="text-muted">OVR ${p.ovr ?? '?'}</span>
            <span class="text-muted">${p.age ?? '?'} anos</span>
          </div>
        </div>
        <div class="trade-item-ovr">${p.ovr ?? '?'}</div>
      </div>
    </div>
  `;
  
  const renderPickItem = (p) => `
    <div class="trade-item-card">
      <div class="d-flex align-items-center gap-3">
        <div class="trade-item-icon pick-icon">
          <i class="bi bi-trophy-fill"></i>
        </div>
        <div class="flex-grow-1">
          <div class="trade-item-name">Pick ${p.season_year}</div>
          <div class="trade-item-meta">
            <span class="badge bg-warning text-dark">${p.round}ª Rodada</span>
          </div>
        </div>
      </div>
    </div>
  `;
  
  const offerPlayersHTML = trade.offer_players.map(renderPlayerItem).join('');
  const offerPicksHTML = trade.offer_picks.map(renderPickItem).join('');
  const requestPlayersHTML = trade.request_players.map(renderPlayerItem).join('');
  const requestPicksHTML = trade.request_picks.map(renderPickItem).join('');
  
  const hasOfferItems = trade.offer_players.length > 0 || trade.offer_picks.length > 0;
  const hasRequestItems = trade.request_players.length > 0 || trade.request_picks.length > 0;
  
  card.innerHTML = `
    <div class="trade-card-header">
      <div class="trade-card-teams">
        <div class="trade-team">
          <div class="trade-team-name">${fromTeam}</div>
          <small class="text-muted">${new Date(trade.created_at).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' })}</small>
        </div>
        <div class="trade-arrow">
          <i class="bi bi-arrow-left-right"></i>
        </div>
        <div class="trade-team text-end">
          <div class="trade-team-name">${toTeam}</div>
          <small class="text-muted">Trade #${trade.id}</small>
        </div>
      </div>
      <div class="mt-3">
        ${statusBadge}
      </div>
    </div>
    
    <div class="trade-card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="trade-section">
            <div class="trade-section-header">
              <i class="bi bi-box-arrow-right me-2"></i>
              <span>${fromTeam} envia</span>
            </div>
            <div class="trade-items">
              ${hasOfferItems ? offerPlayersHTML + offerPicksHTML : '<div class="text-center text-muted py-3">Nenhum item oferecido</div>'}
            </div>
          </div>
        </div>
        
        <div class="col-md-6">
          <div class="trade-section">
            <div class="trade-section-header">
              <i class="bi bi-box-arrow-in-left me-2"></i>
              <span>${toTeam} envia</span>
            </div>
            <div class="trade-items">
              ${hasRequestItems ? requestPlayersHTML + requestPicksHTML : '<div class="text-center text-muted py-3">Nenhum item solicitado</div>'}
            </div>
          </div>
        </div>
      </div>
      
      ${trade.notes ? `
        <div class="trade-notes">
          <i class="bi bi-chat-left-text-fill me-2"></i>
          <span>${trade.notes}</span>
        </div>
      ` : ''}
      
      ${responseNotes}
    </div>
    
    ${trade.status === 'pending' && type === 'received' ? `
      <div class="trade-card-footer">
        <div class="mb-3">
          <label class="form-label text-light-gray small fw-bold">
            <i class="bi bi-pencil me-1"></i>Adicionar observação (opcional):
          </label>
          <textarea class="form-control bg-dark text-white border-secondary" 
                    id="responseNotes_${trade.id}" rows="2" 
                    placeholder="Digite sua resposta ou comentário..."></textarea>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-success" onclick="respondTrade(${trade.id}, 'accepted')">
            <i class="bi bi-check-circle-fill me-2"></i>Aceitar Trade
          </button>
          <button class="btn btn-danger" onclick="respondTrade(${trade.id}, 'rejected')">
            <i class="bi bi-x-circle-fill me-2"></i>Rejeitar
          </button>
          <button class="btn btn-info" onclick="openCounterProposal(${trade.id}, ${JSON.stringify(trade).replace(/"/g, '&quot;')})">
            <i class="bi bi-arrow-repeat me-2"></i>Fazer Contraproposta
          </button>
        </div>
      </div>
    ` : ''}
    
    ${trade.status === 'pending' && type === 'sent' ? `
      <div class="trade-card-footer">
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-warning" onclick="openModifyTrade(${trade.id}, ${JSON.stringify(trade).replace(/"/g, '&quot;')})">
            <i class="bi bi-pencil-fill me-2"></i>Modificar Proposta
          </button>
          <button class="btn btn-secondary" onclick="respondTrade(${trade.id}, 'cancelled')">
            <i class="bi bi-x-circle me-2"></i>Cancelar Trade
          </button>
        </div>
      </div>
    ` : ''}
  `;
  
  return card;
}

async function respondTrade(tradeId, action) {
  const actionTexts = {
    'accepted': 'aceitar',
    'rejected': 'rejeitar', 
    'cancelled': 'cancelar'
  };
  
  if (!confirm(`Confirma ${actionTexts[action]} esta trade?`)) {
    return;
  }
  
  // Pegar observação se existir
  const notesEl = document.getElementById(`responseNotes_${tradeId}`);
  const responseNotes = notesEl ? notesEl.value.trim() : '';
  
  try {
    await api('trades.php', {
      method: 'PUT',
      body: JSON.stringify({ trade_id: tradeId, action, response_notes: responseNotes })
    });
    
    alert('Trade atualizada!');
    loadTrades('received');
    loadTrades('sent');
    loadTrades('history');
  loadTrades('league');
    // Atualiza meus jogadores e picks imediatamente após a decisão
    try {
      await loadMyAssets();
      // Se um time alvo estiver selecionado no modal, recarregar os assets dele também
      const targetEl = document.getElementById('targetTeam');
      if (targetEl && targetEl.value) {
        await onTargetTeamChange({ target: targetEl });
      }
    } catch (e) {
      console.warn('Falha ao atualizar assets após trade:', e);
    }
  } catch (err) {
    alert(err.error || 'Erro ao atualizar trade');
  }
}

// Abrir modal de contraproposta
async function openCounterProposal(originalTradeId, originalTrade) {
  // Decodificar o trade se vier como string
  if (typeof originalTrade === 'string') {
    originalTrade = JSON.parse(originalTrade.replace(/&quot;/g, '"'));
  }
  
  // Limpar seleções anteriores
  clearCounterProposalState();
  
  // Preencher o modal com dados invertidos
  document.getElementById('targetTeam').value = originalTrade.from_team_id;
  document.getElementById('targetTeam').disabled = true; // Não pode mudar o time
  
  // Carregar jogadores e picks do time que enviou a proposta original
  await onTargetTeamChange({ target: document.getElementById('targetTeam') });
  
  // Aguardar renderização dos cards
  setTimeout(() => {
    // Selecionar os jogadores que eu deveria enviar na proposta original (agora vou oferecer)
    originalTrade.request_players.forEach(p => {
      const card = document.querySelector(`#offerPlayersContainer .trade-selectable-item[data-id="${p.id}"]`);
      if (card && !card.classList.contains('unavailable')) {
        card.click();
      }
    });
    
    originalTrade.request_picks.forEach(p => {
      const card = document.querySelector(`#offerPicksContainer .trade-selectable-item[data-id="${p.id}"]`);
      if (card) {
        card.click();
      }
    });
    
    // Selecionar os jogadores/picks que estavam sendo oferecidos (agora vou pedir)
    originalTrade.offer_players.forEach(p => {
      const card = document.querySelector(`#requestPlayersContainer .trade-selectable-item[data-id="${p.id}"]`);
      if (card && !card.classList.contains('unavailable')) {
        card.click();
      }
    });
    
    originalTrade.offer_picks.forEach(p => {
      const card = document.querySelector(`#requestPicksContainer .trade-selectable-item[data-id="${p.id}"]`);
      if (card) {
        card.click();
      }
    });
  }, 600);
  
  // Adicionar nota de contraproposta
  document.getElementById('tradeNotes').value = `[CONTRAPROPOSTA] Em resposta à proposta #${originalTradeId}`;
  
  // Abrir modal
  const modal = new bootstrap.Modal(document.getElementById('proposeTradeModal'));
  modal.show();
  
  // Guardar ID da trade original para cancelar depois
  document.getElementById('proposeTradeModal').dataset.counterTo = originalTradeId;
}

// Abrir modal para modificar trade (quem enviou)
async function openModifyTrade(tradeId, trade) {
  // Decodificar o trade se vier como string
  if (typeof trade === 'string') {
    trade = JSON.parse(trade.replace(/&quot;/g, '"'));
  }
  
  // Primeiro, cancelar a trade atual
  if (!confirm('Para modificar, a proposta atual será cancelada e uma nova será criada. Continuar?')) {
    return;
  }
  
  try {
    await api('trades.php', {
      method: 'PUT',
      body: JSON.stringify({ trade_id: tradeId, action: 'cancelled' })
    });
    
    // Limpar seleções
    clearCounterProposalState();
    
    // Preencher o modal com os dados da trade
    document.getElementById('targetTeam').value = trade.to_team_id;
    await onTargetTeamChange({ target: document.getElementById('targetTeam') });
    
    // Pré-selecionar itens após renderização
    setTimeout(() => {
      trade.offer_players.forEach(p => {
        const card = document.querySelector(`#offerPlayersContainer .trade-selectable-item[data-id="${p.id}"]`);
        if (card && !card.classList.contains('unavailable')) {
          card.click();
        }
      });
      
      trade.offer_picks.forEach(p => {
        const card = document.querySelector(`#offerPicksContainer .trade-selectable-item[data-id="${p.id}"]`);
        if (card) {
          card.click();
        }
      });
      
      trade.request_players.forEach(p => {
        const card = document.querySelector(`#requestPlayersContainer .trade-selectable-item[data-id="${p.id}"]`);
        if (card && !card.classList.contains('unavailable')) {
          card.click();
        }
      });
      
      trade.request_picks.forEach(p => {
        const card = document.querySelector(`#requestPicksContainer .trade-selectable-item[data-id="${p.id}"]`);
        if (card) {
          card.click();
        }
      });
    }, 600);
    
    document.getElementById('tradeNotes').value = trade.notes || '';
    
    // Abrir modal
    const modal = new bootstrap.Modal(document.getElementById('proposeTradeModal'));
    modal.show();
    
    // Atualizar listas
    loadTrades('sent');
    loadTrades('history');
  } catch (err) {
    alert(err.error || 'Erro ao modificar trade');
  }
}

// Inicializar
document.addEventListener('DOMContentLoaded', init);
