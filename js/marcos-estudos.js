/* ESTUDOS DE JOGADORES — a tela (marcos-estudos.php). Só leitura: tudo vem de
   api/marcos-estudos.php, e as contas moram em backend/estudos_jogadores.php. */
(function () {
  'use strict';

  const API = '/api/marcos-estudos.php';
  const POR_PAGINA = 100;
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const semAcento = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

  async function get(params) {
    const r = await fetch(API + '?' + new URLSearchParams(params));
    const d = await r.json().catch(() => ({ ok: false, erro: 'Resposta inválida do servidor.' }));
    if (!d.ok) throw new Error(d.erro || 'Falhou.');
    return d;
  }

  /* ── Formatação ─────────────────────────────────────────────────────── */
  const num = (x, d = 1) => (x === null || x === undefined || Number.isNaN(x)) ? '—' : Number(x).toFixed(d).replace(/\.0+$/, '');
  const cOvr = x => x === null || x === undefined ? 'fraco' : x >= 90 ? 'top' : x >= 85 ? 'alto' : x < 70 ? 'fraco' : '';

  /* ── As colunas da tabela ───────────────────────────────────────────── */
  const M = l => l.m || {};
  const COLS = [
    { k: 'pos',   rot: 'Pos',        get: l => l.pos, txt: true },
    { k: 'idade', rot: 'Idade',      get: l => l.idade },
    { k: 'ovr',   rot: 'OVR',        get: l => l.ovr, cls: cOvr },
    { k: 'pico',  rot: 'Pico',       get: l => M(l).pico, cls: cOvr, flag: true },
    { k: 'pks',   rot: 'Pico sust.', get: l => M(l).pico_sust, cls: cOvr },
    { k: 'ipk',   rot: 'Idade pico', get: l => M(l).idade_pico_sust },
    { k: 'nt',    rot: 'Temp.',      get: l => M(l).n },
    { k: 'a85',   rot: '85+',        get: l => M(l).anos_85 },
    { k: 'a90',   rot: '90+',        get: l => M(l).anos_90 },
    { k: 'fim',   rot: 'Fim',        get: l => M(l).ovr_fim, cls: cOvr },
    { k: 'qd',    rot: 'Queda',      get: l => M(l).queda },
    { k: 'pts',   rot: 'PTS',        get: l => M(l).pts_pg, d: 1 },
    { k: 'reb',   rot: 'REB',        get: l => M(l).reb_pg, d: 1 },
    { k: 'ast',   rot: 'AST',        get: l => M(l).ast_pg, d: 1 },
    { k: 'stl',   rot: 'STL',        get: l => M(l).stl_pg, d: 1 },
    { k: 'blk',   rot: 'BLK',        get: l => M(l).blk_pg, d: 1 },
    { k: 'fg',    rot: 'FG%',        get: l => M(l).fg, d: 1 },
    { k: 'ppts',  rot: 'Pico PTS',   get: l => M(l).pico_pts_pg, d: 1 },
    { k: 'preb',  rot: 'Pico REB',   get: l => M(l).pico_reb_pg, d: 1 },
    { k: 'past',  rot: 'Pico AST',   get: l => M(l).pico_ast_pg, d: 1 },
    { k: 'pfg',   rot: 'Pico FG%',   get: l => M(l).pico_fg, d: 1 },
    { k: 'pr',    rot: 'Prêm.',      get: l => M(l).premios_total },
    { k: 'tend',  rot: 'Tend.',      get: l => l.tend ? 1 : 0, show: v => v ? '✓' : '—' },
    { k: 'vidas', rot: 'Vidas',      get: l => l.vidas },
  ];

  /* ── Estado ─────────────────────────────────────────────────────────── */
  const dados = { elite: null, outras: null, historico: null };
  const est = { aba: 'elite', ord: 'ovr', asc: false, pag: 0 };
  let visiveis = [];

  const NOMES_ABA = { elite: 'ELITE', outras: 'Outras ligas', historico: 'Histórico' };

  /* ── Abas ───────────────────────────────────────────────────────────── */
  document.querySelectorAll('.aba').forEach(b => b.addEventListener('click', () => trocarAba(b.dataset.aba)));

  async function trocarAba(aba, nomeParaEstudar) {
    est.aba = aba; est.pag = 0;
    document.querySelectorAll('.aba').forEach(b => b.classList.toggle('on', b.dataset.aba === aba));
    $('painelLista').hidden = aba === 'nome';
    $('painelNome').hidden = aba !== 'nome';
    if (aba === 'nome') {
      history.replaceState(null, '', '#nome' + (nomeParaEstudar ? '=' + encodeURIComponent(nomeParaEstudar) : ''));
      await prepararNomes();
      if (nomeParaEstudar) { $('nomeBusca').value = nomeParaEstudar; estudarNome(nomeParaEstudar); }
      return;
    }
    history.replaceState(null, '', '#' + aba);
    // A coluna "liga" só faz sentido onde há mais de uma; "só num elenco" só no histórico.
    $('fLiga').hidden = aba === 'elite';
    if (aba === 'elite') $('fLiga').value = '';
    $('lAtivos').hidden = aba !== 'historico';
    if (aba !== 'historico') $('fAtivos').checked = false;
    await carregarLista(aba);
  }

  async function carregarLista(aba) {
    if (!dados[aba]) {
      $('corpo').innerHTML = '';
      $('cab').innerHTML = '';
      $('conta').textContent = '';
      $('paginacao').innerHTML = '';
      $('corpo').innerHTML = `<tr><td class="nada" colspan="40">Carregando ${NOMES_ABA[aba]}… (o histórico completo leva alguns segundos)</td></tr>`;
      try {
        dados[aba] = (await get({ acao: 'lista', grupo: aba })).linhas;
      } catch (e) {
        $('corpo').innerHTML = `<tr><td class="nada" colspan="40">Não carregou: ${esc(e.message)}</td></tr>`;
        return;
      }
      const alvo = { elite: 'nElite', outras: 'nOutras', historico: 'nHist' }[aba];
      $(alvo).textContent = dados[aba].length.toLocaleString('pt-BR');
    }
    renderTabela();
  }

  /* ── Filtros ────────────────────────────────────────────────────────── */
  ['fBusca', 'fPos', 'fLiga', 'fOvr', 'fPico', 'fCurtas', 'fStats', 'fAtivos'].forEach(id =>
    $(id).addEventListener('input', () => { est.pag = 0; renderTabela(); }));
  $('bLimpar').addEventListener('click', () => {
    ['fBusca', 'fPos', 'fLiga', 'fOvr', 'fPico'].forEach(id => { $(id).value = ''; });
    ['fCurtas', 'fStats', 'fAtivos'].forEach(id => { $(id).checked = false; });
    est.pag = 0; renderTabela();
  });

  function filtrar(linhas) {
    const q = semAcento($('fBusca').value.trim());
    const pos = $('fPos').value, liga = $('fLiga').value;
    const ovr = parseInt($('fOvr').value, 10), pico = parseInt($('fPico').value, 10);
    const curtas = $('fCurtas').checked, stats = $('fStats').checked, ativos = $('fAtivos').checked;
    return linhas.filter(l => {
      if (q && !(semAcento(l.nome).includes(q) || semAcento(l.time).includes(q))) return false;
      if (pos && l.pos !== pos) return false;
      if (liga && l.liga !== liga) return false;
      if (!Number.isNaN(ovr) && !((l.ovr ?? 0) >= ovr)) return false;
      if (!Number.isNaN(pico) && !((M(l).pico_sust ?? 0) >= pico)) return false;
      if (curtas && M(l).n === 1) return false;
      if (stats && !(M(l).jogos > 0)) return false;
      if (ativos && !l.ativo) return false;
      return true;
    });
  }

  function ordenar(linhas) {
    const col = COLS.find(c => c.k === est.ord);
    const dir = est.asc ? 1 : -1;
    return linhas.slice().sort((a, b) => {
      let va, vb;
      if (est.ord === 'nome') { va = a.nome; vb = b.nome; return dir * va.localeCompare(vb, 'pt'); }
      va = col.get(a); vb = col.get(b);
      if (col.txt) return dir * String(va ?? '').localeCompare(String(vb ?? ''), 'pt');
      // Vazio vai sempre pro fim, qualquer que seja a direção.
      if (va === null || va === undefined) return 1;
      if (vb === null || vb === undefined) return -1;
      return dir * (va - vb);
    });
  }

  /* ── Tabela ─────────────────────────────────────────────────────────── */
  function renderTabela() {
    const todas = dados[est.aba] || [];
    visiveis = ordenar(filtrar(todas));
    const total = visiveis.length;
    const paginas = Math.max(1, Math.ceil(total / POR_PAGINA));
    est.pag = Math.min(est.pag, paginas - 1);
    const fatia = visiveis.slice(est.pag * POR_PAGINA, (est.pag + 1) * POR_PAGINA);

    const filtrando = !!($('fBusca').value || $('fPos').value || $('fLiga').value || $('fOvr').value || $('fPico').value
      || $('fCurtas').checked || $('fStats').checked || $('fAtivos').checked);
    $('bLimpar').hidden = !filtrando;
    $('conta').innerHTML = `<b>${total.toLocaleString('pt-BR')}</b>${filtrando ? ' de ' + todas.length.toLocaleString('pt-BR') : ''} jogadores`;

    $('cab').innerHTML = '<tr><th class="col-jog' + (est.ord === 'nome' ? ' ord' + (est.asc ? ' asc' : '') : '') + '" data-k="nome">Jogador</th>'
      + COLS.map(c => `<th class="${est.ord === c.k ? 'ord' + (est.asc ? ' asc' : '') : ''}" data-k="${c.k}"
            title="${esc(c.rot)}">${esc(c.rot)}</th>`).join('') + '</tr>';

    if (!fatia.length) {
      $('corpo').innerHTML = '<tr><td class="nada" colspan="40">Nenhum jogador com esses filtros.</td></tr>';
    } else {
      $('corpo').innerHTML = fatia.map(l => {
        const m = M(l);
        const celulas = COLS.map(c => {
          const v = c.get(l);
          if (c.txt) return `<td class="n">${esc(v ?? '—')}</td>`;
          const texto = c.show ? c.show(v) : num(v, c.d ?? 0);
          const flag = c.flag && m.fluke ? '<span class="flag" title="Pico de uma temporada só: o OVR não foi sustentado">⚑</span>' : '';
          return `<td class="n ${c.cls ? c.cls(v) : ''}">${texto}${flag}</td>`;
        }).join('');
        const tags = (l.lenda ? '<span class="tag lenda">LENDA</span>' : '') + (!l.ativo ? '<span class="tag fora">fora</span>' : '');
        return `<tr data-id="${l.id}"><td class="col-jog">${esc(l.nome)}${tags}<small>${esc(l.time || '—')}${l.liga ? ' · ' + esc(l.liga) : ''}</small></td>${celulas}</tr>`;
      }).join('');
    }

    $('paginacao').innerHTML = paginas > 1
      ? `<button class="btn" id="pgAnt" ${est.pag === 0 ? 'disabled' : ''}>‹ Anterior</button>
         <span>Página <b>${est.pag + 1}</b> de ${paginas}</span>
         <button class="btn" id="pgProx" ${est.pag >= paginas - 1 ? 'disabled' : ''}>Próxima ›</button>` : '';
    $('pgAnt')?.addEventListener('click', () => { est.pag--; renderTabela(); $('tabela').parentElement.scrollTop = 0; });
    $('pgProx')?.addEventListener('click', () => { est.pag++; renderTabela(); $('tabela').parentElement.scrollTop = 0; });
  }

  $('cab').addEventListener('click', e => {
    const th = e.target.closest('th[data-k]');
    if (!th) return;
    const k = th.dataset.k;
    if (est.ord === k) est.asc = !est.asc; else { est.ord = k; est.asc = k === 'nome' || k === 'pos'; }
    est.pag = 0; renderTabela();
  });
  $('corpo').addEventListener('click', e => {
    const tr = e.target.closest('tr[data-id]');
    if (tr) abrirVida(Number(tr.dataset.id));
  });

  /* ── CSV: a lista inteira (com os filtros e a ordem de agora) ───────── */
  $('bCsv').addEventListener('click', () => {
    const cab = ['id', 'jogador', 'ativo', 'time', 'liga', ...COLS.map(c => c.rot)];
    const lin = visiveis.map(l => [l.id, l.nome, l.ativo ? 1 : 0, l.time ?? '', l.liga ?? '', ...COLS.map(c => c.get(l) ?? '')]);
    const csv = [cab, ...lin].map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(';')).join('\r\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
    a.download = `estudos-${est.aba}-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  });

  /* ── Gráfico de linhas (SVG, sem biblioteca) ────────────────────────── */
  function grafico(series, opc = {}) {
    const W = 900, H = 330, ml = 44, mr = 16, mt = 14, mb = 34;
    const pts = series.flatMap(s => s.pontos);
    if (!pts.length) return '<div class="nada">Sem pontos pra desenhar.</div>';
    const xs = pts.map(p => p[0]), ys = pts.map(p => p[1]);
    const x0 = Math.min(...xs), x1 = Math.max(...xs);
    const y0 = Math.floor((Math.min(...ys) - 3) / 5) * 5, y1 = Math.ceil((Math.max(...ys) + 2) / 5) * 5;
    const X = x => ml + (x1 === x0 ? 0.5 : (x - x0) / (x1 - x0)) * (W - ml - mr);
    const Y = y => mt + (1 - (y - y0) / (y1 - y0 || 1)) * (H - mt - mb);
    let g = '';
    for (let y = y0; y <= y1; y += 5) g += `<line x1="${ml}" x2="${W - mr}" y1="${Y(y)}" y2="${Y(y)}" stroke="rgba(255,255,255,.07)"/><text x="${ml - 8}" y="${Y(y) + 4}" text-anchor="end" fill="#6f6f79" font-size="11">${y}</text>`;
    const passo = x1 - x0 > 20 ? 5 : 2;
    for (let x = Math.ceil(x0 / passo) * passo; x <= x1; x += passo) g += `<text x="${X(x)}" y="${H - 12}" text-anchor="middle" fill="#6f6f79" font-size="11">${x}</text>`;
    g += `<text x="${(ml + W - mr) / 2}" y="${H - 1}" text-anchor="middle" fill="#6f6f79" font-size="10">${esc(opc.eixoX || 'idade')}</text>`;
    const linhas = series.map(s => {
      const d = s.pontos.map((p, i) => (i ? 'L' : 'M') + X(p[0]).toFixed(1) + ' ' + Y(p[1]).toFixed(1)).join(' ');
      const bolas = s.bolas ? s.pontos.map((p, i) => `<circle cx="${X(p[0]).toFixed(1)}" cy="${Y(p[1]).toFixed(1)}" r="${s.raios?.[i] ?? s.raio ?? 3}" fill="${s.cor}"><title>${esc(s.dicas?.[i] ?? `${p[0]}: ${p[1]}`)}</title></circle>`).join('') : '';
      return `<path d="${d}" fill="none" stroke="${s.cor}" stroke-width="${s.largura || 1.5}" opacity="${s.opacidade ?? 1}" stroke-linejoin="round"/>${bolas}`;
    }).join('');
    const leg = series.filter(s => s.rotulo).map(s => `<span><i style="background:${s.cor}"></i>${esc(s.rotulo)}</span>`).join('');
    return `<svg class="grafico" viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(opc.titulo || 'gráfico')}">${g}${linhas}</svg>${leg ? `<div class="leg">${leg}</div>` : ''}`;
  }

  const CORES = ['#60a5fa', '#34d399', '#fbbf24', '#f472b6', '#a78bfa', '#fb923c', '#2dd4bf', '#f87171', '#a3e635'];

  /* ── O popup da carreira ────────────────────────────────────────────── */
  const dlg = $('dlg');
  dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });

  async function abrirVida(id) {
    $('dlgConteudo').innerHTML = '<div class="carga">Carregando carreira…</div>';
    if (!dlg.open) dlg.showModal();
    let v;
    try { v = await get({ acao: 'vida', id }); }
    catch (e) { $('dlgConteudo').innerHTML = `<div class="carga">Não carregou: ${esc(e.message)}</div>`; return; }
    $('dlgConteudo').innerHTML = htmlVida(v);
    $('dlgConteudo').querySelector('.fechar').addEventListener('click', () => dlg.close());
    $('dlgConteudo').querySelectorAll('[data-vida]').forEach(b => b.addEventListener('click', () => abrirVida(Number(b.dataset.vida))));
    $('dlgConteudo').querySelectorAll('[data-estudo]').forEach(b => b.addEventListener('click', () => { dlg.close(); trocarAba('nome', b.dataset.estudo); }));
  }

  const card = (rot, val, peq) => `<div class="card"><span>${esc(rot)}</span><b>${val}</b>${peq ? `<small>${peq}</small>` : ''}</div>`;

  function htmlVida(v) {
    const m = v.m || {}, h = v.hoje;
    const sub = h ? [h.time, h.liga, h.pos, h.idade + ' anos', 'OVR ' + h.ovr, h.altura].filter(Boolean).join(' · ') : 'Fora dos elencos hoje';
    const cards = m.n ? [
      card('Temporadas', m.n, `T${m.temporada_ini}–T${m.temporada_fim} · ${m.times} time(s)`),
      card('Pico', num(m.pico, 0) + (m.fluke ? ' <span class="flag">⚑</span>' : ''), m.fluke ? 'pico de uma temporada' : 'idade ' + m.idade_pico),
      card('Pico sustentado', num(m.pico_sust, 0), 'idade ' + m.idade_pico_sust),
      card('Início → fim', `${m.ovr_ini} → ${m.ovr_fim}`, `${m.idade_ini} → ${m.idade_fim} anos`),
      card('Queda', num(m.queda, 0), 'do pico sust. ao fim'),
      card('Anos 85+ / 90+', `${m.anos_85} / ${m.anos_90}`),
      card('Jogos', num(m.jogos, 0), m.temporadas_com_stats + ' temporada(s) com stats'),
      card('PTS · REB · AST', `${num(m.pts_pg)} · ${num(m.reb_pg)} · ${num(m.ast_pg)}`, 'médias da carreira'),
      card('STL · BLK · FG%', `${num(m.stl_pg)} · ${num(m.blk_pg)} · ${num(m.fg)}`, 'médias da carreira'),
      card('Melhor PTS / FG%', `${num(m.pico_pts_pg)} / ${num(m.pico_fg)}`, '20+ jogos'),
      card('Prêmios', m.premios_total || 0, Object.entries(m.premios || {}).map(([t, q]) => `${q}× ${t}`).join(' · ')),
    ].join('') : '<div class="nada">Sem histórico de temporadas registrado pra este jogador.</div>';

    const curva = Object.entries(v.curva || {}).map(([i, o]) => [Number(i), o]);
    const graf = curva.length > 1 ? `<div class="sec">OVR por idade</div><div class="bloco">${grafico([{ pontos: curva, cor: '#fc0025', largura: 2.4, bolas: true, raio: 3.5 }], { titulo: 'OVR por idade' })}</div>` : '';

    const SKILLS = ['IN', 'MID', '3PT', 'POST D', 'PER D', 'PLAY', 'REB', 'ATHL', 'IQ', 'POT'];
    const linhas = (v.temporadas || []).map(t => {
      const s = t.stats;
      return `<tr><td>T${t.temporada}</td><td>${t.ano}</td><td>${esc(t.liga)}</td><td style="text-align:left">${esc(t.time)}</td><td>${t.idade}</td><td>${esc(t.pos)}</td>
        <td class="n ${cOvr(t.ovr)}">${t.ovr}</td>
        ${SKILLS.map(k => `<td class="n">${esc(t.letras[k] || '·')}</td>`).join('')}
        <td class="n">${s ? s.jogos : '—'}</td><td class="n">${s ? num(s.min) : '—'}</td><td class="n">${s ? num(s.pts) : '—'}</td><td class="n">${s ? num(s.reb) : '—'}</td>
        <td class="n">${s ? num(s.ast) : '—'}</td><td class="n">${s ? num(s.stl) : '—'}</td><td class="n">${s ? num(s.blk) : '—'}</td><td class="n">${s ? num(s.fg) : '—'}</td>
        <td style="text-align:left">${esc((t.premios || []).join(', '))}</td></tr>`;
    }).join('');
    const tabela = linhas ? `<div class="sec">Temporada a temporada</div><div class="tabela" style="max-height:none"><table>
      <thead><tr><th>Temp.</th><th>Ano</th><th>Liga</th><th>Time</th><th>Idade</th><th>Pos</th><th>OVR</th>${SKILLS.map(k => `<th>${k}</th>`).join('')}
      <th>J</th><th>MIN</th><th>PTS</th><th>REB</th><th>AST</th><th>STL</th><th>BLK</th><th>FG%</th><th>Prêmios</th></tr></thead><tbody>${linhas}</tbody></table></div>` : '';

    const td = v.tendencias;
    const tend = td && Object.keys(td.dados || {}).length
      ? `<div class="sec">Tendências (jogo) <small style="font-weight:500;text-transform:none;letter-spacing:0;color:var(--text-3)">· atualizadas em ${esc(String(td.em).slice(0, 10))}</small></div>
         <div class="tend">${Object.entries(td.dados).map(([k, x]) => `<div>${esc(k)}<b>${esc(x)}</b></div>`).join('')}</div>` : '';

    const outras = (v.outras_vidas || []).length
      ? `<div class="sec">Outras vidas de ${esc(v.nome)}</div><div class="chips">${v.outras_vidas.map(o =>
          `<button class="chip" data-vida="${o.id}">${esc(o.liga)} · ${o.n} temp. <small>pico ${o.pico}</small></button>`).join('')}
         <button class="chip" data-estudo="${esc(v.nome)}"><i class="bi bi-people"></i> Estudo por nome (${v.outras_vidas.length + 1} vidas)</button></div>` : '';

    return `<div class="dlg-cab"><div><h2>${esc(v.nome)}${h && h.lenda ? '<span class="tag lenda">LENDA</span>' : ''}</h2><p>${esc(sub)}</p></div>
      <button class="fechar" aria-label="Fechar"><i class="bi bi-x-lg"></i></button></div>
      <div class="dlg-corpo"><div class="cards">${cards}</div>${graf}${tend}${outras}${tabela}</div>`;
  }

  /* ── Estudo por nome ────────────────────────────────────────────────── */
  let nomesCarregados = false;
  async function prepararNomes() {
    if (nomesCarregados) return;
    try {
      const d = await get({ acao: 'nomes' });
      $('listaNomes').innerHTML = d.nomes.map(n => `<option value="${esc(n.nome)}">${n.vidas} vidas</option>`).join('');
      $('nomeChips').innerHTML = d.nomes.slice(0, 30).map(n => `<button class="chip" data-nome="${esc(n.nome)}">${esc(n.nome)}<small>${n.vidas} vidas</small></button>`).join('');
      nomesCarregados = true;
    } catch (e) { $('nomeChips').innerHTML = `<span class="explica">Não carregou a lista de nomes: ${esc(e.message)}</span>`; }
  }
  $('nomeChips').addEventListener('click', e => {
    const b = e.target.closest('[data-nome]');
    if (b) { $('nomeBusca').value = b.dataset.nome; estudarNome(b.dataset.nome); }
  });
  $('nomeIr').addEventListener('click', () => estudarNome($('nomeBusca').value));
  $('nomeBusca').addEventListener('keydown', e => { if (e.key === 'Enter') estudarNome($('nomeBusca').value); });
  $('nomeMin').addEventListener('change', () => { if ($('nomeBusca').value.trim()) estudarNome($('nomeBusca').value); });

  async function estudarNome(nome) {
    nome = String(nome || '').trim();
    if (!nome) return;
    $('nomeResultado').innerHTML = '<div class="carga">Calculando…</div>';
    history.replaceState(null, '', '#nome=' + encodeURIComponent(nome));
    let d;
    try { d = await get({ acao: 'nome', nome, min: $('nomeMin').value }); }
    catch (e) { $('nomeResultado').innerHTML = `<div class="nada">${esc(e.message)}</div>`; return; }
    $('nomeResultado').innerHTML = htmlEstudo(d);
    $('nomeResultado').querySelectorAll('tr[data-vida]').forEach(tr => tr.addEventListener('click', () => abrirVida(Number(tr.dataset.vida))));
  }

  const linhaResumo = (rot, r, d = 1, dica) => {
    r = r || {};
    return `<tr><td title="${esc(dica || '')}">${esc(rot)}</td><td class="n">${num(r.media, d)}</td><td class="n">${num(r.mediana, d)}</td><td class="n">${num(r.min, d)}</td><td class="n">${num(r.max, d)}</td><td class="n fraco">${r.n ?? 0}</td></tr>`;
  };
  const cabResumo = '<thead><tr><th style="text-align:left">Métrica</th><th>Média</th><th>Mediana</th><th>Mín</th><th>Máx</th><th>Vidas</th></tr></thead>';

  function htmlEstudo(d) {
    const R = d.resumo;
    const usadas = d.vidas.filter(v => v.usada);
    const cards = [
      card('Vidas', d.vidas_total, `${d.vidas_usadas} entram nas médias (${d.min_temporadas}+ temp.)`),
      card('Pico sustentado', num(R.pico_sustentado.media), `mediana ${num(R.pico_sustentado.mediana)}`),
      card('Idade do pico', num(R.idade_do_pico.media), `de ${num(R.idade_do_pico.min, 0)} a ${num(R.idade_do_pico.max, 0)} anos`),
      card('Carreira', num(R.temporadas.media) + ' temp.', `de ${num(R.temporadas.min, 0)} a ${num(R.temporadas.max, 0)}`),
      card('OVR no fim', num(R.ovr_no_fim.media), `aos ${num(R.idade_no_fim.media)} anos`),
      card('Queda do pico ao fim', num(R.queda_pico_ao_fim.media), 'em OVR'),
      card('FG%', num(R.fg.media), `${R.fg.n} vida(s) com dado`),
    ].join('');

    const letras = `<div class="letras">${Object.entries(d.letras_pico).map(([k, x]) => {
      const c = x.letra ? x.letra[0].toLowerCase() : '';
      return `<div class="letra ${c}" title="média numérica ${x.media ?? '—'} (A+ = 12, F = 0)">${esc(k)}<b>${esc(x.letra || '—')}</b></div>`;
    }).join('')}</div>`;

    // As curvas: cada vida fina (as apagadas ainda mais), e a média grossa por cima.
    const series = d.vidas.map((v, i) => ({
      pontos: Object.entries(v.curva).map(([a, o]) => [Number(a), o]),
      cor: CORES[i % CORES.length], largura: 1.4, opacidade: v.usada ? 0.7 : 0.22,
      rotulo: `vida ${v.id} (${v.liga}${v.usada ? '' : ', fora das médias'})`,
    })).filter(s => s.pontos.length);
    if (d.curva_media.length) series.push({
      pontos: d.curva_media.map(p => [p.idade, p.ovr]), cor: '#fc0025', largura: 3.2, bolas: true,
      // Quanto mais vidas entram no ponto, maior a bolinha: a média de uma vida só não é média.
      raios: d.curva_media.map(p => 2 + Math.min(p.vidas, 5) * 0.8),
      rotulo: 'MÉDIA das vidas usadas (bolinha maior = mais vidas no ponto)', dicas: d.curva_media.map(p => `${p.idade} anos: OVR médio ${p.ovr} (${p.vidas} vida(s))`),
    });

    const variacao = d.variacao_anual.length
      ? `<table><thead><tr><th style="text-align:left">Idade</th><th>Variação média do OVR</th><th>Vidas</th></tr></thead><tbody>${d.variacao_anual.map(x =>
          `<tr><td>${x.de} → ${x.para}</td><td class="n ${x.media > 0 ? 'alto' : x.media < 0 ? 'fraco' : ''}">${x.media > 0 ? '+' : ''}${num(x.media, 2)}</td><td class="n fraco">${x.vidas}</td></tr>`).join('')}</tbody></table>`
      : '<div class="nada">Poucas vidas pra medir a variação.</div>';

    const vidas = d.vidas.slice().sort((a, b) => b.m.pico_sust - a.m.pico_sust).map(v => {
      const m = v.m;
      return `<tr class="${v.usada ? '' : 'fora-media'}" data-vida="${v.id}"><td>#${v.id}</td><td>${esc(v.liga)}</td><td class="n">${m.n}</td>
        <td class="n ${cOvr(m.pico)}">${m.pico}${m.fluke ? '<span class="flag" title="pico de uma temporada só">⚑</span>' : ''}</td><td class="n ${cOvr(m.pico_sust)}">${m.pico_sust}</td>
        <td class="n">${m.idade_pico_sust}</td><td class="n">${m.ovr_fim}</td><td class="n">${m.queda}</td><td class="n">${num(m.pts_pg)}</td><td class="n">${num(m.fg)}</td>
        <td>${v.usada ? '' : '<span class="tag fora">fora das médias</span>'}</td></tr>`;
    }).join('');

    return `<h2 style="font-size:20px;font-weight:800;margin:6px 0 2px">${esc(d.nome)}</h2>
      <div class="cards">${cards}</div>

      <div class="sec">Carreira (média entre as ${d.vidas_usadas} vidas usadas)</div>
      <div class="bloco"><table>${cabResumo}<tbody>
        ${linhaResumo('Pico (OVR máximo)', R.pico, 1, 'o maior OVR, mesmo que de uma temporada só')}
        ${linhaResumo('Pico sustentado', R.pico_sustentado, 1, 'o maior OVR segurado por duas temporadas seguidas')}
        ${linhaResumo('Idade do pico sustentado', R.idade_do_pico)}
        ${linhaResumo('Temporadas na carreira', R.temporadas)}
        ${linhaResumo('Anos com OVR 85+', R.anos_85_ou_mais)}
        ${linhaResumo('Anos com OVR 90+', R.anos_90_ou_mais)}
        ${linhaResumo('OVR no início', R.ovr_inicial)}
        ${linhaResumo('Idade no início', R.idade_inicial)}
        ${linhaResumo('OVR no fim', R.ovr_no_fim)}
        ${linhaResumo('Idade no fim', R.idade_no_fim)}
        ${linhaResumo('Queda do pico sust. ao fim', R.queda_pico_ao_fim)}
      </tbody></table></div>

      <div class="sec">Estatísticas por jogo</div>
      <div class="bloco"><table>${cabResumo}<tbody>
        ${linhaResumo('Minutos (média da carreira)', R.minutos)}
        ${linhaResumo('PTS (média da carreira)', R.pts)}
        ${linhaResumo('REB (média da carreira)', R.reb)}
        ${linhaResumo('AST (média da carreira)', R.ast)}
        ${linhaResumo('STL (média da carreira)', R.stl)}
        ${linhaResumo('BLK (média da carreira)', R.blk)}
        ${linhaResumo('FG% (média da carreira)', R.fg)}
        ${linhaResumo('PTS — melhor temporada', R.pico_pts, 1, 'só temporadas com 20+ jogos')}
        ${linhaResumo('REB — melhor temporada', R.pico_reb)}
        ${linhaResumo('AST — melhor temporada', R.pico_ast)}
        ${linhaResumo('STL — melhor temporada', R.pico_stl)}
        ${linhaResumo('BLK — melhor temporada', R.pico_blk)}
        ${linhaResumo('FG% — melhor temporada', R.pico_fg)}
      </tbody></table>
      <p class="explica" style="margin:8px 0 0">A coluna <b>Vidas</b> diz com quantas vidas cada média foi calculada — só parte das temporadas tem estatística, e o FG% some nas primeiras.</p></div>

      <div class="sec">Letras na temporada do pico sustentado (média das vidas)</div>
      <div class="bloco">${letras}</div>

      <div class="sec">OVR por idade</div>
      <div class="bloco">${grafico(series, { titulo: 'OVR por idade' })}</div>

      <div class="sec">Quanto o OVR muda de um ano pro outro</div>
      <div class="bloco">${variacao}</div>

      <div class="sec">As vidas (clique pra abrir a carreira)</div>
      <div class="bloco"><table><thead><tr><th style="text-align:left">Vida</th><th>Liga</th><th>Temp.</th><th>Pico</th><th>Pico sust.</th><th>Idade pico</th><th>Fim</th><th>Queda</th><th>PTS</th><th>FG%</th><th></th></tr></thead>
        <tbody>${vidas}</tbody></table></div>`;
  }

  /* ── Começo: lê o que veio no endereço (#elite, #outras, #historico, #nome=...) ── */
  function rotear() {
    const hash = decodeURIComponent(location.hash.replace(/^#/, ''));
    if (hash.startsWith('nome')) trocarAba('nome', hash.includes('=') ? hash.split('=').slice(1).join('=') : undefined);
    else trocarAba(['elite', 'outras', 'historico'].includes(hash) ? hash : 'elite');
  }
  // Colar um link com outro #aba numa página já aberta não recarrega — então escuta.
  // (As trocas feitas pela própria tela usam replaceState, que não dispara isto.)
  window.addEventListener('hashchange', rotear);
  rotear();
})();
