<?php
/**
 * Observar liga — o painel do modo observador.
 *
 * A troca em si acontece em qualquer página (é só `?obs=LIGA`, veja
 * backend/observador.php). Esta tela existe pra dar o ponto de partida: mostra
 * onde cada liga está, deixa escolher o time a inspecionar e lista os atalhos
 * das páginas que costumam esconder bug — as de liga, que mudam de verdade
 * quando o óculos muda.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/observador.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

// Só admin geral — admin de liga já vê a própria liga inteira, "ver outra
// liga" não é dele.
if (!hasGlobalAdminAccess($pdo, (int)$user['id'])) {
    header('Location: /dashboard.php');
    exit;
}

$ligaObs  = observadorLiga();
$ligaReal = strtoupper((string)($_SESSION['obs_liga_real'] ?? $user['league'] ?? ''));

// O time do admin serve pra dizer, na tela, de onde ele veio.
$stmtT = $pdo->prepare('SELECT id, city, name, league FROM teams WHERE user_id = ? LIMIT 1');
$stmtT->execute([(int)$user['id']]);
$meuTime = $stmtT->fetch(PDO::FETCH_ASSOC) ?: null;

/** Onde cada liga está: times, temporada e o ano que vale pras picks. */
function panoramaDaLiga(PDO $pdo, string $liga): array
{
    $n = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) {
        try { $st = $pdo->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    })();

    $temporada = '—';
    try {
        $st = $pdo->prepare('SELECT s.season_number, s.status, sp.start_year
            FROM seasons s LEFT JOIN sprints sp ON s.sprint_id = sp.id
            WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ("completed"))
            ORDER BY s.created_at DESC LIMIT 1');
        $st->execute([$liga]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $ano = isset($r['start_year'], $r['season_number'])
                ? (int)$r['start_year'] + (int)$r['season_number'] - 1 : null;
            $temporada = 'T' . (int)$r['season_number'] . ($ano ? ' · ' . $ano : '')
                . ' · ' . ($r['status'] ?: 'regular');
        }
    } catch (Throwable $e) { /* a linha vale sem a temporada */ }

    return [
        'times'     => $n('SELECT COUNT(*) FROM teams WHERE league = ?', [$liga]),
        'jogadores' => $n('SELECT COUNT(*) FROM players p JOIN teams t ON t.id = p.team_id WHERE t.league = ?', [$liga]),
        'picks'     => $n('SELECT COUNT(*) FROM picks pk JOIN teams t ON t.id = pk.team_id WHERE t.league = ?', [$liga]),
        'temporada' => $temporada,
        'anoPicks'  => anoDeCorteDasPicks($pdo, $liga),
    ];
}

$panorama = [];
foreach (OBS_LIGAS as $l) $panorama[$l] = panoramaDaLiga($pdo, $l);

$timesDaLiga = [];
if ($ligaObs) {
    $st = $pdo->prepare("SELECT id, CONCAT(city,' ',name) AS nome FROM teams WHERE league = ? ORDER BY city, name");
    $st->execute([$ligaObs]);
    $timesDaLiga = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$timeEscolhido = observadorTimeId();

// As telas que mais valem inspecionar: as que mudam de verdade com a liga.
$atalhos = [
    ['dashboard.php',        'bi-speedometer2',      'Dashboard'],
    ['teams.php',            'bi-people-fill',       'Times'],
    ['trade-simulator.php',  'bi-arrow-left-right',  'Trade Machine'],
    ['picks.php',            'bi-calendar-event',    'Picks'],
    ['statsjogadores.php',   'bi-bar-chart-line',    'Stats e Skills'],
    ['estatisticas.php',     'bi-graph-up',          'Estatísticas'],
    ['rankings.php',         'bi-trophy',            'Ranking'],
    ['free-agency.php',      'bi-person-plus',       'Free Agency'],
    ['leilao.php',           'bi-hammer',            'Leilão'],
    ['mundo-fba.php',        'bi-globe2',            'Mundo FBA'],
    ['hall-da-fama.php',     'bi-award',             'Hall da Fama'],
    ['history.php',          'bi-clock-history',     'História'],
];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<title>Observar liga - FBA Manager</title>
<meta name="theme-color" content="#7c3aed">
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
<?php include __DIR__ . '/includes/sidebar-css.php'; ?>

.obs-hero{padding:22px 28px 16px}
.obs-eyebrow{font-size:10px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;
  color:#a78bfa;margin-bottom:6px}
.obs-titulo{font-size:26px;font-weight:900;letter-spacing:-.6px;display:flex;align-items:center;gap:10px}
.obs-titulo i{color:#a78bfa}
.obs-sub{color:var(--text-2);font-size:14px;margin-top:5px;max-width:620px;line-height:1.55}
.obs-corpo{padding:0 28px 60px;display:flex;flex-direction:column;gap:18px}

.obs-grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}
.obs-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:15px 16px;
  display:flex;flex-direction:column;gap:9px;text-decoration:none;color:var(--text);transition:.15s}
.obs-card:hover{border-color:#a78bfa;transform:translateY(-1px)}
.obs-card.on{border-color:#a78bfa;box-shadow:0 0 0 1px #a78bfa inset;background:rgba(124,58,237,.07)}
.obs-card-topo{display:flex;align-items:center;justify-content:space-between;gap:8px}
.obs-card-liga{font-size:16px;font-weight:900;letter-spacing:-.3px}
.obs-card-selo{font-size:9.5px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;
  border-radius:999px;padding:3px 9px;background:rgba(124,58,237,.16);color:#a78bfa}
.obs-card-temp{font-size:11.5px;color:var(--text-3)}
.obs-nums{display:flex;gap:14px;flex-wrap:wrap}
.obs-num{font-size:11px;color:var(--text-3)}
.obs-num b{display:block;font-size:16px;font-weight:800;color:var(--text);
  font-variant-numeric:tabular-nums;letter-spacing:-.4px}
.obs-num.alerta b{color:#ef4444}

.obs-bloco{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:16px 18px}
.obs-bloco h2{font-size:11px;font-weight:800;letter-spacing:1px;text-transform:uppercase;
  color:var(--text-2);margin:0 0 12px}
.obs-atalhos{display:grid;grid-template-columns:repeat(auto-fill,minmax(168px,1fr));gap:8px}
.obs-atalho{display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:10px;
  background:var(--panel-2);border:1px solid var(--border);color:var(--text);
  text-decoration:none;font-size:13px;font-weight:600;transition:.15s}
.obs-atalho:hover{border-color:#a78bfa;color:#a78bfa}
.obs-atalho i{color:var(--text-3);font-size:14px}
.obs-atalho:hover i{color:#a78bfa}
.obs-linha{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.obs-sel{background:var(--panel-2);border:1px solid var(--border-md);border-radius:9px;padding:9px 11px;
  color:var(--text);font-family:var(--font);font-size:13px;outline:none;min-width:220px;max-width:100%}
.obs-sel:focus{border-color:#a78bfa}
.obs-dica{font-size:12px;color:var(--text-3);line-height:1.55}
.obs-btn{background:#7c3aed;border:0;border-radius:10px;padding:10px 18px;color:#fff;
  font-family:var(--font);font-size:13px;font-weight:800;cursor:pointer;text-decoration:none;
  display:inline-flex;align-items:center;gap:7px}
.obs-btn.cinza{background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2)}
@media (max-width:640px){
  .obs-hero{padding:18px 14px 12px}
  .obs-corpo{padding:0 14px 60px}
  .obs-titulo{font-size:21px}
}
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
  <div class="obs-hero">
    <div class="obs-eyebrow">Ferramenta de admin</div>
    <h1 class="obs-titulo"><i class="bi bi-eye-fill"></i> Observar liga</h1>
    <p class="obs-sub">Abre o site inteiro pelos olhos de outra liga, sem ter time nela e sem
      ocupar vaga de ninguém. Você continua sendo você — o que fizer sai no seu nome. É só
      visualização de contexto, pra achar o que está quebrado onde você não joga.</p>
  </div>

  <div class="obs-corpo">
    <div class="obs-grade">
      <?php foreach (OBS_LIGAS as $l): $p = $panorama[$l]; $ativa = ($l === $ligaObs); ?>
      <a class="obs-card<?= $ativa ? ' on' : '' ?>" href="?obs=<?= $l ?>">
        <div class="obs-card-topo">
          <span class="obs-card-liga"><?= $l ?></span>
          <?php if ($ativa): ?><span class="obs-card-selo">Vendo</span>
          <?php elseif ($l === $ligaReal): ?><span class="obs-card-selo">Sua liga</span><?php endif; ?>
        </div>
        <div class="obs-card-temp"><?= $h($p['temporada']) ?></div>
        <div class="obs-nums">
          <span class="obs-num"><b><?= $p['times'] ?></b>times</span>
          <span class="obs-num"><b><?= $p['jogadores'] ?></b>jogadores</span>
          <span class="obs-num<?= $p['picks'] === 0 ? ' alerta' : '' ?>"><b><?= $p['picks'] ?></b>picks</span>
          <span class="obs-num"><b><?= $p['anoPicks'] ?></b>ano das picks</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <?php if ($ligaObs): ?>
    <div class="obs-bloco">
      <h2>Time que as telas de GM vão mostrar</h2>
      <div class="obs-linha">
        <select class="obs-sel" onchange="location.href='?obs_time='+this.value">
          <option value="0">Primeiro time da liga (automático)</option>
          <?php foreach ($timesDaLiga as $t): ?>
          <option value="<?= (int)$t['id'] ?>"<?= ((int)$t['id'] === (int)$timeEscolhido) ? ' selected' : '' ?>>
            <?= $h($t['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="obs-dica">Dashboard, elenco, CAP e picks precisam de um time pra ter o que mostrar.
          As telas de liga — ranking, estatísticas, trades — não dependem disso.</span>
      </div>
    </div>

    <div class="obs-bloco">
      <h2>Ir direto pra uma tela da <?= $h($ligaObs) ?></h2>
      <div class="obs-atalhos">
        <?php foreach ($atalhos as [$arquivo, $icone, $rotulo]): ?>
        <a class="obs-atalho" href="/<?= $h($arquivo) ?>"><i class="bi <?= $h($icone) ?>"></i><?= $h($rotulo) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="obs-linha">
      <a class="obs-btn cinza" href="?obs=off"><i class="bi bi-box-arrow-left"></i>
        Sair do modo<?= $ligaReal ? ' e voltar pra ' . $h($ligaReal) : '' ?></a>
    </div>
    <?php else: ?>
    <div class="obs-bloco">
      <p class="obs-dica" style="margin:0">Escolha uma liga acima. A barra roxa fica no topo de todas as
        páginas enquanto o modo estiver ligado, com as quatro ligas à mão — dá pra trocar sem sair de
        onde você está, que é o ponto: ver a MESMA tela em cada liga.
        <?php if ($meuTime): ?><br>Hoje você é GM do <b><?= $h(trim($meuTime['city'] . ' ' . $meuTime['name'])) ?></b>
        (<?= $h($meuTime['league']) ?>), e isso não muda.<?php endif; ?></p>
    </div>
    <?php endif; ?>
  </div>
</div>
</div>

<script>
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
