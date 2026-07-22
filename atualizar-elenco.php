<?php
/**
 * Atualização do elenco — skills, OVR/idade e estatísticas da temporada.
 *
 * Nas ligas com leitura por foto liberada o GM manda a captura do jogo e
 * revisa antes de gravar; em qualquer liga dá para editar tudo na mão.
 * Ao confirmar, o estado da temporada é congelado em player_season_log,
 * que alimenta a comparação por temporada no perfil do jogador.
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

$teamId = (int)$team['id'];
$league = strtoupper((string)$team['league']);

// Precisa bater com VISION_LEAGUES / STATS_LEAGUES nas APIs.
$podeFoto = in_array($league, ['ELITE', 'NEXT', 'RISE'], true);

$stmtSeason = $pdo->prepare("SELECT id, season_number, year FROM seasons
                             WHERE league = ? AND (status IS NULL OR status <> 'completed')
                             ORDER BY id DESC LIMIT 1");
$stmtSeason->execute([$league]);
$season = $stmtSeason->fetch(PDO::FETCH_ASSOC);

$stmtP = $pdo->prepare("SELECT id, name, position, secondary_position, ovr, age, role,
                               skill_in, skill_mid, skill_3pt, skill_post_d, skill_per_d,
                               skill_play, skill_reb, skill_athl, skill_iq, skill_pot
                        FROM players WHERE team_id = ? ORDER BY ovr DESC, name");
$stmtP->execute([$teamId]);
$jogadores = $stmtP->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas já gravadas nesta temporada, para pré-preencher a aba
$statsAtuais = [];
if ($season) {
    $stmtS = $pdo->prepare("SELECT player_id, games, min_pg, pts_pg, reb_pg, ast_pg, stl_pg, blk_pg
                            FROM player_season_stats WHERE season_id = ? AND team_id = ?");
    $stmtS->execute([(int)$season['id'], $teamId]);
    foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $r) $statsAtuais[(int)$r['player_id']] = $r;
}

$SKILL_KEYS = [
    'skill_in' => 'IN', 'skill_mid' => 'MID', 'skill_3pt' => '3PT', 'skill_post_d' => 'POST D',
    'skill_per_d' => 'PER D', 'skill_play' => 'PLAY', 'skill_reb' => 'REB',
    'skill_athl' => 'ATHL', 'skill_iq' => 'IQ', 'skill_pot' => 'POT',
];
$NOTAS = ['', 'A+','A','A-','B+','B','B-','C+','C','C-','D+','D','D-','F'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<title>Atualizar Elenco · FBA Manager</title>
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
.content{max-width:1180px;margin:0 auto;padding:26px 22px 80px}

.page-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
.page-title{font-family:'Oswald',sans-serif;font-size:25px;font-weight:700;display:flex;align-items:center;gap:10px}
.page-title i{color:var(--red)}
.page-sub{font-size:13px;color:var(--text-2);margin-top:5px}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px}
.section-title{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);margin-bottom:12px;display:flex;align-items:center;gap:8px}
.section-title i{color:var(--red)}

.tabs{display:flex;gap:6px;margin:18px 0 14px;flex-wrap:wrap}
.tab-btn{padding:9px 16px;border-radius:999px;background:var(--panel);border:1px solid var(--border);color:var(--text-2);font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease)}
.tab-btn:hover{border-color:var(--border-md);color:var(--text)}
.tab-btn.active{background:var(--red);border-color:var(--red);color:#fff}
.pane{display:none}
.pane.active{display:block}

.tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.tbl th{padding:9px 8px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-3);border-bottom:1px solid var(--border);background:var(--panel-2);white-space:nowrap}
.tbl td{padding:7px 8px;border-bottom:1px solid var(--border);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl .nm{font-weight:600;white-space:nowrap;max-width:170px;overflow:hidden;text-overflow:ellipsis}
.inp{width:100%;min-width:52px;background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:8px;padding:5px 7px;font-family:inherit;font-size:12.5px;text-align:center}
.inp:focus{outline:none;border-color:var(--red)}
.sel{background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:8px;padding:5px 4px;font-family:inherit;font-size:12px;width:100%;min-width:58px}
.sel:focus{outline:none;border-color:var(--red)}

.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:10px;background:var(--red);border:1px solid var(--red);color:#fff;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:filter var(--t) var(--ease);text-decoration:none}
.btn:hover:not(:disabled){filter:brightness(1.1)}
.btn:disabled{opacity:.6;cursor:not-allowed}
.btn.ghost{background:var(--panel-2);border-color:var(--border-md);color:var(--text-2)}
.btn.ghost:hover:not(:disabled){border-color:var(--red);color:var(--red);filter:none}

.drop{border:2px dashed var(--border-md);border-radius:12px;padding:28px 20px;text-align:center;cursor:pointer;transition:border-color var(--t) var(--ease);background:var(--panel-2)}
.drop:hover{border-color:var(--red)}
.drop i{font-size:30px;color:var(--text-3);display:block;margin-bottom:8px}
.prev{max-width:100%;max-height:260px;border-radius:10px;border:1px solid var(--border-md)}
.aviso{display:flex;align-items:flex-start;gap:9px;padding:11px 14px;border-radius:10px;font-size:12.5px;line-height:1.5;margin-bottom:14px}
.aviso i{font-size:15px;flex-shrink:0;margin-top:1px}
.aviso.info{background:var(--panel-2);border:1px solid var(--border);color:var(--text-2)}
.aviso.warn{background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.28);color:var(--amber)}
.aviso.ok{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.28);color:var(--green)}
.aviso.err{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.3);color:#f87171}
.cota{font-size:11.5px;color:var(--text-3)}
.acoes{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px;padding-top:16px;border-top:1px solid var(--border)}
.scroll-x{overflow-x:auto}

@media (max-width:992px){
  :root{--sidebar-w:0px}
  .sidebar{transform:translateX(-260px)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;width:100%;padding-top:54px}
  .topbar{display:flex}
  .content{padding:16px 14px 60px}
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
  <div class="topbar-title">Atualizar Elenco</div>
  <a href="my-roster.php" class="sb-logout"><i class="bi bi-arrow-left"></i></a>
</header>

<main class="main">
 <div class="content">

  <div class="page-eyebrow">
    <?= htmlspecialchars($league) ?>
    <?php if ($season): ?> · Temporada <?= (int)$season['season_number'] ?><?php endif; ?>
  </div>
  <h1 class="page-title"><i class="bi bi-arrow-repeat"></i> Atualizar Elenco</h1>
  <p class="page-sub"><?= htmlspecialchars(trim($team['city'] . ' ' . $team['name'])) ?></p>

  <?php if (!$season): ?>
    <div class="aviso warn" style="margin-top:18px">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <div>Não há temporada em andamento na <?= htmlspecialchars($league) ?>. As alterações não podem ser
      gravadas no histórico até a temporada ser criada pelo admin.</div>
    </div>
  <?php endif; ?>

  <div class="tabs" id="tabs">
    <button class="tab-btn active" data-pane="atributos">Atributos e OVR</button>
    <button class="tab-btn" data-pane="estatisticas">Estatísticas</button>
  </div>

  <!-- ══ Atributos ══ -->
  <div class="pane active" id="pane-atributos">
    <?php if ($podeFoto): ?>
    <div class="panel">
      <div class="section-title"><i class="bi bi-camera-fill"></i> Atualizar por foto
        <span class="cota" id="cotaSkills"></span>
      </div>
      <div class="aviso info">
        <i class="bi bi-info-circle"></i>
        <div>Envie a captura da tela <strong>View List › Simple</strong> do jogo. A leitura é automática, mas
        <strong>sempre passa por revisão</strong> antes de gravar — confira os valores, porque o
        reconhecimento pode errar.</div>
      </div>
      <div id="dropSkills" class="drop">
        <i class="bi bi-cloud-arrow-up"></i>
        Clique ou arraste a imagem das skills
      </div>
      <input type="file" id="fileSkills" accept="image/*" hidden>
      <div id="prevSkillsWrap" style="display:none;text-align:center">
        <img id="prevSkills" class="prev" alt="">
        <div style="margin-top:12px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
          <button class="btn" id="btnLerSkills"><i class="bi bi-magic"></i> Ler imagem</button>
          <button class="btn ghost" id="btnLimparSkills">Trocar imagem</button>
        </div>
      </div>
      <div id="msgSkills"></div>
    </div>
    <?php else: ?>
    <div class="aviso info">
      <i class="bi bi-info-circle"></i>
      <div>A leitura por foto não está liberada para a <?= htmlspecialchars($league) ?>. A atualização aqui é manual.</div>
    </div>
    <?php endif; ?>

    <div class="panel">
      <div class="section-title"><i class="bi bi-pencil-square"></i> Elenco — edição</div>
      <div class="scroll-x">
        <table class="tbl" id="tblSkills">
          <thead>
            <tr>
              <th>Jogador</th><th style="width:62px">OVR</th><th style="width:62px">Idade</th>
              <?php foreach ($SKILL_KEYS as $lbl): ?><th style="width:72px"><?= $lbl ?></th><?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($jogadores as $p): ?>
            <tr data-pid="<?= (int)$p['id'] ?>">
              <td class="nm"><?= htmlspecialchars($p['name']) ?></td>
              <td><input class="inp" type="number" min="40" max="99" data-f="ovr" value="<?= (int)$p['ovr'] ?>"></td>
              <td><input class="inp" type="number" min="15" max="50" data-f="age" value="<?= (int)$p['age'] ?>"></td>
              <?php foreach ($SKILL_KEYS as $col => $lbl): ?>
                <td>
                  <select class="sel" data-f="<?= $col ?>">
                    <?php foreach ($NOTAS as $n): ?>
                      <option value="<?= $n ?>"<?= ((string)$p[$col] === $n) ? ' selected' : '' ?>><?= $n === '' ? '—' : $n ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="acoes">
        <button class="btn" id="btnSalvarSkills"><i class="bi bi-save2"></i> Salvar atributos</button>
        <a class="btn ghost" href="my-roster.php"><i class="bi bi-arrow-left"></i> Voltar ao elenco</a>
        <span class="cota">Ao salvar, o estado da temporada é registrado para comparação no perfil do jogador.</span>
      </div>
      <div id="msgSalvarSkills"></div>
    </div>
  </div>

  <!-- ══ Estatísticas ══ -->
  <div class="pane" id="pane-estatisticas">
    <?php if ($podeFoto): ?>
    <div class="panel">
      <div class="section-title"><i class="bi bi-camera-fill"></i> Estatísticas por foto
        <span class="cota" id="cotaStats"></span>
      </div>
      <div class="aviso info">
        <i class="bi bi-info-circle"></i>
        <div>Envie a captura da tela de estatísticas na aba <strong>Per Game</strong>. Também passa por revisão
        antes de gravar.</div>
      </div>
      <div id="dropStats" class="drop">
        <i class="bi bi-cloud-arrow-up"></i>
        Clique ou arraste a imagem das estatísticas
      </div>
      <input type="file" id="fileStats" accept="image/*" hidden>
      <div id="prevStatsWrap" style="display:none;text-align:center">
        <img id="prevStats" class="prev" alt="">
        <div style="margin-top:12px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
          <button class="btn" id="btnLerStats"><i class="bi bi-magic"></i> Ler imagem</button>
          <button class="btn ghost" id="btnLimparStats">Trocar imagem</button>
        </div>
      </div>
      <div id="msgStats"></div>
    </div>
    <?php endif; ?>

    <div class="panel">
      <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Estatísticas da temporada</div>
      <div class="scroll-x">
        <table class="tbl" id="tblStats">
          <thead>
            <tr>
              <th>Jogador</th><th style="width:66px">Jogos</th><th style="width:66px">MIN</th>
              <th style="width:66px">PTS</th><th style="width:66px">REB</th><th style="width:66px">AST</th>
              <th style="width:66px">ROU</th><th style="width:66px">TOC</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($jogadores as $p):
            $s = $statsAtuais[(int)$p['id']] ?? null; ?>
            <tr data-pid="<?= (int)$p['id'] ?>">
              <td class="nm"><?= htmlspecialchars($p['name']) ?></td>
              <td><input class="inp" type="number" min="0" max="200" step="1"   data-f="games"  value="<?= $s ? (int)$s['games'] : '' ?>"></td>
              <td><input class="inp" type="number" min="0" max="60"  step="0.1" data-f="min_pg" value="<?= $s ? rtrim(rtrim($s['min_pg'],'0'),'.') : '' ?>"></td>
              <td><input class="inp" type="number" min="0" max="99"  step="0.1" data-f="pts_pg" value="<?= $s ? rtrim(rtrim($s['pts_pg'],'0'),'.') : '' ?>"></td>
              <td><input class="inp" type="number" min="0" max="50"  step="0.1" data-f="reb_pg" value="<?= $s ? rtrim(rtrim($s['reb_pg'],'0'),'.') : '' ?>"></td>
              <td><input class="inp" type="number" min="0" max="50"  step="0.1" data-f="ast_pg" value="<?= $s ? rtrim(rtrim($s['ast_pg'],'0'),'.') : '' ?>"></td>
              <td><input class="inp" type="number" min="0" max="20"  step="0.1" data-f="stl_pg" value="<?= $s ? rtrim(rtrim($s['stl_pg'],'0'),'.') : '' ?>"></td>
              <td><input class="inp" type="number" min="0" max="20"  step="0.1" data-f="blk_pg" value="<?= $s ? rtrim(rtrim($s['blk_pg'],'0'),'.') : '' ?>"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="acoes">
        <button class="btn" id="btnSalvarStats"<?= $season ? '' : ' disabled' ?>><i class="bi bi-save2"></i> Salvar estatísticas</button>
        <a class="btn ghost" href="my-roster.php"><i class="bi bi-arrow-left"></i> Voltar ao elenco</a>
      </div>
      <div id="msgSalvarStats"></div>
    </div>
  </div>

 </div>
</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* menu mobile + tema */
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

const PODE_FOTO = <?= $podeFoto ? 'true' : 'false' ?>;
const TEM_TEMPORADA = <?= $season ? 'true' : 'false' ?>;
const ELENCO = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name']], $jogadores)) ?>;

const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

function msg(el, tipo, texto) {
  el.innerHTML = `<div class="aviso ${tipo}" style="margin-top:14px"><i class="bi bi-${
    tipo === 'ok' ? 'check-circle-fill' : tipo === 'err' ? 'x-octagon-fill' : 'info-circle'}"></i><div>${texto}</div></div>`;
}

/* ── Abas ── */
$('tabs').addEventListener('click', e => {
  const b = e.target.closest('.tab-btn');
  if (!b) return;
  document.querySelectorAll('.tab-btn').forEach(x => x.classList.toggle('active', x === b));
  document.querySelectorAll('.pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + b.dataset.pane));
});

/* ── Envio de imagem (reaproveitado pelas duas abas) ── */
function ligarUpload(cfg) {
  const drop = $(cfg.drop), file = $(cfg.file), wrap = $(cfg.wrap), img = $(cfg.img);
  if (!drop) return;
  drop.addEventListener('click', () => file.click());
  drop.addEventListener('dragover', e => { e.preventDefault(); drop.style.borderColor = 'var(--red)'; });
  drop.addEventListener('dragleave', () => { drop.style.borderColor = ''; });
  drop.addEventListener('drop', e => { e.preventDefault(); drop.style.borderColor = '';
    if (e.dataTransfer.files[0]) carregar(e.dataTransfer.files[0]); });
  file.addEventListener('change', () => { if (file.files[0]) carregar(file.files[0]); });
  $(cfg.limpar).addEventListener('click', () => { wrap.style.display = 'none'; drop.style.display = ''; file.value = ''; });
  function carregar(f) {
    const fr = new FileReader();
    fr.onload = e => { img.src = e.target.result; wrap.style.display = ''; drop.style.display = 'none'; };
    fr.readAsDataURL(f);
  }
}

async function lerImagem(endpoint, imgEl, btn, msgEl) {
  if (!imgEl.src) return null;
  btn.disabled = true;
  const label = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Lendo…';
  msgEl.innerHTML = '';
  try {
    const res = await fetch(endpoint, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ image: imgEl.src })
    });
    const d = await res.json();
    if (!res.ok) { msg(msgEl, 'err', esc(d.error || 'Erro ao ler a imagem.')); return null; }
    return d;
  } catch (e) {
    msg(msgEl, 'err', 'Erro ao ler a imagem.');
    return null;
  } finally {
    btn.disabled = false; btn.innerHTML = label;
  }
}

/* ── Skills por foto ── */
if (PODE_FOTO) {
  ligarUpload({ drop:'dropSkills', file:'fileSkills', wrap:'prevSkillsWrap', img:'prevSkills', limpar:'btnLimparSkills' });
  ligarUpload({ drop:'dropStats',  file:'fileStats',  wrap:'prevStatsWrap',  img:'prevStats',  limpar:'btnLimparStats' });

  $('btnLerSkills').addEventListener('click', async () => {
    const d = await lerImagem('/api/vision_skills.php', $('prevSkills'), $('btnLerSkills'), $('msgSkills'));
    if (!d) return;
    if (d.used != null) $('cotaSkills').textContent = `· ${d.used}/${d.limit} leituras nesta temporada`;
    const det = (d.detected || []).filter(x => x.player_id);
    if (!det.length) { msg($('msgSkills'), 'err', 'Nenhum jogador do seu elenco foi reconhecido na imagem.'); return; }
    let aplicados = 0;
    det.forEach(x => {
      const tr = document.querySelector(`#tblSkills tbody tr[data-pid="${x.player_id}"]`);
      if (!tr) return;
      aplicados++;
      Object.entries(x.grades || {}).forEach(([k, v]) => {
        const mapa = { in:'skill_in', mid:'skill_mid', pt3:'skill_3pt', post_d:'skill_post_d',
                       per_d:'skill_per_d', play:'skill_play', reb:'skill_reb', athl:'skill_athl',
                       iq:'skill_iq', pot:'skill_pot' };
        const sel = tr.querySelector(`[data-f="${mapa[k]}"]`);
        if (sel && v) { sel.value = v; sel.style.borderColor = 'var(--amber)'; }
      });
      if (x.rating) { const i = tr.querySelector('[data-f="ovr"]'); i.value = x.rating; i.style.borderColor = 'var(--amber)'; }
      if (x.age)    { const i = tr.querySelector('[data-f="age"]'); i.value = x.age;    i.style.borderColor = 'var(--amber)'; }
    });
    msg($('msgSkills'), 'warn',
      `<strong>${aplicados} jogador(es) preenchidos na tabela abaixo</strong>, destacados em amarelo.
       Confira antes de salvar — o reconhecimento erra, principalmente em OVR e idade.
       Nada foi gravado ainda.`);
  });

  $('btnLerStats').addEventListener('click', async () => {
    const d = await lerImagem('/api/vision_stats.php', $('prevStats'), $('btnLerStats'), $('msgStats'));
    if (!d) return;
    if (d.used != null) $('cotaStats').textContent = `· ${d.used}/${d.limit} leituras nesta temporada`;
    const det = (d.detected || []).filter(x => x.player_id);
    if (!det.length) { msg($('msgStats'), 'err', 'Nenhum jogador do seu elenco foi reconhecido na imagem.'); return; }
    let aplicados = 0;
    det.forEach(x => {
      const tr = document.querySelector(`#tblStats tbody tr[data-pid="${x.player_id}"]`);
      if (!tr) return;
      aplicados++;
      ['games','min_pg','pts_pg','reb_pg','ast_pg','stl_pg','blk_pg'].forEach(f => {
        const i = tr.querySelector(`[data-f="${f}"]`);
        if (i && x[f] != null) { i.value = x[f]; i.style.borderColor = 'var(--amber)'; }
      });
    });
    msg($('msgStats'), 'warn',
      `<strong>${aplicados} jogador(es) preenchidos na tabela abaixo</strong>, destacados em amarelo.
       Confira antes de salvar. Nada foi gravado ainda.`);
  });
}

/* ── Salvar atributos ── */
$('btnSalvarSkills').addEventListener('click', async () => {
  const btn = $('btnSalvarSkills'), out = $('msgSalvarSkills');
  const linhas = [...document.querySelectorAll('#tblSkills tbody tr')];
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Salvando…';
  out.innerHTML = '';

  let erros = 0, ok = 0;
  for (const tr of linhas) {
    const pid = parseInt(tr.dataset.pid, 10);
    const grades = {};
    tr.querySelectorAll('select[data-f]').forEach(s => { grades[s.dataset.f] = s.value; });
    const ovr = parseInt(tr.querySelector('[data-f="ovr"]').value, 10);
    const age = parseInt(tr.querySelector('[data-f="age"]').value, 10);
    const payload = { id: pid, skill_grades: {
      in: grades.skill_in, mid: grades.skill_mid, pt3: grades.skill_3pt,
      post_d: grades.skill_post_d, per_d: grades.skill_per_d, play: grades.skill_play,
      reb: grades.skill_reb, athl: grades.skill_athl, iq: grades.skill_iq, pot: grades.skill_pot
    }};
    if (!isNaN(ovr)) payload.ovr = ovr;
    if (!isNaN(age)) payload.age = age;
    try {
      const r = await fetch('/api/players.php', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      if (r.ok) ok++; else erros++;
    } catch (e) { erros++; }
  }

  // Congela o estado da temporada para a comparação no perfil do jogador.
  let snap = null;
  if (TEM_TEMPORADA) {
    try {
      const r = await fetch('/api/player_stats.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_snapshot' }) });
      snap = await r.json();
    } catch (e) { /* o salvamento principal ja aconteceu */ }
  }

  btn.disabled = false; btn.innerHTML = '<i class="bi bi-save2"></i> Salvar atributos';
  document.querySelectorAll('#tblSkills [style*="amber"]').forEach(el => { el.style.borderColor = ''; });

  if (erros) msg(out, 'err', `${ok} salvos, <strong>${erros} falharam</strong>. Tente novamente.`);
  else msg(out, 'ok', `${ok} jogador(es) atualizados.` +
    (snap && snap.success ? ` Histórico da temporada ${snap.season_number} registrado.` : ''));
});

/* ── Salvar estatísticas ── */
$('btnSalvarStats').addEventListener('click', async () => {
  const btn = $('btnSalvarStats'), out = $('msgSalvarStats');
  const stats = [];
  document.querySelectorAll('#tblStats tbody tr').forEach(tr => {
    const pid = parseInt(tr.dataset.pid, 10);
    const v = f => { const el = tr.querySelector(`[data-f="${f}"]`); const n = parseFloat(el.value); return isNaN(n) ? null : n; };
    const games = v('games');
    // Linha sem jogos e sem pontos e considerada nao preenchida
    if (!games && !v('pts_pg')) return;
    stats.push({ player_id: pid, games: games || 0, min_pg: v('min_pg') || 0, pts_pg: v('pts_pg') || 0,
                 reb_pg: v('reb_pg') || 0, ast_pg: v('ast_pg') || 0, stl_pg: v('stl_pg') || 0,
                 blk_pg: v('blk_pg') || 0, source: 'manual' });
  });

  if (!stats.length) { msg(out, 'err', 'Preencha ao menos um jogador (jogos ou pontos).'); return; }

  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Salvando…';
  out.innerHTML = '';
  try {
    const r = await fetch('/api/player_stats.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save_stats', stats }) });
    const d = await r.json();
    if (!r.ok || !d.success) msg(out, 'err', esc(d.error || 'Erro ao salvar.'));
    else {
      msg(out, 'ok', `${d.saved} jogador(es) com estatísticas da temporada ${d.season_number} salvas.`);
      document.querySelectorAll('#tblStats [style*="amber"]').forEach(el => { el.style.borderColor = ''; });
    }
  } catch (e) { msg(out, 'err', 'Erro ao salvar.'); }
  finally { btn.disabled = false; btn.innerHTML = '<i class="bi bi-save2"></i> Salvar estatísticas'; }
});
</script>
</body>
</html>
