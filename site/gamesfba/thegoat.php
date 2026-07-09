<?php
// Mesmo mapa de logos usado em site.php — os nomes dos times aqui batem com
// "Cidade Nome" do banco (teams.city + ' ' + teams.name).
$teamLogos = require __DIR__ . '/../_partials/team-logos.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>THE GOAT — Simulador de Carreira na NBA</title>
<meta name="description" content="Roube atributos das lendas da NBA no draft, viva uma carreira inteira temporada a temporada, e descubra em qual degrau da escada você fica entre os maiores de todos os tempos.">
<meta name="game-icon" content="bi-trophy-fill">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Bebas+Neue&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0a0a0a;
  --bg2:#131313;
  --wood:#262626;
  --wood2:#1a1a1a;
  --paper:#f5f5f5;
  --ink:#0a0a0a;
  --accent:#E63946;
  --accent2:#ff5a68;
  --gold:#d8a13a;
  --line:rgba(255,255,255,0.10);
  --line2:rgba(255,255,255,0.18);
  --muted:#a0a0a0;
}
*{box-sizing:border-box;}
html,body{margin:0;padding:0;}
body{
  background:
    repeating-linear-gradient(180deg, rgba(255,255,255,0.015) 0px, rgba(255,255,255,0.015) 2px, transparent 2px, transparent 42px),
    radial-gradient(ellipse at 50% -10%, rgba(230,57,70,.12) 0%, var(--bg) 55%);
  color:var(--paper);
  font-family:'Inter',sans-serif;
  min-height:100vh;
  display:flex;
  flex-direction:column;
  align-items:center;
  -webkit-font-smoothing:antialiased;
}
#app{
  width:100%;
  max-width:480px;
  min-height:100vh;
  position:relative;
  padding:0 0 40px 0;
  border-left:1px solid var(--line);
  border-right:1px solid var(--line);
}
.eyebrow{
  font-family:'IBM Plex Mono',monospace;
  font-size:11px;
  letter-spacing:.14em;
  text-transform:uppercase;
  color:var(--accent2);
  font-weight:600;
}
h1,h2,h3{margin:0;font-family:'Bebas Neue','Anton',sans-serif;font-weight:400;letter-spacing:.01em;}
.topbar{
  display:flex;align-items:center;justify-content:space-between;
  padding:18px 22px 14px 22px;
  border-bottom:1px solid var(--line);
}
.logo{font-family:'Bebas Neue','Anton',sans-serif;font-size:20px;letter-spacing:.03em;color:var(--paper);}
.logo span{color:var(--accent2);}
.seedtag{font-family:'IBM Plex Mono',monospace;font-size:10px;color:var(--muted);}
.screen{padding:26px 22px 10px 22px;}
.hero-title{font-size:44px;line-height:0.95;margin:14px 0 10px 0;color:var(--paper);}
.hero-title em{font-style:normal;color:var(--accent2);}
.sub{color:var(--muted);font-size:14.5px;line-height:1.5;margin-bottom:22px;}
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  font-family:'Bebas Neue',sans-serif;font-size:19px;letter-spacing:.04em;
  background:var(--accent);color:#160f0a;border:none;border-radius:3px;
  padding:14px 22px;width:100%;cursor:pointer;
  box-shadow:0 3px 0 #a03e08, 0 8px 18px rgba(232,89,12,0.25);
  transition:transform .08s ease;
}
.btn:active{transform:translateY(2px);box-shadow:0 1px 0 #a03e08;}
.btn.secondary{
  background:transparent;color:var(--paper);border:1px solid var(--line2);
  box-shadow:none;
}
.btn.gold{background:var(--gold);box-shadow:0 3px 0 #9a7220,0 8px 18px rgba(216,161,58,.25);}
.btn.small{padding:10px 16px;font-size:16px;width:auto;}
.row{display:flex;gap:10px;}
.row > .btn{flex:1;}
.card{
  background:linear-gradient(180deg,#1c1613,#161110);
  border:1px solid var(--line);border-radius:10px;padding:18px;margin-bottom:14px;
}
.pos-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px;}
.pos-card{
  border:1px solid var(--line);border-radius:8px;padding:14px 12px;cursor:pointer;
  background:#171210;transition:.12s ease;
}
.pos-card:active{transform:scale(.97);}
.pos-card .abbr{font-family:'Bebas Neue','Anton',sans-serif;font-size:26px;color:var(--accent2);}
.pos-card .name{font-size:13px;color:var(--paper);font-weight:600;margin-top:2px;}
.pos-card .desc{font-size:11.5px;color:var(--muted);margin-top:4px;line-height:1.35;}
.legend-select{
  width:100%;background:#171210;color:var(--paper);border:1px solid var(--line2);
  border-radius:8px;padding:13px 12px;font-family:'Inter',sans-serif;font-size:15px;
}
.attr-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.attr-pick{
  border:1px solid var(--line);border-radius:8px;padding:11px 10px;text-align:center;
  font-size:13px;color:var(--paper);cursor:pointer;background:#171210;transition:.12s ease;
}
.attr-pick:active{transform:scale(.97);}
.attr-pick.active{background:var(--accent);color:#160f0a;border-color:var(--accent);font-weight:700;}
.attr-pick.used{opacity:.35;cursor:not-allowed;color:var(--muted);}
.clock-wrap{display:flex;flex-direction:column;align-items:center;padding:20px 0 6px 0;}
.clock{
  width:150px;height:150px;border-radius:50%;
  border:5px solid var(--wood);
  display:flex;align-items:center;justify-content:center;position:relative;
  background:radial-gradient(circle at 40% 30%, #241b12, #100b08 75%);
}
.clock .num{font-family:'Bebas Neue',sans-serif;font-size:52px;color:var(--accent2);}
.clock svg{position:absolute;top:-5px;left:-5px;}
.spin-counter{font-family:'IBM Plex Mono',monospace;font-size:12px;color:var(--muted);margin-top:14px;letter-spacing:.08em;}
.steal-card{
  text-align:center;padding:22px 16px;
}
.steal-legend{font-family:'IBM Plex Mono',monospace;font-size:12px;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;}
.steal-name{font-family:'Bebas Neue','Anton',sans-serif;font-size:26px;color:var(--paper);margin:6px 0 2px 0;}
.steal-attr{font-size:15px;color:var(--muted);margin-bottom:4px;}
.steal-amt{font-family:'Bebas Neue',sans-serif;font-size:38px;color:var(--gold);}
.current-legend-name{font-family:'Bebas Neue','Anton',sans-serif;font-size:24px;color:var(--accent2);margin:4px 0 2px 0;}
.tier-comum{color:#9fb0bd;}
.tier-estrela{color:#7fc7ff;}
.tier-lenda{color:#d8a13a;}
.tier-mito{color:#ff5b5b;}
.bar-row{display:flex;align-items:center;gap:8px;margin-bottom:7px;}
.bar-label{width:104px;font-size:11.5px;color:var(--muted);font-family:'IBM Plex Mono',monospace;flex-shrink:0;}
.bar-track{flex:1;height:7px;background:#241c17;border-radius:4px;overflow:hidden;}
.bar-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:4px;}
.bar-val{width:26px;text-align:right;font-family:'IBM Plex Mono',monospace;font-size:11.5px;color:var(--paper);}
.ovr-badge{
  display:flex;align-items:center;gap:14px;margin-bottom:16px;
}
.ovr-num{font-family:'Bebas Neue','Anton',sans-serif;font-size:56px;color:var(--accent2);line-height:1;}
.ovr-meta .pos{font-family:'Bebas Neue',sans-serif;font-size:20px;color:var(--paper);letter-spacing:.03em;}
.ovr-meta .lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;}
.team-reveal{text-align:center;padding:10px 0 4px 0;}
.team-swatch{width:74px;height:74px;border-radius:50%;margin:0 auto 14px auto;display:flex;align-items:center;justify-content:center;font-family:'Bebas Neue','Anton',sans-serif;font-size:22px;color:#fff;border:3px solid rgba(255,255,255,.25);}
.team-name{font-family:'Bebas Neue','Anton',sans-serif;font-size:26px;}
.divider{height:1px;background:var(--line);margin:18px 0;}
.season-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px;}
.season-age{font-family:'Bebas Neue','Anton',sans-serif;font-size:30px;}
.season-year{font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--muted);}
.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:14px 0;}
.stat-box{background:#181210;border:1px solid var(--line);border-radius:7px;padding:9px 4px;text-align:center;}
.stat-box .v{font-family:'Bebas Neue',sans-serif;font-size:22px;color:var(--paper);}
.stat-box .k{font-size:9.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;}
.badge{
  display:inline-block;font-family:'IBM Plex Mono',monospace;font-size:10.5px;
  border:1px solid var(--gold);color:var(--gold);border-radius:20px;padding:3px 10px;margin:3px 4px 0 0;
}
.badge.ring{border-color:#c9a227;background:rgba(201,162,39,.1);}
.note{font-size:13px;color:var(--muted);line-height:1.5;margin-top:10px;}
.finals-score{display:flex;justify-content:center;align-items:center;gap:18px;margin:22px 0;}
.finals-score .v{font-family:'Bebas Neue','Anton',sans-serif;font-size:40px;}
.finals-score .sep{color:var(--muted);font-size:22px;}
.qtr-track{display:flex;justify-content:center;gap:6px;margin-bottom:18px;}
.qtr-dot{width:36px;height:5px;border-radius:3px;background:#241c17;}
.qtr-dot.done{background:var(--accent2);}
.tier-scale{display:flex;flex-direction:column-reverse;gap:3px;margin:16px 0;}
.tier-row{display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:5px;font-size:12px;color:var(--muted);}
.tier-row.active{background:rgba(232,89,12,.15);color:var(--paper);font-weight:700;}
.tier-row .n{font-family:'IBM Plex Mono',monospace;width:16px;}
.trajectory{display:flex;flex-direction:column;gap:8px;margin:14px 0;}
.traj-item{display:flex;align-items:center;gap:12px;padding:10px 12px;background:#181210;border:1px solid var(--line);border-radius:8px;position:relative;}
.traj-item:not(:last-child)::after{content:'';position:absolute;left:31px;bottom:-9px;width:2px;height:8px;background:var(--line2);}
.traj-swatch{width:42px;height:42px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-family:'Bebas Neue','Anton',sans-serif;font-size:13px;color:#fff;border:2px solid rgba(255,255,255,.25);}
.traj-info{flex:1;min-width:0;}
.traj-team{font-family:'Bebas Neue','Anton',sans-serif;font-size:15px;color:var(--paper);line-height:1.15;}
.traj-years{font-size:11px;color:var(--muted);font-family:'IBM Plex Mono',monospace;margin-top:2px;}
.traj-seasons{font-size:11px;color:var(--accent2);font-family:'IBM Plex Mono',monospace;white-space:nowrap;flex-shrink:0;text-align:right;}
.share-card{
  border-radius:14px;padding:24px 20px;position:relative;overflow:hidden;
  background:linear-gradient(160deg, var(--teamc1,#2b2320), var(--teamc2,#181210));
  border:1px solid rgba(255,255,255,.15);
}
.share-card::before{
  content:"";position:absolute;inset:0;
  background:repeating-linear-gradient(135deg, rgba(255,255,255,.03) 0 8px, transparent 8px 16px);
}
.share-top{display:flex;justify-content:space-between;position:relative;z-index:1;}
.share-ovr{font-family:'Bebas Neue','Anton',sans-serif;font-size:48px;color:#fff;}
.share-pos{font-family:'Bebas Neue',sans-serif;font-size:20px;color:rgba(255,255,255,.85);text-align:right;}
.share-tier{font-family:'Bebas Neue','Anton',sans-serif;font-size:22px;color:#fff;margin:14px 0 4px 0;position:relative;z-index:1;}
.share-team{font-size:12px;color:rgba(255,255,255,.7);position:relative;z-index:1;}
.share-stats{display:flex;gap:16px;margin-top:16px;position:relative;z-index:1;flex-wrap:wrap;}
.share-stats div{text-align:center;}
.share-stats .v{font-family:'Bebas Neue',sans-serif;font-size:24px;color:#fff;}
.share-stats .k{font-size:9px;color:rgba(255,255,255,.6);text-transform:uppercase;}
.watermark{font-family:'IBM Plex Mono',monospace;font-size:10px;color:rgba(255,255,255,.4);margin-top:16px;position:relative;z-index:1;}
.fair{font-size:11px;color:var(--muted);text-align:center;margin-top:22px;line-height:1.5;}
.linklike{color:var(--accent2);text-decoration:underline;cursor:pointer;background:none;border:none;font-size:inherit;font-family:inherit;padding:0;}
input[type=text]{
  width:100%;background:#171210;border:1px solid var(--line2);border-radius:6px;color:var(--paper);
  padding:11px 12px;font-family:'IBM Plex Mono',monospace;font-size:13px;margin-bottom:14px;
}
::selection{background:var(--accent);color:#160f0a;}
</style>
</head>
<body>
<?php include __DIR__ . '/../_partials/nav.php'; ?>
<div id="app"></div>
<script>window.__TEAM_LOGOS__ = <?= json_encode($teamLogos, JSON_UNESCAPED_UNICODE) ?>;</script>
<script>
/* ---------- RNG determinístico ---------- */
function mulberry32(a){
  return function(){
    a |= 0; a = a + 0x6D2B79F5 | 0;
    let t = Math.imul(a ^ a >>> 15, 1 | a);
    t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
    return ((t ^ t >>> 14) >>> 0) / 4294967296;
  }
}
function seedFromString(str){
  let h = 1779033703 ^ str.length;
  for (let i = 0; i < str.length; i++) {
    h = Math.imul(h ^ str.charCodeAt(i), 3432918353);
    h = h << 13 | h >>> 19;
  }
  return (h ^ h >>> 16) >>> 0;
}
let rng = Math.random;
function ri(min,max){ return Math.floor(rng()*(max-min+1))+min; }
function pick(arr){ return arr[Math.floor(rng()*arr.length)]; }
function clamp(v,min,max){ return Math.max(min,Math.min(max,v)); }
function teamSwatchHtml(name, c1, c2, sizeStyle){
  const logo = (window.__TEAM_LOGOS__ || {})[name];
  const initials = name.split(' ').map(w=>w[0]).slice(-2).join('');
  if (logo){
    return `<div class="team-swatch" style="${sizeStyle||''}background:#111;overflow:hidden;"><img src="${logo}" alt="${initials}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.parentElement.style.background='linear-gradient(135deg,${c1},${c2})';this.parentElement.textContent='${initials}';"></div>`;
  }
  return `<div class="team-swatch" style="${sizeStyle||''}background:linear-gradient(135deg,${c1},${c2});">${initials}</div>`;
}

/* ---------- Dados ---------- */
const POSITIONS = {
  PG:{name:'Armador', abbr:'PG', desc:'Cria pro time, comanda a bola. Passe e drible acima de tudo.', weights:{arr3:.18,arm:.08,pas:.28,dri:.20,def:.10,fis:.04,sal:.06,cl:.06}},
  SG:{name:'Ala-armador', abbr:'SG', desc:'Pontuador de perímetro. Arremesso, drible e sangue frio.', weights:{arr3:.24,arm:.16,pas:.10,dri:.16,def:.12,fis:.06,sal:.08,cl:.08}},
  SF:{name:'Ala', abbr:'SF', desc:'Faz de tudo um pouco. O mais equilibrado das cinco posições.', weights:{arr3:.16,arm:.14,pas:.12,dri:.12,def:.14,fis:.12,sal:.12,cl:.08}},
  PF:{name:'Ala-pivô', abbr:'PF', desc:'Físico e finalização por dentro, disputa rebote e defende a pintura.', weights:{arr3:.08,arm:.16,pas:.08,dri:.06,def:.18,fis:.20,sal:.16,cl:.08}},
  C:{name:'Pivô', abbr:'C', desc:'A muralha embaixo da cesta. Físico, salto e defesa de área.', weights:{arr3:.02,arm:.14,pas:.06,dri:.04,def:.22,fis:.26,sal:.20,cl:.06}}
};
const ATTRS = {
  arr3:'Arremesso de 3', arm:'Finalização', pas:'Passe', dri:'Drible',
  def:'Defesa', fis:'Físico/Atleticismo', sal:'Iq', cl:'Clutch'
};
const LEAGUE = [
{name:"Anchorage Envood", gm:"Caio Capobiango Cardoso Fonseca (Zaza)", roster:[
  {name:"Ron Holland 4x🧃",pos:"PF",ovr:93,age:26},{name:"Eric Gordon",pos:"SF",ovr:85,age:34},
  {name:"Max Christie",pos:"SG",ovr:85,age:27},{name:"Larry Sanders",pos:"C",ovr:84,age:26},
  {name:"Markelle Fultz",pos:"PG",ovr:80,age:34},{name:"Dejounte Murray",pos:"PG",ovr:84,age:31},
  {name:"Jaden McDaniels",pos:"PF",ovr:83,age:29},{name:"Alperen Sengun",pos:"C",ovr:82,age:32},
  {name:"Pascal Siakam",pos:"SF",ovr:82,age:33},{name:"Brice Sensabaugh",pos:"SG",ovr:81,age:25},
  {name:"Iman Shumpert",pos:"SG",ovr:75,age:21},{name:"Spencer Hawes",pos:"C",ovr:75,age:20},
  {name:"Maciej Lampe",pos:"C",ovr:80,age:31}], gleague:[
  {name:"Romeo Langford",pos:"SG",ovr:76,age:21},{name:"Lonnie Walker IV",pos:"SG",ovr:73,age:19}]},
{name:"Athens Olympics", gm:"Kleberson Barreto Costa", roster:[
  {name:"Zach Lavine",pos:"SG",ovr:92,age:29},{name:"Anthony Davis",pos:"PF",ovr:89,age:36},
  {name:"Jaden Ivey",pos:"PG",ovr:89,age:28},{name:"Myles Turner",pos:"C",ovr:85,age:32},
  {name:"Klay Thompson",pos:"SF",ovr:84,age:22},{name:"Jalen Green",pos:"SG",ovr:83,age:33},
  {name:"James Anderson",pos:"SF",ovr:81,age:26},{name:"Will Richard",pos:"SG",ovr:80,age:25},
  {name:"Marcus Morris",pos:"PF",ovr:78,age:23},{name:"Darko Milicic",pos:"C",ovr:76,age:31},
  {name:"D'Angelo Russell",pos:"PG",ovr:78,age:32},{name:"Matisse Thybulle",pos:"SG",ovr:77,age:24},
  {name:"Nick Young",pos:"SG",ovr:75,age:22},{name:"Raul Neto",pos:"PG",ovr:73,age:30},
  {name:"Isaac Bonga",pos:"SF",ovr:67,age:28}]},
{name:"Boston Panthers", gm:"Ian de Oliveira Barbosa", roster:[
  {name:"Deni Avdija",pos:"SF",ovr:89,age:29},{name:"Tre Johnson",pos:"SG",ovr:87,age:23},
  {name:"Isaiah Thomas",pos:"PG",ovr:80,age:23},{name:"Bill Walton",pos:"C",ovr:78,age:20},
  {name:"Luke Ridnour",pos:"PG",ovr:85,age:34},{name:"Jabari Parker",pos:"PF",ovr:82,age:30},
  {name:"Tobias Harris",pos:"C",ovr:79,age:20},{name:"Anthony Bennett",pos:"PF",ovr:80,age:29},
  {name:"Joan Beringer",pos:"C",ovr:79,age:23},{name:"Jaden Hardy",pos:"SG",ovr:78,age:31},
  {name:"Marcus Sasser",pos:"PG",ovr:78,age:28},{name:"Donatas Motiejunas",pos:"PF",ovr:75,age:21},
  {name:"Greg Monroe",pos:"C",ovr:75,age:31}]},
{name:"Buffalo Blackouts", gm:"Pedro Fava", roster:[
  {name:"Giannis Antetokounmpo",pos:"PG",ovr:91,age:28},{name:"Josh Green",pos:"SG",ovr:89,age:29},
  {name:"Michael Porter Jr",pos:"PF",ovr:87,age:22},{name:"Onyeka Okongwu",pos:"C",ovr:86,age:29},
  {name:"Kevin Durant",pos:"SF",ovr:81,age:18},{name:"CJ McCollum",pos:"PG",ovr:87,age:30},
  {name:"Nikola Jovic",pos:"PF",ovr:84,age:27},{name:"Jaylin Williams",pos:"PF",ovr:78,age:28},
  {name:"Alec Burks",pos:"SG",ovr:78,age:21},{name:"Kenyon Martin Jr.",pos:"PF",ovr:78,age:29},
  {name:"Killian Hayes",pos:"PG",ovr:78,age:29},{name:"Sion James",pos:"SF",ovr:78,age:25},
  {name:"Glen Davis",pos:"PF",ovr:74,age:21},{name:"Frankie Adrien",pos:"PF",ovr:68,age:26},
  {name:"Marquese Chriss",pos:"PF",ovr:63,age:32}]},
{name:"Calgary Mooses", gm:"Pedro Brandli Pereira Kanada Cardoso", roster:[
  {name:"Tyrese Maxey",pos:"PG",ovr:93,age:29},{name:"Zion Williamson",pos:"PF",ovr:90,age:21},
  {name:"Donte DiVincenzo",pos:"SG",ovr:88,age:23},{name:"Khaman Maluach",pos:"C",ovr:88,age:22},
  {name:"Gui Santos",pos:"SF",ovr:86,age:27},{name:"Isaac Okoro",pos:"PF",ovr:82,age:29},
  {name:"Patrick O'Bryant",pos:"C",ovr:80,age:26},{name:"Rodney Stuckey",pos:"PG",ovr:75,age:21},
  {name:"Kenneth Faried",pos:"PF",ovr:78,age:22},{name:"Nerlens Noel",pos:"PF",ovr:78,age:28},
  {name:"Alex Len",pos:"C",ovr:74,age:29},{name:"Amari Williams",pos:"C",ovr:72,age:25},
  {name:"Nassir Little",pos:"SF",ovr:72,age:23}]},
{name:"Chicago Dope", gm:"Thales Victor Freitas Coelho Gonzalez", roster:[
  {name:"Amen Thompson",pos:"PF",ovr:94,age:26},{name:"Gradey Dick",pos:"SF",ovr:89,age:25},
  {name:"Kon Knueppel",pos:"SG",ovr:89,age:23},{name:"DeMarcus Cousins",pos:"C",ovr:87,age:24},
  {name:"Dyson Daniels 🧃",pos:"PG",ovr:87,age:27},{name:"Rasheer Fleming",pos:"PF",ovr:81,age:24},
  {name:"Nikola Vucevic",pos:"C",ovr:80,age:22},{name:"Mike Conley",pos:"PG",ovr:78,age:19},
  {name:"Nikola Mirotic",pos:"PF",ovr:77,age:21},{name:"Jaime Jaquez Jr.",pos:"SF",ovr:78,age:27},
  {name:"Josh Okogie",pos:"SG",ovr:77,age:21},{name:"Landry Shamet",pos:"SG",ovr:77,age:23},
  {name:"Noah Penda",pos:"SF",ovr:75,age:23},{name:"Rudy Fernandez",pos:"SG",ovr:71,age:22}]},
{name:"Colorado Frostborn", gm:"Matheus Muniz", roster:[
  {name:"Carmelo Anthony",pos:"SF",ovr:94,age:30},{name:"Ben Simmons",pos:"PG",ovr:86,age:31},
  {name:"Collin Sexton",pos:"PG",ovr:85,age:21},{name:"Collin Murray-Boyles",pos:"PF",ovr:83,age:25},
  {name:"Walker Kessler",pos:"C",ovr:85,age:29},{name:"Kemba Walker",pos:"PG",ovr:81,age:22},
  {name:"Cam Reddish",pos:"SF",ovr:80,age:21},{name:"Norman Powell",pos:"SG",ovr:77,age:35},
  {name:"Marc Gasol",pos:"C",ovr:72,age:22},{name:"Royce O'Neale",pos:"SF",ovr:64,age:35},
  {name:"Jordan Walsh",pos:"SF",ovr:79,age:25},{name:"Marthy Mccarthy",pos:"PG",ovr:78,age:24},
  {name:"Landry Fields",pos:"SG",ovr:71,age:25},{name:"Courtney Outlaw",pos:"SF",ovr:40,age:30}]},
{name:"Dallas Blues", gm:"Mateus Maia", roster:[
  {name:"Jabari Smith Jr 🧃🧃",pos:"PF",ovr:94,age:27},{name:"Russell Westbrook",pos:"PG",ovr:94,age:34},
  {name:"Joe Dumars",pos:"PG",ovr:88,age:32},{name:"Tari Eason",pos:"PF",ovr:86,age:29},
  {name:"Clint Capela",pos:"C",ovr:83,age:30},{name:"Kobe Bufkin",pos:"SF",ovr:81,age:25},
  {name:"GG Jackson",pos:"SF",ovr:80,age:25},{name:"Barney Thorpe",pos:"C",ovr:79,age:25},
  {name:"Jimmer Fredette",pos:"PG",ovr:77,age:23},{name:"Xavier Tillman Sr.",pos:"C",ovr:77,age:31},
  {name:"Brandon Knight",pos:"PG",ovr:76,age:19},{name:"Aaron Nesmith",pos:"SG",ovr:75,age:30},
  {name:"Shake Milton",pos:"SG",ovr:74,age:23},{name:"Ramon Sessions",pos:"PG",ovr:73,age:21},
  {name:"Ryan Broekhoff",pos:"SF",ovr:70,age:31}]},
{name:"El Paso Guerreros", gm:"Remerson Barboza", roster:[
  {name:"Jaylen Brown",pos:"SG",ovr:90,age:31},{name:"Brandon Ingram",pos:"SF",ovr:88,age:32},
  {name:"Adam Morrison",pos:"PF",ovr:85,age:27},{name:"Nikola Topic",pos:"PG",ovr:85,age:25},
  {name:"Yanic Niederhauser",pos:"C",ovr:82,age:25},{name:"De'Aaron Fox",pos:"PG",ovr:84,age:35},
  {name:"Kendall Gill",pos:"SG",ovr:84,age:32},{name:"PJ Washington",pos:"PF",ovr:81,age:22},
  {name:"Liviu Paunescu",pos:"SF",ovr:80,age:26},{name:"Jett Howard",pos:"SG",ovr:85,age:25},
  {name:"Caleb Houstan",pos:"SG",ovr:81,age:30},{name:"Daniel Gibson",pos:"PG",ovr:80,age:26},
  {name:"Ryan Dunn",pos:"SF",ovr:79,age:27}]},
{name:"Hawaii Heatwave", gm:"Guilherme Faleiro", roster:[
  {name:"Paolo Banchero",pos:"PF",ovr:95,age:27},{name:"Elgin Baylor",pos:"SF",ovr:94,age:29},
  {name:"Kyle Lowry",pos:"PG",ovr:94,age:26},{name:"Rob Dillingham",pos:"SG",ovr:88,age:26},
  {name:"James Wiseman",pos:"C",ovr:84,age:29},{name:"Nick Smith Jr.",pos:"PG",ovr:78,age:25},
  {name:"Jan Veselý",pos:"PF",ovr:77,age:22},{name:"Norris Cole",pos:"PG",ovr:75,age:23},
  {name:"Oshae Brissett",pos:"SF",ovr:75,age:23},{name:"Marco Belinelli",pos:"SG",ovr:74,age:21},
  {name:"Zach Edey",pos:"C",ovr:74,age:29},{name:"Chandler Parsons",pos:"SF",ovr:71,age:24},
  {name:"Malcolm Delaney",pos:"PG",ovr:69,age:23},{name:"Jarvis Hayes",pos:"SF",ovr:68,age:33},
  {name:"Marion Griffin",pos:"PF",ovr:44,age:33}]},
{name:"Houston Parfums", gm:"Kenderson Fellipe Aguiar Freitas", roster:[
  {name:"Victor Wembanyama",pos:"PF",ovr:98,age:25},{name:"Paul George",pos:"SG",ovr:94,age:24},
  {name:"Scoot Henderson",pos:"PG",ovr:92,age:25},{name:"Deandre Ayton",pos:"C",ovr:87,age:22},
  {name:"Bilal Coulibaly",pos:"SG",ovr:83,age:25},{name:"Max Shulga",pos:"SG",ovr:78,age:26},
  {name:"Jazian Gortman",pos:"PG",ovr:77,age:26},{name:"Carl Landry",pos:"PF",ovr:76,age:23},
  {name:"Moritz Wagner",pos:"C",ovr:76,age:22},{name:"Duncan Robinson",pos:"SF",ovr:75,age:26},
  {name:"Gabe Vicent",pos:"PG",ovr:75,age:24},{name:"Adem Bona",pos:"C",ovr:76,age:25},
  {name:"Leon Powe",pos:"PF",ovr:76,age:27},{name:"Karlo Matkovic",pos:"PF",ovr:74,age:28},
  {name:"Pat Connaughton",pos:"SF",ovr:62,age:34}]},
{name:"Kansas City Swifties", gm:"Caio Gomes", roster:[
  {name:"Jordan Farmar",pos:"PG",ovr:92,age:26},{name:"Gordon Hayward",pos:"SG",ovr:91,age:25},
  {name:"Scottie Barnes 🎖️",pos:"SF",ovr:91,age:34},{name:"Derrick Favors",pos:"PF",ovr:87,age:24},
  {name:"Thomas Sorber",pos:"C",ovr:86,age:23},{name:"Hassan Whiteside",pos:"C",ovr:81,age:26},
  {name:"Gary Trent Jr",pos:"SF",ovr:80,age:22},{name:"Zhaire Smith",pos:"SG",ovr:76,age:22},
  {name:"Nemanja Bjelica",pos:"PF",ovr:75,age:27},{name:"Corey Brewer",pos:"SF",ovr:73,age:21},
  {name:"Rashad Vaughn",pos:"SG",ovr:73,age:31},{name:"Adreian Payne",pos:"PF",ovr:54,age:34},
  {name:"Dennis Schroeder",pos:"PG",ovr:77,age:28},{name:"Javon Small",pos:"PG",ovr:76,age:25},
  {name:"Jordan Crawford",pos:"SG",ovr:76,age:26}]},
{name:"Kentucky Cavalinhos", gm:"Leonardo Cardoso", roster:[
  {name:"Dylan Harper",pos:"SG",ovr:92,age:23},{name:"Aaron Gordon",pos:"SF",ovr:88,age:29},
  {name:"Derik Queen",pos:"C",ovr:88,age:23},{name:"Paul Millsap",pos:"PF",ovr:86,age:27},
  {name:"De'Andre Hunter",pos:"SG",ovr:84,age:23},{name:"Dariq Whitehead",pos:"SF",ovr:86,age:25},
  {name:"Bruce Brown",pos:"SG",ovr:79,age:23},{name:"Danny Wolf",pos:"PF",ovr:79,age:24},
  {name:"Daniel Gafford",pos:"C",ovr:77,age:22},{name:"Tiago Splitter",pos:"C",ovr:72,age:22},
  {name:"Liam McNeeley",pos:"SF",ovr:80,age:22},{name:"Christian Koloko",pos:"C",ovr:78,age:30},
  {name:"Caris LeVert",pos:"SG",ovr:76,age:32},{name:"Leonard Miller",pos:"SF",ovr:76,age:25}],
  gleague:[{name:"Reggie Jackson",pos:"PG",ovr:75,age:22}]},
{name:"Los Angeles Celestials", gm:"Lázaro Costa Resende", roster:[
  {name:"VJ Edgecombe",pos:"SG",ovr:97,age:23},{name:"Charles Barkley",pos:"PF",ovr:96,age:35},
  {name:"Mikal Bridges",pos:"SF",ovr:89,age:23},{name:"Anthony Black",pos:"PG",ovr:87,age:25},
  {name:"Rudy Gobert",pos:"C",ovr:84,age:30},{name:"Ben Saraf",pos:"PG",ovr:78,age:23},
  {name:"Bojan Bogdanovic",pos:"SF",ovr:77,age:23},{name:"Bol Bol",pos:"C",ovr:77,age:21},
  {name:"Adama Bal",pos:"SG",ovr:76,age:26},{name:"Jaylen Wells",pos:"SF",ovr:75,age:26},
  {name:"Cole Aldrich",pos:"C",ovr:74,age:26},{name:"Rondae Hollis-Jefferson",pos:"SF",ovr:72,age:33},
  {name:"Brandon Boston Jr.",pos:"SG",ovr:71,age:33}],
  gleague:[{name:"Cory Joseph",pos:"PG",ovr:77,age:20},{name:"Javaris Crittenton",pos:"SG",ovr:74,age:20}]},
{name:"Los Angeles Souks", gm:"Henrick Taufner", roster:[
  {name:"Ace Bailey",pos:"SF",ovr:95,age:22},{name:"Cade Cunningham",pos:"PG",ovr:94,age:33},
  {name:"Asa Newell",pos:"PF",ovr:90,age:22},{name:"Kevin Seraphin",pos:"C",ovr:83,age:25},
  {name:"Devin Vassell",pos:"SG",ovr:81,age:29},{name:"Wayne Bryant",pos:"SF",ovr:82,age:24},
  {name:"A.J Johnson",pos:"SG",ovr:80,age:26},{name:"Lucas Nogueira",pos:"C",ovr:80,age:30},
  {name:"Tristan Da Silva",pos:"PF",ovr:80,age:30},{name:"Yves Missi",pos:"C",ovr:80,age:26},
  {name:"Daniel Orton",pos:"C",ovr:75,age:24},{name:"Jalen Smith",pos:"PF",ovr:74,age:30},
  {name:"Koby Brea",pos:"SF",ovr:73,age:24},{name:"Cliff Sloan",pos:"PF",ovr:40,age:24}],
  gleague:[{name:"Ty Jerome",pos:"PG",ovr:73,age:23}]},
{name:"Louisville Shuffle", gm:"Gabriel da Silva Jardim de Matos", roster:[
  {name:"Jalen Williams",pos:"SG",ovr:95,age:29},{name:"Andrea Bargnani 🧃",pos:"PF",ovr:88,age:25},
  {name:"Kyrie Irving",pos:"PG",ovr:88,age:20},{name:"Thabo Sefolosha",pos:"SF",ovr:88,age:28},
  {name:"Dereck Lively II",pos:"C",ovr:84,age:25},{name:"Kristaps Porzingis",pos:"PF",ovr:85,age:33},
  {name:"Al Horford",pos:"PF",ovr:78,age:20},{name:"Acie Law",pos:"PG",ovr:75,age:22},
  {name:"Taran Armstrong",pos:"PG",ovr:73,age:28},{name:"Pops Mensah-Bonsu",pos:"PF",ovr:79,age:25},
  {name:"Alexey Shved",pos:"SG",ovr:75,age:26},{name:"Cam Spencer",pos:"SG",ovr:74,age:29},
  {name:"Tim Henderson",pos:"PG",ovr:72,age:25},{name:"Craig Brackins",pos:"PF",ovr:71,age:27}]},
{name:"México City Catrinas", gm:"Jose Vinicius Leal Cortez", roster:[
  {name:"Cooper Flagg",pos:"PF",ovr:97,age:23},{name:"Luka Doncic",pos:"PG",ovr:94,age:22},
  {name:"Brandon Miller",pos:"SF",ovr:93,age:26},{name:"Alex Sarr",pos:"C",ovr:87,age:26},
  {name:"Avery Bradley",pos:"SG",ovr:84,age:24},{name:"Mitchell Robinson",pos:"C",ovr:80,age:22},
  {name:"Kyle Anderson",pos:"SF",ovr:75,age:31},{name:"Alex Toohey",pos:"SG",ovr:75,age:24},
  {name:"Elie Okobo",pos:"PG",ovr:75,age:22},{name:"Dāvis Bertāns",pos:"PF",ovr:74,age:20},
  {name:"Baylor Scheierman",pos:"SG",ovr:73,age:30},{name:"Loren Hudson",pos:"PG",ovr:73,age:24},
  {name:"Jason Smith",pos:"PF",ovr:71,age:21}]},
{name:"Miami Sunsets", gm:"Caio Esteves Motta", roster:[
  {name:"Bernard King",pos:"SF",ovr:94,age:24},{name:"Moses Malone",pos:"C",ovr:93,age:28},
  {name:"Karl-Anthony Towns",pos:"PF",ovr:90,age:32},{name:"Reed Sheppard",pos:"SG",ovr:90,age:27},
  {name:"Marcus Smart",pos:"SG",ovr:82,age:31},{name:"TyTy Washington Jr",pos:"SG",ovr:84,age:28},
  {name:"Bruno Caboclo",pos:"SF",ovr:75,age:29},{name:"Xavier Henry",pos:"SF",ovr:75,age:22},
  {name:"Sean Williams",pos:"C",ovr:74,age:20},{name:"Andrew Nembhard",pos:"PG",ovr:73,age:30},
  {name:"Jhonni Broone",pos:"C",ovr:76,age:27},{name:"Brent Price",pos:"PF",ovr:60,age:33},
  {name:"Trent Jones-Garcia",pos:"C",ovr:53,age:31}]},
{name:"Milwaukee Beezz", gm:"Vinicius Rocha", roster:[
  {name:"Dwyane Wade",pos:"SG",ovr:93,age:33},{name:"Ja Morant",pos:"PG",ovr:89,age:21},
  {name:"Cam Whitmore",pos:"SG",ovr:88,age:25},{name:"Noa Essengue",pos:"PF",ovr:85,age:23},
  {name:"Greg Oden",pos:"C",ovr:80,age:19},{name:"Keyonte George 🧃",pos:"PG",ovr:85,age:25},
  {name:"Jamal Shead",pos:"SG",ovr:82,age:28},{name:"Kevin Huerter",pos:"SF",ovr:82,age:21},
  {name:"Emoni Bates",pos:"SF",ovr:78,age:25},{name:"Collin Gillespie",pos:"PG",ovr:73,age:31},
  {name:"Quincy Pondexter",pos:"SF",ovr:80,age:27},{name:"Shelden Williams",pos:"PF",ovr:79,age:28},
  {name:"Markieff Morris",pos:"PF",ovr:76,age:22},{name:"Daishen Nix",pos:"PG",ovr:73,age:33}]},
{name:"New Jersey Reapers", gm:"Lucas Fernandes Rodrigues", roster:[
  {name:"Tyrese Haliburton",pos:"PG",ovr:96,age:30},{name:"Rick Barry",pos:"SF",ovr:94,age:29},
  {name:"Tyrus Thomas",pos:"C",ovr:92,age:25},{name:"Shaedon Sharpe",pos:"SG",ovr:85,age:27},
  {name:"Wendell Carter Jr",pos:"PF",ovr:85,age:22},{name:"Cameron Johnson",pos:"SF",ovr:79,age:25},
  {name:"Goga Bitadze",pos:"C",ovr:78,age:22},{name:"De'Anthony Melton",pos:"PG",ovr:76,age:22},
  {name:"Jeff Green",pos:"SF",ovr:76,age:20},{name:"Bruno Fernando",pos:"C",ovr:74,age:22},
  {name:"Ben Uzoh",pos:"PG",ovr:75,age:27},{name:"Kevon Looney",pos:"C",ovr:68,age:32},
  {name:"Ivan Rabb",pos:"PF",ovr:54,age:34}]},
{name:"New York Mafia", gm:"Murilo Toledo", roster:[
  {name:"LeBron James",pos:"SF",ovr:97,age:30},{name:"Rudy Gay 🧃",pos:"SG",ovr:89,age:24},
  {name:"Bronny James Jr",pos:"PG",ovr:88,age:25},{name:"Jarace Walker",pos:"PF",ovr:86,age:25},
  {name:"Brook Lopez",pos:"C",ovr:84,age:35},{name:"Jusuf Nurkic",pos:"C",ovr:82,age:30},
  {name:"Christian Braun",pos:"SG",ovr:81,age:29},{name:"Mitch Richmond",pos:"SG",ovr:78,age:26},
  {name:"Nicolas Claxton",pos:"C",ovr:77,age:22},{name:"Jarred Vanderbilt",pos:"PF",ovr:76,age:22},
  {name:"Jevon Carter",pos:"PG",ovr:74,age:24},{name:"Tudor Stefan",pos:"SF",ovr:70,age:25},
  {name:"C.J.Elleby",pos:"SF",ovr:73,age:29},{name:"Kira Lewis Jr",pos:"PG",ovr:59,age:33},
  {name:"Ben Jacobsen",pos:"SG",ovr:41,age:24}]},
{name:"Oakland Blue Foxes", gm:"Bruno Coelho", roster:[
  {name:"Anthony Edwards",pos:"SG",ovr:94,age:28},{name:"Rajon Rondo",pos:"PG",ovr:94,age:26},
  {name:"Joel Embiid",pos:"C",ovr:93,age:31},{name:"Jaren Jackson Jr",pos:"PF",ovr:86,age:21},
  {name:"Cedric Coward",pos:"SF",ovr:83,age:24},{name:"Lonnie Walker IV",pos:"SG",ovr:79,age:22},
  {name:"Hugo Gonzalez",pos:"SF",ovr:78,age:23},{name:"Yi Jianlian",pos:"PF",ovr:75,age:19},
  {name:"Aaron Holiday",pos:"PG",ovr:77,age:22},{name:"Chuma Okeke",pos:"SF",ovr:77,age:23},
  {name:"Eric Dawkins",pos:"SF",ovr:77,age:27},{name:"Grant Williams",pos:"PF",ovr:77,age:22},
  {name:"Al Thornton",pos:"SF",ovr:76,age:23},{name:"Tristan Vukcevic",pos:"C",ovr:76,age:26},
  {name:"Brandan Wright",pos:"PF",ovr:74,age:19}]},
{name:"Oklahoma Gunslingers", gm:"Lucas Ferreira Monteiro", roster:[
  {name:"Mickael Pietrus",pos:"SF",ovr:85,age:30},{name:"Brandon Roy",pos:"SG",ovr:84,age:26},
  {name:"Dalen Terry",pos:"SG",ovr:84,age:27},{name:"Taylor Hendricks",pos:"PF",ovr:83,age:23},
  {name:"Blake Wesley",pos:"PG",ovr:80,age:24},{name:"Jeremy Sochan",pos:"PF",ovr:84,age:26},
  {name:"Kendrick Perkins",pos:"PF",ovr:82,age:29},{name:"Precious Achiwua",pos:"PF",ovr:82,age:28},
  {name:"Steve Adams",pos:"C",ovr:81,age:25},{name:"Jalen Brunson",pos:"PG",ovr:78,age:21},
  {name:"Anfernee Simons",pos:"SG",ovr:77,age:20},{name:"Jaxson Hayes",pos:"C",ovr:75,age:19},
  {name:"Colin Kelly",pos:"C",ovr:79,age:26},{name:"Patrick Patterson",pos:"PF",ovr:79,age:26},
  {name:"Olivier-Maxence Prosper",pos:"PF",ovr:77,age:25}]},
{name:"Oregon Puddles", gm:"Daniel Victor Dias", roster:[
  {name:"Domantas Sabonis",pos:"C",ovr:92,age:33},{name:"Jayson Tatum",pos:"SF",ovr:90,age:35},
  {name:"Bennedict Mathurin",pos:"SG",ovr:87,age:28},{name:"Serge Ibaka",pos:"PF",ovr:86,age:33},
  {name:"Coby White",pos:"PG",ovr:84,age:21},{name:"Jared McCain",pos:"PG",ovr:84,age:27},
  {name:"Malaki Branham",pos:"SF",ovr:82,age:27},{name:"Moussa Diabate",pos:"C",ovr:79,age:28},
  {name:"Bryce McGowens",pos:"SG",ovr:80,age:30},{name:"Adou Thiero",pos:"PF",ovr:77,age:24},
  {name:"Gilberto Olivari",pos:"SG",ovr:73,age:25},{name:"Archie Goodwin",pos:"PG",ovr:71,age:27},
  {name:"Calbert Aldridge",pos:"PF",ovr:70,age:25}]},
{name:"Orlando Black Lions", gm:"Anderson Mariano Silva", roster:[
  {name:"LaMelo Ball",pos:"PG",ovr:92,age:28},{name:"Carter Bryant 🧃🧃",pos:"SF",ovr:91,age:23},
  {name:"Matas Buzelis 🧃🧃",pos:"PF",ovr:91,age:25},{name:"Stephon Castle",pos:"SG",ovr:90,age:26},
  {name:"Mohamed Bamba",pos:"C",ovr:83,age:22},{name:"Jordan Poole",pos:"SG",ovr:82,age:22},
  {name:"Gary Harris",pos:"SG",ovr:77,age:30},{name:"Jesse Edwards",pos:"C",ovr:77,age:29},
  {name:"Kyshawn George",pos:"PF",ovr:76,age:28},{name:"Wilson Chandler",pos:"SF",ovr:75,age:20},
  {name:"Kel'el Ware",pos:"C",ovr:75,age:27},{name:"Chris Singleton",pos:"SF",ovr:74,age:22},
  {name:"Jeremy Lin",pos:"PG",ovr:72,age:26}]},
{name:"Philadelphia Devils", gm:"Matheus Sampaio", roster:[
  {name:"Lamarcus Aldridge",pos:"PF",ovr:92,age:26},{name:"Ausar Thompson",pos:"SF",ovr:91,age:26},
  {name:"Trae Young",pos:"PG",ovr:91,age:21},{name:"RJ Barrett",pos:"SG",ovr:88,age:21},
  {name:"Jonas Valančiūnas",pos:"C",ovr:81,age:20},{name:"Leandro Barbosa",pos:"SG",ovr:84,age:32},
  {name:"J.J. Barea",pos:"PG",ovr:81,age:28},{name:"Thaddeus Young",pos:"PF",ovr:75,age:19},
  {name:"Ed Davis",pos:"C",ovr:77,age:26},{name:"Trevor Booker",pos:"PF",ovr:77,age:27},
  {name:"Gabriele Procida",pos:"SF",ovr:76,age:31},{name:"Jae'Sean Tate",pos:"SF",ovr:75,age:24},
  {name:"Mason Plumlee",pos:"C",ovr:65,age:32}]},
{name:"Pittsburgh Phantoms", gm:"Ágata Máximo", roster:[
  {name:"Derrick Rose",pos:"PG",ovr:92,age:34},{name:"Chris Bosh",pos:"C",ovr:89,age:31},
  {name:"Jase Richardson",pos:"SG",ovr:89,age:22},{name:"Evan Mobley",pos:"PF",ovr:87,age:34},
  {name:"Kawhi Leonard",pos:"SF",ovr:82,age:21},{name:"Darius Garland",pos:"PG",ovr:88,age:21},
  {name:"Joakim Noah",pos:"C",ovr:77,age:22},{name:"Georges Niang",pos:"SF",ovr:74,age:32},
  {name:"Clifton Madsen",pos:"C",ovr:80,age:26},{name:"N'Faly Dante",pos:"C",ovr:73,age:28},
  {name:"Walt Craig",pos:"SG",ovr:73,age:28},{name:"Daniel Theis",pos:"C",ovr:70,age:30},
  {name:"John Berger",pos:"PF",ovr:66,age:31}]},
{name:"San Antonio Vultures", gm:"Kevyn Martins", roster:[
  {name:"John Wall",pos:"SG",ovr:91,age:24},{name:"Larry Johnson",pos:"PF",ovr:91,age:30},
  {name:"Egor Demin",pos:"PG",ovr:85,age:23},{name:"Jimmy Butler",pos:"SF",ovr:81,age:22},
  {name:"Tristan Thompson",pos:"C",ovr:80,age:21},{name:"Tyler Herro",pos:"SG",ovr:85,age:21},
  {name:"Nolan Traore",pos:"SG",ovr:83,age:23},{name:"Derrick Williams",pos:"PF",ovr:79,age:21},
  {name:"Ryan Rollins",pos:"PG",ovr:83,age:28},{name:"Al-Farouq Aminu",pos:"SF",ovr:81,age:24},
  {name:"Lance Stephenson",pos:"SG",ovr:81,age:24},{name:"Cam Christie",pos:"SG",ovr:80,age:26},
  {name:"Mark Williams",pos:"C",ovr:80,age:28},{name:"Tidjane Salaun",pos:"C",ovr:80,age:25}],
  gleague:[{name:"Enes Freedom",pos:"C",ovr:79,age:19}]},
{name:"San Francisco JoyBoys", gm:"Yan Simão", roster:[
  {name:"Len Bias",pos:"SF",ovr:96,age:29},{name:"Devin Booker",pos:"SG",ovr:94,age:32},
  {name:"Josh Giddey",pos:"PG",ovr:87,age:31},{name:"Jalen Duren",pos:"C",ovr:86,age:27},
  {name:"Jonathan Kuminga",pos:"PF",ovr:86,age:32},{name:"Bub Carrington",pos:"PG",ovr:81,age:26},
  {name:"Robert Covington",pos:"SF",ovr:80,age:31},{name:"Ekpe Udoh",pos:"C",ovr:75,age:28},
  {name:"Jared Dudley",pos:"SF",ovr:75,age:22},{name:"Grayson Allen",pos:"SG",ovr:74,age:24},
  {name:"Seth Curry",pos:"SG",ovr:74,age:31},{name:"DaRon Holmes II",pos:"PF",ovr:73,age:28},
  {name:"Egor Kasparov",pos:"C",ovr:73,age:28},{name:"Kyle Singler",pos:"SF",ovr:73,age:24}],
  gleague:[{name:"Rayan Rupert",pos:"SG",ovr:75,age:25}]},
{name:"San Jose Carpinteros", gm:"Lennon Herman", roster:[
  {name:"Nikola Jokic",pos:"C",ovr:94,age:30},{name:"Jeremiah Fears",pos:"PG",ovr:92,age:22},
  {name:"Kasparas Jakucionis",pos:"PG",ovr:84,age:22},{name:"Drake Powell",pos:"SF",ovr:80,age:20},
  {name:"Julius Randle",pos:"PF",ovr:83,age:30},{name:"Isaiah Stewart",pos:"C",ovr:81,age:27},
  {name:"Stanley Johnson",pos:"SF",ovr:81,age:29},{name:"Marvin Bagley III",pos:"PF",ovr:80,age:20},
  {name:"Kevin Knox",pos:"SF",ovr:77,age:19},{name:"Rui Hachimura",pos:"SF",ovr:77,age:21},
  {name:"Jarrett Culver",pos:"SG",ovr:75,age:20},{name:"Julian Wright",pos:"SF",ovr:60,age:18}],
  gleague:[{name:"Brandin Podziemski",pos:"PG",ovr:81,age:24},{name:"Brandon Clarke",pos:"PF",ovr:76,age:22}]},
{name:"St. Louis Archers", gm:"Eduardo Antunes", roster:[
  {name:"Keegan Murray",pos:"SF",ovr:95,age:29},{name:"Chet Holmgren",pos:"PF",ovr:92,age:28},
  {name:"Shai Gilgeous-Alexander",pos:"PG",ovr:91,age:22},{name:"Donovan Clingan",pos:"C",ovr:86,age:27},
  {name:"Cason Wallace",pos:"SG",ovr:84,age:25},{name:"Bismack Biyombo",pos:"PF",ovr:79,age:19},
  {name:"Nickeil Alexander-Walker",pos:"SG",ovr:79,age:22},{name:"Robert Williams III",pos:"C",ovr:79,age:22},
  {name:"Keldon Johnson",pos:"SF",ovr:76,age:21},{name:"Sekou Doumbouya",pos:"SF",ovr:75,age:24},
  {name:"Daequan Cook",pos:"SG",ovr:73,age:20},{name:"Dante Exum",pos:"PG",ovr:73,age:30},
  {name:"Omari Spellman",pos:"PF",ovr:73,age:24}],
  gleague:[{name:"Lu Dort",pos:"SF",ovr:76,age:22},{name:"Naz Reid",pos:"C",ovr:76,age:21}]},
{name:"Washington Peacemakers", gm:"Victor Hugo Simoes", roster:[
  {name:"Zaccharie Risacher",pos:"SF",ovr:93,age:26},{name:"Kevin Love",pos:"PF",ovr:92,age:34},
  {name:"Victor Oladipo",pos:"PG",ovr:92,age:30},{name:"Noah Vonleh",pos:"C",ovr:86,age:29},
  {name:"Kentavious Caldwell-Pope",pos:"SG",ovr:83,age:29},{name:"Patrick Williams",pos:"SF",ovr:85,age:28},
  {name:"Miles Bridges",pos:"PF",ovr:83,age:22},{name:"Sun Yue",pos:"PG",ovr:71,age:21},
  {name:"Glenn Robinson III",pos:"SF",ovr:67,age:31},{name:"Travis Outlaw",pos:"SF",ovr:66,age:30},
  {name:"Dwight Powell",pos:"C",ovr:63,age:34},{name:"DeAndre Daniels",pos:"SF",ovr:57,age:33},
  {name:"Usman Garuba",pos:"C",ovr:52,age:34},{name:"Grant Reed",pos:"C",ovr:49,age:33}]}
];
function colorForIndex(i){
  const hue = (i*47) % 360;
  return [`hsl(${hue},60%,42%)`, `hsl(${hue},55%,22%)`];
}
const TEAMS = LEAGUE.map((t,i)=>{
  const [c1,c2] = colorForIndex(i);
  return [t.name, c1, c2];
});
function flattenPlayers(){
  const out = [];
  LEAGUE.forEach(t=>{
    (t.roster||[]).forEach(p=> out.push({name:p.name, pos:p.pos, ovr:p.ovr, age:p.age, team:t.name}));
    (t.gleague||[]).forEach(p=> out.push({name:p.name, pos:p.pos, ovr:p.ovr, age:p.age, team:t.name}));
  });
  return out;
}
const TIERS = [
  'Jogador comum','Sensação de uma temporada','Titular de rotação','Jogador histórico',
  'Top 50 de todos os tempos','Um dos melhores da sua posição','Top 10 de todos os tempos',
  'O melhor da sua posição na história','Top 5 de todos os tempos','Sempre citado no debate GOAT','O GOAT'
];
const LEGENDS = [
  {name:"Ron Holland", team:"Anchorage Envood", pos:"PF", ovr:93, arr3:78, arm:91, pas:81, dri:76, def:84, fis:90, sal:82, cl:86},
  {name:"Eric Gordon", team:"Anchorage Envood", pos:"SF", ovr:85, arr3:77, arm:80, pas:76, dri:75, def:70, fis:79, sal:76, cl:80},
  {name:"Max Christie", team:"Anchorage Envood", pos:"SG", ovr:85, arr3:83, arm:81, pas:72, dri:78, def:70, fis:75, sal:75, cl:74},
  {name:"Zach Lavine", team:"Athens Olympics", pos:"SG", ovr:92, arr3:88, arm:92, pas:80, dri:88, def:68, fis:82, sal:84, cl:88},
  {name:"Anthony Davis", team:"Athens Olympics", pos:"PF", ovr:89, arr3:76, arm:94, pas:76, dri:72, def:96, fis:94, sal:88, cl:90},
  {name:"Jaden Ivey", team:"Athens Olympics", pos:"PG", ovr:89, arr3:84, arm:76, pas:92, dri:94, def:68, fis:72, sal:82, cl:80},
  {name:"Myles Turner", team:"Athens Olympics", pos:"C", ovr:85, arr3:58, arm:88, pas:67, dri:62, def:80, fis:86, sal:75, cl:76},
  {name:"Deni Avdija", team:"Boston Panthers", pos:"SF", ovr:89, arr3:82, arm:86, pas:80, dri:82, def:76, fis:80, sal:80, cl:82},
  {name:"Tre Johnson", team:"Boston Panthers", pos:"SG", ovr:87, arr3:83, arm:82, pas:74, dri:84, def:72, fis:74, sal:74, cl:78},
  {name:"Luke Ridnour", team:"Boston Panthers", pos:"PG", ovr:85, arr3:74, arm:75, pas:87, dri:87, def:65, fis:72, sal:76, cl:78},
  {name:"Giannis Antetokounmpo", team:"Buffalo Blackouts", pos:"PG", ovr:91, arr3:74, arm:96, pas:84, dri:86, def:92, fis:98, sal:79, cl:88},
  {name:"Josh Green", team:"Buffalo Blackouts", pos:"SG", ovr:89, arr3:80, arm:84, pas:76, dri:86, def:70, fis:78, sal:78, cl:78},
  {name:"Michael Porter Jr", team:"Buffalo Blackouts", pos:"PF", ovr:87, arr3:76, arm:86, pas:73, dri:72, def:77, fis:86, sal:72, cl:76},
  {name:"Onyeka Okongwu", team:"Buffalo Blackouts", pos:"C", ovr:86, arr3:58, arm:89, pas:67, dri:60, def:85, fis:88, sal:74, cl:78},
  {name:"CJ McCollum", team:"Buffalo Blackouts", pos:"PG", ovr:87, arr3:77, arm:75, pas:91, dri:89, def:71, fis:71, sal:75, cl:80},
  {name:"Tyrese Maxey", team:"Calgary Mooses", pos:"PG", ovr:93, arr3:85, arm:89, pas:85, dri:90, def:72, fis:78, sal:83, cl:88},
  {name:"Zion Williamson", team:"Calgary Mooses", pos:"PF", ovr:90, arr3:68, arm:97, pas:74, dri:82, def:78, fis:97, sal:80, cl:89},
  {name:"Donte DiVincenzo", team:"Calgary Mooses", pos:"SG", ovr:88, arr3:86, arm:79, pas:78, dri:82, def:75, fis:75, sal:78, cl:82},
  {name:"Khaman Maluach", team:"Calgary Mooses", pos:"C", ovr:88, arr3:63, arm:93, pas:69, dri:66, def:83, fis:93, sal:74, cl:82},
  {name:"Gui Santos", team:"Calgary Mooses", pos:"SF", ovr:86, arr3:80, arm:79, pas:71, dri:75, def:75, fis:80, sal:75, cl:77},
  {name:"Amen Thompson", team:"Chicago Dope", pos:"PF", ovr:94, arr3:80, arm:92, pas:79, dri:74, def:88, fis:96, sal:78, cl:87},
  {name:"Gradey Dick", team:"Chicago Dope", pos:"SF", ovr:89, arr3:80, arm:80, pas:76, dri:82, def:78, fis:82, sal:76, cl:80},
  {name:"Kon Knueppel", team:"Chicago Dope", pos:"SG", ovr:89, arr3:86, arm:80, pas:76, dri:84, def:72, fis:76, sal:76, cl:82},
  {name:"DeMarcus Cousins", team:"Chicago Dope", pos:"C", ovr:87, arr3:58, arm:87, pas:71, dri:62, def:88, fis:92, sal:76, cl:80},
  {name:"Dyson Daniels", team:"Chicago Dope", pos:"PG", ovr:87, arr3:78, arm:73, pas:87, dri:87, def:67, fis:70, sal:80, cl:75},
  {name:"Carmelo Anthony", team:"Colorado Frostborn", pos:"SF", ovr:94, arr3:90, arm:92, pas:74, dri:82, def:71, fis:86, sal:86, cl:91},
  {name:"Ben Simmons", team:"Colorado Frostborn", pos:"PG", ovr:86, arr3:81, arm:77, pas:90, dri:88, def:66, fis:72, sal:75, cl:76},
  {name:"Collin Sexton", team:"Colorado Frostborn", pos:"PG", ovr:85, arr3:77, arm:71, pas:89, dri:84, def:70, fis:71, sal:73, cl:75},
  {name:"Walker Kessler", team:"Colorado Frostborn", pos:"C", ovr:85, arr3:59, arm:86, pas:68, dri:64, def:82, fis:90, sal:72, cl:74},
  {name:"Jabari Smith Jr", team:"Dallas Blues", pos:"PF", ovr:94, arr3:82, arm:88, pas:83, dri:74, def:87, fis:91, sal:83, cl:87},
  {name:"Russell Westbrook", team:"Dallas Blues", pos:"PG", ovr:94, arr3:72, arm:92, pas:90, dri:93, def:73, fis:94, sal:81, cl:87},
  {name:"Joe Dumars", team:"Dallas Blues", pos:"PG", ovr:88, arr3:78, arm:80, pas:91, dri:88, def:67, fis:73, sal:79, cl:80},
  {name:"Tari Eason", team:"Dallas Blues", pos:"PF", ovr:86, arr3:72, arm:84, pas:70, dri:68, def:79, fis:89, sal:77, cl:77},
  {name:"Jaylen Brown", team:"El Paso Guerreros", pos:"SG", ovr:90, arr3:83, arm:91, pas:78, dri:82, def:84, fis:89, sal:82, cl:90},
  {name:"Brandon Ingram", team:"El Paso Guerreros", pos:"SF", ovr:88, arr3:85, arm:88, pas:82, dri:86, def:74, fis:80, sal:86, cl:87},
  {name:"Adam Morrison", team:"El Paso Guerreros", pos:"PF", ovr:85, arr3:68, arm:80, pas:74, dri:70, def:80, fis:86, sal:70, cl:76},
  {name:"Nikola Topic", team:"El Paso Guerreros", pos:"PG", ovr:85, arr3:77, arm:74, pas:90, dri:87, def:68, fis:71, sal:76, cl:74},
  {name:"Jett Howard", team:"El Paso Guerreros", pos:"SG", ovr:85, arr3:80, arm:80, pas:73, dri:77, def:70, fis:73, sal:76, cl:79},
  {name:"Paolo Banchero", team:"Hawaii Heatwave", pos:"PF", ovr:95, arr3:78, arm:93, pas:80, dri:83, def:80, fis:92, sal:86, cl:88},
  {name:"Elgin Baylor", team:"Hawaii Heatwave", pos:"SF", ovr:94, arr3:88, arm:90, pas:83, dri:82, def:83, fis:88, sal:82, cl:85},
  {name:"Kyle Lowry", team:"Hawaii Heatwave", pos:"PG", ovr:94, arr3:82, arm:72, pas:91, dri:88, def:84, fis:77, sal:90, cl:90},
  {name:"Rob Dillingham", team:"Hawaii Heatwave", pos:"SG", ovr:88, arr3:85, arm:78, pas:75, dri:82, def:73, fis:77, sal:74, cl:78},
  {name:"Victor Wembanyama", team:"Houston Parfums", pos:"PF", ovr:98, arr3:88, arm:95, pas:82, dri:80, def:98, fis:94, sal:91, cl:91},
  {name:"Paul George", team:"Houston Parfums", pos:"SG", ovr:94, arr3:89, arm:84, pas:82, dri:91, def:74, fis:81, sal:83, cl:84},
  {name:"Scoot Henderson", team:"Houston Parfums", pos:"PG", ovr:92, arr3:80, arm:77, pas:96, dri:96, def:72, fis:78, sal:79, cl:79},
  {name:"Deandre Ayton", team:"Houston Parfums", pos:"C", ovr:87, arr3:62, arm:88, pas:69, dri:64, def:85, fis:91, sal:77, cl:77},
  {name:"Jordan Farmar", team:"Kansas City Swifties", pos:"PG", ovr:92, arr3:80, arm:77, pas:95, dri:90, def:73, fis:80, sal:79, cl:80},
  {name:"Gordon Hayward", team:"Kansas City Swifties", pos:"SG", ovr:91, arr3:83, arm:81, pas:81, dri:82, def:74, fis:78, sal:81, cl:81},
  {name:"Scottie Barnes", team:"Kansas City Swifties", pos:"SF", ovr:91, arr3:84, arm:82, pas:75, dri:82, def:76, fis:81, sal:82, cl:85},
  {name:"Derrick Favors", team:"Kansas City Swifties", pos:"PF", ovr:87, arr3:74, arm:86, pas:74, dri:73, def:77, fis:90, sal:72, cl:76},
  {name:"Thomas Sorber", team:"Kansas City Swifties", pos:"C", ovr:86, arr3:63, arm:85, pas:68, dri:63, def:87, fis:92, sal:70, cl:75},
  {name:"Dylan Harper", team:"Kentucky Cavalinhos", pos:"SG", ovr:92, arr3:84, arm:84, pas:78, dri:86, def:77, fis:82, sal:79, cl:81},
  {name:"Aaron Gordon", team:"Kentucky Cavalinhos", pos:"SF", ovr:88, arr3:77, arm:81, pas:75, dri:81, def:74, fis:79, sal:75, cl:83},
  {name:"Derik Queen", team:"Kentucky Cavalinhos", pos:"C", ovr:88, arr3:62, arm:93, pas:70, dri:66, def:89, fis:90, sal:74, cl:82},
  {name:"Paul Millsap", team:"Kentucky Cavalinhos", pos:"PF", ovr:86, arr3:71, arm:83, pas:70, dri:73, def:78, fis:87, sal:74, cl:81},
  {name:"Dariq Whitehead", team:"Kentucky Cavalinhos", pos:"SF", ovr:86, arr3:75, arm:79, pas:71, dri:76, def:71, fis:83, sal:77, cl:80},
  {name:"VJ Edgecombe", team:"Los Angeles Celestials", pos:"SG", ovr:97, arr3:93, arm:92, pas:87, dri:94, def:80, fis:87, sal:85, cl:88},
  {name:"Charles Barkley", team:"Los Angeles Celestials", pos:"PF", ovr:96, arr3:79, arm:96, pas:84, dri:82, def:88, fis:94, sal:81, cl:84},
  {name:"Mikal Bridges", team:"Los Angeles Celestials", pos:"SF", ovr:89, arr3:82, arm:82, pas:77, dri:81, def:89, fis:80, sal:84, cl:86},
  {name:"Anthony Black", team:"Los Angeles Celestials", pos:"PG", ovr:87, arr3:76, arm:78, pas:84, dri:84, def:84, fis:78, sal:82, cl:81},
  {name:"Ace Bailey", team:"Los Angeles Souks", pos:"SF", ovr:95, arr3:86, arm:85, pas:81, dri:83, def:82, fis:87, sal:81, cl:85},
  {name:"Cade Cunningham", team:"Los Angeles Souks", pos:"PG", ovr:94, arr3:82, arm:84, pas:91, dri:91, def:77, fis:79, sal:91, cl:89},
  {name:"Asa Newell", team:"Los Angeles Souks", pos:"PF", ovr:90, arr3:77, arm:88, pas:76, dri:75, def:82, fis:90, sal:78, cl:82},
  {name:"Jalen Williams", team:"Louisville Shuffle", pos:"SG", ovr:95, arr3:86, arm:88, pas:84, dri:86, def:84, fis:85, sal:87, cl:90},
  {name:"Andrea Bargnani", team:"Louisville Shuffle", pos:"PF", ovr:88, arr3:76, arm:89, pas:75, dri:70, def:83, fis:90, sal:73, cl:77},
  {name:"Kyrie Irving", team:"Louisville Shuffle", pos:"PG", ovr:88, arr3:92, arm:90, pas:88, dri:98, def:65, fis:70, sal:90, cl:95},
  {name:"Thabo Sefolosha", team:"Louisville Shuffle", pos:"SF", ovr:88, arr3:82, arm:81, pas:73, dri:83, def:75, fis:83, sal:74, cl:78},
  {name:"Kristaps Porzingis", team:"Louisville Shuffle", pos:"PF", ovr:85, arr3:71, arm:82, pas:72, dri:66, def:79, fis:85, sal:72, cl:77},
  {name:"Cooper Flagg", team:"Mexico City Catrinas", pos:"PF", ovr:97, arr3:85, arm:96, pas:80, dri:77, def:86, fis:93, sal:87, cl:90},
  {name:"Luka Doncic", team:"Mexico City Catrinas", pos:"PG", ovr:94, arr3:87, arm:90, pas:95, dri:92, def:68, fis:82, sal:96, cl:95},
  {name:"Brandon Miller", team:"Mexico City Catrinas", pos:"SF", ovr:93, arr3:87, arm:87, pas:80, dri:83, def:78, fis:88, sal:79, cl:82},
  {name:"Alex Sarr", team:"Mexico City Catrinas", pos:"C", ovr:87, arr3:62, arm:91, pas:73, dri:60, def:82, fis:92, sal:77, cl:80},
  {name:"Bernard King", team:"Miami Sunsets", pos:"SF", ovr:94, arr3:83, arm:84, pas:83, dri:87, def:83, fis:89, sal:83, cl:87},
  {name:"Moses Malone", team:"Miami Sunsets", pos:"C", ovr:93, arr3:60, arm:95, pas:72, dri:66, def:85, fis:95, sal:83, cl:90},
  {name:"Karl-Anthony Towns", team:"Miami Sunsets", pos:"PF", ovr:90, arr3:75, arm:84, pas:78, dri:74, def:83, fis:89, sal:80, cl:83},
  {name:"Reed Sheppard", team:"Miami Sunsets", pos:"SG", ovr:90, arr3:81, arm:84, pas:75, dri:86, def:76, fis:78, sal:75, cl:80},
  {name:"Dwyane Wade", team:"Milwaukee Beezz", pos:"SG", ovr:93, arr3:78, arm:95, pas:81, dri:88, def:89, fis:86, sal:89, cl:96},
  {name:"Ja Morant", team:"Milwaukee Beezz", pos:"PG", ovr:89, arr3:82, arm:78, pas:92, dri:88, def:68, fis:76, sal:76, cl:82},
  {name:"Cam Whitmore", team:"Milwaukee Beezz", pos:"SG", ovr:88, arr3:84, arm:83, pas:77, dri:84, def:75, fis:76, sal:76, cl:77},
  {name:"Noa Essengue", team:"Milwaukee Beezz", pos:"PF", ovr:85, arr3:73, arm:86, pas:71, dri:71, def:77, fis:87, sal:72, cl:80},
  {name:"Keyonte George", team:"Milwaukee Beezz", pos:"PG", ovr:85, arr3:77, arm:75, pas:84, dri:88, def:65, fis:72, sal:77, cl:74},
  {name:"Tyrese Haliburton", team:"New Jersey Reapers", pos:"PG", ovr:96, arr3:88, arm:78, pas:97, dri:91, def:76, fis:72, sal:94, cl:92},
  {name:"Rick Barry", team:"New Jersey Reapers", pos:"SF", ovr:94, arr3:87, arm:87, pas:79, dri:82, def:82, fis:84, sal:80, cl:89},
  {name:"Tyrus Thomas", team:"New Jersey Reapers", pos:"C", ovr:92, arr3:67, arm:92, pas:78, dri:70, def:90, fis:94, sal:75, cl:81},
  {name:"Shaedon Sharpe", team:"New Jersey Reapers", pos:"SG", ovr:85, arr3:79, arm:90, pas:74, dri:86, def:70, fis:84, sal:78, cl:81},
  {name:"Wendell Carter Jr", team:"New Jersey Reapers", pos:"PF", ovr:85, arr3:71, arm:80, pas:73, dri:71, def:80, fis:85, sal:73, cl:79},
  {name:"LeBron James", team:"New York Mafia", pos:"SF", ovr:97, arr3:83, arm:97, pas:95, dri:90, def:90, fis:98, sal:96, cl:97},
  {name:"Rudy Gay", team:"New York Mafia", pos:"SG", ovr:89, arr3:86, arm:82, pas:78, dri:86, def:74, fis:78, sal:78, cl:80},
  {name:"Bronny James Jr", team:"New York Mafia", pos:"PG", ovr:88, arr3:81, arm:77, pas:93, dri:89, def:72, fis:77, sal:76, cl:81},
  {name:"Jarace Walker", team:"New York Mafia", pos:"PF", ovr:86, arr3:71, arm:85, pas:75, dri:68, def:78, fis:89, sal:74, cl:81},
  {name:"Anthony Edwards", team:"Oakland Blue Foxes", pos:"SG", ovr:94, arr3:84, arm:94, pas:79, dri:88, def:84, fis:88, sal:84, cl:92},
  {name:"Rajon Rondo", team:"Oakland Blue Foxes", pos:"PG", ovr:94, arr3:67, arm:80, pas:96, dri:92, def:86, fis:74, sal:88, cl:90},
  {name:"Joel Embiid", team:"Oakland Blue Foxes", pos:"C", ovr:93, arr3:80, arm:97, pas:78, dri:73, def:90, fis:96, sal:89, cl:93},
  {name:"Jaren Jackson Jr", team:"Oakland Blue Foxes", pos:"PF", ovr:86, arr3:75, arm:82, pas:70, dri:73, def:79, fis:85, sal:74, cl:76},
  {name:"Mickael Pietrus", team:"Oklahoma Gunslingers", pos:"SF", ovr:85, arr3:79, arm:77, pas:76, dri:78, def:73, fis:81, sal:76, cl:76},
  {name:"Domantas Sabonis", team:"Oregon Puddles", pos:"C", ovr:92, arr3:75, arm:94, pas:92, dri:78, def:80, fis:92, sal:93, cl:88},
  {name:"Jayson Tatum", team:"Oregon Puddles", pos:"SF", ovr:90, arr3:90, arm:92, pas:82, dri:88, def:84, fis:86, sal:90, cl:93},
  {name:"Bennedict Mathurin", team:"Oregon Puddles", pos:"SG", ovr:87, arr3:83, arm:82, pas:77, dri:84, def:69, fis:74, sal:76, cl:77},
  {name:"Serge Ibaka", team:"Oregon Puddles", pos:"PF", ovr:86, arr3:74, arm:82, pas:71, dri:69, def:78, fis:85, sal:77, cl:78},
  {name:"LaMelo Ball", team:"Orlando Black Lions", pos:"PG", ovr:92, arr3:81, arm:80, pas:90, dri:92, def:72, fis:80, sal:80, cl:78},
  {name:"Carter Bryant", team:"Orlando Black Lions", pos:"SF", ovr:91, arr3:82, arm:85, pas:75, dri:81, def:81, fis:85, sal:76, cl:83},
  {name:"Matas Buzelis", team:"Orlando Black Lions", pos:"PF", ovr:91, arr3:78, arm:91, pas:79, dri:77, def:84, fis:90, sal:78, cl:84},
  {name:"Stephon Castle", team:"Orlando Black Lions", pos:"SG", ovr:90, arr3:84, arm:81, pas:80, dri:85, def:74, fis:79, sal:80, cl:84},
  {name:"Lamarcus Aldridge", team:"Philadelphia Devils", pos:"PF", ovr:92, arr3:74, arm:88, pas:81, dri:77, def:84, fis:93, sal:79, cl:81},
  {name:"Ausar Thompson", team:"Philadelphia Devils", pos:"SF", ovr:91, arr3:80, arm:84, pas:78, dri:81, def:76, fis:83, sal:80, cl:86},
  {name:"Trae Young", team:"Philadelphia Devils", pos:"PG", ovr:91, arr3:88, arm:79, pas:93, dri:94, def:60, fis:66, sal:86, cl:88},
  {name:"RJ Barrett", team:"Philadelphia Devils", pos:"SG", ovr:88, arr3:83, arm:79, pas:76, dri:84, def:72, fis:81, sal:76, cl:82},
  {name:"Derrick Rose", team:"Pittsburgh Phantoms", pos:"PG", ovr:92, arr3:72, arm:95, pas:85, dri:93, def:72, fis:88, sal:84, cl:93},
  {name:"Chris Bosh", team:"Pittsburgh Phantoms", pos:"C", ovr:89, arr3:62, arm:90, pas:70, dri:66, def:90, fis:92, sal:78, cl:78},
  {name:"Jase Richardson", team:"Pittsburgh Phantoms", pos:"SG", ovr:89, arr3:86, arm:82, pas:78, dri:82, def:74, fis:76, sal:76, cl:78},
  {name:"Evan Mobley", team:"Pittsburgh Phantoms", pos:"PF", ovr:87, arr3:70, arm:88, pas:75, dri:69, def:79, fis:87, sal:76, cl:81},
  {name:"Darius Garland", team:"Pittsburgh Phantoms", pos:"PG", ovr:88, arr3:87, arm:78, pas:90, dri:92, def:72, fis:71, sal:87, cl:90},
  {name:"John Wall", team:"San Antonio Vultures", pos:"SG", ovr:91, arr3:85, arm:85, pas:79, dri:88, def:77, fis:82, sal:79, cl:85},
  {name:"Larry Johnson", team:"San Antonio Vultures", pos:"PF", ovr:91, arr3:75, arm:89, pas:76, dri:77, def:82, fis:92, sal:78, cl:84},
  {name:"Egor Demin", team:"San Antonio Vultures", pos:"PG", ovr:85, arr3:80, arm:72, pas:89, dri:86, def:67, fis:72, sal:72, cl:74},
  {name:"Tyler Herro", team:"San Antonio Vultures", pos:"SG", ovr:85, arr3:81, arm:81, pas:72, dri:83, def:69, fis:77, sal:70, cl:80},
  {name:"Len Bias", team:"San Francisco JoyBoys", pos:"SF", ovr:96, arr3:88, arm:86, pas:84, dri:86, def:85, fis:87, sal:84, cl:85},
  {name:"Devin Booker", team:"San Francisco JoyBoys", pos:"SG", ovr:94, arr3:87, arm:86, pas:79, dri:88, def:74, fis:80, sal:83, cl:85},
  {name:"Josh Giddey", team:"San Francisco JoyBoys", pos:"PG", ovr:87, arr3:79, arm:78, pas:90, dri:87, def:67, fis:71, sal:76, cl:78},
  {name:"Jalen Duren", team:"San Francisco JoyBoys", pos:"C", ovr:86, arr3:58, arm:93, pas:72, dri:66, def:86, fis:95, sal:80, cl:84},
  {name:"Jonathan Kuminga", team:"San Francisco JoyBoys", pos:"PF", ovr:86, arr3:74, arm:83, pas:75, dri:72, def:80, fis:86, sal:71, cl:77},
  {name:"Nikola Jokic", team:"San Jose Carpinteros", pos:"C", ovr:94, arr3:84, arm:92, pas:98, dri:83, def:80, fis:91, sal:97, cl:92},
  {name:"Jeremiah Fears", team:"San Jose Carpinteros", pos:"PG", ovr:92, arr3:82, arm:81, pas:90, dri:93, def:71, fis:74, sal:79, cl:80},
  {name:"Keegan Murray", team:"St. Louis Archers", pos:"SF", ovr:95, arr3:84, arm:91, pas:83, dri:87, def:79, fis:87, sal:80, cl:90},
  {name:"Chet Holmgren", team:"St. Louis Archers", pos:"PF", ovr:92, arr3:80, arm:87, pas:81, dri:78, def:85, fis:90, sal:78, cl:84},
  {name:"Shai Gilgeous-Alexander", team:"St. Louis Archers", pos:"PG", ovr:91, arr3:83, arm:94, pas:84, dri:93, def:82, fis:82, sal:92, cl:94},
  {name:"Donovan Clingan", team:"St. Louis Archers", pos:"C", ovr:86, arr3:59, arm:89, pas:67, dri:63, def:84, fis:90, sal:75, cl:76},
  {name:"Zaccharie Risacher", team:"Washington Peacemakers", pos:"SF", ovr:93, arr3:83, arm:84, pas:79, dri:86, def:77, fis:83, sal:78, cl:83},
  {name:"Kevin Love", team:"Washington Peacemakers", pos:"PF", ovr:92, arr3:76, arm:92, pas:79, dri:77, def:86, fis:92, sal:82, cl:84},
  {name:"Victor Oladipo", team:"Washington Peacemakers", pos:"PG", ovr:92, arr3:81, arm:83, pas:90, dri:96, def:76, fis:75, sal:83, cl:79},
  {name:"Noah Vonleh", team:"Washington Peacemakers", pos:"C", ovr:86, arr3:61, arm:85, pas:70, dri:59, def:83, fis:92, sal:75, cl:78},
  {name:"Patrick Williams", team:"Washington Peacemakers", pos:"SF", ovr:85, arr3:77, arm:78, pas:72, dri:74, def:73, fis:78, sal:75, cl:81},
];

/* ---------- Estado ---------- */
let S = {};
function newGame(){
  const seed = Date.now() % 2147483647;
  rng = mulberry32(seed);
  S = {
    screen:'home', playerName:null, playerNumber:null,
    position:null, attrs:null, draftIdx:0, draftLog:[], legendsPool:[...LEGENDS], currentLegend:null, pendingAttr:null,
    team:null, age:19, year:1, baseOVR:0, potential:null, trueOVR:0, career:[], trophies:{rings:0,mvp:0,allstar:0,dpoy:0,roy:false,scoring:0,allNBA:0,finalsMVP:0,mip:0,allFBA1:0,allFBA2:0,allFBA3:0,allDef1:0,allDef2:0,rookie1:0,sixthMan:0},
    peakOVR:0, totals:{gp:0,pts:0,reb:0,ast:0,stl:0,blk:0}, pendingFinals:null, retired:false, finalsViewIdx:0,
    popularity:50, pendingEvent:null, lastEventNote:null,
    salary:null, lastContractSeasonCount:0, contractInterval:ri(3,4), pendingContract:null
  };
}
newGame();

/* ---------- Lógica de carreira ---------- */
function baselineAttrs(){
  const a = {};
  for (const k in ATTRS) a[k] = 0;
  return a;
}
function computeOVR(attrs, position){
  const w = POSITIONS[position].weights;
  let weighted = 0;
  for (const k in w) weighted += (attrs[k]||0) * w[k];
  return clamp(Math.round(weighted), 40, 99);
}
function drawLegend(){
  if (S.legendsPool.length === 0) S.legendsPool = [...LEGENDS];
  const idx = Math.floor(rng()*S.legendsPool.length);
  return S.legendsPool.splice(idx,1)[0];
}
function tierForValue(v){
  if (v >= 90) return 'mito';
  if (v >= 82) return 'lenda';
  if (v >= 72) return 'estrela';
  return 'comum';
}
function doSteal(attrKey){
  const legend = S.currentLegend;
  const amt = legend[attrKey];
  const tier = tierForValue(amt);
  S.attrs[attrKey] = clamp(amt, 0, 99);
  const entry = {legend, attrKey, tier, amt};
  S.draftLog.push(entry);
  S.draftIdx++;
  S.currentLegend = S.draftIdx < 8 ? drawLegend() : null;
  return entry;
}
function posUsage(position){
  return {PG:0.92, SG:1.05, SF:1.0, PF:0.88, C:0.8}[position];
}
function rollPotential(ovr){
  const r = rng();
  let ceiling;
  if (r < 0.50) ceiling = ri(1,7);        // maioria: pouco espaço pra crescer
  else if (r < 0.82) ceiling = ri(6,13);   // bom jogador, ainda vai evoluir
  else if (r < 0.96) ceiling = ri(11,19);  // futuro All-Star
  else ceiling = ri(16,26);                // talento raro, geracional
  return clamp(ovr + ceiling, ovr, 97);
}
function developPlayer(age){
  const gap = S.potential - S.trueOVR;
  let delta = 0;
  let narrative = null;
  if (age <= 23){
    delta = ri(2,6) + Math.round(gap*0.15);
    if (rng() < 0.15){ delta += ri(3,6); narrative = 'breakout'; }
  } else if (age <= 27){
    delta = ri(0,4) + Math.round(gap*0.10);
    if (rng() < 0.10 && gap > 5){ delta += ri(2,5); narrative = 'breakout'; }
  } else if (age <= 30){
    delta = rng() < 0.5 ? ri(-1,2) : ri(-2,1);
  } else if (age <= 33){
    delta = ri(-4,-1);
    if (delta <= -3) narrative = 'decline';
  } else {
    delta = ri(-7,-2);
    narrative = 'decline';
  }
  S.trueOVR = clamp(S.trueOVR + delta, 40, S.potential);
  return {delta, narrative};
}
function simulateSeries(winProb, bestOf){
  bestOf = bestOf || 7;
  const need = Math.ceil(bestOf/2);
  let wins=0, losses=0; const games=[];
  while (wins<need && losses<need){
    const win = rng() < clamp(winProb, 0.05, 0.95);
    const myScore = ri(92,124);
    const oppScore = win ? myScore - ri(2,14) : myScore + ri(2,14);
    if (win) wins++; else losses++;
    games.push({win, myScore, oppScore});
  }
  return {wins, losses, games, won: wins>=need};
}
function pickOpponent(exclude){
  let t;
  do { t = pick(TEAMS); } while (t[0]===exclude);
  return t[0];
}
function simulateSeason(){
  const age = S.age;
  const dev = developPlayer(age);
  let effOVR = clamp(Math.round(S.trueOVR + ri(-3,3)), 35, Math.min(99, S.potential+2));
  S.peakOVR = Math.max(S.peakOVR, effOVR);

  const injuryChance = 0.06 + Math.max(0, age-30)*0.015;
  let missed = 0;
  if (rng() < injuryChance){ missed = ri(6,34); effOVR = clamp(effOVR-4,35,99); }
  const gp = clamp(82-missed, 10, 82);

  const a = S.attrs;
  const usage = posUsage(S.position);
  const scoreAttr = 0.55*a.arr3 + 0.30*a.arm + 0.15*a.dri;
  // Experiência: calouros ainda não são protagonistas do ataque/esquema.
  // A produção sobe gradualmente até atingir o pico por volta da 5ª temporada.
  const seasonsPlayed = S.career.length + 1;
  const experience = clamp(0.5 + (seasonsPlayed-1)*0.125, 0.5, 1);
  let ppg = clamp(+(4 + (effOVR-50)*0.42 + (scoreAttr-50)*0.28 + (usage-1)*9 + ri(-15,15)/10).toFixed(1), 2, 38);
  const posRebBonus = {PG:-0.5,SG:0,SF:1,PF:3,C:5}[S.position];
  let rpg = clamp(+(1 + a.sal*0.07 + a.fis*0.06 + posRebBonus + ri(-8,8)/10).toFixed(1), 1, 16);
  const posAstBonus = {PG:3.5,SG:0.7,SF:0,PF:-1,C:-1.3}[S.position];
  let apg = clamp(+(0.5 + a.pas*0.11 + posAstBonus + ri(-8,8)/10).toFixed(1), 0.5, 13);
  const posStlBonus = {PG:1.0,SG:0.7,SF:0.5,PF:0.3,C:0.2}[S.position];
  let spg = clamp(+(0.3 + a.def*0.018 + posStlBonus + ri(-5,5)/10).toFixed(1), 0.1, 4);
  const posBlkBonus = {PG:-0.3,SG:-0.2,SF:0.1,PF:0.8,C:1.4}[S.position];
  let bpg = clamp(+(0.1 + a.def*0.015 + a.fis*0.005 + posBlkBonus + ri(-5,5)/10).toFixed(1), 0, 4.5);
  ppg = clamp(+(ppg*experience).toFixed(1), 1.5, 38);
  rpg = clamp(+(rpg*experience).toFixed(1), 0.5, 16);
  apg = clamp(+(apg*experience).toFixed(1), 0.3, 13);
  spg = clamp(+(spg*experience).toFixed(1), 0.1, 4);
  bpg = clamp(+(bpg*experience).toFixed(1), 0, 4.5);

  const per = clamp(+((effOVR/4.2) + ri(-15,15)/10).toFixed(1), 8, 34);

  const teamStrength = ri(35,95);
  const combined = clamp(teamStrength + effOVR*0.35, 0, 130);
  let standing = clamp(Math.round(16 - (combined/130)*15 + ri(-1,1)), 1, 15);
  const playoffs = standing <= 10;
  const topSeed = standing <= 8;

  const awards = [];
  const allStarChance = effOVR>=88?0.9:effOVR>=80?0.55:effOVR>=75?0.22:effOVR>=68?0.06:0.01;
  const isAllStar = rng() < allStarChance;
  if (isAllStar){ awards.push('All-Star'); S.trophies.allstar++; }

  if (effOVR>=93 && rng() < 0.55){
    awards.push('All-FBA First Team'); S.trophies.allFBA1++; S.trophies.allNBA++;
  } else if (effOVR>=88 && rng() < 0.48){
    awards.push('All-FBA First Team'); S.trophies.allFBA1++; S.trophies.allNBA++;
  } else if (effOVR>=84 && rng() < 0.42){
    awards.push('All-FBA Second Team'); S.trophies.allFBA2++; S.trophies.allNBA++;
  } else if (effOVR>=80 && rng() < 0.34){
    awards.push('All-FBA Third Team'); S.trophies.allFBA3++; S.trophies.allNBA++;
  }

  if (a.def>=92 && rng() < 0.45){
    awards.push('All-Defensive First Team'); S.trophies.allDef1++;
  } else if (a.def>=86 && rng() < 0.38){
    awards.push('All-Defensive Second Team'); S.trophies.allDef2++;
  }

  if (effOVR>=88 && topSeed && rng() < 0.40){
    awards.push('MVP'); S.trophies.mvp++;
  }
  if (a.def>=88 && effOVR>=78 && rng() < 0.16){
    awards.push('DPOY'); S.trophies.dpoy++;
  }
  if (S.year===1 && effOVR>=70 && rng() < 0.38){
    awards.push('Rookie First Team'); S.trophies.rookie1 = true;
  }
  if (ppg>=26.5 && rng() < 0.18){
    awards.push('Cestinha'); S.trophies.scoring++;
  }
  if (dev.narrative==='breakout' && dev.delta>=6 && rng() < 0.4){
    awards.push('Jogador Mais Melhorado'); S.trophies.mip++;
  }
  if (effOVR<=86 && ppg>=15 && ppg<=28 && rng() < 0.22){
    awards.push('Sixth Man'); S.trophies.sixthMan++;
  }

  let champion=false, finalsMVP=false, finalsLog=null, finalsOpponent=null;
  const playoffRun = [];
  if (playoffs){
    const winProbBase = clamp(0.30 + (combined-60)*0.006, 0.15, 0.82);
    let eliminated = false;
    if (standing >= 7){
      const opp = pickOpponent(S.team.name);
      const win = rng() < clamp(winProbBase, 0.2, 0.8);
      playoffRun.push({round:'Play-in', opponent:opp, result: win?'venceu':'perdeu', score:null});
      if (!win) eliminated = true;
    }
    const rounds = ['Primeira rodada','Semifinal de conferência','Final de conferência','Finais da NBA'];
    let roundIdx = 0;
    while (!eliminated && roundIdx < rounds.length){
      const opp = pickOpponent(S.team.name);
      const roundBoost = roundIdx*0.025;
      const series = simulateSeries(clamp(winProbBase - roundBoost, 0.1, 0.85));
      const roundName = rounds[roundIdx];
      playoffRun.push({round:roundName, opponent:opp, result: series.won?'venceu':'perdeu', score:`${series.wins}-${series.losses}`});
      if (roundName==='Finais da NBA'){
        finalsLog = {wins:series.wins, losses:series.losses, games:series.games, champion:series.won};
        finalsOpponent = opp;
        if (series.won){
          champion = true;
          S.trophies.rings++;
          if (rng() < (effOVR>=90?0.5:0.22)){ finalsMVP=true; S.trophies.finalsMVP++; awards.push('MVP das Finais'); }
          awards.push('Campeão da NBA');
        }
      }
      if (!series.won) eliminated = true;
      roundIdx++;
    }
  }

  S.totals.gp += gp; S.totals.pts += Math.round(ppg*gp); S.totals.reb += Math.round(rpg*gp); S.totals.ast += Math.round(apg*gp);
  S.totals.stl += Math.round(spg*gp); S.totals.blk += Math.round(bpg*gp);

  let popDelta = ri(-2,2);
  if (champion) popDelta += 14;
  if (finalsMVP) popDelta += 6;
  if (awards.includes('MVP')) popDelta += 9;
  if (isAllStar) popDelta += 3;
  if (awards.includes('Rookie First Team')) popDelta += 5;
  if (awards.includes('Cestinha')) popDelta += 3;
  if (awards.includes('DPOY')) popDelta += 3;
  if (awards.includes('Jogador Mais Melhorado')) popDelta += 2;
  if (awards.includes('All-FBA First Team')) popDelta += 4;
  if (awards.includes('All-FBA Second Team')) popDelta += 3;
  if (awards.includes('All-FBA Third Team')) popDelta += 2;
  if (awards.includes('All-Defensive First Team')) popDelta += 2;
  if (awards.includes('All-Defensive Second Team')) popDelta += 1;
  if (awards.includes('Sixth Man')) popDelta += 2;
  if (missed >= 20) popDelta -= 3; else if (missed >= 8) popDelta -= 1;
  if (!playoffs) popDelta -= 1;
  S.popularity = clamp((S.popularity==null?50:S.popularity) + popDelta, 0, 100);

  const season = {
    age, year:S.year, team:S.team, effOVR, gp, ppg, rpg, apg, spg, bpg, per, standing, playoffs, champion, finalsMVP, awards,
    finalsLog, finalsOpponent, playoffRun, dev, missed, popDelta
  };
  S.career.push(season);
  S.year++;
  return season;
}
function retirementCheck(){
  if (S.age >= 41) return true;
  if (S.age >= 34 && rng() < (S.age-33)*0.09) return true;
  return false;
}
/* ---------- Contratos ---------- */
function salaryForOVR(ovr, pop){
  const base = 1 + Math.pow(Math.max(ovr-40,0)/59, 1.8) * 48;
  const popBonus = ((pop==null?50:pop) - 50) * 0.06;
  return Math.max(1, Math.round((base + popBonus) * 10) / 10);
}
function contractDue(){
  if (S.age >= 40) return false;
  return (S.career.length - S.lastContractSeasonCount) >= S.contractInterval;
}
function buildContractOffer(s){
  const ovr = s.effOVR;
  const pop = S.popularity==null?50:S.popularity;
  const target = salaryForOVR(ovr, pop);
  const homeSalary = Math.round(target * (0.92 + rng()*0.16) * 10) / 10;
  const homeYears = ri(2,5);
  const numSuitors = ri(2,3);
  const usedTeams = new Set([S.team.name]);
  const suitors = [];
  let guard = 0;
  while (suitors.length < numSuitors && guard < 40){
    guard++;
    const t = pick(TEAMS);
    if (usedTeams.has(t[0])) continue;
    usedTeams.add(t[0]);
    const variance = 0.88 + rng()*0.34;
    suitors.push({
      name:t[0], c1:t[1], c2:t[2],
      salary: Math.round(target * variance * 10) / 10,
      years: ri(2,5)
    });
  }
  return {homeSalary, homeYears, suitors};
}
/* ---------- Eventos de bastidores ---------- */
const EVENTS = [
  {
    id:'injury',
    condition:(s)=> s.missed >= 12,
    build:(s)=>({
      title:'Lesão tira você de combate',
      text:`Você desfalcou o ${S.team.name} por ${s.missed} jogos nesta temporada. A comissão médica está dividida sobre a melhor forma de te trazer de volta.`,
      options:[
        {label:'Antecipar a volta e jogar no sacrifício', effect:()=>{
          S.trueOVR = clamp(S.trueOVR - ri(2,4), 40, S.potential);
          S.popularity = clamp(S.popularity + ri(5,9), 0, 100);
          return 'Você voltou antes da hora. Os fãs amaram a raça — mas o corpo cobrou o preço, com leve perda de nível.';
        }},
        {label:'Seguir o protocolo médico à risca', effect:()=>{
          S.popularity = clamp(S.popularity - ri(1,3), 0, 100);
          S.potential = clamp(S.potential + ri(0,1), 40, 99);
          return 'Recuperação completa. Alguns torcedores acharam que você foi "mole", mas o seu corpo agradece a longo prazo.';
        }}
      ]
    })
  },
  {
    id:'minor_injury',
    condition:(s)=> s.missed >= 4 && s.missed < 12,
    build:(s)=>({
      title:'Torção chata no meio da temporada',
      text:`Você perdeu alguns jogos com uma lesão menor. Não foi o fim do mundo, mas mexeu no ritmo da carreira e nos treinos.`,
      options:[
        {label:'Voltar cedo demais', effect:()=>{
          S.trueOVR = clamp(S.trueOVR - ri(1,3), 40, S.potential);
          S.popularity = clamp(S.popularity + ri(1,4), 0, 100);
          return 'Você forçou a volta e a torcida gostou da coragem, mas o corpo sentiu.';
        }},
        {label:'Segurar mais uma semana', effect:()=>{
          S.potential = clamp(S.potential + ri(0,2), 40, 99);
          return 'Paciencia no banco. Você perdeu uma manchete, mas evitou virar problema crônico.';
        }}
      ]
    })
  },
  {
    id:'night_out',
    condition:(s)=> s.age >= 21,
    build:()=>({
      title:'Noite de balada com o time',
      text:'Colegas chamaram você para sair na folga. A noite parece inocente, mas amanhã tem treino cedo.',
      options:[
        {label:'Ir e virar notícia', effect:()=>{
          S.popularity = clamp(S.popularity + ri(5,10), 0, 100);
          S.trueOVR = clamp(S.trueOVR - ri(1,3), 40, S.potential);
          return 'As fotos circularam rápido. Você ganhou fama de descontraído, mas acordou pesado.';
        }},
        {label:'Sair de fininho e dormir cedo', effect:()=>{
          S.potential = clamp(S.potential + ri(1,2), 40, 99);
          return 'Você ficou fora das manchetes e descansou como profissional sério.';
        }}
      ]
    })
  },
  {
    id:'curfew',
    condition:(s)=> s.age >= 21,
    build:()=>({
      title:'Quebra de curfew',
      text:'Um assistente técnico afirma que você chegou tarde demais em um dia importante de treino. A diretoria quer explicações.',
      options:[
        {label:'Assumir a bronca publicamente', effect:()=>{
          S.popularity = clamp(S.popularity - ri(1,3), 0, 100);
          S.trueOVR = clamp(S.trueOVR - ri(0,2), 40, S.potential);
          return 'Você pediu desculpas. O vestiário aceitou, mas a comissão técnica ficou de olho.';
        }},
        {label:'Negar tudo e seguir o jogo', effect:()=>{
          S.popularity = clamp(S.popularity + ri(2,5), 0, 100);
          return 'Você segurou a narrativa. A internet adorou o caos.';
        }}
      ]
    })
  },
  {
    id:'fatigue',
    condition:(s)=> s.ppg >= 24 || s.rpg >= 10 || s.apg >= 8,
    build:(s)=>({
      title:'Sinais de fadiga',
      text:`Você está carregando muito da produção ofensiva do ${S.team.name}. A comissão te sugere pegar leve em algumas semanas.`,
      options:[
        {label:'Ignorar e continuar forçando', effect:()=>{
          S.trueOVR = clamp(S.trueOVR - ri(1,2), 40, S.potential);
          S.popularity = clamp(S.popularity + ri(2,4), 0, 100);
          return 'Você quis provar resistência. O ritmo caiu um pouco, mas a imagem de guerreiro cresceu.';
        }},
        {label:'Reduzir a carga e descansar', effect:()=>{
          S.potential = clamp(S.potential + ri(0,2), 40, 99);
          return 'Menos desgaste, mais longevidade. O estilo foi menos vistoso, mas muito mais sustentável.';
        }}
      ]
    })
  },
  {
    id:'social_media',
    condition:()=> true,
    build:()=>({
      title:'Treta nas redes',
      text:'Uma postagem sua viralizou e a internet quer transformar tudo em polêmica, de hashtag a meme de grupo.',
      options:[
        {label:'Entrar na discussão', effect:()=>{
          S.popularity = clamp(S.popularity + ri(4,9), 0, 100);
          S.trueOVR = clamp(S.trueOVR - ri(0,1), 40, S.potential);
          return 'Você entrou no barro e saiu mais famoso — mesmo sem ganhar nada em quadra.';
        }},
        {label:'Apagar e ficar quieto', effect:()=>{
          S.popularity = clamp(S.popularity - ri(0,2), 0, 100);
          return 'Você sumiu do feed. Menos barulho, menos problema.';
        }}
      ]
    })
  },
  {
    id:'teammate_fight',
    condition:()=> true,
    build:()=>({
      title:'Climão no vestiário',
      text:'Surgiu um desentendimento sério com um companheiro de time sobre quem deveria arremessar nos momentos decisivos dos jogos.',
      options:[
        {label:'Resolver a conversa em particular', effect:()=>{
          S.popularity = clamp(S.popularity + ri(1,3), 0, 100);
          return 'Vocês se acertaram longe das câmeras. A imprensa nem ficou sabendo da treta.';
        }},
        {label:'Expor a treta nas redes sociais', effect:()=>{
          S.popularity = clamp(S.popularity + ri(6,10), 0, 100);
          S.trueOVR = clamp(S.trueOVR - ri(0,2), 40, S.potential);
          return 'O vídeo viralizou. Sua popularidade disparou, mas o vestiário ficou mais pesado.';
        }}
      ]
    })
  },
  {
    id:'trade_rumor',
    condition:(s)=> s.standing >= 9,
    build:()=>{
      const targetName = pickOpponent(S.team.name);
      return {
        title:'Seu nome pipoca no mercado de trocas',
        text:`Com o ${S.team.name} fora da briga por vaga nos playoffs, rumores de troca tomaram conta dos bastidores.`,
        options:[
          {label:'Pedir publicamente para ficar', effect:()=>{
            S.popularity = clamp(S.popularity + ri(4,7), 0, 100);
            return `Você reafirmou seu compromisso com o ${S.team.name}. A torcida abraçou a lealdade.`;
          }},
          {label:'Pedir a troca para um contendor', effect:()=>{
            const oldName = S.team.name;
            const t = TEAMS.find(t=>t[0]===targetName);
            if (t) S.team = {name:t[0], c1:t[1], c2:t[2]};
            S.popularity = clamp(S.popularity - ri(2,5), 0, 100);
            return `Troca concluída: você deixou o ${oldName} rumo ao ${S.team.name}. Página virada.`;
          }}
        ]
      };
    }
  },
  {
    id:'extension',
    condition:(s)=> s.age >= 23,
    build:()=>({
      title:'Proposta de extensão contratual',
      text:`A diretoria do ${S.team.name} sentou com seu estafe e colocou na mesa uma extensão de contrato bem gorda para te garantir por mais temporadas.`,
      options:[
        {label:'Assinar e garantir o dinheiro', effect:()=>{
          S.popularity = clamp(S.popularity + ri(3,6), 0, 100);
          return 'Contrato assinado. Segurança financeira e carinho da torcida por escolher ficar.';
        }},
        {label:'Recusar e apostar em si mesmo', effect:()=>{
          S.potential = clamp(S.potential + ri(1,3), 40, 99);
          S.popularity = clamp(S.popularity + ri(-2,4), 0, 100);
          return 'Você recusou a oferta. A motivação de provar seu valor pode elevar seu teto — mas a torcida ficou dividida.';
        }}
      ]
    })
  },
  {
    id:'record',
    condition:(s)=> s.ppg>=27 || s.rpg>=12 || s.apg>=9 || s.per>=27,
    build:(s)=>{
      const statText = s.ppg>=27 ? `${s.ppg} pontos por jogo`
        : s.rpg>=12 ? `${s.rpg} rebotes por jogo`
        : s.apg>=9 ? `${s.apg} assistências por jogo`
        : `PER de ${s.per}`;
      return {
        title:'Temporada de números impressionantes',
        text:`Você fechou a temporada com uma média de ${statText}, um dos grandes destaques da liga.`,
        options:[
          {label:'Comemorar e aparecer bastante nas redes', effect:()=>{
            S.popularity = clamp(S.popularity + ri(8,12), 0, 100);
            return 'Os números viralizaram. Sua popularidade deu um salto e as marcas ligaram no dia seguinte.';
          }},
          {label:'Manter a cabeça no próximo desafio', effect:()=>{
            S.popularity = clamp(S.popularity + ri(2,4), 0, 100);
            S.potential = clamp(S.potential + ri(0,1), 40, 99);
            return 'Discrição total. Os veteranos do vestiário notaram a postura e passaram a te respeitar mais.';
          }}
        ]
      };
    }
  }
];
function maybeTriggerEvent(s){

  const candidates = EVENTS.filter(ev=> ev.condition(s));
  if (candidates.length===0) return false;
  if (rng() >= 0.62) return false;
  const chosen = pick(candidates);
  S.pendingEvent = chosen.build(s);
  return true;
}

function careerLegacyScore(){
  const t = S.trophies || {};
  const seasons = S.career || [];
  const played = seasons.length;
  const eliteSeasons = seasons.filter(s=> (s.effOVR||0) >= 90).length;
  const primeSeasons = seasons.filter(s=> (s.effOVR||0) >= 85).length;
  const awardSeasons = seasons.filter(s=> (s.awards||[]).some(a=> /MVP|Campeão|Finais|All-FBA First Team|All-Defensive First Team|DPOY/.test(a))).length;

  const avgPts = S.totals && S.totals.gp ? S.totals.pts / S.totals.gp : 0;
  const avgReb = S.totals && S.totals.gp ? S.totals.reb / S.totals.gp : 0;
  const avgAst = S.totals && S.totals.gp ? S.totals.ast / S.totals.gp : 0;
  const efficiencyBonus = clamp(Math.round(avgPts*2 + avgReb*1.5 + avgAst*1.5), 0, 55);

  let score = 0;
  score += played * 8;
  score += Math.min(eliteSeasons, 8) * 18;
  score += Math.min(primeSeasons, 14) * 6;
  score += Math.min(awardSeasons, 14) * 4;
  score += (t.rings||0) * 58;
  score += (t.finalsMVP||0) * 32;
  score += (t.mvp||0) * 82;
  score += (t.allstar||0) * 6;
  score += (t.allNBA||0) * 4;
  score += (t.allFBA1||0) * 14;
  score += (t.allFBA2||0) * 9;
  score += (t.allFBA3||0) * 6;
  score += (t.allDef1||0) * 12;
  score += (t.allDef2||0) * 8;
  score += (t.dpoy||0) * 20;
  score += (t.mip||0) * 10;
  score += (t.sixthMan||0) * 7;
  score += (t.rookie1 ? 8 : 0);
  score += (t.roy ? 10 : 0);
  score += (t.scoring||0) * 6;
  score += Math.max(0, (S.peakOVR||0) - 80) * 5;
  score += Math.max(0, (S.popularity||50) - 50) * 0.7;
  score += efficiencyBonus;
  if ((S.age||0) >= 34) score -= ((S.age||0) - 33) * 3;
  return Math.round(score);
}
function finalTierIndex(){
  const score = careerLegacyScore();
  const t = S.trophies || {};
  let idx = 0;
  const thresholds = [0,120,180,250,340,440,560,700,820,900,970];
  for (let i=0;i<thresholds.length;i++) if (score>=thresholds[i]) idx=i;

  // Gates para os dois níveis mais altos: não dá para cair ali só por volume.
  if (idx >= 10) {
    if (!(((t.rings||0) >= 5) || ((t.mvp||0) >= 4)) || (t.allFBA1||0) < 8 || (S.peakOVR||0) < 95){
      idx = 9;
    }
  }
  if (idx >= 9) {
    if (!(((t.rings||0) >= 3) || ((t.mvp||0) >= 2)) || (t.allFBA1||0) < 5){
      idx = 8;
    }
  }
  if (idx >= 8 && (t.allFBA1||0) < 3){
    idx = 7;
  }
  if (idx >= 6 && (t.allstar||0) === 0){
    idx = Math.min(idx, 5);
  }
  return clamp(idx,0,10);
}


/* ---------- Render ---------- */
const app = document.getElementById('app');
function render(){
  let html = '';
  html += `<div class="topbar"><div class="logo">THE <span>GOAT</span></div><div class="seedtag">${S.playerName?`Nº${S.playerNumber} · ${S.playerName}`:''}</div></div>`;
  if (S.screen==='home') html += renderHome();
  else if (S.screen==='position') html += renderPosition();
  else if (S.screen==='draft') html += renderDraft();
  else if (S.screen==='draftresult') html += renderDraftResult();
  else if (S.screen==='teamreveal') html += renderTeamReveal();
  else if (S.screen==='career') html += renderCareer();
  else if (S.screen==='event') html += renderEvent();
  else if (S.screen==='contract') html += renderContract();
  else if (S.screen==='finals') html += renderFinals();
  else if (S.screen==='end') html += renderEnd();
  app.innerHTML = html;
}
function renderHome(){
  return `<div class="screen">
    <div class="eyebrow">Simulador de carreira</div>
    <h1 class="hero-title">VOCÊ É O<br><em>PRÓXIMO GOAT?</em></h1>
    <p class="sub">Roube atributos das lendas da NBA no draft, viva uma carreira inteira temporada a temporada, e descubra em qual degrau da escada você fica entre os maiores de todos os tempos.</p>
    <div class="card">
      <div class="eyebrow" style="margin-bottom:8px;">Seu nome</div>
      <input type="text" id="nameInput" placeholder="ex: Marcus Silva" maxlength="24">
      <div class="eyebrow" style="margin-bottom:8px;">Sua camisa</div>
      <input type="text" id="numberInput" placeholder="número da camisa (0-99)" maxlength="2" inputmode="numeric">
      <button class="btn" data-action="start">COMEÇAR ▸</button>
    </div>
    <p class="fair">Roda 100% no seu navegador, sem servidor e sem cadastro.<br><br>Sem afiliação com a NBA ou qualquer jogador. Nomes usados apenas para identificação.</p>
  </div>`;
}
function renderPosition(){
  let cards = '';
  for (const k in POSITIONS){
    const p = POSITIONS[k];
    cards += `<div class="pos-card" data-action="pickpos" data-pos="${k}">
      <div class="abbr">${p.abbr}</div><div class="name">${p.name}</div><div class="desc">${p.desc}</div>
    </div>`;
  }
  return `<div class="screen">
    <div class="eyebrow">Passo 1 de 2</div>
    <h2 style="font-size:28px;margin:8px 0 14px 0;">ESCOLHA SUA POSIÇÃO</h2>
    <div class="pos-grid">${cards}</div>
  </div>`;
}
function renderDraft(){
  const last = S.draftLog[S.draftLog.length-1];
  let bars = '';
  for (const k in ATTRS){
    const v = S.attrs[k]||0;
    bars += `<div class="bar-row"><div class="bar-label">${ATTRS[k]}</div><div class="bar-track"><div class="bar-fill" style="width:${v}%"></div></div><div class="bar-val">${v}</div></div>`;
  }
  const stealBlock = last ? `
    <div class="steal-card">
      <div class="steal-legend">Você roubou de</div>
      <div class="steal-name tier-${last.tier}">${last.legend.name}</div>
      <div class="steal-attr">${last.legend.pos} · ${last.legend.team}</div>
      <div class="steal-attr">${ATTRS[last.attrKey]}</div>
      <div class="steal-amt">${last.amt}</div>
    </div>` : `<div class="steal-card"><div class="steal-attr" style="margin-top:20px;">Escolha de quem e o que roubar.</div></div>`;
  const done = S.draftIdx >= 8;

  let pickerBlock = '';
  if (!done){
    const usedAttrs = new Set(S.draftLog.map(e=>e.attrKey));
    let attrBtns = '';
    for (const k in ATTRS){
      const used = usedAttrs.has(k);
      attrBtns += `<div class="attr-pick ${S.pendingAttr===k?'active':''} ${used?'used':''}" ${used?'':`data-action="chooseattr" data-attr="${k}"`}>${ATTRS[k]}${used?' ✓':''}</div>`;
    }
    const canSteal = !!S.pendingAttr;
    pickerBlock = `
      <div class="card">
        <div class="eyebrow" style="margin-bottom:8px;">Você vai roubar de</div>
        <div class="current-legend-name">${S.currentLegend.name}</div>
        <div class="steal-attr" style="margin-bottom:2px;">${S.currentLegend.pos} · ${S.currentLegend.team} · OVR ${S.currentLegend.ovr}</div>
        <div class="eyebrow" style="margin:14px 0 8px 0;">Qual atributo? (só pode escolher cada um uma vez)</div>
        <div class="attr-grid">${attrBtns}</div>
        <button class="btn" style="margin-top:14px;" data-action="steal" ${canSteal?'':'disabled'}>ROUBAR ▸</button>
      </div>`;
  }

  return `<div class="screen">
    <div class="eyebrow">O draft — escolha e roube</div>
    <h2 style="font-size:26px;margin:8px 0 4px 0;">RODADA ${Math.min(S.draftIdx+1,8)} DE 8</h2>
    <div class="card">${stealBlock}</div>
    <div class="card">
      <div class="eyebrow" style="margin-bottom:10px;">Seus atributos</div>
      ${bars}
    </div>
    ${pickerBlock}
    ${done ? `<button class="btn gold" data-action="finishdraft">VER MEU JOGADOR ▸</button>` : ''}
  </div>`;
}
function renderDraftResult(){
  const ovr = computeOVR(S.attrs, S.position);
  S.baseOVR = ovr;
  if (S.potential === null){
    S.potential = rollPotential(ovr);
    S.trueOVR = ovr;
  }
  let bars = '';
  for (const k in ATTRS){
    const v = S.attrs[k]||0;
    bars += `<div class="bar-row"><div class="bar-label">${ATTRS[k]}</div><div class="bar-track"><div class="bar-fill" style="width:${v}%"></div></div><div class="bar-val">${v}</div></div>`;
  }
  return `<div class="screen">
    <div class="eyebrow">Perfil final · Nº${S.playerNumber} ${S.playerName}</div>
    <div class="ovr-badge">
      <div class="ovr-num">${ovr}</div>
      <div class="ovr-meta"><div class="pos">${POSITIONS[S.position].abbr} · ${POSITIONS[S.position].name}</div><div class="lbl">OVERALL DE ENTRADA NA LIGA</div></div>
    </div>
    <div class="card">${bars}</div>
    <button class="btn" data-action="gotolottery">LOTERIA DO DRAFT ▸</button>
  </div>`;
}
function renderTeamReveal(){
  if (!S.team){
    const t = pick(TEAMS);
    S.team = {name:t[0], c1:t[1], c2:t[2]};
  }
  return `<div class="screen">
    <div class="eyebrow">Você foi selecionado por</div>
    <div class="team-reveal">
      ${teamSwatchHtml(S.team.name, S.team.c1, S.team.c2)}
      <div class="team-name">${S.team.name}</div>
    </div>
    <p class="sub" style="text-align:center;margin-top:14px;">Sua carreira começa aos 19 anos. Uma temporada de cada vez até a aposentadoria.</p>
    <button class="btn" data-action="startcareer">COMEÇAR CARREIRA ▸</button>
  </div>`;
}
function devText(dev, age){
  if (dev.narrative==='breakout') return `Salto de nível: seu jogo evoluiu muito além do esperado para o seu potencial nesta temporada (Overall ${dev.delta>=0?'+':''}${dev.delta}).`;
  if (dev.narrative==='decline') return `A idade cobrou seu preço fisicamente nesta temporada (Overall ${dev.delta}).`;
  if (dev.delta > 0) return `Evolução natural de jogo (Overall +${dev.delta}).`;
  if (dev.delta < 0) return `Leve queda de rendimento (Overall ${dev.delta}).`;
  return 'Overall estável nesta temporada.';
}
function standingText(standing, teamName){
  if (standing<=2) return `O ${teamName} dominou a temporada regular, terminando na ${standing}ª colocação da conferência.`;
  if (standing<=6) return `O ${teamName} fez uma campanha sólida, na ${standing}ª colocação da conferência.`;
  if (standing<=10) return `O ${teamName} brigou até o fim, garantindo a ${standing}ª colocação da conferência.`;
  return `O ${teamName} decepcionou e ficou de fora da briga pelos playoffs, na ${standing}ª colocação da conferência.`;
}
function playoffRunHtml(run){
  if (!run || !run.length) return '';
  const rows = run.map(r=>`<div class="bar-row" style="justify-content:space-between;">
      <div class="bar-label" style="width:auto;color:${r.result==='venceu'?'var(--paper)':'var(--muted)'};">${r.round}</div>
      <div style="font-family:'IBM Plex Mono',monospace;font-size:11.5px;color:${r.result==='venceu'?'var(--accent2)':'var(--muted)'};">${r.result==='venceu'?'✓ venceu':'✕ perdeu'} · ${r.opponent}${r.score?` (${r.score})`:''}</div>
    </div>`).join('');
  return `<div style="margin-top:10px;">${rows}</div>`;
}

function awardBadgeClass(a){
  if (/Campeão|MVP das Finais|MVP|DPOY/.test(a)) return 'ring';
  if (/All-FBA|All-Defensive|All-Star|Rookie|Sixth Man|Melhorado|Cestinha/.test(a)) return '';
  return '';
}
function renderAwardPills(awards){
  if (!awards || !awards.length) return '<p class="note" style="margin-top:10px;">Nenhum prêmio individual nesta temporada.</p>';
  return `<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px;">${awards.map(a=>`<span class="badge ${awardBadgeClass(a)}">${a}</span>`).join('')}</div>`;
}
function renderEvent(){
  const ev = S.pendingEvent;
  if (!ev) { S.screen='career'; return renderCareer(); }
  const optionsHtml = ev.options.map((o,i)=>
    `<button class="btn ${i===0?'gold':'secondary'}" data-action="eventchoice" data-idx="${i}" style="margin-bottom:10px;">${o.label}</button>`
  ).join('');
  return `<div class="screen">
    <div class="eyebrow">Bastidores da carreira</div>
    <h2 style="font-size:26px;margin:8px 0 12px 0;">${ev.title}</h2>
    <p class="sub">${ev.text}</p>
    <div style="margin-top:18px;">${optionsHtml}</div>
  </div>`;
}
function renderContract(){
  const off = S.pendingContract;
  const s = S.career[S.career.length-1];
  const suitorsHtml = off.suitors.map((t,i)=>`
    <div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;">
      <div style="display:flex;align-items:center;gap:10px;">
        ${teamSwatchHtml(t.name, t.c1, t.c2, 'width:44px;height:44px;font-size:13px;margin:0;')}
        <div>
          <div style="font-family:'Bebas Neue',sans-serif;font-size:17px;color:var(--paper);">${t.name}</div>
          <div class="steal-attr" style="margin:0;font-size:13px;">${t.years} anos · $${t.salary}M/temporada</div>
        </div>
      </div>
      <button class="btn small gold" data-action="signteam" data-idx="${i}">ASSINAR ▸</button>
    </div>`).join('');
  return `<div class="screen">
    <div class="eyebrow">Temporada ${s.year} · Mercado de contratos</div>
    <h2 style="font-size:26px;margin:8px 0 10px 0;">HORA DE NEGOCIAR</h2>
    <p class="sub">Seu vínculo com o ${S.team.name} está no fim. Renove, ouça outras propostas ou force sua saída.</p>
    <div class="card">
      <div class="eyebrow" style="margin-bottom:8px;">Proposta do ${S.team.name}</div>
      <div style="font-family:'Bebas Neue',sans-serif;font-size:22px;color:var(--paper);">${off.homeYears} anos · $${off.homeSalary}M/temporada</div>
      <button class="btn" style="margin-top:12px;" data-action="renewcontract">RENOVAR COM O ${S.team.name.toUpperCase()} ▸</button>
    </div>
    <div class="eyebrow" style="margin:18px 0 10px 0;">Outros times de olho em você</div>
    ${suitorsHtml}
    <button class="btn secondary" style="margin-top:6px;" data-action="requesttrade">PEDIR TROCA PARA UM CONTENDOR ▸</button>
  </div>`;
}
function popularityLabel(p){
  if (p>=85) return 'Ícone global';
  if (p>=70) return 'Queridinho da torcida';
  if (p>=50) return 'Bem visto pelos fãs';
  if (p>=30) return 'Discreto';
  return 'Mal visto pela torcida';
}
function renderCareer(){
  const s = S.career[S.career.length-1];
  if (!s){
    return `<div class="screen"><p class="sub">Carregando...</p></div>`;
  }
  const secondary = POSITIONS[S.position].abbr==='C'||POSITIONS[S.position].abbr==='PF' ? 'RPG' : 'APG';
  let awardsHtml = renderAwardPills(s.awards);
  const canRetire = S.age >= 30;
  const forceRetire = S.age >= 41;
  const pop = S.popularity==null?50:S.popularity;
  const noteHtml = S.lastEventNote
    ? `<div class="card"><div class="eyebrow" style="margin-bottom:8px;">O que aconteceu</div><p class="note" style="margin-top:0;">${S.lastEventNote}</p></div>`
    : '';
  S.lastEventNote = null;
  return `<div class="screen">
    <div class="season-head">
      <div><div class="eyebrow">${S.team.name} · Nº${S.playerNumber} ${S.playerName}</div><div class="season-age">${s.age} ANOS</div></div>
      <div class="season-year">TEMPORADA ${s.year}<br>$${S.salary||0}M/ano</div>
    </div>
    ${noteHtml}
    <div class="card">
      <div class="stat-grid">
        <div class="stat-box"><div class="v">${s.gp}</div><div class="k">Jogos</div></div>
        <div class="stat-box"><div class="v">${s.ppg}</div><div class="k">PPG</div></div>
        <div class="stat-box"><div class="v">${s.rpg}</div><div class="k">RPG</div></div>
        <div class="stat-box"><div class="v">${s.apg}</div><div class="k">APG</div></div>
        <div class="stat-box"><div class="v">${s.spg}</div><div class="k">SPG</div></div>
        <div class="stat-box"><div class="v">${s.bpg}</div><div class="k">BPG</div></div>
      </div>
      <div class="bar-row"><div class="bar-label">Overall</div><div class="bar-track"><div class="bar-fill" style="width:${s.effOVR}%"></div></div><div class="bar-val">${s.effOVR}</div></div>
      <div class="bar-row"><div class="bar-label">Popularidade</div><div class="bar-track"><div class="bar-fill" style="width:${pop}%;background:linear-gradient(90deg,var(--gold),var(--accent2));"></div></div><div class="bar-val">${pop}</div></div>
      <p class="note">${popularityLabel(pop)}${s.popDelta!=null?` (${s.popDelta>=0?'+':''}${s.popDelta} nesta temporada)`:''}</p>
      <p class="note">${s.missed>0?`Perdeu ${s.missed} jogos com lesão. `:''}${devText(s.dev, s.age)}</p>
    </div>
    <div class="card">
      <div class="eyebrow" style="margin-bottom:8px;">Prêmios da temporada</div>
      ${awardsHtml}
    </div>
    <div class="card">
      <div class="eyebrow" style="margin-bottom:8px;">Campanha do time</div>
      <p class="note" style="margin-top:0;">${standingText(s.standing, S.team.name)}${!s.playoffs?' Sem vaga nos playoffs esta temporada.':''}</p>
      ${playoffRunHtml(s.playoffRun)}
    </div>
    ${s.finalsLog ? `<button class="btn gold" data-action="showfinals">${s.champion?'REVER AS FINAIS ▸':'VER AS FINAIS ▸'}</button>` : ''}
    ${forceRetire
      ? `<button class="btn" data-action="endcareer">ENCERRAR CARREIRA ▸</button>`
      : `<div class="row">
          <button class="btn" data-action="nextseason">PRÓXIMA TEMPORADA ▸</button>
          ${canRetire?`<button class="btn secondary small" data-action="endcareer">Aposentar</button>`:''}
        </div>`}
  </div>`;
}
function renderFinals(){
  const s = S.career[S.career.length-1];
  const f = s.finalsLog;
  const idx = clamp(S.finalsViewIdx||0, 0, f.games.length-1);
  const isLast = idx === f.games.length-1;
  const g = f.games[idx];
  let winsSoFar = 0, lossesSoFar = 0;
  for (let i=0;i<=idx;i++){ if (f.games[i].win) winsSoFar++; else lossesSoFar++; }

  let dots = '';
  for (let i=0;i<f.games.length;i++) dots += `<div class="qtr-dot ${i<=idx?'done':''}"></div>`;

  const gameResultText = g.win
    ? `Vitória! ${S.team.name} ${g.myScore} — ${g.oppScore} ${s.finalsOpponent}`
    : `Derrota. ${S.team.name} ${g.myScore} — ${g.oppScore} ${s.finalsOpponent}`;

  let closingText = '';
  if (isLast){
    closingText = f.champion
      ? `Vocês bateram o ${s.finalsOpponent} e fecharam a série, taça erguida.`
      : `O ${s.finalsOpponent} levou a melhor na série.`;
  }

  return `<div class="screen">
    <div class="eyebrow">Finais da NBA · Temporada ${s.year} · Jogo ${idx+1} de ${f.games.length}</div>
    <h2 style="font-size:24px;margin:8px 0 4px 0;">${S.team.name} <span style="color:var(--muted);font-size:16px;">vs</span> ${s.finalsOpponent||''}</h2>
    <div class="qtr-track">${dots}</div>
    <div class="finals-score">
      <div class="v">${winsSoFar}</div><div class="sep">—</div><div class="v">${lossesSoFar}</div>
    </div>
    <p class="sub" style="text-align:center;">${gameResultText}${closingText?' '+closingText:''}</p>
    ${isLast
      ? `<button class="btn gold" data-action="backtocareer">${f.champion?'SOMOS CAMPEÕES ▸':'CONTINUAR ▸'}</button>`
      : `<button class="btn gold" data-action="finalsnext">PRÓXIMO JOGO ▸</button>`}
  </div>`;
}
function buildTrajectory(){
  const groups = [];
  S.career.forEach(s=>{
    const last = groups[groups.length-1];
    if (last && last.name === s.team.name){
      last.toYear = s.year;
      last.seasons++;
    } else {
      groups.push({name:s.team.name, c1:s.team.c1, c2:s.team.c2, fromYear:s.year, toYear:s.year, seasons:1});
    }
  });
  return groups;
}

function renderTrajectory(){
  const groups = buildTrajectory();
  return groups.map(g=>{
    const initials = g.name.split(' ').map(w=>w[0]).slice(-2).join('');
    const range = g.fromYear===g.toYear ? `Temporada ${g.fromYear}` : `Temporadas ${g.fromYear}–${g.toYear}`;
    return `<div class="traj-item">
      <div class="traj-swatch" style="background:linear-gradient(135deg,${g.c1},${g.c2});">${initials}</div>
      <div class="traj-info">
        <div class="traj-team">${g.name}</div>
        <div class="traj-years">${range}</div>
      </div>
      <div class="traj-seasons">${g.seasons} ${g.seasons===1?'temp.':'temps.'}</div>
    </div>`;
  }).join('');
}

function renderEnd(){
  const idx = finalTierIndex();
  const t = S.trophies;
  let scale = '';
  for (let i=TIERS.length-1;i>=0;i--){
    scale += `<div class="tier-row ${i===idx?'active':''}"><div class="n">${i+1}</div><div>${TIERS[i]}</div></div>`;
  }
  return `<div class="screen">
    <div class="eyebrow">Fim de carreira</div>
    <h2 style="font-size:30px;margin:8px 0 16px 0;">${TIERS[idx].toUpperCase()}</h2>
    <div class="share-card" style="--teamc1:${S.team.c1};--teamc2:${S.team.c2};">
      <div class="share-top">
        <div><div class="share-ovr">${S.peakOVR}</div><div class="share-team">PICO DE OVERALL</div></div>
        <div class="share-pos">${POSITIONS[S.position].abbr}<br>${S.team.name}</div>
      </div>
      <div class="share-tier">${TIERS[idx]}</div>
      <div class="share-stats">
        <div><div class="v">${t.rings}</div><div class="k">Anéis</div></div>
        <div><div class="v">${t.mvp}</div><div class="k">MVP</div></div>
        <div><div class="v">${t.allstar}</div><div class="k">All-Star</div></div>
        <div><div class="v">${t.allNBA}</div><div class="k">All-FBA</div></div>
        <div><div class="v">${t.dpoy}</div><div class="k">DPOY</div></div>
        <div><div class="v">${t.finalsMVP}</div><div class="k">Finals MVP</div></div>
        <div><div class="v">${S.totals.pts.toLocaleString('pt-BR')}</div><div class="k">Pontos</div></div>
        <div><div class="v">${S.popularity==null?50:S.popularity}</div><div class="k">Popularidade</div></div>
      </div>
      <div class="watermark">thegoat.game · Nº${S.playerNumber} ${S.playerName}</div>
    </div>
    <div class="card" style="margin-top:16px;">
      <div class="eyebrow" style="margin-bottom:8px;">Prêmios da carreira</div>
      <div class="stat-grid">
        <div class="stat-box"><div class="v">${t.allFBA1}</div><div class="k">1º Time</div></div>
        <div class="stat-box"><div class="v">${t.allFBA2}</div><div class="k">2º Time</div></div>
        <div class="stat-box"><div class="v">${t.allFBA3}</div><div class="k">3º Time</div></div>
        <div class="stat-box"><div class="v">${t.allDef1}</div><div class="k">All-Def 1º</div></div>
        <div class="stat-box"><div class="v">${t.allDef2}</div><div class="k">All-Def 2º</div></div>
        <div class="stat-box"><div class="v">${t.rookie1 ? 1 : 0}</div><div class="k">Rookie 1º</div></div>
        <div class="stat-box"><div class="v">${t.sixthMan}</div><div class="k">Sixth Man</div></div>
        <div class="stat-box"><div class="v">${t.allNBA}</div><div class="k">All-FBA Total</div></div>
        <div class="stat-box"><div class="v">${t.scoring}</div><div class="k">Cestinha</div></div>
      </div>
    </div>
    <div class="card" style="margin-top:16px;">
      <div class="eyebrow" style="margin-bottom:8px;">Números da carreira</div>
      <div class="stat-grid">
        <div class="stat-box"><div class="v">${S.totals.gp.toLocaleString('pt-BR')}</div><div class="k">Jogos</div></div>
        <div class="stat-box"><div class="v">${S.totals.pts.toLocaleString('pt-BR')}</div><div class="k">Pontos</div></div>
        <div class="stat-box"><div class="v">${S.totals.reb.toLocaleString('pt-BR')}</div><div class="k">Rebotes</div></div>
        <div class="stat-box"><div class="v">${S.totals.ast.toLocaleString('pt-BR')}</div><div class="k">Assist.</div></div>
        <div class="stat-box"><div class="v">${S.totals.stl.toLocaleString('pt-BR')}</div><div class="k">Roubos</div></div>
        <div class="stat-box"><div class="v">${S.totals.blk.toLocaleString('pt-BR')}</div><div class="k">Tocos</div></div>
      </div>
    </div>
    <div class="card" style="margin-top:16px;">
      <div class="eyebrow" style="margin-bottom:8px;">Trajetória na liga</div>
      <div class="trajectory">${renderTrajectory()}</div>
    </div>
    <div class="card" style="margin-top:16px;">
      <div class="eyebrow" style="margin-bottom:8px;">Onde você fica na história</div>
      <div class="tier-scale">${scale}</div>
    </div>
    <button class="btn" data-action="restart">NOVA CARREIRA ▸</button>
  </div>`;
}

/* ---------- Ações ---------- */
app.addEventListener('click', (e)=>{
  const el = e.target.closest('[data-action]');
  if (!el) return;
  const action = el.dataset.action;
  if (action==='start'){
    const nameVal = document.getElementById('nameInput').value.trim();
    const numVal = document.getElementById('numberInput').value.trim();
    newGame();
    S.playerName = nameVal || `Novato #${ri(100,999)}`;
    let num = parseInt(numVal, 10);
    if (isNaN(num) || num < 0 || num > 99) num = ri(0,99);
    S.playerNumber = num;
    S.screen='position';
  } else if (action==='pickpos'){
    S.position = el.dataset.pos;
    S.attrs = baselineAttrs();
    S.currentLegend = drawLegend();
    S.pendingAttr = null;
    S.screen='draft';
  } else if (action==='chooseattr'){
    const used = S.draftLog.some(e=>e.attrKey===el.dataset.attr);
    if (!used) S.pendingAttr = el.dataset.attr;
  } else if (action==='steal'){
    if (S.pendingAttr){
      doSteal(S.pendingAttr);
      S.pendingAttr = null;
    }
  } else if (action==='finishdraft'){
    S.screen='draftresult';
  } else if (action==='gotolottery'){
    S.screen='teamreveal';
  } else if (action==='startcareer'){
    S.salary = Math.max(1, Math.round(salaryForOVR(S.baseOVR, 50) * 0.22 * 10) / 10);
    const s0 = simulateSeason();
    S.screen = contractDue() ? 'contract' : (maybeTriggerEvent(s0) ? 'event' : 'career');
    if (S.screen==='contract') S.pendingContract = buildContractOffer(s0);
  } else if (action==='nextseason'){
    if (retirementCheck()){
      S.age++;
      S.screen='end';
    } else {
      S.age++;
      const s1 = simulateSeason();
      S.screen = contractDue() ? 'contract' : (maybeTriggerEvent(s1) ? 'event' : 'career');
      if (S.screen==='contract') S.pendingContract = buildContractOffer(s1);
    }
  } else if (action==='eventchoice'){
    const idx = parseInt(el.dataset.idx, 10);
    const ev = S.pendingEvent;
    if (ev && ev.options[idx]){
      S.lastEventNote = ev.options[idx].effect();
    }
    S.pendingEvent = null;
    S.screen = 'career';
  } else if (action==='renewcontract'){
    S.salary = S.pendingContract.homeSalary;
    S.popularity = clamp((S.popularity==null?50:S.popularity) + ri(2,5), 0, 100);
    S.lastEventNote = `Você renovou com o ${S.team.name} por ${S.pendingContract.homeYears} anos, $${S.salary}M por temporada. A torcida celebrou a lealdade.`;
    S.pendingContract = null;
    S.lastContractSeasonCount = S.career.length;
    S.contractInterval = ri(3,4);
    S.screen = 'career';
  } else if (action==='signteam'){
    const idx = parseInt(el.dataset.idx, 10);
    const t = S.pendingContract.suitors[idx];
    const oldName = S.team.name;
    S.team = {name:t.name, c1:t.c1, c2:t.c2};
    S.salary = t.salary;
    S.popularity = clamp((S.popularity==null?50:S.popularity) + ri(-3,5), 0, 100);
    S.lastEventNote = `Você deixou o ${oldName} e assinou com o ${S.team.name} por ${t.years} anos, $${S.salary}M por temporada. Página virada.`;
    S.pendingContract = null;
    S.lastContractSeasonCount = S.career.length;
    S.contractInterval = ri(3,4);
    S.screen = 'career';
  } else if (action==='requesttrade'){
    const oldName = S.team.name;
    const opp = pickOpponent(S.team.name);
    const t = TEAMS.find(tm=>tm[0]===opp);
    S.team = {name:t[0], c1:t[1], c2:t[2]};
    const pop = S.popularity==null?50:S.popularity;
    S.salary = Math.round(salaryForOVR(S.trueOVR, pop) * (0.85 + rng()*0.2) * 10) / 10;
    S.popularity = clamp(pop + ri(-2,7), 0, 100);
    S.lastEventNote = `Você pediu para sair e a diretoria atendeu: troca fechada, ${oldName} → ${S.team.name}. Novo contrato de $${S.salary}M por temporada.`;
    S.pendingContract = null;
    S.lastContractSeasonCount = S.career.length;
    S.contractInterval = ri(3,4);
    S.screen = 'career';
  } else if (action==='endcareer'){
    S.screen='end';
  } else if (action==='showfinals'){
    S.finalsViewIdx = 0;
    S.screen='finals';
  } else if (action==='finalsnext'){
    const s = S.career[S.career.length-1];
    const max = s.finalsLog.games.length-1;
    S.finalsViewIdx = Math.min((S.finalsViewIdx||0)+1, max);
  } else if (action==='backtocareer'){
    S.screen='career';
  } else if (action==='restart'){
    newGame();
  }
  render();
});
render();
</script>
</body>
</html>
