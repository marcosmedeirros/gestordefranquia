<?php
/**
 * Página do jogador — perfil completo em abas.
 * Funciona para jogador ATIVO (existe em players) e APOSENTADO
 * (só existe em player_season_log). Somente leitura.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/salary_cap.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

$playerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$playerId) { header('Location: players.php'); exit; }

$isAdmin    = hasAdminAccess($pdo, (int)$user['id']);
$userLeague = $user['league'] ?? '';

// Time do usuário (cartão do menu lateral)
$stmtMine = $pdo->prepare("SELECT id, city, name, league, photo_url FROM teams WHERE user_id = ? LIMIT 1");
$stmtMine->execute([$user['id']]);
$team = $stmtMine->fetch(PDO::FETCH_ASSOC) ?: null;

// ── Jogador ativo ──────────────────────────────────────
$stmtP = $pdo->prepare("
    SELECT p.*, t.id AS t_id, t.city AS t_city, t.name AS t_name, t.league AS t_league,
           t.photo_url AS t_photo, t.conference AS t_conf
    FROM players p JOIN teams t ON t.id = p.team_id
    WHERE p.id = ? LIMIT 1
");
$stmtP->execute([$playerId]);
$P = $stmtP->fetch(PDO::FETCH_ASSOC);

$isRetired = false;
$league    = '';
$playerName = '';

if ($P) {
    $league = $P['t_league'];
    $playerName = $P['name'];
} else {
    // ── Aposentado: último registro do histórico ────────
    $stmtR = $pdo->prepare("
        SELECT psl.* FROM player_season_log psl
        INNER JOIN (SELECT player_id, MAX(year) AS ly FROM player_season_log WHERE player_id = ? GROUP BY player_id) m
              ON m.player_id = psl.player_id AND m.ly = psl.year
        WHERE psl.player_id = ? LIMIT 1
    ");
    $stmtR->execute([$playerId, $playerId]);
    $R = $stmtR->fetch(PDO::FETCH_ASSOC);
    if (!$R) { header('Location: players.php'); exit; }
    $isRetired = true;
    $league = $R['league'];
    $playerName = $R['player_name'];
}

// Escopo de liga (mesma regra da busca)
if (!$isAdmin && $league !== $userLeague) {
    header('Location: players.php'); exit;
}

// ── Carreira (histórico por temporada) ─────────────────
$career = [];
try {
    $stmtC = $pdo->prepare("SELECT year, season_number, team_name, team_id, ovr, age, position
                            FROM player_season_log WHERE player_id = ? ORDER BY year ASC, season_number ASC");
    $stmtC->execute([$playerId]);
    $career = $stmtC->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Contrato (ELITE ativo) ─────────────────────────────
$salary = null; $capMode = 'ovr_sum';
if (!$isRetired) {
    try {
        $sm = $pdo->prepare("SELECT cap_mode FROM league_settings WHERE league = ?");
        $sm->execute([$league]);
        $capMode = $sm->fetchColumn() ?: 'ovr_sum';
        if ($capMode === 'salary') {
            $sum = getTeamCapSummary($pdo, (int)$P['t_id']);
            foreach ($sum['roster'] as $rp) {
                if ((int)$rp['id'] === $playerId) { $salary = $rp; break; }
            }
        }
    } catch (Exception $e) {}
}

// ── Prêmios ────────────────────────────────────────────
$awards = [];
try {
    $stmtA = $pdo->prepare("
        SELECT sa.award_type, s.season_number, s.year, CONCAT(t.city,' ',t.name) AS team_name
        FROM season_awards sa
        JOIN seasons s ON s.id = sa.season_id
        LEFT JOIN teams t ON t.id = sa.team_id
        WHERE sa.player_name = ? AND s.league = ?
        ORDER BY s.season_number DESC
    ");
    $stmtA->execute([$playerName, $league]);
    $awards = $stmtA->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Trades em que entrou ───────────────────────────────
$trades = [];
try {
    $stmtT = $pdo->prepare("
        SELECT tr.id, tr.season_year, tr.cycle, tr.status, tr.resolved_at, tr.created_at,
               CONCAT(f.city,' ',f.name) AS from_team, CONCAT(o.city,' ',o.name) AS to_team
        FROM trade_items ti
        JOIN trades tr ON tr.id = ti.trade_id
        LEFT JOIN teams f ON f.id = tr.from_team_id
        LEFT JOIN teams o ON o.id = tr.to_team_id
        WHERE ti.player_id = ? AND tr.status = 'accepted'
        ORDER BY tr.resolved_at DESC, tr.id DESC
    ");
    $stmtT->execute([$playerId]);
    $trades = $stmtT->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Atributos (skills) ─────────────────────────────────
// Agrupados por area para a aba nao virar uma lista solta de 10 barras.
$skillGroups = [
    'Ataque'  => ['bi-bullseye', [
        'skill_in'  => 'Finalização', 'skill_mid' => 'Meia-distância', 'skill_3pt' => 'Três pontos',
        'skill_play' => 'Armação',
    ]],
    'Defesa'  => ['bi-shield-fill', [
        'skill_post_d' => 'Defesa de poste', 'skill_per_d' => 'Defesa de perímetro', 'skill_reb' => 'Rebote',
    ]],
    'Físico e mental' => ['bi-lightning-charge-fill', [
        'skill_athl' => 'Atletismo', 'skill_iq' => 'QI de jogo', 'skill_pot' => 'Potencial',
    ]],
];

$skills = [];               // continua plano: usado para saber se a aba aparece
$skillsByGroup = [];
if (!$isRetired) {
    foreach ($skillGroups as $groupName => [$groupIcon, $cols]) {
        foreach ($cols as $col => $label) {
            if (isset($P[$col]) && $P[$col] !== null && $P[$col] !== '') {
                $skills[$label] = $P[$col];
                $skillsByGroup[$groupName]['icon'] = $groupIcon;
                $skillsByGroup[$groupName]['items'][$label] = $P[$col];
            }
        }
    }
}

// Cor por faixa de valor, na mesma leitura do OVR usada no resto do site.
function skillTone(int $v): string {
    if ($v >= 90) return 'elite';
    if ($v >= 80) return 'otimo';
    if ($v >= 70) return 'bom';
    if ($v >= 60) return 'medio';
    return 'fraco';
}

$AWARD_LABELS = ['mvp'=>'MVP','dpoy'=>'DPOY','mip'=>'MIP','6th_man'=>'6º Homem','roy'=>'ROY'];

// Dados de exibição
$dispPos  = $isRetired ? ($R['position'] ?? '') : ($P['position'] ?? '');
$dispOvr  = $isRetired ? (int)($R['ovr'] ?? 0) : (int)($P['ovr'] ?? 0);
$dispAge  = $isRetired ? (int)($R['age'] ?? 0) : (int)($P['age'] ?? 0);
$dispTeam = $isRetired ? ($R['team_name'] ?? '') : trim($P['t_city'] . ' ' . $P['t_name']);
$dispTeamId    = $isRetired ? null : (int)$P['t_id'];
$dispTeamPhoto = (!$isRetired && !empty($P['t_photo'])) ? $P['t_photo'] : '/img/default-team.png';

// Foto do jogador — mesma regra do getPlayerPhotoUrl() de players.php:
// foto enviada pelo GM, senao o headshot da NBA, senao avatar com as iniciais.
$avatarFallback = 'https://ui-avatars.com/api/?name=' . rawurlencode($playerName ?: 'P')
                . '&background=121212&color=fc0025&rounded=true&bold=true';

$nbaPhoto = (!$isRetired && !empty($P['nba_player_id']))
    ? 'https://cdn.nba.com/headshots/nba/latest/1040x760/' . rawurlencode((string)$P['nba_player_id']) . '.png'
    : '';

$custom = $isRetired ? '' : trim((string)($P['foto_adicional'] ?? ''));
$dispPhoto = $custom !== '' ? $custom : ($nbaPhoto ?: $avatarFallback);

// Se a foto enviada pelo GM sumir do servidor, ainda tenta o headshot da NBA
// antes de desistir e mostrar as iniciais.
$photoFallback = ($custom !== '' && $nbaPhoto !== '') ? $nbaPhoto : $avatarFallback;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<meta name="theme-color" content="#fc0025">
<title><?= htmlspecialchars($playerName) ?> · FBA Manager</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:color-mix(in srgb, var(--red) 10%, transparent);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;--border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--text:#f0f0f3;--text-2:#868690;--text-3:#7d7d85;--amber:#f59e0b;--green:#22c55e;--purple:#a855f7;--font:'Montserrat',sans-serif;--radius:14px;--radius-sm:10px;--sidebar-w:260px;--ease:cubic-bezier(.2,.8,.2,1);--t:200ms;--border-red:color-mix(in srgb, var(--red) 22%, transparent)}
:root[data-theme="light"]{--bg:#f6f7fb;--panel:#ffffff;--panel-2:#f2f4f8;--panel-3:#e9edf4;--border:#e3e6ee;--border-md:#d7dbe6;--text:#111217;--text-2:#5b6270;--text-3:#657080}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.icon-btn{width:34px;height:34px;border-radius:10px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:15px;cursor:pointer;text-decoration:none;transition:all .2s}
.icon-btn:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
.content{max-width:1000px;margin:0 auto;padding:24px 16px 80px;width:100%}
/* hero */
.p-hero{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:16px;display:flex;gap:20px;align-items:center;flex-wrap:wrap}
.p-avatar{width:88px;height:88px;border-radius:20px;background:var(--panel-2);border:1px solid var(--border-md);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
.p-avatar img{width:100%;height:100%;object-fit:cover;object-position:top center;display:block}
.p-name{font-family:'Oswald',sans-serif;font-size:28px;font-weight:800;line-height:1.1}
.p-sub{font-size:13px;color:var(--text-2);margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.p-team-link{display:inline-flex;align-items:center;gap:7px;color:var(--text);text-decoration:none;font-weight:600}
.p-team-link:hover{color:var(--red)}
.p-team-link img{width:24px;height:24px;border-radius:7px;object-fit:contain;background:var(--panel-3);border:1px solid var(--border-md)}
.p-badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.badge-x{font-size:10px;font-weight:800;padding:3px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.4px}
.badge-ovr{background:var(--red-soft);border:1px solid var(--border-red);color:var(--red)}
.badge-ret{background:rgba(245,158,11,.14);border:1px solid rgba(245,158,11,.38);color:var(--amber)}
.badge-neutral{background:var(--panel-3);border:1px solid var(--border-md);color:var(--text-2)}
.p-ovrbox{margin-left:auto;text-align:center;flex-shrink:0}
.p-ovrbox .v{font-family:'Oswald',sans-serif;font-size:44px;font-weight:800;color:var(--red);line-height:1}
.p-ovrbox .l{font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:1px}
/* abas */
.tabs{display:flex;gap:6px;overflow-x:auto;margin-bottom:14px;padding-bottom:2px}
.tab-btn{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:9px 16px;color:var(--text-2);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all var(--t)}
.tab-btn:hover{color:var(--text)}
.tab-btn.active{background:var(--red-soft);border-color:var(--border-red);color:var(--red)}
.tab-pane{display:none}
.tab-pane.active{display:block}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:14px}
.section-title{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);margin-bottom:12px;display:flex;align-items:center;gap:8px}
.section-title i{color:var(--red)}
.grid-2{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px}
.kv{background:var(--panel-2);border:1px solid var(--border);border-radius:10px;padding:12px 14px}
.kv-l{font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px}
.kv-v{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700;margin-top:3px}
.kv-v.small{font-size:15px;font-family:var(--font);font-weight:600}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{padding:9px 10px;text-align:left;color:var(--text-3);font-weight:600;border-bottom:1px solid var(--border);font-size:10px;text-transform:uppercase;letter-spacing:.5px}
.tbl td{padding:10px;border-bottom:1px solid var(--border)}
.tbl tr:last-child td{border-bottom:none}
.tbl td.num,.tbl th.num{text-align:right;font-family:'Oswald',sans-serif;font-weight:700}
.empty{text-align:center;padding:22px;color:var(--text-3);font-size:13px}
.skill-row{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.skill-row:last-child{margin-bottom:0}
.skill-name{font-size:12px;color:var(--text-2);width:140px;flex-shrink:0}
.skill-bar{flex:1;height:8px;background:var(--panel-3);border-radius:999px;overflow:hidden}
.skill-fill{height:100%;background:var(--red);border-radius:999px;transition:width .5s var(--ease)}
.skill-val{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;width:34px;text-align:right}

/* Escala de cor por faixa — mesma leitura do OVR no resto do site */
.tone-elite{background:#22c55e} .skill-val.tone-elite{background:none;color:#22c55e}
.tone-otimo{background:#84cc16} .skill-val.tone-otimo{background:none;color:#84cc16}
.tone-bom  {background:#eab308} .skill-val.tone-bom  {background:none;color:#eab308}
.tone-medio{background:#f97316} .skill-val.tone-medio{background:none;color:#f97316}
.tone-fraco{background:#ef4444} .skill-val.tone-fraco{background:none;color:#ef4444}

/* Resumo no topo da aba */
.sk-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}
.sk-sum-item{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px}
.sk-sum-l{font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:6px}
.sk-sum-v{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700;line-height:1.1}
.sk-sum-v span{font-size:15px;opacity:.85;margin-left:4px}
.sk-sum-v.sk-strong,.sk-sum-v.sk-weak{font-size:14px;font-family:var(--font);font-weight:600}
.sk-sum-v.sk-strong{color:#22c55e}
.sk-sum-v.sk-weak{color:#f97316}

/* Grupos lado a lado */
.sk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;align-items:start}
.sk-card{margin-bottom:0}
/* gráfico de carreira */
.chart{background:var(--panel-2);border:1px solid var(--border);border-radius:10px;padding:14px;overflow-x:auto}
@media(max-width:640px){
  .content{padding:18px 12px 72px}
  .panel{padding:16px 14px}
  .p-hero{padding:18px}
  .p-name{font-size:22px}
  .p-ovrbox{margin-left:0}
  .skill-name{width:110px}
}
/* Layout com menu lateral */
.app{display:flex;min-height:100vh}
.sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;transition:transform var(--t) var(--ease);overflow-y:auto;scrollbar-width:none}
.sidebar::-webkit-scrollbar{display:none}
.sb-brand{padding:22px 18px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-shrink:0}
.sb-logo{width:34px;height:34px;border-radius:9px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#fff;flex-shrink:0}
.sb-brand-text{font-weight:700;font-size:15px;line-height:1.1}
.sb-brand-text span{display:block;font-size:11px;font-weight:400;color:var(--text-2)}
.sb-team{margin:14px 14px 0;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-team img{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-team-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.2}
.sb-team-league{font-size:11px;color:var(--red);font-weight:600}
.sb-nav{flex:1;padding:12px 10px 8px}
.sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 6px}
.sb-nav a{font-family:'Inter',sans-serif;display:flex;align-items:center;gap:10px;padding:10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease)}
.sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0}
.sb-nav a:hover{background:var(--panel-2);color:var(--text)}
.sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600}
.sb-theme-toggle{margin:10px 14px;display:flex;align-items:center;gap:8px;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:9px 12px;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;flex-shrink:0}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-username{font-size:12px;font-weight:500;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;text-decoration:none;flex-shrink:0}
.topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:260}
.topbar-title{font-weight:700;font-size:15px;flex:1}
.topbar-title em{color:var(--red);font-style:normal}
.menu-btn{width:34px;height:34px;border-radius:9px;background:var(--panel-2);border:1px solid var(--border);color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:17px}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:250}
.sb-overlay.show{display:block}
.main{margin-left:var(--sidebar-w);min-height:100vh;width:calc(100% - var(--sidebar-w))}
@media(max-width:992px){
  :root{--sidebar-w:0px}
  .sidebar{transform:translateX(-260px)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;width:100%;padding-top:54px}
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
  <div class="topbar-title">Perfil do <em>Jogador</em></div>
  <a href="players.php" class="icon-btn"><i class="bi bi-arrow-left"></i></a>
</header>

<main class="main">
 <div class="content">

  <!-- Cabeçalho -->
  <div class="p-hero">
    <div class="p-avatar">
      <img src="<?= htmlspecialchars($dispPhoto) ?>" alt="<?= htmlspecialchars($playerName) ?>"
           data-fb2="<?= htmlspecialchars($avatarFallback, ENT_QUOTES) ?>"
           onerror="this.onerror=function(){this.onerror=null;this.src=this.dataset.fb2};this.src='<?= htmlspecialchars($photoFallback, ENT_QUOTES) ?>'">
    </div>
    <div style="min-width:0">
      <div class="p-name"><?= htmlspecialchars($playerName) ?></div>
      <div class="p-sub">
        <?php if ($dispPos): ?><span><?= htmlspecialchars($dispPos) ?></span> ·<?php endif; ?>
        <?php if ($dispAge): ?><span><?= $dispAge ?> anos</span> ·<?php endif; ?>
        <?php if ($isRetired): ?>
          <span><?= htmlspecialchars($dispTeam ?: '—') ?></span>
        <?php else: ?>
          <a class="p-team-link" href="team-history.php?team_id=<?= $dispTeamId ?>">
            <img src="<?= htmlspecialchars($dispTeamPhoto) ?>" alt="" onerror="this.src='/img/default-team.png'">
            <?= htmlspecialchars($dispTeam) ?>
          </a>
        <?php endif; ?>
      </div>
      <div class="p-badges">
        <span class="badge-x badge-neutral"><?= htmlspecialchars($league) ?></span>
        <?php if ($isRetired): ?>
          <span class="badge-x badge-ret">Aposentado</span>
        <?php else: ?>
          <?php if (!empty($P['role'])): ?><span class="badge-x badge-neutral"><?= htmlspecialchars($P['role']) ?></span><?php endif; ?>
          <?php if (!empty($P['player_tag'])): ?><span class="badge-x badge-neutral"><?= htmlspecialchars($P['player_tag']) ?></span><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="p-ovrbox">
      <div class="v"><?= $dispOvr ?: '—' ?></div>
      <div class="l"><?= $isRetired ? 'Último OVR' : 'OVR' ?></div>
    </div>
  </div>

  <!-- Abas -->
  <div class="tabs" id="tabs">
    <button class="tab-btn active" data-tab="geral">Visão geral</button>
    <?php if ($salary): ?><button class="tab-btn" data-tab="contrato">Contrato</button><?php endif; ?>
    <button class="tab-btn" data-tab="carreira">Carreira</button>
    <?php if (!$isRetired): ?><button class="tab-btn" data-tab="draft">Draft</button><?php endif; ?>
    <button class="tab-btn" data-tab="premios">Prêmios</button>
    <button class="tab-btn" data-tab="trades">Trades</button>
    <?php if ($skills): ?><button class="tab-btn" data-tab="atributos">Atributos</button><?php endif; ?>
  </div>

  <!-- Visão geral -->
  <div class="tab-pane active" id="pane-geral">
    <div class="panel">
      <div class="section-title"><i class="bi bi-person-badge"></i> Resumo</div>
      <div class="grid-2">
        <div class="kv"><div class="kv-l">Posição</div><div class="kv-v"><?= htmlspecialchars($dispPos ?: '—') ?></div></div>
        <div class="kv"><div class="kv-l">OVR</div><div class="kv-v"><?= $dispOvr ?: '—' ?></div></div>
        <div class="kv"><div class="kv-l">Idade</div><div class="kv-v"><?= $dispAge ?: '—' ?></div></div>
        <div class="kv"><div class="kv-l">Time</div><div class="kv-v small"><?= htmlspecialchars($dispTeam ?: '—') ?></div></div>
        <?php if (!$isRetired): ?>
        <div class="kv"><div class="kv-l">Temporadas na liga</div><div class="kv-v"><?= (int)($P['seasons_in_league'] ?? 0) ?></div></div>
        <div class="kv"><div class="kv-l">Já foi trocado</div><div class="kv-v small"><?= !empty($P['was_traded']) ? 'Sim' : 'Não' ?></div></div>
        <?php else: ?>
        <div class="kv"><div class="kv-l">Temporadas no histórico</div><div class="kv-v"><?= count($career) ?></div></div>
        <div class="kv"><div class="kv-l">Status</div><div class="kv-v small">Fora da liga</div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Contrato -->
  <?php if ($salary): ?>
  <div class="tab-pane" id="pane-contrato">
    <div class="panel">
      <div class="section-title"><i class="bi bi-cash-stack"></i> Contrato (ELITE)</div>
      <div class="grid-2">
        <div class="kv"><div class="kv-l">Salário base</div><div class="kv-v"><?= (int)$salary['base_salary'] ?>M</div></div>
        <div class="kv"><div class="kv-l">Bônus de prêmio</div><div class="kv-v"><?= (int)$salary['award_bonus'] ?>M</div></div>
        <div class="kv"><div class="kv-l">Salário total</div><div class="kv-v" style="color:var(--red)"><?= (int)$salary['total_salary'] ?>M</div></div>
        <div class="kv"><div class="kv-l">Cap Flex</div><div class="kv-v small"><?= !empty($salary['cap_flex_eligible']) ? '+' . (int)$salary['cap_flex_value'] . 'M' : 'Não elegível' ?></div></div>
        <div class="kv"><div class="kv-l">Rookie Scale</div><div class="kv-v small"><?= !empty($salary['is_rookie_scale']) ? 'Sim' : 'Não' ?></div></div>
        <div class="kv"><div class="kv-l">No time que draftou</div><div class="kv-v small"><?= !empty($salary['is_on_draft_team']) ? 'Sim' : 'Não' ?></div></div>
      </div>
      <div style="font-size:11px;color:var(--text-3);margin-top:10px">O Cap Flex aumenta o teto salarial do time, não o salário do jogador.</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Carreira -->
  <div class="tab-pane" id="pane-carreira">
    <div class="panel">
      <div class="section-title"><i class="bi bi-graph-up"></i> Evolução por temporada</div>
      <?php if (!$career): ?>
        <div class="empty">Sem histórico registrado para este jogador.</div>
      <?php else: ?>
        <div class="chart" id="careerChart"></div>
        <div style="overflow-x:auto;margin-top:14px">
          <table class="tbl">
            <thead><tr><th>Temporada</th><th>Time</th><th class="num">OVR</th><th class="num">Idade</th><th>Pos.</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($career) as $c): ?>
              <tr>
                <td><?= (int)$c['year'] ?><?= $c['season_number'] ? ' <span style="color:var(--text-3);font-size:11px">(T' . (int)$c['season_number'] . ')</span>' : '' ?></td>
                <td><?= htmlspecialchars($c['team_name'] ?: '—') ?></td>
                <td class="num"><?= (int)$c['ovr'] ?></td>
                <td class="num"><?= (int)$c['age'] ?></td>
                <td><?= htmlspecialchars($c['position'] ?: '—') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Draft -->
  <?php if (!$isRetired): ?>
  <div class="tab-pane" id="pane-draft">
    <div class="panel">
      <div class="section-title"><i class="bi bi-trophy"></i> Draft</div>
      <?php
        $dbId = $P['drafted_by_team_id'] ?? null;
        $dbName = '—';
        if ($dbId) {
            $sd = $pdo->prepare("SELECT CONCAT(city,' ',name) FROM teams WHERE id = ?");
            $sd->execute([$dbId]);
            $dbName = $sd->fetchColumn() ?: '—';
        }
        $rnd = $P['draft_round'] ?? null;
        $pk  = $P['draft_pick_position'] ?? null;
      ?>
      <div class="grid-2">
        <div class="kv"><div class="kv-l">Draftado por</div><div class="kv-v small"><?= htmlspecialchars($dbName) ?></div></div>
        <div class="kv"><div class="kv-l">Temporada do draft</div><div class="kv-v"><?= $P['drafted_season_number'] ? 'T' . (int)$P['drafted_season_number'] : '—' ?></div></div>
        <div class="kv"><div class="kv-l">Rodada</div><div class="kv-v"><?= $rnd ? (int)$rnd . 'ª' : '—' ?></div></div>
        <div class="kv"><div class="kv-l">Nº da pick</div><div class="kv-v"><?= $pk ? '#' . (int)$pk : '—' ?></div></div>
        <div class="kv"><div class="kv-l">Ainda no time que draftou</div><div class="kv-v small"><?= ($dbId && (int)$dbId === (int)$P['team_id']) ? 'Sim' : 'Não' ?></div></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Prêmios -->
  <div class="tab-pane" id="pane-premios">
    <div class="panel">
      <div class="section-title"><i class="bi bi-award"></i> Prêmios individuais</div>
      <?php if (!$awards): ?>
        <div class="empty">Nenhum prêmio registrado.</div>
      <?php else: ?>
        <div style="overflow-x:auto">
          <table class="tbl">
            <thead><tr><th>Prêmio</th><th>Temporada</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($awards as $a): ?>
              <tr>
                <td style="font-weight:600;color:var(--amber)"><?= htmlspecialchars($AWARD_LABELS[$a['award_type']] ?? $a['award_type']) ?></td>
                <td><?= (int)$a['year'] ?><?= $a['season_number'] ? ' (T' . (int)$a['season_number'] . ')' : '' ?></td>
                <td><?= htmlspecialchars($a['team_name'] ?: '—') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Trades -->
  <div class="tab-pane" id="pane-trades">
    <div class="panel">
      <div class="section-title"><i class="bi bi-arrow-left-right"></i> Trocas em que entrou</div>
      <?php if (!$trades): ?>
        <div class="empty">Este jogador nunca foi trocado.</div>
      <?php else: ?>
        <div style="overflow-x:auto">
          <table class="tbl">
            <thead><tr><th>Data</th><th>De</th><th>Para</th><th class="num">Temporada</th></tr></thead>
            <tbody>
            <?php foreach ($trades as $t): ?>
              <tr>
                <td><?= $t['resolved_at'] ? date('d/m/Y', strtotime($t['resolved_at'])) : ($t['created_at'] ? date('d/m/Y', strtotime($t['created_at'])) : '—') ?></td>
                <td><?= htmlspecialchars($t['from_team'] ?: '—') ?></td>
                <td><?= htmlspecialchars($t['to_team'] ?: '—') ?></td>
                <td class="num"><?= $t['season_year'] ? (int)$t['season_year'] : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Atributos -->
  <?php if ($skills): ?>
  <div class="tab-pane" id="pane-atributos">
    <?php
      $nums = array_filter(array_map(fn($v) => is_numeric($v) ? (int)$v : null, $skills), fn($v) => $v !== null);
      $melhorLabel = ''; $piorLabel = '';
      if ($nums) {
          $melhorLabel = array_search(max($nums), $nums, true);
          $piorLabel   = array_search(min($nums), $nums, true);
      }
    ?>
    <?php if ($nums): ?>
    <div class="sk-summary">
      <div class="sk-sum-item">
        <div class="sk-sum-l">Média dos atributos</div>
        <div class="sk-sum-v"><?= round(array_sum($nums) / count($nums)) ?></div>
      </div>
      <div class="sk-sum-item">
        <div class="sk-sum-l">Ponto forte</div>
        <div class="sk-sum-v sk-strong"><?= htmlspecialchars($melhorLabel) ?> <span><?= max($nums) ?></span></div>
      </div>
      <div class="sk-sum-item">
        <div class="sk-sum-l">Ponto fraco</div>
        <div class="sk-sum-v sk-weak"><?= htmlspecialchars($piorLabel) ?> <span><?= min($nums) ?></span></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="sk-grid">
      <?php foreach ($skillsByGroup as $groupName => $group): ?>
      <div class="panel sk-card">
        <div class="section-title"><i class="bi <?= htmlspecialchars($group['icon']) ?>"></i> <?= htmlspecialchars($groupName) ?></div>
        <?php foreach ($group['items'] as $label => $val):
          $num = is_numeric($val) ? max(0, min(99, (int)$val)) : null; ?>
          <div class="skill-row">
            <div class="skill-name"><?= htmlspecialchars($label) ?></div>
            <?php if ($num !== null): ?>
              <div class="skill-bar">
                <div class="skill-fill tone-<?= skillTone($num) ?>" style="width:<?= round($num / 99 * 100) ?>%"></div>
              </div>
              <div class="skill-val tone-<?= skillTone($num) ?>"><?= $num ?></div>
            <?php else: ?>
              <div style="flex:1;font-size:12px;color:var(--text-2)"><?= htmlspecialchars((string)$val) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

 </div>
</main>
</div>

<script>
/* menu mobile + tema */
(function(){
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sbOverlay');
  const menuBtn = document.getElementById('menuBtn');
  if (menuBtn) menuBtn.addEventListener('click', () => { sidebar?.classList.add('open'); overlay?.classList.add('show'); });
  if (overlay) overlay.addEventListener('click', () => { sidebar?.classList.remove('open'); overlay.classList.remove('show'); });
  const themeToggle = document.getElementById('themeToggle');
  const themeKey = 'fba-theme';
  const applyTheme = (t) => {
    if (t === 'light') { document.documentElement.setAttribute('data-theme','light'); if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>'; return; }
    document.documentElement.removeAttribute('data-theme'); if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
  };
  applyTheme(localStorage.getItem(themeKey) || 'dark');
  if (themeToggle) themeToggle.addEventListener('click', () => {
    const cur = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    const next = cur === 'light' ? 'dark' : 'light';
    localStorage.setItem(themeKey, next); applyTheme(next);
  });
})();

/* abas */
document.querySelectorAll('#tabs .tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#tabs .tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    const pane = document.getElementById('pane-' + btn.dataset.tab);
    if (pane) pane.classList.add('active');
  });
});

/* gráfico simples de evolução de OVR */
(function(){
  const el = document.getElementById('careerChart');
  if (!el) return;
  const data = <?= json_encode(array_map(fn($c) => ['y' => (int)$c['year'], 'o' => (int)$c['ovr']], $career)) ?>;
  if (!data.length) return;
  const W = Math.max(320, data.length * 90), H = 170, P = 30;
  const ovrs = data.map(d => d.o);
  const min = Math.max(0, Math.min(...ovrs) - 4), max = Math.max(...ovrs) + 4;
  const span = (max - min) || 1;
  const x = i => P + (data.length === 1 ? (W - 2*P)/2 : i * (W - 2*P) / (data.length - 1));
  const y = v => H - P - ((v - min) / span) * (H - 2*P);
  const pts = data.map((d,i) => `${x(i)},${y(d.o)}`).join(' ');
  let svg = `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" xmlns="http://www.w3.org/2000/svg">`;
  svg += `<polyline points="${pts}" fill="none" stroke="var(--red)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>`;
  data.forEach((d,i) => {
    svg += `<circle cx="${x(i)}" cy="${y(d.o)}" r="4.5" fill="var(--red)"/>`;
    svg += `<text x="${x(i)}" y="${y(d.o) - 12}" text-anchor="middle" font-size="12" font-weight="700" fill="var(--text)">${d.o}</text>`;
    svg += `<text x="${x(i)}" y="${H - 8}" text-anchor="middle" font-size="11" fill="var(--text-3)">${d.y}</text>`;
  });
  svg += `</svg>`;
  el.innerHTML = svg;
})();
</script>
</body>
</html>
