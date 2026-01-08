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
  
  // Event listeners
  document.getElementById('submitTradeBtn').addEventListener('click', submitTrade);
  document.getElementById('targetTeam').addEventListener('change', onTargetTeamChange);
}

async function loadTeams() {
  try {
    const data = await api('teams.php');
    allTeams = data.teams || [];
    
    // Preencher select de times (exceto o meu, apenas da mesma liga)
    const select = document.getElementById('targetTeam');
    select.innerHTML = '<option value="">Selecione...</option>';
    allTeams
      .filter(t => t.id !== myTeamId && t.league === myLeague)
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
    myPlayers = (playersData.players || []).filter(p => p.available_for_trade);
    
    const selectPlayers = document.getElementById('offerPlayers');
    selectPlayers.innerHTML = '';
    myPlayers.forEach(p => {
      const option = document.createElement('option');
      option.value = p.id;
      option.textContent = `${p.name} (${p.position}, OVR ${p.ovr})`;
      selectPlayers.appendChild(option);
    });
    
    // Minhas picks
    const picksData = await api(`picks.php?team_id=${myTeamId}`);
    myPicks = picksData.picks || [];
    
    const selectPicks = document.getElementById('offerPicks');
    selectPicks.innerHTML = '';
    myPicks.forEach(p => {
      const option = document.createElement('option');
      option.value = p.id;
      option.textContent = `${p.season_year} - ${p.round}ª rodada (de ${p.original_team_name})`;
      selectPicks.appendChild(option);
    });
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
    const players = (playersData.players || []).filter(p => p.available_for_trade);
    
    const select = document.getElementById('requestPlayers');
    select.innerHTML = '';
    players.forEach(p => {
      const option = document.createElement('option');
      option.value = p.id;
      option.textContent = `${p.name} (${p.position}, OVR ${p.ovr})`;
      select.appendChild(option);
    });
    
    // Carregar picks do time alvo
    const picksData = await api(`picks.php?team_id=${teamId}`);
    const picks = picksData.picks || [];
    
    const selectPicks = document.getElementById('requestPicks');
    selectPicks.innerHTML = '';
    picks.forEach(p => {
      const option = document.createElement('option');
      option.value = p.id;
      option.textContent = `${p.season_year} - ${p.round}ª rodada (de ${p.original_team_name})`;
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
    await api('trades.php', {
      method: 'POST',
      body: JSON.stringify({
        to_team_id: parseInt(targetTeam),
        offer_players: offerPlayers.map(id => parseInt(id)),
        offer_picks: offerPicks.map(id => parseInt(id)),
        request_players: requestPlayers.map(id => parseInt(id)),
        request_picks: requestPicks.map(id => parseInt(id)),
        notes
      })
    });
    
    alert('Proposta de trade enviada!');
    bootstrap.Modal.getInstance(document.getElementById('proposeTradeModal')).hide();
    document.getElementById('proposeTradeForm').reset();
    loadTrades('sent');
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
      return;
    }
    
    container.innerHTML = '';
    trades.forEach(trade => {
      const card = createTradeCard(trade, type);
      container.appendChild(card);
    });
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
    'cancelled': '<span class="badge bg-secondary">Cancelada</span>'
  }[trade.status];
  
  const fromTeam = `${trade.from_city} ${trade.from_name}`;
  const toTeam = `${trade.to_city} ${trade.to_name}`;
  
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
          ${trade.offer_players.map(p => `<li><i class="bi bi-person-fill text-orange"></i> ${p.name} (${p.position}, ${p.ovr})</li>`).join('')}
          ${trade.offer_picks.map(p => `<li><i class="bi bi-trophy-fill text-orange"></i> Pick ${p.season_year} - ${p.round}ª rodada</li>`).join('')}
        </ul>
      </div>
      <div class="col-md-6">
        <h6 class="text-orange mb-2">${toTeam} envia:</h6>
        <ul class="list-unstyled text-white">
          ${trade.request_players.map(p => `<li><i class="bi bi-person-fill text-orange"></i> ${p.name} (${p.position}, ${p.ovr})</li>`).join('')}
          ${trade.request_picks.map(p => `<li><i class="bi bi-trophy-fill text-orange"></i> Pick ${p.season_year} - ${p.round}ª rodada</li>`).join('')}
        </ul>
      </div>
    </div>
    
    ${trade.notes ? `<div class="mt-3 p-2 bg-dark rounded"><small class="text-light-gray">${trade.notes}</small></div>` : ''}
    
    ${trade.status === 'pending' && type === 'received' ? `
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-success btn-sm" onclick="respondTrade(${trade.id}, 'accepted')">
          <i class="bi bi-check-circle me-1"></i>Aceitar
        </button>
        <button class="btn btn-danger btn-sm" onclick="respondTrade(${trade.id}, 'rejected')">
          <i class="bi bi-x-circle me-1"></i>Rejeitar
        </button>
      </div>
    ` : ''}
    
    ${trade.status === 'pending' && type === 'sent' ? `
      <div class="mt-3">
        <button class="btn btn-secondary btn-sm" onclick="respondTrade(${trade.id}, 'cancelled')">
          <i class="bi bi-x-circle me-1"></i>Cancelar
        </button>
      </div>
    ` : ''}
  `;
  
  return card;
}

async function respondTrade(tradeId, action) {
  if (!confirm(`Confirma ${action === 'accepted' ? 'aceitar' : action === 'rejected' ? 'rejeitar' : 'cancelar'} esta trade?`)) {
    return;
  }
  
  try {
    await api('trades.php', {
      method: 'PUT',
      body: JSON.stringify({ trade_id: tradeId, action })
    });
    
    alert('Trade atualizada!');
    loadTrades('received');
    loadTrades('sent');
    loadTrades('history');
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

// Inicializar
document.addEventListener('DOMContentLoaded', init);
