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
let myTeamName = window.__TEAM_NAME__ || 'Seu time';
let allTeams = [];
let myPlayers = [];
let targetTeamPlayers = [];
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
  const pickNumber = pick.draft_pick_number || null;
  const hasYearRound = year !== '?' && round !== '?';
  const isCurrentDraft = Number(year) === currentSeasonYear && Number(pickNumber || 0) > 0 && Number(pick.draft_session_id || 0) > 0;
  
  // Mostrar de quem é a pick (time original)
  const originalTeam = pick.original_team_city && pick.original_team_name 
    ? `${pick.original_team_city} ${pick.original_team_name}` 
    : 'Time';
  
  let display = (pickNumber && isCurrentDraft)
    ? `Pick ${pickNumber} (${originalTeam})${hasYearRound ? ` - ${year} R${round}` : ''}`
    : `Pick ${year} R${round} (${originalTeam})`;

  if (pick.swap_type) {
    display += ` <span class="badge bg-secondary ms-1">${pick.swap_type}</span>`;
  }

  if (isCurrentDraft) {
    display += ' - Draft atual';
  }
  
  // Se a pick foi trocada (team_id != original_team_id), mostrar "via"
  if (pick.team_id && pick.original_team_id && pick.team_id != pick.original_team_id) {
    // Mostrar quem enviou a pick (last_owner)
    if (pick.last_owner_city && pick.last_owner_name) {
      display += ` <span class="text-info">via ${pick.last_owner_city} ${pick.last_owner_name}</span>`;
    }
  }

  return display;
};

const calcTop8Cap = (players = []) => {
  const sorted = [...players].sort((a, b) => (Number(b.ovr) || 0) - (Number(a.ovr) || 0));
  return sorted.slice(0, 8).reduce((sum, p) => sum + (Number(p.ovr) || 0), 0);
};

const computeCapProjection = (basePlayers = [], outgoing = [], incoming = []) => {
  const outIds = new Set(outgoing.map((p) => Number(p.id)));
  const roster = basePlayers.filter((p) => !outIds.has(Number(p.id))).concat(incoming.map((p) => ({ ...p })));
  return calcTop8Cap(roster);
};

const formatCapValue = (value) => Number.isFinite(value) ? value.toLocaleString('pt-BR') : '-';

function updateCapImpact() {
  const capRow = document.getElementById('capImpactRow');
  if (!capRow) return;

  const myCurrent = calcTop8Cap(myPlayers);
  const targetCurrent = calcTop8Cap(targetTeamPlayers);
  const offerPlayers = playerState.offer.selected || [];
  const requestPlayers = playerState.request.selected || [];

  const myProjected = computeCapProjection(myPlayers, offerPlayers, requestPlayers);
  const targetProjected = targetTeamPlayers.length
    ? computeCapProjection(targetTeamPlayers, requestPlayers, offerPlayers)
    : null;

  const myDelta = Number.isFinite(myProjected) ? myProjected - myCurrent : null;
  const targetDelta = Number.isFinite(targetProjected) ? targetProjected - targetCurrent : null;

  const capMyCurrentEl = document.getElementById('capMyCurrent');
  const capMyProjectedEl = document.getElementById('capMyProjected');
  const capMyDeltaEl = document.getElementById('capMyDelta');
  const capTargetCurrentEl = document.getElementById('capTargetCurrent');
  const capTargetProjectedEl = document.getElementById('capTargetProjected');
  const capTargetDeltaEl = document.getElementById('capTargetDelta');
  const capTargetLabel = document.getElementById('capTargetLabel');

  const applyDeltaBadge = (el, delta) => {
    if (!el) return;
    el.className = 'badge';
    if (!Number.isFinite(delta)) {
      el.classList.add('bg-secondary');
      el.textContent = '±0';
      return;
    }
    if (delta > 0) {
      el.classList.add('bg-success');
    } else if (delta < 0) {
      el.classList.add('bg-danger');
    } else {
      el.classList.add('bg-secondary');
    }
    el.textContent = `${delta > 0 ? '+' : ''}${delta}`;
  };

  if (capMyCurrentEl) capMyCurrentEl.textContent = formatCapValue(myCurrent);
  if (capMyProjectedEl) capMyProjectedEl.textContent = Number.isFinite(myProjected) ? formatCapValue(myProjected) : '-';
  applyDeltaBadge(capMyDeltaEl, myDelta);

  const targetTeamSelect = document.getElementById('targetTeam');
  if (capTargetLabel && targetTeamSelect) {
    const opt = targetTeamSelect.selectedOptions[0];
    capTargetLabel.textContent = opt ? opt.textContent : 'Time alvo';
  }

  if (capTargetCurrentEl) capTargetCurrentEl.textContent = targetTeamPlayers.length ? formatCapValue(targetCurrent) : '-';
  if (capTargetProjectedEl) capTargetProjectedEl.textContent = (targetTeamPlayers.length && Number.isFinite(targetProjected))
    ? formatCapValue(targetProjected)
    : '-';
  applyDeltaBadge(capTargetDeltaEl, (targetTeamPlayers.length && Number.isFinite(targetDelta)) ? targetDelta : null);
}

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
    updateMultiItemOptions(row, true).catch((err) => console.warn('Erro ao atualizar itens:', err));
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
  let list = type === 'players' ? (data.players || []) : (data.picks || []);
  if (type === 'picks') {
    list = list.filter((pick) => {
      if (Number(pick.swap_locked || 0) === 1 && !pick.swap_type) return false;
      const year = Number(pick.season_year || 0);
      return Number.isFinite(year) && year >= currentSeasonYear;
    });
  }
  multiTradeState.assets[type][teamId] = list;
  return list;
};

const updateMultiItemOptions = async (row, keepItemSelection = false) => {
  const fromSelect = row.querySelector('[data-role="from-team"]');
  const typeSelect = row.querySelector('[data-role="item-type"]');
  const itemSelect = row.querySelector('[data-role="item-id"]');
  if (!fromSelect || !typeSelect || !itemSelect) return;

  const teamId = Number(fromSelect.value);
  const type = typeSelect.value;
  const previousItemId = keepItemSelection ? itemSelect.value : '';
  if (!teamId || !type) {
    itemSelect.innerHTML = '<option value="">Selecione a origem e o tipo</option>';
    return;
  }

  const list = await loadMultiAssets(teamId, type === 'player' ? 'players' : 'picks');
  if (!list || list.length === 0) {
    itemSelect.innerHTML = '<option value="">Nenhum item dispon?vel</option>';
    return;
  }

  if (type === 'player') {
    itemSelect.innerHTML = '<option value="">Selecione o jogador</option>' + list.map((player) => {
      return `<option value="${player.id}">${formatTradePlayerDisplay(player)}</option>`;
    }).join('');
  } else {
    itemSelect.innerHTML = '<option value="">Selecione a pick</option>' + list.map((pick) => {
      const summary = buildPickSummary(pick);
      const via = summary.via ? ` ? ${summary.via}` : '';
      const meta = summary.meta ? ` ${summary.meta}` : '';
      return `<option value="${pick.id}">${summary.title}${meta} (${summary.origin}${via})</option>`;
    }).join('');
  }

  if (keepItemSelection && previousItemId) {
    itemSelect.value = previousItemId;
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
      updateMultiItemOptions(row, false);
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
  const modal = document.getElementById('multiTradeModal');
  const editTradeId = modal?.dataset.editTradeId ? parseInt(modal.dataset.editTradeId, 10) : null;

  try {
    if (editTradeId) {
      await api('trades.php?action=edit_multi_trade', {
        method: 'PUT',
        body: JSON.stringify({ trade_id: editTradeId, teams: selectedTeams, items, notes })
      });
      alert('Trade múltipla atualizada!');
    } else {
      await api('trades.php?action=multi_trades', {
        method: 'POST',
        body: JSON.stringify({ teams: selectedTeams, items, notes })
      });
      alert('Troca múltipla enviada!');
    }
    bootstrap.Modal.getInstance(modal).hide();
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
  const modal = document.getElementById('multiTradeModal');
  if (modal) {
    delete modal.dataset.editTradeId;
    const modalTitle = modal.querySelector('.modal-title');
    if (modalTitle) modalTitle.innerHTML = '<i class="bi bi-people-fill me-2" style="color:var(--red)"></i>Trade Múltipla';
  }
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
  const pickNumber = pick.draft_pick_number || null;
  const isCurrentDraft = Number(year) === currentSeasonYear && Number(pickNumber || 0) > 0 && Number(pick.draft_session_id || 0) > 0;
  const origin = pick.original_team_city && pick.original_team_name
    ? `${pick.original_team_city} ${pick.original_team_name}`
    : (pick.original_team_name || 'Time');
  const via = pick.last_owner_city && pick.last_owner_name
    ? `via ${pick.last_owner_city} ${pick.last_owner_name}`
    : '';
  const metaParts = [];
  if (pickNumber && isCurrentDraft) {
    metaParts.push(`${year} R${round}`);
    metaParts.push('Draft atual');
  }
  if (pick.swap_type) {
    metaParts.push(pick.swap_type);
  }
  return {
    title: (pickNumber && isCurrentDraft) ? `Pick ${pickNumber}` : `Pick ${year} R${round}`,
    origin,
    via,
    meta: metaParts.join(' - ')
  };
};

const findSelectedPickById = (pickId) => {
  const id = Number(pickId);
  return pickState.offer.selected.find(p => Number(p.id) === id)
    || pickState.request.selected.find(p => Number(p.id) === id)
    || null;
};

const getSwapKey = (pick) => `${pick.season_year || ''}-${pick.round || ''}`;

const getSwapCandidateMap = () => {
  const byKey = (list) => list.reduce((acc, pick) => {
    if (pick.swap_type) return acc; // picks que já são swap não podem ser re-swappadas
    const key = getSwapKey(pick);
    if (!acc[key]) acc[key] = [];
    acc[key].push(pick);
    return acc;
  }, {});

  const offerByKey = byKey(pickState.offer.selected);
  const requestByKey = byKey(pickState.request.selected);
  const map = {};

  Object.keys(offerByKey).forEach((key) => {
    if (!requestByKey[key]) return;
    if (offerByKey[key].length !== 1 || requestByKey[key].length !== 1) return;
    const offerPick = offerByKey[key][0];
    const requestPick = requestByKey[key][0];
    map[Number(offerPick.id)] = Number(requestPick.id);
    map[Number(requestPick.id)] = Number(offerPick.id);
  });

  return map;
};

const getOppositeSwapRole = (role) => role === 'SB' ? 'SW' : 'SB';

const syncSwapRoles = () => {
  const candidateMap = getSwapCandidateMap();
  ['offer', 'request'].forEach((side) => {
    pickState[side].selected.forEach((pick) => {
      const pairId = candidateMap[Number(pick.id)];
      if (!pairId && pick.swapRole) {
        delete pick.swapRole;
      }
      if (pairId && pick.swapRole) {
        const pairPick = findSelectedPickById(pairId);
        if (pairPick && !pairPick.swapRole) {
          pairPick.swapRole = getOppositeSwapRole(pick.swapRole);
        }
      }
    });
  });
};

const setSwapRole = (pickId, role) => {
  const candidateMap = getSwapCandidateMap();
  const pairId = candidateMap[Number(pickId)];
  if (!pairId) return;
  const pick = findSelectedPickById(pickId);
  const pairPick = findSelectedPickById(pairId);
  if (!pick || !pairPick) return;
  pick.swapRole = role;
  pairPick.swapRole = getOppositeSwapRole(role);
};

const clearSwapRole = (pickId) => {
  const candidateMap = getSwapCandidateMap();
  const pairId = candidateMap[Number(pickId)];
  const pick = findSelectedPickById(pickId);
  if (pick) delete pick.swapRole;
  if (pairId) {
    const pairPick = findSelectedPickById(pairId);
    if (pairPick) delete pairPick.swapRole;
  }
};

const buildSwapPairsPayload = () => {
  const candidateMap = getSwapCandidateMap();
  const pairs = [];
  const used = new Set();
  let invalid = false;

  pickState.offer.selected.forEach((pick) => {
    if (!pick.swapRole) return;
    const pairId = candidateMap[Number(pick.id)];
    if (!pairId) {
      invalid = true;
      return;
    }
    if (used.has(Number(pick.id)) || used.has(Number(pairId))) return;
    const pairPick = findSelectedPickById(pairId);
    if (!pairPick || !pairPick.swapRole) {
      invalid = true;
      return;
    }
    pairs.push({
      offer_pick_id: Number(pick.id),
      request_pick_id: Number(pairId),
      offer_role: pick.swapRole,
      request_role: pairPick.swapRole
    });
    used.add(Number(pick.id));
    used.add(Number(pairId));
  });

  return { pairs, invalid };
};

const isCurrentDraftPick = (pick) => {
  if (!pick) return false;
  const year = Number(pick.season_year || 0);
  const pickNumber = Number(pick.draft_pick_number || 0);
  return year === currentSeasonYear && pickNumber > 0 && Number(pick.draft_session_id || 0) > 0;
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

      selectedEl.addEventListener('change', (event) => {
        const toggle = event.target.closest('[data-action="toggle-swap"]');
        if (toggle) {
          const pickId = Number(toggle.dataset.pickId);
          if (toggle.checked) {
            setSwapRole(pickId, 'SB');
          } else {
            clearSwapRole(pickId);
          }
          renderSelectedPicks('offer');
          renderSelectedPicks('request');
          return;
        }

        const roleSelect = event.target.closest('[data-action="swap-role"]');
        if (roleSelect) {
          const pickId = Number(roleSelect.dataset.pickId);
          const role = roleSelect.value;
          setSwapRole(pickId, role);
          renderSelectedPicks('offer');
          renderSelectedPicks('request');
        }
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
    if (Number(pick.swap_locked || 0) === 1 && !pick.swap_type) return false;
    // Always include picks from an active draft session (position already assigned)
    if (Number(pick.draft_session_id || 0) > 0) return true;
    const year = Number(pick.season_year || 0);
    if (!Number.isFinite(year) || year <= 0) return false;
    return year >= currentSeasonYear;
  }).sort((a, b) => {
    const aCurrent = isCurrentDraftPick(a);
    const bCurrent = isCurrentDraftPick(b);
    if (aCurrent !== bCurrent) return aCurrent ? -1 : 1;
    if (aCurrent && bCurrent) {
      return Number(a.draft_pick_number || 0) - Number(b.draft_pick_number || 0);
    }
    const yearDiff = Number(a.season_year || 0) - Number(b.season_year || 0);
    if (yearDiff !== 0) return yearDiff;
    const roundDiff = Number(a.round || 0) - Number(b.round || 0);
    if (roundDiff !== 0) return roundDiff;
    return Number(a.id || 0) - Number(b.id || 0);
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
  updateCapImpact();
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
          <div class="pick-meta">${summary.meta ? `${summary.meta} • ` : ''}${summary.origin}${summary.via ? ` • ${summary.via}` : ''}</div>
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

  syncSwapRoles();
  const candidateMap = getSwapCandidateMap();

  const selected = pickState[side].selected;
  if (!selected || selected.length === 0) {
    container.innerHTML = '<div class="pick-empty-state">Nenhuma pick selecionada.</div>';
    return;
  }

  container.innerHTML = selected.map((pick) => {
    const summary = buildPickSummary(pick);
    const pairId = candidateMap[Number(pick.id)];
    const hasPair = Boolean(pairId);
    const swapChecked = Boolean(pick.swapRole);
    const swapControls = hasPair ? `
        <div class="d-flex align-items-center gap-2">
          <div class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" data-action="toggle-swap" data-pick-id="${pick.id}" ${swapChecked ? 'checked' : ''}>
            <label class="form-check-label text-light-gray" style="font-size:12px">Swap</label>
          </div>
          <select class="form-select form-select-sm bg-dark text-white border-secondary" data-action="swap-role" data-pick-id="${pick.id}" ${swapChecked ? '' : 'disabled'}>
            <option value="SB" ${pick.swapRole === 'SB' ? 'selected' : ''}>SB</option>
            <option value="SW" ${pick.swapRole === 'SW' ? 'selected' : ''}>SW</option>
          </select>
        </div>
      ` : '';
    return `
      <div class="selected-pick-card" data-pick-id="${pick.id}">
        <div class="selected-pick-info">
          <div class="pick-title mb-1">${summary.title}</div>
          <div class="pick-meta">${summary.meta ? `${summary.meta} • ` : ''}${summary.origin}${summary.via ? ` • ${summary.via}` : ''}</div>
        </div>
        <div class="selected-pick-actions">
          ${swapControls}
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
    updateCapImpact();
  }
}

function removePlayerFromSelection(side, playerId) {
  const state = playerState[side];
  if (!state) return;
  state.selected = state.selected.filter((p) => Number(p.id) !== Number(playerId));
  renderPlayerOptions(side);
  renderSelectedPlayers(side);
  updateCapImpact();
}

function resetPlayerSelection(side) {
  if (!playerState[side]) return;
  playerState[side].selected = [];
  renderPlayerOptions(side);
  renderSelectedPlayers(side);
  updateCapImpact();
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
    updateCapImpact();
    return;
  }
  playersFromTrade.forEach((player) => {
    addPlayerToSelection(side, Number(player.id), player, false);
  });
  renderPlayerOptions(side);
  renderSelectedPlayers(side);
  updateCapImpact();
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
    renderSelectedPicks('offer');
    renderSelectedPicks('request');
  }
}

function removePickFromSelection(side, pickId) {
  const state = pickState[side];
  if (!state) return;
  state.selected = state.selected.filter((p) => Number(p.id) !== Number(pickId));
  renderPickOptions(side);
  renderSelectedPicks('offer');
  renderSelectedPicks('request');
}

function resetPickSelection(side) {
  if (!pickState[side]) return;
  pickState[side].selected = [];
  renderPickOptions(side);
  renderSelectedPicks('offer');
  renderSelectedPicks('request');
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
  targetTeamPlayers = [];
  updateCapImpact();
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

function populateLeagueTradesTeamFilter() {
  const select = document.getElementById('leagueTradesTeamFilter');
  if (!select) return;

  const myLeagueNormalized = (myLeague ?? '').toString().trim().toUpperCase();
  let leagueTeams = allTeams
    .filter((team) => {
      const teamLeague = (team.league ?? '').toString().trim().toUpperCase();
      if (!myLeagueNormalized) return true;
      return teamLeague === myLeagueNormalized;
    })
    .sort((a, b) => getTeamLabel(a).localeCompare(getTeamLabel(b)));

  if (leagueTeams.length === 0 && allTeams.length > 0) {
    leagueTeams = [...allTeams].sort((a, b) => getTeamLabel(a).localeCompare(getTeamLabel(b)));
  }

  const previousValue = select.value;
  select.innerHTML = '<option value="">Todos os times</option>';

  leagueTeams.forEach((team) => {
    const option = document.createElement('option');
    option.value = String(team.id);
    option.textContent = getTeamLabel(team);
    select.appendChild(option);
  });

  if (previousValue && leagueTeams.some((team) => String(team.id) === previousValue)) {
    select.value = previousValue;
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

  const leagueTradesTeamFilter = document.getElementById('leagueTradesTeamFilter');
  if (leagueTradesTeamFilter) {
    leagueTradesTeamFilter.addEventListener('change', () => {
      filterLeagueTrades(leagueTradesSearch ? leagueTradesSearch.value : '');
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
    populateLeagueTradesTeamFilter();
    
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
    updateCapImpact();
  } catch (err) {
    console.error('Erro ao carregar assets:', err);
  }
}

async function onTargetTeamChange(e) {
  const teamId = e.target.value;
  if (!teamId) {
    targetTeamPlayers = [];
    setAvailablePlayers('request', [], { resetSelected: true });
    setAvailablePicks('request', [], { resetSelected: true });
    updateCapImpact();
    return;
  }
  
  try {
    // Carregar jogadores do time alvo
    const playersData = await api(`players.php?team_id=${teamId}`);
    const players = playersData.players || [];
    
  console.log('Jogadores do time alvo carregados:', players.length);
    
    targetTeamPlayers = players;
    setAvailablePlayers('request', players, { resetSelected: true });
    
    // Carregar picks do time alvo
    const picksData = await api(`picks.php?team_id=${teamId}`);
    const picks = picksData.picks || [];
    
    console.log('Picks do time alvo:', picks.length);
    setAvailablePicks('request', picks, { resetSelected: true });
    updateCapImpact();
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
  const swapPayload = buildSwapPairsPayload();
  
  if (!targetTeam) {
    return alert('Selecione um time.');
  }
  
  if (offerPlayers.length === 0 && offerPickPayload.length === 0) {
    return alert('Você precisa oferecer algo (jogadores ou picks).');
  }
  
  if (requestPlayers.length === 0 && requestPickPayload.length === 0) {
    return alert('Você precisa pedir algo em troca (jogadores ou picks).');
  }

  if (swapPayload.invalid) {
    return alert('Revise o swap: selecione SB/SW para as duas picks do mesmo ano e rodada.');
  }
  
  try {
    const payload = {
      to_team_id: parseInt(targetTeam),
      offer_players: offerPlayers,
      offer_picks: offerPickPayload,
      request_players: requestPlayers,
      request_picks: requestPickPayload,
      notes,
      swap_pairs: swapPayload.pairs
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
  const teamFilter = document.getElementById('leagueTradesTeamFilter');
  const selectedTeamId = Number(teamFilter?.value || 0);
  const term = (searchTerm || '').toLowerCase().trim();

  const filtered = allLeagueTrades.filter((trade) => {
    const matchesTeam = !selectedTeamId || (trade.is_multi
      ? (trade.teams || []).some((team) => Number(team.id) === selectedTeamId)
      : Number(trade.from_team_id) === selectedTeamId || Number(trade.to_team_id) === selectedTeamId);

    if (!matchesTeam) {
      return false;
    }

    if (!term) {
      return true;
    }

    if (trade.is_multi) {
      return (trade.items || []).some((item) => {
        return item.player_name && item.player_name.toLowerCase().includes(term);
      });
    }

    const hasInOffer = (trade.offer_players || []).some((p) =>
      p.name && p.name.toLowerCase().includes(term)
    );

    const hasInRequest = (trade.request_players || []).some((p) =>
      p.name && p.name.toLowerCase().includes(term)
    );

    return hasInOffer || hasInRequest;
  });
  
  // Renderizar resultados
  container.innerHTML = '';
  if (filtered.length === 0) {
    const selectedTeamName = teamFilter?.selectedOptions?.[0]?.textContent || 'time selecionado';
    if (term && selectedTeamId) {
      container.innerHTML = `<div class="text-center text-light-gray py-4">Nenhuma trade encontrada para ${selectedTeamName} com "${searchTerm}"</div>`;
    } else if (selectedTeamId) {
      container.innerHTML = `<div class="text-center text-light-gray py-4">Nenhuma trade encontrada para ${selectedTeamName}</div>`;
    } else if (term) {
      container.innerHTML = `<div class="text-center text-light-gray py-4">Nenhuma trade encontrada com "${searchTerm}"</div>`;
    } else {
      container.innerHTML = '<div class="text-center text-light-gray py-4">Nenhuma trade encontrada</div>';
    }
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
    
    if (type === 'league') {
      const searchInput = document.getElementById('leagueTradesSearch');
      filterLeagueTrades(searchInput ? searchInput.value : '');
      return;
    }

    if (combined.length === 0) {
      container.innerHTML = '<div class="text-center text-light-gray py-4">Nenhuma trade encontrada</div>';
      return;
    }

    container.innerHTML = '';
    combined.forEach(trade => {
      const card = createTradeCard(trade, type);
      container.appendChild(card);
    });
  } catch (err) {
    container.innerHTML = '<div class="text-center text-danger py-4">Erro ao carregar trades</div>';
  }
}

function createMultiTradeCard(trade, type) {
  const card = document.createElement('div');
  card.className = 'tc';

  const statusBadge = {
    'pending':   '<span class="tag gray">Pendente</span>',
    'accepted':  '<span class="tag green">Aceita</span>',
    'rejected':  '<span class="tag red">Rejeitada</span>',
    'cancelled': '<span class="tag gray">Cancelada</span>'
  }[trade.status] || '<span class="tag gray">-</span>';

  const teamMap = {};
  (trade.teams || []).forEach((team) => {
    teamMap[team.id] = getTeamLabel(team);
  });

  const acceptanceBadge = trade.status === 'pending'
    ? `<span class="tag blue">Aceitar ${trade.teams_accepted || 0}/${trade.teams_total || 0}</span>`
    : '';

  // Group items by to_team_id
  const byTeam = {};
  (trade.items || []).forEach((item) => {
    const toId = String(item.to_team_id);
    if (!byTeam[toId]) byTeam[toId] = [];
    byTeam[toId].push(item);
  });
  const itemsHtml = Object.keys(byTeam).length > 0
    ? Object.entries(byTeam).map(([toId, teamItems]) => {
        const toLabel = teamMap[toId] || `Time ${toId}`;
        const rows = teamItems.map((item) => {
          let detail = '';
          if (item.player_id || item.player_name) {
            detail = formatTradePlayerDisplay({ name: item.player_name, position: item.player_position, age: item.player_age, ovr: item.player_ovr });
          } else if (item.pick_id) {
            detail = formatTradePickDisplay(item);
          }
          return `<li class="tc-item mb-1"><i class="bi bi-arrow-right me-1" style="color:var(--red)"></i>${detail || 'Item'}</li>`;
        }).join('');
        return `<div class="mb-3"><div style="font-weight:600;color:var(--red);font-size:13px;margin-bottom:4px">${toLabel} recebe:</div><ul class="list-unstyled ms-2 mb-0">${rows}</ul></div>`;
      }).join('')
    : `<div style="color:var(--text-3);font-size:13px">Nenhum item</div>`;

  const teamsList = (trade.teams || []).map((team) => {
    return `<span class="team-chip"><span class="team-chip-badge">${team.city?.[0] || 'T'}</span>${getTeamLabel(team)}</span>`;
  }).join('');

  card.innerHTML = `
    <div class="tc-header">
      <div>
        <div class="tc-title">Trade múltipla</div>
        <div class="tc-date">${new Date(trade.created_at).toLocaleDateString('pt-BR')}</div>
      </div>
      <div class="d-flex gap-2 align-items-center flex-wrap">
        ${acceptanceBadge}
        ${statusBadge}
      </div>
    </div>
    <div class="mb-3 d-flex flex-wrap gap-2">${teamsList || `<span style="color:var(--text-3);font-size:13px">Times</span>`}</div>
    <div>${itemsHtml}</div>
    ${trade.notes ? `<div class="tc-notes"><i class="bi bi-chat-left-text me-2"></i>${trade.notes}</div>` : ''}
    ${type === 'league' ? `<div class="reaction-bar mt-3">${buildTradeReactionBar(trade, 'multi')}</div>` : ''}
  `;

  if (trade.status === 'pending' && type === 'received') {
    const actions = document.createElement('div');
    actions.className = 'tc-actions';
    actions.innerHTML = `
      <button class="btn-r primary sm" ${trade.my_accepted ? 'disabled' : ''}>
        <i class="bi bi-check-circle"></i>${trade.my_accepted ? 'Aceito' : 'Aceitar'}
      </button>
      <button class="btn-r outline sm">
        <i class="bi bi-x-circle"></i>Rejeitar
      </button>
    `;
    const [acceptBtn, rejectBtn] = actions.querySelectorAll('button');
    acceptBtn.addEventListener('click', () => respondMultiTrade(trade.id, 'accepted'));
    rejectBtn.addEventListener('click', () => respondMultiTrade(trade.id, 'rejected'));
    card.appendChild(actions);
  }

  if (trade.status === 'pending' && type === 'sent') {
    const actions = document.createElement('div');
    actions.className = 'tc-actions';
    const canEdit = (trade.teams_accepted || 0) === 0;
    actions.innerHTML = `
      ${canEdit ? `<button class="btn-r secondary sm" id="editMultiBtn_${trade.id}">
        <i class="bi bi-pencil"></i>Editar
      </button>` : ''}
      <button class="btn-r outline sm">
        <i class="bi bi-x-circle"></i>Cancelar
      </button>
    `;
    actions.querySelector('.btn-r.outline').addEventListener('click', () => respondMultiTrade(trade.id, 'cancelled'));
    if (canEdit) {
      actions.querySelector(`#editMultiBtn_${trade.id}`).addEventListener('click', () => openEditMultiTrade(trade));
    }
    card.appendChild(actions);
  }

  return card;
}

async function openEditMultiTrade(trade) {
  resetMultiTradeForm();

  const modal = document.getElementById('multiTradeModal');
  if (!modal) return;
  modal.dataset.editTradeId = trade.id;
  const modalTitle = modal.querySelector('.modal-title');
  if (modalTitle) modalTitle.innerHTML = '<i class="bi bi-pencil me-2" style="color:var(--red)"></i>Editar Trade Múltipla';

  // Check team boxes for the trade's teams
  const teamsContainer = document.getElementById('multiTradeTeamsList');
  if (teamsContainer) {
    (trade.teams || []).forEach((team) => {
      const cb = teamsContainer.querySelector(`input[value="${team.id}"]`);
      if (cb && Number(cb.value) !== Number(myTeamId)) cb.checked = true;
    });
    renderMultiTeamLimit();
    updateMultiItemTeamOptions();
  }

  const notesEl = document.getElementById('multiTradeNotes');
  if (notesEl) notesEl.value = trade.notes || '';

  // Replace empty row with rows from existing items
  const container = document.getElementById('multiTradeItems');
  if (container) container.innerHTML = '';
  const existingItems = trade.items || [];
  if (existingItems.length === 0) {
    addMultiTradeItemRow();
  } else {
    existingItems.forEach(() => addMultiTradeItemRow());
    // Pre-fill row selects after a short delay (DOM needs to settle)
    setTimeout(async () => {
      const rows = container.querySelectorAll('.multi-trade-item-row');
      for (let i = 0; i < existingItems.length && i < rows.length; i++) {
        const item = existingItems[i];
        const row = rows[i];
        const fromSel = row.querySelector('[data-role="from-team"]');
        const toSel   = row.querySelector('[data-role="to-team"]');
        const typeSel = row.querySelector('[data-role="item-type"]');
        if (fromSel && item.from_team_id) fromSel.value = item.from_team_id;
        if (toSel   && item.to_team_id)   toSel.value   = item.to_team_id;
        if (typeSel) {
          typeSel.value = item.player_id ? 'player' : 'pick';
          typeSel.dispatchEvent(new Event('change'));
          try { await updateMultiItemOptions(row, false); } catch (e) {}
          const itemSel = row.querySelector('[data-role="item-id"]');
          if (itemSel) itemSel.value = item.player_id || item.pick_id || '';
        }
      }
    }, 250);
  }

  bootstrap.Modal.getOrCreateInstance(modal).show();
}

function createTradeCard(trade, type) {
  if (trade.is_multi) {
    return createMultiTradeCard(trade, type);
  }
  const card = document.createElement('div');
  card.className = 'tc';

  const statusBadge = {
    'pending':   '<span class="tag gray">Pendente</span>',
    'accepted':  '<span class="tag green">Aceita</span>',
    'rejected':  '<span class="tag red">Rejeitada</span>',
    'cancelled': '<span class="tag gray">Cancelada</span>',
    'countered': '<span class="tag blue">Contraproposta</span>'
  }[trade.status] || '';

  const fromTeam = `${trade.from_city} ${trade.from_name}`;
  const toTeam   = `${trade.to_city} ${trade.to_name}`;

  const responseNotes = trade.response_notes ? `
    <div class="tc-response-notes">
      <div style="font-size:11px;font-weight:600;color:var(--amber);margin-bottom:4px"><i class="bi bi-chat-dots me-1"></i>Resposta:</div>
      <div style="font-size:13px;color:var(--text-2)">${trade.response_notes}</div>
    </div>
  ` : '';

  card.innerHTML = `
    <div class="tc-header">
      <div>
        <div class="tc-title">${fromTeam} <i class="bi bi-arrow-right" style="color:var(--red)"></i> ${toTeam}</div>
        <div class="tc-date">${new Date(trade.created_at).toLocaleDateString('pt-BR')}</div>
      </div>
      <div>${statusBadge}</div>
    </div>

    <div class="row g-3">
      <div class="col-md-6">
        <div class="tc-side-title">${fromTeam} oferece</div>
        <ul class="list-unstyled mb-0">
          ${trade.offer_players.map(p => `<li class="tc-item"><i class="bi bi-person-fill" style="color:var(--red)"></i>${formatTradePlayerDisplay(p)}</li>`).join('')}
          ${trade.offer_picks.map(p => `<li class="tc-item"><i class="bi bi-trophy-fill" style="color:var(--red)"></i>${formatTradePickDisplay(p)}</li>`).join('')}
          ${(trade.offer_players.length === 0 && trade.offer_picks.length === 0) ? '<li style="color:var(--text-3);font-size:13px">Nenhum item</li>' : ''}
        </ul>
      </div>
      <div class="col-md-6">
        <div class="tc-side-title">${toTeam} envia</div>
        <ul class="list-unstyled mb-0">
          ${trade.request_players.map(p => `<li class="tc-item"><i class="bi bi-person-fill" style="color:var(--red)"></i>${formatTradePlayerDisplay(p)}</li>`).join('')}
          ${trade.request_picks.map(p => `<li class="tc-item"><i class="bi bi-trophy-fill" style="color:var(--red)"></i>${formatTradePickDisplay(p)}</li>`).join('')}
          ${(trade.request_players.length === 0 && trade.request_picks.length === 0) ? '<li style="color:var(--text-3);font-size:13px">Nenhum item</li>' : ''}
        </ul>
      </div>
    </div>

    ${trade.notes ? `<div class="tc-notes"><i class="bi bi-chat-left-text me-2"></i>${trade.notes}</div>` : ''}
    ${responseNotes}
    ${type === 'league' ? `<div class="reaction-bar mt-3">${buildTradeReactionBar(trade, 'single')}</div>` : ''}

    ${trade.status === 'pending' && type === 'received' ? `
      <div style="margin-top:14px">
        <div style="margin-bottom:8px">
          <label class="form-label">Observação (opcional):</label>
          <textarea class="form-control form-control-sm"
                    id="responseNotes_${trade.id}" rows="2"
                    placeholder="Adicione uma mensagem..."></textarea>
        </div>
        <div class="tc-actions" style="margin-top:8px">
          <button class="btn-r primary sm" onclick="respondTrade(${trade.id}, 'accepted')">
            <i class="bi bi-check-circle"></i>Aceitar
          </button>
          <button class="btn-r outline sm" onclick="respondTrade(${trade.id}, 'rejected')">
            <i class="bi bi-x-circle"></i>Rejeitar
          </button>
          <button class="btn-r secondary sm" onclick="openCounterProposal(${trade.id}, ${JSON.stringify(trade).replace(/"/g, '&quot;')})">
            <i class="bi bi-arrow-repeat"></i>Contraproposta
          </button>
        </div>
      </div>
    ` : ''}

    ${trade.status === 'pending' && type === 'sent' ? `
      <div class="tc-actions">
        <button class="btn-r secondary sm" onclick="openModifyTrade(${trade.id}, ${JSON.stringify(trade).replace(/"/g, '&quot;')})">
          <i class="bi bi-pencil"></i>Modificar
        </button>
        <button class="btn-r outline sm" onclick="respondTrade(${trade.id}, 'cancelled')">
          <i class="bi bi-x-circle"></i>Cancelar
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
