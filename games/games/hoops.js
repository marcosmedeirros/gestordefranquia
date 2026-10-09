/* FBA HOOPS — a tela. Quem decide é o servidor (@see hoops.php); aqui é
   só desenhar o que ele manda e animar o caminho até lá. */
(function () {
  'use strict';
  const H = window.HOOPS;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esperar = ms => new Promise(r => setTimeout(r, ms));
  const h = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = n => Number(n).toLocaleString('pt-BR');

  let estado = H.estado;
  let ocupado = false;
  let selecionada = null;

  // ── servidor ────────────────────────────────────────────────────────
  async function api(acao, dados = {}) {
    const fd = new FormData();
    fd.append('acao', acao);
    for (const k in dados) fd.append(k, dados[k]);
    try {
      const r = await fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json();
      if (!j.ok) aviso(j.erro || 'Algo deu errado.', true);
      return j;
    } catch (e) {
      aviso('Sem conexão com o servidor. Tente de novo.', true);
      return { ok: false };
    }
  }

  let toastTimer;
  function aviso(txt, erro) {
    const t = $('#toast');
    t.textContent = txt;
    t.className = 'toast' + (erro ? ' err' : '');
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.hidden = true; }, 3200);
  }

  function telas(qual) {
    for (const t of ['salao', 'draft', 'temporada', 'fim']) $('#tela-' + t).hidden = t !== qual;
    window.scrollTo({ top: 0 });
  }

  // ── a carta ─────────────────────────────────────────────────────────
  const SUFIXOS = /^(jr\.?|sr\.?|ii|iii|iv)$/i;
  function nomeCarta(n) {
    if (n.length <= 15) return n;
    const p = n.split(' ').filter(x => !SUFIXOS.test(x));
    const resto = p.slice(1).join(' ');
    return resto.length <= 15 && resto ? resto : p[p.length - 1];
  }
  // A carta mostra o NOME COMPLETO (pedido do Victor, 08/10/2026): nome
  // longo encolhe a fonte e pode quebrar em duas linhas, mas não é cortado.
  // O nome curto (nomeCarta) fica só pra onde não cabe carta: o placar.
  function tamNome(n) {
    return n.length > 22 ? 'n3' : n.length > 15 ? 'n2' : '';
  }
  function iniciais(n) {
    return n.split(' ').filter(x => !SUFIXOS.test(x)).map(x => x[0]).slice(0, 2).join('').toUpperCase();
  }
  function corNota(v) {
    return v >= 85 ? '#22c55e' : v >= 75 ? '#84cc16' : v >= 62 ? '#f59e0b' : '#ef4444';
  }

  function cartaHTML(c) {
    const ats = Object.keys(H.atributos).map(k => {
      const v = c.at[k];
      return `<div class="v-at"><span>${H.atributos[k].curto}</span><i style="--v:${v}%;--c:${corNota(v)}"></i><b>${v}</b></div>`;
    }).join('');
    const alt = c.altura ? (c.altura / 100).toFixed(2).replace('.', ',') + ' m' : '';
    const bio = [alt, c.idade ? c.idade + ' anos' : '', c.pais].filter(Boolean).map(h).join(' · ');
    return `<div class="carta r-${c.rar}" data-id="${h(c.id)}"><div class="carta-in">
      <div class="face frente">
        <div class="c-lado"><div class="c-ovr">${c.ovr}</div><div class="c-pos">${h(c.pos)}</div>
          ${c.logo ? `<img class="c-logo" src="${h(c.logo)}" alt="" loading="lazy">` : ''}<div class="c-liga">${h(c.liga)}</div></div>
        ${c.foto ? `<img class="c-foto" src="${h(c.foto)}" alt="" loading="lazy" data-ini="${h(iniciais(c.nome))}">`
                 : `<div class="c-foto sem">${h(iniciais(c.nome))}</div>`}
        <div class="c-base"><div class="c-nome ${tamNome(c.nome)}">${h(c.nome)}</div>
          <div class="c-linha"><div><b>${c.linha.pts}</b>PTS</div><div><b>${c.linha.reb}</b>REB</div><div><b>${c.linha.ast}</b>AST</div></div>
          <div class="c-time">${h(c.time)}</div></div>
      </div>
      <div class="face verso">
        <div class="v-cab"><b class="${tamNome(c.nome)}">${h(c.nome)}</b><span>${c.ovr}</span></div>
        <div class="v-ats">${ats}</div>
        <div class="v-temp">${h(c.pos)}${c.pos2 ? '/' + h(c.pos2) : ''} · ${h(c.time)}<br>${bio}<br>
          Temporada: ${c.linha.pts} pts · ${c.linha.reb} reb · ${c.linha.ast} ast em ${c.linha.gp} jogos</div>
      </div>
    </div></div>`;
  }

  // Foto que não carrega vira as iniciais — carta com ícone quebrado é pior.
  document.addEventListener('error', e => {
    const img = e.target;
    if (img.classList && img.classList.contains('c-foto')) {
      const d = document.createElement('div');
      d.className = 'c-foto sem';
      d.textContent = img.dataset.ini || '';
      img.replaceWith(d);
    } else if (img.classList && (img.classList.contains('c-logo'))) {
      img.remove();
    }
  }, true);

  // ── salão ───────────────────────────────────────────────────────────
  function desenharSalao() {
    const ordem = ['campeao', 'final', 'cf', 'r2', 'r1', 'playin', 'fora'];
    $('#tabela-premios').innerHTML = ordem.map(f =>
      `<tr><td>${h(H.fases[f])}</td><td>${H.premios[f] ? num(H.premios[f] * H.dobro) : '—'}</td></tr>`).join('');
    $('#nota-premiadas').textContent = (H.dobro > 1 ? 'Prêmios em DOBRO hoje! ' : '') + (H.restantes > 0
      ? `Você ainda tem ${H.restantes} temporada${H.restantes > 1 ? 's' : ''} premiada${H.restantes > 1 ? 's' : ''} hoje.`
      : 'Suas temporadas premiadas de hoje acabaram — as próximas valem só pro salão.');
  }

  $('#bt-comecar').addEventListener('click', async () => {
    if (ocupado) return;
    ocupado = true;
    const r = await api('novo');
    ocupado = false;
    if (r.ok) { estado = r.estado; desenharDraft(); telas('draft'); }
  });

  // ── draft ───────────────────────────────────────────────────────────
  /* A posição de cada titular na quadra mora no CSS (.vaga.p0 … .p4), que
     é quem sabe se a quadra está deitada (desktop) ou em pé (celular). */
  function vagaHTML(v) {
    const c = estado.time[v];
    const rot = v < 5 ? H.titulares[v] : (v + 1) + 'º';
    const min = v >= 5 ? `<span class="min">${H.minutos[v]} min</span>` : '';
    if (!c) {
      const pulsa = estado.opcoes === null && v === proximaVazia() ? ' pulsa' : '';
      return `<div class="vazia${pulsa}" data-v="${v}" title="Abrir pacote"><i class="bi bi-plus-lg"></i></div>
        <div class="vaga-info"><span>${rot}</span>${min}</div>`;
    }
    const q = estado.quimica.jogadores[v] ?? 0;
    const naPos = v >= 5 || c.pos === H.titulares[v] || c.pos2 === H.titulares[v];
    return `<div class="vaga-carta" data-v="${v}">${cartaHTML(c)}</div>
      <div class="vaga-info ${naPos ? '' : 'fora'}" title="Química ${q} de 3"><span>${naPos ? rot : rot + ' · FORA DE POSIÇÃO'}</span>${min}
        <span class="dots q${q}"><i></i><i></i><i></i></span></div>`;
  }
  function proximaVazia() { return estado.time.findIndex(c => !c); }

  const ICONE_EQ = { armacao: 'bi-arrow-left-right', aro: 'bi-shield-fill', espaco: 'bi-bullseye', rebote: 'bi-arrow-repeat' };

  function desenharDraft() {
    const quadra = $('#quadra');
    $$('.vaga', quadra).forEach(x => x.remove());
    for (let v = 0; v < 5; v++) {
      const d = document.createElement('div');
      d.className = `vaga p${v}` + (selecionada === v ? ' sel' : '');
      d.innerHTML = vagaHTML(v);
      quadra.appendChild(d);
    }
    const banco = $('#banco');
    banco.innerHTML = '';
    for (let v = 5; v < 12; v++) {
      const d = document.createElement('div');
      d.className = 'vaga' + (selecionada === v ? ' sel' : '');
      d.innerHTML = vagaHTML(v);
      banco.appendChild(d);
    }

    // ── a HUD ──
    const f = estado.forca;
    const n = estado.time.filter(Boolean).length;
    $('#m-elenco').textContent = n;
    $('#m-pips').innerHTML = estado.time.map(c => `<i class="${c ? 'on' : ''}"></i>`).join('');
    const vazio = n === 0;
    $('#m-forca').textContent = vazio ? '—' : f.forca.toFixed(1);
    const eqT = f.equilibrio.total;
    $('#m-forca-sub').textContent = vazio ? `Os times da NBA vão de ${H.nba_forca[0]} a ${H.nba_forca[2]}`
      : `OVR em quadra ${f.base} · equilíbrio ${eqT >= 0 ? '+' : ''}${eqT} · NBA ${H.nba_forca[0]}–${H.nba_forca[2]}`;
    // A régua vai de 70 a 90: a faixa azul são os times da NBA, o ponto é você.
    const naRegua = x => Math.max(0, Math.min(100, (x - 70) / 20 * 100));
    $('#m-regua-nba').style.left = naRegua(H.nba_forca[0]) + '%';
    $('#m-regua-nba').style.width = (naRegua(H.nba_forca[2]) - naRegua(H.nba_forca[0])) + '%';
    $('#m-regua-eu').hidden = vazio;
    $('#m-regua-eu').style.left = naRegua(f.forca) + '%';
    $('#m-quim').textContent = estado.quimica.total;
    $('#m-anel').style.setProperty('--p', Math.round(estado.quimica.total / 36 * 100));
    $('#m-equilibrio').innerHTML = Object.entries(f.equilibrio.itens).map(([k, it]) => {
      const cls = it.valor > 0 ? 'ok' : it.valor < 0 ? 'ruim' : 'neutro';
      return `<div class="eq ${cls}"><span class="eq-ico"><i class="bi ${ICONE_EQ[k] || 'bi-circle'}"></i></span>
        <div><b>${h(it.label)}</b><small>${h(it.dica)}</small></div><em>${it.valor > 0 ? '+' : ''}${it.valor}</em></div>`;
    }).join('');
    $('#bt-jogar').disabled = !estado.cheio;
    $('#dica-troca').textContent = selecionada !== null
      ? 'Agora toque na vaga pra onde ele vai (ou nele de novo pra cancelar).'
      : estado.cheio ? 'Time completo! Ajuste a escalação trocando cartas de lugar, ou simule a temporada.'
        : 'Toque numa vaga vazia pra abrir o pacote. Toque em duas cartas do time pra trocá-las de lugar.';
  }

  document.addEventListener('click', async e => {
    const alvo = e.target.closest('#tela-draft [data-v]');
    if (!alvo || ocupado) return;
    const v = Number(alvo.dataset.v);
    const cheia = !!estado.time[v];

    if (selecionada !== null) {
      if (selecionada === v) { selecionada = null; desenharDraft(); return; }
      const a = selecionada;
      selecionada = null;
      ocupado = true;
      const r = await api('trocar', { a, b: v });
      ocupado = false;
      if (r.ok) estado = r.estado;
      desenharDraft();
      return;
    }
    if (cheia) { selecionada = v; desenharDraft(); return; }
    abrirPacote(v);
  });

  // ── o pacote ────────────────────────────────────────────────────────
  function rotuloVaga(v) {
    return v < 5 ? `Pacote · ${H.titulares[v]}` : `Pacote · Banco (${v + 1}º)`;
  }

  async function abrirPacote(v, jaAberto = false) {
    ocupado = true;
    const ab = $('#abertura');
    const palco = $('#ab-palco');
    ab.classList.remove('pronta');
    ab.hidden = false;
    document.body.style.overflow = 'hidden';

    if (!jaAberto) {
      $('#ab-vaga').textContent = rotuloVaga(v);
      palco.innerHTML = `<div class="pacote treme" style="font-size:1.25em"><div class="pacote-brilho"></div>
        <div class="pacote-tira"></div><div class="pacote-logo">FBA<br><b>HOOPS</b></div>
        <div class="pacote-vaga">${v < 5 ? h(H.titulares[v]) : 'BANCO'}</div></div>`;
      const [r] = await Promise.all([api('abrir', { vaga: v }), esperar(1150)]);
      if (!r.ok) { fecharAbertura(); ocupado = false; return; }
      estado = r.estado;
      const pac = $('.pacote', palco);
      pac.classList.remove('treme');
      pac.classList.add('rasga');
      await esperar(380);
      const cl = document.createElement('div'); cl.className = 'clarao'; palco.appendChild(cl);
      pac.classList.add('some');
      await esperar(430);
    } else {
      $('#ab-vaga').textContent = rotuloVaga(estado.aberta);
    }

    const ops = estado.opcoes;
    const forcaAgora = estado.forca.forca;
    const quimAgora = estado.quimica.total;
    palco.innerHTML = ops.map((c, i) => {
      const df = Math.round((c.sim_forca - forcaAgora) * 10) / 10;
      const dq = c.sim_quimica - quimAgora;
      const chip = (rot, x) => `<span class="${x > 0 ? 'mais' : x < 0 ? 'menos' : ''}">${rot} ${x > 0 ? '+' + x : x < 0 ? x : '±0'}</span>`;
      // Com o time vazio, "força +66" não diz nada: é a força de um time de
      // um jogador só. A diferença só tem sentido a partir da 2ª carta.
      const delta = estado.time.some(Boolean) ? chip('Força', df) + chip('Química', dq) : '<span>Primeira carta do time</span>';
      return `<div class="opcao r-${c.rar}" data-i="${i}">
        <div style="position:relative">${cartaHTML(c)}<div class="capa"></div></div>
        <div class="op-acao"><div class="op-delta">${delta}</div>
          <button class="btn-escolher" data-escolher="${i}"><i class="bi bi-check-lg"></i> Escolher</button></div>
      </div>`;
    }).join('');

    const cartas = $$('.opcao', palco);
    for (const [i, el] of cartas.entries()) {
      setTimeout(() => el.classList.add('chega'), i * (jaAberto ? 40 : 110));
    }
    await esperar(jaAberto ? 300 : 650);
    for (const [i, el] of cartas.entries()) {
      const ultima = i === cartas.length - 1;
      const especial = ['icone', 'ouro'].includes(ops[i].rar);
      if (!jaAberto && ultima && especial) await esperar(550); // o suspense da dourada
      el.classList.add('revelada');
      if (!jaAberto && especial) el.classList.add('destaque');
      await esperar(jaAberto ? 60 : 340);
    }
    ab.classList.add('pronta');
    ocupado = false;
  }

  function fecharAbertura() {
    $('#abertura').hidden = true;
    $('#ab-palco').innerHTML = '';
    document.body.style.overflow = '';
  }

  $('#ab-palco').addEventListener('click', async e => {
    if (!$('#abertura').classList.contains('pronta') || ocupado) return;
    const bt = e.target.closest('[data-escolher]');
    if (bt) {
      ocupado = true;
      bt.disabled = true;
      const r = await api('escolher', { carta: bt.dataset.escolher });
      ocupado = false;
      if (r.ok) { estado = r.estado; fecharAbertura(); desenharDraft(); }
      else bt.disabled = false;
      return;
    }
    const carta = e.target.closest('.carta');
    if (carta) carta.classList.toggle('virada');
  });

  // A carta inclina na direção do mouse, como carta de verdade na mão.
  // Só no mouse: no toque, inclinar no dedo atrapalharia o virar.
  $('#ab-palco').addEventListener('pointermove', e => {
    if (e.pointerType !== 'mouse') return;
    const carta = e.target.closest('.opcao.revelada .carta');
    $$('.opcao .carta', $('#ab-palco')).forEach(c => { if (c !== carta) { c.style.removeProperty('--rx'); c.style.removeProperty('--ry'); } });
    if (!carta) return;
    const r = carta.getBoundingClientRect();
    const x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
    carta.style.setProperty('--ry', (x * 16).toFixed(1) + 'deg');
    carta.style.setProperty('--rx', (-y * 12).toFixed(1) + 'deg');
  });
  $('#ab-palco').addEventListener('pointerleave', () => {
    $$('.opcao .carta', $('#ab-palco')).forEach(c => { c.style.removeProperty('--rx'); c.style.removeProperty('--ry'); });
  });

  $('#bt-desistir').addEventListener('click', async () => {
    if (ocupado || !confirm('Desistir deste draft? O time montado até aqui se perde.')) return;
    const r = await api('desistir');
    if (r.ok) { estado = r.estado; selecionada = null; telas('salao'); }
  });

  $('#bt-jogar').addEventListener('click', async () => {
    if (ocupado || !estado.cheio) return;
    ocupado = true;
    const bt = $('#bt-jogar');
    bt.disabled = true;
    bt.innerHTML = '<i class="bi bi-hourglass-split"></i><span>Simulando…</span>';
    const r = await api('jogar');
    ocupado = false;
    bt.innerHTML = '<i class="bi bi-play-circle-fill"></i><span>Simular temporada</span>';
    if (!r.ok) { bt.disabled = false; return; }
    estado = { ativo: false };
    H.restantes = Math.max(0, H.restantes - (r.replay.premiada ? 1 : 0));
    assistir(r.replay, r.saldo);
  });

  // ══════════════════════════ A TEMPORADA ═══════════════════════════
  let vel = 1;
  let pular = false;
  function marcarVel(v) {
    vel = v;
    $$('.controles [data-vel]').forEach(x => x.classList.toggle('ativo', Number(x.dataset.vel) === v));
  }
  $$('.controles [data-vel]').forEach(b => b.addEventListener('click', () => marcarVel(Number(b.dataset.vel))));
  $('#bt-pular').addEventListener('click', () => { pular = true; });
  const pausa = ms => pular ? Promise.resolve() : esperar(ms / vel);

  function escudo(R, s, grande) {
    const t = R.times[s];
    if (t && t[2]) return `<img src="${h(t[2])}" alt="">`;
    const nome = t ? t[0] : s;
    return `<span class="${grande ? '' : 'mini-escudo'}">${h(iniciais(nome))}</span>`;
  }
  const nomeT = (R, s) => R.times[s] ? R.times[s][0] : s;
  const confNome = c => c === 'L' ? 'Conferência Leste' : 'Conferência Oeste';

  function tabelaHTML(R, ordem, t) {
    return ordem.map((s, i) => {
      const z = i < 6 ? 'z1' : i < 10 ? 'z2' : '';
      return `<div class="linha-t ${z} ${s === 'EU' ? 'eu' : ''}" data-s="${h(s)}"><span class="p">${i + 1}</span>${escudo(R, s)}
        <b>${h(nomeT(R, s))}</b><span class="r">${t[s].v}-${t[s].d}</span><span class="s">${t[s].saldo > 0 ? '+' : ''}${t[s].saldo}</span></div>`;
    }).join('');
  }

  // Reordena com animação: cada linha sai de onde estava (FLIP).
  function atualizarTabela(R, ordem, t) {
    const caixa = $('#t-tabela');
    const antes = {};
    $$('.linha-t', caixa).forEach(el => { antes[el.dataset.s] = el.getBoundingClientRect().top; });
    caixa.innerHTML = tabelaHTML(R, ordem, t);
    if (pular) return;
    $$('.linha-t', caixa).forEach(el => {
      const a = antes[el.dataset.s];
      if (a === undefined) return;
      const d = a - el.getBoundingClientRect().top;
      if (!d) return;
      el.style.transition = 'none';
      el.style.transform = `translateY(${d}px)`;
      requestAnimationFrame(() => { el.style.transition = ''; el.style.transform = ''; });
    });
  }

  function marco(txt) {
    const m = $('#t-marco');
    m.hidden = false;
    m.innerHTML = txt;
  }

  async function assistir(R, saldoFinal) {
    marcarVel(1); pular = false;
    telas('temporada');
    $('#t-regular').hidden = false;
    $('#t-barra').hidden = false;
    $('#t-po').hidden = true;
    $('#tela-temporada').classList.remove('modo-po');
    $('#t-nome').textContent = R.times.EU[0];
    $('#t-escudo').textContent = iniciais(R.times.EU[0]);
    $('#t-conf').textContent = `${confNome(R.conf)} · no lugar do ${R.cedeu.nome} · força ${R.forca}`;
    $('#t-fase').textContent = 'Temporada regular';
    $('#t-tabela-tit').textContent = confNome(R.conf);
    $('#t-ticker').innerHTML = '';
    $('#t-marco').hidden = true;

    const conf = Object.keys(R.times).filter(s => R.times[s][4] === R.conf);
    const t = {};
    Object.keys(R.times).forEach(s => { t[s] = { v: 0, d: 0, saldo: 0 }; });
    const ordenar = () => [...conf].sort((a, b) => (t[b].v - t[a].v) || (t[b].saldo - t[a].saldo) || (a < b ? -1 : 1));
    $('#t-tabela').innerHTML = tabelaHTML(R, ordenar(), t);

    let k = 0, v = 0, d = 0;
    for (let i = 0; i < R.jogos.length; i++) {
      const [c, f, pc, pf, pr] = R.jogos[i];
      t[c].saldo += pc - pf; t[f].saldo += pf - pc;
      if (pc > pf) { t[c].v++; t[f].d++; } else { t[f].v++; t[c].d++; }
      if (c !== 'EU' && f !== 'EU') continue;

      k++;
      const casa = c === 'EU';
      const meu = casa ? pc : pf, dele = casa ? pf : pc, adv = casa ? f : c;
      const venceu = meu > dele;
      venceu ? v++ : d++;

      if (!pular) {
        const dest = R.destaques[i];
        const quem = dest ? R.elenco[dest[0]] : null;
        const linha = document.createElement('div');
        linha.className = 'jogo ' + (venceu ? 'v' : 'd');
        linha.innerHTML = `<span class="j-n">J${k}</span>${escudo(R, adv)}
          <div class="j-adv"><b>${casa ? 'vs' : '@'} ${h(nomeT(R, adv))}</b>
          <small>${quem ? h(nomeCarta(quem.nome)) + ': ' + dest[1] + ' pts, ' + dest[2] + ' reb, ' + dest[3] + ' ast' : ''}</small></div>
          <span class="j-res"><em>${venceu ? 'V' : 'D'}</em>${meu}-${dele}${pr ? ` <small>${pr > 1 ? pr : ''}OT</small>` : ''}</span>`;
        const tk = $('#t-ticker');
        tk.prepend(linha);
        while (tk.children.length > 7) tk.lastChild.remove();
        $('#t-v').textContent = v; $('#t-d').textContent = d;
        $(venceu ? '#t-v' : '#t-d').classList.remove('bate');
        void $('#t-v').offsetWidth;
        $(venceu ? '#t-v' : '#t-d').classList.add('bate');
        $('#t-progresso').style.width = (k / 82 * 100) + '%';
        $('#t-jogo').textContent = `Jogo ${k} de 82`;
        atualizarTabela(R, ordenar(), t);

        const pos = ordenar().indexOf('EU') + 1;
        if (k === 20) marco(`<b>Primeiro quarto da temporada:</b> ${v}-${d}, ${pos}º no ${R.conf === 'L' ? 'Leste' : 'Oeste'}.`);
        if (k === 41) marco(`<b>Metade da temporada!</b> ${v}-${d}. ${pos <= 6 ? 'Em zona de playoff direto.' : pos <= 10 ? 'Na briga pelo play-in.' : 'Fora da zona de classificação — tem chão pela frente.'}`);
        if (k === 55) marco(`<b>All-Star break.</b> ${v}-${d}, ${pos}º lugar. Faltam 27 jogos.`);
        if (k === 72) marco(`<b>Reta final:</b> 10 jogos pra decidir. ${pos}º, ${v}-${d}.`);
        await pausa(k > 75 ? 620 : 380);
      }
    }

    // a classificação final é a do servidor — ela já desempata do jeito oficial
    $('#t-v').textContent = R.v; $('#t-d').textContent = R.d;
    $('#t-progresso').style.width = '100%';
    $('#t-jogo').textContent = 'Fim da temporada regular';
    $('#t-tabela').innerHTML = tabelaHTML(R, R.ordem[R.conf], R.tabela);
    marco(`<b>Fim da temporada regular:</b> ${R.v}-${R.d}, ${R.posicao}º lugar na ${confNome(R.conf)}. ` +
      (R.posicao <= 6 ? 'Vaga direta nos playoffs!' : R.posicao <= 10 ? 'Vai disputar o play-in.' : 'Fora da pós-temporada.'));
    pular = false;
    await esperar(1800);

    await playoffs(R);
    await esperar(600);
    if (saldoFinal !== undefined) {
      $('#saldo').textContent = num(saldoFinal);
      $('#saldo').parentElement.classList.add('pulo');
    }
    // Campeão: antes do resultado, o elenco sobe ao palco (hoops_campeao.js).
    if (R.fase === 'campeao' && window.HoopsCampeao) {
      await new Promise(ok => window.HoopsCampeao.abrir(R, { aoFechar: ok }));
    }
    fim(R);
  }

  // ══════════════════════════ OS PLAYOFFS ═══════════════════════════
  /* Palco próprio, e não um bloco embaixo da classificação: na primeira
     versão a chave aparecia fora da tela, com a velocidade que sobrou da
     temporada (10x), e quem assistia ia direto da tabela pro "Campeão".
     Agora a tela troca, a velocidade volta pro 1x, cada rodada abre com um
     letreiro e TODAS as séries andam jogo a jogo, juntas. */
  // Na chave vai a SIGLA (DEN, CLE...), como nas chaves da NBA: com sete
  // colunas, o nome inteiro virava "Nugg…". O nome completo fica no title.
  const sigla = (R, s) => s === 'EU' ? iniciais(nomeT(R, 'EU')) : s;
  const vencedorDo = j => (j[2] > j[3] ? j[0] : j[1]);

  // Oeste à esquerda, Leste à direita, as Finais no meio — como a NBA desenha.
  const COLUNAS = [['O', 'r1', '1ª rodada'], ['O', 'r2', 'Semifinal'], ['O', 'cf', 'Final do Oeste'],
    ['F', 'final', 'Finais da NBA'], ['L', 'cf', 'Final do Leste'], ['L', 'r2', 'Semifinal'], ['L', 'r1', '1ª rodada']];
  const QUANTAS = { r1: 4, r2: 2, cf: 1, final: 1 };

  async function letreiro(txt) {
    if (pular) return;
    const b = $('#po-banner');
    $('span', b).textContent = txt;
    b.hidden = false;
    b.classList.remove('anima'); void b.offsetWidth; b.classList.add('anima');
    await esperar(1500);
    b.hidden = true;
  }

  function montarChave() {
    const linha = '<div class="bx-t"><span class="sd"></span><span class="lg"></span><b>—</b><em></em></div>';
    $('#po-chave').innerHTML = COLUNAS.map(([c, r, rot]) => `<div class="col-chave ${r === 'final' ? 'col-final' : ''}" data-col="${c}-${r}">
      <div class="col-rot">${rot}</div><div class="col-caixas">${Array.from({ length: QUANTAS[r] },
        (_, i) => `<div class="bx vazio" id="bx-${c}-${r}-${i}">${linha}${linha}</div>`).join('')}</div></div>`).join('');
  }

  /** Põe dois times numa caixa da chave (null = ainda vem do play-in). */
  function preencher(R, id, a, b, seeds) {
    const bx = $('#bx-' + id);
    if (!bx) return;
    bx.classList.remove('vazio', 'campeao');
    bx.classList.toggle('minha', a === 'EU' || b === 'EU');
    $$('.bx-t', bx).forEach((row, i) => {
      const s = i === 0 ? a : b;
      row.className = 'bx-t' + (s === 'EU' ? ' eu' : '');
      $('.sd', row).textContent = s && seeds ? seeds.indexOf(s) + 1 : '';
      $('.lg', row).innerHTML = s ? escudo(R, s) : '';
      $('b', row).textContent = s ? sigla(R, s) : 'Play-in';
      row.title = s ? nomeT(R, s) : '';
      $('em', row).textContent = s ? '0' : '';
    });
    if (!pular) { bx.classList.remove('entra'); void bx.offsetWidth; bx.classList.add('entra'); }
  }

  /** Mostra a série até o jogo g: vitórias de cada lado, e quem passou. */
  function placarBox(id, serie, g) {
    const bx = $('#bx-' + id);
    const jogos = serie.jogos.slice(0, g);
    const va = jogos.filter(j => vencedorDo(j) === serie.a).length;
    const rows = $$('.bx-t', bx);
    $('em', rows[0]).textContent = va;
    $('em', rows[1]).textContent = jogos.length - va;
    const ult = jogos[jogos.length - 1];
    if (ult && !pular) {
      const r = rows[vencedorDo(ult) === serie.a ? 0 : 1];
      r.classList.remove('marcou'); void r.offsetWidth; r.classList.add('marcou');
    }
    if (g >= serie.jogos.length) {
      rows.forEach((r, i) => r.classList.add((i === 0 ? serie.a : serie.b) === serie.vencedor ? 'venceu' : 'perdeu'));
    }
  }

  function painel(html, cls = '') {
    const p = $('#po-minha');
    p.className = 'po-minha bloco ' + cls;
    p.innerHTML = html;
  }

  /** O painel grande da série do GM, jogo a jogo. */
  function painelMinha(R, titulo, serie, g) {
    const adv = serie.a === 'EU' ? serie.b : serie.a;
    const jogos = serie.jogos.slice(0, g);
    const meus = jogos.filter(j => vencedorDo(j) === 'EU').length;
    const meuPlacar = j => (j[0] === 'EU' ? [j[2], j[3]] : [j[3], j[2]]);
    const chips = jogos.map((j, n) => {
      const v = vencedorDo(j) === 'EU';
      const [eu, ele] = meuPlacar(j);
      return `<span class="chip-j ${v ? 'v' : 'd'}">J${n + 1} <b>${v ? 'V' : 'D'}</b> ${eu}-${ele}${j[4] ? ' OT' : ''}</span>`;
    }).join('');
    const unico = serie.jogos.length === 1;
    const [n1, n2] = unico && g ? meuPlacar(jogos[0]) : [meus, jogos.length - meus];
    const acabou = g >= serie.jogos.length;
    const res = !acabou ? '' : serie.vencedor === 'EU'
      ? `<div class="pm-res v">${titulo === 'Finais da NBA' ? 'CAMPEÃO!' : 'Vitória — seu time avança!'}</div>`
      : '<div class="pm-res d">Eliminado.</div>';
    painel(`<div class="pm-rot">Sua ${unico ? 'partida' : 'série'} · ${h(titulo)}</div>
      <div class="pm-placar"><div class="pm-time eu">${escudo(R, 'EU', true)}<b>${h(nomeT(R, 'EU'))}</b></div>
        <div class="pm-num"><span>${n1}</span><i>-</i><span>${n2}</span></div>
        <div class="pm-time">${escudo(R, adv, true)}<b>${h(nomeT(R, adv))}</b></div></div>
      ${unico ? '' : `<div class="pm-jogos">${chips}</div>`}${res}`, 'minha');
  }

  function painelFora(R, titulo) {
    const txt = ['fora', 'playin'].includes(R.fase)
      ? 'Seu time não está nos playoffs. Acompanhe quem leva o título.'
      : `Seu time já caiu (${h(R.fase_nome.toLowerCase())}). Acompanhe até o título.`;
    painel(`<div class="pm-rot">${h(titulo)}</div><div class="pm-msg">${txt}</div>`);
  }

  // No celular a chave é uma coluna comprida: leva a rodada da vez pra tela.
  function rolarPara(r) {
    if (window.innerWidth > 760) return;
    const col = $(`[data-col="O-${r}"]`) || $(`[data-col="F-${r}"]`);
    if (col) col.scrollIntoView({ behavior: pular ? 'auto' : 'smooth', block: 'center' });
  }

  async function playIn(R) {
    $('#po-playin').hidden = false;
    const rot = ['7º × 8º', '9º × 10º', 'Vaga do 8º'];
    $('#pi-grid').innerHTML = ['O', 'L'].map(c => `<div class="pi-conf"><h4>${c === 'O' ? 'Oeste' : 'Leste'}</h4>${
      [0, 1, 2].map(i => `<div class="pi-jogo" id="pi-${c}-${i}"><small>${rot[i]}</small>
        <div class="bx-t"></div><div class="bx-t"></div></div>`).join('')}</div>`).join('');
    const mostrar = (c, i, revelado) => {
      const jogo = R.playin[c].jogos[i];
      const [casa, fora, pc, pf] = jogo.jogos[0];
      const el = $(`#pi-${c}-${i}`);
      el.classList.toggle('minha', casa === 'EU' || fora === 'EU');
      $$('.bx-t', el).forEach((row, k) => {
        const s = k === 0 ? casa : fora;
        row.className = 'bx-t' + (s === 'EU' ? ' eu' : '') + (revelado ? (jogo.vencedor === s ? ' venceu marcou' : ' perdeu') : '');
        row.innerHTML = `<span class="sd">${R.ordem[c].indexOf(s) + 1}</span><span class="lg">${escudo(R, s)}</span>
          <b title="${h(nomeT(R, s))}">${h(sigla(R, s))}</b><em>${revelado ? (k === 0 ? pc : pf) : ''}</em>`;
      });
    };
    for (const i of [0, 1, 2]) {
      for (const c of ['O', 'L']) mostrar(c, i, false);
      const meu = ['O', 'L'].map(c => R.playin[c].jogos[i]).find(j => j.a === 'EU' || j.b === 'EU');
      if (meu) painelMinha(R, 'Play-in', meu, 0);
      await pausa(900);
      for (const c of ['O', 'L']) mostrar(c, i, true);
      if (meu) painelMinha(R, 'Play-in', meu, 1);
      await pausa(meu ? 1800 : 1100);
    }
  }

  async function rodadaPO(R, r, titulo, series) {
    $('#t-fase').textContent = titulo;
    rolarPara(r);
    await letreiro(titulo);
    const minha = series.find(x => x.s.a === 'EU' || x.s.b === 'EU');
    if (minha) painelMinha(R, titulo, minha.s, 0); else painelFora(R, titulo);
    const max = Math.max(...series.map(x => x.s.jogos.length));
    for (let g = 1; g <= max; g++) {
      for (const x of series) if (g <= x.s.jogos.length) placarBox(x.id, x.s, g);
      const meuJogo = minha && g <= minha.s.jogos.length;
      if (meuJogo) painelMinha(R, titulo, minha.s, g);
      await pausa(meuJogo ? 1100 : 750);
    }
    await pausa(1200);
    pular = false; // "Pular fase" pula UMA rodada; a próxima volta a ser contada
  }

  async function playoffs(R) {
    marcarVel(1);
    pular = false;
    $('#t-regular').hidden = true;
    $('#t-barra').hidden = true;
    $('#t-po').hidden = false;
    $('#tela-temporada').classList.add('modo-po');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    montarChave();

    // A chave nasce com os seis primeiros de cada lado; 7º e 8º saem do play-in.
    for (const c of ['O', 'L']) {
      const sd = R.chave[c].seeds;
      R.chave[c].r1.forEach((s, i) => preencher(R, `${c}-r1-${i}`, s.a, [0, 3].includes(i) ? null : s.b, sd));
    }
    $('#t-fase').textContent = 'Play-in';
    const lado = R.conf === 'L' ? 'Leste' : 'Oeste';
    painel(`<div class="pm-rot">Pós-temporada</div><div class="pm-msg">${
      R.posicao <= 6 ? `Seu time está classificado direto, como ${R.posicao}º do ${lado}. Primeiro, o play-in define os últimos.`
        : R.posicao <= 10 ? `Seu time terminou em ${R.posicao}º do ${lado} e vai disputar o play-in.`
          : 'Seu time ficou fora. Acompanhe quem leva o título.'}</div>`);
    await letreiro('Play-in');
    await playIn(R);
    for (const c of ['O', 'L']) {
      const sd = R.chave[c].seeds;
      [0, 3].forEach(i => preencher(R, `${c}-r1-${i}`, R.chave[c].r1[i].a, R.chave[c].r1[i].b, sd));
    }
    await pausa(1200);
    pular = false;
    $('#po-playin').hidden = true;

    const daRodada = r => ['O', 'L'].flatMap(c => R.chave[c][r].map((s, i) => ({ id: `${c}-${r}-${i}`, s })));
    await rodadaPO(R, 'r1', '1ª rodada', daRodada('r1'));
    for (const c of ['O', 'L']) R.chave[c].r2.forEach((s, i) => preencher(R, `${c}-r2-${i}`, s.a, s.b, R.chave[c].seeds));
    await pausa(500);
    await rodadaPO(R, 'r2', 'Semifinais de conferência', daRodada('r2'));
    for (const c of ['O', 'L']) preencher(R, `${c}-cf-0`, R.chave[c].cf.a, R.chave[c].cf.b, R.chave[c].seeds);
    await pausa(500);
    await rodadaPO(R, 'cf', 'Finais de conferência', ['O', 'L'].map(c => ({ id: `${c}-cf-0`, s: R.chave[c].cf })));
    preencher(R, 'F-final-0', R.final.a, R.final.b, null);
    await pausa(500);
    await rodadaPO(R, 'final', 'Finais da NBA', [{ id: 'F-final-0', s: R.final }]);

    $('#bx-F-final-0').classList.add('campeao');
    $('#t-fase').textContent = 'Campeão';
    painel(`<div class="pm-campeao">${escudo(R, R.campeao, true)}<div><small>Campeão da NBA</small>
      <b>${h(nomeT(R, R.campeao))}</b></div><i class="bi bi-trophy-fill"></i></div>`, R.campeao === 'EU' ? 'ouro' : '');
    await esperar(2800);
  }

  // ── fim ─────────────────────────────────────────────────────────────
  function fim(R) {
    telas('fim');
    const campeao = R.fase === 'campeao';
    const premio = R.premio > 0
      ? `<div class="f-premio"><i class="bi bi-coin"></i> +${num(R.premio)} moedas</div>`
      : `<div class="f-premio zero">${R.mestre ? 'Código mestre: temporada sem moedas e fora do salão.'
        : R.premiada ? 'Essa fase não paga moedas.' : 'Temporada fora do limite diário de premiadas — valeu pro salão.'}</div>`;
    const linhas = R.elenco.map((c, v) => {
      if (!c) return '';
      const m = R.medias[v] || { pts: 0, reb: 0, ast: 0 };
      return `<tr><td>${v < 5 ? h(H.titulares[v]) : (v + 1) + 'º'}</td><td><span class="ovr r-${c.rar}">${c.ovr}</span> ${h(c.nome)}</td>
        <td class="n">${m.pts.toFixed(1)}</td><td class="n">${m.reb.toFixed(1)}</td><td class="n">${m.ast.toFixed(1)}</td></tr>`;
    }).join('');
    const campeaoNome = nomeT(R, R.campeao);
    $('#fim').innerHTML = `
      <div class="fim-cab ${campeao ? 'campeao' : ''}">
        ${campeao ? '<div class="trofeu"><i class="bi bi-trophy-fill"></i></div>' : ''}
        <div class="f-rot">${h(R.times.EU[0])}</div>
        <h2>${h(R.fase_nome)}</h2>
        <div class="f-rec">${R.v}-${R.d} na temporada regular · ${R.posicao}º na ${confNome(R.conf)}${campeao ? '' : ' · campeão: ' + h(campeaoNome)}</div>
        ${premio}
      </div>
      <div class="bloco"><h3><i class="bi bi-bar-chart-fill"></i> As médias do seu elenco</h3>
        <div class="tabela-scroll"><table class="elenco-fim"><thead><tr><th></th><th>Jogador</th><th class="n">PTS</th><th class="n">REB</th><th class="n">AST</th></tr></thead>
        <tbody>${linhas}</tbody></table></div></div>
      <div class="fim-acoes">
        <button class="btn pri grande" id="bt-denovo"><i class="bi bi-box-seam"></i> Montar outro time</button>
        ${campeao ? '<button class="btn grande" id="bt-festa"><i class="bi bi-trophy-fill"></i> Ver a comemoração</button>' : ''}
        <button class="btn grande" id="bt-rever"><i class="bi bi-arrow-repeat"></i> Ver a temporada de novo</button>
        <a class="btn grande" href="${location.pathname}"><i class="bi bi-stars"></i> Salão</a>
      </div>`;
    $('#bt-denovo').addEventListener('click', () => $('#bt-comecar').click());
    $('#bt-rever').addEventListener('click', () => assistir(R));
    if (campeao) $('#bt-festa').addEventListener('click', () => window.HoopsCampeao && window.HoopsCampeao.abrir(R));
    if (campeao) confete();
  }

  function confete() {
    const cores = ['#f5d66b', '#f97316', '#22c55e', '#3b82f6', '#fc0025', '#fff'];
    for (let i = 0; i < 120; i++) {
      const c = document.createElement('div');
      c.className = 'confete';
      c.style.left = Math.random() * 100 + 'vw';
      c.style.background = cores[i % cores.length];
      c.style.animationDuration = 2.2 + Math.random() * 2.5 + 's';
      c.style.animationDelay = Math.random() * 1.2 + 's';
      document.body.appendChild(c);
      setTimeout(() => c.remove(), 6500);
    }
  }

  // ── o código mestre ─────────────────────────────────────────────────
  /* O navegador não sabe o código: guarda só o sha256 dele (H.mestre_hash).
     As últimas teclas digitadas são comparadas com esse hash, e quando batem
     o TEXTO vai pro servidor, que é quem confere e liga o modo. */
  function cantoMestre(ligado) {
    $('#canto-mestre').hidden = !ligado;
  }
  let teclas = '';
  async function sha256(txt) {
    const b = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(txt));
    return [...new Uint8Array(b)].map(x => x.toString(16).padStart(2, '0')).join('');
  }
  document.addEventListener('keydown', async e => {
    if (!window.crypto || !crypto.subtle || e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
    if (e.target.closest && e.target.closest('input, textarea, select, [contenteditable]')) return;
    teclas = (teclas + e.key.toLowerCase()).slice(-H.mestre_len);
    if (teclas.length < H.mestre_len || (await sha256(teclas)) !== H.mestre_hash) return;
    const tentativa = teclas;
    teclas = '';
    const fd = new FormData();
    fd.append('acao', 'codigo');
    fd.append('codigo', tentativa);
    try {
      const r = await (await fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })).json();
      if (!r.ok) return;
      H.mestre = r.mestre;
      cantoMestre(r.mestre);
      aviso(r.mestre ? 'Código mestre ativado: título garantido, sem moedas e fora do salão.' : 'Código mestre desativado.');
    } catch (err) { /* sem conexão: nada muda */ }
  });

  // ── início ──────────────────────────────────────────────────────────
  desenharSalao();
  cantoMestre(H.mestre);
  if (H.rever) {
    fim(H.rever);
  } else if (estado.ativo) {
    desenharDraft();
    telas('draft');
    if (estado.opcoes) abrirPacote(estado.aberta, true); // F5 com pacote aberto: as mesmas cinco
  } else {
    telas('salao');
  }
})();
