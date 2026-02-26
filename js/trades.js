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

let myTeamId = window.__TEAM_ID__;
let myLeague = window.__USER_LEAGUE__;
let allTeams = [];
let myPlayers = [];
let myPicks = [];
let allLeagueTrades = []; // Armazenar trades da liga para busca
const currentSeasonYear = Number(window.__CURRENT_SEASON_YEAR__ || new Date().getFullYear());
const tradeEmojiList = ['👍', '❤️', '😂', '😮', '😢', '😡'];


const pickState = {
  offer: { available: [], selected: [] },
  request: { available: [], selected: [] }
};

const playerState = {
  offer: { available: [], selected: [] },
  request: { available: [], selected: [] }
};

const multiTradeState = {
  assets: { players: {}, picks: {} },
  itemCounter: 0
};

const formatTradePlayerDisplay = (player) => {
  if (!player) return '';
  const name = player.name || 'Jogador';
  const position = player.position || '-';
  const ovr = player.ovr ?? '?';
  const age = player.age ?? '?';
  return `${name} (${position}, ${ovr}/${age})`;
};

const formatTradePickDisplay = (pick) => {
  if (!pick) return '';
  const year = pick.season_year || '?';
  const round = pick.round || '?';
  
  // Mostrar de quem é a pick (time original)
  const originalTeam = pick.original_team_city && pick.original_team_name 
    ? `${pick.original_team_city} ${pick.original_team_name}` 
    : 'Time';
  
  let display = `Pick ${year} R${round} (${originalTeam})`;
  
  // Se a pick foi trocada (team_id != original_team_id), mostrar "via"
  if (pick.team_id && pick.original_team_id && pick.team_id != pick.original_team_id) {
    // Mostrar quem enviou a pick (last_owner)
    if (pick.last_owner_city && pick.last_owner_name) {
      display += ` <span class="text-info">via ${pick.last_owner_city} ${pick.last_owner_name}</span>`;
    }
  }

  return display;
};

const getTeamLabel = (team) => team ? `${team.city} ${team.name}` : 'Time';

const buildTradeReactionBar = (trade, tradeType) => {
  const reactions = Array.isArray(trade.reactions) ? trade.reactions : [];
  const mineEmoji = reactions.find(r => r.mine)?.emoji || null;
  const countsMap = Object.fromEntries(reactions.map(r => [r.emoji, r.count]));
  return tradeEmojiList.map((emoji) => {
    const count = countsMap[emoji] || 0;
    const activeClass = mineEmoji === emoji ? 'reaction-chip active' : 'reaction-chip';
    const enc = encodeURIComponent(emoji);
    return `<span class="${activeClass}" onclick="toggleTradeReaction(${trade.id}, '${tradeType}', '${enc}')">${emoji} <span class="reaction-count">${count}</span></span>`;
  }).join(' ');
};

const updateTradeReactionsInState = (tradeId, tradeType, reactions) => {
  const match = allLeagueTrades.find(tr => {
    if (tradeType === 'multi') {
      return tr.is_multi && Number(tr.id) === Number(tradeId);
    }
    return !tr.is_multi && Number(tr.id) === Number(tradeId);
  });
  if (match) {
    match.reactions = reactions || [];
  }
};

const getSelectedMultiTeams = () => {
  const container = document.getElementById('multiTradeTeamsList');
  if (!container) return [];
  return Array.from(container.querySelectorAll('input[type="checkbox"]:checked'))
    .map((input) => Number(input.value))
    .filter((id) => Number.isFinite(id));
};

const renderMultiTeamLimit = () => {
  const container = document.getElementById('multiTradeTeamsList');
  if (!container) return;
  const selected = getSelectedMultiTeams();
  const limitReached = selected.length >= 7;
  container.querySelectorAll('input[type="checkbox"]').forEach((input) => {
    if (input.disabled && Number(input.value) === Number(myTeamId)) {
      return;
    }
    if (!input.checked) {
      input.disabled = limitReached;
    }
  });
};

const renderMultiTradeTeams = () => {
  const container = document.getElementById('multiTradeTeamsList');
  if (!container) return;

  const myId = Number(myTeamId);
  const myLeagueNormalized = (myLeague ?? '').toString().trim().toUpperCase();
  const filtered = allTeams.filter((team) => {
    const teamLeague = (team.league ?? '').toString().trim().toUpperCase();
    if (!myLeagueNormalized) return true;
    return teamLeague === myLeagueNormalized;
  });

  container.innerHTML = filtered
    .sort((a, b) => getTeamLabel(a).localeCompare(getTeamLabel(b)))
    .map((team) => {
      const checked = Number(team.id) === myId ? 'checked' : '';
      const disabled = Number(team.id) === myId ? 'disabled' : '';
      return `
        <div class="form-check">
          <input class="form-check-input multi-team-checkbox" type="checkbox" value="${team.id}" id="multiTeam_${team.id}" ${checked} ${disabled}>
          <label class="form-check-label text-light-gray" for="multiTeam_${team.id}">${getTeamLabel(team)}</label>
        </div>
      `;
    })
    .join('');

  container.addEventListener('change', (event) => {
    if (!event.target.classList.contains('multi-team-checkbox')) return;
    renderMultiTeamLimit();
    updateMultiItemTeamOptions();
  });

  renderMultiTeamLimit();
  updateMultiItemTeamOptions();
};

const updateMultiItemTeamOptions = () => {
  const selectedTeams = getSelectedMultiTeams();
  const rows = document.querySelectorAll('.multi-trade-item-row');
  rows.forEach((row) => {
    const fromSelect = row.querySelector('[data-role="from-team"]');
    const toSelect = row.querySelector('[data-role="to-team"]');
    if (!fromSelect || !toSelect) return;
    const currentFrom = fromSelect.value;
    const currentTo = toSelect.value;
    const fromOptionsHtml = selectedTeams.map((id) => {
      const team = allTeams.find((t) => Number(t.id) === Number(id));
      return `<option value="${id}">${getTeamLabel(team)}</option>`;
    }).join('');
    const toOptionsHtml = selectedTeams
      .filter((id) => Number(id) !== Number(currentFrom || 0))
      .map((id) => {
        const team = allTeams.find((t) => Number(t.id) === Number(id));
        return `<option value="${id}">${getTeamLabel(team)}</option>`;
      }).join('');

    fromSelect.innerHTML = '<option value="">Origem...</option>' + fromOptionsHtml;
    toSelect.innerHTML = '<option value="">Destino...</option>' + toOptionsHtml;
    if (selectedTeams.includes(Number(currentFrom))) fromSelect.value = currentFrom;
    if (selectedTeams.includes(Number(currentTo)) && Number(currentTo) !== Number(currentFrom || 0)) {
      toSelect.value = currentTo;
    } else {
      toSelect.value = '';
    }
    updateMultiItemOptions(row).catch((err) => console.warn('Erro ao atualizar itens:', err));
  });
};

const getMultiAssetCache = (type, teamId) => {
  return multiTradeState.assets[type][teamId] || [];
};

const loadMultiAssets = async (teamId, type) => {
  if (!teamId) return [];
  if (multiTradeState.assets[type][teamId]) {
    return multiTradeState.assets[type][teamId];
  }
  const endpoint = type === 'players' ? `players.php?team_id=${teamId}` : `picks.php?team_id=${teamId}`;
  const data = await api(endpoint);
  const list = type === 'players' ? (data.players || []) : (data.picks || []);
  multiTradeState.assets[type][teamId] = list;
  return list;
};

const updateMultiItemOptions = async (row) => {
  const fromSelect = row.querySelector('[data-role="from-team"]');
  const typeSelect = row.querySelector('[data-role="item-type"]');
  const itemSelect = row.querySelector('[data-role="item-id"]');
  if (!fromSelect || !typeSelect || !itemSelect) return;

  const teamId = Number(fromSelect.value);
  const type = typeSelect.value;
  if (!teamId || !type) {
    itemSelect.innerHTML = '<option value="">Selecione a origem e o tipo</option>';
    return;
  }

  const list = await loadMultiAssets(teamId, type === 'player' ? 'players' : 'picks');
  if (!list || list.length === 0) {
    itemSelect.innerHTML = '<option value="">Nenhum item disponível</option>';
    return;
  }

  if (type === 'player') {
    itemSelect.innerHTML = '<option value="">Selecione o jogador</option>' + list.map((player) => {
      return `<option value="${player.id}">${formatTradePlayerDisplay(player)}</option>`;
    }).join('');
  } else {
    itemSelect.innerHTML = '<option value="">Selecione a pick</option>' + list.map((pick) => {
      const summary = buildPickSummary(pick);
      const via = summary.via ? ` • ${summary.via}` : '';
      return `<option value="${pick.id}">${summary.title} (${summary.origin}${via})</option>`;
    }).join('');
  }
};

const addMultiTradeItemRow = () => {
  const container = document.getElementById('multiTradeItems');
  if (!container) return;
  const rowId = `multiItem_${multiTradeState.itemCounter++}`;
  const row = document.createElement('div');
  row.className = 'multi-trade-item-row bg-dark rounded border border-secondary p-3 mb-3';
  row.dataset.rowId = rowId;
  row.innerHTML = `
    <div class="d-flex justify-content-between align-items-center mb-2">
      <strong class="text-white">Item</strong>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-role="remove-item">Remover</button>
    </div>
    <div class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label text-light-gray small">Origem</label>
        <select class="form-select bg-dark text-white border-secondary" data-role="from-team"></select>
      </div>
      <div class="col-md-3">
        <label class="form-label text-light-gray small">Destino</label>
        <select class="form-select bg-dark text-white border-secondary" data-role="to-team"></select>
      </div>
      <div class="col-md-3">
        <label class="form-label text-light-gray small">Tipo</label>
        <select class="form-select bg-dark text-white border-secondary" data-role="item-type">
          <option value="">Selecione...</option>
          <option value="player">Jogador</option>
          <option value="pick">Pick</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label text-light-gray small">Item</label>
        <select class="form-select bg-dark text-white border-secondary" data-role="item-id">
          <option value="">Selecione a origem e o tipo</option>
        </select>
      </div>
    </div>
  `;
  container.appendChild(row);
  updateMultiItemTeamOptions();

  row.addEventListener('change', (event) => {
    if (event.target.matches('[data-role="from-team"]') || event.target.matches('[data-role="item-type"]')) {
      updateMultiItemOptions(row);
    }
    if (event.target.matches('[data-role="from-team"]')) {
      updateMultiItemTeamOptions();
    }
  });

  row.addEventListener('click', (event) => {
    if (event.target.matches('[data-role="remove-item"]')) {
      row.remove();
    }
  });
};

const submitMultiTrade = async () => {
  const selectedTeams = getSelectedMultiTeams();
  if (selectedTeams.length < 2) {
    return alert('Selecione pelo menos 2 times.');
  }
  if (selectedTeams.length > 7) {
    return alert('Máximo de 7 times.');
  }

  const items = [];
  let hasInvalid = false;
  const sendCounts = {};
  const receiveCounts = {};
  document.querySelectorAll('.multi-trade-item-row').forEach((row) => {
    const fromTeam = Number(row.querySelector('[data-role="from-team"]').value);
    const toTeam = Number(row.querySelector('[data-role="to-team"]').value);
    const type = row.querySelector('[data-role="item-type"]').value;
    const itemId = Number(row.querySelector('[data-role="item-id"]').value);
    if (!fromTeam || !toTeam || !type || !itemId) {
      hasInvalid = true;
      return;
    }
    if (fromTeam === toTeam) {
      hasInvalid = true;
      return;
    }
    sendCounts[fromTeam] = (sendCounts[fromTeam] || 0) + 1;
    receiveCounts[toTeam] = (receiveCounts[toTeam] || 0) + 1;
    const payload = { from_team_id: fromTeam, to_team_id: toTeam };
    if (type === 'player') {
      payload.player_id = itemId;
    } else {
      payload.pick_id = itemId;
    }
    items.push(payload);
  });

  if (hasInvalid || items.length === 0) {
    return alert('Preencha todos os itens da troca múltipla.');
  }

  const missingFlow = selectedTeams.some((teamId) => {
    return !sendCounts[teamId] || !receiveCounts[teamId];
  });
  if (missingFlow) {
    return alert('Todos os times devem enviar e receber pelo menos um item.');
  }

  const notes = (document.getElementById('multiTradeNotes')?.value || '').trim();

  try {
    await api('trades.php?action=multi_trades', {
      method: 'POST',
      body: JSON.stringify({ teams: selectedTeams, items, notes })
    });
    alert('Troca múltipla enviada!');
    bootstrap.Modal.getInstance(document.getElementById('multiTradeModal')).hide();
    resetMultiTradeForm();
    loadTrades('sent');
    loadTrades('received');
    loadTrades('history');
    loadTrades('league');
  } catch (err) {
    alert(err.error || 'Erro ao enviar troca múltipla');
  }
};

const resetMultiTradeForm = () => {
  const notes = document.getElementById('multiTradeNotes');
  if (notes) notes.value = '';
  const container = document.getElementById('multiTradeItems');
  if (container) container.innerHTML = '';
  const teamsContainer = document.getElementById('multiTradeTeamsList');
  if (teamsContainer) {
    teamsContainer.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      if (Number(input.value) === Number(myTeamId)) {
        input.checked = true;
        input.disabled = true;
      } else {
        input.checked = false;
        input.disabled = false;
      }
    });
  }
  addMultiTradeItemRow();
  renderMultiTeamLimit();
};

const buildPickSummary = (pick) => {
  const year = pick.season_year || '?';
  const round = pick.round || '?';
  const origin = pick.original_team_city && pick.original_team_name
    ? `${pick.original_team_city} ${pick.original_team_name}`
    : (pick.original_team_name || 'Time');
  const via = pick.last_owner_city && pick.last_owner_name
    ? `via ${pick.last_owner_city} ${pick.last_owner_name}`
    : '';
  return {
    title: `Pick ${year} R${round}`,
    origin,
    via
  };
};

function setupPickSelectorHandlers() {
  ['offer', 'request'].forEach((side) => {
    const optionsEl = document.getElementById(`${side}PicksOptions`);
    const selectedEl = document.getElementById(`${side}PicksSelected`);

    if (optionsEl) {
      optionsEl.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action="add-pick"]');
        if (!button) return;
        addPickToSelection(side, Number(button.dataset.pickId));
      });
    }

    if (selectedEl) {
      selectedEl.addEventListener('click', (event) => {
        const removeBtn = event.target.closest('[data-action="remove-pick"]');
        if (!removeBtn) return;
        removePickFromSelection(side, Number(removeBtn.dataset.pickId));
      });

    }
  });

  renderPickOptions('offer');
  renderSelectedPicks('offer');
  renderPickOptions('request');
  renderSelectedPicks('request');
}

function setupPlayerSelectorHandlers() {
  ['offer', 'request'].forEach((side) => {
    const optionsEl = document.getElementById(`${side}PlayersOptions`);
    const selectedEl = document.getElementById(`${side}PlayersSelected`);

    if (optionsEl) {
      optionsEl.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action="add-player"]');
        if (!button) return;
        addPlayerToSelection(side, Number(button.dataset.playerId));
      });
    }

    if (selectedEl) {
      selectedEl.addEventListener('click', (event) => {
        const removeBtn = event.target.closest('[data-action="remove-player"]');
        if (!removeBtn) return;
        removePlayerFromSelection(side, Number(removeBtn.dataset.playerId));
      });
    }
  });

  renderPlayerOptions('offer');
  renderSelectedPlayers('offer');
  renderPlayerOptions('request');
  renderSelectedPlayers('request');
}

function setAvailablePicks(side, picks, { resetSelected = false } = {}) {
  const raw = Array.isArray(picks) ? picks : [];
  pickState[side].available = raw.filter((pick) => {
    const year = Number(pick.season_year || 0);
    if (!Number.isFinite(year) || year <= 0) return false;
    return year > currentSeasonYear;
  });
  if (resetSelected) {
    pickState[side].selected = [];
  } else {
    syncSelectedPickMetadata(side);
  }
  renderPickOptions(side);
  renderSelectedPicks(side);
}

function setAvailablePlayers(side, players, { resetSelected = false } = {}) {
  playerState[side].available = Array.isArray(players) ? players : [];
  if (resetSelected) {
    playerState[side].selected = [];
  } else {
    syncSelectedPlayerMetadata(side);
  }
  renderPlayerOptions(side);
  renderSelectedPlayers(side);
}

function syncSelectedPlayerMetadata(side) {
  playerState[side].selected = playerState[side].selected.map((selected) => {
    const updated = playerState[side].available.find((p) => Number(p.id) === Number(selected.id));
    return updated ? { ...updated } : selected;
  });
}

function syncSelectedPickMetadata(side) {
  pickState[side].selected = pickState[side].selected.map((selected) => {
    const updated = pickState[side].available.find((p) => Number(p.id) === Number(selected.id));
    if (updated) {
      return { ...updated, protection: selected.protection || 'none' };
    }
    return selected;
  });
}

function renderPickOptions(side) {
  const container = document.getElementById(`${side}PicksOptions`);
  if (!container) return;

  if (side === 'request' && !document.getElementById('targetTeam').value) {
    container.innerHTML = '<div class="pick-empty-state">Selecione um time para visualizar as picks disponíveis.</div>';
    return;
  }

  const picks = pickState[side].available;
  if (!picks || picks.length === 0) {
    container.innerHTML = '<div class="pick-empty-state">Nenhuma pick disponível.</div>';
    return;
  }

  const selectedIds = pickState[side].selected.map((p) => Number(p.id));
  container.innerHTML = picks.map((pick) => {
    const summary = buildPickSummary(pick);
    const isSelected = selectedIds.includes(Number(pick.id));
    const disabledAttr = isSelected ? 'disabled' : '';
    const selectedClass = isSelected ? 'is-selected' : '';
    return `
      <div class="pick-option-card ${selectedClass}">
        <div>
          <div class="pick-title">${summary.title}</div>
          <div class="pick-meta">${summary.origin}${summary.via ? ` • ${summary.via}` : ''}</div>
        </div>
        <button type="button" class="btn btn-sm ${isSelected ? 'btn-outline-secondary' : 'btn-outline-orange'}" data-action="add-pick" data-pick-id="${pick.id}" ${disabledAttr}>
          ${isSelected ? 'Selecionada' : 'Adicionar'}
        </button>
      </div>
    `;
  }).join('');
}

function renderSelectedPicks(side) {
  const container = document.getElementById(`${side}PicksSelected`);
  if (!container) return;

  const selected = pickState[side].selected;
  if (!selected || selected.length === 0) {
    container.innerHTML = '<div class="pick-empty-state">Nenhuma pick selecionada.</div>';
    return;
  }

  container.innerHTML = selected.map((pick) => {
    const summary = buildPickSummary(pick);
    return `
      <div class="selected-pick-card" data-pick-id="${pick.id}">
        <div class="selected-pick-info">
          <div class="pick-title mb-1">${summary.title}</div>
          <div class="pick-meta">${summary.origin}${summary.via ? ` • ${summary.via}` : ''}</div>
        </div>
        <div class="selected-pick-actions">
          <button type="button" class="btn btn-outline-light btn-sm" data-action="remove-pick" data-pick-id="${pick.id}">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
      </div>
    `;
  }).join('');
}

function renderPlayerOptions(side) {
  const container = document.getElementById(`${side}PlayersOptions`);
  if (!container) return;

  if (side === 'request' && !document.getElementById('targetTeam').value) {
    container.innerHTML = '<div class="pick-empty-state">Selecione um time para visualizar os jogadores disponíveis.</div>';
    return;
  }

  const players = playerState[side].available;
  if (!players || players.length === 0) {
    container.innerHTML = '<div class="pick-empty-state">Nenhum jogador disponível.</div>';
    return;
  }

  const selectedIds = playerState[side].selected.map((p) => Number(p.id));
  container.innerHTML = players.map((player) => {
    const display = formatTradePlayerDisplay(player);
    const isSelected = selectedIds.includes(Number(player.id));
    const disabledAttr = isSelected ? 'disabled' : '';
    const selectedClass = isSelected ? 'is-selected' : '';
    return `
      <div class="pick-option-card ${selectedClass}">
        <div>
          <div class="pick-title">${display}</div>
          ${player.available_for_trade ? '' : '<div class="pick-meta text-warning">Fora do trade block</div>'}
        </div>
        <button type="button" class="btn btn-sm ${isSelected ? 'btn-outline-secondary' : 'btn-outline-orange'}" data-action="add-player" data-player-id="${player.id}" ${disabledAttr}>
          ${isSelected ? 'Selecionado' : 'Adicionar'}
        </button>
      </div>
    `;
  }).join('');
}

function renderSelectedPlayers(side) {
  const container = document.getElementById(`${side}PlayersSelected`);
  if (!container) return;

  const selected = playerState[side].selected;
  if (!selected || selected.length === 0) {
    container.innerHTML = '<div class="pick-empty-state">Nenhum jogador selecionado.</div>';
    return;
  }

  container.innerHTML = selected.map((player) => {
    const display = formatTradePlayerDisplay(player);
    return `
      <div class="selected-pick-card" data-player-id="${player.id}">
        <div class="selected-pick-info">
          <div class="pick-title mb-1">${display}</div>
          ${player.available_for_trade ? '' : '<small class="text-warning">Fora do trade block</small>'}
        </div>
        <div class="selected-pick-actions">
          <button type="button" class="btn btn-outline-light btn-sm" data-action="remove-player" data-player-id="${player.id}">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
      </div>
    `;
  }).join('');
}

function addPlayerToSelection(side, playerId, fallbackPlayer = null, shouldRender = true) {
  const state = playerState[side];
  if (!state) return;
  if (state.selected.some((p) => Number(p.id) === Number(playerId))) return;
  const player = fallbackPlayer || state.available.find((p) => Number(p.id) === Number(playerId));
  if (!player) return;
  state.selected.push({ ...player });
  if (shouldRender) {
    renderPlayerOptions(side);
    renderSelectedPlayers(side);
  }
}

function removePlayerFromSelection(side, playerId) {
  const state = playerState[side];
  if (!state) return;
  state.selected = state.selected.filter((p) => Number(p.id) !== Number(playerId));
  renderPlayerOptions(side);
  renderSelectedPlayers(side);
}

function resetPlayerSelection(side) {
  if (!playerState[side]) return;
  playerState[side].selected = [];
  renderPlayerOptions(side);
  renderSelectedPlayers(side);
}

function getPlayerSelectionIds(side) {
  if (!playerState[side]) return [];
  return playerState[side].selected.map((player) => Number(player.id));
}

function prefillPlayerSelections(side, playersFromTrade) {
  if (!playerState[side]) return;
  playerState[side].selected = [];
  if (!Array.isArray(playersFromTrade) || playersFromTrade.length === 0) {
    renderPlayerOptions(side);
    renderSelectedPlayers(side);
    return;
  }
  playersFromTrade.forEach((player) => {
    addPlayerToSelection(side, Number(player.id), player, false);
  });
  renderPlayerOptions(side);
  renderSelectedPlayers(side);
}

function addPickToSelection(side, pickId, fallbackPick = null, shouldRender = true) {
  const state = pickState[side];
  if (!state) return;
  if (state.selected.some((p) => Number(p.id) === Number(pickId))) return;
  const pick = fallbackPick || state.available.find((p) => Number(p.id) === Number(pickId));
  if (!pick) return;
  state.selected.push({ ...pick });
  if (shouldRender) {
    renderPickOptions(side);
    renderSelectedPicks(side);
  }
}

function removePickFromSelection(side, pickId) {
  const state = pickState[side];
  if (!state) return;
  state.selected = state.selected.filter((p) => Number(p.id) !== Number(pickId));
  renderPickOptions(side);
  renderSelectedPicks(side);
}

function resetPickSelection(side) {
  if (!pickState[side]) return;
  pickState[side].selected = [];
  renderPickOptions(side);
  renderSelectedPicks(side);
}

function getPickPayload(side) {
  if (!pickState[side]) return [];
  return pickState[side].selected.map((pick) => ({
    id: Number(pick.id)
  }));
}

function prefillPickSelections(side, picksFromTrade) {
  if (!pickState[side]) return;
  pickState[side].selected = [];
  if (!Array.isArray(picksFromTrade) || picksFromTrade.length === 0) {
    renderPickOptions(side);
    renderSelectedPicks(side);
    return;
  }
  picksFromTrade.forEach((pick) => {
    addPickToSelection(side, Number(pick.id), pick, false);
  });
  renderPickOptions(side);
  renderSelectedPicks(side);
}


function resetTradeFormState() {
  const form = document.getElementById('proposeTradeForm');
  if (form) {
    form.reset();
  }
  ['offer', 'request'].forEach((side) => resetPlayerSelection(side));
  ['offer', 'request'].forEach((side) => resetPickSelection(side));
  const targetSelect = document.getElementById('targetTeam');
  if (targetSelect) {
    targetSelect.disabled = false;
    targetSelect.value = '';
  }
}

function clearCounterProposalState() {
  const modalEl = document.getElementById('proposeTradeModal');
  if (modalEl && modalEl.dataset.counterTo) {
    delete modalEl.dataset.counterTo;
  }
  const targetSelect = document.getElementById('targetTeam');
  if (targetSelect) {
    targetSelect.disabled = false;
  }
}

async function init() {
  if (!myTeamId) return;
  
  // Carregar times da liga
  await loadTeams();
  renderMultiTradeTeams();
  
  setupPickSelectorHandlers();
  setupPlayerSelectorHandlers();

  // Carregar meus jogadores e picks
  await loadMyAssets();
  
  // Carregar trades
  loadTrades('received');
  loadTrades('sent');
  loadTrades('history');
  loadTrades('league');
  
  // Event listeners
  document.getElementById('submitTradeBtn').addEventListener('click', submitTrade);
  document.getElementById('targetTeam').addEventListener('change', onTargetTeamChange);
  const addMultiItemBtn = document.getElementById('addMultiTradeItemBtn');
  if (addMultiItemBtn) {
    addMultiItemBtn.addEventListener('click', addMultiTradeItemRow);
  }
  const submitMultiBtn = document.getElementById('submitMultiTradeBtn');
  if (submitMultiBtn) {
    submitMultiBtn.addEventListener('click', submitMultiTrade);
  }

  // Event listener para busca de jogador nas trades gerais
  const leagueTradesSearch = document.getElementById('leagueTradesSearch');
  if (leagueTradesSearch) {
    leagueTradesSearch.addEventListener('input', (e) => {
      filterLeagueTrades(e.target.value);
    });
  }

  const tradeModalEl = document.getElementById('proposeTradeModal');
  if (tradeModalEl) {
    tradeModalEl.addEventListener('hidden.bs.modal', () => {
      resetTradeFormState();
      clearCounterProposalState();
    });
  }

  const multiModalEl = document.getElementById('multiTradeModal');
  if (multiModalEl) {
    multiModalEl.addEventListener('hidden.bs.modal', () => {
      resetMultiTradeForm();
    });
  }

  const multiItemsContainer = document.getElementById('multiTradeItems');
  if (multiItemsContainer && multiItemsContainer.children.length === 0) {
    addMultiTradeItemRow();
  }

  // Verificar se há jogador pré-selecionado na URL
  const urlParams = new URLSearchParams(window.location.search);
  const preselectedPlayerId = urlParams.get('player');
  const preselectedTeamId = urlParams.get('team');
  
  if (preselectedPlayerId && preselectedTeamId) {
    // Abrir modal automaticamente com o jogador e time pré-selecionado
    setTimeout(async () => {
      await openTradeWithPreselectedPlayer(preselectedPlayerId, preselectedTeamId);
    }, 500);
  }
}

async function openTradeWithPreselectedPlayer(playerId, teamId) {
  try {
    // Selecionar o time
    const targetTeamSelect = document.getElementById('targetTeam');
    targetTeamSelect.value = teamId;
    
    // Carregar jogadores do time alvo
    await onTargetTeamChange({ target: targetTeamSelect });
    
    // Aguardar um pouco para garantir que os selects foram populados
    setTimeout(() => {
      // Pré-selecionar o jogador solicitado
      addPlayerToSelection('request', Number(playerId));
      
      // Abrir o modal
      const modal = new bootstrap.Modal(document.getElementById('proposeTradeModal'));
      modal.show();
      
      // Adicionar nota sugerindo a trade
      document.getElementById('tradeNotes').value = 'Olá! Tenho interesse neste jogador. Vamos negociar?';
    }, 300);
  } catch (err) {
    console.error('Erro ao pré-selecionar jogador:', err);
  }
}

async function loadTeams() {
  try {
    const data = await api('teams.php');
    allTeams = data.teams || [];
    
    console.log('Times carregados:', allTeams.length, 'Meu time:', myTeamId, 'Minha liga:', myLeague);
    
    // Preencher select de times (exceto o meu, apenas da mesma liga)
    const select = document.getElementById('targetTeam');
    select.innerHTML = '<option value="">Selecione...</option>';
    const myId = Number(myTeamId);
    const myLeagueNormalized = (myLeague ?? '').toString().trim().toUpperCase();

    const filteredTeams = allTeams.filter(t => {
      const teamId = Number(t.id);
      if (!Number.isFinite(teamId) || teamId === myId) return false;
      const teamLeagueNormalized = (t.league ?? '').toString().trim().toUpperCase();
      // Se não conseguir determinar liga, deixa passar, mas prioriza comparação normalizada
      if (!myLeagueNormalized) return true;
      return teamLeagueNormalized === myLeagueNormalized;
    });
    
    console.log('Times filtrados para trade:', filteredTeams.length);
    
    if (filteredTeams.length === 0) {
      const option = document.createElement('option');
      option.disabled = true;
      option.textContent = 'Nenhum time disponível na sua liga';
      select.appendChild(option);
      return;
    }

    filteredTeams
      .sort((a, b) => `${a.city} ${a.name}`.localeCompare(`${b.city} ${b.name}`))
      .forEach(t => {
        const option = document.createElement('option');
        option.value = t.id;
        option.textContent = `${t.city} ${t.name}`;
        select.appendChild(option);
      });
  } catch (err) {
    console.error('Erro ao carregar times:', err);
  }
}

async function loadMyAssets() {
  try {
    // Meus jogadores disponíveis para troca
    const playersData = await api(`players.php?team_id=${myTeamId}`);
  myPlayers = playersData.players || [];
    
  console.log('Meus jogadores carregados:', myPlayers.length);
    
    setAvailablePlayers('offer', myPlayers, { resetSelected: true });
    
    // Minhas picks
    const picksData = await api(`picks.php?team_id=${myTeamId}`);
    myPicks = picksData.picks || [];
    
    console.log('Minhas picks:', myPicks.length);
    setAvailablePicks('offer', myPicks);
  } catch (err) {
    console.error('Erro ao carregar assets:', err);
  }
}

async function onTargetTeamChange(e) {
  const teamId = e.target.value;
  if (!teamId) {
    setAvailablePlayers('request', [], { resetSelected: true });
    setAvailablePicks('request', [], { resetSelected: true });
    return;
  }
  
  try {
    // Carregar jogadores do time alvo
    const playersData = await api(`players.php?team_id=${teamId}`);
  const players = playersData.players || [];
    
  console.log('Jogadores do time alvo carregados:', players.length);
    
    setAvailablePlayers('request', players, { resetSelected: true });
    
    // Carregar picks do time alvo
    const picksData = await api(`picks.php?team_id=${teamId}`);
    const picks = picksData.picks || [];
    
    console.log('Picks do time alvo:', picks.length);
    setAvailablePicks('request', picks, { resetSelected: true });
  } catch (err) {
    console.error('Erro ao carregar assets do time:', err);
  }
}

async function submitTrade() {
  const targetTeam = document.getElementById('targetTeam').value;
  const offerPlayers = getPlayerSelectionIds('offer');
  const requestPlayers = getPlayerSelectionIds('request');
  const offerPickPayload = getPickPayload('offer');
  const requestPickPayload = getPickPayload('request');
  const notes = document.getElementById('tradeNotes').value;
  const modalEl = document.getElementById('proposeTradeModal');
  const counterTo = modalEl && modalEl.dataset.counterTo ? parseInt(modalEl.dataset.counterTo, 10) : null;
  
  if (!targetTeam) {
    return alert('Selecione um time.');
  }
  
  if (offerPlayers.length === 0 && offerPickPayload.length === 0) {
    return alert('Você precisa oferecer algo (jogadores ou picks).');
  }
  
  if (requestPlayers.length === 0 && requestPickPayload.length === 0) {
    return alert('Você precisa pedir algo em troca (jogadores ou picks).');
  }
  
  try {
    const payload = {
      to_team_id: parseInt(targetTeam),
      offer_players: offerPlayers,
      offer_picks: offerPickPayload,
      request_players: requestPlayers,
      request_picks: requestPickPayload,
      notes
    };
    if (counterTo) {
      payload.counter_to_trade_id = counterTo;
    }

    await api('trades.php', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    
    alert('Proposta de trade enviada!');
    bootstrap.Modal.getInstance(document.getElementById('proposeTradeModal')).hide();
    clearCounterProposalState();
    document.getElementById('proposeTradeForm').reset();
    loadTrades('sent');
    loadTrades('received');
    loadTrades('history');
    loadTrades('league');
  } catch (err) {
    alert(err.error || 'Erro ao enviar trade');
  }
}

function filterLeagueTrades(searchTerm) {
  const container = document.getElementById('leagueTradesList');
  const badge = document.getElementById('leagueTradesCount');
  
  if (!searchTerm || searchTerm.trim() === '') {
    // Mostrar todas as trades
    container.innerHTML = '';
    allLeagueTrades.forEach(trade => {
      const card = createTradeCard(trade, 'league');
      container.appendChild(card);
    });
    badge.textContent = `${allLeagueTrades.length} ${allLeagueTrades.length === 1 ? 'trade' : 'trocas'}`;
    return;
  }
  
  const term = searchTerm.toLowerCase().trim();
  
  // Filtrar trades que contenham o jogador
  const filtered = allLeagueTrades.filter(trade => {
    if (trade.is_multi) {
      return (trade.items || []).some((item) => {
        return item.player_name && item.player_name.toLowerCase().includes(term);
      });
    }
    // Buscar em offer_players
    const hasInOffer = trade.offer_players.some(p => 
      p.name && p.name.toLowerCase().includes(term)
    );
    
    // Buscar em request_players
    const hasInRequest = trade.request_players.some(p => 
      p.name && p.name.toLowerCase().includes(term)
    );
    
    return hasInOffer || hasInRequest;
  });
  
  // Renderizar resultados
  container.innerHTML = '';
  if (filtered.length === 0) {
    container.innerHTML = `<div class="text-center text-light-gray py-4">Nenhuma trade encontrada com "${searchTerm}"</div>`;
    badge.textContent = '0 trocas';
    return;
  }
  
  filtered.forEach(trade => {
    const card = createTradeCard(trade, 'league');
    container.appendChild(card);
  });
  
  badge.textContent = `${filtered.length} ${filtered.length === 1 ? 'trade' : 'trocas'}`;
}

async function loadTrades(type) {
  const container = document.getElementById(`${type}TradesList`);
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const [dataResult, multiResult] = await Promise.allSettled([
      api(`trades.php?type=${type}`),
      api(`trades.php?action=multi_trades&type=${type}`)
    ]);
    const data = dataResult.status === 'fulfilled' ? dataResult.value : { trades: [] };
    const multiData = multiResult.status === 'fulfilled' ? multiResult.value : { trades: [] };
    const trades = (data.trades || []).map((trade) => ({ ...trade, is_multi: false }));
    const multiTrades = (multiData.trades || []).map((trade) => ({ ...trade, is_multi: true }));
    const combined = [...trades, ...multiTrades].sort((a, b) => {
      return new Date(b.created_at) - new Date(a.created_at);
    });
    
    // Armazenar trades da liga para busca
    if (type === 'league') {
      allLeagueTrades = combined;
    }
    
    if (combined.length === 0) {
      container.innerHTML = '<div class="text-center text-light-gray py-4">Nenhuma trade encontrada</div>';
      if (type === 'league') {
        const badge = document.getElementById('leagueTradesCount');
        if (badge) badge.textContent = '0 trocas';
      }
      return;
    }
    
    container.innerHTML = '';
    combined.forEach(trade => {
      const card = createTradeCard(trade, type);
      container.appendChild(card);
    });

    if (type === 'league') {
      const badge = document.getElementById('leagueTradesCount');
      if (badge) {
        badge.textContent = `${combined.length} ${combined.length === 1 ? 'trade' : 'trocas'}`;
      }
    }
  } catch (err) {
    container.innerHTML = '<div class="text-center text-danger py-4">Erro ao carregar trades</div>';
  }
}

function createMultiTradeCard(trade, type) {
  const card = document.createElement('div');
  card.className = 'bg-dark-panel border-orange rounded p-4 mb-3';

  const statusBadge = {
    'pending': '<span class="badge bg-warning text-dark">Pendente</span>',
    'accepted': '<span class="badge bg-success">Aceita</span>',
    'rejected': '<span class="badge bg-danger">Rejeitada</span>',
    'cancelled': '<span class="badge bg-secondary">Cancelada</span>'
  }[trade.status] || '<span class="badge bg-secondary">-</span>';

  const teamMap = {};
  (trade.teams || []).forEach((team) => {
    teamMap[team.id] = getTeamLabel(team);
  });

  const acceptanceBadge = trade.status === 'pending'
    ? `<span class="badge bg-info text-dark">Aceitar ${trade.teams_accepted || 0}/${trade.teams_total || 0}</span>`
    : '';

  const reactionBar = type === 'league'
    ? `<div class="reaction-bar mt-2">${buildTradeReactionBar(trade, 'multi')}</div>`
    : '';

  const items = (trade.items || []).map((item) => {
    const fromLabel = teamMap[item.from_team_id] || `Time ${item.from_team_id}`;
    const toLabel = teamMap[item.to_team_id] || `Time ${item.to_team_id}`;
    let detail = '';
    if (item.player_id) {
      detail = formatTradePlayerDisplay({
        name: item.player_name,
        position: item.player_position,
        age: item.player_age,
        ovr: item.player_ovr
      });
    } else if (item.pick_id) {
      detail = formatTradePickDisplay(item);
    }
    return `<li><i class="bi bi-arrow-left-right text-orange"></i> <strong>${fromLabel}</strong> → <strong>${toLabel}</strong>: ${detail || 'Item'}</li>`;
  }).join('');

  const teamsList = (trade.teams || []).map((team) => {
    return `<span class="team-chip"><span class="team-chip-badge">${team.city?.[0] || 'T'}</span>${getTeamLabel(team)}</span>`;
  }).join('');

  card.innerHTML = `
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
      <div>
        <h5 class="text-white mb-1">Trade múltipla</h5>
        <small class="text-light-gray">${new Date(trade.created_at).toLocaleDateString('pt-BR')}</small>
      </div>
      <div class="d-flex gap-2 align-items-center">
        ${acceptanceBadge}
        ${statusBadge}
      </div>
    </div>
    ${reactionBar}
    <div class="mb-3 d-flex flex-wrap gap-2">${teamsList || '<span class="text-muted">Times</span>'}</div>
    <div>
      <h6 class="text-orange mb-2">Itens</h6>
      <ul class="list-unstyled text-white">
        ${items || '<li class="text-muted">Nenhum item</li>'}
      </ul>
    </div>
    ${trade.notes ? `<div class="mt-3 p-2 bg-dark rounded"><small class="text-light-gray"><i class="bi bi-chat-left-text me-1"></i>${trade.notes}</small></div>` : ''}
  `;

  if (trade.status === 'pending' && type === 'received') {
    const actions = document.createElement('div');
    actions.className = 'mt-3 d-flex gap-2 flex-wrap';
    actions.innerHTML = `
      <button class="btn btn-success btn-sm" ${trade.my_accepted ? 'disabled' : ''}>
        <i class="bi bi-check-circle me-1"></i>${trade.my_accepted ? 'Aceito' : 'Aceitar'}
      </button>
      <button class="btn btn-danger btn-sm">
        <i class="bi bi-x-circle me-1"></i>Rejeitar
      </button>
    `;
    const [acceptBtn, rejectBtn] = actions.querySelectorAll('button');
    acceptBtn.addEventListener('click', () => respondMultiTrade(trade.id, 'accepted'));
    rejectBtn.addEventListener('click', () => respondMultiTrade(trade.id, 'rejected'));
    card.appendChild(actions);
  }

  if (trade.status === 'pending' && type === 'sent') {
    const actions = document.createElement('div');
    actions.className = 'mt-3 d-flex gap-2 flex-wrap';
    actions.innerHTML = `
      <button class="btn btn-secondary btn-sm">
        <i class="bi bi-x-circle me-1"></i>Cancelar
      </button>
    `;
    actions.querySelector('button').addEventListener('click', () => respondMultiTrade(trade.id, 'cancelled'));
    card.appendChild(actions);
  }

  return card;
}

function createTradeCard(trade, type) {
  if (trade.is_multi) {
    return createMultiTradeCard(trade, type);
  }
  const card = document.createElement('div');
  card.className = 'bg-dark-panel border-orange rounded p-4 mb-3';
  
  const statusBadge = {
    'pending': '<span class="badge bg-warning text-dark">Pendente</span>',
    'accepted': '<span class="badge bg-success">Aceita</span>',
    'rejected': '<span class="badge bg-danger">Rejeitada</span>',
    'cancelled': '<span class="badge bg-secondary">Cancelada</span>',
    'countered': '<span class="badge bg-info">Contraproposta</span>'
  }[trade.status];
  
  const fromTeam = `${trade.from_city} ${trade.from_name}`;
  const toTeam = `${trade.to_city} ${trade.to_name}`;
  
  // Verificar se tem observação de resposta
  const responseNotes = trade.response_notes ? `
    <div class="mt-2 p-2 bg-dark rounded border-start border-warning border-3">
      <small class="text-warning fw-bold"><i class="bi bi-chat-dots me-1"></i>Resposta:</small>
      <small class="text-light-gray d-block">${trade.response_notes}</small>
    </div>
  ` : '';

  const reactionBar = type === 'league'
    ? `<div class="reaction-bar mt-2">${buildTradeReactionBar(trade, 'single')}</div>`
    : '';
  
  card.innerHTML = `
    <div class="d-flex justify-content-between align-items-start mb-3">
      <div>
        <h5 class="text-white mb-1">${fromTeam} <i class="bi bi-arrow-right text-orange"></i> ${toTeam}</h5>
        <small class="text-light-gray">${new Date(trade.created_at).toLocaleDateString('pt-BR')}</small>
      </div>
      <div>${statusBadge}</div>
    </div>
    ${reactionBar}
    
    <div class="row">
      <div class="col-md-6">
        <h6 class="text-orange mb-2">${fromTeam} oferece:</h6>
        <ul class="list-unstyled text-white">
          ${trade.offer_players.map(p => `<li><i class="bi bi-person-fill text-orange"></i> ${formatTradePlayerDisplay(p)}</li>`).join('')}
          ${trade.offer_picks.map(p => `<li><i class="bi bi-trophy-fill text-orange"></i> ${formatTradePickDisplay(p)}</li>`).join('')}
          ${(trade.offer_players.length === 0 && trade.offer_picks.length === 0) ? '<li class="text-muted">Nenhum item</li>' : ''}
        </ul>
      </div>
      <div class="col-md-6">
        <h6 class="text-orange mb-2">${toTeam} envia:</h6>
        <ul class="list-unstyled text-white">
          ${trade.request_players.map(p => `<li><i class="bi bi-person-fill text-orange"></i> ${formatTradePlayerDisplay(p)}</li>`).join('')}
          ${trade.request_picks.map(p => `<li><i class="bi bi-trophy-fill text-orange"></i> ${formatTradePickDisplay(p)}</li>`).join('')}
          ${(trade.request_players.length === 0 && trade.request_picks.length === 0) ? '<li class="text-muted">Nenhum item</li>' : ''}
        </ul>
      </div>
    </div>
    
    ${trade.notes ? `<div class="mt-3 p-2 bg-dark rounded"><small class="text-light-gray"><i class="bi bi-chat-left-text me-1"></i>${trade.notes}</small></div>` : ''}
    ${responseNotes}
    
    ${trade.status === 'pending' && type === 'received' ? `
      <div class="mt-3">
        <div class="mb-2">
          <label class="form-label text-light-gray small">Observação (opcional):</label>
          <textarea class="form-control form-control-sm bg-dark text-white border-secondary" 
                    id="responseNotes_${trade.id}" rows="2" 
                    placeholder="Adicione uma mensagem..."></textarea>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-success btn-sm" onclick="respondTrade(${trade.id}, 'accepted')">
            <i class="bi bi-check-circle me-1"></i>Aceitar
          </button>
          <button class="btn btn-danger btn-sm" onclick="respondTrade(${trade.id}, 'rejected')">
            <i class="bi bi-x-circle me-1"></i>Rejeitar
          </button>
          <button class="btn btn-info btn-sm" onclick="openCounterProposal(${trade.id}, ${JSON.stringify(trade).replace(/"/g, '&quot;')})">
            <i class="bi bi-arrow-repeat me-1"></i>Contraproposta
          </button>
        </div>
      </div>
    ` : ''}
    
    ${trade.status === 'pending' && type === 'sent' ? `
      <div class="mt-3 d-flex gap-2 flex-wrap">
        <button class="btn btn-warning btn-sm" onclick="openModifyTrade(${trade.id}, ${JSON.stringify(trade).replace(/"/g, '&quot;')})">
          <i class="bi bi-pencil me-1"></i>Modificar
        </button>
        <button class="btn btn-secondary btn-sm" onclick="respondTrade(${trade.id}, 'cancelled')">
          <i class="bi bi-x-circle me-1"></i>Cancelar
        </button>
      </div>
    ` : ''}
  `;
  
  return card;
}

async function respondTrade(tradeId, action) {
  const actionTexts = {
    'accepted': 'aceitar',
    'rejected': 'rejeitar', 
    'cancelled': 'cancelar'
  };
  
  if (!confirm(`Confirma ${actionTexts[action]} esta trade?`)) {
    return;
  }
  
  // Pegar observação se existir
  const notesEl = document.getElementById(`responseNotes_${tradeId}`);
  const responseNotes = notesEl ? notesEl.value.trim() : '';
  
  try {
    await api('trades.php', {
      method: 'PUT',
      body: JSON.stringify({ trade_id: tradeId, action, response_notes: responseNotes })
    });
    
    alert('Trade atualizada!');
    loadTrades('received');
    loadTrades('sent');
    loadTrades('history');
  loadTrades('league');
    // Atualiza meus jogadores e picks imediatamente após a decisão
    try {
      await loadMyAssets();
      // Se um time alvo estiver selecionado no modal, recarregar os assets dele também
      const targetEl = document.getElementById('targetTeam');
      if (targetEl && targetEl.value) {
        await onTargetTeamChange({ target: targetEl });
      }
    } catch (e) {
      console.warn('Falha ao atualizar assets após trade:', e);
    }
  } catch (err) {
    alert(err.error || 'Erro ao atualizar trade');
  }
}

async function respondMultiTrade(tradeId, action) {
  const actionTexts = {
    'accepted': 'aceitar',
    'rejected': 'rejeitar',
    'cancelled': 'cancelar'
  };

  if (!confirm(`Confirma ${actionTexts[action]} esta trade múltipla?`)) {
    return;
  }

  try {
    await api('trades.php?action=multi_trades', {
      method: 'PUT',
      body: JSON.stringify({ trade_id: tradeId, action })
    });
    alert('Trade múltipla atualizada!');
    loadTrades('received');
    loadTrades('sent');
    loadTrades('history');
    loadTrades('league');
    try {
      await loadMyAssets();
    } catch (e) {
      console.warn('Falha ao atualizar assets após trade múltipla:', e);
    }
  } catch (err) {
    alert(err.error || 'Erro ao atualizar trade múltipla');
  }
}

async function toggleTradeReaction(tradeId, tradeType, encodedEmoji) {
  try {
    const emoji = decodeURIComponent(encodedEmoji);
    const trade = allLeagueTrades.find(tr => {
      if (tradeType === 'multi') {
        return tr.is_multi && Number(tr.id) === Number(tradeId);
      }
      return !tr.is_multi && Number(tr.id) === Number(tradeId);
    });
    const reactions = Array.isArray(trade?.reactions) ? trade.reactions : [];
    const mineEmoji = reactions.find(r => r.mine)?.emoji || null;
    const action = mineEmoji === emoji ? 'remove' : 'set';

    const result = await api('trades.php?action=trade_reaction', {
      method: 'POST',
      body: JSON.stringify({ trade_id: tradeId, trade_type: tradeType, emoji, action })
    });

    updateTradeReactionsInState(tradeId, tradeType, result.reactions || []);
    const searchInput = document.getElementById('leagueTradesSearch');
    filterLeagueTrades(searchInput ? searchInput.value : '');
  } catch (err) {
    console.warn('Falha ao reagir a trade:', err);
  }
}

// Abrir modal de contraproposta
async function openCounterProposal(originalTradeId, originalTrade) {
  // Decodificar o trade se vier como string
  if (typeof originalTrade === 'string') {
    originalTrade = JSON.parse(originalTrade.replace(/&quot;/g, '"'));
  }
  
  // Preencher o modal com dados invertidos
  const targetSelect = document.getElementById('targetTeam');
  targetSelect.value = originalTrade.from_team_id;
  targetSelect.disabled = true; // Não pode mudar o time
  
  // Carregar jogadores e picks do time que enviou a proposta original
  await onTargetTeamChange({ target: targetSelect });

  prefillPlayerSelections('offer', originalTrade.request_players || []);
  prefillPlayerSelections('request', originalTrade.offer_players || []);
  prefillPickSelections('offer', originalTrade.request_picks || []);
  prefillPickSelections('request', originalTrade.offer_picks || []);
  
  // Adicionar nota de contraproposta
  document.getElementById('tradeNotes').value = `[CONTRAPROPOSTA] Em resposta à proposta #${originalTradeId}`;
  
  // Abrir modal
  const modal = new bootstrap.Modal(document.getElementById('proposeTradeModal'));
  modal.show();
  
  // Guardar ID da trade original para cancelar depois
  document.getElementById('proposeTradeModal').dataset.counterTo = originalTradeId;
}

// Abrir modal para modificar trade (quem enviou)
async function openModifyTrade(tradeId, trade) {
  // Decodificar o trade se vier como string
  if (typeof trade === 'string') {
    trade = JSON.parse(trade.replace(/&quot;/g, '"'));
  }
  
  // Primeiro, cancelar a trade atual
  if (!confirm('Para modificar, a proposta atual será cancelada e uma nova será criada. Continuar?')) {
    return;
  }
  
  try {
    await api('trades.php', {
      method: 'PUT',
      body: JSON.stringify({ trade_id: tradeId, action: 'cancelled' })
    });
    
    // Preencher o modal com os dados da trade
    document.getElementById('targetTeam').value = trade.to_team_id;
    await onTargetTeamChange({ target: document.getElementById('targetTeam') });
    
  prefillPlayerSelections('offer', trade.offer_players || []);
  prefillPlayerSelections('request', trade.request_players || []);
    prefillPickSelections('offer', trade.offer_picks || []);
    prefillPickSelections('request', trade.request_picks || []);
    
    document.getElementById('tradeNotes').value = trade.notes || '';
    
    // Abrir modal
    const modal = new bootstrap.Modal(document.getElementById('proposeTradeModal'));
    modal.show();
    
    // Atualizar listas
    loadTrades('sent');
    loadTrades('history');
  } catch (err) {
    alert(err.error || 'Erro ao modificar trade');
  }
}

// Inicializar
document.addEventListener('DOMContentLoaded', init);
