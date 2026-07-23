<?php
/**
 * Tabela da liga — classificação por conferência e playoffs, por temporada.
 *
 * Reflete o que o admin lançou no card "Posições" e no registro de playoffs.
 * Quando só parte das posições foi informada, os times restantes da
 * conferência aparecem depois, marcados como não confirmados.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

$stmtTeam = $pdo->prepare('SELECT id, city, name, league, photo_url FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC) ?: null;

$isAdmin = hasAdminAccess($pdo, (int)$user['id']);
$minhaLiga = strtoupper((string)($team['league'] ?? $user['league'] ?? 'ELITE'));
$meuTimeId = $team ? (int)$team['id'] : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<title>Tabela · FBA Manager</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Inter:wght@400;500;600&family=Oswald:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:color-mix(in srgb, var(--red) 10%, transparent);--border-red:color-mix(in srgb, var(--red) 25%, transparent);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1e1e24;--border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);--text:#f0f0f3;--text-2:#868690;--text-3:#7d7d85;--amber:#f59e0b;--green:#22c55e;--radius:14px;--radius-sm:10px;--font:'Montserrat',sans-serif;--sidebar-w:260px;--t:.2s;--ease:cubic-bezier(.4,0,.2,1)}
:root[data-theme="light"]{--bg:#f6f7fb;--panel:#fff;--panel-2:#f2f4f8;--panel-3:#e9edf4;--border:#e3e6ee;--border-md:#d7dbe6;--text:#111217;--text-2:#5b6270;--text-3:#657080}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.app{display:flex;min-height:100vh}
.sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;overflow-y:auto;scrollbar-width:none;transition:transform var(--t) var(--ease)}
.sidebar::-webkit-scrollbar{display:none}
.sb-team{margin:14px 14px 0;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-team img{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-team-name{font-size:13px;font-weight:600;line-height:1.2}
.sb-team-league{font-size:11px;color:var(--red);font-weight:600}
.sb-nav{flex:1;padding:12px 10px 8px}
.sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 5px}
.sb-nav a{font-family:'Inter',sans-serif;display:flex;align-items:center;gap:10px;padding:10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease)}
.sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0}
.sb-nav a:hover{background:var(--panel-2);color:var(--text)}
.sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-username{font-size:12px;font-weight:500;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;flex-shrink:0}
.sb-logout:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
.topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:240}
.topbar-title{font-weight:700;font-size:15px;flex:1}
.menu-btn{width:34px;height:34px;border-radius:9px;background:var(--panel-2);border:1px solid var(--border);color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:17px}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:250}
.sb-overlay.show{display:block}
.main{margin-left:var(--sidebar-w);min-height:100vh;width:calc(100% - var(--sidebar-w))}
.content{max-width:1180px;margin:0 auto;padding:26px 22px 80px}

.page-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
.page-title{font-family:'Oswald',sans-serif;font-size:25px;font-weight:700;display:flex;align-items:center;gap:10px}
.page-title i{color:var(--red)}
.page-sub{font-size:13px;color:var(--text-2);margin-top:5px}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px}
.section-title{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.section-title i{color:var(--red)}

.filtros{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:18px 0 14px}
.fbtn{padding:8px 16px;border-radius:999px;background:var(--panel);border:1px solid var(--border);color:var(--text-2);font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease)}
.fbtn:hover{border-color:var(--border-md);color:var(--text)}
.fbtn.active{background:var(--red);border-color:var(--red);color:#fff}
.sel{background:var(--panel);border:1px solid var(--border-md);color:var(--text);border-radius:999px;padding:8px 14px;font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer}
.sel:focus{outline:none;border-color:var(--red)}
.badge-atual{font-size:10px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;padding:3px 9px;border-radius:999px;background:var(--red-soft);border:1px solid var(--border-red);color:var(--red)}

/* Conferências */
.confs{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.conf-h{display:flex;align-items:center;gap:8px;font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);margin-bottom:12px}
.conf-h .dot{width:8px;height:8px;border-radius:50%;background:var(--red)}
.trow{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:9px;border-bottom:1px solid var(--border)}
.trow:last-child{border-bottom:none}
.trow:hover{background:var(--panel-2)}
.trow.po{background:color-mix(in srgb, var(--green) 6%, transparent)}
.trow.meu{outline:1px solid var(--border-red);background:var(--red-soft)}
.tpos{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;width:26px;text-align:right;color:var(--text-2);flex-shrink:0}
.trow.po .tpos{color:var(--green)}
.tlogo{width:28px;height:28px;border-radius:7px;object-fit:cover;border:1px solid var(--border-md);background:var(--panel-3);flex-shrink:0}
.tname{flex:1;min-width:0;font-size:12.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tname a{color:inherit;text-decoration:none}
.tname a:hover{color:var(--red)}
.tflag{font-size:9px;font-weight:700;padding:2px 7px;border-radius:999px;background:var(--panel-3);border:1px solid var(--border);color:var(--text-3);flex-shrink:0}
.corte{display:flex;align-items:center;gap:8px;margin:6px 0;font-size:9.5px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--green)}
.corte::before,.corte::after{content:'';flex:1;height:1px;background:color-mix(in srgb, var(--green) 28%, transparent)}

/* Playoffs */
.po-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
.po-fase{background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:13px 15px}
.po-fase h4{font-family:'Oswald',sans-serif;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-3);margin-bottom:10px}
.po-fase.camp{border-color:rgba(245,158,11,.4);background:rgba(245,158,11,.07)}
.po-fase.camp h4{color:var(--amber)}
.po-fase.vice{border-color:var(--border-md)}
.po-time{display:flex;align-items:center;gap:8px;padding:5px 0;font-size:12.5px}
.po-time img{width:24px;height:24px;border-radius:6px;object-fit:cover;border:1px solid var(--border-md);background:var(--panel-3);flex-shrink:0}
.po-time span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.campeao{display:flex;align-items:center;gap:14px;padding:18px 20px;border-radius:14px;background:linear-gradient(135deg,rgba(245,158,11,.14),transparent);border:1px solid rgba(245,158,11,.35);margin-bottom:16px}
.campeao img{width:64px;height:64px;border-radius:14px;object-fit:cover;border:2px solid var(--amber);background:var(--panel-3);flex-shrink:0}
.campeao .lbl{font-size:10px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:var(--amber)}
.campeao .nm{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700;line-height:1.15}
.campeao .sub{font-size:12px;color:var(--text-2);margin-top:2px}

.vazio{text-align:center;padding:26px;color:var(--text-3);font-size:13px}
.nota{font-size:11px;color:var(--text-3);margin-top:12px;display:flex;align-items:center;gap:6px}

@media (max-width:992px){
  :root{--sidebar-w:0px}
  .sidebar{transform:translateX(-260px)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;width:100%;padding-top:54px}
  .topbar{display:flex}
  .content{padding:16px 14px 60px}
}
@media (max-width:820px){
  .confs{grid-template-columns:1fr}
  .campeao{gap:12px;padding:14px}
  .campeao img{width:52px;height:52px}
  .campeao .nm{font-size:18px}
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
  <div class="topbar-title">Tabela</div>
</header>

<main class="main">
 <div class="content">

  <div class="page-eyebrow" id="eyebrow"><?= htmlspecialchars($minhaLiga) ?></div>
  <h1 class="page-title"><i class="bi bi-table"></i> Tabela</h1>
  <p class="page-sub">Classificação e playoffs por temporada, conforme lançado pela administração.</p>

  <div class="filtros">
    <?php foreach (['ELITE','NEXT','RISE','ROOKIE'] as $lg): ?>
      <button type="button" class="fbtn<?= $lg === $minhaLiga ? ' active' : '' ?>" data-liga="<?= $lg ?>"><?= $lg ?></button>
    <?php endforeach; ?>
    <select class="sel" id="selTemporada" style="margin-left:auto"></select>
  </div>

  <div id="conteudo"><div class="panel vazio">Carregando…</div></div>

 </div>
</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
  document.getElementById('menuBtn')?.addEventListener('click', () => { sb?.classList.add('open'); ov?.classList.add('show'); });
  ov?.addEventListener('click', () => { sb?.classList.remove('open'); ov?.classList.remove('show'); });
  const btn = document.querySelector('[data-theme-toggle]');
  const aplica = t => { document.documentElement.dataset.theme = t; localStorage.setItem('fba-theme', t);
    if (btn) btn.innerHTML = t === 'light' ? '<i class="bi bi-sun"></i><span>Modo claro</span>' : '<i class="bi bi-moon"></i><span>Modo escuro</span>'; };
  aplica(localStorage.getItem('fba-theme') || 'dark');
  btn?.addEventListener('click', () => aplica(document.documentElement.dataset.theme === 'light' ? 'dark' : 'light'));
})();

const MEU_TIME = <?= $meuTimeId ?>;
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const LOGO = '/img/default-team.png';

let LIGA = '<?= $minhaLiga ?>', TEMPORADAS = [], SEASON_ID = null;

const FASES = {
  champion:        { t: 'Campeão',            cls: 'camp' },
  runner_up:       { t: 'Vice-campeão',       cls: 'vice' },
  conference_final:{ t: 'Final de Conferência', cls: '' },
  second_round:    { t: 'Semifinal de Conferência', cls: '' },
  first_round:     { t: 'Primeira Rodada',    cls: '' },
};

function linhaTime(t, i) {
  const po = t.position <= 8;           // zona de playoffs
  const meu = Number(t.team_id) === MEU_TIME;
  return `
    ${i === 8 ? '<div class="corte">Zona de playoffs</div>' : ''}
    <div class="trow ${po ? 'po' : ''} ${meu ? 'meu' : ''}">
      <span class="tpos">${t.position}º</span>
      <img class="tlogo" src="${esc(t.photo_url || LOGO)}" alt="" onerror="this.src='${LOGO}'">
      <span class="tname"><a href="/team-history.php?team_id=${t.team_id}">${esc(t.name)}</a></span>
      ${t.informado ? '' : '<span class="tflag" title="Posição não lançada pela administração">não confirmada</span>'}
    </div>`;
}

function render(d) {
  const box = $('conteudo');
  if (!d.season) {
    box.innerHTML = '<div class="panel vazio">Nenhuma temporada com classificação lançada nesta liga.</div>';
    return;
  }

  const confs = d.conferences || {};
  const campeao = (d.playoffs || []).find(p => p.position === 'champion');
  const vice    = (d.playoffs || []).find(p => p.position === 'runner_up');

  let html = '';

  if (campeao) {
    html += `<div class="campeao">
      <img src="${esc(campeao.photo_url || LOGO)}" alt="" onerror="this.src='${LOGO}'">
      <div style="min-width:0">
        <div class="lbl"><i class="bi bi-trophy-fill"></i> Campeão · T${d.season.season_number}</div>
        <div class="nm">${esc(campeao.name)}</div>
        ${vice ? `<div class="sub">Vice: ${esc(vice.name)}</div>` : ''}
      </div>
    </div>`;
  }

  const temAlgo = Object.values(confs).some(l => l.length);
  if (temAlgo) {
    html += '<div class="confs">';
    for (const [conf, lista] of Object.entries(confs)) {
      if (!lista.length) continue;
      const naoConf = lista.filter(x => !x.informado).length;
      html += `<div class="panel">
        <div class="conf-h"><span class="dot"></span> Conferência ${esc(conf)}</div>
        ${lista.map(linhaTime).join('')}
        ${naoConf ? `<div class="nota"><i class="bi bi-info-circle"></i> ${naoConf} time(s) sem posição lançada — ordem provisória.</div>` : ''}
      </div>`;
    }
    html += '</div>';
  }

  // Playoffs por fase, do campeão para trás
  const porFase = {};
  (d.playoffs || []).forEach(p => { (porFase[p.position] ||= []).push(p); });
  const fases = Object.keys(FASES).filter(f => porFase[f]?.length);
  if (fases.length) {
    html += `<div class="section-title" style="margin-top:22px"><i class="bi bi-diagram-3-fill"></i> Playoffs</div>
      <div class="po-grid">` +
      fases.map(f => `<div class="po-fase ${FASES[f].cls}">
        <h4>${FASES[f].t}</h4>
        ${porFase[f].map(p => `<div class="po-time">
          <img src="${esc(p.photo_url || LOGO)}" alt="" onerror="this.src='${LOGO}'">
          <span>${esc(p.name)}</span></div>`).join('')}
      </div>`).join('') + '</div>';
  } else if (temAlgo) {
    html += '<div class="panel vazio" style="margin-top:16px">Playoffs ainda não lançados nesta temporada.</div>';
  }

  box.innerHTML = html || '<div class="panel vazio">Nada lançado nesta temporada.</div>';
}

async function carregarTemporadas() {
  const r = await fetch(`/api/tabela.php?action=seasons&league=${encodeURIComponent(LIGA)}`);
  const d = await r.json();
  TEMPORADAS = d.seasons || [];
  const sel = $('selTemporada');
  if (!TEMPORADAS.length) {
    sel.innerHTML = '<option>Sem temporadas</option>';
    render({ season: null });
    return;
  }
  // A mais recente primeiro, marcada como atual.
  sel.innerHTML = TEMPORADAS.map((s, i) =>
    `<option value="${s.id}">T${s.season_number}${s.year ? ' · ' + s.year : ''}${i === 0 ? ' (atual)' : ''}</option>`
  ).join('');
  SEASON_ID = TEMPORADAS[0].id;
  sel.value = SEASON_ID;
  await carregarTabela();
}

async function carregarTabela() {
  $('conteudo').innerHTML = '<div class="panel vazio">Carregando…</div>';
  const r = await fetch(`/api/tabela.php?action=table&season_id=${SEASON_ID}&league=${encodeURIComponent(LIGA)}`);
  render(await r.json());
}

document.querySelectorAll('.fbtn').forEach(b => b.addEventListener('click', async () => {
  document.querySelectorAll('.fbtn').forEach(x => x.classList.toggle('active', x === b));
  LIGA = b.dataset.liga;
  $('eyebrow').textContent = LIGA;
  await carregarTemporadas();
}));
$('selTemporada').addEventListener('change', e => { SEASON_ID = e.target.value; carregarTabela(); });

carregarTemporadas();
</script>
</body>
</html>
