<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();
$user = getUserSession();
$pdo  = db();

$team = null;
try {
    $s = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
    $s->execute([$user['id']]);
    $team = $s->fetch();
} catch (Exception $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS copa2026_predictions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        picks JSON NOT NULL DEFAULT '{}',
        updated_at DATETIME DEFAULT NOW(),
        UNIQUE KEY uk_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $picks = is_array($body['picks'] ?? null) ? $body['picks'] : [];
    try {
        $pdo->prepare('INSERT INTO copa2026_predictions (user_id, picks, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE picks=VALUES(picks), updated_at=NOW()')
            ->execute([$user['id'], json_encode($picks)]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit;
}

$savedPicks = '{}';
try {
    $s = $pdo->prepare('SELECT picks FROM copa2026_predictions WHERE user_id = ?');
    $s->execute([$user['id']]);
    $row = $s->fetch();
    if ($row) $savedPicks = $row['picks'];
} catch (Exception $e) {}

$isAdmin = hasAdminAccess($pdo, (int)$user['id']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta name="theme-color" content="#fc0025">
<title>Bolão Copa 2026 · FBA</title>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:rgba(252,0,37,.10);--red-glow:rgba(252,0,37,.18);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;--border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--border-red:rgba(252,0,37,.22);--text:#f0f0f3;--text-2:#868690;--text-3:#48484f;--green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;--sidebar-w:260px;--font:'Poppins',sans-serif;--radius:14px;--radius-sm:10px;--ease:cubic-bezier(.2,.8,.2,1);--t:200ms}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.app{display:flex;min-height:100vh}

/* Sidebar */
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
.sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 5px}
.sb-nav a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease)}
.sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0}
.sb-nav a:hover{background:var(--panel-2);color:var(--text)}
.sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600}
.sb-nav a.active i{color:var(--red)}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-username{font-size:12px;font-weight:500;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;flex-shrink:0}
.sb-logout:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}

/* Topbar */
.topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:240}
.topbar-title{font-weight:700;font-size:15px;flex:1}
.topbar-title em{color:var(--red);font-style:normal}
.menu-btn{width:34px;height:34px;border-radius:9px;background:var(--panel-2);border:1px solid var(--border);color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:17px}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:250}
.sb-overlay.show{display:block}

/* Main */
.main{margin-left:var(--sidebar-w);min-height:100vh;width:calc(100% - var(--sidebar-w));display:flex;flex-direction:column}
.page-hero{padding:24px 28px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.page-hero-left{}
.hero-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
.hero-title{font-size:22px;font-weight:800;display:flex;align-items:center;gap:10px}
.hero-sub{font-size:12px;color:var(--text-2);margin-top:2px}
.content{padding:20px 28px 60px;flex:1}

/* Bracket scroll wrapper */
.bracket-scroll{overflow-x:auto;overflow-y:visible;padding-bottom:20px;-webkit-overflow-scrolling:touch}
.bracket-scroll::-webkit-scrollbar{height:6px}
.bracket-scroll::-webkit-scrollbar-track{background:var(--panel-2);border-radius:3px}
.bracket-scroll::-webkit-scrollbar-thumb{background:var(--border-md);border-radius:3px}

/* Bracket container */
.bracket{display:flex;align-items:stretch;gap:0;position:relative;min-width:1400px;height:680px}

/* Round columns */
.col{display:flex;flex-direction:column;justify-content:space-around;padding:0;position:relative}
.col-r32{width:152px}
.col-r16{width:152px}
.col-qf{width:152px}
.col-sf{width:152px}
.col-final{width:172px;justify-content:center;gap:10px}
.col-gap{width:20px;flex-shrink:0}

/* Round labels */
.round-label{font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-3);text-align:center;padding:0 4px 10px;white-space:nowrap}

/* Match card */
.match-card{background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;position:relative;width:100%}
.match-meta{font-size:8px;font-weight:600;color:var(--text-3);text-align:center;padding:4px 6px 2px;letter-spacing:.3px;border-bottom:1px solid var(--border)}
.team-slot{display:flex;align-items:center;gap:6px;padding:6px 8px;cursor:pointer;transition:all var(--t) var(--ease);user-select:none;min-height:28px}
.team-slot:hover:not(.empty):not(.tbd){background:rgba(255,255,255,.05)}
.team-slot.winner{background:var(--red-soft);border-left:2px solid var(--red)}
.team-slot.loser{opacity:.38}
.team-slot.empty,.team-slot.tbd{cursor:default;opacity:.35}
.team-flag{font-size:13px;line-height:1;flex-shrink:0}
.team-code{font-size:11px;font-weight:700;color:var(--text)}
.team-slot.winner .team-code{color:var(--red)}
.match-divider{height:1px;background:var(--border);margin:0}

/* Final special card */
.final-card{background:var(--panel-2);border:1px solid var(--border-red);border-radius:var(--radius);overflow:hidden;text-align:center}
.final-label{font-size:10px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;padding:8px 10px 6px;color:var(--red);border-bottom:1px solid var(--border)}
.third-card{background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;text-align:center}
.third-label{font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:6px 10px 4px;color:var(--text-3);border-bottom:1px solid var(--border)}
.champion-badge{font-size:22px;text-align:center;padding:6px 4px 2px}
.champion-name{font-size:11px;font-weight:700;color:var(--amber);text-align:center;padding-bottom:6px}

/* SVG connector overlay */
#bracket-svg{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;overflow:visible}

/* Save bar */
.save-bar{display:flex;align-items:center;gap:12px;margin-top:18px;flex-wrap:wrap}
.btn-save{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border-radius:var(--radius-sm);background:var(--red);border:none;color:#fff;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:filter var(--t) var(--ease)}
.btn-save:hover{filter:brightness(1.1)}
.btn-save:disabled{opacity:.5;cursor:not-allowed}
.btn-reset{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;border-radius:var(--radius-sm);background:transparent;border:1px solid var(--border-md);color:var(--text-2);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease)}
.btn-reset:hover{border-color:var(--border-red);color:var(--red)}
.save-status{font-size:12px;color:var(--text-2)}
.save-status.ok{color:var(--green)}
.save-status.err{color:var(--red)}

/* Progress bar */
.progress-wrap{background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:14px}
.progress-bar-outer{flex:1;background:var(--panel-3);border-radius:999px;height:6px;overflow:hidden}
.progress-bar-inner{height:100%;border-radius:999px;background:var(--red);transition:width .4s var(--ease)}
.progress-text{font-size:12px;font-weight:600;color:var(--text-2);white-space:nowrap}

/* Responsive */
@media(max-width:992px){
  :root{--sidebar-w:0px}
  .sidebar{transform:translateX(-260px)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;width:100%;padding-top:54px}
  .topbar{display:flex}
  .page-hero,.content{padding-left:16px;padding-right:16px}
  .page-hero{padding-top:16px}
}
</style>
</head>
<body>
<div class="app">

<aside class="sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">FBA</div>
    <div class="sb-brand-text">FBA Manager<span>Liga <?= htmlspecialchars($user['league'] ?? '') ?></span></div>
  </div>
  <?php if ($team): ?>
  <div class="sb-team">
    <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
    <div>
      <div class="sb-team-name"><?= htmlspecialchars($team['city'].' '.$team['name']) ?></div>
      <div class="sb-team-league"><?= htmlspecialchars($user['league'] ?? '') ?></div>
    </div>
  </div>
  <?php endif; ?>
  <nav class="sb-nav">
    <div class="sb-section">Principal</div>
    <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
    <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
    <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
    <a href="/players.php"><i class="bi bi-person-lines-fill"></i> Jogadores</a>
    <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
    <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
    <a href="/mercado.php"><i class="bi bi-shop"></i> Mercado</a>
    <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
    <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
    <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>
    <a href="/tapas.php"><i class="bi bi-hand-index-thumb"></i> Tapas</a>
    <div class="sb-section">Liga</div>
    <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
    <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
    <a href="/hall-da-fama.php"><i class="bi bi-award-fill"></i> Hall da Fama</a>
    <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
    <a href="/mundo-fba.php"><i class="bi bi-globe2"></i> Mundo FBA</a>
    <a href="/estatisticas.php"><i class="bi bi-bar-chart-line-fill"></i> Estatísticas</a>
    <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
    <a href="/copa2026.php" class="active"><i class="bi bi-globe-americas"></i> Bolão Copa 2026</a>
    <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>
    <a href="/thepathetic.php"><i class="bi bi-newspaper"></i> The Pathetic</a>
    <?php if ($isAdmin): ?>
    <div class="sb-section">Admin</div>
    <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
    <?php endif; ?>
    <div class="sb-section">Conta</div>
    <a href="/settings.php"><i class="bi bi-gear-fill"></i> Minha Conta</a>
    <a href="/team-public-page.php"><i class="bi bi-globe2"></i> Página do Time</a>
  </nav>
  <div class="sb-footer">
    <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>"
         alt="<?= htmlspecialchars($user['name']) ?>" class="sb-avatar"
         onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name']) ?>&background=1c1c21&color=fc0025'">
    <span class="sb-username"><?= htmlspecialchars($user['name']) ?></span>
    <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
  </div>
</aside>

<div class="sb-overlay" id="sbOverlay"></div>
<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div class="topbar-title">FBA <em>Bolão Copa 2026</em></div>
</header>

<main class="main">
  <div class="page-hero">
    <div class="page-hero-left">
      <div class="hero-eyebrow">⚽ Copa do Mundo 2026</div>
      <h1 class="hero-title"><i class="bi bi-globe-americas" style="color:var(--red)"></i> Bolão · Chave do Mata-Mata</h1>
      <div class="hero-sub">Clique em um time para avançá-lo. Salve seu palpite quando terminar.</div>
    </div>
  </div>

  <div class="content">

    <div class="progress-wrap">
      <div class="progress-bar-outer"><div class="progress-bar-inner" id="progressBar" style="width:0%"></div></div>
      <span class="progress-text" id="progressText">0 / 31 palpites</span>
    </div>

    <!-- Round labels row -->
    <div style="display:flex;gap:0;min-width:1400px;margin-bottom:6px;padding-right:0" id="roundLabels">
      <div style="width:152px;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-3);text-align:center">Segundas de Final</div>
      <div style="width:20px"></div>
      <div style="width:152px;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-3);text-align:center">Oitavas de Final</div>
      <div style="width:20px"></div>
      <div style="width:152px;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-3);text-align:center">Quartas de Final</div>
      <div style="width:20px"></div>
      <div style="width:152px;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-3);text-align:center">Semifinal</div>
      <div style="width:20px"></div>
      <div style="width:172px;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--amber);text-align:center">Final</div>
      <div style="width:20px"></div>
      <div style="width:152px;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-3);text-align:center">Semifinal</div>
      <div style="width:20px"></div>
      <div style="width:152px;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-3);text-align:center">Quartas de Final</div>
      <div style="width:20px"></div>
      <div style="width:152px;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-3);text-align:center">Oitavas de Final</div>
      <div style="width:20px"></div>
      <div style="width:152px;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-3);text-align:center">Segundas de Final</div>
    </div>

    <div class="bracket-scroll">
      <div class="bracket" id="bracket">
        <!-- Left side -->
        <div class="col col-r32" id="col-left-r32"></div>
        <div class="col-gap"></div>
        <div class="col col-r16" id="col-left-r16"></div>
        <div class="col-gap"></div>
        <div class="col col-qf" id="col-left-qf"></div>
        <div class="col-gap"></div>
        <div class="col col-sf" id="col-left-sf"></div>
        <div class="col-gap"></div>
        <!-- Final center -->
        <div class="col col-final" id="col-final"></div>
        <div class="col-gap"></div>
        <!-- Right side -->
        <div class="col col-sf" id="col-right-sf"></div>
        <div class="col-gap"></div>
        <div class="col col-qf" id="col-right-qf"></div>
        <div class="col-gap"></div>
        <div class="col col-r16" id="col-right-r16"></div>
        <div class="col-gap"></div>
        <div class="col col-r32" id="col-right-r32"></div>
        <!-- SVG connectors -->
        <svg id="bracket-svg" xmlns="http://www.w3.org/2000/svg"></svg>
      </div>
    </div>

    <div class="save-bar">
      <button class="btn-save" id="btnSave" onclick="savePicks()">
        <i class="bi bi-send-fill"></i> Salvar Palpite
      </button>
      <button class="btn-reset" onclick="resetPicks()">
        <i class="bi bi-arrow-counterclockwise"></i> Limpar
      </button>
      <span class="save-status" id="saveStatus"></span>
    </div>

  </div>
</main>
</div>

<script>
// ── Data ───────────────────────────────────────────────────────────────
const FLAGS = {
  GER:'🇩🇪',PAR:'🇵🇾',FRA:'🇫🇷',SWE:'🇸🇪',RSA:'🇿🇦',CAN:'🇨🇦',NED:'🇳🇱',MAR:'🇲🇦',
  POR:'🇵🇹',CRO:'🇭🇷',ESP:'🇪🇸',AUT:'🇦🇹',USA:'🇺🇸',BIH:'🇧🇦',BEL:'🇧🇪',SEN:'🇸🇳',
  BRA:'🇧🇷',JPN:'🇯🇵',CIV:'🇨🇮',NOR:'🇳🇴',MEX:'🇲🇽',ECU:'🇪🇨',ENG:'🏴',COD:'🇨🇩',
  ARG:'🇦🇷',CPV:'🇨🇻',AUS:'🇦🇺',EGY:'🇪🇬',SUI:'🇨🇭',ALG:'🇩🇿',COL:'🇨🇴',GHA:'🇬🇭',
};

const R32 = {
  J73:['RSA','CAN'],J74:['GER','PAR'],J75:['NED','MAR'],J76:['BRA','JPN'],
  J77:['FRA','SWE'],J78:['CIV','NOR'],J79:['MEX','ECU'],J80:['ENG','COD'],
  J81:['USA','BIH'],J82:['BEL','SEN'],J83:['POR','CRO'],J84:['ESP','AUT'],
  J85:['SUI','ALG'],J86:['ARG','CPV'],J87:['COL','GHA'],J88:['AUS','EGY'],
};

const GINFO = {
  J73:{d:'28/06',t:'16:00'},J74:{d:'29/06',t:'17:30'},J75:{d:'29/06',t:'22:00'},
  J76:{d:'29/06',t:'14:00'},J77:{d:'30/06',t:'18:00'},J78:{d:'30/06',t:'14:00'},
  J79:{d:'30/06',t:'22:00'},J80:{d:'01/07',t:'13:00'},J81:{d:'01/07',t:'21:00'},
  J82:{d:'01/07',t:'17:00'},J83:{d:'02/07',t:'20:00'},J84:{d:'02/07',t:'16:00'},
  J85:{d:'03/07',t:'00:00'},J86:{d:'03/07',t:'19:00'},J87:{d:'03/07',t:'22:30'},
  J88:{d:'03/07',t:'15:00'},J89:{d:'04/07',t:'18:00'},J90:{d:'04/07',t:'14:00'},
  J91:{d:'05/07',t:'17:00'},J92:{d:'05/07',t:'21:00'},J93:{d:'06/07',t:'16:00'},
  J94:{d:'06/07',t:'21:00'},J95:{d:'07/07',t:'13:00'},J96:{d:'07/07',t:'17:00'},
  J97:{d:'09/07',t:'17:00'},J98:{d:'10/07',t:'16:00'},J99:{d:'11/07',t:'18:00'},
  J100:{d:'11/07',t:'22:00'},J101:{d:'14/07',t:'16:00'},J102:{d:'15/07',t:'16:00'},
  J103:{d:'18/07',t:'18:00'},J104:{d:'19/07',t:'16:00'},
};

// Who feeds into each game [slot0, slot1]
const FEEDS = {
  J89:['J74','J77'],J90:['J73','J75'],J91:['J76','J78'],J92:['J79','J80'],
  J93:['J83','J84'],J94:['J81','J82'],J95:['J86','J88'],J96:['J87','J85'],
  J97:['J89','J90'],J98:['J93','J94'],J99:['J91','J92'],J100:['J95','J96'],
  J101:['J97','J98'],J102:['J99','J100'],
  J104:['J101','J102'],
  J103:['J101_L','J102_L'],
};

// What each game feeds into
const INTO = {
  J73:{g:'J90',s:0},J74:{g:'J89',s:0},J75:{g:'J90',s:1},J76:{g:'J91',s:0},
  J77:{g:'J89',s:1},J78:{g:'J91',s:1},J79:{g:'J92',s:0},J80:{g:'J92',s:1},
  J81:{g:'J94',s:0},J82:{g:'J94',s:1},J83:{g:'J93',s:0},J84:{g:'J93',s:1},
  J85:{g:'J96',s:1},J86:{g:'J95',s:0},J87:{g:'J96',s:0},J88:{g:'J95',s:1},
  J89:{g:'J97',s:0},J90:{g:'J97',s:1},J91:{g:'J99',s:0},J92:{g:'J99',s:1},
  J93:{g:'J98',s:0},J94:{g:'J98',s:1},J95:{g:'J100',s:0},J96:{g:'J100',s:1},
  J97:{g:'J101',s:0},J98:{g:'J101',s:1},J99:{g:'J102',s:0},J100:{g:'J102',s:1},
  J101:{g:'J104',s:0},J102:{g:'J104',s:1},
};

// Layout order in columns (top → bottom)
const COLS = {
  lr32:['J74','J77','J73','J75','J83','J84','J81','J82'],
  lr16:['J89','J90','J93','J94'],
  lqf: ['J97','J98'],
  lsf: ['J101'],
  rsf: ['J102'],
  rqf: ['J99','J100'],
  rr16:['J91','J92','J95','J96'],
  rr32:['J76','J78','J79','J80','J86','J88','J87','J85'],
};

// Connector pairs: [top_game, bottom_game, result_game]
const CONNECTORS = [
  // Left R32→R16
  ['J74','J77','J89'],['J73','J75','J90'],['J83','J84','J93'],['J81','J82','J94'],
  // Left R16→QF
  ['J89','J90','J97'],['J93','J94','J98'],
  // Left QF→SF
  ['J97','J98','J101'],
  // Right R32→R16
  ['J76','J78','J91'],['J79','J80','J92'],['J86','J88','J95'],['J87','J85','J96'],
  // Right R16→QF
  ['J91','J92','J99'],['J95','J96','J100'],
  // Right QF→SF
  ['J99','J100','J102'],
  // SF→Final
  ['J101','J102','J104'],
];

// ── State ──────────────────────────────────────────────────────────────
let picks = <?= $savedPicks ?: '{}' ?>;
const TOTAL_PICKS = 31; // 16+8+4+2+1

function getTeams(gid) {
  if (R32[gid]) return [...R32[gid]];
  const [f0, f1] = FEEDS[gid] || [];
  return [resolveTeam(f0), resolveTeam(f1)];
}

function resolveTeam(ref) {
  if (!ref) return null;
  if (ref.endsWith('_L')) {
    const g = ref.replace('_L','');
    const [t0, t1] = getTeams(g);
    const w = picks[g];
    if (!w) return null;
    return w === t0 ? t1 : t0;
  }
  return picks[ref] || null;
}

function makePick(gid, team) {
  const oldWinner = picks[gid];
  if (oldWinner === team) { delete picks[gid]; clearDownstream(gid, oldWinner); }
  else { clearDownstream(gid, oldWinner); picks[gid] = team; }
  render();
}

function clearDownstream(gid, oldTeam) {
  if (!oldTeam) return;
  const info = INTO[gid];
  if (!info) return;
  const next = info.g;
  if (picks[next] === oldTeam) { clearDownstream(next, oldTeam); delete picks[next]; }
  // 3rd place doesn't need explicit clearing — it reads losers dynamically
}

function resetPicks() {
  if (!confirm('Limpar todos os palpites?')) return;
  picks = {};
  render();
}

// ── Render ─────────────────────────────────────────────────────────────
function matchCard(gid) {
  const [t1, t2] = getTeams(gid);
  const w = picks[gid];
  const info = GINFO[gid] || {};

  const slot = (code) => {
    if (!code) return `<div class="team-slot tbd"><span class="team-flag">·</span><span class="team-code" style="color:var(--text-3);font-size:10px">A definir</span></div>`;
    const cls = w ? (w === code ? ' winner' : ' loser') : '';
    return `<div class="team-slot${cls}" onclick="makePick('${gid}','${code}')">
      <span class="team-flag">${FLAGS[code]||''}</span>
      <span class="team-code">${code}</span>
    </div>`;
  };

  return `<div class="match-card" data-game="${gid}">
    <div class="match-meta">${gid} · ${info.d||''} ${info.t||''}</div>
    ${slot(t1)}
    <div class="match-divider"></div>
    ${slot(t2)}
  </div>`;
}

function finalBlock() {
  const [t1, t2] = getTeams('J104');
  const w = picks['J104'];
  const champion = w || null;
  const info = GINFO['J104'] || {};

  const slot = (code) => {
    if (!code) return `<div class="team-slot tbd"><span class="team-flag">·</span><span class="team-code" style="color:var(--text-3);font-size:10px">A definir</span></div>`;
    const cls = w ? (w === code ? ' winner' : ' loser') : '';
    return `<div class="team-slot${cls}" onclick="makePick('J104','${code}')">
      <span class="team-flag">${FLAGS[code]||''}</span>
      <span class="team-code">${code}</span>
    </div>`;
  };

  const champHTML = champion ? `<div class="champion-badge">🏆</div><div class="champion-name">${FLAGS[champion]||''} ${champion}</div>` : `<div style="padding:8px 0;font-size:10px;color:var(--text-3);text-align:center">Campeão</div>`;

  // 3rd place
  const [l1, l2] = getTeams('J103');
  const w3 = picks['J103'];
  const slot3 = (code) => {
    if (!code) return `<div class="team-slot tbd" style="min-height:24px"><span class="team-code" style="color:var(--text-3);font-size:10px">·</span></div>`;
    const cls = w3 ? (w3 === code ? ' winner' : ' loser') : '';
    return `<div class="team-slot${cls}" onclick="makePick('J103','${code}')">
      <span class="team-flag">${FLAGS[code]||''}</span>
      <span class="team-code">${code}</span>
    </div>`;
  };

  return `
    <div class="final-card" data-game="J104">
      <div class="final-label">🏆 Final · ${info.d||''}</div>
      ${champHTML}
      <div class="match-divider"></div>
      ${slot(t1)}
      <div class="match-divider"></div>
      ${slot(t2)}
    </div>
    <div class="third-card" data-game="J103" style="margin-top:8px">
      <div class="third-label">3º Lugar · ${GINFO['J103']?.d||''}</div>
      ${slot3(l1)}
      <div class="match-divider"></div>
      ${slot3(l2)}
    </div>
  `;
}

function render() {
  const colMap = {
    'col-left-r32': COLS.lr32,
    'col-left-r16': COLS.lr16,
    'col-left-qf':  COLS.lqf,
    'col-left-sf':  COLS.lsf,
    'col-right-sf': COLS.rsf,
    'col-right-qf': COLS.rqf,
    'col-right-r16':COLS.rr16,
    'col-right-r32':COLS.rr32,
  };
  for (const [id, games] of Object.entries(colMap)) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = games.map(matchCard).join('');
  }
  const fc = document.getElementById('col-final');
  if (fc) fc.innerHTML = finalBlock();

  updateProgress();
  requestAnimationFrame(drawConnectors);
}

function updateProgress() {
  const total = TOTAL_PICKS;
  const done = Object.keys(picks).length;
  const pct = Math.round(done / total * 100);
  const bar = document.getElementById('progressBar');
  const txt = document.getElementById('progressText');
  if (bar) bar.style.width = pct + '%';
  if (txt) txt.textContent = `${done} / ${total} palpites`;
}

// ── SVG Connectors ─────────────────────────────────────────────────────
function drawConnectors() {
  const bracket = document.getElementById('bracket');
  const svg = document.getElementById('bracket-svg');
  if (!bracket || !svg) return;

  const br = bracket.getBoundingClientRect();
  svg.setAttribute('width', br.width);
  svg.setAttribute('height', br.height);
  svg.innerHTML = '';

  const COLOR_CONN = 'rgba(255,255,255,.10)';
  const COLOR_LIVE = 'rgba(252,0,37,.35)';

  function line(x1,y1,x2,y2,color) {
    const l = document.createElementNS('http://www.w3.org/2000/svg','line');
    l.setAttribute('x1',x1); l.setAttribute('y1',y1);
    l.setAttribute('x2',x2); l.setAttribute('y2',y2);
    l.setAttribute('stroke', color);
    l.setAttribute('stroke-width','1.5');
    svg.appendChild(l);
  }

  function cardCenter(gid) {
    const el = document.querySelector(`[data-game="${gid}"]`);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { x: r.left - br.left, y: r.top - br.top, w: r.width, h: r.height, cx: r.left - br.left + r.width/2, cy: r.top - br.top + r.height/2, right: r.right - br.left, left: r.left - br.left };
  }

  for (const [gA, gB, gC] of CONNECTORS) {
    const A = cardCenter(gA);
    const B = cardCenter(gB);
    const C = cardCenter(gC);
    if (!A || !B || !C) continue;

    const isLeft = A.right < C.left; // left bracket goes rightward
    const hasPickA = !!picks[gA];
    const hasPickB = !!picks[gB];
    const col = (hasPickA && hasPickB) ? COLOR_LIVE : COLOR_CONN;

    if (isLeft) {
      // Horizontal from right of A to midX, vertical, horizontal from right of B to midX, then to C
      const midX = A.right + (C.left - A.right) / 2;
      line(A.right, A.cy, midX, A.cy, col);
      line(midX, A.cy, midX, B.cy, col);
      line(A.right, B.cy, midX, B.cy, col);
      line(midX, C.cy, C.left, C.cy, col);
    } else {
      // Right bracket: connections go leftward
      const midX = B.left - (B.left - C.right) / 2;
      line(A.left, A.cy, midX, A.cy, col);
      line(midX, A.cy, midX, B.cy, col);
      line(B.left, B.cy, midX, B.cy, col);
      line(midX, C.cy, C.right, C.cy, col);
    }
  }
}

// ── Save ───────────────────────────────────────────────────────────────
async function savePicks() {
  const btn = document.getElementById('btnSave');
  const status = document.getElementById('saveStatus');
  btn.disabled = true;
  status.textContent = 'Salvando…';
  status.className = 'save-status';
  try {
    const res = await fetch('/copa2026.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({picks})
    });
    const json = await res.json();
    if (json.success) {
      status.textContent = '✓ Palpite salvo!';
      status.className = 'save-status ok';
    } else {
      status.textContent = 'Erro ao salvar.';
      status.className = 'save-status err';
    }
  } catch(e) {
    status.textContent = 'Erro de conexão.';
    status.className = 'save-status err';
  } finally {
    btn.disabled = false;
    setTimeout(() => { status.textContent = ''; status.className = 'save-status'; }, 4000);
  }
}

// ── Sidebar toggle ─────────────────────────────────────────────────────
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sbOverlay');
const menuBtn = document.getElementById('menuBtn');
if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); overlay.classList.add('show'); });
if (overlay) overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); });

// ── Init ───────────────────────────────────────────────────────────────
render();
window.addEventListener('resize', drawConnectors);
</script>
<script src="/js/pwa.js"></script>
</body>
</html>
