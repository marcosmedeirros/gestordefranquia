<?php
/**
 * Stats dos Jogadores — tabela densa e ordenável, no espírito da tela de
 * elenco do 2K: muitas linhas visíveis, ordenar clicando na coluna, e a
 * coluna ordenada em destaque.
 *
 * As estatísticas vêm de player_season_stats, que é preenchida em
 * atualizar-elenco.php (por foto ou na mão). Enquanto ninguém lançou nada,
 * a tabela fica legitimamente vazia — por isso a regra abaixo.
 *
 * REGRA DE EXIBIÇÃO (pedida pelo Marcos): em "Todos os times", jogador sem
 * estatística lançada NÃO aparece — senão a lista da liga inteira vira uma
 * parede de traços. Ao filtrar por um time específico, aparecem todos,
 * inclusive os sem lançamento, que é como o GM enxerga o que falta preencher.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();
$liga = $user['league'] ?? 'ELITE';

// ── Temporada corrente da liga ──────────────────────────────────────────
$seasonId = null; $seasonNumber = null; $seasonLabel = '';
try {
    $st = $pdo->prepare('
        SELECT s.id, s.season_number, s.year, sp.start_year
        FROM seasons s
        LEFT JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ("completed"))
        ORDER BY s.created_at DESC LIMIT 1');
    $st->execute([$liga]);
    if ($s = $st->fetch(PDO::FETCH_ASSOC)) {
        $seasonId     = (int)$s['id'];
        $seasonNumber = (int)$s['season_number'];
        $ano = isset($s['start_year'], $s['season_number'])
            ? (int)$s['start_year'] + (int)$s['season_number'] - 1
            : (int)($s['year'] ?? 0);
        $seasonLabel = 'Temporada ' . $seasonNumber . ($ano ? ' · ' . $ano : '');
    }
} catch (Exception $e) { /* sem temporada: a página ainda lista o elenco */ }

// ── Time do usuário ─────────────────────────────────────────────────────
$st = $pdo->prepare('SELECT id, city, name FROM teams WHERE user_id = ? LIMIT 1');
$st->execute([$user['id']]);
$meuTime = $st->fetch(PDO::FETCH_ASSOC) ?: null;

$st = $pdo->prepare('SELECT id, city, name FROM teams WHERE league = ? ORDER BY city, name');
$st->execute([$liga]);
$times = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ── Jogadores + estatísticas da temporada ───────────────────────────────
// LEFT JOIN porque quem ainda não teve lançamento precisa aparecer no filtro
// por time. Quem some em "Todos" é decidido no cliente, não aqui.
$sql = '
    SELECT p.id, p.name, p.position, p.age, p.ovr, p.team_id,
           p.player_skill_grades,
           p.skill_in, p.skill_mid, p.skill_3pt, p.skill_post_d, p.skill_per_d,
           p.skill_play, p.skill_reb, p.skill_athl, p.skill_iq, p.skill_pot,
           t.city AS team_city, t.name AS team_name,
           ps.games, ps.min_pg, ps.pts_pg, ps.reb_pg, ps.ast_pg, ps.stl_pg, ps.blk_pg
    FROM players p
    JOIN teams t ON t.id = p.team_id
    LEFT JOIN player_season_stats ps
           ON ps.player_id = p.id ' . ($seasonId ? 'AND ps.season_id = :sid' : 'AND 1=0') . '
    WHERE t.league = :liga
    ORDER BY p.ovr DESC, p.name ASC';
$st = $pdo->prepare($sql);
$st->bindValue(':liga', $liga);
if ($seasonId) $st->bindValue(':sid', $seasonId, PDO::PARAM_INT);
$st->execute();
$linhas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Coluna skill_* tem prioridade; o JSON de notas é o fallback. Mesma regra do
// normalizeSkillGrades() no JS e do player.php. "-" conta como não preenchido.
$SKILLS = [
    'in' => 'skill_in', 'mid' => 'skill_mid', 'pt3' => 'skill_3pt',
    'post_d' => 'skill_post_d', 'per_d' => 'skill_per_d', 'play' => 'skill_play',
    'reb' => 'skill_reb', 'athl' => 'skill_athl', 'iq' => 'skill_iq', 'pot' => 'skill_pot',
];

$dados = array_map(function ($r) use ($SKILLS) {
    $num = function ($v) { return ($v === null || $v === '') ? null : (float)$v; };

    $json = [];
    if (!empty($r['player_skill_grades'])) {
        $d = json_decode((string)$r['player_skill_grades'], true);
        if (is_array($d)) $json = $d;
    }
    $sk = [];
    foreach ($SKILLS as $chave => $coluna) {
        $v = $r[$coluna] ?? null;
        if ($v === null || $v === '' || $v === '-') $v = $json[$chave] ?? null;
        if ($v === null || $v === '' || $v === '-') $v = null;
        $sk[$chave] = $v;
    }

    return [
        'sk' => $sk,
        'id'    => (int)$r['id'],
        'nome'  => $r['name'],
        'pos'   => $r['position'] ?: '',
        'idade' => (int)$r['age'],
        'ovr'   => (int)$r['ovr'],
        'time'  => trim(($r['team_city'] ?? '') . ' ' . ($r['team_name'] ?? '')),
        'timeId'=> (int)$r['team_id'],
        'jogos' => $r['games'] === null ? null : (int)$r['games'],
        'min'   => $num($r['min_pg']),
        'pts'   => $num($r['pts_pg']),
        'reb'   => $num($r['reb_pg']),
        'ast'   => $num($r['ast_pg']),
        'rou'   => $num($r['stl_pg']),
        'toc'   => $num($r['blk_pg']),
    ];
}, $linhas);

$comStats  = count(array_filter($dados, fn($d) => $d['jogos'] !== null));
$comSkills = count(array_filter($dados, fn($d) => count(array_filter($d['sk'], fn($v) => $v !== null)) > 0));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<title>Stats dos Jogadores - FBA Manager</title>
<meta name="theme-color" content="#fc0025">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/styles.css">
<style>
:root{
  --red:#fc0025; --red-soft:color-mix(in srgb,var(--red) 10%,transparent);
  --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
  --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
  --text:#f0f0f3; --text-2:#9a9aa4; --text-3:#8d8d97;
  --green:#22c55e; --amber:#f59e0b; --blue:#3b82f6;
  --sidebar-w:260px; --font:'Montserrat',sans-serif; --radius:14px; --radius-sm:10px;
  --border-red:color-mix(in srgb,var(--red) 22%,transparent);
  --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
}
:root[data-theme="light"]{
  --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
  --border:#e3e6ee; --border-md:#d7dbe6;
  --border-red:color-mix(in srgb,var(--red) 18%,transparent);
  --text:#111217; --text-2:#5b6270; --text-3:#59616e;
}
*,*::before,*::after{box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:var(--font);margin:0}
.main{margin-left:var(--sidebar-w);min-height:100vh;transition:margin var(--t) var(--ease)}

<?php // Sidebar e topbar: CSS compartilhado, ao lado do markup em sidebar.php ?>
<?php include __DIR__ . '/includes/sidebar-css.php'; ?>

.page-hero{padding:26px 28px 6px}
.page-eyebrow{font-size:10px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:6px}
.page-title{font-size:26px;font-weight:900;letter-spacing:-.6px;line-height:1.1;display:flex;align-items:center;gap:10px}
.page-title i{color:var(--red)}
.page-sub{color:var(--text-2);font-size:14px;margin-top:5px}

.content{padding:16px 28px 60px}

/* ── abas ───────────────────────────────────────── */
.abas{display:flex;gap:6px;margin-bottom:12px;border-bottom:1px solid var(--border);padding-bottom:0}
.aba{background:none;border:0;border-bottom:2px solid transparent;padding:9px 15px;
  font-family:var(--font);font-size:13.5px;font-weight:700;color:var(--text-2);cursor:pointer;
  display:inline-flex;align-items:center;gap:7px;transition:all var(--t) var(--ease)}
.aba i{font-size:13px}
.aba:hover{color:var(--text)}
.aba.on{color:var(--red);border-bottom-color:var(--red)}

/* ── barra de filtros ───────────────────────────── */
.filtros{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.f-campo{background:var(--panel);border:1px solid var(--border-md);border-radius:9px;
  padding:8px 11px;color:var(--text);font-size:13px;font-family:var(--font);outline:none;min-width:0}
.f-campo:focus{border-color:var(--red)}
.f-busca{flex:1 1 220px;max-width:320px}
.f-chip{background:var(--panel);border:1px solid var(--border-md);border-radius:999px;padding:7px 14px;
  font-size:12.5px;font-weight:700;color:var(--text-2);cursor:pointer;font-family:var(--font);
  transition:all var(--t) var(--ease);white-space:nowrap}
.f-chip:hover{color:var(--text);border-color:var(--red)}
.f-chip.on{background:var(--red);border-color:var(--red);color:#fff}
.f-contador{margin-left:auto;font-size:12px;color:var(--text-3);white-space:nowrap;
  font-variant-numeric:tabular-nums}

/* ── tabela densa ───────────────────────────────── */
.tabela-caixa{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
  overflow:auto;max-height:calc(100vh - 250px)}
table.stats{width:100%;border-collapse:separate;border-spacing:0;font-size:13px;min-width:840px}
table.stats th,table.stats td{padding:0 10px;height:34px;white-space:nowrap;border-bottom:1px solid var(--border)}
table.stats thead th{position:sticky;top:0;z-index:3;background:var(--panel-2);
  font-size:10px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);
  cursor:pointer;user-select:none;transition:color var(--t) var(--ease)}
table.stats thead th:hover{color:var(--text)}
table.stats thead th .seta{opacity:0;margin-right:3px;font-size:9px}
table.stats thead th.ord{color:var(--red)}
table.stats thead th.ord .seta{opacity:1}

/* coluna do nome fixa: rolar na horizontal sem perder de quem é a linha */
table.stats th.col-nome,table.stats td.col-nome{position:sticky;left:0;z-index:2;background:var(--panel);
  text-align:left;min-width:180px;font-weight:700}
table.stats thead th.col-nome{z-index:4;background:var(--panel-2)}
table.stats td.num,table.stats th.num{text-align:right;font-variant-numeric:tabular-nums}
table.stats td.col-time{color:var(--text-2);max-width:190px;overflow:hidden;text-overflow:ellipsis}

/* destaque da coluna ordenada, como na tela do 2K */
table.stats td.ord{background:color-mix(in srgb,var(--red) 7%,transparent);color:var(--text);font-weight:700}

tbody tr:hover td{background:var(--panel-2)}
tbody tr:hover td.col-nome{background:var(--panel-2)}
tbody tr.meu td.col-nome{box-shadow:inset 3px 0 0 var(--red)}
tbody tr.meu td{background:color-mix(in srgb,var(--red) 4%,transparent)}
tbody tr.sem-stat td:not(.col-nome):not(.col-time){color:var(--text-3)}

.pl-link{color:inherit;text-decoration:none}
.pl-link:hover{color:var(--red)}
.pos-tag{display:inline-block;min-width:30px;text-align:center;font-size:10px;font-weight:800;
  padding:2px 5px;border-radius:5px;background:var(--panel-3);color:var(--text-2)}

.vazio{padding:48px 20px;text-align:center;color:var(--text-2)}
.vazio i{font-size:34px;color:var(--text-3);display:block;margin-bottom:12px}
.vazio a{color:var(--red)}

@media (max-width:992px){
  :root{--sidebar-w:0px}
  .main{margin-left:0;padding-top:54px;width:100%}
  .page-hero,.content{padding-left:14px;padding-right:14px}
  .tabela-caixa{max-height:none}
  .topbar{display:flex}
  .sidebar{transform:translateX(-260px)}
  .sidebar.open{transform:translateX(0)}
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
  <div class="topbar-title">FBA <em>Manager</em></div>
</header>

<div class="main">

  <div class="page-hero">
    <div class="page-eyebrow">Liga <?= htmlspecialchars($liga) ?><?= $seasonLabel ? ' · ' . htmlspecialchars($seasonLabel) : '' ?></div>
    <h1 class="page-title"><i class="bi bi-bar-chart-line-fill"></i> Stats e Skills</h1>
    <p class="page-sub">Clique no título de uma coluna para ordenar. Clique de novo para inverter.</p>
  </div>

  <div class="content">
    <div class="abas" id="abas">
      <button type="button" class="aba on" data-aba="stats"><i class="bi bi-clipboard-data"></i> Estatísticas</button>
      <button type="button" class="aba" data-aba="skills"><i class="bi bi-sliders"></i> Atributos</button>
    </div>

    <div class="filtros">
      <input type="search" id="fBusca" class="f-campo f-busca" placeholder="Buscar jogador…" autocomplete="off">
      <select id="fTime" class="f-campo">
        <option value="">Todos os times</option>
        <?php if ($meuTime): ?>
          <option value="<?= (int)$meuTime['id'] ?>">★ <?= htmlspecialchars(trim($meuTime['city'] . ' ' . $meuTime['name'])) ?></option>
        <?php endif; ?>
        <?php foreach ($times as $t): if ($meuTime && (int)$t['id'] === (int)$meuTime['id']) continue; ?>
          <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars(trim($t['city'] . ' ' . $t['name'])) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="fPos" class="f-campo">
        <option value="">Todas as posições</option>
        <?php foreach (['PG','SG','SF','PF','C'] as $p): ?>
          <option value="<?= $p ?>"><?= $p ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($meuTime): ?>
        <button type="button" class="f-chip" id="fMeu" data-time="<?= (int)$meuTime['id'] ?>">
          <i class="bi bi-star-fill" style="font-size:10px"></i> Meu time
        </button>
      <?php endif; ?>
      <span class="f-contador" id="contador"></span>
    </div>

    <div class="tabela-caixa">
      <table class="stats">
        <thead>
          <tr id="cabecalho"></tr>
        </thead>
        <tbody id="corpo"></tbody>
      </table>
      <div class="vazio" id="vazio" style="display:none"></div>
    </div>
  </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= assetUrl('/js/pwa.js') ?>"></script>
<script>
const DADOS      = <?= json_encode($dados, JSON_UNESCAPED_UNICODE) ?>;
const MEU_TIME   = <?= $meuTime ? (int)$meuTime['id'] : 'null' ?>;
const COM_STATS  = <?= (int)$comStats ?>;
const COM_SKILLS = <?= (int)$comSkills ?>;
const TEM_TEMP   = <?= $seasonId ? 'true' : 'false' ?>;

// Colunas fixas nas duas abas: quem é o jogador não muda de aba pra aba.
const FIXAS = [
  { c:'nome',  rot:'Jogador', tipo:'txt', cls:'col-nome' },
  { c:'time',  rot:'Time',    tipo:'txt', cls:'col-time' },
  { c:'pos',   rot:'Pos',     tipo:'txt', cls:'' },
  { c:'idade', rot:'Idade',   tipo:'num', cls:'num' },
  { c:'ovr',   rot:'OVR',     tipo:'num', cls:'num' },
];

const COLS = {
  stats: [
    { c:'jogos', rot:'Jogos' }, { c:'min', rot:'Min', dec:1 }, { c:'pts', rot:'Pts', dec:1 },
    { c:'reb', rot:'Reb', dec:1 }, { c:'ast', rot:'Ast', dec:1 },
    { c:'rou', rot:'Rou', dec:1 }, { c:'toc', rot:'Toc', dec:1 },
  ].map(function (o) { return Object.assign({ tipo:'num', cls:'num' }, o); }),
  skills: [
    ['in','IN'],['mid','MID'],['pt3','3PT'],['post_d','POST D'],['per_d','PER D'],
    ['play','PLAY'],['reb','REB'],['athl','ATHL'],['iq','IQ'],['pot','POT'],
  ].map(function (par) { return { c:'sk.' + par[0], rot:par[1], tipo:'skill', cls:'num' }; }),
};

let aba = 'stats';
let ordCol = 'ovr', ordAsc = false;

const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
  ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

/** Valor de uma coluna, incluindo as de atributo ("sk.iq"). */
function valor(p, c) {
  return c.indexOf('sk.') === 0 ? p.sk[c.slice(3)] : p[c];
}

// Notas em letra viram posto numérico só para ordenar — o app aceita as duas
// formas no mesmo campo. As faixas de cor seguem a leitura do resto do site.
const POSTO = { 'A+':95,'A':90,'A-':85,'B+':80,'B':75,'B-':70,
                'C+':65,'C':60,'C-':55,'D+':50,'D':45,'D-':40,'F':30 };
function postoSkill(v) {
  if (v === null || v === undefined || v === '') return null;
  const n = Number(v);
  if (!Number.isNaN(n) && String(v).trim() !== '') return n;   // já veio numérico
  const p = POSTO[String(v).toUpperCase().trim()];
  return p === undefined ? null : p;
}
function corSkill(v) {
  const p = postoSkill(v);
  if (p === null) return '';
  if (p >= 85) return 'color:#22c55e';
  if (p >= 70) return 'color:#f59e0b';
  return 'color:#ef4444';
}

/** A linha tem algum dado na aba atual? É o que decide sumir em "Todos". */
function vazioNaAba(p) {
  return aba === 'stats'
    ? p.jogos === null
    : COLS.skills.every(function (col) { return valor(p, col.c) === null; });
}

const fmt = (v, dec) => (v === null || v === undefined) ? '—' : (dec ? Number(v).toFixed(1) : String(v));

function colunas() { return FIXAS.concat(COLS[aba]); }

function filtrar() {
  const termo = (document.getElementById('fBusca').value || '').trim().toLowerCase();
  const time  = document.getElementById('fTime').value;
  const pos   = document.getElementById('fPos').value;

  return DADOS.filter(function (p) {
    if (time && String(p.timeId) !== String(time)) return false;
    if (pos && p.pos !== pos) return false;
    if (termo && p.nome.toLowerCase().indexOf(termo) < 0) return false;
    // Sem time escolhido, quem não tem dado NA ABA ATUAL fica de fora: a liga
    // inteira não pode virar uma parede de traços. Com um time escolhido,
    // aparecem todos — é assim que o GM vê o que falta preencher.
    if (!time && vazioNaAba(p)) return false;
    return true;
  });
}

function ordenar(linhas) {
  const col  = colunas().find(function (c) { return c.c === ordCol; });
  const tipo = col ? col.tipo : 'num';
  return linhas.slice().sort(function (a, b) {
    let x = valor(a, ordCol), y = valor(b, ordCol);
    if (tipo === 'txt') {
      return ordAsc ? String(x).localeCompare(String(y), 'pt-BR')
                    : String(y).localeCompare(String(x), 'pt-BR');
    }
    if (tipo === 'skill') { x = postoSkill(x); y = postoSkill(y); }
    // Nulo sempre por último, nas duas direções: linha sem lançamento não
    // pode encabeçar o ranking só por estar vazia.
    const nx = x === null || x === undefined, ny = y === null || y === undefined;
    if (nx && ny) return 0;
    if (nx) return 1;
    if (ny) return -1;
    return ordAsc ? x - y : y - x;
  });
}

function renderCabecalho() {
  document.getElementById('cabecalho').innerHTML = colunas().map(function (col) {
    const on = col.c === ordCol;
    return '<th class="' + col.cls + (on ? ' ord' : '') + '" data-c="' + col.c + '">'
      + '<span class="seta">' + (on ? (ordAsc ? '▲' : '▼') : '▼') + '</span>' + esc(col.rot) + '</th>';
  }).join('');
}

function render() {
  const linhas = ordenar(filtrar());
  const corpo  = document.getElementById('corpo');
  const vazio  = document.getElementById('vazio');
  const time   = document.getElementById('fTime').value;

  renderCabecalho();

  if (!linhas.length) {
    corpo.innerHTML = '';
    vazio.style.display = 'block';
    const nenhum = aba === 'stats' ? COM_STATS === 0 : COM_SKILLS === 0;
    const oQue   = aba === 'stats' ? 'estatística lançada' : 'atributo preenchido';
    vazio.innerHTML = (aba === 'stats' && !TEM_TEMP)
      ? '<i class="bi bi-calendar-x"></i>Nenhuma temporada aberta nesta liga ainda.'
      : (nenhum
        ? '<i class="bi bi-clipboard-data"></i>Nenhum ' + oQue + ' nesta liga ainda.<br>'
          + 'Os dados aparecem aqui conforme os GMs preenchem em <a href="/atualizar-elenco.php">Atualizar elenco</a>.'
          + '<br><span style="font-size:12.5px;color:var(--text-3)">Escolha um time no filtro para ver o elenco mesmo sem lançamento.</span>'
        : '<i class="bi bi-search"></i>Nenhum jogador com esses filtros.');
    document.getElementById('contador').textContent = '';
    return;
  }
  vazio.style.display = 'none';

  const cols = colunas();
  corpo.innerHTML = linhas.map(function (p) {
    const meu = MEU_TIME && p.timeId === MEU_TIME;
    const tds = cols.map(function (col) {
      const on = col.c === ordCol ? ' ord' : '';
      if (col.c === 'nome') {
        return '<td class="col-nome' + on + '"><a class="pl-link" href="/player.php?id=' + p.id + '">' + esc(p.nome) + '</a></td>';
      }
      if (col.c === 'time') return '<td class="col-time' + on + '">' + esc(p.time) + '</td>';
      if (col.c === 'pos')  return '<td class="' + on.trim() + '"><span class="pos-tag">' + (esc(p.pos) || '—') + '</span></td>';
      const v = valor(p, col.c);
      if (col.tipo === 'skill') {
        return '<td class="num' + on + '" style="' + corSkill(v) + '">' + (v === null ? '—' : esc(v)) + '</td>';
      }
      return '<td class="num' + on + '">' + fmt(v, col.dec) + '</td>';
    }).join('');
    return '<tr class="' + (meu ? 'meu' : '') + (vazioNaAba(p) ? ' sem-stat' : '') + '">' + tds + '</tr>';
  }).join('');

  const semLanc = linhas.filter(vazioNaAba).length;
  document.getElementById('contador').textContent =
    linhas.length + ' jogador' + (linhas.length === 1 ? '' : 'es')
    + (time && semLanc ? ' · ' + semLanc + ' sem lançamento' : '');
}

document.getElementById('cabecalho').addEventListener('click', function (e) {
  const th = e.target.closest('th');
  if (!th || !th.dataset.c) return;
  const col = colunas().find(function (c) { return c.c === th.dataset.c; });
  if (ordCol === th.dataset.c) {
    ordAsc = !ordAsc;
  } else {
    ordCol = th.dataset.c;
    // Texto começa de A a Z; número e nota começam do maior, que é o que interessa.
    ordAsc = !!(col && col.tipo === 'txt');
  }
  render();
});

document.getElementById('abas').addEventListener('click', function (e) {
  const b = e.target.closest('.aba');
  if (!b || b.dataset.aba === aba) return;
  aba = b.dataset.aba;
  document.querySelectorAll('#abas .aba').forEach(function (x) { x.classList.toggle('on', x === b); });
  // Se a ordenação era por coluna da outra aba, volta pro OVR.
  if (!colunas().some(function (c) { return c.c === ordCol; })) { ordCol = 'ovr'; ordAsc = false; }
  render();
});

['fBusca', 'fTime', 'fPos'].forEach(function (id) {
  const el = document.getElementById(id);
  el.addEventListener(id === 'fBusca' ? 'input' : 'change', function () {
    if (id === 'fTime') sincronizarChip();
    render();
  });
});

const chip = document.getElementById('fMeu');
function sincronizarChip() {
  if (!chip) return;
  chip.classList.toggle('on', document.getElementById('fTime').value === chip.dataset.time);
}
if (chip) {
  chip.addEventListener('click', function () {
    const sel = document.getElementById('fTime');
    sel.value = (sel.value === chip.dataset.time) ? '' : chip.dataset.time;
    sincronizarChip();
    render();
  });
}

render();

// Menu lateral no celular — mesmo comportamento das outras telas.
const sidebar = document.getElementById('sidebar');
const sbOverlay = document.getElementById('sbOverlay');
document.getElementById('menuBtn')?.addEventListener('click', () => {
  sidebar?.classList.toggle('open');
  sbOverlay?.classList.toggle('show');
});
sbOverlay?.addEventListener('click', () => {
  sidebar?.classList.remove('open');
  sbOverlay.classList.remove('show');
});
</script>
</body>
</html>
