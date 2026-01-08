const api = async (path, options = {}) => {
  const doFetch = async (url) => {
    const res = await fetch(url, {
      headers: { 'Content-Type': 'application/json' },
      ...options,
    });
    let body = {};
    try { body = await res.json(); } catch { body = {}; }
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
let currentSort = { field: 'role', ascending: false };

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
    if (['ovr', 'age'].includes(currentSort.field)) {
      aVal = Number(aVal);
      bVal = Number(bVal);
    }

    if (aVal < bVal) return currentSort.ascending ? -1 : 1;
    if (aVal > bVal) return currentSort.ascending ? 1 : -1;
    return 0;
  });

  const tbody = document.getElementById('players-tbody');
  tbody.innerHTML = '';
  sorted.forEach(p => {
    const tr = document.createElement('tr');
    const ovrColor = getOvrColor(p.ovr);
    tr.innerHTML = `
      <td>${p.name}</td>
      <td><span style="font-size: 1.3rem; font-weight: bold; color: ${ovrColor};">${p.ovr}</span></td>
      <td>${p.position}</td>
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
        <button class="btn btn-sm btn-outline-danger btn-delete-player" data-id="${p.id}">Excluir</button>
      </td>
    `;
    tbody.appendChild(tr);
  });
}

async function loadPlayers() {
  const teamId = window.__TEAM_ID__;
  if (!teamId) return;
  document.getElementById('players-status').classList.remove('d-none');
  document.getElementById('players-list').classList.add('d-none');
  try {
    const data = await api(`players.php?team_id=${teamId}`);
    allPlayers = data.players || [];
    // Ordenar por role padrão (Titular primeiro)
    currentSort = { field: 'role', ascending: false };
    updateSortIcons();
    renderPlayers(allPlayers);
    document.getElementById('players-status').classList.add('d-none');
    document.getElementById('players-list').classList.remove('d-none');
  } catch (err) {
    alert(err.error || 'Erro ao carregar jogadores');
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
    role: fd.get('role'),
    ovr: Number(fd.get('ovr')),
    available_for_trade: document.getElementById('available_for_trade').checked,
  };
  if (!payload.name || !payload.age || !payload.position || !payload.ovr) {
    return alert('Preencha nome, idade, posição e OVR.');
  }
  try {
    const res = await api('players.php', { method: 'POST', body: JSON.stringify(payload) });
    alert('Jogador adicionado. CAP Top8: ' + (res.cap_top8 ?? '')); 
    form.reset();
    loadPlayers();
  } catch (err) {
    alert(err.error || 'Erro ao adicionar jogador');
  }
}

async function updatePlayer(payload) {
  try {
    const res = await api('players.php', { method: 'PUT', body: JSON.stringify(payload) });
    alert('Jogador atualizado. CAP Top8: ' + (res.cap_top8 ?? ''));
    loadPlayers();
  } catch (err) {
    alert(err.error || 'Erro ao atualizar jogador');
  }
}

async function deletePlayer(id) {
  if (!confirm('Deseja excluir este jogador?')) return;
  try {
    const res = await api('players.php', { method: 'DELETE', body: JSON.stringify({ id }) });
    alert('Jogador removido. CAP Top8: ' + (res.cap_top8 ?? ''));
    loadPlayers();
  } catch (err) {
    alert(err.error || 'Erro ao remover jogador');
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

// Delegation for actions
document.getElementById('players-tbody')?.addEventListener('click', (e) => {
  const target = e.target;
  if (target.classList.contains('btn-delete-player')) {
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
