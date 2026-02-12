const api = async (path, options = {}) => {
  const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
  let body = {};
  try { body = await res.json(); } catch {}
  if (!res.ok || body.success === false) throw body;
  return body;
};

let punishmentCatalog = [];
let motiveCatalog = [];
const BAN_TYPES = new Set(['BAN_TRADES', 'BAN_TRADES_PICKS', 'BAN_FREE_AGENCY', 'ROTACAO_AUTOMATICA']);

let currentLeague = '';
let currentTeamId = '';
let currentPicks = [];

const leagueSelect = document.getElementById('punicaoLeague');
const teamSelect = document.getElementById('punicaoTeam');
const typeSelect = document.getElementById('punicaoType');
const motiveSelect = document.getElementById('punicaoMotive');
const notesInput = document.getElementById('punicaoNotes');
const pickSelect = document.getElementById('punicaoPick');
const scopeSelect = document.getElementById('punicaoScope');
const createdAtInput = document.getElementById('punicaoDate');
const listContainer = document.getElementById('punicoesList');
const submitBtn = document.getElementById('punicaoSubmit');
const historyLeagueSelect = document.getElementById('punicaoHistoryLeague');
const historyTeamSelect = document.getElementById('punicaoHistoryTeam');

const newMotiveInput = document.getElementById('newMotiveLabel');
const newMotiveBtn = document.getElementById('newMotiveBtn');
const newPunishmentInput = document.getElementById('newPunishmentLabel');
const newPunishmentEffect = document.getElementById('newPunishmentEffect');
const newPunishmentPick = document.getElementById('newPunishmentPick');
const newPunishmentScope = document.getElementById('newPunishmentScope');
const newPunishmentBtn = document.getElementById('newPunishmentBtn');

const pickRow = document.getElementById('punicaoPickRow');
const scopeRow = document.getElementById('punicaoScopeRow');

function renderTypeOptions() {
  if (!typeSelect) return;
  typeSelect.innerHTML = '<option value="">Selecione...</option>' + punishmentCatalog.map(type => (
    `<option value="${type.label}" data-effect-type="${type.effect_type}" data-requires-pick="${type.requires_pick}" data-requires-scope="${type.requires_scope}">${type.label}</option>`
  )).join('');
}

function renderMotiveOptions() {
  if (!motiveSelect) return;
  motiveSelect.innerHTML = '<option value="">Selecione...</option>' + motiveCatalog.map(motive => (
    `<option value="${motive.label}">${motive.label}</option>`
  )).join('');
}

function updateFormVisibility() {
  const option = typeSelect?.selectedOptions?.[0];
  const effectType = option?.dataset?.effectType || '';
  const requiresPick = option?.dataset?.requiresPick === '1';
  const requiresScope = option?.dataset?.requiresScope === '1';
  if (pickRow) {
    pickRow.style.display = requiresPick || effectType === 'PERDA_PICK_ESPECIFICA' ? 'block' : 'none';
  }
  if (scopeRow) {
    scopeRow.style.display = requiresScope || BAN_TYPES.has(effectType) ? 'block' : 'none';
  }
}

async function loadCatalog() {
  const data = await api('punicoes.php?action=catalog');
  motiveCatalog = data.motives || [];
  punishmentCatalog = data.types || [];
  renderMotiveOptions();
  renderTypeOptions();
  updateFormVisibility();
}

async function loadLeagues() {
  const data = await api('punicoes.php?action=leagues');
  const leagueOptions = (data.leagues || []).map(l => (
    `<option value="${l}">${l}</option>`
  )).join('');
  leagueSelect.innerHTML = '<option value="">Selecione a liga...</option>' + leagueOptions;
  if (historyLeagueSelect) {
    historyLeagueSelect.innerHTML = '<option value="">Todas as ligas</option>' + leagueOptions;
  }
}

async function loadTeams(league, targetSelect = teamSelect, emptyLabel = 'Selecione o time...') {
  if (!targetSelect) return;
  if (!league) {
    targetSelect.innerHTML = `<option value="">${emptyLabel}</option>`;
    return;
  }
  const data = await api(`punicoes.php?action=teams&league=${league}`);
  targetSelect.innerHTML = `<option value="">${emptyLabel}</option>` + (data.teams || []).map(t => (
    `<option value="${t.id}">${t.city} ${t.name}</option>`
  )).join('');
}

async function loadPicks(teamId) {
  currentPicks = [];
  pickSelect.innerHTML = '<option value="">Selecione a pick...</option>';
  if (!teamId) return;
  const data = await api(`punicoes.php?action=picks&team_id=${teamId}`);
  currentPicks = data.picks || [];
  pickSelect.innerHTML = '<option value="">Selecione a pick...</option>' + currentPicks.map(p => (
    `<option value="${p.id}">${p.season_year} R${p.round}</option>`
  )).join('');
}

function getTypeLabel(type) {
  const match = punishmentCatalog.find(item => item.effect_type === type || item.label === type);
  return match ? match.label : type;
}

async function loadPunishments({ teamId = '', league = '' } = {}) {
  if (!teamId && !league) {
    listContainer.innerHTML = '<div class="text-light-gray">Selecione uma liga ou time para ver as punições.</div>';
    return;
  }
  const params = new URLSearchParams({ action: 'punishments' });
  if (teamId) params.append('team_id', teamId);
  if (league) params.append('league', league);
  const data = await api(`punicoes.php?${params.toString()}`);
  const rows = data.punishments || [];
  if (!rows.length) {
    listContainer.innerHTML = '<div class="text-light-gray">Nenhuma punição registrada.</div>';
    return;
  }
  listContainer.innerHTML = `
    <div class="table-responsive">
      <table class="table table-dark table-hover align-middle">
        <thead>
          <tr>
            <th>Motivo</th>
            <th>Punição</th>
            <th>Time</th>
            <th>Data</th>
            <th>Status</th>
            <th class="text-end">Ações</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map(p => {
            const pickInfo = p.pick_id ? `Pick ${p.season_year || ''} R${p.round || ''}`.trim() : '';
            const teamName = `${p.city || ''} ${p.name || ''}`.trim();
            const leagueLabel = p.league || p.team_league || '-';
            const statusLabel = p.reverted_at ? 'Revertida' : 'Ativa';
            const motiveLabel = p.motive || '-';
            const punishmentLabel = p.punishment_label || getTypeLabel(p.type);
            return `
              <tr>
                <td>${motiveLabel}</td>
                <td>
                  <div>${punishmentLabel}</div>
                  ${pickInfo ? `<small class="text-light-gray">${pickInfo}</small>` : ''}
                </td>
                <td>${teamName}<div class="text-light-gray small">${leagueLabel}</div></td>
                <td class="text-light-gray small">${p.created_at}</td>
                <td><span class="badge ${p.reverted_at ? 'bg-secondary' : 'bg-success'}">${statusLabel}</span></td>
                <td class="text-end">
                  ${p.reverted_at ? '' : `<button class="btn btn-sm btn-outline-warning" onclick="revertPunishment(${p.id})">Reverter</button>`}
                </td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>
    </div>
  `;
}

async function submitPunishment() {
  if (!currentTeamId) {
    alert('Selecione um time.');
    return;
  }
  const type = typeSelect.value;
  if (!type) {
    alert('Selecione a punição.');
    return;
  }
  const payload = {
    action: 'add',
    team_id: Number(currentTeamId),
    type: type,
    motive: motiveSelect?.value || '',
    punishment_label: typeSelect?.value || '',
    effect_type: typeSelect?.selectedOptions?.[0]?.dataset?.effectType || type,
    notes: notesInput.value.trim(),
    season_scope: scopeSelect.value || 'current',
    created_at: createdAtInput.value || ''
  };
  if (type === 'PERDA_PICK_ESPECIFICA') {
    const pickId = Number(pickSelect.value || 0);
    if (!pickId) {
      alert('Selecione a pick a remover.');
      return;
    }
    payload.pick_id = pickId;
  }

  await api('punicoes.php', { method: 'POST', body: JSON.stringify(payload) });
  notesInput.value = '';
  pickSelect.value = '';
  await loadPicks(currentTeamId);
  await loadPunishments({
    teamId: historyTeamSelect?.value || currentTeamId,
    league: historyLeagueSelect?.value || ''
  });
  alert('Punição registrada!');
}

leagueSelect.addEventListener('change', async (e) => {
  currentLeague = e.target.value;
  currentTeamId = '';
  await loadTeams(currentLeague);
  listContainer.innerHTML = '<div class="text-light-gray">Selecione uma liga ou time para ver as punições.</div>';
});

teamSelect.addEventListener('change', async (e) => {
  currentTeamId = e.target.value;
  await loadPicks(currentTeamId);
  if (historyTeamSelect) {
    historyTeamSelect.value = currentTeamId;
  }
  await loadPunishments({
    teamId: currentTeamId,
    league: historyLeagueSelect?.value || currentLeague
  });
});

if (historyLeagueSelect) {
  historyLeagueSelect.addEventListener('change', async (e) => {
    const league = e.target.value;
    await loadTeams(league, historyTeamSelect, 'Todos os times');
    await loadPunishments({
      teamId: historyTeamSelect?.value || '',
      league
    });
  });
}

if (historyTeamSelect) {
  historyTeamSelect.addEventListener('change', async (e) => {
    await loadPunishments({
      teamId: e.target.value,
      league: historyLeagueSelect?.value || ''
    });
  });
}

typeSelect.addEventListener('change', updateFormVisibility);
submitBtn.addEventListener('click', submitPunishment);

async function createMotive() {
  const label = newMotiveInput?.value.trim();
  if (!label) {
    alert('Informe o motivo.');
    return;
  }
  await api('punicoes.php', { method: 'POST', body: JSON.stringify({ action: 'add_motive', label }) });
  if (newMotiveInput) newMotiveInput.value = '';
  await loadCatalog();
}

async function createPunishment() {
  const label = newPunishmentInput?.value.trim();
  const effectType = newPunishmentEffect?.value || '';
  if (!label || !effectType) {
    alert('Informe a consequência e o tipo.');
    return;
  }
  await api('punicoes.php', {
    method: 'POST',
    body: JSON.stringify({
      action: 'add_type',
      label,
      effect_type: effectType,
      requires_pick: newPunishmentPick?.checked || false,
      requires_scope: newPunishmentScope?.checked || false
    })
  });
  if (newPunishmentInput) newPunishmentInput.value = '';
  if (newPunishmentPick) newPunishmentPick.checked = false;
  if (newPunishmentScope) newPunishmentScope.checked = false;
  await loadCatalog();
}

async function revertPunishment(punishmentId) {
  await api('punicoes.php', { method: 'POST', body: JSON.stringify({ action: 'revert', punishment_id: punishmentId }) });
  await loadPunishments({
    teamId: historyTeamSelect?.value || currentTeamId,
    league: historyLeagueSelect?.value || currentLeague
  });
}

newMotiveBtn?.addEventListener('click', createMotive);
newPunishmentBtn?.addEventListener('click', createPunishment);

loadCatalog();
loadLeagues();
if (historyTeamSelect) {
  historyTeamSelect.innerHTML = '<option value="">Todos os times</option>';
}
