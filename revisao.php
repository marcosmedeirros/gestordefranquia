<?php
/**
 * CONFERÊNCIA DE OFF-SEASON — a liga inteira numa tela só.
 *
 * Nasceu em 26/09/2026, depois que o banco caiu e ~34h de trocas voltaram a
 * ser reconstruídas a partir do que cada GM lembrava no WhatsApp. O gargalo
 * não foi aplicar: foi descobrir o que estava errado, time por time.
 *
 * Aqui estão os 30 times abertos, com elenco e picks (do ano em curso pra
 * frente), e qualquer GM arruma qualquer um enquanto a janela está aberta
 * (REVISAO_ABERTA, em backend/revisao.php). Cada time termina no "Time OK".
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/revisao.php';
requireAuth();

$user = getUserSession();
$pdo  = db();
revisaoGarantirTabelas($pdo);

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;

/* A liga da tela: a escolhida na aba, ou a do próprio GM quando ele abre
   direto. Quem não tem time cai na primeira da lista. */
revisaoLiga($_GET['liga'] ?? ($team['league'] ?? null));
$ligaAtual = revisaoLiga();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Conferência - FBA Manager</title>
  <meta name="theme-color" content="#fc0025">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/styles.css">
  <style>
    /* Os tokens do app. shell-css.php depende deles já estarem de pé — sem
       este bloco a barra lateral ocupa a largura toda, que é exatamente o
       aviso que está no topo daquele arquivo. */
    :root{
      --red:#fc0025;
      --red-2:color-mix(in srgb, var(--red) 85%, white);
      --red-soft:color-mix(in srgb, var(--red) 10%, transparent);
      --red-glow:color-mix(in srgb, var(--red) 18%, transparent);
      --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
      --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
      --border-red:color-mix(in srgb, var(--red) 22%, transparent);
      --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
      --green:#22c55e; --amber:#f59e0b; --blue:#3b82f6;
      --sidebar-w:260px;
      --font:'Montserrat',sans-serif;
      --radius:14px; --radius-sm:10px; --radius-xs:6px;
      --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
    }
    :root[data-theme="light"]{
      --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
      --border:#e3e6ee; --border-md:#d7dbe6; --text:#12141a; --text-2:#5b6172; --text-3:#6b7080;
    }
  </style>
  <?php /* Barra lateral, topbar, main — o mesmo shell das outras telas. */ ?>
  <?php include __DIR__ . '/includes/shell-css.php'; ?>
  <style>
    .rv-wrap{display:flex;flex-direction:column;gap:14px;max-width:1120px}

    /* Abas de liga: cada uma confere a sua, com regra de cap diferente. */
    .rv-abas{display:flex;gap:4px;border-bottom:1px solid var(--border-md,#2a2a31)}
    .rv-aba{padding:9px 20px;font-size:13.5px;font-weight:700;color:var(--text-2,#9aa);
      text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;letter-spacing:.02em}
    .rv-aba:hover{color:var(--text,#fff)}
    .rv-aba.on{color:var(--red,#fc0025);border-bottom-color:var(--red,#fc0025)}

    .rv-topo{border:1px solid var(--border-md,#2a2a31);border-radius:12px;background:var(--panel,#0e0e12);
      padding:15px 17px;display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between}
    .rv-topo h2{margin:0 0 3px;font-size:16px;font-weight:700}
    .rv-topo p{margin:0;font-size:13px;color:var(--text-2,#9aa);max-width:62ch}
    .rv-prog{display:flex;align-items:baseline;gap:8px}
    .rv-prog b{font-size:26px;font-weight:800;font-variant-numeric:tabular-nums;line-height:1}
    .rv-prog span{font-size:12px;color:var(--text-2,#9aa)}

    .rv-filtros{display:flex;flex-wrap:wrap;gap:7px}
    .rv-pill{background:var(--panel-2,#16161a);color:var(--text-2,#9aa);border:1px solid var(--border-md,#2a2a31);
      border-radius:999px;padding:6px 14px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit}
    .rv-pill:hover{color:var(--text,#fff)}
    .rv-pill.on{background:var(--red,#fc0025);border-color:var(--red,#fc0025);color:#fff}
    .rv-busca{flex:1;min-width:170px;background:var(--panel-2,#16161a);border:1px solid var(--border-md,#2a2a31);
      color:var(--text,#fff);border-radius:999px;padding:6px 15px;font-size:13px;font-family:inherit}

    /* ── card de time ──────────────────────────────────────────── */
    /* Resultado da busca: responde "onde isso está" antes da lista de times. */
    .rv-achados{border:1px solid var(--border-red,#4a1520);border-radius:12px;
      background:color-mix(in srgb,var(--red,#fc0025) 5%,var(--panel,#0e0e12));overflow:hidden}
    .ac-h{padding:9px 16px;font-size:11.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
      color:var(--text-2,#9aa);border-bottom:1px solid var(--border,#1c1c22)}
    .ac-l{display:flex;align-items:center;gap:11px;padding:8px 16px;
      border-bottom:1px solid var(--border,#1c1c22)}
    .ac-l:last-child{border-bottom:0}
    .ac-onde{color:var(--text-2,#9aa);font-size:12px;white-space:nowrap}
    .ac-onde b{color:var(--text,#fff);font-weight:600}

    .tm{border:1px solid var(--border-md,#2a2a31);border-radius:12px;background:var(--panel,#0e0e12);
      overflow:hidden}
    .tm.ok{border-color:#1f6b38}
    /* Time confirmado fica verde na linha inteira, pra dar pra varrer a lista
       de cima a baixo e ver quem falta sem abrir nada. */
    .tm.ok > summary{background:color-mix(in srgb,#2fd06a 9%,transparent)}
    .tm.ok > summary:hover{background:color-mix(in srgb,#2fd06a 14%,transparent)}
    .tm.ok .tm-nome b{color:#8ee9a8}
    .tm.torto{border-color:#7a2020}
    .tm > summary{padding:13px 16px;cursor:pointer;display:flex;align-items:center;gap:13px;
      list-style:none;flex-wrap:wrap}
    .tm > summary::-webkit-details-marker{display:none}
    .tm > summary:hover{background:var(--panel-2,#16161a)}
    .tm-seta{color:var(--text-2,#9aa);transition:transform .15s}
    .tm[open] .tm-seta{transform:rotate(90deg)}
    .tm-nome{flex:1;min-width:150px}
    .tm-nome b{display:block;font-size:15px;font-weight:700}
    .tm-nome small{color:var(--text-2,#9aa);font-size:12px}
    .tm-nums{display:flex;gap:16px;align-items:center}
    .tm-n{text-align:right}
    .tm-n b{display:block;font-size:16px;font-weight:800;font-variant-numeric:tabular-nums;line-height:1.1}
    .tm-n span{font-size:10.5px;letter-spacing:.05em;text-transform:uppercase;color:var(--text-2,#9aa)}
    .tm-n.alerta b{color:#ff5a6e}
    .tm-sel{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;white-space:nowrap}
    .tm-sel.ok{background:color-mix(in srgb,#2fd06a 16%,transparent);color:#4ade80}
    .tm-sel.torto{background:color-mix(in srgb,#fc0025 14%,transparent);color:#ff8a97}
    .tm-sel.pend{background:var(--panel-2,#16161a);color:var(--text-2,#9aa)}
    /* O OK mora na própria linha do time: dar o certo não deveria exigir
       abrir o card e rolar até o fim. */
    .tm-ok-btn{background:transparent;border:1px solid #1f6b38;color:#4ade80;border-radius:999px;
      padding:4px 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;
      display:inline-flex;align-items:center;gap:6px}
    .tm-ok-btn:hover{background:#1f9d4d;border-color:#1f9d4d;color:#fff}

    .tm-corpo{border-top:1px solid var(--border-md,#2a2a31);padding:0}
    .tm-cols{display:grid;grid-template-columns:1fr 340px;gap:0}
    .tm-cols > div{padding:12px 16px}
    .tm-cols > div:first-child{border-right:1px solid var(--border,#1c1c22)}
    .tm-h{font-size:11.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
      color:var(--text-2,#9aa);margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;gap:8px}

    .ln{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--border,#1c1c22)}
    .ln:last-child{border-bottom:0}
    .ln-ovr{font-weight:800;font-size:14px;min-width:26px;text-align:right;font-variant-numeric:tabular-nums}
    .ln-nome{flex:1;min-width:0}
    .ln-nome b{display:block;font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ln-nome small{color:var(--text-2,#9aa);font-size:11.5px}
    .ln-acoes{display:flex;gap:5px;flex-shrink:0}
    .mini{background:var(--panel-2,#16161a);border:1px solid var(--border-md,#2a2a31);color:var(--text-2,#9aa);
      border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit;
      white-space:nowrap}
    .mini:hover{color:#fff;border-color:var(--red,#fc0025)}
    .mini.perigo:hover{color:#ff5a6e;border-color:#7a2020}
    .mini.verde{border-color:#1f6b38;color:#4ade80}
    .mini.verde:hover{background:#1f9d4d;color:#fff;border-color:#1f9d4d}
    .tag{font-size:10px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:0 5px;
      border-radius:3px;background:var(--panel-2,#16161a);color:var(--text-2,#9aa)}
    .vazio{color:var(--text-2,#9aa);font-size:12.5px;padding:8px 0}

    .tm-pend{padding:9px 16px;background:color-mix(in srgb,#fc0025 7%,transparent);
      border-top:1px solid var(--border,#1c1c22);font-size:12.5px;color:#ff8a97;
      display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .tm-rodape{padding:11px 16px;border-top:1px solid var(--border,#1c1c22);display:flex;
      justify-content:flex-end;gap:8px;flex-wrap:wrap;align-items:center}

    @media (max-width:820px){
      .tm-cols{grid-template-columns:1fr}
      .tm-cols > div:first-child{border-right:0;border-bottom:1px solid var(--border,#1c1c22)}
    }
    @media (max-width:560px){
      .tm > summary{gap:9px}
      .tm-nums{width:100%;justify-content:flex-start;gap:20px}
      .ln{flex-wrap:wrap}
      .ln-acoes{width:100%;justify-content:flex-end}
    }
  </style>
</head>
<body>
  <div class="app">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="sb-overlay" id="sbOverlay"></div>

    <main class="main">
      <div class="topbar">
        <button class="topbar-menu-btn" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <span class="topbar-title">
          <i class="bi bi-clipboard2-check me-2" style="color:var(--red)"></i>Conferência
        </span>
      </div>

      <div class="content">
        <div class="rv-wrap">

          <div class="rv-abas">
            <?php foreach (REVISAO_LIGAS as $lg): ?>
              <a href="/revisao.php?liga=<?= urlencode($lg) ?>"
                 class="rv-aba<?= $lg === $ligaAtual ? ' on' : '' ?>"><?= htmlspecialchars($lg) ?></a>
            <?php endforeach; ?>
          </div>

          <div class="rv-topo">
            <div>
              <h2>Confira os elencos e as picks da <?= htmlspecialchars($ligaAtual) ?></h2>
              <p>
                Todos os times estão abertos: se um jogador seu está no time errado, mova daqui mesmo.
                Picks do ano em curso pra frente. Cada movimento fica registrado com o seu nome.
              </p>
            </div>
            <div class="rv-prog"><b id="pgN">—</b><span id="pgT">de 30 confirmados</span></div>
          </div>

          <div class="rv-filtros">
            <input class="rv-busca" id="busca" placeholder="Buscar jogador, pick ou time…"
                   oninput="aoBuscar()" autocomplete="off">
            <button class="rv-pill on" data-f="todos" onclick="filtro(this)">Todos</button>
            <button class="rv-pill" data-f="torto" onclick="filtro(this)">Irregulares</button>
            <button class="rv-pill" data-f="pend" onclick="filtro(this)">Falta confirmar</button>
            <button class="rv-pill" data-f="ok" onclick="filtro(this)">Confirmados</button>
          </div>

          <!-- Onde está o jogador / a pick. Some quando a busca está vazia. -->
          <div class="rv-achados" id="achados" hidden></div>

          <div id="lista"><div class="vazio" style="padding:26px;text-align:center">Carregando a liga…</div></div>
        </div>
      </div>
    </main>
  </div>

  <!-- modal: mover jogador / enviar pick -->
  <div class="modal fade" id="mvModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="mvTitulo">Para qual time?</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p id="mvOque" style="font-size:14px;color:var(--text-2,#9aa)"></p>
          <select class="form-select" id="mvTime"></select>
          <div class="alert alert-danger mt-3" id="mvErro" style="display:none;font-size:13px"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-r secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn-r primary" id="mvConfirma">Mover</button>
        </div>
      </div>
    </div>
  </div>

  <!-- modal: adicionar jogador -->
  <div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Adicionar jogador <span id="addTime" style="font-weight:400;opacity:.7"></span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#abaFa">
              Da free agency</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaNovo">
              Criar do zero</button></li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="abaFa">
              <input class="form-control mb-3" id="faBusca" placeholder="Buscar na free agency…"
                     oninput="carregarFa()" autocomplete="off">
              <div id="faLista" style="max-height:340px;overflow:auto"></div>
            </div>
            <div class="tab-pane fade" id="abaNovo">
              <p style="font-size:13px;color:var(--text-2,#9aa)">
                Use isto só pra quem não existe em lugar nenhum do app. Se o jogador já está em
                outro time, mova ele — criar um segundo deixa dois com o mesmo nome.
              </p>
              <div class="row g-2">
                <div class="col-12"><input class="form-control" id="nvNome" placeholder="Nome"></div>
                <div class="col-6 col-md-3"><select class="form-select" id="nvPos">
                  <option value="">Posição</option>
                  <option>PG</option><option>SG</option><option>SF</option><option>PF</option><option>C</option>
                </select></div>
                <div class="col-6 col-md-3"><select class="form-select" id="nvSec">
                  <option value="">2ª (opcional)</option>
                  <option>PG</option><option>SG</option><option>SF</option><option>PF</option><option>C</option>
                </select></div>
                <div class="col-6 col-md-3"><input class="form-control" id="nvOvr" type="number"
                  min="40" max="99" placeholder="OVR"></div>
                <div class="col-6 col-md-3"><input class="form-control" id="nvIdade" type="number"
                  min="16" max="45" placeholder="Idade"></div>
              </div>
              <button class="btn-r primary mt-3" onclick="criarJogador()">Criar e adicionar</button>
            </div>
          </div>
          <div class="alert alert-danger mt-3" id="addErro" style="display:none;font-size:13px"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- modal: dispensar -->
  <div class="modal fade" id="dpModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Dispensar</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p id="dpTexto" style="font-size:14px;margin:0"></p>
          <div class="alert alert-danger mt-3" id="dpErro" style="display:none;font-size:13px"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-r secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn-r primary" id="dpConfirma">Dispensar</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  const LIGA = <?= json_encode($ligaAtual) ?>;
  let DADOS = null, FILTRO = 'todos', ABERTOS = new Set(), mvAlvo = null, dpAlvo = null;

  /* A liga vai em toda chamada — no GET pela querystring, no POST pelo corpo.
     Sem isso a API cairia na liga do GM e um admin da ELITE conferindo a NEXT
     receberia "o time não é da ELITE" em cada movimento. */
  async function api(url, opts) {
    const sep = url.includes('?') ? '&' : '?';
    const u = '/api/revisao.php' + url + sep + 'liga=' + encodeURIComponent(LIGA);
    if (opts && opts.body) {
      const corpo = JSON.parse(opts.body);
      corpo.liga = LIGA;
      opts = { ...opts, body: JSON.stringify(corpo) };
    }
    const r = await fetch(u, opts);
    const d = await r.json().catch(() => ({}));
    if (!r.ok || d.success === false) throw new Error(d.error || 'Erro na requisição');
    return d;
  }
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const atr = s => esc(s).replace(/'/g, '&#39;');

  async function carregar(manterAbertos = true) {
    if (!manterAbertos) ABERTOS.clear();
    DADOS = await api('?action=tudo');
    desenhar();
  }

  /* ── BUSCA ────────────────────────────────────────────────────────
     Dois trabalhos no mesmo campo: filtrar os cards (instantâneo, local) e
     perguntar ao servidor onde está o jogador ou a pick. O segundo espera
     350ms de silêncio pra não disparar uma consulta por tecla. */
  let buscaTimer = null;
  function aoBuscar() {
    desenhar();
    clearTimeout(buscaTimer);
    buscaTimer = setTimeout(procurarNaLiga, 350);
  }

  async function procurarNaLiga() {
    const q = document.getElementById('busca').value.trim();
    const box = document.getElementById('achados');
    if (q.length < 2) { box.hidden = true; box.innerHTML = ''; return; }
    try {
      const d = await api('?action=buscar&q=' + encodeURIComponent(q));
      const nada = !d.jogadores.length && !d.picks.length;
      if (nada) { box.hidden = true; box.innerHTML = ''; return; }
      box.hidden = false;
      box.innerHTML =
        (d.jogadores.length ? `<div class="ac-h">Jogadores · ${d.jogadores.length}</div>` +
          d.jogadores.map(p => `
            <div class="ac-l">
              <span class="ln-ovr">${p.ovr}</span>
              <span class="ln-nome"><b>${esc(p.name)}</b>
                <small>${esc(p.position)}${p.secondary_position ? ' / ' + esc(p.secondary_position) : ''} · ${p.age}a</small></span>
              <span class="ac-onde">está no <b>${esc(p.time)}</b></span>
              <span class="ln-acoes">
                <button class="mini" onclick="abrirMover('jogador',${p.id},${p.team_id},'${atr(p.name)}')">Mover</button>
                <button class="mini perigo" onclick="abrirDispensa(${p.id},'${atr(p.name)}','${atr(p.time)}')">Dispensar</button>
              </span>
            </div>`).join('') : '') +
        (d.picks.length ? `<div class="ac-h">Picks · ${d.picks.length}</div>` +
          d.picks.map(k => `
            <div class="ac-l">
              <span class="ln-nome"><b>${k.season_year} · ${k.round}ª rodada</b>
                <small>${esc(k.origem)}${k.swap_type ? ' · <span class="tag">swap ' + esc(k.swap_type) + '</span>' : ''}</small></span>
              <span class="ac-onde">está com o <b>${esc(k.dono)}</b></span>
              <span class="ln-acoes">
                <button class="mini" onclick="abrirMover('pick',${k.id},${k.team_id},'${k.season_year} · ${k.round}ª (${atr(k.origem)})')">Enviar</button>
              </span>
            </div>`).join('') : '');
    } catch (e) { box.hidden = true; }
  }

  /* ── ADICIONAR JOGADOR ────────────────────────────────────────────── */
  let addTimeId = null;

  function abrirAdd(id, nome) {
    addTimeId = id;
    document.getElementById('addTime').textContent = 'em ' + nome;
    document.getElementById('addErro').style.display = 'none';
    document.getElementById('faBusca').value = '';
    ['nvNome','nvOvr','nvIdade'].forEach(i => document.getElementById(i).value = '');
    ['nvPos','nvSec'].forEach(i => document.getElementById(i).value = '');
    new bootstrap.Modal(document.getElementById('addModal')).show();
    carregarFa();
  }

  let faTimer = null;
  function carregarFa() {
    clearTimeout(faTimer);
    faTimer = setTimeout(async () => {
      const lista = document.getElementById('faLista');
      lista.innerHTML = '<div class="vazio">Carregando…</div>';
      try {
        const d = await api('?action=free_agents&q=' +
          encodeURIComponent(document.getElementById('faBusca').value.trim()));
        lista.innerHTML = d.fa.length ? d.fa.map(f => `
          <div class="ln">
            <span class="ln-ovr">${f.overall}</span>
            <span class="ln-nome"><b>${esc(f.name)}</b>
              <small>${esc(f.position)}${f.secondary_position ? ' / ' + esc(f.secondary_position) : ''} · ${f.age}a${
                f.original_team_name ? ' · saiu do ' + esc(f.original_team_name) : ''}</small></span>
            <span class="ln-acoes"><button class="mini verde" onclick="addFa(${f.id})">Adicionar</button></span>
          </div>`).join('') : '<div class="vazio">Ninguém livre com esse nome.</div>';
      } catch (e) { lista.innerHTML = `<div class="vazio">${esc(e.message)}</div>`; }
    }, 300);
  }

  async function addFa(faId) {
    const err = document.getElementById('addErro');
    err.style.display = 'none';
    try {
      await api('?action=add_fa', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ fa_id: faId, to_team_id: addTimeId }),
      });
      ABERTOS.add(addTimeId);
      bootstrap.Modal.getInstance(document.getElementById('addModal')).hide();
      await carregar();
    } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
  }

  async function criarJogador() {
    const err = document.getElementById('addErro');
    err.style.display = 'none';
    try {
      await api('?action=criar_jogador', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          to_team_id: addTimeId,
          name: document.getElementById('nvNome').value,
          position: document.getElementById('nvPos').value,
          secondary_position: document.getElementById('nvSec').value,
          ovr: Number(document.getElementById('nvOvr').value),
          age: Number(document.getElementById('nvIdade').value),
        }),
      });
      ABERTOS.add(addTimeId);
      bootstrap.Modal.getInstance(document.getElementById('addModal')).hide();
      await carregar();
    } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
  }

  function filtro(btn) {
    document.querySelectorAll('.rv-pill').forEach(b => b.classList.remove('on'));
    btn.classList.add('on');
    FILTRO = btn.dataset.f;
    desenhar();
  }

  function desenhar() {
    if (!DADOS) return;
    const q = (document.getElementById('busca').value || '').trim().toLowerCase();
    const ok = DADOS.times.filter(t => t.ok_em).length;
    document.getElementById('pgN').textContent = ok;
    document.getElementById('pgT').textContent = `de ${DADOS.times.length} confirmados`;

    const lista = DADOS.times.filter(t => {
      if (FILTRO === 'ok' && !t.ok_em) return false;
      if (FILTRO === 'pend' && t.ok_em) return false;
      if (FILTRO === 'torto' && !t.pendencias.length) return false;
      if (!q) return true;
      return t.nome.toLowerCase().includes(q)
          || (t.gm || '').toLowerCase().includes(q)
          || t.elenco.some(p => p.name.toLowerCase().includes(q));
    });

    document.getElementById('lista').innerHTML = lista.length
      ? lista.map(cardTime).join('')
      : '<div class="vazio" style="padding:26px;text-align:center">Nenhum time com esse filtro.</div>';
  }

  function cardTime(t) {
    const estado = t.pendencias.length ? 'torto' : (t.ok_em ? 'ok' : 'pend');
    /* O OK fica na linha. Dentro de um <summary> o clique abriria o card,
       por isso o stopPropagation/preventDefault no onclick. */
    const selo = estado === 'ok'
      ? '<span class="tm-sel ok"><i class="bi bi-check2-circle"></i> Confirmado</span>'
      : (estado === 'torto'
          ? '<span class="tm-sel torto">Irregular</span>'
          : `<button class="tm-ok-btn" title="Marcar que este time está certo"
                     onclick="event.preventDefault();event.stopPropagation();confirmarTime(${t.id})">
               <i class="bi bi-check2"></i> OK</button>`);
    const capAlerta = (t.cap > DADOS.cap_max || t.cap < DADOS.cap_min) ? ' alerta' : '';
    const jogAlerta = (t.qtd > 15 || t.qtd < 13) ? ' alerta' : '';

    return `
    <details class="tm ${estado}" data-id="${t.id}"${ABERTOS.has(t.id) ? ' open' : ''}
             ontoggle="ABERTOS[this.open?'add':'delete'](${t.id})">
      <summary>
        <i class="bi bi-chevron-right tm-seta"></i>
        <span class="tm-nome">
          <b>${esc(t.nome)}</b>
          <small>${t.gm ? esc(t.gm) : 'sem GM'}</small>
        </span>
        <span class="tm-nums">
          <span class="tm-n${jogAlerta}"><b>${t.qtd}</b><span>Jog</span></span>
          <span class="tm-n${capAlerta}"><b>${t.cap}${DADOS.unidade}</b><span>Cap</span></span>
        </span>
        ${selo}
      </summary>

      <div class="tm-corpo">
        ${t.pendencias.length ? `<div class="tm-pend">
            <i class="bi bi-exclamation-triangle-fill"></i>${esc(t.pendencias.join(' · '))}
          </div>` : ''}

        <div class="tm-cols">
          <div>
            <div class="tm-h">
              <span>Elenco</span>
              <span style="display:flex;align-items:center;gap:9px">${t.qtd} jogadores
                <button class="mini" onclick="abrirAdd(${t.id},'${atr(t.nome)}')">
                  <i class="bi bi-plus-lg"></i> Adicionar</button></span>
            </div>
            ${t.elenco.length ? t.elenco.map(p => `
              <div class="ln">
                <span class="ln-ovr">${p.ovr}</span>
                <span class="ln-nome">
                  <b>${esc(p.name)}</b>
                  <small>${esc(p.position)}${p.secondary_position ? ' / ' + esc(p.secondary_position) : ''}
                    · ${p.age}a · <span class="tag">${esc(p.role)}</span></small>
                </span>
                <span class="ln-acoes">
                  <button class="mini" onclick="abrirMover('jogador',${p.id},${t.id},'${atr(p.name)}')">Mover</button>
                  <button class="mini perigo" onclick="abrirDispensa(${p.id},'${atr(p.name)}','${atr(t.nome)}')">Dispensar</button>
                </span>
              </div>`).join('') : '<div class="vazio">Sem jogadores.</div>'}
          </div>

          <div>
            <div class="tm-h"><span>Picks${DADOS.ano ? ' · de ' + DADOS.ano + ' em diante' : ''}</span>
              <span>${t.picks.length}</span></div>
            ${t.picks.length ? t.picks.map(k => `
              <div class="ln">
                <span class="ln-nome">
                  <b>${k.season_year} · ${k.round}ª rodada</b>
                  <small>${esc(k.origem)}${k.swap_type ? ' · <span class="tag">swap ' + esc(k.swap_type) + '</span>' : ''}</small>
                </span>
                <span class="ln-acoes">
                  <button class="mini" onclick="abrirMover('pick',${k.id},${t.id},'${k.season_year} · ${k.round}ª (${atr(k.origem)})')">Enviar</button>
                </span>
              </div>`).join('') : '<div class="vazio">Sem picks daqui pra frente.</div>'}
          </div>
        </div>

        <div class="tm-rodape">
          ${t.ok_em
            ? `<span style="font-size:12.5px;color:#4ade80">Confirmado em ${esc(String(t.ok_em).replace('T',' ').slice(0,16))}</span>`
            : (t.pendencias.length
                ? '<span style="font-size:12.5px;color:var(--text-2,#9aa)">Arrume as pendências pra poder confirmar.</span>'
                : `<button class="mini verde" onclick="confirmarTime(${t.id})">
                     <i class="bi bi-check2-circle"></i> Está certo, pode confirmar</button>`)}
        </div>
      </div>
    </details>`;
  }

  // ── mover ──────────────────────────────────────────────────────
  function abrirMover(tipo, id, origemId, rotulo) {
    mvAlvo = { tipo, id, origemId };
    document.getElementById('mvTitulo').textContent = tipo === 'jogador' ? 'Mover jogador' : 'Enviar pick';
    document.getElementById('mvOque').textContent =
      (tipo === 'jogador' ? 'Mandando ' : 'Mandando a pick ') + rotulo + ' para:';
    document.getElementById('mvErro').style.display = 'none';
    document.getElementById('mvTime').innerHTML = DADOS.times
      .filter(t => Number(t.id) !== Number(origemId))
      .map(t => `<option value="${t.id}">${esc(t.nome)}</option>`).join('');
    new bootstrap.Modal(document.getElementById('mvModal')).show();
  }

  document.getElementById('mvConfirma').addEventListener('click', async () => {
    const btn = document.getElementById('mvConfirma'), err = document.getElementById('mvErro');
    const destino = Number(document.getElementById('mvTime').value);
    btn.disabled = true;
    try {
      const acao = mvAlvo.tipo === 'jogador' ? 'mover_jogador' : 'mover_pick';
      const corpo = mvAlvo.tipo === 'jogador'
        ? { player_id: mvAlvo.id, to_team_id: destino }
        : { pick_id: mvAlvo.id, to_team_id: destino };
      await api('?action=' + acao, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo),
      });
      ABERTOS.add(destino);
      bootstrap.Modal.getInstance(document.getElementById('mvModal')).hide();
      await carregar();
    } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
    finally { btn.disabled = false; }
  });

  // ── dispensar ──────────────────────────────────────────────────
  function abrirDispensa(id, nome, time) {
    dpAlvo = id;
    document.getElementById('dpTexto').innerHTML =
      `<b>${esc(nome)}</b> sai do ${esc(time)} e vai pra free agency. Isso não volta atrás.`;
    document.getElementById('dpErro').style.display = 'none';
    new bootstrap.Modal(document.getElementById('dpModal')).show();
  }

  document.getElementById('dpConfirma').addEventListener('click', async () => {
    const btn = document.getElementById('dpConfirma'), err = document.getElementById('dpErro');
    btn.disabled = true;
    try {
      await api('?action=dispensar', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: dpAlvo }),
      });
      bootstrap.Modal.getInstance(document.getElementById('dpModal')).hide();
      await carregar();
    } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
    finally { btn.disabled = false; }
  });

  // ── confirmar ──────────────────────────────────────────────────
  async function confirmarTime(id) {
    try {
      await api('?action=ok', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ team_id: id }),
      });
      await carregar();
    } catch (e) {
      const t = DADOS.times.find(x => Number(x.id) === Number(id));
      alert(e.message + (t ? '\n\n' + t.nome : ''));
    }
  }

  carregar().catch(e => {
    document.getElementById('lista').innerHTML =
      `<div class="vazio" style="padding:26px;text-align:center">${esc(e.message)}</div>`;
  });
  </script>
</body>
</html>
