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

const DEFAULT_LIMITS = {
  waiversUsed: 0,
  waiversMax: 3,
  signingsUsed: 0,
  signingsMax: 3,
};

let allFreeAgents = [];
let currentLimits = { ...DEFAULT_LIMITS };

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
  await loadLimits();
  await loadFreeAgents();
  await loadMyOffers();
  
  if (window.__IS_ADMIN__) {
    await loadAdminOffers();
  }
  
  // Event listeners
  document.getElementById('search-fa')?.addEventListener('input', filterFreeAgents);
  document.getElementById('filter-position')?.addEventListener('change', filterFreeAgents);
  document.getElementById('btn-send-offer')?.addEventListener('click', sendOffer);
  if (window.__IS_ADMIN__) {
    document.getElementById('btn-open-create-fa')?.addEventListener('click', openCreateFaModal);
    document.getElementById('btn-create-fa')?.addEventListener('click', submitCreateFreeAgent);
  }
  
  // Tab change listeners
  document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
    tab.addEventListener('shown.bs.tab', (e) => {
      const target = e.target.getAttribute('data-bs-target');
      if (target === '#my-offers') loadMyOffers();
      if (target === '#admin-offers') loadAdminOffers();
    });
  });
});

async function loadLimits() {
  try {
    const data = await api('free-agency.php?action=limits');
    currentLimits = {
      waiversUsed: Number.isFinite(data.waivers_used) ? data.waivers_used : DEFAULT_LIMITS.waiversUsed,
      waiversMax: Number.isFinite(data.waivers_max) ? data.waivers_max : DEFAULT_LIMITS.waiversMax,
      signingsUsed: Number.isFinite(data.signings_used) ? data.signings_used : DEFAULT_LIMITS.signingsUsed,
      signingsMax: Number.isFinite(data.signings_max) ? data.signings_max : DEFAULT_LIMITS.signingsMax,
    };
    updateLimitBadges();
  } catch (err) {
    console.error('Erro ao carregar limites:', err);
    currentLimits = { ...DEFAULT_LIMITS };
    updateLimitBadges();
  }
}

function updateLimitBadges() {
  const waiversCount = document.getElementById('waivers-count');
  const signingsCount = document.getElementById('signings-count');
  const waiversBadge = document.getElementById('waivers-badge');
  const signingsBadge = document.getElementById('signings-badge');

  if (waiversCount) {
    waiversCount.textContent = `${currentLimits.waiversUsed}/${currentLimits.waiversMax}`;
  }
  if (signingsCount) {
    signingsCount.textContent = `${currentLimits.signingsUsed}/${currentLimits.signingsMax}`;
  }
  if (waiversBadge) {
    waiversBadge.classList.toggle('bg-danger', currentLimits.waiversUsed >= currentLimits.waiversMax);
    waiversBadge.classList.toggle('bg-secondary', currentLimits.waiversUsed < currentLimits.waiversMax);
  }
  if (signingsBadge) {
    signingsBadge.classList.toggle('bg-danger', currentLimits.signingsUsed >= currentLimits.signingsMax);
    signingsBadge.classList.toggle('bg-success', currentLimits.signingsUsed < currentLimits.signingsMax);
  }
}

function remainingSignings() {
  return Math.max(0, currentLimits.signingsMax - currentLimits.signingsUsed);
}

async function loadFreeAgents() {
  const container = document.getElementById('fa-list');
  
  try {
    const data = await api('free-agency.php?action=list');
    allFreeAgents = data.free_agents || [];
    renderFreeAgents(allFreeAgents);
  } catch (err) {
    container.innerHTML = '<div class="col-12"><div class="alert alert-danger">Erro ao carregar free agents</div></div>';
    console.error(err);
  }
}

function filterFreeAgents() {
  const search = document.getElementById('search-fa').value.toLowerCase();
  const position = document.getElementById('filter-position').value;
  
  const filtered = allFreeAgents.filter(fa => {
    const matchName = fa.name.toLowerCase().includes(search);
    const matchPos = !position || fa.position === position || fa.secondary_position === position;
    return matchName && matchPos;
  });
  
  renderFreeAgents(filtered);
}

function renderFreeAgents(agents) {
  const container = document.getElementById('fa-list');
  
  if (agents.length === 0) {
    container.innerHTML = `
      <div class="col-12">
        <div class="alert alert-info">
          <i class="bi bi-info-circle me-2"></i>Nenhum jogador disponível na Free Agency no momento.
        </div>
      </div>
    `;
    return;
  }
  
  container.innerHTML = agents.map(fa => {
    const posDisplay = fa.secondary_position ? `${fa.position}/${fa.secondary_position}` : fa.position;
    const ovrColor = getOvrColor(fa.ovr);
    const limitReached = remainingSignings() <= 0;
    const disabledAttr = limitReached ? 'disabled' : '';
    const limitLabel = limitReached ? '<small class="text-danger d-block mt-1">Limite de contratações atingido</small>' : '';
    
    return `
      <div class="col-md-6 col-lg-4">
        <div class="fa-card">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="player-name">${fa.name}</div>
              <div class="player-info">
                <span class="badge bg-orange">${posDisplay}</span>
                <span class="ms-2">${fa.age} anos</span>
              </div>
              <div class="player-info mt-1">
                <small>Ex: ${fa.original_team_name || 'Desconhecido'}</small>
              </div>
              ${fa.pending_offers > 0 ? `<small class="text-warning"><i class="bi bi-clock me-1"></i>${fa.pending_offers} proposta(s)</small>` : ''}
            </div>
            <div class="text-end">
              <div class="player-ovr" style="color: ${ovrColor}">${fa.ovr}</div>
              <button class="btn btn-sm btn-orange mt-2" ${disabledAttr} onclick="openOfferModal(${fa.id}, '${fa.name.replace(/'/g, "\\'")}', '${posDisplay}', ${fa.ovr})">
                <i class="bi bi-send"></i> Propor
              </button>
              ${limitLabel}
            </div>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

function openOfferModal(faId, name, position, ovr) {
  if (!canSendMoreOffers()) {
    alert('Você já atingiu o limite de contratações para esta temporada (3).');
    return;
  }
  document.getElementById('offer-fa-id').value = faId;
  document.getElementById('offer-player-name').textContent = name;
  document.getElementById('offer-player-info').textContent = `${position} - OVR ${ovr}`;
  document.getElementById('offer-notes').value = '';
  
  new bootstrap.Modal(document.getElementById('offerModal')).show();
}

function canSendMoreOffers() {
  return remainingSignings() > 0;
}

async function sendOffer() {
  const faId = document.getElementById('offer-fa-id').value;
  const notes = document.getElementById('offer-notes').value;
  if (!canSendMoreOffers()) {
    alert('Você já atingiu o limite de contratações para esta temporada (3).');
    bootstrap.Modal.getInstance(document.getElementById('offerModal'))?.hide();
    return;
  }
  
  try {
    const data = await api('free-agency.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'offer',
        free_agent_id: parseInt(faId),
        notes
      })
    });
    
    alert(data.message || 'Proposta enviada!');
    bootstrap.Modal.getInstance(document.getElementById('offerModal')).hide();
    loadFreeAgents();
    loadMyOffers();
    loadLimits();
  } catch (err) {
    alert(err.error || 'Erro ao enviar proposta');
  }
}

async function loadMyOffers() {
  const container = document.getElementById('my-offers-list');
  if (!container) return;
  
  try {
    const data = await api('free-agency.php?action=my_offers');
    const offers = data.offers || [];
    
    if (offers.length === 0) {
      container.innerHTML = `
        <div class="alert alert-info">
          <i class="bi bi-info-circle me-2"></i>Você não enviou nenhuma proposta ainda.
        </div>
      `;
      return;
    }
    
    container.innerHTML = offers.map(o => {
      const statusBadge = {
        pending: '<span class="badge bg-warning">Pendente</span>',
        accepted: '<span class="badge bg-success">Aceita</span>',
        rejected: '<span class="badge bg-danger">Rejeitada</span>'
      }[o.status];
      
      return `
        <div class="fa-card">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <strong class="text-white">${o.player_name}</strong>
              <span class="text-light-gray ms-2">${o.position} - OVR ${o.ovr}</span>
              ${o.notes ? `<p class="text-light-gray mb-0 mt-1"><small>"${o.notes}"</small></p>` : ''}
            </div>
            <div class="text-end">
              ${statusBadge}
              ${o.status === 'pending' ? `
                <button class="btn btn-sm btn-outline-danger ms-2" onclick="cancelOffer(${o.id})">
                  <i class="bi bi-x"></i>
                </button>
              ` : ''}
            </div>
          </div>
        </div>
      `;
    }).join('');
  } catch (err) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar propostas</div>';
    console.error(err);
  }
}

async function cancelOffer(offerId) {
  if (!confirm('Cancelar esta proposta?')) return;
  
  try {
    await api(`free-agency.php?action=cancel_offer&offer_id=${offerId}`, { method: 'DELETE' });
    loadMyOffers();
    loadFreeAgents();
  } catch (err) {
    alert(err.error || 'Erro ao cancelar');
  }
}

async function loadAdminOffers() {
  const container = document.getElementById('admin-offers-list');
  if (!container) return;
  
  try {
    const data = await api('free-agency.php?action=admin_offers');
    const players = data.players || [];
    
    if (players.length === 0) {
      container.innerHTML = `
        <div class="alert alert-info">
          <i class="bi bi-info-circle me-2"></i>Nenhuma proposta pendente.
        </div>
      `;
      return;
    }
    
    container.innerHTML = players.map(p => `
      <div class="fa-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h5 class="text-white mb-1">${p.player.name}</h5>
            <span class="badge bg-orange">${p.player.position}</span>
            <span class="text-light-gray ms-2">OVR ${p.player.ovr} | ${p.player.age} anos</span>
            <br><small class="text-light-gray">Ex: ${p.player.original_team || 'Desconhecido'}</small>
          </div>
          <span class="badge bg-secondary">${p.offers.length} proposta(s)</span>
        </div>
        <div class="ps-3 border-start border-orange">
          ${p.offers.map(o => `
            <div class="offer-card d-flex justify-content-between align-items-center">
              <div>
                <strong class="text-white">${o.team_name}</strong>
                ${o.notes ? `<p class="text-light-gray mb-0"><small>"${o.notes}"</small></p>` : ''}
              </div>
              <div>
                <button class="btn btn-sm btn-success me-1" onclick="approveOffer(${o.id})">
                  <i class="bi bi-check-lg"></i> Aprovar
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="rejectOffer(${o.id})">
                  <i class="bi bi-x-lg"></i>
                </button>
              </div>
            </div>
          `).join('')}
        </div>
      </div>
    `).join('');
  } catch (err) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar propostas</div>';
    console.error(err);
  }
}

async function approveOffer(offerId) {
  if (!confirm('Aprovar esta proposta? O jogador será contratado pelo time.')) return;
  
  try {
    const data = await api('free-agency.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'approve', offer_id: offerId })
    });
    alert(data.message || 'Proposta aprovada!');
    loadAdminOffers();
    loadFreeAgents();
  } catch (err) {
    alert(err.error || 'Erro ao aprovar');
  }
}

async function rejectOffer(offerId) {
  if (!confirm('Rejeitar esta proposta?')) return;
  
  try {
    await api('free-agency.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'reject', offer_id: offerId })
    });
    loadAdminOffers();
  } catch (err) {
    alert(err.error || 'Erro ao rejeitar');
  }
}

function openCreateFaModal() {
  const form = document.getElementById('createFaForm');
  if (form) {
    form.reset();
  }
  const leagueSelect = document.getElementById('faLeague');
  if (leagueSelect && window.__USER_LEAGUE__) {
    leagueSelect.value = window.__USER_LEAGUE__;
  }
  const modalEl = document.getElementById('createFaModal');
  if (modalEl) new bootstrap.Modal(modalEl).show();
}

async function submitCreateFreeAgent() {
  const payload = {
    action: 'create_free_agent',
    name: document.getElementById('faName').value.trim(),
    age: parseInt(document.getElementById('faAge').value, 10),
    ovr: parseInt(document.getElementById('faOvr').value, 10),
    position: document.getElementById('faPosition').value,
    secondary_position: document.getElementById('faSecondary').value || null,
    league: document.getElementById('faLeague').value,
    original_team_name: document.getElementById('faOriginal').value.trim()
  };

  if (!payload.name || !payload.age || !payload.ovr || !payload.position) {
    alert('Preencha nome, idade, OVR e posição.');
    return;
  }

  try {
    const data = await api('free-agency.php', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    alert(data.message || 'Free agent criado!');
    bootstrap.Modal.getInstance(document.getElementById('createFaModal'))?.hide();
    await loadFreeAgents();
    if (window.__IS_ADMIN__) {
      await loadAdminOffers();
    }
  } catch (err) {
    alert(err.error || 'Erro ao criar free agent');
  }
}
