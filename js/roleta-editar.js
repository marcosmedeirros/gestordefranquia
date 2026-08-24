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
let reUltimoTotalSorteados = null; // baseline pro polling detectar giro de outra sessão
const RE_POLL_MS = 4000;

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
    reUltimoTotalSorteados = (data.sorteados || []).length;
    reEstadoAtual = data;
    reRenderTudo(data);
  } catch (e) {
    document.getElementById('rtHistorico').innerHTML = `<p style="color:#ef4444;font-size:13px">${_reEsc(e.message)}</p>`;
  }
}

/**
 * Roda em segundo plano pra quem está só acompanhando ver o giro acontecer
 * também, sem precisar recarregar a página. Compara o total de sorteados com
 * o último conhecido — se aumentou e não fui eu que girei agora, replica a
 * mesma animação da roda com quem saiu.
 */
async function rePoll() {
  if (reGirando) return; // já girando localmente ou animando outro giro remoto
  try {
    const data = await _reFetch(`/api/roleta.php?action=estado&id=${ROLETA_ID}`);
    const novoTotal = (data.sorteados || []).length;
    // Só mexe na tela quando detecta um giro novo — senão atrapalharia quem
    // está no meio de uma edição (título/participantes) a cada poll.
    if (reUltimoTotalSorteados !== null && novoTotal > reUltimoTotalSorteados) {
      const idsNaUrnaAntes = new Set(reUrnaRenderizada.map(t => Number(t.id)));
      const novoSorteio = (data.sorteados || []).find(t => idsNaUrnaAntes.has(Number(t.id)));
      reUltimoTotalSorteados = novoTotal;
      if (novoSorteio) {
        await reAnimarGiroRemoto(novoSorteio, data);
      } else {
        reEstadoAtual = data;
        reRenderTudo(data);
      }
    }
  } catch (e) { /* silencioso: só não atualiza dessa vez */ }
}

/** Mesma animação de reGirar(), mas pra um giro que outra sessão fez. */
async function reAnimarGiroRemoto(participante, data) {
  reGirando = true;
  const urnaNoGiro = reUrnaRenderizada.slice();
  const idx = urnaNoGiro.findIndex(t => Number(t.id) === Number(participante.id));
  const roda = document.getElementById('rtRoda');
  if (roda && idx !== -1 && !RE_SEM_MOVIMENTO) {
    const n = urnaNoGiro.length;
    const ang = 360 / n;
    const meio = idx * ang + ang / 2;
    const voltas = 4;
    roda.style.transform = `rotate(${voltas * 360 + (360 - meio)}deg)`;
    await new Promise(r => setTimeout(r, 1900));
  }
  reAnunciar({ pick: participante.pick_number, nome_display: participante.nome_display });
  reEstadoAtual = data;
  reRenderTudo(data);
  reGirando = false;
  if (reEstadoAtual) reAtualizarBotaoGirar(reEstadoAtual);
}

function reRenderTudo(data) {
  reUrnaRenderizada = data.na_urna || [];
  document.querySelector('#rlTituloHero span').textContent = data.titulo;
  document.title = `${data.titulo} — Editar Roleta`;
  reRenderRoda(reUrnaRenderizada);
  reRenderUrna(reUrnaRenderizada, data.bloqueada);
  reRenderHistorico(data.sorteados || [], data.total || 0);
  reRenderResumo(data);
  reAtualizarBotaoGirar(data);
  reRenderConfig(data);
  // Os botões de ação só existem no HTML pra admin — pra GM que só acompanha
  // a página nem renderiza o bloco, então tudo aqui é opcional.
  const podeMexer = data.pode_girar !== false;
  const btnExcluir = document.getElementById('rlExcluir');
  if (btnExcluir) {
    btnExcluir.disabled = data.bloqueada || !podeMexer;
    btnExcluir.title = !podeMexer
      ? 'Só o admin da liga pode excluir.'
      : (data.bloqueada ? 'Já teve o 1º sorteio — não pode mais ser excluída.' : '');
  }
  reAtualizarBotaoDraft(data);
}

/** O draft só faz sentido depois que a ordem está fechada. */
function reAtualizarBotaoDraft(data) {
  const btn = document.getElementById('rtCriarDraft');
  if (btn) btn.style.display = data.concluido ? '' : 'none';
  // O sorteio de marca (dropdown de times da NBA) é exclusivo da ROOKIE — nas
  // outras ligas a pick é o nome de um jogador, então o botão nem aparece.
  const btnNba = document.getElementById('rtCriarDraftNba');
  if (btnNba) {
    const ehRookie = (typeof ROLETA_LIGA === 'string') && ROLETA_LIGA.toUpperCase() === 'ROOKIE';
    btnNba.style.display = (data.concluido && ehRookie) ? '' : 'none';
  }
}

async function reCriarDraftEm(btnId, modo, destino) {
  const btn = document.getElementById(btnId);
  if (!btn || btn.disabled) return;
  btn.disabled = true;
  try {
    const res = await fetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'criar_de_roleta', roleta_id: ROLETA_ID, modo }),
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Erro ao criar o draft');
    window.location.href = destino(data.id);
  } catch (e) {
    alert(e.message);
    btn.disabled = false;
  }
}

async function reCriarDraft() {
  await reCriarDraftEm('rtCriarDraft', 'texto_livre', (id) => `/draft-aleatorio.php?id=${id}`);
}

// O draft de marca não tem tela de admin própria: cada GM vê a fila e a vez dele
// pelo rookie-sorteio.php, então o admin cai na lista de drafts.
async function reCriarDraftNba() {
  await reCriarDraftEm('rtCriarDraftNba', 'time_nba', () => '/drafts-aleatorios.php');
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

function reRenderHistorico(sorteados, total) {
  const el = document.getElementById('rtHistorico');
  if (!sorteados.length) {
    el.innerHTML = `<div class="rt-vazio"><i class="bi bi-clock-history"></i><p>Nenhum participante sorteado ainda.</p></div>`;
    return;
  }
  el.innerHTML = sorteados.map((t) => {
    const ordem = total - t.pick_number + 1;
    const quando = t.eliminated_at
      ? new Date(String(t.eliminated_at).replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
      : '';
    return `
      <div class="rt-hist-linha">
        <span class="rt-hist-saida">${ordem}º a sair</span>
        <span class="rt-hist-pick">Escolha ${t.pick_number}</span>
        <img class="rt-hist-logo" src="${_reEsc(t.photo_url || RE_LOGO_PADRAO)}" alt="" onerror="this.src='${RE_LOGO_PADRAO}'">
        <span class="rt-hist-time">${_reEsc(t.nome_display)}</span>
        <span class="rt-hist-hora">${_reEsc(quando)}</span>
      </div>`;
  }).join('');
}

/** Copia texto pro clipboard (com fallback pra navegadores/contextos sem a API). */
async function reCopiar(texto, btnEl) {
  try {
    await navigator.clipboard.writeText(texto);
  } catch (e) {
    const ta = document.createElement('textarea');
    ta.value = texto;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e2) {}
    document.body.removeChild(ta);
  }
  if (btnEl) {
    const original = btnEl.innerHTML;
    btnEl.innerHTML = '<i class="bi bi-check2"></i>';
    setTimeout(() => { btnEl.innerHTML = original; }, 1200);
  }
}

/** Copia a ordem inteira (pick 1 ao último), pronta pra colar no WhatsApp. */
function reCopiarTudo(btnEl) {
  const sorteados = (reEstadoAtual && reEstadoAtual.sorteados) || [];
  if (!sorteados.length) return;
  const texto = sorteados
    .slice()
    .sort((a, b) => a.pick_number - b.pick_number)
    .map(t => `Pick ${t.pick_number}, ${t.nome_display}`)
    .join('\n');
  reCopiar(texto, btnEl);
}
window.reCopiarTudo = reCopiarTudo;

function reAtualizarBotaoGirar(data) {
  const btn = document.getElementById('rtGirar');
  if (!btn) return;
  const acabou = !!data.concluido;

  // Girar é restrito ao admin geral (nem o admin da liga pode) — o servidor
  // também barra, aqui é só pra não oferecer um botão que vai dar erro.
  if (!data.is_global_admin) {
    btn.disabled = true;
    btn.innerHTML = acabou
      ? '<i class="bi bi-check2-all me-1"></i>Sorteio concluído'
      : '<i class="bi bi-eye me-1"></i>Só o admin geral gira';
    return;
  }

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
      const voltas = 4;
      roda.style.transform = `rotate(${voltas * 360 + (360 - meio)}deg)`;
      await new Promise(r => setTimeout(r, 1900));
    }

    reAnunciar(data);
    reUltimoTotalSorteados = (data.sorteados || []).length;
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

/** Cria outra roleta com os mesmos participantes — o sorteio não vem junto. */
async function reDuplicar() {
  const nome = await perguntarSite('Nome da cópia:', `${reEstadoAtual?.titulo || 'Roleta'} (cópia)`);
  if (nome === null) return;
  if (!nome.trim()) { alert('O nome não pode ficar vazio.'); return; }
  try {
    const data = await _reFetch('/api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'duplicar', id: ROLETA_ID, titulo: nome.trim() }),
    });
    window.location.href = `/roleta-editar.php?id=${data.id}`;
  } catch (e) {
    alert(e.message);
  }
}

async function reReiniciar() {
  if (reGirando) return;
  if (!await confirmarSite('Reiniciar o sorteio?\n\nTodas as escolhas definidas serão apagadas e todo mundo volta pra urna.')) return;
  try {
    const data = await _reFetch('/api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'reiniciar', id: ROLETA_ID }),
    });
    document.getElementById('rtAnuncio').innerHTML = '';
    reUltimoTotalSorteados = (data.sorteados || []).length;
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
  if (!await confirmarSite('Excluir esta roleta permanentemente? Essa ação não pode ser desfeita.')) return;
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

  // Espectador (GM da liga que não administra) só lê a ficha da roleta.
  if (data.pode_girar === false) {
    box.innerHTML = `
      <div class="edit-locked-msg"><i class="bi bi-eye-fill"></i>Você está acompanhando esta roleta. Só o admin da ${_reEsc(data.league || 'liga')} pode girar ou editar.</div>
      <div style="margin-top:12px;font-size:12px;color:var(--text-3)">Tipo: <b style="color:var(--text)">${tipoLabel}</b></div>`;
    return;
  }

  // A liga pode ser trocada a qualquer momento — inclusive depois do 1º giro,
  // porque ela só decide quem enxerga a roleta, não o resultado do sorteio.
  const ligas = data.minhas_ligas || [];
  const blocoLiga = ligas.length ? `
    <div class="mb-3">
      <div style="font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:6px">Liga da roleta</div>
      <div style="display:flex;gap:8px">
        <select id="rlEditLiga" class="form-control">
          ${ligas.map(l => `<option value="${_reEsc(l)}"${l === data.league ? ' selected' : ''}>${_reEsc(l)}</option>`).join('')}
        </select>
        <button type="button" class="btn-ghost" id="rlSalvarLiga">Salvar</button>
      </div>
      <small style="font-size:11px;color:var(--text-3)">Define quem vê a roleta no painel e quem pode girar. O draft gerado por ela acompanha.</small>
    </div>` : '';

  if (data.bloqueada) {
    box.innerHTML = `
      <div class="edit-locked-msg"><i class="bi bi-lock-fill"></i>Esta roleta já teve o 1º sorteio — título, participantes e notificação não podem mais ser editados.</div>
      ${blocoLiga}
      <div style="margin-top:12px;font-size:12px;color:var(--text-3)">Tipo: <b style="color:var(--text)">${tipoLabel}</b> · Notificação: <b style="color:var(--text)">${data.notificar_saida ? 'ativada' : 'desativada'}</b></div>`;
    reLigarBotaoLiga();
    return;
  }

  reSelecionadosNovos = [];
  box.innerHTML = `
    ${blocoLiga}
    <div class="mb-2">
      <div style="font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:6px">Título</div>
      <div style="display:flex;gap:8px">
        <input type="text" id="rlEditTitulo" class="form-control" value="${_reEsc(data.titulo)}">
        <button type="button" class="btn-ghost" id="rlSalvarTitulo">Salvar</button>
      </div>
    </div>
    <div style="font-size:11px;color:var(--text-3);margin:10px 0">Tipo: <b style="color:var(--text)">${tipoLabel}</b> (não pode ser trocado depois de criada)</div>
    ${data.tipo === 'personalizado' ? `
    <div class="mb-1" style="display:flex;gap:8px">
      <input type="text" id="rlEditNomeLivre" class="form-control" placeholder="Nome do participante">
      <button type="button" class="btn-ghost" id="rlEditAddNomeLivre"><i class="bi bi-plus-lg"></i></button>
    </div>
    <small style="color:var(--text-3);font-size:11px;display:block;margin-bottom:8px">Dá pra colar vários nomes de uma vez — um por linha, cada linha vira um participante.</small>` : `
    <div class="mb-2 rl-autocomplete">
      <input type="text" id="rlEditBusca" class="form-control" placeholder="Digite o nome do GM ou do time..." autocomplete="off">
      <div class="rl-autocomplete-results" id="rlEditBuscaResultados"></div>
    </div>`}
    <div id="rlEditChips" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px"></div>
    <label class="rl-check-row">
      <input type="checkbox" id="rlEditNotificar" ${data.notificar_saida ? 'checked' : ''} style="width:16px;height:16px">
      <span>Notificar quem sair da roleta</span>
    </label>`;

  reLigarBotaoLiga();
  document.getElementById('rlSalvarTitulo').addEventListener('click', reSalvarTitulo);
  document.getElementById('rlEditNotificar').addEventListener('change', reSalvarNotificacao);
  reRenderChipsNovos();

  if (data.tipo === 'personalizado') {
    document.getElementById('rlEditAddNomeLivre').addEventListener('click', reAdicionarNomeLivreEdit);
    document.getElementById('rlEditNomeLivre').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); reAdicionarNomeLivreEdit(); }
    });
    document.getElementById('rlEditNomeLivre').addEventListener('paste', (e) => {
      const texto = (e.clipboardData || window.clipboardData).getData('text');
      if (texto && texto.includes('\n')) {
        e.preventDefault();
        reAdicionarNomesLivreEmLote(texto);
      }
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

/** Liga o botão de salvar a liga — existe nos dois estados do painel. */
function reLigarBotaoLiga() {
  const btn = document.getElementById('rlSalvarLiga');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    const liga = document.getElementById('rlEditLiga').value;
    btn.disabled = true;
    try {
      await _reFetch('/api/roleta.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'definir_liga', id: ROLETA_ID, league: liga }),
      });
      const antes = btn.textContent;
      btn.textContent = 'Salvo!';
      setTimeout(() => { btn.textContent = antes; btn.disabled = false; }, 1500);
      reCarregar();
    } catch (e) {
      btn.disabled = false;
      alert(e.message);
    }
  });
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

/** Cola vários nomes de uma vez (um por linha) — cada linha vira um participante. */
function reAdicionarNomesLivreEmLote(texto) {
  const linhas = texto.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  linhas.forEach(nome => reSelecionadosNovos.push({ nome_display: nome, label: nome }));
  if (linhas.length) reRenderChipsNovos();
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
  const ligar = (id, fn) => { const el = document.getElementById(id); if (el) el.addEventListener('click', fn); };
  ligar('rtGirar', reGirar);
  ligar('rtDuplicar', reDuplicar);
  ligar('rtReiniciar', reReiniciar);
  ligar('rlExcluir', reExcluirRoleta);
  ligar('rtCriarDraft', reCriarDraft);
  ligar('rtCriarDraftNba', reCriarDraftNba);
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.rl-autocomplete')) {
      document.querySelectorAll('.rl-autocomplete-results').forEach(el => el.classList.remove('show'));
    }
  });
  setInterval(rePoll, RE_POLL_MS);
});
