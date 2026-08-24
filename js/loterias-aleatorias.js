// Central das Loterias Aleatórias — lista as loterias da liga e monta uma nova
// escolhendo participantes e a chance (%) de cada um.

const _ltEsc = s => (s == null ? '' : String(s))
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

let ltTipoAtual = 'times';
let ltSelecionados = [];   // { team_id, user_id, nome_display, label, chance }
let ltBuscaTimeout = null;

async function _ltFetch(url, options = {}) {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...options });
  const txt = await res.text();
  let data;
  try { data = JSON.parse(txt); } catch (e) { throw new Error('Resposta inválida do servidor'); }
  if (!data.success) throw new Error(data.error || 'Erro desconhecido');
  return data;
}

// ── Listagem ────────────────────────────────────────────────────────────────
async function ltCarregar() {
  const grid = document.getElementById('loteriaGrid');
  try {
    const data = await _ltFetch('/api/loteria-aleatoria.php?action=listar');
    const cards = data.loterias.map(l => {
      const pct = l.total ? Math.round((l.sorteados / l.total) * 100) : 0;
      let label = 'Não sorteada', cor = 'var(--text-3)', bg = 'var(--panel-3)';
      if (l.concluido)      { label = 'Concluída';    cor = '#22c55e'; bg = 'rgba(34,197,94,.12)'; }
      else if (l.sorteados) { label = 'Em andamento'; cor = '#f59e0b'; bg = 'rgba(245,158,11,.12)'; }

      const tipo = { gms: 'GMs', times: 'Times', personalizado: 'Personalizado' }[l.tipo] || l.tipo;
      return `
      <div class="roleta-card" onclick="window.location.href='/loteria-aleatoria.php?id=${l.id}'">
        <div class="roleta-card-icon" style="background:rgba(245,158,11,.12);color:#f59e0b"><i class="bi bi-dice-3-fill"></i></div>
        <div class="roleta-card-title">${_ltEsc(l.titulo)}</div>
        <div class="roleta-card-sub">${l.league ? _ltEsc(l.league) + ' · ' : ''}${tipo} · ${l.sorteados}/${l.total} sorteados</div>
        <div class="roleta-card-progress"><div style="width:${pct}%"></div></div>
        <span class="roleta-card-status" style="color:${cor};background:${bg}">${label}</span>
      </div>`;
    });

    cards.push(`
      <button type="button" class="roleta-card roleta-card-new" id="btnNovaLoteriaCard">
        <i class="bi bi-plus-circle"></i>
        <span style="font-size:13px;font-weight:700">Nova loteria</span>
      </button>`);

    grid.innerHTML = cards.join('');
    document.getElementById('btnNovaLoteriaCard').addEventListener('click', ltAbrirModal);
  } catch (e) {
    grid.innerHTML = `<div class="empty"><i class="bi bi-exclamation-triangle"></i><p>Erro ao carregar: ${_ltEsc(e.message)}</p></div>`;
  }
}

// ── Modal de criação ────────────────────────────────────────────────────────
function ltAbrirModal() {
  ltSelecionados = [];
  document.getElementById('ltTitulo').value = '';
  ltRenderLista();
  new bootstrap.Modal(document.getElementById('modalNovaLoteria')).show();
}

function ltTrocarTipo(tipo) {
  ltTipoAtual = tipo;
  ltSelecionados = [];
  ltRenderLista();
  document.querySelectorAll('.rl-tipo-tab').forEach(t => t.classList.toggle('active', t.dataset.tipo === tipo));
  document.getElementById('ltBuscaWrap').style.display = tipo === 'personalizado' ? 'none' : '';
  document.getElementById('ltPersonalizadoWrap').style.display = tipo === 'personalizado' ? '' : 'none';
}

/**
 * A lista mostra a % de cada um e a soma. A soma não precisa fechar em 100 —
 * o servidor normaliza pelos pesos —, mas fora de 100 é quase sempre engano de
 * digitação, então o aviso aparece.
 */
function ltRenderLista() {
  const wrap = document.getElementById('ltLista');
  const soma = document.getElementById('ltSoma');

  if (!ltSelecionados.length) {
    wrap.innerHTML = '';
    soma.className = 'lot-soma';
    soma.innerHTML = '<small>Adicione os participantes e defina a chance de cada um.</small>';
    return;
  }

  wrap.innerHTML = ltSelecionados.map((p, i) => `
    <div class="lot-row">
      <span class="lot-row-nome">${_ltEsc(p.label)}</span>
      <input type="number" min="0" max="100" step="0.1" value="${p.chance}" data-idx="${i}" aria-label="Chance de ${_ltEsc(p.label)}">
      <span class="lot-row-pct">%</span>
      <button type="button" data-remover="${i}" aria-label="Remover"><i class="bi bi-x-lg"></i></button>
    </div>`).join('');

  wrap.querySelectorAll('input[data-idx]').forEach(inp => {
    inp.addEventListener('input', () => {
      ltSelecionados[Number(inp.dataset.idx)].chance = Number(inp.value) || 0;
      ltAtualizarSoma();
    });
  });
  wrap.querySelectorAll('button[data-remover]').forEach(btn => {
    btn.addEventListener('click', () => {
      ltSelecionados.splice(Number(btn.dataset.remover), 1);
      ltRenderLista();
    });
  });

  ltAtualizarSoma();
}

function ltAtualizarSoma() {
  const soma = document.getElementById('ltSoma');
  const total = ltSelecionados.reduce((s, p) => s + (Number(p.chance) || 0), 0);
  const fechou = Math.abs(total - 100) < 0.011;
  soma.className = 'lot-soma ' + (fechou ? 'ok' : 'alerta');
  soma.innerHTML = fechou
    ? `<span><i class="bi bi-check-circle-fill"></i> Soma: ${total.toFixed(1)}%</span>`
    : `<span><i class="bi bi-exclamation-triangle-fill"></i> Soma: ${total.toFixed(1)}%</span>
       <small>Não precisa fechar em 100 — as chances são proporcionais entre si.</small>`;
}

async function ltBuscarParticipantes(q) {
  const box = document.getElementById('ltBuscaResultados');
  if (q.length < 2) { box.classList.remove('show'); return; }
  try {
    const jaEscolhidos = ltSelecionados.map(p => p.team_id).filter(Boolean).join(',');
    const data = await _ltFetch(`/api/loteria-aleatoria.php?action=buscar_participantes&q=${encodeURIComponent(q)}&excluir_team_ids=${jaEscolhidos}`);
    if (!data.resultados.length) {
      box.innerHTML = '<div class="rl-ac-empty">Nada encontrado.</div>';
      box.classList.add('show');
      return;
    }
    box.innerHTML = data.resultados.map(r => `
      <div class="rl-ac-item" data-team="${r.team_id}" data-user="${r.user_id}"
           data-time="${_ltEsc(r.time_label)}" data-gm="${_ltEsc(r.gm_label)}">
        <img src="${_ltEsc(r.photo_url || '/img/default-team.png')}" alt="" onerror="this.src='/img/default-team.png'">
        <div>
          <div style="font-weight:600">${_ltEsc(ltTipoAtual === 'times' ? r.time_label : r.gm_label)}</div>
          <div style="font-size:11px;color:var(--text-3)">${_ltEsc(r.league || '')} · ${_ltEsc(ltTipoAtual === 'times' ? r.gm_label : r.time_label)}</div>
        </div>
      </div>`).join('');
    box.classList.add('show');

    box.querySelectorAll('.rl-ac-item').forEach(item => {
      item.addEventListener('click', () => {
        const label = ltTipoAtual === 'times' ? item.dataset.time : item.dataset.gm;
        ltSelecionados.push({
          team_id: Number(item.dataset.team),
          user_id: Number(item.dataset.user),
          time_label: item.dataset.time,
          gm_label: item.dataset.gm,
          label,
          chance: 0,
        });
        document.getElementById('ltBusca').value = '';
        box.classList.remove('show');
        ltRenderLista();
      });
    });
  } catch (e) {
    box.innerHTML = `<div class="rl-ac-empty">${_ltEsc(e.message)}</div>`;
    box.classList.add('show');
  }
}

function ltAdicionarNomeLivre() {
  const input = document.getElementById('ltNomeLivre');
  const nome = input.value.trim();
  if (!nome) return;
  ltSelecionados.push({ nome_display: nome, label: nome, chance: 0 });
  input.value = '';
  ltRenderLista();
}

async function ltCriarLoteria() {
  const titulo = document.getElementById('ltTitulo').value.trim();
  const liga = document.getElementById('ltLiga')?.value || '';
  const notificar = document.getElementById('ltNotificar').checked;
  if (!titulo) { alert('Digite um título pra loteria.'); return; }
  if (!liga) { alert('Escolha a liga da loteria.'); return; }
  if (ltSelecionados.length < 2) { alert('Adicione pelo menos 2 participantes.'); return; }
  // A seta do every() NÃO pode ser async: função async devolve Promise, e
  // Promise é sempre verdadeira — o every() daria true até quando todo mundo
  // tem chance definida, e o aviso apareceria sempre. Quem é async aqui é a
  // ltCriarLoteria, lá em cima, que é onde o await do confirmarSite mora.
  if (ltSelecionados.every(p => !Number(p.chance))) {
    if (!await confirmarSite('Ninguém tem chance definida — o sorteio vai tratar todo mundo por igual. Criar assim mesmo?')) return;
  }

  const btn = document.getElementById('btnCriarLoteria');
  btn.disabled = true;
  try {
    const data = await _ltFetch('/api/loteria-aleatoria.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'criar',
        titulo,
        league: liga,
        tipo: ltTipoAtual,
        notificar_saida: notificar,
        participantes: ltSelecionados,
      }),
    });
    window.location.href = `/loteria-aleatoria.php?id=${data.id}`;
  } catch (e) {
    alert(e.message);
    btn.disabled = false;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  ltCarregar();
  document.getElementById('btnNovaLoteriaTop')?.addEventListener('click', ltAbrirModal);
  document.getElementById('btnCriarLoteria')?.addEventListener('click', ltCriarLoteria);
  document.getElementById('btnAddNomeLivreLt')?.addEventListener('click', ltAdicionarNomeLivre);
  document.getElementById('ltNomeLivre')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); ltAdicionarNomeLivre(); }
  });
  document.querySelectorAll('.rl-tipo-tab').forEach(tab => {
    tab.addEventListener('click', () => ltTrocarTipo(tab.dataset.tipo));
  });
  const busca = document.getElementById('ltBusca');
  busca?.addEventListener('input', () => {
    clearTimeout(ltBuscaTimeout);
    const q = busca.value.trim();
    ltBuscaTimeout = setTimeout(() => ltBuscarParticipantes(q), 250);
  });
  document.addEventListener('click', e => {
    if (!e.target.closest('.rl-autocomplete')) {
      document.querySelectorAll('.rl-autocomplete-results').forEach(el => el.classList.remove('show'));
    }
  });
});
