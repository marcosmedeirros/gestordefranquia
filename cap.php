<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/preview_gate.php';
// Funcionalidade em avaliacao: fora de qualquer menu, so abre com ?preview=<token>
requirePreview('cap');
requireAuth();
$user = getUserSession();
$pdo  = db();

$stmtMine = $pdo->prepare("SELECT id, city, name, league, photo_url FROM teams WHERE user_id = ? LIMIT 1");
$stmtMine->execute([$user['id']]);
$team = $stmtMine->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$team) { header('Location: teams.php'); exit; }

// Dentro do preview o modo salario vale mesmo com a liga desligada,
// que e justamente o que os admins vao avaliar aqui.
$capMode = 'salary';
$teamId = (int)$team['id'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<meta name="theme-color" content="#f59e0b">
<title>Salary Cap · FBA Manager</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:color-mix(in srgb, var(--red) 10%, transparent);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;--border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--text:#f0f0f3;--text-2:#868690;--text-3:#7d7d85;--amber:#f59e0b;--amber-soft:rgba(245,158,11,.10);--border-amber:rgba(245,158,11,.25);--green:#22c55e;--purple:#a855f7;--font:'Montserrat', sans-serif;--radius:14px;--radius-sm:10px;--sidebar-w:260px;--ease:cubic-bezier(.2,.8,.2,1);--t:200ms;--border-red:color-mix(in srgb, var(--red) 22%, transparent)}
:root[data-theme="light"]{--bg:#f6f7fb;--panel:#ffffff;--panel-2:#f2f4f8;--panel-3:#e9edf4;--border:#e3e6ee;--border-md:#d7dbe6;--text:#111217;--text-2:#5b6270;--text-3:#657080}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.icon-btn{width:34px;height:34px;border-radius:10px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:15px;cursor:pointer;text-decoration:none;transition:all .2s}
.icon-btn:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
.content{max-width:1000px;margin:0 auto;padding:24px 16px 80px;width:100%}
.hero{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:8px}
.hero-title{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700;display:flex;align-items:center;gap:10px}
.hero-title i{color:var(--red)}
.hero-team{font-size:14px;color:var(--text-2);margin-top:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.league-badge{display:inline-block;background:var(--red-soft);border:1px solid var(--border-red);color:var(--red);border-radius:999px;font-size:10px;font-weight:700;padding:4px 10px;letter-spacing:.5px}
.status-badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;font-size:11px;font-weight:700;padding:5px 12px}
.status-badge.ok{background:rgba(34,197,94,.12);color:var(--green);border:1px solid rgba(34,197,94,.3)}
.status-badge.over{background:rgba(239,68,68,.12);color:#ef4444;border:1px solid rgba(239,68,68,.3)}
.status-badge.floor{background:rgba(245,158,11,.12);color:var(--amber);border:1px solid var(--border-amber)}
.section-title{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);margin:22px 0 12px;display:flex;align-items:center;gap:8px}
.section-title i{color:var(--red)}
.info-hint{color:var(--text-3);font-size:12px;cursor:help;margin-left:4px}
.info-hint:hover{color:var(--red)}
.suggestions{display:flex;flex-direction:column;gap:8px}
.suggestion{display:flex;gap:10px;align-items:flex-start;border-radius:10px;padding:11px 13px;font-size:13px;line-height:1.45}
.suggestion i{flex-shrink:0;font-size:15px;margin-top:1px}
.suggestion.ok{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);color:var(--text)}.suggestion.ok i{color:var(--green)}
.suggestion.danger{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.28);color:var(--text)}.suggestion.danger i{color:#ef4444}
.suggestion.warn{background:rgba(245,158,11,.08);border:1px solid var(--border-amber);color:var(--text)}.suggestion.warn i{color:var(--amber)}
.suggestion.info{background:var(--panel-2);border:1px solid var(--border);color:var(--text-2)}.suggestion.info i{color:var(--text-2)}
.suggestion.tip{background:var(--red-soft);border:1px solid var(--border-red);color:var(--text-2)}.suggestion.tip i{color:var(--red)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:24px}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:4px}
.stat-card.hi-amber{border-color:var(--border-amber);background:var(--amber-soft)}
.stat-card.hi-red{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.05)}
.stat-label{font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center}
.stat-value{font-family:'Oswald',sans-serif;font-size:26px;font-weight:700;color:var(--text);line-height:1}
.stat-value.amber{color:var(--amber)}.stat-value.green{color:var(--green)}.stat-value.red{color:#ef4444}
.stat-sub{font-size:11px;color:var(--text-2)}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px}
.roster-table{width:100%;border-collapse:collapse;font-size:13px}
.roster-table th{padding:8px 10px;text-align:left;color:var(--text-3);font-weight:600;border-bottom:1px solid var(--border);font-size:11px;text-transform:uppercase;letter-spacing:.4px}
.roster-table td{padding:10px 10px;border-bottom:1px solid var(--border);vertical-align:middle}
.roster-table tr:last-child td{border-bottom:none}
.roster-table td.num,.roster-table th.num{text-align:right;font-family:'Oswald',sans-serif;font-weight:700}
.tag{display:inline-flex;align-items:center;gap:4px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:999px;font-size:10px;font-weight:700;padding:2px 8px;color:var(--text-2)}
.tag.rookie{background:var(--amber-soft);border-color:var(--border-amber);color:var(--amber)}
.tag.flex{background:rgba(168,85,247,.12);border-color:rgba(168,85,247,.3);color:var(--purple)}
.tag.bonus{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.3);color:var(--green)}
.tag.flex-off{background:var(--panel-3);border-color:var(--border);color:var(--text-3);text-decoration:line-through;text-decoration-thickness:1px}

/* ── Simulador de trocas ─────────────────────────── */
.sim-note{display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--green);background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.22);border-radius:10px;padding:9px 12px;margin-bottom:14px}
.sim-teams{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.sim-side label{display:block;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:6px}
.sim-side select{width:100%;background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:10px;padding:9px 11px;font-family:inherit;font-size:13px}
.sim-list{margin-top:10px;max-height:260px;overflow-y:auto;border:1px solid var(--border);border-radius:10px;background:var(--panel-2)}
.sim-row{display:flex;align-items:center;gap:9px;padding:7px 11px;border-bottom:1px solid var(--border);font-size:12.5px;cursor:pointer}
.sim-row:last-child{border-bottom:none}
.sim-row:hover{background:var(--panel-3)}
.sim-row input{accent-color:var(--red);flex-shrink:0;cursor:pointer}
.sim-row .nm{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sim-row .ov{font-family:'Oswald',sans-serif;font-weight:700;color:var(--text-2);font-size:12px}
.sim-row .sal{font-family:'Oswald',sans-serif;font-weight:700;color:var(--red);width:46px;text-align:right}
.btn-sim{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:10px;background:var(--red);border:1px solid var(--red);color:#fff;font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;transition:filter var(--t) var(--ease)}
.btn-sim:hover:not(:disabled){filter:brightness(1.1)}
.btn-sim:disabled{opacity:.6;cursor:not-allowed}
.btn-sim.ghost{background:var(--panel-2);border-color:var(--border-md);color:var(--text-2)}
.btn-sim.ghost:hover{border-color:var(--red);color:var(--red)}
.sim-cards{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.sim-card{background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:14px 16px}
.sim-card h4{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;margin-bottom:10px}
.sim-line{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:5px 0;font-size:12.5px;border-bottom:1px solid var(--border)}
.sim-line:last-child{border-bottom:none}
.sim-line .lbl{color:var(--text-2)}
.sim-line .val{font-family:'Oswald',sans-serif;font-weight:700}
.delta{font-size:11px;font-weight:700;margin-left:6px}
.delta.up{color:#ef4444}.delta.down{color:var(--green)}.delta.same{color:var(--text-3)}
.sim-status{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:999px;margin-top:10px}
.sim-status.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:var(--green)}
.sim-status.bad{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#ef4444}
.sim-status.warn{background:var(--amber-soft);border:1px solid var(--border-amber);color:var(--amber)}
.sim-verdict{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:11px;font-size:12.5px;line-height:1.55}
.sim-verdict i{font-size:16px;flex-shrink:0;margin-top:1px}
.sim-verdict.ok{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.28);color:var(--green)}
.sim-verdict.bad{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.30);color:#f87171}
.sim-verdict li{margin-bottom:4px}
@media (max-width: 760px){
  .sim-teams{grid-template-columns:1fr}
  .sim-cards{grid-template-columns:1fr}
}
.rules-panel details{margin-bottom:8px}
.rules-panel summary{cursor:pointer;font-size:13px;font-weight:600;color:var(--text);padding:6px 0}
.rules-panel summary:hover{color:var(--amber)}
.rules-panel .rules-body{font-size:12px;color:var(--text-2);padding:6px 0 10px 4px;line-height:1.6}
.skeleton{background:linear-gradient(90deg,var(--panel-2) 25%,var(--panel-3) 50%,var(--panel-2) 75%);background-size:200% 100%;animation:shimmer 1.2s infinite;border-radius:8px}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.empty{text-align:center;padding:24px;color:var(--text-3);font-size:13px}
@media(max-width:640px){
  .content{padding:18px 12px 72px}
  .panel{padding:16px 14px}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .hero{padding:18px}
  .hero-name{font-size:18px}
  .roster-table{font-size:12px}
  .roster-table th:nth-child(2),.roster-table td:nth-child(2){display:none}
}

/* -- Layout com menu lateral -- */
.app { display: flex; min-height: 100vh; }
.sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; transition: transform var(--t) var(--ease); overflow-y: auto; scrollbar-width: none; }
.sidebar::-webkit-scrollbar { display: none; }
.sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
.sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
.sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
.sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
.sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
.sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
.sb-nav { flex: 1; padding: 12px 10px 8px; }
.sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
.sb-nav a { font-family:'Inter',sans-serif; display: flex; align-items: center; gap: 10px; padding: 10px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
.sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
.sb-nav a:hover { background: var(--panel-2); color: var(--text); }
.sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
.sb-nav a.active i { color: var(--red); }
.sb-theme-toggle{margin:10px 14px;display:flex;align-items:center;gap:8px;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:9px 12px;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;flex-shrink:0}
.sb-theme-toggle:hover{color:var(--text)}
.sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
.sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; flex-shrink: 0; }
.sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }
.topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 260; }
.topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
.topbar-title em { color: var(--amber); font-style: normal; }
.menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
.sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
.sb-overlay.show { display: block; }
.main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }
@media (max-width: 992px) {
  :root { --sidebar-w: 0px; }
  .sidebar { transform: translateX(-260px); }
  .sidebar.open { transform: translateX(0); }
  .main { margin-left: 0; width: 100%; padding-top: 54px; }
  .topbar { display: flex; }
  .content { padding-left: 16px; padding-right: 16px; }
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
  <div class="topbar-title">Salary <em>Cap</em></div>
  <a href="dashboard.php" class="icon-btn"><i class="bi bi-arrow-left"></i></a>
</header>

<main class="main">
 <div class="content">

  <div class="hero">
    <div class="hero-title"><i class="bi bi-cash-stack"></i> Salary Cap</div>
    <div class="hero-team"><?= htmlspecialchars(trim($team['city'] . ' ' . $team['name'])) ?>
      <span class="league-badge"><?= htmlspecialchars($team['league']) ?></span>
      <span class="status-badge" id="statusBadge" style="display:none"></span>
    </div>
  </div>

  <?php if ($capMode !== 'salary'): ?>
  <div class="panel empty">
    <i class="bi bi-info-circle" style="font-size:22px;color:var(--text-3)"></i>
    <p style="margin-top:10px">O Salary Cap novo (folha em dinheiro) existe só para a liga <b>ELITE</b> por enquanto.
    Sua liga (<?= htmlspecialchars($team['league']) ?>) continua usando o sistema de Cap por soma de OVR — veja em
    <a href="teams.php" style="color:var(--amber)">Times</a>.</p>
  </div>
  <?php else: ?>

  <div class="section-title"><i class="bi bi-cash-stack"></i> Visão Geral do Cap</div>
  <div class="stats-grid" id="statsGrid">
    <?php for($i=0;$i<6;$i++): ?>
    <div class="stat-card"><div class="skeleton" style="height:10px;width:55%;margin-bottom:8px"></div><div class="skeleton" style="height:24px;width:45%"></div></div>
    <?php endfor; ?>
  </div>

  <div class="section-title" id="suggTitle" style="display:none"><i class="bi bi-lightbulb"></i> Como ficar no cap</div>
  <div class="panel" id="suggPanel" style="display:none">
    <div class="suggestions" id="suggList"></div>
  </div>

  <div class="section-title"><i class="bi bi-people-fill"></i> Elenco — Detalhamento Salarial</div>
  <div class="panel">
    <div style="overflow-x:auto">
      <table class="roster-table" id="rosterTable">
        <thead>
          <tr>
            <th>Jogador</th>
            <th class="num">OVR</th>
            <th class="num">Salário Base<i class="bi bi-question-circle info-hint" title="Definido pela tabela de OVR. Na temporada de estreia (rookie), usa a Rookie Scale pela posição do pick, se conhecida."></i></th>
            <th>Situação</th>
            <th class="num">Total<i class="bi bi-question-circle info-hint" title="Salário Base + Bônus de Prêmio da temporada. O Cap Flex não entra aqui — ele aumenta o Cap Máximo do time, não o salário do jogador."></i></th>
          </tr>
        </thead>
        <tbody id="rosterBody">
          <tr><td colspan="5"><div class="skeleton" style="height:120px"></div></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="section-title"><i class="bi bi-arrow-left-right"></i> Simulador de Trocas
    <i class="bi bi-question-circle info-hint" title="Monta uma troca hipotética entre dois times da ELITE e mostra o cap dos dois lados antes e depois. Nada é gravado — serve só para testar."></i>
  </div>
  <div class="panel">
    <div class="sim-note">
      <i class="bi bi-shield-check"></i>
      Simulação pura: o resultado é calculado e descartado. Nenhum jogador muda de time e nenhuma troca é registrada.
    </div>

    <div class="sim-teams">
      <div class="sim-side">
        <label>Time A</label>
        <select id="simTeamA"></select>
        <div class="sim-list" id="simListA"><div class="empty">Escolha um time</div></div>
      </div>
      <div class="sim-side">
        <label>Time B</label>
        <select id="simTeamB"></select>
        <div class="sim-list" id="simListB"><div class="empty">Escolha um time</div></div>
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px">
      <button class="btn-sim" id="btnSimular"><i class="bi bi-calculator"></i> Simular troca</button>
      <button class="btn-sim ghost" id="btnSimLimpar"><i class="bi bi-eraser"></i> Limpar seleção</button>
      <span style="font-size:11.5px;color:var(--text-3)">Marque os jogadores que <strong>cada time envia</strong>.</span>
    </div>

    <div id="simResult" style="display:none;margin-top:18px"></div>
  </div>

  <div class="section-title"><i class="bi bi-journal-text"></i> Como funciona</div>
  <div class="panel rules-panel">
    <details>
      <summary><i class="bi bi-graph-up-arrow"></i> Salário por OVR</summary>
      <div class="rules-body">Cada jogador tem salário automático conforme o OVR: de 60M (OVR 99) até o piso de 2M
      (OVR 77 ou menos, "veteran minimum"). Recalculado sempre que o OVR mudar — nada fica desatualizado.</div>
    </details>
    <details>
      <summary><i class="bi bi-stars"></i> Rookie Scale</summary>
      <div class="rules-body">Na temporada de estreia, o salário do jogador draftado é definido pela posição do pick,
      não pelo OVR: picks 1–3 = 18M, 4–8 = 14M, 9–12 = 12M, 13–16 = 8M, 17–22 = 5M, 23–30 = 3M, 2ª rodada = 2M
      (fixo, qualquer posição). Ao fim da 1ª temporada, passa a valer a tabela de OVR normalmente.</div>
    </details>
    <details>
      <summary><i class="bi bi-arrows-angle-expand"></i> Cap Flex</summary>
      <div class="rules-body">Time que ainda tem o jogador que ele mesmo draftou ganha um bônus no <b>Cap Máximo</b>
      (não no salário do jogador) se o OVR for alto: 85–89 = +3M, 90–92 = +5M, 93+ = +8M. Se o jogador for negociado
      para outro time, o Cap Flex dele deixa de valer.</div>
    </details>
    <details>
      <summary><i class="bi bi-trophy"></i> Bônus de Prêmio</summary>
      <div class="rules-body">Quem venceu um prêmio individual (MVP +5M, DPOY +3M, ROY +2M, MIP +2M, 6º Homem +2M) na
      temporada registrada mais recente fica com o salário mais caro <b>só na temporada seguinte</b> — depois disso o
      bônus some automaticamente. Prêmios de Finals MVP, All-NBA e All-Defensivo ainda não têm cadastro no sistema,
      então não geram bônus por enquanto.</div>
    </details>
    <details>
      <summary><i class="bi bi-shield-check"></i> Status do time</summary>
      <div class="rules-body">Cap Máximo = 205M + soma do Cap Flex do elenco. Cap Mínimo = 180M — fica abaixo do piso
      só é um problema de verdade depois da Trade Deadline (a validação automática disso ainda não existe, é só um
      aviso informativo por enquanto).</div>
    </details>
  </div>

  <?php endif; ?>
 </div>
</main>
</div>

<script>
(function(){
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sbOverlay');
  const menuBtn = document.getElementById('menuBtn');
  if (menuBtn) menuBtn.addEventListener('click', () => { sidebar?.classList.add('open'); overlay?.classList.add('show'); });
  if (overlay) overlay.addEventListener('click', () => { sidebar?.classList.remove('open'); overlay.classList.remove('show'); });

  const themeToggle = document.getElementById('themeToggle');
  const themeKey = 'fba-theme';
  const applyTheme = (theme) => {
    if (theme === 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
      if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
      return;
    }
    document.documentElement.removeAttribute('data-theme');
    if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
  };
  applyTheme(localStorage.getItem(themeKey) || 'dark');
  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
      const next = current === 'light' ? 'dark' : 'light';
      localStorage.setItem(themeKey, next);
      applyTheme(next);
    });
  }
})();

<?php if ($capMode === 'salary'): ?>
const TEAM_ID = <?= $teamId ?>;

function esc(s){ if(!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function statCard(label, value, sub, cls, hint){
  return `<div class="stat-card ${cls||''}">
    <div class="stat-label">${label}${hint ? `<i class="bi bi-question-circle info-hint" title="${esc(hint)}"></i>` : ''}</div>
    <div class="stat-value ${cls==='hi-amber'?'amber':(cls==='hi-red'?'red':'')}">${value}</div>
    ${sub ? `<div class="stat-sub">${sub}</div>` : ''}
  </div>`;
}

const STATUS_LABELS = {
  dentro_do_cap: { text: 'Dentro do Cap', cls: 'ok' },
  over_the_cap:  { text: 'Over the Cap', cls: 'over' },
  abaixo_do_piso:{ text: 'Abaixo do Piso', cls: 'floor' },
};

async function loadCap(){
  try {
    const res = await fetch(`/api/cap.php?action=summary&team_id=${TEAM_ID}`);
    const data = await res.json();
    if (!data.success) {
      document.getElementById('statsGrid').innerHTML = `<div class="empty" style="grid-column:1/-1">${esc(data.error || 'Erro ao carregar o Cap.')}</div>`;
      document.getElementById('rosterBody').innerHTML = '';
      return;
    }
    const s = data.summary;

    const st = STATUS_LABELS[s.status] || { text: s.status, cls: 'ok' };
    const badge = document.getElementById('statusBadge');
    badge.textContent = st.text;
    badge.className = `status-badge ${st.cls}`;
    badge.style.display = 'inline-flex';

    document.getElementById('statsGrid').innerHTML = [
      statCard('Cap Base', s.cap_base + 'M', '', '', 'Piso fixo de toda franquia ELITE, antes de qualquer Cap Flex.'),
      statCard('Cap Flex', '+' + s.cap_flex_total + 'M',
        `${s.cap_flex_used_slots}/${s.cap_flex_max_players} vagas` + (s.cap_flex_eligible_count > s.cap_flex_max_players ? ` · ${s.cap_flex_eligible_count} elegíveis` : ''),
        '', `O Cap Flex vale para no máximo ${s.cap_flex_max_players} jogadores. Havendo mais elegíveis, contam os de maior valor.`),
      statCard('Cap Máximo', s.cap_max + 'M', '', 'hi-amber', 'Cap Base + Cap Flex — o teto real de folha salarial da sua franquia.'),
      statCard('Folha Salarial', s.payroll + 'M', '', s.payroll > s.cap_max ? 'hi-red' : '', 'Soma do salário total (base + bônus de prêmio) de todo o elenco.'),
      statCard('Espaço Disponível', s.space + 'M', '', s.space < 0 ? 'hi-red' : '', 'Cap Máximo menos a Folha Salarial. Negativo = acima do Cap.'),
      statCard('Cap Mínimo', s.cap_floor + 'M', 'informativo', '', 'Piso da liga — fica abaixo dele só é aplicado de verdade na Trade Deadline.'),
    ].join('');

    // Sugestões de como ficar no cap
    const sugg = s.suggestions || [];
    const ICONS = { ok:'check-circle-fill', danger:'exclamation-octagon-fill', warn:'exclamation-triangle-fill', info:'info-circle-fill', tip:'lightbulb-fill' };
    if (sugg.length) {
      document.getElementById('suggTitle').style.display = 'flex';
      document.getElementById('suggPanel').style.display = 'block';
      document.getElementById('suggList').innerHTML = sugg.map(x =>
        `<div class="suggestion ${x.type}"><i class="bi bi-${ICONS[x.type] || 'info-circle-fill'}"></i><span>${esc(x.text)}</span></div>`
      ).join('');
    }

    if (!s.roster.length) {
      document.getElementById('rosterBody').innerHTML = '<tr><td colspan="5" class="empty">Nenhum jogador no elenco.</td></tr>';
      return;
    }

    document.getElementById('rosterBody').innerHTML = s.roster.map(p => `
      <tr>
        <td>${esc(p.name)}</td>
        <td class="num">${p.ovr}</td>
        <td class="num">${p.base_salary}M</td>
        <td>
          ${p.is_rookie_scale ? '<span class="tag rookie" title="Salário definido pela Rookie Scale (posição do pick), não pela tabela de OVR, por ser a temporada de estreia.">Rookie Scale</span>' : ''}
          ${p.cap_flex_eligible ? (p.cap_flex_counted
            ? `<span class="tag flex" title="Ainda está no time que o draftou e o OVR qualifica — adiciona +${p.cap_flex_value}M ao Cap Máximo do time.">Cap Flex +${p.cap_flex_value}M</span>`
            : `<span class="tag flex-off" title="Qualifica para Cap Flex, mas o time já usou as ${s.cap_flex_max_players} vagas com jogadores de valor maior — este não soma ao Cap Máximo.">Cap Flex +${p.cap_flex_value}M (fora das vagas)</span>`) : ''}
          ${p.award_bonus > 0 ? `<span class="tag bonus" title="Bônus de prêmio da temporada anterior, vale só nesta temporada.">Bônus +${p.award_bonus}M</span>` : ''}
        </td>
        <td class="num">${p.total_salary}M</td>
      </tr>
    `).join('');
  } catch (e) {
    document.getElementById('statsGrid').innerHTML = '<div class="empty" style="grid-column:1/-1">Erro ao carregar o Cap.</div>';
  }
}
loadCap();

/* ── Simulador de trocas ─────────────────────────────
   Cada lado carrega o elenco pelo proprio endpoint de summary, entao os
   salarios mostrados aqui sao os mesmos da tabela acima. */
const simRosters = { a: [], b: [] };

async function simCarregarTimes() {
  const r = await fetch('/api/cap.php?action=teams');
  const d = await r.json();
  if (!d.success) return;
  const opts = '<option value="">Selecione…</option>' +
    d.teams.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
  document.getElementById('simTeamA').innerHTML = opts;
  document.getElementById('simTeamB').innerHTML = opts;
  // Ja abre com o time do usuario de um lado, que e o caso mais comum.
  document.getElementById('simTeamA').value = String(TEAM_ID);
  simCarregarElenco('a');
}

async function simCarregarElenco(lado) {
  const sel = document.getElementById(lado === 'a' ? 'simTeamA' : 'simTeamB');
  const box = document.getElementById(lado === 'a' ? 'simListA' : 'simListB');
  const teamId = parseInt(sel.value, 10);
  simRosters[lado] = [];
  if (!teamId) { box.innerHTML = '<div class="empty">Escolha um time</div>'; return; }

  box.innerHTML = '<div class="skeleton" style="height:120px;margin:8px"></div>';
  try {
    const r = await fetch(`/api/cap.php?action=summary&team_id=${teamId}`);
    const d = await r.json();
    if (!d.success) { box.innerHTML = '<div class="empty">Erro ao carregar o elenco</div>'; return; }
    simRosters[lado] = d.summary.roster || [];
    if (!simRosters[lado].length) { box.innerHTML = '<div class="empty">Elenco vazio</div>'; return; }
    box.innerHTML = simRosters[lado].map(p => `
      <label class="sim-row">
        <input type="checkbox" data-lado="${lado}" value="${p.id}">
        <span class="nm">${esc(p.name)}</span>
        <span class="ov">${p.ovr}</span>
        <span class="sal">${p.total_salary}M</span>
      </label>`).join('');
  } catch (e) {
    box.innerHTML = '<div class="empty">Erro ao carregar o elenco</div>';
  }
}

function simSelecionados(lado) {
  return [...document.querySelectorAll(`.sim-list input[data-lado="${lado}"]:checked`)].map(i => parseInt(i.value, 10));
}

function simDelta(antes, depois) {
  const d = depois - antes;
  if (d === 0) return '<span class="delta same">=</span>';
  return `<span class="delta ${d > 0 ? 'up' : 'down'}">${d > 0 ? '+' : ''}${d}M</span>`;
}

// Veredito da regra dos 120%: é o que decide se a troca pode sair.
function vereditoHtml(d, nomeA, nomeB) {
  if (d.valida) {
    const aplicou = d.matching.a.aplica || d.matching.b.aplica;
    return `<div class="sim-verdict ok">
      <i class="bi bi-check-circle-fill"></i>
      <div><strong>Troca permitida.</strong>
      ${aplicou
        ? ` O casamento salarial de ${d.matching.a.pct}% foi respeitado.`
        : ` Os dois times terminam dentro do teto, então a regra dos ${d.matching.a.pct}% nem precisa ser aplicada.`}</div>
    </div>`;
  }
  const problemas = [];
  [['a', nomeA], ['b', nomeB]].forEach(([k, nome]) => {
    const m = d.matching[k];
    if (m.ok) return;
    problemas.push(`<li><strong>${esc(nome)}</strong> ficaria acima do teto recebendo
      <strong>${m.recebido}M</strong> e enviando <strong>${m.enviado}M</strong>.
      O limite é ${m.limite_receber}M (${m.pct}% de ${m.enviado}M) — passou <strong>${m.excesso}M</strong>.
      Para valer, precisaria enviar pelo menos ${m.envio_minimo}M
      (mais ${m.falta_enviar}M) ou receber ${m.excesso}M a menos.</li>`);
  });
  return `<div class="sim-verdict bad">
    <i class="bi bi-x-octagon-fill"></i>
    <div><strong>Troca barrada pela regra dos ${d.matching.a.pct}%.</strong>
      <ul style="margin:6px 0 0 16px;padding:0">${problemas.join('')}</ul></div>
  </div>`;
}

function simCard(titulo, antes, depois, m) {
  const st = depois.status === 'over_the_cap'
    ? { cls: 'bad', txt: 'Acima do teto' }
    : depois.status === 'abaixo_do_piso'
      ? { cls: 'warn', txt: 'Abaixo do piso' }
      : { cls: 'ok', txt: 'Dentro do cap' };
  const linha = (lbl, a, b, sufixo = 'M') =>
    `<div class="sim-line"><span class="lbl">${lbl}</span>
       <span class="val">${a}${sufixo} → ${b}${sufixo} ${simDelta(a, b)}</span></div>`;

  let match = '';
  if (m) {
    match = m.aplica
      ? `<div class="sim-line"><span class="lbl">Envia / recebe</span>
           <span class="val">${m.enviado}M / ${m.recebido}M
           <span class="delta ${m.ok ? 'down' : 'up'}">limite ${m.limite_receber}M</span></span></div>`
      : `<div class="sim-line"><span class="lbl">Envia / recebe</span>
           <span class="val">${m.enviado}M / ${m.recebido}M
           <span class="delta same">sem trava</span></span></div>`;
  }

  return `
    <div class="sim-card">
      <h4>${esc(titulo)}</h4>
      ${linha('Folha salarial', antes.payroll, depois.payroll)}
      ${linha('Cap flex', antes.cap_flex_total, depois.cap_flex_total)}
      ${linha('Cap máximo', antes.cap_max, depois.cap_max)}
      ${linha('Espaço', antes.space, depois.space)}
      ${match}
      <span class="sim-status ${st.cls}"><i class="bi bi-circle-fill" style="font-size:6px"></i> ${st.txt}</span>
      ${m && !m.ok ? '<span class="sim-status bad" style="margin-left:6px"><i class="bi bi-x-octagon-fill" style="font-size:9px"></i> Estoura os ' + m.pct + '%</span>' : ''}
    </div>`;
}

async function simular() {
  const a = parseInt(document.getElementById('simTeamA').value, 10);
  const b = parseInt(document.getElementById('simTeamB').value, 10);
  const btn = document.getElementById('btnSimular');
  const out = document.getElementById('simResult');

  if (!a || !b) { alert('Escolha os dois times.'); return; }
  if (a === b) { alert('Escolha dois times diferentes.'); return; }
  const sendA = simSelecionados('a');
  const sendB = simSelecionados('b');
  if (!sendA.length && !sendB.length) { alert('Marque ao menos um jogador para trocar.'); return; }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Simulando…';
  try {
    const r = await fetch('/api/cap.php?action=simulate_trade', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ team_a: a, team_b: b, send_a: sendA, send_b: sendB })
    });
    const d = await r.json();
    if (!d.success) { alert(d.error || 'Erro ao simular.'); return; }

    const nomeA = document.getElementById('simTeamA').selectedOptions[0].textContent;
    const nomeB = document.getElementById('simTeamB').selectedOptions[0].textContent;
    const nomes = (ids, lado) => ids.length
      ? ids.map(id => (simRosters[lado].find(p => p.id === id) || {}).name).filter(Boolean).join(', ')
      : 'nada';

    out.style.display = 'block';
    out.innerHTML = `
      ${vereditoHtml(d, nomeA, nomeB)}
      <div style="font-size:12px;color:var(--text-2);margin:12px 0;line-height:1.6">
        <strong>${esc(nomeA)}</strong> envia: ${esc(nomes(sendA, 'a'))}<br>
        <strong>${esc(nomeB)}</strong> envia: ${esc(nomes(sendB, 'b'))}
      </div>
      <div class="sim-cards">
        ${simCard(nomeA, d.antes.a, d.depois.a, d.matching.a)}
        ${simCard(nomeB, d.antes.b, d.depois.b, d.matching.b)}
      </div>`;
    out.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  } catch (e) {
    alert('Erro ao simular a troca.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-calculator"></i> Simular troca';
  }
}

document.getElementById('simTeamA').addEventListener('change', () => simCarregarElenco('a'));
document.getElementById('simTeamB').addEventListener('change', () => simCarregarElenco('b'));
document.getElementById('btnSimular').addEventListener('click', simular);
document.getElementById('btnSimLimpar').addEventListener('click', () => {
  document.querySelectorAll('.sim-list input:checked').forEach(i => { i.checked = false; });
  document.getElementById('simResult').style.display = 'none';
});
simCarregarTimes();
<?php endif; ?>
</script>
</body>
</html>
