<?php
/**
 * Os playoffs da ELITE, temporada a temporada.
 *
 * A tabela das séries existia desde sempre, mas só era preenchida de carona:
 * quem registrava a pontuação da temporada no admin tinha ali um campo de
 * chaveamento, e quem pulava deixava o buraco. Vinte e cinco temporadas de
 * ELITE, duas com chaveamento — o resto da história só existia na memória de
 * quem jogou.
 *
 * Por isso esta tela não é só de leitura: onde falta, quem administra a liga
 * preenche na hora, no mesmo lugar em que olhou e viu que faltava. Mandar o
 * admin para outra tela seria garantir que ninguém preenchesse.
 *
 * O placar da série sai do número de jogos (melhor de 7: 6 jogos é 4-2), e essa
 * regra mora em backend/playoff_series.php. Os dados vêm de api/playoffs.php.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

$LIGA = 'ELITE';
$podeEditar = in_array($LIGA, array_map('strtoupper', getAdminLeagues($pdo, (int)$user['id'])), true);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Playoffs ELITE - FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
    /* Os mesmos tokens das outras telas — a lista tem que vir completa porque o
       shell usa --sidebar-w, --ease e --radius-sm. */
    :root{
      --red:#fc0025;
      --red-2:color-mix(in srgb, var(--red) 85%, white);
      --red-soft:color-mix(in srgb, var(--red) 10%, transparent);
      --red-glow:color-mix(in srgb, var(--red) 18%, transparent);
      --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
      --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
      --border-red:color-mix(in srgb, var(--red) 22%, transparent);
      --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
      --green:#22c55e; --amber:#f59e0b; --blue:#3b82f6; --gold:#e5b567;
      --sidebar-w:260px;
      --font:'Montserrat',sans-serif;
      --radius:14px; --radius-sm:10px; --radius-xs:6px;
      --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
    }
    :root[data-theme="light"]{
      --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
      --border:#e3e6ee; --border-md:#d7dbe6; --text:#12141a; --text-2:#5b6172; --text-3:#6b7080;
      --gold:#a37b28;
    }
    </style>

    <?php include __DIR__ . '/includes/shell-css.php'; ?>

    <style>
    .panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;margin-bottom:16px}

    /* ── Filtros ─────────────────────────────────────────────── */
    .po-filtros{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .po-chip{background:var(--panel-2);border:1px solid var(--border);color:var(--text-2);
             border-radius:999px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;
             transition:all var(--t) var(--ease)}
    .po-chip:hover{color:var(--text);border-color:var(--border-md)}
    .po-chip.on{background:var(--red-soft);border-color:var(--border-red);color:var(--red-2)}
    .po-filtros .sep{flex:1}

    /* ── Card da temporada ───────────────────────────────────── */
    .po-temp{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
             margin-bottom:12px;overflow:hidden}
    .po-temp.vazia{border-style:dashed}
    .po-head{display:flex;gap:12px;align-items:center;justify-content:space-between;
             padding:14px 16px;cursor:pointer;flex-wrap:wrap}
    .po-head:hover{background:var(--panel-2)}
    .po-titulo{font-family:'Oswald',var(--font);font-size:17px;font-weight:700;letter-spacing:.4px;
               display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .po-era{font-family:var(--font);font-size:10px;font-weight:700;letter-spacing:.6px;
            background:var(--panel-3);border:1px solid var(--border);color:var(--text-3);
            border-radius:999px;padding:2px 8px}
    .po-sub{font-size:12px;color:var(--text-2);margin-top:3px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
    .po-sub .campeao{color:var(--gold);font-weight:700}
    .po-sub .falta{color:var(--amber);font-weight:700}
    .po-acoes{display:flex;gap:6px;align-items:center;flex-shrink:0}
    .po-btn{background:var(--panel-2);border:1px solid var(--border);color:var(--text-2);
            border-radius:var(--radius-xs);padding:6px 12px;font-size:12px;font-weight:700;
            cursor:pointer;white-space:nowrap;transition:all var(--t) var(--ease)}
    .po-btn:hover{color:var(--text);border-color:var(--border-md)}
    .po-btn.destaque{background:var(--red-soft);border-color:var(--border-red);color:var(--red-2)}
    .po-btn.ok{background:color-mix(in srgb,var(--green) 12%,transparent);
               border-color:color-mix(in srgb,var(--green) 30%,transparent);color:var(--green)}
    .po-corpo{border-top:1px solid var(--border);padding:14px 16px}

    /* ── Chaveamento ─────────────────────────────────────────── */
    .po-conf{margin-bottom:14px}
    .po-conf:last-child{margin-bottom:0}
    .po-conf-nome{font-size:10px;font-weight:800;letter-spacing:1.2px;color:var(--text-3);
                  text-transform:uppercase;margin-bottom:8px;padding-bottom:5px;
                  border-bottom:1px solid var(--border)}
    .po-fase{margin-bottom:10px}
    .po-fase-nome{font-size:10px;font-weight:700;letter-spacing:.8px;color:var(--text-3);
                  text-transform:uppercase;margin-bottom:5px}
    .po-serie{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:8px;
              background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-xs);
              padding:8px 10px;margin-bottom:5px}
    .po-serie .t{font-size:13px;color:var(--text-2);display:flex;align-items:center;gap:6px;min-width:0}
    .po-serie .t.dir{justify-content:flex-end;text-align:right}
    .po-serie .t .nome{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .po-serie .t.venceu .nome{color:var(--text);font-weight:700}
    .po-serie .seed{font-size:10px;color:var(--text-3);font-variant-numeric:tabular-nums;flex-shrink:0}
    .po-placar{font-family:'Oswald',var(--font);font-size:15px;font-weight:700;color:var(--text);
               font-variant-numeric:tabular-nums;white-space:nowrap;padding:0 4px}
    .po-final{background:linear-gradient(180deg,color-mix(in srgb,var(--gold) 10%,transparent),transparent);
              border:1px solid color-mix(in srgb,var(--gold) 26%,transparent);border-radius:var(--radius-sm);
              padding:12px;margin-top:4px}
    .po-final .po-conf-nome{color:var(--gold);border-bottom-color:color-mix(in srgb,var(--gold) 20%,transparent)}
    .po-final .po-serie{background:transparent;border:none;padding:4px 2px;margin:0}
    .po-final .po-placar{font-size:19px}
    .po-vazio{color:var(--text-3);font-size:13px;text-align:center;padding:14px 8px}
    .po-aviso{display:flex;gap:8px;align-items:flex-start;font-size:12px;color:var(--amber);
              background:color-mix(in srgb,var(--amber) 8%,transparent);
              border:1px solid color-mix(in srgb,var(--amber) 22%,transparent);
              border-radius:var(--radius-xs);padding:8px 10px;margin-bottom:12px}

    /* ── Editor ──────────────────────────────────────────────── */
    .po-ed-linha{display:grid;grid-template-columns:1fr 1fr 1.1fr .8fr;gap:6px;margin-bottom:6px;align-items:center}
    .po-ed-linha select{background:var(--panel-2);border:1px solid var(--border);color:var(--text);
                        border-radius:var(--radius-xs);padding:7px 8px;font-size:12px;font-family:var(--font);
                        width:100%;min-width:0}
    .po-ed-linha select:focus{outline:2px solid var(--border-red);outline-offset:1px}
    .po-ed-linha select.vazio{color:var(--text-3)}
    .po-ed-cab{display:grid;grid-template-columns:1fr 1fr 1.1fr .8fr;gap:6px;margin-bottom:5px;
               font-size:10px;font-weight:700;letter-spacing:.6px;color:var(--text-3);text-transform:uppercase}
    .po-ed-rodape{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap;
                  border-top:1px solid var(--border);padding-top:12px;margin-top:12px}
    .po-ed-msg{font-size:12px;color:var(--text-2);margin-right:auto}
    .po-ed-msg.erro{color:var(--red-2)}
    .po-ed-msg.ok{color:var(--green)}
    .po-ed-dica{font-size:12px;color:var(--text-3);margin-bottom:12px;line-height:1.5}

    .po-carregando{text-align:center;padding:40px 0;color:var(--text-3);font-size:13px}

    /* O aviso de "salvou". A confirmação não cabe no card: ele é redesenhado
       no mesmo instante, com o chaveamento novo, e a mensagem sumiria junto
       com o formulário que a mostrou. */
    .po-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(10px);
              background:var(--panel-3);border:1px solid var(--border-md);color:var(--text);
              border-radius:999px;padding:10px 18px;font-size:13px;font-weight:600;z-index:90;
              box-shadow:0 10px 30px rgba(0,0,0,.35);opacity:0;pointer-events:none;
              transition:opacity var(--t) var(--ease),transform var(--t) var(--ease)}
    .po-toast.on{opacity:1;transform:translateX(-50%) translateY(0)}
    .po-toast.ok{border-color:color-mix(in srgb,var(--green) 40%,transparent);color:var(--green)}
    .po-toast.erro{border-color:var(--border-red);color:var(--red-2)}

    @media (max-width: 720px){
      .po-ed-cab{display:none}
      .po-ed-linha{grid-template-columns:1fr 1fr;gap:5px;padding:8px;margin-bottom:8px;
                   background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-xs)}
      .po-serie{grid-template-columns:1fr auto 1fr;gap:5px;padding:7px 8px}
      .po-serie .t{font-size:12px;align-items:flex-start}
      /* Na tela do celular o nome inteiro vale mais que a linha única:
         "Bed-Stuy Alley D…" contra "Pittsburgh Phanto…" não diz quem jogou. */
      .po-serie .t .nome{white-space:normal;overflow:visible;line-height:1.25}
      .po-serie .seed{margin-top:1px}
      .po-head{padding:12px 13px}
      .po-corpo{padding:12px 13px}
      .po-acoes{width:100%}
      .po-btn{flex:1}
    }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Playoffs</em></div>
</header>

<main class="main">
    <div class="dash-hero">
        <div class="dash-hero-label">ELITE</div>
        <h1 class="dash-hero-title">Playoffs</h1>
        <p class="dash-hero-sub" id="poResumo">Carregando o histórico…</p>
    </div>

    <div class="panel po-filtros" id="poFiltros" style="display:none"></div>

    <div id="poLista"><div class="po-carregando">Carregando…</div></div>
</main>

<script>
const PODE_EDITAR = <?= $podeEditar ? 'true' : 'false' ?>;
const LIGA = <?= json_encode($LIGA) ?>;

let DADOS = null;          // resposta da API
let filtroEra = 'todas';
let soFaltando = false;
const abertas = new Set(); // temporadas expandidas
const editando = new Set();// temporadas em modo de edição

const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
  ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

const nomeTime = id => (DADOS?.times?.[id]?.nome) || (id ? 'Time #' + id : '—');

/* O placar sai do número de jogos: melhor de 7, o vencedor sempre faz 4.
   A mesma conta de playoffPlacarPorJogos() no PHP — aqui só pra desenhar. */
function placar(jogos) {
  const precisa = Math.floor((DADOS?.melhor_de ?? 7) / 2) + 1;
  if (jogos < precisa || jogos > (DADOS?.melhor_de ?? 7)) return null;
  return [precisa, jogos - precisa];
}

/* ── Leitura ──────────────────────────────────────────────────── */

function serieHtml(s, seeds) {
  const p = placar(s.jogos) || ['?', '?'];
  const aVenceu = s.vencedor === s.a;
  const [ea, eb] = aVenceu ? p : [p[1], p[0]];
  const seed = id => seeds[id] ? `<span class="seed">${seeds[id]}º</span>` : '';
  return `
    <div class="po-serie">
      <div class="t ${aVenceu ? 'venceu' : ''}">${seed(s.a)}<span class="nome">${esc(nomeTime(s.a))}</span></div>
      <div class="po-placar">${ea} <span style="color:var(--text-3);font-size:12px">x</span> ${eb}</div>
      <div class="t dir ${aVenceu ? '' : 'venceu'}"><span class="nome">${esc(nomeTime(s.b))}</span>${seed(s.b)}</div>
    </div>`;
}

function chaveamentoHtml(t) {
  if (!t.series.length) {
    return `<div class="po-vazio">
      <i class="bi bi-diagram-2" style="font-size:22px;display:block;margin-bottom:6px;opacity:.5"></i>
      Nenhuma série registrada nesta temporada.
      ${PODE_EDITAR ? 'Use o <strong>Preencher</strong> aí em cima.' : ''}
    </div>`;
  }

  const seeds = {};
  (t.participantes || []).forEach(p => { if (p.seed) seeds[p.id] = p.seed; });

  const fases = DADOS.fases;
  let html = avisoDivergenciaHtml(t);

  for (const conf of ['LESTE', 'OESTE']) {
    const doConf = t.series.filter(s => s.conferencia === conf);
    if (!doConf.length) continue;
    html += `<div class="po-conf"><div class="po-conf-nome">${conf}</div>`;
    for (const [chave, rotulo] of Object.entries(fases)) {
      if (chave === 'final') continue;
      const daFase = doConf.filter(s => s.fase === chave);
      if (!daFase.length) continue;
      html += `<div class="po-fase"><div class="po-fase-nome">${esc(rotulo)}</div>
               ${daFase.map(s => serieHtml(s, seeds)).join('')}</div>`;
    }
    html += `</div>`;
  }

  // Série sem conferência que não é a final (chaveamento antigo, ou liga que
  // não usa conferência): não pode sumir da tela só por não se encaixar.
  const soltas = t.series.filter(s => s.fase !== 'final' && !['LESTE','OESTE'].includes(s.conferencia));
  if (soltas.length) {
    html += `<div class="po-conf"><div class="po-conf-nome">Sem conferência</div>
             ${soltas.map(s => serieHtml(s, seeds)).join('')}</div>`;
  }

  const final = t.series.filter(s => s.fase === 'final');
  if (final.length) {
    html += `<div class="po-final"><div class="po-conf-nome">${esc(fases.final)}</div>
             ${final.map(s => serieHtml(s, seeds)).join('')}</div>`;
  }
  return html;
}

/* O chaveamento e o registro da temporada são tabelas diferentes, preenchidas
   em momentos diferentes. Quando discordam, quem está lendo precisa saber —
   e nenhuma das duas é corrigida daqui: pontuação registrada é assunto do
   admin da temporada. */
function avisoDivergenciaHtml(t) {
  const oficial = (t.podio?.champion || [])[0];
  const final = t.series.find(s => s.fase === 'final');
  if (!oficial || !final || final.vencedor === oficial) return '';
  return `<div class="po-aviso"><i class="bi bi-exclamation-triangle-fill"></i>
    <div>O registro da temporada aponta <strong>${esc(nomeTime(oficial))}</strong> como campeão,
    e a grande final aqui deu <strong>${esc(nomeTime(final.vencedor))}</strong>. Um dos dois está errado.</div>
  </div>`;
}

/* ── Editor ───────────────────────────────────────────────────── */

/* As quinze séries de um chaveamento de 16 times, na ordem em que acontecem.
   São linhas fixas de propósito: o admin preenche o que sabe e deixa o resto
   em branco, e linha incompleta é simplesmente ignorada ao salvar. */
function linhasDoEditor() {
  const linhas = [];
  for (const conf of ['LESTE', 'OESTE']) {
    for (let i = 0; i < 4; i++) linhas.push({ fase: 'r1', conf });
    for (let i = 0; i < 2; i++) linhas.push({ fase: 'r2', conf });
    linhas.push({ fase: 'cf', conf });
  }
  linhas.push({ fase: 'final', conf: null });
  return linhas;
}

function opcoesTimes(t, conf, escolhido) {
  const daTemporada = (t.participantes || []).map(p => p.id);
  const vistos = new Set(daTemporada);
  (t.series || []).forEach(s => { vistos.add(s.a); vistos.add(s.b); });

  // Os times da temporada primeiro (é quem pode ter jogado aquele playoff);
  // o resto da liga entra depois, pra temporada sem classificação lançada.
  const outros = Object.keys(DADOS.times).map(Number).filter(id => !vistos.has(id));
  const confDe = id => (t.participantes || []).find(p => p.id === id)?.conf
                    || DADOS.times[id]?.conf || null;

  const grupo = (rotulo, ids) => {
    if (!ids.length) return '';
    const opts = ids
      .sort((a, b) => nomeTime(a).localeCompare(nomeTime(b), 'pt-BR'))
      .map(id => `<option value="${id}" ${id === escolhido ? 'selected' : ''}>${esc(nomeTime(id))}</option>`)
      .join('');
    return `<optgroup label="${esc(rotulo)}">${opts}</optgroup>`;
  };

  const doConf = [...vistos].filter(id => conf && confDe(id) === conf);
  const resto  = [...vistos].filter(id => !doConf.includes(id));

  return `<option value="">— time —</option>`
       + grupo(conf ? conf : 'Da temporada', doConf.length ? doConf : resto)
       + (doConf.length ? grupo('Outros da temporada', resto) : '')
       + grupo('Outros times da liga', outros);
}

function editorHtml(t) {
  const fases = DADOS.fases;
  const existentes = {};
  (t.series || []).forEach(s => {
    const chave = s.fase + '|' + (s.conferencia || '');
    (existentes[chave] = existentes[chave] || []).push(s);
  });
  const usados = {};

  let html = `<div class="po-ed-dica">
    Preencha o que souber e salve — linha sem os dois times, sem vencedor ou sem placar é ignorada.
    O placar é o da série: numa melhor de ${DADOS.melhor_de}, o vencedor sempre chega a
    ${Math.floor(DADOS.melhor_de / 2) + 1} vitórias.
  </div>`;

  html += `<div class="po-ed-cab">
      <div>Time A</div><div>Time B</div><div>Quem passou</div><div>Placar</div>
    </div>`;

  let confAtual = 'nenhuma';
  let faseAtual = null;
  linhasDoEditor().forEach(l => {
    if (l.conf !== confAtual) {
      if (confAtual !== 'nenhuma') html += `</div>`;
      confAtual = l.conf;
      faseAtual = null;
      html += `<div class="po-conf"><div class="po-conf-nome">${l.conf || esc(fases.final)}</div>`;
    }
    if (l.conf && l.fase !== faseAtual) {
      faseAtual = l.fase;
      html += `<div class="po-fase-nome">${esc(fases[l.fase])}</div>`;
    }

    const chave = l.fase + '|' + (l.conf || '');
    usados[chave] = (usados[chave] || 0);
    const s = (existentes[chave] || [])[usados[chave]++] || {};

    html += `
      <div class="po-ed-linha" data-fase="${l.fase}" data-conf="${l.conf || ''}">
        <select class="po-a" onchange="poSincronizar(this)">${opcoesTimes(t, l.conf, s.a)}</select>
        <select class="po-b" onchange="poSincronizar(this)">${opcoesTimes(t, l.conf, s.b)}</select>
        <select class="po-v" data-vencedor="${s.vencedor || ''}"></select>
        <select class="po-j">
          <option value="">— placar —</option>
          ${[4,5,6,7].map(j => {
            const p = placar(j);
            return `<option value="${j}" ${s.jogos === j ? 'selected' : ''}>${p[0]}-${p[1]}</option>`;
          }).join('')}
        </select>
      </div>`;
  });
  html += `</div>`;

  html += `<div class="po-ed-rodape">
      <div class="po-ed-msg" id="poMsg-${t.id}"></div>
      <button class="po-btn" onclick="poCancelar(${t.id})">Cancelar</button>
      <button class="po-btn destaque" onclick="poSalvar(${t.id})">Salvar chaveamento</button>
    </div>`;
  return html;
}

/* O select de "quem passou" só pode oferecer os dois times da própria linha —
   é a trava que impede gravar um terceiro time como vencedor de uma série que
   ele não jogou. Roda a cada mudança dos dois selects de time. */
function poSincronizar(alvo) {
  const linha = alvo.closest('.po-ed-linha');
  const a = linha.querySelector('.po-a').value;
  const b = linha.querySelector('.po-b').value;
  const v = linha.querySelector('.po-v');
  const antes = v.value || v.dataset.vencedor || '';

  v.innerHTML = `<option value="">— quem passou —</option>`
    + [a, b].filter(Boolean).filter((id, i, arr) => arr.indexOf(id) === i)
        .map(id => `<option value="${id}" ${String(antes) === String(id) ? 'selected' : ''}>${esc(nomeTime(Number(id)))}</option>`)
        .join('');
}

function poCancelar(id) { editando.delete(id); render(); }

let toastTimer = null;
function poToast(texto, tipo) {
  let el = document.getElementById('poToast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'poToast';
    document.body.appendChild(el);
  }
  el.className = 'po-toast ' + (tipo || '');
  el.textContent = texto;
  requestAnimationFrame(() => el.classList.add('on'));
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('on'), 3200);
}

async function poSalvar(id) {
  const card = document.querySelector(`[data-temp="${id}"]`);
  const msg  = document.getElementById('poMsg-' + id);
  const series = [];

  card.querySelectorAll('.po-ed-linha').forEach(l => {
    const a = Number(l.querySelector('.po-a').value || 0);
    const b = Number(l.querySelector('.po-b').value || 0);
    const v = Number(l.querySelector('.po-v').value || 0);
    const j = Number(l.querySelector('.po-j').value || 0);
    if (!a || !b || !v || !j) return;            // linha em branco: sem drama
    series.push({ fase: l.dataset.fase, conferencia: l.dataset.conf || null,
                  team_a_id: a, team_b_id: b, winner_team_id: v, jogos: j });
  });

  msg.className = 'po-ed-msg';
  msg.textContent = 'Salvando…';
  try {
    const r = await fetch('/api/playoffs.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ acao: 'salvar', liga: LIGA, season_id: id, series }),
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Não deu pra salvar.');

    editando.delete(id);
    abertas.add(id);
    await carregar();
    poToast(d.salvas === 1 ? '1 série salva.' : `${d.salvas} séries salvas.`, 'ok');
    // Linha que o servidor recusou é a que o admin precisa ver: sem isto ele
    // sai da tela achando que gravou as quinze.
    if (d.ignoradas?.length) poToast(`${d.ignoradas.length} linha(s) não entraram: ${d.ignoradas[0]}`, 'erro');
  } catch (e) {
    msg.className = 'po-ed-msg erro';
    msg.textContent = e.message || 'Erro de rede.';
  }
}

/* ── Tela ─────────────────────────────────────────────────────── */

function resumoDaTemporada(t) {
  const final = t.series.find(s => s.fase === 'final');
  if (final) {
    const p = placar(final.jogos);
    const perdedor = final.vencedor === final.a ? final.b : final.a;
    return `<span class="campeao"><i class="bi bi-trophy-fill"></i> ${esc(nomeTime(final.vencedor))}</span>
            <span style="color:var(--text-3)">venceu ${esc(nomeTime(perdedor))} por ${p[0]}-${p[1]}</span>`;
  }
  const oficial = (t.podio?.champion || [])[0];
  if (t.series.length) {
    return `<span>${t.series.length === 1 ? '1 série registrada' : t.series.length + ' séries registradas'}</span>
            <span class="falta">· falta a grande final</span>`;
  }
  if (oficial) {
    return `<span class="campeao"><i class="bi bi-trophy-fill"></i> ${esc(nomeTime(oficial))}</span>
            <span class="falta">· sem o chaveamento</span>`;
  }
  return `<span class="falta">Nada registrado</span>`;
}

function tempHtml(t) {
  const aberta = abertas.has(t.id);
  const emEdicao = editando.has(t.id);
  const vazia = !t.series.length;

  return `
    <section class="po-temp ${vazia ? 'vazia' : ''}" data-temp="${t.id}">
      <div class="po-head" onclick="poAlternar(${t.id}, event)">
        <div style="min-width:0">
          <div class="po-titulo">
            Temporada ${t.numero}
            ${t.era ? `<span class="po-era">Era ${t.era}</span>` : ''}
            ${t.status !== 'completed' ? `<span class="po-era" style="color:var(--green)">em andamento</span>` : ''}
          </div>
          <div class="po-sub">${resumoDaTemporada(t)}</div>
        </div>
        <div class="po-acoes">
          ${PODE_EDITAR ? `<button class="po-btn ${vazia ? 'destaque' : ''}"
             onclick="poEditar(${t.id}, event)">${vazia ? 'Preencher' : 'Editar'}</button>` : ''}
          <button class="po-btn" onclick="poAlternar(${t.id}, event)">
            <i class="bi bi-chevron-${aberta ? 'up' : 'down'}"></i>
          </button>
        </div>
      </div>
      ${aberta || emEdicao ? `<div class="po-corpo">${emEdicao ? editorHtml(t) : chaveamentoHtml(t)}</div>` : ''}
    </section>`;
}

function poAlternar(id, ev) {
  if (ev) ev.stopPropagation();
  if (editando.has(id)) return;      // no meio de uma edição, o clique não fecha
  abertas.has(id) ? abertas.delete(id) : abertas.add(id);
  render();
}

function poEditar(id, ev) {
  if (ev) ev.stopPropagation();
  editando.add(id);
  abertas.add(id);
  render();
}

function render() {
  const lista = document.getElementById('poLista');
  let temps = DADOS.temporadas;
  if (filtroEra !== 'todas') temps = temps.filter(t => String(t.era) === filtroEra);
  if (soFaltando) temps = temps.filter(t => !t.series.some(s => s.fase === 'final'));

  lista.innerHTML = temps.length
    ? temps.map(tempHtml).join('')
    : `<div class="panel po-vazio">Nenhuma temporada com esse filtro.</div>`;

  // Os selects de vencedor dependem dos dois selects de time, então só dá pra
  // montá-los depois que a linha existe no documento.
  lista.querySelectorAll('.po-ed-linha .po-a').forEach(poSincronizar);
}

function renderFiltros() {
  const eras = [...new Set(DADOS.temporadas.map(t => t.era).filter(e => e !== null))].sort((a, b) => b - a);
  const caixa = document.getElementById('poFiltros');
  caixa.style.display = '';
  caixa.innerHTML =
      `<button class="po-chip ${filtroEra === 'todas' ? 'on' : ''}" onclick="poFiltrarEra('todas')">Todas</button>`
    + eras.map((e, i) => `<button class="po-chip ${filtroEra === String(e) ? 'on' : ''}"
         onclick="poFiltrarEra('${e}')">Era ${e}${i === 0 ? ' (atual)' : ''}</button>`).join('')
    + `<div class="sep"></div>`
    + `<button class="po-chip ${soFaltando ? 'on' : ''}" onclick="poSoFaltando()">
         <i class="bi bi-funnel"></i> Só as que faltam</button>`;
}

function poFiltrarEra(e) { filtroEra = String(e); renderFiltros(); render(); }
function poSoFaltando()  { soFaltando = !soFaltando; renderFiltros(); render(); }

function renderResumo() {
  const total = DADOS.temporadas.length;
  const comFinal = DADOS.temporadas.filter(t => t.series.some(s => s.fase === 'final')).length;
  const falta = total - comFinal;
  document.getElementById('poResumo').innerHTML =
    `${total} temporada${total === 1 ? '' : 's'} de ${LIGA} · <strong>${comFinal}</strong> com o chaveamento`
    + (falta ? ` · <strong style="color:var(--amber)">${falta}</strong> a preencher` : ' · tudo registrado');
}

async function carregar() {
  try {
    const r = await fetch('/api/playoffs.php?liga=' + encodeURIComponent(LIGA));
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Falha ao carregar');
    DADOS = d;
    renderResumo();
    renderFiltros();
    render();
  } catch (e) {
    document.getElementById('poLista').innerHTML =
      `<div class="panel po-vazio">Não deu pra carregar os playoffs. ${esc(e.message || '')}</div>`;
  }
}

carregar();
</script>

<script src="/js/sidebar.js"></script>
<script src="/js/tema.js"></script>
</body>
</html>
