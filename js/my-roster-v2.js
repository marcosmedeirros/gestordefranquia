// my-roster-v2.js - Tabela + Quinteto Titular
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

function getOvrColor(ovr) {
  if (ovr >= 95) return '#00ff00';
  if (ovr >= 89) return '#00dd00';
  if (ovr >= 84) return '#ffff00';
  if (ovr >= 79) return '#ffd700';
  if (ovr >= 72) return '#ff9900';
  return '#ff4444';
}

function getPlayerPhotoUrl(player) {
  const customPhoto = (player.foto_adicional || '').toString().trim();
  if (customPhoto) {
    if (/^https?:\/\//i.test(customPhoto)) {
      return customPhoto;
    }
    return `/${customPhoto.replace(/^\/+/, '')}`;
  }
  return player.nba_player_id
    ? `https://cdn.nba.com/headshots/nba/latest/1040x760/${player.nba_player_id}.png`
    : `https://ui-avatars.com/api/?name=${encodeURIComponent(player.name)}&background=121212&color=f17507&rounded=true&bold=true`;
}

function convertToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

function normalizeRoleKey(role) {
  const normalized = (role || '').toString().trim().toLowerCase();
  if (normalized === 'titular') return 'Titular';
  if (normalized === 'banco') return 'Banco';
  if (normalized === 'g-league' || normalized === 'gleague' || normalized === 'g league') return 'G-League';
  return 'Outro';
}

const roleOrder = { 'Titular': 0, 'Banco': 1, 'Outro': 2, 'G-League': 3 };
const starterPositionOrder = { PG: 0, SG: 1, SF: 2, PF: 3, C: 4 };

let allPlayers = [];
let currentSort = { field: 'role', ascending: true };
let currentSearch = '';
let currentRoleFilter = '';
let editPhotoFile = null;

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

function applyFilters(players) {
  const term = currentSearch.trim().toLowerCase();
  const roleFilter = currentRoleFilter;
  return players.filter(p => {
    const roleOk = !roleFilter || normalizeRoleKey(p.role) === normalizeRoleKey(roleFilter);
    if (!term) return roleOk;
    const hay = `${p.name} ${p.position} ${p.secondary_position || ''}`.toLowerCase();
    return roleOk && hay.includes(term);
  });
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
  let sorted = applyFilters([...players]);
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

    // Em caso de empate por função, ordenar por posição de armador a pivô
    if (currentSort.field === 'role' && a.role === 'Titular' && b.role === 'Titular') {
      const aPos = starterPositionOrder[a.position] ?? 999;
      const bPos = starterPositionOrder[b.position] ?? 999;
      if (aPos !== bPos) {
        return currentSort.ascending ? aPos - bPos : bPos - aPos;
      }
    }
    return 0;
  });

  // Renderizar Quinteto Titular (grid) + Banco (lista lateral)
  const grid = document.getElementById('players-grid');
  if (grid) {
    grid.innerHTML = '';
    const titulares = sorted.filter(p => normalizeRoleKey(p.role) === 'Titular');
    titulares.sort((a, b) => {
      const pa = starterPositionOrder[a.position] ?? 999;
      const pb = starterPositionOrder[b.position] ?? 999;
      if (pa !== pb) return pa - pb;
      return Number(b.ovr) - Number(a.ovr);
    });
    const starters = titulares.slice(0, 5);
    const bench = sorted
      .filter(p => normalizeRoleKey(p.role) === 'Banco')
      .sort((a, b) => Number(b.ovr) - Number(a.ovr));

    const row = document.createElement('div');
    row.className = 'row g-3';

    const colLeft = document.createElement('div');
    colLeft.className = 'col-12 col-lg-8';
    const startersSection = document.createElement('div');
    startersSection.className = 'roster-section';
    startersSection.innerHTML = '<h5>Quinteto Titular</h5>';
    if (starters.length === 0) {
      startersSection.innerHTML += '<div class="text-center text-light-gray">Sem jogadores marcados como Titular.</div>';
    } else {
      const list = document.createElement('div');
      list.className = 'row g-3';
      starters.forEach(p => {
        const ovrColor = getOvrColor(p.ovr);
        const photoUrl = getPlayerPhotoUrl(p);
        const col = document.createElement('div');
        col.className = 'col-12 col-sm-6 col-md-4';
        const card = document.createElement('div');
        card.className = 'card border-orange h-100 roster-card text-center';
        card.innerHTML = `
          <div class=\"card-body p-3 d-flex flex-column gap-3 align-items-center\">\n            <img src=\"${photoUrl}\" alt=\"${p.name}\" style=\"width: 72px; height: 72px; object-fit: cover; border-radius: 50%; border: 2px solid var(--fba-orange); background: #1a1a1a;\" onerror=\"this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=f17507&rounded=true&bold=true'\">\n            <div class=\"text-center\">\n              <h6 class=\"text-white mb-1 fw-bold\" style=\"font-size: 1.05rem;\">${p.name}</h6>\n              <div class=\"d-flex justify-content-center gap-2 flex-wrap small\">\n                <span class=\"badge bg-secondary\">${p.position}${p.secondary_position ? '/' + p.secondary_position : ''}</span>\n              </div>\n            </div>\n            <div class=\"text-center\">\n              <div class=\"fw-bold\" style=\"font-size: 1.8rem; line-height: 1; color: ${ovrColor};\">${p.ovr}</div>\n              <small class=\"text-light-gray\">${p.age} anos</small>\n            </div>\n          </div>`;
        col.appendChild(card);
        list.appendChild(col);
      });
      startersSection.appendChild(list);
    }
    colLeft.appendChild(startersSection);

    const colRight = document.createElement('div');
    colRight.className = 'col-12 col-lg-4';
    const benchSection = document.createElement('div');
    benchSection.className = 'roster-section';
    benchSection.innerHTML = '<h5>Banco</h5>';
    if (bench.length === 0) {
      benchSection.innerHTML += '<div class="text-center text-light-gray">Sem jogadores no banco.</div>';
    } else {
      const ul = document.createElement('ul');
      ul.className = 'list-group list-group-flush';
      bench.forEach(p => {
        const li = document.createElement('li');
        li.className = 'list-group-item bg-transparent text-white d-flex justify-content-between align-items-center px-0';
        li.innerHTML = `
          <span>${p.name} <small class=\"text-light-gray\">(${p.position}${p.secondary_position ? '/' + p.secondary_position : ''})</small></span>
          <span class=\"fw-bold\" style=\"color:${getOvrColor(p.ovr)}\">${p.ovr}</span>`;
        ul.appendChild(li);
      });
      benchSection.appendChild(ul);
    }
    colRight.appendChild(benchSection);

    row.appendChild(colLeft);
    row.appendChild(colRight);
    grid.appendChild(row);

    document.getElementById('players-status').style.display = 'none';
    grid.style.display = '';
  }

  renderPlayersMobileCards(sorted);

  const statusEl = document.getElementById('players-status');
  if (statusEl) {
    statusEl.style.display = 'none';
  }

  updateRosterStats();
  try {
    renderPlayersTable(sorted);
  } catch (e) {
    console.warn('Falha ao renderizar tabela:', e);
  }
}

function renderPlayersMobileCards(players) {
  const container = document.getElementById('players-mobile-cards');
  if (!container) return;
  container.innerHTML = '';
  container.style.display = '';
  if (!players || players.length === 0) {
    container.innerHTML = '<div class="text-center text-light-gray">Nenhum jogador encontrado.</div>';
    return;
  }

  players.forEach(p => {
    const canRetire = Number(p.age) >= 35;
    const photoUrl = getPlayerPhotoUrl(p);
    const card = document.createElement('div');
    card.className = 'roster-mobile-card';
    card.innerHTML = `
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="d-flex align-items-center gap-2">
          <img src="${photoUrl}" alt="${p.name}"
               style="width: 44px; height: 44px; object-fit: cover; border-radius: 50%; border: 1px solid var(--fba-orange); background: #1a1a1a;"
               onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=f17507&rounded=true&bold=true'">
          <div>
            <div class="text-white fw-bold">${p.name}</div>
            <div class="text-light-gray small">${p.position}${p.secondary_position ? '/' + p.secondary_position : ''} • ${normalizeRoleKey(p.role)}</div>
          </div>
        </div>
        <div class="text-end">
          <div class="fw-bold" style="color:${getOvrColor(p.ovr)}; font-size: 1.2rem;">${p.ovr}</div>
          <small class="text-light-gray">${p.age} anos</small>
        </div>
      </div>
      <div class="mt-2">
        ${p.available_for_trade ? '<span class="badge bg-success">Disponível</span>' : '<span class="badge bg-secondary">Indisp.</span>'}
      </div>
      <div class="roster-mobile-actions mt-3">
        <button class="btn btn-outline-light btn-sm btn-edit-player" data-id="${p.id}" title="Editar"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-outline-warning btn-sm btn-waive-player" data-id="${p.id}" data-name="${p.name}" title="Dispensar"><i class="bi bi-hand-thumbs-down"></i></button>
        ${canRetire ? `<button class="btn btn-outline-danger btn-sm btn-retire-player" data-id="${p.id}" data-name="${p.name}" title="Aposentar"><i class="bi bi-box-arrow-right"></i></button>` : ''}
        <button class="btn btn-sm ${p.available_for_trade ? 'btn-outline-success' : 'btn-outline-danger'} btn-toggle-trade" data-id="${p.id}" data-trade="${p.available_for_trade}" title="Disponibilidade para Troca">
          <i class="bi ${p.available_for_trade ? 'bi-check-circle' : 'bi-x-circle'}"></i>
        </button>
      </div>
    `;
    container.appendChild(card);
  });
}

function renderPlayersTable(players) {
  const wrapper = document.getElementById('players-table-wrapper');
  const tbody = document.getElementById('players-table-body');
  if (!wrapper || !tbody) return;
  tbody.innerHTML = '';
  if (!players || players.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-light-gray">Nenhum jogador encontrado.</td></tr>';
    wrapper.style.display = '';
    return;
  }
  players.forEach(p => {
    const canRetire = Number(p.age) >= 35;
    const photoUrl = getPlayerPhotoUrl(p);
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>
        <div class="d-flex align-items-center gap-2">
          <img src="${photoUrl}" alt="${p.name}"
               style="width: 36px; height: 36px; object-fit: cover; border-radius: 50%; border: 1px solid var(--fba-orange); background: #1a1a1a;"
               onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=f17507&rounded=true&bold=true'">
          <div class="d-flex flex-column">
            <span class="fw-semibold">${p.name}</span>
            <small class="text-light-gray">${p.position}${p.secondary_position ? '/' + p.secondary_position : ''}</small>
          </div>
        </div>
      </td>
      <td>${p.position}${p.secondary_position ? '/' + p.secondary_position : ''}</td>
      <td><span style="color:${getOvrColor(p.ovr)};" class="fw-bold">${p.ovr}</span></td>
      <td>${p.age}</td>
      <td>${normalizeRoleKey(p.role)}</td>
      <td>
        ${p.available_for_trade ? '<span class="badge bg-success">Disponível</span>' : '<span class="badge bg-secondary">Indisp.</span>'}
      </td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-light btn-edit-player" data-id="${p.id}" title="Editar"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-sm btn-outline-warning btn-waive-player" data-id="${p.id}" data-name="${p.name}" title="Dispensar"><i class="bi bi-hand-thumbs-down"></i></button>
        ${canRetire ? `<button class="btn btn-sm btn-outline-danger btn-retire-player" data-id="${p.id}" data-name="${p.name}" title="Aposentar"><i class="bi bi-box-arrow-right"></i></button>` : ''}
        <button class="btn btn-sm ${p.available_for_trade ? 'btn-outline-success' : 'btn-outline-danger'} btn-toggle-trade" data-id="${p.id}" data-trade="${p.available_for_trade}" title="Disponibilidade para Troca">
          <i class="bi ${p.available_for_trade ? 'bi-check-circle' : 'bi-x-circle'}"></i>
        </button>
      </td>`;
    tbody.appendChild(tr);
  });
  wrapper.style.display = '';
}

function updateRosterStats() {
  const totalPlayers = allPlayers.length;
  const topEight = [...allPlayers]
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
  const mobileCardsEl = document.getElementById('players-mobile-cards');
  if (!teamId) {
    if (statusEl) {
      statusEl.innerHTML = '<div class="alert alert-warning text-center"><i class="bi bi-exclamation-triangle me-2"></i>Você ainda não possui um time.</div>';
      statusEl.style.display = 'block';
    }
    if (gridEl) gridEl.style.display = 'none';
    if (mobileCardsEl) mobileCardsEl.style.display = 'none';
    return;
  }
  if (statusEl) {
    statusEl.innerHTML = '<div class="spinner-border text-orange" role="status"></div><p class="text-light-gray mt-2">Carregando jogadores...</p>';
    statusEl.style.display = 'block';
  }
  if (gridEl) gridEl.style.display = 'none';
  if (mobileCardsEl) mobileCardsEl.style.display = 'none';
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
  document.getElementById('players-search')?.addEventListener('input', (e) => {
    currentSearch = (e.target.value || '').toLowerCase();
    renderPlayers(allPlayers);
  });
  document.getElementById('players-role-filter')?.addEventListener('change', (e) => {
    currentRoleFilter = e.target.value || '';
    renderPlayers(allPlayers);
  });
  document.querySelector('#players-table thead')?.addEventListener('click', (e) => {
    const th = e.target.closest('th.sortable');
    if (th && th.dataset.sort) sortPlayers(th.dataset.sort);
  });

  const editPhotoInput = document.getElementById('edit-foto-adicional');
  editPhotoInput?.addEventListener('change', (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    editPhotoFile = file;
    const preview = document.getElementById('edit-foto-preview');
    if (!preview) return;
    if (preview.dataset.objectUrl) {
      URL.revokeObjectURL(preview.dataset.objectUrl);
      delete preview.dataset.objectUrl;
    }
    if (window.URL && URL.createObjectURL) {
      const objectUrl = URL.createObjectURL(file);
      preview.src = objectUrl;
      preview.dataset.objectUrl = objectUrl;
      return;
    }
    const reader = new FileReader();
    reader.onload = (ev) => {
      preview.src = ev.target.result;
    };
    reader.readAsDataURL(file);
  });

  const formPlayer = document.getElementById('form-player');
  const handleAddPlayer = async () => {
    const form = formPlayer;
    if (!form) return;
    const teamId = window.__TEAM_ID__;
    if (!teamId) {
      alert('Você ainda não possui um time.');
      return;
    }
    const formData = new FormData(form);
    const payload = {
      team_id: teamId,
      name: (formData.get('name') || '').toString().trim(),
      age: parseInt(formData.get('age') || '0', 10),
      position: (formData.get('position') || '').toString().trim(),
      secondary_position: (formData.get('secondary_position') || '').toString().trim() || null,
      role: (formData.get('role') || 'Titular').toString(),
      ovr: parseInt(formData.get('ovr') || '0', 10),
      available_for_trade: formData.get('available_for_trade') ? 1 : 0
    };

    if (!payload.name || !payload.age || !payload.position || !payload.ovr) {
      alert('Preencha nome, idade, posição e OVR.');
      return;
    }

    const btn = document.getElementById('btn-add-player');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando...';
    }

    try {
      const res = await api('players.php', { method: 'POST', body: JSON.stringify(payload) });
      alert(res.message || 'Jogador adicionado.');
      form.reset();
      document.getElementById('available_for_trade').checked = true;
      loadPlayers();
    } catch (err) {
      alert('Erro ao cadastrar jogador: ' + (err.error || err.message || 'Desconhecido'));
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Cadastrar Jogador';
      }
    }
  };

  formPlayer?.addEventListener('submit', async (e) => {
    e.preventDefault();
    handleAddPlayer();
  });

  // Delegação para ações da tabela
  document.getElementById('players-table-body')?.addEventListener('click', async (e) => {
    const btn = e.target.closest('button');
    if (!btn) return;
    if (btn.classList.contains('btn-toggle-trade')) {
      const playerId = btn.dataset.id;
      const currentStatus = (() => {
        const raw = String(btn.dataset.trade || '').toLowerCase();
        return raw === 'true' || raw === '1' || raw === 'yes';
      })();
      const newStatus = currentStatus ? 0 : 1;
      try {
        await api('players.php', { method: 'PUT', body: JSON.stringify({ id: playerId, available_for_trade: newStatus }) });
        loadPlayers();
      } catch (err) {
        alert('Erro ao atualizar: ' + (err.error || 'Desconhecido'));
      }
      return;
    }
    if (btn.classList.contains('btn-edit-player')) {
      const playerId = btn.dataset.id;
      const player = allPlayers.find(p => p.id == playerId);
      if (player) {
        document.getElementById('edit-player-id').value = player.id;
        document.getElementById('edit-name').value = player.name;
        editPhotoFile = null;
        const editPhotoField = document.getElementById('edit-foto-adicional');
        if (editPhotoField) editPhotoField.value = '';
        const editPreview = document.getElementById('edit-foto-preview');
        if (editPreview) editPreview.src = getPlayerPhotoUrl(player);
        document.getElementById('edit-age').value = player.age;
        document.getElementById('edit-position').value = player.position;
        document.getElementById('edit-secondary-position').value = player.secondary_position || '';
        document.getElementById('edit-ovr').value = player.ovr;
        document.getElementById('edit-role').value = player.role;
        document.getElementById('edit-available').checked = !!player.available_for_trade;
        new bootstrap.Modal(document.getElementById('editPlayerModal')).show();
      }
      return;
    }
    if (btn.classList.contains('btn-waive-player')) {
      const playerId = btn.dataset.id;
      const playerName = btn.dataset.name;
      if (confirm(`Dispensar ${playerName}?`)) {
        try {
          const res = await api('players.php', { method: 'DELETE', body: JSON.stringify({ id: playerId }) });
          alert(res.message || 'Jogador dispensado e enviado para a Free Agency!');
          loadPlayers();
          loadFreeAgencyLimits();
        } catch (err) {
          alert('Erro: ' + (err.error || 'Desconhecido'));
        }
      }
      return;
    }
    if (btn.classList.contains('btn-retire-player')) {
      const playerId = btn.dataset.id;
      const playerName = btn.dataset.name;
      if (confirm(`Aposentar ${playerName}?`)) {
        try {
          const res = await api('players.php', { method: 'DELETE', body: JSON.stringify({ id: playerId, retirement: true }) });
          alert(res.message || 'Jogador aposentado!');
          loadPlayers();
        } catch (err) {
          alert('Erro: ' + (err.error || 'Desconhecido'));
        }
      }
    }
  });

  // Delegação para ações nos cards mobile
  document.getElementById('players-mobile-cards')?.addEventListener('click', async (e) => {
    const btn = e.target.closest('button');
    if (!btn) return;
    if (btn.classList.contains('btn-toggle-trade')) {
      const playerId = btn.dataset.id;
      const currentStatus = (() => {
        const raw = String(btn.dataset.trade || '').toLowerCase();
        return raw === 'true' || raw === '1' || raw === 'yes';
      })();
      const newStatus = currentStatus ? 0 : 1;
      try {
        await api('players.php', { method: 'PUT', body: JSON.stringify({ id: playerId, available_for_trade: newStatus }) });
        loadPlayers();
      } catch (err) {
        alert('Erro ao atualizar: ' + (err.error || 'Desconhecido'));
      }
      return;
    }
    if (btn.classList.contains('btn-edit-player')) {
      const playerId = btn.dataset.id;
      const player = allPlayers.find(p => p.id == playerId);
      if (player) {
        document.getElementById('edit-player-id').value = player.id;
        document.getElementById('edit-name').value = player.name;
        editPhotoFile = null;
        const editPhotoField = document.getElementById('edit-foto-adicional');
        if (editPhotoField) editPhotoField.value = '';
        const editPreview = document.getElementById('edit-foto-preview');
        if (editPreview) editPreview.src = getPlayerPhotoUrl(player);
        document.getElementById('edit-age').value = player.age;
        document.getElementById('edit-position').value = player.position;
        document.getElementById('edit-secondary-position').value = player.secondary_position || '';
        document.getElementById('edit-ovr').value = player.ovr;
        document.getElementById('edit-role').value = player.role;
        document.getElementById('edit-available').checked = !!player.available_for_trade;
        new bootstrap.Modal(document.getElementById('editPlayerModal')).show();
      }
      return;
    }
    if (btn.classList.contains('btn-waive-player')) {
      const playerId = btn.dataset.id;
      const playerName = btn.dataset.name;
      if (confirm(`Dispensar ${playerName}?`)) {
        try {
          const res = await api('players.php', { method: 'DELETE', body: JSON.stringify({ id: playerId }) });
          alert(res.message || 'Jogador dispensado e enviado para a Free Agency!');
          loadPlayers();
          loadFreeAgencyLimits();
        } catch (err) {
          alert('Erro: ' + (err.error || 'Desconhecido'));
        }
      }
      return;
    }
    if (btn.classList.contains('btn-retire-player')) {
      const playerId = btn.dataset.id;
      const playerName = btn.dataset.name;
      if (confirm(`Aposentar ${playerName}?`)) {
        try {
          const res = await api('players.php', { method: 'DELETE', body: JSON.stringify({ id: playerId, retirement: true }) });
          alert(res.message || 'Jogador aposentado!');
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
    if (editPhotoFile) {
      data.foto_adicional = await convertToBase64(editPhotoFile);
    }
    try {
      await api('players.php', { method: 'PUT', body: JSON.stringify(data) });
      bootstrap.Modal.getInstance(document.getElementById('editPlayerModal')).hide();
      loadPlayers();
    } catch (err) {
      alert('Erro ao salvar: ' + (err.error || 'Desconhecido'));
    }
  });
});
