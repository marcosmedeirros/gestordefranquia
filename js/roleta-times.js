/**
 * Roleta dos 32 Times — sorteio da ordem inversa de escolha.
 * O primeiro a sair fica com a escolha 32, o seguinte com a 31, até a 1.
 */

const _rtEsc = s => (s == null ? '' : String(s))
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const RT_CORES = ['#fc0025', '#f59e0b', '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899',
                  '#06b6d4', '#eab308', '#f97316', '#14b8a6', '#a855f7', '#ef4444'];
const RT_LOGO_PADRAO = '/img/default-team.png';
const RT_SEM_MOVIMENTO = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let rtUrnaRenderizada = [];   // [{id, team_name, ...}] na ordem desenhada na roleta
let rtGirando = false;
let rtUltimoSorteados = [];   // último histórico renderizado, pra "copiar tudo"
let rtUltimoTotal = 0;

/** Copia texto pro clipboard (com fallback pra navegadores/contextos sem a API). */
async function rtCopiar(texto, btnEl) {
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
window.rtCopiar = rtCopiar;

async function _rtFetch(url, options = {}) {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...options });
  const txt = await res.text();
  let data;
  try { data = JSON.parse(txt); }
  catch (e) { throw new Error('Resposta inválida do servidor'); }
  if (!data.success) throw new Error(data.error || 'Erro desconhecido');
  return data;
}

async function rtCarregar() {
  try {
    const data = await _rtFetch('api/roleta-times.php');
    rtRenderTudo(data);
  } catch (e) {
    const el = document.getElementById('rtHistorico');
    if (el) el.innerHTML = `<p style="color:#ef4444;font-size:13px">${_rtEsc(e.message)}</p>`;
  }
}

function rtRenderTudo(data) {
  rtUrnaRenderizada = data.na_urna || [];
  rtUltimoSorteados = data.sorteados || [];
  rtUltimoTotal = data.total || 0;
  rtRenderRoleta(rtUrnaRenderizada);
  rtRenderUrna(rtUrnaRenderizada);
  rtRenderHistorico(rtUltimoSorteados, rtUltimoTotal);
  rtRenderResumo(data);
  rtAtualizarBotao(data);
}

function rtRenderResumo(data) {
  const el = document.getElementById('rtResumo');
  if (!el) return;
  const faltam = (data.na_urna || []).length;
  const saiu = (data.sorteados || []).length;
  if (data.concluido) {
    el.innerHTML = `<span class="rt-chip ok"><i class="bi bi-check-circle-fill"></i>Sorteio concluído — 32 escolhas definidas</span>
                    <a href="/legends-draft.php" class="rt-chip next" style="text-decoration:none"><i class="bi bi-stars"></i>Ir para o Draft de Lendas</a>`;
    return;
  }
  el.innerHTML = `<span class="rt-chip"><i class="bi bi-hourglass-split"></i>${faltam} na urna</span>
                  <span class="rt-chip"><i class="bi bi-list-ol"></i>${saiu} já sorteados</span>
                  ${faltam ? `<span class="rt-chip next">Próxima a sair: <b>escolha ${faltam}</b></span>` : ''}`;
}

function rtRenderRoleta(urna) {
  const roda = document.getElementById('rtRoda');
  if (!roda) return;

  // Zera a rotação sem animar antes de redesenhar.
  roda.style.transition = 'none';
  roda.style.transform = 'rotate(0deg)';
  void roda.offsetWidth;
  roda.style.transition = '';

  if (!urna.length) {
    roda.innerHTML = `<div class="rt-roda-vazia">Todos os 32 times já saíram.</div>`;
    return;
  }

  const n = urna.length;
  const ang = 360 / n;
  const stops = urna.map((t, i) => {
    const c = RT_CORES[i % RT_CORES.length];
    return `${c} ${i * ang}deg ${(i + 1) * ang}deg`;
  }).join(', ');

  let html = `<div class="rt-fatias" style="background:conic-gradient(from 0deg, ${stops})"></div>`;

  // Sempre mostra o nome de todo mundo (32 fatias inclusive) — o que muda com
  // mais gente na urna é o tamanho da letra, pra caber sem sobrepor a fatia
  // vizinha. O rótulo é um risco radial: o "comprimento" do texto vai em
  // direção ao raio (tem espaço de sobra), quem aperta é a altura da linha
  // contra o arco entre uma fatia e a outra — por isso a fonte encolhe com n.
  const fonte = n <= 8 ? 14 : n <= 16 ? 12 : n <= 24 ? 10 : 8.5;
  urna.forEach((t, i) => {
    const meio = i * ang + ang / 2;
    // A faixa do rótulo aponta para "leste" na orientação neutra do CSS, mas
    // o conic-gradient começa no norte — daí os 90° de correção.
    html += `<div class="rt-rotulo" style="transform:rotate(${meio - 90}deg)">
               <span style="font-size:${fonte}px">${_rtEsc(rtNomeCurtoGM(t.gm_name))}</span>
             </div>`;
  });
  roda.innerHTML = html;
}

/**
 * Nome curto do GM pra caber na fatia da roleta: usa o apelido entre
 * parênteses se houver (ex.: "Caio ... (Zaza)" -> "Zaza"), senão o primeiro
 * nome (ex.: "Jose Vinicius Leal Cortez" -> "Jose").
 */
function rtNomeCurtoGM(nome) {
  const s = String(nome || '').trim();
  const apelido = s.match(/\(([^)]+)\)/);
  if (apelido) return apelido[1].trim();
  return s.split(/\s+/)[0] || s;
}

function rtRenderUrna(urna) {
  const el = document.getElementById('rtUrna');
  if (!el) return;
  if (!urna.length) {
    el.innerHTML = `<div class="rt-vazio"><i class="bi bi-inbox"></i><p>Urna vazia — todos já saíram.</p></div>`;
    return;
  }
  el.innerHTML = urna.map(t => `
    <div class="rt-urna-item" id="rt-urna-${t.id}">
      <img src="${_rtEsc(t.photo_url || RT_LOGO_PADRAO)}" alt="" onerror="this.src='${RT_LOGO_PADRAO}'">
      <div class="rt-urna-txt">
        <div class="rt-urna-gm">${_rtEsc(t.gm_name)}</div>
      </div>
    </div>`).join('');
}

/** Histórico na ordem em que os times foram saindo. */
function rtRenderHistorico(sorteados, total) {
  const el = document.getElementById('rtHistorico');
  if (!el) return;
  if (!sorteados.length) {
    el.innerHTML = `<div class="rt-vazio"><i class="bi bi-clock-history"></i><p>Nenhum time sorteado ainda.</p></div>`;
    return;
  }
  el.innerHTML = sorteados.map((t) => {
    const ordem = total - t.pick_number + 1;
    const quando = t.eliminated_at
      ? new Date(String(t.eliminated_at).replace(' ', 'T')).toLocaleString('pt-BR',
          { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
      : '';
    return `
      <div class="rt-hist-linha">
        <span class="rt-hist-saida">${ordem}º a sair</span>
        <span class="rt-hist-pick">Escolha ${t.pick_number}</span>
        <img class="rt-hist-logo" src="${_rtEsc(t.photo_url || RT_LOGO_PADRAO)}" alt="" onerror="this.src='${RT_LOGO_PADRAO}'">
        <span class="rt-hist-time">${_rtEsc(t.gm_name)}</span>
        <span class="rt-hist-hora">${_rtEsc(quando)}</span>
        <button type="button" class="rt-copy-btn" title="Copiar para colar no WhatsApp"
                data-copy-pick="${t.pick_number}" data-copy-gm="${_rtEsc(t.gm_name)}">
          <i class="bi bi-clipboard"></i>
        </button>
      </div>`;
  }).join('');
}

/** Copia a ordem inteira (pick 1 ao último), pronta pra colar no WhatsApp. */
function rtCopiarTudo(btnEl) {
  if (!rtUltimoSorteados.length) return;
  const texto = rtUltimoSorteados
    .slice()
    .sort((a, b) => a.pick_number - b.pick_number)
    .map(t => `Pick ${t.pick_number}, ${t.gm_name}`)
    .join('\n');
  rtCopiar(texto, btnEl);
}
window.rtCopiarTudo = rtCopiarTudo;

// Delegação: um listener só para todos os botões de copiar (histórico e anúncio).
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.rt-copy-btn');
  if (!btn) return;
  const pick = btn.getAttribute('data-copy-pick');
  const gm = btn.getAttribute('data-copy-gm');
  rtCopiar(`Pick ${pick}, ${gm}`, btn);
});

function rtAtualizarBotao(data) {
  const btn = document.getElementById('rtGirar');
  if (!btn) return;
  const acabou = !!data.concluido;
  btn.disabled = rtGirando || acabou;
  btn.innerHTML = acabou
    ? '<i class="bi bi-check2-all me-1"></i>Sorteio concluído'
    : `<i class="bi bi-arrow-repeat me-1"></i>Girar (define a escolha ${(data.na_urna || []).length})`;
}

async function rtGirar() {
  if (rtGirando || !rtUrnaRenderizada.length) return;
  rtGirando = true;
  const btn = document.getElementById('rtGirar');
  if (btn) btn.disabled = true;

  const urnaNoGiro = rtUrnaRenderizada.slice();

  try {
    const data = await _rtFetch('api/roleta-times.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'girar' })
    });

    const idx = urnaNoGiro.findIndex(t => Number(t.id) === Number(data.sorteado_id));
    const roda = document.getElementById('rtRoda');

    if (roda && idx !== -1 && !RT_SEM_MOVIMENTO) {
      const n = urnaNoGiro.length;
      const ang = 360 / n;
      const meio = idx * ang + ang / 2;
      const voltas = 5;                       // só efeito visual
      roda.style.transform = `rotate(${voltas * 360 + (360 - meio)}deg)`;
      await new Promise(r => setTimeout(r, 4300));
    }

    rtAnunciar(data);
    rtRenderTudo(data);
  } catch (e) {
    alert(e.message);
  } finally {
    rtGirando = false;
    rtAtualizarBotao({ na_urna: rtUrnaRenderizada, concluido: false });
  }
}

/** Destaque de quem acabou de sair, com botão pra copiar e colar no WhatsApp. */
function rtAnunciar(data) {
  const el = document.getElementById('rtAnuncio');
  if (!el) return;
  el.innerHTML = `
    <div class="rt-anuncio-box">
      <div class="rt-anuncio-pick">Escolha ${data.pick}</div>
      <div class="rt-anuncio-time">${_rtEsc(data.gm_name)}</div>
      <button type="button" class="rt-copy-btn rt-copy-btn-grande" title="Copiar para colar no WhatsApp"
              data-copy-pick="${data.pick}" data-copy-gm="${_rtEsc(data.gm_name)}">
        <i class="bi bi-clipboard me-1"></i>Copiar
      </button>
    </div>`;
  el.classList.remove('mostrar');
  void el.offsetWidth;
  el.classList.add('mostrar');
}

async function rtReiniciar() {
  if (rtGirando) return;
  if (!confirm('Reiniciar o sorteio?\n\nTodas as 32 escolhas definidas serão apagadas e todos os times voltam para a urna.')) return;
  try {
    const data = await _rtFetch('api/roleta-times.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'reiniciar' })
    });
    document.getElementById('rtAnuncio').innerHTML = '';
    rtRenderTudo(data);
  } catch (e) {
    alert(e.message);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  rtCarregar();
  document.getElementById('rtGirar')?.addEventListener('click', rtGirar);
  document.getElementById('rtReiniciar')?.addEventListener('click', rtReiniciar);
});
