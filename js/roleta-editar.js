// Roleta específica (hub de roletas) — girar, ver quem saiu/quem ainda pode
// sair, e editar título/participantes/notificação até o 1º sorteio.

const _reEsc = s => (s == null ? '' : String(s))
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const RE_CORES = ['#fc0025', '#f59e0b', '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899',
                  '#06b6d4', '#eab308', '#f97316', '#14b8a6', '#a855f7', '#ef4444'];
const RE_LOGO_PADRAO = '/img/default-team.png';
const RE_SEM_MOVIMENTO = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let reUrnaRenderizada = [];
let reGirando = false;
let reEstadoAtual = null;
let reBuscaTimeout = null;
let reSelecionadosNovos = []; // participantes escolhidos no autocomplete, ainda não salvos

async function _reFetch(url, options = {}) {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...options });
  const txt = await res.text();
  let data;
  try { data = JSON.parse(txt); }
  catch (e) { throw new Error('Resposta inválida do servidor'); }
  if (!data.success) throw new Error(data.error || 'Erro desconhecido');
  return data;
}

function reNomeCurto(nome) {
  const s = String(nome || '').trim();
  const apelido = s.match(/\(([^)]+)\)/);
  if (apelido) return apelido[1].trim();
  return s.split(/\s+/)[0] || s;
}

async function reCarregar() {
  try {
    const data = await _reFetch(`/api/roleta.php?action=estado&id=${ROLETA_ID}`);
    reEstadoAtual = data;
    reRenderTudo(data);
  } catch (e) {
    document.getElementById('rtHistorico').innerHTML = `<p style="color:#ef4444;font-size:13px">${_reEsc(e.message)}</p>`;
  }
}

function reRenderTudo(data) {
  reUrnaRenderizada = data.na_urna || [];
  document.querySelector('#rlTituloHero span').textContent = data.titulo;
  document.title = `${data.titulo} — Editar Roleta`;
  reRenderRoda(reUrnaRenderizada);
  reRenderUrna(reUrnaRenderizada, data.bloqueada);
  reRenderHistorico(data.sorteados || []);
  reRenderResumo(data);
  reAtualizarBotaoGirar(data);
  reRenderConfig(data);
  document.getElementById('rlExcluir').disabled = data.bloqueada;
  document.getElementById('rlExcluir').title = data.bloqueada ? 'Já teve o 1º sorteio — não pode mais ser excluída.' : '';
}

function reRenderResumo(data) {
  const el = document.getElementById('rtResumo');
  const faltam = (data.na_urna || []).length;
  const saiu = (data.sorteados || []).length;
  let html = '';
  if (data.concluido) {
    html += `<span class="rt-chip ok"><i class="bi bi-check-circle-fill"></i>Sorteio concluído — ${data.total} definidos</span>`;
  } else {
    html += `<span class="rt-chip"><i class="bi bi-hourglass-split"></i>${faltam} na urna</span>
             <span class="rt-chip"><i class="bi bi-list-ol"></i>${saiu} já sorteados</span>
             ${faltam ? `<span class="rt-chip next">Próxima a sair: <b>escolha ${faltam}</b></span>` : ''}`;
  }
  if (data.bloqueada) html += `<span class="rt-chip lock"><i class="bi bi-lock-fill"></i>Título/participantes travados após o 1º sorteio</span>`;
  el.innerHTML = html;
}

function reRenderRoda(urna) {
  const roda = document.getElementById('rtRoda');
  roda.style.transition = 'none';
  roda.style.transform = 'rotate(0deg)';
  void roda.offsetWidth;
  roda.style.transition = '';

  if (!urna.length) {
    roda.innerHTML = `<div class="rt-roda-vazia">Todos já saíram.</div>`;
    return;
  }

  const n = urna.length;
  const ang = 360 / n;
  const stops = urna.map((t, i) => `${RE_CORES[i % RE_CORES.length]} ${i * ang}deg ${(i + 1) * ang}deg`).join(', ');
  let html = `<div class="rt-fatias" style="background:conic-gradient(from 0deg, ${stops})"></div>`;

  const fonte = n <= 8 ? 14 : n <= 16 ? 12 : n <= 24 ? 10 : 8.5;
  urna.forEach((t, i) => {
    const meio = i * ang + ang / 2;
    html += `<div class="rt-rotulo" style="transform:rotate(${meio - 90}deg)">
               <span style="font-size:${fonte}px">${_reEsc(reNomeCurto(t.nome_display))}</span>
             </div>`;
  });
  roda.innerHTML = html;
}

function reRenderUrna(urna, bloqueada) {
  const el = document.getElementById('rtUrna');
  if (!urna.length) {
    el.innerHTML = `<div class="rt-vazio"><i class="bi bi-inbox"></i><p>Urna vazia — todos já saíram.</p></div>`;
    return;
  }
  el.innerHTML = urna.map(t => `
    <div class="rt-urna-item">
      <img src="${_reEsc(t.photo_url || RE_LOGO_PADRAO)}" alt="" onerror="this.src='${RE_LOGO_PADRAO}'">
      <div class="rt-urna-txt"><div class="rt-urna-gm">${_reEsc(t.nome_display)}</div></div>
      ${bloqueada ? '' : `<button type="button" class="rt-urna-remove" data-id="${t.id}" title="Remover"><i class="bi bi-x-lg"></i></button>`}
    </div>`).join('');
  if (!bloqueada) {
    el.querySelectorAll('.rt-urna-remove').forEach(btn => {
      btn.addEventListener('click', () => reRemoverParticipante(Number(btn.dataset.id)));
    });
  }
}

function reRenderHistorico(sorteados) {
  const el = document.getElementById('rtHistorico');
  if (!sorteados.length) {
    el.innerHTML = `<div class="rt-vazio"><i class="bi bi-clock-history"></i><p>Nenhum participante sorteado ainda.</p></div>`;
    return;
  }
  el.innerHTML = sorteados.map((t, i) => {
    const quando = t.eliminated_at
      ? new Date(String(t.eliminated_at).replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
      : '';
    return `
      <div class="rt-hist-linha">
        <span class="rt-hist-saida">${i + 1}º a sair</span>
        <span class="rt-hist-pick">Escolha ${t.pick_number}</span>
        <img class="rt-hist-logo" src="${_reEsc(t.photo_url || RE_LOGO_PADRAO)}" alt="" onerror="this.src='${RE_LOGO_PADRAO}'">
        <span class="rt-hist-time">${_reEsc(t.nome_display)}</span>
        <span class="rt-hist-hora">${_reEsc(quando)}</span>
      </div>`;
  }).join('');
}

function reAtualizarBotaoGirar(data) {
  const btn = document.getElementById('rtGirar');
  const acabou = !!data.concluido;
  btn.disabled = reGirando || acabou;
  btn.innerHTML = acabou
    ? '<i class="bi bi-check2-all me-1"></i>Sorteio concluído'
    : `<i class="bi bi-arrow-repeat me-1"></i>Girar (define a escolha ${(data.na_urna || []).length})`;
}

async function reGirar() {
  if (reGirando || !reUrnaRenderizada.length) return;
  reGirando = true;
  document.getElementById('rtGirar').disabled = true;

  const urnaNoGiro = reUrnaRenderizada.slice();

  try {
    const data = await _reFetch('/api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'girar', id: ROLETA_ID }),
    });

    const idx = urnaNoGiro.findIndex(t => Number(t.id) === Number(data.sorteado_id));
    const roda = document.getElementById('rtRoda');
    if (roda && idx !== -1 && !RE_SEM_MOVIMENTO) {
      const n = urnaNoGiro.length;
      const ang = 360 / n;
      const meio = idx * ang + ang / 2;
      const voltas = 5;
      roda.style.transform = `rotate(${voltas * 360 + (360 - meio)}deg)`;
      await new Promise(r => setTimeout(r, 4300));
    }

    reAnunciar(data);
    reEstadoAtual = data;
    reRenderTudo(data);
  } catch (e) {
    alert(e.message);
  } finally {
    reGirando = false;
    if (reEstadoAtual) reAtualizarBotaoGirar(reEstadoAtual);
  }
}

function reAnunciar(data) {
  const el = document.getElementById('rtAnuncio');
  el.innerHTML = `
    <div class="rt-anuncio-box">
      <div class="rt-anuncio-pick">Escolha ${data.pick}</div>
      <div class="rt-anuncio-time">${_reEsc(data.nome_display)}</div>
    </div>`;
  el.classList.remove('mostrar');
  void el.offsetWidth;
  el.classList.add('mostrar');
}

async function reReiniciar() {
  if (reGirando) return;
  if (!confirm('Reiniciar o sorteio?\n\nTodas as escolhas definidas serão apagadas e todo mundo volta pra urna.')) return;
  try {
    const data = await _reFetch('/api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'reiniciar', id: ROLETA_ID }),
    });
    document.getElementById('rtAnuncio').innerHTML = '';
    reEstadoAtual = data;
    reRenderTudo(data);
  } catch (e) {
    alert(e.message);
  }
}

async function reRemoverParticipante(id) {
  try {
    const data = await _reFetch('/api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'editar', id: ROLETA_ID, remover_ids: [id] }),
    });
    reEstadoAtual = data;
    reRenderTudo(data);
  } catch (e) {
    alert(e.message);
  }
}

async function reExcluirRoleta() {
  if (!confirm('Excluir esta roleta permanentemente? Essa ação não pode ser desfeita.')) return;
  try {
    await _reFetch('/api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'excluir', id: ROLETA_ID }),
    });
    window.location.href = '/roleta.php';
  } catch (e) {
    alert(e.message);
  }
}

function reRenderConfig(data) {
  const box = document.getElementById('rlConfigBody');
  const tipoLabel = { gms: 'GMs', times: 'Times', personalizado: 'Personalizado' }[data.tipo] || data.tipo;

  if (data.bloqueada) {
    box.innerHTML = `
      <div class="edit-locked-msg"><i class="bi bi-lock-fill"></i>Esta roleta já teve o 1º sorteio — título, participantes e notificação não podem mais ser editados.</div>
      <div style="margin-top:12px;font-size:12px;color:var(--text-3)">Tipo: <b style="color:var(--text)">${tipoLabel}</b> · Notificação: <b style="color:var(--text)">${data.notificar_saida ? 'ativada' : 'desativada'}</b></div>`;
    return;
  }

  reSelecionadosNovos = [];
  box.innerHTML = `
    <div class="mb-2">
      <div style="font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:6px">Título</div>
      <div style="display:flex;gap:8px">
        <input type="text" id="rlEditTitulo" class="form-control" value="${_reEsc(data.titulo)}">
        <button type="button" class="btn-ghost" id="rlSalvarTitulo">Salvar</button>
      </div>
    </div>
    <div style="font-size:11px;color:var(--text-3);margin:10px 0">Tipo: <b style="color:var(--text)">${tipoLabel}</b> (não pode ser trocado depois de criada)</div>
    ${data.tipo === 'personalizado' ? `
    <div class="mb-2" style="display:flex;gap:8px">
      <input type="text" id="rlEditNomeLivre" class="form-control" placeholder="Nome do participante">
      <button type="button" class="btn-ghost" id="rlEditAddNomeLivre"><i class="bi bi-plus-lg"></i></button>
    </div>` : `
    <div class="mb-2 rl-autocomplete">
      <input type="text" id="rlEditBusca" class="form-control" placeholder="Digite o nome do GM ou do time..." autocomplete="off">
      <div class="rl-autocomplete-results" id="rlEditBuscaResultados"></div>
    </div>`}
    <div id="rlEditChips" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px"></div>
    <label class="rl-check-row">
      <input type="checkbox" id="rlEditNotificar" ${data.notificar_saida ? 'checked' : ''} style="width:16px;height:16px">
      <span>Notificar quem sair da roleta</span>
    </label>`;

  document.getElementById('rlSalvarTitulo').addEventListener('click', reSalvarTitulo);
  document.getElementById('rlEditNotificar').addEventListener('change', reSalvarNotificacao);
  reRenderChipsNovos();

  if (data.tipo === 'personalizado') {
    document.getElementById('rlEditAddNomeLivre').addEventListener('click', reAdicionarNomeLivreEdit);
    document.getElementById('rlEditNomeLivre').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); reAdicionarNomeLivreEdit(); }
    });
  } else {
    const buscaInput = document.getElementById('rlEditBusca');
    buscaInput.addEventListener('input', () => {
      clearTimeout(reBuscaTimeout);
      const q = buscaInput.value.trim();
      reBuscaTimeout = setTimeout(() => reBuscarParticipantes(q, data.tipo), 250);
    });
  }
}

function reRenderChipsNovos() {
  const wrap = document.getElementById('rlEditChips');
  if (!wrap) return;
  if (!reSelecionadosNovos.length) { wrap.innerHTML = ''; return; }
  wrap.innerHTML = reSelecionadosNovos.map((p, i) => `
    <span class="rt-chip">${_reEsc(p.label)}<button type="button" data-i="${i}" style="background:transparent;border:none;color:var(--text-3);cursor:pointer;margin-left:4px"><i class="bi bi-x"></i></button></span>
  `).join('') + `<button type="button" class="btn-ghost" id="rlSalvarNovos"><i class="bi bi-plus-lg"></i> Adicionar à roleta</button>`;
  wrap.querySelectorAll('button[data-i]').forEach(btn => {
    btn.addEventListener('click', () => { reSelecionadosNovos.splice(Number(btn.dataset.i), 1); reRenderChipsNovos(); });
  });
  const btnSalvar = document.getElementById('rlSalvarNovos');
  if (btnSalvar) btnSalvar.addEventListener('click', reSalvarNovosParticipantes);
}

async function reBuscarParticipantes(q, tipo) {
  const box = document.getElementById('rlEditBuscaResultados');
  if (q.length < 2) { box.classList.remove('show'); box.innerHTML = ''; return; }
  try {
    const jaNaRoleta = (reEstadoAtual.na_urna || []).concat(reEstadoAtual.sorteados || []).filter(p => p.team_id).map(p => p.team_id);
    const novosIds = reSelecionadosNovos.filter(p => p.team_id).map(p => p.team_id);
    const excluir = jaNaRoleta.concat(novosIds).join(',');
    const res = await fetch(`/api/roleta.php?action=buscar_participantes&q=${encodeURIComponent(q)}&excluir_team_ids=${encodeURIComponent(excluir)}`);
    const data = await res.json();
    if (!data.success || !data.resultados.length) {
      box.innerHTML = '<div class="rl-ac-empty">Nenhum resultado.</div>';
      box.classList.add('show');
      return;
    }
    box.innerHTML = data.resultados.map((r, i) => {
      const principal = tipo === 'times' ? r.time_label : r.gm_label;
      const secundario = tipo === 'times' ? r.gm_label : r.time_label;
      return `<div class="rl-ac-item" data-idx="${i}">
        ${r.photo_url ? `<img src="${_reEsc(r.photo_url)}" onerror="this.style.display='none'">` : ''}
        <span>${_reEsc(principal)} <span style="color:var(--text-3);font-weight:400">— ${_reEsc(secundario)} · ${_reEsc(r.league)}</span></span>
      </div>`;
    }).join('');
    box.classList.add('show');
    box.querySelectorAll('.rl-ac-item').forEach(el => {
      el.addEventListener('click', () => {
        const r = data.resultados[Number(el.dataset.idx)];
        const label = tipo === 'times' ? r.time_label : r.gm_label;
        reSelecionadosNovos.push({ team_id: r.team_id, user_id: r.user_id, time_label: r.time_label, gm_label: r.gm_label, label });
        reRenderChipsNovos();
        document.getElementById('rlEditBusca').value = '';
        box.classList.remove('show');
      });
    });
  } catch (e) {
    box.innerHTML = '<div class="rl-ac-empty">Erro na busca.</div>';
    box.classList.add('show');
  }
}

function reAdicionarNomeLivreEdit() {
  const input = document.getElementById('rlEditNomeLivre');
  const nome = input.value.trim();
  if (!nome) return;
  reSelecionadosNovos.push({ nome_display: nome, label: nome });
  input.value = '';
  reRenderChipsNovos();
}

async function reSalvarTitulo() {
  const titulo = document.getElementById('rlEditTitulo').value.trim();
  if (!titulo) { alert('Digite um título.'); return; }
  try {
    const data = await _reFetch('/api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'editar', id: ROLETA_ID, titulo }),
    });
    reEstadoAtual = data;
    reRenderTudo(data);
  } catch (e) {
    alert(e.message);
  }
}

async function reSalvarNotificacao() {
  const checked = document.getElementById('rlEditNotificar').checked;
  try {
    const data = await _reFetch('/api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'editar', id: ROLETA_ID, notificar_saida: checked }),
    });
    reEstadoAtual = data;
  } catch (e) {
    alert(e.message);
  }
}

async function reSalvarNovosParticipantes() {
  if (!reSelecionadosNovos.length) return;
  try {
    const data = await _reFetch('/api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'editar', id: ROLETA_ID, adicionar: reSelecionadosNovos }),
    });
    reSelecionadosNovos = [];
    reEstadoAtual = data;
    reRenderTudo(data);
  } catch (e) {
    alert(e.message);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  reCarregar();
  document.getElementById('rtGirar').addEventListener('click', reGirar);
  document.getElementById('rtReiniciar').addEventListener('click', reReiniciar);
  document.getElementById('rlExcluir').addEventListener('click', reExcluirRoleta);
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.rl-autocomplete')) {
      document.querySelectorAll('.rl-autocomplete-results').forEach(el => el.classList.remove('show'));
    }
  });
});
