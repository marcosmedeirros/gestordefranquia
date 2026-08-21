<?php
/**
 * Tática — tela única do GM. Sem envio, sem prazo: o time mantém 3 táticas
 * nomeadas em paralelo e a que estiver marcada como ativa já é a oficial.
 * O admin controla quando a edição fica aberta (corte diário + toggle manual).
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

$stmtTeam = $pdo->prepare('SELECT id, city, name, league, photo_url FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
if (!$team) { header('Location: my-roster.php'); exit; }

$isElite = strtoupper((string)$team['league']) === 'ELITE';

// Modelo técnico e playbook são de ELITE e NEXT. Na RISE e na ROOKIE não
// existem — mostrar os campos lá seria pedir uma escolha que não vale nada.
require_once __DIR__ . '/backend/modelos_tecnicos.php';
$temModeloTecnico = in_array(strtoupper((string)$team['league']), ['ELITE', 'NEXT'], true);
$MODELOS = $temModeloTecnico ? modelosTecnicosParaJson() : [];
$SIGLAS  = modeloTecnicoAtributos();

// Quantos dos oito o time já gastou. A conta é do fechamento da janela
// (ver backend/modelo_tecnico_trocas.php) — aqui só se lê o placar.
require_once __DIR__ . '/backend/modelo_tecnico_trocas.php';
$PLACAR = $temModeloTecnico
    ? modeloTecnicoPlacar($pdo, (int)$team['id'])
    : ['usados' => 0, 'limite' => 0, 'restam' => 0, 'trocas' => 0, 'historico' => []];

$SLOT_LABELS = ['regular' => 'Tática 1', 'playoffs' => 'Tática 2', 'outra' => 'Tática 3'];

// Mapa compartilhado com o admin — ver backend/tatica_opcoes.php.
$OPCOES = require __DIR__ . '/backend/tatica_opcoes.php';?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<title>Tática · FBA Manager</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Inter:wght@400;500;600&family=Oswald:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-2:color-mix(in srgb, var(--red) 85%, white);--red-soft:color-mix(in srgb, var(--red) 10%, transparent);--red-glow:color-mix(in srgb, var(--red) 18%, transparent);--border-red:color-mix(in srgb, var(--red) 25%, transparent);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1e1e24;--border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);--border-strong:var(--border-md);--text:#f0f0f3;--text-2:#868690;--text-3:#7d7d85;--amber:#f59e0b;--green:#22c55e;--blue:#3b82f6;--radius:14px;--radius-sm:10px;--radius-xs:6px;--font:'Montserrat',sans-serif;--sidebar-w:260px;--t:.2s;--ease:cubic-bezier(.4,0,.2,1)}
:root[data-theme="light"]{--bg:#f6f7fb;--panel:#fff;--panel-2:#f2f4f8;--panel-3:#e9edf4;--border:#e3e6ee;--border-md:#d7dbe6;--text:#111217;--text-2:#5b6270;--text-3:#657080}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.app{display:flex;min-height:100vh}
.sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;overflow-y:auto;scrollbar-width:none;transition:transform var(--t) var(--ease)}
.sidebar::-webkit-scrollbar{display:none}
.sb-team{margin:14px 14px 0;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-team img{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-team-name{font-size:13px;font-weight:600;line-height:1.2}
.sb-team-league{font-size:11px;color:var(--red);font-weight:600}
.sb-nav{flex:1;padding:12px 10px 8px}
.sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 5px}
.sb-nav a{font-family:'Inter',sans-serif;display:flex;align-items:center;gap:10px;padding:10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease)}
.sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0}
.sb-nav a:hover{background:var(--panel-2);color:var(--text)}
.sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600}
.sb-theme-toggle{margin:0 14px 12px;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--panel-2);color:var(--text);display:flex;align-items:center;justify-content:center;gap:8px;font-size:12px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease)}
.sb-theme-toggle:hover{border-color:var(--border-red);color:var(--red)}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-username{font-size:12px;font-weight:500;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;flex-shrink:0}
.sb-logout:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
.topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:240}
.topbar-title{font-weight:700;font-size:15px;flex:1}
.menu-btn{width:34px;height:34px;border-radius:9px;background:var(--panel-2);border:1px solid var(--border);color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:17px}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:250}
.sb-overlay.show{display:block}
.main{margin-left:var(--sidebar-w);min-height:100vh;width:calc(100% - var(--sidebar-w))}
.content{max-width:1180px;margin:0 auto;padding:26px 22px 90px}

.page-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
.page-title{font-family:'Oswald',sans-serif;font-size:25px;font-weight:700;display:flex;align-items:center;gap:10px}
.page-title i{color:var(--red)}
.page-sub{font-size:13px;color:var(--text-2);margin-top:5px}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px}
.section-title{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);margin-bottom:14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.section-title i{color:var(--red)}
.section-title .hint{margin-left:auto;font-family:var(--font);font-size:11px;font-weight:500;letter-spacing:0;text-transform:none;color:var(--text-3)}

.aviso{display:flex;align-items:flex-start;gap:9px;padding:11px 14px;border-radius:10px;font-size:12.5px;line-height:1.5;margin-bottom:14px}
.aviso i{font-size:15px;flex-shrink:0;margin-top:1px}
.aviso.info{background:var(--panel-2);border:1px solid var(--border);color:var(--text-2)}
.aviso.warn{background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.28);color:var(--amber)}
.aviso.ok{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.28);color:var(--green)}
.aviso.err{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.3);color:#f87171}

/* Táticas (abas) */
.slots{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:18px 0 14px}
.slot-btn{position:relative;padding:9px 18px;border-radius:999px;background:var(--panel);border:1px solid var(--border);color:var(--text-2);font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease);display:flex;align-items:center;gap:6px}
.slot-btn:hover{border-color:var(--border-md);color:var(--text)}
.slot-btn.active{background:var(--red);border-color:var(--red);color:#fff}
.slot-btn .dot{display:none;width:6px;height:6px;border-radius:50%;background:var(--green)}
.slot-btn.saved .dot{display:block}
.slot-btn.active .dot{background:#fff}
.slot-btn .star{display:none;color:var(--green);font-size:12px}
.slot-btn.is-official .star{display:inline}
.slot-btn.active .star{color:#fff}
.slots-hint{font-size:11px;color:var(--text-3);margin-left:auto}
@media (max-width:760px){.slots-hint{margin-left:0;width:100%}}

.tactic-status{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.tactic-status .badge-ativa{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--green);background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);padding:6px 12px;border-radius:999px}
.tactic-status .badge-ativa i{font-size:13px}

/* Quinteto */
.court{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
.slot{background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:12px 10px;text-align:center;transition:border-color var(--t) var(--ease)}
.slot:hover{border-color:var(--border-md)}
.slot-pos{font-family:'Oswald',sans-serif;font-size:11px;font-weight:700;letter-spacing:1px;color:var(--red);margin-bottom:8px}
.slot select{width:100%;background:var(--panel-3);border:1px solid var(--border-md);color:var(--text);border-radius:8px;padding:7px 6px;font-family:inherit;font-size:11.5px}
.slot select:focus{outline:none;border-color:var(--red)}
.slot-info{font-size:10.5px;color:var(--text-3);margin-top:6px;min-height:14px}
.slot.dup{border-color:#ef4444}
.slot.dup .slot-info{color:#f87171}

/* Minutos previstos (somente leitura) */
.min-preview{display:flex;flex-wrap:wrap;gap:8px}
.min-chip{display:flex;align-items:center;gap:7px;background:var(--panel-2);border:1px solid var(--border);border-radius:999px;padding:5px 10px 5px 6px;font-size:11.5px}
.min-chip .tag{font-size:9px;font-weight:700;padding:2px 6px;border-radius:999px;background:var(--panel-3);color:var(--text-3)}
.min-chip .tag.tit{background:var(--red-soft);color:var(--red)}
.min-chip .mn{font-family:'Oswald',sans-serif;font-weight:700;color:var(--text)}

/* ── Modelo técnico ────────────────────────────────── */
.mt-bloco{margin-top:16px;padding:14px;border-radius:12px;
  background:var(--panel-2);border:1px solid var(--border)}
.mt-cab{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
  flex-wrap:wrap;margin-bottom:12px}
.mt-placar{display:block;margin-top:3px;font-size:10.5px;font-weight:500;
  color:var(--text-3);letter-spacing:0;text-transform:none;font-family:var(--font)}
.mt-escolha{display:flex;align-items:center;gap:12px;flex-wrap:wrap}

/* A foto sai do card inteiro: o rosto ocupa a faixa de cima, então o
   object-position puxa pra lá em vez de mostrar a moldura. */
.mt-foto{width:56px;height:56px;flex:none;border-radius:10px;overflow:hidden;
  background:var(--panel-3);display:flex;align-items:center;justify-content:center;
  color:var(--text-3);font-size:20px;border:1px solid var(--border)}
.mt-foto img{width:100%;height:100%;object-fit:cover;object-position:center 22%}

.mt-attrs{display:flex;gap:10px;flex-wrap:wrap}
.mt-attr{text-align:center;min-width:38px}
.mt-attr b{display:block;font-size:15px;font-weight:800;color:var(--text);
  line-height:1;font-variant-numeric:tabular-nums}
.mt-attr span{font-size:9px;color:var(--text-3);letter-spacing:.06em;font-weight:700}
.mt-sistema{font-size:11px;font-weight:700;color:var(--red);
  text-transform:uppercase;letter-spacing:.04em;align-self:center}

/* O modal com os cards */
.mt-grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px}
.mt-card{border:1px solid var(--border);border-radius:12px;overflow:hidden;
  background:var(--panel-2);cursor:pointer;text-align:left;padding:0;
  font-family:var(--font);transition:border-color .15s,transform .15s}
.mt-card:hover{border-color:var(--red);transform:translateY(-2px)}
.mt-card.escolhido{border-color:var(--red);box-shadow:0 0 0 1px var(--red) inset}
.mt-card img{width:100%;display:block;aspect-ratio:9/16;object-fit:cover}
.mt-card-pe{padding:8px 10px;display:flex;align-items:center;justify-content:space-between;gap:6px}
.mt-card-nome{font-size:12.5px;font-weight:700;color:var(--text);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mt-card-sel{font-size:10px;color:var(--red);font-weight:700;flex:none}

@media (max-width:640px){
  .mt-grade{grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:9px}
  .mt-cab .btn{width:100%}
  .mt-attrs{width:100%;justify-content:space-between}
}

.mt-modal{position:fixed;inset:0;z-index:400;display:flex;align-items:center;
  justify-content:center;padding:18px}
.mt-modal[hidden]{display:none}
.mt-modal-fundo{position:absolute;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(3px)}
.mt-modal-caixa{position:relative;width:min(980px,100%);max-height:88vh;display:flex;
  flex-direction:column;background:var(--panel);border:1px solid var(--border-md);
  border-radius:14px;overflow:hidden}
.mt-modal-cab{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
  padding:14px 18px;border-bottom:1px solid var(--border)}
.mt-modal-corpo{padding:16px 18px;overflow-y:auto}

/* Sistema */
.fgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:13px}
.field label{display:block;font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--text-3);margin-bottom:5px}
.field select,.field input,.field textarea{width:100%;background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:9px;padding:8px 10px;font-family:inherit;font-size:12.5px}
.field select:focus,.field input:focus,.field textarea:focus{outline:none;border-color:var(--red)}
.field textarea{resize:vertical;min-height:76px}
.field select:disabled,.field input:disabled,.field textarea:disabled{opacity:.55;cursor:not-allowed}

.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:10px;background:var(--red);border:1px solid var(--red);color:#fff;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:filter var(--t) var(--ease);text-decoration:none}
.btn:hover:not(:disabled){filter:brightness(1.1)}
.btn:disabled{opacity:.6;cursor:not-allowed}
.btn.ghost{background:var(--panel-2);border-color:var(--border-md);color:var(--text-2)}
.btn.ghost:hover:not(:disabled){border-color:var(--red);color:var(--red);filter:none}
.btn.sm{padding:7px 12px;font-size:12px}
.btn.success{background:var(--green);border-color:var(--green)}

/* Barra fixa de salvar */
.savebar{position:sticky;bottom:0;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:13px 18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:0 -6px 24px -12px rgba(0,0,0,.7);margin-top:4px}
.savebar .st{font-size:11.5px;color:var(--text-3);margin-left:auto}

@media (max-width:992px){
  :root{--sidebar-w:0px}
  .sidebar{transform:translateX(-260px)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;width:100%;padding-top:54px}
  .topbar{display:flex}
  .content{padding:16px 14px 70px}
}
@media (max-width:760px){
  /* Cinco colunas nao cabem: o quinteto vira lista de posicoes. */
  .court{grid-template-columns:1fr;gap:8px}
  .slot{display:flex;align-items:center;gap:10px;text-align:left;padding:10px 12px}
  .slot-pos{margin-bottom:0;width:30px;flex-shrink:0}
  .slot > div:last-child{flex:1;min-width:0}
  .slot-info{margin-top:3px}
  .savebar{position:static;box-shadow:none}
  .savebar .st{margin-left:0;width:100%}
}
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; animation-delay: 0ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; transition-delay: 0ms !important; scroll-behavior: auto !important; } }
<?php include __DIR__ . '/includes/accent-color.php'; ?>
</style>
</head>
<body>
<div class="app">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div class="topbar-title">Tática</div>
  <a href="my-roster.php" class="sb-logout"><i class="bi bi-arrow-left"></i></a>
</header>

<main class="main">
 <div class="content">

  <div class="page-eyebrow"><?= htmlspecialchars($team['league']) ?></div>
  <h1 class="page-title"><i class="bi bi-clipboard2-pulse"></i> Tática</h1>
  <p class="page-sub"><?= htmlspecialchars(trim($team['city'] . ' ' . $team['name'])) ?></p>

  <div class="aviso info" style="margin-top:16px">
    <i class="bi bi-info-circle"></i>
    <div>Mantenha até <strong>3 táticas</strong> prontas em paralelo e marque qual está <strong>ativa</strong> —
    é ela que vale a qualquer momento, sem precisar enviar nada.</div>
  </div>

  <div id="avisoJanela"></div>

  <div class="slots" id="slots">
    <?php foreach ($SLOT_LABELS as $k => $lbl): ?>
      <button type="button" class="slot-btn<?= $k === 'regular' ? ' active' : '' ?>" data-slot="<?= $k ?>">
        <span class="dot" title="Já configurada"></span><?= $lbl ?><i class="bi bi-check-circle-fill star" title="Tática ativa"></i>
      </button>
    <?php endforeach; ?>
    <span class="slots-hint">Três táticas em paralelo — só a marcada como ativa vale.</span>
  </div>

  <div id="carregando" class="panel" style="text-align:center;color:var(--text-3);font-size:13px">Carregando elenco…</div>

  <div id="conteudo" style="display:none">

    <div class="tactic-status" id="tacticStatus"></div>

    <!-- Quinteto -->
    <div class="panel">
      <div class="section-title"><i class="bi bi-people-fill"></i> Quinteto titular
        <span class="hint">Sugerido pelo elenco: melhor de cada posição, priorizando quem está marcado como Titular.</span>
      </div>
      <div class="court" id="court"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
        <button class="btn ghost sm" id="btnSugQuinteto"><i class="bi bi-magic"></i> Sugerir quinteto</button>
        <button class="btn ghost sm" id="btnLimparQuinteto"><i class="bi bi-eraser"></i> Limpar</button>
      </div>
      <div id="avisoQuinteto"></div>
    </div>

    <?php if ($isElite): ?>
    <!-- G-League -->
    <div class="panel" id="painelGleague">
      <div class="section-title"><i class="bi bi-arrow-down-circle"></i> G-League
        <span class="hint" id="gleagueHint"></span>
      </div>
      <div class="fgrid" id="gleagueCampos"></div>
    </div>
    <?php endif; ?>

    <!-- Rotação -->
    <div class="panel">
      <div class="section-title"><i class="bi bi-stopwatch"></i> Rotação
        <span class="hint">Você define quantos jogadores entram na rotação — o sistema distribui os minutos automaticamente.</span>
      </div>
      <div class="fgrid" style="margin-bottom:14px">
        <div class="field">
          <label for="f_rotation_players">Jogadores na rotação</label>
          <input type="number" id="f_rotation_players" data-f="rotation_players" min="5" max="15" placeholder="ex.: 9">
        </div>
        <div class="field">
          <label for="f_veteran_focus">Foco em veteranos (0–100)</label>
          <input type="number" id="f_veteran_focus" data-f="veteran_focus" min="0" max="100" placeholder="ex.: 50">
        </div>
      </div>
      <div class="section-title" style="margin-bottom:8px"><i class="bi bi-eye"></i> Minutos previstos <span class="hint">Calculado pelo sistema — não é editável aqui.</span></div>
      <div class="min-preview" id="minPreview"></div>
    </div>

    <!-- Sistema -->
    <div class="panel">
      <div class="section-title"><i class="bi bi-sliders"></i> Sistema de jogo</div>
      <div class="fgrid">
        <?php
        $camposSistema = ['game_style' => 'Estilo de jogo',
            'offense_style' => 'Foco ofensivo', 'pace' => 'Ritmo',
            'offensive_rebound' => 'Rebote ofensivo', 'defensive_rebound' => 'Rebote defensivo',
            'offensive_aggression' => 'Agressividade defensiva', 'defensive_focus' => 'Foco defensivo'];
        foreach ($camposSistema as $campo => $rotulo): ?>
        <div class="field">
          <label for="f_<?= $campo ?>"><?= $rotulo ?></label>
          <select id="f_<?= $campo ?>" data-f="<?= $campo ?>">
            <option value="">—</option>
            <?php foreach ($OPCOES[$campo] as $v => $lbl): ?>
              <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($temModeloTecnico): ?>
      <div class="mt-bloco">
        <div class="mt-cab">
          <div>
            <div class="section-title" style="margin:0"><i class="bi bi-person-badge"></i> Modelo técnico</div>
            <span class="hint mt-placar" id="mtRestam"><?php
              // "Usados" inclui o primeiro modelo: as oito vagas são um mais
              // sete trocas.
              echo 'São ' . $PLACAR['limite'] . ' modelos na edição — usados: ' . $PLACAR['usados'];
            ?></span>
          </div>
          <button type="button" class="btn ghost" id="btnVerModelos">
            <i class="bi bi-grid-3x3-gap"></i> Ver modelos técnicos
          </button>
        </div>
        <div class="mt-escolha">
          <!-- A foto do escolhido: quem está montando a tática vê o coach,
               não só o nome dele numa lista. -->
          <div class="mt-foto" id="mtFoto"><i class="bi bi-person"></i></div>
          <div class="field" style="flex:1;min-width:0">
            <label for="f_technical_model">Quem comanda o time</label>
            <select id="f_technical_model" data-f="technical_model">
              <option value="">—</option>
              <?php foreach ($OPCOES['technical_model'] as $v => $lbl): ?>
                <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mt-attrs" id="mtAttrs"></div>
        </div>
      </div>
      <?php endif; ?>

      <div class="fgrid" style="margin-top:13px">
        <?php if ($temModeloTecnico): ?>
        <div class="field" style="grid-column:1/-1">
          <label for="f_playbook">Playbook</label>
          <textarea id="f_playbook" data-f="playbook" placeholder="Jogadas e ajustes que você quer usar…"></textarea>
        </div>
        <?php endif; ?>
        <div class="field" style="grid-column:1/-1">
          <label for="f_notes">Observações</label>
          <textarea id="f_notes" data-f="notes"
            placeholder="Aqui você coloca as posições que quer os jogadores — se ele vai ser só SF, ou SF/PF."></textarea>
        </div>
      </div>
    </div>

    <div class="savebar">
      <button class="btn" id="btnSalvar"><i class="bi bi-save2"></i> Salvar agora</button>
      <button class="btn success" id="btnAtivar"><i class="bi bi-check-circle"></i> Ativar esta tática</button>
      <button class="btn ghost" id="btnCopiar"><i class="bi bi-clipboard-check"></i> Copiar tática</button>
      <span class="st" id="statusSalvar"><i class="bi bi-cloud-check"></i> Salva automaticamente</span>
    </div>
    <div id="msgSalvar"></div>
  </div>

 </div>
</main>

<?php if ($temModeloTecnico): ?>
<!-- Os cards da edição. Fica fora do <main> porque é sobreposição: dentro
     do fluxo ele herdaria o padding e a rolagem do painel. -->
<div class="mt-modal" id="mtModal" hidden>
  <div class="mt-modal-fundo" data-fechar></div>
  <div class="mt-modal-caixa" role="dialog" aria-modal="true" aria-labelledby="mtModalTitulo">
    <div class="mt-modal-cab">
      <div>
        <div id="mtModalTitulo" style="font-size:16px;font-weight:700">Modelos técnicos</div>
        <div class="hint">Os <?= count($MODELOS) ?> coaches da edição. Clique num para escolher.</div>
      </div>
      <button type="button" class="btn ghost" data-fechar><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="mt-modal-corpo">
      <div class="mt-grade" id="mtGrade"></div>
    </div>
  </div>
</div>
<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
  document.getElementById('menuBtn')?.addEventListener('click', () => { sb?.classList.add('open'); ov?.classList.add('show'); });
  ov?.addEventListener('click', () => { sb?.classList.remove('open'); ov?.classList.remove('show'); });
  const btn = document.getElementById('themeToggle');
  const aplica = t => { document.documentElement.dataset.theme = t; localStorage.setItem('fba-theme', t);
    if (btn) btn.innerHTML = t === 'light' ? '<i class="bi bi-sun"></i><span>Modo claro</span>' : '<i class="bi bi-moon"></i><span>Modo escuro</span>'; };
  aplica(localStorage.getItem('fba-theme') || 'dark');
  btn?.addEventListener('click', () => aplica(document.documentElement.dataset.theme === 'light' ? 'dark' : 'light'));
})();

const POSICOES = ['PG','SG','SF','PF','C'];
const SLOT_LABELS = <?= json_encode($SLOT_LABELS, JSON_UNESCAPED_UNICODE) ?>;
const TIME_NOME   = <?= json_encode(trim($team['city'] . ' ' . $team['name']), JSON_UNESCAPED_UNICODE) ?>;
const IS_ELITE = <?= $isElite ? 'true' : 'false' ?>;

/* ── Modelo técnico ─────────────────────────────────── */
// O catálogo vem do servidor pra não existir uma segunda lista aqui que
// envelheça sozinha. Vazio na RISE e na ROOKIE, que não usam modelo.
const MODELOS = <?= json_encode($MODELOS, JSON_UNESCAPED_UNICODE) ?>;
const SIGLAS  = <?= json_encode($SIGLAS, JSON_UNESCAPED_UNICODE) ?>;
const TEM_MODELO = <?= $temModeloTecnico ? 'true' : 'false' ?>;
const MODELO_LIMITE = <?= MODELO_TECNICO_LIMITE ?>;
const MODELO_PLACAR = <?= json_encode($PLACAR, JSON_UNESCAPED_UNICODE) ?>;

const acharModelo = (chave) => MODELOS.find(m => m.chave === chave) || null;

/** A foto e os atributos do escolhido, ao lado do select. */
function pintarModeloEscolhido(){
  if (!TEM_MODELO) return;
  const sel = document.getElementById('f_technical_model');
  const foto = document.getElementById('mtFoto');
  const attrs = document.getElementById('mtAttrs');
  if (!sel || !foto || !attrs) return;

  const m = acharModelo(sel.value);
  if (!m){
    foto.innerHTML = '<i class="bi bi-person"></i>';
    attrs.innerHTML = '';
    return;
  }
  foto.innerHTML = `<img src="${m.foto}" alt="${m.nome}">`;
  const numeros = Object.entries(SIGLAS)
    .filter(([k]) => m.attrs && m.attrs[k] !== undefined)
    .map(([k, [sigla, nome]]) =>
      `<div class="mt-attr" title="${nome}"><b>${m.attrs[k]}</b><span>${sigla}</span></div>`)
    .join('');
  attrs.innerHTML = numeros + (m.sistema ? `<div class="mt-sistema">${m.sistema}</div>` : '');
}

/** Os cards, no modal. */
function abrirModelos(){
  const modal = document.getElementById('mtModal');
  const grade = document.getElementById('mtGrade');
  const sel = document.getElementById('f_technical_model');
  if (!modal || !grade) return;

  grade.innerHTML = MODELOS.map(m => `
    <button type="button" class="mt-card${sel && sel.value === m.chave ? ' escolhido' : ''}"
            data-modelo="${m.chave.replace(/"/g, '&quot;')}">
      <img src="${m.foto}" alt="${m.nome}" loading="lazy">
      <div class="mt-card-pe">
        <span class="mt-card-nome">${m.nome}</span>
        ${sel && sel.value === m.chave ? '<span class="mt-card-sel">ATUAL</span>' : ''}
      </div>
    </button>`).join('');

  grade.querySelectorAll('[data-modelo]').forEach(b => {
    b.addEventListener('click', () => {
      if (sel){
        sel.value = b.dataset.modelo;
        sel.dispatchEvent(new Event('change', {bubbles:true}));
      }
      pintarModeloEscolhido();
      fecharModelos();
    });
  });
  modal.hidden = false;
  document.body.style.overflow = 'hidden';
}

function fecharModelos(){
  const modal = document.getElementById('mtModal');
  if (!modal) return;
  modal.hidden = true;
  document.body.style.overflow = '';
}

if (TEM_MODELO){
  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('btnVerModelos')?.addEventListener('click', abrirModelos);
    document.getElementById('f_technical_model')?.addEventListener('change', pintarModeloEscolhido);
    document.getElementById('mtModal')?.addEventListener('click', (e) => {
      if (e.target.closest('[data-fechar]')) fecharModelos();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') fecharModelos();
    });
    pintarModeloEscolhido();
  });
}
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

let ELENCO = [], TATICAS = {}, ACTIVE_SLOT = null, SLOT = 'regular', GLEAGUE_SLOTS = 0, EDIT_WINDOW = { open: true };
let sujo = false;
let carregando = true; // durante a carga os campos mudam sozinhos: não é edição
let timerAuto = null;

function msg(tipo, texto) {
  $('msgSalvar').innerHTML = `<div class="aviso ${tipo}" style="margin-top:12px"><i class="bi bi-${
    tipo === 'ok' ? 'check-circle-fill' : tipo === 'err' ? 'x-octagon-fill' : 'info-circle'}"></i><div>${texto}</div></div>`;
}

function renderJanela() {
  const box = $('avisoJanela');
  if (EDIT_WINDOW.open) { box.innerHTML = ''; return; }
  box.innerHTML = `<div class="aviso warn"><i class="bi bi-lock-fill"></i><div>Edição fechada${
    EDIT_WINDOW.reason ? ' — ' + esc(EDIT_WINDOW.reason) : ''}. Reabre quando o admin liberar.</div></div>`;
}

function aplicarBloqueioEdicao() {
  const bloqueado = !EDIT_WINDOW.open;
  document.querySelectorAll('#conteudo select, #conteudo input, #conteudo textarea').forEach(el => { el.disabled = bloqueado; });
  // btnCopiar fica de fora: copiar nao altera nada, entao vale com a janela fechada.
  ['btnSugQuinteto','btnLimparQuinteto','btnSalvar','btnAtivar'].forEach(id => { const el = $(id); if (el) el.disabled = bloqueado; });
}

/* ── Quinteto ── */
function montarQuinteto() {
  $('court').innerHTML = POSICOES.map((pos, i) => `
    <div class="slot" data-i="${i}">
      <div class="slot-pos">${pos}</div>
      <div>
        <select data-f="starter_${i+1}_id">
          <option value="">—</option>
          ${ELENCO.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}
        </select>
        <div class="slot-info"></div>
      </div>
    </div>`).join('');
  $('court').addEventListener('change', e => {
    if (e.target.tagName === 'SELECT') { atualizarQuinteto(); atualizarPreviewMinutos(); }
  });
}

function atualizarQuinteto() {
  const escolhidos = [];
  document.querySelectorAll('#court .slot').forEach(slot => {
    const sel = slot.querySelector('select');
    const id = parseInt(sel.value, 10);
    const p = ELENCO.find(x => Number(x.id) === id);
    const info = slot.querySelector('.slot-info');
    info.textContent = p ? `${p.position}${p.secondary_position ? '/' + p.secondary_position : ''} · OVR ${p.ovr} · ${p.age}a` : '';
    if (id) escolhidos.push(id);
  });
  // Mesmo jogador em duas posicoes e o erro mais comum: marca os dois lados.
  const dup = escolhidos.filter((v, i) => escolhidos.indexOf(v) !== i);
  document.querySelectorAll('#court .slot').forEach(slot => {
    const id = parseInt(slot.querySelector('select').value, 10);
    const rep = id && dup.includes(id);
    slot.classList.toggle('dup', !!rep);
    if (rep) slot.querySelector('.slot-info').textContent = 'Repetido em outra posição';
  });
  const box = $('avisoQuinteto');
  if (dup.length) box.innerHTML = '<div class="aviso err" style="margin:12px 0 0"><i class="bi bi-x-octagon-fill"></i><div>O mesmo jogador está em mais de uma posição.</div></div>';
  else if (escolhidos.length < 5) box.innerHTML = `<div class="aviso warn" style="margin:12px 0 0"><i class="bi bi-exclamation-triangle-fill"></i><div>Faltam ${5 - escolhidos.length} titular(es).</div></div>`;
  else box.innerHTML = '';
  return { escolhidos, dup };
}

function quintetoIds() {
  return [...document.querySelectorAll('#court select')].map(s => parseInt(s.value, 10)).filter(Boolean);
}

function gleagueIds() {
  if (!IS_ELITE) return [];
  return [$('f_gleague_1_id'), $('f_gleague_2_id')].filter(Boolean).map(s => parseInt(s.value, 10)).filter(Boolean);
}

/* ── G-League ── */
function montarGleague() {
  const box = $('gleagueCampos');
  if (!box) return;
  const hint = $('gleagueHint');
  if (!GLEAGUE_SLOTS) {
    if (hint) hint.textContent = 'Esta liga não usa G-League.';
    box.innerHTML = '';
    return;
  }
  // O texto não fala mais de tamanho de elenco: as vagas deixaram de
  // depender dele e a dica antiga mandava contar jogadores à toa.
  if (hint) hint.textContent = `${GLEAGUE_SLOTS} vagas — deixe em branco se não for mandar ninguém.`;
  const opts = p => `<option value="${p.id}">${esc(p.name)}</option>`;
  let html = `<div class="field"><label for="f_gleague_1_id">G-League 1</label>
    <select id="f_gleague_1_id" data-f="gleague_1_id"><option value="">—</option>${ELENCO.map(opts).join('')}</select></div>`;
  if (GLEAGUE_SLOTS >= 2) {
    html += `<div class="field"><label for="f_gleague_2_id">G-League 2</label>
      <select id="f_gleague_2_id" data-f="gleague_2_id"><option value="">—</option>${ELENCO.map(opts).join('')}</select></div>`;
  }
  box.innerHTML = html;
  box.querySelectorAll('select').forEach(s => s.addEventListener('change', atualizarPreviewMinutos));
}

/* ── Minutos previstos (somente leitura) ── */
function atualizarPreviewMinutos() {
  // Pedimos ao backend pra recalcular com o quinteto/rotação atuais da tela,
  // sem esperar o autosave — assim o GM ve o efeito na hora.
  clearTimeout(atualizarPreviewMinutos._t);
  atualizarPreviewMinutos._t = setTimeout(async () => {
    try {
      const r = await fetch('/api/tactics.php?action=preview_minutes', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(montarPayload())
      });
      const d = await r.json();
      if (d.success) renderPreviewMinutos(d.preview_minutes || {});
    } catch (e) { /* pré-visualização é cortesia — sem bloquear a tela por isso */ }
  }, 350);
}

function renderPreviewMinutos(mapa) {
  const q = quintetoIds();
  const ids = Object.keys(mapa).map(Number);
  if (!ids.length) { $('minPreview').innerHTML = '<span style="color:var(--text-3);font-size:12px">Defina o quinteto para ver a prévia.</span>'; return; }
  const ordenado = ids.sort((a, b) => (mapa[b] || 0) - (mapa[a] || 0));
  $('minPreview').innerHTML = ordenado.map(id => {
    const p = ELENCO.find(x => Number(x.id) === id);
    if (!p) return '';
    const titular = q.includes(id);
    return `<div class="min-chip"><span class="tag ${titular ? 'tit' : ''}">${titular ? 'Tit' : 'Banco'}</span>
      <span>${esc(p.name)}</span><span class="mn">${mapa[id]}min</span></div>`;
  }).join('');
}

/* ── Carga ── */
async function carregar() {
  carregando = true;
  $('carregando').style.display = '';
  $('conteudo').style.display = 'none';
  $('msgSalvar').innerHTML = '';

  const r = await fetch('/api/tactics.php?action=get');
  const d = await r.json();
  if (!d.success) { $('carregando').textContent = d.error || 'Erro ao carregar.'; return; }

  ELENCO = d.players || [];
  TATICAS = d.tactics || {};
  ACTIVE_SLOT = d.active_slot || 'regular';
  GLEAGUE_SLOTS = d.gleague_slots || 0;
  EDIT_WINDOW = d.edit_window || { open: true };
  SLOT = ACTIVE_SLOT;

  if (!ELENCO.length) { $('carregando').textContent = 'Seu elenco está vazio.'; return; }

  renderJanela();
  montarQuinteto();
  montarGleague();
  mostrarSlot(SLOT);

  $('carregando').style.display = 'none';
  $('conteudo').style.display = '';
  aplicarBloqueioEdicao();
}

function mostrarSlot(slot) {
  SLOT = slot;
  carregando = true;
  document.querySelectorAll('.slot-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.slot === SLOT);
    b.classList.toggle('saved', !!TATICAS[b.dataset.slot]?.saved);
    b.classList.toggle('is-official', b.dataset.slot === ACTIVE_SLOT);
  });

  const t = TATICAS[slot]?.data || {};
  POSICOES.forEach((_, i) => {
    const s = document.querySelector(`#court select[data-f="starter_${i+1}_id"]`);
    if (s) s.value = t[`starter_${i+1}_id`] || '';
  });
  document.querySelectorAll('[data-f]').forEach(el => {
    const f = el.dataset.f;
    if (f.startsWith('starter_')) return;
    el.value = (t[f] !== null && t[f] !== undefined) ? t[f] : '';
  });

  atualizarQuinteto();
  renderPreviewMinutos(TATICAS[slot]?.preview_minutes || {});

  const statusBox = $('tacticStatus');
  statusBox.innerHTML = (slot === ACTIVE_SLOT)
    ? `<span class="badge-ativa"><i class="bi bi-check-circle-fill"></i> Esta é a tática ativa agora</span>`
    : '';
  $('btnAtivar').style.display = (slot === ACTIVE_SLOT) ? 'none' : '';

  carregando = false;
  sujo = false;
}

function aplicarSugestao() {
  (window.SUGERIDO_STARTERS || []).forEach((id, i) => {
    const s = document.querySelector(`#court select[data-f="starter_${i+1}_id"]`);
    if (s) s.value = id || '';
  });
  atualizarQuinteto();
  atualizarPreviewMinutos();
}

$('btnSugQuinteto').addEventListener('click', async () => {
  try {
    const r = await fetch('/api/tactics.php?action=get');
    const d = await r.json();
    window.SUGERIDO_STARTERS = d.suggested_starters || [];
    aplicarSugestao();
    msg('info', 'Quinteto sugerido pelo elenco.');
  } catch (e) { /* mantém quinteto atual se a sugestão falhar */ }
});
$('btnLimparQuinteto').addEventListener('click', () => {
  document.querySelectorAll('#court select').forEach(s => { s.value = ''; });
  atualizarQuinteto(); atualizarPreviewMinutos();
});
/* ── Copiar a tática como texto ──
 * Copia o que está na tela num formato que se cola direto no WhatsApp: sem
 * markdown, sem tabela, só rótulo e valor. O botão antes copiava DE outra
 * tática, o que ninguém usava — o que se quer é levar a tática pra fora. */
const ROTULOS_SISTEMA = {
  technical_model: 'Modelo técnico', game_style: 'Estilo de jogo',
  offense_style: 'Foco ofensivo', pace: 'Ritmo',
  offensive_rebound: 'Rebote ofensivo', defensive_rebound: 'Rebote defensivo',
  offensive_aggression: 'Agressividade defensiva', defensive_focus: 'Foco defensivo',
};

function nomeDoJogador(id) {
  const p = ELENCO.find(x => String(x.id) === String(id));
  return p ? p.name : null;
}

function montarTextoDaTatica() {
  const linhas = [];
  const timeNome = TIME_NOME;
  linhas.push(`*${timeNome || 'Tática'}* — ${SLOT_LABELS[SLOT] || 'Tática'}`);

  // Quinteto na ordem em que está na quadra (PG → C).
  const titulares = [];
  for (let i = 1; i <= 5; i++) {
    const s = document.querySelector(`#court select[data-f="starter_${i}_id"]`);
    const nome = s && s.value ? nomeDoJogador(s.value) : null;
    if (nome) titulares.push(nome);
  }
  if (titulares.length) {
    linhas.push('');
    linhas.push('*Quinteto*');
    titulares.forEach((n, i) => linhas.push(`${i + 1}. ${n}`));
  }

  const gl = [];
  ['gleague_1_id', 'gleague_2_id'].forEach(f => {
    const el = document.querySelector(`[data-f="${f}"]`);
    const nome = el && el.value ? nomeDoJogador(el.value) : null;
    if (nome) gl.push(nome);
  });
  if (gl.length) {
    linhas.push('');
    linhas.push('*G-League*');
    gl.forEach(n => linhas.push(`• ${n}`));
  }

  const sistema = [];
  Object.entries(ROTULOS_SISTEMA).forEach(([campo, rotulo]) => {
    const el = document.querySelector(`[data-f="${campo}"]`);
    if (!el || !el.value) return;
    // O texto da opção, não o código interno — é o que a pessoa lê no jogo.
    const txt = el.options ? (el.options[el.selectedIndex]?.text || el.value) : el.value;
    sistema.push(`${rotulo}: ${txt}`);
  });
  const rot = document.querySelector('[data-f="rotation_players"]');
  if (rot && rot.value) sistema.push(`Jogadores na rotação: ${rot.value}`);
  const vet = document.querySelector('[data-f="veteran_focus"]');
  if (vet && vet.value !== '') sistema.push(`Foco em veteranos: ${vet.value}`);
  if (sistema.length) {
    linhas.push('');
    linhas.push('*Sistema de jogo*');
    sistema.forEach(l => linhas.push(l));
  }

  ['playbook', 'notes'].forEach(campo => {
    const el = document.querySelector(`[data-f="${campo}"]`);
    if (!el || !el.value.trim()) return;
    linhas.push('');
    linhas.push(campo === 'playbook' ? '*Playbook*' : '*Observações*');
    linhas.push(el.value.trim());
  });

  return linhas.join('\n');
}

$('btnCopiar').addEventListener('click', async () => {
  const texto = montarTextoDaTatica();
  const btn = $('btnCopiar');
  const original = btn.innerHTML;
  try {
    await navigator.clipboard.writeText(texto);
  } catch (e) {
    // clipboard bloqueado (http, permissão): cai no método antigo, que ainda
    // funciona em praticamente todo navegador.
    const ta = document.createElement('textarea');
    ta.value = texto;
    ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e2) {
      msg('erro', 'Não consegui copiar. Selecione e copie manualmente.');
      document.body.removeChild(ta);
      return;
    }
    document.body.removeChild(ta);
  }
  btn.innerHTML = '<i class="bi bi-check2"></i> Copiado!';
  setTimeout(() => { btn.innerHTML = original; }, 1800);
});

/* ── Salvar / ativar ── */
function montarPayload() {
  const payload = { action: 'save', slot: SLOT };
  document.querySelectorAll('[data-f]').forEach(el => { payload[el.dataset.f] = el.value || null; });
  return payload;
}

function statusSalvamento(estado, texto) {
  const el = $('statusSalvar');
  const icones = { salvando: 'arrow-repeat', ok: 'cloud-check', erro: 'exclamation-triangle' };
  el.innerHTML = `<i class="bi bi-${icones[estado] || 'cloud-check'}"></i> ${texto}`;
  el.style.color = estado === 'erro' ? '#f87171' : '';
}

async function gravar({ silencioso = false } = {}) {
  if (!EDIT_WINDOW.open) return false;
  atualizarQuinteto();
  statusSalvamento('salvando', 'Salvando…');
  try {
    const r = await fetch('/api/tactics.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(montarPayload())
    });
    const d = await r.json();
    if (!r.ok || !d.success) {
      statusSalvamento('erro', 'Não salvou');
      if (!silencioso) msg('err', esc(d.error || 'Erro ao salvar.'));
      return false;
    }
    const hora = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    statusSalvamento('ok', 'Salvo às ' + hora);
    if (TATICAS[SLOT]) TATICAS[SLOT].saved = true;
    document.querySelector(`.slot-btn[data-slot="${SLOT}"]`)?.classList.add('saved');
    sujo = false;
    if (!silencioso) msg('ok', `<strong>${esc(SLOT_LABELS[SLOT])}</strong> salva.`);
    return true;
  } catch (e) {
    statusSalvamento('erro', 'Sem conexão');
    if (!silencioso) msg('err', 'Erro ao salvar.');
    return false;
  }
}

$('btnAtivar').addEventListener('click', async () => {
  if (!EDIT_WINDOW.open) return;
  if (sujo) await gravar({ silencioso: true });
  try {
    const r = await fetch('/api/tactics.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'set_active', slot: SLOT })
    });
    const d = await r.json();
    if (!d.success) { msg('err', esc(d.error || 'Erro ao ativar.')); return; }
    ACTIVE_SLOT = SLOT;
    mostrarSlot(SLOT);
    msg('ok', `<strong>${esc(SLOT_LABELS[SLOT])}</strong> agora é a tática ativa do time.`);
  } catch (e) { msg('err', 'Erro ao ativar.'); }
});

$('btnSalvar').addEventListener('click', () => gravar());

function agendarAutosave() {
  if (!EDIT_WINDOW.open) return;
  sujo = true;
  statusSalvamento('salvando', 'Alterações pendentes…');
  clearTimeout(timerAuto);
  timerAuto = setTimeout(() => gravar({ silencioso: true }), 1200);
}

['input', 'change'].forEach(ev =>
  document.addEventListener(ev, e => {
    if (carregando) return;
    if (e.target.closest('#conteudo')) agendarAutosave();
  })
);

$('slots').addEventListener('click', async e => {
  const b = e.target.closest('.slot-btn');
  if (!b || b.dataset.slot === SLOT) return;
  clearTimeout(timerAuto);
  if (sujo) await gravar({ silencioso: true });
  mostrarSlot(b.dataset.slot);
});

window.addEventListener('beforeunload', () => {
  if (!sujo || !EDIT_WINDOW.open) return;
  clearTimeout(timerAuto);
  navigator.sendBeacon?.('/api/tactics.php',
    new Blob([JSON.stringify(montarPayload())], { type: 'application/json' }));
});

carregar();
</script>
</body>
</html>
