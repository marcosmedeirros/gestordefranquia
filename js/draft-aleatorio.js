// Draft Aleatório — a ordem sorteada numa roleta vira a ordem deste draft.
// Mesmas funções do Draft de Lendas (escolher / pular / desfazer / despular /
// finalizar), sem badges e sem OVR/idade fixos.

const _daEsc = s => (s == null ? '' : String(s))
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

let daEstado = null;

/** Segundos -> "12s" / "3m 05s" / "1h 02m". */
function daTempo(seg) {
  seg = Math.max(0, Math.round(Number(seg) || 0));
  if (seg < 60) return seg + 's';
  if (seg < 3600) return Math.floor(seg / 60) + 'm ' + String(seg % 60).padStart(2, '0') + 's';
  return Math.floor(seg / 3600) + 'h ' + String(Math.floor((seg % 3600) / 60)).padStart(2, '0') + 'm';
}
let daPickAlvo = null; // pick sendo preenchida pelo modal (null = a da vez)

// Trava de envio: enquanto uma ação de escrita está em voo, nenhuma outra sai.
// Sem isso, clique duplo (ou Enter + clique) disparava duas requisições — a
// segunda chegava depois da primeira já ter avançado a vez e acabava
// registrando a escolha do GM seguinte junto, como se tivesse escolhido 2x.
let daEmVoo = false;

async function _daFetch(url, options = {}) {
  const ehEscrita = String(options.method || 'GET').toUpperCase() === 'POST';
  if (ehEscrita) {
    if (daEmVoo) throw new Error('Calma — a ação anterior ainda está sendo registrada.');
    daEmVoo = true;
  }
  try {
    const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...options });
    const txt = await res.text();
    let data;
    try { data = JSON.parse(txt); } catch (e) { throw new Error('Resposta inválida do servidor'); }
    if (!data.success) throw new Error(data.error || 'Erro desconhecido');
    return data;
  } finally {
    if (ehEscrita) daEmVoo = false;
  }
}

async function daCarregar() {
  try {
    daEstado = await _daFetch(`/api/drafts-aleatorios.php?action=estado&id=${window.DRAFT_ID}`);
    daRenderTudo();
  } catch (e) {
    document.getElementById('daContent').innerHTML =
      `<div class="da-vazio"><i class="bi bi-exclamation-triangle"></i><p>${_daEsc(e.message)}</p></div>`;
  }
}

function daRenderTudo() {
  const box = document.getElementById('daContent');
  const d = daEstado;

  const tituloEl = document.getElementById('daTitulo');
  if (tituloEl) tituloEl.textContent = d.titulo;
  document.title = `${d.titulo} — FBA Manager`;

  if (!d.total) {
    box.innerHTML = `<div class="da-vazio"><i class="bi bi-hourglass-split"></i><p>Este draft ainda não tem participantes.</p></div>`;
    return;
  }

  const pct = Math.round((d.feitas / d.total) * 100);
  // pick_number vem como string do PDO — sem o Number() a comparação estrita
  // nunca acha a vez e o card de escolha some.
  const vez = d.picks.find(p => Number(p.pick_number) === Number(d.vez_pick_number));
  const ehMinhaVez = vez && Number(vez.user_id) === Number(window.SESSION_USER_ID);

  let html = `<div class="da-progress"><div style="width:${pct}%"></div></div>`;

  // Liga do draft: decide quem vê o card no painel e na central. Só o admin
  // das ligas que ele administra troca — a API confere de novo.
  const ligasDa = d.minhas_ligas || [];
  if (ligasDa.length) {
    html += `
    <div class="da-liga-box">
      <span class="da-liga-label"><i class="bi bi-flag-fill"></i> Liga do draft</span>
      <select id="daLiga">
        ${ligasDa.map(l => `<option value="${_daEsc(l)}"${l === d.league ? ' selected' : ''}>${_daEsc(l)}</option>`).join('')}
      </select>
      <button type="button" class="btn-ghost" id="btnDaSalvarLiga">Salvar</button>
    </div>`;
  }

  if (d.finalizado) {
    html += `
    <div class="da-turno da-turno-ok">
      <div class="da-turno-label"><i class="bi bi-flag-fill"></i> Draft finalizado</div>
      <div class="da-turno-gm">${d.feitas} de ${d.total} escolhas registradas.</div>
      ${d.is_admin ? `<div style="margin-top:10px"><button type="button" class="btn-ghost" id="btnDaReabrir"><i class="bi bi-arrow-counterclockwise me-1"></i>Reabrir draft</button></div>` : ''}
    </div>`;
  } else if (vez) {
    html += `
    <div class="da-turno">
      <div class="da-turno-label">Escolha ${vez.pick_number} de ${d.total}</div>
      <div class="da-turno-gm">${_daEsc(vez.nome_display)}${ehMinhaVez ? ' — é a sua vez!' : ''}</div>
      <div class="da-form-row">
        <input type="text" id="daNomeJogador" placeholder="Nome do jogador" maxlength="150">
        <button type="button" class="btn-orange" id="btnDaEscolher">Confirmar escolha</button>
        <button type="button" class="da-btn-pular" id="btnDaPular" data-gm="${_daEsc(vez.nome_display)}"><i class="bi bi-skip-forward-fill"></i> Pular escolha</button>
      </div>
    </div>`;
  } else if (d.draft_completo) {
    html += `
    <div class="da-turno da-turno-ok">
      <div class="da-turno-label"><i class="bi bi-check-circle-fill"></i> Draft concluído</div>
      <div class="da-turno-gm">Todas as ${d.total} escolhas foram resolvidas.</div>
      ${d.is_admin ? `<div style="margin-top:10px"><button type="button" class="btn-orange" id="btnDaFinalizar"><i class="bi bi-flag-fill me-1"></i>Finalizar Draft</button>
        <div style="font-size:11px;color:var(--text-2);margin-top:6px">Depois de finalizar, ninguém mais consegue mexer nas escolhas.</div></div>` : ''}
    </div>`;
  }

  html += `
    <div class="card">
      <div class="card-head">
        <div class="card-head-left"><i class="bi bi-list-ol"></i><span>Quadro do Draft</span></div>
        <div style="display:flex;gap:8px">
          <button type="button" class="btn-ghost" id="btnDaCopiarLink"><i class="bi bi-link-45deg me-1"></i>Copiar link</button>
          <button type="button" class="btn-ghost" id="btnDaCopiarEscolhas"><i class="bi bi-clipboard-check me-1"></i>Copiar escolhas</button>
          <button type="button" class="btn-ghost" id="btnDaCopiar"><i class="bi bi-clipboard me-1"></i>Copiar ordem</button>
        </div>
      </div>
      <div class="card-body"><div class="da-board">${d.picks.map(p => daLinhaBoard(p, d)).join('')}</div></div>
    </div>`;

  box.innerHTML = html;

  const on = (id, fn) => { const el = document.getElementById(id); if (el) el.addEventListener('click', fn); };
  on('btnDaEscolher', daEscolher);
  on('btnDaFinalizar', daFinalizar);
  on('btnDaReabrir', daReabrir);
  on('btnDaCopiar', daCopiarOrdem);
  on('btnDaCopiarEscolhas', daCopiarEscolhas);
  on('btnDaCopiarLink', daCopiarLink);
  on('btnDaSalvarLiga', daSalvarLiga);
  const btnPular = document.getElementById('btnDaPular');
  if (btnPular) btnPular.addEventListener('click', () => daAbrirModalPular(btnPular.dataset.gm));

  document.querySelectorAll('.da-btn-preencher').forEach(b =>
    b.addEventListener('click', () => daAbrirModalPreencher(Number(b.dataset.pick), b.dataset.gm)));
  document.querySelectorAll('.da-btn-desfazer').forEach(b =>
    b.addEventListener('click', () => daDesfazer(Number(b.dataset.pick), b.dataset.gm)));
  document.querySelectorAll('.da-btn-despular').forEach(b =>
    b.addEventListener('click', () => daDespular(Number(b.dataset.pick), b.dataset.gm)));
  document.querySelectorAll('.da-btn-editar-gm').forEach(b =>
    b.addEventListener('click', () => daRenomearGM(Number(b.dataset.pick), b.dataset.gm)));

  const input = document.getElementById('daNomeJogador');
  if (input) input.addEventListener('keydown', e => { if (e.key === 'Enter') daEscolher(); });
}

function daLinhaBoard(p, d) {
  const ehVez = Number(p.pick_number) === Number(d.vez_pick_number);
  const ehMeu = Number(p.user_id) === Number(window.SESSION_USER_ID);
  const classe = ehVez ? 'atual' : (ehMeu ? 'eu' : '');
  const gm = _daEsc(p.nome_display);
  const foto = p.photo_url
    ? `<img class="da-row-foto" src="${_daEsc(p.photo_url)}" alt="">`
    : `<span class="da-row-foto da-row-foto-vazia"><i class="bi bi-shield"></i></span>`;

  let jogador;
  if (p.player_name) {
    jogador = `<i class="bi bi-check-circle-fill"></i><b>${_daEsc(p.player_name)}</b>`
      + (!d.finalizado ? `<button type="button" class="da-btn-desfazer" data-pick="${p.pick_number}" data-gm="${gm}" title="Desfazer escolha"><i class="bi bi-arrow-counterclockwise"></i></button>` : '');
  } else if (Number(p.skipped)) {
    jogador = `<span class="da-pulada"><i class="bi bi-skip-forward-fill"></i> Pulada</span>`
      + (!d.finalizado
        ? `<button type="button" class="da-btn-preencher" data-pick="${p.pick_number}" data-gm="${gm}">Escolher</button>`
          + `<button type="button" class="da-btn-despular" data-pick="${p.pick_number}" data-gm="${gm}" title="Tirar do pulado"><i class="bi bi-arrow-counterclockwise"></i></button>`
        : '');
  } else {
    jogador = ehVez ? 'escolhendo agora...' : 'aguardando...';
  }

  const editarGm = d.is_admin
    ? `<button type="button" class="da-btn-editar-gm" data-pick="${p.pick_number}" data-gm="${gm}" title="Editar nome do GM"><i class="bi bi-pencil"></i></button>`
    : '';

  // Quanto essa escolha demorou (vem pronto da API, em segundos).
  const tempo = p.tempo_seg != null
    ? `<span class="da-row-tempo" title="Tempo que essa escolha levou"><i class="bi bi-stopwatch"></i>${daTempo(p.tempo_seg)}</span>`
    : '';

  return `
    <div class="da-row ${classe}">
      <div class="da-row-pick">${p.pick_number}</div>
      ${foto}
      <div class="da-row-gm"><span>${gm}</span>${editarGm}</div>
      <div class="da-row-jogador">${jogador}</div>
      ${tempo}
    </div>`;
}

async function daEscolher() {
  const nome = document.getElementById('daNomeJogador').value.trim();
  if (!nome) { alert('Digite o nome do jogador.'); return; }
  const btn = document.getElementById('btnDaEscolher');
  btn.disabled = true;
  try {
    daEstado = await _daFetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'escolher', id: window.DRAFT_ID, player_name: nome }),
    });
    daRenderTudo();
  } catch (e) {
    alert(e.message);
    btn.disabled = false;
  }
}

// ── Pular ────────────────────────────────────────────────
function daAbrirModalPular(gm) {
  const t = document.getElementById('daPularModalText');
  if (t) t.textContent = `A escolha de ${gm} vai ser marcada como pulada e o draft segue pra próxima. Dá pra voltar nela depois.`;
  document.getElementById('daPularModalOverlay').classList.add('open');
}
function daFecharModalPular() { document.getElementById('daPularModalOverlay').classList.remove('open'); }
window.daFecharModalPular = daFecharModalPular;

async function daConfirmarPular() {
  const btn = document.getElementById('btnDaConfirmarPular');
  btn.disabled = true;
  try {
    daEstado = await _daFetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'pular', id: window.DRAFT_ID }),
    });
    daFecharModalPular();
    daRenderTudo();
  } catch (e) {
    alert(e.message);
  } finally {
    btn.disabled = false;
  }
}

// ── Preencher uma pick específica (normalmente uma pulada) ─
function daAbrirModalPreencher(pick, gm) {
  daPickAlvo = pick;
  const t = document.getElementById('daPreencherModalText');
  if (t) t.textContent = `Escolha ${pick} — ${gm}.`;
  document.getElementById('daPreencherNome').value = '';
  document.getElementById('daPreencherModalOverlay').classList.add('open');
  setTimeout(() => document.getElementById('daPreencherNome').focus(), 50);
}
function daFecharModalPreencher() {
  daPickAlvo = null;
  document.getElementById('daPreencherModalOverlay').classList.remove('open');
}
window.daFecharModalPreencher = daFecharModalPreencher;

async function daConfirmarPreencher() {
  const nome = document.getElementById('daPreencherNome').value.trim();
  if (!nome) { alert('Digite o nome do jogador.'); return; }
  const btn = document.getElementById('btnDaConfirmarPreencher');
  btn.disabled = true;
  try {
    daEstado = await _daFetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'escolher', id: window.DRAFT_ID,
        player_name: nome, pick_number: daPickAlvo,
      }),
    });
    daFecharModalPreencher();
    daRenderTudo();
  } catch (e) {
    alert(e.message);
  } finally {
    btn.disabled = false;
  }
}

async function daDesfazer(pick, gm) {
  if (!confirm(`Desfazer a escolha ${pick} (${gm})? Ela volta pra "pulada" e pode ser preenchida de novo.`)) return;
  try {
    daEstado = await _daFetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'desfazer', id: window.DRAFT_ID, pick_number: pick }),
    });
    daRenderTudo();
  } catch (e) { alert(e.message); }
}

async function daDespular(pick, gm) {
  if (!confirm(`Tirar a escolha ${pick} (${gm}) do "pulado"? Ela volta pra fila normal do draft.`)) return;
  try {
    daEstado = await _daFetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'despular', id: window.DRAFT_ID, pick_number: pick }),
    });
    daRenderTudo();
  } catch (e) { alert(e.message); }
}

async function daFinalizar() {
  if (!confirm('Finalizar o draft? Depois disso ninguém mais consegue alterar as escolhas.')) return;
  try {
    daEstado = await _daFetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'finalizar', id: window.DRAFT_ID }),
    });
    daRenderTudo();
  } catch (e) { alert(e.message); }
}

async function daReabrir() {
  if (!confirm('Reabrir o draft para novas alterações?')) return;
  try {
    daEstado = await _daFetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'reabrir', id: window.DRAFT_ID }),
    });
    daRenderTudo();
  } catch (e) { alert(e.message); }
}

async function daSalvarLiga(ev) {
  const btn = ev.currentTarget;
  const liga = document.getElementById('daLiga')?.value;
  if (!liga) return;
  btn.disabled = true;
  try {
    daEstado = await _daFetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'definir_liga', id: window.DRAFT_ID, league: liga }),
    });
    daRenderTudo();
  } catch (e) {
    btn.disabled = false;
    alert(e.message);
  }
}

async function daRenomearGM(pick, atual) {
  const nome = prompt('Nome do GM para esta escolha:', atual);
  if (nome === null) return;
  if (!nome.trim()) { alert('O nome não pode ficar vazio.'); return; }
  try {
    daEstado = await _daFetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'renomear_gm', id: window.DRAFT_ID, pick_number: pick, nome: nome.trim() }),
    });
    daRenderTudo();
  } catch (e) { alert(e.message); }
}

function daCopiarLink(ev) {
  const btn = ev.currentTarget;
  const original = btn.innerHTML;
  navigator.clipboard.writeText(window.location.href).then(() => {
    btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copiado!';
    setTimeout(() => { btn.innerHTML = original; }, 1600);
  }).catch(() => alert('Não consegui copiar o link.'));
}

/** Copia texto e dá o retorno visual no próprio botão. */
function daCopiar(texto, btn) {
  const original = btn.innerHTML;
  navigator.clipboard.writeText(texto).then(() => {
    btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copiado!';
    setTimeout(() => { btn.innerHTML = original; }, 1600);
  }).catch(() => prompt('Copie o texto abaixo:', texto));
}

/** Ordem completa do draft, escolhidos ou não. */
function daCopiarOrdem(ev) {
  const texto = daEstado.picks
    .map(p => `Pick ${p.pick_number}, ${p.nome_display}`
      + (p.player_name ? ' — ' + p.player_name : '')
      + (p.tempo_seg != null ? ' [' + daTempo(p.tempo_seg) + ']' : ''))
    .join('\n');
  daCopiar(texto, ev.currentTarget);
}

/**
 * Só as escolhas já feitas, no formato de mandar no grupo:
 * "1 - Oakland Blue Foxes - LeBron James". Puladas ainda sem jogador ficam
 * de fora — o que interessa colar é quem já escolheu.
 */
function daCopiarEscolhas(ev) {
  const feitas = daEstado.picks.filter(p => p.player_name);
  if (!feitas.length) { alert('Ainda não tem nenhuma escolha pra copiar.'); return; }
  const texto = `${daEstado.titulo} — escolhas até agora\n\n`
    + feitas.map(p => `${p.pick_number} - ${p.nome_display} - ${p.player_name}`).join('\n');
  daCopiar(texto, ev.currentTarget);
}

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('btnDaConfirmarPular').addEventListener('click', daConfirmarPular);
  document.getElementById('btnDaConfirmarPreencher').addEventListener('click', daConfirmarPreencher);
  document.getElementById('daPreencherNome').addEventListener('keydown', e => {
    if (e.key === 'Enter') daConfirmarPreencher();
  });
  daCarregar();
});
