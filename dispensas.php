<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$pdo = db();
$user = getUserSession();
/* timeDaTela: no observador, as dispensas são as da liga observada. */
require_once __DIR__ . '/backend/observador.php';
$team = ($user && isset($user['id'])) ? timeDaTela($pdo, (int)$user['id']) : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<title>Dispensas · FBA Manager</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025; --red-2:color-mix(in srgb,var(--red) 85%,white); --red-soft:color-mix(in srgb,var(--red) 10%,transparent); --red-glow:color-mix(in srgb,var(--red) 18%,transparent);
  --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
  --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10); --border-red:color-mix(in srgb,var(--red) 22%,transparent);
  --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
  --green:#22c55e; --amber:#f59e0b; --blue:#3b82f6;
  --sidebar-w:260px; --font:'Montserrat',sans-serif;
  --radius:14px; --radius-sm:10px; --radius-xs:6px;
  --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
}
:root[data-theme="light"]{
  --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
  --border:#e3e6ee; --border-md:#d7dbe6; --border-red:color-mix(in srgb,var(--red) 18%,transparent);
  --text:#111217; --text-2:#5b6270; --text-3:#657080;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:var(--font);background:var(--bg);color:var(--text);line-height:1.5;min-height:100vh;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
.app{display:flex;min-height:100vh}
.main{margin-left:var(--sidebar-w);min-height:100vh;width:calc(100% - var(--sidebar-w))}
.topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:240}
.topbar-title{font-weight:700;font-size:15px;flex:1}
.menu-btn{display:none;width:34px;height:34px;border-radius:9px;background:var(--panel-2);border:1px solid var(--border);color:var(--text);align-items:center;justify-content:center;cursor:pointer;font-size:17px;margin-right:4px;flex-shrink:0}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:250}
.sb-overlay.show{display:block}

/* ── Menu lateral (duplicado em cada página — ver leilao.php) ─── */
.sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;overflow-y:auto;scrollbar-width:none;transition:transform var(--t) var(--ease)}
.sidebar::-webkit-scrollbar{display:none}
.sb-brand{padding:22px 18px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-shrink:0}
.sb-logo{width:34px;height:34px;border-radius:9px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#fff;flex-shrink:0}
.sb-brand-text{font-weight:700;font-size:15px;line-height:1.1}
.sb-brand-text span{display:block;font-size:11px;font-weight:400;color:var(--text-2)}
.sb-team{margin:14px 14px 0;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-team img{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-team-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.2}
.sb-team-league{font-size:11px;color:var(--red);font-weight:600}
.sb-nav{flex:1;padding:12px 10px 8px}
.sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 6px}
.sb-nav a{font-family:'Inter',sans-serif;display:flex;align-items:center;gap:10px;padding:10px 10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease)}
.sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0}
.sb-nav a:hover{background:var(--panel-2);color:var(--text)}
.sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600}
.sb-nav a.active i{color:var(--red)}
.sb-theme-toggle{margin:0 14px 12px;padding:8px 10px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--panel-2);color:var(--text);display:flex;align-items:center;justify-content:center;gap:8px;font-size:12px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease)}
.sb-theme-toggle:hover{border-color:var(--border-red);color:var(--red)}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-username{font-size:12px;font-weight:500;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;flex-shrink:0}
.sb-logout:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}

@media (max-width:992px){
  :root{--sidebar-w:0px}
  .sidebar{transform:translateX(-260px)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;width:100%;padding-top:54px}
  .menu-btn{display:flex}
  .topbar{display:flex}
  .top .menu-btn{display:none}
}

/* ── Conteúdo da página ───────────────────────────────────────── */
.page{padding:20px 32px 40px}
.top{display:flex;align-items:center;gap:12px;margin-bottom:26px}
.right{margin-left:auto;display:flex;gap:8px}
.gbtn{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:11px;background:var(--panel);border:1px solid var(--border);color:var(--text-2);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:var(--t)}
.gbtn:hover{border-color:var(--border-md);color:var(--text)}
.page-hero{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px}
.page-hero-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
.page-hero-title{font-size:26px;font-weight:800;color:var(--text);line-height:1.1}
.page-hero-sub{font-size:13px;color:var(--text-2);margin-top:4px;max-width:680px}
.page-hero-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.mine-bid{display:inline-flex;align-items:center;gap:9px;background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:10px 14px;font-size:13px}
.mine-bid b{color:var(--red);font-size:16px}
.section{font-size:14px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--text-2);margin:26px 0 14px;display:flex;align-items:center;gap:9px}
.section i{color:var(--red)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:16px;display:flex;flex-direction:column;gap:11px}
.card.soon{border-color:var(--border-red)}
.pl{display:flex;align-items:center;gap:12px}
.pl .av{width:48px;height:48px;border-radius:12px;background:var(--panel-3);border:1px solid var(--border-md);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;color:var(--red);flex-shrink:0}
.pl .nm{font-weight:700;font-size:17px;line-height:1.1}
.pl .meta{font-size:12px;color:var(--text-3);margin-top:2px}
.ovr{margin-left:auto;text-align:right;flex-shrink:0}
.ovr .v{font-weight:800;font-size:24px;color:var(--red);line-height:1}
.ovr .l{font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-3)}
.row{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text-2)}
.row i{color:var(--text-3)}
.timer{display:flex;align-items:center;gap:10px;background:var(--panel-2);border:1px solid var(--border);border-radius:11px;padding:9px 12px}
.timer .clock{font-weight:700;font-size:20px;letter-spacing:1px}
.timer.warn .clock{color:var(--amber)}.timer.crit .clock{color:var(--red)}
.timer .cl{font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-3)}
.claims{margin-left:auto;font-size:11.5px;color:var(--text-3);font-weight:600}
.bid{display:flex;align-items:center;gap:8px;background:var(--panel-2);border:1px solid var(--border);border-radius:11px;padding:8px 12px;font-size:12.5px}
.bid .lead-bid{font-weight:700;color:var(--text)}
.bid .me{margin-left:auto;font-size:11.5px;color:var(--green);font-weight:700}
.actbtn{width:100%;padding:11px;border-radius:11px;border:1px solid var(--red);background:var(--red);color:#fff;font-family:var(--font);font-weight:700;font-size:13.5px;cursor:pointer;transition:var(--t);display:inline-flex;align-items:center;justify-content:center;gap:8px}
.actbtn:hover{background:var(--red-2)}
.actbtn.on{background:transparent;color:var(--red)}
.actbtn.dis{background:var(--panel-2);border-color:var(--border);color:var(--text-3);cursor:default}
/* Caixa do lance: o valor é escolhido, e o espaço no cap é só o teto. */
.bidbox{display:flex;flex-direction:column;gap:6px}
.bidbox label{font-size:10.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:var(--text-3)}
.bidrow{display:flex;align-items:stretch;gap:8px}
.bidinput{flex:1;min-width:0;padding:10px 11px;border-radius:11px;border:1px solid var(--border);
  background:var(--panel-2);color:var(--text);font-family:var(--font);font-weight:800;font-size:15px;
  font-variant-numeric:tabular-nums}
.bidinput:focus{outline:none;border-color:var(--red)}
/* O spinner do number rouba largura num campo estreito e não ajuda em nada. */
.bidinput::-webkit-outer-spin-button,.bidinput::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.bidinput{-moz-appearance:textfield}
.bidun{align-self:center;font-weight:800;font-size:13px;color:var(--text-3);margin-left:-4px}
.actbtn.slim{width:auto;flex:0 0 auto;padding:10px 14px;font-size:12.5px;white-space:nowrap}
.bidhint{font-size:11px;line-height:1.4;color:var(--text-3)}
.bidhint b{color:var(--text-2)}
.linkbtn{align-self:flex-start;background:none;border:none;padding:2px 0;color:var(--text-3);
  font-family:var(--font);font-size:11.5px;font-weight:700;cursor:pointer}
.linkbtn:hover{color:var(--red)}
/* No celular a linha do lance não cabe lado a lado sem espremer o campo. */
@media (max-width:420px){
  .bidrow{flex-wrap:wrap}
  .bidinput{flex:1 1 100px}
  .actbtn.slim{flex:1 1 100%}
}
.empty{padding:24px 16px;text-align:center;color:var(--text-3);background:var(--panel);border:1px solid var(--border);border-radius:var(--radius)}
.empty i{font-size:28px;display:block;margin-bottom:8px}
.empty p{font-size:12px}
.note{background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:12px 14px;font-size:12.5px;color:var(--text-2);display:flex;gap:9px;align-items:flex-start}
.note i{color:var(--red);margin-top:1px}
.recent{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:6px 4px}
.rrow{display:flex;align-items:center;gap:10px;padding:9px 12px;border-bottom:1px solid var(--border);font-size:13px}
.rrow:last-child{border-bottom:none}
.rrow .rn{font-weight:600}
.badge{font-size:10px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;padding:3px 8px;border-radius:999px;flex-shrink:0}
.badge.claimed{background:color-mix(in srgb,var(--green) 16%,transparent);color:var(--green);border:1px solid color-mix(in srgb,var(--green) 30%,transparent)}
.badge.cleared{background:var(--panel-3);color:var(--text-2);border:1px solid var(--border)}
/* ── Botão ⓘ e modal de detalhes (o mesmo de Jogadores, sem Bootstrap) ── */
.infobtn{width:28px;height:28px;border-radius:8px;border:1px solid color-mix(in srgb,var(--blue) 45%,transparent);background:color-mix(in srgb,var(--blue) 10%,transparent);color:var(--blue);display:inline-flex;align-items:center;justify-content:center;font-size:14px;cursor:pointer;flex-shrink:0;transition:var(--t);padding:0}
.infobtn:hover{background:var(--blue);color:#fff}
.infobtn:focus-visible{outline:2px solid var(--blue);outline-offset:2px}
.nmrow{display:flex;align-items:center;gap:8px;min-width:0}
.nmrow .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dm-fundo{position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(3px);z-index:600;display:flex;align-items:center;justify-content:center;padding:16px}
.dm-fundo[hidden]{display:none}
.dm-caixa{background:var(--panel);border:1px solid var(--border-md);border-radius:16px;width:100%;max-width:760px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden}
.dm-cab{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border)}
.dm-cab h3{font-size:16px;font-weight:800;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dm-fechar{width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--text-2);cursor:pointer}
.dm-fechar:hover{border-color:var(--red);color:var(--red)}
.dm-corpo{overflow-y:auto}
.skill-grades-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:6px}
.skill-grade-item{background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:8px 4px;text-align:center}
.skill-grade-label{font-size:9px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.skill-grade-value{font-size:15px;font-weight:800}
@media (max-width:520px){.skill-grades-grid{gap:4px}.skill-grade-value{font-size:13px}.dm-fundo{padding:8px}}
.loading{text-align:center;color:var(--text-3);padding:60px;font-size:14px}
.loading i{font-size:24px;display:block;margin-bottom:10px;color:var(--red);animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
/* Mobile do conteudo: precisa vir DEPOIS das regras base de .page/.page-hero-*,
   senao a base (mais abaixo no arquivo) ganharia por ordem de fonte — media
   query nao soma especificidade. */
@media (max-width:992px){
  .page{padding:20px 16px 40px}
  .page-hero-title{font-size:18px}
}
@media (prefers-reduced-motion: reduce){*,*::before,*::after{animation-duration:0.01ms !important;animation-delay:0ms !important;animation-iteration-count:1 !important;transition-duration:0.01ms !important;transition-delay:0ms !important;scroll-behavior:auto !important}}
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
  <!-- Page hero -->
  <div class="page-hero">
    <div>
      <div class="page-hero-eyebrow">FBA Elite · <span id="waiverHoursKicker">12h</span> de janela</div>
      <h1 class="page-hero-title"><i class="bi bi-hourglass-split" style="color:var(--red);margin-right:8px"></i>Dispensas</h1>
      <p class="page-hero-sub">Todo jogador dispensado na ELITE fica <b><span id="waiverHoursLead">12h</span> em waiver</b> antes de virar free agent. Nesse período cada time dá <b>um lance fechado</b>: você escolhe o valor até o limite do seu espaço no salary cap, e <b>ninguém vê os lances dos outros</b> — nem quantos existem. Ao fim, o jogador vai pro <b>maior lance</b> (desempate: quem deu primeiro; editar o valor refaz a sua hora) e o salário dele entra no cap do vencedor. Só dá pra dar lance em quem <b>cabe no seu cap</b>. Sem lances, cai no free agency. Quem <b>sobrou do draft</b> entra pela mesma porta, com <b>24h</b> de janela.</p>
    </div>
    <div class="page-hero-actions" style="flex-direction:column;align-items:flex-end">
      <div class="mine-bid" id="mineBid" style="display:none"><i class="bi bi-wallet2" style="color:var(--red)"></i> Seu lance possível agora: <b id="myBidVal">—</b> de espaço no cap</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
        <button class="gbtn" id="adminResolve" style="display:none"><i class="bi bi-hourglass-bottom"></i> Resolver vencidos</button>
        <button class="gbtn" id="refresh"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>
        <a href="/dashboard.php" class="gbtn"><i class="bi bi-arrow-left"></i> Voltar</a>
      </div>
    </div>
  </div>

  <div id="content"><div class="loading"><i class="bi bi-hourglass-split"></i> Carregando dispensas…</div></div>
</div>
</main>
</div><!-- .app -->

<div class="dm-fundo" id="dmFundo" hidden>
  <div class="dm-caixa" role="dialog" aria-modal="true" aria-labelledby="dmTitulo">
    <div class="dm-cab">
      <h3 id="dmTitulo">Detalhes</h3>
      <button class="dm-fechar" id="dmFechar" type="button" aria-label="Fechar"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="dm-corpo" id="dmCorpo"></div>
  </div>
</div>

<script>
/* ── Modal de detalhes: mesmo desenho do de Jogadores (players.php) ── */
const SKILL_GRADE_FIELDS = [
  {key:'in',label:'IN'},{key:'mid',label:'MID'},{key:'pt3',label:'3PT'},{key:'post_d',label:'POST D'},{key:'per_d',label:'PER D'},
  {key:'play',label:'PLAY'},{key:'reb',label:'REB'},{key:'athl',label:'ATHL'},{key:'iq',label:'IQ'},{key:'pot',label:'POT'},
];
function _corAvatar(){ try{ const m=getComputedStyle(document.documentElement).getPropertyValue('--red').trim().match(/#([0-9a-fA-F]{6})/); return m?m[1]:'fc0025'; }catch(e){ return 'fc0025'; } }
function fotoDoJogador(p){
  const custom = String(p.foto_adicional||'').trim();
  if (custom) return custom;
  return p.nba_player_id ? `https://cdn.nba.com/headshots/nba/latest/260x190/${p.nba_player_id}.png`
    : `https://ui-avatars.com/api/?name=${encodeURIComponent(p.name||'P')}&background=121212&color=${_corAvatar()}&rounded=true&bold=true`;
}
function notasDoJogador(p){
  let g = {};
  try { g = typeof p.player_skill_grades === 'object' && p.player_skill_grades ? p.player_skill_grades : JSON.parse(p.player_skill_grades||'{}') || {}; } catch(e) { g = {}; }
  const col = {in:p.skill_in,mid:p.skill_mid,pt3:p.skill_3pt,post_d:p.skill_post_d,per_d:p.skill_per_d,play:p.skill_play,reb:p.skill_reb,athl:p.skill_athl,iq:p.skill_iq,pot:p.skill_pot};
  Object.entries(col).forEach(([k,v]) => { if (v !== null && v !== undefined && v !== '') g[k] = v; });
  return g;
}
function corDaNota(v){ if(!v||v==='-') return 'var(--text-3)'; const g=String(v).toUpperCase().trim();
  if(['A+','A','A-','B+'].includes(g)) return '#22c55e'; if(['B','B-','C+','C','C-'].includes(g)) return '#f59e0b'; return '#ef4444'; }
const rotulo = t => `<div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:10px">${t}</div>`;

async function abrirDetalhes(id){
  const corpo = $('dmCorpo');
  $('dmTitulo').textContent = 'Detalhes';
  corpo.innerHTML = '<div class="loading"><i class="bi bi-hourglass-split"></i> Carregando…</div>';
  $('dmFundo').hidden = false;
  $('dmFechar').focus();
  try{
    const r = await fetch('/api/waivers.php?action=detalhes&id=' + encodeURIComponent(id));
    const d = await r.json();
    if(!d.success){ corpo.innerHTML = `<div style="padding:20px;color:var(--red)">${esc(d.error||'Erro ao carregar.')}</div>`; return; }
    const p = d.player || {}, log = d.season_log || [], tr = d.transfers || [];
    $('dmTitulo').textContent = p.name || 'Detalhes';
    const delta = log.length >= 2 ? (parseInt(log[log.length-1].ovr)||0) - (parseInt(log[log.length-2].ovr)||0) : 0;
    const deltaHtml = delta > 0 ? `<span style="font-size:11px;color:#22c55e;font-weight:700;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);padding:2px 8px;border-radius:999px;margin-left:8px">+${delta}</span>`
      : delta < 0 ? `<span style="font-size:11px;color:#ef4444;font-weight:700;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);padding:2px 8px;border-radius:999px;margin-left:8px">${delta}</span>` : '';
    const logHtml = log.length ? log.map((s,i) => {
        const label = (s.season_number ? `Temp ${s.season_number}` : `Temporada ${i+1}`) + (s.year ? ` · ${s.year}` : '');
        const dd = i > 0 ? (parseInt(s.ovr)||0) - (parseInt(log[i-1].ovr)||0) : 0;
        const dh = dd > 0 ? `<span style="font-size:10px;color:#22c55e;font-weight:700;margin-left:6px">+${dd}</span>` : dd < 0 ? `<span style="font-size:10px;color:#ef4444;font-weight:700;margin-left:6px">${dd}</span>` : '';
        return `<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border)">
          <div><div style="font-size:12px;font-weight:600">${esc(label)}</div><div style="font-size:11px;color:var(--text-2)">${esc(s.team_name||'-')} · ${s.age??'-'}a</div></div>
          <div style="display:flex;align-items:center"><span style="color:var(--red);font-weight:800;font-size:15px">${s.ovr??'-'}</span>${dh}</div></div>`;
      }).join('') : '<div style="font-size:13px;color:var(--text-3);padding:8px 0">Nenhum snapshot registrado ainda.</div>';
    const trHtml = tr.length ? tr.map(t => `<div style="padding:8px 0;border-bottom:1px solid var(--border)">
        <div style="font-size:12px;font-weight:600">${esc(t.from_team)} <span style="color:var(--text-3)">→</span> ${esc(t.to_team)}</div>
        ${t.year ? `<div style="font-size:11px;color:var(--text-3)">${t.year}</div>` : ''}</div>`).join('')
      : '<div style="font-size:13px;color:var(--text-3);padding:8px 0">Nenhuma trade encontrada.</div>';
    const notas = notasDoJogador(p);
    const temNota = SKILL_GRADE_FIELDS.some(f => notas[f.key]);
    const notasHtml = `<div class="skill-grades-grid">${SKILL_GRADE_FIELDS.map(f => { const v = notas[f.key] || '-';
        return `<div class="skill-grade-item"><div class="skill-grade-label">${f.label}</div><div class="skill-grade-value" style="color:${corDaNota(v)}">${esc(v)}</div></div>`; }).join('')}</div>`
      + (temNota ? '' : '<div style="font-size:11.5px;color:var(--text-3);margin-top:8px">As notas não foram guardadas quando este jogador foi dispensado.</div>');
    const reserva = `https://ui-avatars.com/api/?name=${encodeURIComponent(p.name||'P')}&background=121212&color=${_corAvatar()}&rounded=true&bold=true`;
    corpo.innerHTML = `
      <div style="background:var(--panel-2);padding:20px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:16px">
        <img src="${esc(fotoDoJogador(p))}" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid var(--border-red);flex-shrink:0;background:var(--panel-3)" onerror="this.onerror=null;this.src='${reserva}'">
        <div style="flex:1;min-width:0">
          <div style="font-size:18px;font-weight:800;line-height:1.2">${esc(p.name||'-')}</div>
          <div style="font-size:12px;color:var(--text-2);margin-top:2px">${esc(p.team_name||'-')}</div>
          <div style="display:flex;align-items:center;margin-top:6px"><span style="font-size:30px;font-weight:900;color:var(--red);line-height:1">${p.ovr??'-'}</span>${deltaHtml}</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid var(--border)">
        ${[['Idade',p.age??'-'],['Posição',p.position??'-'],['Pos. Sec.',p.secondary_position||'-']].map(([l,v]) =>
          `<div style="padding:12px 8px;text-align:center;border-right:1px solid var(--border)"><div style="font-size:15px;font-weight:800">${esc(v)}</div><div style="font-size:10px;color:var(--text-2);text-transform:uppercase;letter-spacing:.7px;font-weight:600">${l}</div></div>`).join('')}
      </div>
      <div style="padding:14px 22px;border-bottom:1px solid var(--border)">${rotulo('Notas por Skill')}${notasHtml}</div>
      <div style="padding:16px 22px">${rotulo('Evolução por Temporada')}${logHtml}</div>
      <div style="padding:0 22px 22px">${rotulo('Transferências')}${trHtml}</div>`;
  }catch(e){ corpo.innerHTML = '<div style="padding:20px;color:var(--red)">Erro ao carregar detalhes.</div>'; }
}
function fecharDetalhes(){ $('dmFundo').hidden = true; }
document.addEventListener('DOMContentLoaded', () => {
  $('dmFechar').addEventListener('click', fecharDetalhes);
  $('dmFundo').addEventListener('click', e => { if (e.target.id === 'dmFundo') fecharDetalhes(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('dmFundo').hidden) fecharDetalhes(); });
});
</script>

<script>
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
// "16M" na ELITE, "9 de OVR" onde o cap e soma — colado ninguem le.
const fmtCap = (v, u) => (u === 'OVR' ? v + ' de OVR' : v + 'M');
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
    if(!DATA.success){ $('content').innerHTML=`<div class="empty"><i class="bi bi-exclamation-triangle"></i><p>Erro: ${esc(DATA.error||'')}</p></div>`; return; }
    $('adminResolve').style.display = DATA.is_admin ? '' : 'none';
    if(DATA.can_claim && DATA.my_cap_space!=null){ $('mineBid').style.display=''; $('myBidVal').textContent = DATA.my_cap_space+'M'; }
    if(DATA.waiver_hours!=null){
      $('waiverHoursKicker').textContent = DATA.waiver_hours+'h';
      $('waiverHoursLead').textContent = DATA.waiver_hours+'h';
    }
    render();
  }catch(e){ $('content').innerHTML='<div class="empty"><i class="bi bi-wifi-off"></i><p>Falha ao carregar.</p></div>'; }
}

function render(){
  const open = DATA.open || [];
  const canClaim = DATA.can_claim;
  let html = '';

  if(!canClaim){
    html += `<div class="note"><i class="bi bi-info-circle-fill"></i><div>As dispensas com waiver são exclusivas da ELITE. Seu time não é da ELITE, então você acompanha, mas não dá lance.</div></div>`;
  }

  html += `<div class="section"><i class="bi bi-hourglass-split"></i> Em waiver <span style="color:var(--text-3);font-weight:400;text-transform:none;letter-spacing:0;font-size:12px">· ${open.length} jogador${open.length===1?'':'es'}</span></div>`;

  if(!open.length){
    html += `<div class="empty"><i class="bi bi-inboxes"></i><p>Nenhum jogador em waiver agora.</p></div>`;
  } else {
    html += `<div class="grid">` + open.map(w=>{
      const own = Number(w.team_id) === Number(DATA.my_team_id);
      const mine = w.my_bid != null;
      const sec = Math.max(0, Number(w.seconds_left)||0);
      let btn;
      if(own) btn = `<button class="actbtn dis" disabled>Você dispensou este jogador</button>`;
      else if(!canClaim) btn = `<button class="actbtn dis" disabled>Somente times da ELITE</button>`;
      // Sem espaço pro salário dele, o lance nem chega a ser oferecido — o
      // botão diz por quê, em vez de deixar o time descobrir no erro.
      if(own) { /* já resolvido acima */ }
      else if(!canClaim) { /* idem */ }
      else if(w.cap_cabe === false) btn = `<button class="actbtn dis" disabled><i class="bi bi-slash-circle"></i> Não cabe no seu cap (custa ${fmtCap(w.cap_custo, w.cap_unidade)})</button>`;
      else {
        /* O VALOR É ESCOLHIDO, o espaço no cap é só o teto. Antes o botão
           apostava o espaço inteiro sempre, e não havia decisão nenhuma a
           tomar — quem tinha mais cap ganhava tudo. */
        const teto = DATA.my_cap_space!=null ? Number(DATA.my_cap_space) : null;
        const valor = mine ? w.my_bid : (teto!=null ? Math.min(teto, w.cap_custo || teto) : '');
        btn = `<div class="bidbox">
          <label for="bid${w.id}">Seu lance</label>
          <div class="bidrow">
            <input id="bid${w.id}" class="bidinput" type="number" inputmode="numeric"
                   min="1" ${teto!=null?`max="${teto}"`:''} step="1" value="${valor}"
                   onkeydown="if(event.key==='Enter')claim(${w.id},true)">
            <span class="bidun">M</span>
            <button class="actbtn slim" onclick="claim(${w.id},true)">
              <i class="bi bi-cash-coin"></i> ${mine?'Atualizar':'Dar lance'}
            </button>
          </div>
          <div class="bidhint">${teto!=null?`até ${teto}M — o seu espaço no cap`:'até o seu espaço no cap'}${
            mine?' · <b>mudar o valor tira a sua prioridade de horário</b>':''}</div>
          ${mine?`<button class="linkbtn" onclick="claim(${w.id},false)"><i class="bi bi-x-circle"></i> Cancelar meu lance</button>`:''}
        </div>`;
      }
      const pos = [w.position, w.secondary_position].filter(Boolean).join('/');
      const crit = sec < 3600 ? 'crit' : (sec < 3*3600 ? 'warn' : '');
      // O lance é cego: nem quem está ganhando, nem com quanto, nem quantos
      // lances existem. A pessoa vê só o que ela mesma apostou.
      // Só aparece quando existe: o card não diz nada sobre a disputa,
      // nem "você ainda não deu lance" — quem não deu vê o campo em branco.
      const meuLance = mine ? `${w.my_bid}M` : '';
      return `<div class="card ${sec<3600?'soon':''}">
        <div class="pl">
          <div class="av">${esc((w.position||'?').slice(0,2))}</div>
          <div style="min-width:0">
            <div class="nmrow"><div class="nm">${esc(w.name)}</div><button class="infobtn" type="button" onclick="abrirDetalhes(${w.id})" title="Detalhes" aria-label="Detalhes do jogador"><i class="bi bi-info-circle"></i></button></div>
            <div class="meta">${esc(pos)} · ${w.age||'?'} anos</div>
          </div>
          <div class="ovr"><div class="v">${w.ovr}</div><div class="l">OVR</div></div>
        </div>
        ${w.waived_by_name
          ? `<div class="row"><i class="bi bi-box-arrow-right"></i> Dispensado por <b style="color:var(--text)">${esc(w.waived_by_name)}</b></div>`
          /* Calouro que sobrou do draft não veio de time nenhum: dizer
             "dispensado por" seria mentira, e deixar em branco esconde de
             onde ele saiu. */
          : `<div class="row"><i class="bi bi-mortarboard"></i> <b style="color:var(--text)">Não escolhido no draft</b></div>`}
        ${w.cap_custo!=null?`<div class="row"><i class="bi bi-cash-stack"></i> Custa <b style="color:${w.cap_cabe===false?'var(--red)':'var(--text)'}">${fmtCap(w.cap_custo, w.cap_unidade)}</b> no seu cap</div>`:''}
        ${mine?`<div class="bid">
          <i class="bi bi-check-circle-fill" style="color:var(--green)"></i>
          <span class="lead-bid">Seu lance: ${esc(meuLance)}</span>
        </div>`:''}
        <div class="timer ${crit}">
          <i class="bi bi-alarm" style="color:var(--text-3)"></i>
          <div><div class="clock" data-sec="${sec}">${fmt(sec)}</div><div class="cl">até resolver</div></div>
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
        <button class="infobtn" type="button" onclick="abrirDetalhes(${r.id})" title="Detalhes" aria-label="Detalhes do jogador"><i class="bi bi-info-circle"></i></button>
        <span style="color:var(--text-3);font-size:12px">${r.ovr} OVR</span>
        ${r.status==='claimed'
          ? `<span style="margin-left:auto;font-size:12px;color:var(--text-2)">${esc(r.from_name || 'Draft')} → <b style="color:var(--text)">${esc(r.to_name||'?')}</b></span><span class="badge claimed">${r.bid != null ? 'Levado por ' + Number(r.bid) + 'M' : 'Levado no lance'}</span>`
          : `<span style="margin-left:auto;font-size:12px;color:var(--text-2)">${esc(r.from_name || 'Draft')} → Free Agency</span><span class="badge cleared">Sem lance</span>`}
      </div>`).join('') + `</div>`;
  }

  $('content').innerHTML = html;
}

async function claim(id, want){
  const campo = document.getElementById('bid'+id);
  const bid = (want && campo) ? Number(campo.value) : null;
  if(want && campo){
    if(!bid || bid <= 0){ alert('Escreva o valor do lance.'); campo.focus(); return; }
    const teto = campo.max ? Number(campo.max) : null;
    if(teto != null && bid > teto){ alert(`O lance passa do seu espaço no cap (${teto}M).`); campo.focus(); return; }
  }
  try{
    const r = await fetch('/api/waivers.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:want?'claim':'unclaim', id, bid})});
    const d = await r.json();
    if(!d.success){ alert(d.error||'Erro'); return; }
    load();
  }catch(e){ alert('Falha no lance.'); }
}

$('refresh').onclick = load;
$('adminResolve').onclick = async () => {
  if(!await confirmarSite('Resolver agora todas as dispensas vencidas?')) return;
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
