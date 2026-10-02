/**
 * FBAX — o Twitter da FBA. @see backend/fbax.php pro modelo de dados.
 *
 * A tela inteira é uma lista só. Não há aba, não há grid, não há story: o
 * Timeline antigo tinha os três e o resultado foi que ninguém postava, porque
 * cada um deles pedia uma decisão antes de escrever a primeira palavra.
 */
(() => {
  'use strict';

  const API = '/api/fbax.php';
  const MAX = 600;

  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  let liga = '';
  let meuTime = 0;
  let posso = false;
  let fotoBase64 = '';
  let carregando = false;
  let ultimaData = null;

  /* ── Quanto tempo faz ──────────────────────────────────────────────
     Relativo até uma semana, data depois: "há 9 dias" não diz nada, e a
     pergunta a partir daí deixa de ser "foi agora?" e vira "foi quando?". */
  function quando(iso) {
    const t = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(t)) return '';
    const seg = Math.floor((Date.now() - t.getTime()) / 1000);
    if (seg < 60) return 'agora';
    if (seg < 3600) return Math.floor(seg / 60) + 'min';
    if (seg < 86400) return Math.floor(seg / 3600) + 'h';
    if (seg < 604800) return Math.floor(seg / 86400) + 'd';
    return t.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
  }

  const escudo = (p) => p.team_photo
    ? `<img class="avatar" src="${esc(p.team_photo)}" alt="" onerror="this.style.visibility='hidden'">`
    : `<div class="avatar"></div>`;

  /** O miolo de um post: cabeçalho, texto, foto. Sem ações — quem chama decide. */
  function miolo(p, pequeno) {
    const cls = pequeno ? 'fx-citado-topo' : 'fx-topo';
    return `
      <div class="${cls}">
        ${pequeno ? escudo(p) : ''}
        <span class="fx-time">${esc(p.team_name)}</span>
        <span class="fx-liga">${esc(p.team_league)}</span>
        ${pequeno ? '' : `<span class="fx-gm">· ${esc(p.author_name)}</span>`}
        <span class="fx-quando">· ${quando(p.created_at)}</span>
      </div>
      ${p.texto ? `<div class="fx-texto">${esc(p.texto)}</div>` : ''}
      ${p.photo_url ? `<div class="fx-foto"><img src="${esc(p.photo_url)}" alt="" loading="lazy"></div>` : ''}`;
  }

  /** Um post inteiro, com as ações embaixo. */
  function cartao(p, opts = {}) {
    const citado = p.repost_of_id
      ? (p.original
          ? `<div class="fx-citado">${miolo(p.original, true)}</div>`
          : `<div class="fx-citado"><span class="fx-sumiu">Esse post foi removido.</span></div>`)
      : '';

    /* REPOST SECO MOSTRA O ORIGINAL, não uma casca vazia. Quem reposta sem
       escrever nada está dizendo "leiam isto" — o que tem que aparecer é o
       isto, com uma tarja em cima dizendo quem trouxe de volta. */
    const seco = p.repost_of_id && !p.texto && !p.photo_url;
    const marca = seco
      ? `<div class="fx-marca"><i class="bi bi-arrow-repeat"></i> ${esc(p.team_name)} repostou</div>`
      : '';
    const alvo = seco && p.original ? p.original : p;

    /* NO REPOST SECO AS AÇÕES SÃO DO ORIGINAL. Quem responde a um RT está
       respondendo ao post, não ao gesto de repostar — e contar curtida no
       RT separaria o placar em dois, com o original mostrando 3 e o RT 1,
       sem ninguém saber qual é o número. Só o apagar continua sendo do RT:
       ali o que se desfaz é o próprio repost. */
    const a = seco && p.original ? p.original : p;

    return `
      <article class="fx-post${opts.alvo ? ' alvo' : ''}" data-id="${p.id}">
        ${escudo(alvo)}
        <div class="fx-corpo">
          ${marca}
          ${seco && p.original ? miolo(p.original, false) : miolo(p, false)}
          ${seco ? '' : citado}
          <div class="fx-acoes">
            <button class="fx-acao responder" data-acao="responder" data-id="${a.id}" title="Responder">
              <i class="bi bi-chat"></i>${a.respostas || ''}</button>
            <button class="fx-acao repostar${a.repostei ? ' on' : ''}" data-acao="repostar" data-id="${a.id}" title="Repostar">
              <i class="bi bi-arrow-repeat"></i>${a.reposts || ''}</button>
            <button class="fx-acao curtir${a.curti ? ' on' : ''}" data-acao="curtir" data-id="${a.id}" title="Curtir">
              <i class="bi bi-heart${a.curti ? '-fill' : ''}"></i>${a.curtidas || ''}</button>
            ${p.team_id === meuTime
              ? `<button class="fx-acao apagar" data-acao="apagar" data-id="${p.id}" title="Apagar"><i class="bi bi-trash3"></i></button>`
              : ''}
          </div>
        </div>
      </article>`;
  }

  async function api(metodo, corpo, query) {
    const r = await fetch(API + (query || ''), metodo === 'GET' ? {} : {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo)
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Deu erro aqui.');
    return d;
  }

  // ── O feed ────────────────────────────────────────────────────────
  async function carregar(mais) {
    if (carregando) return;
    carregando = true;
    const lista = $('fxFeed');
    try {
      const q = `?action=feed${liga ? '&league=' + encodeURIComponent(liga) : ''}`
              + (mais && ultimaData ? '&before=' + encodeURIComponent(ultimaData) : '');
      const d = await api('GET', null, q);

      meuTime = d.meu_time || 0;
      posso = !!d.posso_postar;
      $('fxCompositor').style.display = posso ? '' : 'none';

      const html = (d.posts || []).map((p) => cartao(p)).join('');
      if (mais) lista.insertAdjacentHTML('beforeend', html);
      else lista.innerHTML = html || `<div class="fx-vazio">Ainda não tem nada por aqui. Seja o primeiro.</div>`;

      if (d.posts && d.posts.length) ultimaData = d.posts[d.posts.length - 1].created_at;
      $('fxMais').style.display = (d.posts || []).length >= 20 ? '' : 'none';
    } catch (e) {
      if (!mais) lista.innerHTML = `<div class="fx-vazio">${esc(e.message)}</div>`;
    } finally {
      carregando = false;
    }
  }

  // ── O compositor ──────────────────────────────────────────────────
  function medirTexto() {
    const t = $('fxTexto');
    const resta = MAX - t.value.length;
    const c = $('fxConta');
    c.textContent = resta;
    c.className = 'fx-conta' + (resta < 0 ? ' estourou' : resta <= 40 ? ' perto' : '');
    $('fxPostar').disabled = (t.value.trim() === '' && !fotoBase64) || resta < 0;
    // Cresce com o conteúdo. Altura em border-box precisa da borda somada,
    // senão a última linha fica cortada.
    t.style.height = 'auto';
    t.style.height = (t.scrollHeight + (t.offsetHeight - t.clientHeight)) + 'px';
  }

  function limparCompositor() {
    $('fxTexto').value = '';
    fotoBase64 = '';
    $('fxPrevia').style.display = 'none';
    $('fxArquivo').value = '';
    medirTexto();
  }

  function lerFoto(arquivo) {
    if (!arquivo) return;
    const fr = new FileReader();
    fr.onload = () => {
      fotoBase64 = String(fr.result);
      $('fxPreviaImg').src = fotoBase64;
      $('fxPrevia').style.display = '';
      medirTexto();
    };
    fr.readAsDataURL(arquivo);
  }

  async function postar() {
    const btn = $('fxPostar');
    btn.disabled = true;
    try {
      const d = await api('POST', { action: 'postar', texto: $('fxTexto').value, photo_base64: fotoBase64 });
      limparCompositor();
      /* Entra na frente sem recarregar: quem acabou de escrever quer ver o que
         escreveu, e um reload jogaria a rolagem pro topo e piscaria a tela. */
      if (d.post) {
        const lista = $('fxFeed');
        if (lista.querySelector('.fx-vazio')) lista.innerHTML = '';
        lista.insertAdjacentHTML('afterbegin', cartao(d.post));
      }
    } catch (e) {
      alert(e.message);
      btn.disabled = false;
    }
  }

  // ── As ações de um post ───────────────────────────────────────────
  async function agir(acao, id, elemento) {
    if (acao === 'curtir') {
      const d = await api('POST', { action: 'curtir', post_id: id });
      document.querySelectorAll(`.fx-acao.curtir[data-id="${id}"]`).forEach((b) => {
        b.classList.toggle('on', d.curti);
        b.innerHTML = `<i class="bi bi-heart${d.curti ? '-fill' : ''}"></i>${d.curtidas || ''}`;
      });
      return;
    }

    /* O REPOST ABRE O MENU, não reposta direto: a partir daqui saem dois
       caminhos (seco e citado) e o botão sozinho só dava um. O texto da
       primeira opção vira "Desfazer" quando já está reposto, pra o menu
       dizer o que vai acontecer em vez de o usuário descobrir clicando. */
    if (acao === 'repostar') {
      abrirMenuRepost(id, elemento, elemento.classList.contains('on'));
      return;
    }

    if (acao === 'apagar') {
      if (!confirm('Apagar este post? As respostas vão junto.')) return;
      await api('POST', { action: 'apagar', post_id: id });
      ultimaData = null;
      await carregar(false);
      if ($('fxModal').classList.contains('show')) fecharThread();
      return;
    }

    if (acao === 'responder') abrirThread(id);
  }

  // ── Repostar: seco ou citando ─────────────────────────────────────
  let alvoRepost = 0;
  let fotoCitar = '';

  function abrirMenuRepost(id, botao, jaRepostou) {
    alvoRepost = id;
    $('fxRpSecoTxt').textContent = jaRepostou ? 'Desfazer repost' : 'Repostar';
    const m = $('fxMenuRepost');
    const r = botao.getBoundingClientRect();
    m.classList.add('show');
    /* Mede DEPOIS de mostrar e sobe quando não cabe embaixo: no último post
       da tela o menu nascia metade fora da janela. */
    const alt = m.offsetHeight;
    m.style.top = (r.bottom + alt + 8 > window.innerHeight ? r.top - alt - 6 : r.bottom + 6) + 'px';
    m.style.left = Math.max(8, Math.min(r.left, window.innerWidth - m.offsetWidth - 8)) + 'px';
  }

  const fecharMenuRepost = () => $('fxMenuRepost').classList.remove('show');

  async function repostarSeco() {
    fecharMenuRepost();
    const d = await api('POST', { action: 'repostar', post_id: alvoRepost });
    // Repostar muda contador em dois lugares (o post e o repost), então aqui
    // vale recarregar — é o único caso em que a lista muda de tamanho.
    if (d.desfez || d.post) { ultimaData = null; await carregar(false); }
  }

  async function abrirCitar() {
    fecharMenuRepost();
    fotoCitar = '';
    $('fxCitarTexto').value = '';
    $('fxCitarPrevia').style.display = 'none';
    $('fxCitarArquivo').value = '';
    $('fxCitarConta').textContent = MAX;
    /* O POST CITADO FICA À VISTA enquanto se escreve. Citar sem ver o que
       se cita é como responder de memória. */
    $('fxCitarAlvo').innerHTML = '<div class="fx-citado">Carregando…</div>';
    $('fxCitar').classList.add('show');
    $('fxCitarTexto').focus();
    try {
      const d = await api('GET', null, `?action=thread&id=${alvoRepost}`);
      $('fxCitarAlvo').innerHTML = `<div class="fx-citado">${miolo(d.post, true)}</div>`;
    } catch (e) {
      $('fxCitarAlvo').innerHTML = `<div class="fx-citado"><span class="fx-sumiu">${esc(e.message)}</span></div>`;
    }
  }

  async function enviarCitacao() {
    const btn = $('fxCitarEnviar');
    const texto = $('fxCitarTexto').value;
    if (texto.trim() === '' && !fotoCitar) return;
    btn.disabled = true;
    try {
      await api('POST', { action: 'repostar', post_id: alvoRepost, texto, photo_base64: fotoCitar });
      $('fxCitar').classList.remove('show');
      ultimaData = null;
      await carregar(false);
    } catch (e) { alert(e.message); } finally { btn.disabled = false; }
  }

  // ── A thread ──────────────────────────────────────────────────────
  async function abrirThread(id) {
    const cx = $('fxThread');
    cx.innerHTML = '<div class="fx-vazio">Carregando…</div>';
    $('fxModal').classList.add('show');
    try {
      const d = await api('GET', null, `?action=thread&id=${id}`);
      cx.innerHTML = cartao(d.post, { alvo: true })
        + (posso ? `
          <div class="fx-box" style="margin-top:12px">
            <textarea id="fxResposta" placeholder="Responder…" maxlength="600"></textarea>
            <div class="fx-box-pe">
              <span class="fx-conta" id="fxContaResp">600</span>
              <button type="button" class="fx-enviar" id="fxEnviarResp" data-id="${id}">Responder</button>
            </div>
          </div>` : '')
        + (d.respostas || []).map((r) => cartao(r)).join('');

      const ta = $('fxResposta');
      if (ta) {
        ta.addEventListener('input', () => {
          const resta = MAX - ta.value.length;
          $('fxContaResp').textContent = resta;
          ta.style.height = 'auto';
          ta.style.height = (ta.scrollHeight + (ta.offsetHeight - ta.clientHeight)) + 'px';
        });
        ta.focus();
      }
    } catch (e) {
      cx.innerHTML = `<div class="fx-vazio">${esc(e.message)}</div>`;
    }
  }

  function fecharThread() {
    $('fxModal').classList.remove('show');
    $('fxThread').innerHTML = '';
  }

  // ── Ligações ──────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    meuTime = window.MY_TEAM_ID || 0;

    $('fxChips').addEventListener('click', (e) => {
      const b = e.target.closest('.fx-chip');
      if (!b) return;
      $('fxChips').querySelectorAll('.fx-chip').forEach((x) => x.classList.remove('active'));
      b.classList.add('active');
      liga = b.dataset.league || '';
      ultimaData = null;
      carregar(false);
    });

    $('fxTexto').addEventListener('input', medirTexto);
    $('fxBtnFoto').addEventListener('click', () => $('fxArquivo').click());
    $('fxArquivo').addEventListener('change', (e) => lerFoto(e.target.files[0]));
    $('fxTirarFoto').addEventListener('click', () => {
      fotoBase64 = ''; $('fxPrevia').style.display = 'none'; $('fxArquivo').value = ''; medirTexto();
    });
    $('fxPostar').addEventListener('click', postar);
    $('fxMais').addEventListener('click', () => carregar(true));
    $('fxFechar').addEventListener('click', fecharThread);

    // ── O menu do repost e o compositor da citação ──────────────────
    $('fxMenuRepost').addEventListener('click', (e) => {
      const b = e.target.closest('[data-rp]');
      if (!b) return;
      (b.dataset.rp === 'seco' ? repostarSeco() : abrirCitar()).catch?.((err) => alert(err.message));
    });
    // Clique fora e rolagem fecham o menu: ele é posicionado em pixel fixo,
    // então rolar sem fechar o deixaria flutuando longe do botão.
    document.addEventListener('click', (e) => {
      if (!e.target.closest('#fxMenuRepost') && !e.target.closest('.fx-acao.repostar')) fecharMenuRepost();
    }, true);
    window.addEventListener('scroll', fecharMenuRepost, true);

    $('fxCitarFechar').addEventListener('click', () => $('fxCitar').classList.remove('show'));
    $('fxCitar').addEventListener('click', (e) => { if (e.target === $('fxCitar')) $('fxCitar').classList.remove('show'); });
    $('fxCitarEnviar').addEventListener('click', enviarCitacao);
    $('fxCitarBtnFoto').addEventListener('click', () => $('fxCitarArquivo').click());
    $('fxCitarArquivo').addEventListener('change', (e) => {
      const f = e.target.files[0];
      if (!f) return;
      const fr = new FileReader();
      fr.onload = () => { fotoCitar = String(fr.result); $('fxCitarPreviaImg').src = fotoCitar; $('fxCitarPrevia').style.display = ''; };
      fr.readAsDataURL(f);
    });
    $('fxCitarTirarFoto').addEventListener('click', () => {
      fotoCitar = ''; $('fxCitarPrevia').style.display = 'none'; $('fxCitarArquivo').value = '';
    });
    $('fxCitarTexto').addEventListener('input', () => {
      const ta = $('fxCitarTexto');
      $('fxCitarConta').textContent = MAX - ta.value.length;
      ta.style.height = 'auto';
      ta.style.height = (ta.scrollHeight + (ta.offsetHeight - ta.clientHeight)) + 'px';
    });
    $('fxModal').addEventListener('click', (e) => { if (e.target === $('fxModal')) fecharThread(); });

    /* Um ouvinte só, no documento: o feed é reescrito a cada carga e pendurar
       listener em cada botão vazaria um por post a cada rolagem. */
    document.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-acao]');
      if (b) {
        e.preventDefault();
        try { await agir(b.dataset.acao, +b.dataset.id, b); } catch (err) { alert(err.message); }
        return;
      }
      const enviar = e.target.closest('#fxEnviarResp');
      if (enviar) {
        const ta = $('fxResposta');
        if (!ta || ta.value.trim() === '') return;
        enviar.disabled = true;
        try {
          await api('POST', { action: 'responder', post_id: +enviar.dataset.id, texto: ta.value });
          await abrirThread(+enviar.dataset.id);
          ultimaData = null;
          carregar(false);
        } catch (err) { alert(err.message); enviar.disabled = false; }
      }
    });

    carregar(false);
  });
})();
