(function() {
  document.addEventListener('DOMContentLoaded', () => {
    const playersListEl = document.getElementById('playersList');
    const searchInput = document.getElementById('searchInput');
    const sortSelect = document.getElementById('sortSelect');
    const countBadge = document.getElementById('countBadge');
    const tradeTabBtn = document.getElementById('trade-list-tab');

    if (!playersListEl || !searchInput || !sortSelect || !countBadge) {
      return;
    }

    let currentData = [];
    let debounceTimer = null;
    let initialized = false;

    function parseSort(value) {
      const [key, dir] = value.split('_');
      let sort = key;
      if (key === 'team') sort = 'team';
      return { sort, dir };
    }

    async function loadPlayers() {
      const q = searchInput.value.trim();
      const { sort, dir } = parseSort(sortSelect.value);
      const url = `/api/trade-list.php?q=${encodeURIComponent(q)}&sort=${encodeURIComponent(sort)}&dir=${encodeURIComponent(dir)}`;

      playersListEl.innerHTML = `<div class="text-center py-4"><div class="spinner-border" style="color: var(--fba-orange);"></div></div>`;

      try {
        const res = await fetch(url);
        const data = await res.json();
        if (!data.success) throw new Error('Erro ao buscar lista');
        currentData = data.players || [];
        countBadge.textContent = `${data.count || currentData.length} jogadores`;
        renderPlayers(currentData);
      } catch (e) {
        playersListEl.innerHTML = `<div class="alert alert-danger">Erro ao carregar lista de trocas.</div>`;
      }
    }

    function renderPlayers(players) {
      if (!players || players.length === 0) {
        playersListEl.innerHTML = `
        <div class="alert alert-info d-flex align-items-center" role="alert">
          <i class="bi bi-info-circle me-2"></i>
          <div>Nenhum jogador disponível para troca na sua liga.</div>
        </div>
      `;
        return;
      }

      const html = players.map(p => {
        const teamLogo = p.team_logo || '/img/default-team.png';
        const secPos = p.secondary_position ? ` / ${p.secondary_position}` : '';
        return `
        <div class="player-card">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="player-name">${p.name}</div>
              <div class="player-meta">Pos: ${p.position}${secPos} • Idade: ${p.age} • OVR: ${p.ovr} • Função: ${p.role || '-'}</div>
            </div>
            <div class="team-chip">
              <img class="team-logo" src="${teamLogo}" alt="${p.team_name}">
              <span>${p.team_name}</span>
            </div>
          </div>
        </div>
        `;
      }).join('');

      playersListEl.innerHTML = html;
    }

    function debounceLoad() {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(loadPlayers, 250);
    }

    function initializeTradeList() {
      if (initialized) return;
      initialized = true;
      loadPlayers();
      searchInput.addEventListener('input', debounceLoad);
      sortSelect.addEventListener('change', loadPlayers);
    }

    if (tradeTabBtn) {
      tradeTabBtn.addEventListener('shown.bs.tab', initializeTradeList);
      if (tradeTabBtn.classList.contains('active')) {
        initializeTradeList();
      }
    } else {
      initializeTradeList();
    }
  });
})();
