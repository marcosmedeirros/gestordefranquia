<?php
/**
 * Calendário das ligas.
 *
 * Todo mundo vê; quem administra uma liga marca eventos dela na mesma tela.
 * Não existe página separada de administração de propósito: marcar evento é
 * clicar no dia, e uma tela paralela só pra isso viraria uma segunda versão do
 * calendário pra manter.
 *
 * Por padrão abre na liga do usuário. As outras entram pelo filtro.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/calendario.php';
requireAuth();

$user = getUserSession();
$pdo = db();
ensureCalendarioTables($pdo);

$minhaLiga = strtoupper(trim((string)($user['league'] ?? '')));
if (!in_array($minhaLiga, CALENDARIO_LIGAS, true)) $minhaLiga = CALENDARIO_LIGAS[0];

$ligasAdmin = array_values(array_intersect(
    array_map('strtoupper', getAdminLeagues($pdo, (int)$user['id'])),
    CALENDARIO_LIGAS
));
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
    <title>Calendário - FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php /* Oswald entra por causa do .section-title, que usa ela nas outras telas. */ ?>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
    /* Os mesmos tokens das outras telas. A lista precisa ficar COMPLETA: o
       shell usa --sidebar-w, --ease e --radius-sm, e sem eles a barra lateral
       perde a largura e vira uma faixa ocupando a tela toda. */
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

    <?php /* Barra lateral, topbar, main e hero — o mesmo shell das outras telas. */ ?>
    <?php include __DIR__ . '/includes/shell-css.php'; ?>

    <style>

    /* Caixa e título de seção, iguais aos de cap.php e das demais telas. */
    .panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px}
    .section-title{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;
      letter-spacing:.8px;color:var(--text-2);margin:22px 0 12px;display:flex;align-items:center;gap:8px}
    @media (max-width:992px){ .panel{padding:16px 14px} }

    /* ── Filtro de ligas ──────────────────────────────────────────── */
    .cal-filtros{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
    .cal-liga{
      display:inline-flex;align-items:center;gap:7px;padding:7px 13px;border-radius:999px;
      border:1px solid var(--border-md);background:var(--panel-2);color:var(--text-2);
      font-size:12.5px;font-weight:700;cursor:pointer;font-family:var(--font);
      transition:all var(--t);
    }
    .cal-liga .ponto{width:9px;height:9px;border-radius:50%;background:currentColor;flex-shrink:0}
    /* Ligada, a pílula assume a cor da liga — é o que amarra a cor ao nome
       antes da pessoa olhar o calendário e ter que adivinhar de quem é o
       ponto colorido. */
    .cal-liga.on{color:var(--c);border-color:var(--c);background:color-mix(in srgb,var(--c) 14%,transparent)}

    /* ── Grade do mês ─────────────────────────────────────────────── */
    .cal-topo{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
    .cal-mes{font-size:19px;font-weight:800;color:var(--text);text-transform:capitalize}
    .cal-nav{display:flex;gap:6px}
    .cal-btn{
      width:34px;height:34px;border-radius:9px;border:1px solid var(--border-md);
      background:var(--panel-2);color:var(--text);cursor:pointer;display:grid;place-items:center;
    }
    .cal-btn:hover{border-color:var(--red)}
    .cal-hoje{width:auto;padding:0 12px;font-size:12px;font-weight:700}

    .cal-grade{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}
    .cal-cab{
      text-align:center;font-size:10.5px;font-weight:800;color:var(--text-3);
      letter-spacing:.06em;text-transform:uppercase;padding:4px 0;
    }
    .cal-dia{
      min-height:96px;background:var(--panel-2);border:1px solid var(--border);
      border-radius:10px;padding:6px;display:flex;flex-direction:column;gap:3px;
      cursor:default;transition:border-color var(--t);
    }
    .cal-dia.fora{opacity:.35}
    .cal-dia.hoje{border-color:var(--red);box-shadow:0 0 0 1px color-mix(in srgb,var(--red) 35%,transparent) inset}
    .cal-dia.clicavel{cursor:pointer}
    .cal-dia.clicavel:hover{border-color:var(--border-md)}
    .cal-num{font-size:12px;font-weight:800;color:var(--text-2);line-height:1}
    .cal-dia.hoje .cal-num{color:var(--red)}

    .cal-ev{
      display:flex;align-items:center;gap:5px;padding:3px 6px;border-radius:6px;
      font-size:10.5px;font-weight:700;line-height:1.25;cursor:pointer;text-align:left;
      border:1px solid color-mix(in srgb,var(--c) 40%,transparent);
      background:color-mix(in srgb,var(--c) 16%,transparent);color:var(--c);
      width:100%;font-family:var(--font);overflow:hidden;
    }
    .cal-ev span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cal-ev i{flex-shrink:0;font-size:10px}
    .cal-mais{font-size:10px;color:var(--text-3);font-weight:700;padding-left:4px}

    /* ── Lista (mobile e "próximos") ──────────────────────────────── */
    .cal-lista{display:flex;flex-direction:column;gap:8px}
    .cal-item{
      display:flex;gap:11px;align-items:flex-start;padding:11px 13px;background:var(--panel-2);
      border:1px solid var(--border);border-left:3px solid var(--c);border-radius:10px;
    }
    .cal-item-data{
      flex-shrink:0;text-align:center;min-width:44px;
    }
    .cal-item-dia{font-size:19px;font-weight:800;color:var(--text);line-height:1}
    .cal-item-mes{font-size:9.5px;font-weight:700;color:var(--text-3);text-transform:uppercase}
    .cal-item-tit{font-size:13.5px;font-weight:700;color:var(--text);margin-bottom:2px}
    .cal-item-meta{font-size:11.5px;color:var(--text-2);display:flex;gap:7px;flex-wrap:wrap;align-items:center}
    .cal-tag{
      display:inline-flex;align-items:center;gap:4px;padding:1px 7px;border-radius:999px;
      font-size:9.5px;font-weight:800;background:color-mix(in srgb,var(--c) 16%,transparent);
      color:var(--c);border:1px solid color-mix(in srgb,var(--c) 35%,transparent);
    }
    .cal-link{color:#60a5fa;text-decoration:none;font-weight:700;font-size:11.5px}
    .cal-link:hover{text-decoration:underline}
    .cal-acoes{margin-left:auto;display:flex;gap:5px;flex-shrink:0}
    .cal-acao{
      width:28px;height:28px;border-radius:7px;border:1px solid var(--border-md);
      background:var(--panel-3);color:var(--text-2);cursor:pointer;display:grid;place-items:center;font-size:12px;
    }
    .cal-acao:hover{color:var(--text);border-color:var(--red)}

    .cal-vazio{padding:26px;text-align:center;color:var(--text-3);font-size:13px}

    /* Em telas pequenas a grade de 7 colunas vira ilegível — 96px de altura em
       coluna de 45px não cabe nem o título. Vira lista. */
    .cal-grade-wrap{display:block}
    .cal-lista-wrap{display:none}
    @media (max-width:820px){
      .cal-grade-wrap{display:none}
      .cal-lista-wrap{display:block}
    }

    /* ── Modal ────────────────────────────────────────────────────── */
    .cal-modal{
      position:fixed;inset:0;background:rgba(0,0,0,.62);z-index:1200;
      display:none;align-items:center;justify-content:center;padding:16px;
    }
    .cal-modal.aberto{display:flex}
    .cal-caixa{
      background:var(--panel);border:1px solid var(--border-md);border-radius:var(--radius);
      width:100%;max-width:480px;max-height:90vh;overflow:auto;padding:18px;
    }
    .cal-caixa h3{font-size:16px;font-weight:800;color:var(--text);margin:0 0 14px}
    .cal-campo{margin-bottom:11px}
    .cal-campo label{display:block;font-size:11px;font-weight:700;color:var(--text-3);
      text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
    .cal-campo input,.cal-campo select,.cal-campo textarea{
      width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;
      color:var(--text);padding:9px 11px;font-size:13px;font-family:var(--font);
    }
    .cal-campo input:focus,.cal-campo select:focus,.cal-campo textarea:focus{outline:none;border-color:var(--red)}
    .cal-linha{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .cal-rodape{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}
    .cal-b{padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;border:1px solid var(--border-md);
      background:var(--panel-2);color:var(--text);font-family:var(--font)}
    .cal-b.primario{background:var(--red);border-color:var(--red);color:#fff}
    .cal-b.perigo{background:transparent;border-color:rgba(239,68,68,.4);color:#ef4444;margin-right:auto}
    .cal-erro{color:#ef4444;font-size:12px;margin-top:8px;display:none}
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Calendário</em></div>
</header>

<main class="main">
    <div class="dash-hero">
        <div>
            <div class="dash-eyebrow">Organização</div>
            <h1 class="dash-title">Calendário</h1>
            <p class="dash-sub">Lives, prazos de FA, deadline e o que mais estiver marcado.</p>
        </div>
    </div>

    <div class="cal-filtros" id="calFiltros"></div>

    <div class="panel" style="padding:14px">
        <div class="cal-topo">
            <div class="cal-mes" id="calMes">—</div>
            <div class="cal-nav">
                <button class="cal-btn" id="calAnt" title="Mês anterior"><i class="bi bi-chevron-left"></i></button>
                <button class="cal-btn cal-hoje" id="calHoje">Hoje</button>
                <button class="cal-btn" id="calProx" title="Próximo mês"><i class="bi bi-chevron-right"></i></button>
            </div>
        </div>

        <div class="cal-grade-wrap">
            <div class="cal-grade" id="calCabecalho"></div>
            <div class="cal-grade" id="calGrade" style="margin-top:6px"></div>
        </div>
        <div class="cal-lista-wrap"><div class="cal-lista" id="calListaMes"></div></div>
    </div>

    <div class="section-title" style="margin-top:22px"><i class="bi bi-hourglass-split"></i> Próximos</div>
    <div class="panel" style="padding:14px">
        <div class="cal-lista" id="calProximos"></div>
    </div>
</main>

<!-- Modal: ver/editar evento -->
<div class="cal-modal" id="calModal">
  <div class="cal-caixa">
    <h3 id="calModalTit">Novo evento</h3>
    <div id="calModalCorpo"></div>
    <div class="cal-erro" id="calModalErro"></div>
    <div class="cal-rodape" id="calModalRodape"></div>
  </div>
</div>

<script>
const MINHA_LIGA  = <?= json_encode($minhaLiga) ?>;
const LIGAS       = <?= json_encode(CALENDARIO_LIGAS) ?>;
const CORES       = <?= json_encode(CALENDARIO_CORES, JSON_UNESCAPED_UNICODE) ?>;
const TIPOS       = <?= json_encode(CALENDARIO_TIPOS, JSON_UNESCAPED_UNICODE) ?>;
// As ligas que ESTE usuário administra. Quem não administra nada nunca vê
// botão de marcar — a regra também é conferida na API, isto é só a tela.
const LIGAS_ADMIN = <?= json_encode($ligasAdmin) ?>;

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
  ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

// Começa só na liga da pessoa, como pedido. A escolha fica no navegador: quem
// acompanha duas ligas não quer religar o filtro toda visita.
let ligasAtivas = (() => {
  try {
    const salvo = JSON.parse(localStorage.getItem('fba-cal-ligas') || 'null');
    if (Array.isArray(salvo) && salvo.length) return salvo.filter(l => LIGAS.includes(l));
  } catch (e) {}
  return [MINHA_LIGA];
})();
if (!ligasAtivas.length) ligasAtivas = [MINHA_LIGA];

let ref = new Date(); ref.setDate(1);
let eventosDoMes = [];

const MESES = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
const DIAS  = ['dom','seg','ter','qua','qui','sex','sáb'];

const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
const soData = (s) => String(s || '').slice(0, 10);
const hora = (s) => String(s || '').slice(11, 16);

/* ── Filtro ─────────────────────────────────────────────────────── */
function pintarFiltros() {
  document.getElementById('calFiltros').innerHTML = LIGAS.map(l => `
    <button class="cal-liga ${ligasAtivas.includes(l) ? 'on' : ''}" style="--c:${CORES[l]}"
            data-liga="${l}">
      <span class="ponto"></span>${l}${l === MINHA_LIGA ? ' <span style="opacity:.6;font-weight:600">· sua</span>' : ''}
    </button>`).join('');

  document.querySelectorAll('.cal-liga').forEach(b => b.addEventListener('click', () => {
    const l = b.dataset.liga;
    ligasAtivas = ligasAtivas.includes(l) ? ligasAtivas.filter(x => x !== l) : [...ligasAtivas, l];
    // Nunca deixa o filtro vazio: calendário sem liga nenhuma é uma tela em
    // branco que parece defeito.
    if (!ligasAtivas.length) ligasAtivas = [l];
    localStorage.setItem('fba-cal-ligas', JSON.stringify(ligasAtivas));
    pintarFiltros(); carregar();
  }));
}

/* ── Carregar ───────────────────────────────────────────────────── */
async function carregar() {
  const ini = new Date(ref.getFullYear(), ref.getMonth(), 1);
  const fim = new Date(ref.getFullYear(), ref.getMonth() + 1, 0);
  // Pega a semana que sobra dos dois lados: a grade mostra dias do mês
  // vizinho, e evento neles apareceria vazio.
  const de  = new Date(ini); de.setDate(de.getDate() - 7);
  const ate = new Date(fim); ate.setDate(ate.getDate() + 7);

  const q = `de=${ymd(de)}&ate=${ymd(ate)}&ligas=${ligasAtivas.join(',')}`;
  try {
    const r = await fetch(`/api/calendario.php?acao=eventos&${q}`);
    const d = await r.json();
    eventosDoMes = d.eventos || [];
  } catch (e) { eventosDoMes = []; }

  pintarGrade();
  pintarListaMes();
  carregarProximos();
}

async function carregarProximos() {
  const alvo = document.getElementById('calProximos');
  try {
    const r = await fetch(`/api/calendario.php?acao=proximos&limite=8&ligas=${ligasAtivas.join(',')}`);
    const d = await r.json();
    const evs = d.eventos || [];
    alvo.innerHTML = evs.length ? evs.map(itemHtml).join('')
      : '<div class="cal-vazio">Nada marcado por enquanto.</div>';
    ligarAcoes(alvo);
  } catch (e) { alvo.innerHTML = '<div class="cal-vazio">Não deu pra carregar.</div>'; }
}

/* Um evento cobre todos os dias entre início e fim. */
function eventosDoDia(dia) {
  return eventosDoMes.filter(e => soData(e.inicio) <= dia && soData(e.fim || e.inicio) >= dia);
}

/* ── Grade ──────────────────────────────────────────────────────── */
function pintarGrade() {
  document.getElementById('calMes').textContent = `${MESES[ref.getMonth()]} de ${ref.getFullYear()}`;
  document.getElementById('calCabecalho').innerHTML = DIAS.map(d => `<div class="cal-cab">${d}</div>`).join('');

  const primeiro = new Date(ref.getFullYear(), ref.getMonth(), 1);
  const inicio = new Date(primeiro);
  inicio.setDate(inicio.getDate() - primeiro.getDay());
  const hojeStr = ymd(new Date());

  let html = '';
  for (let i = 0; i < 42; i++) {
    const d = new Date(inicio); d.setDate(inicio.getDate() + i);
    const s = ymd(d);
    const fora = d.getMonth() !== ref.getMonth();
    const evs = eventosDoDia(s);
    const podeMarcar = LIGAS_ADMIN.length > 0;

    html += `<div class="cal-dia ${fora ? 'fora' : ''} ${s === hojeStr ? 'hoje' : ''} ${podeMarcar ? 'clicavel' : ''}"
                  data-dia="${s}">
      <div class="cal-num">${d.getDate()}</div>
      ${evs.slice(0, 3).map(e => `
        <button class="cal-ev" style="--c:${e.cor}" data-id="${e.id}">
          <i class="bi ${e.tipo_icone}"></i><span>${esc(e.titulo)}</span>
        </button>`).join('')}
      ${evs.length > 3 ? `<div class="cal-mais">+${evs.length - 3}</div>` : ''}
    </div>`;
  }
  const grade = document.getElementById('calGrade');
  grade.innerHTML = html;

  grade.querySelectorAll('.cal-ev').forEach(b => b.addEventListener('click', (ev) => {
    ev.stopPropagation();
    abrirEvento(Number(b.dataset.id));
  }));
  if (LIGAS_ADMIN.length) {
    grade.querySelectorAll('.cal-dia').forEach(c => c.addEventListener('click', () => abrirNovo(c.dataset.dia)));
  }
}

/* ── Lista ──────────────────────────────────────────────────────── */
function itemHtml(e) {
  const d = new Date(e.inicio.replace(' ', 'T'));
  const h = e.dia_inteiro ? '' : hora(e.inicio);
  const fim = e.fim ? ` até ${soData(e.fim).split('-').reverse().slice(0,2).join('/')}` : '';
  return `
    <div class="cal-item" style="--c:${e.cor}">
      <div class="cal-item-data">
        <div class="cal-item-dia">${d.getDate()}</div>
        <div class="cal-item-mes">${MESES[d.getMonth()].slice(0,3)}</div>
      </div>
      <div style="min-width:0;flex:1">
        <div class="cal-item-tit">${esc(e.titulo)}</div>
        <div class="cal-item-meta">
          <span class="cal-tag" style="--c:${e.cor}"><i class="bi ${e.tipo_icone}"></i>${esc(e.league)}</span>
          <span>${esc(e.tipo_rotulo)}${h ? ' · ' + h : ''}${fim}</span>
          ${e.link ? `<a class="cal-link" href="${esc(e.link)}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> abrir</a>` : ''}
        </div>
        ${e.descricao ? `<div class="cal-item-meta" style="margin-top:3px">${esc(e.descricao)}</div>` : ''}
      </div>
      ${e.posso_editar ? `<div class="cal-acoes">
        <button class="cal-acao" data-editar="${e.id}" title="Editar"><i class="bi bi-pencil"></i></button>
      </div>` : ''}
    </div>`;
}

function pintarListaMes() {
  const mes = String(ref.getMonth() + 1).padStart(2, '0');
  const pref = `${ref.getFullYear()}-${mes}`;
  const doMes = eventosDoMes.filter(e => soData(e.inicio).startsWith(pref)
                                      || soData(e.fim || e.inicio).startsWith(pref));
  const alvo = document.getElementById('calListaMes');
  alvo.innerHTML = doMes.length ? doMes.map(itemHtml).join('')
    : '<div class="cal-vazio">Nada marcado neste mês.</div>';
  ligarAcoes(alvo);
}

function ligarAcoes(raiz) {
  raiz.querySelectorAll('[data-editar]').forEach(b =>
    b.addEventListener('click', () => abrirEvento(Number(b.dataset.editar))));
}

/* ── Modal ──────────────────────────────────────────────────────── */
const modal = document.getElementById('calModal');
const fecharModal = () => modal.classList.remove('aberto');
modal.addEventListener('click', e => { if (e.target === modal) fecharModal(); });

function abrirEvento(id) {
  const e = eventosDoMes.find(x => x.id === id);
  if (!e) return;
  if (e.posso_editar) return abrirForm(e);

  // Quem não administra vê os detalhes, não o formulário.
  document.getElementById('calModalTit').textContent = e.titulo;
  document.getElementById('calModalCorpo').innerHTML = `
    <div class="cal-item" style="--c:${e.cor};border-left-width:3px">
      <div style="min-width:0">
        <div class="cal-item-meta">
          <span class="cal-tag" style="--c:${e.cor}"><i class="bi ${e.tipo_icone}"></i>${esc(e.league)}</span>
          <span>${esc(e.tipo_rotulo)}</span>
        </div>
        <div style="margin-top:8px;font-size:13px;color:var(--text)">
          ${soData(e.inicio).split('-').reverse().join('/')}${e.dia_inteiro ? '' : ' às ' + hora(e.inicio)}
          ${e.fim ? ' — até ' + soData(e.fim).split('-').reverse().join('/') : ''}
        </div>
        ${e.descricao ? `<div style="margin-top:8px;font-size:12.5px;color:var(--text-2)">${esc(e.descricao)}</div>` : ''}
        ${e.link ? `<div style="margin-top:10px"><a class="cal-link" href="${esc(e.link)}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> ${esc(e.link)}</a></div>` : ''}
      </div>
    </div>`;
  document.getElementById('calModalErro').style.display = 'none';
  document.getElementById('calModalRodape').innerHTML = `<button class="cal-b" onclick="fecharModalPub()">Fechar</button>`;
  modal.classList.add('aberto');
}
window.fecharModalPub = fecharModal;

function abrirNovo(dia) { abrirForm({ inicio: `${dia} 20:00:00`, league: LIGAS_ADMIN[0] || MINHA_LIGA }); }

function abrirForm(e) {
  const novo = !e.id;
  document.getElementById('calModalTit').textContent = novo ? 'Novo evento' : 'Editar evento';
  document.getElementById('calModalErro').style.display = 'none';

  const opLigas = LIGAS_ADMIN.map(l =>
    `<option value="${l}"${e.league === l ? ' selected' : ''}>${l}</option>`).join('');
  const opTipos = Object.entries(TIPOS).map(([k, v]) =>
    `<option value="${k}"${e.tipo === k ? ' selected' : ''}>${esc(v.rotulo)}</option>`).join('');

  const dtLocal = (s) => s ? String(s).replace(' ', 'T').slice(0, 16) : '';

  document.getElementById('calModalCorpo').innerHTML = `
    <div class="cal-campo">
      <label>Título</label>
      <input id="fTitulo" maxlength="140" placeholder="Live do draft, Fecha o FA..." value="${esc(e.titulo || '')}">
    </div>
    <div class="cal-linha">
      <div class="cal-campo"><label>Liga</label><select id="fLiga">${opLigas}</select></div>
      <div class="cal-campo"><label>Tipo</label><select id="fTipo">${opTipos}</select></div>
    </div>
    <div class="cal-linha">
      <div class="cal-campo"><label>Começa</label><input type="datetime-local" id="fInicio" value="${dtLocal(e.inicio)}"></div>
      <div class="cal-campo"><label>Termina <span style="text-transform:none;font-weight:600">(opcional)</span></label>
        <input type="datetime-local" id="fFim" value="${dtLocal(e.fim)}"></div>
    </div>
    <div class="cal-campo">
      <label style="display:flex;align-items:center;gap:7px;text-transform:none;font-size:12.5px;color:var(--text-2)">
        <input type="checkbox" id="fDiaInteiro" style="width:auto" ${e.dia_inteiro ? 'checked' : ''}>
        Dia inteiro (ignora a hora)
      </label>
    </div>
    <div class="cal-campo"><label>Link <span style="text-transform:none;font-weight:600">(opcional)</span></label>
      <input id="fLink" placeholder="youtube.com/..." value="${esc(e.link || '')}"></div>
    <div class="cal-campo"><label>Descrição <span style="text-transform:none;font-weight:600">(opcional)</span></label>
      <textarea id="fDesc" rows="2">${esc(e.descricao || '')}</textarea></div>`;

  document.getElementById('calModalRodape').innerHTML = `
    ${novo ? '' : '<button class="cal-b perigo" id="bApagar"><i class="bi bi-trash"></i> Apagar</button>'}
    <button class="cal-b" id="bCancelar">Cancelar</button>
    <button class="cal-b primario" id="bSalvar">Salvar</button>`;

  document.getElementById('bCancelar').onclick = fecharModal;
  document.getElementById('bSalvar').onclick = () => salvar(e.id || 0);
  if (!novo) document.getElementById('bApagar').onclick = () => apagar(e.id);

  modal.classList.add('aberto');
  setTimeout(() => document.getElementById('fTitulo').focus(), 50);
}

function erroModal(msg) {
  const el = document.getElementById('calModalErro');
  el.textContent = msg; el.style.display = 'block';
}

async function salvar(id) {
  const corpo = {
    acao: 'salvar', id,
    titulo: document.getElementById('fTitulo').value.trim(),
    league: document.getElementById('fLiga').value,
    tipo: document.getElementById('fTipo').value,
    inicio: document.getElementById('fInicio').value,
    fim: document.getElementById('fFim').value,
    dia_inteiro: document.getElementById('fDiaInteiro').checked,
    link: document.getElementById('fLink').value.trim(),
    descricao: document.getElementById('fDesc').value.trim(),
  };
  if (!corpo.titulo) return erroModal('O evento precisa de um título.');
  if (!corpo.inicio) return erroModal('O evento precisa de uma data.');

  const b = document.getElementById('bSalvar');
  b.disabled = true; b.textContent = 'Salvando...';
  try {
    const r = await fetch('/api/calendario.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(corpo),
    });
    const d = await r.json();
    if (!r.ok || !d.ok) { erroModal(d.erro || 'Não deu pra salvar.'); return; }
    fecharModal(); carregar();
  } catch (e) { erroModal('Erro de rede. Tente de novo.'); }
  finally { b.disabled = false; b.textContent = 'Salvar'; }
}

async function apagar(id) {
  if (!confirm('Apagar este evento do calendário?')) return;
  try {
    const r = await fetch('/api/calendario.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({acao: 'apagar', id}),
    });
    const d = await r.json();
    if (!r.ok || !d.ok) { erroModal(d.erro || 'Não deu pra apagar.'); return; }
    fecharModal(); carregar();
  } catch (e) { erroModal('Erro de rede.'); }
}

/* ── Navegação ──────────────────────────────────────────────────── */
document.getElementById('calAnt').onclick  = () => { ref.setMonth(ref.getMonth() - 1); carregar(); };
document.getElementById('calProx').onclick = () => { ref.setMonth(ref.getMonth() + 1); carregar(); };
document.getElementById('calHoje').onclick = () => { ref = new Date(); ref.setDate(1); carregar(); };
document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharModal(); });

pintarFiltros();
carregar();
</script>
</body>
</html>
