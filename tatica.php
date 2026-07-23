<?php
/**
 * Tática — a tela de trabalho do dia a dia.
 *
 * O GM ajusta aqui quinteto, rotação, minutos e o sistema de jogo, com o
 * elenco de verdade na frente. Nada é enviado ao jogo por esta página: o que
 * ela guarda alimenta o formulário de diretrizes.php na hora do envio oficial,
 * para o GM só revisar em vez de digitar tudo de novo.
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

// Próxima deadline de diretrizes da liga, para a página lembrar o GM.
$deadline = null;
try {
    $st = $pdo->prepare("SELECT deadline_date, description, phase FROM directive_deadlines
                         WHERE league = ? AND is_active = 1 AND deadline_date >= NOW()
                         ORDER BY deadline_date ASC LIMIT 1");
    $st->execute([$team['league']]);
    $deadline = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {}

$OPCOES = [
    'pace' => ['no_preference' => 'Sem preferência', 'patient' => 'Patient Offense',
               'average' => 'Average Tempo', 'shoot_at_will' => 'Shoot at Will'],
    'game_style' => ['balanced' => 'Balanced', 'triangle' => 'Triangle', 'grit_grind' => 'Grit & Grind',
                     'pace_space' => 'Pace & Space', 'perimeter_centric' => 'Perimeter Centric',
                     'post_centric' => 'Post Centric', 'seven_seconds' => 'Seven Seconds', 'defense' => 'Defense'],
    'offense_style' => ['no_preference' => 'Sem preferência', 'pick_roll' => 'Pick & Roll',
                        'neutral' => 'Neutro', 'play_through_star' => 'Play Through Star',
                        'get_to_basket' => 'Get to The Basket', 'get_shooters_open' => 'Get Shooters Open',
                        'feed_post' => 'Feed The Post'],
    'offensive_rebound' => ['no_preference' => 'Sem preferência', 'crash_glass' => 'Crash Offensive Glass',
                            'some_crash' => 'Some Crash, Others Get Back', 'limit_transition' => 'Limit Transition'],
    'defensive_rebound' => ['no_preference' => 'Sem preferência', 'crash_glass' => 'Crash Defensive Glass',
                            'some_crash' => 'Some Crash Others Run', 'run_transition' => 'Run in Transition'],
    'offensive_aggression' => ['no_preference' => 'Sem preferência', 'physical' => 'Play Physical Defense',
                               'conservative' => 'Conservative Defense', 'neutral' => 'Neutral Defensive Aggression'],
    'defensive_focus' => ['no_preference' => 'Sem preferência', 'neutral' => 'Neutral Defensive Focus',
                          'protect_paint' => 'Protect the Paint', 'limit_perimeter' => 'Limit Perimeter Shots'],
    'rotation_style' => ['auto' => 'Automática', 'manual' => 'Manual'],
    'technical_model' => ['HC' => 'HC', 'FBA 14' => 'FBA 14', 'Michael Stauffer' => 'Michael Stauffer',
                          'Joe Mazzulla' => 'Joe Mazzulla', 'Mark Daigneault' => 'Mark Daigneault',
                          'Greg Popovich' => 'Greg Popovich', 'Phil Jackson' => 'Phil Jackson'],
];
?>
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
:root{--red:#fc0025;--red-soft:color-mix(in srgb, var(--red) 10%, transparent);--border-red:color-mix(in srgb, var(--red) 25%, transparent);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1e1e24;--border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);--text:#f0f0f3;--text-2:#868690;--text-3:#7d7d85;--amber:#f59e0b;--green:#22c55e;--radius:14px;--radius-sm:10px;--font:'Montserrat',sans-serif;--sidebar-w:260px;--t:.2s;--ease:cubic-bezier(.4,0,.2,1)}
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

/* Minutos */
.min-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.min-total{font-family:'Oswald',sans-serif;font-size:20px;font-weight:700}
.min-total.ok{color:var(--green)}.min-total.over{color:#ef4444}.min-total.under{color:var(--amber)}
.min-bar{flex:1;min-width:120px;height:7px;background:var(--panel-3);border-radius:999px;overflow:hidden;border:1px solid var(--border)}
.min-bar i{display:block;height:100%;background:var(--green);border-radius:999px;transition:width .3s var(--ease)}
.min-bar.over i{background:#ef4444}.min-bar.under i{background:var(--amber)}
.mrow{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--border)}
.mrow:last-child{border-bottom:none}
.mrow .nm{flex:1;min-width:0;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mrow .tag{font-size:9.5px;font-weight:700;padding:2px 7px;border-radius:999px;background:var(--panel-3);border:1px solid var(--border);color:var(--text-3);flex-shrink:0}
.mrow .tag.tit{background:var(--red-soft);border-color:var(--border-red);color:var(--red)}
.mrow .ov{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;color:var(--text-2);width:26px;text-align:right;flex-shrink:0}
.mrow input{width:62px;background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:8px;padding:5px 6px;font-family:inherit;font-size:12.5px;text-align:center;flex-shrink:0}
.mrow input:focus{outline:none;border-color:var(--red)}
.mrow.zero .nm,.mrow.zero .ov{opacity:.5}

/* Sistema */
.fgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:13px}
.field label{display:block;font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--text-3);margin-bottom:5px}
.field select,.field input,.field textarea{width:100%;background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:9px;padding:8px 10px;font-family:inherit;font-size:12.5px}
.field select:focus,.field input:focus,.field textarea:focus{outline:none;border-color:var(--red)}
.field textarea{resize:vertical;min-height:76px}

.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:10px;background:var(--red);border:1px solid var(--red);color:#fff;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:filter var(--t) var(--ease);text-decoration:none}
.btn:hover:not(:disabled){filter:brightness(1.1)}
.btn:disabled{opacity:.6;cursor:not-allowed}
.btn.ghost{background:var(--panel-2);border-color:var(--border-md);color:var(--text-2)}
.btn.ghost:hover:not(:disabled){border-color:var(--red);color:var(--red);filter:none}
.btn.sm{padding:7px 12px;font-size:12px}

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
    <div>Ajuste aqui o seu time no dia a dia. <strong>Nada é enviado ao jogo por esta página</strong> —
    na hora da diretriz, o formulário em <a href="diretrizes.php" style="color:var(--red)">Diretrizes</a>
    já vem preenchido com o que estiver salvo aqui, e você só revisa e envia.</div>
  </div>

  <?php if ($deadline): ?>
  <div class="aviso warn">
    <i class="bi bi-calendar-event"></i>
    <div>Próxima diretriz: <strong><?= htmlspecialchars($deadline['description'] ?: 'sem descrição') ?></strong>
    até <?= date('d/m/Y \à\s H:i', strtotime($deadline['deadline_date'])) ?>
    <?= $deadline['phase'] === 'playoffs' ? ' (playoffs)' : '' ?>.</div>
  </div>
  <?php endif; ?>

  <div id="carregando" class="panel" style="text-align:center;color:var(--text-3);font-size:13px">Carregando elenco…</div>

  <div id="conteudo" style="display:none">

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

    <!-- Rotação e minutos -->
    <div class="panel">
      <div class="section-title"><i class="bi bi-stopwatch"></i> Minutos por jogo
        <span class="hint">A soma precisa fechar 240 (5 jogadores × 48 minutos).</span>
      </div>
      <div class="min-head">
        <span class="min-total" id="minTotal">0 / 240</span>
        <span class="min-bar" id="minBar"><i style="width:0"></i></span>
        <button class="btn ghost sm" id="btnSugMinutos"><i class="bi bi-magic"></i> Distribuir automático</button>
      </div>
      <div id="minLista"></div>
    </div>

    <!-- Sistema -->
    <div class="panel">
      <div class="section-title"><i class="bi bi-sliders"></i> Sistema de jogo</div>
      <div class="fgrid">
        <?php foreach ([
            'technical_model' => 'Modelo técnico', 'game_style' => 'Estilo de jogo',
            'offense_style' => 'Foco ofensivo', 'pace' => 'Ritmo',
            'offensive_rebound' => 'Rebote ofensivo', 'defensive_rebound' => 'Rebote defensivo',
            'offensive_aggression' => 'Agressividade defensiva', 'defensive_focus' => 'Foco defensivo',
            'rotation_style' => 'Rotação',
        ] as $campo => $rotulo): ?>
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
        <div class="field">
          <label for="f_rotation_players">Jogadores na rotação</label>
          <input type="number" id="f_rotation_players" data-f="rotation_players" min="5" max="15" placeholder="ex.: 9">
        </div>
        <div class="field">
          <label for="f_veteran_focus">Foco em veteranos (0–100)</label>
          <input type="number" id="f_veteran_focus" data-f="veteran_focus" min="0" max="100" placeholder="ex.: 50">
        </div>
      </div>
      <div class="fgrid" style="margin-top:13px">
        <div class="field" style="grid-column:1/-1">
          <label for="f_playbook">Playbook</label>
          <textarea id="f_playbook" data-f="playbook" placeholder="Jogadas e ajustes que você quer usar…"></textarea>
        </div>
        <div class="field" style="grid-column:1/-1">
          <label for="f_notes">Observações</label>
          <textarea id="f_notes" data-f="notes" placeholder="Anotações para você e para o admin…"></textarea>
        </div>
      </div>
    </div>

    <div class="savebar">
      <button class="btn" id="btnSalvar"><i class="bi bi-save2"></i> Salvar tática</button>
      <a class="btn ghost" href="diretrizes.php"><i class="bi bi-send"></i> Ir para o envio</a>
      <button class="btn ghost" id="btnRepetir" title="Preenche com a última diretriz enviada"><i class="bi bi-arrow-counterclockwise"></i> Repetir a anterior</button>
      <span class="st" id="statusSalvar"></span>
    </div>
    <div id="msgSalvar"></div>
  </div>

 </div>
</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
  document.getElementById('menuBtn')?.addEventListener('click', () => { sb?.classList.add('open'); ov?.classList.add('show'); });
  ov?.addEventListener('click', () => { sb?.classList.remove('open'); ov?.classList.remove('show'); });
  const btn = document.querySelector('[data-theme-toggle]');
  const aplica = t => { document.documentElement.dataset.theme = t; localStorage.setItem('fba-theme', t);
    if (btn) btn.innerHTML = t === 'light' ? '<i class="bi bi-sun"></i><span>Modo claro</span>' : '<i class="bi bi-moon"></i><span>Modo escuro</span>'; };
  aplica(localStorage.getItem('fba-theme') || 'dark');
  btn?.addEventListener('click', () => aplica(document.documentElement.dataset.theme === 'light' ? 'dark' : 'light'));
})();

const POSICOES = ['PG','SG','SF','PF','C'];
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

let ELENCO = [], SUGERIDO = null, ULTIMA = null;

function msg(tipo, texto) {
  $('msgSalvar').innerHTML = `<div class="aviso ${tipo}" style="margin-top:12px"><i class="bi bi-${
    tipo === 'ok' ? 'check-circle-fill' : tipo === 'err' ? 'x-octagon-fill' : 'info-circle'}"></i><div>${texto}</div></div>`;
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
    if (e.target.tagName === 'SELECT') { atualizarQuinteto(); marcarTitulares(); }
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

/* ── Minutos ── */
function montarMinutos() {
  const q = quintetoIds();
  const ordenado = [...ELENCO].sort((a, b) => {
    const qa = q.includes(Number(a.id)) ? 1 : 0, qb = q.includes(Number(b.id)) ? 1 : 0;
    if (qa !== qb) return qb - qa;
    return Number(b.ovr) - Number(a.ovr);
  });
  $('minLista').innerHTML = ordenado.map(p => {
    const titular = q.includes(Number(p.id));
    return `<div class="mrow" data-pid="${p.id}">
      <span class="nm">${esc(p.name)}</span>
      <span class="tag ${titular ? 'tit' : ''}">${titular ? 'Titular' : esc(p.role || '—')}</span>
      <span class="ov">${p.ovr}</span>
      <input type="number" min="0" max="48" data-min="${p.id}" value="0">
    </div>`;
  }).join('');
  $('minLista').addEventListener('input', e => { if (e.target.dataset.min) somarMinutos(); });
}

function marcarTitulares() {
  const q = quintetoIds();
  document.querySelectorAll('#minLista .mrow').forEach(r => {
    const titular = q.includes(Number(r.dataset.pid));
    const tag = r.querySelector('.tag');
    const p = ELENCO.find(x => Number(x.id) === Number(r.dataset.pid));
    tag.classList.toggle('tit', titular);
    tag.textContent = titular ? 'Titular' : (p?.role || '—');
  });
}

function somarMinutos() {
  let total = 0;
  document.querySelectorAll('#minLista input[data-min]').forEach(i => {
    const v = parseInt(i.value, 10) || 0;
    total += v;
    i.closest('.mrow').classList.toggle('zero', v === 0);
  });
  const el = $('minTotal'), bar = $('minBar');
  el.textContent = `${total} / 240`;
  el.className = 'min-total ' + (total === 240 ? 'ok' : total > 240 ? 'over' : 'under');
  bar.className = 'min-bar ' + (total === 240 ? '' : total > 240 ? 'over' : 'under');
  bar.querySelector('i').style.width = Math.min(100, total / 240 * 100) + '%';
  return total;
}

function aplicarMinutos(mapa) {
  document.querySelectorAll('#minLista input[data-min]').forEach(i => {
    i.value = mapa[i.dataset.min] ?? 0;
  });
  somarMinutos();
}

/* ── Carga ── */
async function carregar() {
  const r = await fetch('/api/tactics.php?action=get');
  const d = await r.json();
  if (!d.success) { $('carregando').textContent = d.error || 'Erro ao carregar.'; return; }

  ELENCO = d.players || [];
  SUGERIDO = d.suggested || null;
  ULTIMA = d.last || null;

  if (!ELENCO.length) { $('carregando').textContent = 'Seu elenco está vazio.'; return; }

  montarQuinteto();
  montarMinutos();

  const t = d.tactics;
  if (t) {
    // Ja existe rascunho salvo: restaura tudo como estava.
    POSICOES.forEach((_, i) => {
      const s = document.querySelector(`#court select[data-f="starter_${i+1}_id"]`);
      if (s && t[`starter_${i+1}_id`]) s.value = t[`starter_${i+1}_id`];
    });
    document.querySelectorAll('[data-f]').forEach(el => {
      const f = el.dataset.f;
      if (f.startsWith('starter_')) return;
      if (t[f] !== null && t[f] !== undefined) el.value = t[f];
    });
    aplicarMinutos(t.player_minutes || {});
  } else if (SUGERIDO) {
    // Primeira vez: abre com quinteto E minutos prontos, para o GM so ajustar.
    aplicarSugestao();
    aplicarMinutos(SUGERIDO.minutes || {});
    msg('info', 'Quinteto e minutos montados a partir do seu elenco. Ajuste o que quiser e salve.');
  }

  atualizarQuinteto();
  marcarTitulares();
  somarMinutos();

  $('btnRepetir').style.display = ULTIMA ? '' : 'none';
  $('carregando').style.display = 'none';
  $('conteudo').style.display = '';
}

function aplicarSugestao() {
  (SUGERIDO?.starters || []).forEach((id, i) => {
    const s = document.querySelector(`#court select[data-f="starter_${i+1}_id"]`);
    if (s) s.value = id || '';
  });
  atualizarQuinteto();
  marcarTitulares();
}

$('btnSugQuinteto').addEventListener('click', () => { aplicarSugestao(); msg('info', 'Quinteto sugerido pelo elenco.'); });
$('btnLimparQuinteto').addEventListener('click', () => {
  document.querySelectorAll('#court select').forEach(s => { s.value = ''; });
  atualizarQuinteto(); marcarTitulares();
});
$('btnSugMinutos').addEventListener('click', () => {
  aplicarMinutos(SUGERIDO?.minutes || {});
  msg('info', 'Minutos distribuídos por função e OVR, fechando 240.');
});
$('btnRepetir').addEventListener('click', () => {
  if (!ULTIMA) return;
  if (!confirm('Preencher com a última diretriz enviada? O que está na tela será substituído.')) return;
  POSICOES.forEach((_, i) => {
    const s = document.querySelector(`#court select[data-f="starter_${i+1}_id"]`);
    if (s) s.value = ULTIMA[`starter_${i+1}_id`] || '';
  });
  document.querySelectorAll('[data-f]').forEach(el => {
    const f = el.dataset.f;
    if (f.startsWith('starter_')) return;
    if (ULTIMA[f] !== null && ULTIMA[f] !== undefined) el.value = ULTIMA[f];
  });
  aplicarMinutos(ULTIMA.player_minutes || {});
  atualizarQuinteto(); marcarTitulares();
  msg('info', 'Preenchido com a última diretriz enviada. Revise e salve.');
});

/* ── Salvar ── */
$('btnSalvar').addEventListener('click', async () => {
  const btn = $('btnSalvar');
  const { dup } = atualizarQuinteto();
  if (dup.length) { msg('err', 'Corrija o quinteto: há jogador repetido.'); return; }

  const total = somarMinutos();
  if (total !== 240) {
    const ok = confirm(`Os minutos somam ${total}, não 240.\n\nSalvar mesmo assim? A diretriz oficial exige exatamente 240.`);
    if (!ok) return;
  }

  const payload = { action: 'save', player_minutes: {} };
  document.querySelectorAll('[data-f]').forEach(el => { payload[el.dataset.f] = el.value || null; });
  document.querySelectorAll('#minLista input[data-min]').forEach(i => {
    payload.player_minutes[i.dataset.min] = parseInt(i.value, 10) || 0;
  });

  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Salvando…';
  try {
    const r = await fetch('/api/tactics.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const d = await r.json();
    if (!r.ok || !d.success) msg('err', esc(d.error || 'Erro ao salvar.'));
    else {
      msg('ok', 'Tática salva. Ao abrir <strong>Diretrizes</strong> para enviar, o formulário já virá com estes dados.');
      $('statusSalvar').textContent = 'Salvo às ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }
  } catch (e) { msg('err', 'Erro ao salvar.'); }
  finally { btn.disabled = false; btn.innerHTML = '<i class="bi bi-save2"></i> Salvar tática'; }
});

carregar();
</script>
</body>
</html>
