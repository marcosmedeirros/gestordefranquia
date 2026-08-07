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

$dados = array_map(function ($r) {
    $num = function ($v) { return ($v === null || $v === '') ? null : (float)$v; };
    return [
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

$comStats = count(array_filter($dados, fn($d) => $d['jogos'] !== null));
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
  --sidebar-w:260px; --font:'Montserrat',sans-serif; --radius:14px;
  --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
}
:root[data-theme="light"]{
  --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
  --border:#e3e6ee; --border-md:#d7dbe6;
  --text:#111217; --text-2:#5b6270; --text-3:#59616e;
}
*,*::before,*::after{box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:var(--font);margin:0}
.main{margin-left:var(--sidebar-w);min-height:100vh;transition:margin var(--t) var(--ease)}

/* Topbar mobile — mesmo padrão de players.php */
.topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);
  border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:240}
.topbar-title{font-weight:700;font-size:15px;flex:1}
.topbar-title em{color:var(--red);font-style:normal}
.menu-btn{width:34px;height:34px;border-radius:9px;background:var(--panel-2);
  border:1px solid var(--border);color:var(--text);display:flex;align-items:center;justify-content:center}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:250}
.sb-overlay.show{display:block}

.page-hero{padding:26px 28px 6px}
.page-eyebrow{font-size:10px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:6px}
.page-title{font-size:26px;font-weight:900;letter-spacing:-.6px;line-height:1.1;display:flex;align-items:center;gap:10px}
.page-title i{color:var(--red)}
.page-sub{color:var(--text-2);font-size:14px;margin-top:5px}

.content{padding:16px 28px 60px}

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
    <h1 class="page-title"><i class="bi bi-bar-chart-line-fill"></i> Stats dos Jogadores</h1>
    <p class="page-sub">Clique no título de uma coluna para ordenar. Clique de novo para inverter.</p>
  </div>

  <div class="content">
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
          <tr id="cabecalho">
            <th class="col-nome"      data-c="nome"  data-tipo="txt"><span class="seta">▼</span>Jogador</th>
            <th class="col-time"      data-c="time"  data-tipo="txt">Time</th>
            <th                       data-c="pos"   data-tipo="txt">Pos</th>
            <th class="num"           data-c="idade" data-tipo="num">Idade</th>
            <th class="num"           data-c="ovr"   data-tipo="num">OVR</th>
            <th class="num"           data-c="jogos" data-tipo="num">Jogos</th>
            <th class="num"           data-c="min"   data-tipo="num">Min</th>
            <th class="num"           data-c="pts"   data-tipo="num">Pts</th>
            <th class="num"           data-c="reb"   data-tipo="num">Reb</th>
            <th class="num"           data-c="ast"   data-tipo="num">Ast</th>
            <th class="num"           data-c="rou"   data-tipo="num">Rou</th>
            <th class="num"           data-c="toc"   data-tipo="num">Toc</th>
          </tr>
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
const TEM_TEMP   = <?= $seasonId ? 'true' : 'false' ?>;

// Estatística ainda não lançada. Vale pra regra de "some em Todos os times".
const semStat = p => p.jogos === null;

let ordCol = 'ovr', ordAsc = false;

const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
  ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

/** 1 casa decimal nas médias; traço quando não há lançamento. */
const fmt = (v, dec) => (v === null || v === undefined) ? '—' : (dec ? Number(v).toFixed(1) : String(v));

function filtrar() {
  const termo = (document.getElementById('fBusca').value || '').trim().toLowerCase();
  const time  = document.getElementById('fTime').value;
  const pos   = document.getElementById('fPos').value;

  return DADOS.filter(p => {
    if (time && String(p.timeId) !== String(time)) return false;
    if (pos && p.pos !== pos) return false;
    if (termo && p.nome.toLowerCase().indexOf(termo) < 0) return false;
    // Sem time escolhido, quem não tem estatística fica de fora: a lista da
    // liga inteira não pode virar uma parede de traços. Com um time
    // escolhido, aparecem todos — é assim que o GM vê o que falta lançar.
    if (!time && semStat(p)) return false;
    return true;
  });
}

function ordenar(linhas) {
  const th   = document.querySelector(`#cabecalho th[data-c="${ordCol}"]`);
  const tipo = th ? th.dataset.tipo : 'num';
  return linhas.slice().sort((a, b) => {
    let x = a[ordCol], y = b[ordCol];
    if (tipo === 'txt') {
      return ordAsc ? String(x).localeCompare(String(y), 'pt-BR')
                    : String(y).localeCompare(String(x), 'pt-BR');
    }
    // Nulo sempre por último, nas duas direções: linha sem lançamento não
    // pode encabeçar o ranking só por estar vazia.
    const nx = x === null || x === undefined, ny = y === null || y === undefined;
    if (nx && ny) return 0;
    if (nx) return 1;
    if (ny) return -1;
    return ordAsc ? x - y : y - x;
  });
}

function render() {
  const linhas = ordenar(filtrar());
  const corpo  = document.getElementById('corpo');
  const vazio  = document.getElementById('vazio');
  const time   = document.getElementById('fTime').value;

  document.querySelectorAll('#cabecalho th').forEach(th => {
    const on = th.dataset.c === ordCol;
    th.classList.toggle('ord', on);
    const seta = th.querySelector('.seta');
    if (on) {
      if (seta) seta.textContent = ordAsc ? '▲' : '▼';
      else th.insertAdjacentHTML('afterbegin', `<span class="seta">${ordAsc ? '▲' : '▼'}</span>`);
    }
  });

  if (!linhas.length) {
    corpo.innerHTML = '';
    vazio.style.display = 'block';
    vazio.innerHTML = !TEM_TEMP
      ? '<i class="bi bi-calendar-x"></i>Nenhuma temporada aberta nesta liga ainda.'
      : (COM_STATS === 0
        ? '<i class="bi bi-clipboard-data"></i>Nenhuma estatística lançada nesta temporada ainda.<br>'
          + 'Elas aparecem aqui conforme os GMs preenchem em <a href="/atualizar-elenco.php">Atualizar elenco</a>.'
          + '<br><span style="font-size:12.5px;color:var(--text-3)">Escolha um time no filtro para ver o elenco mesmo sem lançamento.</span>'
        : '<i class="bi bi-search"></i>Nenhum jogador com esses filtros.');
    document.getElementById('contador').textContent = '';
    return;
  }
  vazio.style.display = 'none';

  corpo.innerHTML = linhas.map(p => {
    const meu = MEU_TIME && p.timeId === MEU_TIME;
    const td  = (c, v, dec) =>
      `<td class="num${ordCol === c ? ' ord' : ''}">${fmt(v, dec)}</td>`;
    return `<tr class="${meu ? 'meu' : ''}${semStat(p) ? ' sem-stat' : ''}">
      <td class="col-nome${ordCol === 'nome' ? ' ord' : ''}"><a class="pl-link" href="/player.php?id=${p.id}">${esc(p.nome)}</a></td>
      <td class="col-time${ordCol === 'time' ? ' ord' : ''}">${esc(p.time)}</td>
      <td class="${ordCol === 'pos' ? 'ord' : ''}"><span class="pos-tag">${esc(p.pos) || '—'}</span></td>
      ${td('idade', p.idade)}${td('ovr', p.ovr)}${td('jogos', p.jogos)}
      ${td('min', p.min, 1)}${td('pts', p.pts, 1)}${td('reb', p.reb, 1)}
      ${td('ast', p.ast, 1)}${td('rou', p.rou, 1)}${td('toc', p.toc, 1)}
    </tr>`;
  }).join('');

  const semLanc = linhas.filter(semStat).length;
  document.getElementById('contador').textContent =
    `${linhas.length} jogador${linhas.length === 1 ? '' : 'es'}`
    + (time && semLanc ? ` · ${semLanc} sem lançamento` : '');
}

document.getElementById('cabecalho').addEventListener('click', e => {
  const th = e.target.closest('th');
  if (!th || !th.dataset.c) return;
  if (ordCol === th.dataset.c) {
    ordAsc = !ordAsc;
  } else {
    ordCol = th.dataset.c;
    // Texto começa de A a Z; número começa do maior, que é o que se quer ver.
    ordAsc = th.dataset.tipo === 'txt';
  }
  render();
});

['fBusca', 'fTime', 'fPos'].forEach(id => {
  const el = document.getElementById(id);
  el.addEventListener(id === 'fBusca' ? 'input' : 'change', () => {
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
  chip.addEventListener('click', () => {
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
