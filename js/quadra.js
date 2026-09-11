/**
 * QUADRA DE ESCALAÇÃO — Meu Elenco.
 *
 * Meia quadra com os cinco lugares (um por posição principal), o banco e a
 * G-League. Escalar é arrastar o jogador pro destino — ou, no celular, tocar
 * nele e depois no destino (arrastar com o dedo não funciona no Safari).
 *
 * Cada movimento SALVA NA HORA (api/players.php, action=set_lineup). Um
 * movimento pode mexer em dois jogadores — quem entra e quem sai do lugar — e
 * os dois vão juntos: trocar dois armadores um de cada vez esbarraria na regra
 * de "uma posição de cada" no primeiro save. Se o servidor recusar, a quadra
 * volta pro que está gravado e diz o motivo.
 *
 * A barra "Salvar escalação" só aparece quando a própria quadra achou algo a
 * corrigir ao abrir (dois titulares na mesma posição): isso não foi escolha do
 * GM, então não grava sem ele confirmar.
 *
 * As travas daqui são as do servidor, repetidas só pra acender o lugar certo
 * enquanto se arrasta — quem decide continua sendo a API.
 *
 * Depende de my-roster-v2.js pra foto, cor do OVR, salário e recarregar o
 * elenco (getPlayerPhotoUrl, getOvrColor, playerSalary, loadPlayers).
 */
(function () {
  'use strict';

  const POS = ['PG', 'SG', 'SF', 'PF', 'C'];
  const CORES = { PG: '#3b82f6', SG: '#06b6d4', SF: '#22c55e', PF: '#f59e0b', C: '#ef4444' };
  // Onde cada lugar fica na meia quadra (cesta embaixo), em % da caixa.
  const LUGAR = { PG: [50, 17], SG: [83, 38], SF: [17, 38], PF: [69, 71], C: [31, 71] };

  const SVG = `<svg viewBox="0 0 500 470" preserveAspectRatio="none" aria-hidden="true">
    <rect x="1" y="1" width="498" height="468"/>
    <path d="M190 1 A60 60 0 0 0 310 1"/>
    <rect x="170" y="280" width="160" height="189"/>
    <path d="M190 280 A60 60 0 0 1 310 280"/>
    <path d="M30 469 V350 A232 232 0 0 1 470 350 V469"/>
    <line x1="220" y1="442" x2="280" y2="442"/>
    <circle cx="250" cy="427" r="10"/>
  </svg>`;

  let jogadores = [];
  let base = {};        // id -> função no servidor
  let pend = {};        // id -> função ainda não salva
  let chave = '';       // retrato do elenco: muda quando o servidor muda
  let selecionado = null;
  let arrastando = null;
  let salvando = false; // um save por vez: mexer de novo no meio embaralharia o que foi pro servidor
  let msg = { tipo: '', html: '' };

  const raizEl = () => document.getElementById('quadra-escalacao');
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const normRole = r => (typeof normalizeRoleKey === 'function' ? normalizeRoleKey(r) : String(r || ''));
  const posDe = j => String(j.position || '').toUpperCase().trim();
  const porId = id => jogadores.find(j => String(j.id) === String(id));
  const roleDe = id => (id in pend ? pend[id] : base[id]);
  const vagasGL = () => Number(window.__GLEAGUE_VAGAS__ || 0);
  const foto = j => (typeof getPlayerPhotoUrl === 'function' ? getPlayerPhotoUrl(j) : '');
  const corOvr = o => (typeof getOvrColor === 'function' ? getOvrColor(Number(o)) : 'var(--text)');
  const reserva = j => `https://ui-avatars.com/api/?name=${encodeURIComponent(j.name || '?')}&background=121212&color=f17507&rounded=true&bold=true`;
  const sobrenome = n => {
    const p = String(n || '').trim().split(/\s+/);
    return p.length > 1 ? p.slice(1).join(' ') : (p[0] || '');
  };
  const sel = j => (j && String(selecionado) === String(j.id) ? ' sel' : '');

  function aviso(tipo, html) { msg = { tipo, html }; }
  function titularDe(p) { return jogadores.find(j => roleDe(j.id) === 'Titular' && posDe(j) === p); }
  function mudancas() { return Object.keys(pend).filter(id => pend[id] !== base[id]); }
  function definir(id, role) { if (base[id] === role) delete pend[id]; else pend[id] = role; }

  /** '' se pode ir; senão o motivo, em texto de gente. */
  function motivo(j, alvo) {
    const origem = roleDe(j.id);
    if (alvo === 'Banco') return origem === 'Banco' ? 'Já está no banco.' : '';
    if (alvo === 'G-League') {
      if (!vagasGL()) return 'Esta liga não tem G-League.';
      if (origem === 'G-League') return 'Já está na G-League.';
      if (Number(j.age) >= 25) return `G-League só até 24 anos — ${j.name} tem ${j.age}.`;
      const ocupadas = jogadores.filter(x => roleDe(x.id) === 'G-League').length;
      if (ocupadas >= vagasGL()) return `G-League cheia (${vagasGL()} vagas). Tire alguém antes.`;
      return '';
    }
    if (posDe(j) !== alvo) return `${j.name} é ${posDe(j) || 'sem posição'} — esse lugar é de ${alvo}.`;
    const ocupante = titularDe(alvo);
    if (ocupante && String(ocupante.id) === String(j.id)) return 'Já está nesse lugar.';
    return '';
  }

  function mover(j, alvo) {
    const origem = roleDe(j.id);
    if (POS.includes(alvo)) {
      const ocupante = titularDe(alvo);
      definir(j.id, 'Titular');
      if (ocupante) {
        // Quem sai da quadra vai pra onde o outro estava; da G-League só volta
        // quem ainda pode estar lá, e "Outro" vira banco.
        let destino = (origem === 'G-League' && Number(ocupante.age) < 25) ? 'G-League' : 'Banco';
        definir(ocupante.id, destino);
      }
    } else {
      definir(j.id, alvo);
    }
    selecionado = null;
  }

  function tentar(j, alvo) {
    if (!j) return;
    const m = motivo(j, alvo);
    if (m) { aviso('err', esc(m)); desenhar(); return; }
    mover(j, alvo);
    salvar();
  }

  function render(lista) {
    lista = lista || [];
    const nova = lista.map(j => j.id + ':' + normRole(j.role)).sort().join('|');
    // Ordenar ou filtrar a tabela redesenha a página inteira. Se o elenco do
    // servidor não mudou, a quadra mantém o que ainda não foi salvo.
    if (nova === chave && jogadores.length) { desenhar(); return; }
    chave = nova;
    jogadores = lista.slice();
    base = {}; pend = {}; selecionado = null;
    jogadores.forEach(j => { base[j.id] = normRole(j.role); });

    // Titular repetido na mesma posição (ou sem posição de quadra) não cabe
    // em lugar nenhum: fica o de maior OVR, os outros vão pro banco pendentes.
    const dono = {}; const sobra = [];
    jogadores.filter(j => base[j.id] === 'Titular')
      .sort((a, b) => Number(b.ovr) - Number(a.ovr))
      .forEach(j => {
        const p = posDe(j);
        if (!POS.includes(p) || dono[p]) { pend[j.id] = 'Banco'; sobra.push(j.name); } else dono[p] = true;
      });
    if (sobra.length) {
      aviso('info', `${esc(sobra.join(', '))} ${sobra.length === 1 ? 'estava' : 'estavam'} como titular numa posição que já tinha dono — ` +
        `${sobra.length === 1 ? 'foi' : 'foram'} pro banco. Salve pra confirmar.`);
    }
    desenhar();
  }

  function chip(j) {
    const extra = roleDe(j.id) === 'Outro' ? ' · Outro' : '';
    return `<button type="button" class="qd-jog${sel(j)}${j.id in pend ? ' qd-mudou' : ''}" data-id="${esc(j.id)}" draggable="true">
      <img src="${esc(foto(j))}" alt="" draggable="false" loading="lazy" onerror="this.onerror=null;this.src='${reserva(j)}'">
      <span class="n">${esc(j.name)}<span class="m"> · ${esc(posDe(j))}${j.secondary_position ? '/' + esc(j.secondary_position) : ''} · ${esc(j.age)}a${extra}</span></span>
      <span class="o" style="color:${corOvr(j.ovr)}">${esc(j.ovr)}</span>
    </button>`;
  }

  function desenhar() {
    const raiz = raizEl();
    if (!raiz) return;
    ligar(raiz);
    if (!jogadores.length) { raiz.innerHTML = ''; return; }

    const escalados = POS.map(titularDe).filter(Boolean);
    const media = escalados.length
      ? Math.round(escalados.reduce((s, j) => s + Number(j.ovr || 0), 0) / escalados.length) : 0;
    const temSalario = typeof SALARY_MODE !== 'undefined' && SALARY_MODE && typeof playerSalary === 'function';
    const salario = temSalario ? escalados.reduce((s, j) => s + Number(playerSalary(j) || 0), 0) : null;
    const banco = jogadores.filter(j => ['Banco', 'Outro'].includes(roleDe(j.id)))
      .sort((a, b) => Number(b.ovr) - Number(a.ovr));
    const gl = jogadores.filter(j => roleDe(j.id) === 'G-League');
    const nMud = mudancas().length;

    const lugares = POS.map(p => {
      const j = titularDe(p);
      const [x, y] = LUGAR[p];
      return `<button type="button" class="qd-lugar${sel(j)}${j && j.id in pend ? ' qd-mudou' : ''}" data-alvo="${p}"
          ${j ? `data-id="${esc(j.id)}" draggable="true"` : ''} style="left:${x}%;top:${y}%;--pos-c:${CORES[p]}"
          aria-label="${p}: ${j ? esc(j.name) : 'vazio'}">
        ${j ? `<img class="qd-foto" src="${esc(foto(j))}" alt="" draggable="false" onerror="this.onerror=null;this.src='${reserva(j)}'">`
            : `<span class="qd-vazio">${p}</span>`}
        <span class="qd-pos">${p}</span>
        ${j ? `<span class="qd-nome">${esc(sobrenome(j.name))}</span><span class="qd-ovr" style="color:${corOvr(j.ovr)}">${esc(j.ovr)}</span>`
            : '<span class="qd-nome" style="color:var(--text-3)">vazio</span>'}
      </button>`;
    }).join('');

    const zonaBanco = `<div class="qd-zona" data-alvo="Banco">
        <h6><span>Banco</span><span>${banco.length}</span></h6>
        <div class="qd-lista">${banco.map(chip).join('') || '<div class="qd-vaga">Ninguém no banco</div>'}</div>
      </div>`;
    const zonaGL = (vagasGL() || gl.length) ? `<div class="qd-zona" data-alvo="G-League">
        <h6><span>G-League</span><span>${gl.length}/${vagasGL()}</span></h6>
        <div class="qd-lista">${gl.map(chip).join('')}${
          Array.from({ length: Math.max(0, vagasGL() - gl.length) }, () => '<div class="qd-vaga">Vaga livre · até 24 anos</div>').join('')
        }</div>
      </div>` : '';

    raiz.innerHTML = `<section class="qd" aria-label="Escalação">
      <div class="qd-topo">
        <div class="qd-titulo">Escalação</div>
        <div class="qd-resumo">${escalados.length}/5 em quadra · OVR médio <b>${media || '—'}</b>${
          salario !== null ? ` · quinteto <b>${salario}M</b>` : ''}</div>
      </div>
      <div class="qd-grid">
        <div class="qd-quadra">${SVG}${lugares}</div>
        <div class="qd-lado">${zonaBanco}${zonaGL}</div>
      </div>
      <div class="qd-barra"${nMud ? '' : ' hidden'}>
        <span class="sp">${nMud} ${nMud === 1 ? 'jogador precisa' : 'jogadores precisam'} ir pro banco pra quadra ficar válida.</span>
        <button type="button" class="qd-btn" data-acao="desfazer">Desfazer</button>
        <button type="button" class="qd-btn pri" data-acao="salvar"><i class="bi bi-check2"></i> Salvar escalação</button>
      </div>
      <div class="qd-msg ${msg.tipo}" role="status">${msg.html}</div>
      <div class="qd-dica"><i class="bi bi-hand-index"></i> Arraste um jogador pra quadra, pro banco${vagasGL() ? ' ou pra G-League' : ''} — ou toque nele e depois no destino.
        Cada lugar aceita só a posição principal, e cada mudança é salva na hora.</div>
    </section>`;
    marcarAlvos(raiz);
  }

  /** Acende onde o jogador em mãos pode ir; apaga (com o motivo no title) onde não pode. */
  function marcarAlvos(raiz) {
    const id = arrastando ?? selecionado;
    const j = id != null ? porId(id) : null;
    raiz.querySelectorAll('[data-alvo]').forEach(t => {
      t.classList.remove('qd-alvo-ok', 'qd-alvo-no');
      if (!j) { t.removeAttribute('title'); return; }
      const m = motivo(j, t.dataset.alvo);
      t.classList.add(m ? 'qd-alvo-no' : 'qd-alvo-ok');
      t.title = m || 'Soltar aqui';
    });
  }

  async function salvar() {
    const roles = {};
    mudancas().forEach(id => { roles[id] = pend[id]; });
    if (!Object.keys(roles).length || salvando) return;
    salvando = true;
    aviso('info', '<i class="bi bi-arrow-repeat"></i> Salvando…');
    desenhar();

    let erro = '';
    try {
      const r = await fetch('/api/players.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'set_lineup', team_id: window.__TEAM_ID__, roles }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.success) erro = d.error || 'Não deu pra salvar a escalação.';
    } catch (e) {
      erro = 'Não deu pra salvar a escalação. Confira a conexão e tente de novo.';
    }

    if (erro) {
      // Recusado: a quadra volta pro que está gravado.
      Object.keys(roles).forEach(id => { delete pend[id]; });
      aviso('err', esc(erro));
    } else {
      // Aceito: a função nova passa a ser a oficial aqui mesmo, sem recarregar
      // a página — os objetos são os mesmos da tabela (allPlayers).
      Object.entries(roles).forEach(([id, role]) => { const j = porId(id); if (j) j.role = role; });
      aviso('ok', '<i class="bi bi-check2-circle"></i> Salvo.');
    }
    salvando = false;
    chave = '';
    // Redesenha a página inteira (tabela, contagem por função e a quadra),
    // respeitando a busca e o filtro que estiverem na tela.
    if (typeof renderPlayers === 'function' && typeof allPlayers !== 'undefined') renderPlayers(allPlayers);
    else render(jogadores);
  }

  function ligar(raiz) {
    if (raiz.dataset.ligado) return;
    raiz.dataset.ligado = '1';

    raiz.addEventListener('click', e => {
      if (salvando) return;
      const acao = e.target.closest('[data-acao]');
      if (acao) {
        if (acao.dataset.acao === 'salvar') salvar();
        else { aviso('', ''); chave = ''; render(jogadores); }
        return;
      }
      const jogEl = e.target.closest('[data-id]');
      const alvoEl = e.target.closest('[data-alvo]');

      if (selecionado != null) {
        const j = porId(selecionado);
        if (jogEl && String(jogEl.dataset.id) === String(selecionado)) {
          selecionado = null; aviso('', ''); desenhar(); return;
        }
        if (alvoEl) {
          const alvo = alvoEl.dataset.alvo;
          // Tocar noutro jogador da MESMA zona troca a seleção; qualquer outro
          // destino é um movimento.
          if (jogEl && !POS.includes(alvo) && roleDe(j.id) === alvo) {
            selecionado = jogEl.dataset.id; desenhar(); return;
          }
          tentar(j, alvo);
          return;
        }
        selecionado = null; aviso('', ''); desenhar();
        return;
      }
      if (jogEl) {
        selecionado = jogEl.dataset.id;
        aviso('info', `<b>${esc(porId(selecionado)?.name)}</b> selecionado — toque no destino.`);
        desenhar();
      }
    });

    raiz.addEventListener('dragstart', e => {
      const el = e.target.closest('[data-id]');
      if (!el) return;
      if (salvando) { e.preventDefault(); return; }
      arrastando = el.dataset.id;
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', String(arrastando)); } catch (_) {}
      el.classList.add('arrastando');
      marcarAlvos(raiz);
    });
    raiz.addEventListener('dragover', e => {
      const t = e.target.closest('[data-alvo]');
      if (!t || arrastando == null) return;
      const m = motivo(porId(arrastando), t.dataset.alvo);
      const caixa = raiz.querySelector('.qd-msg');
      if (m) {
        if (caixa && caixa.dataset.m !== m) { caixa.className = 'qd-msg err'; caixa.textContent = m; caixa.dataset.m = m; }
        return;
      }
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
    });
    raiz.addEventListener('drop', e => {
      const t = e.target.closest('[data-alvo]');
      if (!t || arrastando == null) return;
      e.preventDefault();
      const j = porId(arrastando);
      arrastando = null;
      tentar(j, t.dataset.alvo);
    });
    raiz.addEventListener('dragend', () => {
      if (arrastando == null) return;
      arrastando = null;
      desenhar();
    });
  }

  window.QuadraEscalacao = { render };
})();
