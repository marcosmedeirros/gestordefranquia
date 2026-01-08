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

function renderPlayers(players) {
  const tbody = document.getElementById('players-tbody');
  tbody.innerHTML = '';
  players.forEach(p => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${p.id}</td>
      <td>${p.name}</td>
      <td>${p.position}</td>
      <td><span class="badge bg-gradient-orange">${p.ovr}</span></td>
      <td>${p.role}</td>
      <td>${p.age}</td>
      <td>${p.available_for_trade ? 'Sim' : 'Não'}</td>
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
    renderPlayers(data.players || []);
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

// Bind
document.getElementById('btn-refresh-players')?.addEventListener('click', loadPlayers);
document.getElementById('btn-add-player')?.addEventListener('click', addPlayer);

// Initial
loadPlayers();
