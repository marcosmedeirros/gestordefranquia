<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$user = getUserSession();
$pdo  = db();

$teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if (!$teamId) {
    $stmtOwn = $pdo->prepare("SELECT id FROM teams WHERE user_id = ? LIMIT 1");
    $stmtOwn->execute([$user['id']]);
    $own = $stmtOwn->fetch(PDO::FETCH_ASSOC);
    $teamId = $own ? (int)$own['id'] : 0;
}
if (!$teamId) { header('Location: teams.php'); exit; }

$stmtT = $pdo->prepare("SELECT CONCAT(city,' ',name) AS full_name, city, name, league, photo_url,
                               public_primary_color, public_secondary_color
                        FROM teams WHERE id = ?");
$stmtT->execute([$teamId]);
$teamInfo = $stmtT->fetch(PDO::FETCH_ASSOC);
if (!$teamInfo) { header('Location: teams.php'); exit; }

// Time do usuário logado — usado pelo cartão do menu lateral (pode ser outro time
// que não o exibido nesta página).
$stmtMine = $pdo->prepare("SELECT id, city, name, league, photo_url FROM teams WHERE user_id = ? LIMIT 1");
$stmtMine->execute([$user['id']]);
$team = $stmtMine->fetch(PDO::FETCH_ASSOC) ?: null;

// Identidade visual do time exibido (cai no vermelho padrão se não tiver cor)
$hexRe = '/^#[0-9a-fA-F]{6}$/';
$teamPrimary   = (!empty($teamInfo['public_primary_color'])   && preg_match($hexRe, $teamInfo['public_primary_color']))   ? $teamInfo['public_primary_color']   : '#fc0025';
$teamSecondary = (!empty($teamInfo['public_secondary_color']) && preg_match($hexRe, $teamInfo['public_secondary_color'])) ? $teamInfo['public_secondary_color'] : $teamPrimary;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#fc0025">
<title>Histórico · <?= htmlspecialchars($teamInfo['full_name']) ?></title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:color-mix(in srgb, var(--red) 10%, transparent);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;--border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--text:#f0f0f3;--text-2:#868690;--text-3:#7d7d85;--amber:#f59e0b;--green:#22c55e;--purple:#a855f7;--font:'Montserrat', sans-serif;--radius:14px;--radius-sm:10px;--sidebar-w:260px;--ease:cubic-bezier(.2,.8,.2,1);--t:200ms;--border-red:color-mix(in srgb, var(--red) 22%, transparent)}
/* Identidade visual do time exibido */
:root{--team-primary:<?= htmlspecialchars($teamPrimary) ?>;--team-secondary:<?= htmlspecialchars($teamSecondary) ?>}
/* O conteúdo adota as cores do time; o menu lateral mantém a identidade do app.
   Redeclarar --red aqui vence a herança do :root (inclusive o !important da cor
   do usuário), porque a declaração está no próprio elemento .main. */
.main{--red:var(--team-primary);--red-soft:color-mix(in srgb, var(--team-primary) 10%, transparent);--border-red:color-mix(in srgb, var(--team-primary) 22%, transparent)}
:root[data-theme="light"]{--bg:#f6f7fb;--panel:#ffffff;--panel-2:#f2f4f8;--panel-3:#e9edf4;--border:#e3e6ee;--border-md:#d7dbe6;--text:#111217;--text-2:#5b6270;--text-3:#657080}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.topbar{position:sticky;top:0;z-index:300;height:54px;background:var(--panel);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 16px;gap:12px}
.topbar-logo{width:30px;height:30px;border-radius:8px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;color:#fff}
.icon-btn{width:34px;height:34px;border-radius:10px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:15px;cursor:pointer;text-decoration:none;transition:all .2s}
.icon-btn:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
.content{padding:20px 32px 80px;width:100%}
.hero{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.hero-logo{width:72px;height:72px;border-radius:16px;background:var(--panel-2);border:1px solid var(--border-md);display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-size:28px;font-weight:700;color:#fff;flex-shrink:0;overflow:hidden}
.hero-logo img{width:100%;height:100%;object-fit:contain;border-radius:14px}
.hero-name{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700;color:var(--text)}
.league-badge{display:inline-block;background:var(--red-soft);border:1px solid color-mix(in srgb, var(--red) 25%, transparent);color:var(--red);border-radius:999px;font-size:10px;font-weight:700;padding:4px 10px;letter-spacing:.5px;margin-top:6px}
.section-title{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);margin-bottom:12px;display:flex;align-items:center;gap:8px}
.section-title i{color:var(--red)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-bottom:24px}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:4px}
.stat-card.hi-red{border-color:color-mix(in srgb, var(--red) 30%, transparent);background:color-mix(in srgb, var(--red) 5%, transparent)}
.stat-card.hi-amber{border-color:rgba(245,158,11,.3);background:rgba(245,158,11,.04)}
.stat-label{font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px}
.stat-value{font-family:'Oswald',sans-serif;font-size:28px;font-weight:700;color:var(--text);line-height:1}
.stat-value.red{color:var(--red)}.stat-value.amber{color:var(--amber)}.stat-value.green{color:var(--green)}
.stat-sub{font-size:11px;color:var(--text-2)}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.row{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border)}
.row:last-child{border-bottom:none}
.row-label{font-size:13px;color:var(--text)}
.row-val{font-family:'Oswald',sans-serif;font-size:18px;font-weight:700}
.pos-badge{width:34px;height:34px;border-radius:10px;background:var(--panel-2);border:1px solid var(--border-md);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:var(--red);flex-shrink:0}
.ovr-pill{background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:2px 8px;font-size:11px;font-weight:700}
.year-table{width:100%;border-collapse:collapse;font-size:12px}
.year-table th{padding:8px 10px;text-align:left;color:var(--text-3);font-weight:600;border-bottom:1px solid var(--border);font-size:11px;text-transform:uppercase;letter-spacing:.4px}
.year-table td{padding:8px 10px;border-bottom:1px solid var(--border)}
.year-table tr:last-child td{border-bottom:none}
.skeleton{background:linear-gradient(90deg,var(--panel-2) 25%,var(--panel-3) 50%,var(--panel-2) 75%);background-size:200% 100%;animation:shimmer 1.2s infinite;border-radius:8px}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.empty{text-align:center;padding:24px;color:var(--text-3);font-size:13px}
/* Abas da página do time */
/* Barra de ações do time */
.t-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:20px;padding-top:18px;border-top:1px solid var(--border)}
.act{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:10px;background:var(--panel);border:1px solid var(--border);color:var(--text-2);font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none;transition:all var(--t) var(--ease);white-space:nowrap}
.act:hover{border-color:var(--border-md);color:var(--text);background:var(--panel-2)}
.act i{font-size:14px}
.act-primary{background:var(--red);border-color:var(--red);color:#fff}
.act-primary:hover{background:var(--red);border-color:var(--red);color:#fff;filter:brightness(1.1)}
@media (max-width:640px){.act{padding:8px 11px;font-size:12px}}
.th-tabs{display:flex;gap:6px;overflow-x:auto;margin-bottom:16px;padding-bottom:2px}
.th-tab{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:9px 16px;color:var(--text-2);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all var(--t)}
.th-tab:hover{color:var(--text)}
.th-tab.active{background:var(--red-soft);border-color:var(--border-red);color:var(--red)}
.th-hidden{display:none !important}
.history-accordion{display:flex;flex-direction:column;gap:10px}
.history-acc-item{background:var(--panel);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.history-acc-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;background:transparent;border:0;color:var(--text);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;text-align:left}
.history-acc-toggle:hover{background:var(--red-soft)}
.history-acc-title{display:flex;align-items:center;gap:10px;min-width:0}
.history-acc-title i{color:var(--red);font-size:15px;flex-shrink:0}
.history-acc-badge{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:26px;padding:0 8px;border-radius:999px;background:var(--panel-2);border:1px solid var(--border);color:var(--text-2);font-size:11px;font-weight:800;flex-shrink:0}
.history-acc-chevron{transition:transform var(--t) var(--ease);color:var(--text-2)}
.history-acc-item.open .history-acc-chevron{transform:rotate(180deg)}
.history-acc-body{display:none;padding:0 16px 16px}
.history-acc-item.open .history-acc-body{display:block}
.trade-panel{background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:12px}
.ver-todos-btn{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;margin-top:10px;padding:9px 12px;border-radius:8px;background:var(--panel-2);border:1px solid var(--border);color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;transition:all .2s}
.ver-todos-btn:hover{border-color:var(--border-red);color:var(--red);background:var(--red-soft)}
@media(max-width:640px){
  .content{padding:18px 16px 72px}
  .panel{padding:16px 14px}
  .two-col{grid-template-columns:1fr}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .hero{padding:18px}
  .hero-name{font-size:18px}
  .section-title{font-size:12px;line-height:1.25;flex-wrap:wrap}
  .section-title span{display:block;width:100%}
  .history-acc-toggle{padding:12px 12px}
  .history-acc-body{padding:0 12px 12px}
  .year-table{font-size:11px}
  .row{gap:10px}
  .row-val{font-size:16px}
}
@media(max-width:430px){
  .stats-grid{grid-template-columns:1fr}
  .hero{gap:14px}
  .hero-logo{width:60px;height:60px;border-radius:14px}
  .history-acc-title{gap:8px}
  .history-acc-badge{min-width:24px;height:24px;font-size:10px}
  .trade-panel{padding:10px}
}

/* -- Layout com menu lateral -- */
    .app { display: flex; min-height: 100vh; }

    /* ── Sidebar ─────────────────────────────────── */
    .sidebar {
      position: fixed; top: 0; left: 0;
      width: 260px; height: 100vh;
      background: var(--panel); border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      z-index: 300; transition: transform var(--t) var(--ease);
      overflow-y: auto; scrollbar-width: none;
    }
    .sidebar::-webkit-scrollbar { display: none; }

    .sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
    .sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
    .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
    .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }

    .sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
    .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
    .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }

    .sb-season { margin: 10px 14px 0; background: var(--red-soft); border: 1px solid var(--border-red); border-radius: 8px; padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .sb-season-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); }
    .sb-season-val { font-size: 14px; font-weight: 700; color: var(--red); }

    .sb-nav { flex: 1; padding: 12px 10px 8px; }
    .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
    .sb-nav a { font-family:'Inter',sans-serif; display: flex; align-items: center; gap: 10px; padding: 10px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
    .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
    .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
    .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
    .sb-nav a.active i { color: var(--red); }
    .sb-theme-toggle { margin: 0 14px 12px; padding: 8px 10px; border-radius: 10px; border: 1px solid var(--border); background: var(--panel-2); color: var(--text); display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease); }
    .sb-theme-toggle:hover { border-color: var(--border-red); color: var(--red); }

    .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
    .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; flex-shrink: 0; }
    .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

    /* ── Topbar mobile ───────────────────────────── */
    .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 260; }
    .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
    .topbar-title em { color: var(--red); font-style: normal; }
    .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
    .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
    .sb-overlay.show { display: block; }

    /* ── Main ────────────────────────────────────── */
    .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }

/* Responsivo do layout com menu lateral */
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
  <div class="topbar-title">Histórico do <em>Clube</em></div>
  <a href="teams.php" class="icon-btn"><i class="bi bi-arrow-left"></i></a>
</header>

<main class="main">
 <div class="content">
  <!-- Hero -->
  <div class="hero">
    <div class="hero-logo">
      <?php if (!empty($teamInfo['photo_url'])): ?>
        <img src="<?= htmlspecialchars($teamInfo['photo_url']) ?>" alt="Logo">
      <?php else: ?>
        <?= mb_strtoupper(mb_substr($teamInfo['city'] ?? '?', 0, 1)) ?>
      <?php endif; ?>
    </div>
    <div style="min-width:0;flex:1">
      <div class="hero-name"><?= htmlspecialchars($teamInfo['full_name']) ?></div>
      <div style="font-size:12px;color:var(--text-2);margin-top:4px" id="hero-owner"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;align-items:center">
        <span class="league-badge"><?= htmlspecialchars($teamInfo['league']) ?></span>
        <span id="hero-conf" style="display:none"></span>
        <span id="hero-tag" style="display:none"></span>
      </div>
    </div>
    <div id="hero-chips" style="display:flex;gap:10px;flex-wrap:wrap;margin-left:auto"></div>
  </div>

  <div class="th-tabs" id="thTabs">
    <button class="th-tab active" data-tab="geral">Visão geral</button>
    <button class="th-tab" data-tab="elenco">Elenco</button>
    <button class="th-tab" data-tab="historico">Histórico</button>
    <button class="th-tab" data-tab="trades">Trades</button>
    <button class="th-tab" data-tab="legado">Legado</button>
    <button class="th-tab" data-tab="feed">Feed</button>
  </div>

  <div class="section-title" data-th-tab="geral"><i class="bi bi-bar-chart-fill"></i> Visão Geral</div>
  <div class="stats-grid" id="stats-grid" data-th-tab="geral">
    <?php for($i=0;$i<6;$i++): ?>
    <div class="stat-card"><div class="skeleton" style="height:10px;width:55%;margin-bottom:8px"></div><div class="skeleton" style="height:28px;width:45%"></div></div>
    <?php endfor; ?>
  </div>

  <div class="section-title" data-th-tab="geral"><i class="bi bi-trophy-fill"></i> Desempenho por Fase</div>
  <div class="panel" id="phases-panel" data-th-tab="geral"><div class="skeleton" style="height:180px"></div></div>

  <div id="franchise-health-wrap" data-th-tab="geral" style="display:none">
    <div class="section-title"><i class="bi bi-heart-pulse-fill"></i> Saúde da Franquia</div>
    <div class="two-col" id="franchise-health-grid"></div>
  </div>

  <div id="playoff-campaigns-wrap" data-th-tab="geral" style="display:none">
    <div class="section-title"><i class="bi bi-diagram-3-fill"></i> Campanhas de Playoff <span style="font-size:10px;font-weight:400;color:var(--text-3);text-transform:none;letter-spacing:0">(placar de cada série)</span></div>
    <div class="panel" id="playoff-campaigns-panel"></div>
  </div>

  <div class="two-col" data-th-tab="geral">
    <div class="panel">
      <div class="section-title"><i class="bi bi-person-fill"></i> Destaque</div>
      <div id="players-content"><div class="skeleton" style="height:80px"></div></div>
    </div>
    <div class="panel">
      <div class="section-title"><i class="bi bi-person-badge-fill"></i> GM</div>
      <div id="gm-content"><div class="skeleton" style="height:80px"></div></div>
    </div>
  </div>

  <div class="panel" data-th-tab="elenco">
    <div class="section-title"><i class="bi bi-clipboard-data"></i> Elenco atual
      <span id="roster-season" style="font-size:10px;font-weight:400;color:var(--text-3);text-transform:none;letter-spacing:0"></span>
    </div>
    <div id="roster-stats"><div class="skeleton" style="height:160px"></div></div>
  </div>

  <div class="panel" data-th-tab="elenco">
    <div class="section-title"><i class="bi bi-people-fill"></i> Melhor por Posição</div>
    <div id="best-by-pos"><div class="skeleton" style="height:160px"></div></div>
  </div>

  <div class="panel" id="best-roster-panel" style="display:none" data-th-tab="elenco">
    <div class="section-title"><i class="bi bi-gem"></i> Melhor Elenco Já Montado <span id="best-roster-year" style="font-size:10px;font-weight:400;color:var(--text-3);text-transform:none;letter-spacing:0"></span></div>
    <div id="best-roster-content"></div>
  </div>

  <div class="panel" id="legends-panel" style="display:none" data-th-tab="legado">
    <div class="section-title"><i class="bi bi-star-fill" style="color:var(--amber)"></i> Hall dos Aposentados <span style="font-size:10px;font-weight:400;color:var(--text-3);text-transform:none;letter-spacing:0">(bateram 86+ de OVR alguma vez e já saíram da liga)</span></div>
    <div id="legends-content"></div>
  </div>

  <div class="panel" data-th-tab="trades">
    <div class="section-title"><i class="bi bi-arrow-left-right"></i> Trades</div>
    <div class="history-accordion">
      <div class="history-acc-item open" id="cycle-acc-item" style="display:none">
        <button type="button" class="history-acc-toggle" data-target="cycle-panel">
          <span class="history-acc-title"><i class="bi bi-arrow-repeat"></i><span>Trades por Ciclo</span></span>
          <span style="display:flex;align-items:center;gap:10px"><span class="history-acc-badge" id="cycle-count-badge">0</span><i class="bi bi-chevron-down history-acc-chevron"></i></span>
        </button>
        <div class="history-acc-body">
          <div class="trade-panel" id="cycle-panel"><div id="cycle-content"></div></div>
        </div>
      </div>
      <div class="history-acc-item" id="partner-acc-item" style="display:none">
        <button type="button" class="history-acc-toggle" data-target="partner-panel">
          <span class="history-acc-title"><i class="bi bi-people"></i><span>Trades por Time</span></span>
          <span style="display:flex;align-items:center;gap:10px"><span class="history-acc-badge" id="partner-count-badge">0</span><i class="bi bi-chevron-down history-acc-chevron"></i></span>
        </button>
        <div class="history-acc-body">
          <div class="trade-panel" id="partner-panel"><div id="partner-content"></div></div>
        </div>
      </div>
    </div>
  </div>

  <div class="panel" id="league-stats-panel" style="display:none" data-th-tab="geral">
    <div class="section-title"><i class="bi bi-graph-up-arrow"></i> Estatísticas na Liga <span style="font-size:10px;font-weight:400;color:var(--text-3);text-transform:none;letter-spacing:0">(valor do time e posição entre os times da liga)</span></div>
    <div id="league-stats-content"></div>
  </div>

  <div class="panel" id="positions-panel" style="display:none" data-th-tab="historico">
    <div class="section-title"><i class="bi bi-list-ol"></i> Classificação por Temporada</div>
    <div id="positions-content"></div>
  </div>

  <div class="panel" data-th-tab="historico">
    <div class="section-title"><i class="bi bi-graph-up"></i> Evolução por Temporada <span style="font-size:10px;font-weight:400;color:var(--text-3);text-transform:none;letter-spacing:0">(top 5 titulares)</span></div>
    <div id="avg-by-year"><div class="skeleton" style="height:100px"></div></div>
  </div>

  <div class="panel" id="drafted-panel" style="display:none" data-th-tab="elenco">
    <div class="section-title"><i class="bi bi-star-fill" style="color:var(--purple)"></i> Draftados pelo Clube</div>
    <div id="drafted-content"></div>
  </div>

  <div class="panel" data-th-tab="historico">
    <div class="section-title"><i class="bi bi-award-fill"></i> Prêmios Individuais</div>
    <div id="awards-content"><div class="skeleton" style="height:50px"></div></div>
  </div>

  <div class="panel" id="champ-panel" style="display:none" data-th-tab="elenco">
    <div class="section-title"><i class="bi bi-stars"></i> Elenco Campeão — <span id="champ-year"></span></div>
    <div id="champ-roster"></div>
  </div>

  <div data-th-tab="feed">
    <div id="feed-stories-bar"></div>
    <div id="feed-composer" style="display:none"></div>
    <div class="panel">
      <div class="section-title"><i class="bi bi-collection-play-fill"></i> Feed do Time</div>
      <div id="feed-list"><div class="skeleton" style="height:120px"></div></div>
      <button type="button" class="ver-todos-btn" id="feed-load-more" style="display:none">Carregar mais</button>
    </div>
  </div>

  <?php // Fica no fim: as acoes sao o passo seguinte a ler o historico.
    $ehMeuTime = $team && (int)$team['id'] === $teamId; ?>
  <div class="t-actions">
    <?php if ($ehMeuTime): ?>
      <a class="act act-primary" href="/my-roster.php"><i class="bi bi-people-fill"></i> Gerenciar elenco</a>
      <a class="act" href="/trades.php"><i class="bi bi-arrow-left-right"></i> Minhas trades</a>
      <a class="act" href="/picks.php"><i class="bi bi-ticket-perforated"></i> Minhas picks</a>
      <a class="act" href="/team-public-page.php"><i class="bi bi-globe2"></i> Página pública</a>
    <?php else: ?>
      <?php /* Simular e propor viraram a mesma tela (Trade Machine), entao um botao so. */ ?>
      <a class="act act-primary" href="/trade-simulator.php?team_id=<?= $teamId ?>">
        <i class="bi bi-arrow-left-right"></i> Montar troca</a>
    <?php endif; ?>
    <button class="act" id="btnShareTeam" title="Copiar o link desta página"><i class="bi bi-link-45deg"></i> <span>Copiar link</span></button>
  </div>
 </div><!-- .content -->
</main>
</div><!-- .app -->

<script>
// Menu lateral no mobile
(function(){
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sbOverlay');
  const menuBtn = document.getElementById('menuBtn');
  if (menuBtn) menuBtn.addEventListener('click', () => { sidebar?.classList.add('open'); overlay?.classList.add('show'); });
  if (overlay) overlay.addEventListener('click', () => { sidebar?.classList.remove('open'); overlay.classList.remove('show'); });
})();

/* Abas: apenas mostram/escondem as seções (nenhum bloco muda de lugar).
   Painéis que o próprio JS esconde por falta de dados continuam escondidos. */
(function(){
  const tabs = document.getElementById('thTabs');
  if (!tabs) return;
  function apply(tab){
    document.querySelectorAll('[data-th-tab]').forEach(el => {
      el.classList.toggle('th-hidden', el.dataset.thTab !== tab);
    });
    tabs.querySelectorAll('.th-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  }
  tabs.addEventListener('click', e => {
    const btn = e.target.closest('.th-tab');
    if (btn) apply(btn.dataset.tab);
  });
  apply('geral');
})();

const TEAM_ID = <?= $teamId ?>;
const AWARD_LABELS = { mvp:'MVP', dpoy:'DPOY', mip:'MIP', '6th_man':'6º Homem', roy:'ROY' };
const POS_LABELS   = { PG:'Armador', SG:'Ala-Armador', SF:'Ala', PF:'Ala-Pivô', C:'Pivô' };
const TEAM_TAG_META = {
  Contending: { label: '🏆 Contending', color: '#10b981', bg: 'rgba(16,185,129,.12)' },
  Buying:     { label: '📈 Buying',     color: '#3b82f6', bg: 'rgba(59,130,246,.12)' },
  Selling:    { label: '📦 Selling',    color: '#f97316', bg: 'rgba(249,115,22,.12)' },
  Rebuilding: { label: '🔧 Rebuilding', color: '#64748b', bg: 'rgba(100,116,139,.12)' },
};
function chip(label, value, color){
  return `<div style="background:var(--panel-2);border:1px solid var(--border);border-radius:10px;padding:8px 14px;text-align:center;min-width:78px">
    <div style="font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;color:${color||'var(--text)'}">${value}</div>
    <div style="font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-3)">${label}</div>
  </div>`;
}

function esc(s){ if(!s)return''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function statCard(label, value, sub='', cls=''){
  return `<div class="stat-card ${cls}">
    <div class="stat-label">${label}</div>
    <div class="stat-value">${value ?? '—'}</div>
    ${sub ? `<div class="stat-sub">${sub}</div>` : ''}
  </div>`;
}

function row(icon, label, val, color='var(--text)'){
  if(!val) return '';
  return `<div class="row">
    <span class="row-label">${icon} ${label}</span>
    <span class="row-val" style="color:${color}">${val}×</span>
  </div>`;
}

async function load(){
  const res  = await fetch(`/api/team-stats.php?team_id=${TEAM_ID}`);
  const data = await res.json();
  if(!data.success){ document.querySelector('main').innerHTML='<div class="empty">Erro ao carregar dados.</div>'; return; }

  const { team, seasons, playoffs, regular, picks, trades, players, drafted, awards, gm, positions, cap_summary: capSummary, punishments } = data;
  const bestRoster = data.best_roster, tradesByCycle = data.trades_by_cycle || [],
        tradesByPartner = data.trades_by_partner || [], leagueStats = data.league_stats || {},
        retiredLegends = data.retired_legends || [];

  document.querySelectorAll('.history-acc-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const item = btn.closest('.history-acc-item');
      if (!item) return;
      const isOpen = item.classList.contains('open');
      document.querySelectorAll('.history-acc-item.open').forEach(openItem => {
        if (openItem !== item) openItem.classList.remove('open');
      });
      item.classList.toggle('open', !isOpen);
    });
  });

  document.getElementById('hero-owner').textContent = team.owner_name ? `GM: ${team.owner_name}` : '';

  // ── Hero: conferência, status da franquia, ranking e moedas ──
  if (team.conference) {
    const confEl = document.getElementById('hero-conf');
    confEl.style.display = 'inline-block';
    confEl.className = 'league-badge';
    confEl.style.background = 'var(--panel-2)'; confEl.style.color = 'var(--text-2)'; confEl.style.borderColor = 'var(--border-md)';
    confEl.textContent = team.conference;
  }
  if (team.team_tag && TEAM_TAG_META[team.team_tag]) {
    const m = TEAM_TAG_META[team.team_tag];
    const tagEl = document.getElementById('hero-tag');
    tagEl.style.display = 'inline-flex';
    tagEl.style.cssText += `background:${m.bg};color:${m.color};border:1px solid ${m.color}44;border-radius:999px;font-size:11px;font-weight:700;padding:4px 10px`;
    tagEl.textContent = m.label;
  }
  const chips = [];
  if (team.ranking_points) chips.push(chip('Ranking', team.ranking_points, 'var(--red)'));
  if (team.ranking_titles) chips.push(chip('Títulos Liga', team.ranking_titles, 'var(--amber)'));
  if (team.moedas) chips.push(chip('Moedas', team.moedas, '#f59e0b'));
  document.getElementById('hero-chips').innerHTML = chips.join('');

  // ── Visão Geral ──
  const pct = seasons.played > 0 ? Math.round((playoffs.appearances/seasons.played)*100) : 0;
  document.getElementById('stats-grid').innerHTML = [
    statCard('Temporadas',   seasons.played,        ''),
    statCard('Pontos Total', seasons.total_points,  `Melhor: ${seasons.best_pts} pts`),
    statCard('Playoffs',     playoffs.appearances,  `${pct}% das temp.`),
    statCard('Títulos',      playoffs.titles,       playoffs.runner_ups>0?`${playoffs.runner_ups}× vice`:'', playoffs.titles>0?'hi-red':''),
    statCard('Trocas',       trades,                ''),
    statCard('Picks trocadas', picks.traded,          ''),
  ].join('');

  // ── Fases ──
  const phases = [
    { icon:'🏆', label:'Títulos',         val:playoffs.titles,      color:'var(--amber)' },
    { icon:'🥈', label:'Vices',           val:playoffs.runner_ups,  color:'#94a3b8' },
    { icon:'🔥', label:'Final de Conf.',  val:playoffs.conf_finals, color:'var(--red)' },
    { icon:'⚡', label:'2ª Rodada',       val:playoffs.second_round,color:'var(--text)' },
    { icon:'🎯', label:'1ª Rodada',       val:playoffs.first_round, color:'var(--text)' },
    { icon:'📊', label:'Regular Top 8',  val:regular.top8,         color:'var(--text-2)' },
    { icon:'🔝', label:'Regular Top 4',  val:regular.top4,         color:'var(--text-2)' },
    { icon:'1️⃣',  label:'1° da Regular',  val:regular.top1,         color:'var(--green)' },
  ];
  const phaseHTML = phases.map(p => row(p.icon, p.label, p.val, p.color)).join('');
  document.getElementById('phases-panel').innerHTML = phaseHTML || '<div class="empty">Nenhum dado ainda</div>';

  // ── Saúde da Franquia: Salary Cap (ELITE) + Reputação/Punições ──
  (function(){
    const wrap = document.getElementById('franchise-health-wrap');
    const grid = document.getElementById('franchise-health-grid');
    if (!wrap || !grid) return;
    let html = '';

    if (capSummary) {
      const overCap = capSummary.payroll > capSummary.cap_max;
      const STATUS_LABEL = { dentro_do_cap: 'Dentro do cap', over_the_cap: 'Acima do teto', abaixo_do_piso: 'Abaixo do piso' };
      html += `<div class="panel">
        <div class="section-title" style="margin-bottom:10px"><i class="bi bi-cash-stack"></i> Salary Cap</div>
        <div class="row"><span class="row-label">Folha salarial</span><span class="row-val" style="color:${overCap?'#ef4444':'var(--text)'}">${capSummary.payroll}M</span></div>
        <div class="row"><span class="row-label">Cap máximo</span><span class="row-val" style="color:var(--amber)">${capSummary.cap_max}M</span></div>
        <div class="row"><span class="row-label">Espaço disponível</span><span class="row-val" style="color:${capSummary.space<0?'#ef4444':'var(--green)'}">${capSummary.space}M</span></div>
        <div class="row" style="border:none"><span class="row-label">Status</span><span style="font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;background:${overCap?'rgba(239,68,68,.12)':'rgba(34,197,94,.12)'};color:${overCap?'#ef4444':'var(--green)'}">${STATUS_LABEL[capSummary.status]||capSummary.status}</span></div>
        <a href="/cap.php" style="font-size:11px;color:var(--text-3);display:inline-block;margin-top:8px">Ver detalhes do cap →</a>
      </div>`;
    }

    const pu = punishments || { total: 0, active: 0, recent: [] };
    const puColor = pu.active > 0 ? '#ef4444' : 'var(--green)';
    html += `<div class="panel">
      <div class="section-title" style="margin-bottom:10px"><i class="bi bi-shield-exclamation"></i> Reputação</div>
      <div class="row"><span class="row-label">Punições ativas</span><span class="row-val" style="color:${puColor}">${pu.active}</span></div>
      <div class="row" style="border:none"><span class="row-label">Histórico total</span><span class="row-val">${pu.total}</span></div>
      ${pu.recent.length ? `<div style="margin-top:10px;display:flex;flex-direction:column;gap:6px">
        ${pu.recent.slice(0,3).map(p => `<div style="font-size:11px;color:var(--text-2);display:flex;justify-content:space-between;gap:8px">
          <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.punishment_label || p.motive || p.type || 'Punição')}</span>
          <span style="color:var(--text-3);flex-shrink:0">${p.reverted_at ? 'revertida' : ''}</span>
        </div>`).join('')}</div>` : '<div style="font-size:11px;color:var(--text-3);margin-top:8px">Nenhuma punição registrada.</div>'}
    </div>`;

    grid.innerHTML = html;
    wrap.style.display = html ? 'block' : 'none';
  })();

  // ── Classificação por temporada (posição final na conferência) ──
  (function(){
    const box = document.getElementById('positions-content');
    const panel = document.getElementById('positions-panel');
    if (!box || !panel) return;
    if (!positions || !positions.length) return;
    panel.style.display = 'block';
    box.innerHTML = `<div style="overflow-x:auto"><table class="year-table">
      <thead><tr><th>Temporada</th><th>Conferência</th><th>Posição</th><th>Playoffs</th></tr></thead>
      <tbody>${positions.slice().reverse().map(p => `
        <tr>
          <td style="font-weight:700">${p.year ?? ('T'+p.season_number)}</td>
          <td style="color:var(--text-2)">${esc(p.conference || '—')}</td>
          <td><span class="ovr-pill" style="color:${p.made_playoffs?'var(--green)':'var(--text)'}">${p.position}º${p.conference_size?' de '+p.conference_size:''}</span></td>
          <td>${p.made_playoffs ? '<span style="color:var(--green);font-size:12px"><i class="bi bi-check-circle-fill"></i> Sim</span>' : '<span style="color:var(--text-3);font-size:12px">Não</span>'}</td>
        </tr>`).join('')}</tbody>
    </table></div>`;
  })();

  // ── Campanhas de Playoff (placar de cada série) ──
  (function(){
    const camps = playoffs.campaigns || [];
    const wrap = document.getElementById('playoff-campaigns-wrap');
    if (!wrap) return;
    if (!camps.length) { wrap.style.display = 'none'; return; }
    const RES = { champion:'Campeão 🏆', runner_up:'Vice-campeão', conference_final:'Final de Conferência', second_round:'Semifinal', first_round:'1ª Rodada' };
    const RLBL = { r1:'1ª Rodada', r2:'Semifinal', cf:'Final Conf.', fin:'Final' };
    document.getElementById('playoff-campaigns-panel').innerHTML = camps.map(c => {
      const title = c.year ? `${c.year}` : (c.season_number ? `Temporada ${c.season_number}` : '—');
      const resLabel = RES[c.result] || 'Playoffs';
      const series = (c.series || []).map(s => `
        <span style="display:inline-flex;align-items:center;gap:5px;background:var(--panel-2);border:1px solid ${s.won?'rgba(34,197,94,.3)':'rgba(239,68,68,.3)'};border-radius:8px;padding:3px 9px;font-size:12px">
          <span style="color:var(--text-3);font-size:11px">${RLBL[s.round]||s.round}</span>
          <b style="color:${s.won?'#22c55e':'#ef4444'}">${s.score}</b>
        </span>`).join('');
      return `<div style="padding:9px 0;border-bottom:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
          <b style="font-family:'Oswald',sans-serif;font-size:14px">${title}</b>
          <span style="font-size:11px;color:var(--text-2)">${resLabel}</span>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">${series}</div>
      </div>`;
    }).join('');
    wrap.style.display = '';
  })();

  // ── Destaque ──
  let pHTML = '';
  if(players.best) pHTML += `<div class="row">
    <div><div style="font-size:13px;font-weight:600">🏆 ${esc(players.best.player_name)}</div>
    <div style="font-size:11px;color:var(--text-2)">${esc(players.best.position)} · ${players.best.year??''}</div></div>
    <span class="row-val" style="color:var(--green)">${players.best.ovr} OVR</span>
  </div>`;
  if(players.worst) pHTML += `<div class="row">
    <div><div style="font-size:13px;font-weight:600">📉 ${esc(players.worst.player_name)}</div>
    <div style="font-size:11px;color:var(--text-2)">${esc(players.worst.position)} · ${players.worst.year??''}</div></div>
    <span class="row-val" style="color:#ef4444">${players.worst.ovr} OVR</span>
  </div>`;
  pHTML += `<div class="row" style="border:none;padding-top:12px">
    <span style="font-size:12px;color:var(--text-2)">Total passaram pelo clube</span>
    <span style="font-family:'Oswald',sans-serif;font-size:16px;font-weight:700">${players.total_ever}</span>
  </div>`;
  document.getElementById('players-content').innerHTML = pHTML;

  // ── GM ──
  const gmRows = [
    { icon:'👋', label:'Tapas usados',   val: gm.tapas_used },
    { icon:'💰', label:'FBA Points',     val: gm.fba_points },
    { icon:'🔑', label:'Acessos',        val: gm.logins },
  ].filter(r => r.val > 0);
  document.getElementById('gm-content').innerHTML = gmRows.length
    ? gmRows.map(r=>`<div class="row"><span style="font-size:13px">${r.icon} ${r.label}</span><span class="row-val">${r.val}</span></div>`).join('')
    : '<div class="empty">Nenhum dado do GM</div>';

  // ── Melhor por posição ──
  const bpHTML = ['PG','SG','SF','PF','C'].filter(p => players.best_by_pos[p]).map(pos => {
    const p = players.best_by_pos[pos];
    return `<div class="row">
      <div style="display:flex;align-items:center;gap:10px">
        <div class="pos-badge">${pos}</div>
        <div><div style="font-size:13px;font-weight:600">${esc(p.player_name)}</div>
        <div style="font-size:11px;color:var(--text-3)">${POS_LABELS[pos]??pos} · ${p.year??''}</div></div>
      </div>
      <span class="ovr-pill">${p.ovr} OVR</span>
    </div>`;
  }).join('');
  document.getElementById('best-by-pos').innerHTML = bpHTML || '<div class="empty">Nenhum dado ainda</div>';

  // ── Evolução (top 5 titulares) ──
  const ay = players.avg_by_year;
  document.getElementById('avg-by-year').innerHTML = ay.length
    ? `<div style="overflow-x:auto"><table class="year-table">
        <thead><tr><th>Ano</th><th>Titulares</th><th>OVR Médio</th><th>Idade Média</th></tr></thead>
        <tbody>${ay.map(r=>`<tr>
          <td style="font-weight:700">${r.year}</td>
          <td style="color:var(--text-2)">${r.players}</td>
          <td><span style="font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;color:var(--green)">${r.avg_ovr}</span></td>
          <td><span style="font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;color:var(--amber)">${r.avg_age}</span></td>
        </tr>`).join('')}</tbody>
      </table></div>`
    : '<div class="empty">Nenhum histórico ainda</div>';


  // ── Melhor elenco já montado ──
  if (bestRoster && bestRoster.players && bestRoster.players.length) {
    document.getElementById('best-roster-panel').style.display = 'block';
    document.getElementById('best-roster-year').textContent =
      `— ${bestRoster.year ?? '—'} · média ${bestRoster.avg_ovr} OVR (top 5)`;
    document.getElementById('best-roster-content').innerHTML =
      `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px">
        ${bestRoster.players.map((p, i) => `
          <div style="display:flex;align-items:center;gap:8px;background:var(--panel-2);border:1px solid ${i < 5 ? 'var(--border-red)' : 'var(--border)'};border-radius:10px;padding:8px 10px">
            <span class="pos-badge" style="width:28px;height:28px;font-size:9px">${esc(p.position || '-')}</span>
            <div style="min-width:0;flex:1">
              <div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.player_name)}</div>
              <div style="font-size:10px;color:var(--text-3)">${p.age ?? '-'} anos</div>
            </div>
            <span style="font-family:'Oswald',sans-serif;font-size:15px;font-weight:700;color:var(--red)">${p.ovr}</span>
          </div>`).join('')}
      </div>
      <div style="font-size:11px;color:var(--text-3);margin-top:10px">Destacados = os 5 que definiram a média da temporada.</div>`;
  }

  // ── Hall dos aposentados ──
  // Jogadores que já passaram pelo time, bateram 86+ de OVR em algum momento
  // (não precisa ter sido no fim da carreira) e já saíram da liga de vez.
  if (retiredLegends.length) {
    document.getElementById('legends-panel').style.display = 'block';
    document.getElementById('legends-content').innerHTML =
      `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px">
        ${retiredLegends.map(p => {
          const periodo = p.first_year && p.last_year
            ? (p.first_year === p.last_year ? p.first_year : `${p.first_year}–${p.last_year}`)
            : '';
          return `
          <div style="display:flex;align-items:center;gap:10px;background:var(--panel-2);border:1px solid rgba(245,158,11,.3);border-radius:10px;padding:10px 12px">
            <i class="bi bi-star-fill" style="color:var(--amber);font-size:14px;flex-shrink:0"></i>
            <div style="min-width:0;flex:1">
              <div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.player_name)}</div>
              ${periodo ? `<div style="font-size:10px;color:var(--text-3)">${periodo}</div>` : ''}
            </div>
            <span style="font-family:'Oswald',sans-serif;font-size:16px;font-weight:800;color:var(--amber)">${p.peak_ovr}</span>
          </div>`;
        }).join('')}
      </div>
      <div style="font-size:11px;color:var(--text-3);margin-top:10px">OVR = o pico que o jogador atingiu enquanto esteve no time, não o valor no momento em que saiu.</div>`;
  }

  // ── Trades por ciclo ──
  if (tradesByCycle.length) {
    document.getElementById('cycle-acc-item').style.display = 'block';
    document.getElementById('cycle-count-badge').textContent = tradesByCycle.reduce((acc, c) => acc + (c.total || 0), 0);
    const maxC = Math.max(...tradesByCycle.map(c => c.total), 1);
    document.getElementById('cycle-content').innerHTML = tradesByCycle.map(c => `
      <div style="display:flex;align-items:center;gap:10px;padding:6px 0">
        <span style="font-size:12px;color:var(--text-2);width:64px;flex-shrink:0">${c.cycle ? 'Ciclo ' + c.cycle : 'Sem ciclo'}</span>
        <div style="flex:1;height:8px;background:var(--panel-3);border-radius:999px;overflow:hidden">
          <div style="height:100%;width:${Math.round((c.total / maxC) * 100)}%;background:var(--red);border-radius:999px"></div>
        </div>
        <span style="font-family:'Oswald',sans-serif;font-size:15px;font-weight:700;width:24px;text-align:right">${c.total}</span>
      </div>`).join('');
  }

  // ── Trades por time parceiro (mostra 8, com opção de ver todos) ──
  if (tradesByPartner.length) {
    document.getElementById('partner-acc-item').style.display = 'block';
    document.getElementById('partner-count-badge').textContent = tradesByPartner.length;
    const LIMITE = 8;
    const linhaParceiro = p => `
      <div class="row">
        <div style="display:flex;align-items:center;gap:8px;min-width:0">
          <img src="${esc(p.photo_url || '/img/default-team.png')}" alt="" style="width:24px;height:24px;border-radius:6px;object-fit:cover;flex-shrink:0" onerror="this.src='/img/default-team.png'">
          <span style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.team_name)}</span>
        </div>
        <span class="row-val" style="font-size:15px;color:var(--red)">${p.total}</span>
      </div>`;
    const alvo = document.getElementById('partner-content');
    let expandido = false;
    const desenhar = () => {
      const lista = expandido ? tradesByPartner : tradesByPartner.slice(0, LIMITE);
      const restantes = tradesByPartner.length - LIMITE;
      alvo.innerHTML = lista.map(linhaParceiro).join('')
        + (restantes > 0 ? `
          <button type="button" id="partner-toggle" class="ver-todos-btn">
            <i class="bi bi-chevron-${expandido ? 'up' : 'down'}"></i>
            ${expandido ? 'Ver menos' : `Ver todos os ${tradesByPartner.length} times`}
          </button>` : '');
      const btn = document.getElementById('partner-toggle');
      if (btn) btn.addEventListener('click', () => { expandido = !expandido; desenhar(); });
    };
    desenhar();
  }

  // ── Estatísticas na liga ──
  const LS_LABELS = {
    avg_ovr:  { label: 'OVR médio do elenco',   icon: '📊' },
    avg_age:  { label: 'Idade média',            icon: '🎂' },
    drafted:  { label: 'Jogadores draftados',    icon: '🎓' },
    turnover: { label: 'Passaram pelo clube',    icon: '🔁' },
    fa:       { label: 'Contratações na FA',     icon: '🖊️' },
  };
  const lsRows = Object.keys(LS_LABELS)
    .filter(k => leagueStats[k] && leagueStats[k].value !== null)
    .map(k => {
      const s = leagueStats[k], meta = LS_LABELS[k];
      const top3 = s.rank && s.rank <= 3;
      return `<div class="row">
        <span class="row-label">${meta.icon} ${meta.label}</span>
        <span style="display:flex;align-items:center;gap:10px">
          <span class="row-val">${s.value}</span>
          ${s.rank ? `<span style="font-size:10px;font-weight:700;padding:3px 8px;border-radius:999px;background:${top3 ? 'var(--red-soft)' : 'var(--panel-3)'};border:1px solid ${top3 ? 'var(--border-red)' : 'var(--border)'};color:${top3 ? 'var(--red)' : 'var(--text-3)'}">${s.rank}º de ${s.total}</span>` : ''}
        </span>
      </div>`;
    }).join('');
  if (lsRows) {
    document.getElementById('league-stats-panel').style.display = 'block';
    document.getElementById('league-stats-content').innerHTML = lsRows;
  }

  // ── Draftados ──
  if(drafted && drafted.length > 0){
    document.getElementById('drafted-panel').style.display = 'block';
    document.getElementById('drafted-content').innerHTML =
      `<div style="overflow-x:auto"><table class="year-table">
        <thead><tr><th>Jogador</th><th>Pos</th><th>OVR</th><th>Temporada</th></tr></thead>
        <tbody>${drafted.map(p=>`<tr>
          <td style="font-weight:600">${esc(p.name)}</td>
          <td style="color:var(--text-3)">${esc(p.position)}</td>
          <td><span style="font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;color:var(--purple)">${p.ovr}</span></td>
          <td style="color:var(--text-3)">${p.drafted_season_number ? 'T'+p.drafted_season_number : '—'}</td>
        </tr>`).join('')}</tbody>
      </table></div>`;
  }

  // ── Prêmios ──
  document.getElementById('awards-content').innerHTML = awards.length
    ? awards.map(a=>`<div class="row"><span style="font-size:13px;font-weight:600">🏅 ${AWARD_LABELS[a.award_type]||a.award_type}</span><span class="row-val" style="color:var(--amber)">${a.total}×</span></div>`).join('')
    : '<div class="empty">Nenhum prêmio individual ainda</div>';

  // ── Elenco campeão ──
  if(playoffs.champ_seasons.length>0 && playoffs.champ_roster.length>0){
    document.getElementById('champ-panel').style.display='block';
    document.getElementById('champ-year').textContent = playoffs.champ_seasons[0].year ?? playoffs.champ_seasons[0].season_number;
    document.getElementById('champ-roster').innerHTML = playoffs.champ_roster.map(p=>`
      <div class="row">
        <div style="display:flex;align-items:center;gap:10px">
          <div class="pos-badge">${esc(p.position)}</div>
          <span style="font-size:13px;font-weight:600">${esc(p.player_name)}</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
          <span class="ovr-pill">${p.ovr} OVR</span>
          <span style="font-size:11px;color:var(--text-3)">${p.age?p.age+'a':''}</span>
        </div>
      </div>`).join('');
  }
}

load();

/* Elenco atual com as estatisticas da temporada */
(async function carregarElencoAtual() {
  const box = document.getElementById('roster-stats');
  if (!box) return;
  try {
    const r = await fetch(`/api/player_stats.php?action=team_roster_stats&team_id=${TEAM_ID}`);
    const d = await r.json();
    if (!d.success || !(d.players || []).length) {
      box.innerHTML = '<div class="empty">Nenhum jogador no elenco.</div>';
      return;
    }
    const lbl = document.getElementById('roster-season');
    if (lbl && d.season_number) lbl.textContent = `— temporada ${d.season_number}`;

    // Sem estatistica registrada, mostra o traco em vez de zero: zero seria
    // "jogou e nao pontuou", que e diferente de "ninguem preencheu ainda".
    const n = v => (v === null || v === undefined) ? '—'
      : String(Number(v)).replace('.', ',');

    const isLoyal = p => Number(p.is_loyal ?? (Number(p.was_traded ?? 1) === 0 ? 1 : 0)) === 1;
    const isCapBonus = p => Number(p.cap_bonus_eligible) === 1;
    const loyalTag = p => isLoyal(p) ? '<span style="background:rgba(6,182,212,.15);color:#06b6d4;border:1px solid rgba(6,182,212,.35);border-radius:999px;font-size:9px;font-weight:700;padding:1px 6px;margin-left:5px">Leal</span>' : '';
    const nameColor = p => isCapBonus(p) ? `color:${p.player_tag_color || '#f59e0b'};` : '';

    const linhas = d.players.map(p => `
      <tr>
        <td><a href="/player.php?id=${p.id}" style="${nameColor(p)}text-decoration:none">${esc(p.name)}</a>${loyalTag(p)}</td>
        <td style="color:var(--text-2)">${esc(p.position || '')}${p.secondary_position ? '/' + esc(p.secondary_position) : ''}</td>
        <td style="text-align:center;font-weight:700">${p.ovr ?? '—'}</td>
        <td style="text-align:center;color:var(--text-2)">${p.age ?? '—'}</td>
        <td style="text-align:center">${n(p.games)}</td>
        <td style="text-align:center">${n(p.min_pg)}</td>
        <td style="text-align:center;font-weight:700;color:var(--red)">${n(p.pts_pg)}</td>
        <td style="text-align:center">${n(p.reb_pg)}</td>
        <td style="text-align:center">${n(p.ast_pg)}</td>
        <td style="text-align:center">${n(p.stl_pg)}</td>
        <td style="text-align:center">${n(p.blk_pg)}</td>
      </tr>`).join('');

    const comStats = d.players.filter(p => p.games !== null).length;
    box.innerHTML = `
      <div style="overflow-x:auto">
        <table class="year-table" style="min-width:100%">
          <thead><tr>
            <th>Jogador</th><th>Pos</th><th style="text-align:center">OVR</th>
            <th style="text-align:center">Idade</th><th style="text-align:center">J</th>
            <th style="text-align:center">MIN</th><th style="text-align:center">PTS</th>
            <th style="text-align:center">REB</th><th style="text-align:center">AST</th>
            <th style="text-align:center">ROU</th><th style="text-align:center">TOC</th>
          </tr></thead>
          <tbody>${linhas}</tbody>
        </table>
      </div>
      ${comStats < d.players.length
        ? `<div style="font-size:11px;color:var(--text-3);margin-top:10px">
             ${d.players.length - comStats} de ${d.players.length} jogadores ainda sem estatísticas nesta temporada.
           </div>`
        : ''}`;
  } catch (e) {
    box.innerHTML = '<div class="empty">Não foi possível carregar o elenco.</div>';
  }
})();

/* Copiar link desta pagina */
(function(){
  const b = document.getElementById('btnShareTeam');
  if (!b) return;
  b.addEventListener('click', async () => {
    let ok = false;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(location.href); ok = true;
      }
    } catch (e) { ok = false; }
    if (!ok) {
      const ta = document.createElement('textarea');
      ta.value = location.href; ta.style.position = 'fixed'; ta.style.top = '-1000px';
      document.body.appendChild(ta); ta.select();
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta);
    }
    const sp = b.querySelector('span'), ic = b.querySelector('i');
    sp.textContent = ok ? 'Link copiado!' : 'Não foi possível copiar';
    ic.className = ok ? 'bi bi-check-lg' : 'bi bi-exclamation-triangle';
    setTimeout(() => { sp.textContent = 'Copiar link'; ic.className = 'bi bi-link-45deg'; }, 2000);
  });
})();

/* Feed do Time: timeline automatica (trades/draft/premios/punicoes/playoffs)
   + posts manuais do GM + curtidas + Stories (24h). */
(function(){
  const FEED_ICONES = { trade:'🔄', punicao:'⚠️', premio:'🏆', playoff:'🏀', draft:'🎓' };
  let feedPosts = [];
  let feedTimeline = [];
  let feedCanPost = false;
  let feedStories = [];

  async function feedApi(action, body) {
    const opts = body
      ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, team_id: TEAM_ID, ...body }) }
      : {};
    const url = body ? '/api/team-feed.php' : `/api/team-feed.php?action=${action}&team_id=${TEAM_ID}`;
    const res = await fetch(url, opts);
    const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || 'Erro desconhecido');
    return data;
  }

  function feedItemsMesclados() {
    const posts = feedPosts.map(p => ({ ...p, _tipo: 'post', _data: p.created_at }));
    const auto = feedTimeline.map(t => ({ ...t, _tipo: t.tipo, _data: t.data }));
    return posts.concat(auto).sort((a, b) => new Date(b._data) - new Date(a._data));
  }

  function renderFeedList() {
    const box = document.getElementById('feed-list');
    const itens = feedItemsMesclados();
    if (!itens.length) {
      box.innerHTML = '<div class="empty">Nada por aqui ainda — trades, picks de draft e prêmios do time aparecem aqui automaticamente.</div>';
      document.getElementById('feed-load-more').style.display = 'none';
      return;
    }
    box.innerHTML = itens.map(it => {
      if (it._tipo === 'post') return feedCardPost(it);
      return `<div class="row" style="align-items:flex-start">
        <span class="row-label">${FEED_ICONES[it._tipo] || '•'} ${esc(it.texto)}</span>
        <span style="font-size:11px;color:var(--text-3);white-space:nowrap">${feedDataCurta(it._data)}</span>
      </div>`;
    }).join('');
    document.getElementById('feed-load-more').style.display = itens.length >= 10 ? '' : 'none';
  }

  function feedCardPost(p) {
    const podeApagar = feedCanPost;
    return `<div class="panel" style="margin-bottom:12px" data-post-id="${p.id}">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <img src="${esc(p.author_photo || '/img/default-team.png')}" alt="" style="width:26px;height:26px;border-radius:50%;object-fit:cover" onerror="this.src='/img/default-team.png'">
        <span style="font-size:13px;font-weight:600">${esc(p.author_name)}</span>
        <span style="font-size:11px;color:var(--text-3);margin-left:auto">${feedDataCurta(p.created_at)}</span>
      </div>
      ${p.texto ? `<div style="font-size:13px;margin-bottom:${p.photo_url ? '10px' : '0'}">${esc(p.texto)}</div>` : ''}
      ${p.photo_url ? `<img src="${esc(p.photo_url)}" alt="" style="width:100%;border-radius:10px;max-height:420px;object-fit:cover;margin-bottom:8px">` : ''}
      <div style="display:flex;align-items:center;gap:14px">
        <button type="button" class="feed-like-btn" data-post-id="${p.id}" data-liked="${p.liked_by_me ? '1' : '0'}" style="background:transparent;border:none;cursor:pointer;font-size:13px;color:${p.liked_by_me ? 'var(--red)' : 'var(--text-2)'}">
          <i class="bi ${p.liked_by_me ? 'bi-heart-fill' : 'bi-heart'}"></i> <span class="feed-like-count">${p.like_count}</span>
        </button>
        ${podeApagar ? `<button type="button" class="feed-del-btn" data-post-id="${p.id}" style="background:transparent;border:none;cursor:pointer;font-size:12px;color:var(--text-3)"><i class="bi bi-trash"></i></button>` : ''}
      </div>
    </div>`;
  }

  function feedDataCurta(iso) {
    if (!iso) return '';
    return new Date(String(iso).replace(' ', 'T')).toLocaleString('pt-BR', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
  }

  function renderFeedStories() {
    const bar = document.getElementById('feed-stories-bar');
    if (!feedStories.length) { bar.innerHTML = ''; return; }
    bar.innerHTML = `<div style="display:flex;gap:12px;overflow-x:auto;padding-bottom:8px;margin-bottom:12px">
      ${feedStories.map((s, i) => `
        <button type="button" class="feed-story-avatar" data-idx="${i}" style="background:transparent;border:none;cursor:pointer;flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:4px">
          <span style="width:60px;height:60px;border-radius:50%;padding:2px;background:${s.vista_por_mim ? 'var(--border-md)' : 'linear-gradient(135deg,var(--red),var(--amber,#f59e0b))'};display:flex">
            <img src="${esc(s.photo_url)}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;border:2px solid var(--panel)">
          </span>
          <span style="font-size:10px;color:var(--text-2);max-width:64px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">Story</span>
        </button>`).join('')}
    </div>`;
    bar.querySelectorAll('.feed-story-avatar').forEach(btn => {
      btn.addEventListener('click', () => abrirVisualizadorStory(Number(btn.dataset.idx)));
    });
  }

  function abrirVisualizadorStory(idx) {
    const s = feedStories[idx];
    if (!s) return;
    feedApi('visualizar_story', { story_id: s.id }).catch(() => {});
    s.vista_por_mim = true;

    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:#000;z-index:2000;display:flex;align-items:center;justify-content:center;flex-direction:column';
    overlay.innerHTML = `
      <div style="position:absolute;top:16px;right:16px;z-index:2;display:flex;gap:10px">
        ${feedCanPost ? `<button id="feed-story-del" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;padding:6px 10px;cursor:pointer"><i class="bi bi-trash"></i></button>` : ''}
        <button id="feed-story-close" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;padding:6px 12px;cursor:pointer;font-size:18px;line-height:1">×</button>
      </div>
      <img src="${esc(s.photo_url)}" style="max-width:92vw;max-height:78vh;border-radius:12px;object-fit:contain">
      ${s.texto ? `<div style="color:#fff;margin-top:14px;font-size:14px;max-width:80vw;text-align:center">${esc(s.texto)}</div>` : ''}`;
    document.body.appendChild(overlay);

    const fechar = () => overlay.remove();
    overlay.querySelector('#feed-story-close').addEventListener('click', fechar);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) fechar(); });
    const btnDel = overlay.querySelector('#feed-story-del');
    if (btnDel) btnDel.addEventListener('click', async () => {
      if (!confirm('Apagar essa story?')) return;
      try {
        await feedApi('excluir_story', { story_id: s.id });
        feedStories = feedStories.filter(x => x.id !== s.id);
        renderFeedStories();
        fechar();
      } catch (e) { alert(e.message); }
    });
  }

  /* Redimensiona a imagem num canvas (lado maior <=1600px) e reexporta em
     JPEG antes de mandar — evita depender do limite de upload do host. */
  function comprimirImagem(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onerror = () => reject(new Error('Não foi possível ler a imagem.'));
      reader.onload = () => {
        const img = new Image();
        img.onerror = () => reject(new Error('Arquivo de imagem inválido.'));
        img.onload = () => {
          const maxLado = 1600;
          let { width, height } = img;
          if (width > maxLado || height > maxLado) {
            const escala = maxLado / Math.max(width, height);
            width = Math.round(width * escala);
            height = Math.round(height * escala);
          }
          const canvas = document.createElement('canvas');
          canvas.width = width; canvas.height = height;
          canvas.getContext('2d').drawImage(img, 0, 0, width, height);
          resolve(canvas.toDataURL('image/jpeg', 0.82));
        };
        img.src = reader.result;
      };
      reader.readAsDataURL(file);
    });
  }

  function renderFeedComposer() {
    const box = document.getElementById('feed-composer');
    if (!feedCanPost) { box.style.display = 'none'; return; }
    box.style.display = '';
    box.innerHTML = `<div class="panel">
      <textarea id="feed-composer-texto" placeholder="Compartilhe algo sobre o time..." rows="2"
        style="width:100%;background:var(--panel-2);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:10px 12px;font-family:var(--font);font-size:13px;resize:vertical"></textarea>
      <div style="display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap">
        <label class="ver-todos-btn" style="width:auto;padding:8px 14px;cursor:pointer">
          <i class="bi bi-image"></i> Foto
          <input type="file" id="feed-composer-foto" accept="image/*" style="display:none">
        </label>
        <span id="feed-composer-foto-nome" style="font-size:11px;color:var(--text-3)"></span>
        <div style="margin-left:auto;display:flex;gap:8px">
          <button type="button" class="act" id="feed-btn-story">Postar Story (24h)</button>
          <button type="button" class="act act-primary" id="feed-btn-postar">Postar</button>
        </div>
      </div>
    </div>`;

    let fotoBase64 = null;
    document.getElementById('feed-composer-foto').addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      document.getElementById('feed-composer-foto-nome').textContent = 'Processando imagem...';
      try {
        fotoBase64 = await comprimirImagem(file);
        document.getElementById('feed-composer-foto-nome').textContent = file.name;
      } catch (err) {
        alert(err.message);
        fotoBase64 = null;
        document.getElementById('feed-composer-foto-nome').textContent = '';
      }
    });

    document.getElementById('feed-btn-postar').addEventListener('click', async (e) => {
      const texto = document.getElementById('feed-composer-texto').value.trim();
      if (!texto && !fotoBase64) { alert('Escreva algo ou adicione uma foto.'); return; }
      e.target.disabled = true;
      try {
        const data = await feedApi('postar', { texto, photo_base64: fotoBase64 || '' });
        feedPosts = data.posts;
        renderFeedList();
        document.getElementById('feed-composer-texto').value = '';
        fotoBase64 = null;
        document.getElementById('feed-composer-foto-nome').textContent = '';
        document.getElementById('feed-composer-foto').value = '';
      } catch (err) { alert(err.message); }
      e.target.disabled = false;
    });

    document.getElementById('feed-btn-story').addEventListener('click', async (e) => {
      if (!fotoBase64) { alert('Stories precisam de uma foto — escolha uma acima antes de postar.'); return; }
      const texto = document.getElementById('feed-composer-texto').value.trim();
      e.target.disabled = true;
      try {
        const data = await feedApi('postar_story', { texto, photo_base64: fotoBase64 });
        feedStories = data.stories;
        renderFeedStories();
        document.getElementById('feed-composer-texto').value = '';
        fotoBase64 = null;
        document.getElementById('feed-composer-foto-nome').textContent = '';
        document.getElementById('feed-composer-foto').value = '';
      } catch (err) { alert(err.message); }
      e.target.disabled = false;
    });
  }

  document.addEventListener('click', async (e) => {
    const likeBtn = e.target.closest('.feed-like-btn');
    if (likeBtn) {
      const postId = Number(likeBtn.dataset.postId);
      const jaCurtiu = likeBtn.dataset.liked === '1';
      try {
        const data = await feedApi(jaCurtiu ? 'descurtir' : 'curtir', { post_id: postId });
        const post = feedPosts.find(p => p.id === postId);
        if (post) { post.like_count = data.like_count; post.liked_by_me = data.liked_by_me; }
        renderFeedList();
      } catch (err) { alert(err.message); }
      return;
    }
    const delBtn = e.target.closest('.feed-del-btn');
    if (delBtn) {
      if (!confirm('Apagar este post?')) return;
      const postId = Number(delBtn.dataset.postId);
      try {
        await feedApi('excluir_post', { post_id: postId });
        feedPosts = feedPosts.filter(p => p.id !== postId);
        renderFeedList();
      } catch (err) { alert(err.message); }
    }
  });

  document.getElementById('feed-load-more')?.addEventListener('click', async (e) => {
    const itens = feedItemsMesclados();
    const before = itens.length ? itens[itens.length - 1]._data : null;
    if (!before) return;
    e.target.disabled = true;
    try {
      const rPosts = await fetch(`/api/team-feed.php?action=posts&team_id=${TEAM_ID}&before=${encodeURIComponent(before)}`).then(r => r.json());
      const rTime = await fetch(`/api/team-feed.php?action=timeline&team_id=${TEAM_ID}&before=${encodeURIComponent(before)}`).then(r => r.json());
      feedPosts = feedPosts.concat(rPosts.posts || []);
      feedTimeline = feedTimeline.concat(rTime.timeline || []);
      renderFeedList();
    } catch (err) { alert('Erro ao carregar mais itens.'); }
    e.target.disabled = false;
  });

  async function carregarFeed() {
    try {
      const data = await feedApi('estado');
      feedCanPost = !!data.can_post;
      feedPosts = data.posts || [];
      feedTimeline = data.timeline || [];
      feedStories = data.stories || [];
      renderFeedStories();
      renderFeedComposer();
      renderFeedList();
    } catch (e) {
      document.getElementById('feed-list').innerHTML = '<div class="empty">Não foi possível carregar o feed.</div>';
    }
  }
  carregarFeed();
})();
</script>
</body>
</html>
