/**
 * QUADRA DE ESCALAÇÃO — Meu Elenco.
 *
 * Meia quadra com os cinco lugares (PG, SG, SF, PF, C), o banco e a G-League.
 * Escalar é arrastar o jogador pro destino — ou, no celular, tocar nele e
 * depois no destino (arrastar com o dedo não funciona no Safari).
 *
 * Cada lugar aceita a posição principal ou a secundária do jogador: Giannis
 * (SF/PF) pode fechar o PF. O lugar escolhido vai junto no save (slots, gravado
 * em players.lineup_slot); vazio é a principal.
 *
 * Cada movimento SALVA NA HORA (api/players.php, action=set_lineup). Um
 * movimento pode mexer em dois jogadores — quem entra e quem sai do lugar — e
 * os dois vão juntos: trocar dois armadores um de cada vez esbarraria na regra
 * de "um lugar de cada" no primeiro save. Se o servidor recusar, a quadra
 * volta pro que está gravado e diz o motivo.
 *
 * A barra "Salvar escalação" só aparece quando a própria quadra achou algo a
 * corrigir ao abrir (dois titulares no mesmo lugar): isso não foi escolha do
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
  let baseLugar = {};   // id -> lugar gravado ('' = posição principal)
  let pendLugar = {};   // id -> lugar ainda não salvo
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
  const secDe = j => String(j.secondary_position || '').toUpperCase().trim();
  const posTexto = j => posDe(j) + (secDe(j) && secDe(j) !== posDe(j) ? '/' + secDe(j) : '');
  const cobre = (j, p) => !!p && (posDe(j) === p || secDe(j) === p);
  const porId = id => jogadores.find(j => String(j.id) === String(id));
  const roleDe = id => (id in pend ? pend[id] : base[id]);
  const lugarBruto = id => (id in pendLugar ? pendLugar[id] : baseLugar[id]) || '';
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
  /** Lugar do titular: o escolhido, se ele ainda joga ali; senão a posição principal. */
  function lugarDe(j) { const l = lugarBruto(j.id); return cobre(j, l) ? l : posDe(j); }
  function titularDe(p) { return jogadores.find(j => roleDe(j.id) === 'Titular' && lugarDe(j) === p); }
  function mudancas() {
    const ids = Object.keys(pend).filter(id => pend[id] !== base[id])
      .concat(Object.keys(pendLugar).filter(id => pendLugar[id] !== (baseLugar[id] || '')));
    return [...new Set(ids)];
  }
  function definir(id, role) { if (base[id] === role) delete pend[id]; else pend[id] = role; }
  function definirLugar(id, lugar) {
    const j = porId(id);
    const l = (j && lugar === posDe(j)) ? '' : (lugar || '');   // a principal é guardada como vazio
    if ((baseLugar[id] || '') === l) delete pendLugar[id]; else pendLugar[id] = l;
  }

  /** '' se pode ir; senão o motivo, em texto de gente. */
  function motivo(j, alvo) {
    const origem = roleDe(j.id);
    if (alvo === 'Banco') return origem === 'Banco' ? 'Já está no banco.' : '';
    if (alvo === 'Outro') return origem === 'Outro' ? 'Já está em Outros.' : '';
    if (alvo === 'G-League') {
      if (!vagasGL()) return 'Esta liga não tem G-League.';
      if (origem === 'G-League') return 'Já está na G-League.';
      if (Number(j.age) >= 25) return `G-League só até 24 anos — ${j.name} tem ${j.age}.`;
      const ocupadas = jogadores.filter(x => roleDe(x.id) === 'G-League').length;
      if (ocupadas >= vagasGL()) return `G-League cheia (${vagasGL()} vagas). Tire alguém antes.`;
      return '';
    }
    if (!cobre(j, alvo)) return `${j.name} é ${posTexto(j) || 'sem posição'} — esse lugar é de ${alvo}.`;
    const ocupante = titularDe(alvo);
    if (ocupante && String(ocupante.id) === String(j.id)) return 'Já está nesse lugar.';
    return '';
  }

  function mover(j, alvo) {
    const origem = roleDe(j.id);
    if (POS.includes(alvo)) {
      const ocupante = titularDe(alvo);
      const lugarAntes = origem === 'Titular' ? lugarDe(j) : '';
      definir(j.id, 'Titular');
      definirLugar(j.id, alvo);
      if (ocupante) {
        if (lugarAntes && cobre(ocupante, lugarAntes)) {
          // Troca dentro da quadra: quem estava vai pro lugar que o outro deixou.
          definirLugar(ocupante.id, lugarAntes);
        } else {
          // Quem sai da quadra vai pra onde o outro estava; da G-League só volta
          // quem ainda pode estar lá, e "Outro" vira banco.
          const destino = (origem === 'G-League' && Number(ocupante.age) < 25) ? 'G-League' : 'Banco';
          definir(ocupante.id, destino);
          definirLugar(ocupante.id, '');
        }
      }
    } else {
      definir(j.id, alvo);
      definirLugar(j.id, '');
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
    // Posições e lugar entram no retrato: mudar só a posição de alguém (no modal
    // do elenco ou na tela de tática) tem que redesenhar a quadra na hora — com o
    // retrato só de função, a quadra achava que nada tinha mudado.
    const nova = lista.map(j => j.id + ':' + normRole(j.role) + ':' + posDe(j) + '/' + secDe(j) + '@' + String(j.lineup_slot || ''))
      .sort().join('|');
    // Ordenar ou filtrar a tabela redesenha a página inteira. Se o elenco do
    // servidor não mudou, a quadra mantém o que ainda não foi salvo.
    if (nova === chave && jogadores.length) { desenhar(); return; }
    chave = nova;
    jogadores = lista.slice();
    base = {}; pend = {}; baseLugar = {}; pendLugar = {}; selecionado = null;
    jogadores.forEach(j => {
      base[j.id] = normRole(j.role);
      baseLugar[j.id] = String(j.lineup_slot || '').toUpperCase().trim();
    });

    // Dois titulares no mesmo lugar (ou sem posição de quadra) não cabem: fica o
    // de maior OVR; o outro vai pra secundária se ela estiver livre, senão pro
    // banco. Tudo pendente, esperando o GM confirmar.
    const dono = {}; const repetidos = []; const mudouLugar = []; const sobra = [];
    const titulares = jogadores.filter(j => base[j.id] === 'Titular').sort((a, b) => Number(b.ovr) - Number(a.ovr));
    titulares.forEach(j => {
      const p = lugarDe(j);
      if (POS.includes(p) && !dono[p]) dono[p] = true; else repetidos.push(j);
    });
    repetidos.forEach(j => {
      const alt = [posDe(j), secDe(j)].find(p => POS.includes(p) && !dono[p]);
      if (alt) { dono[alt] = true; definirLugar(j.id, alt); mudouLugar.push(`${j.name} (${alt})`); }
      else { pend[j.id] = 'Banco'; definirLugar(j.id, ''); sobra.push(j.name); }
    });
    const partes = [];
    if (mudouLugar.length) partes.push(`${esc(mudouLugar.join(', '))} ${mudouLugar.length === 1 ? 'foi' : 'foram'} pro lugar livre da outra posição`);
    if (sobra.length) partes.push(`${esc(sobra.join(', '))} ${sobra.length === 1 ? 'foi' : 'foram'} pro banco`);
    if (partes.length) {
      aviso('info', `Havia titular repetido no mesmo lugar: ${partes.join('; ')}. Salve pra confirmar.`);
    }
    desenhar();
  }

  function chip(j) {
    return `<button type="button" class="qd-jog${sel(j)}${j.id in pend || j.id in pendLugar ? ' qd-mudou' : ''}" data-id="${esc(j.id)}" draggable="true">
      <img src="${esc(foto(j))}" alt="" draggable="false" loading="lazy" onerror="this.onerror=null;this.src='${reserva(j)}'">
      <span class="n">${esc(j.name)}<span class="m"> · ${esc(posTexto(j))} · ${esc(j.age)}a</span></span>
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
    // Banco e "Outros" separados: quem está como Outro não é reserva da rotação,
    // e misturar os dois fazia o banco parecer maior do que é.
    const porOvr = (a, b) => Number(b.ovr) - Number(a.ovr);
    const banco = jogadores.filter(j => roleDe(j.id) === 'Banco').sort(porOvr);
    const outros = jogadores.filter(j => roleDe(j.id) === 'Outro').sort(porOvr);
    const gl = jogadores.filter(j => roleDe(j.id) === 'G-League');
    const nMud = mudancas().length;

    const lugares = POS.map(p => {
      const j = titularDe(p);
      const [x, y] = LUGAR[p];
      const naSecundaria = j && posDe(j) !== p;
      return `<button type="button" class="qd-lugar${sel(j)}${j && (j.id in pend || j.id in pendLugar) ? ' qd-mudou' : ''}" data-alvo="${p}"
          ${j ? `data-id="${esc(j.id)}" draggable="true"` : ''} style="left:${x}%;top:${y}%;--pos-c:${CORES[p]}"
          aria-label="${p}: ${j ? esc(j.name) + (naSecundaria ? ` (${esc(posTexto(j))}, na posição secundária)` : '') : 'vazio'}">
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
    const zonaOutros = `<div class="qd-zona" data-alvo="Outro">
        <h6><span>Outros</span><span>${outros.length}</span></h6>
        <div class="qd-lista">${outros.map(chip).join('') || '<div class="qd-vaga">Ninguém em Outros</div>'}</div>
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
        <div class="qd-lado">${zonaBanco}${zonaOutros}${zonaGL}</div>
      </div>
      <div class="qd-barra"${nMud ? '' : ' hidden'}>
        <span class="sp">${nMud} ${nMud === 1 ? 'jogador precisa' : 'jogadores precisam'} mudar de lugar pra quadra ficar válida.</span>
        <button type="button" class="qd-btn" data-acao="desfazer">Desfazer</button>
        <button type="button" class="qd-btn pri" data-acao="salvar"><i class="bi bi-check2"></i> Salvar escalação</button>
      </div>
      <div class="qd-msg ${msg.tipo}" role="status">${msg.html}</div>
      <div class="qd-dica"><i class="bi bi-hand-index"></i> Arraste um jogador pra quadra, pro banco, pra Outros${vagasGL() ? ' ou pra G-League' : ''} — ou toque nele e depois no destino.
        Cada lugar aceita a posição principal ou a secundária do jogador, e cada mudança é salva na hora.</div>
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
    const ids = mudancas();
    const roles = {};
    const slots = {};
    ids.forEach(id => {
      if (id in pend && pend[id] !== base[id]) roles[id] = pend[id];
      if (id in pendLugar && pendLugar[id] !== (baseLugar[id] || '')) slots[id] = pendLugar[id];
    });
    if (!ids.length || salvando) return;
    salvando = true;
    aviso('info', '<i class="bi bi-arrow-repeat"></i> Salvando…');
    desenhar();

    let erro = '';
    try {
      const r = await fetch('/api/players.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'set_lineup', team_id: window.__TEAM_ID__, roles, slots }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.success) erro = d.error || 'Não deu pra salvar a escalação.';
    } catch (e) {
      erro = 'Não deu pra salvar a escalação. Confira a conexão e tente de novo.';
    }

    if (erro) {
      // Recusado: a quadra volta pro que está gravado.
      ids.forEach(id => { delete pend[id]; delete pendLugar[id]; });
      aviso('err', esc(erro));
    } else {
      // Aceito: a função e o lugar novos passam a ser os oficiais aqui mesmo, sem
      // recarregar a página — os objetos são os mesmos da tabela (allPlayers).
      Object.entries(roles).forEach(([id, role]) => { const j = porId(id); if (j) j.role = role; });
      ids.forEach(id => {
        const j = porId(id);
        if (!j) return;
        const lugar = normRole(j.role) === 'Titular' ? lugarBruto(id) : '';
        j.lineup_slot = lugar || null;
      });
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
