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

// Reusa o escapeHtml global de admin.js quando disponível (mesma página no admin.php);
// caso contrário (ex.: punicoes.php standalone), usa uma implementação própria.
// Nome com prefixo (_escapeHtml) para não colidir com a declaração `function escapeHtml` de admin.js
// quando os dois scripts são carregados juntos (redeclaração global geraria SyntaxError).
const _escapeHtml = (typeof escapeHtml === 'function')
  ? escapeHtml
  : (s => (s || '').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'));

let _punCatalog = [];
let _motiveCatalog = [];
const _BAN_TYPES = new Set(['BAN_TRADES', 'BAN_TRADES_PICKS', 'BAN_FREE_AGENCY', 'ROTACAO_AUTOMATICA']);

const _el = id => document.getElementById(id);

/* ─── O QUADRO DO EDITAL ────────────────────────────────────────────────
   O admin escolhia "motivo" (texto livre) e "consequência" (lista solta),
   sem nada ligando os dois — e sem saber que efeito aquilo teria. O edital
   já liga: cada infração tem a sua escala por ocorrência. Aqui ele escolhe
   a infração, e o sistema diz em que degrau o time está e o que vai
   acontecer, antes de confirmar. */
let _quadro = [];
let _efeitosInfo = {};
let _duracoes = {};
let _momento = { temporada: 0, ciclo: 0 };
let _previaAtual = null;

async function _carregarQuadro(league) {
  _quadro = []; _previaAtual = null;
  const sel = _el('punicaoInfracao');
  if (!league) {
    if (sel) sel.innerHTML = '<option value="">Escolha a liga primeiro</option>';
    return;
  }
  try {
    const d = await _pApi(`punicoes.php?action=quadro&league=${encodeURIComponent(league)}`);
    _quadro = d.quadro || [];
    _efeitosInfo = d.efeitos || {};
    _duracoes = d.duracoes || {};
    _momento = d.momento || _momento;
  } catch (e) { _quadro = []; }

  if (sel) {
    sel.innerHTML = '<option value="">Selecione a infração...</option>' + _quadro.map(i =>
      `<option value="${i.id}">${_escapeHtml(i.codigo)} — ${_escapeHtml(i.titulo)}${i.artigo ? ' · ' + _escapeHtml(i.artigo) : ''}</option>`
    ).join('');
  }
  _renderDuracoes();
}

function _renderDuracoes() {
  const sel = _el('punicaoDuracao');
  if (!sel || !Object.keys(_duracoes).length) return;
  sel.innerHTML = Object.entries(_duracoes).map(([k, v]) =>
    `<option value="${k}">${_escapeHtml(v)}</option>`).join('');
}

/**
 * A ESCADA DA INFRAÇÃO: o que ela custa na 1ª, na 2ª, na 3ª.
 *
 * Sai do quadro que já veio do servidor (`action=quadro` manda os degraus com
 * o texto pronto), então não custa requisição nenhuma e aparece no instante
 * em que a infração é escolhida — antes disso o admin escolhia no escuro e só
 * via a pena depois de também escolher o time.
 *
 * Com o time escolhido, o degrau dele fica marcado; sem time, a escada
 * aparece inteira, que é a pergunta "quanto custa essa infração".
 */
function _escadaHtml(infracaoId, ocorrenciaAtual) {
  const inf = _quadro.find(i => Number(i.id) === Number(infracaoId));
  const degraus = inf?.degraus || [];
  if (!degraus.length) return '';

  const ord = n => ['1ª', '2ª', '3ª', '4ª', '5ª'][n - 1] || `${n}ª`;
  const maior = Math.max(...degraus.map(d => Number(d.ocorrencia)));
  const oc = Number(ocorrenciaAtual || 0);

  return `<div class="escada">
      <div class="escada-h">O quadro pra essa infração</div>
      ${degraus.map(d => {
        const n = Number(d.ocorrencia);
        // Ocorrência 0 é a pena que não escala: o edital escreve "já na 1ª
        // ocorrência", e a mesma coisa vale sempre.
        const rotulo = n === 0 ? 'sempre' : ord(n);
        /* Passou do último degrau, o último é o que vale — é o que
           punicaoProximoDegrau faz, e a marca tem que dizer o mesmo. */
        const aqui = oc > 0 && (n === 0 || n === oc || (oc > maior && n === maior));
        return `<div class="escada-l${aqui ? ' aqui' : ''}">
            <span class="escada-oc">${rotulo}</span>
            <span>${_escapeHtml(d.texto || '')}</span>
          </div>`;
      }).join('')}
    </div>`;
}

/** A prévia do que vai acontecer, buscada no servidor (é ele quem conta). */
async function _carregarPrevia(teamId, infracaoId) {
  const box = _el('punicaoPrevia');
  const btn = _el('punicaoSubmit');
  _previaAtual = null;
  if (btn) btn.disabled = true;
  if (!box) return;

  if (!infracaoId) { box.style.display = 'none'; box.innerHTML = ''; return; }

  /* SEM TIME AINDA, mas já dá pra mostrar a pena: a escada é da infração, não
     do time. O que depende do time é só em qual degrau ele está. */
  if (!teamId) {
    box.style.display = '';
    box.innerHTML = _escadaHtml(infracaoId, 0)
      + '<div class="previa-nada">Escolha o time pra saber em que degrau ele está.</div>';
    return;
  }

  box.style.display = '';
  box.innerHTML = '<div class="previa-nada">Conferindo o histórico do time…</div>';

  let d;
  try {
    d = await _pApi(`punicoes.php?action=previa&team_id=${teamId}&infracao_id=${infracaoId}`);
  } catch (e) {
    box.innerHTML = `<div class="previa-nada">Não deu pra calcular: ${_escapeHtml(e.error || 'erro')}</div>`;
    return;
  }
  _previaAtual = d;

  const ord = ['1ª', '2ª', '3ª', '4ª', '5ª'][d.ocorrencia - 1] || `${d.ocorrencia}ª`;
  const reincid = d.ja_teve > 0
    ? `${d.ja_teve} ${d.ja_teve === 1 ? 'vez' : 'vezes'} neste ciclo`
    : 'primeira vez neste ciclo';

  const efeitos = (d.efeitos || []).map(e => {
    const info = _efeitosInfo[e.efeito] || { label: e.efeito };
    const valor = (e.valor !== undefined && e.valor !== null) ? ` (${e.valor})` : '';
    const dur = e.duracao && _duracoes[e.duracao] ? `<small>${_escapeHtml(_duracoes[e.duracao])}</small>` : '';
    const manual = info.modo === 'registra'
      ? '<small>fica registrado — quem executa é você</small>' : '';
    return `<div class="previa-efeito"><i class="bi bi-dot"></i><div>${_escapeHtml(info.label)}${valor}${dur}${manual}</div></div>`;
  }).join('');

  const avisos = [];
  if (d.pick_alvo && d.pick_alvo.ano) avisos.push(`Vai cair a pick própria de 1ª rodada de <b>${d.pick_alvo.ano}</b>.`);
  if (d.pick_alvo && d.pick_alvo.aviso) avisos.push(_escapeHtml(d.pick_alvo.aviso));
  if (d.fim_da_escala) avisos.push('O time já passou do último degrau do quadro — a pena repete a mais grave.');

  box.innerHTML = `
    <div class="previa-degrau">
      <i class="bi bi-diagram-3-fill"></i> ${ord} ocorrência
      <span style="opacity:.75;font-weight:600;text-transform:none;letter-spacing:0">· ${reincid}</span>
    </div>
    ${efeitos || '<div class="previa-nada">Esta infração não tem consequência no quadro.</div>'}
    ${avisos.length ? `<div class="previa-aviso">${avisos.join('<br>')}</div>` : ''}
    ${_escadaHtml(infracaoId, d.ocorrencia)}`;

  if (btn) btn.disabled = !(d.efeitos || []).length;
}

/** Quem está cumprindo alguma coisa agora, na liga escolhida. */
async function _carregarAtivas(league) {
  const box = _el('punicoesAtivas');
  if (!box) return;
  if (!league) { box.textContent = 'Escolha uma liga.'; return; }

  box.textContent = 'Carregando…';
  let lista = [];
  try {
    const d = await _pApi(`punicoes.php?action=punishments&league=${encodeURIComponent(league)}`);
    // Já cumprida fica fora daqui: ninguém está cumprindo o que já foi pago.
    lista = (d.punishments || []).filter(p => !p.reverted_at && !Number(p.ja_cumprida) && p.efeitos_json);
  } catch (e) { box.textContent = 'Não deu pra carregar.'; return; }

  /* Só o que corre no tempo E já começou. Perda de pick e advertência já
     aconteceram: listá-las aqui diria que o time está impedido de algo que
     não existe mais. */
  const porTime = new Map();
  lista.forEach(p => {
    let efeitos = [];
    try { efeitos = JSON.parse(p.efeitos_json) || []; } catch { return; }
    efeitos.forEach(e => {
      const info = _efeitosInfo[e.efeito];
      if (!info || info.duracao !== 'periodo') return;
      const vig = (e.vigencia || '').toUpperCase();
      const agora = vig === 'TEMPORADA' ? _momento.temporada : _momento.ciclo;
      if (vig !== 'PERMANENTE') {
        if (e.ate === null || e.ate === undefined || agora > Number(e.ate)) return;
        if (e.desde !== null && e.desde !== undefined && agora < Number(e.desde)) return;
      }
      const nome = `${p.city || ''} ${p.name || ''}`.trim();
      if (!porTime.has(nome)) porTime.set(nome, new Map());

      /* UM EFEITO, UMA TAG. Duas punições podem impor a mesma coisa (o
         quadro repete "rotação automática" em três degraus da mesma
         infração), e listar as duas só enche a linha dizendo o mesmo. Fica
         a que dura mais, que é o que responde "até quando". */
      const atual = porTime.get(nome).get(e.efeito);
      const maisLonga = !atual || vig === 'PERMANENTE'
                        || (atual.ate !== null && Number(e.ate) > Number(atual.ate));
      if (!maisLonga) return;

      porTime.get(nome).set(e.efeito, {
        tag: info.tag + (e.valor ? ` (${e.valor})` : ''),
        ate: vig === 'PERMANENTE' ? 'sem prazo'
           : (vig === 'TEMPORADA' ? `até a temporada ${e.ate}` : `até o ciclo ${e.ate}`),
        atePrazo: vig === 'PERMANENTE' ? null : Number(e.ate),
        infracao: p.motive || '',
      });
    });
  });

  if (!porTime.size) {
    box.innerHTML = '<div style="color:var(--text-3)">Ninguém cumprindo punição nesta liga.</div>';
    return;
  }
  box.innerHTML = [...porTime.entries()].map(([time, mapa]) => `
    <div class="ativa-item">
      <div class="ativa-time">${_escapeHtml(time)}</div>
      <div class="ativa-tags">${[...mapa.values()].map(e => `
        <span class="ativa-tag" title="${_escapeHtml(e.infracao)}">
          <i class="bi bi-exclamation-octagon-fill"></i>${_escapeHtml(e.tag)}
          <small>${_escapeHtml(e.ate)}</small>
        </span>`).join('')}</div>
    </div>`).join('');
}

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
    `<option value="${t.id}">${_escapeHtml(t.city)} ${_escapeHtml(t.name)}</option>`
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
      const teamName = _escapeHtml(`${p.city || ''} ${p.name || ''}`.trim() || 'Time');
      const league = p.league || p.team_league || '-';
      const reverted = !!p.reverted_at;
      // Revertida > já cumprida > ativa: revertida é a punição desfeita, já
      // cumprida é a que vale mas não cobra. Dizer "Ativa" nas duas mentiria.
      const cumprida = !reverted && !!Number(p.ja_cumprida);
      const punLabel = _escapeHtml(p.punishment_label || _getTypeLabel(p.type));
      const initial = teamName.charAt(0).toUpperCase();
      const pickChip = p.pick_id
        ? `<span class="pun-chip"><i class="bi bi-ticket-detailed"></i> Pick ${p.season_year || ''} R${p.round || ''}</span>`
        : '';
      let dataFmt = p.created_at || '';
      try {
        const d = new Date((p.created_at || '').replace(' ', 'T'));
        if (!isNaN(d)) dataFmt = d.toLocaleString('pt-BR', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
      } catch (e) {}
      const revertedInfo = reverted && p.reverted_at ? ` · revertida em ${String(p.reverted_at).slice(0, 10).split('-').reverse().join('/')}` : '';

      return `
        <div class="pun-v2${reverted ? ' is-reverted' : ''}">
          <div class="pun-v2-bar"></div>
          <div class="pun-v2-body">
            <div class="pun-v2-top">
              <div class="pun-v2-team">
                <span class="pun-v2-logo">${initial}</span>
                <span style="min-width:0">
                  <span class="pun-v2-teamname">${teamName}</span>
                  <span class="pun-v2-league">${league}</span>
                </span>
              </div>
              ${/* A REVERTIDA NÃO LEVA SELO. O canto de cima é onde se lê o
                    que a punição está fazendo — e ela não está fazendo nada.
                    O card já vem apagado (is-reverted) e a data diz "revertida
                    em tal dia", então o selo era um terceiro aviso da mesma
                    coisa, no lugar mais chamativo da linha. */ ''}
              ${reverted ? '' : `
              <div class="pun-v2-actions">
                <span class="pun-badge ${cumprida ? 'pun-badge-off' : 'pun-badge-on'}">${cumprida ? 'Já cumprida' : 'Ativa'}</span>
                <button type="button" class="btn-reverter" onclick="revertPunishment(${p.id})"><i class="bi bi-arrow-counterclockwise"></i>Reverter</button>
              </div>`}
            </div>
            <div class="pun-v2-chips">
              <span class="pun-chip type">${punLabel}</span>
              ${pickChip}
            </div>
            ${p.motive ? `<div class="pun-v2-motive">${_escapeHtml(p.motive)}</div>` : ''}
            <div class="pun-v2-date"><i class="bi bi-clock"></i> ${dataFmt}${revertedInfo}</div>
          </div>
        </div>`;
    }).join('');
  } catch (e) {
    container.innerHTML = `<p class="empty-state" style="color:var(--red)">${e.error || 'Erro ao carregar.'}</p>`;
  }
};

window.revertPunishment = async function(id) {
  if (!await confirmarSite('Reverter esta punição?')) return;
  try {
    await _pApi('punicoes.php', { method: 'POST', body: JSON.stringify({ action: 'revert', punishment_id: id }) });
    await window.loadPunishments({
      teamId: _el('punicaoHistoryTeam')?.value || '',
      league: _el('punicaoHistoryLeague')?.value || ''
    });
    _notify('success', 'Punição revertida.');
  } catch (e) { alert(e.error || 'Erro ao reverter.'); }
};

async function zerarPunicoesEAvisos(league) {
  if (!league) { alert('Selecione uma liga primeiro.'); return; }
  if (!await confirmarSite(`Zerar todas as punições e avisos (FBA SERASA) da ${league}? Isso APAGA o histórico inteiro (inclusive punições antigas já revertidas) e limpa qualquer banimento vigente — não dá pra desfazer.`)) return;
  try {
    const data = await _pApi('punicoes.php', { method: 'POST', body: JSON.stringify({ action: 'reset_league', league }) });
    _notify('success', `Zerado! ${data.apagados || 0} punição(ões)/aviso(s) apagados do histórico.`);
    if (typeof window.loadPunishments === 'function') {
      await window.loadPunishments({ league, teamId: _el('punicaoHistoryTeam')?.value || _el('punicaoTeam')?.value || '' });
    }
  } catch (e) {
    alert(e.error || 'Erro ao zerar punições e avisos.');
  }
}
window.zerarPunicoesEAvisos = zerarPunicoesEAvisos;

window.initPunicoes = async function(preselectedLeague) {
  let _curLeague = preselectedLeague || '';
  let _curTeamId = '';

  _el('punicaoLeague')?.addEventListener('change', async e => {
    _curLeague = e.target.value;
    _curTeamId = '';
    await _loadTeams(_curLeague, 'punicaoTeam');
    await _carregarQuadro(_curLeague);
    await _carregarPrevia('', '');
    await _carregarAtivas(_curLeague);
  });

  _el('punicaoTeam')?.addEventListener('change', async e => {
    _curTeamId = e.target.value;
    await _loadPicks(_curTeamId);
    const ht = _el('punicaoHistoryTeam');
    if (ht) ht.value = _curTeamId;
    await _carregarPrevia(_curTeamId, _el('punicaoInfracao')?.value || '');
    await window.loadPunishments({ teamId: _curTeamId, league: _el('punicaoHistoryLeague')?.value || _curLeague });
  });

  _el('punicaoInfracao')?.addEventListener('change', async e => {
    await _carregarPrevia(_curTeamId, e.target.value);
  });

  _el('punicaoAvancado')?.addEventListener('click', (ev) => {
    const p = _el('painelAvulsa');
    if (!p) return;
    const abrir = p.style.display === 'none';
    p.style.display = abrir ? '' : 'none';
    // O botão marca que o painel está aberto, e diz isso a quem usa leitor
    // de tela — é ele que controla o painel de baixo.
    ev.currentTarget.classList.toggle('aberto', abrir);
    ev.currentTarget.setAttribute('aria-expanded', abrir ? 'true' : 'false');
  });

  _el('punicaoHistoryLeague')?.addEventListener('change', async e => {
    await _loadTeams(e.target.value, 'punicaoHistoryTeam', 'Todos os times');
    await window.loadPunishments({ teamId: _el('punicaoHistoryTeam')?.value || '', league: e.target.value });
  });

  _el('punicaoHistoryTeam')?.addEventListener('change', async e => {
    await window.loadPunishments({ teamId: e.target.value, league: _el('punicaoHistoryLeague')?.value || '' });
  });

  _el('punicaoType')?.addEventListener('change', _updateFormVisibility);

  _el('btnZerarPunicoesAvisos')?.addEventListener('click', () => {
    zerarPunicoesEAvisos(_curLeague || _el('punicaoLeague')?.value || '');
  });

  // Aplicar pelo quadro: uma punição com todos os efeitos do degrau.
  _el('punicaoSubmit')?.addEventListener('click', async () => {
    const infracaoId = Number(_el('punicaoInfracao')?.value || 0);
    if (!_curTeamId) { alert('Selecione um time.'); return; }
    if (!infracaoId)  { alert('Selecione a infração.'); return; }
    if (!_previaAtual) { alert('Espere a prévia carregar.'); return; }

    const btn = _el('punicaoSubmit');
    if (btn) btn.disabled = true;
    try {
      const r = await _pApi('punicoes.php', { method: 'POST', body: JSON.stringify({
        action: 'aplicar',
        team_id: Number(_curTeamId),
        infracao_id: infracaoId,
        ocorrencia: _previaAtual.ocorrencia,
        notes: _el('punicaoNotes')?.value?.trim() || '',
        // Marcado, o servidor registra e não cobra nada. @see api/punicoes.php
        ja_cumprida: !!_el('punicaoJaCumprida')?.checked,
      })});
      const notes = _el('punicaoNotes');
      if (notes) notes.value = '';
      // Desmarca depois de aplicar: o padrão é punir, e o check esquecido
      // marcado faria a próxima punição não valer sem ninguém notar.
      const chk = _el('punicaoJaCumprida');
      if (chk) chk.checked = false;
      await _loadPicks(_curTeamId);
      await _carregarPrevia(_curTeamId, infracaoId);
      await _carregarAtivas(_curLeague);
      await window.loadPunishments({ teamId: _el('punicaoHistoryTeam')?.value || _curTeamId, league: _el('punicaoHistoryLeague')?.value || '' });
      // Aviso do servidor (ex.: perda de pick que ficou pendente) não pode
      // sumir atrás do "pronto": é a parte que não aconteceu.
      if (r.avisos && r.avisos.length) _notify('warning', r.avisos.join(' '));
      else _notify('success', 'Punição aplicada!');
    } catch (e) {
      alert(e.error || 'Erro ao aplicar.');
      if (btn) btn.disabled = false;
    }
  });

  _el('punicaoSubmitAvulsa')?.addEventListener('click', async () => {
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
      // A duração vem escolhida agora (temporada ou ciclo, com começo).
      // `season_scope` fica pro servidor traduzir quando não vier nada.
      duracao: _el('punicaoDuracao')?.value || 'TEMPORADA',
      created_at: _el('punicaoDate')?.value || '',
      ja_cumprida: !!_el('punicaoJaCumpridaAvulsa')?.checked
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
      const chk = _el('punicaoJaCumpridaAvulsa');
      if (chk) chk.checked = false;
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
  // A liga já vem escolhida quando se chega pelo admin; na página solta,
  // a primeira da lista. Sem isso o quadro e as ativas abrem vazios e
  // parecem quebrados até alguém mexer no seletor.
  const seletorLiga = _el('punicaoLeague');
  const primeira = [...(seletorLiga?.options || [])].map(o => o.value).find(v => v);
  const ligaInicial = preselectedLeague || primeira || '';
  if (ligaInicial) {
    _curLeague = ligaInicial;
    const ls = _el('punicaoLeague');
    if (ls) ls.value = ligaInicial;
    await _loadTeams(ligaInicial, 'punicaoTeam');
    await _carregarQuadro(ligaInicial);
    await _carregarAtivas(ligaInicial);
    const hl = _el('punicaoHistoryLeague');
    if (hl) {
      hl.value = ligaInicial;
      await _loadTeams(ligaInicial, 'punicaoHistoryTeam', 'Todos os times');
      await window.loadPunishments({ league: ligaInicial });
    }
  }
};

// Auto-init quando carregado diretamente na punicoes.php
(function () {
  function _tryAutoInit() {
    // ?league= vem de quem chegou pelo admin: cai na liga que estava aberta
    // lá, em vez de na primeira da lista.
    const q = new URLSearchParams(location.search).get('league') || '';
    const liga = /^(ELITE|NEXT|RISE|ROOKIE)$/i.test(q) ? q.toUpperCase() : '';
    if (_el('punicaoLeague')) window.initPunicoes(liga);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _tryAutoInit);
  } else {
    _tryAutoInit();
  }
})();
