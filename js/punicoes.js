// Usa o helper api do admin.js se disponível, senão define o próprio
const _pApi = (typeof api === 'function') ? api : async (path, opts = {}) => {
  const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...opts });
  let body = {};
  try { body = await res.json(); } catch {}
  if (!res.ok || body.success === false) throw body;
  return body;
};

const _notify = (type, msg) =>
  typeof showAlert === 'function' ? showAlert(type, msg) : alert(msg);

let _punCatalog = [];
let _motiveCatalog = [];
const _BAN_TYPES = new Set(['BAN_TRADES', 'BAN_TRADES_PICKS', 'BAN_FREE_AGENCY', 'ROTACAO_AUTOMATICA']);

const _el = id => document.getElementById(id);

function _renderTypeOptions() {
  const sel = _el('punicaoType');
  if (!sel) return;
  sel.innerHTML = '<option value="">Selecione...</option>' + _punCatalog.map(t =>
    `<option value="${t.label}" data-effect-type="${t.effect_type}" data-requires-pick="${t.requires_pick}" data-requires-scope="${t.requires_scope}">${t.label}</option>`
  ).join('');
}

function _renderMotiveOptions() {
  const sel = _el('punicaoMotive');
  if (!sel) return;
  sel.innerHTML = '<option value="">Selecione...</option>' + _motiveCatalog.map(m =>
    `<option value="${m.label}">${m.label}</option>`
  ).join('');
}

function _updateFormVisibility() {
  const option = _el('punicaoType')?.selectedOptions?.[0];
  const effectType = option?.dataset?.effectType || '';
  const requiresPick = option?.dataset?.requiresPick === '1';
  const requiresScope = option?.dataset?.requiresScope === '1';
  const pickRow = _el('punicaoPickRow');
  const scopeRow = _el('punicaoScopeRow');
  if (pickRow) pickRow.style.display = requiresPick || effectType === 'PERDA_PICK_ESPECIFICA' ? '' : 'none';
  if (scopeRow) scopeRow.style.display = requiresScope || _BAN_TYPES.has(effectType) ? '' : 'none';
}

async function _loadCatalog() {
  const data = await _pApi('punicoes.php?action=catalog');
  _motiveCatalog = data.motives || [];
  _punCatalog = data.types || [];
  _renderMotiveOptions();
  _renderTypeOptions();
  _updateFormVisibility();
}

async function _loadLeagues(preselected) {
  const data = await _pApi('punicoes.php?action=leagues');
  const leagues = data.leagues || [];
  const opts = leagues.map(l => `<option value="${l}"${l === preselected ? ' selected' : ''}>${l}</option>`).join('');
  const form = _el('punicaoLeague');
  const hist = _el('punicaoHistoryLeague');
  if (form) form.innerHTML = '<option value="">Selecione a liga...</option>' + opts;
  if (hist) hist.innerHTML = '<option value="">Todas as ligas</option>' + opts;
}

async function _loadTeams(league, targetId, emptyLabel = 'Selecione o time...') {
  const sel = _el(targetId);
  if (!sel) return;
  if (!league) { sel.innerHTML = `<option value="">${emptyLabel}</option>`; return; }
  const data = await _pApi(`punicoes.php?action=teams&league=${encodeURIComponent(league)}`);
  sel.innerHTML = `<option value="">${emptyLabel}</option>` + (data.teams || []).map(t =>
    `<option value="${t.id}">${t.city} ${t.name}</option>`
  ).join('');
}

async function _loadPicks(teamId) {
  const sel = _el('punicaoPick');
  if (!sel) return;
  if (!teamId) { sel.innerHTML = '<option value="">Selecione a pick...</option>'; return; }
  const data = await _pApi(`punicoes.php?action=picks&team_id=${teamId}`);
  sel.innerHTML = '<option value="">Selecione a pick...</option>' + (data.picks || []).map(p =>
    `<option value="${p.id}">${p.season_year} R${p.round}</option>`
  ).join('');
}

function _getTypeLabel(type) {
  const m = _punCatalog.find(i => i.effect_type === type || i.label === type);
  return m ? m.label : type;
}

window.loadPunishments = async function({ teamId = '', league = '' } = {}) {
  const container = _el('punicoesList');
  if (!container) return;
  if (!teamId && !league) {
    container.innerHTML = '<p class="empty-state">Selecione uma liga ou time para ver as punições.</p>';
    return;
  }
  container.innerHTML = '<p class="empty-state">Carregando...</p>';
  const params = new URLSearchParams({ action: 'punishments' });
  if (teamId) params.append('team_id', teamId);
  if (league) params.append('league', league);
  try {
    const data = await _pApi(`punicoes.php?${params}`);
    const rows = data.punishments || [];
    if (!rows.length) {
      container.innerHTML = '<p class="empty-state">Nenhuma punição registrada.</p>';
      return;
    }
    container.innerHTML = rows.map(p => {
      const pickInfo = p.pick_id ? ` · Pick ${p.season_year || ''} R${p.round || ''}` : '';
      const teamName = `${p.city || ''} ${p.name || ''}`.trim();
      const league = p.league || p.team_league || '-';
      const reverted = !!p.reverted_at;
      const punLabel = p.punishment_label || _getTypeLabel(p.type);
      return `
        <div class="pun-card${reverted ? ' pun-card-reverted' : ''}">
          <div class="pun-card-head">
            <div>
              <div class="pun-card-title">${punLabel}</div>
              <div class="pun-card-sub">${p.motive || '-'}${pickInfo}</div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
              <span class="pun-badge ${reverted ? 'pun-badge-off' : 'pun-badge-on'}">${reverted ? 'Revertida' : 'Ativa'}</span>
              ${reverted ? '' : `<button class="btn-ghost" style="padding:4px 10px;font-size:11px" onclick="revertPunishment(${p.id})">Reverter</button>`}
            </div>
          </div>
          <div class="pun-card-meta">${teamName} · ${league} · ${p.created_at}</div>
        </div>`;
    }).join('');
  } catch (e) {
    container.innerHTML = `<p class="empty-state" style="color:var(--red)">${e.error || 'Erro ao carregar.'}</p>`;
  }
};

window.revertPunishment = async function(id) {
  if (!confirm('Reverter esta punição?')) return;
  try {
    await _pApi('punicoes.php', { method: 'POST', body: JSON.stringify({ action: 'revert', punishment_id: id }) });
    await window.loadPunishments({
      teamId: _el('punicaoHistoryTeam')?.value || '',
      league: _el('punicaoHistoryLeague')?.value || ''
    });
    _notify('success', 'Punição revertida.');
  } catch (e) { alert(e.error || 'Erro ao reverter.'); }
};

window.initPunicoes = async function(preselectedLeague) {
  let _curLeague = preselectedLeague || '';
  let _curTeamId = '';

  _el('punicaoLeague')?.addEventListener('change', async e => {
    _curLeague = e.target.value;
    _curTeamId = '';
    await _loadTeams(_curLeague, 'punicaoTeam');
  });

  _el('punicaoTeam')?.addEventListener('change', async e => {
    _curTeamId = e.target.value;
    await _loadPicks(_curTeamId);
    const ht = _el('punicaoHistoryTeam');
    if (ht) ht.value = _curTeamId;
    await window.loadPunishments({ teamId: _curTeamId, league: _el('punicaoHistoryLeague')?.value || _curLeague });
  });

  _el('punicaoHistoryLeague')?.addEventListener('change', async e => {
    await _loadTeams(e.target.value, 'punicaoHistoryTeam', 'Todos os times');
    await window.loadPunishments({ teamId: _el('punicaoHistoryTeam')?.value || '', league: e.target.value });
  });

  _el('punicaoHistoryTeam')?.addEventListener('change', async e => {
    await window.loadPunishments({ teamId: e.target.value, league: _el('punicaoHistoryLeague')?.value || '' });
  });

  _el('punicaoType')?.addEventListener('change', _updateFormVisibility);

  _el('punicaoSubmit')?.addEventListener('click', async () => {
    if (!_curTeamId) { alert('Selecione um time.'); return; }
    const typeEl = _el('punicaoType');
    const type = typeEl?.value;
    if (!type) { alert('Selecione a punição.'); return; }
    const payload = {
      action: 'add',
      team_id: Number(_curTeamId),
      type,
      motive: _el('punicaoMotive')?.value || '',
      punishment_label: type,
      effect_type: typeEl?.selectedOptions?.[0]?.dataset?.effectType || type,
      notes: _el('punicaoNotes')?.value?.trim() || '',
      season_scope: _el('punicaoScope')?.value || 'current',
      created_at: _el('punicaoDate')?.value || ''
    };
    if (type === 'PERDA_PICK_ESPECIFICA') {
      const pickId = Number(_el('punicaoPick')?.value || 0);
      if (!pickId) { alert('Selecione a pick a remover.'); return; }
      payload.pick_id = pickId;
    }
    try {
      await _pApi('punicoes.php', { method: 'POST', body: JSON.stringify(payload) });
      const notes = _el('punicaoNotes');
      if (notes) notes.value = '';
      await _loadPicks(_curTeamId);
      await window.loadPunishments({ teamId: _el('punicaoHistoryTeam')?.value || _curTeamId, league: _el('punicaoHistoryLeague')?.value || '' });
      _notify('success', 'Punição registrada!');
    } catch (e) { alert(e.error || 'Erro ao registrar.'); }
  });

  _el('newMotiveBtn')?.addEventListener('click', async () => {
    const input = _el('newMotiveLabel');
    const label = input?.value.trim();
    if (!label) { alert('Informe o motivo.'); return; }
    try {
      await _pApi('punicoes.php', { method: 'POST', body: JSON.stringify({ action: 'add_motive', label }) });
      if (input) input.value = '';
      await _loadCatalog();
      _notify('success', 'Motivo cadastrado!');
    } catch (e) { alert(e.error || 'Erro.'); }
  });

  _el('newPunishmentBtn')?.addEventListener('click', async () => {
    const input = _el('newPunishmentLabel');
    const label = input?.value.trim();
    if (!label) { alert('Informe a consequência.'); return; }
    const map = {
      'aviso formal': 'AVISO_FORMAL',
      'perda da pick 1º rodada': 'PERDA_PICK_1R', 'perda da pick 1a rodada': 'PERDA_PICK_1R',
      'perda de pick específica': 'PERDA_PICK_ESPECIFICA', 'perda de pick especifica': 'PERDA_PICK_ESPECIFICA',
      'trades bloqueadas por uma temporada': 'BAN_TRADES', 'trades sem picks': 'BAN_TRADES_PICKS',
      'sem poder usar fa na temporada': 'BAN_FREE_AGENCY',
      'rotacao automatica': 'ROTACAO_AUTOMATICA', 'rotação automatica': 'ROTACAO_AUTOMATICA', 'rotação automática': 'ROTACAO_AUTOMATICA'
    };
    const effectType = map[label.toLowerCase()] || 'AVISO_FORMAL';
    try {
      await _pApi('punicoes.php', { method: 'POST', body: JSON.stringify({
        action: 'add_type', label, effect_type: effectType,
        requires_pick: effectType === 'PERDA_PICK_ESPECIFICA',
        requires_scope: ['BAN_TRADES', 'BAN_TRADES_PICKS', 'BAN_FREE_AGENCY', 'ROTACAO_AUTOMATICA'].includes(effectType)
      })});
      if (input) input.value = '';
      await _loadCatalog();
      _notify('success', 'Consequência cadastrada!');
    } catch (e) { alert(e.error || 'Erro.'); }
  });

  await Promise.all([_loadCatalog(), _loadLeagues(preselectedLeague)]);
  if (preselectedLeague) {
    await _loadTeams(preselectedLeague, 'punicaoTeam');
    const hl = _el('punicaoHistoryLeague');
    if (hl) {
      hl.value = preselectedLeague;
      await _loadTeams(preselectedLeague, 'punicaoHistoryTeam', 'Todos os times');
      await window.loadPunishments({ league: preselectedLeague });
    }
  }
};

// Auto-init quando carregado diretamente na punicoes.php
(function () {
  function _tryAutoInit() {
    if (_el('punicaoLeague')) window.initPunicoes();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _tryAutoInit);
  } else {
    _tryAutoInit();
  }
})();
