// Uma loteria específica — sorteia as escolhas uma a uma, com peso na chance
// de cada participante. Quem só acompanha vê tudo, mas não sorteia.

const _laEsc = s => (s == null ? '' : String(s))
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const LA_LOGO_PADRAO = '/img/default-team.png';
let laEstado = null;
let laSorteando = false;

async function _laFetch(url, options = {}) {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...options });
  const txt = await res.text();
  let data;
  try { data = JSON.parse(txt); } catch (e) { throw new Error('Resposta inválida do servidor'); }
  if (!data.success) throw new Error(data.error || 'Erro desconhecido');
  return data;
}

async function laCarregar() {
  try {
    laEstado = await _laFetch(`/api/loteria-aleatoria.php?action=estado&id=${window.LOTERIA_ID}`);
    laRenderTudo();
  } catch (e) {
    document.getElementById('ltUrna').innerHTML = `<p style="color:#ef4444;font-size:13px">${_laEsc(e.message)}</p>`;
  }
}

function laRenderTudo(destaque = null) {
  const d = laEstado;
  document.querySelector('#ltTituloHero span').textContent = d.titulo;
  document.title = `${d.titulo} — Loteria`;
  document.getElementById('ltProgresso').textContent = `${d.sorteados.length}/${d.total} definidos`;

  laRenderBola(destaque);
  laRenderUrna();
  laRenderHistorico();
  laRenderBotao();
  laRenderConfig();

  const btnExcluir = document.getElementById('ltExcluir');
  if (btnExcluir) {
    btnExcluir.disabled = d.pode_sortear === false;
    btnExcluir.title = d.pode_sortear === false ? 'Só o admin da liga pode excluir.' : '';
  }
  const btnReiniciar = document.getElementById('ltReiniciar');
  if (btnReiniciar) btnReiniciar.disabled = d.pode_sortear === false;
  const btnDuplicar = document.getElementById('ltDuplicar');
  if (btnDuplicar) btnDuplicar.disabled = d.pode_sortear === false;
}

/** Bolinha grande: mostra o último sorteado (ou o estado inicial/final). */
function laRenderBola(destaque) {
  const box = document.getElementById('ltBola');
  const d = laEstado;
  const ultimo = destaque || (d.sorteados.length ? d.sorteados[d.sorteados.length - 1] : null);

  if (!ultimo) {
    box.className = 'lot-bola';
    box.innerHTML = `<div class="lot-bola-nome" style="color:var(--text-3);font-size:14px;font-weight:600">Ninguém sorteado ainda</div>
      <div class="lot-bola-chance">${d.total} participantes na urna</div>`;
    return;
  }

  box.className = 'lot-bola' + (destaque ? ' novo' : '');
  box.innerHTML = `
    <div class="lot-bola-pick">Escolha ${ultimo.pick_number}</div>
    <div class="lot-bola-nome">${_laEsc(ultimo.nome_display)}</div>
    <div class="lot-bola-chance">tinha ${Number(ultimo.chance).toFixed(1)}% de chance base</div>`;
}

function laRenderUrna() {
  const box = document.getElementById('ltUrna');
  const urna = laEstado.na_urna || [];
  if (!urna.length) {
    box.innerHTML = '<p style="font-size:12.5px;color:var(--text-3);margin:0">A urna está vazia — todas as escolhas já saíram.</p>';
    return;
  }
  box.innerHTML = urna.map(p => `
    <div class="lot-urna-item">
      <img src="${_laEsc(p.photo_url || LA_LOGO_PADRAO)}" alt="" onerror="this.src='${LA_LOGO_PADRAO}'">
      <span class="lot-urna-nome">${_laEsc(p.nome_display)}</span>
      <span class="lot-urna-base">base ${Number(p.chance).toFixed(1)}%</span>
      <span class="lot-urna-chance">${Number(p.chance_atual).toFixed(1)}%</span>
    </div>`).join('');
}

function laRenderHistorico() {
  const box = document.getElementById('ltHistorico');
  const lista = laEstado.sorteados || [];
  if (!lista.length) {
    box.innerHTML = '<p style="font-size:12.5px;color:var(--text-3);margin:0">Nenhuma escolha definida ainda.</p>';
    return;
  }
  box.innerHTML = lista.map(p => `
    <div class="lot-hist-item">
      <span class="lot-hist-pick">${p.pick_number}</span>
      <span class="lot-hist-nome">${_laEsc(p.nome_display)}</span>
      <span class="lot-hist-chance">${Number(p.chance).toFixed(1)}%</span>
    </div>`).join('');
}

function laRenderBotao() {
  const btn = document.getElementById('ltSortear');
  const d = laEstado;

  if (d.pode_sortear === false) {
    btn.disabled = true;
    btn.textContent = d.concluido ? 'Loteria concluída' : 'Só o admin da liga sorteia';
    return;
  }
  if (d.concluido) {
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-check2-all me-1"></i>Loteria concluída';
    return;
  }
  const proxima = d.sorteados.length + 1;
  btn.disabled = laSorteando;
  btn.innerHTML = laSorteando
    ? 'Sorteando...'
    : `<i class="bi bi-dice-3-fill me-1"></i>Sortear a escolha ${proxima}`;
}

async function laSortear() {
  if (laSorteando || laEstado.concluido) return;
  laSorteando = true;
  laRenderBotao();
  try {
    const data = await _laFetch('/api/loteria-aleatoria.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'sortear', id: window.LOTERIA_ID }),
    });
    laEstado = data;
    const sorteado = (data.sorteados || []).find(p => Number(p.id) === Number(data.sorteado_id));
    laSorteando = false;
    laRenderTudo(sorteado || null);
  } catch (e) {
    laSorteando = false;
    laRenderBotao();
    alert(e.message);
  }
}

/**
 * Painel de configuração. Depois do 1º sorteio só a liga continua editável —
 * mexer em participante ou chance com a loteria em andamento invalidaria o
 * que já saiu.
 */
function laRenderConfig() {
  const box = document.getElementById('ltConfigBody');
  const d = laEstado;
  const tipoLabel = { gms: 'GMs', times: 'Times', personalizado: 'Personalizado' }[d.tipo] || d.tipo;

  if (d.pode_sortear === false) {
    box.innerHTML = `
      <p style="font-size:12.5px;color:var(--text-2);margin:0">Você está acompanhando esta loteria. Só o admin da ${_laEsc(d.league || 'liga')} pode sortear ou editar.</p>
      <p style="font-size:12px;color:var(--text-3);margin:10px 0 0">Tipo: <b style="color:var(--text)">${tipoLabel}</b></p>`;
    return;
  }

  const ligas = d.minhas_ligas || [];
  const blocoLiga = ligas.length ? `
    <div class="lot-cfg-row">
      <select id="ltEditLiga">
        ${ligas.map(l => `<option value="${_laEsc(l)}"${l === d.league ? ' selected' : ''}>${_laEsc(l)}</option>`).join('')}
      </select>
      <button type="button" class="btn-ghost" id="ltSalvarLiga">Salvar liga</button>
    </div>` : '';

  if (d.bloqueada) {
    box.innerHTML = `
      ${blocoLiga}
      <div class="lot-aviso">
        <i class="bi bi-lock-fill"></i>
        <span>A loteria já começou — título, participantes e chances não podem mais mudar. Pra recomeçar do zero, use <b>Reiniciar</b>.</span>
      </div>
      <p style="font-size:12px;color:var(--text-3);margin:12px 0 0">Tipo: <b style="color:var(--text)">${tipoLabel}</b> · Notificação: <b style="color:var(--text)">${d.notificar_saida ? 'ativada' : 'desativada'}</b></p>`;
    laLigarBotaoLiga();
    return;
  }

  const soma = (d.na_urna || []).reduce((s, p) => s + Number(p.chance), 0);
  box.innerHTML = `
    ${blocoLiga}
    <div class="lot-cfg-row">
      <input type="text" id="ltEditTitulo" value="${_laEsc(d.titulo)}" aria-label="Título da loteria">
      <button type="button" class="btn-ghost" id="ltSalvarTitulo">Salvar</button>
    </div>
    <div style="font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:6px">Chance de cada participante</div>
    <div class="lot-chance-edit" id="ltChancesEdit">
      ${(d.na_urna || []).map(p => `
        <div class="lot-urna-item">
          <span class="lot-urna-nome">${_laEsc(p.nome_display)}</span>
          <input type="number" min="0" max="100" step="0.1" value="${Number(p.chance)}" data-pid="${p.id}" aria-label="Chance de ${_laEsc(p.nome_display)}">
          <span class="lot-urna-base">%</span>
        </div>`).join('')}
    </div>
    <div style="font-size:12px;font-weight:700;margin-bottom:12px;color:${Math.abs(soma - 100) < 0.011 ? 'var(--green)' : 'var(--amber)'}" id="ltSomaEdit">Soma: ${soma.toFixed(1)}%</div>
    <button type="button" class="btn-orange" id="ltSalvarChances" style="width:100%">Salvar chances</button>
    <p style="font-size:11px;color:var(--text-3);margin:10px 0 0">Não precisa fechar em 100% — as chances valem umas em relação às outras.</p>`;

  laLigarBotaoLiga();
  document.getElementById('ltSalvarTitulo').addEventListener('click', laSalvarTitulo);
  document.getElementById('ltSalvarChances').addEventListener('click', laSalvarChances);
  document.querySelectorAll('#ltChancesEdit input').forEach(inp => {
    inp.addEventListener('input', () => {
      const total = [...document.querySelectorAll('#ltChancesEdit input')]
        .reduce((s, i) => s + (Number(i.value) || 0), 0);
      const el = document.getElementById('ltSomaEdit');
      el.textContent = `Soma: ${total.toFixed(1)}%`;
      el.style.color = Math.abs(total - 100) < 0.011 ? 'var(--green)' : 'var(--amber)';
    });
  });
}

function laLigarBotaoLiga() {
  const btn = document.getElementById('ltSalvarLiga');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      await _laFetch('/api/loteria-aleatoria.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'definir_liga', id: window.LOTERIA_ID, league: document.getElementById('ltEditLiga').value }),
      });
      laCarregar();
    } catch (e) {
      alert(e.message);
    } finally {
      btn.disabled = false;
    }
  });
}

async function laSalvarTitulo() {
  const titulo = document.getElementById('ltEditTitulo').value.trim();
  if (!titulo) { alert('O título não pode ficar vazio.'); return; }
  try {
    laEstado = await _laFetch('/api/loteria-aleatoria.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'editar', id: window.LOTERIA_ID, titulo }),
    });
    laRenderTudo();
  } catch (e) { alert(e.message); }
}

async function laSalvarChances() {
  const chances = {};
  document.querySelectorAll('#ltChancesEdit input').forEach(inp => {
    chances[inp.dataset.pid] = Number(inp.value) || 0;
  });
  const btn = document.getElementById('ltSalvarChances');
  btn.disabled = true;
  try {
    laEstado = await _laFetch('/api/loteria-aleatoria.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'editar', id: window.LOTERIA_ID, chances }),
    });
    laRenderTudo();
  } catch (e) {
    alert(e.message);
    btn.disabled = false;
  }
}

/** Cria outra loteria com os mesmos participantes e chances — sem o sorteio. */
async function laDuplicar() {
  const nome = prompt('Nome da cópia:', `${laEstado?.titulo || 'Loteria'} (cópia)`);
  if (nome === null) return;
  if (!nome.trim()) { alert('O nome não pode ficar vazio.'); return; }
  try {
    const data = await _laFetch('/api/loteria-aleatoria.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'duplicar', id: window.LOTERIA_ID, titulo: nome.trim() }),
    });
    window.location.href = `/loteria-aleatoria.php?id=${data.id}`;
  } catch (e) { alert(e.message); }
}

async function laReiniciar() {
  if (!confirm('Reiniciar a loteria? Todas as escolhas já sorteadas são apagadas e tudo volta pra urna.')) return;
  try {
    laEstado = await _laFetch('/api/loteria-aleatoria.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'reiniciar', id: window.LOTERIA_ID }),
    });
    laRenderTudo();
  } catch (e) { alert(e.message); }
}

async function laExcluir() {
  if (!confirm(`Excluir a loteria "${laEstado.titulo}"? Não dá pra desfazer.`)) return;
  try {
    await _laFetch('/api/loteria-aleatoria.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'excluir', id: window.LOTERIA_ID }),
    });
    window.location.href = '/loterias-aleatorias.php';
  } catch (e) { alert(e.message); }
}

function laCopiarOrdem() {
  const lista = laEstado.sorteados || [];
  if (!lista.length) { alert('Nenhuma escolha definida ainda.'); return; }
  const texto = `*${laEstado.titulo}*\n` +
    lista.map(p => `${p.pick_number} - ${p.nome_display}`).join('\n');
  const btn = document.getElementById('ltCopiar');
  const original = btn.innerHTML;
  navigator.clipboard.writeText(texto).then(() => {
    btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copiado!';
    setTimeout(() => { btn.innerHTML = original; }, 1800);
  }).catch(() => alert('Não consegui copiar.'));
}

document.addEventListener('DOMContentLoaded', () => {
  laCarregar();
  const ligar = (id, fn) => { const el = document.getElementById(id); if (el) el.addEventListener('click', fn); };
  ligar('ltSortear', laSortear);
  ligar('ltDuplicar', laDuplicar);
  ligar('ltReiniciar', laReiniciar);
  ligar('ltExcluir', laExcluir);
  ligar('ltCopiar', laCopiarOrdem);
});
