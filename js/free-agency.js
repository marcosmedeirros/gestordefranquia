// Sistema de Leilões - FBA Manager

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

let activeAuctions = [];
let recentAuctions = [];
let teamPoints = window.__TEAM_POINTS__ || 0;
let auctionRefreshInterval = null;
let currentLeague = window.__USER_LEAGUE__ || 'ELITE';

// Cores por OVR
function getOvrColor(ovr) {
  if (ovr >= 90) return '#00ff00';
  if (ovr >= 80) return '#7fff00';
  if (ovr >= 70) return '#ffff00';
  if (ovr >= 60) return '#ffa500';
  return '#ff4444';
}

// Inicialização
document.addEventListener('DOMContentLoaded', async () => {
  // Admin: Filtro de liga
  const leagueFilter = document.getElementById('adminLeagueFilter');
  if (leagueFilter && window.__IS_ADMIN__) {
    leagueFilter.value = currentLeague;
    leagueFilter.addEventListener('change', (e) => {
      currentLeague = e.target.value;
      loadActiveAuctions();
      loadRecentAuctions();
      loadAdminAuctions();
    });
  }
  
  await loadActiveAuctions();
  await loadRecentAuctions();
  
  // Event listeners
  document.getElementById('btn-place-bid')?.addEventListener('click', placeBid);
  
  // Admin
  if (window.__IS_ADMIN__) {
    document.getElementById('createAuctionForm')?.addEventListener('submit', createAuction);
    loadAdminAuctions();
    document.getElementById('adminAuctionModal')?.addEventListener('shown.bs.modal', loadAdminAuctions);
  }
  
  // Atualizar leilões a cada 10 segundos
  auctionRefreshInterval = setInterval(() => {
    loadActiveAuctions();
    if (window.__IS_ADMIN__) loadAdminAuctions();
  }, 10000);
});

// ============================================
// LEILÕES - USUÁRIO
// ============================================

async function loadTeamPoints() {
  try {
    const data = await api('free-agency.php?action=get_team_points');
    teamPoints = data.available_points || 0;
    document.getElementById('available-points').textContent = teamPoints;
    window.__TEAM_POINTS__ = teamPoints;
  } catch (err) {
    console.error('Erro ao carregar pontos:', err);
  }
}

async function loadActiveAuctions() {
  const container = document.getElementById('auctions-list');
  if (!container) return;
  
  try {
    const league = window.__IS_ADMIN__ ? currentLeague : window.__USER_LEAGUE__;
    const data = await api(`free-agency.php?action=active_auctions&league=${league}`);
    activeAuctions = data.auctions || [];
    renderActiveAuctions();
    await loadTeamPoints();
  } catch (err) {
    container.innerHTML = '<div class="col-12"><div class="alert alert-danger">Erro ao carregar leilões</div></div>';
    console.error(err);
  }
}

function renderActiveAuctions() {
  const container = document.getElementById('auctions-list');
  if (!container) return;
  
  if (activeAuctions.length === 0) {
    container.innerHTML = `
      <div class="col-12">
        <div class="alert alert-info bg-dark border-orange">
          <i class="bi bi-info-circle me-2"></i>Nenhum leilão ativo no momento. Aguarde novos jogadores serem disponibilizados.
        </div>
      </div>
    `;
    return;
  }
  
  container.innerHTML = activeAuctions.map(auction => {
    const ovrColor = getOvrColor(auction.player_ovr);
    const secondsRemaining = parseInt(auction.seconds_remaining) || 0;
    const timeDisplay = formatAuctionTime(secondsRemaining);
    const isEndingSoon = secondsRemaining <= 60;
    const timeClass = isEndingSoon ? 'text-danger auction-timer ending-soon' : 
                      (secondsRemaining <= 300 ? 'text-warning' : 'text-success');
    
    const myBid = auction.my_bid || 0;
    const isWinning = myBid > 0 && myBid >= (auction.current_bid || 0);
    
    let bidStatus = '';
    if (myBid > 0) {
      bidStatus = isWinning 
        ? '<span class="badge bg-success"><i class="bi bi-trophy-fill me-1"></i>Você está vencendo!</span>' 
        : '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Você foi superado!</span>';
    }
    
    const minNextBid = Math.max(1, (auction.current_bid || 0) + 1);
    const canBid = teamPoints >= minNextBid;
    
    const posDisplay = auction.player_secondary_position 
      ? `${auction.player_position}/${auction.player_secondary_position}` 
      : auction.player_position;
    
    return `
      <div class="col-md-6 col-xl-4 mb-4">
        <div class="fa-card auction-card ${isWinning ? 'border-success' : ''}">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <h5 class="text-white mb-1">${auction.player_name}</h5>
              <span class="badge bg-orange">${posDisplay}</span>
              <span class="ms-2 text-light-gray">${auction.player_age} anos</span>
            </div>
            <div class="text-end">
              <div class="fs-2 fw-bold" style="color: ${ovrColor}">${auction.player_ovr}</div>
            </div>
          </div>
          
          <div class="auction-info mt-3 p-3 bg-dark rounded">
            <div class="row align-items-center">
              <div class="col-6">
                <small class="text-light-gray d-block">Lance atual:</small>
                <div class="text-orange fw-bold fs-4">${auction.current_bid || 0} <small>pts</small></div>
                ${auction.winning_team_name 
                  ? `<small class="text-light-gray">${auction.winning_team_name}</small>` 
                  : '<small class="text-muted">Sem lances</small>'}
              </div>
              <div class="col-6 text-end">
                <small class="text-light-gray d-block">Tempo restante:</small>
                <div class="${timeClass} fw-bold fs-4">${timeDisplay}</div>
              </div>
            </div>
          </div>
          
          ${bidStatus ? `<div class="mt-3 text-center">${bidStatus}</div>` : ''}
          ${myBid > 0 ? `<div class="text-center"><small class="text-light-gray">Seu lance: ${myBid} pts</small></div>` : ''}
          
          <div class="mt-3">
            <button class="btn btn-orange w-100" 
                    onclick="openBidModal(${auction.id}, '${auction.player_name.replace(/'/g, "\\'")}', '${posDisplay}', ${auction.player_ovr}, ${auction.current_bid || 0})" 
                    ${!canBid ? 'disabled' : ''}>
              <i class="bi bi-hammer me-1"></i>Fazer Lance (mín. ${minNextBid} pts)
            </button>
            ${!canBid ? '<small class="text-danger d-block mt-1 text-center">Pontos insuficientes</small>' : ''}
          </div>
        </div>
      </div>
    `;
  }).join('');
}

async function loadRecentAuctions() {
  const container = document.getElementById('recent-auctions-list');
  if (!container) return;
  
  try {
    const league = window.__IS_ADMIN__ ? currentLeague : window.__USER_LEAGUE__;
    const data = await api(`free-agency.php?action=recent_auctions&league=${league}`);
    recentAuctions = data.auctions || [];
    renderRecentAuctions();
  } catch (err) {
    container.innerHTML = '<div class="text-muted">Erro ao carregar histórico</div>';
  }
}

function renderRecentAuctions() {
  const container = document.getElementById('recent-auctions-list');
  if (!container) return;
  
  if (recentAuctions.length === 0) {
    container.innerHTML = '<div class="text-muted">Nenhum leilão finalizado recentemente.</div>';
    return;
  }
  
  container.innerHTML = `
    <div class="table-responsive">
      <table class="table table-dark table-sm">
        <thead>
          <tr>
            <th>Jogador</th>
            <th>Pos</th>
            <th>OVR</th>
            <th>Vencedor</th>
            <th>Lance</th>
            <th>Data</th>
          </tr>
        </thead>
        <tbody>
          ${recentAuctions.map(a => `
            <tr>
              <td>${a.player_name}</td>
              <td><span class="badge bg-secondary">${a.player_position}</span></td>
              <td style="color: ${getOvrColor(a.player_ovr)}">${a.player_ovr}</td>
              <td>${a.winner_team_name || '<span class="text-muted">Sem vencedor</span>'}</td>
              <td class="text-orange">${a.winning_bid || 0} pts</td>
              <td class="text-muted">${formatDate(a.ended_at)}</td>
            </tr>
          `).join('')}
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

function formatDate(dateStr) {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function openBidModal(auctionId, playerName, position, ovr, currentBid) {
  const minNextBid = Math.max(1, currentBid + 1);
  
  document.getElementById('bid-auction-id').value = auctionId;
  document.getElementById('bid-player-name').textContent = playerName;
  document.getElementById('bid-player-info').textContent = `${position} - OVR ${ovr}`;
  document.getElementById('bid-current').textContent = currentBid;
  document.getElementById('bid-min').textContent = minNextBid;
  document.getElementById('bid-available').textContent = teamPoints;
  
  const bidInput = document.getElementById('bid-amount');
  bidInput.value = minNextBid;
  bidInput.min = minNextBid;
  bidInput.max = teamPoints;
  
  new bootstrap.Modal(document.getElementById('bidModal')).show();
}

async function placeBid() {
  const btn = document.getElementById('btn-place-bid');
  const auctionId = document.getElementById('bid-auction-id').value;
  const bidAmount = parseInt(document.getElementById('bid-amount').value);
  
  if (!bidAmount || bidAmount < 1) {
    alert('Informe um valor válido para o lance');
    return;
  }
  
  if (bidAmount > teamPoints) {
    alert('Você não tem pontos suficientes para este lance');
    return;
  }
  
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando...';
  
  try {
    const data = await api('free-agency.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'place_bid',
        auction_id: parseInt(auctionId),
        bid_amount: bidAmount
      })
    });
    
    alert(data.message || 'Lance registrado com sucesso!');
    bootstrap.Modal.getInstance(document.getElementById('bidModal'))?.hide();
    await loadActiveAuctions();
  } catch (err) {
    alert(err.error || 'Erro ao fazer lance');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-hammer me-1"></i>Confirmar Lance';
  }
}

// ============================================
// ADMIN - GERENCIAR LEILÕES
// ============================================

async function createAuction(e) {
  e.preventDefault();
  
  const name = document.getElementById('auctionPlayerName').value.trim();
  const age = parseInt(document.getElementById('auctionPlayerAge').value);
  const ovr = parseInt(document.getElementById('auctionPlayerOvr').value);
  const position = document.getElementById('auctionPlayerPosition').value;
  const secondaryPosition = document.getElementById('auctionPlayerSecondary').value;
  const league = document.getElementById('auctionPlayerLeague').value;
  
  if (!name) {
    alert('Informe o nome do jogador');
    return;
  }
  
  const btn = e.target.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Criando...';
  
  try {
    const data = await api('free-agency.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'start_auction',
        player_name: name,
        player_age: age,
        player_ovr: ovr,
        player_position: position,
        player_secondary_position: secondaryPosition || null,
        league: league,
        duration_minutes: 20
      })
    });
    
    alert(data.message || 'Leilão iniciado!');
    e.target.reset();
    await loadAdminAuctions();
    await loadActiveAuctions();
  } catch (err) {
    alert(err.error || 'Erro ao criar leilão');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-hammer me-1"></i>Cadastrar e Iniciar Leilão (20 min)';
  }
}

async function loadAdminAuctions() {
  const container = document.getElementById('admin-auctions-list');
  if (!container) return;
  
  try {
    const data = await api(`free-agency.php?action=active_auctions&league=${currentLeague}`);
    const auctions = data.auctions || [];
    
    if (auctions.length === 0) {
      container.innerHTML = '<div class="text-muted">Nenhum leilão em andamento.</div>';
      return;
    }
    
    container.innerHTML = auctions.map(a => {
      const secondsRemaining = parseInt(a.seconds_remaining) || 0;
      const timeDisplay = formatAuctionTime(secondsRemaining);
      
      return `
        <div class="d-flex justify-content-between align-items-center bg-dark p-3 rounded mb-2">
          <div>
            <strong class="text-white">${a.player_name}</strong>
            <span class="badge bg-orange ms-2">${a.player_position}</span>
            <span class="ms-2">OVR ${a.player_ovr}</span>
            <div class="small text-light-gray">
              Lance atual: <span class="text-orange">${a.current_bid || 0} pts</span>
              ${a.winning_team_name ? ` (${a.winning_team_name})` : ''}
              | Tempo: <span class="${secondsRemaining <= 60 ? 'text-danger' : ''}">${timeDisplay}</span>
            </div>
          </div>
          <div class="btn-group">
            <button class="btn btn-success btn-sm" onclick="finalizeAuction(${a.id})" title="Finalizar agora">
              <i class="bi bi-check-lg"></i>
            </button>
            <button class="btn btn-danger btn-sm" onclick="cancelAuction(${a.id})" title="Cancelar">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
        </div>
      `;
    }).join('');
  } catch (err) {
    container.innerHTML = '<div class="text-danger">Erro ao carregar leilões</div>';
  }
}

async function finalizeAuction(auctionId) {
  if (!confirm('Finalizar este leilão agora? O vencedor atual receberá o jogador.')) return;
  
  try {
    const data = await api('free-agency.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'finalize_auction',
        auction_id: auctionId
      })
    });
    alert(data.message || 'Leilão finalizado!');
    await loadAdminAuctions();
    await loadActiveAuctions();
    await loadRecentAuctions();
  } catch (err) {
    alert(err.error || 'Erro ao finalizar leilão');
  }
}

async function cancelAuction(auctionId) {
  if (!confirm('Cancelar este leilão? Todos os lances serão descartados.')) return;
  
  try {
    const data = await api('free-agency.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'cancel_auction',
        auction_id: auctionId
      })
    });
    alert(data.message || 'Leilão cancelado!');
    await loadAdminAuctions();
    await loadActiveAuctions();
  } catch (err) {
    alert(err.error || 'Erro ao cancelar leilão');
  }
}
