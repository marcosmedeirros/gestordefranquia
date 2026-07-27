<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$user = getUserSession();
$pdo  = db();
// Liga inicial: a do time do usuário, senão ELITE
$stmtT = $pdo->prepare("SELECT league FROM teams WHERE user_id = ? LIMIT 1");
$stmtT->execute([$user['id']]);
$myLeague = $stmtT->fetchColumn() ?: 'ELITE';
if (!in_array($myLeague, ['ELITE', 'NEXT', 'RISE'], true)) $myLeague = 'ELITE';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<title>Retrospectiva · FBA Manager</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:color-mix(in srgb,var(--red) 10%,transparent);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1e1e24;--border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);--text:#f0f0f3;--text-2:#8a8a94;--text-3:#6f6f78;--amber:#f59e0b;--green:#22c55e;--blue:#60a5fa;--radius:14px;--sidebar-w:260px;--font:'Montserrat',sans-serif;--t:.2s;--ease:cubic-bezier(.4,0,.2,1)}
:root[data-theme="light"]{--bg:#f6f7fb;--panel:#fff;--panel-2:#f2f4f8;--panel-3:#e9edf4;--border:#e3e6ee;--border-md:#d7dbe6;--text:#111217;--text-2:#5b6270;--text-3:#7a8291}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.app{display:flex;min-height:100vh}
.main{margin-left:var(--sidebar-w);width:calc(100% - var(--sidebar-w));min-height:100vh}
.wrap{max-width:1280px;margin:0 auto;padding:22px 20px 80px}
@media(max-width:992px){:root{--sidebar-w:0px}.main{width:100%;padding-top:54px}}

.topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:260}
.menu-btn{width:34px;height:34px;border-radius:9px;background:var(--panel-2);border:1px solid var(--border);color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:17px}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:250}
.sb-overlay.show{display:block}
@media(max-width:992px){.topbar{display:flex}}

/* Header */
.hero{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.hero h1{font-family:'Oswald',sans-serif;font-size:30px;font-weight:700;letter-spacing:.5px;line-height:1}
.hero h1 em{color:var(--red);font-style:normal}
.hero .sub{font-size:13px;color:var(--text-2);margin-top:6px}
.league-tabs{margin-left:auto;display:flex;gap:6px;background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:4px}
.league-tabs button{padding:8px 16px;border-radius:9px;background:transparent;border:none;color:var(--text-2);font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;transition:all var(--t)}
.league-tabs button.active{background:var(--red);color:#fff}

/* Tabs */
.tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:20px;overflow-x:auto;scrollbar-width:none}
.tabs::-webkit-scrollbar{display:none}
.tab{padding:11px 16px;background:none;border:none;border-bottom:2px solid transparent;color:var(--text-2);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all var(--t)}
.tab:hover{color:var(--text)}
.tab.active{color:var(--red);border-bottom-color:var(--red)}
.tabpane{display:none}
.tabpane.active{display:block;animation:fade .25s var(--ease)}
@keyframes fade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}

.card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px}
.section-title{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-2);margin:0 0 14px;display:flex;align-items:center;gap:8px}
.section-title i{color:var(--red)}
.muted{color:var(--text-3);font-size:13px}
.empty{text-align:center;color:var(--text-3);padding:40px 16px;font-size:14px}

/* Superlative cards */
.super-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:20px}
.super{background:linear-gradient(150deg,var(--panel-2),var(--panel));border:1px solid var(--border);border-radius:14px;padding:15px 16px;position:relative;overflow:hidden}
.super .ic{font-size:20px;margin-bottom:8px;display:block}
.super .lab{font-size:10px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:var(--text-3)}
.super .val{font-family:'Oswald',sans-serif;font-size:20px;font-weight:700;margin-top:3px;line-height:1.15}
.super .meta{font-size:11.5px;color:var(--text-2);margin-top:3px}

/* Leaderboard */
.lb{width:100%;border-collapse:collapse;font-size:13px}
.lb th{text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);padding:8px 10px;border-bottom:1px solid var(--border)}
.lb td{padding:8px 10px;border-bottom:1px solid var(--border);vertical-align:middle}
.lb tr:last-child td{border-bottom:none}
.lb .num{text-align:right;font-family:'Oswald',sans-serif;font-weight:700}
.lb .pos{width:34px;color:var(--text-3);font-family:'Oswald',sans-serif;font-weight:700}
.lb .tm{display:flex;align-items:center;gap:9px;min-width:0}
.lb .tm img{width:24px;height:24px;border-radius:7px;object-fit:cover;border:1px solid var(--border-md);background:var(--panel-3);flex-shrink:0}
.lb .tm .nm{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.medal{color:var(--amber)}

/* Heatmap */
.hm-scroll{overflow-x:auto;scrollbar-width:thin}
.hm{border-collapse:separate;border-spacing:3px;font-size:11px}
.hm th.col{font-family:'Oswald',sans-serif;font-size:11px;font-weight:600;color:var(--text-2);text-align:center;padding:2px 0;white-space:nowrap}
.hm th.col small{display:block;font-size:9px;color:var(--text-3);font-weight:400}
.hm td.team,.hm th.team{text-align:left;padding-right:10px;position:sticky;left:0;background:var(--panel);z-index:2}
.hm .abbr{font-family:'Oswald',sans-serif;font-weight:700;font-size:12px}
.hm .tn{font-size:10.5px;color:var(--text-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px}
.hm .cell{width:34px;height:30px;text-align:center;border-radius:7px;font-family:'Oswald',sans-serif;font-weight:700;font-size:12px;color:#1a1a1a}
.hm .cell.empty{background:var(--panel-3)!important;color:var(--text-3)}
.legend{display:flex;gap:14px;flex-wrap:wrap;margin-top:14px;font-size:11.5px;color:var(--text-2)}
.legend span{display:inline-flex;align-items:center;gap:6px}
.legend i{width:16px;height:16px;border-radius:5px;display:inline-block}

/* Champions */
.champ-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.champ{background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:14px;display:flex;align-items:center;gap:12px}
.champ img{width:46px;height:46px;border-radius:11px;object-fit:cover;border:1px solid var(--border-md);background:var(--panel-3);flex-shrink:0}
.champ .season{font-size:10px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--amber)}
.champ .cname{font-weight:700;font-size:13.5px;line-height:1.2;margin-top:2px}
.champ .vice{font-size:11px;color:var(--text-3);margin-top:2px}

/* Awards table */
.aw{width:100%;border-collapse:collapse;font-size:12.5px}
.aw th{text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--text-3);padding:8px;border-bottom:1px solid var(--border);white-space:nowrap}
.aw td{padding:8px;border-bottom:1px solid var(--border)}
.aw tr:last-child td{border-bottom:none}
.aw .pl{font-weight:600}
.aw .tm{font-size:11px;color:var(--text-3)}
.aw .s{font-family:'Oswald',sans-serif;font-weight:700;color:var(--red);white-space:nowrap}
</style>
</head>
<body>
<div class="app">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>
<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div style="font-weight:700">Retrospectiva</div>
</header>

<main class="main">
 <div class="wrap">
  <div class="hero">
    <div>
      <h1><i class="bi bi-hourglass-split" style="color:var(--red)"></i> Retro<em>spectiva</em></h1>
      <div class="sub" id="heroSub">Panorama histórico da liga — temporada a temporada</div>
    </div>
    <div class="league-tabs" id="leagueTabs">
      <?php foreach (['ELITE','NEXT','RISE'] as $lg): ?>
      <button data-league="<?= $lg ?>" class="<?= $lg === $myLeague ? 'active' : '' ?>"><?= $lg ?></button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="tabs" id="tabs">
    <button class="tab active" data-tab="panorama"><i class="bi bi-grid-1x2"></i> Panorama</button>
    <button class="tab" data-tab="acumulado"><i class="bi bi-diagram-3"></i> Posição no Acumulado</button>
    <button class="tab" data-tab="campeoes"><i class="bi bi-trophy"></i> Campeões</button>
    <button class="tab" data-tab="premios"><i class="bi bi-award"></i> Prêmios</button>
  </div>

  <div id="content"><div class="empty"><i class="bi bi-hourglass"></i> Carregando…</div></div>
 </div>
</main>
</div>

<script>
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
let LEAGUE = <?= json_encode($myLeague) ?>;
let TAB = 'panorama';
let DATA = null;

/* sidebar mobile + tema */
(function(){
  const sb=$('sidebar'),ov=$('sbOverlay'),mb=$('menuBtn');
  if(mb)mb.onclick=()=>{sb?.classList.add('open');ov?.classList.add('show')};
  if(ov)ov.onclick=()=>{sb?.classList.remove('open');ov.classList.remove('show')};
  const tt=$('themeToggle');
  if(tt)tt.onclick=()=>{const l=document.documentElement.getAttribute('data-theme')==='light';localStorage.setItem('fba-theme',l?'dark':'light');document.documentElement.setAttribute('data-theme',l?'dark':'light')};
})();

function tier(rank){
  if(rank===1) return {bg:'#f0c14b'};
  if(rank<=4)  return {bg:'#a9c9ea'};
  if(rank<=8)  return {bg:'#a7ddc4'};
  if(rank<=16) return {bg:'#d9d7cf'};
  return {bg:'#f2b8b5'};
}
const LOGO = '/img/default-team.png';
const img = (u,cls) => `<img class="${cls}" src="${esc(u||LOGO)}" onerror="this.src='${LOGO}'">`;

async function load(){
  $('content').innerHTML = '<div class="empty"><i class="bi bi-hourglass"></i> Carregando…</div>';
  try{
    const r = await fetch(`/api/retrospectiva.php?league=${encodeURIComponent(LEAGUE)}`);
    DATA = await r.json();
    if(!DATA.success){ $('content').innerHTML=`<div class="empty">Erro: ${esc(DATA.error||'')}</div>`; return; }
    if(DATA.empty || !DATA.seasons.length){ $('content').innerHTML='<div class="empty">Ainda não há temporadas registradas para esta liga.</div>'; return; }
    $('heroSub').textContent = `${LEAGUE} · ${DATA.seasons.length} temporada${DATA.seasons.length>1?'s':''} · ${DATA.teams.length} times`;
    render();
  }catch(e){ $('content').innerHTML='<div class="empty">Falha ao carregar.</div>'; }
}

function render(){
  if(TAB==='panorama') return renderPanorama();
  if(TAB==='acumulado') return renderHeatmap();
  if(TAB==='campeoes') return renderChampions();
  if(TAB==='premios') return renderAwards();
}

function renderPanorama(){
  const s = DATA.superlatives || {};
  const card = (ic,lab,val,meta) => val ? `<div class="super"><span class="ic">${ic}</span><div class="lab">${lab}</div><div class="val">${esc(val)}</div>${meta?`<div class="meta">${esc(meta)}</div>`:''}</div>` : '';
  const supers = [
    card('🏆','Mais títulos', s.most_titles?.team, s.most_titles?`${s.most_titles.titles} título(s)`:''),
    card('👑','Mais tempo em 1º', s.top_dog?.team, s.top_dog?`${s.top_dog.seasons} temporada(s) no topo`:''),
    card('🔥','Maior pontuação', s.best_season?`${s.best_season.points} pts`:null, s.best_season?`${s.best_season.team} · ${s.best_season.season||''}`:''),
    card('📈','Maior escalada', s.biggest_riser && s.biggest_riser.delta>0?s.biggest_riser.team:null, s.biggest_riser?`subiu ${s.biggest_riser.delta} posições (${s.biggest_riser.from}º → ${s.biggest_riser.to}º)`:''),
    card('🎯','Mais consistente', s.most_consistent?.team, s.most_consistent?`${s.most_consistent.avg}º na média do acumulado`:''),
    card('📅','Histórico', `${s.n_seasons} temporadas`, `${s.n_champions} campeões diferentes`),
  ].filter(Boolean).join('');

  const lb = DATA.leaderboard.map((t,i)=>`
    <tr>
      <td class="pos">${i+1}</td>
      <td><div class="tm">${img(t.photo_url,'')}<span class="nm">${esc(t.name)}</span></div></td>
      <td class="num">${t.total}</td>
      <td class="num">${t.played}</td>
      <td class="num">${t.avg}</td>
      <td class="num">${t.best}</td>
      <td class="num">${t.titles>0?`<span class="medal">🏆 ${t.titles}</span>`:'—'}</td>
    </tr>`).join('');

  $('content').innerHTML = `
    <div class="super-grid">${supers}</div>
    <div class="card">
      <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Ranking histórico — pontos acumulados</div>
      <div style="overflow-x:auto">
        <table class="lb">
          <thead><tr><th></th><th>Time</th><th class="num">Total</th><th class="num">Temp.</th><th class="num">Média</th><th class="num">Melhor</th><th class="num">Títulos</th></tr></thead>
          <tbody>${lb}</tbody>
        </table>
      </div>
    </div>`;
}

function renderHeatmap(){
  const seasons = DATA.seasons;
  // ordena times pelo total acumulado (melhor no topo) usando o leaderboard
  const order = DATA.leaderboard.map(t=>t.team_id);
  const byId = {}; DATA.teams.forEach(t=>byId[t.team_id]=t);
  const head = seasons.map(s=>`<th class="col">${s.label}<small>${s.year?String(s.year).slice(2):''}</small></th>`).join('');
  const rows = order.map(tid=>{
    const t = byId[tid]; if(!t) return '';
    const cells = seasons.map((s,i)=>{
      const rank = (DATA.heatmap[tid]||[])[i];
      if(rank==null) return `<td><div class="cell empty">–</div></td>`;
      return `<td><div class="cell" style="background:${tier(rank).bg}">${rank}</div></td>`;
    }).join('');
    return `<tr>
      <td class="team"><div class="abbr">${esc(t.abbr)}</div><div class="tn">${esc(t.name)}</div></td>
      ${cells}
    </tr>`;
  }).join('');

  $('content').innerHTML = `
    <div class="card">
      <div class="section-title"><i class="bi bi-diagram-3-fill"></i> Posição no acumulado <span class="muted" style="font-weight:400;text-transform:none;letter-spacing:0">· colocação de cada time na soma de pontos até cada temporada</span></div>
      <div class="hm-scroll">
        <table class="hm">
          <thead><tr><th class="team"></th>${head}</tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
      <div class="legend">
        <span><i style="background:#f0c14b"></i> 1º</span>
        <span><i style="background:#a9c9ea"></i> 2º–4º</span>
        <span><i style="background:#a7ddc4"></i> 5º–8º</span>
        <span><i style="background:#d9d7cf"></i> 9º–16º</span>
        <span><i style="background:#f2b8b5"></i> 17º+</span>
      </div>
    </div>`;
}

function renderChampions(){
  const champs = (DATA.champions||[]).filter(c=>c.champion).slice().reverse();
  const counts = {};
  (DATA.champions||[]).forEach(c=>{ if(c.champion) counts[c.champion]=(counts[c.champion]||0)+1; });
  const rank = Object.entries(counts).sort((a,b)=>b[1]-a[1]);
  const grid = champs.length ? champs.map(c=>`
    <div class="champ">
      ${img(c.champion_photo,'')}
      <div style="min-width:0">
        <div class="season">T${c.season_number}${c.year?` · ${c.year}`:''}</div>
        <div class="cname">🏆 ${esc(c.champion)}</div>
        ${c.runner_up?`<div class="vice">vice: ${esc(c.runner_up)}</div>`:''}
      </div>
    </div>`).join('') : '<div class="empty">Nenhum campeão registrado ainda.</div>';
  const rankHtml = rank.length ? `
    <div class="card" style="margin-top:16px">
      <div class="section-title"><i class="bi bi-stack"></i> Títulos por franquia</div>
      <table class="lb"><tbody>${rank.map(([nm,c],i)=>`<tr><td class="pos">${i+1}</td><td><span class="nm" style="font-weight:600">${esc(nm)}</span></td><td class="num">${'🏆'.repeat(Math.min(c,5))} ${c}</td></tr>`).join('')}</tbody></table>
    </div>` : '';
  $('content').innerHTML = `
    <div class="card">
      <div class="section-title"><i class="bi bi-trophy-fill"></i> Campeões por temporada</div>
      <div class="champ-grid">${grid}</div>
    </div>${rankHtml}`;
}

function renderAwards(){
  const aw = (DATA.awards||[]).filter(a=>a.mvp.player||a.dpoy.player||a.roy.player||a.mip.player||a.sixth.player).slice().reverse();
  const cell = o => o && o.player ? `<div class="pl">${esc(o.player)}</div><div class="tm">${esc(o.team||'')}</div>` : '<span class="muted">—</span>';
  const rows = aw.length ? aw.map(a=>`
    <tr>
      <td class="s">T${a.season_number}${a.year?`<br><span class="tm">${a.year}</span>`:''}</td>
      <td>${cell(a.mvp)}</td><td>${cell(a.dpoy)}</td><td>${cell(a.mip)}</td><td>${cell(a.sixth)}</td><td>${cell(a.roy)}</td>
    </tr>`).join('') : '<tr><td colspan="6" class="empty">Nenhum prêmio registrado ainda.</td></tr>';
  $('content').innerHTML = `
    <div class="card">
      <div class="section-title"><i class="bi bi-award-fill"></i> Prêmios individuais por temporada</div>
      <div style="overflow-x:auto">
        <table class="aw">
          <thead><tr><th>Temp.</th><th>MVP</th><th>DPOY</th><th>MIP</th><th>6º Homem</th><th>ROY</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    </div>`;
}

/* eventos */
$('leagueTabs').addEventListener('click', e=>{
  const b = e.target.closest('button'); if(!b) return;
  LEAGUE = b.dataset.league;
  [...$('leagueTabs').children].forEach(x=>x.classList.toggle('active', x===b));
  load();
});
$('tabs').addEventListener('click', e=>{
  const b = e.target.closest('.tab'); if(!b) return;
  TAB = b.dataset.tab;
  [...$('tabs').children].forEach(x=>x.classList.toggle('active', x===b));
  if(DATA) render();
});

load();
</script>
</body>
</html>
