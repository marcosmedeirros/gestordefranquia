const api = async (path, options = {}) => {
  const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
  let body = {};
  try { body = await res.json(); } catch {}
  if (!res.ok || body.success === false) throw body;
  return body;
};

const PUNISHMENT_TYPES = [
  { value: 'AVISO_FORMAL', label: 'Aviso formal' },
  { value: 'PERDA_PICK_1R', label: 'Perda de pick 1ª rodada' },
  { value: 'PERDA_PICK_ESPECIFICA', label: 'Perda de pick específica' },
  { value: 'BAN_TRADES', label: 'Banir trades (temporada)' },
  { value: 'BAN_TRADES_PICKS', label: 'Banir uso de picks em trades' },
  { value: 'BAN_FREE_AGENCY', label: 'Banir Free Agency' },
  { value: 'ROTACAO_AUTOMATICA', label: 'Rotação automática' },
  { value: 'TETO_MINUTOS', label: 'Teto de minutos' },
  { value: 'REDISTRIBUICAO_MINUTOS', label: 'Redistribuição de minutos' },
  { value: 'ANULACAO_TRADE', label: 'Anulação de trade' },
  { value: 'ANULACAO_FA', label: 'Anulação de FA' },
  { value: 'DROP_OBRIGATORIO', label: 'Drop obrigatório' },
  { value: 'CORRECAO_ROSTER', label: 'Correção de roster' },
  { value: 'INATIVIDADE_REGISTRADA', label: 'Inatividade registrada' },
  { value: 'EXCLUSAO_LIGA', label: 'Exclusão da liga' }
];

const BAN_TYPES = new Set(['BAN_TRADES', 'BAN_TRADES_PICKS', 'BAN_FREE_AGENCY']);

let currentLeague = '';
let currentTeamId = '';
let currentPicks = [];

const leagueSelect = document.getElementById('punicaoLeague');
const teamSelect = document.getElementById('punicaoTeam');
const typeSelect = document.getElementById('punicaoType');
const notesInput = document.getElementById('punicaoNotes');
const pickSelect = document.getElementById('punicaoPick');
const scopeSelect = document.getElementById('punicaoScope');
const createdAtInput = document.getElementById('punicaoDate');
const listContainer = document.getElementById('punicoesList');
const submitBtn = document.getElementById('punicaoSubmit');

const pickRow = document.getElementById('punicaoPickRow');
const scopeRow = document.getElementById('punicaoScopeRow');

function renderTypeOptions() {
  if (!typeSelect) return;
  typeSelect.innerHTML = '<option value="">Selecione...</option>' + PUNISHMENT_TYPES.map(type => (
    `<option value="${type.value}">${type.label}</option>`
  )).join('');
}

function updateFormVisibility() {
  const type = typeSelect?.value || '';
  if (pickRow) {
    pickRow.style.display = type === 'PERDA_PICK_ESPECIFICA' ? 'block' : 'none';
  }
  if (scopeRow) {
    scopeRow.style.display = BAN_TYPES.has(type) ? 'block' : 'none';
  }
}

async function loadLeagues() {
  const data = await api('punicoes.php?action=leagues');
  leagueSelect.innerHTML = '<option value="">Selecione a liga...</option>' + (data.leagues || []).map(l => (
    `<option value="${l}">${l}</option>`
  )).join('');
}

async function loadTeams(league) {
  const data = await api(`punicoes.php?action=teams&league=${league}`);
  teamSelect.innerHTML = '<option value="">Selecione o time...</option>' + (data.teams || []).map(t => (
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

async function loadPunishments(teamId) {
  if (!teamId) {
    listContainer.innerHTML = '<div class="text-light-gray">Selecione um time para ver as punições.</div>';
    return;
  }
  const data = await api(`punicoes.php?action=punishments&team_id=${teamId}`);
  const rows = data.punishments || [];
  if (!rows.length) {
    listContainer.innerHTML = '<div class="text-light-gray">Nenhuma punição registrada.</div>';
    return;
  }
  listContainer.innerHTML = rows.map(p => {
    const pickInfo = p.pick_id ? `Pick ${p.season_year || ''} R${p.round || ''}`.trim() : '-';
    return `
      <div class="punicao-card">
        <div class="d-flex justify-content-between flex-wrap gap-2">
          <div>
            <strong>${p.type}</strong>
            <div class="text-light-gray small">${p.created_at}</div>
          </div>
          <span class="badge bg-secondary">${p.season_scope || 'current'}</span>
        </div>
        <div class="mt-2 text-light-gray">${p.notes || 'Sem observações'}</div>
        <div class="mt-2"><small class="text-light-gray">Pick:</small> ${pickInfo}</div>
      </div>
    `;
  }).join('');
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
    type,
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
  await loadPunishments(currentTeamId);
  alert('Punição registrada!');
}

leagueSelect.addEventListener('change', async (e) => {
  currentLeague = e.target.value;
  currentTeamId = '';
  await loadTeams(currentLeague);
  listContainer.innerHTML = '<div class="text-light-gray">Selecione um time para ver as punições.</div>';
});

teamSelect.addEventListener('change', async (e) => {
  currentTeamId = e.target.value;
  await loadPicks(currentTeamId);
  await loadPunishments(currentTeamId);
});

typeSelect.addEventListener('change', updateFormVisibility);
submitBtn.addEventListener('click', submitPunishment);

renderTypeOptions();
updateFormVisibility();
loadLeagues();
