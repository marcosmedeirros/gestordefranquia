/* LENDAS DA QUADRA — o hub: jogar, baralho, loja, ranking e desafios.
   A mesa 3D mora em lendas.js; daqui se entra nela por LendasJogo.entrar. */
(function () {
  'use strict';
  const L = window.LENDAS;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const h = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = n => Number(n).toLocaleString('pt-BR');
  const esperar = ms => new Promise(r => setTimeout(r, ms));
  let ocupado = false;

  async function api(acao, dados = {}) {
    const fd = new FormData();
    fd.append('acao', acao);
    for (const k in dados) fd.append(k, typeof dados[k] === 'object' ? JSON.stringify(dados[k]) : dados[k]);
    try {
      const r = await fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
      return await r.json();
    } catch (e) { return { ok: false, erro: 'Sem conexão com o servidor.' }; }
  }
  let toastT;
  function toast(txt, erro) {
    let el = $('#toast-hub');
    if (!el) { el = document.createElement('div'); el.id = 'toast-hub'; document.body.appendChild(el); }
    el.className = 'toast-hub' + (erro ? ' erro' : '');
    el.textContent = txt;
    el.hidden = false;
    clearTimeout(toastT);
    toastT = setTimeout(() => { el.hidden = true; }, 3000);
  }
  function saldo(v) { L.saldo = v; $('#saldo').textContent = num(v); }
  saldo(L.saldo);

  // ── as regras, escritas uma vez e mostradas no hub e na mesa ──────
  const regras = [
    `<b>Objetivo:</b> derrubar a Fortaleza do rival (${L.fortaleza} de vida).`,
    '<b>Por turno, uma de cada:</b> 1 movimento (de um herói já em quadra), 1 ataque e 1 carta da mão.',
    '<b>Energia:</b> começa em 2 e cresce +1 por turno, até 8. Cada carta custa energia.',
    '<b>Mão de 5:</b> usou uma carta, compra outra na hora.',
    `<b>Heróis</b> entram na sua zona (as 2 fileiras azuis) e não agem no turno em que entram. No máximo ${L.em_quadra} em quadra.`,
    'Todo ataque rola um <b>d20</b>: 1 erra, 20 é crítico (dano em dobro).',
    '<b>Revide:</b> quem leva golpe corpo a corpo devolve metade do ATQ (o Ladino não sofre revide).',
    `<b>Pressão no garrafão:</b> cada herói seu na zona vermelha tira ${L.pressao} da Fortaleza rival no começo do seu turno.`,
    '<b>Arremesso contestado:</b> quem ataca de longe com um inimigo colado só pode atacar esse inimigo.',
    '<b>Ferreiros</b> equipam heróis seus. <b>Vilões</b> sabotam o rival.',
    `<b>Limite:</b> ${L.turnos_max} turnos somados. Se ninguém cair, vence a Fortaleza mais inteira.`,
  ];
  $$('.lista-regras').forEach(ul => { ul.innerHTML = regras.map(r => `<li>${r}</li>`).join(''); });
  $('#bt-ajuda').addEventListener('click', () => { $('#ajuda').hidden = false; });
  $('#bt-fechar-ajuda').addEventListener('click', () => { $('#ajuda').hidden = true; });

  // ── abas ──────────────────────────────────────────────────────────
  const carregadas = {};
  $('#abas').addEventListener('click', e => {
    const b = e.target.closest('[data-aba]');
    if (!b) return;
    $$('#abas button').forEach(x => x.classList.toggle('ativa', x === b));
    $$('.painel').forEach(p => { p.hidden = p.id !== 'aba-' + b.dataset.aba; });
    const aba = b.dataset.aba;
    if (aba === 'baralho' && !carregadas.baralho) abrirBaralho();
    if (aba === 'loja') desenharLoja();
    if (aba === 'ranking') carregarRanking();
    if (aba === 'desafios') carregarDesafios();
  });

  // ═════════════════════════ JOGAR ═════════════════════════════════
  $('#niveis').innerHTML = Object.entries(L.niveis).map(([n, nome]) => `<button data-nivel="${n}">${h(nome)}</button>`).join('');
  $('#niveis').addEventListener('click', async e => {
    const b = e.target.closest('[data-nivel]');
    if (!b || ocupado) return;
    ocupado = true;
    const r = await api('treino_novo', { nivel: b.dataset.nivel });
    ocupado = false;
    if (r.ok) LendasJogo.entrar(r, true, b.dataset.nivel); else toast(r.erro, true);
  });
  if (L.treino && !L.treino.estado.fim) {
    $('#bt-continuar').hidden = false;
    $('#bt-continuar').addEventListener('click', () => LendasJogo.entrar(L.treino, false));
  }

  $('#p-vit').textContent = num(L.premios.vitoria);
  $('#p-der').textContent = num(L.premios.derrota);
  $('#p-dia').textContent = L.premios.por_dia;

  let online = L.online;
  let esperaTimer = null;

  function desenharOnline() {
    $('#online-livre').hidden = online.status !== 'livre';
    $('#online-espera').hidden = online.status !== 'aguardando';
    $('#bt-voltar-partida').hidden = online.status !== 'jogando';
    clearInterval(esperaTimer);
    if (online.status === 'aguardando') {
      if (online.modo === 'codigo') {
        const link = `${location.origin}${location.pathname}?codigo=${online.codigo}`;
        $('#online-espera').innerHTML = `<div class="espera"><div class="espera-rot">Seu código</div><div class="codigo">${h(online.codigo)}</div>
          <div class="espera-sub">Mande o código ou o link pra quem você quer desafiar.</div>
          <div class="niveis"><button id="bt-copiar"><i class="bi bi-clipboard"></i> Copiar link</button><button id="bt-cancelar">Cancelar</button></div></div>`;
        $('#bt-copiar').addEventListener('click', () => { navigator.clipboard && navigator.clipboard.writeText(link); toast('Link copiado.'); });
      } else {
        $('#online-espera').innerHTML = `<div class="espera"><div class="anel-carga"></div><div class="espera-rot">Procurando adversário…</div>
          <div class="espera-sub">Força do seu baralho: <b>${online.poder}</b>. A busca aceita diferenças maiores quanto mais você espera.</div>
          <div class="niveis"><button id="bt-cancelar">Cancelar</button></div></div>`;
      }
      $('#bt-cancelar').addEventListener('click', async () => { await api('on_cancelar'); online = { status: 'livre' }; desenharOnline(); });
      // Esperando: pergunta a cada 2s se alguém entrou.
      esperaTimer = setInterval(async () => {
        const r = await api('on_status');
        if (!r.ok) return;
        online = r;
        if (r.status === 'jogando') { clearInterval(esperaTimer); entrarOnline(r.id, true); }
        else if (r.status !== 'aguardando') desenharOnline();
      }, 2000);
    }
  }

  async function entrarOnline(id, nova) {
    const r = await api('on_estado', { partida: id, desde: 0 });
    if (!r.estado) { toast(r.erro || 'Não deu pra abrir a partida.', true); return; }
    LendasJogo.entrar(r, nova);
  }

  async function procurar(modo) {
    if (ocupado) return;
    ocupado = true;
    const r = await api('on_procurar', { modo });
    ocupado = false;
    if (!r.ok) { toast(r.erro, true); return; }
    online = r;
    if (r.status === 'jogando') entrarOnline(r.id, true); else desenharOnline();
  }
  $('#bt-fila').addEventListener('click', () => procurar('fila'));
  $('#bt-codigo').addEventListener('click', () => procurar('codigo'));
  $('#bt-entrar').addEventListener('click', () => entrarComCodigo($('#in-codigo').value));
  $('#in-codigo').addEventListener('keydown', e => { if (e.key === 'Enter') entrarComCodigo(e.target.value); });
  $('#in-codigo').addEventListener('input', e => { e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); });
  $('#bt-voltar-partida').addEventListener('click', () => entrarOnline(online.id, false));

  async function entrarComCodigo(codigo) {
    if (ocupado || !codigo) return;
    ocupado = true;
    const r = await api('on_entrar', { codigo });
    ocupado = false;
    if (!r.ok) { toast(r.erro, true); return; }
    online = r;
    if (r.status === 'jogando') entrarOnline(r.id, true);
  }
  desenharOnline();
  // Veio por link de convite (?codigo=…): já entra.
  if (L.codigo_url && online.status === 'livre') { $('#in-codigo').value = L.codigo_url; entrarComCodigo(L.codigo_url); }
  else if (online.status === 'jogando') entrarOnline(online.id, false);

  // ═════════════════════════ BARALHO ═══════════════════════════════
  let catalogo = {}, colecao = {}, baralho = [], salvo = '';
  const CLASSE = { heroi: 'Herói', ferreiro: 'Ferreiro', vilao: 'Vilão' };
  const RAR = { comum: 'Comum', rara: 'Rara', epica: 'Épica', lendaria: 'Lendária' };

  /** A carta em miniatura (coleção, baralho e loja). */
  function cartaMini(c, extra = '') {
    const corpo = c.classe === 'heroi'
      ? `<div class="m-tipo">${h(L.arquetipos[c.tipo].nome)} · ${h(c.pos)}</div><div class="m-stats"><span class="a">⚔${c.atq}</span><span class="v">♥${c.vida}</span><span>↔${c.mov}</span><span>◎${c.alc}</span></div>`
      : `<div class="m-desc">${h(c.desc)}</div><div class="m-por">por ${h(c.jogador)}</div>`;
    return `<div class="mini r-${c.rar}" data-id="${h(c.id)}" title="${h(c.nome)} — ${RAR[c.rar]}">
      <img src="${h(c.foto)}" alt="" loading="lazy"><div class="m-custo">${c.custo}</div><div class="m-classe ${c.classe}">${CLASSE[c.classe]}</div>
      <div class="m-nome">${h(c.nome)}</div>${corpo}${extra}</div>`;
  }

  async function abrirBaralho() {
    const r = await api('colecao');
    if (!r.ok) { toast(r.erro, true); return; }
    carregadas.baralho = true;
    catalogo = {}; r.catalogo.forEach(c => { catalogo[c.id] = c; });
    colecao = r.colecao;
    baralho = r.baralho.slice();
    salvo = JSON.stringify([...baralho].sort());
    desenharBaralho();
  }

  function contagem() { const n = {}; baralho.forEach(id => { n[id] = (n[id] || 0) + 1; }); return n; }

  function desenharBaralho() {
    const n = contagem();
    const herois = baralho.filter(id => catalogo[id].classe === 'heroi').length;
    const peso = { comum: 1, rara: 1.5, epica: 2.2, lendaria: 3.2 };
    $('#b-conta').textContent = `${baralho.length}/${L.tam_baralho}`;
    $('#b-poder').textContent = Math.round(baralho.reduce((s, id) => s + catalogo[id].custo * peso[catalogo[id].rar], 0));
    $('#b-herois').textContent = `${herois} heróis · ${baralho.length - herois} efeitos`;
    const mudou = JSON.stringify([...baralho].sort()) !== salvo;
    let erro = '';
    if (baralho.length !== L.tam_baralho) erro = `Faltam ${L.tam_baralho - baralho.length} carta(s).`;
    else if (herois < 10) erro = 'Ponha pelo menos 10 heróis.';
    $('#b-erro').textContent = mudou ? erro : '';
    $('#bt-salvar').disabled = !mudou || !!erro;

    // o baralho, agrupado por classe e ordenado por custo
    const grupos = { heroi: [], ferreiro: [], vilao: [] };
    Object.keys(n).forEach(id => grupos[catalogo[id].classe].push(id));
    $('#b-lista').innerHTML = Object.entries(grupos).map(([cl, ids]) => {
      ids.sort((a, b) => catalogo[a].custo - catalogo[b].custo || catalogo[a].nome.localeCompare(catalogo[b].nome));
      const qtd = ids.reduce((s, id) => s + n[id], 0);
      return `<div class="b-grupo"><h4>${CLASSE[cl]}s <small>${qtd}</small></h4>${ids.map(id => {
        const c = catalogo[id];
        return `<div class="b-linha r-${c.rar}" data-tirar="${h(id)}" title="Tirar do baralho"><span class="b-custo">${c.custo}</span>
          <span class="b-nome">${h(c.nome)}</span>${c.classe === 'heroi' ? `<span class="b-st">⚔${c.atq} ♥${c.vida}</span>` : ''}
          ${n[id] > 1 ? `<span class="b-x">×${n[id]}</span>` : ''}<i class="bi bi-dash-circle"></i></div>`;
      }).join('')}</div>`;
    }).join('');
    desenharColecao();
  }

  function desenharColecao() {
    const busca = $('#f-busca').value.trim().toLowerCase();
    const cl = $('#f-classe').value, rar = $('#f-rar').value, tenho = $('#f-tenho').checked;
    const n = contagem();
    const ordem = { lendaria: 0, epica: 1, rara: 2, comum: 3 };
    const lista = Object.values(catalogo).filter(c =>
      (!cl || c.classe === cl) && (!rar || c.rar === rar) && (!tenho || colecao[c.id]) &&
      (!busca || (c.nome + ' ' + (c.jogador || '') + ' ' + (c.time || '')).toLowerCase().includes(busca)))
      .sort((a, b) => ordem[a.rar] - ordem[b.rar] || b.custo - a.custo || a.nome.localeCompare(b.nome))
      .slice(0, 180);
    $('#c-grid').innerHTML = lista.map(c => {
      const tem = colecao[c.id] || 0, usando = n[c.id] || 0;
      const cabe = usando < Math.min(tem, L.copias[c.classe]) && baralho.length < L.tam_baralho;
      const selo = tem ? `<div class="m-qtd">${usando}/${Math.min(tem, L.copias[c.classe])}</div>` : '<div class="m-qtd falta">não tem</div>';
      return `<div class="c-item ${tem ? '' : 'bloqueada'} ${cabe ? 'cabe' : ''}" data-por="${h(c.id)}">${cartaMini(c, selo)}</div>`;
    }).join('') || '<p class="vazio">Nenhuma carta com esses filtros.</p>';
  }
  ['#f-busca', '#f-classe', '#f-rar', '#f-tenho'].forEach(s => $(s).addEventListener('input', desenharColecao));

  $('#c-grid').addEventListener('click', e => {
    const el = e.target.closest('[data-por]');
    if (!el) return;
    const id = el.dataset.por, c = catalogo[id];
    const tem = colecao[id] || 0, usando = contagem()[id] || 0;
    if (!tem) { toast('Você não tem essa carta — ela sai nos pacotes da Loja.', true); return; }
    if (usando >= Math.min(tem, L.copias[c.classe])) { toast(c.classe === 'heroi' ? 'Cada herói entra uma vez só.' : `No máximo ${Math.min(tem, L.copias[c.classe])} cópia(s) dessa.`, true); return; }
    if (baralho.length >= L.tam_baralho) { toast(`O baralho já tem ${L.tam_baralho} cartas — tire uma antes.`, true); return; }
    baralho.push(id);
    desenharBaralho();
  });
  $('#b-lista').addEventListener('click', e => {
    const el = e.target.closest('[data-tirar]');
    if (!el) return;
    baralho.splice(baralho.indexOf(el.dataset.tirar), 1);
    desenharBaralho();
  });
  $('#bt-salvar').addEventListener('click', async () => {
    const r = await api('salvar_baralho', { cartas: baralho });
    if (!r.ok) { toast(r.erro, true); return; }
    salvo = JSON.stringify([...baralho].sort());
    desenharBaralho();
    toast('Baralho salvo. É ele que entra nas próximas partidas.');
  });

  // ═════════════════════════ LOJA ══════════════════════════════════
  function desenharLoja() {
    const chance = (p, r) => (p.chances[r] / Object.values(p.chances).reduce((a, b) => a + b, 0) * 100).toFixed(p.chances[r] < 20 ? 1 : 0);
    $('#pacotes').innerHTML = Object.entries(L.pacotes).map(([k, p]) => `<div class="pacote-loja ${k}">
      <div class="pl-arte"><div class="pl-brilho"></div><span>LENDAS</span><b>${k === 'premium' ? 'PREMIUM' : 'BÁSICO'}</b></div>
      <h3>${h(p.nome)}</h3><p>${p.cartas} cartas · garante uma <b>${RAR[p.garante].toLowerCase()}</b> ou melhor</p>
      <div class="pl-chances">${['comum', 'rara', 'epica', 'lendaria'].map(r => `<span class="r-${r}">${RAR[r]} ${chance(p, r)}%</span>`).join('')}</div>
      <button class="bt-comprar" data-pacote="${k}" ${L.saldo < p.preco ? 'disabled' : ''}><i class="bi bi-coin"></i> ${num(p.preco)}</button></div>`).join('');
  }
  $('#pacotes').addEventListener('click', async e => {
    const b = e.target.closest('[data-pacote]');
    if (!b || ocupado) return;
    ocupado = true;
    b.disabled = true;
    const r = await api('pacote', { tipo: b.dataset.pacote });
    ocupado = false;
    if (!r.ok) { toast(r.erro, true); desenharLoja(); return; }
    saldo(r.saldo);
    carregadas.baralho = false;   // a coleção mudou: o editor recarrega na próxima visita
    abrirPacoteAnimado(r);
  });

  /** As cinco cartas viram uma a uma, a melhor por último. */
  async function abrirPacoteAnimado(r) {
    const ordem = { comum: 0, rara: 1, epica: 2, lendaria: 3 };
    const cartas = r.cartas.slice().sort((a, b) => ordem[a.carta.rar] - ordem[b.carta.rar]);
    const ov = $('#abre-pacote');
    $('#ap-rodape').innerHTML = '';
    // O selo fica FORA da carta: dentro dela o overflow:hidden o escondia.
    $('#ap-cartas').innerHTML = cartas.map((c, i) => `<div class="ap-carta" style="--i:${i}"><div class="ap-capa"></div>${cartaMini(c.carta)}
      ${c.repetida ? `<div class="ap-selo repetida">Repetida · +${c.moedas} moedas</div>` : (c.nova ? '<div class="ap-selo nova">Nova!</div>' : '<div class="ap-selo">2ª cópia</div>')}</div>`).join('');
    ov.hidden = false;
    const els = $$('.ap-carta', ov);
    for (const [i, el] of els.entries()) {
      await esperar(i === els.length - 1 && ordem[cartas[i].carta.rar] >= 2 ? 900 : 420);
      el.classList.add('virada', 'r-' + cartas[i].carta.rar);
    }
    await esperar(400);
    let rod = r.reembolso ? `<p>Repetidas viraram <b>${r.reembolso}</b> moedas.</p>` : '';
    (r.desafios || []).forEach(d => { rod += `<p class="ap-desafio"><i class="bi bi-flag-fill"></i> Desafio cumprido: ${h(d.nome)} · +${d.moedas}</p>`; });
    $('#ap-rodape').innerHTML = rod + '<button class="bt-sec" id="bt-fechar-pacote">Continuar</button>';
    $('#bt-fechar-pacote').addEventListener('click', () => { ov.hidden = true; desenharLoja(); });
  }

  // ═════════════════════════ RANKING ═══════════════════════════════
  async function carregarRanking() {
    const r = await api('ranking');
    if (!r.ok) return;
    const mes = new Date(r.temporada + '-01T12:00:00').toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
    const linhas = r.top.map((x, i) => `<tr class="${String(x.id_usuario) === String(L.uid) ? 'eu' : ''}"><td>${i + 1}</td><td>${h(x.nome)}</td>
      <td>${x.vitorias}-${x.derrotas}</td><td class="pts">${x.pontos}</td></tr>`).join('');
    $('#ranking').innerHTML = `<h3 class="sec">Temporada de ${h(mes)}</h3>
      <p class="sub">+${25} por vitória, −${15} por derrota (nunca abaixo de zero). A temporada vira no começo de cada mês.</p>
      ${r.eu ? `<div class="meu-rank">Você: <b>${r.eu.pos}º</b> · ${r.eu.pontos} pontos · ${r.eu.vitorias}-${r.eu.derrotas}</div>` : '<div class="meu-rank">Você ainda não jogou nesta temporada.</div>'}
      ${linhas ? `<table class="tab-rank"><thead><tr><th>#</th><th>GM</th><th>V-D</th><th>Pontos</th></tr></thead><tbody>${linhas}</tbody></table>` : '<p class="vazio">Ninguém jogou ainda. O topo está livre.</p>'}`;
  }

  // ═════════════════════════ DESAFIOS ══════════════════════════════
  async function carregarDesafios() {
    const r = await api('desafios');
    if (!r.ok) return;
    const feitos = r.feitos || {};
    $('#desafios').innerHTML = `<p class="sub">Cada desafio paga uma vez só, na primeira vez que é cumprido. Só valem partidas online.</p>` +
      Object.entries(L.desafios).map(([k, d]) => `<div class="desafio ${feitos[k] ? 'feito' : ''}">
        <i class="bi ${feitos[k] ? 'bi-check-circle-fill' : 'bi-flag'}"></i><div><b>${h(d.nome)}</b><small>${h(d.desc)}</small></div>
        <span>${feitos[k] ? 'feito' : '+' + num(d.moedas)}</span></div>`).join('');
  }
})();
