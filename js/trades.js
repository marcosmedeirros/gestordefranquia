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
let allLeagueTrades = []; // Armazenar trades da liga para busca

const formatTradePlayerDisplay = (player) => {
  if (!player) return '';
  const name = player.name || 'Jogador';
  const position = player.position || '-';
  const ovr = player.ovr ?? '?';
  const age = player.age ?? '?';
  return `${name} (${position}, ${ovr}/${age})`;
};

const formatTradePickDisplay = (pick) => {
  if (!pick) return '';
  const year = pick.season_year || '?';
  const round = pick.round || '?';
  
  // Mostrar de quem é a pick (time original)
  const originalTeam = pick.original_team_city && pick.original_team_name 
    ? `${pick.original_team_city} ${pick.original_team_name}` 
    : 'Time';
  
  let display = `Pick ${year} R${round} (${originalTeam})`;
  
  // Se a pick foi trocada (team_id != original_team_id), mostrar "via"
  if (pick.team_id && pick.original_team_id && pick.team_id != pick.original_team_id) {
    // Mostrar quem enviou a pick (last_owner)
    if (pick.last_owner_city && pick.last_owner_name) {
      display += ` <span class="text-info">via ${pick.last_owner_city} ${pick.last_owner_name}</span>`;
    }
  }
  
  return display;
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

  // Event listener para busca de jogador nas trades gerais
  const leagueTradesSearch = document.getElementById('leagueTradesSearch');
  if (leagueTradesSearch) {
    leagueTradesSearch.addEventListener('input', (e) => {
      filterLeagueTrades(e.target.value);
    });
  }

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
    
    // Aguardar um pouco para garantir que os selects foram populados
    setTimeout(() => {
      // Pré-selecionar o jogador solicitado
      const requestPlayersSelect = document.getElementById('requestPlayers');
      const playerOption = requestPlayersSelect.querySelector(`option[value="${playerId}"]`);
      if (playerOption) {
        playerOption.selected = true;
        playerOption.scrollIntoView({ block: 'nearest' });
      }
      
      // Abrir o modal
      const modal = new bootstrap.Modal(document.getElementById('proposeTradeModal'));
      modal.show();
      
      // Adicionar nota sugerindo a trade
      document.getElementById('tradeNotes').value = 'Olá! Tenho interesse neste jogador. Vamos negociar?';
    }, 300);
  } catch (err) {
    console.error('Erro ao pré-selecionar jogador:', err);
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
    const myId = Number(myTeamId);
    const myLeagueNormalized = (myLeague ?? '').toString().trim().toUpperCase();

    const filteredTeams = allTeams.filter(t => {
      const teamId = Number(t.id);
      if (!Number.isFinite(teamId) || teamId === myId) return false;
      const teamLeagueNormalized = (t.league ?? '').toString().trim().toUpperCase();
      // Se não conseguir determinar liga, deixa passar, mas prioriza comparação normalizada
      if (!myLeagueNormalized) return true;
      return teamLeagueNormalized === myLeagueNormalized;
    });
    
    console.log('Times filtrados para trade:', filteredTeams.length);
    
    if (filteredTeams.length === 0) {
      const option = document.createElement('option');
      option.disabled = true;
      option.textContent = 'Nenhum time disponível na sua liga';
      select.appendChild(option);
      return;
    }

    filteredTeams
      .sort((a, b) => `${a.city} ${a.name}`.localeCompare(`${b.city} ${b.name}`))
      .forEach(t => {
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
    
    const selectPlayers = document.getElementById('offerPlayers');
    selectPlayers.innerHTML = '';
    if (myPlayers.length === 0) {
      const option = document.createElement('option');
      option.disabled = true;
      option.textContent = 'Nenhum jogador encontrado no seu elenco';
      selectPlayers.appendChild(option);
    } else {
      myPlayers.forEach(p => {
        const option = document.createElement('option');
        option.value = p.id;
        const statusLabel = p.available_for_trade ? '' : ' • fora do trade block';
        option.textContent = `${formatTradePlayerDisplay(p)}${statusLabel}`;
        selectPlayers.appendChild(option);
      });
    }
    
    // Minhas picks
    const picksData = await api(`picks.php?team_id=${myTeamId}`);
    myPicks = picksData.picks || [];
    
    console.log('Minhas picks:', myPicks.length);
    
    const selectPicks = document.getElementById('offerPicks');
    selectPicks.innerHTML = '';
    if (myPicks.length === 0) {
      const option = document.createElement('option');
      option.disabled = true;
      option.textContent = 'Nenhuma pick disponível';
      selectPicks.appendChild(option);
    } else {
      myPicks.forEach(p => {
        const option = document.createElement('option');
        option.value = p.id;
        // Mostrar "via" se a pick foi trocada (team_id != original_team_id)
        const isTraded = p.original_team_id && p.team_id && p.original_team_id != p.team_id;
        const viaLabel = isTraded && p.last_owner_city && p.last_owner_name
          ? ` (via ${p.last_owner_city} ${p.last_owner_name})`
          : '';
        const originLabel = p.original_team_city && p.original_team_name
          ? `${p.original_team_city} ${p.original_team_name}`
          : (p.original_team_name || 'Time');
        option.textContent = `${p.season_year} - ${p.round}ª rodada (${originLabel})${viaLabel}`;
        selectPicks.appendChild(option);
      });
    }
  } catch (err) {
    console.error('Erro ao carregar assets:', err);
  }
}

async function onTargetTeamChange(e) {
  const teamId = e.target.value;
  if (!teamId) return;
  
  try {
    // Carregar jogadores do time alvo
    const playersData = await api(`players.php?team_id=${teamId}`);
  const players = playersData.players || [];
    
  console.log('Jogadores do time alvo carregados:', players.length);
    
    const select = document.getElementById('requestPlayers');
    select.innerHTML = '';
    if (players.length === 0) {
      const option = document.createElement('option');
      option.disabled = true;
      option.textContent = 'Nenhum jogador encontrado no elenco';
      select.appendChild(option);
    } else {
      players.forEach(p => {
        const option = document.createElement('option');
        option.value = p.id;
        const statusLabel = p.available_for_trade ? '' : ' • fora do trade block';
        option.textContent = `${formatTradePlayerDisplay(p)}${statusLabel}`;
        select.appendChild(option);
      });
    }
    
    // Carregar picks do time alvo
    const picksData = await api(`picks.php?team_id=${teamId}`);
    const picks = picksData.picks || [];
    
    console.log('Picks do time alvo:', picks.length);
    
    const selectPicks = document.getElementById('requestPicks');
    selectPicks.innerHTML = '';
    if (picks.length === 0) {
      const option = document.createElement('option');
      option.disabled = true;
      option.textContent = 'Nenhuma pick disponível';
      selectPicks.appendChild(option);
    } else {
      picks.forEach(p => {
        const option = document.createElement('option');
        option.value = p.id;
        // Mostrar "via" se a pick foi trocada (team_id != original_team_id)
        const isTraded = p.original_team_id && p.team_id && p.original_team_id != p.team_id;
        const viaLabel = isTraded && p.last_owner_city && p.last_owner_name
          ? ` (via ${p.last_owner_city} ${p.last_owner_name})`
          : '';
        const originLabel = p.original_team_city && p.original_team_name
          ? `${p.original_team_city} ${p.original_team_name}`
          : (p.original_team_name || 'Time');
        option.textContent = `${p.season_year} - ${p.round}ª rodada (${originLabel})${viaLabel}`;
        selectPicks.appendChild(option);
      });
    }
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

function filterLeagueTrades(searchTerm) {
  const container = document.getElementById('leagueTradesList');
  const badge = document.getElementById('leagueTradesCount');
  
  if (!searchTerm || searchTerm.trim() === '') {
    // Mostrar todas as trades
    container.innerHTML = '';
    allLeagueTrades.forEach(trade => {
      const card = createTradeCard(trade, 'league');
      container.appendChild(card);
    });
    badge.textContent = `${allLeagueTrades.length} ${allLeagueTrades.length === 1 ? 'trade' : 'trocas'}`;
    return;
  }
  
  const term = searchTerm.toLowerCase().trim();
  
  // Filtrar trades que contenham o jogador
  const filtered = allLeagueTrades.filter(trade => {
    // Buscar em offer_players
    const hasInOffer = trade.offer_players.some(p => 
      p.name && p.name.toLowerCase().includes(term)
    );
    
    // Buscar em request_players
    const hasInRequest = trade.request_players.some(p => 
      p.name && p.name.toLowerCase().includes(term)
    );
    
    return hasInOffer || hasInRequest;
  });
  
  // Renderizar resultados
  container.innerHTML = '';
  if (filtered.length === 0) {
    container.innerHTML = `<div class="text-center text-light-gray py-4">Nenhuma trade encontrada com "${searchTerm}"</div>`;
    badge.textContent = '0 trocas';
    return;
  }
  
  filtered.forEach(trade => {
    const card = createTradeCard(trade, 'league');
    container.appendChild(card);
  });
  
  badge.textContent = `${filtered.length} ${filtered.length === 1 ? 'trade' : 'trocas'}`;
}

async function loadTrades(type) {
  const container = document.getElementById(`${type}TradesList`);
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const data = await api(`trades.php?type=${type}`);
    const trades = data.trades || [];
    
    // Armazenar trades da liga para busca
    if (type === 'league') {
      allLeagueTrades = trades;
    }
    
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
  card.className = 'bg-dark-panel border-orange rounded p-4 mb-3';
  
  const statusBadge = {
    'pending': '<span class="badge bg-warning text-dark">Pendente</span>',
    'accepted': '<span class="badge bg-success">Aceita</span>',
    'rejected': '<span class="badge bg-danger">Rejeitada</span>',
    'cancelled': '<span class="badge bg-secondary">Cancelada</span>',
    'countered': '<span class="badge bg-info">Contraproposta</span>'
  }[trade.status];
  
  const fromTeam = `${trade.from_city} ${trade.from_name}`;
  const toTeam = `${trade.to_city} ${trade.to_name}`;
  
  // Verificar se tem observação de resposta
  const responseNotes = trade.response_notes ? `
    <div class="mt-2 p-2 bg-dark rounded border-start border-warning border-3">
      <small class="text-warning fw-bold"><i class="bi bi-chat-dots me-1"></i>Resposta:</small>
      <small class="text-light-gray d-block">${trade.response_notes}</small>
    </div>
  ` : '';
  
  card.innerHTML = `
    <div class="d-flex justify-content-between align-items-start mb-3">
      <div>
        <h5 class="text-white mb-1">${fromTeam} <i class="bi bi-arrow-right text-orange"></i> ${toTeam}</h5>
        <small class="text-light-gray">${new Date(trade.created_at).toLocaleDateString('pt-BR')}</small>
      </div>
      <div>${statusBadge}</div>
    </div>
    
    <div class="row">
      <div class="col-md-6">
        <h6 class="text-orange mb-2">${fromTeam} oferece:</h6>
        <ul class="list-unstyled text-white">
          ${trade.offer_players.map(p => `<li><i class="bi bi-person-fill text-orange"></i> ${formatTradePlayerDisplay(p)}</li>`).join('')}
          ${trade.offer_picks.map(p => `<li><i class="bi bi-trophy-fill text-orange"></i> ${formatTradePickDisplay(p)}</li>`).join('')}
          ${(trade.offer_players.length === 0 && trade.offer_picks.length === 0) ? '<li class="text-muted">Nenhum item</li>' : ''}
        </ul>
      </div>
      <div class="col-md-6">
        <h6 class="text-orange mb-2">${toTeam} envia:</h6>
        <ul class="list-unstyled text-white">
          ${trade.request_players.map(p => `<li><i class="bi bi-person-fill text-orange"></i> ${formatTradePlayerDisplay(p)}</li>`).join('')}
          ${trade.request_picks.map(p => `<li><i class="bi bi-trophy-fill text-orange"></i> ${formatTradePickDisplay(p)}</li>`).join('')}
          ${(trade.request_players.length === 0 && trade.request_picks.length === 0) ? '<li class="text-muted">Nenhum item</li>' : ''}
        </ul>
      </div>
    </div>
    
    ${trade.notes ? `<div class="mt-3 p-2 bg-dark rounded"><small class="text-light-gray"><i class="bi bi-chat-left-text me-1"></i>${trade.notes}</small></div>` : ''}
    ${responseNotes}
    
    ${trade.status === 'pending' && type === 'received' ? `
      <div class="mt-3">
        <div class="mb-2">
          <label class="form-label text-light-gray small">Observação (opcional):</label>
          <textarea class="form-control form-control-sm bg-dark text-white border-secondary" 
                    id="responseNotes_${trade.id}" rows="2" 
                    placeholder="Adicione uma mensagem..."></textarea>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-success btn-sm" onclick="respondTrade(${trade.id}, 'accepted')">
            <i class="bi bi-check-circle me-1"></i>Aceitar
          </button>
          <button class="btn btn-danger btn-sm" onclick="respondTrade(${trade.id}, 'rejected')">
            <i class="bi bi-x-circle me-1"></i>Rejeitar
          </button>
          <button class="btn btn-info btn-sm" onclick="openCounterProposal(${trade.id}, ${JSON.stringify(trade).replace(/"/g, '&quot;')})">
            <i class="bi bi-arrow-repeat me-1"></i>Contraproposta
          </button>
        </div>
      </div>
    ` : ''}
    
    ${trade.status === 'pending' && type === 'sent' ? `
      <div class="mt-3 d-flex gap-2 flex-wrap">
        <button class="btn btn-warning btn-sm" onclick="openModifyTrade(${trade.id}, ${JSON.stringify(trade).replace(/"/g, '&quot;')})">
          <i class="bi bi-pencil me-1"></i>Modificar
        </button>
        <button class="btn btn-secondary btn-sm" onclick="respondTrade(${trade.id}, 'cancelled')">
          <i class="bi bi-x-circle me-1"></i>Cancelar
        </button>
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
  
  // Preencher o modal com dados invertidos
  document.getElementById('targetTeam').value = originalTrade.from_team_id;
  document.getElementById('targetTeam').disabled = true; // Não pode mudar o time
  
  // Carregar jogadores e picks do time que enviou a proposta original
  await onTargetTeamChange({ target: document.getElementById('targetTeam') });
  
  // Pré-selecionar jogadores/picks que estavam sendo pedidos (agora vou oferecer)
  setTimeout(() => {
    const offerPlayersSelect = document.getElementById('offerPlayers');
    const offerPicksSelect = document.getElementById('offerPicks');
    
    // Selecionar os jogadores que eu deveria enviar na proposta original
    originalTrade.request_players.forEach(p => {
      const option = offerPlayersSelect.querySelector(`option[value="${p.id}"]`);
      if (option) option.selected = true;
    });
    
    originalTrade.request_picks.forEach(p => {
      const option = offerPicksSelect.querySelector(`option[value="${p.id}"]`);
      if (option) option.selected = true;
    });
    
    // Selecionar os jogadores/picks que estavam sendo oferecidos (agora vou pedir)
    const requestPlayersSelect = document.getElementById('requestPlayers');
    const requestPicksSelect = document.getElementById('requestPicks');
    
    originalTrade.offer_players.forEach(p => {
      const option = requestPlayersSelect.querySelector(`option[value="${p.id}"]`);
      if (option) option.selected = true;
    });
    
    originalTrade.offer_picks.forEach(p => {
      const option = requestPicksSelect.querySelector(`option[value="${p.id}"]`);
      if (option) option.selected = true;
    });
  }, 500);
  
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
    
    // Preencher o modal com os dados da trade
    document.getElementById('targetTeam').value = trade.to_team_id;
    await onTargetTeamChange({ target: document.getElementById('targetTeam') });
    
    // Pré-selecionar itens
    setTimeout(() => {
      trade.offer_players.forEach(p => {
        const option = document.querySelector(`#offerPlayers option[value="${p.id}"]`);
        if (option) option.selected = true;
      });
      
      trade.offer_picks.forEach(p => {
        const option = document.querySelector(`#offerPicks option[value="${p.id}"]`);
        if (option) option.selected = true;
      });
      
      trade.request_players.forEach(p => {
        const option = document.querySelector(`#requestPlayers option[value="${p.id}"]`);
        if (option) option.selected = true;
      });
      
      trade.request_picks.forEach(p => {
        const option = document.querySelector(`#requestPicks option[value="${p.id}"]`);
        if (option) option.selected = true;
      });
    }, 500);
    
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
