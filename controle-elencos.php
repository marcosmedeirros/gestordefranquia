<?php
/**
 * controle-elencos.php — letras e estatísticas da liga inteira, numa tela.
 *
 * Um card por time mostrando o que falta, e um card fixo no começo pra lançar
 * a liga toda de uma vez. Clicou, abre o modal: o elenco com o valor de agora,
 * o prompt pra IA, o modelo de CSV e o envio. O CSV só PREENCHE a revisão —
 * quem grava é o botão Salvar, depois de o admin conferir o que mudou.
 *
 * A regra de gravação mora em backend/controle_elencos.php.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/atualizacoes.php';
requireAuth();
$user = getUserSession();
$pdo  = db();

// Aberta a qualquer usuário logado: é a tela única de edição de letras e
// estatísticas, com atalho em Stats e Skills. O admin continua chegando pelos
// cards da aba da liga.
$ehAdmin = ($user['user_type'] ?? 'jogador') === 'admin'
        || !empty(getAdminLeagues($pdo, (int)$user['id']));
$minhasLigas = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

// O menu lateral mostra o cartão do time do usuário quando $team existe.
$stTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stTeam->execute([(int)$user['id']]);
$team = $stTeam->fetch(PDO::FETCH_ASSOC) ?: null;

$pedida = strtoupper(trim((string)($_GET['league'] ?? '')));

// ?time=ID (vindo do "Editar" em teams.php): a liga sai do time, e a tela abre
// com o modal dele já aberto.
$timeInicial = (int)($_GET['time'] ?? 0);
if ($timeInicial) {
    $stLigaTime = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $stLigaTime->execute([$timeInicial]);
    $ligaDoTime = strtoupper((string)$stLigaTime->fetchColumn());
    if ($ligaDoTime !== '') $pedida = $ligaDoTime; else $timeInicial = 0;
}
$ligaDoUsuario = strtoupper((string)($user['league'] ?? ''));
$ligaInicial = in_array($pedida, $minhasLigas, true) ? $pedida
             : (in_array($ligaDoUsuario, $minhasLigas, true) ? $ligaDoUsuario : $minhasLigas[0]);
// Veio do card "Editar Stats" da aba de uma liga: a tela fica só nela, sem
// abas pras outras — cada liga tem a sua página.
if (in_array($pedida, $minhasLigas, true)) $minhasLigas = [$pedida];
$tipoInicial = ($_GET['tipo'] ?? '') === 'letras' ? 'letras' : 'stats';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Controle de Elencos · FBA Manager</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Oswald:wght@500;700&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025;--red-soft:color-mix(in srgb,var(--red) 12%,transparent);
  --bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
  --border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);
  --text:#f0f0f3;--text-2:#8b8b95;--text-3:#6f6f79;
  --green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;
  --font:'Montserrat',system-ui,sans-serif;--num:'Oswald',sans-serif;--radius:14px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.wrap{max-width:1180px;margin:0 auto}

.topo{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}
h1{font-size:24px;font-weight:800;line-height:1.15}
h1 i{color:var(--red)}
.sub{font-size:13px;color:var(--text-2);margin-top:4px;max-width:640px;line-height:1.5}
.voltar{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;border:1px solid var(--border-md);color:var(--text-2);text-decoration:none;font-size:12.5px;font-weight:600}
.voltar:hover{border-color:var(--red);color:var(--red)}

.barra{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.abas{display:flex;gap:8px;flex-wrap:wrap}
.aba{padding:9px 18px;border-radius:10px;background:var(--panel-2);border:1.5px solid var(--border);color:var(--text-2);font-size:12.5px;font-weight:700;cursor:pointer;font-family:var(--font);letter-spacing:.4px}
.aba.on{border-color:var(--red);background:var(--red-soft);color:var(--red)}

/* O seletor muda a página inteira de assunto — por isso segmentado e grande,
   e não duas abas pequenas: é a primeira decisão da tela. */
.seletor{display:inline-flex;background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:4px;gap:4px}
.seletor button{border:0;background:transparent;color:var(--text-2);font-family:var(--font);font-size:13px;font-weight:700;padding:9px 16px;border-radius:9px;cursor:pointer;display:inline-flex;align-items:center;gap:7px}
.seletor button.on{background:var(--red);color:#fff}

.aviso-topo{font-size:12.5px;color:var(--text-2);background:var(--panel);border:1px solid var(--border);border-radius:11px;padding:10px 14px;margin-bottom:14px;display:flex;gap:8px;align-items:center}
.aviso-topo i{color:var(--blue)}

.grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.tcard{background:var(--panel);border:1.5px solid var(--border);border-radius:13px;padding:14px;text-align:left;cursor:pointer;font-family:var(--font);color:var(--text);display:flex;flex-direction:column;gap:10px;transition:border-color .15s,transform .15s}
.tcard:hover{border-color:var(--border-md);transform:translateY(-1px)}
.tcard:focus-visible{outline:2px solid var(--red);outline-offset:2px}
.tcard-cab{display:flex;align-items:center;gap:10px;min-width:0}
.tcard img{width:38px;height:38px;border-radius:9px;object-fit:cover;background:var(--panel-3);flex-shrink:0}
.tcard b{display:block;font-size:13.5px;font-weight:800;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tcard small{display:block;font-size:11px;color:var(--text-2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tcard-pe{display:flex;align-items:center;justify-content:space-between;gap:8px}
.tcard-data{font-size:10.5px;color:var(--text-3)}

/* O card da liga inteira é o primeiro e é diferente de propósito: não é um
   time, é a ação em massa. */
.tcard.liga{border-style:dashed;border-color:color-mix(in srgb,var(--red) 45%,transparent);background:linear-gradient(160deg,var(--red-soft),var(--panel) 70%)}
.tcard.liga .ico{width:38px;height:38px;border-radius:9px;display:grid;place-items:center;background:var(--red-soft);color:var(--red);font-size:18px;flex-shrink:0}

.chip{display:inline-block;font-size:9.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;border-radius:99px;padding:3px 9px;border:1px solid;white-space:nowrap}
.chip.falta{color:var(--amber);border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.1)}
.chip.ok{color:var(--green);border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.1)}
.chip.cinza{color:var(--text-3);border-color:var(--border);background:var(--panel-3)}

.vazio{padding:40px;text-align:center;color:var(--text-2);font-size:13px}

/* ── Modal ── */
.fundo{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:500;display:flex;align-items:center;justify-content:center;padding:18px}
.fundo[hidden]{display:none}
.caixa{background:var(--panel);border:1px solid var(--border-md);border-radius:16px;width:100%;max-width:1120px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden}
.caixa-cab{display:flex;align-items:flex-start;gap:12px;padding:16px 18px;border-bottom:1px solid var(--border)}
.caixa-cab h2{font-size:17px;font-weight:800;line-height:1.2}
.caixa-cab p{font-size:12px;color:var(--text-2);margin-top:3px}
.fechar{margin-left:auto;background:transparent;border:1px solid var(--border);color:var(--text-2);width:34px;height:34px;border-radius:9px;cursor:pointer;font-size:16px;flex-shrink:0}
.fechar:hover{border-color:var(--red);color:var(--red)}
.caixa-acoes{display:flex;gap:8px;flex-wrap:wrap;padding:12px 18px;border-bottom:1px solid var(--border);align-items:center}
.caixa-corpo{overflow:auto;flex:1;padding:12px 18px}
.caixa-pe{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px 18px;border-top:1px solid var(--border)}
.caixa-pe .resumo{font-size:12.5px;color:var(--text-2);margin-right:auto}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 15px;border-radius:10px;border:1.5px solid transparent;background:var(--red);color:#fff;font-family:var(--font);font-size:12.5px;font-weight:700;cursor:pointer}
.btn:disabled{background:var(--panel-3);color:var(--text-3);cursor:not-allowed}
.btn.ghost{background:transparent;border-color:var(--border-md);color:var(--text-2)}
.btn.ghost:hover:not(:disabled){border-color:var(--red);color:var(--red)}
.btn.azul{background:#2563eb}
.temp-sel{display:inline-flex;align-items:center;gap:7px;margin-left:auto;padding:0 4px 0 11px;height:38px;
  border-radius:10px;border:1.5px solid var(--border-md);background:var(--panel-2);color:var(--text-2)}
.temp-sel[hidden]{display:none}
.temp-sel i{color:var(--red)}
.temp-sel select{background:transparent;border:0;color:var(--text);font-family:var(--font);font-size:12.5px;
  font-weight:700;height:100%;padding-right:6px;cursor:pointer;outline:none}
.temp-sel select option{background:var(--panel-2);color:var(--text)}
.temp-sel:focus-within{border-color:var(--red)}
@media (max-width:700px){.temp-sel{margin-left:0;width:100%}.temp-sel select{flex:1}}

.msg{margin:0 18px;font-size:12.5px;line-height:1.5;border-radius:10px;padding:9px 12px;border:1px solid}
.msg:empty{display:none}
.msg.ok{color:var(--green);border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.08);margin-top:10px}
.msg.warn{color:var(--amber);border-color:rgba(245,158,11,.3);background:rgba(245,158,11,.08);margin-top:10px}
.msg.err{color:var(--red);border-color:var(--red-soft);background:var(--red-soft);margin-top:10px}

table{width:100%;border-collapse:collapse;font-size:12.5px}
th{position:sticky;top:0;background:var(--panel-2);color:var(--text-3);font-size:9.5px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;padding:8px 9px;text-align:center;white-space:nowrap;z-index:1}
th:first-child,td:first-child{text-align:left}
td{padding:6px 9px;border-top:1px solid var(--border);white-space:nowrap;text-align:center;font-variant-numeric:tabular-nums}
td.nm{font-weight:700}
td.tm{color:var(--text-2);font-size:11.5px;text-align:left}
td.vazio-cel{color:var(--text-3)}
/* Mudou: o novo em âmbar, o antigo pequeno embaixo. É o que o admin confere
   de relance antes de salvar. */
td.mudou{color:var(--amber);font-weight:800;background:rgba(245,158,11,.06)}
td.mudou s{display:block;font-size:9.5px;color:var(--text-3);font-weight:500}

@media (max-width:700px){
  .main{padding-left:12px;padding-right:12px;padding-bottom:50px}
  h1{font-size:20px}
  .fundo{padding:0}
  .caixa{max-height:100vh;height:100vh;border-radius:0}
  .seletor{width:100%}
  .seletor button{flex:1;justify-content:center}
}
@media (prefers-reduced-motion:reduce){.tcard{transition:none}.tcard:hover{transform:none}}

/* ── Menu lateral: o mesmo das outras telas ─────────────────────────── */
:root{--sidebar-w:260px;--radius-sm:10px;--ease:cubic-bezier(.2,.8,.2,1);--t:200ms;
  --border-red:color-mix(in srgb,var(--red) 22%,transparent)}
.main{margin-left:var(--sidebar-w);min-height:100vh;padding:22px 18px 60px;transition:margin var(--t) var(--ease)}
<?php include __DIR__ . '/includes/sidebar-css.php'; ?>
@media (max-width:992px){
  :root{--sidebar-w:0px}
  .main{margin-left:0;padding-top:70px;width:100%}
  .topbar{display:flex}
  .sidebar{transform:translateX(-260px)}
  .sidebar.open{transform:translateX(0)}
}
<?php include __DIR__ . '/includes/accent-color.php'; ?>
</style>
</head>
<body>
<div class="app">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div class="topbar-title">FBA <em>Manager</em></div>
</header>

<div class="main">
<div class="wrap">
  <div class="topo">
    <div>
      <h1><i class="bi bi-table"></i> Controle de Elencos</h1>
      <div class="sub">Lance as letras e as estatísticas de um time — ou da liga inteira — por CSV.
        Nada é gravado direto do arquivo: ele preenche a revisão, e o Salvar é seu.</div>
    </div>
    <?php if ($ehAdmin): ?>
    <a href="/admin.php" class="voltar"><i class="bi bi-arrow-left"></i> Admin</a>
    <?php else: ?>
    <a href="/statsjogadores.php" class="voltar"><i class="bi bi-arrow-left"></i> Stats e Skills</a>
    <?php endif; ?>
  </div>

  <div class="barra">
    <div class="abas" id="abas"></div>
    <div class="seletor" id="seletor">
      <button type="button" data-tipo="stats"><i class="bi bi-bar-chart-fill"></i> Estatísticas</button>
      <button type="button" data-tipo="letras"><i class="bi bi-sliders"></i> Letras</button>
    </div>
  </div>

  <div class="aviso-topo" id="avisoTopo" hidden></div>
  <div class="grade" id="grade"><div class="vazio">Carregando…</div></div>
</div>
</div><!-- .main -->
</div><!-- .app -->

<div class="fundo" id="modal" hidden>
  <div class="caixa" role="dialog" aria-modal="true" aria-labelledby="mTitulo">
    <div class="caixa-cab">
      <div>
        <h2 id="mTitulo"></h2>
        <p id="mSub"></p>
      </div>
      <button type="button" class="fechar" id="mFechar" title="Fechar"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="caixa-acoes">
      <button type="button" class="btn ghost" id="mPrompt"><i class="bi bi-clipboard"></i> Copiar prompt pra IA</button>
      <button type="button" class="btn ghost" id="mModelo"><i class="bi bi-download"></i> Baixar modelo CSV</button>
      <label class="btn azul" id="mEnviarRot">
        <i class="bi bi-upload"></i> Enviar CSV
        <input type="file" id="mArquivo" accept=".csv,text/csv" hidden>
      </label>
      <?php /* Só nas estatísticas: letra não tem temporada. Vem marcada a de
               sempre (a última com classificação); dá pra voltar numa anterior. */ ?>
      <label class="temp-sel" id="mTempRot" title="Temporada que recebe as estatísticas">
        <i class="bi bi-calendar3"></i>
        <select id="mTemporada" aria-label="Temporada"></select>
      </label>
    </div>
    <div class="msg" id="mMsg"></div>
    <div class="caixa-corpo"><table id="mTabela"></table></div>
    <div class="caixa-pe">
      <span class="resumo" id="mResumo"></span>
      <button type="button" class="btn ghost" id="mDescartar" disabled><i class="bi bi-eraser"></i> Descartar</button>
      <button type="button" class="btn" id="mSalvar" disabled><i class="bi bi-save2"></i> Salvar</button>
    </div>
  </div>
</div>

<script src="<?= assetUrl('/js/elenco-csv.js') ?>"></script>
<script>
const LIGAS  = <?= json_encode(array_values($minhasLigas)) ?>;
// As colunas e as notas vêm do mesmo lugar que o servidor usa pra validar —
// uma lista escrita aqui envelheceria sozinha.
const SKILLS = <?= json_encode(ATUALIZACAO_SKILLS, JSON_UNESCAPED_UNICODE) ?>;
const STATS  = <?= json_encode(ATUALIZACAO_STATS, JSON_UNESCAPED_UNICODE) ?>;
const NOTAS  = <?= json_encode(ATUALIZACAO_NOTAS) ?>;
const API    = '/api/controle-elencos.php';

let liga = <?= json_encode($ligaInicial) ?>;
const TIME_INICIAL = <?= (int)$timeInicial ?>;
let tipo = <?= json_encode($tipoInicial) ?>;
let times = [], temporada = null, temporadas = [];
const modal = { escopo: null, timeId: null, nome: '', jogadores: [], porId: {}, novos: {}, temporadaId: null };

const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

async function getJSON(url, opc) {
  const r = await fetch(url, opc);
  const d = await r.json().catch(() => ({ ok: false, erro: 'Resposta inválida do servidor.' }));
  if (!d.ok) throw new Error(d.erro || 'Falhou.');
  return d;
}

function colunas() {
  return tipo === 'letras'
    ? Object.entries(SKILLS).map(([c, rot]) => ({ c, rot }))
    : Object.entries(STATS).map(([c, o]) => ({ c, rot: o.rot, max: o.max }));
}

function lembrarNaUrl() {
  const u = new URL(location.href);
  u.searchParams.set('league', liga);
  u.searchParams.set('tipo', tipo);
  history.replaceState(null, '', u);
}

/* ── Barra ─────────────────────────────────────────────────────────── */
function renderBarra() {
  $('abas').style.display = LIGAS.length < 2 ? 'none' : '';
  $('abas').innerHTML = LIGAS.map(l =>
    `<button type="button" class="aba ${l === liga ? 'on' : ''}" data-liga="${l}">${l}</button>`).join('');
  $('seletor').querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.tipo === tipo));
}
$('abas').addEventListener('click', e => {
  const b = e.target.closest('[data-liga]');
  if (!b || b.dataset.liga === liga) return;
  liga = b.dataset.liga; lembrarNaUrl(); renderBarra(); carregar();
});
$('seletor').addEventListener('click', e => {
  const b = e.target.closest('[data-tipo]');
  if (!b || b.dataset.tipo === tipo) return;
  tipo = b.dataset.tipo; lembrarNaUrl(); renderBarra(); renderGrade();
});

/* ── Grade ─────────────────────────────────────────────────────────── */
async function carregar() {
  $('grade').innerHTML = '<div class="vazio">Carregando…</div>';
  try {
    const d = await getJSON(`${API}?acao=times&liga=${encodeURIComponent(liga)}`);
    times = d.times || []; temporada = d.temporada; temporadas = d.temporadas || [];
    renderGrade();
  } catch (e) {
    $('grade').innerHTML = `<div class="vazio">${esc(e.message)}</div>`;
  }
}

function situacao(t) {
  if (!t.jogadores) return { cls: 'cinza', txt: 'sem jogadores', pendente: false };
  // 7 lançados já conta como feito (verde): é o mesmo mínimo que paga as
  // moedas, e o fim do banco raramente tem letra ou jogo pra lançar.
  const MIN = <?= (int)ATUALIZACAO_MIN_JOGADORES_MOEDA ?>;
  const bastam = Math.min(MIN, t.jogadores);
  if (tipo === 'letras') {
    const comLetras = t.jogadores - t.sem_letras;
    if (t.sem_letras === 0) return { cls: 'ok', txt: 'letras completas', pendente: false };
    if (comLetras >= bastam) return { cls: 'ok', txt: `${comLetras}/${t.jogadores} com letras`, pendente: false };
    return { cls: 'falta', txt: `${t.sem_letras} sem letras`, pendente: true };
  }
  if (!temporada) return { cls: 'cinza', txt: 'sem temporada', pendente: false };
  if (t.com_stats === 0) return { cls: 'falta', txt: 'nada lançado', pendente: true };
  if (t.com_stats >= t.jogadores) return { cls: 'ok', txt: 'tudo lançado', pendente: false };
  if (t.com_stats >= bastam) return { cls: 'ok', txt: `${t.com_stats}/${t.jogadores} lançados`, pendente: false };
  return { cls: 'falta', txt: `${t.com_stats}/${t.jogadores} lançados`, pendente: true };
}

function dataCurta(s) {
  if (!s) return 'nunca atualizado';
  const d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d) ? '' : 'atualizado ' + d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
}

function renderGrade() {
  const aviso = $('avisoTopo');
  // O texto vai dentro de um <span>: o aviso é flex, e texto solto ao lado de
  // um <b> vira três itens flex separados — no celular cada pedaço ia pra sua
  // própria coluna.
  if (tipo === 'stats' && temporada) {
    aviso.hidden = false;
    aviso.innerHTML = `<i class="bi bi-info-circle"></i><span>As estatísticas vão pra <b>T${temporada.numero}${
      temporada.ano ? ' (' + temporada.ano + ')' : ''}</b> — a última temporada com classificação definida.</span>`;
  } else if (tipo === 'stats') {
    aviso.hidden = false;
    aviso.innerHTML = '<i class="bi bi-info-circle"></i><span>A liga ainda não tem temporada pra receber estatística.</span>';
  } else {
    aviso.hidden = true;
  }

  const pendentes = times.filter(t => situacao(t).pendente).length;
  const cardLiga = `
    <button type="button" class="tcard liga" data-escopo="liga">
      <div class="tcard-cab">
        <span class="ico"><i class="bi bi-collection-fill"></i></span>
        <div style="min-width:0"><b>Liga inteira</b><small>Um CSV com todos os ${times.length} times</small></div>
      </div>
      <div class="tcard-pe">
        <span class="chip ${pendentes ? 'falta' : 'ok'}">${pendentes ? pendentes + ' com pendência' : 'nada pendente'}</span>
      </div>
    </button>`;

  const cards = times.map(t => {
    const s = situacao(t);
    return `
    <button type="button" class="tcard" data-escopo="time" data-id="${t.id}">
      <div class="tcard-cab">
        <img src="${esc(t.logo)}" alt="" loading="lazy" onerror="this.src='/img/default-team.png'">
        <div style="min-width:0"><b>${esc(t.nome)}</b><small>${esc(t.gm)} · ${t.jogadores} jogadores</small></div>
      </div>
      <div class="tcard-pe">
        <span class="chip ${s.cls}">${s.txt}</span>
        <span class="tcard-data">${dataCurta(tipo === 'letras' ? t.letras_em : t.stats_em)}</span>
      </div>
    </button>`;
  }).join('');

  $('grade').innerHTML = cardLiga + (cards || '<div class="vazio">Nenhum time nesta liga.</div>');
}

$('grade').addEventListener('click', e => {
  const c = e.target.closest('.tcard');
  if (!c) return;
  abrirModal(c.dataset.escopo, c.dataset.escopo === 'time' ? parseInt(c.dataset.id, 10) : null);
});

/* ── Modal ─────────────────────────────────────────────────────────── */
function aviso(tipoMsg, html) {
  const el = $('mMsg');
  el.className = 'msg' + (html ? ' ' + tipoMsg : '');
  el.innerHTML = html || '';
}

async function abrirModal(escopo, timeId) {
  modal.escopo = escopo; modal.timeId = timeId; modal.novos = {};
  const t = times.find(x => x.id === timeId);
  modal.nome = escopo === 'liga' ? `Liga ${liga} inteira` : (t ? t.nome : 'Time');

  $('mTitulo').textContent = `${modal.nome} — ${tipo === 'letras' ? 'Letras' : 'Estatísticas'}`;
  $('mSub').textContent = escopo === 'liga'
    ? 'O modelo traz todos os jogadores da liga, com a coluna do time.'
    : (t ? `${t.gm} · ${t.jogadores} jogadores` : '');
  aviso('', '');
  $('modal').hidden = false;
  document.body.style.overflow = 'hidden';

  // Seletor de temporada: sempre abre na de sempre (a alvo).
  modal.temporadaId = temporada ? temporada.id : null;
  $('mTempRot').hidden = tipo !== 'stats' || !temporadas.length;
  $('mTemporada').innerHTML = temporadas.map(s =>
    `<option value="${s.id}"${s.id === modal.temporadaId ? ' selected' : ''}>${esc(s.rotulo)}${
      temporada && s.id === temporada.id ? ' (padrão)' : ''}</option>`).join('');

  await carregarElencoModal();
}

function urlElencoModal() {
  return `${API}?acao=elenco&liga=${encodeURIComponent(liga)}${modal.timeId ? '&time=' + modal.timeId : ''}` +
         (tipo === 'stats' && modal.temporadaId ? '&temporada=' + modal.temporadaId : '');
}

async function carregarElencoModal() {
  $('mTabela').innerHTML = '<tbody><tr><td class="vazio-cel">Carregando…</td></tr></tbody>';
  try {
    const d = await getJSON(urlElencoModal());
    modal.jogadores = d.jogadores || [];
    modal.porId = Object.fromEntries(modal.jogadores.map(j => [j.id, j]));
    renderModal();
  } catch (e) {
    aviso('err', esc(e.message));
    $('mTabela').innerHTML = '';
  }
}

// Trocar a temporada troca o "valor de agora" da revisão inteira.
$('mTemporada').addEventListener('change', async e => {
  if (Object.keys(modal.novos).length &&
      !confirm('Tem mudança lida do CSV que ainda não foi salva. Trocar de temporada e descartar?')) {
    e.target.value = modal.temporadaId;
    return;
  }
  modal.temporadaId = parseInt(e.target.value, 10) || null;
  modal.novos = {};
  aviso('', '');
  await carregarElencoModal();
});

function fecharModal() {
  if (Object.keys(modal.novos).length &&
      !confirm('Tem mudança lida do CSV que ainda não foi salva. Fechar e descartar?')) return;
  $('modal').hidden = true;
  document.body.style.overflow = '';
}
$('mFechar').addEventListener('click', fecharModal);
$('modal').addEventListener('click', e => { if (e.target === $('modal')) fecharModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('modal').hidden) fecharModal(); });

function igual(a, b) {
  if (a === '' || a === null || a === undefined) return b === '' || b === null || b === undefined;
  return String(a).toUpperCase() === String(b).toUpperCase() || Number(a) === Number(b) && !isNaN(Number(a));
}

function renderModal() {
  const cols = colunas();
  const comTime = modal.escopo === 'liga';
  const cab = `<thead><tr><th>Jogador</th>${comTime ? '<th>Time</th>' : ''}${cols.map(c => `<th>${esc(c.rot)}</th>`).join('')}</tr></thead>`;

  const corpo = modal.jogadores.map(j => {
    const novo = modal.novos[j.id] || {};
    const cels = cols.map(({ c }) => {
      const atual = j[c];
      if (c in novo && !igual(novo[c], atual)) {
        return `<td class="mudou">${esc(novo[c])}<s>${atual === '' ? '—' : esc(atual)}</s></td>`;
      }
      return atual === '' ? '<td class="vazio-cel">—</td>' : `<td>${esc(atual)}</td>`;
    }).join('');
    return `<tr><td class="nm">${esc(j.name)}</td>${comTime ? `<td class="tm">${esc(j.time)}</td>` : ''}${cels}</tr>`;
  }).join('');

  $('mTabela').innerHTML = cab + `<tbody>${corpo || `<tr><td class="vazio-cel" colspan="${cols.length + 2}">Nenhum jogador.</td></tr>`}</tbody>`;

  const n = Object.keys(modal.novos).length;
  $('mResumo').textContent = n
    ? `${n} jogador(es) com mudança — confira em âmbar antes de salvar.`
    : `${modal.jogadores.length} jogador(es). Envie um CSV pra preencher a revisão.`;
  $('mSalvar').disabled = n === 0;
  $('mDescartar').disabled = n === 0;
}

/* ── Modelo e prompt ───────────────────────────────────────────────── */
function modeloLinhas() {
  const cols = colunas();
  const comTime = modal.escopo === 'liga';
  const cab = ['id', ...(comTime ? ['time'] : []), 'jogador', ...cols.map(c => c.rot)];
  return [cab, ...modal.jogadores.map(j =>
    [j.id, ...(comTime ? [j.time] : []), j.name, ...cols.map(({ c }) => j[c] ?? '')])];
}
function slug(s) { return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); }

$('mModelo').addEventListener('click', () => {
  ElencoCSV.baixar(`${tipo === 'letras' ? 'letras' : 'estatisticas'}-${slug(modal.nome)}.csv`, modeloLinhas());
});
$('mPrompt').addEventListener('click', e => {
  const texto = ElencoCSV.paraTexto(modeloLinhas());
  const o = { comTime: modal.escopo === 'liga', notas: NOTAS, comOvrIdade: false };
  ElencoCSV.copiar(tipo === 'letras' ? ElencoCSV.promptLetras(texto, o) : ElencoCSV.promptStats(texto, o), e.currentTarget);
});

/* ── Importar ──────────────────────────────────────────────────────── */
$('mArquivo').addEventListener('change', e => {
  const f = e.target.files[0];
  e.target.value = '';
  if (!f) return;
  const fr = new FileReader();
  fr.onload = () => importar(String(fr.result || ''));
  fr.readAsText(f, 'UTF-8');
});

function importar(texto) {
  const linhas = ElencoCSV.ler(texto);
  if (linhas.length < 2) { aviso('err', 'CSV vazio ou sem linhas de jogador.'); return; }

  const cab = linhas[0].map(h => h.trim().toLowerCase());
  const iId = cab.indexOf('id');
  if (iId < 0) { aviso('err', 'Falta a coluna <b>id</b>. Baixe o modelo desta tela e não apague essa coluna.'); return; }

  const cols = colunas();
  const indice = cols.map(col => ({ ...col, i: cab.indexOf(col.rot.toLowerCase()) })).filter(col => col.i >= 0);
  if (!indice.length) {
    aviso('err', `Não achei nenhuma coluna de ${tipo === 'letras' ? 'letras' : 'estatística'}. `
      + `Confira se o arquivo é de <b>${tipo === 'letras' ? 'Letras' : 'Estatísticas'}</b> — o seletor no topo decide o tipo.`);
    return;
  }

  let fora = 0, invalidos = 0, semMudanca = 0;
  const porTime = {};
  const novos = {};

  for (const l of linhas.slice(1)) {
    const id = parseInt(l[iId], 10);
    const j = modal.porId[id];
    if (!j) { if (l[iId]) fora++; continue; }

    const rec = {};
    let mudou = false;
    for (const { c, max, i } of indice) {
      const bruto = String(l[i] ?? '').trim();
      if (bruto === '') continue;                       // em branco: mantém o de agora
      if (tipo === 'letras') {
        const v = bruto.toUpperCase();
        if (!NOTAS.includes(v)) { invalidos++; continue; }
        rec[c] = v;
      } else {
        const n = parseFloat(bruto.replace(',', '.'));
        if (isNaN(n) || n < 0 || n > max) { invalidos++; continue; }
        rec[c] = c === 'games' ? Math.round(n) : Math.round(n * 10) / 10;
      }
      if (!igual(rec[c], j[c])) mudou = true;
    }
    if (!mudou) { if (Object.keys(rec).length) semMudanca++; continue; }
    novos[id] = rec;
    porTime[j.time] = (porTime[j.time] || 0) + 1;
  }

  modal.novos = novos;
  renderModal();

  const n = Object.keys(novos).length;
  let txt = n
    ? `<b>${n} jogador(es) com mudança</b>, destacados em âmbar. Nada foi gravado ainda.`
    : 'O CSV não trouxe nenhuma mudança em relação ao que já está lançado.';
  if (modal.escopo === 'liga' && n) {
    txt += '<br>' + Object.entries(porTime).map(([t, q]) => `${esc(t)}: ${q}`).join(' · ');
  }
  if (semMudanca) txt += `<br>${semMudanca} linha(s) iguais ao que já está lá.`;
  if (fora) txt += `<br>${fora} linha(s) com id que não é ${modal.escopo === 'liga' ? 'desta liga' : 'deste time'} — ignoradas.`;
  if (invalidos) txt += `<br>${invalidos} valor(es) fora da escala ${tipo === 'letras' ? 'A+ até F' : 'aceita'} — ignorados.`;
  aviso(n ? ((fora || invalidos) ? 'warn' : 'ok') : 'warn', txt);
}

$('mDescartar').addEventListener('click', () => { modal.novos = {}; aviso('', ''); renderModal(); });

/* ── Salvar ────────────────────────────────────────────────────────── */
$('mSalvar').addEventListener('click', async () => {
  const btn = $('mSalvar');
  // Estatística vai com a linha INTEIRA: coluna que o CSV não trouxe herda o
  // que já está lançado — sem isso, um arquivo só com PTS zeraria o resto.
  // Letra vai só com o que mudou: em branco o servidor mantém a de agora.
  const linhas = Object.entries(modal.novos).map(([id, rec]) => {
    const j = modal.porId[id] || {};
    const linha = { id: parseInt(id, 10) };
    if (tipo === 'stats') {
      for (const c of Object.keys(STATS)) linha[c] = c in rec ? rec[c] : (j[c] === '' ? '' : j[c]);
    } else {
      Object.assign(linha, rec);
    }
    return linha;
  });

  btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Salvando…';
  try {
    const d = await getJSON(API, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ acao: 'salvar', liga, tipo, linhas, temporada: modal.temporadaId })
    });
    let txt = `Gravado: ${d.times.map(t => `${esc(t.nome)} (${t.jogadores})`).join(', ')}.`;
    if (d.vazios) txt += ` ${d.vazios} linha(s) sem dado foram puladas.`;
    if (d.ignorados) txt += ` ${d.ignorados} jogador(es) não são mais da liga.`;
    if (d.moedas) txt += `<br><b>+${d.moedas} moedas</b> pra você.`;
    modal.novos = {};
    aviso('ok', txt);
    // Recarrega o que está na tela: o valor "de agora" passou a ser o novo.
    const e = await getJSON(urlElencoModal());
    modal.jogadores = e.jogadores || [];
    modal.porId = Object.fromEntries(modal.jogadores.map(j => [j.id, j]));
    renderModal();
    carregar();
  } catch (err) {
    aviso('err', esc(err.message));
  } finally {
    btn.innerHTML = '<i class="bi bi-save2"></i> Salvar';
    btn.disabled = Object.keys(modal.novos).length === 0;
  }
});

// Menu lateral no celular — mesmo comportamento das outras telas.
const sidebar = document.getElementById('sidebar');
const sbOverlay = document.getElementById('sbOverlay');
document.getElementById('menuBtn')?.addEventListener('click', () => {
  sidebar?.classList.toggle('open');
  sbOverlay?.classList.toggle('show');
});
sbOverlay?.addEventListener('click', () => {
  sidebar?.classList.remove('open');
  sbOverlay.classList.remove('show');
});

renderBarra();
carregar().then(() => {
  if (TIME_INICIAL && times.some(t => t.id === TIME_INICIAL)) abrirModal('time', TIME_INICIAL);
});
</script>
</body>
</html>
