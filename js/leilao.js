/**
 * Leilão de Jogadores — JS (redesign completo)
 */

// ── Helpers ───────────────────────────────────────────────────────────────────

const _esc = s => (s || '').toString()
  .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

async function _fetchJson(url, options = {}) {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...options });
  const text = await res.text();
  try { return JSON.parse(text); }
  catch (e) {
    const snippet = text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0, 200);
    throw new Error(snippet || 'Resposta inválida do servidor');
  }
}

// ── Design helpers ────────────────────────────────────────────────────────────

function _statusBadge(status) {
  const c = { pendente:'#f59e0b', ativo:'#22c55e', finalizado:'#64748b', aceita:'#22c55e', recusada:'#ef4444', cancelado:'#ef4444' };
  const l = { pendente:'Pendente', ativo:'Ativo', finalizado:'Finalizado', aceita:'Aceita', recusada:'Recusada', cancelado:'Cancelado' };
  const color = c[status] || 'var(--text-3)';
  const label = l[status] || (status || '—');
  return `<span style="font-size:10px;font-weight:700;color:${color};padding:2px 9px;background:${color}18;border:1px solid ${color}44;border-radius:999px;text-transform:uppercase;white-space:nowrap">${label}</span>`;
}

function _renderPropostasHtml(propostas, showActions, leilaoId) {
  if (!propostas || !propostas.length) {
    return '<p style="text-align:center;color:var(--text-3);font-size:13px;padding:24px 0">Nenhuma proposta recebida ainda.</p>';
  }
  return propostas.map(p => {
    const jogs = (p.jogadores || []).map(j => {
      const age = j.age ? ` · ${j.age} anos` : '';
      return `<span style="display:inline-flex;align-items:center;background:var(--panel-3);border-radius:6px;padding:3px 9px;font-size:11px;margin:2px 2px 2px 0"><strong style="color:var(--text)">${_esc(j.name)}</strong>&nbsp;<span style="color:var(--text-2)">${_esc(j.position||'')} · OVR ${j.overall||j.ovr||'?'}${age}</span></span>`;
    }).join('') || '<span style="color:var(--text-3);font-size:12px">—</span>';

    const picks = (p.picks || []).map(pk => {
      const swapBadge = pk.swap_type ? `<span style="font-size:9px;font-weight:700;background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3);border-radius:4px;padding:1px 5px;margin-left:5px">${_esc(pk.swap_type)}</span>` : '';
      const origTeam = (pk.original_team_name || '').trim() ? `<span style="font-size:10px;color:var(--text-3);margin-left:5px">${_esc(pk.original_team_name.trim())}</span>` : '';
      return `<span style="display:inline-flex;align-items:center;background:rgba(59,130,246,.1);color:#3b82f6;border:1px solid rgba(59,130,246,.25);border-radius:6px;padding:3px 9px;font-size:11px;margin:2px 2px 2px 0">${_esc(String(pk.season_year||''))} R${pk.round||pk.round_num||'?'}${swapBadge}${origTeam}</span>`;
    }).join('') || '<span style="color:var(--text-3);font-size:12px">—</span>';

    const obs = p.obs || p.notas;

    const extraJogs = (p.extra_jogadores || []).map(j => {
      const age = j.age ? ` · ${j.age} anos` : '';
      return `<span style="display:inline-flex;align-items:center;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);border-radius:6px;padding:3px 9px;font-size:11px;margin:2px 2px 2px 0"><strong style="color:var(--text)">${_esc(j.name)}</strong>&nbsp;<span style="color:var(--text-2)">${_esc(j.position||'')} · OVR ${j.overall||j.ovr||'?'}${age}</span></span>`;
    }).join('');
    const extraPicks = (p.extra_picks || []).map(pk => {
      const orig = (pk.original_team_name || '').trim();
      return `<span style="display:inline-flex;align-items:center;background:rgba(245,158,11,.1);color:#f59e0b;border:1px solid rgba(245,158,11,.25);border-radius:6px;padding:3px 9px;font-size:11px;margin:2px 2px 2px 0">${_esc(String(pk.season_year||''))} R${pk.round||'?'}${orig ? `<span style="font-size:10px;color:var(--text-3);margin-left:5px">${_esc(orig)}</span>` : ''}</span>`;
    }).join('');
    const personalizedHtml = p.is_personalized ? `
      <div style="background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.18);border-radius:8px;padding:10px 12px;margin-top:12px">
        <div style="font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px"><i class="bi bi-stars me-1"></i>Oferta Personalizada — itens do vendedor incluídos</div>
        ${extraJogs || extraPicks
          ? `<div style="margin-bottom:4px">${extraJogs}</div><div>${extraPicks}</div>`
          : '<span style="color:var(--text-3);font-size:12px">—</span>'}
      </div>` : '';

    const borderColor = p.status === 'aceita' ? 'rgba(34,197,94,.3)' : p.status === 'recusada' ? 'rgba(239,68,68,.12)' : 'var(--border)';

    const actionHtml = (showActions && p.status === 'pendente') ? `
      <div style="display:flex;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid var(--border)">
        <button onclick="aceitarProposta(${p.id})"
          style="flex:1;padding:9px;background:#22c55e;border:none;border-radius:8px;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--font)">
          <i class="bi bi-check-lg me-1"></i>Aceitar
        </button>
        <button onclick="recusarProposta(${p.id}, ${leilaoId})"
          style="flex:1;padding:9px;background:transparent;border:1px solid rgba(239,68,68,.4);border-radius:8px;color:#ef4444;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--font)">
          <i class="bi bi-x-lg me-1"></i>Recusar
        </button>
      </div>` : '';

    return `
      <div class="auction-proposal-card" style="background:var(--panel-2);border:1px solid ${borderColor};border-radius:var(--radius-sm);padding:14px 16px;margin-bottom:10px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;flex-wrap:wrap">
          <div style="font-weight:600;font-size:14px;color:var(--text)">${_esc(p.team_name||'—')}</div>
          ${_statusBadge(p.status)}
        </div>
        <div style="font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px">Jogadores</div>
        <div style="margin-bottom:12px">${jogs}</div>
        <div style="font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px">Picks</div>
        <div>${picks}</div>
        ${obs ? `<div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:8px;padding:10px 12px;margin-top:12px"><div style="font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px"><i class="bi bi-sticky me-1"></i>Observação</div><div style="font-size:13px;color:var(--text-2)">${_esc(obs)}</div></div>` : ''}
        ${personalizedHtml}
        ${actionHtml}
      </div>`;
  }).join('');
}

function _applyLeilaoTableLabels(root = document) {
  const tables = root.querySelectorAll('.table-responsive table, table.table');
  tables.forEach((table) => {
    const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
    table.querySelectorAll('tbody tr').forEach((tr) => {
      Array.from(tr.children).forEach((cell, idx) => {
        if (cell.tagName !== 'TD') return;
        if (!cell.dataset.label) {
          cell.dataset.label = headers[idx] || '';
        }
      });
    });
  });
}

// ── Modal de propostas (único ponto de abertura) ──────────────────────────────

let _modalLeilaoId = null;
let _modalIsOwner = false;
let _propostsAutoRefresh = null;
let _currentProposalSellerTeamId = null;
let _customOfferOpen = false;

function _getModalInstance() {
  const el = document.getElementById('modalVerPropostas');
  if (!el) return null;
  return bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el);
}

async function _loadPropostasContent(leilaoId, isOwner) {
  _modalLeilaoId = leilaoId;
  _modalIsOwner = isOwner;
  const container = document.getElementById('listaPropostasRecebidas');
  if (!container) return;
  container.innerHTML = '<div style="display:flex;justify-content:center;padding:32px"><div class="spinner-border" style="color:var(--red);width:1.5rem;height:1.5rem" role="status"></div></div>';
  try {
    const endpoint = isOwner ? 'ver_propostas' : 'ver_propostas_enviadas';
    const data = await _fetchJson(`api/leilao.php?action=${endpoint}&leilao_id=${leilaoId}`);
    const propostas = data.propostas || [];
    container.innerHTML = _renderPropostasHtml(propostas, isOwner, leilaoId);
  } catch (e) {
    container.innerHTML = `<p style="color:#ef4444;font-size:13px;text-align:center;padding:20px 0">${_esc(e.message||'Erro ao carregar')}</p>`;
  }
}

async function verMinhasPropostasRecebidas(leilaoId) {
  document.getElementById('leilaoIdVerPropostas').value = leilaoId;
  _getModalInstance().show();
  await _loadPropostasContent(leilaoId, true);
  _startPropostasAutoRefresh(leilaoId, true);
}

async function verPropostasEnviadas(leilaoId) {
  document.getElementById('leilaoIdVerPropostas').value = leilaoId;
  _getModalInstance().show();
  await _loadPropostasContent(leilaoId, false);
}

async function verPropostasAdmin(leilaoId) {
  await verMinhasPropostasRecebidas(leilaoId);
}

// Botão de refresh manual dentro do modal
window._refreshPropostas = async function() {
  if (!_modalLeilaoId) return;
  await _loadPropostasContent(_modalLeilaoId, _modalIsOwner);
};

function _startPropostasAutoRefresh(leilaoId, isOwner) {
  clearInterval(_propostsAutoRefresh);
  _propostsAutoRefresh = setInterval(async () => {
    const modalEl = document.getElementById('modalVerPropostas');
    if (!modalEl || !modalEl.classList.contains('show')) {
      clearInterval(_propostsAutoRefresh);
      return;
    }
    const container = document.getElementById('listaPropostasRecebidas');
    if (!container) return;
    try {
      const endpoint = isOwner ? 'ver_propostas' : 'ver_propostas_enviadas';
      const data = await _fetchJson(`api/leilao.php?action=${endpoint}&leilao_id=${leilaoId}`);
      const propostas = data.propostas || [];
      container.innerHTML = _renderPropostasHtml(propostas, isOwner, leilaoId);
    } catch (e) {}
  }, 15000);
}

// ── Leilões ativos ────────────────────────────────────────────────────────────

async function carregarLeiloesAtivos() {
  const container = document.getElementById('leiloesAtivosContainer');
  if (!container) return;
  container.innerHTML = '<div style="display:flex;justify-content:center;padding:32px"><div class="spinner-border" style="color:var(--red);width:1.5rem;height:1.5rem" role="status"></div></div>';

  try {
    const url = currentLeagueId
      ? `api/leilao.php?action=listar_ativos&league_id=${currentLeagueId}`
      : 'api/leilao.php?action=listar_ativos';
    const data = await _fetchJson(url);

    if (!data.success || !data.leiloes || !data.leiloes.length) {
      container.innerHTML = '<p style="color:var(--text-3);font-size:13px;text-align:center;padding:20px 0">Nenhum leilão em andamento.</p>';
      return;
    }

    const faDisabled = typeof faStatusEnabled !== 'undefined' && !faStatusEnabled;
    const banner = faDisabled
      ? `<div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:var(--radius-sm);padding:10px 14px;font-size:13px;color:#f59e0b;margin-bottom:16px"><i class="bi bi-exclamation-triangle me-2"></i><strong>Período fechado:</strong> propostas temporariamente desativadas nesta liga.</div>`
      : '';

    const cards = data.leiloes.map(l => {
      const isMyTeam = l.team_id == userTeamId;
      const teamLabel = _esc(l.team_name || 'Sem time');
      const borderLeft = isMyTeam ? 'border-left:3px solid #f59e0b;' : '';
      const myBadge = isMyTeam ? `<span style="font-size:10px;font-weight:700;color:#f59e0b;padding:2px 8px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:999px;margin-left:6px">Seu Jogador</span>` : '';

      const propCount = Number(l.total_propostas) || 0;
      const timerHtml = l.data_fim
        ? `<span class="auction-timer" data-end-time="${l.data_fim}"${l.data_fim_ts ? ` data-end-ts="${Number(l.data_fim_ts)*1000}"` : ''}
            style="font-size:13px;font-weight:700;color:#22c55e;font-variant-numeric:tabular-nums">20:00</span>`
        : '';

      const actionHtml = (() => {
        if (!userTeamId || isMyTeam) return '';
        if (faDisabled) return `<button disabled style="width:100%;padding:9px;background:var(--panel-3);border:1px solid var(--border);border-radius:8px;color:var(--text-3);font-size:13px;font-weight:600;cursor:not-allowed">Período fechado</button>`;
        return `<button onclick="abrirModalProposta(${l.id}, '${l.player_name.replace(/'/g,"\\'")}', ${l.team_id || 'null'})"
          class="auction-propose-btn"
          style="width:100%;padding:9px;background:var(--red);border:none;border-radius:8px;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--font)">
          <i class="bi bi-send me-1"></i>Enviar Proposta
        </button>`;
      })();

      const verBtn = (!isMyTeam && userTeamId) ? `
        <button onclick="verPropostasEnviadas(${l.id})"
          style="width:100%;padding:7px;background:transparent;border:1px solid var(--border);border-radius:8px;color:var(--text-2);font-size:12px;font-weight:500;cursor:pointer;font-family:var(--font);margin-bottom:8px">
          <i class="bi bi-eye me-1"></i>Ver propostas
        </button>` : '';

      const ownerBtn = isMyTeam && propCount > 0 ? `
        <button onclick="verMinhasPropostasRecebidas(${l.id})"
          style="width:100%;padding:9px;background:var(--red);border:none;border-radius:8px;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--font)">
          <i class="bi bi-inbox me-1"></i>Ver Propostas (${propCount})
        </button>` : '';

      return `
        <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;${borderLeft}">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px;flex-wrap:wrap">
            <div style="flex:1;min-width:0">
              <div style="font-size:16px;font-weight:700;color:var(--text);line-height:1.2">
                ${_esc(l.player_name)}${myBadge}
              </div>
              <div style="font-size:12px;color:var(--text-3);margin-top:3px">${_esc(l.position||'')} · ${l.age||'?'} anos · OVR <strong style="color:var(--red)">${l.ovr||'?'}</strong></div>
            </div>
            ${timerHtml ? `<div style="flex-shrink:0">${timerHtml}</div>` : ''}
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
            <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-2)"><i class="bi bi-building"></i>${teamLabel}</div>
            <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-2)"><i class="bi bi-trophy"></i>${_esc(l.league_name||'')}</div>
            <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-2)">
              <i class="bi bi-chat-dots"></i>${propCount} proposta${propCount !== 1 ? 's' : ''}
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:0">
            ${verBtn}${ownerBtn}${actionHtml}
          </div>
        </div>`;
    }).join('');

    container.innerHTML = banner + `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">${cards}</div>`;
    _applyLeilaoTableLabels(container);
    iniciarCronometros();
  } catch (e) {
    container.innerHTML = `<p style="color:#ef4444;font-size:13px;text-align:center;padding:20px 0">Erro ao carregar: ${_esc(e.message||'')}</p>`;
  }
}

// ── Histórico ─────────────────────────────────────────────────────────────────

async function carregarHistoricoLeiloes() {
  const container = document.getElementById('leiloesHistoricoContainer');
  if (!container) return;
  container.innerHTML = '<div style="display:flex;justify-content:center;padding:28px"><div class="spinner-border" style="color:var(--red);width:1.2rem;height:1.2rem" role="status"></div></div>';

  try {
    const url = currentLeagueId
      ? `api/leilao.php?action=historico&league_id=${currentLeagueId}`
      : 'api/leilao.php?action=historico';
    const data = await _fetchJson(url);
    const leiloes = (data.leiloes || []).filter(l => l.winner_team_name);

    if (!leiloes.length) {
      container.innerHTML = '<p style="color:var(--text-3);font-size:13px;text-align:center;padding:20px 0">Nenhum leilão concluído ainda.</p>';
      return;
    }

    const cards = leiloes.map(l => {
      const dateStr = l.data_fim
        ? new Date(l.data_fim).toLocaleDateString('pt-BR', { day:'2-digit', month:'short', year:'numeric' })
        : '';
      const playerLabel = l.player_name
        ? `<span style="font-weight:700;color:var(--text)">${_esc(l.player_name)}</span>`
        : `<span style="color:var(--text-3);font-style:italic">Jogador removido</span>`;
      return `
        <div style="background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <div style="flex:1;min-width:140px">
            <div style="font-size:13px;margin-bottom:4px">${playerLabel}</div>
            <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-2);flex-wrap:wrap">
              <span>${_esc(l.team_name||'—')}</span>
              <i class="bi bi-arrow-right" style="font-size:10px;color:var(--text-3)"></i>
              <span style="color:#22c55e;font-weight:600">${_esc(l.winner_team_name)}</span>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;flex-wrap:wrap">
            ${dateStr ? `<span style="font-size:11px;color:var(--text-3)">${dateStr}</span>` : ''}
            <button onclick="verPropostaVencedora(${l.id})"
              style="padding:5px 12px;background:transparent;border:1px solid var(--border);border-radius:8px;color:var(--text-2);font-size:12px;font-weight:500;cursor:pointer;font-family:var(--font);white-space:nowrap;transition:all .15s"
              onmouseover="this.style.borderColor='var(--border-red)';this.style.color='var(--red)'"
              onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text-2)'">
              <i class="bi bi-trophy me-1"></i>Proposta vencedora
            </button>
          </div>
        </div>`;
    }).join('');

    container.innerHTML = `<div style="display:flex;flex-direction:column;gap:8px">${cards}</div>`;
    _applyLeilaoTableLabels(container);
  } catch (e) {
    container.innerHTML = '<p style="color:#ef4444;font-size:13px">Erro ao carregar histórico.</p>';
  }
}

async function verPropostaVencedora(leilaoId) {
  document.getElementById('leilaoIdVerPropostas').value = leilaoId;
  const modalEl = document.getElementById('modalVerPropostas');
  const titleEl = modalEl?.querySelector('.modal-title');
  if (titleEl) titleEl.innerHTML = '<i class="bi bi-trophy" style="color:#22c55e;margin-right:8px"></i>Proposta Vencedora';
  _getModalInstance().show();
  const container = document.getElementById('listaPropostasRecebidas');
  if (!container) return;
  container.innerHTML = '<div style="display:flex;justify-content:center;padding:28px"><div class="spinner-border" style="color:var(--red);width:1.2rem;height:1.2rem" role="status"></div></div>';
  try {
    const data = await _fetchJson(`api/leilao.php?action=ver_propostas&leilao_id=${leilaoId}`);
    const propostas = data.propostas || [];
    const vencedora = propostas.filter(p => p.status === 'aceita');
    if (!vencedora.length) {
      container.innerHTML = '<p style="text-align:center;color:var(--text-3);font-size:13px;padding:24px 0">Nenhuma proposta aceita neste leilão.</p>';
      return;
    }
    container.innerHTML = _renderPropostasHtml(vencedora, false, leilaoId);
  } catch (e) {
    container.innerHTML = `<p style="color:#ef4444;font-size:13px;text-align:center;padding:20px">${_esc(e.message||'Erro')}</p>`;
  }
}

// ── Propostas Recebidas (card inline, sem modal) ───────────────────────────────

async function carregarPropostasRecebidas() {
  const container = document.getElementById('propostasRecebidasContainer');
  if (!container) return;
  container.innerHTML = '<div style="display:flex;justify-content:center;padding:24px"><div class="spinner-border" style="color:var(--red);width:1.2rem;height:1.2rem" role="status"></div></div>';

  try {
    const data = await _fetchJson('api/leilao.php?action=propostas_recebidas');
    const leiloes = data.leiloes || [];

    if (!leiloes.length) {
      container.innerHTML = '<p style="color:var(--text-3);font-size:13px;text-align:center;padding:16px 0">Nenhum jogador seu está em leilão com propostas.</p>';
      return;
    }

    const cards = leiloes.map(l => {
      const n = Number(l.total_propostas) || 0;
      return `
        <div style="background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 16px;margin-bottom:10px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
            <div>
              <div style="font-weight:600;font-size:14px;color:var(--text)">${_esc(l.player_name)}</div>
              <div style="font-size:12px;color:var(--text-3);margin-top:2px">${_esc(l.position||'')} · OVR ${l.ovr||'?'} · ${n} proposta${n!==1?'s':''}</div>
            </div>
            <button onclick="verMinhasPropostasRecebidas(${l.id})"
              style="padding:7px 14px;background:var(--red);border:none;border-radius:8px;color:#fff;font-size:12px;font-weight:600;cursor:pointer;font-family:var(--font);white-space:nowrap">
              <i class="bi bi-inbox me-1"></i>Ver e Escolher
            </button>
          </div>
        </div>`;
    }).join('');

    container.innerHTML = cards;
    _applyLeilaoTableLabels(container);
  } catch (e) {
    container.innerHTML = '<p style="color:#ef4444;font-size:13px">Erro ao carregar propostas.</p>';
  }
}

// ── Modal Proposta (enviar) ────────────────────────────────────────────────────

async function abrirModalProposta(leilaoId, playerName, sellerTeamId) {
  if (typeof faStatusEnabled !== 'undefined' && !faStatusEnabled) {
    alert('O período de propostas está fechado nesta liga.');
    return;
  }
  document.getElementById('leilaoIdProposta').value = leilaoId;
  const sellerInput = document.getElementById('leilaoSellerTeamId');
  if (sellerInput) sellerInput.value = sellerTeamId || '';
  _currentProposalSellerTeamId = sellerTeamId || null;
  _customOfferOpen = false;
  document.getElementById('jogadorLeilaoNome').textContent = playerName;
  document.getElementById('notasProposta').value = '';
  const obsInput = document.getElementById('obsProposta');
  if (obsInput) obsInput.value = '';

  // Reset custom offer section
  const customSection = document.getElementById('propostaCustomSection');
  if (customSection) customSection.style.display = 'none';
  const chevron = document.getElementById('customOfferChevron');
  if (chevron) { chevron.classList.remove('bi-chevron-up'); chevron.classList.add('bi-chevron-down'); }
  const sellerPlayersDiv = document.getElementById('sellerPlayersParaTroca');
  if (sellerPlayersDiv) sellerPlayersDiv.innerHTML = '<p style="color:var(--text-3);font-size:13px">Carregando...</p>';
  const sellerPicksDiv = document.getElementById('sellerPicksParaTroca');
  if (sellerPicksDiv) sellerPicksDiv.innerHTML = '<p style="color:var(--text-3);font-size:13px">Carregando...</p>';
  const toggleBtn = document.getElementById('btnToggleCustomOffer');
  if (toggleBtn) toggleBtn.style.display = sellerTeamId ? '' : 'none';

  const container = document.getElementById('meusJogadoresParaTroca');
  container.innerHTML = '<p style="color:var(--text-3);font-size:13px">Carregando...</p>';

  try {
    const url = userTeamId ? `api/team-players.php?team_id=${userTeamId}` : 'api/team-players.php';
    const data = await _fetchJson(url);
    const players = data.players || [];
    if (players.length) {
      container.innerHTML = '<div style="display:flex;flex-direction:column;gap:6px">' +
        players.map(pl => `
          <label style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:var(--panel-2);border:1px solid var(--border);border-radius:8px;cursor:pointer">
            <input class="form-check-input player-checkbox" type="checkbox" value="${pl.id}" style="flex-shrink:0;margin:0">
            <span style="font-size:13px"><strong style="color:var(--text)">${_esc(pl.name)}</strong> <span style="color:var(--text-2)">${_esc(pl.position||'')} · OVR ${pl.ovr||pl.overall||'?'}</span></span>
          </label>`).join('') + '</div>';
    } else {
      container.innerHTML = '<p style="color:#f59e0b;font-size:13px">Você não tem jogadores disponíveis para troca.</p>';
    }
  } catch (e) {
    container.innerHTML = '<p style="color:#ef4444;font-size:13px">Erro ao carregar jogadores.</p>';
  }

  const picksContainer = document.getElementById('minhasPicksParaTroca');
  if (picksContainer) {
    picksContainer.innerHTML = '<p style="color:var(--text-3);font-size:13px">Carregando...</p>';
    try {
      const dataP = await _fetchJson('api/leilao.php?action=minhas_picks');
      const picks = dataP.picks || [];
      if (picks.length) {
        picksContainer.innerHTML = '<div style="display:flex;flex-direction:column;gap:6px">' +
          picks.map(pk => {
            const r = pk.round || pk.round_num || pk.rnd;
            const orig = (pk.original_team_name || '').trim();
            const label = `${pk.season_year} R${r}${orig ? ' · ' + orig : ''}`;
            return `
              <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:var(--panel-2);border:1px solid var(--border);border-radius:8px">
                <input class="form-check-input pick-checkbox" type="checkbox" value="${pk.id}" style="flex-shrink:0;margin:0">
                <span style="font-size:13px;color:var(--text);flex:1">${_esc(label)}</span>
                <select class="pick-swap-select" data-pick-id="${pk.id}" style="font-size:11px;background:var(--panel-3);border:1px solid var(--border);color:var(--text-2);border-radius:6px;padding:2px 6px;cursor:pointer;font-family:var(--font)">
                  <option value="">Sem SWAP</option>
                  <option value="SB">SB</option>
                  <option value="SW">SW</option>
                </select>
              </div>`;
          }).join('') + '</div>';
      } else {
        picksContainer.innerHTML = '<p style="color:var(--text-3);font-size:13px">Você não tem picks disponíveis.</p>';
      }
    } catch (e) {
      picksContainer.innerHTML = '<p style="color:#ef4444;font-size:13px">Erro ao carregar picks.</p>';
    }
  }

  new bootstrap.Modal(document.getElementById('modalProposta')).show();
}

function toggleCustomOffer() {
  _customOfferOpen = !_customOfferOpen;
  const section = document.getElementById('propostaCustomSection');
  const chevron = document.getElementById('customOfferChevron');
  if (section) section.style.display = _customOfferOpen ? 'block' : 'none';
  if (chevron) {
    chevron.classList.toggle('bi-chevron-down', !_customOfferOpen);
    chevron.classList.toggle('bi-chevron-up', _customOfferOpen);
  }
  if (_customOfferOpen && _currentProposalSellerTeamId) {
    _loadSellerItemsForProposal();
  }
}

async function _loadSellerItemsForProposal() {
  if (!_currentProposalSellerTeamId) return;
  const playersDiv = document.getElementById('sellerPlayersParaTroca');
  const picksDiv = document.getElementById('sellerPicksParaTroca');
  try {
    const data = await _fetchJson(`api/leilao.php?action=seller_items&seller_team_id=${_currentProposalSellerTeamId}`);
    const players = data.players || [];
    if (playersDiv) {
      playersDiv.innerHTML = players.length
        ? '<div style="display:flex;flex-direction:column;gap:6px">' + players.map(pl =>
            `<label style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:var(--panel-2);border:1px solid var(--border);border-radius:8px;cursor:pointer">
              <input class="form-check-input extra-player-checkbox" type="checkbox" value="${pl.id}" style="flex-shrink:0;margin:0">
              <span style="font-size:13px"><strong style="color:var(--text)">${_esc(pl.name)}</strong> <span style="color:var(--text-2)">${_esc(pl.position||'')} · OVR ${pl.ovr||'?'} · ${pl.age||'?'} anos</span></span>
            </label>`).join('') + '</div>'
        : '<p style="color:var(--text-3);font-size:13px">Nenhum jogador disponível.</p>';
    }
    const picks = data.picks || [];
    if (picksDiv) {
      picksDiv.innerHTML = picks.length
        ? '<div style="display:flex;flex-direction:column;gap:6px">' + picks.map(pk => {
            const r = pk.round || pk.round_num;
            const orig = (pk.original_team_name || '').trim();
            const label = `${pk.season_year} R${r}${orig ? ' · ' + orig : ''}`;
            return `<label style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:var(--panel-2);border:1px solid var(--border);border-radius:8px;cursor:pointer">
              <input class="form-check-input extra-pick-checkbox" type="checkbox" value="${pk.id}" style="flex-shrink:0;margin:0">
              <span style="font-size:13px;color:var(--text)">${_esc(label)}</span>
            </label>`;
          }).join('') + '</div>'
        : '<p style="color:var(--text-3);font-size:13px">Nenhuma pick disponível.</p>';
    }
  } catch (e) {
    if (playersDiv) playersDiv.innerHTML = '<p style="color:#ef4444;font-size:13px">Erro ao carregar.</p>';
    if (picksDiv) picksDiv.innerHTML = '';
  }
}

document.getElementById('btnEnviarProposta')?.addEventListener('click', async function () {
  if (typeof faStatusEnabled !== 'undefined' && !faStatusEnabled) {
    alert('O período de propostas está fechado nesta liga.');
    return;
  }
  const leilaoId = document.getElementById('leilaoIdProposta').value;
  const notas = document.getElementById('notasProposta').value;
  const obs = document.getElementById('obsProposta')?.value || '';
  const playerIds = Array.from(document.querySelectorAll('.player-checkbox:checked')).map(cb => cb.value);
  const pickIds = Array.from(document.querySelectorAll('.pick-checkbox:checked')).map(cb => cb.value);

  const pickSwaps = {};
  document.querySelectorAll('.pick-swap-select').forEach(sel => {
    if (sel.value) pickSwaps[sel.dataset.pickId] = sel.value;
  });
  const extraPlayerIds = Array.from(document.querySelectorAll('.extra-player-checkbox:checked')).map(cb => cb.value);
  const extraPickIds = Array.from(document.querySelectorAll('.extra-pick-checkbox:checked')).map(cb => cb.value);
  const isPersonalized = extraPlayerIds.length > 0 || extraPickIds.length > 0;

  if (!playerIds.length && !pickIds.length && !notas.trim() && !obs.trim()) {
    alert('Informe uma mensagem ou selecione jogadores/picks para oferecer.');
    return;
  }

  this.disabled = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando...';
  try {
    const data = await _fetchJson('api/leilao.php', {
      method: 'POST',
      body: JSON.stringify({ action:'enviar_proposta', leilao_id:leilaoId, player_ids:playerIds, pick_ids:pickIds, notas, obs, pick_swaps:pickSwaps, extra_player_ids:extraPlayerIds, extra_pick_ids:extraPickIds, is_personalized:isPersonalized })
    });
    if (data.success) {
      bootstrap.Modal.getInstance(document.getElementById('modalProposta'))?.hide();
      carregarLeiloesAtivos();
      carregarPropostasRecebidas();
    } else {
      alert('Erro: ' + (data.error || 'Erro desconhecido'));
    }
  } catch (e) {
    alert('Erro ao enviar proposta: ' + (e.message || ''));
  } finally {
    this.disabled = false;
    this.innerHTML = '<i class="bi bi-send me-1"></i>Enviar Proposta';
  }
});

// ── Aceitar / Recusar ─────────────────────────────────────────────────────────

async function aceitarProposta(propostaId) {
  if (!confirm('Aceitar esta proposta? A troca será processada.')) return;
  try {
    const data = await _fetchJson('api/leilao.php', {
      method: 'POST',
      body: JSON.stringify({ action:'aceitar_proposta', proposta_id:propostaId })
    });
    if (data.success) {
      bootstrap.Modal.getInstance(document.getElementById('modalVerPropostas'))?.hide();
      carregarLeiloesAtivos();
      carregarPropostasRecebidas();
      carregarHistoricoLeiloes();
      if (isAdmin) carregarLeiloesAdmin();
    } else {
      alert('Erro: ' + (data.error || 'Erro desconhecido'));
    }
  } catch (e) {
    alert('Erro ao aceitar proposta: ' + (e.message || ''));
  }
}

async function recusarProposta(propostaId, leilaoId) {
  if (!confirm('Recusar esta proposta?')) return;
  try {
    const data = await _fetchJson('api/leilao.php', {
      method: 'POST',
      body: JSON.stringify({ action:'recusar_proposta', proposta_id:propostaId })
    });
    if (data.success) {
      // Recarrega APENAS o conteúdo do modal sem fechar/reabrir (fix do bug de tela bugada)
      await _loadPropostasContent(leilaoId || _modalLeilaoId, _modalIsOwner);
      carregarPropostasRecebidas();
    } else {
      alert('Erro: ' + (data.error || 'Erro desconhecido'));
    }
  } catch (e) {
    alert('Erro ao recusar proposta: ' + (e.message || ''));
  }
}

// ── Admin: leilões ────────────────────────────────────────────────────────────

async function carregarLeiloesAdmin() {
  const container = document.getElementById('adminLeiloesContainer');
  if (!container) return;

  try {
    const data = await _fetchJson('api/leilao.php?action=listar_admin');
    const leiloes = data.leiloes || [];

    if (!leiloes.length) {
      container.innerHTML = '<p style="color:var(--text-3);font-size:13px;text-align:center;padding:16px 0">Nenhum leilão cadastrado.</p>';
      return;
    }

    const cards = leiloes.map(l => {
      const n = Number(l.total_propostas) || 0;
      const teamLabel = _esc(l.team_name || 'Sem time');
      return `
        <div style="background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 16px;margin-bottom:10px">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px;flex-wrap:wrap">
            <div style="flex:1;min-width:0">
              <div style="font-weight:600;font-size:14px;color:var(--text)">${_esc(l.player_name)}</div>
              <div style="font-size:11px;color:var(--text-3);margin-top:2px">${_esc(l.position||'')} · OVR ${l.ovr||'?'} · ${_esc(l.league_name||'')} · ${teamLabel}</div>
            </div>
            ${_statusBadge(l.status)}
          </div>
          <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center">
            <button onclick="verPropostasAdmin(${l.id})"
              style="padding:5px 12px;background:transparent;border:1px solid var(--border);border-radius:8px;color:var(--text-2);font-size:12px;font-weight:500;cursor:pointer;font-family:var(--font)">
              <i class="bi bi-eye me-1"></i>Ver Propostas ${n > 0 ? `(${n})` : ''}
            </button>
            ${l.status === 'ativo' ? `
              <button onclick="cancelarLeilao(${l.id})"
                style="padding:5px 12px;background:transparent;border:1px solid rgba(239,68,68,.35);border-radius:8px;color:#ef4444;font-size:12px;font-weight:500;cursor:pointer;font-family:var(--font)">
                <i class="bi bi-x-lg me-1"></i>Cancelar
              </button>` : ''}
            ${l.status === 'finalizado' ? `
              <button onclick="reverterLeilao(${l.id}, '${(l.player_name||'').replace(/'/g,"\\'")}' )"
                style="padding:5px 12px;background:transparent;border:1px solid rgba(245,158,11,.35);border-radius:8px;color:#f59e0b;font-size:12px;font-weight:500;cursor:pointer;font-family:var(--font)">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Reverter
              </button>` : ''}
          </div>
        </div>`;
    }).join('');

    container.innerHTML = cards;
    _applyLeilaoTableLabels(container);
  } catch (e) {
    container.innerHTML = '<p style="color:#ef4444;font-size:13px">Erro ao carregar leilões.</p>';
  }
}

async function carregarPendentesCriados() {
  const container = document.getElementById('auctionTempList');
  if (!container) return;

  try {
    const data = await _fetchJson('api/leilao.php?action=listar_temp');
    const criados = data.leiloes || [];

    if (!criados.length) {
      container.innerHTML = '<p style="color:var(--text-3);font-size:13px;text-align:center;padding:12px 0">Nenhum jogador criado.</p>';
      return;
    }

    const cards = criados.map(l => {
      const status = (l.status || '').toLowerCase();
      return `
        <div style="background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 15px;margin-bottom:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:13px;color:var(--text)">${_esc(l.player_name)}</div>
            <div style="font-size:11px;color:var(--text-3);margin-top:2px">${_esc(l.position||'')} · OVR ${l.ovr||'?'} · ${_esc(l.league_name||'')}</div>
          </div>
          ${_statusBadge(status)}
          <div style="display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap">
            ${status === 'pendente' ? `
              <button onclick="iniciarLeilaoPendente(${l.id})"
                style="padding:5px 11px;background:#22c55e;border:none;border-radius:7px;color:#fff;font-size:12px;font-weight:600;cursor:pointer;font-family:var(--font)">
                <i class="bi bi-play-fill me-1"></i>Iniciar
              </button>
              <button onclick="removerLeilaoPendente(${l.id})"
                style="padding:5px 11px;background:transparent;border:1px solid rgba(239,68,68,.35);border-radius:7px;color:#ef4444;font-size:12px;font-weight:500;cursor:pointer;font-family:var(--font)">
                <i class="bi bi-trash"></i>
              </button>` : ''}
            ${status === 'ativo' ? `
              <button onclick="verPropostasAdmin(${l.id})"
                style="padding:5px 11px;background:transparent;border:1px solid var(--border);border-radius:7px;color:var(--text-2);font-size:12px;font-weight:500;cursor:pointer;font-family:var(--font)">
                <i class="bi bi-eye me-1"></i>Propostas
              </button>` : ''}
          </div>
        </div>`;
    }).join('');

    container.innerHTML = cards;
    _applyLeilaoTableLabels(container);
  } catch (e) {
    container.innerHTML = '<p style="color:#ef4444;font-size:13px">Erro ao carregar jogadores criados.</p>';
  }
}

async function cancelarLeilao(leilaoId) {
  if (!confirm('Cancelar este leilão?')) return;
  try {
    const data = await _fetchJson('api/leilao.php', {
      method: 'POST',
      body: JSON.stringify({ action:'cancelar', leilao_id:leilaoId })
    });
    if (data.success) {
      carregarLeiloesAdmin();
      carregarLeiloesAtivos();
    } else {
      alert('Erro: ' + (data.error || ''));
    }
  } catch (e) { alert('Erro ao cancelar: ' + (e.message||'')); }
}

async function reverterLeilao(leilaoId, playerName) {
  if (!confirm(`Reverter leilão de "${playerName}"?\n\nO jogador retorna ao time de origem e os itens voltam para quem os enviou.`)) return;
  try {
    const data = await _fetchJson('api/leilao.php', {
      method: 'POST',
      body: JSON.stringify({ action:'reverter', leilao_id:leilaoId })
    });
    if (data.success) {
      carregarLeiloesAdmin();
      carregarLeiloesAtivos();
    } else {
      alert('Erro: ' + (data.error || ''));
    }
  } catch (e) { alert('Erro ao reverter: ' + (e.message||'')); }
}

// ── Admin: criar jogador / pendentes ──────────────────────────────────────────

async function iniciarLeilaoPendente(leilaoId) {
  try {
    const data = await _fetchJson('api/leilao.php', {
      method: 'POST',
      body: JSON.stringify({ action:'iniciar_leilao', leilao_id:leilaoId })
    });
    if (!data.success) throw new Error(data.error || 'Erro ao iniciar');
    carregarPendentesCriados();
    carregarLeiloesAdmin();
    carregarLeiloesAtivos();
  } catch (e) { alert(e.message || 'Erro'); }
}

async function removerLeilaoPendente(leilaoId) {
  if (!confirm('Remover este jogador pendente?')) return;
  try {
    const data = await _fetchJson('api/leilao.php', {
      method: 'POST',
      body: JSON.stringify({ action:'remover_temp', leilao_id:leilaoId })
    });
    if (!data.success) throw new Error(data.error || 'Erro');
    carregarPendentesCriados();
    carregarLeiloesAdmin();
  } catch (e) { alert(e.message || 'Erro'); }
}

async function cadastrarJogadorLeilao() {
  const selectedPlayerId = document.getElementById('auctionSelectedPlayerId')?.value || '';
  const selectedTeamId = document.getElementById('auctionSelectedTeamId')?.value || '';
  const leagueId = document.getElementById('selectLeague')?.value;
  const newPlayerEnabled = document.getElementById('auctionModeCreate')?.checked;

  if ((!selectedPlayerId && !newPlayerEnabled) || !leagueId) {
    alert('Selecione liga e jogador');
    return;
  }

  const payload = {
    action: 'cadastrar',
    player_id: selectedPlayerId || null,
    team_id: newPlayerEnabled ? null : (selectedTeamId || null),
    league_id: leagueId
  };

  if (newPlayerEnabled) {
    payload.new_player = {
      name: document.getElementById('auctionPlayerName')?.value || '',
      position: document.getElementById('auctionPlayerPosition')?.value || '',
      age: document.getElementById('auctionPlayerAge')?.value || '',
      ovr: document.getElementById('auctionPlayerOvr')?.value || ''
    };
  }

  try {
    const data = await _fetchJson('api/leilao.php', { method:'POST', body: JSON.stringify(payload) });
    if (data.success) {
      const si = document.getElementById('selectLeague');
      if (si) si.value = '';
      const sp = document.getElementById('auctionPlayerSearch');
      if (sp) sp.value = '';
      document.getElementById('auctionSelectedPlayerId') && (document.getElementById('auctionSelectedPlayerId').value = '');
      document.getElementById('auctionSelectedTeamId') && (document.getElementById('auctionSelectedTeamId').value = '');
      const sl = document.getElementById('auctionSelectedLabel');
      if (sl) sl.style.display = 'none';
      carregarLeiloesAdmin();
      carregarLeiloesAtivos();
    } else {
      alert('Erro: ' + (data.error || ''));
    }
  } catch (e) { alert('Erro ao cadastrar: ' + (e.message||'')); }
}

async function criarJogadorParaLista() {
  const leagueId = document.getElementById('selectLeague')?.value;
  const name = document.getElementById('auctionPlayerName')?.value.trim();
  const position = document.getElementById('auctionPlayerPosition')?.value;
  const age = parseInt(document.getElementById('auctionPlayerAge')?.value || '0', 10);
  const ovr = parseInt(document.getElementById('auctionPlayerOvr')?.value || '0', 10);

  if (!leagueId || !name || !position || !age || !ovr) {
    alert('Preencha liga, nome, posição, idade e OVR.');
    return;
  }

  try {
    const resAuction = await _fetchJson('api/leilao.php', {
      method: 'POST',
      body: JSON.stringify({ action:'cadastrar', player_id:null, team_id:null, league_id:leagueId, new_player:{name,position,age,ovr}, status:'pendente' })
    });
    if (!resAuction.success) throw new Error(resAuction.error || 'Erro');
    document.getElementById('auctionPlayerName').value = '';
    document.getElementById('auctionPlayerAge').value = '25';
    document.getElementById('auctionPlayerOvr').value = '70';
    carregarLeiloesAdmin();
    carregarPendentesCriados();
    carregarLeiloesAtivos();
  } catch (e) { alert(e.message || 'Erro ao criar jogador'); }
}

// ── setupAdminEvents (mantida para compatibilidade com o form em leilao.php) ──

function setupAdminEvents() {
  const selectLeague = document.getElementById('selectLeague');
  const btnCadastrar = document.getElementById('btnCadastrarLeilao');
  if (!selectLeague || !btnCadastrar) return;

  const modeSearch = document.getElementById('auctionModeSearch');
  const modeCreate = document.getElementById('auctionModeCreate');
  const searchArea = document.getElementById('auctionSearchArea');
  const createArea = document.getElementById('auctionCreateArea');
  const createNameInput = document.getElementById('auctionPlayerName');
  const createPositionSelect = document.getElementById('auctionPlayerPosition');
  const createAgeInput = document.getElementById('auctionPlayerAge');
  const createOvrInput = document.getElementById('auctionPlayerOvr');
  const searchBtn = document.getElementById('auctionSearchBtn');
  const searchInput = document.getElementById('auctionPlayerSearch');
  const searchResults = document.getElementById('auctionPlayerResults');
  const selectedLabel = document.getElementById('auctionSelectedLabel');
  const selectedPlayerIdInput = document.getElementById('auctionSelectedPlayerId');
  const selectedTeamIdInput = document.getElementById('auctionSelectedTeamId');
  const createBtn = document.getElementById('btnCriarJogadorLeilao');

  const setMode = () => {
    const isCreate = modeCreate?.checked;
    if (searchArea) searchArea.style.display = isCreate ? 'none' : 'block';
    if (createArea) createArea.style.display = isCreate ? 'block' : 'none';
    const createReady = !!(createNameInput?.value.trim() && createPositionSelect?.value && createAgeInput?.value && createOvrInput?.value);
    const hasLeague = !!selectLeague.value;
    btnCadastrar.disabled = isCreate ? !(createReady && hasLeague) : !(selectedPlayerIdInput?.value && hasLeague);
    if (createBtn) createBtn.disabled = !(isCreate && createReady && hasLeague);
  };

  modeSearch?.addEventListener('change', setMode);
  modeCreate?.addEventListener('change', setMode);
  selectLeague.addEventListener('change', () => {
    btnCadastrar.disabled = true;
    if (selectedPlayerIdInput) selectedPlayerIdInput.value = '';
    if (selectedTeamIdInput) selectedTeamIdInput.value = '';
    if (selectedLabel) selectedLabel.style.display = 'none';
    setMode();
  });
  [createNameInput, createPositionSelect, createAgeInput, createOvrInput].forEach(el => {
    el?.addEventListener('input', setMode);
    el?.addEventListener('change', setMode);
  });

  btnCadastrar.addEventListener('click', cadastrarJogadorLeilao);
  createBtn?.addEventListener('click', criarJogadorParaLista);

  searchBtn?.addEventListener('click', async () => {
    const term = searchInput?.value.trim();
    const leagueName = selectLeague.options?.[selectLeague.selectedIndex]?.dataset?.leagueName || '';
    if (!term || term.length < 2 || !leagueName) {
      if (searchResults) searchResults.style.display = 'none';
      return;
    }
    try {
      const data = await _fetchJson(`api/team.php?action=search_player&query=${encodeURIComponent(term)}&league=${encodeURIComponent(leagueName)}`);
      const players = data.players || [];
      if (!searchResults) return;
      if (!players.length) { searchResults.style.display = 'none'; return; }
      searchResults.innerHTML = players.map(p =>
        `<button type="button" class="list-group-item list-group-item-action" data-player-id="${p.id}" data-team-id="${p.team_id}">${_esc(p.name)} — ${_esc(p.team_name)} (${p.ovr||p.overall})</button>`
      ).join('');
      searchResults.style.display = 'block';
      searchResults.querySelectorAll('button').forEach(btn => {
        btn.addEventListener('click', () => {
          if (selectedPlayerIdInput) selectedPlayerIdInput.value = btn.dataset.playerId;
          if (selectedTeamIdInput) selectedTeamIdInput.value = btn.dataset.teamId;
          if (searchInput) searchInput.value = btn.textContent.trim();
          if (selectedLabel) { selectedLabel.textContent = `Selecionado: ${btn.textContent.trim()}`; selectedLabel.style.display = 'block'; }
          searchResults.style.display = 'none';
          btnCadastrar.disabled = false;
        });
      });
    } catch (e) {
      if (searchResults) searchResults.style.display = 'none';
    }
  });

  setMode();
}

// ── Cronômetros ────────────────────────────────────────────────────────────────

function iniciarCronometros() {
  const timers = document.querySelectorAll('.auction-timer');
  if (!timers.length) return;

  const update = () => {
    const now = Date.now();
    timers.forEach(timer => {
      const endTime = timer.getAttribute('data-end-time');
      if (!endTime) return;
      let end = Number(timer.getAttribute('data-end-fixed') || 0);
      if (!end) {
        const endTs = Number(timer.getAttribute('data-end-ts') || 0);
        if (endTs) {
          end = endTs;
        } else {
          const normalized = endTime.replace(' ', 'T');
          const endLocal = new Date(normalized).getTime();
          const endUtc = new Date(normalized + 'Z').getTime();
          const target = 20 * 60 * 1000;
          const diffLocal = endLocal - now;
          const diffUtc = endUtc - now;
          end = Math.abs(diffLocal - target) <= Math.abs(diffUtc - target) ? endLocal : endUtc;
          if (Number.isNaN(end)) return;
          if (end - now > target * 2) end = now + target;
        }
        timer.setAttribute('data-end-fixed', String(end));
      }
      const diff = end - now;
      if (diff <= 0) {
        timer.textContent = 'Encerrado';
        timer.style.color = '#ef4444';
        timer.closest('[style]')?.querySelectorAll('.auction-propose-btn').forEach(btn => {
          btn.disabled = true;
        });
        return;
      }
      const totalSeconds = Math.floor(diff / 1000);
      const minutes = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
      const seconds = String(totalSeconds % 60).padStart(2, '0');
      timer.textContent = `${minutes}:${seconds}`;
      timer.style.color = diff < 300000 ? '#f59e0b' : '#22c55e';
    });
  };

  update();
  if (!window._leilaoTimerInterval) {
    window._leilaoTimerInterval = setInterval(update, 1000);
  }
}

// ── Manter compatibilidade com parseJsonSafe (usado em outros lugares) ────────
async function parseJsonSafe(response) {
  const text = await response.text();
  try { return JSON.parse(text); }
  catch (e) {
    const snippet = text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,200);
    throw new Error(snippet || 'Resposta inválida do servidor');
  }
}

// ── Auto-refresh da página ────────────────────────────────────────────────────

let _pageRefreshInterval = null;

function _startPageAutoRefresh() {
  clearInterval(_pageRefreshInterval);
  _pageRefreshInterval = setInterval(async () => {
    if (document.hidden) return;
    // Não atualiza se o modal de proposta (envio) estiver aberto
    const propostaModal = document.getElementById('modalProposta');
    if (propostaModal && propostaModal.classList.contains('show')) return;

    await carregarLeiloesAtivos();
    if (userTeamId) await carregarPropostasRecebidas();
    if (isAdmin) await carregarLeiloesAdmin();
  }, 30000);
}

// ── Init ──────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
  carregarLeiloesAtivos();
  if (userTeamId) carregarPropostasRecebidas();
  carregarHistoricoLeiloes();
  if (isAdmin) {
    carregarLeiloesAdmin();
    carregarPendentesCriados();
    setupAdminEvents();
  }
  _applyLeilaoTableLabels();
  _startPageAutoRefresh();
});
