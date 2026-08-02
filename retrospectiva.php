<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$user = getUserSession();
$pdo  = db();
$stmtT = $pdo->prepare("SELECT league FROM teams WHERE user_id = ? LIMIT 1");
$stmtT->execute([$user['id']]);
$myLeague = $stmtT->fetchColumn() ?: 'ELITE';
if (!in_array($myLeague, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) $myLeague = 'ELITE';
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
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025; --red-2:#ff3355;
  --bg:#08080c; --bg-2:#0d0d13;
  --panel:#141419; --panel-2:#1a1a22; --panel-3:#22222c;
  --border:rgba(255,255,255,.08); --border-2:rgba(255,255,255,.14);
  --ink:#f4f4f7; --ink-2:#a2a2ae; --ink-3:#6c6c78;
  --gold:#f4c95d; --blue:#7cb3e8; --green:#57cfa0; --amber:#f59e0b;
  --radius:18px; --radius-sm:12px;
  --disp:'Oswald',sans-serif; --body:'Inter',sans-serif;
  --shadow:0 20px 60px -24px rgba(0,0,0,.7);
  --t:.22s cubic-bezier(.4,0,.2,1);
  /* tiers do heatmap */
  --t1:#f4c95d; --t2:#9ec5ec; --t3:#9ad9bd; --t4:#d6d9df; --t5:#f2aca6;
}
:root[data-theme="light"]{
  --bg:#eef1f7; --bg-2:#e7ebf3;
  --panel:#ffffff; --panel-2:#f4f6fb; --panel-3:#e9edf4;
  --border:#e4e8f0; --border-2:#d3d9e6;
  --ink:#12141a; --ink-2:#5a6172; --ink-3:#8b93a4;
  --shadow:0 18px 50px -28px rgba(20,30,60,.35);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{
  font-family:var(--body); color:var(--ink); line-height:1.5;
  background:
    radial-gradient(1100px 520px at 50% -160px, color-mix(in srgb,var(--red) 20%, transparent), transparent 70%),
    linear-gradient(180deg,var(--bg-2),var(--bg));
  background-attachment:fixed; min-height:100vh; -webkit-font-smoothing:antialiased;
}
a{color:inherit;text-decoration:none}
.page{max-width:1200px;margin:0 auto;padding:20px 20px 90px}

/* Topbar */
.top{display:flex;align-items:center;gap:12px;margin-bottom:34px}
.brand{display:flex;align-items:center;gap:11px}
.brand .mark{width:40px;height:40px;border-radius:11px;background:linear-gradient(135deg,var(--red),#c30f38);display:flex;align-items:center;justify-content:center;color:#fff;font-family:var(--disp);font-weight:700;font-size:15px;box-shadow:0 8px 22px -8px var(--red)}
.brand b{font-family:var(--disp);font-weight:600;font-size:16px;letter-spacing:.5px}
.brand span{display:block;font-size:11px;color:var(--ink-3);font-weight:500;letter-spacing:.3px;margin-top:-2px}
.top .right{margin-left:auto;display:flex;gap:8px}
.ghost-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:11px;background:var(--panel);border:1px solid var(--border);color:var(--ink-2);font-family:var(--body);font-size:13px;font-weight:600;cursor:pointer;transition:var(--t)}
.ghost-btn:hover{border-color:var(--border-2);color:var(--ink)}

/* Hero */
.hero{position:relative;margin-bottom:26px}
.hero .kicker{font-family:var(--disp);font-size:12px;font-weight:600;letter-spacing:3px;text-transform:uppercase;color:var(--red);margin-bottom:6px}
.hero h1{font-family:var(--disp);font-size:clamp(38px,7vw,64px);font-weight:700;line-height:.98;letter-spacing:-.5px}
.hero h1 .lg{color:transparent;-webkit-text-stroke:1.4px var(--ink-2)}
.hero .lead{font-size:14.5px;color:var(--ink-2);margin-top:12px;max-width:560px}
.leaguebar{display:flex;gap:7px;margin-top:20px;flex-wrap:wrap}
.leaguebar button{font-family:var(--disp);font-weight:600;font-size:14px;letter-spacing:1px;padding:9px 20px;border-radius:12px;background:var(--panel);border:1px solid var(--border);color:var(--ink-2);cursor:pointer;transition:var(--t)}
.leaguebar button:hover{border-color:var(--border-2);color:var(--ink)}
.leaguebar button.active{background:linear-gradient(135deg,var(--red),#d11033);border-color:transparent;color:#fff;box-shadow:0 10px 26px -12px var(--red)}

/* headline strip */
.strip{display:flex;gap:0;flex-wrap:wrap;margin-top:22px;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow)}
.strip .cell{flex:1;min-width:120px;padding:16px 20px;border-right:1px solid var(--border)}
.strip .cell:last-child{border-right:none}
.strip .n{font-family:var(--disp);font-size:30px;font-weight:700;line-height:1}
.strip .l{font-size:11px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;color:var(--ink-3);margin-top:5px}

/* Tabs */
.tabs{display:flex;gap:4px;margin:28px 0 22px;overflow-x:auto;scrollbar-width:none;border-bottom:1px solid var(--border)}
.tabs::-webkit-scrollbar{display:none}
.tab{display:inline-flex;align-items:center;gap:8px;padding:12px 18px;background:none;border:none;border-bottom:2px solid transparent;color:var(--ink-3);font-family:var(--body);font-size:13.5px;font-weight:600;cursor:pointer;white-space:nowrap;transition:var(--t)}
.tab i{font-size:15px}
.tab:hover{color:var(--ink)}
.tab.active{color:var(--ink);border-bottom-color:var(--red)}

/* Cards / panels */
.panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:22px;box-shadow:var(--shadow)}
.panel + .panel{margin-top:18px}
.h{display:flex;align-items:center;gap:10px;margin-bottom:18px}
.h .t{font-family:var(--disp);font-size:16px;font-weight:600;letter-spacing:.5px;text-transform:uppercase}
.h .sub{font-size:12.5px;color:var(--ink-3);font-weight:400}
.h .ico{width:30px;height:30px;border-radius:9px;background:var(--red-soft,rgba(252,0,37,.12));display:flex;align-items:center;justify-content:center;color:var(--red);font-size:15px;background:color-mix(in srgb,var(--red) 13%, transparent)}
.empty{text-align:center;color:var(--ink-3);padding:60px 16px;font-size:14px}
.loading{text-align:center;color:var(--ink-3);padding:70px 16px;font-size:14px}
.loading i{font-size:26px;display:block;margin-bottom:12px;animation:spin 1s linear infinite;color:var(--red)}
@keyframes spin{to{transform:rotate(360deg)}}

/* Superlative tiles */
.supers{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;margin-bottom:20px}
.tile{position:relative;background:linear-gradient(155deg,var(--panel-2),var(--panel));border:1px solid var(--border);border-radius:16px;padding:18px;overflow:hidden;transition:var(--t)}
.tile:hover{border-color:var(--border-2);transform:translateY(-2px)}
.tile::after{content:'';position:absolute;top:-40px;right:-40px;width:120px;height:120px;border-radius:50%;background:radial-gradient(circle,color-mix(in srgb,var(--accent,var(--red)) 26%,transparent),transparent 70%);opacity:.5}
.tile .emoji{font-size:24px;position:relative}
.tile .lab{font-size:10.5px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--ink-3);margin-top:12px}
.tile .val{font-family:var(--disp);font-size:22px;font-weight:700;line-height:1.12;margin-top:4px;position:relative}
.tile .meta{font-size:12px;color:var(--ink-2);margin-top:5px;position:relative}

/* Tables */
.tbl{width:100%;border-collapse:separate;border-spacing:0;font-size:13.5px}
.tbl thead th{position:sticky;top:0;text-align:left;font-size:10.5px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--ink-3);padding:0 12px 11px;border-bottom:1px solid var(--border);background:var(--panel)}
.tbl tbody td{padding:11px 12px;border-bottom:1px solid var(--border)}
.tbl tbody tr:last-child td{border-bottom:none}
.tbl tbody tr{transition:background var(--t)}
.tbl tbody tr:hover{background:color-mix(in srgb,var(--ink) 4%, transparent)}
.tbl .num{text-align:right;font-family:var(--disp);font-weight:600}
.tbl .pos{width:44px}
.pos-badge{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:8px;font-family:var(--disp);font-weight:700;font-size:13px;color:var(--ink-2);background:var(--panel-3)}
.pos-badge.g1{background:linear-gradient(135deg,#f4c95d,#d99e2b);color:#3a2a00}
.pos-badge.g2{background:linear-gradient(135deg,#cfd6e2,#9aa4b8);color:#1c2330}
.pos-badge.g3{background:linear-gradient(135deg,#e0a774,#b9793f);color:#2c1600}
.team-cell{display:flex;align-items:center;gap:10px;min-width:0}
.team-cell img{width:30px;height:30px;border-radius:9px;object-fit:cover;border:1px solid var(--border-2);background:var(--panel-3);flex-shrink:0}
.team-cell .nm{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chip{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;background:color-mix(in srgb,var(--gold) 16%,transparent);color:var(--gold);border:1px solid color-mix(in srgb,var(--gold) 30%,transparent)}

/* Heatmap */
.hm-wrap{overflow-x:auto;scrollbar-width:thin;margin:0 -4px;padding:0 4px}
.hm{border-collapse:separate;border-spacing:4px}
.hm thead th.col{font-family:var(--disp);font-size:12px;font-weight:600;color:var(--ink-2);text-align:center;padding-bottom:6px;white-space:nowrap;min-width:38px}
.hm thead th.col small{display:block;font-size:9.5px;color:var(--ink-3);font-weight:400}
.hm .rk{width:36px;color:var(--ink-3);font-family:var(--disp);font-weight:700;font-size:12px;text-align:center}
.hm td.team,.hm th.teamh{text-align:left;position:sticky;left:0;z-index:3;background:var(--panel);padding-right:14px}
.hm td.team .in{display:flex;align-items:center;gap:9px}
.hm td.team img{width:26px;height:26px;border-radius:8px;object-fit:cover;border:1px solid var(--border-2);background:var(--panel-3);flex-shrink:0}
.hm td.team .abbr{font-family:var(--disp);font-weight:700;font-size:13px;letter-spacing:.5px}
.hm td.team .tn{font-size:10.5px;color:var(--ink-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;line-height:1.1}
.hm .cell{width:38px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:9px;font-family:var(--disp);font-weight:700;font-size:13px;color:#171717;cursor:default;transition:transform .12s ease}
.hm .cell:hover{transform:scale(1.14);box-shadow:0 6px 16px -6px rgba(0,0,0,.6);position:relative;z-index:5}
.hm .cell.void{background:var(--panel-3)!important;color:var(--ink-3);font-weight:500}
.hm .tot{font-family:var(--disp);font-weight:700;font-size:14px;text-align:right;padding-left:12px;white-space:nowrap}
.legend{display:flex;gap:16px;flex-wrap:wrap;margin-top:18px;font-size:12px;color:var(--ink-2);font-weight:500}
.legend span{display:inline-flex;align-items:center;gap:7px}
.legend i{width:18px;height:18px;border-radius:6px}

/* Champions */
.champs{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}
.champ{position:relative;background:var(--panel-2);border:1px solid var(--border);border-radius:16px;padding:16px;display:flex;align-items:center;gap:13px;overflow:hidden;transition:var(--t)}
.champ:hover{border-color:var(--border-2);transform:translateY(-2px)}
.champ.latest{border-color:color-mix(in srgb,var(--gold) 40%,transparent);background:linear-gradient(150deg,color-mix(in srgb,var(--gold) 10%,var(--panel-2)),var(--panel-2))}
.champ .logo{width:52px;height:52px;border-radius:13px;object-fit:cover;border:1px solid var(--border-2);background:var(--panel-3);flex-shrink:0}
.champ .season{font-family:var(--disp);font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--gold)}
.champ .cname{font-family:var(--disp);font-weight:600;font-size:16px;line-height:1.1;margin-top:3px}
.champ .vice{font-size:11px;color:var(--ink-3);margin-top:4px}
.champ .ring{position:absolute;top:12px;right:14px;font-size:16px}

/* Awards */
.aw{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
.aw thead th{text-align:left;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ink-3);padding:0 12px 11px;border-bottom:1px solid var(--border);white-space:nowrap}
.aw tbody td{padding:12px;border-bottom:1px solid var(--border);vertical-align:top}
.aw tbody tr:last-child td{border-bottom:none}
.aw tbody tr:hover{background:color-mix(in srgb,var(--ink) 4%, transparent)}
.aw .season-cell{font-family:var(--disp);font-weight:700;color:var(--red);font-size:15px}
.aw .season-cell small{display:block;color:var(--ink-3);font-size:11px;font-weight:400;font-family:var(--body)}
.aw .pl{font-weight:600}
.aw .tm{font-size:11px;color:var(--ink-3);margin-top:1px}
.aw .dash{color:var(--ink-3)}

@media(max-width:640px){
  .page{padding:16px 14px 70px}
  .panel{padding:16px}
  .hero h1{font-size:40px}
  .strip .cell{min-width:50%;flex:1 1 50%}
}
</style>
</head>
<body>
<div class="page">

  <div class="top">
    <a href="/dashboard.php" class="brand">
      <div class="mark">FBA</div>
      <div><b>RETROSPECTIVA</b><span>panorama histórico</span></div>
    </a>
    <div class="right">
      <button class="ghost-btn" id="themeBtn" title="Tema"><i class="bi bi-circle-half"></i></button>
      <a href="/dashboard.php" class="ghost-btn"><i class="bi bi-arrow-left"></i> Voltar</a>
    </div>
  </div>

  <div class="hero">
    <div class="kicker">Liga FBA · linha do tempo</div>
    <h1>A história em <span class="lg">números</span></h1>
    <p class="lead" id="lead">Cada temporada, cada campeão, cada escalada no acumulado — a jornada completa de cada franquia.</p>
    <div class="leaguebar" id="leaguebar">
      <?php foreach (['ELITE','NEXT','RISE','ROOKIE'] as $lg): ?>
      <button data-league="<?= $lg ?>" class="<?= $lg === $myLeague ? 'active' : '' ?>"><?= $lg ?></button>
      <?php endforeach; ?>
    </div>
    <div class="strip" id="strip"></div>
  </div>

  <div class="tabs" id="tabs">
    <button class="tab active" data-tab="panorama"><i class="bi bi-grid-1x2-fill"></i> Panorama</button>
    <button class="tab" data-tab="acumulado"><i class="bi bi-diagram-3-fill"></i> Posição no Acumulado</button>
    <button class="tab" data-tab="campeoes"><i class="bi bi-trophy-fill"></i> Campeões</button>
    <button class="tab" data-tab="premios"><i class="bi bi-award-fill"></i> Prêmios</button>
  </div>

  <div id="content"><div class="loading"><i class="bi bi-hourglass-split"></i> Carregando retrospectiva…</div></div>
</div>

<script>
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
let LEAGUE = <?= json_encode($myLeague) ?>, TAB = 'panorama', DATA = null;
const LOGO='/img/default-team.png';
const img=(u,cls)=>`<img class="${cls||''}" src="${esc(u||LOGO)}" onerror="this.src='${LOGO}'">`;

$('themeBtn').onclick=()=>{const l=document.documentElement.getAttribute('data-theme')==='light';localStorage.setItem('fba-theme',l?'dark':'light');document.documentElement.setAttribute('data-theme',l?'dark':'light')};

const TIER = r => r===1?'var(--t1)':r<=4?'var(--t2)':r<=8?'var(--t3)':r<=16?'var(--t4)':'var(--t5)';
const posBadge = i => `<span class="pos-badge ${i<3?'g'+(i+1):''}">${i+1}</span>`;

async function load(){
  $('content').innerHTML='<div class="loading"><i class="bi bi-hourglass-split"></i> Carregando…</div>';
  $('strip').innerHTML='';
  try{
    const r=await fetch(`/api/retrospectiva.php?league=${encodeURIComponent(LEAGUE)}`);
    DATA=await r.json();
    if(!DATA.success){$('content').innerHTML=`<div class="panel"><div class="empty">Erro: ${esc(DATA.error||'')}</div></div>`;return;}
    if(DATA.empty||!DATA.seasons.length){$('content').innerHTML='<div class="panel"><div class="empty">Ainda não há temporadas registradas nesta liga.</div></div>';return;}
    const s=DATA.superlatives||{};
    $('strip').innerHTML=[
      ['n',DATA.seasons.length,'Temporadas'],
      ['n',DATA.teams.length,'Franquias'],
      ['n',s.n_champions||0,'Campeões diferentes'],
      ['n',(s.most_titles?.titles||0),'Recorde de títulos'],
    ].map(c=>`<div class="cell"><div class="${c[0]}">${c[1]}</div><div class="l">${c[2]}</div></div>`).join('');
    render();
  }catch(e){$('content').innerHTML='<div class="panel"><div class="empty">Falha ao carregar.</div></div>';}
}

function render(){({panorama:renderPanorama,acumulado:renderHeatmap,campeoes:renderChampions,premios:renderAwards}[TAB]||renderPanorama)();}

function renderPanorama(){
  const s=DATA.superlatives||{};
  const tile=(emoji,accent,lab,val,meta)=>val?`<div class="tile" style="--accent:${accent}"><span class="emoji">${emoji}</span><div class="lab">${lab}</div><div class="val">${esc(val)}</div>${meta?`<div class="meta">${esc(meta)}</div>`:''}</div>`:'';
  const tiles=[
    tile('🏆','var(--gold)','Mais títulos', s.most_titles?.team, s.most_titles?`${s.most_titles.titles} título${s.most_titles.titles>1?'s':''}`:''),
    tile('👑','var(--red)','Mais tempo em 1º', s.top_dog?.team, s.top_dog?`${s.top_dog.seasons} temporada${s.top_dog.seasons>1?'s':''} liderando o acumulado`:''),
    tile('🔥','#f97316','Maior pontuação', s.best_season?`${s.best_season.points} pts`:null, s.best_season?`${s.best_season.team} · ${s.best_season.season||''} (${s.best_season.year||''})`:''),
    tile('📈','var(--green)','Maior escalada', (s.biggest_riser&&s.biggest_riser.delta>0)?s.biggest_riser.team:null, s.biggest_riser?`subiu ${s.biggest_riser.delta} posições · ${s.biggest_riser.from}º → ${s.biggest_riser.to}º`:''),
    tile('🎯','var(--blue)','Mais consistente', s.most_consistent?.team, s.most_consistent?`${s.most_consistent.avg}º de média no acumulado`:''),
  ].filter(Boolean).join('');

  const rows=DATA.leaderboard.map((t,i)=>`
    <tr>
      <td class="pos">${posBadge(i)}</td>
      <td><div class="team-cell">${img(t.photo_url)}<span class="nm">${esc(t.name)}</span></div></td>
      <td class="num" style="color:var(--red)">${t.total}</td>
      <td class="num">${t.played}</td>
      <td class="num">${t.avg}</td>
      <td class="num">${t.best}</td>
      <td class="num">${t.titles>0?`<span class="chip">🏆 ${t.titles}</span>`:'<span class="dash" style="color:var(--ink-3)">—</span>'}</td>
    </tr>`).join('');

  $('content').innerHTML=`
    <div class="supers">${tiles}</div>
    <div class="panel">
      <div class="h"><div class="ico"><i class="bi bi-bar-chart-fill"></i></div><div><div class="t">Ranking histórico</div></div><div class="sub" style="margin-left:auto">pontos acumulados em todas as temporadas</div></div>
      <div style="overflow-x:auto">
        <table class="tbl">
          <thead><tr><th></th><th>Franquia</th><th class="num">Total</th><th class="num">Temp.</th><th class="num">Média</th><th class="num">Melhor</th><th class="num">Títulos</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    </div>`;
}

function renderHeatmap(){
  const S=DATA.seasons, order=DATA.leaderboard, byId={};
  DATA.teams.forEach(t=>byId[t.team_id]=t);
  const head=S.map(s=>`<th class="col">${s.label}<small>${s.year?"'"+String(s.year).slice(2):''}</small></th>`).join('');
  const rows=order.map((lt,idx)=>{
    const t=byId[lt.team_id]; if(!t) return '';
    const ranks=DATA.heatmap[lt.team_id]||[], cum=DATA.cumulative[lt.team_id]||[];
    const cells=S.map((s,i)=>{
      const rk=ranks[i];
      if(rk==null) return `<td><div class="cell void">·</div></td>`;
      const tip=`${t.name} · ${s.label}${s.year?' ('+s.year+')':''}: ${rk}º lugar · ${cum[i]??0} pts acumulados`;
      return `<td><div class="cell" style="background:${TIER(rk)}" title="${esc(tip)}">${rk}</div></td>`;
    }).join('');
    return `<tr>
      <td class="rk">${idx+1}</td>
      <td class="team"><div class="in">${img(t.photo_url)}<div style="min-width:0"><div class="abbr">${esc(t.abbr)}</div><div class="tn">${esc(t.name)}</div></div></div></td>
      ${cells}
      <td class="tot" style="color:${TIER(lt.final_rank||30)}">${lt.total}</td>
    </tr>`;
  }).join('');

  $('content').innerHTML=`
    <div class="panel">
      <div class="h"><div class="ico"><i class="bi bi-diagram-3-fill"></i></div><div><div class="t">Posição no acumulado</div></div><div class="sub" style="margin-left:auto">colocação na soma de pontos até cada temporada</div></div>
      <div class="hm-wrap">
        <table class="hm">
          <thead><tr><th></th><th class="teamh"></th>${head}<th></th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
      <div class="legend">
        <span><i style="background:var(--t1)"></i> 1º lugar</span>
        <span><i style="background:var(--t2)"></i> 2º–4º</span>
        <span><i style="background:var(--t3)"></i> 5º–8º</span>
        <span><i style="background:var(--t4)"></i> 9º–16º</span>
        <span><i style="background:var(--t5)"></i> 17º ou pior</span>
        <span style="color:var(--ink-3)">· passe o mouse numa célula para ver os pontos</span>
      </div>
    </div>`;
}

function renderChampions(){
  const champs=(DATA.champions||[]).filter(c=>c.champion).slice().reverse();
  const counts={};(DATA.champions||[]).forEach(c=>{if(c.champion)counts[c.champion]=(counts[c.champion]||0)+1;});
  const rank=Object.entries(counts).sort((a,b)=>b[1]-a[1]);
  const grid=champs.length?champs.map((c,i)=>`
    <div class="champ ${i===0?'latest':''}">
      ${i===0?'<span class="ring">🏅</span>':''}
      ${img(c.champion_photo,'logo')}
      <div style="min-width:0">
        <div class="season">${c.year?c.year:'T'+c.season_number}</div>
        <div class="cname">${esc(c.champion)}</div>
        ${c.runner_up?`<div class="vice">vice · ${esc(c.runner_up)}</div>`:''}
      </div>
    </div>`).join(''):'<div class="empty">Nenhum campeão registrado ainda.</div>';
  const rankHtml=rank.length?`
    <div class="panel">
      <div class="h"><div class="ico"><i class="bi bi-stack"></i></div><div class="t">Títulos por franquia</div></div>
      <table class="tbl"><tbody>${rank.map(([nm,c],i)=>`<tr><td class="pos">${posBadge(i)}</td><td><span class="nm" style="font-weight:600">${esc(nm)}</span></td><td class="num">${'🏆'.repeat(Math.min(c,6))} <span style="color:var(--gold)">${c}</span></td></tr>`).join('')}</tbody></table>
    </div>`:'';
  $('content').innerHTML=`
    <div class="panel">
      <div class="h"><div class="ico"><i class="bi bi-trophy-fill"></i></div><div class="t">Campeões por temporada</div><div class="sub" style="margin-left:auto">do mais recente ao primeiro</div></div>
      <div class="champs">${grid}</div>
    </div>${rankHtml}`;
}

function renderAwards(){
  const aw=(DATA.awards||[]).filter(a=>a.mvp.player||a.dpoy.player||a.roy.player||a.mip.player||a.sixth.player).slice().reverse();
  const cell=o=>o&&o.player?`<div class="pl">${esc(o.player)}</div><div class="tm">${esc(o.team||'')}</div>`:'<span class="dash">—</span>';
  const rows=aw.length?aw.map(a=>`
    <tr>
      <td><div class="season-cell">${a.year||'T'+a.season_number}<small>T${a.season_number}</small></div></td>
      <td>${cell(a.mvp)}</td><td>${cell(a.dpoy)}</td><td>${cell(a.mip)}</td><td>${cell(a.sixth)}</td><td>${cell(a.roy)}</td>
    </tr>`).join(''):'<tr><td colspan="6"><div class="empty">Nenhum prêmio registrado ainda.</div></td></tr>';
  $('content').innerHTML=`
    <div class="panel">
      <div class="h"><div class="ico"><i class="bi bi-award-fill"></i></div><div class="t">Prêmios individuais</div><div class="sub" style="margin-left:auto">temporada a temporada</div></div>
      <div style="overflow-x:auto">
        <table class="aw">
          <thead><tr><th>Temporada</th><th>🏆 MVP</th><th>🛡️ DPOY</th><th>📈 MIP</th><th>🔥 6º Homem</th><th>🌟 ROY</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    </div>`;
}

$('leaguebar').addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;LEAGUE=b.dataset.league;[...$('leaguebar').children].forEach(x=>x.classList.toggle('active',x===b));load();});
$('tabs').addEventListener('click',e=>{const b=e.target.closest('.tab');if(!b)return;TAB=b.dataset.tab;[...$('tabs').children].forEach(x=>x.classList.toggle('active',x===b));if(DATA)render();});

load();
</script>
</body>
</html>
