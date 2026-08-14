<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/preview_gate.php';
requireAuth();
$user = getUserSession();
$pdo  = db();

// A pagina nao e mais restrita (esta no menu da ELITE), mas o link de preview
// continua valendo: abrir com ?preview=<token> liga o modo salario e a
// simulacao de troca nesta sessao, mesmo se a liga ainda estiver no modo antigo.
// Sem esta chamada nada le o token da URL e previewActive() nunca fica true.
previewUnlock('cap');

$stmtMine = $pdo->prepare("SELECT id, city, name, league, photo_url FROM teams WHERE user_id = ? LIMIT 1");
$stmtMine->execute([$user['id']]);
$team = $stmtMine->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$team) { header('Location: teams.php'); exit; }

// Salary Cap (folha em dinheiro) so vale para ligas com cap_mode = 'salary'
// em league_settings — hoje so a ELITE usa. Outras ligas veem o aviso abaixo.
$stmtCapMode = $pdo->prepare("SELECT cap_mode FROM league_settings WHERE league = ?");
$stmtCapMode->execute([$team['league'] ?? '']);
$capMode = $stmtCapMode->fetchColumn() ?: 'ovr_sum';
if (previewActive('cap')) { $capMode = 'salary'; }
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
.content{padding:20px 32px 40px;width:100%}
.page-hero{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px;padding:0 0 4px;margin-bottom:8px}
.page-hero-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
.page-hero-title{font-size:26px;font-weight:800;color:var(--text);line-height:1.1}
.page-hero-sub{font-size:13px;color:var(--text-2);margin-top:4px}
.page-hero-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
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
.tag.lenda{background:rgba(245,197,66,.14);border-color:rgba(245,197,66,.4);color:#f5c542;font-weight:800;letter-spacing:.4px}

/* Botão que leva pra Trade Machine (o simulador daqui virou de lá). */
.btn-sim{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:10px;background:var(--red);border:1px solid var(--red);color:#fff;font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;transition:filter var(--t) var(--ease)}
.btn-sim:hover:not(:disabled){filter:brightness(1.1)}
.btn-sim:disabled{opacity:.6;cursor:not-allowed}
.btn-sim.ghost{background:var(--panel-2);border-color:var(--border-md);color:var(--text-2)}
.btn-sim.ghost:hover{border-color:var(--red);color:var(--red)}

.rules-panel details{margin-bottom:8px}
.rules-panel summary{cursor:pointer;font-size:13px;font-weight:600;color:var(--text);padding:6px 0}
.rules-panel summary:hover{color:var(--amber)}
.rules-panel .rules-body{font-size:12px;color:var(--text-2);padding:6px 0 10px 4px;line-height:1.6}
.skeleton{background:linear-gradient(90deg,var(--panel-2) 25%,var(--panel-3) 50%,var(--panel-2) 75%);background-size:200% 100%;animation:shimmer 1.2s infinite;border-radius:8px}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.empty{text-align:center;padding:24px;color:var(--text-3);font-size:13px}
@media(max-width:640px){
  .content{padding:20px 16px 40px}
  .panel{padding:16px 14px}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
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
  .page-hero-title { font-size: 18px; }
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

  <!-- Page hero -->
  <div class="page-hero">
    <div>
      <div class="page-hero-eyebrow">Liga · Salary Cap</div>
      <h1 class="page-hero-title"><i class="bi bi-cash-stack" style="color:var(--red);margin-right:8px"></i>Salary Cap</h1>
      <p class="page-hero-sub">Folha salarial, espaço no cap e simulador de trocas do <?= htmlspecialchars(trim($team['city'] . ' ' . $team['name'])) ?></p>
    </div>
    <div class="page-hero-actions">
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

  <?php // O simulador de dois times que morava aqui foi absorvido pela Trade
        // Machine: lá dá pra montar com até 7 times, incluir picks, ver o
        // casamento salarial dos 120% e ainda enviar a proposta. Manter as
        // duas telas significava duas contas de cap pra conferir. ?>
  <div class="section-title"><i class="bi bi-arrow-left-right"></i> Simular uma troca</div>
  <div class="panel" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;justify-content:space-between">
    <div style="font-size:13px;color:var(--text-2);line-height:1.55;max-width:56ch">
      A simulação agora é toda na <strong>Trade Machine</strong>: ela mostra o salário de cada
      jogador na hora de escolher, a folha dos dois lados antes e depois, e se a troca
      passa no casamento salarial. Nada é gravado enquanto você não enviar.
    </div>
    <a href="/trade-simulator.php" class="btn-sim" style="text-decoration:none">
      <i class="bi bi-sliders"></i> Abrir a Trade Machine
    </a>
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
      <!-- Os dois números vêm do resumo, não do texto: a base e o piso são
           configurados pelo admin (Central da Liga), e um texto fixo aqui
           passaria a mentir no dia em que a liga mudasse o teto. -->
      <div class="rules-body">Cap Máximo = <b id="regraCapBase">—</b> + Cap Flex + Bônus de Lealdade do elenco.
      Cap Mínimo = <b id="regraCapPiso">—</b> — ficar abaixo do piso
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

    // O texto da regra acompanha o que a liga configurou.
    const regraBase = document.getElementById('regraCapBase');
    const regraPiso = document.getElementById('regraCapPiso');
    if (regraBase) regraBase.textContent = s.cap_base + 'M';
    if (regraPiso) regraPiso.textContent = s.cap_floor + 'M';

    document.getElementById('statsGrid').innerHTML = [
      statCard('Cap Base', s.cap_base + 'M', '', '', 'O teto que a liga definiu, igual pra toda franquia — antes de Cap Flex e Bônus de Lealdade.'),
      statCard('Cap Flex', '+' + s.cap_flex_total + 'M',
        `${s.cap_flex_used_slots}/${s.cap_flex_max_players} vagas` + (s.cap_flex_eligible_count > s.cap_flex_max_players ? ` · ${s.cap_flex_eligible_count} elegíveis` : ''),
        '', `O Cap Flex vale para no máximo ${s.cap_flex_max_players} jogadores. Havendo mais elegíveis, contam os de maior valor.`),
      statCard('Bônus de Lealdade', '+' + s.cap_loyalty_total + 'M',
        `${s.cap_loyalty_used_slots}/${s.cap_loyalty_max_players} vagas` + (s.cap_loyalty_eligible_count > s.cap_loyalty_max_players ? ` · ${s.cap_loyalty_eligible_count} elegíveis` : ''),
        '', `Jogador Leal (nunca trocado, OVR≥90, draftado pelo draft da própria temporada) soma +${s.cap_loyalty_bonus_millions}M ao Cap Máximo, no máximo ${s.cap_loyalty_max_players} jogadores.`),
      statCard('Cap Máximo', s.cap_max + 'M', '', 'hi-amber', 'Cap Base + Cap Flex + Bônus de Lealdade — o teto real de folha salarial da sua franquia.'),
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
        <td${p.is_lenda ? ' style="color:#f5c542;font-weight:800"' : ''}>${esc(p.name)}</td>
        <td class="num">${p.ovr}</td>
        <td class="num">${p.base_salary}M</td>
        <td>
          ${p.is_lenda ? '<span class="tag lenda" title="Lenda da franquia: uma por time. Ignora a tabela de OVR e vale no mínimo 40M (acima de 94 OVR a tabela volta a valer). Anula o Bônus de Lealdade — os dois não se somam.">LENDA</span>' : ''}
          ${p.is_rookie_scale ? '<span class="tag rookie" title="Salário definido pela Rookie Scale (posição do pick), não pela tabela de OVR, por ser a temporada de estreia.">Rookie Scale</span>' : ''}
          ${p.cap_flex_eligible ? (p.cap_flex_counted
            ? `<span class="tag flex" title="Ainda está no time que o draftou e o OVR qualifica — adiciona +${p.cap_flex_value}M ao Cap Máximo do time.">Cap Flex +${p.cap_flex_value}M</span>`
            : `<span class="tag flex-off" title="Qualifica para Cap Flex, mas o time já usou as ${s.cap_flex_max_players} vagas com jogadores de valor maior — este não soma ao Cap Máximo.">Cap Flex +${p.cap_flex_value}M (fora das vagas)</span>`) : ''}
          ${p.award_bonus > 0 ? `<span class="tag bonus" title="Bônus de prêmio da temporada anterior, vale só nesta temporada.">Bônus +${p.award_bonus}M</span>` : ''}
          ${p.loyalty_bonus_eligible ? (p.loyalty_bonus_counted
            ? `<span class="tag flex" title="Jogador Leal (nunca trocado, OVR≥90, draftado pelo draft da própria temporada) — adiciona +${s.cap_loyalty_bonus_millions}M ao Cap Máximo.">Leal +${s.cap_loyalty_bonus_millions}M</span>`
            : `<span class="tag flex-off" title="Qualifica pro Bônus de Lealdade, mas o time já usou as ${s.cap_loyalty_max_players} vagas com jogadores de OVR maior — este não soma ao Cap Máximo.">Leal +${s.cap_loyalty_bonus_millions}M (fora das vagas)</span>`)
            : (p.is_loyal ? `<span class="tag" title="Nunca foi trocado e veio do draft normal, mas não cumpre OVR≥90 — só a tag, sem bônus de cap.">Leal</span>` : '')}
        </td>
        <td class="num">${p.total_salary}M</td>
      </tr>
    `).join('');
  } catch (e) {
    document.getElementById('statsGrid').innerHTML = '<div class="empty" style="grid-column:1/-1">Erro ao carregar o Cap.</div>';
  }
}
loadCap();

<?php endif; ?>
</script>
</body>
</html>
