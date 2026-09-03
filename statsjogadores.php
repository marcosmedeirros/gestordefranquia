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

/*
 * AS TEMPORADAS QUE DÁ PRA OLHAR.
 *
 * A página mostrava só a corrente, e não havia como ver o que o jogador fez
 * antes — a estatística existia no banco e não tinha porta. Agora a lista
 * inteira do SPRINT ATIVO vira um seletor.
 *
 * Só as que TÊM lançamento: temporada sem nenhuma linha em
 * player_season_stats abriria a tela inteira vazia, e escolher "Temporada 4"
 * pra não ver nada não é opção, é armadilha.
 *
 * O sprint filtra porque `season_number` se repete a cada sprint: sem isso a
 * lista traria três "Temporada 1" de eras diferentes, indistinguíveis.
 */
$temporadas = [];
try {
    $st = $pdo->prepare('
        SELECT s.id, s.season_number, s.year, s.status, sp.start_year
        FROM seasons s
        LEFT JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ?
          AND (sp.id IS NULL OR sp.status IS NULL OR sp.status = "active")
          AND EXISTS (SELECT 1 FROM player_season_stats ps WHERE ps.season_id = s.id)
        ORDER BY s.season_number DESC');
    $st->execute([$liga]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $ano = isset($s['start_year'], $s['season_number'])
            ? (int)$s['start_year'] + (int)$s['season_number'] - 1
            : (int)($s['year'] ?? 0);
        $temporadas[] = [
            'id'     => (int)$s['id'],
            'numero' => (int)$s['season_number'],
            'label'  => 'Temporada ' . (int)$s['season_number'] . ($ano ? ' · ' . $ano : ''),
        ];
    }
} catch (Exception $e) { /* sem temporadas: a página ainda lista o elenco */ }

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

/*
 * A ESCOLHIDA. `?temporada=` manda, e ela precisa estar na lista — id de
 * outra liga ou de sprint velho viraria estatística de gente que não é
 * daqui. Sem parâmetro, abre na corrente; se a corrente ainda não tem
 * lançamento nenhum, abre na mais recente que tem.
 */
// Guardada ANTES de $seasonId virar a escolhida: a importação de CSV grava
// sempre na corrente, e a tela precisa dizer isso quando você está olhando
// outra — senão o lançamento parece ir pra temporada que está na tela.
$seasonCorrenteId = $seasonId;

$idsValidos = array_column($temporadas, 'id');
$pedida = isset($_GET['temporada']) ? (int)$_GET['temporada'] : 0;
if ($pedida > 0 && in_array($pedida, $idsValidos, true)) {
    $seasonId = $pedida;
} elseif ($seasonId === null || !in_array($seasonId, $idsValidos, true)) {
    if ($temporadas) $seasonId = (int)$temporadas[0]['id'];
}
foreach ($temporadas as $t) {
    if ($t['id'] === $seasonId) { $seasonNumber = $t['numero']; $seasonLabel = $t['label']; break; }
}

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

/* ── barra dos botões de importar ───────────────── */
.admin-barra{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-4px 0 14px}
.aviso-temp{display:flex;gap:8px;align-items:flex-start;margin:0 0 14px;padding:10px 13px;
  border-radius:10px;font-size:13px;line-height:1.5;
  background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.28);color:var(--text-2)}
.aviso-temp b{color:var(--text)}
.aviso-temp i{color:#f59e0b;margin-top:2px;flex-shrink:0}
.admin-barra .f-chip{display:inline-flex;align-items:center;gap:6px}

/* ── importação em massa ────────────────────────── */
.imp-fundo{position:fixed;inset:0;z-index:90;background:rgba(6,6,9,.72);backdrop-filter:blur(3px);
  display:none;align-items:center;justify-content:center;padding:18px}
.imp-fundo.on{display:flex}
.imp-cx{background:var(--panel);border:1px solid var(--border-md);border-radius:16px;
  width:min(760px,100%);max-height:min(86vh,760px);display:flex;flex-direction:column;overflow:hidden}
.imp-cab{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border)}
.imp-cab b{font-size:15px;font-weight:800;flex:1;min-width:0}
.imp-x{background:none;border:0;color:var(--text-3);cursor:pointer;font-size:15px;padding:4px 6px}
.imp-x:hover{color:var(--text)}
.imp-corpo{padding:16px;overflow:auto;display:flex;flex-direction:column;gap:14px}
.imp-info{font-size:12.5px;line-height:1.55;color:var(--text-2)}
.imp-info code{background:var(--panel-2);border-radius:5px;padding:1px 5px;font-size:11.5px}
/* A lista rola sozinha: são até centenas de nomes, e o que importa embaixo
   dela é a área de colar o CSV. */
.imp-lista{border:1px solid var(--border);border-radius:11px;max-height:210px;overflow:auto}
.imp-linha{display:flex;align-items:center;gap:10px;padding:6px 11px;font-size:12.5px;
  border-bottom:1px solid var(--border)}
.imp-linha:last-child{border-bottom:none}
.imp-linha .id{font-size:11px;color:var(--text-3);font-variant-numeric:tabular-nums;
  min-width:44px;flex:none}
.imp-linha .nome{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.imp-linha .time{font-size:11px;color:var(--text-3);white-space:nowrap;overflow:hidden;
  text-overflow:ellipsis;max-width:38%}
.imp-acoes{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.imp-txt{width:100%;min-height:130px;background:var(--panel-2);border:1px solid var(--border-md);
  border-radius:11px;padding:10px 12px;color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
  font-size:12px;line-height:1.5;outline:none;resize:vertical}
.imp-txt:focus{border-color:var(--red)}
.imp-btn{background:var(--red);border:0;border-radius:10px;padding:10px 18px;color:#fff;
  font-family:var(--font);font-size:13px;font-weight:800;cursor:pointer}
.imp-btn[disabled]{opacity:.5;cursor:not-allowed}
.imp-msg{font-size:12.5px;line-height:1.55;border-radius:10px;padding:10px 12px}
.imp-msg.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#4ade80}
.imp-msg.erro{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171}
.imp-vazio{padding:18px;text-align:center;color:var(--text-3);font-size:12.5px}

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

/* ── Copiar os dados da linha ──
   Largura fixa e apertada: é uma ação, não um dado, e não pode disputar
   espaço com as colunas de número numa tabela que já rola de lado. */
table.stats td.col-copia,table.stats th.col-copia{width:34px;padding-left:2px;padding-right:6px;text-align:center}
.bt-copia{width:26px;height:26px;border-radius:7px;display:inline-flex;align-items:center;
  justify-content:center;cursor:pointer;font-size:12px;line-height:1;
  border:1px solid var(--border);background:var(--panel-2);color:var(--text-3);
  transition:color .15s,border-color .15s}
.bt-copia:hover{color:var(--red);border-color:color-mix(in srgb, var(--red) 35%, transparent)}
.bt-copia.ok{color:#22c55e;border-color:color-mix(in srgb, #22c55e 40%, transparent)}
/* No celular a linha inteira já está espremida; o botão encolhe junto. */
@media(max-width:640px){
  table.stats td.col-copia,table.stats th.col-copia{width:28px;padding-right:3px}
  .bt-copia{width:22px;height:22px;font-size:11px}
}

/* ── MODO COMPACTO ──
   O sticky da coluna do nome ja existia, mas nao bastava: com 180px de nome
   mais 151 de time, sobravam 16px dos 347 visiveis num celular de 375 — as
   dez colunas de atributo ficavam INTEIRAS fora da tela. Aqui a coluna do
   nome cede a metade da largura e o resto do espaco vai pros numeros, que
   e o que se veio comparar. */
.tabela-caixa.compacto table.stats{min-width:0}
.tabela-caixa.compacto table.stats th.col-nome,
.tabela-caixa.compacto table.stats td.col-nome{min-width:0;width:104px;max-width:104px;
  overflow:hidden;text-overflow:ellipsis}
.tabela-caixa.compacto table.stats th,
.tabela-caixa.compacto table.stats td{padding:0 6px}
/* Uma sombra na borda direita: sem ela o numero rolando por baixo do nome
   parece parte da coluna dele. */
.tabela-caixa.compacto table.stats th.col-nome,
.tabela-caixa.compacto table.stats td.col-nome{box-shadow:2px 0 6px rgba(0,0,0,.28)}
.tabela-caixa.compacto tbody tr.meu td.col-nome{box-shadow:inset 3px 0 0 var(--red),2px 0 6px rgba(0,0,0,.28)}

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
      <?php /* Recarrega a página: a estatística vem do servidor, e trazer
               todas as temporadas de uma vez pra filtrar no cliente seria
               carregar a liga inteira vezes o número de temporadas. */ ?>
      <?php if (count($temporadas) > 1): ?>
      <select id="fTemporada" class="f-campo" aria-label="Temporada"
              onchange="location.search = '?temporada=' + this.value">
        <?php foreach ($temporadas as $t): ?>
          <option value="<?= (int)$t['id'] ?>"<?= $t['id'] === $seasonId ? ' selected' : '' ?>>
            <?= htmlspecialchars($t['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
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
      <button type="button" class="f-chip" id="fCompacto"
        title="Encurta o nome e esconde o time, pra sobrar tela pros números">
        <i class="bi bi-arrows-collapse-vertical" style="font-size:11px"></i> Compactar
      </button>
      <span class="f-contador" id="contador"></span>
    </div>

    <?php /* A importação era só do admin. Virou de todo mundo: o gargalo era
             depender de uma pessoa só pra lançar o que a liga inteira já tem
             na mão, e quem tiver a planilha resolve. Vale pra liga toda, não
             só pro próprio elenco. */ ?>
    <div class="admin-barra">
      <button type="button" class="f-chip" onclick="abrirImport('stats')">
        <i class="bi bi-clipboard-data"></i> Importar estatísticas</button>
      <button type="button" class="f-chip" onclick="abrirImport('skills')">
        <i class="bi bi-sliders"></i> Importar atributos</button>
      <?php /* Ao lado da importação porque é o movimento contrário: leva a
               tabela inteira de uma vez. Sai separado por TAB, que é o que
               cola direto em planilha — o botão de cada linha continua
               existindo pro caso de um jogador só, e esse é em texto corrido
               porque vai pro grupo. */ ?>
      <button type="button" class="f-chip" id="btCopiaStats" onclick="copiarTudo('stats', this)">
        <i class="bi bi-clipboard-data"></i> Copiar estatísticas</button>
      <button type="button" class="f-chip" id="btCopiaSkills" onclick="copiarTudo('skills', this)">
        <i class="bi bi-sliders"></i> Copiar atributos</button>
    </div>

    <?php /* Olhando temporada passada, o aviso é obrigatório: a importação
             grava na CORRENTE, e sem dizer isso o lançamento parece ir pra
             temporada que está na tela. */ ?>
    <?php if ($seasonCorrenteId && $seasonId !== $seasonCorrenteId): ?>
    <div class="aviso-temp">
      <i class="bi bi-clock-history"></i>
      Você está vendo uma <b>temporada passada</b>. Importar estatísticas ou
      atributos grava sempre na <b>temporada atual</b>, não nesta.
    </div>
    <?php endif; ?>

    <div class="imp-fundo" id="impFundo" onclick="if(event.target===this)fecharImport()">
      <div class="imp-cx" role="dialog" aria-modal="true" aria-labelledby="impTitulo">
        <div class="imp-cab">
          <b id="impTitulo">Importar</b>
          <button type="button" class="imp-x" onclick="fecharImport()" aria-label="Fechar">
            <i class="bi bi-x-lg"></i></button>
        </div>
        <div class="imp-corpo" id="impCorpo"></div>
      </div>
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

/* MODO COMPACTO — pra comparar atributo no celular.

   Medido em 375px: o nome (180px) e o time (151px) ocupavam 331 dos 347
   visíveis, e as DEZ colunas de atributo ficavam todas fora da tela. Pra
   comparar dois jogadores era rolar pra direita perdendo o nome de vista.

   Compacto: o nome vira "A. Edwards", o time sai (o filtro de time já
   existe logo acima) e a coluna do jogador fica ANCORADA à esquerda —
   assim o nome não some enquanto se rola pelos números, que é o que
   torna a comparação possível. Fica gravado por aba do navegador. */
// No CELULAR ele ja vem ligado: o padrao largo la nao e uma preferencia,
// e uma tela onde as dez colunas de atributo ficam fora do campo de visao.
// No desktop segue desligado, que la a tabela quase cabe. A escolha da
// pessoa, quando existe, vence os dois.
let compacto = window.innerWidth < 760;
try {
  const salvo = localStorage.getItem('stats:compacto');
  if (salvo !== null) compacto = salvo === '1';
} catch (e) {}

/** "Anthony Edwards" → "A. Edwards". Nome de uma palavra só fica inteiro. */
function nomeCurto(nome) {
  const partes = String(nome || '').trim().split(/\s+/);
  if (partes.length < 2) return nome;
  return partes[0].charAt(0).toUpperCase() + '. ' + partes.slice(1).join(' ');
}

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
/* A categoria é parâmetro, e não a aba aberta: "Copiar atributos" estando na
   aba Estatísticas tem que olhar atributo, senão devolve o recorte errado —
   os jogadores com estatística, que é outra pergunta. Sem argumento vale a
   aba, que é o que a tabela quer. */
function vazioNaAba(p, qual) {
  return (qual || aba) === 'stats'
    ? p.jogos === null
    : COLS.skills.every(function (col) { return valor(p, col.c) === null; });
}

const fmt = (v, dec) => (v === null || v === undefined) ? '—' : (dec ? Number(v).toFixed(1) : String(v));

/* No compacto o que sai:

     Time    sempre — sao 151px em 375 de tela, e o filtro de time esta ali
             em cima pra quem precisar.
     Idade   so na aba de ATRIBUTOS. La sao dez colunas disputando a tela, e
             a idade nao entra na comparacao de skill; na aba de numeros ela
             fica, que sao sete colunas e sobra espaco.

   Pos e OVR ficam nas duas: sao as ancoras da comparacao — um pivo de 90
   nao se compara a um armador de 70. */
function colunas() {
  let fixas = FIXAS;
  if (compacto) {
    fixas = fixas.filter(function (c) {
      if (c.c === 'time') return false;
      if (c.c === 'idade' && aba === 'skills') return false;
      return true;
    });
  }
  return fixas.concat(COLS[aba]);
}

function filtrar(qual) {
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
    if (!time && vazioNaAba(p, qual)) return false;
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

/* No compacto o rotulo encolhe junto: "POST D" custava 69px de largura pra
   uma coluna cujo conteudo e "B+". O title guarda o nome inteiro. */
const ROTULO_CURTO = { 'POST D':'POST', 'PER D':'PER', 'ATHL':'ATL', 'Idade':'Id' };
function renderCabecalho() {
  document.getElementById('cabecalho').innerHTML = colunas().map(function (col) {
    const on = col.c === ordCol;
    const curto = compacto && ROTULO_CURTO[col.rot];
    return '<th class="' + col.cls + (on ? ' ord' : '') + '" data-c="' + col.c + '"'
      + (curto ? ' title="' + esc(col.rot) + '"' : '') + '>'
      + '<span class="seta">' + (on ? (ordAsc ? '▲' : '▼') : '▼') + '</span>'
      + esc(curto || col.rot) + '</th>';
  }).join('') + '<th class="col-copia"></th>';
  // O cabeçalho da coluna de copiar fica VAZIO de propósito: um rótulo ali
  // seria clicável como os outros e não ordenaria nada.
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
        // O title guarda o nome inteiro: encurtar não pode custar saber quem é.
        return '<td class="col-nome' + on + '"><a class="pl-link" href="/player.php?id=' + p.id + '"'
          + (compacto ? ' title="' + esc(p.nome) + '"' : '') + '>'
          + esc(compacto ? nomeCurto(p.nome) : p.nome) + '</a></td>';
      }
      if (col.c === 'time') return '<td class="col-time' + on + '">' + esc(p.time) + '</td>';
      if (col.c === 'pos')  return '<td class="' + on.trim() + '"><span class="pos-tag">' + (esc(p.pos) || '—') + '</span></td>';
      const v = valor(p, col.c);
      if (col.tipo === 'skill') {
        return '<td class="num' + on + '" style="' + corSkill(v) + '">' + (v === null ? '—' : esc(v)) + '</td>';
      }
      return '<td class="num' + on + '">' + fmt(v, col.dec) + '</td>';
    }).join('');
    // A coluna de copiar não entra em colunas(): não é dado, não ordena e
    // não muda com a aba. Fica no fim, onde o olho já terminou de ler a linha.
    const copiar = '<td class="col-copia">'
      + '<button type="button" class="bt-copia" data-id="' + p.id + '" title="Copiar os dados deste jogador">'
      + '<i class="bi bi-clipboard"></i></button></td>';
    return '<tr class="' + (meu ? 'meu' : '') + (vazioNaAba(p) ? ' sem-stat' : '') + '">' + tds + copiar + '</tr>';
  }).join('');

  const semLanc = linhas.filter(vazioNaAba).length;
  document.getElementById('contador').textContent =
    linhas.length + ' jogador' + (linhas.length === 1 ? '' : 'es')
    + (time && semLanc ? ' · ' + semLanc + ' sem lançamento' : '');
}

/* ══ COPIAR OS DADOS DE UM JOGADOR ════════════════════════════════════
   Uma linha da tabela é a resposta pronta pra uma pergunta que se faz no
   grupo o tempo todo ("como está o fulano?"), e até agora só dava pra
   responder tirando print ou digitando de novo.

   O texto sai da ABA ATUAL: em Números vêm as médias, em Atributos vêm as
   notas — copiar as duas sempre daria um bloco que ninguém lê inteiro. O
   link do jogador vai junto porque quem recebe costuma querer o resto. */
function textoDoJogador(p) {
  const linhas = [];
  linhas.push(p.nome + (p.time ? ' · ' + p.time : ''));

  const ficha = [];
  if (p.pos)   ficha.push(p.pos);
  if (p.ovr !== null && p.ovr !== undefined) ficha.push(p.ovr + ' OVR');
  if (p.idade !== null && p.idade !== undefined) ficha.push(p.idade + ' anos');
  if (ficha.length) linhas.push(ficha.join(' · '));

  if (aba === 'stats') {
    if (p.jogos === null) {
      linhas.push('Sem estatística lançada.');
    } else {
      // Jogos primeiro, e com a mesma régua do resto do sistema: média sem
      // saber em quantos jogos não diz nada.
      linhas.push(
        [p.jogos + ' J', fmt(p.min, 1) + ' MIN', fmt(p.pts, 1) + ' PTS',
         fmt(p.reb, 1) + ' REB', fmt(p.ast, 1) + ' AST',
         fmt(p.rou, 1) + ' ROU', fmt(p.toc, 1) + ' TOC'].join(' · ')
      );
    }
  } else {
    const notas = COLS.skills
      .map(function (col) {
        const v = valor(p, col.c);
        return v === null ? null : col.rot + ' ' + v;
      })
      .filter(Boolean);
    linhas.push(notas.length ? notas.join(' · ') : 'Sem atributo preenchido.');
  }

  linhas.push(location.origin + '/player.php?id=' + p.id);
  return linhas.join('\n');
}

/* Copiar em si. O navigator.clipboard só existe em contexto seguro, e o site
   é aberto por http de vez em quando — aí o caminho velho ainda funciona. */
async function paraAreaDeTransferencia(txt) {
  try {
    await navigator.clipboard.writeText(txt);
    return true;
  } catch (e) {
    try {
      const ta = document.createElement('textarea');
      ta.value = txt;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      const ok = document.execCommand('copy');
      ta.remove();
      return ok;
    } catch (e2) { return false; }
  }
}

async function copiarJogador(bt) {
  const id = Number(bt.dataset.id);
  const p  = DADOS.find(function (x) { return x.id === id; });
  if (!p) return;

  const ok = await paraAreaDeTransferencia(textoDoJogador(p));
  bt.innerHTML = ok ? '<i class="bi bi-check2"></i>' : '<i class="bi bi-x"></i>';
  bt.classList.toggle('ok', ok);
  setTimeout(function () {
    bt.innerHTML = '<i class="bi bi-clipboard"></i>';
    bt.classList.remove('ok');
  }, 1500);
}

/* ══ COPIAR A TABELA INTEIRA ══════════════════════════════════════════
   Separado por TAB e com cabeçalho: cola direto em planilha, cada valor na
   sua célula. O botão de linha é outro caso — ali é uma resposta pra mandar
   no grupo, e vai em texto corrido.

   Leva os jogadores que ESTÃO NA TELA, na ordem da tela: quem filtrou por
   time ou posição quer aquele recorte, e ignorar o filtro devolveria a liga
   inteira sem aviso. Sem filtro nenhum, é a tabela toda mesmo.

   Cada botão copia a SUA categoria, não a aba aberta — foi assim que o
   pedido veio ("copiar stats e copiar skills"), e evita a pessoa copiar
   atributo achando que levou estatística. */
async function copiarTudo(qual, bt) {
  const linhas = ordenar(filtrar(qual));
  const rotulo = bt.innerHTML;

  const avisar = function (txt, classe) {
    bt.innerHTML = txt;
    bt.classList.toggle('ok', classe === 'ok');
    setTimeout(function () { bt.innerHTML = rotulo; bt.classList.remove('ok'); }, 2200);
  };

  if (!linhas.length) { avisar('<i class="bi bi-x"></i> Nada na tela', 'erro'); return; }

  const cols = COLS[qual];
  const cab  = ['Jogador', 'Time', 'Pos', 'OVR', 'Idade']
                 .concat(cols.map(function (c) { return c.rot; }));

  const corpo = linhas.map(function (p) {
    const base = [p.nome, p.time || '', p.pos || '',
                  p.ovr   === null || p.ovr   === undefined ? '' : p.ovr,
                  p.idade === null || p.idade === undefined ? '' : p.idade];
    return base.concat(cols.map(function (c) {
      const v = valor(p, c.c);
      // Célula vazia, e não "-": num campo numérico de planilha o traço vira
      // texto e estraga a soma da coluna inteira.
      if (v === null || v === undefined) return '';
      return c.dec ? fmt(v, c.dec) : v;
    })).join('\t');
  });

  const ok = await paraAreaDeTransferencia([cab.join('\t')].concat(corpo).join('\n'));
  avisar(ok ? '<i class="bi bi-check2"></i> ' + linhas.length + ' copiados'
            : '<i class="bi bi-x"></i> Não deu', ok ? 'ok' : 'erro');
}

// Delegado no corpo da tabela: as linhas são redesenhadas a cada filtro e
// a cada ordenação, e ouvinte preso em cada botão morreria junto.
document.getElementById('corpo').addEventListener('click', function (e) {
  const bt = e.target.closest('.bt-copia');
  if (bt) copiarJogador(bt);
});

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

const chipCompacto = document.getElementById('fCompacto');
function sincronizarCompacto() {
  if (chipCompacto) chipCompacto.classList.toggle('on', compacto);
  document.querySelector('.tabela-caixa').classList.toggle('compacto', compacto);
}
if (chipCompacto) {
  chipCompacto.addEventListener('click', function () {
    compacto = !compacto;
    try { localStorage.setItem('stats:compacto', compacto ? '1' : '0'); } catch (e) {}
    sincronizarCompacto();
    render();
  });
}
sincronizarCompacto();

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

/* ══ IMPORTAÇÃO EM MASSA ══════════════════════════════════════════════
   A tela já mostra quem está sem lançamento; aqui dá pra ver a lista dos
   pendentes, baixar um CSV com eles já preenchidos nas duas primeiras colunas
   (id e nome, pra não errar de jogador) e devolver o arquivo preenchido.
   Aberto pra qualquer GM, e vale pra liga inteira. */

const IMP_COLS = {
  stats:  ['jogos', 'min', 'pts', 'reb', 'ast', 'rou', 'toc'],
  skills: ['in', 'mid', '3pt', 'post_d', 'per_d', 'play', 'reb', 'athl', 'iq', 'pot'],
};
const IMP_TITULO = { stats: 'Importar estatísticas', skills: 'Importar atributos' };
let impTipo = 'stats';
let impPendentes = [];

function fecharImport() {
  document.getElementById('impFundo').classList.remove('on');
}

async function abrirImport(tipo) {
  impTipo = tipo;
  document.getElementById('impTitulo').textContent = IMP_TITULO[tipo];
  document.getElementById('impFundo').classList.add('on');
  document.getElementById('impCorpo').innerHTML =
    '<div class="imp-vazio"><i class="bi bi-hourglass-split"></i> Carregando quem falta…</div>';

  try {
    const r = await fetch('/api/stats-import.php?tipo=' + tipo, { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Erro ao carregar');
    impPendentes = d.jogadores || [];
    desenharImport(d);
  } catch (e) {
    document.getElementById('impCorpo').innerHTML =
      '<div class="imp-msg erro">' + (e.message || 'Não deu pra carregar a lista.') + '</div>';
  }
}

function desenharImport(d) {
  const cols = IMP_COLS[impTipo];
  const oQue = impTipo === 'stats'
    ? 'sem estatística lançada (ou com tudo zerado) na temporada' + (d.season_number ? ' ' + d.season_number : '')
    : 'sem nenhum atributo preenchido';

  const lista = impPendentes.length
    ? impPendentes.map(j =>
        '<div class="imp-linha"><span class="id">#' + j.id + '</span>' +
        '<span class="nome">' + esc(j.name) + '</span>' +
        '<span class="time">' + esc(j.time || '') + '</span></div>').join('')
    : '<div class="imp-vazio">Ninguém pendente — está tudo lançado.</div>';

  document.getElementById('impCorpo').innerHTML =
    '<div class="imp-info"><b>' + impPendentes.length + '</b> jogador' +
      (impPendentes.length === 1 ? '' : 'es') + ' ' + oQue + ' na ' + esc(d.league) + '.</div>' +
    '<div class="imp-lista">' + lista + '</div>' +
    '<div class="imp-info">O CSV tem uma linha por jogador, nesta ordem:<br>' +
      '<code>id,nome,' + cols.join(',') + '</code>' +
      (impTipo === 'skills'
        ? '<br>As notas vão de <code>A+</code> a <code>F</code>; <code>-</code> deixa em branco.'
        : '<br>Aceita vírgula ou ponto no decimal.') +
      '<br>Baixe o modelo, preencha e cole aqui — quem já tem lançamento também pode ser corrigido.</div>' +
    '<div class="imp-acoes">' +
      '<button type="button" class="f-chip" onclick="baixarModelo()">' +
        '<i class="bi bi-download"></i> Baixar modelo com os ' + impPendentes.length + ' pendentes</button>' +
      '<label class="f-chip" style="cursor:pointer">' +
        '<i class="bi bi-file-earmark-arrow-up"></i> Escolher arquivo' +
        '<input type="file" accept=".csv,text/csv,text/plain" style="display:none" onchange="lerArquivo(this)"></label>' +
    '</div>' +
    '<textarea class="imp-txt" id="impCsv" placeholder="Cole aqui o CSV preenchido…"></textarea>' +
    '<div id="impMsg"></div>' +
    '<div class="imp-acoes"><button type="button" class="imp-btn" onclick="enviarImport(this)">' +
      'Importar' + '</button></div>';
}

/** O modelo já vem com id e nome preenchidos: é o que impede trocar de jogador. */
function baixarModelo() {
  const cols = IMP_COLS[impTipo];
  const linhas = ['id,nome,' + cols.join(',')];
  impPendentes.forEach(j => {
    // O nome vai entre aspas porque quase todo nome tem espaço e alguns têm vírgula.
    linhas.push(j.id + ',"' + String(j.name).replace(/"/g, '""') + '"' + ','.repeat(cols.length));
  });
  // O BOM faz o Excel abrir os acentos certos — sem ele "Doncic" vira "DonÄiÄ".
  const blob = new Blob(['﻿' + linhas.join('\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'fba-' + impTipo + '-pendentes.csv';
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

function lerArquivo(input) {
  const f = input.files && input.files[0];
  if (!f) return;
  const leitor = new FileReader();
  leitor.onload = () => {
    document.getElementById('impCsv').value = String(leitor.result || '').replace(/^﻿/, '');
  };
  leitor.readAsText(f, 'UTF-8');
  input.value = '';
}

async function enviarImport(botao) {
  const csv = (document.getElementById('impCsv').value || '').trim();
  const msg = document.getElementById('impMsg');
  if (!csv) {
    msg.innerHTML = '<div class="imp-msg erro">Cole o CSV ou escolha um arquivo antes.</div>';
    return;
  }
  botao.disabled = true;
  botao.textContent = 'Importando…';
  try {
    const r = await fetch('/api/stats-import.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'importar', tipo: impTipo, csv }),
    });
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Erro na importação');

    const recusadas = d.recusados || [];
    msg.innerHTML =
      '<div class="imp-msg ok"><b>' + d.gravados + '</b> jogador' +
      (d.gravados === 1 ? '' : 'es') + ' atualizado' + (d.gravados === 1 ? '' : 's') + '.' +
      (recusadas.length
        ? '</div><div class="imp-msg erro" style="margin-top:8px">' + recusadas.length +
          ' linha' + (recusadas.length === 1 ? '' : 's') + ' de fora:<br>' +
          recusadas.slice(0, 12).map(x => 'linha ' + x.linha + ' — ' + esc(x.motivo)).join('<br>') +
          (recusadas.length > 12 ? '<br>…e mais ' + (recusadas.length - 12) + '.' : '') + '</div>'
        : '</div>') +
      (d.gravados ? '<div class="imp-info" style="margin-top:8px">Recarregue a página pra ver na tabela.</div>' : '');
  } catch (e) {
    msg.innerHTML = '<div class="imp-msg erro">' + esc(e.message || 'Falhou.') + '</div>';
  }
  botao.disabled = false;
  botao.textContent = 'Importar';
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') fecharImport();
});

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
