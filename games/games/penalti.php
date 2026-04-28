<?php
// penalti.php — Copa do Brasil de Pênaltis
if (session_status() === PHP_SESSION_NONE) session_start();
require '../core/conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = (int)$_SESSION['user_id'];

// ── DADOS DOS TIMES ───────────────────────────────────────────────────────────
$TIMES = [
  // tier 1 — dificuldade alta
  ['name'=>'Flamengo',          'tier'=>1,'badge'=>'FLA','color'=>'#cc0000'],
  ['name'=>'Corinthians',       'tier'=>1,'badge'=>'COR','color'=>'#1a1a1a'],
  ['name'=>'Palmeiras',         'tier'=>1,'badge'=>'PAL','color'=>'#006b3c'],
  ['name'=>'São Paulo',         'tier'=>1,'badge'=>'SPF','color'=>'#cc0000'],
  ['name'=>'Santos',            'tier'=>1,'badge'=>'SAN','color'=>'#1a1a1a'],
  ['name'=>'Vasco da Gama',     'tier'=>1,'badge'=>'VAS','color'=>'#1a1a1a'],
  ['name'=>'Fluminense',        'tier'=>1,'badge'=>'FLU','color'=>'#8b0000'],
  ['name'=>'Botafogo',          'tier'=>1,'badge'=>'BOT','color'=>'#1a1a1a'],
  ['name'=>'Atlético Mineiro',  'tier'=>1,'badge'=>'CAM','color'=>'#1a1a1a'],
  ['name'=>'Cruzeiro',          'tier'=>1,'badge'=>'CRU','color'=>'#1a3a8f'],
  ['name'=>'Grêmio',            'tier'=>1,'badge'=>'GRE','color'=>'#1a3a8f'],
  // tier 2 — dificuldade média
  ['name'=>'Internacional',          'tier'=>2,'badge'=>'INT','color'=>'#cc0000'],
  ['name'=>'Athletico Paranaense',   'tier'=>2,'badge'=>'CAP','color'=>'#cc0000'],
  ['name'=>'Coritiba',               'tier'=>2,'badge'=>'COT','color'=>'#1a6b2c'],
  ['name'=>'Bahia',                  'tier'=>2,'badge'=>'BAH','color'=>'#1a3a8f'],
  ['name'=>'Vitória',                'tier'=>2,'badge'=>'VIT','color'=>'#cc0000'],
  ['name'=>'Sport Recife',           'tier'=>2,'badge'=>'SPT','color'=>'#cc0000'],
  ['name'=>'Náutico',                'tier'=>2,'badge'=>'NAU','color'=>'#cc0000'],
  ['name'=>'Santa Cruz',             'tier'=>2,'badge'=>'SCR','color'=>'#cc0000'],
  ['name'=>'Ceará',                  'tier'=>2,'badge'=>'CEA','color'=>'#1a1a1a'],
  ['name'=>'Fortaleza',              'tier'=>2,'badge'=>'FOR','color'=>'#1a3a8f'],
  ['name'=>'Goiás',                  'tier'=>2,'badge'=>'GOI','color'=>'#1a6b2c'],
  ['name'=>'Atlético Goianiense',    'tier'=>2,'badge'=>'ACG','color'=>'#cc0000'],
  ['name'=>'Guarani',                'tier'=>2,'badge'=>'GUA','color'=>'#006b3c'],
  ['name'=>'Ponte Preta',            'tier'=>2,'badge'=>'PON','color'=>'#1a1a1a'],
  ['name'=>'Portuguesa',             'tier'=>2,'badge'=>'POR','color'=>'#cc0000'],
  ['name'=>'América Mineiro',        'tier'=>2,'badge'=>'AME','color'=>'#006b3c'],
  ['name'=>'Chapecoense',            'tier'=>2,'badge'=>'CHA','color'=>'#006b3c'],
  ['name'=>'Avaí',                   'tier'=>2,'badge'=>'AVA','color'=>'#1a3a8f'],
  ['name'=>'Figueirense',            'tier'=>2,'badge'=>'FIG','color'=>'#1a1a1a'],
  ['name'=>'Paraná Clube',           'tier'=>2,'badge'=>'PAR','color'=>'#1a3a8f'],
  ['name'=>'Juventude',              'tier'=>2,'badge'=>'JUV','color'=>'#006b3c'],
  // tier 3 — dificuldade baixa
  ['name'=>'Remo',              'tier'=>3,'badge'=>'REM','color'=>'#1a3a8f'],
  ['name'=>'Paysandu',          'tier'=>3,'badge'=>'PAY','color'=>'#1a3a8f'],
  ['name'=>'CSA',               'tier'=>3,'badge'=>'CSA','color'=>'#1a3a8f'],
  ['name'=>'CRB',               'tier'=>3,'badge'=>'CRB','color'=>'#cc0000'],
  ['name'=>'ABC',               'tier'=>3,'badge'=>'ABC','color'=>'#cc0000'],
  ['name'=>'América RN',        'tier'=>3,'badge'=>'AMR','color'=>'#cc0000'],
  ['name'=>'Sampaio Corrêa',    'tier'=>3,'badge'=>'SAM','color'=>'#1a3a8f'],
  ['name'=>'Moto Club',         'tier'=>3,'badge'=>'MOT','color'=>'#1a3a8f'],
  ['name'=>'Treze',             'tier'=>3,'badge'=>'TRE','color'=>'#1a1a1a'],
  ['name'=>'Campinense',        'tier'=>3,'badge'=>'CAM','color'=>'#cc0000'],
  ['name'=>'Botafogo-PB',       'tier'=>3,'badge'=>'BPB','color'=>'#1a1a1a'],
  ['name'=>'Confiança',         'tier'=>3,'badge'=>'CON','color'=>'#1a3a8f'],
  ['name'=>'Sergipe',           'tier'=>3,'badge'=>'SER','color'=>'#cc0000'],
  ['name'=>'Joinville',         'tier'=>3,'badge'=>'JOI','color'=>'#1a1a1a'],
  ['name'=>'Criciúma',          'tier'=>3,'badge'=>'CRI','color'=>'#f5a800'],
  ['name'=>'Operário Ferroviário','tier'=>3,'badge'=>'OPE','color'=>'#1a1a1a'],
  ['name'=>'Londrina',          'tier'=>3,'badge'=>'LON','color'=>'#cc0000'],
  ['name'=>'Vila Nova',         'tier'=>3,'badge'=>'VNO','color'=>'#cc0000'],
  ['name'=>'Brasil de Pelotas', 'tier'=>3,'badge'=>'BPE','color'=>'#006b3c'],
  ['name'=>'Caxias',            'tier'=>3,'badge'=>'CAX','color'=>'#cc0000'],
  ['name'=>'Ypiranga-RS',       'tier'=>3,'badge'=>'YPI','color'=>'#f5a800'],
  ['name'=>'São Bento',         'tier'=>3,'badge'=>'SBE','color'=>'#1a3a8f'],
  ['name'=>'Ferroviária',       'tier'=>3,'badge'=>'FER','color'=>'#006b3c'],
  ['name'=>'XV de Piracicaba',  'tier'=>3,'badge'=>'XVP','color'=>'#006b3c'],
  ['name'=>'Bangu',             'tier'=>3,'badge'=>'BAN','color'=>'#1a1a1a'],
  ['name'=>'Madureira',         'tier'=>3,'badge'=>'MAD','color'=>'#f5a800'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
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
body{background:var(--bg);color:var(--text);font-family:system-ui,sans-serif;min-height:100vh;overflow-x:hidden}

/* ── HEADER ── */
.header{background:linear-gradient(135deg,#1a2a5e,#0d1a3a);padding:14px 18px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border)}
.header-logo{font-size:22px}
.header-title{font-size:16px;font-weight:700;color:#fff}
.header-sub{font-size:11px;color:var(--text3)}
.btn-back{margin-left:auto;background:rgba(255,255,255,.07);border:1px solid var(--border);color:var(--text2);padding:6px 14px;border-radius:8px;font-size:12px;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:6px}
.btn-back:hover{background:rgba(255,255,255,.12);color:#fff}

/* ── SCREENS ── */
.screen{display:none;padding:20px 16px;max-width:520px;margin:0 auto}
.screen.active{display:block}

/* ── CARDS ── */
.card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:14px}
.card-title{font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;display:flex;align-items:center;gap:8px}

/* ── BOTÕES ── */
.btn{border:none;border-radius:10px;padding:12px 20px;font-size:14px;font-weight:700;cursor:pointer;transition:.15s;width:100%}
.btn-green{background:#16a34a;color:#fff}
.btn-green:hover{background:#15803d}
.btn-red{background:#dc2626;color:#fff}
.btn-red:hover{background:#b91c1c}
.btn-blue{background:#2563eb;color:#fff}
.btn-blue:hover{background:#1d4ed8}
.btn-outline{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text)}
.btn-outline:hover{background:rgba(255,255,255,.1)}

/* ── TIME BADGE ── */
.team-badge{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px;font-size:10px;font-weight:800;letter-spacing:.5px}
.team-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)}
.team-row:last-child{border-bottom:none}
.team-name{flex:1;font-size:13px;font-weight:600}
.team-pts{font-size:13px;font-weight:700;color:var(--amber);min-width:24px;text-align:center}
.team-saldo{font-size:11px;color:var(--text3);min-width:30px;text-align:center}

/* ── GRUPO ── */
.group-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
.group-card{background:var(--panel2);border:1px solid var(--border);border-radius:12px;padding:12px}
.group-label{font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px}
.stage-badge{display:inline-block;background:linear-gradient(135deg,#b8860b,#ffd700);color:#000;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800;margin-bottom:14px}

/* ── CAMPO DE PÊNALTI ── */
.field-wrap{position:relative;width:100%;max-width:400px;margin:0 auto}
.field-svg{width:100%;display:block}

/* Zonas de chute */
.shoot-zone-grid{display:grid;grid-template-columns:1fr 1fr 1fr;grid-template-rows:1fr 1fr;gap:4px;margin:12px auto;max-width:300px}
.sz{background:rgba(255,255,255,.05);border:2px solid rgba(255,255,255,.15);border-radius:8px;height:60px;cursor:pointer;transition:.15s;display:flex;align-items:center;justify-content:center;font-size:22px;position:relative;overflow:hidden}
.sz:hover{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.35);transform:scale(1.03)}
.sz.selected{border-color:var(--amber);background:rgba(245,158,11,.15)}
.sz.goal{border-color:var(--green)!important;background:rgba(34,197,94,.2)!important}
.sz.saved{border-color:var(--red)!important;background:rgba(239,68,68,.2)!important}

.arrow-label{font-size:10px;color:var(--text3);text-align:center;margin-top:2px;pointer-events:none}

/* ── GOLEIRO ── */
.keeper-wrap{position:relative;margin:0 auto;max-width:300px;height:70px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:8px}
.keeper-icon{position:absolute;font-size:32px;top:50%;transform:translateY(-50%);transition:left .25s ease}

/* ── PLACAR ── */
.score-board{background:var(--panel3);border:1px solid var(--border2);border-radius:14px;padding:14px 18px;text-align:center;margin-bottom:14px}
.score-teams{display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:4px}
.score-team-name{font-size:12px;color:var(--text2);font-weight:600}
.score-goals{font-size:36px;font-weight:800;color:#fff;letter-spacing:2px;font-variant-numeric:tabular-nums}
.score-goals span{color:var(--text3);font-size:24px;margin:0 8px}
.score-info{font-size:11px;color:var(--text3);margin-top:4px}

/* ── RESULTADO ── */
.result-overlay{position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px}
.result-box{background:var(--panel);border:1px solid var(--border2);border-radius:18px;padding:28px 24px;max-width:380px;width:100%;text-align:center}
.result-icon{font-size:52px;margin-bottom:10px}
.result-title{font-size:22px;font-weight:800;margin-bottom:6px}
.result-sub{font-size:13px;color:var(--text2);margin-bottom:18px}

/* ── ANIMAÇÃO ── */
@keyframes ballFly{from{transform:translate(0,0) scale(1)}to{transform:translate(var(--dx),var(--dy)) scale(.5)}}
@keyframes bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
@keyframes shake{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-6px)}40%,80%{transform:translateX(6px)}}
@keyframes flash{0%,100%{opacity:1}50%{opacity:.3}}
.anim-bounce{animation:bounce .4s ease}
.anim-shake{animation:shake .4s ease}

.phase-title{font-size:18px;font-weight:800;color:#fff;margin-bottom:4px}
.phase-sub{font-size:12px;color:var(--text3);margin-bottom:16px}

.match-info{display:flex;align-items:center;justify-content:space-between;background:var(--panel3);border-radius:12px;padding:12px 16px;margin-bottom:12px}
.match-team{text-align:center;flex:1}
.match-team-name{font-size:12px;color:var(--text2);font-weight:700}
.match-vs{font-size:16px;font-weight:800;color:var(--text3)}

.kick-panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:12px}
.kick-title{font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.7px;margin-bottom:10px;text-align:center}

.penalty-log{max-height:90px;overflow-y:auto;display:flex;flex-wrap:wrap;gap:4px;justify-content:center;margin-bottom:10px}
.log-dot{width:26px;height:26px;border-radius:50%;border:2px solid rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:13px}

.status-msg{text-align:center;font-size:14px;font-weight:700;min-height:22px;margin-bottom:8px;transition:.2s}
.status-msg.ok{color:var(--green)}
.status-msg.fail{color:var(--red)}
.status-msg.neutral{color:var(--amber)}

.instructions{font-size:11px;color:var(--text3);text-align:center;margin-top:6px}

/* Tier visual */
.tier1{border-left:3px solid #ffd700}
.tier2{border-left:3px solid #aaa}
.tier3{border-left:3px solid #cd7f32}

.danger-bar{height:4px;border-radius:2px;background:linear-gradient(90deg,var(--green),var(--amber),var(--red));margin:8px 0}

.classif-tag{font-size:10px;padding:2px 7px;border-radius:10px;font-weight:700}
.classif-tag.q{background:rgba(34,197,94,.2);color:var(--green)}
.classif-tag.e{background:rgba(239,68,68,.15);color:var(--red)}

.hidden{display:none!important}
</style>
</head>
<body>
<div class="header">
  <div class="header-logo">⚽</div>
  <div>
    <div class="header-title">Copa Pênaltis</div>
    <div class="header-sub">Fase de Grupos · Mata-Mata</div>
  </div>
  <a href="../games.php" class="btn-back"><i class="bi bi-arrow-left"></i> Sair</a>
</div>

<!-- TELA 1: INÍCIO -->
<div class="screen active" id="sc-start">
  <div style="text-align:center;padding:24px 0 16px">
    <div style="font-size:56px;margin-bottom:10px">🏆</div>
    <div style="font-size:22px;font-weight:800;color:#fff;margin-bottom:6px">Copa Pênaltis</div>
    <div style="font-size:13px;color:var(--text2);margin-bottom:6px">Fase de grupos + Mata-Mata</div>
    <div style="font-size:11px;color:var(--text3)">Chute e defenda pênaltis para avançar</div>
  </div>

  <div class="card">
    <div class="card-title"><i class="bi bi-info-circle"></i> Como funciona</div>
    <div style="font-size:12px;color:var(--text2);line-height:1.8">
      <div>⚽ <b>Fase de Grupos</b> — 3 jogos, 4 times por grupo</div>
      <div>🏅 <b>Classificação</b> — Passam os 2 melhores (pts + saldo)</div>
      <div>⚔️ <b>Mata-Mata</b> — Oitavas, Quartas, Semi, Final</div>
      <div>❌ <b>Eliminado</b> — Volta à tela inicial</div>
    </div>
  </div>

  <div class="card">
    <div class="card-title"><i class="bi bi-controller"></i> Controles</div>
    <div style="font-size:12px;color:var(--text2);line-height:1.8">
      <div>🦵 <b>Para chutar</b> — escolha uma das 6 zonas do gol</div>
      <div>🧤 <b>Para defender</b> — escolha a zona que o adversário vai chutar</div>
      <div>🎯 Cada partida: 5 pênaltis cada lado</div>
    </div>
  </div>

  <button class="btn btn-green" onclick="startGame()">
    <i class="bi bi-play-fill"></i> Começar Copa
  </button>
</div>

<!-- TELA 2: GRUPOS -->
<div class="screen" id="sc-groups">
  <div style="margin-bottom:14px">
    <div class="phase-title">Fase de Grupos</div>
    <div class="phase-sub">4 grupos · Passam os 2 melhores de cada</div>
  </div>
  <div id="groups-view"></div>
  <button class="btn btn-blue" id="btn-play-match" onclick="playNextMatch()" style="margin-top:6px">
    <i class="bi bi-play-fill"></i> Jogar Próximo Jogo
  </button>
</div>

<!-- TELA 3: PARTIDA (pênaltis) -->
<div class="screen" id="sc-match">
  <div class="match-info">
    <div class="match-team">
      <div class="match-team-name" id="m-team-user">Seu Time</div>
      <div style="font-size:10px;color:var(--text3)" id="m-badge-user"></div>
    </div>
    <div class="match-vs">VS</div>
    <div class="match-team">
      <div class="match-team-name" id="m-team-opp">Adversário</div>
      <div style="font-size:10px;color:var(--text3)" id="m-badge-opp"></div>
    </div>
  </div>

  <div class="score-board">
    <div class="score-teams">
      <div class="score-team-name" id="sb-user"></div>
      <div class="score-goals"><span id="sb-score-user">0</span><span>×</span><span id="sb-score-opp">0</span></div>
      <div class="score-team-name" id="sb-opp"></div>
    </div>
    <div class="score-info" id="sb-info">Pênalti 1 de 5</div>
  </div>

  <!-- Log visual -->
  <div class="kick-panel">
    <div class="kick-title" id="kick-round-label">Rodada de Chutes</div>
    <div class="penalty-log" id="penalty-log"></div>
    <div class="status-msg neutral" id="status-msg">Escolha uma zona para chutar</div>

    <!-- Gol SVG com goleiro animado -->
    <div style="position:relative;max-width:320px;margin:0 auto 8px">
      <svg viewBox="0 0 320 110" xmlns="http://www.w3.org/2000/svg" style="width:100%;display:block">
        <!-- Grama -->
        <rect width="320" height="110" fill="#1a3a1a"/>
        <!-- Área grande -->
        <rect x="60" y="60" width="200" height="50" fill="none" stroke="#fff" stroke-width="1.5" opacity=".4"/>
        <!-- Área pequena -->
        <rect x="110" y="80" width="100" height="30" fill="none" stroke="#fff" stroke-width="1.5" opacity=".3"/>
        <!-- Bola de pênalti -->
        <circle cx="160" cy="95" r="5" fill="#fff" opacity=".6"/>
        <!-- Poste esq -->
        <rect x="55" y="10" width="6" height="70" fill="#ccc" rx="2"/>
        <!-- Poste dir -->
        <rect x="259" y="10" width="6" height="70" fill="#ccc" rx="2"/>
        <!-- Travessão -->
        <rect x="55" y="10" width="210" height="6" fill="#ccc" rx="2"/>
        <!-- Fundo do gol (rede) -->
        <rect x="61" y="16" width="198" height="64" fill="rgba(255,255,255,.04)" stroke="rgba(255,255,255,.1)" stroke-width=".5"/>
        <!-- Divisórias de zona (linhas guia) -->
        <line x1="127" y1="16" x2="127" y2="80" stroke="rgba(255,255,255,.12)" stroke-width="1" stroke-dasharray="4,3"/>
        <line x1="193" y1="16" x2="193" y2="80" stroke="rgba(255,255,255,.12)" stroke-width="1" stroke-dasharray="4,3"/>
        <line x1="61"  y1="48" x2="259" y2="48" stroke="rgba(255,255,255,.12)" stroke-width="1" stroke-dasharray="4,3"/>
        <!-- Zona highlight (atualizado por JS) -->
        <rect id="zone-highlight" x="0" y="0" width="0" height="0" fill="rgba(245,158,11,.25)" rx="2"/>
        <!-- Goleiro -->
        <text id="keeper-svg" x="160" y="72" font-size="28" text-anchor="middle" dominant-baseline="middle" style="transition:x .22s ease,y .22s ease">🧤</text>
        <!-- Bola animada -->
        <circle id="ball-svg" cx="160" cy="95" r="7" fill="#fff" opacity="0"/>
      </svg>
    </div>

    <!-- Grid de zonas clicáveis -->
    <div class="shoot-zone-grid" id="shoot-zones">
      <div class="sz" data-zone="0" onclick="handleZoneClick(0)"><div class="arrow-label">↖ Alto Esq</div></div>
      <div class="sz" data-zone="1" onclick="handleZoneClick(1)"><div class="arrow-label">↑ Alto Cen</div></div>
      <div class="sz" data-zone="2" onclick="handleZoneClick(2)"><div class="arrow-label">↗ Alto Dir</div></div>
      <div class="sz" data-zone="3" onclick="handleZoneClick(3)"><div class="arrow-label">↙ Baixo Esq</div></div>
      <div class="sz" data-zone="4" onclick="handleZoneClick(4)"><div class="arrow-label">↓ Baixo Cen</div></div>
      <div class="sz" data-zone="5" onclick="handleZoneClick(5)"><div class="arrow-label">↘ Baixo Dir</div></div>
    </div>
    <div class="instructions" id="kick-instructions">Clique na zona onde quer chutar</div>
  </div>

  <button class="btn btn-outline hidden" id="btn-next-kick" onclick="nextKick()">
    Próximo ›
  </button>
</div>

<!-- TELA 4: RESULTADO DA PARTIDA -->
<div class="screen" id="sc-match-result">
  <div style="text-align:center;padding:16px 0">
    <div style="font-size:48px;margin-bottom:8px" id="mr-icon">🏅</div>
    <div style="font-size:20px;font-weight:800;margin-bottom:4px" id="mr-title">Vitória!</div>
    <div style="font-size:13px;color:var(--text2);margin-bottom:16px" id="mr-sub"></div>
  </div>
  <div class="score-board">
    <div class="score-teams">
      <div class="score-team-name" id="mr-team-user"></div>
      <div class="score-goals">
        <span id="mr-score-user">0</span><span>×</span><span id="mr-score-opp">0</span>
      </div>
      <div class="score-team-name" id="mr-team-opp"></div>
    </div>
  </div>
  <div id="mr-group-state"></div>
  <button class="btn btn-blue" id="btn-mr-continue" onclick="afterMatch()">
    Continuar ›
  </button>
</div>

<!-- TELA 5: MATA-MATA -->
<div class="screen" id="sc-knockout">
  <div style="margin-bottom:14px">
    <div class="phase-title" id="ko-phase-title">Oitavas de Final</div>
    <div class="phase-sub" id="ko-phase-sub">Eliminação direta · Perde e vai pra casa</div>
  </div>
  <div id="ko-bracket"></div>
  <button class="btn btn-blue" id="btn-ko-play" onclick="playKnockout()">
    <i class="bi bi-play-fill"></i> Jogar
  </button>
</div>

<!-- TELA 6: CAMPEÃO -->
<div class="screen" id="sc-champion">
  <div style="text-align:center;padding:30px 0">
    <div style="font-size:64px;margin-bottom:12px">🏆</div>
    <div style="font-size:24px;font-weight:800;color:var(--gold);margin-bottom:8px">CAMPEÃO!</div>
    <div style="font-size:16px;color:#fff;font-weight:700;margin-bottom:6px" id="champ-name"></div>
    <div style="font-size:12px;color:var(--text3);margin-bottom:24px">Você venceu a Copa Pênaltis!</div>
    <button class="btn btn-green" onclick="resetGame()" style="max-width:240px;margin:0 auto">
      <i class="bi bi-arrow-clockwise"></i> Jogar Novamente
    </button>
  </div>
</div>

<!-- OVERLAY ELIMINADO -->
<div class="result-overlay hidden" id="overlay-eliminated">
  <div class="result-box">
    <div class="result-icon">😔</div>
    <div class="result-title" style="color:var(--red)">Eliminado!</div>
    <div class="result-sub" id="elim-msg">Você foi eliminado da copa.</div>
    <button class="btn btn-red" onclick="resetGame()">Voltar ao Início</button>
  </div>
</div>

<script>
// ── TIMES DATA ──────────────────────────────────────────────────────────────
const TIMES = <?= json_encode(array_values($TIMES)) ?>;

// ── DIFICULDADE POR TIER ─────────────────────────────────────────────────────
// pSave = chance do goleiro adversário defender (atacando)
// pScore = chance do adversário marcar quando chuta (defendendo)
function getDifficulty(tier, knockoutRound) {
  // knockout amplifica dificuldade
  const boost = knockoutRound ? knockoutRound * 0.04 : 0;
  if (tier === 1) return { pSave: 0.42 + boost, pScore: 0.78 + boost };
  if (tier === 2) return { pSave: 0.30 + boost, pScore: 0.65 + boost };
  return           { pSave: 0.18 + boost, pScore: 0.52 + boost };
}

// ── ESTADO DO JOGO ──────────────────────────────────────────────────────────
let state = null;

function freshState() {
  return {
    phase: 'groups',    // 'groups' | 'knockout'
    userTeam: null,     // objeto time
    groups: [],         // [{name, teams:[{...time, pts, gs, gc}]}]
    groupMatchIndex: 0, // qual jogo da fase de grupos (0-2 para o user)
    groupMatches: [],   // lista de partidas do user na fase de grupos
    currentMatch: null, // partida em andamento
    koRound: 0,         // 0=oitavas,1=quartas,2=semi,3=final
    koBracket: [],      // times no mata-mata (16 classificados)
    koMatches: [],      // partidas do mata-mata corrente
  };
}

// ── SORTEIO DOS GRUPOS ───────────────────────────────────────────────────────
function drawGroups(userTeam) {
  // 16 times sorteados: user + 15 outros, 4 grupos de 4
  const pool = TIMES.filter(t => t.name !== userTeam.name);
  shuffle(pool);
  const chosen = pool.slice(0, 15);
  chosen.unshift(userTeam);
  shuffle(chosen);

  // Garantir que user fique no grupo A posição 0
  const userIdx = chosen.findIndex(t => t.name === userTeam.name);
  [chosen[0], chosen[userIdx]] = [chosen[userIdx], chosen[0]];

  const groups = [];
  const letters = ['A','B','C','D'];
  for (let i = 0; i < 4; i++) {
    groups.push({
      name: 'Grupo ' + letters[i],
      teams: chosen.slice(i*4, i*4+4).map(t => ({...t, pts:0, gs:0, gc:0}))
    });
  }
  return groups;
}

// ── PARTIDAS DO USER NA FASE DE GRUPOS ──────────────────────────────────────
function buildUserGroupMatches(groups) {
  const group = groups[0]; // user está no grupo A
  const others = group.teams.filter(t => t.name !== state.userTeam.name);
  return others.map(opp => ({ opp, result: null }));
}

// ── INÍCIO DO JOGO ──────────────────────────────────────────────────────────
function startGame() {
  // Escolhe um time aleatório para o user (com preferência por tier 1-2)
  const pool = TIMES.filter(t => t.tier <= 2);
  const userTeam = pool[Math.floor(Math.random() * pool.length)];

  state = freshState();
  state.userTeam = userTeam;
  state.groups = drawGroups(userTeam);
  state.groupMatches = buildUserGroupMatches(state.groups);
  state.groupMatchIndex = 0;

  showScreen('sc-groups');
  renderGroups();
  document.getElementById('btn-play-match').textContent = '⚽ Jogar Jogo 1 de 3';
}

// ── RENDER GRUPOS ──────────────────────────────────────────────────────────
function renderGroups() {
  const wrap = document.getElementById('groups-view');
  wrap.innerHTML = state.groups.map((g, gi) => {
    const sorted = [...g.teams].sort((a,b) => {
      if (b.pts !== a.pts) return b.pts - a.pts;
      return (b.gs-b.gc) - (a.gs-a.gc);
    });
    return `<div class="card" style="padding:12px 14px;margin-bottom:10px">
      <div class="card-title" style="margin-bottom:8px"><i class="bi bi-people"></i> ${g.name}</div>
      ${sorted.map((t,i) => {
        const isUser = t.name === state.userTeam.name;
        const saldo = t.gs - t.gc;
        const classifTag = gi === 0 && state.groupMatchIndex === 3
          ? (i < 2 ? '<span class="classif-tag q">Q</span>' : '<span class="classif-tag e">E</span>')
          : '';
        return `<div class="team-row ${isUser?'':''}">
          <div class="team-badge" style="background:${t.color}20;color:${t.color}">${t.badge}</div>
          <div class="team-name" style="${isUser?'color:var(--amber);font-weight:800':''}">${t.name}</div>
          ${classifTag}
          <div class="team-saldo" style="color:${saldo>=0?'var(--green)':'var(--red)'}">${saldo>0?'+':''}${saldo}</div>
          <div class="team-pts">${t.pts}pts</div>
        </div>`;
      }).join('')}
    </div>`;
  }).join('');

  // Simula jogos dos outros grupos (não do user)
  const btnPlay = document.getElementById('btn-play-match');
  if (state.groupMatchIndex >= 3) {
    btnPlay.textContent = '📋 Ver Classificação & Avançar';
    btnPlay.onclick = advanceFromGroups;
  } else {
    btnPlay.textContent = `⚽ Jogar Jogo ${state.groupMatchIndex+1} de 3`;
    btnPlay.onclick = playNextMatch;
  }
}

// ── PRÓXIMO JOGO DA FASE DE GRUPOS ──────────────────────────────────────────
function playNextMatch() {
  if (state.groupMatchIndex >= 3) { advanceFromGroups(); return; }
  const match = state.groupMatches[state.groupMatchIndex];
  startMatch(match.opp, false);
}

// ── INICIAR PARTIDA (PÊNALTIS) ───────────────────────────────────────────────
function startMatch(opp, isKnockout, koRound = 0) {
  state.currentMatch = {
    opp,
    isKnockout,
    koRound,
    userGoals: 0,
    oppGoals: 0,
    kicks: [],      // {role:'shoot'|'defend', userZone, oppZone, result:'goal'|'save'}
    kickIndex: 0,   // 0..9 (5 chutes cada, alternando)
    phase: 'shoot', // 'shoot' = user chuta; 'defend' = user defende
    locked: false,
  };

  // Alternância: par=user chuta, ímpar=user defende
  // Sequência: user chuta (0), opp chuta (1), ... (5 pares)
  // kick 0,2,4,6,8 = user chuta; 1,3,5,7,9 = user defende

  updateMatchUI();
  showScreen('sc-match');
  prepareKick();
}

function currentKickIsUserShooting() {
  return state.currentMatch.kickIndex % 2 === 0;
}

function prepareKick() {
  const m = state.currentMatch;
  if (m.locked) return;

  const kickNum = m.kickIndex;
  const userShooting = currentKickIsUserShooting();
  const round = Math.floor(kickNum / 2) + 1; // 1-5

  document.getElementById('sb-info').textContent = `Pênalti ${round} de 5`;
  document.getElementById('kick-round-label').textContent = userShooting
    ? `🦵 Você está CHUTANDO (${round}/5)`
    : `🧤 Você está DEFENDENDO (${round}/5)`;
  document.getElementById('kick-instructions').textContent = userShooting
    ? 'Clique na zona onde quer chutar'
    : 'Clique na zona que vai defender';
  setStatus('', 'neutral');
  resetZones();
  document.getElementById('btn-next-kick').classList.add('hidden');

  // Reposicionar goleiro
  animateKeeper(4, false); // zona 4 = centro-baixo (posição inicial)
}

function handleZoneClick(zone) {
  const m = state.currentMatch;
  if (m.locked) return;
  m.locked = true;

  // ── MODO MORTE SÚBITA ──────────────────────────────────────────────────────
  if (m.suddenDeath) {
    handleSuddenDeathKick(zone);
    return;
  }

  // ── MODO NORMAL ────────────────────────────────────────────────────────────
  const userShooting = currentKickIsUserShooting();
  const opp = m.opp;
  const diff = getDifficulty(opp.tier, m.koRound);

  let result, oppZone;

  if (userShooting) {
    oppZone = Math.floor(Math.random() * 6);
    const saved = (zone === oppZone) || (Math.random() < diff.pSave);
    result = saved ? 'save' : 'goal';
    animateKeeper(oppZone, true);
    animateBall(zone);
    highlightZoneSVG(zone, result);
    markZone(zone, result);
    if (result === 'goal') { m.userGoals++; setStatus('⚽ GOL! Você marcou!', 'ok'); }
    else setStatus('🧤 Defendido! O goleiro pegou.', 'fail');
    addLogDot(result === 'goal' ? 'user-goal' : 'defense');
  } else {
    oppZone = Math.floor(Math.random() * 6);
    const scored = (zone !== oppZone) && (Math.random() < diff.pScore);
    result = scored ? 'goal' : 'save';
    animateKeeper(zone, true);
    animateBall(oppZone);
    highlightZoneSVG(oppZone, scored ? 'goal' : 'save');
    markZone(oppZone, result === 'save' ? 'goal' : 'saved');
    if (result === 'save') setStatus('🧤 Você defendeu! Ótima defesa!', 'ok');
    else { m.oppGoals++; setStatus('😬 Gol do adversário!', 'fail'); }
    addLogDot(result === 'save' ? 'defense' : 'opp-goal');
  }

  m.kicks.push({ userShooting, userZone: zone, oppZone, result });
  updateMatchUI();
  m.kickIndex++;

  if (m.kickIndex >= 10) {
    setTimeout(finishMatch, 900);
  } else {
    document.getElementById('btn-next-kick').classList.remove('hidden');
    m.locked = false;
  }
}

function handleSuddenDeathKick(zone) {
  const m = state.currentMatch;
  const diff = getDifficulty(m.opp.tier, m.koRound);
  resetZones();

  if (m.sdKick === 0) {
    // User chuta
    const oppZone = Math.floor(Math.random() * 6);
    const saved = (zone === oppZone) || (Math.random() < diff.pSave);
    animateKeeper(oppZone, true);
    animateBall(zone);
    highlightZoneSVG(zone, saved ? 'save' : 'goal');
    markZone(zone, saved ? 'saved' : 'goal');
    m.sdUserScored = !saved;

    if (m.sdUserScored) setStatus('⚽ GOL na morte súbita!', 'ok');
    else setStatus('🧤 Defendido na morte súbita!', 'fail');

    m.sdKick = 1;
    // Agora adversário chuta — o user defende
    setTimeout(() => {
      resetZones();
      document.getElementById('kick-round-label').textContent = '💀 MORTE SÚBITA — Defenda o chute!';
      document.getElementById('kick-instructions').textContent = 'Clique na zona para defender';
      setStatus(m.sdUserScored ? 'Você marcou! Agora defenda para vencer.' : 'Você errou. Defenda para não perder!', 'neutral');
      m.locked = false;
    }, 700);

  } else {
    // User defende
    const oppZone = Math.floor(Math.random() * 6);
    const scored = (zone !== oppZone) && (Math.random() < diff.pScore * 0.9);
    animateKeeper(zone, true);
    animateBall(oppZone);
    highlightZoneSVG(oppZone, scored ? 'goal' : 'save');
    markZone(oppZone, scored ? 'goal' : 'saved');

    const userWins = m.sdUserScored && !scored;
    const userLoses = !m.sdUserScored && scored;

    if (!scored) setStatus('🧤 Você defendeu!', 'ok');
    else setStatus('😬 Gol do adversário na morte súbita!', 'fail');

    setTimeout(() => {
      if (userWins) {
        m.userGoals++; // placar simbólico
        showMatchResult(true, false, false);
      } else if (userLoses) {
        m.oppGoals++;
        showMatchResult(false, false, false);
      } else {
        // Ambos marcaram ou nenhum marcou — nova rodada
        m.sdKick = 0;
        m.sdUserScored = null;
        m.locked = false;
        document.getElementById('kick-round-label').textContent = '💀 MORTE SÚBITA — Você chuta novamente';
        document.getElementById('kick-instructions').textContent = 'Clique na zona para chutar';
        setStatus('Continua! Nova rodada de morte súbita.', 'neutral');
        document.getElementById('penalty-log').innerHTML = '';
        resetZones();
        animateKeeper(4, false); // zona 4 = centro-baixo (posição inicial)
      }
    }, 700);
  }
}

function nextKick() {
  state.currentMatch.locked = false;
  document.getElementById('btn-next-kick').classList.add('hidden');
  prepareKick();
}

// Coordenadas SVG (cx) e cy por zona
const ZONE_SVG = {
  0: {x: 82,  y: 32},
  1: {x: 160, y: 32},
  2: {x: 238, y: 32},
  3: {x: 82,  y: 62},
  4: {x: 160, y: 62},
  5: {x: 238, y: 62},
};
// Bounds highlight por zona [x, y, w, h]
const ZONE_RECT = {
  0: [61,  16, 66, 32],
  1: [127, 16, 66, 32],
  2: [193, 16, 66, 32],
  3: [61,  48, 66, 32],
  4: [127, 48, 66, 32],
  5: [193, 48, 66, 32],
};

function animateKeeper(zone, jump) {
  const k = document.getElementById('keeper-svg');
  if (!k) return;
  const pos = ZONE_SVG[zone] ?? {x:160, y:62};
  k.setAttribute('x', pos.x);
  k.setAttribute('y', pos.y);
}

function animateBall(zone) {
  const b = document.getElementById('ball-svg');
  if (!b) return;
  const pos = ZONE_SVG[zone] ?? {x:160, y:32};
  b.setAttribute('cx', pos.x);
  b.setAttribute('cy', pos.y);
  b.setAttribute('opacity', '1');
  setTimeout(() => b.setAttribute('opacity', '0'), 600);
}

function highlightZoneSVG(zone, cls) {
  const h = document.getElementById('zone-highlight');
  if (!h) return;
  const r = ZONE_RECT[zone];
  if (!r) { h.setAttribute('width','0'); return; }
  h.setAttribute('x', r[0]);
  h.setAttribute('y', r[1]);
  h.setAttribute('width', r[2]);
  h.setAttribute('height', r[3]);
  h.setAttribute('fill', cls === 'goal' ? 'rgba(34,197,94,.3)' : 'rgba(239,68,68,.3)');
}

function getZoneX(zone) {
  return zone; // agora usamos zone diretamente em animateKeeper
}

function markZone(zone, cls) {
  const el = document.querySelector(`.sz[data-zone="${zone}"]`);
  if (el) el.classList.add(cls === 'goal' ? 'goal' : 'saved');
}

function resetZones() {
  document.querySelectorAll('.sz').forEach(el => {
    el.classList.remove('goal','saved','selected');
  });
}

function addLogDot(type) {
  const log = document.getElementById('penalty-log');
  const dot = document.createElement('div');
  dot.className = 'log-dot';
  if (type === 'user-goal') { dot.textContent = '⚽'; dot.style.borderColor = 'var(--green)'; }
  else if (type === 'opp-goal') { dot.textContent = '⚽'; dot.style.borderColor = 'var(--red)'; }
  else { dot.textContent = '🧤'; dot.style.borderColor = 'var(--blue)'; }
  log.appendChild(dot);
}

function setStatus(msg, cls) {
  const el = document.getElementById('status-msg');
  el.textContent = msg;
  el.className = 'status-msg ' + cls;
}

function updateMatchUI() {
  const m = state.currentMatch;
  document.getElementById('m-team-user').textContent = state.userTeam.name;
  document.getElementById('m-team-opp').textContent = m.opp.name;
  document.getElementById('m-badge-user').textContent = state.userTeam.badge;
  document.getElementById('m-badge-opp').textContent = m.opp.badge;
  document.getElementById('sb-user').textContent = state.userTeam.name;
  document.getElementById('sb-opp').textContent = m.opp.name;
  document.getElementById('sb-score-user').textContent = m.userGoals;
  document.getElementById('sb-score-opp').textContent = m.oppGoals;
}

// ── FINALIZAR PARTIDA ────────────────────────────────────────────────────────
function finishMatch() {
  const m = state.currentMatch;
  const win = m.userGoals > m.oppGoals;
  const draw = m.userGoals === m.oppGoals;

  if (m.isKnockout) {
    if (draw) {
      // Pênalti extra (morte súbita) — simplificado: simula 3 pênaltis extras
      runSuddenDeath();
      return;
    }
    showMatchResult(win, draw, false);
  } else {
    // Fase de grupos
    updateGroupStandings(m.opp, m.userGoals, m.oppGoals);
    showMatchResult(win, draw, false);
  }
}

function runSuddenDeath() {
  // Ativa modo morte súbita — o user joga kick a kick
  const m = state.currentMatch;
  m.suddenDeath = true;
  m.sdKick = 0;   // 0=user chuta, 1=opp chuta
  m.sdUserScored = null;
  m.locked = false;

  document.getElementById('sb-info').textContent = 'MORTE SÚBITA!';
  document.getElementById('kick-round-label').textContent = '💀 MORTE SÚBITA — Você chuta primeiro';
  document.getElementById('kick-instructions').textContent = 'Clique na zona para chutar';
  setStatus('Empate! Decide na morte súbita.', 'neutral');
  document.getElementById('penalty-log').innerHTML = '';
  resetZones();
  document.getElementById('btn-next-kick').classList.add('hidden');
  animateKeeper(4, false); // zona 4 = centro-baixo (posição inicial)
}

function updateGroupStandings(opp, uGoals, oGoals) {
  const group = state.groups[0];
  const uTeam = group.teams.find(t => t.name === state.userTeam.name);
  const oTeam = group.teams.find(t => t.name === opp.name);
  uTeam.gs += uGoals; uTeam.gc += oGoals;
  oTeam.gs += oGoals; oTeam.gc += uGoals;
  if (uGoals > oGoals) { uTeam.pts += 3; }
  else if (uGoals === oGoals) { uTeam.pts += 1; oTeam.pts += 1; }
  else { oTeam.pts += 3; }

  // Simula jogos dos outros times do grupo
  const others = group.teams.filter(t => t.name !== state.userTeam.name && t.name !== opp.name);
  if (others.length >= 2) {
    const g1 = Math.floor(Math.random()*4), g2 = Math.floor(Math.random()*4);
    others[0].gs += g1; others[0].gc += g2;
    others[1].gs += g2; others[1].gc += g1;
    if (g1 > g2) others[0].pts += 3;
    else if (g1 === g2) { others[0].pts += 1; others[1].pts += 1; }
    else others[1].pts += 3;
  }

  // Simula jogos dos outros grupos apenas no último jogo do user
  if (state.groupMatchIndex === 2) {
    for (let gi = 1; gi < state.groups.length; gi++) {
      const g = state.groups[gi];
      for (let a = 0; a < g.teams.length; a++) {
        for (let b = a+1; b < g.teams.length; b++) {
          const ga = Math.floor(Math.random()*4), gb = Math.floor(Math.random()*4);
          g.teams[a].gs += ga; g.teams[a].gc += gb;
          g.teams[b].gs += gb; g.teams[b].gc += ga;
          if (ga > gb) g.teams[a].pts += 3;
          else if (ga === gb) { g.teams[a].pts += 1; g.teams[b].pts += 1; }
          else g.teams[b].pts += 3;
        }
      }
    }
  }

  state.groupMatchIndex++;
}

// ── MOSTRAR RESULTADO DA PARTIDA ────────────────────────────────────────────
function showMatchResult(win, draw, knockout) {
  const m = state.currentMatch;
  document.getElementById('mr-team-user').textContent = state.userTeam.name;
  document.getElementById('mr-team-opp').textContent = m.opp.name;
  document.getElementById('mr-score-user').textContent = m.userGoals;
  document.getElementById('mr-score-opp').textContent = m.oppGoals;

  if (win) {
    document.getElementById('mr-icon').textContent = '🏅';
    document.getElementById('mr-title').textContent = 'Vitória!';
    document.getElementById('mr-title').style.color = 'var(--green)';
    document.getElementById('mr-sub').textContent = `${state.userTeam.name} ${m.userGoals} × ${m.oppGoals} ${m.opp.name}`;
  } else if (draw) {
    document.getElementById('mr-icon').textContent = '🤝';
    document.getElementById('mr-title').textContent = 'Empate!';
    document.getElementById('mr-title').style.color = 'var(--amber)';
    document.getElementById('mr-sub').textContent = 'Um ponto para cada lado.';
  } else {
    document.getElementById('mr-icon').textContent = '😓';
    document.getElementById('mr-title').textContent = m.isKnockout ? 'Eliminado!' : 'Derrota!';
    document.getElementById('mr-title').style.color = m.isKnockout ? 'var(--red)' : 'var(--amber)';
    document.getElementById('mr-sub').textContent = m.isKnockout
      ? 'Você foi eliminado. A copa continua sem você.'
      : 'Derrota, mas ainda há mais jogos.';
  }

  const btnCont = document.getElementById('btn-mr-continue');

  if (m.isKnockout && !win) {
    document.getElementById('mr-group-state').innerHTML = '';
    btnCont.textContent = 'Voltar ao Início';
    btnCont.onclick = resetGame;
  } else {
    btnCont.textContent = 'Continuar ›';
    btnCont.onclick = afterMatch;
    document.getElementById('mr-group-state').innerHTML = '';
  }

  showScreen('sc-match-result');
}

function afterMatch() {
  const m = state.currentMatch;
  if (m.isKnockout) {
    advanceKnockout();
  } else {
    showScreen('sc-groups');
    renderGroups();
  }
}

// ── AVANÇAR FASE DE GRUPOS → MATA-MATA ──────────────────────────────────────
function advanceFromGroups() {
  // Checar se user classificou
  const group = state.groups[0];
  const sorted = [...group.teams].sort((a,b) => {
    if (b.pts !== a.pts) return b.pts - a.pts;
    return (b.gs-b.gc) - (a.gs-a.gc);
  });
  const userPos = sorted.findIndex(t => t.name === state.userTeam.name);
  if (userPos >= 2) {
    // Eliminado na fase de grupos
    document.getElementById('elim-msg').textContent =
      `${state.userTeam.name} terminou em ${userPos+1}º no Grupo A e não se classificou.`;
    document.getElementById('overlay-eliminated').classList.remove('hidden');
    return;
  }

  // Montar bracket do mata-mata: 8 times (2 de cada grupo)
  state.phase = 'knockout';
  state.koRound = 0;
  const qualifiers = [];
  state.groups.forEach(g => {
    const s = [...g.teams].sort((a,b) => {
      if (b.pts !== a.pts) return b.pts - a.pts;
      return (b.gs-b.gc) - (a.gs-a.gc);
    });
    qualifiers.push(s[0], s[1]);
  });

  // Garantir user no bracket
  state.koBracket = qualifiers;
  showKnockoutScreen();
}

// ── MATA-MATA ────────────────────────────────────────────────────────────────
const KO_ROUND_NAMES = ['Oitavas de Final','Quartas de Final','Semifinal','Final'];

function showKnockoutScreen() {
  const round = state.koRound;
  document.getElementById('ko-phase-title').textContent = KO_ROUND_NAMES[round] ?? 'Final';
  document.getElementById('ko-phase-sub').textContent = 'Mata-Mata · Eliminação direta';

  const bracket = state.koBracket;
  const userIdx = bracket.findIndex(t => t && t.name === state.userTeam.name);
  const pairIdx = userIdx % 2 === 0 ? userIdx + 1 : userIdx - 1;
  const userOpp = bracket[pairIdx];

  let html = '';
  for (let i = 0; i < bracket.length; i += 2) {
    const t1 = bracket[i], t2 = bracket[i+1];
    const isUserMatch = (t1 && t1.name === state.userTeam.name) || (t2 && t2.name === state.userTeam.name);
    html += `<div class="card" style="padding:12px 14px;margin-bottom:8px;${isUserMatch?'border-color:var(--amber)':''}">
      <div style="font-size:10px;color:var(--text3);margin-bottom:6px;font-weight:700">${isUserMatch?'⭐ SEU JOGO':''}</div>
      <div class="team-row" style="border:none;padding:4px 0">
        <div class="team-badge" style="background:${t1?.color??'#333'}20;color:${t1?.color??'#aaa'}">${t1?.badge??'?'}</div>
        <div class="team-name" style="${t1?.name===state.userTeam.name?'color:var(--amber);font-weight:800':''}">${t1?.name??'?'}</div>
        <div style="font-size:10px;color:var(--text3)">Tier ${t1?.tier??'?'}</div>
      </div>
      <div style="text-align:center;font-size:12px;color:var(--text3);padding:2px 0">vs</div>
      <div class="team-row" style="border:none;padding:4px 0">
        <div class="team-badge" style="background:${t2?.color??'#333'}20;color:${t2?.color??'#aaa'}">${t2?.badge??'?'}</div>
        <div class="team-name" style="${t2?.name===state.userTeam.name?'color:var(--amber);font-weight:800':''}">${t2?.name??'?'}</div>
        <div style="font-size:10px;color:var(--text3)">Tier ${t2?.tier??'?'}</div>
      </div>
    </div>`;
  }
  document.getElementById('ko-bracket').innerHTML = html;

  // Botão
  const btn = document.getElementById('btn-ko-play');
  btn.textContent = `⚽ Jogar vs ${userOpp?.name ?? '?'}`;
  showScreen('sc-knockout');
}

function playKnockout() {
  const bracket = state.koBracket;
  const userIdx = bracket.findIndex(t => t && t.name === state.userTeam.name);
  const pairIdx = userIdx % 2 === 0 ? userIdx + 1 : userIdx - 1;
  const opp = bracket[pairIdx];
  startMatch(opp, true, state.koRound + 1);
}

function advanceKnockout() {
  const m = state.currentMatch;
  const win = m.userGoals > m.oppGoals;

  if (!win) { resetGame(); return; }

  // Simular outros jogos do bracket
  const bracket = state.koBracket;
  const winners = [];
  for (let i = 0; i < bracket.length; i += 2) {
    const t1 = bracket[i], t2 = bracket[i+1];
    if (!t1 || !t2) { winners.push(t1 ?? t2); continue; }
    if (t1.name === state.userTeam.name) {
      winners.push(state.userTeam);
    } else {
      // Sorteio ponderado por tier (tier 1 ganha mais)
      const t1w = 4 - t1.tier, t2w = 4 - t2.tier;
      const total = t1w + t2w;
      winners.push(Math.random() < t1w/total ? t1 : t2);
    }
  }

  state.koBracket = winners;
  state.koRound++;

  if (winners.length === 1) {
    // Campeão!
    document.getElementById('champ-name').textContent = state.userTeam.name + ' 🏆';
    showScreen('sc-champion');
    return;
  }

  showKnockoutScreen();
}

// ── RESET ────────────────────────────────────────────────────────────────────
function resetGame() {
  document.getElementById('overlay-eliminated').classList.add('hidden');
  document.getElementById('penalty-log').innerHTML = '';
  state = null;
  showScreen('sc-start');
}

// ── UTILITÁRIOS ──────────────────────────────────────────────────────────────
function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  window.scrollTo(0,0);
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
