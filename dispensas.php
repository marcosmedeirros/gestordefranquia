<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$pdo = db();
$user = getUserSession();
$team = null;
if ($user && isset($user['id'])) {
    $stmtTeam = $pdo->prepare('SELECT id, city, name, photo_url, league FROM teams WHERE user_id = ? LIMIT 1');
    $stmtTeam->execute([$user['id']]);
    $team = $stmtTeam->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<title>Dispensas · FBA Manager</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--bg:#07070a;--bg-2:#0d0d13;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;--border:rgba(255,255,255,.06);--border-2:rgba(255,255,255,.10);--ink:#f0f0f3;--ink-2:#868690;--ink-3:#7d7d85;--amber:#f59e0b;--green:#22c55e;--radius:14px;--disp:'Oswald',sans-serif;--body:'Montserrat',sans-serif;--shadow:none;--t:.2s cubic-bezier(.4,0,.2,1)}
:root[data-theme="light"]{--bg:#f6f7fb;--bg-2:#f2f4f8;--panel:#fff;--panel-2:#f2f4f8;--panel-3:#e9edf4;--border:#e3e6ee;--border-2:#d7dbe6;--ink:#111217;--ink-2:#5b6270;--ink-3:#657080}
:root{--sidebar-w:260px;--text:var(--ink);--text-2:var(--ink-2);--text-3:var(--ink-3);--border-md:var(--border-2);--red-soft:color-mix(in srgb,var(--red) 10%,transparent);--border-red:color-mix(in srgb,var(--red) 22%,transparent);--radius-sm:10px}
:root[data-theme="light"]{--border-red:color-mix(in srgb,var(--red) 18%,transparent)}
/* ── App shell + menu lateral ─────────────────────── */
.app{display:flex;min-height:100vh}
.sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;overflow-y:auto;scrollbar-width:none;transition:transform var(--t)}
.sidebar::-webkit-scrollbar{display:none}
.sb-team{margin:14px 14px 0;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-team img{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-team-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.2}
.sb-team-league{font-size:11px;color:var(--red);font-weight:600}
.sb-nav{flex:1;padding:12px 10px 8px}
.sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 6px}
.sb-nav a{font-family:var(--body);display:flex;align-items:center;gap:10px;padding:10px 10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t)}
.sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0}
.sb-nav a:hover{background:var(--panel-2);color:var(--text)}
.sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600}
.sb-nav a.active i{color:var(--red)}
.sb-theme-toggle{margin:0 14px 12px;padding:8px 10px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--panel-2);color:var(--text);display:flex;align-items:center;justify-content:center;gap:8px;font-size:12px;font-weight:600;cursor:pointer;transition:all var(--t)}
.sb-theme-toggle:hover{border-color:var(--border-red);color:var(--red)}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-username{font-size:12px;font-weight:500;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;transition:all var(--t);text-decoration:none;flex-shrink:0}
.sb-logout:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:250}
.sb-overlay.show{display:block}
.menu-btn{display:none;width:34px;height:34px;border-radius:9px;background:var(--panel-2);border:1px solid var(--border);color:var(--text);align-items:center;justify-content:center;cursor:pointer;font-size:17px;margin-right:4px;flex-shrink:0}
.main{margin-left:var(--sidebar-w);min-height:100vh;width:calc(100% - var(--sidebar-w))}
.topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:240}
.topbar-title{font-family:var(--disp);font-weight:600;font-size:15px;flex:1}
@media (max-width:992px){
  :root{--sidebar-w:0px}
  .sidebar{transform:translateX(-260px)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;width:100%;padding-top:54px}
  .menu-btn{display:flex}
  .topbar{display:flex}
  .top .menu-btn{display:none}
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--body);color:var(--ink);line-height:1.5;background:var(--bg);min-height:100vh;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
.page{max-width:1080px;margin:0 auto;padding:20px 20px 90px}
.top{display:flex;align-items:center;gap:12px;margin-bottom:26px}
.brand{display:flex;align-items:center;gap:11px}
.brand .mark{width:40px;height:40px;border-radius:11px;background:linear-gradient(135deg,var(--red),#c30f38);display:flex;align-items:center;justify-content:center;color:#fff;font-family:var(--disp);font-weight:700;font-size:15px;box-shadow:0 8px 22px -8px var(--red)}
.brand b{font-family:var(--disp);font-weight:600;font-size:16px;letter-spacing:.5px}
.brand span{display:block;font-size:11px;color:var(--ink-3);font-weight:500;margin-top:-2px}
.right{margin-left:auto;display:flex;gap:8px}
.gbtn{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:11px;background:var(--panel);border:1px solid var(--border);color:var(--ink-2);font-family:var(--body);font-size:13px;font-weight:600;cursor:pointer;transition:var(--t)}
.gbtn:hover{border-color:var(--border-2);color:var(--ink)}
.hero .kicker{font-family:var(--body);font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
.hero h1{font-family:var(--disp);font-size:26px;font-weight:700;line-height:1.1}
.hero .lead{font-size:13px;color:var(--ink-2);margin-top:8px;max-width:680px}
.mine-bid{margin-top:14px;display:inline-flex;align-items:center;gap:9px;background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:10px 14px;font-size:13px}
.mine-bid b{font-family:var(--disp);color:var(--red);font-size:16px}
.section{font-family:var(--disp);font-size:14px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--ink-2);margin:26px 0 14px;display:flex;align-items:center;gap:9px}
.section i{color:var(--red)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:16px;box-shadow:var(--shadow);display:flex;flex-direction:column;gap:11px}
.card.soon{border-color:color-mix(in srgb,var(--red) 34%,transparent)}
.pl{display:flex;align-items:center;gap:12px}
.pl .av{width:48px;height:48px;border-radius:12px;background:var(--panel-3);border:1px solid var(--border-2);display:flex;align-items:center;justify-content:center;font-family:var(--disp);font-weight:700;font-size:16px;color:var(--red);flex-shrink:0}
.pl .nm{font-family:var(--disp);font-weight:600;font-size:17px;line-height:1.1}
.pl .meta{font-size:12px;color:var(--ink-3);margin-top:2px}
.ovr{margin-left:auto;text-align:right;flex-shrink:0}
.ovr .v{font-family:var(--disp);font-weight:700;font-size:24px;color:var(--red);line-height:1}
.ovr .l{font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--ink-3)}
.row{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ink-2)}
.row i{color:var(--ink-3)}
.timer{display:flex;align-items:center;gap:10px;background:var(--panel-2);border:1px solid var(--border);border-radius:11px;padding:9px 12px}
.timer .clock{font-family:var(--disp);font-weight:700;font-size:20px;letter-spacing:1px}
.timer.warn .clock{color:var(--amber)}.timer.crit .clock{color:var(--red)}
.timer .cl{font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--ink-3)}
.claims{margin-left:auto;font-size:11.5px;color:var(--ink-3);font-weight:600}
.bid{display:flex;align-items:center;gap:8px;background:var(--panel-2);border:1px solid var(--border);border-radius:11px;padding:8px 12px;font-size:12.5px}
.bid .lead-bid{font-family:var(--disp);font-weight:700;color:var(--ink)}
.bid .me{margin-left:auto;font-size:11.5px;color:var(--green);font-weight:700}
.actbtn{width:100%;padding:11px;border-radius:11px;border:1px solid var(--red);background:var(--red);color:#fff;font-family:var(--body);font-weight:700;font-size:13.5px;cursor:pointer;transition:var(--t);display:inline-flex;align-items:center;justify-content:center;gap:8px}
.actbtn:hover{filter:brightness(1.08)}
.actbtn.on{background:transparent;color:var(--red)}
.actbtn.dis{background:var(--panel-2);border-color:var(--border);color:var(--ink-3);cursor:default}
.empty{text-align:center;color:var(--ink-3);padding:24px 16px;font-size:12px;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius)}
.note{background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:12px 14px;font-size:12.5px;color:var(--ink-2);display:flex;gap:9px;align-items:flex-start}
.note i{color:var(--red);margin-top:1px}
.recent{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:6px 4px;box-shadow:var(--shadow)}
.rrow{display:flex;align-items:center;gap:10px;padding:9px 12px;border-bottom:1px solid var(--border);font-size:13px}
.rrow:last-child{border-bottom:none}
.rrow .rn{font-weight:600}
.badge{font-size:10px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;padding:3px 8px;border-radius:999px;flex-shrink:0}
.badge.claimed{background:color-mix(in srgb,var(--green) 16%,transparent);color:var(--green);border:1px solid color-mix(in srgb,var(--green) 30%,transparent)}
.badge.cleared{background:var(--panel-3);color:var(--ink-2);border:1px solid var(--border)}
.loading{text-align:center;color:var(--ink-3);padding:60px;font-size:14px}
.loading i{font-size:24px;display:block;margin-bottom:10px;color:var(--red);animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
<?php include __DIR__ . '/includes/accent-color.php'; ?>
</style>
</head>
<body>
<div class="app">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>
<header class="topbar">
  <button class="menu-btn" id="menuBtnFixed" aria-label="Abrir menu"><i class="bi bi-list"></i></button>
  <div class="topbar-title">Dispensas</div>
</header>
<main class="main">
<div class="page">
  <div class="top">
    <button class="menu-btn" id="menuBtn" aria-label="Abrir menu"><i class="bi bi-list"></i></button>
    <a href="/dashboard.php" class="brand">
      <div class="mark">FBA</div>
      <div><b>DISPENSAS</b><span>waiver · ELITE</span></div>
    </a>
    <div class="right">
      <button class="gbtn" id="adminResolve" style="display:none"><i class="bi bi-hourglass-bottom"></i> Resolver vencidos</button>
      <button class="gbtn" id="refresh"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>
      <a href="/dashboard.php" class="gbtn"><i class="bi bi-arrow-left"></i> Voltar</a>
    </div>
  </div>

  <div class="hero">
    <div class="kicker">FBA Elite · <span id="waiverHoursKicker">12h</span> de janela</div>
    <h1>Dispensas</h1>
    <p class="lead">Todo jogador dispensado na ELITE fica <b><span id="waiverHoursLead">12h</span> em waiver</b> antes de virar free agent. Nesse período, os times dão <b>lance com o espaço disponível no seu salary cap</b> — ao fim, o jogador vai pro <b>maior lance</b> (desempate: quem deu o lance primeiro) e o salário dele entra no cap do vencedor. Sem lances, cai no free agency.</p>
    <div class="mine-bid" id="mineBid" style="display:none"><i class="bi bi-wallet2" style="color:var(--red)"></i> Seu lance possível agora: <b id="myBidVal">—</b> de espaço no cap</div>
  </div>

  <div id="content"><div class="loading"><i class="bi bi-hourglass-split"></i> Carregando dispensas…</div></div>
</div>
</main>
</div><!-- .app -->

<script>
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
let DATA = null;

function fmt(sec){
  if(sec<=0) return '00:00:00';
  const h=Math.floor(sec/3600), m=Math.floor((sec%3600)/60), s=sec%60;
  const p=n=>String(n).padStart(2,'0');
  return `${p(h)}:${p(m)}:${p(s)}`;
}

async function load(){
  $('content').innerHTML='<div class="loading"><i class="bi bi-hourglass-split"></i> Carregando…</div>';
  try{
    const r = await fetch('/api/waivers.php');
    DATA = await r.json();
    if(!DATA.success){ $('content').innerHTML=`<div class="empty">Erro: ${esc(DATA.error||'')}</div>`; return; }
    $('adminResolve').style.display = DATA.is_admin ? '' : 'none';
    if(DATA.can_claim && DATA.my_cap_space!=null){ $('mineBid').style.display=''; $('myBidVal').textContent = DATA.my_cap_space+'M'; }
    if(DATA.waiver_hours!=null){
      $('waiverHoursKicker').textContent = DATA.waiver_hours+'h';
      $('waiverHoursLead').textContent = DATA.waiver_hours+'h';
    }
    render();
  }catch(e){ $('content').innerHTML='<div class="empty">Falha ao carregar.</div>'; }
}

function render(){
  const open = DATA.open || [];
  const canClaim = DATA.can_claim;
  let html = '';

  if(!canClaim){
    html += `<div class="note"><i class="bi bi-info-circle-fill"></i><div>As dispensas com waiver são exclusivas da ELITE. Seu time não é da ELITE, então você acompanha, mas não dá lance.</div></div>`;
  }

  html += `<div class="section"><i class="bi bi-hourglass-split"></i> Em waiver <span style="color:var(--ink-3);font-weight:400;text-transform:none;letter-spacing:0;font-size:12px">· ${open.length} jogador${open.length===1?'':'es'}</span></div>`;

  if(!open.length){
    html += `<div class="empty"><i class="bi bi-inboxes" style="font-size:22px;display:block;margin-bottom:10px"></i>Nenhum jogador em waiver agora.</div>`;
  } else {
    html += `<div class="grid">` + open.map(w=>{
      const own = Number(w.team_id) === Number(DATA.my_team_id);
      const mine = w.my_bid != null;
      const sec = Math.max(0, Number(w.seconds_left)||0);
      let btn;
      if(own) btn = `<button class="actbtn dis" disabled>Você dispensou este jogador</button>`;
      else if(!canClaim) btn = `<button class="actbtn dis" disabled>Somente times da ELITE</button>`;
      else if(mine) btn = `<button class="actbtn on" onclick="claim(${w.id},false)"><i class="bi bi-x-circle"></i> Cancelar meu lance (${w.my_bid}M)</button>`;
      else btn = `<button class="actbtn" onclick="claim(${w.id},true)"><i class="bi bi-cash-coin"></i> Dar lance · ${DATA.my_cap_space!=null?DATA.my_cap_space+'M de espaço':'meu espaço'}</button>`;
      const pos = [w.position, w.secondary_position].filter(Boolean).join('/');
      const crit = sec < 3600 ? 'crit' : (sec < 3*3600 ? 'warn' : '');
      const topBid = (w.top_bid!=null) ? `${w.top_bid}M de espaço` : 'sem lances';
      return `<div class="card ${sec<3600?'soon':''}">
        <div class="pl">
          <div class="av">${esc((w.position||'?').slice(0,2))}</div>
          <div style="min-width:0">
            <div class="nm">${esc(w.name)}</div>
            <div class="meta">${esc(pos)} · ${w.age||'?'} anos</div>
          </div>
          <div class="ovr"><div class="v">${w.ovr}</div><div class="l">OVR</div></div>
        </div>
        <div class="row"><i class="bi bi-box-arrow-right"></i> Dispensado por <b style="color:var(--ink)">${esc(w.waived_by_name)}</b></div>
        <div class="bid">
          <i class="bi bi-trophy-fill" style="color:var(--amber)"></i> Maior lance: <span class="lead-bid">${topBid}</span>
          ${mine?`<span class="me"><i class="bi bi-check2"></i> seu: ${w.my_bid}M</span>`:''}
        </div>
        <div class="timer ${crit}">
          <i class="bi bi-alarm" style="color:var(--ink-3)"></i>
          <div><div class="clock" data-sec="${sec}">${fmt(sec)}</div><div class="cl">até resolver</div></div>
          <div class="claims"><i class="bi bi-people-fill"></i> ${w.claim_count} lance${Number(w.claim_count)===1?'':'s'}</div>
        </div>
        ${btn}
      </div>`;
    }).join('') + `</div>`;
  }

  const recent = DATA.recent || [];
  if(recent.length){
    html += `<div class="section"><i class="bi bi-clock-history"></i> Resolvidos recentemente</div><div class="recent">` +
      recent.map(r=>`<div class="rrow">
        <span class="rn">${esc(r.name)}</span>
        <span style="color:var(--ink-3);font-size:12px">${r.ovr} OVR</span>
        ${r.status==='claimed'
          ? `<span style="margin-left:auto;font-size:12px;color:var(--ink-2)">${esc(r.from_name)} → <b style="color:var(--ink)">${esc(r.to_name||'?')}</b></span><span class="badge claimed">Levado no lance</span>`
          : `<span style="margin-left:auto;font-size:12px;color:var(--ink-2)">${esc(r.from_name)} → Free Agency</span><span class="badge cleared">Sem lance</span>`}
      </div>`).join('') + `</div>`;
  }

  $('content').innerHTML = html;
}

async function claim(id, want){
  try{
    const r = await fetch('/api/waivers.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:want?'claim':'unclaim', id})});
    const d = await r.json();
    if(!d.success){ alert(d.error||'Erro'); return; }
    load();
  }catch(e){ alert('Falha no lance.'); }
}

$('refresh').onclick = load;
$('adminResolve').onclick = async () => {
  if(!confirm('Resolver agora todas as dispensas vencidas?')) return;
  const r = await fetch('/api/waivers.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'resolve'})});
  const d = await r.json();
  if(d.success) alert(`Resolvidos: ${d.resolved} (levados no lance ${d.claimed}, free agency ${d.cleared}).`);
  load();
};

setInterval(()=>{
  document.querySelectorAll('.clock[data-sec]').forEach(el=>{
    let s = Math.max(0, parseInt(el.dataset.sec||'0',10) - 1);
    el.dataset.sec = s;
    el.textContent = fmt(s);
    const t = el.closest('.timer');
    if(t){ t.classList.toggle('crit', s<3600); t.classList.toggle('warn', s>=3600 && s<3*3600); }
  });
}, 1000);

load();

// ── Menu lateral (toggle mobile) ─────────────────────
(function () {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sbOverlay');
  const menuBtn = document.getElementById('menuBtn');
  const menuBtnFixed = document.getElementById('menuBtnFixed');
  if (!sidebar) return;
  const close = () => { sidebar.classList.remove('open'); if (overlay) overlay.classList.remove('show'); };
  const open = () => { sidebar.classList.add('open'); if (overlay) overlay.classList.add('show'); };
  if (menuBtn) menuBtn.addEventListener('click', open);
  if (menuBtnFixed) menuBtnFixed.addEventListener('click', open);
  if (overlay) overlay.addEventListener('click', close);
  document.querySelectorAll('.sb-nav a').forEach((a) => a.addEventListener('click', close));
})();

// ── Alternar tema (botão do sidebar) ─────────────────
(function () {
  const themeKey = 'fba-theme';
  const themeToggle = document.getElementById('themeToggle');
  const applyTheme = (theme) => {
    if (theme === 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
      if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
    } else {
      document.documentElement.removeAttribute('data-theme');
      if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
    }
  };
  applyTheme(localStorage.getItem(themeKey) || 'dark');
  if (themeToggle) themeToggle.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    localStorage.setItem(themeKey, next);
    applyTheme(next);
  });
})();
</script>
</body>
</html>
