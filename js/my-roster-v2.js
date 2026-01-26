// my-roster-v2.js - Sistema de Grid Responsivo
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

// Função para calcular cor do OVR
function getOvrColor(ovr) {
  if (ovr >= 95) return '#00ff00';
  if (ovr >= 89) return '#00dd00';
  if (ovr >= 84) return '#ffff00';
  if (ovr >= 79) return '#ffd700';
  if (ovr >= 72) return '#ff9900';
  return '#ff4444';
}

// Helper para cor do badge de função
function getRoleBadgeColor(role) {
  switch(role) {
    case 'Titular': return '#28a745';
    case 'Banco': return '#ffc107';
    case 'G-League': return '#17a2b8';
    default: return '#6c757d';
  }
}

// Ordem padrão
const roleOrder = { 'Titular': 0, 'Banco': 1, 'Outro': 2, 'G-League': 3 };
const starterPositionOrder = { PG: 0, SG: 1, SF: 2, PF: 3, C: 4 };

let allPlayers = [];
let currentSort = { field: 'role', ascending: true };
const DEFAULT_FA_LIMITS = { waiversUsed: 0, waiversMax: 3, signingsUsed: 0, signingsMax: 3 };
let currentFALimits = { ...DEFAULT_FA_LIMITS };

async function loadFreeAgencyLimits() {
  if (!window.__TEAM_ID__) return;
  try {
    const data = await api('free-agency.php?action=limits');
    currentFALimits = {
      waiversUsed: Number.isFinite(data.waivers_used) ? data.waivers_used : 0,
      waiversMax: Number.isFinite(data.waivers_max) && data.waivers_max > 0 ? data.waivers_max : DEFAULT_FA_LIMITS.waiversMax,
      signingsUsed: Number.isFinite(data.signings_used) ? data.signings_used : 0,
      signingsMax: Number.isFinite(data.signings_max) && data.signings_max > 0 ? data.signings_max : DEFAULT_FA_LIMITS.signingsMax,
    };
  } catch (err) {
    console.warn('Não foi possível carregar limites de FA:', err);
    currentFALimits = { ...DEFAULT_FA_LIMITS };
  }
  updateFreeAgencyCounters();
}

function updateFreeAgencyCounters() {
  const waiversEl = document.getElementById('waivers-count');
  const signingsEl = document.getElementById('signings-count');
  if (waiversEl) {
    waiversEl.textContent = `${currentFALimits.waiversUsed} / ${currentFALimits.waiversMax}`;
    waiversEl.classList.toggle('text-danger', currentFALimits.waiversMax && currentFALimits.waiversUsed >= currentFALimits.waiversMax);
  }
  if (signingsEl) {
    signingsEl.textContent = `${currentFALimits.signingsUsed} / ${currentFALimits.signingsMax}`;
    signingsEl.classList.toggle('text-danger', currentFALimits.signingsMax && currentFALimits.signingsUsed >= currentFALimits.signingsMax);
  }
}

function sortPlayers(field) {
  if (currentSort.field === field) {
    currentSort.ascending = !currentSort.ascending;
  } else {
    currentSort.field = field;
    currentSort.ascending = field !== 'role';
  }
  renderPlayers(allPlayers);
}

function renderPlayers(players) {
  let sorted = [...players];
  sorted.sort((a, b) => {
    let aVal = a[currentSort.field];
    let bVal = b[currentSort.field];
    
    if (currentSort.field === 'role') {
      aVal = roleOrder[aVal] ?? 999;
      bVal = roleOrder[bVal] ?? 999;
    }
    if (currentSort.field === 'trade') {
      aVal = a.available_for_trade ? 1 : 0;
      bVal = b.available_for_trade ? 1 : 0;
    }
    if (['ovr', 'age', 'seasons_in_league'].includes(currentSort.field)) {
      aVal = Number(aVal);
      bVal = Number(bVal);
    }

    if (aVal < bVal) return currentSort.ascending ? -1 : 1;
    if (aVal > bVal) return currentSort.ascending ? 1 : -1;

    if (currentSort.field === 'role' && a.role === 'Titular' && b.role === 'Titular') {
      const aPos = starterPositionOrder[a.position] ?? 999;
      const bPos = starterPositionOrder[b.position] ?? 999;
      if (aPos !== bPos) {
        return currentSort.ascending ? aPos - bPos : bPos - aPos;
      }
    }
    return 0;
  });

  const grid = document.getElementById('players-grid');
  grid.innerHTML = '';

  sorted.forEach(p => {
    const ovrColor = getOvrColor(p.ovr);
    
    const col = document.createElement('div');
    col.className = 'col-12 col-sm-6 col-lg-4 col-xl-3';
    
    const card = document.createElement('div');
    card.className = 'card bg-dark border-orange h-100';
    card.style.transition = 'transform 0.2s, box-shadow 0.2s';
    card.style.cursor = 'pointer';
    
    card.addEventListener('mouseenter', () => {
      card.style.transform = 'translateY(-4px)';
      card.style.boxShadow = '0 8px 24px rgba(252, 0, 37, 0.3)';
    });
    card.addEventListener('mouseleave', () => {
      card.style.transform = 'translateY(0)';
      card.style.boxShadow = '';
    });
    
    card.innerHTML = `
      <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div class="flex-grow-1 me-2">
            <h6 class="text-white mb-1 fw-bold" style="font-size: 1.1rem;">${p.name}</h6>
            <div class="d-flex gap-2 flex-wrap">
              <span class="badge bg-secondary">${p.position}${p.secondary_position ? '/' + p.secondary_position : ''}</span>
              <span class="badge" style="background: ${getRoleBadgeColor(p.role)};">${p.role}</span>
            </div>
          </div>
          <div class="text-end">
            <div class="fw-bold" style="font-size: 2rem; line-height: 1; color: ${ovrColor};">${p.ovr}</div>
            <small class="text-light-gray">${p.age} anos</small>
          </div>
        </div>
        <div class="d-flex gap-2 mt-3">
          <button class="btn btn-sm btn-outline-light flex-fill btn-edit-player" data-id="${p.id}" title="Editar">
            <i class="bi bi-pencil"></i>
          </button>
          <button class="btn btn-sm btn-outline-warning flex-fill btn-waive-player" data-id="${p.id}" data-name="${p.name}" title="Dispensar">
            <i class="bi bi-hand-thumbs-down"></i>
          </button>
          <button class="btn btn-sm btn-outline-danger flex-fill btn-retire-player" data-id="${p.id}" data-name="${p.name}" title="Aposentar">
            <i class="bi bi-box-arrow-right"></i>
          </button>
          <button class="btn btn-sm flex-fill btn-toggle-trade ${p.available_for_trade ? 'btn-outline-success' : 'btn-outline-danger'}" data-id="${p.id}" data-trade="${p.available_for_trade}" title="Disponibilidade para Troca">
            <i class="bi ${p.available_for_trade ? 'bi-check-circle' : 'bi-x-circle'} me-1"></i>
            ${p.available_for_trade ? 'Disponível' : 'Indisp.'}
          </button>
        </div>
      </div>
    `;
    
    col.appendChild(card);
    grid.appendChild(col);
  });
  
  document.getElementById('players-status').style.display = 'none';
  grid.style.display = '';
  updateRosterStats();
}

function updateRosterStats() {
  const totalPlayers = allPlayers.length;
  const topEight = allPlayers
    .sort((a, b) => Number(b.ovr) - Number(a.ovr))
    .slice(0, 8)
    .reduce((sum, p) => sum + Number(p.ovr), 0);
  
  document.getElementById('total-players').textContent = totalPlayers;
  document.getElementById('cap-top8').textContent = topEight;
}

async function loadPlayers() {
  const teamId = window.__TEAM_ID__;
  const statusEl = document.getElementById('players-status');
  const gridEl = document.getElementById('players-grid');
  
  if (!teamId) {
    if (statusEl) {
      statusEl.innerHTML = `<div class="alert alert-warning text-center"><i class="bi bi-exclamation-triangle me-2"></i>Você ainda não possui um time.</div>`;
      statusEl.style.display = 'block';
    }
    if (gridEl) gridEl.style.display = 'none';
    return;
  }
  
  if (statusEl) {
    statusEl.innerHTML = `<div class="spinner-border text-orange" role="status"></div><p class="text-light-gray mt-2">Carregando jogadores...</p>`;
    statusEl.style.display = 'block';
  }
  if (gridEl) gridEl.style.display = 'none';
  
  try {
    const data = await api(`players.php?team_id=${teamId}`);
    allPlayers = data.players || [];
    currentSort = { field: 'role', ascending: true };
    renderPlayers(allPlayers);
    if (statusEl) statusEl.style.display = 'none';
  } catch (err) {
    console.error('Erro ao carregar:', err);
    if (statusEl) {
      statusEl.innerHTML = `<div class="alert alert-danger text-center"><i class="bi bi-x-circle me-2"></i>Erro ao carregar jogadores: ${err.error || 'Desconhecido'}</div>`;
      statusEl.style.display = 'block';
    }
  }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
  loadPlayers();
  loadFreeAgencyLimits();
  
  document.getElementById('btn-refresh-players')?.addEventListener('click', loadPlayers);
  document.getElementById('sort-select')?.addEventListener('change', (e) => sortPlayers(e.target.value));
  
  // Delegação de eventos para botões
  document.getElementById('players-grid')?.addEventListener('click', async (e) => {
    const target = e.target.closest('button');
    if (!target) return;
    
    if (target.classList.contains('btn-toggle-trade')) {
      const playerId = target.dataset.id;
      const newStatus = target.dataset.trade === 'true' ? 0 : 1;
      try {
        await api('players.php', {
          method: 'PUT',
          body: JSON.stringify({ id: playerId, available_for_trade: newStatus })
        });
        loadPlayers();
      } catch (err) {
        alert('Erro ao atualizar: ' + (err.error || 'Desconhecido'));
      }
    }
    
    if (target.classList.contains('btn-edit-player')) {
      const playerId = target.dataset.id;
      const player = allPlayers.find(p => p.id == playerId);
      if (player) {
        document.getElementById('edit-player-id').value = player.id;
        document.getElementById('edit-name').value = player.name;
        document.getElementById('edit-age').value = player.age;
        document.getElementById('edit-position').value = player.position;
        document.getElementById('edit-secondary-position').value = player.secondary_position || '';
        document.getElementById('edit-ovr').value = player.ovr;
        document.getElementById('edit-role').value = player.role;
        document.getElementById('edit-available').checked = player.available_for_trade;
        new bootstrap.Modal(document.getElementById('editPlayerModal')).show();
      }
    }
    
    if (target.classList.contains('btn-waive-player')) {
      const playerId = target.dataset.id;
      const playerName = target.dataset.name;
      if (confirm(`Dispensar ${playerName}?`)) {
        try {
          await api('free-agency.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'waive', player_id: playerId })
          });
          alert('Jogador dispensado!');
          loadPlayers();
          loadFreeAgencyLimits();
        } catch (err) {
          alert('Erro: ' + (err.error || 'Desconhecido'));
        }
      }
    }
    
    if (target.classList.contains('btn-retire-player')) {
      const playerId = target.dataset.id;
      const playerName = target.dataset.name;
      if (confirm(`Aposentar ${playerName}?`)) {
        try {
          await api('players.php', { method: 'DELETE', body: JSON.stringify({ id: playerId }) });
          alert('Jogador aposentado!');
          loadPlayers();
        } catch (err) {
          alert('Erro: ' + (err.error || 'Desconhecido'));
        }
      }
    }
  });
  
  // Salvar edição
  document.getElementById('btn-save-edit')?.addEventListener('click', async () => {
    const data = {
      id: document.getElementById('edit-player-id').value,
      name: document.getElementById('edit-name').value,
      age: document.getElementById('edit-age').value,
      position: document.getElementById('edit-position').value,
      secondary_position: document.getElementById('edit-secondary-position').value || null,
      ovr: document.getElementById('edit-ovr').value,
      role: document.getElementById('edit-role').value,
      available_for_trade: document.getElementById('edit-available').checked ? 1 : 0
    };
    try {
      await api('players.php', { method: 'PUT', body: JSON.stringify(data) });
      bootstrap.Modal.getInstance(document.getElementById('editPlayerModal')).hide();
      loadPlayers();
    } catch (err) {
      alert('Erro ao salvar: ' + (err.error || 'Desconhecido'));
    }
  });
});
