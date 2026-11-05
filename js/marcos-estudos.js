/* ESTUDOS DE JOGADORES — a tela (marcos-estudos.php).
   Os dados e as contas vêm de api/marcos-estudos.php (backend/estudos_jogadores.php); favoritos,
   correções e lixeira são a camada do Marcos (backend/estudos_ajustes.php) e nunca tocam o histórico do jogo. */
(function () {
  'use strict';

  const API = '/api/marcos-estudos.php';
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const semAcento = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  // O navegador pode negar o localStorage (janela privada): a página tem que funcionar sem ele.
  const ler = (k, def) => { try { const x = localStorage.getItem('estudos.' + k); return x === null ? def : JSON.parse(x); } catch (e) { return def; } };
  const guardar = (k, v) => { try { localStorage.setItem('estudos.' + k, JSON.stringify(v)); } catch (e) { /* sem storage: tudo bem */ } };

  /* ── Configuração que fica entre uma visita e outra ─────────────────── */
  const PEAK_PADRAO = { plato: 1, queda: 1, atrib: 0, ponto: 'queda' };
  const cfg = {
    peak: Object.assign({}, PEAK_PADRAO, ler('peak', {})),
    lendas: ler('lendas', 'ocultar'),
    cols: ler('cols', null),       // null = todas
    denso: ler('denso', false),
    modo: 'todas',                 // todas | qualquer (as regras do construtor)
    regras: [],
  };

  /* ── Rede ───────────────────────────────────────────────────────────── */
  async function resposta(r) {
    const d = await r.json().catch(() => ({ ok: false, erro: 'Resposta inválida do servidor.' }));
    if (!d.ok) throw new Error(d.erro || 'Falhou.');
    return d;
  }
  const get = params => fetch(API + '?' + new URLSearchParams({ ...params, ...cfg.peak })).then(resposta);
  const enviar = (acao, corpo) => fetch(API + '?acao=' + acao, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo),
  }).then(resposta);

  /* ── Formatação ─────────────────────────────────────────────────────── */
  const num = (x, d = 1) => (x === null || x === undefined || Number.isNaN(x)) ? '—' : Number(x).toFixed(d).replace(/\.0+$/, '');
  const cOvr = x => x === null || x === undefined ? 'fraco' : x >= 90 ? 'top' : x >= 85 ? 'alto' : x < 70 ? 'fraco' : '';
  const NOTA_NUM = { 'F': 0, 'D-': 1, 'D': 2, 'D+': 3, 'C-': 4, 'C': 5, 'C+': 6, 'B-': 7, 'B': 8, 'B+': 9, 'A-': 10, 'A': 11, 'A+': 12 };
  const NUM_NOTA = Object.fromEntries(Object.entries(NOTA_NUM).map(([k, v]) => [v, k]));
  const LETRAS = ['', 'A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'F'];
  const SKILLS = ['IN', 'MID', '3PT', 'POST D', 'PER D', 'PLAY', 'REB', 'ATHL', 'IQ', 'POT'];
  const LIGAS = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
  const POSICOES = ['PG', 'SG', 'SF', 'PF', 'C'];

  /* ── As colunas da tabela ───────────────────────────────────────────── */
  const M = l => l.m || {};
  const COLS = [
    { k: 'pos',   g: 'Situação', rot: 'Pos',        get: l => l.pos, txt: true },
    { k: 'idade', g: 'Situação', rot: 'Idade',      get: l => l.idade },
    { k: 'ovr',   g: 'Situação', rot: 'OVR',        get: l => l.ovr, cls: cOvr },
    { k: 'ent',   g: 'Situação', rot: 'Entrada',    get: l => M(l).idade_ini, dica: 'Idade na 1ª temporada registrada (rookie, pra quem chegou como calouro)' },
    { k: 'pot',   g: 'Situação', rot: 'POT ini',    get: l => M(l).pot_ini ? NOTA_NUM[M(l).pot_ini] : null, show: v => NUM_NOTA[v] ?? '—', letra: true, dica: 'POT (letra) na entrada' },
    { k: 'nt',    g: 'Situação', rot: 'Temp.',      get: l => l.festiva ? null : M(l).n },
    { k: 'vidas', g: 'Situação', rot: 'Vidas',      get: l => l.vidas },
    { k: 'oini',  g: 'Pico e fim', rot: 'OVR ini',  get: l => M(l).ovr_ini },
    { k: 'pico',  g: 'Pico e fim', rot: 'Pico',     get: l => M(l).pico, cls: cOvr, flag: true },
    { k: 'pks',   g: 'Pico e fim', rot: 'Pico sust.', get: l => M(l).pico_sust, cls: cOvr },
    { k: 'ipk',   g: 'Pico e fim', rot: 'Idade pico', get: l => M(l).idade_pico_sust },
    { k: 'a85',   g: 'Pico e fim', rot: '85+',      get: l => M(l).anos_85, dica: 'Temporadas com OVR 85 ou mais' },
    { k: 'a90',   g: 'Pico e fim', rot: '90+',      get: l => M(l).anos_90, dica: 'Temporadas com OVR 90 ou mais' },
    { k: 'fim',   g: 'Pico e fim', rot: 'Fim',      get: l => M(l).ovr_fim, cls: cOvr, dica: 'OVR da última temporada' },
    { k: 'qd',    g: 'Pico e fim', rot: 'Queda',    get: l => M(l).queda, dica: 'Pico sustentado menos o OVR do fim' },
    { k: 'ps',    g: 'Peak', rot: 'Peak start', get: l => M(l).peak_start, dica: 'Idade em que chega ao patamar de pico' },
    { k: 'pe',    g: 'Peak', rot: 'Peak end',   get: l => M(l).peak_end, dica: 'Idade em que começou a cair (configurável em Peak). Vazio = ainda não caiu' },
    { k: 'apk',   g: 'Peak', rot: 'Anos pico',  get: l => M(l).anos_pico, dica: 'Peak end − peak start' },
    { k: 'aap',   g: 'Peak', rot: 'Anos até pico', get: l => M(l).anos_ate_pico, dica: 'Da entrada ao peak start' },
    { k: 'jogos', g: 'Estatísticas', rot: 'Jogos',  get: l => M(l).jogos },
    { k: 'min',   g: 'Estatísticas', rot: 'MIN',    get: l => M(l).min, d: 1 },
    { k: 'pts',   g: 'Estatísticas', rot: 'PTS',    get: l => M(l).pts_pg, d: 1 },
    { k: 'reb',   g: 'Estatísticas', rot: 'REB',    get: l => M(l).reb_pg, d: 1 },
    { k: 'ast',   g: 'Estatísticas', rot: 'AST',    get: l => M(l).ast_pg, d: 1 },
    { k: 'stl',   g: 'Estatísticas', rot: 'STL',    get: l => M(l).stl_pg, d: 1 },
    { k: 'blk',   g: 'Estatísticas', rot: 'BLK',    get: l => M(l).blk_pg, d: 1 },
    { k: 'fg',    g: 'Estatísticas', rot: 'FG%',    get: l => M(l).fg, d: 1 },
    { k: 'ppts',  g: 'Estatísticas', rot: 'Pico PTS', get: l => M(l).pico_pts_pg, d: 1 },
    { k: 'preb',  g: 'Estatísticas', rot: 'Pico REB', get: l => M(l).pico_reb_pg, d: 1 },
    { k: 'past',  g: 'Estatísticas', rot: 'Pico AST', get: l => M(l).pico_ast_pg, d: 1 },
    { k: 'pstl',  g: 'Estatísticas', rot: 'Pico STL', get: l => M(l).pico_stl_pg, d: 1 },
    { k: 'pblk',  g: 'Estatísticas', rot: 'Pico BLK', get: l => M(l).pico_blk_pg, d: 1 },
    { k: 'pfg',   g: 'Estatísticas', rot: 'Pico FG%', get: l => M(l).pico_fg, d: 1 },
    { k: 'pr',    g: 'Estatísticas', rot: 'Prêm.',  get: l => M(l).premios_total },
    { k: 'tshot', g: 'Tendências', rot: 'T/SHOT',   get: l => l.tshot, dica: 'Tendência de arremessar (0 a 99). Só a ELITE de 75 OVR pra cima, ou o que você digitou' },
    { k: 'ttouch', g: 'Tendências', rot: 'T/TOUCH', get: l => l.ttouch, dica: 'Tendência de ficar com a bola (0 a 99)' },
  ];
  const COL = Object.fromEntries(COLS.map(c => [c.k, c]));
  const PRESETS_COLS = {
    cTodas: null,
    cEssencial: ['pos', 'idade', 'ovr', 'pico', 'pks', 'ps', 'pe', 'nt'],
    cPeak: ['pos', 'idade', 'ovr', 'pks', 'ipk', 'ent', 'pot', 'ps', 'pe', 'apk', 'aap', 'nt', 'tshot', 'ttouch'],
    cStats: ['pos', 'idade', 'ovr', 'pico', 'jogos', 'min', 'pts', 'reb', 'ast', 'stl', 'blk', 'fg', 'ppts', 'preb', 'past', 'pfg', 'pr'],
  };
  const colunasVisiveis = () => COLS.filter(c => !cfg.cols || cfg.cols.includes(c.k));

  /* ── Os campos do construtor de filtros ─────────────────────────────── */
  const sim = f => l => !!f(l);
  const CAMPOS = [
    { k: 'nome', rot: 'Nome', tipo: 'txt', g: 'Jogador', get: l => l.nome },
    { k: 'time', rot: 'Time', tipo: 'txt', g: 'Jogador', get: l => l.time },
    { k: 'nota', rot: 'Sua nota (texto)', tipo: 'txt', g: 'Jogador', get: l => l.nota },
    { k: 'liga', rot: 'Liga', tipo: 'opc', g: 'Jogador', opcoes: LIGAS, get: l => l.liga },
    { k: 'pos', rot: 'Posição', tipo: 'opc', g: 'Jogador', opcoes: POSICOES, get: l => l.pos },
    ...COLS.filter(c => !c.txt).map(c => ({ k: c.k, rot: c.rot + (c.k === 'pe' ? ' (idade)' : ''), tipo: c.letra ? 'letra' : 'num', g: c.g, get: c.get })),
    { k: 'b_ativo', rot: 'Está num elenco hoje', tipo: 'bool', g: 'Situação', get: sim(l => l.ativo) },
    { k: 'b_lenda', rot: 'É lenda', tipo: 'bool', g: 'Situação', get: sim(l => l.lenda) },
    { k: 'b_fav', rot: 'É favorito', tipo: 'bool', g: 'Situação', get: sim(l => l.fav) },
    { k: 'b_festiva', rot: 'É 99 festivo', tipo: 'bool', g: 'Situação', get: sim(l => l.festiva) },
    { k: 'b_aj', rot: 'Tem correção sua', tipo: 'bool', g: 'Situação', get: sim(l => l.ajustada) },
    { k: 'b_man', rot: 'Foi criado por você', tipo: 'bool', g: 'Situação', get: sim(l => l.manual) },
    { k: 'b_nota', rot: 'Tem nota sua', tipo: 'bool', g: 'Situação', get: sim(l => l.nota) },
    { k: 'b_fluke', rot: 'Pico de uma temporada só (⚑)', tipo: 'bool', g: 'Pico e fim', get: sim(l => M(l).fluke) },
    { k: 'b_nopico', rot: 'Ainda não começou a cair', tipo: 'bool', g: 'Peak', get: sim(l => M(l).ainda_no_pico) },
    { k: 'b_stats', rot: 'Tem estatística', tipo: 'bool', g: 'Estatísticas', get: sim(l => M(l).jogos > 0) },
    { k: 'b_tend', rot: 'Tem tendência lançada', tipo: 'bool', g: 'Tendências', get: sim(l => l.tshot !== null || l.ttouch !== null) },
  ];
  const CAMPO = Object.fromEntries(CAMPOS.map(c => [c.k, c]));
  const OPS = {
    num: [['eq', '='], ['ne', '≠'], ['gt', '>'], ['ge', '≥'], ['lt', '<'], ['le', '≤'], ['btw', 'entre'], ['vz', 'está vazio'], ['pr', 'tem valor']],
    txt: [['ct', 'contém'], ['nc', 'não contém'], ['ini', 'começa com'], ['eq', 'é exatamente'], ['vz', 'está vazio'], ['pr', 'tem valor']],
    opc: [['em', 'é um de'], ['fora', 'não é nenhum de']],
    bool: [['sim', 'sim'], ['nao', 'não']],
  };
  OPS.letra = OPS.num;
  const opsDo = c => OPS[c.tipo];
  const novaRegra = (c = 'ovr') => ({ c, op: opsDo(CAMPO[c])[0][0], a: '', b: '', vs: [] });

  /** Uma regra casa com o jogador? Regra pela metade (sem valor) não filtra nada. */
  function casa(l, r) {
    const c = CAMPO[r.c];
    if (!c) return true;
    const v = c.get(l);
    switch (c.tipo) {
      case 'bool': return r.op === 'sim' ? !!v : !v;
      case 'opc': {
        if (!r.vs.length) return true;
        return r.op === 'em' ? r.vs.includes(v) : !r.vs.includes(v);
      }
      case 'txt': {
        if (r.op === 'vz') return v === null || v === undefined || v === '';
        if (r.op === 'pr') return !(v === null || v === undefined || v === '');
        const q = semAcento(r.a.trim());
        if (!q) return true;
        const t = semAcento(v);
        return r.op === 'ct' ? t.includes(q) : r.op === 'nc' ? !t.includes(q) : r.op === 'ini' ? t.startsWith(q) : t === q;
      }
      default: {   // num e letra
        if (r.op === 'vz') return v === null || v === undefined;
        if (r.op === 'pr') return v !== null && v !== undefined;
        const conv = x => c.tipo === 'letra' ? (NOTA_NUM[String(x).trim().toUpperCase()] ?? NaN) : parseFloat(String(x).replace(',', '.'));
        const a = conv(r.a);
        if (Number.isNaN(a)) return true;
        if (v === null || v === undefined) return r.op === 'ne';
        if (r.op === 'btw') { const b = conv(r.b); return Number.isNaN(b) ? v >= a : (v >= Math.min(a, b) && v <= Math.max(a, b)); }
        return { eq: v === a, ne: v !== a, gt: v > a, ge: v >= a, lt: v < a, le: v <= a }[r.op];
      }
    }
  }
  const regraAtiva = r => {
    const c = CAMPO[r.c];
    if (!c) return false;
    if (c.tipo === 'bool' || r.op === 'vz' || r.op === 'pr') return true;
    if (c.tipo === 'opc') return r.vs.length > 0;
    return String(r.a).trim() !== '';
  };

  /* ── Estado ─────────────────────────────────────────────────────────── */
  const LISTAS = ['elite', 'outras', 'historico', 'favoritos', 'lixeira'];
  const dados = { elite: null, outras: null, historico: null, favoritos: null, lixeira: null };
  const est = { aba: 'elite', ord: [['ovr', false]], pag: 0 };
  let visiveis = [];
  const NOMES_ABA = { elite: 'ELITE', outras: 'Outras ligas', historico: 'Histórico', favoritos: 'Favoritos', lixeira: 'Lixeira' };
  const ROTULO_N = { elite: 'nElite', outras: 'nOutras', historico: 'nHist', favoritos: 'nFav', lixeira: 'nLix' };
  const porPagina = () => { const n = parseInt($('fPorPag').value, 10); return n > 0 ? n : Infinity; };
  const ehListaDeSelecao = () => est.aba === 'favoritos' || est.aba === 'lixeira';

  function contar(aba) {
    if (dados[aba]) $(ROTULO_N[aba]).textContent = dados[aba].length.toLocaleString('pt-BR');
  }

  /* ── Abas ───────────────────────────────────────────────────────────── */
  document.querySelectorAll('.aba').forEach(b => b.addEventListener('click', () => trocarAba(b.dataset.aba)));

  async function trocarAba(aba, nomeParaEstudar) {
    est.aba = aba; est.pag = 0;
    document.querySelectorAll('.aba').forEach(b => b.classList.toggle('on', b.dataset.aba === aba));
    $('painelLista').hidden = aba === 'nome';
    $('painelNome').hidden = aba !== 'nome';
    // Na lista a página inteira fica larga (a tabela tem dezenas de colunas) e é ELA que rola,
    // pro cabeçalho da tabela poder ficar fixo no topo da janela.
    document.querySelector('.wrap').classList.toggle('larga', aba !== 'nome');
    if (aba === 'nome') {
      history.replaceState(null, '', '#nome' + (nomeParaEstudar ? '=' + encodeURIComponent(nomeParaEstudar) : ''));
      await prepararNomes();
      if (nomeParaEstudar) { $('nomeBusca').value = nomeParaEstudar; estudarNome(nomeParaEstudar); }
      return;
    }
    history.replaceState(null, '', '#' + aba);
    $('lAtivos').hidden = aba !== 'historico';
    if (aba !== 'historico') $('fAtivos').checked = false;
    $('fLendas').hidden = ehListaDeSelecao();
    await carregarLista(aba);
  }

  async function carregarLista(aba, forcar) {
    if (forcar) dados[aba] = null;
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
      contar(aba);
    }
    if (aba === est.aba) renderTabela();
  }

  /** Favoritos e lixeira são pequenos: ficam sempre em dia, só pra o número da aba ser verdadeiro. */
  async function atualizarContagens() {
    for (const aba of ['favoritos', 'lixeira']) {
      try { dados[aba] = (await get({ acao: 'lista', grupo: aba })).linhas; contar(aba); } catch (e) { /* o número fica sem atualizar */ }
    }
    if (ehListaDeSelecao()) renderTabela();
  }

  /** Depois de gravar algo que muda as métricas ou a lista: recarrega o que estava na tela. */
  function recarregarTudo() {
    for (const k of ['elite', 'outras', 'historico']) dados[k] = null;
    if (LISTAS.includes(est.aba) && !ehListaDeSelecao()) carregarLista(est.aba);
    atualizarContagens();
  }

  /* ── Barra de controle ──────────────────────────────────────────────── */
  $('fLendas').value = cfg.lendas;
  $('fLendas').addEventListener('input', () => { cfg.lendas = $('fLendas').value; guardar('lendas', cfg.lendas); est.pag = 0; renderTabela(); });
  ['fBusca', 'fCurtas', 'fStats', 'fAtivos', 'fAjust', 'fPorPag'].forEach(id =>
    $(id).addEventListener('input', () => { est.pag = 0; renderTabela(); }));
  $('bLimpar').addEventListener('click', () => {
    $('fBusca').value = '';
    ['fCurtas', 'fStats', 'fAtivos', 'fAjust'].forEach(id => { $(id).checked = false; });
    cfg.regras = []; renderRegras();
    est.pag = 0; renderTabela();
  });
  $('bDenso').addEventListener('click', () => { cfg.denso = !cfg.denso; guardar('denso', cfg.denso); aplicarDenso(); });
  function aplicarDenso() { document.body.classList.toggle('denso', !!cfg.denso); $('bDenso').classList.toggle('on', !!cfg.denso); }
  aplicarDenso();

  function alternarPainel(id, botao) {
    const abrir = $(id).hidden;
    ['pFiltros', 'pColunas', 'pPeak'].forEach(p => { $(p).hidden = true; });
    ['bFiltros', 'bColunas', 'bPeak'].forEach(b => $(b).classList.remove('on'));
    $(id).hidden = !abrir;
    if (botao) $(botao).classList.toggle('on', abrir);
  }
  $('bFiltros').addEventListener('click', () => alternarPainel('pFiltros', 'bFiltros'));
  $('bColunas').addEventListener('click', () => { alternarPainel('pColunas', 'bColunas'); renderColunas(); });
  $('bPeak').addEventListener('click', () => { alternarPainel('pPeak', 'bPeak'); preencherPeak(); });
  $('nomePeak').addEventListener('click', () => { alternarPainel('pPeak'); preencherPeak(); });
  $('pResumo').addEventListener('toggle', () => { if ($('pResumo').open) renderResumo(); });

  function filtrar(linhas) {
    const q = semAcento($('fBusca').value.trim());
    const curtas = $('fCurtas').checked, stats = $('fStats').checked, ativos = $('fAtivos').checked, ajust = $('fAjust').checked;
    const regras = cfg.regras.filter(regraAtiva);
    const todas = cfg.modo === 'todas';
    const aplicaLendas = !ehListaDeSelecao();
    return linhas.filter(l => {
      if (q && !(semAcento(l.nome).includes(q) || semAcento(l.time).includes(q) || semAcento(l.nota).includes(q))) return false;
      if (aplicaLendas) {
        if (cfg.lendas === 'ocultar' && l.lenda) return false;
        if (cfg.lendas === 'so' && !l.lenda) return false;
      }
      if (curtas && (M(l).n === 1 || l.festiva)) return false;
      if (stats && !(M(l).jogos > 0)) return false;
      if (ativos && !l.ativo) return false;
      if (ajust && !l.ajustada) return false;
      if (regras.length) {
        if (todas ? !regras.every(r => casa(l, r)) : !regras.some(r => casa(l, r))) return false;
      }
      return true;
    });
  }

  function ordenar(linhas) {
    const chaves = est.ord.map(([k, asc]) => {
      const col = COL[k];
      return { dir: asc ? 1 : -1, nome: k === 'nome', txt: k === 'nome' || (col && col.txt), get: k === 'nome' ? (l => l.nome) : col.get };
    });
    return linhas.slice().sort((a, b) => {
      for (const c of chaves) {
        const va = c.get(a), vb = c.get(b);
        const vaVazio = va === null || va === undefined, vbVazio = vb === null || vb === undefined;
        // Vazio vai sempre pro fim, qualquer que seja a direção.
        if (vaVazio && vbVazio) continue;
        if (vaVazio) return 1;
        if (vbVazio) return -1;
        const r = c.txt ? String(va).localeCompare(String(vb), 'pt') : va - vb;
        if (r) return c.dir * r;
      }
      return 0;
    });
  }

  /* ── Tabela ─────────────────────────────────────────────────────────── */
  function filtrando() {
    return !!($('fBusca').value || $('fCurtas').checked || $('fStats').checked || $('fAtivos').checked || $('fAjust').checked || cfg.regras.some(regraAtiva));
  }

  function renderTabela() {
    if (est.aba === 'nome') return;
    const todas = dados[est.aba] || [];
    visiveis = ordenar(filtrar(todas));
    const total = visiveis.length;
    const POR_PAGINA = porPagina();
    const paginas = Math.max(1, Math.ceil(total / POR_PAGINA));
    est.pag = Math.min(est.pag, paginas - 1);
    const fatia = visiveis.slice(est.pag * POR_PAGINA, (est.pag + 1) * POR_PAGINA);
    const cols = colunasVisiveis();

    const ocultas = (!ehListaDeSelecao() && cfg.lendas === 'ocultar') ? todas.filter(l => l.lenda).length : 0;
    $('bLimpar').hidden = !filtrando();
    $('conta').innerHTML = `<b>${total.toLocaleString('pt-BR')}</b>${total !== todas.length ? ' de ' + todas.length.toLocaleString('pt-BR') : ''} jogadores`
      + (ocultas ? ` · <span title="Mude em 'Lendas' na barra">${ocultas} lenda(s) oculta(s)</span>` : '');
    const nr = cfg.regras.filter(regraAtiva).length;
    $('nRegras').hidden = !nr; $('nRegras').textContent = nr;

    const posOrd = k => est.ord.findIndex(([x]) => x === k);
    const th = (k, rot, dica, extra = '') => {
      const i = posOrd(k);
      return `<th class="${extra}${i >= 0 ? ' ord' + (est.ord[i][1] ? ' asc' : '') : ''}" data-k="${k}" title="${esc(dica || rot)}">${esc(rot)}${i >= 0 && est.ord.length > 1 ? `<span class="seq">${i + 1}</span>` : ''}</th>`;
    };
    $('cab').innerHTML = '<tr>' + th('nome', 'Jogador', 'Ordenar por nome', 'col-jog') + cols.map(c => th(c.k, c.rot, c.dica)).join('') + '</tr>';

    if (!fatia.length) {
      $('corpo').innerHTML = `<tr><td class="nada" colspan="${cols.length + 1}">${
        est.aba === 'favoritos' ? 'Nenhum favorito ainda. Clique na ☆ ao lado do nome de um jogador.'
          : est.aba === 'lixeira' ? 'A lixeira está vazia.' : 'Nenhum jogador com esses filtros.'}</td></tr>`;
    } else {
      $('corpo').innerHTML = fatia.map(l => {
        const m = M(l);
        const celulas = cols.map(c => {
          const v = c.get(l);
          if (c.txt) return `<td class="n">${esc(v ?? '—')}</td>`;
          // Peak end vazio numa vida com histórico = ainda não começou a cair.
          const aindaNoPico = c.k === 'pe' && v === null && m.ainda_no_pico;
          const texto = aindaNoPico ? '<span title="Ainda não começou a cair">no pico</span>' : c.show ? c.show(v) : num(v, c.d ?? 0);
          const flag = c.flag && m.fluke ? '<span class="flag" title="Pico de uma temporada só: o OVR não foi sustentado">⚑</span>' : '';
          return `<td class="n ${c.cls ? c.cls(v) : ''}">${texto}${flag}</td>`;
        }).join('');
        const tags = (l.lenda ? '<span class="tag lenda">LENDA</span>' : '') + (!l.ativo ? '<span class="tag fora">fora</span>' : '')
          + (l.manual ? '<span class="tag manual" title="Criado por você">criado</span>' : '')
          + (l.festiva ? '<span class="tag festa" title="Uma única temporada, com OVR 99 (T20 de 2044): temporada festiva, fora de todas as métricas">99 festivo</span>' : '')
          + (l.nota ? `<i class="bi bi-sticky" style="color:var(--blue);margin-left:5px" title="${esc(l.nota)}"></i>` : '');
        const acoes = est.aba === 'lixeira'
          ? '<button class="ic" data-rest title="Restaurar nos estudos"><i class="bi bi-arrow-counterclockwise"></i></button>'
          : '<button class="ic" data-edit title="Editar"><i class="bi bi-pencil"></i></button><button class="ic perigo" data-del title="Mandar pra lixeira"><i class="bi bi-trash3"></i></button>';
        return `<tr data-id="${l.id}" class="${l.ajustada ? 'ajust' : ''}"><td class="col-jog"><div class="nm">
            <button class="ic ${l.fav ? 'fav' : ''}" data-fav title="${l.fav ? 'Desfavoritar' : 'Favoritar'}"><i class="bi bi-star${l.fav ? '-fill' : ''}"></i></button>
            <div class="txt"><span class="nome">${esc(l.nome)}</span>${tags}<small>${esc(l.time || '—')}${l.liga ? ' · ' + esc(l.liga) : ''}</small></div>
            <div class="acoes">${acoes}</div></div></td>${celulas}</tr>`;
      }).join('');
    }

    $('paginacao').innerHTML = paginas > 1
      ? `<button class="btn" id="pgAnt" ${est.pag === 0 ? 'disabled' : ''}>‹ Anterior</button>
         <span>Página <b>${est.pag + 1}</b> de ${paginas}</span>
         <button class="btn" id="pgProx" ${est.pag >= paginas - 1 ? 'disabled' : ''}>Próxima ›</button>` : '';
    // A rolagem é a da página: ao trocar de página, volta pro começo da tabela.
    const topoDaTabela = () => window.scrollTo(0, Math.max(0, $('tabela').getBoundingClientRect().top + window.scrollY - 12));
    $('pgAnt')?.addEventListener('click', () => { est.pag--; renderTabela(); topoDaTabela(); });
    $('pgProx')?.addEventListener('click', () => { est.pag++; renderTabela(); topoDaTabela(); });
    if ($('pResumo').open) renderResumo();
  }

  $('cab').addEventListener('click', e => {
    const th = e.target.closest('th[data-k]');
    if (!th) return;
    const k = th.dataset.k;
    const i = est.ord.findIndex(([x]) => x === k);
    const asc0 = k === 'nome' || k === 'pos';
    if (e.shiftKey) {                       // Shift: soma uma ordenação secundária
      if (i >= 0) est.ord[i][1] = !est.ord[i][1]; else est.ord.push([k, asc0]);
    } else if (i === 0 && est.ord.length === 1) est.ord[0][1] = !est.ord[0][1];
    else est.ord = [[k, i === 0 ? !est.ord[0][1] : asc0]];
    est.pag = 0; renderTabela();
  });

  /* ── Ações na linha: favoritar, editar, excluir, restaurar, abrir ──── */
  $('corpo').addEventListener('click', e => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    const id = Number(tr.dataset.id);
    const l = (dados[est.aba] || []).find(x => x.id === id);
    if (e.target.closest('[data-fav]')) return alternarFavorito(id, !(l && l.fav));
    if (e.target.closest('[data-edit]')) return abrirEditor(id);
    if (e.target.closest('[data-del]')) return excluir(id, l ? l.nome : '#' + id);
    if (e.target.closest('[data-rest]')) return restaurar(id);
    abrirVida(id);
  });

  async function alternarFavorito(id, valor) {
    try { await enviar('fav', { id, valor }); }
    catch (e) { return avisar('Não deu pra favoritar: ' + e.message); }
    // Muda a estrela em todas as listas já carregadas, sem esperar o servidor de novo.
    for (const k of LISTAS) (dados[k] || []).forEach(l => { if (l.id === id) l.fav = valor; });
    await atualizarContagens();
    renderTabela();
    if (dlg.open && vidaAberta === id && !editando) abrirVida(id);
  }

  async function excluir(id, nome) {
    if (!await perguntar(`Mandar <b>${esc(nome)}</b> pra lixeira?`, 'Isso só tira dos seus estudos (listas, médias e estudo por nome). O histórico do jogo não é mexido, e dá pra restaurar na aba Lixeira.', 'Mandar pra lixeira')) return;
    try { await enviar('excluir', { id, valor: true }); } catch (e) { return avisar('Não excluiu: ' + e.message); }
    if (dlg.open) dlg.close();
    recarregarTudo();
  }
  async function restaurar(id) {
    try { await enviar('excluir', { id, valor: false }); } catch (e) { return avisar('Não restaurou: ' + e.message); }
    if (dlg.open) dlg.close();
    recarregarTudo();
  }

  /* ── Pop-ups próprios (nada de confirm/prompt do navegador) ─────────── */
  const dlgP = $('dlgPergunta');
  function perguntar(titulo, texto, botao = 'Confirmar') {
    return new Promise(resolve => {
      $('perguntaConteudo').innerHTML = `<div class="dlg-cab"><div><h2 style="font-size:16px">${titulo}</h2><p>${texto || ''}</p></div></div>
        <div class="dlg-pe"><button class="btn ok" data-r="1">${esc(botao)}</button><button class="btn" data-r="0">Cancelar</button></div>`;
      const fim = v => { dlgP.close(); resolve(v); };
      $('perguntaConteudo').querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => fim(b.dataset.r === '1')));
      dlgP.addEventListener('cancel', () => resolve(false), { once: true });
      dlgP.showModal();
    });
  }
  function pedir(titulo, rotulo, inicial = '') {
    return new Promise(resolve => {
      $('perguntaConteudo').innerHTML = `<div class="dlg-cab"><div><h2 style="font-size:16px">${esc(titulo)}</h2></div></div>
        <div style="padding:0 18px 12px"><label class="ed"><span class="explica" style="display:block;margin-bottom:5px">${esc(rotulo)}</span>
        <input class="campo" id="pedirValor" style="width:100%" maxlength="120" value="${esc(inicial)}"></label></div>
        <div class="dlg-pe"><button class="btn ok" data-r="1">Criar</button><button class="btn" data-r="0">Cancelar</button></div>`;
      const entrada = $('pedirValor');
      const fim = ok => { const v = entrada.value.trim(); dlgP.close(); resolve(ok && v ? v : null); };
      $('perguntaConteudo').querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => fim(b.dataset.r === '1')));
      entrada.addEventListener('keydown', e => { if (e.key === 'Enter') fim(true); });
      dlgP.addEventListener('cancel', () => resolve(null), { once: true });
      dlgP.showModal();
      entrada.focus();
    });
  }
  const avisar = texto => perguntar('Atenção', esc(texto), 'Ok');

  /* ── Construtor de filtros ──────────────────────────────────────────── */
  function opcoesCampos(sel) {
    const grupos = {};
    CAMPOS.forEach(c => { (grupos[c.g] ||= []).push(c); });
    return Object.entries(grupos).map(([g, cs]) =>
      `<optgroup label="${esc(g)}">${cs.map(c => `<option value="${c.k}" ${c.k === sel ? 'selected' : ''}>${esc(c.rot)}</option>`).join('')}</optgroup>`).join('');
  }

  function renderRegras() {
    const nr = cfg.regras.filter(regraAtiva).length;
    $('nRegras').hidden = !nr; $('nRegras').textContent = nr;
    $('rModo').value = cfg.modo;
    $('rLista').innerHTML = cfg.regras.length ? cfg.regras.map((r, i) => {
      const c = CAMPO[r.c];
      const ops = opsDo(c).map(([v, t]) => `<option value="${v}" ${v === r.op ? 'selected' : ''}>${t}</option>`).join('');
      let valor = '';
      if (c.tipo === 'opc') {
        valor = `<span class="opcs">${c.opcoes.map(o => `<label><input type="checkbox" data-o="${o}" ${r.vs.includes(o) ? 'checked' : ''}>${o}</label>`).join('')}</span>`;
      } else if (c.tipo !== 'bool' && r.op !== 'vz' && r.op !== 'pr') {
        const ent = (cls, v, ph) => c.tipo === 'letra'
          ? `<select data-v="${cls}">${LETRAS.map(x => `<option ${x === v ? 'selected' : ''}>${x}</option>`).join('')}</select>`
          : `<input type="${c.tipo === 'num' ? 'number' : 'text'}" step="any" data-v="${cls}" value="${esc(v)}" placeholder="${ph}">`;
        valor = ent('a', r.a, c.tipo === 'txt' ? 'texto' : 'valor') + (r.op === 'btw' ? ' e ' + ent('b', r.b, 'até') : '');
      }
      return `<div class="regra" data-i="${i}"><select data-c>${opcoesCampos(r.c)}</select><select data-op>${ops}</select>${valor}
        <button class="btn peq rm perigo" data-rm title="Tirar esta regra"><i class="bi bi-x-lg"></i></button></div>`;
    }).join('') : '<p class="dica">Nenhuma regra. Clique em <b>+ Regra</b>, ou use um atalho:</p>';
    $('rAtalhos').innerHTML = ATALHOS.map((a, i) => `<button class="chip" data-atalho="${i}" title="${esc(a.dica)}">${esc(a.rot)}</button>`).join(' ');
  }
  const regra = (c, op, a = '', b = '', vs = []) => ({ c, op, a: String(a), b: String(b), vs });
  const ATALHOS = [
    { rot: 'Estrelas (pico sust. ≥ 90)', dica: 'Quem segurou 90+ por duas temporadas', regras: [regra('pks', 'ge', 90)] },
    { rot: 'Cai tarde (peak end ≥ 32)', dica: 'Só quem já começou a cair, aos 32 ou mais', regras: [regra('pe', 'ge', 32)] },
    { rot: 'Cai cedo (peak end ≤ 28)', dica: 'Quem começou a cair aos 28 ou antes', regras: [regra('pe', 'le', 28)] },
    { rot: 'Estoura rápido (≤ 2 anos até o pico)', dica: 'Chega ao pico em até 2 anos depois da entrada', regras: [regra('aap', 'le', 2), regra('nt', 'ge', 4)] },
    { rot: 'Pico longo (≥ 6 anos)', dica: 'Pelo menos 6 temporadas entre peak start e end', regras: [regra('apk', 'ge', 6)] },
    { rot: 'Entrou jovem (≤ 20 anos)', dica: 'Idade de entrada até 20', regras: [regra('ent', 'le', 20), regra('nt', 'ge', 4)] },
    { rot: 'Com tendência lançada', dica: 'Tem T/SHOT ou T/TOUCH', regras: [regra('b_tend', 'sim')] },
    { rot: 'Sem tendência (ELITE)', dica: 'Jogadores da ELITE sem tendência', regras: [regra('b_tend', 'nao'), regra('liga', 'em', '', '', ['ELITE'])] },
    { rot: 'Só os que mexi', dica: 'Têm correção sua', regras: [regra('b_aj', 'sim')] },
  ];
  $('rAdd').addEventListener('click', () => { cfg.regras.push(novaRegra()); renderRegras(); });
  $('rZera').addEventListener('click', () => { cfg.regras = []; renderRegras(); est.pag = 0; renderTabela(); });
  $('rModo').addEventListener('input', () => { cfg.modo = $('rModo').value; est.pag = 0; renderTabela(); });
  $('rAtalhos').addEventListener('click', e => {
    const b = e.target.closest('[data-atalho]');
    if (!b) return;
    cfg.regras = ATALHOS[Number(b.dataset.atalho)].regras.map(r => ({ ...r, vs: [...r.vs] }));
    cfg.modo = 'todas';
    renderRegras(); est.pag = 0; renderTabela();
  });
  $('rLista').addEventListener('input', e => {
    const linha = e.target.closest('.regra');
    if (!linha) return;
    const r = cfg.regras[Number(linha.dataset.i)];
    if (e.target.matches('[data-c]')) { Object.assign(r, novaRegra(e.target.value)); renderRegras(); }
    else if (e.target.matches('[data-op]')) { r.op = e.target.value; renderRegras(); }
    else if (e.target.matches('[data-v]')) r[e.target.dataset.v] = e.target.value;
    else if (e.target.matches('[data-o]')) r.vs = [...linha.querySelectorAll('[data-o]:checked')].map(x => x.dataset.o);
    est.pag = 0; renderTabela();
  });
  $('rLista').addEventListener('click', e => {
    const rm = e.target.closest('[data-rm]');
    if (!rm) return;
    cfg.regras.splice(Number(rm.closest('.regra').dataset.i), 1);
    renderRegras(); est.pag = 0; renderTabela();
  });

  // Filtros salvos (ficam neste navegador).
  function renderPresets() {
    const p = ler('filtros', {});
    $('rPreset').innerHTML = '<option value="">Filtros salvos…</option>' + Object.keys(p).map(n => `<option>${esc(n)}</option>`).join('');
  }
  $('rSalva').addEventListener('click', async () => {
    const nome = await pedir('Salvar este filtro', 'Dê um nome (as regras, o modo e as caixinhas da barra são guardados neste navegador).');
    if (!nome) return;
    const p = ler('filtros', {});
    p[nome] = { regras: cfg.regras, modo: cfg.modo, busca: $('fBusca').value, lendas: cfg.lendas,
      curtas: $('fCurtas').checked, stats: $('fStats').checked, ativos: $('fAtivos').checked, ajust: $('fAjust').checked };
    guardar('filtros', p); renderPresets(); $('rPreset').value = nome;
  });
  $('rPreset').addEventListener('input', () => {
    const f = ler('filtros', {})[$('rPreset').value];
    if (!f) return;
    cfg.regras = (f.regras || []).map(r => ({ ...r, vs: [...(r.vs || [])] })); cfg.modo = f.modo || 'todas';
    $('fBusca').value = f.busca || ''; $('fCurtas').checked = !!f.curtas; $('fStats').checked = !!f.stats;
    $('fAtivos').checked = !!f.ativos; $('fAjust').checked = !!f.ajust;
    cfg.lendas = f.lendas || 'ocultar'; $('fLendas').value = cfg.lendas;
    renderRegras(); est.pag = 0; renderTabela();
  });
  $('rApaga').addEventListener('click', async () => {
    const n = $('rPreset').value;
    if (!n || !await perguntar(`Apagar o filtro salvo <b>${esc(n)}</b>?`, '', 'Apagar')) return;
    const p = ler('filtros', {}); delete p[n]; guardar('filtros', p); renderPresets();
  });

  /* ── Colunas ────────────────────────────────────────────────────────── */
  function renderColunas() {
    let g = '';
    $('cLista').innerHTML = COLS.map(c => {
      const cab = c.g !== g ? `<div class="grupo">${esc(c.g)}</div>` : '';
      g = c.g;
      return `${cab}<label class="marca" title="${esc(c.dica || '')}"><input type="checkbox" data-col="${c.k}" ${!cfg.cols || cfg.cols.includes(c.k) ? 'checked' : ''}> ${esc(c.rot)}</label>`;
    }).join('');
  }
  $('cLista').addEventListener('input', () => {
    const marcadas = [...$('cLista').querySelectorAll('[data-col]:checked')].map(x => x.dataset.col);
    cfg.cols = marcadas.length === COLS.length ? null : marcadas;
    guardar('cols', cfg.cols); renderTabela();
  });
  Object.keys(PRESETS_COLS).forEach(id => $(id).addEventListener('click', () => {
    cfg.cols = PRESETS_COLS[id]; guardar('cols', cfg.cols); renderColunas(); renderTabela();
  }));

  /* ── Peak ───────────────────────────────────────────────────────────── */
  function preencherPeak() {
    $('pkPonto').value = cfg.peak.ponto; $('pkQueda').value = cfg.peak.queda; $('pkAtrib').value = String(cfg.peak.atrib); $('pkPlato').value = cfg.peak.plato;
  }
  function aplicarPeak(p) {
    cfg.peak = { ...PEAK_PADRAO, ...p };
    guardar('peak', cfg.peak);
    for (const k of LISTAS) dados[k] = null;
    $('pPeak').hidden = true; $('bPeak').classList.remove('on');
    if (est.aba === 'nome') { if ($('nomeBusca').value.trim()) estudarNome($('nomeBusca').value); }
    else carregarLista(est.aba);
    atualizarContagens();
  }
  $('pkAplica').addEventListener('click', () => aplicarPeak({
    ponto: $('pkPonto').value, queda: Math.max(1, Math.min(6, parseInt($('pkQueda').value, 10) || 1)),
    atrib: parseInt($('pkAtrib').value, 10) || 0, plato: Math.max(0, Math.min(3, parseInt($('pkPlato').value, 10) || 0)),
  }));
  $('pkPadrao').addEventListener('click', () => { aplicarPeak({}); preencherPeak(); });

  /* ── Estatísticas do filtro ─────────────────────────────────────────── */
  function renderResumo() {
    const mediana = v => { const s = [...v].sort((a, b) => a - b), m = s.length >> 1; return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2; };
    const lin = colunasVisiveis().filter(c => !c.txt).map(c => {
      const v = visiveis.map(l => c.get(l)).filter(x => x !== null && x !== undefined && !Number.isNaN(x));
      if (!v.length) return `<tr><td>${esc(c.rot)}</td><td>0</td><td colspan="4">—</td></tr>`;
      const f = x => c.show ? c.show(Math.round(x)) : num(x, c.d ?? (Number.isInteger(x) ? 0 : 1));
      return `<tr><td>${esc(c.rot)}</td><td>${v.length}</td><td>${f(v.reduce((a, b) => a + b, 0) / v.length)}</td><td>${f(mediana(v))}</td><td>${f(Math.min(...v))}</td><td>${f(Math.max(...v))}</td></tr>`;
    }).join('');
    $('rResumo').innerHTML = `<table class="resumo-tab"><thead><tr><th>Coluna</th><th>Jogadores</th><th>Média</th><th>Mediana</th><th>Mín</th><th>Máx</th></tr></thead><tbody>${lin}</tbody></table>
      <p class="dica" style="margin-top:6px">Conta os ${visiveis.length.toLocaleString('pt-BR')} jogadores do filtro atual (todas as páginas). Só entra quem tem o valor.</p>`;
  }

  /* ── CSV: a lista inteira (com os filtros e a ordem de agora) ───────── */
  $('bCsv').addEventListener('click', () => {
    const cols = colunasVisiveis();
    const cab = ['id', 'jogador', 'ativo', 'favorito', 'lenda', 'time', 'liga', ...cols.map(c => c.rot)];
    const lin = visiveis.map(l => [l.id, l.nome, l.ativo ? 1 : 0, l.fav ? 1 : 0, l.lenda ? 1 : 0, l.time ?? '', l.liga ?? '', ...cols.map(c => c.show && c.get(l) !== null ? c.show(c.get(l)) : (c.get(l) ?? ''))]);
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
  let vidaAberta = null, editando = false;
  dlg.addEventListener('click', e => { if (e.target === dlg && !editando) dlg.close(); });
  dlg.addEventListener('close', () => { editando = false; vidaAberta = null; });

  async function abrirVida(id) {
    editando = false; vidaAberta = id;
    $('dlgConteudo').innerHTML = '<div class="carga">Carregando carreira…</div>';
    if (!dlg.open) dlg.showModal();
    let v;
    try { v = await get({ acao: 'vida', id }); }
    catch (e) { $('dlgConteudo').innerHTML = `<div class="carga">Não carregou: ${esc(e.message)}</div>`; return; }
    $('dlgConteudo').innerHTML = htmlVida(v);
    const c = $('dlgConteudo');
    c.querySelector('.fechar').addEventListener('click', () => dlg.close());
    c.querySelectorAll('[data-vida]').forEach(b => b.addEventListener('click', () => abrirVida(Number(b.dataset.vida))));
    c.querySelectorAll('[data-estudo]').forEach(b => b.addEventListener('click', () => { dlg.close(); trocarAba('nome', b.dataset.estudo); }));
    c.querySelector('[data-a-fav]')?.addEventListener('click', () => alternarFavorito(id, !v.meta.favorito));
    c.querySelector('[data-a-edit]')?.addEventListener('click', () => abrirEditor(id));
    c.querySelector('[data-a-del]')?.addEventListener('click', () => excluir(id, v.nome));
    c.querySelector('[data-a-rest]')?.addEventListener('click', () => restaurar(id));
  }

  const card = (rot, val, peq) => `<div class="card"><span>${esc(rot)}</span><b>${val}</b>${peq ? `<small>${peq}</small>` : ''}</div>`;

  function htmlVida(v) {
    const m = v.m || {}, h = v.hoje, mt = v.meta || {};
    const sub = h ? [h.time, h.liga, h.pos, h.idade + ' anos', 'OVR ' + h.ovr, h.altura].filter(Boolean).join(' · ') : 'Fora dos elencos hoje';
    const festiva = m.festiva
      ? '<div class="aviso-festa"><b>99 festivo.</b> Uma única temporada, com OVR 99: foi uma temporada festiva e fica <b>fora de todas as métricas</b> e das médias. A carreira está abaixo só como registro.</div>' : '';
    const cards = m.festiva ? '' : m.n ? [
      card('Temporadas', m.n, `T${m.temporada_ini}–T${m.temporada_fim} · ${m.times} time(s)`),
      card('Pico', num(m.pico, 0) + (m.fluke ? ' <span class="flag">⚑</span>' : ''), m.fluke ? 'pico de uma temporada' : 'idade ' + m.idade_pico),
      card('Pico sustentado', num(m.pico_sust, 0), 'idade ' + m.idade_pico_sust),
      card('Início → fim', `${m.ovr_ini} → ${m.ovr_fim}`, `${m.idade_ini} → ${m.idade_fim} anos`),
      card('Entrada · POT', `${m.idade_ini} anos · ${m.pot_ini ?? '—'}`, 'idade de entrada · POT inicial'),
      card('Peak start → end', `${m.peak_start} → ${m.peak_end ?? 'no pico'}`,
        m.peak_end === null ? 'ainda não começou a cair'
          : `${m.anos_pico} ano(s) de pico · ${m.anos_ate_pico} até o pico`
            + (m.peak_end_ovr !== null || m.peak_end_atrib !== null ? `<br>pelo OVR aos ${m.peak_end_ovr ?? '—'}` + (cfgPeakAtrib() ? ` · pelos atributos aos ${m.peak_end_atrib ?? '—'}` : '') : '')),
      card('Queda', num(m.queda, 0), 'do pico sust. ao fim'),
      card('Anos 85+ / 90+', `${m.anos_85} / ${m.anos_90}`),
      card('Jogos', num(m.jogos, 0), m.temporadas_com_stats + ' temporada(s) com stats'),
      card('PTS · REB · AST', `${num(m.pts_pg)} · ${num(m.reb_pg)} · ${num(m.ast_pg)}`, 'médias da carreira'),
      card('STL · BLK · FG%', `${num(m.stl_pg)} · ${num(m.blk_pg)} · ${num(m.fg)}`, 'médias da carreira'),
      card('Melhor PTS / FG%', `${num(m.pico_pts_pg)} / ${num(m.pico_fg)}`, '20+ jogos'),
      card('Prêmios', m.premios_total || 0, Object.entries(m.premios || {}).map(([t, q]) => `${q}× ${t}`).join(' · ')),
    ].join('') : '<div class="nada">Sem histórico de temporadas registrado pra este jogador.</div>';

    const curva = Object.entries(v.curva || {}).map(([i, o]) => [Number(i), o]);
    const graf = (curva.length > 1 && !m.festiva) ? `<div class="sec">OVR por idade</div><div class="bloco">${grafico([{ pontos: curva, cor: '#fc0025', largura: 2.4, bolas: true, raio: 3.5 }], { titulo: 'OVR por idade' })}</div>` : '';

    const linhas = (v.temporadas || []).map(t => {
      const s = t.stats;
      return `<tr><td>T${t.temporada}${t.manual ? ' <span class="tag manual">criada</span>' : t.ajustada ? ' <span class="tag manual" title="Corrigida por você">✎</span>' : ''}</td><td>${t.ano}</td><td>${esc(t.liga)}</td><td style="text-align:left">${esc(t.time)}</td><td>${t.idade}</td><td>${esc(t.pos)}</td>
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
      ? `<div class="sec">Tendências <small style="font-weight:500;text-transform:none;letter-spacing:0;color:var(--text-3)">${td.em ? '· atualizadas em ' + esc(String(td.em).slice(0, 10)) : '· digitadas por você'}</small></div>
         <div class="tend">${Object.entries(td.dados)
            // T/SHOT e T/TOUCH são as duas que o Marcos acompanha: vão na frente e em destaque.
            .sort(([a], [b]) => (['T/SHOT', 'T/TOUCH'].includes(b) ? 1 : 0) - (['T/SHOT', 'T/TOUCH'].includes(a) ? 1 : 0))
            .map(([k, x]) => `<div class="${['T/SHOT', 'T/TOUCH'].includes(k) ? 'dest' : ''}">${esc(k)}<b>${esc(x)}</b></div>`).join('')}</div>` : '';

    const outras = (v.outras_vidas || []).length
      ? `<div class="sec">Outras vidas de ${esc(v.nome)}</div><div class="chips">${v.outras_vidas.map(o =>
          `<button class="chip" data-vida="${o.id}">${esc(o.liga)} · ${o.n} temp. <small>pico ${o.pico}</small></button>`).join('')}
         <button class="chip" data-estudo="${esc(v.nome)}"><i class="bi bi-people"></i> Estudo por nome (${v.outras_vidas.length + 1} vidas)</button></div>` : '';

    const nota = mt.nota ? `<div class="nota-vida"><b>Sua nota:</b> ${esc(mt.nota)}</div>` : '';
    const origem = mt.nome_corrigido ? `<span class="tag manual" title="Nome no jogo: ${esc(mt.nome_original)}">renomeado</span>` : '';
    const lenda = mt.lenda ? `<span class="tag lenda">LENDA${mt.lenda_manual !== null ? ' (sua marcação)' : ''}</span>` : (mt.lenda_manual === 0 ? '<span class="tag fora">não é lenda (sua marcação)</span>' : '');
    const acoes = `<div class="dlg-acoes">
        <button class="btn ${mt.favorito ? 'on' : ''}" data-a-fav><i class="bi bi-star${mt.favorito ? '-fill' : ''}"></i> ${mt.favorito ? 'Favorito' : 'Favoritar'}</button>
        <button class="btn" data-a-edit><i class="bi bi-pencil"></i> Editar</button>
        ${mt.excluido ? '<button class="btn ok" data-a-rest><i class="bi bi-arrow-counterclockwise"></i> Restaurar</button>'
          : '<button class="btn perigo" data-a-del><i class="bi bi-trash3"></i> Excluir</button>'}</div>`;
    return `<div class="dlg-cab"><div><h2>${esc(v.nome)}${lenda}${origem}${mt.manual ? '<span class="tag manual">criado por você</span>' : ''}${mt.excluido ? '<span class="tag fora">na lixeira</span>' : ''}</h2><p>${esc(sub)} · #${v.id}</p></div>
      <button class="fechar" aria-label="Fechar"><i class="bi bi-x-lg"></i></button></div>${acoes}
      <div class="dlg-corpo">${festiva}${nota}<div class="cards">${cards}</div>${graf}${tend}${outras}${tabela}</div>`;
  }
  const cfgPeakAtrib = () => cfg.peak.atrib > 0;

  /* ── O editor ───────────────────────────────────────────────────────── */
  let removidasEd = [];

  async function abrirEditor(id) {
    editando = true; vidaAberta = id;
    $('dlgConteudo').innerHTML = '<div class="carga">Carregando…</div>';
    if (!dlg.open) dlg.showModal();
    let v;
    try { v = await get({ acao: 'vida', id }); }
    catch (e) { $('dlgConteudo').innerHTML = `<div class="carga">Não carregou: ${esc(e.message)}</div>`; return; }
    removidasEd = (v.removidas || []).slice();
    const mt = v.meta;
    const opt = (valor, rot, sel) => `<option value="${valor}" ${String(sel) === String(valor) ? 'selected' : ''}>${rot}</option>`;
    $('dlgConteudo').innerHTML = `<div class="dlg-cab"><div><h2>Editando ${esc(v.nome)}</h2>
        <p>Suas correções ficam numa camada à parte — o histórico do jogo não é alterado. #${v.id}${mt.manual ? ' · criado por você' : ''}</p></div>
        <button class="fechar" aria-label="Fechar sem salvar"><i class="bi bi-x-lg"></i></button></div>
      <div class="dlg-corpo ed">
        <div class="linha">
          <label class="cp" style="flex:1;min-width:220px">Nome<input id="edNome" maxlength="120" value="${esc(v.nome)}"></label>
          <label class="cp">É lenda?<select id="edLenda">${opt('auto', 'Automático (' + (mt.lenda_auto ? 'sim, no jogo' : 'não, no jogo') + ')', mt.lenda_manual === null ? 'auto' : '')}${opt('1', 'Sim, é lenda', mt.lenda_manual === 1 ? '1' : '')}${opt('0', 'Não é lenda', mt.lenda_manual === 0 ? '0' : '')}</select></label>
          <label class="cp">T/SHOT<input id="edTshot" type="number" min="0" max="99" style="width:80px" value="${mt.tshot_manual ?? ''}" placeholder="${esc(v.tendencias?.dados?.['T/SHOT'] ?? '')}"></label>
          <label class="cp">T/TOUCH<input id="edTtouch" type="number" min="0" max="99" style="width:80px" value="${mt.ttouch_manual ?? ''}" placeholder="${esc(v.tendencias?.dados?.['T/TOUCH'] ?? '')}"></label>
        </div>
        <label class="cp" style="margin-bottom:10px">Sua nota sobre ele<textarea id="edNota" maxlength="5000" placeholder="O que você quiser lembrar (aparece na busca)">${esc(mt.nota || '')}</textarea></label>
        <div class="sec" style="margin-top:6px">Temporadas <small style="text-transform:none;letter-spacing:0;font-weight:500">· edite direto; ✕ tira a temporada dos estudos; letras em branco = sem dado</small></div>
        <div class="tabela" style="max-height:none"><table id="edTab"><thead><tr><th>Temp.</th><th>Ano</th><th>Liga</th><th>Time</th><th>Idade</th><th>Pos</th><th>OVR</th>${SKILLS.map(k => `<th>${k}</th>`).join('')}
          <th>J</th><th>MIN</th><th>PTS</th><th>REB</th><th>AST</th><th>STL</th><th>BLK</th><th>FG%</th><th></th></tr></thead><tbody id="edCorpo">${(v.temporadas || []).map(t => linhaEditor(t)).join('')}</tbody></table></div>
        <div class="linha" style="margin-top:8px"><button class="btn peq" id="edAdd"><i class="bi bi-plus-lg"></i> Temporada</button></div>
        <div id="edRemovidas"></div>
      </div>
      <div class="erro" id="edErro" hidden></div>
      <div class="dlg-pe"><button class="btn ok" id="edSalva"><i class="bi bi-check-lg"></i> Salvar</button><button class="btn" id="edCancela">Cancelar</button>
        <span style="flex:1"></span>
        <button class="btn" id="edReverte" title="Apaga todas as suas correções deste jogador e volta ao dado do jogo"><i class="bi bi-arrow-counterclockwise"></i> Desfazer correções</button></div>`;
    renderRemovidas();
    const c = $('dlgConteudo');
    c.querySelector('.fechar').addEventListener('click', () => abrirVida(id));
    $('edCancela').addEventListener('click', () => abrirVida(id));
    $('edAdd').addEventListener('click', () => {
      const ult = [...$('edCorpo').querySelectorAll('tr')].pop();
      const base = ult ? lerLinha(ult) : { temporada: 1, ano: 2030, liga: 'ELITE', time: '', idade: 19, pos: 'PF', ovr: 70, letras: {}, stats: {} };
      const nova = { ...base, season_id: null, temporada: (+base.temporada || 0) + 1, ano: (+base.ano || 0) + 1, idade: (+base.idade || 0) + 1, stats: {}, nova: true };
      $('edCorpo').insertAdjacentHTML('beforeend', linhaEditor(nova));
    });
    $('edCorpo').addEventListener('click', e => {
      const x = e.target.closest('[data-x]');
      if (!x) return;
      const tr = x.closest('tr');
      const t = lerLinha(tr);
      if (t.season_id !== null && t.season_id > 0) { removidasEd.push(t); renderRemovidas(); }
      tr.remove();
    });
    $('edRemovidas').addEventListener('click', e => {
      const b = e.target.closest('[data-volta]');
      if (!b) return;
      const t = removidasEd.splice(Number(b.dataset.volta), 1)[0];
      $('edCorpo').insertAdjacentHTML('beforeend', linhaEditor(t));
      renderRemovidas();
    });
    $('edSalva').addEventListener('click', () => salvarEdicao(id));
    $('edReverte').addEventListener('click', async () => {
      if (!await perguntar('Desfazer as correções?', 'Volta o nome, a marcação de lenda, as tendências e todas as temporadas ao que está no jogo. Temporadas que você criou à mão somem. Favorito e lixeira não mudam.', 'Desfazer')) return;
      try { await enviar('reverter', { id }); } catch (e) { return mostrarErroEd(e.message); }
      recarregarTudo(); abrirVida(id);
    });
  }

  const sel = (opcoes, atual, cls) => `<select class="${cls || ''}">${opcoes.map(o => `<option ${o === atual ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>`;
  function linhaEditor(t) {
    const s = t.stats || {};
    const n = (campo, valor, extra = '') => `<td><input type="number" step="any" data-f="${campo}" value="${valor === null || valor === undefined ? '' : esc(valor)}" ${extra}></td>`;
    const ligas = LIGAS.includes(t.liga) || !t.liga ? LIGAS : [t.liga, ...LIGAS];
    return `<tr data-sid="${t.season_id ?? ''}" class="${t.nova ? 'nova' : ''}">
      ${n('temporada', t.temporada, 'class="w-s"')}${n('ano', t.ano)}
      <td>${sel(ligas, t.liga || 'ELITE').replace('<select class=""', '<select data-f="liga"')}</td>
      <td><input data-f="time" class="w-l" value="${esc(t.time || '')}"></td>${n('idade', t.idade)}
      <td>${sel(['', ...POSICOES], t.pos || '').replace('<select class=""', '<select data-f="pos"')}</td>${n('ovr', t.ovr)}
      ${SKILLS.map(k => `<td><select data-l="${k}">${LETRAS.map(x => `<option ${x === ((t.letras || {})[k] || '') ? 'selected' : ''}>${x}</option>`).join('')}</select></td>`).join('')}
      ${n('jogos', s.jogos)}${n('min', s.min)}${n('pts', s.pts)}${n('reb', s.reb)}${n('ast', s.ast)}${n('stl', s.stl)}${n('blk', s.blk)}${n('fg', s.fg)}
      <td><button class="ic perigo" data-x title="Tirar esta temporada"><i class="bi bi-x-lg"></i></button></td></tr>`;
  }

  function lerLinha(tr) {
    const f = campo => tr.querySelector(`[data-f="${campo}"]`).value;
    const sid = tr.dataset.sid === '' ? null : Number(tr.dataset.sid);
    const letras = {};
    tr.querySelectorAll('[data-l]').forEach(s => { letras[s.dataset.l] = s.value; });
    const stats = {};
    ['jogos', 'min', 'pts', 'reb', 'ast', 'stl', 'blk', 'fg'].forEach(k => { stats[k] = f(k); });
    return { season_id: sid, temporada: f('temporada'), ano: f('ano'), liga: f('liga'), time: f('time'), idade: f('idade'), pos: f('pos'), ovr: f('ovr'), letras, stats };
  }

  function renderRemovidas() {
    $('edRemovidas').innerHTML = removidasEd.length
      ? `<div class="sec">Temporadas tiradas (clique pra trazer de volta)</div><div class="chips">${removidasEd.map((t, i) =>
          `<button class="chip" data-volta="${i}">T${esc(t.temporada)} · ${esc(t.ano)} · ${esc(t.idade)} anos · OVR ${esc(t.ovr)}</button>`).join('')}</div>` : '';
  }
  function mostrarErroEd(texto) { const e = $('edErro'); e.hidden = false; e.textContent = texto; e.scrollIntoView({ block: 'nearest' }); }

  async function salvarEdicao(id) {
    $('edErro').hidden = true;
    const temporadas = [...$('edCorpo').querySelectorAll('tr')].map(lerLinha);
    $('edSalva').disabled = true;
    try {
      await enviar('salvar', {
        id, nome: $('edNome').value, lenda: $('edLenda').value, nota: $('edNota').value,
        tshot: $('edTshot').value, ttouch: $('edTtouch').value, temporadas,
      });
    } catch (e) { $('edSalva').disabled = false; return mostrarErroEd(e.message); }
    recarregarTudo();
    abrirVida(id);
  }

  /* ── Novo jogador ───────────────────────────────────────────────────── */
  $('bNovo').addEventListener('click', async () => {
    const nome = await pedir('Novo jogador', 'Nome do jogador (depois você coloca as temporadas no editor).');
    if (!nome) return;
    try {
      const r = await enviar('nova_vida', { nome });
      recarregarTudo();
      abrirEditor(r.id);
    } catch (e) { avisar('Não criou: ' + e.message); }
  });

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
  ['nomeMin', 'nomeLendas'].forEach(id => $(id).addEventListener('change', () => { if ($('nomeBusca').value.trim()) estudarNome($('nomeBusca').value); }));

  async function estudarNome(nome) {
    nome = String(nome || '').trim();
    if (!nome) return;
    $('nomeResultado').innerHTML = '<div class="carga">Calculando…</div>';
    history.replaceState(null, '', '#nome=' + encodeURIComponent(nome));
    let d;
    try { d = await get({ acao: 'nome', nome, min: $('nomeMin').value, lendas: $('nomeLendas').checked ? 1 : 0 }); }
    catch (e) { $('nomeResultado').innerHTML = `<div class="nada">${esc(e.message)}</div>`; return; }
    $('nomeResultado').innerHTML = htmlEstudo(d);
    $('nomeResultado').querySelectorAll('tr[data-vida]').forEach(tr => tr.addEventListener('click', e => {
      if (e.target.closest('[data-fav]')) return;
      abrirVida(Number(tr.dataset.vida));
    }));
    $('nomeResultado').querySelectorAll('[data-fav]').forEach(b => b.addEventListener('click', async () => {
      await alternarFavorito(Number(b.dataset.fav), b.dataset.val !== '1');
      estudarNome(nome);
    }));
  }

  const linhaResumo = (rot, r, d = 1, dica) => {
    r = r || {};
    return `<tr><td title="${esc(dica || '')}">${esc(rot)}</td><td class="n">${num(r.media, d)}</td><td class="n">${num(r.mediana, d)}</td><td class="n">${num(r.min, d)}</td><td class="n">${num(r.max, d)}</td><td class="n fraco">${r.n ?? 0}</td></tr>`;
  };
  const cabResumo = '<thead><tr><th style="text-align:left">Métrica</th><th>Média</th><th>Mediana</th><th>Mín</th><th>Máx</th><th>Vidas</th></tr></thead>';

  function htmlEstudo(d) {
    const R = d.resumo;
    const porVida = f => d.vidas.slice().sort((a, b) => b.m.pico_sust - a.m.pico_sust).filter(v => v.usada).map(f).join(' / ') || '—';
    const cards = [
      card('Vidas', d.vidas_total, `${d.vidas_usadas} entram nas médias (${d.min_temporadas}+ temp.)`),
      card('Peak end por vida', `<span class="porvida">${porVida(v => v.m.peak_end ?? 'no pico')}</span>`, 'idade em que começou a cair (da maior pra menor pico)'),
      card('Peak start por vida', `<span class="porvida">${porVida(v => v.m.peak_start)}</span>`, 'idade em que chegou ao pico'),
      card('Pico sustentado', num(R.pico_sustentado.media), `mediana ${num(R.pico_sustentado.mediana)}`),
      card('Idade do pico', num(R.idade_do_pico.media), `de ${num(R.idade_do_pico.min, 0)} a ${num(R.idade_do_pico.max, 0)} anos`),
      card('Peak start → end', `${num(R.peak_start.media)} → ${num(R.peak_end.media)}`, `${R.peak_end.n} de ${d.vidas_usadas} vida(s) já começaram a cair`),
      card('Anos de pico', num(R.anos_de_pico.media), `${num(R.anos_ate_o_pico.media)} anos até chegar lá`),
      card('Entrada · POT', `${num(R.idade_inicial.media)} · ${d.pot_inicial_letra ?? '—'}`, 'idade de entrada · POT inicial'),
      card('T/SHOT · T/TOUCH', `${num(R.t_shot.media, 0)} · ${num(R.t_touch.media, 0)}`, `${R.t_shot.n} vida(s) com tendência lançada`),
      card('Carreira', num(R.temporadas.media) + ' temp.', `de ${num(R.temporadas.min, 0)} a ${num(R.temporadas.max, 0)}`),
      card('OVR no fim', num(R.ovr_no_fim.media), `aos ${num(R.idade_no_fim.media)} anos`),
      card('Queda do pico ao fim', num(R.queda_pico_ao_fim.media), 'em OVR'),
      card('FG%', num(R.fg.media), `${R.fg.n} vida(s) com dado`),
    ].join('');

    const aviso = d.lendas_fora && !d.com_lendas
      ? `<div class="nota-vida">${d.lendas_fora} vida(s) de <b>lenda</b> ficaram fora das médias. Marque “incluir lendas” lá em cima pra contar.</div>` : '';

    const letras = `<div class="letras">${Object.entries(d.letras_pico).map(([k, x]) => {
      const c = x.letra ? x.letra[0].toLowerCase() : '';
      return `<div class="letra ${c}" title="média numérica ${x.media ?? '—'} (A+ = 12, F = 0)">${esc(k)}<b>${esc(x.letra || '—')}</b></div>`;
    }).join('')}</div>`;

    // As curvas: cada vida fina (as apagadas ainda mais), e a média grossa por cima.
    const series = d.vidas.map((v, i) => ({
      pontos: Object.entries(v.curva).map(([a, o]) => [Number(a), o]),
      cor: CORES[i % CORES.length], largura: 1.4, opacidade: v.usada ? 0.7 : 0.22,
      rotulo: `vida ${v.id} (${v.liga}${v.usada ? '' : v.lenda && !d.com_lendas ? ', lenda: fora das médias' : ', fora das médias'})`,
    })).filter(s => s.pontos.length);
    if (d.curva_media.length) series.push({
      pontos: d.curva_media.map(p => [p.idade, p.ovr]), cor: '#fc0025', largura: 3.2, bolas: true,
      raios: d.curva_media.map(p => 2 + Math.min(p.vidas, 5) * 0.8),
      rotulo: 'MÉDIA das vidas usadas (bolinha maior = mais vidas no ponto)', dicas: d.curva_media.map(p => `${p.idade} anos: OVR médio ${p.ovr} (${p.vidas} vida(s))`),
    });

    const variacao = d.variacao_anual.length
      ? `<table><thead><tr><th style="text-align:left">Idade</th><th>Variação média do OVR</th><th>Vidas</th></tr></thead><tbody>${d.variacao_anual.map(x =>
          `<tr><td>${x.de} → ${x.para}</td><td class="n ${x.media > 0 ? 'alto' : x.media < 0 ? 'fraco' : ''}">${x.media > 0 ? '+' : ''}${num(x.media, 2)}</td><td class="n fraco">${x.vidas}</td></tr>`).join('')}</tbody></table>`
      : '<div class="nada">Poucas vidas pra medir a variação.</div>';

    const vidas = d.vidas.slice().sort((a, b) => (b.m.pico_sust ?? 0) - (a.m.pico_sust ?? 0)).map(v => {
      const m = v.m;
      const semDado = !m.n;
      return `<tr class="${v.usada ? '' : 'fora-media'}" data-vida="${v.id}"><td style="text-align:left"><button class="ic ${v.fav ? 'fav' : ''}" data-fav="${v.id}" data-val="${v.fav ? 1 : 0}" title="${v.fav ? 'Desfavoritar' : 'Favoritar'}"><i class="bi bi-star${v.fav ? '-fill' : ''}"></i></button> #${v.id}${v.lenda ? '<span class="tag lenda">LENDA</span>' : ''}</td><td>${esc(v.liga ?? '—')}</td><td class="n">${m.n ?? 0}</td>
        <td class="n ${cOvr(m.pico)}">${semDado ? '—' : m.pico}${m.fluke ? '<span class="flag" title="pico de uma temporada só">⚑</span>' : ''}</td><td class="n ${cOvr(m.pico_sust)}">${semDado ? '—' : m.pico_sust}</td>
        <td class="n">${v.festiva || semDado ? '—' : m.peak_start}</td><td class="n">${v.festiva || semDado ? '—' : (m.peak_end ?? '<span title="ainda não começou a cair">no pico</span>')}</td>
        <td class="n">${semDado ? '—' : m.ovr_fim}</td><td class="n">${semDado ? '—' : m.queda}</td><td class="n">${num(m.pts_pg)}</td><td class="n">${num(m.fg)}</td>
        <td class="n">${num(v.tshot, 0)}</td><td class="n">${num(v.ttouch, 0)}</td>
        <td>${v.festiva ? '<span class="tag festa" title="Uma única temporada, com OVR 99: temporada festiva, fora de todas as métricas">99 festivo · fora das métricas</span>'
          : v.usada ? '' : '<span class="tag fora">fora das médias</span>'}</td></tr>`;
    }).join('');

    return `<h2 style="font-size:20px;font-weight:800;margin:6px 0 2px">${esc(d.nome)}</h2>
      ${aviso}<div class="cards">${cards}</div>

      <div class="sec">Carreira (média entre as ${d.vidas_usadas} vidas usadas)</div>
      <div class="bloco"><table>${cabResumo}<tbody>
        ${linhaResumo('Pico (OVR máximo)', R.pico, 1, 'o maior OVR, mesmo que de uma temporada só')}
        ${linhaResumo('Pico sustentado', R.pico_sustentado, 1, 'o maior OVR segurado por duas temporadas seguidas')}
        ${linhaResumo('Idade do pico sustentado', R.idade_do_pico)}
        ${linhaResumo('Peak start (idade em que chega ao pico)', R.peak_start, 1, 'a 1ª temporada com OVR perto do pico sustentado')}
        ${linhaResumo('Peak end (idade em que começou a cair)', R.peak_end, 1, 'só entram as vidas que já começaram a cair')}
        ${linhaResumo('Anos de pico (peak end − peak start)', R.anos_de_pico)}
        ${linhaResumo('Anos até o pico (da entrada ao peak start)', R.anos_ate_o_pico)}
        ${linhaResumo('POT na entrada (A+ = 12 … F = 0)', R.pot_inicial, 1, 'o POT da 1ª temporada registrada')}
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
        ${linhaResumo('T/SHOT (tendência de arremessar, 0–99)', R.t_shot, 0, 'só quem tem tendência lançada')}
        ${linhaResumo('T/TOUCH (tendência de ficar com a bola, 0–99)', R.t_touch, 0, 'só quem tem tendência lançada')}
      </tbody></table>
      <p class="explica" style="margin:8px 0 0">A coluna <b>Vidas</b> diz com quantas vidas cada média foi calculada — só parte das temporadas tem estatística, e o FG% some nas primeiras.</p></div>

      <div class="sec">Letras na temporada do pico sustentado (média das vidas)</div>
      <div class="bloco">${letras}</div>

      <div class="sec">OVR por idade</div>
      <div class="bloco">${grafico(series, { titulo: 'OVR por idade' })}</div>

      <div class="sec">Quanto o OVR muda de um ano pro outro</div>
      <div class="bloco">${variacao}</div>

      <div class="sec">As vidas (clique pra abrir a carreira · ☆ favorita)</div>
      <div class="bloco"><table><thead><tr><th style="text-align:left">Vida</th><th>Liga</th><th>Temp.</th><th>Pico</th><th>Pico sust.</th><th>Peak start</th><th>Peak end</th><th>Fim</th><th>Queda</th><th>PTS</th><th>FG%</th><th>T/SHOT</th><th>T/TOUCH</th><th></th></tr></thead>
        <tbody>${vidas}</tbody></table></div>`;
  }

  /* ── Começo: lê o que veio no endereço (#elite, #outras, #historico, #favoritos, #lixeira, #nome=...) ── */
  function rotear() {
    const hash = decodeURIComponent(location.hash.replace(/^#/, ''));
    if (hash.startsWith('nome')) trocarAba('nome', hash.includes('=') ? hash.split('=').slice(1).join('=') : undefined);
    else trocarAba(LISTAS.includes(hash) ? hash : 'elite');
  }
  // Colar um link com outro #aba numa página já aberta não recarrega — então escuta.
  // (As trocas feitas pela própria tela usam replaceState, que não dispara isto.)
  window.addEventListener('hashchange', rotear);
  renderRegras(); renderPresets();
  rotear();
  atualizarContagens();
})();
