const api = async (path, options = {}) => {
  const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
  let body = {};
  try { body = await res.json(); } catch {}
  if (!res.ok) throw body;
  return body;
};

async function init() {
  await loadLeagues();
  document.getElementById('saveAllBtn').addEventListener('click', saveAllCaps);
}

async function loadLeagues() {
  const container = document.getElementById('leaguesContainer');
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange"></div></div>';
  try {
    const data = await api('admin-leagues.php');
    const leagues = data.leagues || [];
    container.innerHTML = '';
    leagues.forEach(lg => container.appendChild(renderLeagueBlock(lg)));
  } catch (err) {
    container.innerHTML = '<div class="text-center text-danger py-4">Erro ao carregar ligas</div>';
  }
}

function renderLeagueBlock(lg) {
  const wrap = document.createElement('div');
  wrap.className = 'mb-4';
  wrap.innerHTML = `
    <div class="bg-dark-panel border-orange rounded p-3 mb-2">
      <div class="d-flex align-items-center gap-3">
        <h4 class="text-orange mb-0" style="min-width: 120px;">${lg.league}</h4>
        <div class="d-flex align-items-end gap-3 flex-wrap">
          <div>
            <small class="text-light-gray">CAP Mín</small>
            <input type="number" class="form-control bg-dark text-white border-orange" value="${lg.cap_min}" data-league="${lg.league}" data-field="cap_min" style="width:120px;" />
          </div>
          <div>
            <small class="text-light-gray">CAP Máx</small>
            <input type="number" class="form-control bg-dark text-white border-orange" value="${lg.cap_max}" data-league="${lg.league}" data-field="cap_max" style="width:120px;" />
          </div>
        </div>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-dark table-hover">
        <thead>
          <tr>
            <th>Time</th>
            <th>CAP Top8</th>
            <th>Total Jogadores</th>
          </tr>
        </thead>
        <tbody>
          ${lg.teams.map(t => `
            <tr>
              <td>${t.city} ${t.name}</td>
              <td>${t.cap_top8}</td>
              <td>${t.total_players}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
  return wrap;
}

async function saveAllCaps() {
  const inputs = document.querySelectorAll('input[data-league][data-field]');
  const groups = {};
  inputs.forEach(inp => {
    const lg = inp.dataset.league;
    groups[lg] = groups[lg] || { league: lg };
    groups[lg][inp.dataset.field] = parseInt(inp.value, 10);
  });

  const entries = Object.values(groups);
  try {
    await Promise.all(entries.map(e => api('admin-leagues.php', { method: 'PUT', body: JSON.stringify(e) })));
    alert('Caps atualizados!');
  } catch (err) {
    alert(err.error || 'Erro ao salvar caps');
  }
}

document.addEventListener('DOMContentLoaded', init);
