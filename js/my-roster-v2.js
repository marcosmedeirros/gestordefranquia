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

// Fotos oficiais dos jogadores (NBA CDN)
const NBA_HEADSHOT_BASE_URL = 'https://ak-static.cms.nba.com/wp-content/uploads/headshots/nba/latest/260x190/';
const NBA_HEADSHOT_FALLBACK_URL = 'https://cdn.nba.com/headshots/nba/latest/1040x760/fallback.png';
const NBA_HEADSHOT_LOOKUP_CACHE = new Map();


function normalizeNameForNbaUrl(name) {
  if (!name) return '';
  return name
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // remove acentos
    .replace(/[^a-zA-Z0-9 ]/g, '')
    .replace(/\s+/g, '-')
    .toLowerCase();
}

function getPlayerHeadshotUrl(player) {
  if (player?.nba_player_id) {
    return `${NBA_HEADSHOT_BASE_URL}${player.nba_player_id}.png`;
  }
  // Fallback visual: tenta pelo nome (nem sempre existe), senão fallback
  if (player?.name) {
    const norm = normalizeNameForNbaUrl(player.name);
    return `${NBA_HEADSHOT_BASE_URL}${norm}.png`;
  }
  return NBA_HEADSHOT_FALLBACK_URL;
}

function applyPlayerHeadshot(imgEl, player) {
  if (!imgEl) return;
  if (!imgEl.dataset.headshotBound) {
    imgEl.dataset.headshotBound = 'true';
    imgEl.addEventListener('error', () => {
      if (imgEl.dataset.fallbackApplied === 'true') return;
      imgEl.dataset.fallbackApplied = 'true';
      imgEl.src = NBA_HEADSHOT_FALLBACK_URL;
    });
  }
  imgEl.dataset.fallbackApplied = 'false';
  imgEl.src = getPlayerHeadshotUrl(player);
}

async function ensurePlayerHeadshot(imgEl, player) {
  if (!imgEl || !player) return;

  // 1) Se já tem ID, aplica direto
  if (player.nba_player_id) {
    applyPlayerHeadshot(imgEl, player);
    return;
  }

  // 2) Mostra um fallback rápido enquanto resolve
  imgEl.src = NBA_HEADSHOT_FALLBACK_URL;

  // 3) Tenta resolver via API (salva no banco automaticamente)
  const cacheKey = player.id || player.name;
  if (cacheKey && NBA_HEADSHOT_LOOKUP_CACHE.has(cacheKey)) {
    const cached = NBA_HEADSHOT_LOOKUP_CACHE.get(cacheKey);
    if (cached && typeof cached === 'object') {
      player.nba_player_id = cached.nba_player_id;
      player.headshot_url = cached.headshot_url;
      applyPlayerHeadshot(imgEl, player);
      return;
    }
    if (cached === false) {
      // já falhou antes: tenta só o fallback via nome
      applyPlayerHeadshot(imgEl, player);
      return;
    }
  }

  if (cacheKey) NBA_HEADSHOT_LOOKUP_CACHE.set(cacheKey, null);

  try {
    const res = await api('nba-player-lookup.php', {
      method: 'POST',
      body: JSON.stringify({ player_id: player.id, player_name: player.name })
    });

    if (res?.success && res?.nba_player_id) {
      player.nba_player_id = res.nba_player_id;
      if (res.headshot_url) player.headshot_url = res.headshot_url;
      if (cacheKey) NBA_HEADSHOT_LOOKUP_CACHE.set(cacheKey, { nba_player_id: res.nba_player_id, headshot_url: res.headshot_url });
      applyPlayerHeadshot(imgEl, player);
      return;
    }

    if (cacheKey) NBA_HEADSHOT_LOOKUP_CACHE.set(cacheKey, false);
    // sem ID => tenta fallback via nome / fallback
    applyPlayerHeadshot(imgEl, player);
  } catch (err) {
    console.warn('Falha ao buscar NBA ID para', player.name, err);
    if (cacheKey) NBA_HEADSHOT_LOOKUP_CACHE.set(cacheKey, false);
    // Nunca quebra a tela: tenta fallback via nome / fallback
    applyPlayerHeadshot(imgEl, player);
  }
}

// Ordem padrão
const roleOrder = { 'Titular': 0, 'Banco': 1, 'Outro': 2, 'G-League': 3 };
const starterPositionOrder = { PG: 0, SG: 1, SF: 2, PF: 3, C: 4 };

let allPlayers = [];
let currentSort = { field: 'role', ascending: true };
const DEFAULT_FA_LIMITS = { waiversUsed: 0, waiversMax: 3, signingsUsed: 0, signingsMax: 3 };
let currentFALimits = { ...DEFAULT_FA_LIMITS };

function reloadRosterPage() {
  window.location.reload();
}

async function handleSaveEdit() {
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
    const modalEl = document.getElementById('editPlayerModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.hide();
    await loadPlayers();
  } catch (err) {
    alert('Erro ao salvar: ' + (err.error || 'Desconhecido'));
  }
}

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
  const sorted = [...players];
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

  const cardSorted = [...players];
  cardSorted.sort((a, b) => {
    const aRole = roleOrder[a.role] ?? 999;
    const bRole = roleOrder[b.role] ?? 999;
    if (aRole !== bRole) return aRole - bRole;

    if (a.role === 'Titular' && b.role === 'Titular') {
      const aPos = starterPositionOrder[a.position] ?? 999;
      const bPos = starterPositionOrder[b.position] ?? 999;
      if (aPos !== bPos) return aPos - bPos;
    }

    return String(a.name || '').localeCompare(String(b.name || ''));
  });

  const gridEl = document.getElementById('players-grid');
  const listEl = document.getElementById('players-list');
  const listToolbarEl = document.getElementById('players-list-toolbar');
  if (!gridEl || !listEl) return;
  gridEl.innerHTML = '';
  listEl.innerHTML = '';

  const createPlayerCard = (player) => {
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
        <div class="d-flex align-items-center gap-3">
          <div class="player-photo-inline">
            <img class="player-headshot" alt="Foto de ${player.name}">
          </div>
          <div class="flex-grow-1">
            <h6 class="text-white mb-1 fw-bold" style="font-size: 1.1rem;">${player.name}</h6>
            <div class="text-light-gray" style="font-size: 0.85rem;">
              ${player.position}${player.secondary_position ? '/' + player.secondary_position : ''} · ${player.age} anos
            </div>
          </div>
          <div class="text-end">
            <div class="fw-bold" style="font-size: 1.7rem; line-height: 1; color: ${getOvrColor(player.ovr)};">${player.ovr}</div>
            <small class="text-light-gray">OVR</small>
          </div>
        </div>
      </div>
    `;
    ensurePlayerHeadshot(card.querySelector('.player-headshot'), player);

    col.appendChild(card);
    return col;
  };

  const createSection = (title, items, options = {}) => {
    const section = document.createElement('div');
    section.className = 'roster-section';

    const header = document.createElement('div');
    header.className = 'd-flex align-items-center justify-content-between mb-2';
    header.innerHTML = `
      <h6 class="text-white mb-0">${title}</h6>
      <small class="text-light-gray">${items.length} jogador${items.length === 1 ? '' : 'es'}</small>
    `;

    const divider = document.createElement('div');
    divider.className = 'roster-divider';

  const row = document.createElement('div');
  row.className = 'row g-3 justify-content-center';

    if (!items.length) {
      const empty = document.createElement('div');
      empty.className = 'text-light-gray';
      empty.textContent = options.emptyText || 'Sem jogadores nesta seção.';
      section.appendChild(header);
      section.appendChild(divider);
      section.appendChild(empty);
      return section;
    }

    items.forEach((player) => row.appendChild(createPlayerCard(player)));

    section.appendChild(header);
    section.appendChild(divider);
    section.appendChild(row);
    return section;
  };

  const starters = cardSorted.filter((p) => p.role === 'Titular');
  const bench = cardSorted.filter((p) => p.role === 'Banco');
  const others = cardSorted.filter((p) => p.role === 'Outro' || p.role === 'G-League');

  gridEl.appendChild(createSection('Quinteto inicial', starters, { emptyText: 'Sem titulares definidos.' }));
  gridEl.appendChild(createSection('Banco', bench, { emptyText: 'Sem jogadores no banco.' }));
  gridEl.appendChild(createSection('Outros / G-League', others, { emptyText: 'Sem jogadores nesta faixa.' }));

  const listHeader = document.createElement('div');
  listHeader.className = 'roster-list-item text-uppercase text-light-gray fw-semibold';
  listHeader.style.fontSize = '0.7rem';
  listHeader.innerHTML = `
    <div class="d-flex justify-content-between align-items-center">
      <span>Jogador</span>
      <div class="d-flex align-items-center gap-3">
        <span class="roster-list-role text-end">Função</span>
        <span class="text-end" style="min-width: 140px;">Ações</span>
      </div>
    </div>
  `;
  listEl.appendChild(listHeader);

  const listRow = (player) => {
    const item = document.createElement('div');
    item.className = 'roster-list-item';
    item.innerHTML = `
      <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
          <div class="text-white fw-semibold">${player.name}</div>
          <div class="text-light-gray" style="font-size: 0.8rem;">
            ${player.position}${player.secondary_position ? '/' + player.secondary_position : ''} · ${player.age} anos · OVR ${player.ovr}
          </div>
        </div>
        <div class="d-flex align-items-center gap-3">
          <div class="text-end roster-list-role">
            <span class="badge" style="background: ${getRoleBadgeColor(player.role)};">${player.role}</span>
          </div>
          <div class="d-flex gap-2 justify-content-end" style="min-width: 140px;">
            <button class="btn btn-sm btn-outline-light btn-edit-player" data-id="${player.id}" title="Editar" data-bs-toggle="tooltip" data-bs-placement="top">
              <i class="bi bi-pencil"></i>
            </button>
            <button class="btn btn-sm btn-outline-warning btn-waive-player" data-id="${player.id}" data-name="${player.name}" title="Dispensar" data-bs-toggle="tooltip" data-bs-placement="top">
              <i class="bi bi-hand-thumbs-down"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger btn-retire-player" data-id="${player.id}" data-name="${player.name}" title="Aposentar" data-bs-toggle="tooltip" data-bs-placement="top">
              <i class="bi bi-box-arrow-right"></i>
            </button>
            <button class="btn btn-sm btn-toggle-trade ${player.available_for_trade ? 'btn-outline-success' : 'btn-outline-danger'}" data-id="${player.id}" data-trade="${player.available_for_trade}" title="Disponibilidade para Troca" data-bs-toggle="tooltip" data-bs-placement="top">
              <i class="bi ${player.available_for_trade ? 'bi-check-circle' : 'bi-x-circle'}"></i>
            </button>
          </div>
        </div>
      </div>
    `;
    return item;
  };

  sorted.forEach((player) => listEl.appendChild(listRow(player)));

  document.getElementById('players-status').style.display = 'none';
  gridEl.style.display = '';
  listEl.style.display = '';
  if (listToolbarEl) listToolbarEl.style.display = '';
  const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
  tooltipTriggerList.forEach((el) => {
    if (!el.dataset.tooltipBound) {
      el.dataset.tooltipBound = 'true';
      new bootstrap.Tooltip(el);
    }
  });
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
    const listEl = document.getElementById('players-list');
    if (listEl) listEl.style.display = 'none';
    const listToolbarEl = document.getElementById('players-list-toolbar');
    if (listToolbarEl) listToolbarEl.style.display = 'none';
    return;
  }
  
  if (statusEl) {
    statusEl.innerHTML = `<div class="spinner-border text-orange" role="status"></div><p class="text-light-gray mt-2">Carregando jogadores...</p>`;
    statusEl.style.display = 'block';
  }
  if (gridEl) gridEl.style.display = 'none';
  const listEl = document.getElementById('players-list');
  if (listEl) listEl.style.display = 'none';
  const listToolbarEl = document.getElementById('players-list-toolbar');
  if (listToolbarEl) listToolbarEl.style.display = 'none';
  
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
  document.addEventListener('click', async (e) => {
    const target = e.target.closest('button');
    if (!target) return;

    if (target.id === 'btn-save-edit') {
      e.preventDefault();
      await handleSaveEdit();
      return;
    }
    
    if (target.classList.contains('btn-toggle-trade')) {
      const playerId = target.dataset.id;
      const newStatus = target.dataset.trade === 'true' ? 0 : 1;
      try {
        await api('players.php', {
          method: 'PUT',
          body: JSON.stringify({ id: playerId, available_for_trade: newStatus })
        });
        reloadRosterPage();
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
        const modalEl = document.getElementById('editPlayerModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
      }
    }
    
    if (target.classList.contains('btn-waive-player')) {
      const playerId = target.dataset.id;
      const playerName = target.dataset.name;
      if (confirm(`Dispensar ${playerName}?`)) {
        try {
          const res = await api('players.php', {
            method: 'DELETE',
            body: JSON.stringify({ id: playerId })
          });
          alert(res.message || 'Jogador dispensado e enviado para a Free Agency!');
          reloadRosterPage();
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
          reloadRosterPage();
        } catch (err) {
          alert('Erro: ' + (err.error || 'Desconhecido'));
        }
      }
    }
  });
});
