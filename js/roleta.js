// Hub de Roletas — lista as roletas criadas (mais um card fixo pra Roleta
// dos 32 Times, que é uma página separada) e o popup de criar uma nova.

const TIPO_META = {
  gms: { icon: 'bi-person-badge-fill', color: '#3b82f6', bg: 'rgba(59,130,246,.12)', label: 'GMs' },
  times: { icon: 'bi-shield-fill', color: '#22c55e', bg: 'rgba(34,197,94,.12)', label: 'Times' },
  personalizado: { icon: 'bi-list-stars', color: '#a855f7', bg: 'rgba(168,85,247,.12)', label: 'Personalizado' },
};

let rlTipoAtual = 'gms';
let rlSelecionados = []; // { team_id?, user_id?, nome_display?, label }
let rlBuscaTimeout = null;
let rlModalNovaRoleta = null;

function escapeHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function rlCarregarHub() {
  const grid = document.getElementById('roletaGrid');
  try {
    const res = await fetch('/api/roleta.php?action=listar');
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Erro ao carregar');

    const cards = [];

    cards.push(`
      <div class="roleta-card" onclick="window.location.href='/roleta-times.php'">
        <div class="roleta-card-icon" style="background:rgba(236,72,153,.12);color:#ec4899"><i class="bi bi-dice-5-fill"></i></div>
        <div class="roleta-card-title">Roleta dos 32 Times</div>
        <div class="roleta-card-sub">Página pública · sorteio de ordem inversa</div>
      </div>`);

    data.roletas.forEach(r => {
      const meta = TIPO_META[r.tipo] || TIPO_META.gms;
      const pct = r.total ? Math.round((r.sorteados / r.total) * 100) : 0;
      let statusLabel = 'Não iniciada', statusColor = 'var(--text-3)', statusBg = 'var(--panel-3)';
      if (r.concluido) { statusLabel = 'Concluída'; statusColor = '#22c55e'; statusBg = 'rgba(34,197,94,.12)'; }
      else if (r.bloqueada) { statusLabel = 'Em andamento'; statusColor = '#f59e0b'; statusBg = 'rgba(245,158,11,.12)'; }

      cards.push(`
      <div class="roleta-card" onclick="window.location.href='/roleta-editar.php?id=${r.id}'">
        <div class="roleta-card-icon" style="background:${meta.bg};color:${meta.color}"><i class="bi ${meta.icon}"></i></div>
        <div class="roleta-card-title">${escapeHtml(r.titulo)}</div>
        <div class="roleta-card-sub">${r.league ? escapeHtml(r.league) + ' · ' : ''}${meta.label} · ${r.sorteados}/${r.total} sorteados${r.notificar_saida ? ' · <i class="bi bi-bell-fill"></i>' : ''}</div>
        <div class="roleta-card-progress"><div style="width:${pct}%"></div></div>
        <span class="roleta-card-status" style="color:${statusColor};background:${statusBg}">${statusLabel}</span>
      </div>`);
    });

    cards.push(`
      <button type="button" class="roleta-card roleta-card-new" id="btnNovaRoletaCard">
        <i class="bi bi-plus-circle"></i>
        <span style="font-size:13px;font-weight:700">Criar nova roleta</span>
      </button>`);

    grid.innerHTML = cards.join('');
    const btnCard = document.getElementById('btnNovaRoletaCard');
    if (btnCard) btnCard.addEventListener('click', rlAbrirModalNovaRoleta);
  } catch (e) {
    grid.innerHTML = `<div class="empty"><i class="bi bi-exclamation-triangle"></i><p>Erro ao carregar roletas: ${escapeHtml(e.message || e)}</p></div>`;
  }
}

function rlAbrirModalNovaRoleta() {
  rlTipoAtual = 'gms';
  rlSelecionados = [];
  document.getElementById('rlTitulo').value = '';
  document.getElementById('rlBusca').value = '';
  document.getElementById('rlNomeLivre').value = '';
  document.getElementById('rlNotificar').checked = true;
  document.querySelectorAll('.rl-tipo-tab').forEach(t => t.classList.toggle('active', t.dataset.tipo === 'gms'));
  document.getElementById('rlBuscaWrap').style.display = '';
  document.getElementById('rlPersonalizadoWrap').style.display = 'none';
  rlRenderChips();
  rlAtualizarLabelAddTodos();
  rlAtualizarVisibilidadeSemTime();
  rlModalNovaRoleta.show();
}

function rlRenderChips() {
  const wrap = document.getElementById('rlChips');
  if (!rlSelecionados.length) { wrap.innerHTML = '<span style="font-size:12px;color:var(--text-3)">Nenhum participante adicionado ainda.</span>'; return; }
  wrap.innerHTML = rlSelecionados.map((p, i) => `
    <span class="rl-chip">${escapeHtml(p.label)}<button type="button" onclick="rlRemoverSelecionado(${i})"><i class="bi bi-x"></i></button></span>
  `).join('');
}

function rlRemoverSelecionado(i) {
  rlSelecionados.splice(i, 1);
  rlRenderChips();
}

async function rlBuscarParticipantes(q) {
  const box = document.getElementById('rlBuscaResultados');
  if (q.length < 2) { box.classList.remove('show'); box.innerHTML = ''; return; }
  try {
    const excluir = rlSelecionados.filter(p => p.team_id).map(p => p.team_id).join(',');
    const res = await fetch(`/api/roleta.php?action=buscar_participantes&q=${encodeURIComponent(q)}&excluir_team_ids=${encodeURIComponent(excluir)}`);
    const data = await res.json();
    if (!data.success || !data.resultados.length) {
      box.innerHTML = '<div class="rl-ac-empty">Nenhum resultado.</div>';
      box.classList.add('show');
      return;
    }
    box.innerHTML = data.resultados.map((r, i) => {
      const principal = rlTipoAtual === 'times' ? r.time_label : r.gm_label;
      const secundario = rlTipoAtual === 'times' ? r.gm_label : r.time_label;
      const foto = r.photo_url || '';
      return `<div class="rl-ac-item" data-idx="${i}">
        ${foto ? `<img src="${escapeHtml(foto)}" onerror="this.style.display='none'">` : ''}
        <span>${escapeHtml(principal)} <span style="color:var(--text-3);font-weight:400">— ${escapeHtml(secundario)} · ${escapeHtml(r.league)}</span></span>
      </div>`;
    }).join('');
    box.classList.add('show');
    box.querySelectorAll('.rl-ac-item').forEach(el => {
      el.addEventListener('click', () => rlSelecionarParticipante(data.resultados[Number(el.dataset.idx)]));
    });
  } catch (e) {
    box.innerHTML = '<div class="rl-ac-empty">Erro na busca.</div>';
    box.classList.add('show');
  }
}

function rlSelecionarParticipante(r) {
  if (rlSelecionados.some(p => p.team_id === r.team_id)) return;
  const label = rlTipoAtual === 'times' ? r.time_label : r.gm_label;
  rlSelecionados.push({ team_id: r.team_id, user_id: r.user_id, time_label: r.time_label, gm_label: r.gm_label, label });
  rlRenderChips();
  document.getElementById('rlBusca').value = '';
  document.getElementById('rlBuscaResultados').classList.remove('show');
}

function rlAdicionarNomeLivre() {
  const input = document.getElementById('rlNomeLivre');
  const nome = input.value.trim();
  if (!nome) return;
  rlSelecionados.push({ nome_display: nome, label: nome });
  input.value = '';
  rlRenderChips();
}

/** Cola vários nomes de uma vez (um por linha) — cada linha vira um participante. */
function rlAdicionarNomesEmLote(texto) {
  const linhas = texto.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  linhas.forEach(nome => rlSelecionados.push({ nome_display: nome, label: nome }));
  if (linhas.length) rlRenderChips();
}

function rlAtualizarLabelAddTodos() {
  const label = document.getElementById('rlAddTodosLabel');
  if (label) label.textContent = rlTipoAtual === 'times' ? 'Adicionar todos os times da liga' : 'Adicionar todos os GMs da liga';
}

/** Só faz sentido pedir "quem tá sem time" quando a liga é ROOKIE e o tipo é GMs. */
function rlAtualizarVisibilidadeSemTime() {
  const liga = document.getElementById('rlLiga')?.value || '';
  const btn = document.getElementById('btnRlAddSemTime');
  if (btn) btn.style.display = (liga === 'ROOKIE' && rlTipoAtual === 'gms') ? '' : 'none';
}

async function rlAdicionarSemTimeRookie() {
  const btn = document.getElementById('btnRlAddSemTime');
  btn.disabled = true;
  try {
    const res = await fetch('/api/roleta.php?action=usuarios_sem_time&liga=ROOKIE');
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Erro ao buscar quem está sem time.');
    if (!data.resultados.length) { alert('Ninguém cadastrado na ROOKIE está sem time no momento.'); return; }

    data.resultados.forEach(r => {
      if (rlSelecionados.some(p => p.user_id === r.user_id)) return;
      rlSelecionados.push({ team_id: null, user_id: r.user_id, gm_label: r.gm_label, label: r.gm_label });
    });
    rlRenderChips();
  } catch (e) {
    alert(e.message || 'Erro ao adicionar quem está sem time.');
  } finally {
    btn.disabled = false;
  }
}

async function rlAdicionarTodosDaLiga() {
  const liga = document.getElementById('rlLiga')?.value || '';
  if (!liga) { alert('Escolha a liga primeiro.'); return; }

  const btn = document.getElementById('btnRlAddTodos');
  btn.disabled = true;
  try {
    const res = await fetch(`/api/roleta.php?action=times_da_liga&liga=${encodeURIComponent(liga)}`);
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Erro ao buscar os times da liga.');
    if (!data.resultados.length) { alert('Nenhum time encontrado nessa liga.'); return; }

    data.resultados.forEach(r => {
      if (rlSelecionados.some(p => p.team_id === r.team_id)) return;
      const label = rlTipoAtual === 'times' ? r.time_label : r.gm_label;
      rlSelecionados.push({ team_id: r.team_id, user_id: r.user_id, time_label: r.time_label, gm_label: r.gm_label, label });
    });
    rlRenderChips();
  } catch (e) {
    alert(e.message || 'Erro ao adicionar os participantes da liga.');
  } finally {
    btn.disabled = false;
  }
}

async function rlCriarRoleta() {
  const titulo = document.getElementById('rlTitulo').value.trim();
  const notificar = document.getElementById('rlNotificar').checked;
  const liga = document.getElementById('rlLiga')?.value || '';
  if (!titulo) { alert('Digite um título pra roleta.'); return; }
  if (!liga) { alert('Escolha a liga da roleta.'); return; }
  if (rlSelecionados.length < 2) { alert('Adicione pelo menos 2 participantes.'); return; }

  const btn = document.getElementById('btnCriarRoleta');
  btn.disabled = true;
  try {
    const res = await fetch('/api/roleta.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'criar',
        titulo,
        league: liga,
        tipo: rlTipoAtual,
        notificar_saida: notificar,
        participantes: rlSelecionados,
      }),
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Erro ao criar roleta');
    window.location.href = `/roleta-editar.php?id=${data.id}`;
  } catch (e) {
    alert(e.message || 'Erro ao criar roleta');
    btn.disabled = false;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  rlModalNovaRoleta = new bootstrap.Modal(document.getElementById('modalNovaRoleta'));
  rlCarregarHub();

  document.getElementById('btnNovaRoletaTop').addEventListener('click', rlAbrirModalNovaRoleta);

  document.querySelectorAll('.rl-tipo-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      rlTipoAtual = tab.dataset.tipo;
      document.querySelectorAll('.rl-tipo-tab').forEach(t => t.classList.toggle('active', t === tab));
      rlSelecionados = [];
      rlRenderChips();
      document.getElementById('rlBuscaWrap').style.display = rlTipoAtual === 'personalizado' ? 'none' : '';
      document.getElementById('rlPersonalizadoWrap').style.display = rlTipoAtual === 'personalizado' ? '' : 'none';
      rlAtualizarLabelAddTodos();
      rlAtualizarVisibilidadeSemTime();
    });
  });

  document.getElementById('btnRlAddTodos').addEventListener('click', rlAdicionarTodosDaLiga);
  document.getElementById('btnRlAddSemTime')?.addEventListener('click', rlAdicionarSemTimeRookie);
  document.getElementById('rlLiga')?.addEventListener('change', rlAtualizarVisibilidadeSemTime);

  const buscaInput = document.getElementById('rlBusca');
  buscaInput.addEventListener('input', () => {
    clearTimeout(rlBuscaTimeout);
    const q = buscaInput.value.trim();
    rlBuscaTimeout = setTimeout(() => rlBuscarParticipantes(q), 250);
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.rl-autocomplete')) document.getElementById('rlBuscaResultados').classList.remove('show');
  });

  document.getElementById('btnAddNomeLivre').addEventListener('click', rlAdicionarNomeLivre);
  document.getElementById('rlNomeLivre').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); rlAdicionarNomeLivre(); }
  });
  document.getElementById('rlNomeLivre').addEventListener('paste', (e) => {
    const texto = (e.clipboardData || window.clipboardData).getData('text');
    if (texto && texto.includes('\n')) {
      e.preventDefault();
      rlAdicionarNomesEmLote(texto);
    }
  });

  document.getElementById('btnCriarRoleta').addEventListener('click', rlCriarRoleta);
});
