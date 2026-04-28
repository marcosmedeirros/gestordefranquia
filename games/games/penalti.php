<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require '../core/conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = (int)$_SESSION['user_id'];

$TIMES = [
  ['name'=>'Flamengo',             'tier'=>1,'badge'=>'FLA','color'=>'#e30613','dark'=>'#9e0000'],
  ['name'=>'Corinthians',          'tier'=>1,'badge'=>'COR','color'=>'#1a1a1a','dark'=>'#000'],
  ['name'=>'Palmeiras',            'tier'=>1,'badge'=>'PAL','color'=>'#006b3c','dark'=>'#004a29'],
  ['name'=>'São Paulo',            'tier'=>1,'badge'=>'SPF','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Santos',               'tier'=>1,'badge'=>'SAN','color'=>'#555','dark'=>'#222'],
  ['name'=>'Vasco da Gama',        'tier'=>1,'badge'=>'VAS','color'=>'#222','dark'=>'#000'],
  ['name'=>'Fluminense',           'tier'=>1,'badge'=>'FLU','color'=>'#8b0000','dark'=>'#600'],
  ['name'=>'Botafogo',             'tier'=>1,'badge'=>'BOT','color'=>'#333','dark'=>'#111'],
  ['name'=>'Atlético Mineiro',     'tier'=>1,'badge'=>'CAM','color'=>'#1a1a1a','dark'=>'#000'],
  ['name'=>'Cruzeiro',             'tier'=>1,'badge'=>'CRU','color'=>'#1a3a8f','dark'=>'#0d1f5c'],
  ['name'=>'Grêmio',               'tier'=>1,'badge'=>'GRE','color'=>'#3c6eb4','dark'=>'#1a3a8f'],
  ['name'=>'Internacional',        'tier'=>2,'badge'=>'INT','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Athletico PR',         'tier'=>2,'badge'=>'CAP','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Coritiba',             'tier'=>2,'badge'=>'COT','color'=>'#1a6b2c','dark'=>'#0d4a1e'],
  ['name'=>'Bahia',                'tier'=>2,'badge'=>'BAH','color'=>'#1a3a8f','dark'=>'#0d1f5c'],
  ['name'=>'Vitória',              'tier'=>2,'badge'=>'VIT','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Sport Recife',         'tier'=>2,'badge'=>'SPT','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Náutico',              'tier'=>2,'badge'=>'NAU','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Santa Cruz',           'tier'=>2,'badge'=>'SCR','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Ceará',                'tier'=>2,'badge'=>'CEA','color'=>'#1a1a1a','dark'=>'#000'],
  ['name'=>'Fortaleza',            'tier'=>2,'badge'=>'FOR','color'=>'#1a3a8f','dark'=>'#0d1f5c'],
  ['name'=>'Goiás',                'tier'=>2,'badge'=>'GOI','color'=>'#1a6b2c','dark'=>'#0d4a1e'],
  ['name'=>'Atlético GO',          'tier'=>2,'badge'=>'ACG','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Guarani',              'tier'=>2,'badge'=>'GUA','color'=>'#006b3c','dark'=>'#004a29'],
  ['name'=>'Ponte Preta',          'tier'=>2,'badge'=>'PON','color'=>'#1a1a1a','dark'=>'#000'],
  ['name'=>'Portuguesa',           'tier'=>2,'badge'=>'POR','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'América MG',           'tier'=>2,'badge'=>'AME','color'=>'#006b3c','dark'=>'#004a29'],
  ['name'=>'Chapecoense',          'tier'=>2,'badge'=>'CHA','color'=>'#006b3c','dark'=>'#004a29'],
  ['name'=>'Avaí',                 'tier'=>2,'badge'=>'AVA','color'=>'#1a3a8f','dark'=>'#0d1f5c'],
  ['name'=>'Figueirense',          'tier'=>2,'badge'=>'FIG','color'=>'#333','dark'=>'#111'],
  ['name'=>'Paraná Clube',         'tier'=>2,'badge'=>'PAR','color'=>'#1a3a8f','dark'=>'#0d1f5c'],
  ['name'=>'Juventude',            'tier'=>2,'badge'=>'JUV','color'=>'#006b3c','dark'=>'#004a29'],
  ['name'=>'Remo',                 'tier'=>3,'badge'=>'REM','color'=>'#1a3a8f','dark'=>'#0d1f5c'],
  ['name'=>'Paysandu',             'tier'=>3,'badge'=>'PAY','color'=>'#1a3a8f','dark'=>'#0d1f5c'],
  ['name'=>'CSA',                  'tier'=>3,'badge'=>'CSA','color'=>'#1a3a8f','dark'=>'#0d1f5c'],
  ['name'=>'CRB',                  'tier'=>3,'badge'=>'CRB','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'ABC',                  'tier'=>3,'badge'=>'ABC','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'América RN',           'tier'=>3,'badge'=>'AMR','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Sampaio Corrêa',       'tier'=>3,'badge'=>'SAM','color'=>'#1a3a8f','dark'=>'#0d1f5c'],
  ['name'=>'Moto Club',            'tier'=>3,'badge'=>'MOT','color'=>'#1a3a8f','dark'=>'#0d1f5c'],
  ['name'=>'Treze',                'tier'=>3,'badge'=>'TRE','color'=>'#333','dark'=>'#111'],
  ['name'=>'Campinense',           'tier'=>3,'badge'=>'CPS','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Botafogo-PB',          'tier'=>3,'badge'=>'BPB','color'=>'#333','dark'=>'#111'],
  ['name'=>'Confiança',            'tier'=>3,'badge'=>'CON','color'=>'#1a3a8f','dark'=>'#0d1f5c'],
  ['name'=>'Sergipe',              'tier'=>3,'badge'=>'SER','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Joinville',            'tier'=>3,'badge'=>'JOI','color'=>'#333','dark'=>'#111'],
  ['name'=>'Criciúma',             'tier'=>3,'badge'=>'CRI','color'=>'#f5a800','dark'=>'#b37800'],
  ['name'=>'Operário PR',          'tier'=>3,'badge'=>'OPE','color'=>'#333','dark'=>'#111'],
  ['name'=>'Londrina',             'tier'=>3,'badge'=>'LON','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Vila Nova',            'tier'=>3,'badge'=>'VNO','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Brasil de Pelotas',    'tier'=>3,'badge'=>'BPE','color'=>'#006b3c','dark'=>'#004a29'],
  ['name'=>'Caxias',               'tier'=>3,'badge'=>'CAX','color'=>'#cc0000','dark'=>'#900'],
  ['name'=>'Ypiranga-RS',          'tier'=>3,'badge'=>'YPI','color'=>'#f5a800','dark'=>'#b37800'],
  ['name'=>'São Bento',            'tier'=>3,'badge'=>'SBE','color'=>'#1a3a8f','dark'=>'#0d1f5c'],
  ['name'=>'Ferroviária',          'tier'=>3,'badge'=>'FER','color'=>'#006b3c','dark'=>'#004a29'],
  ['name'=>'XV de Piracicaba',     'tier'=>3,'badge'=>'XVP','color'=>'#006b3c','dark'=>'#004a29'],
  ['name'=>'Bangu',                'tier'=>3,'badge'=>'BAN','color'=>'#333','dark'=>'#111'],
  ['name'=>'Madureira',            'tier'=>3,'badge'=>'MAD','color'=>'#f5a800','dark'=>'#b37800'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Copa Pênaltis</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0e17;--panel:#131929;--panel2:#1a2235;--panel3:#1f2a40;
  --border:#2a3a55;--border2:#3a4f70;
  --text:#e8edf5;--text2:#8fa0bb;--text3:#5a6f8a;
  --green:#22c55e;--red:#ef4444;--amber:#f59e0b;--blue:#3b82f6;--gold:#ffd700;
}
body{background:var(--bg);color:var(--text);font-family:system-ui,sans-serif;min-height:100vh;overflow-x:hidden;-webkit-tap-highlight-color:transparent}

/* ── HEADER ── */
.hdr{background:linear-gradient(135deg,#1a2a5e,#0d1a3a);padding:12px 16px;display:flex;align-items:center;gap:10px;border-bottom:2px solid #2a4a8f;position:sticky;top:0;z-index:100}
.hdr-logo{font-size:24px}
.hdr-title{font-size:15px;font-weight:800;color:#fff}
.hdr-sub{font-size:10px;color:#7a9acc}
.btn-back{margin-left:auto;background:rgba(255,255,255,.08);border:1px solid var(--border);color:var(--text2);padding:5px 12px;border-radius:8px;font-size:12px;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:5px;white-space:nowrap}

/* ── SCREENS ── */
.screen{display:none;max-width:540px;margin:0 auto;padding:0 0 80px}
.screen.active{display:block}

/* ── START ── */
.start-hero{background:linear-gradient(180deg,#0d1a3a 0%,#1a2a5e 50%,#0d3a1a 100%);padding:32px 20px 24px;text-align:center;position:relative;overflow:hidden}
.start-hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")}
.hero-icon{font-size:64px;margin-bottom:10px;display:block;filter:drop-shadow(0 4px 12px rgba(0,0,0,.5))}
.hero-title{font-size:26px;font-weight:900;color:#fff;margin-bottom:4px;text-shadow:0 2px 8px rgba(0,0,0,.5)}
.hero-sub{font-size:13px;color:#aac4ee;margin-bottom:20px}

/* ── BOTÕES ── */
.btn{border:none;border-radius:12px;padding:13px 20px;font-size:14px;font-weight:700;cursor:pointer;width:100%;transition:.15s;letter-spacing:.3px}
.btn-primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 4px 14px rgba(37,99,235,.4)}
.btn-primary:hover{filter:brightness(1.1)}
.btn-success{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;box-shadow:0 4px 14px rgba(22,163,74,.4)}
.btn-danger{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff}
.btn-ghost{background:rgba(255,255,255,.07);border:1px solid var(--border);color:var(--text)}
.btn-ghost:hover{background:rgba(255,255,255,.12)}
.btn-sm{padding:8px 16px;font-size:12px;border-radius:8px;width:auto}

/* ── TEAM PICKER ── */
.tier-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text3);padding:16px 16px 8px;display:flex;align-items:center;gap:8px}
.tier-label::after{content:'';flex:1;height:1px;background:var(--border)}
.tier-1-lbl{color:#ffd700}
.tier-2-lbl{color:#aaa}
.tier-3-lbl{color:#cd7f32}
.team-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:0 12px 8px}
.team-card{background:var(--panel);border:2px solid var(--border);border-radius:12px;padding:10px 6px;cursor:pointer;text-align:center;transition:.15s;position:relative;overflow:hidden}
.team-card:hover{transform:translateY(-2px);border-color:var(--border2)}
.team-card.selected{border-color:var(--amber)!important}
.tc-badge{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;font-size:10px;font-weight:800;margin-bottom:4px;letter-spacing:.3px}
.tc-name{font-size:10px;font-weight:600;color:var(--text);line-height:1.2}
.tc-diff{font-size:9px;color:var(--text3);margin-top:2px}

/* ── GROUPS SCREEN ── */
.groups-wrap{padding:12px}
.all-groups{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
.group-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:10px}
.group-card.user-group{border-color:rgba(245,158,11,.5);background:rgba(245,158,11,.04)}
.group-hdr{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between}
.group-hdr .live-dot{width:6px;height:6px;border-radius:50%;background:var(--red);animation:pulse 1s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.g-team{display:flex;align-items:center;gap:5px;padding:3px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.g-team:last-child{border-bottom:none}
.g-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.g-name{font-size:10px;font-weight:600;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.g-name.user-team{color:var(--amber)}
.g-pts{font-size:10px;font-weight:700;color:var(--amber);min-width:20px;text-align:right}
.g-saldo{font-size:9px;color:var(--text3);min-width:22px;text-align:center}

.match-live-feed{background:var(--panel3);border:1px solid var(--border);border-radius:10px;padding:8px 12px;margin-bottom:10px;font-size:11px;color:var(--text2);min-height:32px}
.live-result{animation:fadeIn .3s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}

.classif{display:inline-block;font-size:9px;padding:1px 5px;border-radius:4px;font-weight:700;margin-left:4px}
.classif.q{background:rgba(34,197,94,.2);color:var(--green)}
.classif.e{background:rgba(239,68,68,.15);color:var(--red)}

/* ── ESTÁDIO / CAMPO ── */
.stadium{background:linear-gradient(180deg,#1a5c1a 0%,#2d8a2d 40%,#3aa03a 60%,#2d8a2d 100%);position:relative;overflow:hidden;border-radius:14px 14px 0 0;user-select:none}
.stadium-bg{width:100%;display:block}

/* Campo com perspectiva */
.field-scene{position:relative;width:100%;background:linear-gradient(180deg,#87CEEB 0%,#b0e0ff 30%,#4a8a4a 30.1%,#3a7a3a 60%,#2d6a2d 100%);overflow:hidden;border-radius:14px 14px 0 0}
.crowd{width:100%;height:30px;background:linear-gradient(90deg,#b44,#48b,#4b8,#b84,#84b,#4bb,#bb4,#b44);opacity:.7;position:relative}
.crowd::after{content:'';position:absolute;inset:0;background:repeating-linear-gradient(90deg,rgba(0,0,0,.15) 0px,rgba(0,0,0,.15) 2px,transparent 2px,transparent 8px)}
.sky{height:20px;background:linear-gradient(180deg,#1a3a6a,#2a5a9a)}
.grass-top{height:8px;background:#3a8a3a;border-bottom:2px solid rgba(255,255,255,.3)}

/* GOL SVG */
.goal-container{position:relative;max-width:320px;margin:0 auto;padding:8px 10px 0}
.goal-svg{width:100%;display:block;filter:drop-shadow(0 4px 12px rgba(0,0,0,.5))}

/* KICKER */
.kicker-container{position:relative;max-width:320px;margin:-10px auto 0;height:90px;display:flex;align-items:flex-end;justify-content:center}
.kicker-svg{width:70px;height:90px;display:block;filter:drop-shadow(0 3px 6px rgba(0,0,0,.4))}
.kicker-svg.kick-anim{animation:kickAnim .35s ease forwards}
@keyframes kickAnim{
  0%{transform:translateY(0) rotate(0deg)}
  30%{transform:translateY(-6px) rotate(-5deg)}
  60%{transform:translateY(-4px) rotate(3deg)}
  100%{transform:translateY(0) rotate(0deg)}
}

/* BALL animation */
.ball-fly{position:absolute;transition:all .32s cubic-bezier(.2,0,.5,1);pointer-events:none;font-size:18px;z-index:10}

/* ZONA BUTTONS */
.zone-grid{display:grid;grid-template-columns:1fr 1fr 1fr;grid-template-rows:1fr 1fr;gap:3px;padding:8px 12px 10px;max-width:380px;margin:0 auto}
.zone-btn{background:rgba(255,255,255,.07);border:2px solid rgba(255,255,255,.15);border-radius:10px;height:52px;cursor:pointer;transition:.15s;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;position:relative;overflow:hidden}
.zone-btn:hover{background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.4);transform:scale(1.03)}
.zone-btn.hit-goal{border-color:var(--green)!important;background:rgba(34,197,94,.25)!important;animation:goalFlash .4s ease}
.zone-btn.hit-save{border-color:var(--red)!important;background:rgba(239,68,68,.2)!important}
@keyframes goalFlash{0%,100%{opacity:1}50%{opacity:.6}}
.zone-icon{font-size:16px}
.zone-lbl{font-size:8px;color:rgba(255,255,255,.5)}

/* MATCH PANEL */
.match-panel{background:var(--panel);padding:0;border-radius:0 0 14px 14px;overflow:hidden}
.match-top{background:linear-gradient(135deg,var(--panel2),var(--panel3));padding:10px 14px;display:flex;align-items:center;gap:10px}
.mt-team{flex:1;text-align:center}
.mt-name{font-size:11px;font-weight:700;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mt-score{font-size:32px;font-weight:900;color:#fff;letter-spacing:3px;min-width:80px;text-align:center;font-variant-numeric:tabular-nums}
.mt-round{font-size:10px;color:var(--text3);text-align:center}

/* DOTS de pênalti */
.dots-row{display:flex;align-items:center;gap:6px;padding:6px 14px 4px}
.dots-label{font-size:10px;color:var(--text3);min-width:28px;font-weight:700}
.dot{width:20px;height:20px;border-radius:50%;border:2px solid rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0;transition:.2s}
.dot.goal-u{background:var(--green);border-color:var(--green)}
.dot.save-u{background:rgba(239,68,68,.3);border-color:var(--red)}
.dot.goal-o{background:var(--red);border-color:var(--red)}
.dot.save-o{background:rgba(34,197,94,.2);border-color:var(--green)}
.dot.pending{opacity:.25}

/* STATUS */
.status-bar{text-align:center;padding:6px 12px 2px;font-size:13px;font-weight:700;min-height:28px}
.s-ok{color:var(--green)}
.s-fail{color:var(--red)}
.s-neutral{color:var(--amber)}
.s-info{color:var(--blue)}

/* PHASE TAG */
.phase-tag{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px}
.phase-tag.shoot{background:rgba(245,158,11,.2);color:var(--amber)}
.phase-tag.defend{background:rgba(59,130,246,.2);color:var(--blue)}
.phase-tag.sd{background:rgba(239,68,68,.2);color:var(--red)}

/* OVERLAY RESULTADO */
.result-overlay{position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:300;display:flex;align-items:center;justify-content:center;padding:20px}
.result-box{background:var(--panel);border:1px solid var(--border2);border-radius:20px;padding:28px 22px;max-width:360px;width:100%;text-align:center}
.result-big-icon{font-size:58px;margin-bottom:8px}
.result-title{font-size:22px;font-weight:900;margin-bottom:4px}
.result-score-display{font-size:28px;font-weight:800;margin:10px 0;color:#fff}

/* KNOCKOUT BRACKET */
.ko-wrap{padding:12px}
.ko-match{background:var(--panel);border:1px solid var(--border);border-radius:12px;margin-bottom:8px;overflow:hidden}
.ko-match.user-match{border-color:rgba(245,158,11,.5)}
.ko-team-row{display:flex;align-items:center;gap:8px;padding:8px 12px}
.ko-team-row+.ko-team-row{border-top:1px solid var(--border)}
.ko-badge{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;flex-shrink:0}
.ko-name{flex:1;font-size:12px;font-weight:600}
.ko-tier{font-size:9px;color:var(--text3)}
.user-lbl{font-size:9px;font-weight:700;color:var(--amber);background:rgba(245,158,11,.12);padding:1px 6px;border-radius:4px}

/* CHAMPION */
.champ-screen{text-align:center;padding:40px 20px}
.champ-icon{font-size:72px;margin-bottom:12px;display:block;animation:bounce2 .8s ease infinite alternate}
@keyframes bounce2{from{transform:translateY(0)}to{transform:translateY(-12px)}}

/* MISC */
.hidden{display:none!important}
.card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:12px}
.px{padding:0 14px}
.mb12{margin-bottom:12px}
.section-pad{padding:14px}
.ko-phase-hdr{padding:14px 14px 8px;font-size:16px;font-weight:800;color:#fff}
.ko-phase-sub{padding:0 14px 12px;font-size:11px;color:var(--text3)}
</style>
</head>
<body>

<div class="hdr">
  <div class="hdr-logo">⚽</div>
  <div>
    <div class="hdr-title">Copa Pênaltis</div>
    <div class="hdr-sub" id="hdr-phase">Fase de Grupos · Mata-Mata</div>
  </div>
  <a href="../games.php" class="btn-back btn-sm"><i class="bi bi-arrow-left"></i> Sair</a>
</div>

<!-- ══ TELA 1: INÍCIO ══ -->
<div class="screen active" id="sc-start">
  <div class="start-hero">
    <span class="hero-icon">🏆</span>
    <div class="hero-title">Copa Pênaltis</div>
    <div class="hero-sub">Fase de Grupos · Oitavas · Quartas · Semi · Final</div>
  </div>
  <div class="section-pad">
    <div class="card mb12">
      <div style="font-size:12px;font-weight:700;color:var(--text2);margin-bottom:10px;text-transform:uppercase;letter-spacing:.7px">Como jogar</div>
      <div style="font-size:12px;color:var(--text2);line-height:2">
        <div>⚽ Fase de grupos — 3 jogos, 4 times por grupo</div>
        <div>🏅 Classificam os 2 melhores de cada grupo</div>
        <div>⚔️ Mata-mata até a Final</div>
        <div>🦵 Escolha a zona para <b>chutar</b></div>
        <div>🧤 Escolha a zona para <b>defender</b></div>
        <div>💀 Empate no mata-mata = morte súbita</div>
      </div>
    </div>
    <button class="btn btn-primary" onclick="showScreen('sc-team')">
      <i class="bi bi-controller"></i> Escolher Time & Jogar
    </button>
  </div>
</div>

<!-- ══ TELA 2: ESCOLHER TIME ══ -->
<div class="screen" id="sc-team">
  <div class="section-pad" style="padding-bottom:0">
    <div style="font-size:16px;font-weight:800;color:#fff;margin-bottom:4px">Escolha seu time</div>
    <div style="font-size:12px;color:var(--text3);margin-bottom:12px">Times Tier 1 enfrentam adversários mais difíceis</div>
  </div>

  <div class="tier-label tier-1-lbl">⭐ Tier 1 — Grandes</div>
  <div class="team-grid" id="tg-1"></div>

  <div class="tier-label tier-2-lbl">🥈 Tier 2 — Médios</div>
  <div class="team-grid" id="tg-2"></div>

  <div class="tier-label tier-3-lbl">🥉 Tier 3 — Menores</div>
  <div class="team-grid" id="tg-3"></div>

  <div class="section-pad">
    <button class="btn btn-success" id="btn-confirm-team" onclick="confirmTeam()" disabled>
      <i class="bi bi-play-fill"></i> Confirmar Time
    </button>
  </div>
</div>

<!-- ══ TELA 3: GRUPOS ══ -->
<div class="screen" id="sc-groups">
  <div class="groups-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <div>
        <div style="font-size:16px;font-weight:800;color:#fff">Fase de Grupos</div>
        <div style="font-size:11px;color:var(--text3)" id="groups-round-label">Jogo 1 de 3</div>
      </div>
      <div id="live-indicator" style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--red);font-weight:700">
        <div class="live-dot"></div>AO VIVO
      </div>
    </div>

    <div class="match-live-feed" id="live-feed">Aguardando início dos jogos...</div>

    <div class="all-groups" id="all-groups"></div>

    <button class="btn btn-primary" id="btn-play-group" onclick="playNextGroupMatch()">
      <i class="bi bi-play-fill"></i> Jogar Jogo 1 de 3
    </button>
  </div>
</div>

<!-- ══ TELA 4: PARTIDA ══ -->
<div class="screen" id="sc-match">

  <!-- CAMPO/ESTÁDIO -->
  <div class="field-scene">
    <div class="sky"></div>
    <div class="crowd"></div>
    <div class="grass-top"></div>

    <!-- GOL -->
    <div class="goal-container">
      <svg class="goal-svg" viewBox="0 0 320 130" xmlns="http://www.w3.org/2000/svg">
        <!-- Fundo rede -->
        <defs>
          <pattern id="net" width="12" height="12" patternUnits="userSpaceOnUse">
            <path d="M 12 0 L 0 0 0 12" fill="none" stroke="rgba(255,255,255,.15)" stroke-width=".8"/>
          </pattern>
        </defs>
        <!-- Gramado perspectiva -->
        <polygon points="0,130 320,130 280,90 40,90" fill="#2d7a2d" opacity=".4"/>
        <!-- Rede fundo -->
        <rect x="52" y="14" width="216" height="76" fill="url(#net)"/>
        <!-- Posts -->
        <rect x="48" y="10" width="8" height="80" rx="3" fill="#ddd"/>
        <rect x="264" y="10" width="8" height="80" rx="3" fill="#ddd"/>
        <rect x="48" y="10" width="224" height="8" rx="3" fill="#ddd"/>
        <!-- Divisões de zona (linhas guia) -->
        <line x1="121" y1="18" x2="121" y2="90" stroke="rgba(255,255,255,.1)" stroke-width="1" stroke-dasharray="5,4"/>
        <line x1="196" y1="18" x2="196" y2="90" stroke="rgba(255,255,255,.1)" stroke-width="1" stroke-dasharray="5,4"/>
        <line x1="56" y1="54" x2="264" y2="54" stroke="rgba(255,255,255,.1)" stroke-width="1" stroke-dasharray="5,4"/>
        <!-- Highlight zona selecionada -->
        <rect id="zone-hl" x="0" y="0" width="0" height="0" rx="4" opacity=".7"/>
        <!-- Goleiro -->
        <g id="keeper-g" style="transition:transform .22s ease">
          <!-- corpo do goleiro -->
          <ellipse cx="160" cy="74" rx="10" ry="12" fill="#ff8c00"/>
          <circle cx="160" cy="58" r="9" fill="#ffcc99"/>
          <!-- luvas -->
          <ellipse cx="148" cy="70" rx="5" ry="4" fill="#ff8c00"/>
          <ellipse cx="172" cy="70" rx="5" ry="4" fill="#ff8c00"/>
          <!-- pernas -->
          <rect x="153" y="83" width="6" height="14" rx="3" fill="#1a1a8f"/>
          <rect x="161" y="83" width="6" height="14" rx="3" fill="#1a1a8f"/>
        </g>
        <!-- Bola (começa fora da tela) -->
        <circle id="ball-g" cx="160" cy="200" r="8" fill="white" stroke="#333" stroke-width="1.5" opacity="0">
          <animate id="ball-anim" attributeName="opacity" values="0" dur="0s"/>
        </circle>
      </svg>
    </div>

    <!-- KICKER -->
    <div class="kicker-container">
      <svg id="kicker-svg" class="kicker-svg" viewBox="0 0 70 90" xmlns="http://www.w3.org/2000/svg">
        <!-- Sombra -->
        <ellipse cx="35" cy="88" rx="16" ry="3" fill="rgba(0,0,0,.3)"/>
        <!-- Bola no chão -->
        <circle id="kick-ball" cx="44" cy="80" r="6" fill="white" stroke="#333" stroke-width="1"/>
        <!-- Perna traseira -->
        <line x1="32" y1="62" x2="25" y2="82" stroke="#1a1a8f" stroke-width="5" stroke-linecap="round"/>
        <!-- Perna de chute (animada) -->
        <g id="kick-leg">
          <line x1="38" y1="62" x2="46" y2="80" stroke="#1a1a8f" stroke-width="5" stroke-linecap="round"/>
          <line x1="46" y1="80" x2="50" y2="82" stroke="#cc0000" stroke-width="4" stroke-linecap="round"/>
        </g>
        <!-- Corpo -->
        <rect x="26" y="36" width="18" height="28" rx="6" fill="#cc0000"/>
        <!-- Número -->
        <text x="35" y="54" font-size="9" fill="rgba(255,255,255,.8)" text-anchor="middle" font-weight="bold">10</text>
        <!-- Braço esq -->
        <line x1="28" y1="42" x2="18" y2="54" stroke="#cc0000" stroke-width="5" stroke-linecap="round"/>
        <!-- Braço dir -->
        <line x1="42" y1="42" x2="52" y2="54" stroke="#cc0000" stroke-width="5" stroke-linecap="round"/>
        <!-- Pescoço -->
        <rect x="31" y="30" width="8" height="8" rx="3" fill="#ffcc99"/>
        <!-- Cabeça -->
        <circle cx="35" cy="22" r="12" fill="#ffcc99"/>
        <!-- Cabelo -->
        <ellipse cx="35" cy="13" rx="11" ry="5" fill="#3a2000"/>
      </svg>
    </div>
  </div>

  <!-- PAINEL DA PARTIDA -->
  <div class="match-panel">
    <!-- Placar -->
    <div class="match-top">
      <div class="mt-team">
        <div class="mt-name" id="mt-user-name">Seu Time</div>
        <div style="font-size:9px;color:var(--text3)" id="mt-user-badge"></div>
      </div>
      <div style="text-align:center">
        <div class="mt-score"><span id="mt-score-u">0</span><span style="color:var(--text3);font-size:20px"> × </span><span id="mt-score-o">0</span></div>
        <div class="mt-round" id="mt-round">Pênalti 1/5</div>
      </div>
      <div class="mt-team">
        <div class="mt-name" id="mt-opp-name">Adversário</div>
        <div style="font-size:9px;color:var(--text3)" id="mt-opp-badge"></div>
      </div>
    </div>

    <!-- Dots de pênalti por time -->
    <div class="dots-row">
      <div class="dots-label" id="dots-lbl-u" style="font-size:9px"></div>
      <div id="dots-user" style="display:flex;gap:3px"></div>
    </div>
    <div class="dots-row" style="padding-top:2px">
      <div class="dots-label" id="dots-lbl-o" style="font-size:9px"></div>
      <div id="dots-opp" style="display:flex;gap:3px"></div>
    </div>

    <div style="text-align:center;padding:6px 0 2px">
      <span class="phase-tag shoot" id="phase-tag">🦵 Você chuta</span>
    </div>
    <div class="status-bar s-neutral" id="status-bar">Escolha uma zona no gol</div>

    <!-- ZONAS DE CHUTE/DEFESA -->
    <div class="zone-grid" id="zone-grid">
      <div class="zone-btn" data-zone="0" onclick="handleZone(0)"><span class="zone-icon">↖</span><span class="zone-lbl">Alto Esq</span></div>
      <div class="zone-btn" data-zone="1" onclick="handleZone(1)"><span class="zone-icon">⬆</span><span class="zone-lbl">Alto Cen</span></div>
      <div class="zone-btn" data-zone="2" onclick="handleZone(2)"><span class="zone-icon">↗</span><span class="zone-lbl">Alto Dir</span></div>
      <div class="zone-btn" data-zone="3" onclick="handleZone(3)"><span class="zone-icon">↙</span><span class="zone-lbl">Baixo Esq</span></div>
      <div class="zone-btn" data-zone="4" onclick="handleZone(4)"><span class="zone-icon">⬇</span><span class="zone-lbl">Baixo Cen</span></div>
      <div class="zone-btn" data-zone="5" onclick="handleZone(5)"><span class="zone-icon">↘</span><span class="zone-lbl">Baixo Dir</span></div>
    </div>

    <div style="padding:4px 12px 14px;text-align:center">
      <button class="btn btn-ghost hidden" id="btn-next-kick" onclick="nextKick()" style="padding:8px;font-size:12px">
        Pular ›
      </button>
    </div>
  </div>
</div>

<!-- ══ TELA 5: RESULTADO PARTIDA ══ -->
<div class="screen" id="sc-result">
  <div class="section-pad" style="text-align:center;padding-top:24px">
    <div class="result-big-icon" id="res-icon">🏅</div>
    <div class="result-title" id="res-title">Vitória!</div>
    <div style="font-size:13px;color:var(--text2);margin-bottom:12px" id="res-sub"></div>
    <div class="result-score-display" id="res-score"></div>
    <div style="font-size:12px;color:var(--text3);margin-bottom:20px" id="res-teams"></div>
    <button class="btn btn-primary" id="btn-res-continue" onclick="afterMatch()">Continuar ›</button>
  </div>
</div>

<!-- ══ TELA 6: MATA-MATA ══ -->
<div class="screen" id="sc-ko">
  <div class="ko-phase-hdr" id="ko-phase-hdr">Quartas de Final</div>
  <div class="ko-phase-sub" id="ko-phase-sub">Mata-Mata · Eliminação direta</div>
  <div class="ko-wrap" id="ko-bracket"></div>
  <div class="px mb12">
    <button class="btn btn-primary" id="btn-ko-play" onclick="playKO()">⚽ Jogar</button>
  </div>
</div>

<!-- ══ TELA 7: CAMPEÃO ══ -->
<div class="screen" id="sc-champ">
  <div class="champ-screen">
    <span class="champ-icon">🏆</span>
    <div style="font-size:28px;font-weight:900;color:var(--gold);margin-bottom:8px">CAMPEÃO!</div>
    <div style="font-size:18px;font-weight:700;color:#fff;margin-bottom:6px" id="champ-name"></div>
    <div style="font-size:13px;color:var(--text3);margin-bottom:28px">Você venceu a Copa Pênaltis!</div>
    <div style="display:flex;gap:10px;max-width:280px;margin:0 auto">
      <button class="btn btn-ghost" onclick="showScreen('sc-start')">Início</button>
      <button class="btn btn-success" onclick="location.reload()">Jogar Novamente</button>
    </div>
  </div>
</div>

<!-- ══ OVERLAY ELIMINADO ══ -->
<div class="result-overlay hidden" id="ov-elim">
  <div class="result-box">
    <div class="result-big-icon">😔</div>
    <div class="result-title" style="color:var(--red)">Eliminado!</div>
    <div style="font-size:13px;color:var(--text2);margin:10px 0 18px" id="elim-msg"></div>
    <button class="btn btn-danger" onclick="location.reload()">Voltar ao Início</button>
  </div>
</div>

<script>
// ── DADOS ─────────────────────────────────────────────────────────────────────
const TIMES = <?= json_encode(array_values($TIMES)) ?>;

// ── DIFICULDADE ───────────────────────────────────────────────────────────────
function getDiff(tier, koBoost = 0) {
  const b = koBoost * 0.04;
  if (tier === 1) return { pSave: Math.min(.55, .42 + b), pScore: Math.min(.88, .78 + b) };
  if (tier === 2) return { pSave: Math.min(.40, .28 + b), pScore: Math.min(.75, .62 + b) };
  return             { pSave: Math.min(.28, .16 + b), pScore: Math.min(.62, .48 + b) };
}

// ── ESTADO ────────────────────────────────────────────────────────────────────
let state = null;
let selectedTeam = null;

// ── GOAL GEOMETRY ─────────────────────────────────────────────────────────────
// zonas: [x, y, w, h] dentro do SVG 320x130
const ZONE_RECTS = [
  [56, 18, 65, 36],  // 0 alto-esq
  [121,18, 75, 36],  // 1 alto-cen
  [196,18, 68, 36],  // 2 alto-dir
  [56, 54, 65, 36],  // 3 baixo-esq
  [121,54, 75, 36],  // 4 baixo-cen
  [196,54, 68, 36],  // 5 baixo-dir
];
function zoneCX(z){ const r=ZONE_RECTS[z]; return r[0]+r[2]/2; }
function zoneCY(z){ const r=ZONE_RECTS[z]; return r[1]+r[3]/2; }

// ── KEEPER POSITIONS ──────────────────────────────────────────────────────────
// translateX offset para animar o keeper dentro do goal SVG
const KEEPER_X = { 0:-80, 1:-15, 2:55, 3:-80, 4:-15, 5:55 };

// ── TEAM PICKER ───────────────────────────────────────────────────────────────
(function buildPicker() {
  [1,2,3].forEach(tier => {
    const grid = document.getElementById('tg-' + tier);
    TIMES.filter(t => t.tier === tier).forEach(t => {
      const d = document.createElement('div');
      d.className = 'team-card';
      d.dataset.name = t.name;
      d.innerHTML = `
        <div class="tc-badge" style="background:${t.color}22;color:${t.color};border:1px solid ${t.color}44">${t.badge}</div>
        <div class="tc-name">${t.name}</div>
        <div class="tc-diff">${tier===1?'★★★':tier===2?'★★☆':'★☆☆'}</div>`;
      d.onclick = () => selectTeam(t, d);
      grid.appendChild(d);
    });
  });
})();

function selectTeam(team, el) {
  document.querySelectorAll('.team-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  el.style.borderColor = team.color;
  selectedTeam = team;
  const btn = document.getElementById('btn-confirm-team');
  btn.disabled = false;
  btn.textContent = `⚽ Jogar com ${team.name}`;
}

function confirmTeam() {
  if (!selectedTeam) return;
  startGame(selectedTeam);
}

// ── INÍCIO ────────────────────────────────────────────────────────────────────
function startGame(userTeam) {
  state = {
    userTeam,
    phase: 'groups',
    groups: buildGroups(userTeam),
    gmIdx: 0,       // game index 0-2 nos grupos
    gmList: [],     // partidas do user nos grupos
    cur: null,      // partida corrente
    koRound: 0,
    koBracket: [],
  };
  state.gmList = buildUserMatches(state.groups);
  showScreen('sc-groups');
  renderGroups();
  simulateOtherGroupsLive();
}

// ── GRUPOS ────────────────────────────────────────────────────────────────────
function buildGroups(userTeam) {
  const pool = TIMES.filter(t => t.name !== userTeam.name);
  shuffle(pool);
  const picked = [userTeam, ...pool.slice(0, 15)];
  // Garante user na posição 0 do grupo A
  const groups = [];
  const letters = ['A','B','C','D'];
  for (let i = 0; i < 4; i++) {
    groups.push({ name: 'Grupo ' + letters[i], teams: picked.slice(i*4, i*4+4).map(t => ({...t, pts:0, gs:0, gc:0, played:0})) });
  }
  return groups;
}

function buildUserMatches(groups) {
  const g = groups[0];
  return g.teams.filter(t => t.name !== state.userTeam.name).map(opp => ({ opp, done: false }));
}

// ── SIMULAÇÃO AO VIVO DOS OUTROS GRUPOS ──────────────────────────────────────
let liveSimTimer = null;
const liveMessages = [];

function simulateOtherGroupsLive() {
  if (liveSimTimer) clearInterval(liveSimTimer);
  liveMessages.length = 0;

  // Coleta os confrontos que precisam ser simulados
  const confrontos = [];
  for (let gi = 1; gi < state.groups.length; gi++) {
    const g = state.groups[gi];
    for (let a = 0; a < g.teams.length; a++) {
      for (let b = a+1; b < g.teams.length; b++) {
        confrontos.push({ gi, a, b });
      }
    }
  }
  shuffle(confrontos);

  let idx = 0;
  liveSimTimer = setInterval(() => {
    if (idx >= confrontos.length || state.gmIdx >= 3) {
      clearInterval(liveSimTimer);
      return;
    }
    const { gi, a, b } = confrontos[idx++];
    const g = state.groups[gi];
    const ta = g.teams[a], tb = g.teams[b];
    const ga = Math.floor(Math.random()*4), gb = Math.floor(Math.random()*4);
    ta.gs += ga; ta.gc += gb; ta.played++;
    tb.gs += gb; tb.gc += ga; tb.played++;
    if (ga > gb) ta.pts += 3;
    else if (ga === gb) { ta.pts += 1; tb.pts += 1; }
    else tb.pts += 3;

    const feed = document.getElementById('live-feed');
    if (feed) {
      feed.innerHTML = `<span class="live-result">⚽ ${g.name}: <b>${ta.name}</b> ${ga} × ${gb} <b>${tb.name}</b></span>`;
    }
    renderGroups();
  }, 1800);
}

// ── RENDER TODOS OS GRUPOS ────────────────────────────────────────────────────
function renderGroups() {
  const wrap = document.getElementById('all-groups');
  if (!wrap) return;
  wrap.innerHTML = state.groups.map((g, gi) => {
    const sorted = [...g.teams].sort((a,b) => (b.pts-a.pts) || ((b.gs-b.gc)-(a.gs-a.gc)));
    const isUserGrp = gi === 0;
    const done = isUserGrp && state.gmIdx >= 3;
    return `<div class="group-card ${isUserGrp?'user-group':''}">
      <div class="group-hdr">
        <span>${g.name}${isUserGrp?' <span style="color:var(--amber);font-size:9px">SEU GRUPO</span>':''}</span>
        ${!done?'<div class="live-dot"></div>':''}
      </div>
      ${sorted.map((t,i) => {
        const isUser = t.name === state.userTeam.name;
        const saldo = t.gs - t.gc;
        const tag = done ? (i < 2 ? '<span class="classif q">Q</span>' : '<span class="classif e">E</span>') : '';
        return `<div class="g-team">
          <div class="g-dot" style="background:${t.color}"></div>
          <div class="g-name ${isUser?'user-team':''}">${t.badge} ${t.name}${tag}</div>
          <div class="g-saldo" style="color:${saldo>=0?'var(--green)':'var(--red)'}">${saldo>0?'+':''}${saldo}</div>
          <div class="g-pts">${t.pts}</div>
        </div>`;
      }).join('')}
    </div>`;
  }).join('');

  // Atualiza botão
  const btn = document.getElementById('btn-play-group');
  if (!btn) return;
  if (state.gmIdx >= 3) {
    btn.textContent = '📋 Ver Classificação Final & Avançar';
    btn.onclick = advanceFromGroups;
  } else {
    btn.textContent = `⚽ Jogar Jogo ${state.gmIdx+1} de 3`;
    btn.onclick = playNextGroupMatch;
  }
  document.getElementById('groups-round-label').textContent =
    state.gmIdx >= 3 ? 'Fase de grupos concluída' : `Jogo ${state.gmIdx+1} de 3`;
}

// ── PRÓXIMO JOGO DO GRUPO ─────────────────────────────────────────────────────
function playNextGroupMatch() {
  if (state.gmIdx >= 3) { advanceFromGroups(); return; }
  startMatch(state.gmList[state.gmIdx].opp, false);
}

// ── INICIAR PARTIDA ────────────────────────────────────────────────────────────
function startMatch(opp, isKO, koBoost = 0) {
  if (liveSimTimer) clearInterval(liveSimTimer);
  state.cur = {
    opp, isKO, koBoost,
    uGoals: 0, oGoals: 0,
    kickIdx: 0,   // 0-9: par=user chuta, ímpar=opp chuta
    dotsU: [], dotsO: [],
    locked: false,
    sd: false, sdPhase: 0, sdUserScored: null,
  };
  document.getElementById('penalty-log') && (document.getElementById('penalty-log').innerHTML = '');
  resetZoneBtns();
  updateMatchUI();
  initDots();
  showScreen('sc-match');
  prepKick();
}

// ── UI DA PARTIDA ──────────────────────────────────────────────────────────────
function updateMatchUI() {
  const { cur, userTeam } = state;
  document.getElementById('mt-user-name').textContent = userTeam.name;
  document.getElementById('mt-opp-name').textContent = cur.opp.name;
  document.getElementById('mt-user-badge').textContent = userTeam.badge;
  document.getElementById('mt-opp-badge').textContent = cur.opp.badge;
  document.getElementById('mt-score-u').textContent = cur.uGoals;
  document.getElementById('mt-score-o').textContent = cur.oGoals;
  document.getElementById('dots-lbl-u').textContent = userTeam.badge;
  document.getElementById('dots-lbl-o').textContent = cur.opp.badge;
}

function initDots() {
  ['dots-user','dots-opp'].forEach(id => {
    const el = document.getElementById(id);
    el.innerHTML = '';
    for (let i=0;i<5;i++) {
      const d = document.createElement('div');
      d.className = 'dot pending';
      d.id = id + '-' + i;
      el.appendChild(d);
    }
  });
}

function setDot(side, kickRound, result) {
  // side: 'user'|'opp', kickRound: 0-4
  const id = `dots-${side}-${kickRound}`;
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('pending');
  if (side === 'user') {
    el.className = result === 'goal' ? 'dot goal-u' : 'dot save-u';
    el.textContent = result === 'goal' ? '⚽' : '✕';
  } else {
    el.className = result === 'goal' ? 'dot goal-o' : 'dot save-o';
    el.textContent = result === 'goal' ? '⚽' : '🛡';
  }
}

function prepKick() {
  const m = state.cur;
  if (m.locked) return;
  const userShooting = m.kickIdx % 2 === 0;
  const round = Math.floor(m.kickIdx / 2) + 1;
  document.getElementById('mt-round').textContent = `Pênalti ${round}/5`;

  const tag = document.getElementById('phase-tag');
  if (m.sd) {
    tag.className = 'phase-tag sd';
    tag.textContent = m.sdPhase === 0 ? '💀 Morte Súbita — Chute!' : '💀 Morte Súbita — Defenda!';
  } else if (userShooting) {
    tag.className = 'phase-tag shoot';
    tag.textContent = `🦵 Você chuta (${round}/5)`;
  } else {
    tag.className = 'phase-tag defend';
    tag.textContent = `🧤 Você defende (${round}/5)`;
  }

  setStatus(m.sd ? 'Clique na zona!' : userShooting ? 'Escolha onde chutar' : 'Escolha a zona para defender', 'neutral');
  resetZoneBtns();
  document.getElementById('btn-next-kick').classList.add('hidden');
  moveKeeper(4); // centro
}

// ── CLICK NA ZONA ──────────────────────────────────────────────────────────────
function handleZone(zone) {
  const m = state.cur;
  if (m.locked) return;
  m.locked = true;

  if (m.sd) { handleSD(zone); return; }

  const userShooting = m.kickIdx % 2 === 0;
  const diff = getDiff(m.opp.tier, m.koBoost);
  const oppZone = Math.floor(Math.random() * 6);
  const kickRound = Math.floor(m.kickIdx / 2);

  if (userShooting) {
    const saved = (zone === oppZone) || (Math.random() < diff.pSave);
    const result = saved ? 'save' : 'goal';
    // Goleiro vai para onde a bola foi se defendeu, ou para zona errada se gol
    const keeperVZ = saved ? zone : oppZone;
    animateShoot(zone, keeperVZ, result, true);
    if (result === 'goal') { m.uGoals++; setStatus('⚽ GOL! Você marcou!', 'ok'); }
    else setStatus('🧤 Defendido! O goleiro pegou.', 'fail');
    setDot('user', kickRound, result);
    document.getElementById('mt-score-u').textContent = m.uGoals;
  } else {
    const scored = (zone !== oppZone) && (Math.random() < diff.pScore);
    const result = scored ? 'goal' : 'save';
    // Goleiro vai para onde a bola foi se defendeu, ou para zona errada se gol
    const keeperVZ = scored ? zone : oppZone;
    animateShoot(oppZone, keeperVZ, result, false);
    if (result === 'save') setStatus('🧤 Você defendeu! Ótima defesa!', 'ok');
    else { m.oGoals++; setStatus('😬 Gol do adversário!', 'fail'); }
    setDot('opp', kickRound, result);
    document.getElementById('mt-score-o').textContent = m.oGoals;
  }

  m.kickIdx++;
  if (m.kickIdx >= 10) {
    setTimeout(finishMatch, 1900);
  } else {
    const skipBtn = document.getElementById('btn-next-kick');
    skipBtn.classList.remove('hidden');
    const autoTimer = setTimeout(() => {
      skipBtn.classList.add('hidden');
      nextKick();
    }, 1900);
    skipBtn.onclick = () => { clearTimeout(autoTimer); skipBtn.classList.add('hidden'); nextKick(); };
  }
}

// ── ANIMAÇÕES ──────────────────────────────────────────────────────────────────
function animateShoot(ballZone, keeperZone, result, userKicking) {
  // Kicker
  const ksvg = document.getElementById('kicker-svg');
  ksvg.classList.add('kick-anim');
  setTimeout(() => ksvg.classList.remove('kick-anim'), 400);

  // Bola no chão some
  const kickBall = document.getElementById('kick-ball');
  if (kickBall) kickBall.setAttribute('opacity','0');

  // Keeper move
  setTimeout(() => moveKeeper(keeperZone), 150);

  // Highlight zona
  highlightZone(ballZone, result);

  // Botão da zona
  setTimeout(() => {
    const btn = document.querySelector(`.zone-btn[data-zone="${ballZone}"]`);
    if (btn) btn.classList.add(result === 'goal' ? 'hit-goal' : 'hit-save');
  }, 300);

  // Bola no SVG voa
  animBallSVG(ballZone, result);
}

function animBallSVG(zone, result) {
  const ball = document.getElementById('ball-g');
  if (!ball) return;
  const tx = zoneCX(zone), ty = zoneCY(zone);
  ball.setAttribute('cx', 160);
  ball.setAttribute('cy', 118);
  ball.setAttribute('r', 11);
  ball.setAttribute('opacity', '1');
  ball.setAttribute('fill', 'white');
  ball.setAttribute('stroke', '#333');
  let progress = 0;
  const anim = setInterval(() => {
    progress += 0.045;
    if (progress >= 1) { progress = 1; clearInterval(anim); }
    const ease = progress < 0.5 ? 2*progress*progress : -1+(4-2*progress)*progress;
    const x = 160 + (tx - 160) * ease;
    const y = 118 + (ty - 118) * ease;
    const r = 11 - ease * 5;
    ball.setAttribute('cx', x);
    ball.setAttribute('cy', y);
    ball.setAttribute('r', r);
    // Flash cor no final
    if (progress >= 1) ball.setAttribute('fill', result === 'goal' ? '#22c55e' : '#ef4444');
  }, 16);
  setTimeout(() => {
    ball.setAttribute('opacity','0');
    ball.setAttribute('cx', 160);
    ball.setAttribute('cy', 200);
    ball.setAttribute('fill', 'white');
    const kb = document.getElementById('kick-ball');
    if (kb) kb.setAttribute('opacity','1');
  }, 1400);
}

function moveKeeper(zone) {
  const kg = document.getElementById('keeper-g');
  if (!kg) return;
  const dx = KEEPER_X[zone] ?? 0;
  const dy = zone <= 2 ? -18 : 0;
  kg.style.transform = `translate(${dx}px, ${dy}px)`;
}

function highlightZone(zone, result) {
  const hl = document.getElementById('zone-hl');
  if (!hl) return;
  const r = ZONE_RECTS[zone];
  hl.setAttribute('x', r[0]); hl.setAttribute('y', r[1]);
  hl.setAttribute('width', r[2]); hl.setAttribute('height', r[3]);
  hl.setAttribute('fill', result === 'goal' ? 'rgba(34,197,94,.4)' : 'rgba(239,68,68,.35)');
  setTimeout(() => hl.setAttribute('width','0'), 1000);
}

function resetZoneBtns() {
  document.querySelectorAll('.zone-btn').forEach(b => b.classList.remove('hit-goal','hit-save'));
}

function setStatus(msg, cls) {
  const el = document.getElementById('status-bar');
  el.textContent = msg;
  el.className = 'status-bar s-' + cls;
}

function nextKick() {
  state.cur.locked = false;
  document.getElementById('btn-next-kick').classList.add('hidden');
  resetZoneBtns();
  highlightZone(0, 'save'); // limpa
  document.getElementById('zone-hl') && document.getElementById('zone-hl').setAttribute('width','0');
  moveKeeper(4);
  // Bola no chão volta
  const kb = document.getElementById('kick-ball');
  if (kb) kb.setAttribute('opacity','1');
  prepKick();
}

// ── FINALIZAR PARTIDA ──────────────────────────────────────────────────────────
function finishMatch() {
  const m = state.cur;
  const win = m.uGoals > m.oGoals, draw = m.uGoals === m.oGoals;

  if (m.isKO && draw) { startSD(); return; }

  if (!m.isKO) {
    updateGroupStandings(m.opp, m.uGoals, m.oGoals);
    state.gmIdx++;
  }
  showResult(win, draw);
}

// ── MORTE SÚBITA ──────────────────────────────────────────────────────────────
function startSD() {
  const m = state.cur;
  m.sd = true; m.sdPhase = 0; m.sdUserScored = null; m.locked = false;
  initDots();
  resetZoneBtns();
  moveKeeper(4);
  document.getElementById('mt-round').textContent = 'Morte Súbita!';
  prepKick();
}

function handleSD(zone) {
  const m = state.cur;
  const diff = getDiff(m.opp.tier, m.koBoost);
  const oppZone = Math.floor(Math.random() * 6);

  if (m.sdPhase === 0) {
    // User chuta
    const saved = (zone === oppZone) || (Math.random() < diff.pSave);
    m.sdUserScored = !saved;
    const keeperVZ0 = saved ? zone : oppZone;
    animateShoot(zone, keeperVZ0, saved ? 'save' : 'goal', true);
    if (m.sdUserScored) setStatus('⚽ Gol! Agora defenda para vencer!', 'ok');
    else setStatus('🧤 Defendido! Defenda para não perder!', 'fail');
    m.sdPhase = 1;
    setTimeout(() => {
      resetZoneBtns();
      moveKeeper(4);
      prepKick();
      m.locked = false;
    }, 900);
  } else {
    // User defende
    const scored = (zone !== oppZone) && (Math.random() < diff.pScore * 0.9);
    const keeperVZ1 = scored ? zone : oppZone;
    animateShoot(oppZone, keeperVZ1, scored ? 'goal' : 'save', false);
    const userWins = m.sdUserScored && !scored;
    const userLoses = !m.sdUserScored && scored;
    if (!scored) setStatus('🧤 Você defendeu!', 'ok');
    else setStatus('😬 Gol do adversário!', 'fail');
    setTimeout(() => {
      if (userWins) { m.uGoals++; showResult(true, false); }
      else if (userLoses) { m.oGoals++; showResult(false, false); }
      else {
        // Nova rodada morte súbita
        m.sdPhase = 0; m.sdUserScored = null; m.locked = false;
        resetZoneBtns(); moveKeeper(4); prepKick();
        setStatus('Continua! Nova rodada.', 'neutral');
      }
    }, 900);
  }
}

// ── STANDINGS DOS GRUPOS ───────────────────────────────────────────────────────
function updateGroupStandings(opp, uG, oG) {
  const g = state.groups[0];
  const u = g.teams.find(t => t.name === state.userTeam.name);
  const o = g.teams.find(t => t.name === opp.name);
  u.gs += uG; u.gc += oG; u.played++;
  o.gs += oG; o.gc += uG; o.played++;
  if (uG > oG) u.pts += 3;
  else if (uG === oG) { u.pts += 1; o.pts += 1; }
  else o.pts += 3;

  // Simula jogo entre os outros 2 do grupo
  const rest = g.teams.filter(t => t.name !== state.userTeam.name && t.name !== opp.name);
  if (rest.length >= 2) {
    const ga = Math.floor(Math.random()*4), gb = Math.floor(Math.random()*4);
    rest[0].gs += ga; rest[0].gc += gb; rest[0].played++;
    rest[1].gs += gb; rest[1].gc += ga; rest[1].played++;
    if (ga > gb) rest[0].pts += 3;
    else if (ga === gb) { rest[0].pts += 1; rest[1].pts += 1; }
    else rest[1].pts += 3;
  }
}

// ── RESULTADO DA PARTIDA ───────────────────────────────────────────────────────
function showResult(win, draw) {
  const m = state.cur;
  document.getElementById('res-icon').textContent = win ? '🏅' : draw ? '🤝' : '😓';
  document.getElementById('res-title').textContent = win ? 'Vitória!' : draw ? 'Empate!' : (m.isKO ? 'Eliminado!' : 'Derrota!');
  document.getElementById('res-title').style.color = win ? 'var(--green)' : draw ? 'var(--amber)' : (m.isKO ? 'var(--red)' : 'var(--amber)');
  document.getElementById('res-score').textContent = `${m.uGoals} × ${m.oGoals}`;
  document.getElementById('res-teams').textContent = `${state.userTeam.name} vs ${m.opp.name}`;
  document.getElementById('res-sub').textContent = win
    ? (m.isKO ? `${state.userTeam.name} avança para a próxima fase!` : 'Vitória na fase de grupos!')
    : draw ? 'Empate — 1 ponto cada.' : (m.isKO ? 'Você foi eliminado da copa.' : 'Derrota, mas ainda há jogos!');

  const btn = document.getElementById('btn-res-continue');
  if (m.isKO && !win) {
    btn.textContent = 'Voltar ao Início';
    btn.onclick = () => location.reload();
  } else {
    btn.textContent = 'Continuar ›';
    btn.onclick = afterMatch;
  }
  showScreen('sc-result');
}

function afterMatch() {
  if (state.cur.isKO) { advanceKO(); return; }
  showScreen('sc-groups');
  renderGroups();
  simulateOtherGroupsLive();
}

// ── AVANÇAR DOS GRUPOS ─────────────────────────────────────────────────────────
function advanceFromGroups() {
  if (liveSimTimer) clearInterval(liveSimTimer);
  const g = state.groups[0];
  const sorted = [...g.teams].sort((a,b) => (b.pts-a.pts)||((b.gs-b.gc)-(a.gs-a.gc)));
  const userPos = sorted.findIndex(t => t.name === state.userTeam.name);
  if (userPos >= 2) {
    document.getElementById('elim-msg').textContent =
      `${state.userTeam.name} terminou em ${userPos+1}º no Grupo A e não avançou.`;
    document.getElementById('ov-elim').classList.remove('hidden');
    return;
  }
  // Monta bracket: 2 classificados de cada grupo
  state.koBracket = [];
  state.groups.forEach(grp => {
    const s = [...grp.teams].sort((a,b) => (b.pts-a.pts)||((b.gs-b.gc)-(a.gs-a.gc)));
    state.koBracket.push(s[0], s[1]);
  });
  state.koRound = 0;
  state.phase = 'knockout';
  showKO();
}

// ── MATA-MATA ──────────────────────────────────────────────────────────────────
const KO_NAMES = ['Oitavas de Final','Quartas de Final','Semifinal','Final'];

function showKO() {
  const r = state.koRound;
  document.getElementById('ko-phase-hdr').textContent = KO_NAMES[r] ?? 'Final';
  document.getElementById('ko-phase-sub').textContent = 'Mata-Mata · Eliminação direta · Empate = Morte Súbita';
  document.getElementById('hdr-phase').textContent = KO_NAMES[r] ?? 'Final';

  const bracket = state.koBracket;
  const userIdx = bracket.findIndex(t => t && t.name === state.userTeam.name);
  const pairIdx = userIdx % 2 === 0 ? userIdx + 1 : userIdx - 1;
  const userOpp = bracket[pairIdx];

  let html = '';
  for (let i = 0; i < bracket.length; i += 2) {
    const t1 = bracket[i], t2 = bracket[i+1];
    if (!t1 || !t2) continue;
    const isUserMatch = t1.name === state.userTeam.name || t2.name === state.userTeam.name;
    html += `<div class="ko-match ${isUserMatch?'user-match':''}">
      ${isUserMatch?`<div style="font-size:10px;color:var(--amber);padding:6px 12px 0;font-weight:700">⭐ SEU JOGO</div>`:''}
      <div class="ko-team-row">
        <div class="ko-badge" style="background:${t1.color}22;color:${t1.color}">${t1.badge}</div>
        <div class="ko-name ${t1.name===state.userTeam.name?'':''}">
          ${t1.name} ${t1.name===state.userTeam.name?'<span class="user-lbl">VOCÊ</span>':''}
        </div>
        <div class="ko-tier">Tier ${t1.tier}</div>
      </div>
      <div class="ko-team-row">
        <div class="ko-badge" style="background:${t2.color}22;color:${t2.color}">${t2.badge}</div>
        <div class="ko-name">
          ${t2.name} ${t2.name===state.userTeam.name?'<span class="user-lbl">VOCÊ</span>':''}
        </div>
        <div class="ko-tier">Tier ${t2.tier}</div>
      </div>
    </div>`;
  }
  document.getElementById('ko-bracket').innerHTML = html;

  const btn = document.getElementById('btn-ko-play');
  btn.textContent = `⚽ Jogar vs ${userOpp?.name ?? '?'}`;
  showScreen('sc-ko');
}

function playKO() {
  const bracket = state.koBracket;
  const userIdx = bracket.findIndex(t => t && t.name === state.userTeam.name);
  const pairIdx = userIdx % 2 === 0 ? userIdx + 1 : userIdx - 1;
  startMatch(bracket[pairIdx], true, state.koRound + 1);
}

function advanceKO() {
  const m = state.cur;
  const win = m.uGoals > m.oGoals;
  if (!win) { location.reload(); return; }

  const bracket = state.koBracket;
  const winners = [];
  for (let i = 0; i < bracket.length; i += 2) {
    const t1 = bracket[i], t2 = bracket[i+1];
    if (!t1 || !t2) { winners.push(t1 ?? t2); continue; }
    if (t1.name === state.userTeam.name) { winners.push(state.userTeam); continue; }
    if (t2.name === state.userTeam.name) { winners.push(state.userTeam); continue; }
    const w1 = 4 - t1.tier, w2 = 4 - t2.tier;
    winners.push(Math.random() < w1/(w1+w2) ? t1 : t2);
  }

  state.koBracket = winners;
  state.koRound++;

  if (winners.length === 1) {
    document.getElementById('champ-name').textContent = state.userTeam.name;
    showScreen('sc-champ');
    return;
  }
  showKO();
}

// ── UTILS ──────────────────────────────────────────────────────────────────────
function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  window.scrollTo(0, 0);
}

function shuffle(arr) {
  for (let i = arr.length-1; i > 0; i--) {
    const j = Math.floor(Math.random()*(i+1));
    [arr[i],arr[j]] = [arr[j],arr[i]];
  }
  return arr;
}
</script>
</body>
</html>
