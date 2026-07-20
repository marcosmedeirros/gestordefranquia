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
.content{max-width:960px;margin:0 auto;padding:24px 16px 80px;width:100%}
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
.pos-chart{background:var(--panel-2);border:1px solid var(--border);border-radius:10px;padding:12px 14px 6px;margin-bottom:14px}
.pos-chart svg .pos-line{stroke-dasharray:900;stroke-dashoffset:900;animation:lineDraw 1.15s ease forwards}
.pos-chart svg .pos-dot{opacity:0;transform-box:fill-box;transform-origin:center;animation:dotPop .45s ease forwards}
.pos-chart svg .pos-dot-label{opacity:0;animation:fadeInUp .45s ease forwards}
@keyframes lineDraw{to{stroke-dashoffset:0}}
@keyframes dotPop{0%{opacity:0;transform:scale(.55)}70%{opacity:1;transform:scale(1.08)}100%{opacity:1;transform:scale(1)}}
@keyframes fadeInUp{0%{opacity:0;transform:translateY(4px)}100%{opacity:1;transform:translateY(0)}}
.positions-legend{display:flex;gap:14px;flex-wrap:wrap;margin-top:8px;font-size:11px;color:var(--text-3)}
.positions-legend span{display:inline-flex;align-items:center;gap:5px}
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
  .content{padding:18px 12px 72px}
  .panel{padding:16px 14px}
  .two-col{grid-template-columns:1fr}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .hero{padding:18px}
  .hero-name{font-size:18px}
  .section-title{font-size:12px;line-height:1.25;flex-wrap:wrap}
  .section-title span{display:block;width:100%}
  .history-acc-toggle{padding:12px 12px}
  .history-acc-body{padding:0 12px 12px}
  .positions-legend{gap:10px;font-size:10px}
  .pos-chart{padding:10px 10px 4px;overflow-x:auto}
  .pos-chart svg{min-width:520px}
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
  .positions-legend{flex-direction:column;align-items:flex-start;gap:6px}
  .pos-chart svg{min-width:480px}
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
    <div>
      <div class="hero-name"><?= htmlspecialchars($teamInfo['full_name']) ?></div>
      <div style="font-size:12px;color:var(--text-2);margin-top:4px" id="hero-owner"></div>
      <span class="league-badge"><?= htmlspecialchars($teamInfo['league']) ?></span>
    </div>
  </div>

  <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Visão Geral</div>
  <div class="stats-grid" id="stats-grid">
    <?php for($i=0;$i<6;$i++): ?>
    <div class="stat-card"><div class="skeleton" style="height:10px;width:55%;margin-bottom:8px"></div><div class="skeleton" style="height:28px;width:45%"></div></div>
    <?php endfor; ?>
  </div>

  <div class="section-title"><i class="bi bi-trophy-fill"></i> Desempenho por Fase</div>
  <div class="panel" id="phases-panel"><div class="skeleton" style="height:180px"></div></div>

  <div class="two-col">
    <div class="panel">
      <div class="section-title"><i class="bi bi-person-fill"></i> Destaque</div>
      <div id="players-content"><div class="skeleton" style="height:80px"></div></div>
    </div>
    <div class="panel">
      <div class="section-title"><i class="bi bi-person-badge-fill"></i> GM</div>
      <div id="gm-content"><div class="skeleton" style="height:80px"></div></div>
    </div>
  </div>

  <div class="panel">
    <div class="section-title"><i class="bi bi-people-fill"></i> Melhor por Posição</div>
    <div id="best-by-pos"><div class="skeleton" style="height:160px"></div></div>
  </div>

  <div class="panel" id="positions-panel" style="display:none">
    <div class="section-title"><i class="bi bi-list-ol"></i> Posição por Temporada <span style="font-size:10px;font-weight:400;color:var(--text-3);text-transform:none;letter-spacing:0">(classificação da temporada regular)</span></div>
    <div id="positions-content"></div>
  </div>

  <div class="panel" id="best-roster-panel" style="display:none">
    <div class="section-title"><i class="bi bi-gem"></i> Melhor Elenco Já Montado <span id="best-roster-year" style="font-size:10px;font-weight:400;color:var(--text-3);text-transform:none;letter-spacing:0"></span></div>
    <div id="best-roster-content"></div>
  </div>

  <div class="panel">
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

  <div class="panel" id="league-stats-panel" style="display:none">
    <div class="section-title"><i class="bi bi-graph-up-arrow"></i> Estatísticas na Liga <span style="font-size:10px;font-weight:400;color:var(--text-3);text-transform:none;letter-spacing:0">(valor do time e posição entre os times da liga)</span></div>
    <div id="league-stats-content"></div>
  </div>

  <div class="panel">
    <div class="section-title"><i class="bi bi-graph-up"></i> Evolução por Temporada <span style="font-size:10px;font-weight:400;color:var(--text-3);text-transform:none;letter-spacing:0">(top 5 titulares)</span></div>
    <div id="avg-by-year"><div class="skeleton" style="height:100px"></div></div>
  </div>

  <div class="panel" id="drafted-panel" style="display:none">
    <div class="section-title"><i class="bi bi-star-fill" style="color:var(--purple)"></i> Draftados pelo Clube</div>
    <div id="drafted-content"></div>
  </div>

  <div class="panel">
    <div class="section-title"><i class="bi bi-award-fill"></i> Prêmios Individuais</div>
    <div id="awards-content"><div class="skeleton" style="height:50px"></div></div>
  </div>

  <div class="panel" id="champ-panel" style="display:none">
    <div class="section-title"><i class="bi bi-stars"></i> Elenco Campeão — <span id="champ-year"></span></div>
    <div id="champ-roster"></div>
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

const TEAM_ID = <?= $teamId ?>;
const AWARD_LABELS = { mvp:'MVP', dpoy:'DPOY', mip:'MIP', '6th_man':'6º Homem', roy:'ROY' };
const POS_LABELS   = { PG:'Armador', SG:'Ala-Armador', SF:'Ala', PF:'Ala-Pivô', C:'Pivô' };

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

  const { team, seasons, playoffs, regular, picks, trades, players, drafted, awards, gm, positions } = data;
  const bestRoster = data.best_roster, tradesByCycle = data.trades_by_cycle || [],
        tradesByPartner = data.trades_by_partner || [], leagueStats = data.league_stats || {};

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

  // ── Gráfico de posições (SVG inline, eixo Y invertido: 1º no topo) ──
  function renderPositionChart(pos) {
    const W = 660, H = 230;
    const padL = 30, padR = 16, padT = 16, padB = 30;
    const n = pos.length;
    // escala do eixo Y = tamanho da conferência (quantos times disputavam)
    const maxPos = Math.max(
      ...pos.map(p => p.conference_size || 0),
      ...pos.map(p => p.position),
      10
    );
    const innerW = W - padL - padR, innerH = H - padT - padB;
    const x = i => n === 1 ? padL + innerW / 2 : padL + (i * innerW) / (n - 1);
    const y = p => padT + ((p - 1) / (maxPos - 1)) * innerH;

    const yPlayoff = y(8);
    const zonaPlayoff = maxPos > 8
      ? `<rect x="${padL}" y="${padT}" width="${innerW}" height="${yPlayoff - padT}"
             fill="var(--red)" opacity=".07"></rect>
         <line x1="${padL}" y1="${yPlayoff}" x2="${W - padR}" y2="${yPlayoff}"
             stroke="var(--red)" stroke-width="1" stroke-dasharray="4 4" opacity=".5"></line>
         <text x="${W - padR}" y="${yPlayoff - 5}" text-anchor="end"
             font-size="9" fill="var(--red)" opacity=".8" font-weight="700">ZONA DE PLAYOFF</text>`
      : '';

    // linhas de referência: 1º e último
    const eixoY = [1, maxPos].map(p => `
      <line x1="${padL}" y1="${y(p)}" x2="${W - padR}" y2="${y(p)}" stroke="var(--border)" stroke-width="1"></line>
      <text x="${padL - 6}" y="${y(p) + 3}" text-anchor="end" font-size="9" fill="var(--text-3)">${p}º</text>`).join('');

    const linha = n > 1
            ? `<polyline class="pos-line" fill="none" stroke="var(--red)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"
              points="${pos.map((p, i) => `${x(i)},${y(p.position)}`).join(' ')}"></polyline>`
      : '';

    const pontos = pos.map((p, i) => {
      const primeiro = p.position === 1;
      const cor = primeiro ? 'var(--amber)' : (p.made_playoffs ? 'var(--red)' : 'var(--text-3)');
      const anoLbl = p.year ?? (p.season_number ? 'T' + p.season_number : '');
      return `
          <circle class="pos-dot" style="animation-delay:${120 + i * 85}ms" cx="${x(i)}" cy="${y(p.position)}" r="${primeiro ? 6 : 4.5}"
                fill="${cor}" stroke="var(--panel)" stroke-width="2">
          <title>${anoLbl}: ${p.position}º de ${p.conference_size || maxPos}${primeiro ? ' — 1º da conferência' : (p.made_playoffs ? ' — playoffs' : ' — fora dos playoffs')}</title>
        </circle>
          <text class="pos-dot-label" style="animation-delay:${180 + i * 85}ms" x="${x(i)}" y="${y(p.position) - 11}" text-anchor="middle" font-size="10"
              font-weight="700" fill="${cor}">${p.position}º</text>
          <text class="pos-dot-label" style="animation-delay:${220 + i * 85}ms" x="${x(i)}" y="${H - 10}" text-anchor="middle" font-size="9" fill="var(--text-3)">${anoLbl}</text>`;
    }).join('');

    return `<svg viewBox="0 0 ${W} ${H}" width="100%" height="auto"
                 style="display:block;max-height:240px;overflow:visible" role="img"
                 aria-label="Gráfico de posição por temporada">
        ${zonaPlayoff}${eixoY}${linha}${pontos}
      </svg>`;
  }

  // ── Posição por Temporada ──
  if (positions && positions.length > 0) {
    document.getElementById('positions-panel').style.display = 'block';
    const posBadge = (p) => {
      const isFirst = p.position === 1;
      const top8 = p.made_playoffs;
      const bg = isFirst ? 'rgba(245,158,11,.14)' : (top8 ? 'var(--red-soft)' : 'var(--panel-3)');
      const bd = isFirst ? 'rgba(245,158,11,.35)' : (top8 ? 'var(--border-red)' : 'var(--border)');
      const col = isFirst ? 'var(--amber)' : (top8 ? 'var(--red)' : 'var(--text-2)');
      const confLbl = p.conference ? (p.conference === 'LESTE' ? 'L' : 'O') : '';
      const yr = p.year ?? (p.season_number ? 'T'+p.season_number : '—');
      return `<div style="display:flex;flex-direction:column;align-items:center;gap:4px;min-width:64px">
        <div style="font-size:11px;color:var(--text-3);font-weight:600">${yr}</div>
        <div style="width:46px;height:46px;border-radius:12px;background:${bg};border:1px solid ${bd};display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1">
          <span style="font-family:'Oswald',sans-serif;font-size:18px;font-weight:800;color:${col}">${p.position}º</span>
          ${confLbl ? `<span style="font-size:8px;font-weight:700;color:${col};opacity:.75;letter-spacing:.5px">${confLbl}</span>` : ''}
        </div>
        ${isFirst ? '<i class="bi bi-trophy-fill" style="font-size:10px;color:var(--amber)"></i>' : (top8 ? '<span style="font-size:8px;color:var(--red);font-weight:700;text-transform:uppercase;letter-spacing:.4px">PO</span>' : '<span style="font-size:8px;color:var(--text-3)">—</span>')}
      </div>`;
    };

    const positionsTable = `<div style="overflow-x:auto;margin-top:12px">
      <table class="year-table" style="min-width:100%">
        <thead><tr><th>Temporada</th><th>Posição</th><th>Conferência</th><th>Playoffs</th></tr></thead>
        <tbody>${positions.map(p => {
          const yr = p.year ?? (p.season_number ? 'T'+p.season_number : '—');
          const conf = p.conference ? (p.conference === 'LESTE' ? 'Leste' : 'Oeste') : '—';
          const status = p.position === 1 ? '1º da conferência' : (p.made_playoffs ? 'Playoffs' : 'Fora dos playoffs');
          return `<tr>
            <td style="font-weight:700">${yr}</td>
            <td><span style="font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;color:${p.position === 1 ? 'var(--amber)' : 'var(--red)'}">${p.position}º</span></td>
            <td style="color:var(--text-2)">${conf}</td>
            <td style="color:var(--text-2)">${status}</td>
          </tr>`;
        }).join('')}</tbody>
      </table>
    </div>`;

    let chartHTML = '';
    try {
      chartHTML = `<div class="pos-chart">${renderPositionChart(positions)}</div>`;
    } catch (err) {
      chartHTML = '';
    }

    document.getElementById('positions-content').innerHTML =
      `${chartHTML || `<div class="empty" style="padding:16px 12px;margin-bottom:12px;border:1px dashed var(--border);border-radius:10px">Gráfico indisponível nesta tela, mas a classificação por temporada continua abaixo.</div>`}
       <div style="display:flex;gap:10px;overflow-x:auto;padding:4px 2px 8px;scrollbar-width:thin">${positions.map(posBadge).join('')}</div>
       <div class="positions-legend">
         <span><i class="bi bi-trophy-fill" style="color:var(--amber)"></i> 1º da conferência</span>
         <span><span style="color:var(--red);font-weight:700">PO</span> Zona de playoffs (top 8)</span>
         <span>L/O = Leste / Oeste</span>
       </div>
       ${positionsTable}`;
  }

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
</script>
</body>
</html>
