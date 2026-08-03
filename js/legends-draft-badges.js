// Resumo admin das badges do Draft de Lendas — só leitura.

const _lbEsc = s => (s == null ? '' : String(s))
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

let _lbPicks = [];

async function lbCarregar() {
  const box = document.getElementById('lbBody');
  try {
    const res = await fetch('/api/legends-draft.php?action=admin_resumo', { headers: { 'Content-Type': 'application/json' } });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Erro desconhecido');

    _lbPicks = data.picks || [];
    lbRenderTudo(data);
  } catch (e) {
    box.innerHTML = `<div class="lb-vazio"><i class="bi bi-exclamation-triangle"></i><p>${_lbEsc(e.message)}</p></div>`;
  }
}

function lbRenderTudo(data) {
  const box = document.getElementById('lbBody');

  if (!_lbPicks.length) {
    box.innerHTML = `<div class="lb-vazio"><i class="bi bi-hourglass-split"></i><p>O Draft de Lendas ainda não tem participantes.</p></div>`;
    return;
  }

  const comBadges = _lbPicks.filter(p => (p.badges || []).length > 0).length;
  const semBadges = _lbPicks.filter(p => p.player_name && !p.skipped && (p.badges || []).length === 0).length;
  const totalTokens = _lbPicks.reduce((s, p) => s + (p.tokens_usados || 0), 0);

  let html = `
    <div class="lb-stats">
      <div class="lb-stat"><div class="lb-stat-val">${_lbPicks.length}</div><div class="lb-stat-label">Picks no total</div></div>
      <div class="lb-stat"><div class="lb-stat-val">${comBadges}</div><div class="lb-stat-label">Com badges configuradas</div></div>
      <div class="lb-stat"><div class="lb-stat-val">${semBadges}</div><div class="lb-stat-label">Sem nenhuma badge</div></div>
      <div class="lb-stat"><div class="lb-stat-val">${totalTokens}</div><div class="lb-stat-label">Tokens usados no total</div></div>
    </div>`;

  if (!data.finalizado) {
    html += `<div class="lb-banner"><i class="bi bi-info-circle-fill"></i><span>O Draft de Lendas ainda não foi finalizado pelo admin — as escolhas e badges abaixo podem mudar até lá.</span></div>`;
  }
  if (semBadges > 0) {
    html += `<div class="lb-banner"><i class="bi bi-exclamation-triangle-fill"></i><span><strong>${semBadges} jogador${semBadges > 1 ? 'es' : ''}</strong> ainda ${semBadges > 1 ? 'estão' : 'está'} sem nenhuma badge configurada pelo GM — aplique a distribuição padrão da cartinha do jogador no 2kratings.com pra ${semBadges > 1 ? 'eles' : 'ele'}.</span></div>`;
  }

  html += `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
    <button type="button" class="btn-ghost" id="btnLbCopiarEscolhas"><i class="bi bi-clipboard-check me-1"></i>Copiar todas as escolhas</button>
    <button type="button" class="btn-ghost" id="btnLbCopiarBadges"><i class="bi bi-clipboard-data me-1"></i>Copiar escolhas + badges</button>
  </div>`;
  html += `<input type="text" id="lbSearch" class="lb-search" placeholder="Buscar por GM, jogador ou time...">`;
  html += `<div id="lbList"></div>`;

  box.innerHTML = html;
  lbRenderLista(_lbPicks);
  document.getElementById('btnLbCopiarEscolhas').addEventListener('click', ev => lbCopiar(ev, false));
  document.getElementById('btnLbCopiarBadges').addEventListener('click', ev => lbCopiar(ev, true));
  document.getElementById('lbSearch').addEventListener('input', e => {
    const q = e.target.value.trim().toLowerCase();
    const filtrado = !q ? _lbPicks : _lbPicks.filter(p =>
      (p.gm_name || '').toLowerCase().includes(q) ||
      (p.player_name || '').toLowerCase().includes(q) ||
      (p.team_name || '').toLowerCase().includes(q));
    lbRenderLista(filtrado);
  });
}

/**
 * Copia o quadro do draft no formato de mandar no grupo.
 * Com $comBadges, cada linha ganha as badges configuradas pelo GM embaixo —
 * é o que serve pra conferir quem ainda não montou o jogador.
 */
function lbCopiar(ev, comBadges) {
  const feitas = _lbPicks.filter(p => p.player_name);
  if (!feitas.length) { alert('Ainda não tem nenhuma escolha pra copiar.'); return; }

  const linhas = feitas.map(p => {
    const base = `${p.pick_number} - ${p.gm_name} - ${p.player_name}`;
    if (!comBadges) return base;
    const badges = p.badges || [];
    return badges.length
      ? `${base}\n   ${badges.map(b => `${b.label} (${b.tier})`).join(', ')}`
      : `${base}\n   (sem badges)`;
  });

  const texto = 'Draft de Lendas — resultado\n\n' + linhas.join('\n');
  const btn = ev.currentTarget;
  const original = btn.innerHTML;
  navigator.clipboard.writeText(texto).then(() => {
    btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copiado!';
    setTimeout(() => { btn.innerHTML = original; }, 1600);
  }).catch(() => prompt('Copie o texto abaixo:', texto));
}

function lbRenderLista(picks) {
  const list = document.getElementById('lbList');
  if (!list) return;
  if (!picks.length) {
    list.innerHTML = `<div class="lb-vazio"><i class="bi bi-search"></i><p>Nada encontrado.</p></div>`;
    return;
  }
  list.innerHTML = picks.map(lbCard).join('');
}

function lbCard(p) {
  const badges = p.badges || [];
  const semNada = p.player_name && !p.skipped && badges.length === 0;
  const foto = p.photo_url
    ? `<img class="lb-photo" src="${_lbEsc(p.photo_url)}" onerror="this.style.display='none'" alt="">`
    : '';

  let jogadorHtml;
  if (p.skipped) {
    jogadorHtml = `<span style="font-style:italic;color:var(--text-3)"><i class="bi bi-skip-forward-fill"></i> Escolha pulada</span>`;
  } else if (p.player_name) {
    jogadorHtml = `<b>${_lbEsc(p.player_name)}</b> <span style="color:var(--text-3)">${_lbEsc(p.player_position || '')} · OVR ${p.ovr ?? '-'} · ${p.age ?? '-'}a</span>`;
  } else {
    jogadorHtml = `<span style="color:var(--text-3)">Ainda não escolheu</span>`;
  }

  const tokensHtml = (p.player_name && !p.skipped)
    ? `<span class="lb-tokens${semNada ? ' zero' : ''}">${p.tokens_usados || 0} tokens</span>`
    : '';

  const badgesHtml = badges.length
    ? `<div class="lb-badges">${badges.map(b => `<span class="lb-badge" data-tier="${_lbEsc(b.tier)}">${_lbEsc(b.label)}<span class="lb-badge-cat">${_lbEsc(b.tier)}</span></span>`).join('')}</div>`
    : (semNada ? `<div class="lb-empty-badges"><i class="bi bi-exclamation-circle"></i> Nenhuma badge configurada ainda</div>` : '');

  return `
    <div class="lb-card${semNada ? ' vazio' : ''}">
      <div class="lb-head">
        <div class="lb-pick">${p.pick_number}</div>
        ${foto}
        <div>
          <div class="lb-gm">${_lbEsc(p.gm_name)}</div>
          <div class="lb-team">${_lbEsc(p.team_name || '')}</div>
        </div>
        <div class="lb-jogador">${jogadorHtml}</div>
        ${tokensHtml}
      </div>
      ${badgesHtml}
    </div>`;
}

document.addEventListener('DOMContentLoaded', lbCarregar);
