const api = async (path, options = {}) => {
  const doFetch = async (url) => {
    const res = await fetch(url, {
      headers: { 'Content-Type': 'application/json' },
      ...options,
    });
    let body = {};
    try { body = await res.json(); } catch { body = {}; }
    console.log('API Response Status:', res.status, 'Body:', body);
    return { res, body };
  };
  let { res, body } = await doFetch(`/api/${path}`);
  if (res.status === 404) ({ res, body } = await doFetch(`/public/api/${path}`));
  if (!res.ok) throw body;
  return body;
};

// Função para calcular cor do OVR com nova escala
function getOvrColor(ovr) {
  // 99-95: Big (verde brilhante)
  if (ovr >= 95) return '#00ff00';
  // 94-89: Elite (verde)
  if (ovr >= 89) return '#00dd00';
  // 88-84: Muito Bom (amarelo claro)
  if (ovr >= 84) return '#ffff00';
  // 83-79: Bom Jogador (amarelo escuro/ouro)
  if (ovr >= 79) return '#ffd700';
  // 79-72: Jogadores (laranja)
  if (ovr >= 72) return '#ff9900';
  // 71-60: Ruins (vermelho)
  return '#ff4444';
}

// Ordem padrão de funções
const roleOrder = { 'Titular': 0, 'Banco': 1, 'Outro': 2, 'G-League': 3 };

let allPlayers = [];
let currentSort = { field: 'role', ascending: true };

function sortPlayers(field) {
  // Se clicou na mesma coluna, inverte ordem
  if (currentSort.field === field) {
    currentSort.ascending = !currentSort.ascending;
  } else {
    currentSort.field = field;
    currentSort.ascending = field !== 'role'; // role é descendente por padrão (Titular primeiro)
  }
  
  // Atualizar ícones das colunas
  updateSortIcons();
  renderPlayers(allPlayers);
}

function updateSortIcons() {
  // Remover ícones de todas as colunas
  document.querySelectorAll('.sortable i').forEach(icon => {
    icon.className = 'bi bi-arrow-down-up';
    icon.style.opacity = '0.5';
  });
  
  // Adicionar ícone na coluna atual
  const activeHeader = document.querySelector(`.sortable[data-sort="${currentSort.field}"]`);
  if (activeHeader) {
    const icon = activeHeader.querySelector('i');
    if (icon) {
      if (currentSort.ascending) {
        icon.className = 'bi bi-arrow-up';
        icon.style.opacity = '1';
      } else {
        icon.className = 'bi bi-arrow-down';
        icon.style.opacity = '1';
      }
    }
  }
}

function renderPlayers(players) {
  // Ordenar jogadores
  let sorted = [...players];
  sorted.sort((a, b) => {
    let aVal = a[currentSort.field];
    let bVal = b[currentSort.field];
    
    // Para role, usar ordem customizada
    if (currentSort.field === 'role') {
      aVal = roleOrder[aVal] ?? 999;
      bVal = roleOrder[bVal] ?? 999;
    }
    // Para trade, converter bool
    if (currentSort.field === 'trade') {
      aVal = a.available_for_trade ? 1 : 0;
      bVal = b.available_for_trade ? 1 : 0;
    }
    // Para numéricos, converter
    if (['ovr', 'age', 'seasons_in_league'].includes(currentSort.field)) {
      aVal = Number(aVal);
      bVal = Number(bVal);
    }

    if (aVal < bVal) return currentSort.ascending ? -1 : 1;
    if (aVal > bVal) return currentSort.ascending ? 1 : -1;
    return 0;
  });

  // Renderizar tabela (desktop)
  const tbody = document.getElementById('players-tbody');
  tbody.innerHTML = '';
  
  // Renderizar cards (mobile)
  const cardsContainer = document.getElementById('players-cards-mobile');
  cardsContainer.innerHTML = '';
  
  sorted.forEach(p => {
    const ovrColor = getOvrColor(p.ovr);
    // Tabela (desktop)
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${p.name}</td>
      <td><span style="font-size: 1.3rem; font-weight: bold; color: ${ovrColor};">${p.ovr}</span></td>
      <td>${p.position}</td>
      <td>${p.secondary_position || '-'}</td>
      <td>${p.role}</td>
      <td>${p.age}</td>
      <td>
        <button class="btn btn-sm btn-toggle-trade" data-id="${p.id}" data-trade="${p.available_for_trade}" style="
          background: ${p.available_for_trade ? '#00ff00' : '#ff4444'};
          color: #000;
          border: none;
          font-weight: bold;
          padding: 6px 12px;
          border-radius: 4px;
          cursor: pointer;
          transition: all 0.3s ease;
          display: inline-flex;
          align-items: center;
          gap: 6px;
          white-space: nowrap;
        " title="Clique para alternar disponibilidade para trade">
          <i class="bi ${p.available_for_trade ? 'bi-check-circle-fill' : 'bi-x-circle-fill'}"></i>
          ${p.available_for_trade ? 'Disponível' : 'Não Disponível'}
        </button>
      </td>
      <td>
        <button class="btn btn-sm btn-outline-warning btn-edit-player" data-id="${p.id}">Editar</button>
        <button class="btn btn-sm btn-outline-danger btn-waive-player" data-id="${p.id}">
          <i class="bi bi-person-x"></i> Dispensar
        </button>
      </td>
    `;
    tbody.appendChild(tr);

    // Card (mobile)
    const card = document.createElement('div');
    card.className = 'player-card';
    // Corrigir: definir positionDisplay corretamente
    const positionDisplay = p.secondary_position ? `${p.position}/${p.secondary_position}` : p.position;
    card.innerHTML = `
      <div class="player-card-header">
        <div>
          <h6 class="text-white mb-1">${p.name}</h6>
          <span class="badge bg-orange">${positionDisplay}</span>
          <span class="badge bg-secondary ms-1">${p.role}</span>
        </div>
        <span style="font-size: 1.5rem; font-weight: bold; color: ${ovrColor};">${p.ovr}</span>
      </div>
      <div class="player-card-body text-light-gray">
        <div class="player-card-stat">
          <strong>Idade</strong>
          ${p.age} anos
        </div>
        <div class="player-card-stat">
          <strong>Trade</strong>
          <span class="badge ${p.available_for_trade ? 'bg-success' : 'bg-danger'}">
            ${p.available_for_trade ? 'Disponível' : 'Indisponível'}
          </span>
        </div>
      </div>
      <div class="player-card-actions">
        <button class="btn btn-sm btn-toggle-trade" data-id="${p.id}" data-trade="${p.available_for_trade}" style="
          background: ${p.available_for_trade ? '#00ff00' : '#ff4444'};
          color: #000;
          border: none;
          font-weight: bold;
        ">
          <i class="bi ${p.available_for_trade ? 'bi-check-circle-fill' : 'bi-x-circle-fill'}"></i>
        </button>
        <button class="btn btn-sm btn-outline-warning btn-edit-player" data-id="${p.id}">
          <i class="bi bi-pencil"></i> Editar
        </button>
        <button class="btn btn-sm btn-outline-danger btn-waive-player" data-id="${p.id}">
          <i class="bi bi-person-x"></i> Dispensar
        </button>
      </div>
    `;
    cardsContainer.appendChild(card);
  });
  
  // Mostrar lista e ocultar loading
  document.getElementById('players-status').style.display = 'none';
  document.getElementById('players-list').classList.remove('d-none');

  // Atualizar stats
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
  console.log('loadPlayers chamado, teamId:', teamId);
  
  const statusEl = document.getElementById('players-status');
  const listEl = document.getElementById('players-list');
  
  if (!teamId) {
    console.error('Sem teamId!');
    if (statusEl) {
      statusEl.innerHTML = `
        <div class="alert alert-warning text-center">
          <i class="bi bi-exclamation-triangle me-2"></i>
          Você ainda não possui um time. Crie um time para gerenciar seu elenco.
        </div>
      `;
      statusEl.classList.remove('d-none');
    }
    if (listEl) listEl.classList.add('d-none');
    return;
  }
  
  if (statusEl) {
    statusEl.innerHTML = `
      <div class="spinner-border text-orange" role="status"></div>
      <p class="text-light-gray mt-2">Carregando jogadores...</p>
    `;
    statusEl.classList.remove('d-none');
  }
  if (listEl) listEl.classList.add('d-none');
  
  try {
    console.log('Fetching:', `/api/players.php?team_id=${teamId}`);
    const data = await api(`players.php?team_id=${teamId}`);
    console.log('Resposta API:', data);
    allPlayers = data.players || [];
    console.log('Total de jogadores carregados:', allPlayers.length);
    // Ordenar por role padrão (Titular primeiro)
    currentSort = { field: 'role', ascending: true };
    updateSortIcons();
    renderPlayers(allPlayers);
    if (statusEl) statusEl.classList.add('d-none');
    if (listEl) listEl.classList.remove('d-none');
  } catch (err) {
    console.error('Erro ao carregar:', err);
    if (statusEl) {
      statusEl.innerHTML = `
        <div class="alert alert-danger text-center">
          <i class="bi bi-x-circle me-2"></i>
          Erro ao carregar jogadores: ${err.error || 'Desconhecido'}
        </div>
      `;
    }
  }
}

async function addPlayer() {
  const teamId = window.__TEAM_ID__;
  if (!teamId) return alert('Sem time para adicionar jogadores.');
  const form = document.getElementById('form-player');
  const fd = new FormData(form);
  const payload = {
    team_id: teamId,
    name: fd.get('name'),
    age: Number(fd.get('age')),
    position: fd.get('position'),
    secondary_position: fd.get('secondary_position'),
    role: fd.get('role'),
    ovr: Number(fd.get('ovr')),
    available_for_trade: document.getElementById('available_for_trade').checked,
  };
  if (!payload.name || !payload.age || !payload.position || !payload.ovr) {
    return alert('Preencha nome, idade, posição e OVR.');
  }
  try {
    const res = await api('players.php', { method: 'POST', body: JSON.stringify(payload) });
    form.reset();
    loadPlayers();
  } catch (err) {
    alert(err.error || 'Erro ao adicionar jogador');
  }
}

async function updatePlayer(payload) {
  try {
    const res = await api('players.php', { method: 'PUT', body: JSON.stringify(payload) });
    loadPlayers();
  } catch (err) {
    alert(err.error || 'Erro ao atualizar jogador');
  }
}

async function deletePlayer(id) {
  if (!confirm('Deseja dispensar este jogador? Ele será enviado para a Free Agency e outros times poderão contratá-lo.')) return;
  try {
    const res = await api('free-agency.php', { 
      method: 'POST', 
      body: JSON.stringify({ action: 'waive', player_id: id }) 
    });
    alert(res.message || 'Jogador dispensado e enviado para Free Agency!');
    loadPlayers();
    // Se estiver aberta a tela de Free Agency, recarregar a lista de FAs
    if (window.location.pathname.includes('free-agency.php')) {
      if (typeof loadFreeAgents === 'function') loadFreeAgents();
    }
  } catch (err) {
    alert(err.error || 'Erro ao dispensar jogador');
  }
}

function openEditModal(player) {
  if (!player) return;
  
  const modalEl = document.getElementById('editPlayerModal');
  if (!modalEl) {
    console.error('Modal não encontrado');
    return;
  }
  
  // Preencher os campos do modal
  document.getElementById('edit-player-id').value = player.id;
  document.getElementById('edit-name').value = player.name;
  document.getElementById('edit-age').value = player.age;
  document.getElementById('edit-position').value = player.position;
  document.getElementById('edit-secondary-position').value = player.secondary_position || '';
  document.getElementById('edit-ovr').value = player.ovr;
  document.getElementById('edit-role').value = player.role;
  document.getElementById('edit-available').checked = !!player.available_for_trade;
  
  // Abrir modal com Bootstrap 5
  const bsModal = new bootstrap.Modal(modalEl, {
    backdrop: 'static',
    keyboard: false
  });
  bsModal.show();
}

function getRowPlayer(id) {
  // Encontrar o jogador no array allPlayers usando o ID
  const player = allPlayers.find(p => p.id === id);
  return player || null;
}

// Bind
document.getElementById('btn-refresh-players')?.addEventListener('click', loadPlayers);
document.getElementById('btn-add-player')?.addEventListener('click', addPlayer);

// Adicionar event listeners aos headers para ordenação
document.addEventListener('click', (e) => {
  if (e.target.classList.contains('sortable')) {
    const field = e.target.getAttribute('data-sort');
    if (field) sortPlayers(field);
  }
});

// Delegation for actions (tabela)
document.getElementById('players-tbody')?.addEventListener('click', (e) => {
  const target = e.target;
  if (target.classList.contains('btn-waive-player')) {
    const id = Number(target.dataset.id);
    deletePlayer(id);
  } else if (target.classList.contains('btn-edit-player')) {
    const id = Number(target.dataset.id);
    const p = getRowPlayer(id);
    if (p) openEditModal(p);
  } else if (target.classList.contains('btn-toggle-trade')) {
    const id = Number(target.dataset.id);
    const current = Number(target.dataset.trade);
    updatePlayer({ id, available_for_trade: current ? 0 : 1 });
  }
});

// Delegation for actions (cards mobile)
document.getElementById('players-cards-mobile')?.addEventListener('click', (e) => {
  const target = e.target.closest('button');
  if (!target) return;
  
  if (target.classList.contains('btn-waive-player')) {
    const id = Number(target.dataset.id);
    deletePlayer(id);
  } else if (target.classList.contains('btn-edit-player')) {
    const id = Number(target.dataset.id);
    const p = getRowPlayer(id);
    if (p) openEditModal(p);
  } else if (target.classList.contains('btn-toggle-trade')) {
    const id = Number(target.dataset.id);
    const current = Number(target.dataset.trade);
    updatePlayer({ id, available_for_trade: current ? 0 : 1 });
  }
});

// Edit modal save
document.getElementById('btn-save-edit')?.addEventListener('click', () => {
  const id = Number(document.getElementById('edit-player-id').value);
  const payload = {
    id,
    name: document.getElementById('edit-name').value,
    age: Number(document.getElementById('edit-age').value),
    position: document.getElementById('edit-position').value,
    secondary_position: document.getElementById('edit-secondary-position').value,
    ovr: Number(document.getElementById('edit-ovr').value),
    role: document.getElementById('edit-role').value,
    available_for_trade: document.getElementById('edit-available').checked,
  };
  updatePlayer(payload);
  const modalEl = document.getElementById('editPlayerModal');
  const bsModal = bootstrap.Modal.getInstance(modalEl);
  bsModal && bsModal.hide();
});

// Initial
loadPlayers();
