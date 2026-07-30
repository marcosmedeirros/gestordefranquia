// Draft de Lendas — draft de 1 jogador por GM na ordem da Roleta dos 32,
// seguido da customização de badges com orçamento de tokens.

const _ldEsc = s => (s == null ? '' : String(s))
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const LD_LOGO_PADRAO = '/img/default-team.png';
const LD_TIER_LABEL = { bronze: 'B', silver: 'P', gold: 'O', hof: 'H', legend: 'L' };
const LD_TIER_NOME = { bronze: 'Bronze', silver: 'Prata', gold: 'Ouro', hof: 'HOF', legend: 'Legend' };

let ldEstado = null;
let ldMinhasBadgesEdit = {}; // badge_key -> tier (rascunho local antes de salvar)

async function _ldFetch(url, options = {}) {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...options });
  const txt = await res.text();
  let data;
  try { data = JSON.parse(txt); } catch (e) { throw new Error('Resposta inválida do servidor'); }
  if (!data.success) throw new Error(data.error || 'Erro desconhecido');
  return data;
}

async function ldCarregar() {
  try {
    const data = await _ldFetch('/api/legends-draft.php');
    ldEstado = data;
    ldMinhasBadgesEdit = { ...(data.minhas_badges || {}) };
    ldRenderTudo();
  } catch (e) {
    document.getElementById('ldContent').innerHTML = `<div class="ld-vazio"><i class="bi bi-exclamation-triangle"></i><p>${_ldEsc(e.message)}</p></div>`;
  }
}

function ldRenderTudo() {
  const box = document.getElementById('ldContent');
  const d = ldEstado;

  if (!d.total) {
    box.innerHTML = `<div class="ld-vazio"><i class="bi bi-hourglass-split"></i><p>O Draft de Lendas libera assim que a <a href="/roleta-times.php" style="color:var(--red)">Roleta dos 32</a> terminar.</p></div>`;
    return;
  }

  const picked = d.picks.filter(p => p.player_name).length;
  const pct = Math.round((picked / d.total) * 100);
  const vez = d.picks.find(p => p.pick_number === d.vez_pick_number);
  const ehMinhaVez = vez && Number(vez.user_id) === Number(window.SESSION_USER_ID);

  let html = `
    ${ldRegrasHtml()}
    <div class="ld-progress"><div style="width:${pct}%"></div></div>`;

  if (vez) {
    html += `
    <div class="ld-turno">
      <div class="ld-turno-label">Escolha ${vez.pick_number} de ${d.total}</div>
      <div class="ld-turno-gm">${_ldEsc(vez.gm_name)}${ehMinhaVez ? ' — é a sua vez!' : ''}</div>
      ${ehMinhaVez ? `
      <div class="ld-form-row">
        <input type="text" id="ldNomeJogador" placeholder="Nome do jogador (lenda)" maxlength="150">
        <select id="ldPosicaoJogador">
          <option value="PG">PG</option>
          <option value="SG">SG</option>
          <option value="SF">SF</option>
          <option value="PF">PF</option>
          <option value="C">C</option>
        </select>
        <button type="button" class="btn-orange" id="btnLdEscolher">Confirmar escolha</button>
      </div>` : `<div style="font-size:12px;color:var(--text-2);margin-top:8px">Aguardando ${_ldEsc(vez.gm_name)} escolher...</div>`}
    </div>`;
  } else if (d.draft_completo) {
    html += `<div class="ld-turno" style="border-color:color-mix(in srgb, var(--green) 35%, transparent);background:linear-gradient(135deg,color-mix(in srgb, var(--green) 14%, transparent),transparent)">
      <div class="ld-turno-label" style="color:var(--green)"><i class="bi bi-check-circle-fill"></i> Draft concluído</div>
      <div class="ld-turno-gm">Os ${d.total} jogadores já foram escolhidos.</div>
    </div>`;
  }

  html += `<div class="card"><div class="card-head"><div class="card-head-left"><i class="bi bi-list-ol"></i><span>Quadro do Draft</span></div></div>
    <div class="card-body"><div class="ld-board">${d.picks.map(p => ldLinhaBoard(p, d)).join('')}</div></div>
  </div>`;

  if (d.draft_completo && d.meu_pick) {
    html += ldBadgesHtml(d);
  } else if (d.draft_completo && !d.meu_pick) {
    html += `<div class="card"><div class="card-body"><div class="ld-vazio" style="padding:16px"><i class="bi bi-info-circle"></i><p>Você não tem um jogador neste draft (sua conta não está entre os 32 participantes da roleta).</p></div></div></div>`;
  }

  box.innerHTML = html;

  const btnEscolher = document.getElementById('btnLdEscolher');
  if (btnEscolher) btnEscolher.addEventListener('click', ldEscolher);

  if (d.draft_completo && d.meu_pick) ldWireBadges();
}

function ldRegrasHtml() {
  return `
  <div class="card">
    <div class="card-head"><div class="card-head-left"><i class="bi bi-journal-text"></i><span>Regras do Draft de Lendas</span></div></div>
    <div class="card-body ld-regras">
      <b>Elegibilidade (confira antes de digitar):</b> só jogadores que estão em algum time All-Time do NBA 2K26, e só quem foi draftado até 2010 (draft de 2011 em diante não vale).
      <ul>
        <li>Todo jogador entra com o mesmo OVR e a mesma idade (19 anos / Rookie) e 0 badges — o sistema já salva assim.</li>
        <li>Tendências fixas: 99 de Toque (Touch) e 99 de Arremesso (Shot) pra todo mundo.</li>
        <li>Cada GM tem <b>30 tokens</b> pra gastar em badges no próprio jogador, depois que o draft terminar (tabela de custo abaixo, na seção de badges).</li>
        <li>Se o GM não configurar as badges pela plataforma, o admin aplica manualmente a distribuição padrão da cartinha do jogador no 2kratings.com.</li>
      </ul>
    </div>
  </div>`;
}

function ldLinhaBoard(p, d) {
  const ehVez = p.pick_number === d.vez_pick_number;
  const ehMeu = Number(p.user_id) === Number(window.SESSION_USER_ID);
  const classe = ehVez ? 'atual' : (ehMeu ? 'eu' : '');
  const jogador = p.player_name
    ? `<b>${_ldEsc(p.player_name)}</b> · ${_ldEsc(p.player_position)} · OVR ${p.ovr} · ${p.age} anos`
    : (ehVez ? 'escolhendo agora...' : 'aguardando...');
  return `
    <div class="ld-row ${classe}">
      <div class="ld-row-pick">${p.pick_number}</div>
      <img src="${_ldEsc(p.photo_url || LD_LOGO_PADRAO)}" alt="" onerror="this.src='${LD_LOGO_PADRAO}'">
      <div class="ld-row-gm">${_ldEsc(p.gm_name)}</div>
      <div class="ld-row-jogador">${jogador}</div>
    </div>`;
}

async function ldEscolher() {
  const nome = document.getElementById('ldNomeJogador').value.trim();
  const pos = document.getElementById('ldPosicaoJogador').value;
  if (!nome) { alert('Digite o nome do jogador.'); return; }
  const btn = document.getElementById('btnLdEscolher');
  btn.disabled = true;
  try {
    const data = await _ldFetch('/api/legends-draft.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'escolher', player_name: nome, player_position: pos }),
    });
    ldEstado = data;
    ldMinhasBadgesEdit = { ...(data.minhas_badges || {}) };
    ldRenderTudo();
  } catch (e) {
    alert(e.message);
    btn.disabled = false;
  }
}

function ldTokensUsados() {
  return Object.values(ldMinhasBadgesEdit).reduce((soma, tier) => soma + (ldEstado.tier_custo[tier] || 0), 0);
}

function ldBadgesHtml(d) {
  const porCategoria = {};
  d.catalogo_badges.forEach(b => {
    (porCategoria[b.categoria] = porCategoria[b.categoria] || []).push(b);
  });

  const usados = ldTokensUsados();
  const restantes = d.tokens_orcamento - usados;

  let html = `
  <div class="ld-tokens-bar">
    <div><div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px">Tokens de Badge — ${_ldEsc(d.meu_pick.player_name)}</div>
    <div class="ld-tokens-num ${restantes < 0 ? 'over' : ''}" id="ldTokensRestantes">${restantes}/${d.tokens_orcamento} restantes</div></div>
    <button type="button" class="btn-orange" id="btnLdSalvarBadges">Salvar badges</button>
  </div>
  <div class="card"><div class="card-body">`;

  Object.entries(porCategoria).forEach(([cat, badges]) => {
    html += `<div class="ld-badges-cat"><div class="ld-badges-cat-title">${_ldEsc(cat)}</div>`;
    badges.forEach(b => {
      const tierAtual = ldMinhasBadgesEdit[b.key] || null;
      html += `<div class="ld-badge-row">
        <span class="ld-badge-nome">${_ldEsc(b.label)}</span>
        <div class="ld-tier-picker" data-badge="${b.key}">
          ${Object.keys(LD_TIER_LABEL).map(t => `<button type="button" class="ld-tier-btn ${tierAtual === t ? 'on' : ''}" data-tier="${t}" title="${LD_TIER_NOME[t]} (${d.tier_custo[t]} tokens)">${LD_TIER_LABEL[t]}</button>`).join('')}
          <button type="button" class="ld-tier-btn" data-tier="" title="Nenhuma">–</button>
        </div>
      </div>`;
    });
    html += `</div>`;
  });

  html += `</div></div>`;
  return html;
}

function ldAtualizarBarraTokens() {
  const usados = ldTokensUsados();
  const restantes = ldEstado.tokens_orcamento - usados;
  const el = document.getElementById('ldTokensRestantes');
  if (el) {
    el.textContent = `${restantes}/${ldEstado.tokens_orcamento} restantes`;
    el.classList.toggle('over', restantes < 0);
  }
}

function ldWireBadges() {
  document.querySelectorAll('.ld-tier-picker').forEach(picker => {
    const badgeKey = picker.dataset.badge;
    picker.querySelectorAll('.ld-tier-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const tier = btn.dataset.tier;
        if (tier) ldMinhasBadgesEdit[badgeKey] = tier;
        else delete ldMinhasBadgesEdit[badgeKey];
        picker.querySelectorAll('.ld-tier-btn').forEach(b => b.classList.toggle('on', b.dataset.tier === tier && tier !== ''));
        ldAtualizarBarraTokens();
      });
    });
  });

  const btnSalvar = document.getElementById('btnLdSalvarBadges');
  if (btnSalvar) btnSalvar.addEventListener('click', ldSalvarBadges);
}

async function ldSalvarBadges() {
  const badges = Object.entries(ldMinhasBadgesEdit).map(([badge_key, tier]) => ({ badge_key, tier }));
  const btn = document.getElementById('btnLdSalvarBadges');
  btn.disabled = true;
  try {
    const data = await _ldFetch('/api/legends-draft.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'salvar_badges', badges }),
    });
    ldEstado = data;
    ldMinhasBadgesEdit = { ...(data.minhas_badges || {}) };
    ldRenderTudo();
  } catch (e) {
    alert(e.message);
    btn.disabled = false;
  }
}

document.addEventListener('DOMContentLoaded', ldCarregar);
