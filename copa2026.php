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

$isAdmin = hasAdminAccess($pdo, (int)$user['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $picks = is_array($body['picks'] ?? null) ? $body['picks'] : [];
    $official = !empty($body['official']) && $isAdmin;
    $saveId = $official ? 0 : (int)$user['id'];
    try {
        $pdo->prepare('INSERT INTO copa2026_predictions (user_id, picks, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE picks=VALUES(picks), updated_at=NOW()')
            ->execute([$saveId, json_encode($picks)]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit;
}

// User's own saved picks (to restore bracket on reload)
$myPicks = '{}';
try {
    $s = $pdo->prepare('SELECT picks FROM copa2026_predictions WHERE user_id = ?');
    $s->execute([(int)$user['id']]);
    $row = $s->fetch();
    if ($row && $row['picks']) $myPicks = $row['picks'];
} catch (Exception $e) {}

// Official picks (for admin to enter/edit actual Copa results)
$officialPicks = '{}';
try {
    $s = $pdo->prepare('SELECT picks FROM copa2026_predictions WHERE user_id = 0');
    $s->execute([]);
    $row = $s->fetch();
    if ($row && $row['picks']) $officialPicks = $row['picks'];
} catch (Exception $e) {}

// Copa ranking: all users' picks vs official results (2 pts per correct pick)
$ptNamesPHP = [
    'GER'=>'Alemanha','PAR'=>'Paraguai','FRA'=>'França','SWE'=>'Suécia',
    'RSA'=>'África do Sul','CAN'=>'Canadá','NED'=>'Holanda','MAR'=>'Marrocos',
    'POR'=>'Portugal','CRO'=>'Croácia','ESP'=>'Espanha','AUT'=>'Áustria',
    'USA'=>'EUA','BIH'=>'Bósnia','BEL'=>'Bélgica','SEN'=>'Senegal',
    'BRA'=>'Brasil','JPN'=>'Japão','CIV'=>'C. do Marfim','NOR'=>'Noruega',
    'MEX'=>'México','ECU'=>'Equador','ENG'=>'Inglaterra','COD'=>'RD Congo',
    'ARG'=>'Argentina','CPV'=>'Cabo Verde','AUS'=>'Austrália','EGY'=>'Egito',
    'SUI'=>'Suíça','ALG'=>'Argélia','COL'=>'Colômbia','GHA'=>'Gana',
];
$officialArr = json_decode($officialPicks, true) ?: [];
$copaRanking = [];
try {
    $rankRows = $pdo->query("
        SELECT cp.user_id, cp.picks, u.name as uname,
               t.name as tname, t.city as tcity, t.league as league
        FROM copa2026_predictions cp
        JOIN users u ON u.id = cp.user_id
        LEFT JOIN teams t ON t.user_id = cp.user_id
        WHERE cp.user_id > 0
        ORDER BY u.name ASC
    ")->fetchAll();
    foreach ($rankRows as $rankRow) {
        $userPicks = json_decode($rankRow['picks'], true) ?: [];
        $pts = 0;
        foreach ($officialArr as $game => $winner) {
            if (isset($userPicks[$game]) && $userPicks[$game] === $winner) $pts += 2;
        }
        $copaRanking[] = [
            'uname'    => $rankRow['uname'],
            'team'     => trim(($rankRow['tcity'] ?? '') . ' ' . ($rankRow['tname'] ?? '')),
            'league'   => $rankRow['league'] ?? '',
            'champion' => $userPicks['J104'] ?? null,
            'pts'      => $pts,
        ];
    }
    usort($copaRanking, fn($a,$b) => $b['pts'] <=> $a['pts']);
} catch (Exception $e) {}
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
:root{--red:#fc0025;--red-soft:rgba(252,0,37,.10);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;--border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--text:#f0f0f3;--text-2:#868690;--text-3:#48484f;--green:#22c55e;--amber:#f59e0b;--sidebar-w:260px;--font:'Poppins',sans-serif;--radius:14px;--radius-sm:10px;--ease:cubic-bezier(.2,.8,.2,1);--t:200ms}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.app{display:flex;min-height:100vh}
.sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;transition:transform var(--t) var(--ease);overflow-y:auto;scrollbar-width:none}
.sidebar::-webkit-scrollbar{display:none}
.sb-brand{padding:22px 18px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-shrink:0}
.sb-logo{width:34px;height:34px;border-radius:9px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#fff;flex-shrink:0}
.sb-brand-text{font-weight:700;font-size:15px;line-height:1.1}
.sb-brand-text span{display:block;font-size:11px;font-weight:400;color:var(--text-2)}
.sb-team{margin:14px 14px 0;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-team img{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-team-name{font-size:13px;font-weight:600;line-height:1.2}
.sb-team-league{font-size:11px;color:var(--red);font-weight:600}
.sb-nav{flex:1;padding:12px 10px 8px}
.sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 5px}
.sb-nav a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease)}
.sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0}
.sb-nav a:hover{background:var(--panel-2);color:var(--text)}
.sb-nav a.active{background:rgba(252,0,37,.10);color:var(--red);font-weight:600}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-username{font-size:12px;font-weight:500;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;flex-shrink:0}
.sb-logout:hover{background:rgba(252,0,37,.10);border-color:var(--red);color:var(--red)}
.topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:240}
.topbar-title{font-weight:700;font-size:15px;flex:1}
.topbar-title em{color:var(--red);font-style:normal}
.menu-btn{width:34px;height:34px;border-radius:9px;background:var(--panel-2);border:1px solid var(--border);color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:17px}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:250}
.sb-overlay.show{display:block}
.main{margin-left:var(--sidebar-w);min-height:100vh;width:calc(100% - var(--sidebar-w));display:flex;flex-direction:column}
.page-hero{padding:24px 28px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.hero-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
.hero-title{font-size:22px;font-weight:800;display:flex;align-items:center;gap:10px}
.hero-sub{font-size:12px;color:var(--text-2);margin-top:2px}
.content{padding:20px 28px 60px;flex:1}
.progress-wrap{background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:14px}
.progress-bar-outer{flex:1;background:var(--panel-3);border-radius:999px;height:6px;overflow:hidden}
.progress-bar-inner{height:100%;border-radius:999px;background:var(--red);transition:width .4s var(--ease)}
.progress-text{font-size:12px;font-weight:600;color:var(--text-2);white-space:nowrap}
.bracket-outer{overflow-x:auto;overflow-y:visible;padding-bottom:16px;-webkit-overflow-scrolling:touch}
.bracket-outer::-webkit-scrollbar{height:6px}
.bracket-outer::-webkit-scrollbar-track{background:var(--panel-2);border-radius:3px}
.bracket-outer::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:3px}
.round-labels{display:flex;align-items:flex-end;padding-bottom:8px;width:1548px}
.round-label-cell{font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-3);text-align:center}
.round-label-cell.final{color:var(--amber)}
.round-label-gap{flex-shrink:0}
.bracket-wrap{position:relative;width:1548px}
.br-col{position:absolute;top:0}
.mc{position:absolute;left:0;right:0;background:var(--panel-2);border:1px solid var(--border);border-radius:10px;overflow:hidden;width:100%}
.mc-meta{font-size:8px;font-weight:600;color:var(--text-3);text-align:center;padding:3px 6px 2px;border-bottom:1px solid var(--border);letter-spacing:.3px;white-space:nowrap}
.mc-slot{display:flex;align-items:center;gap:7px;padding:6px 9px;cursor:pointer;transition:background var(--t) var(--ease),opacity var(--t) var(--ease);min-height:30px;user-select:none}
.mc-slot:not(.tbd):hover{background:rgba(255,255,255,.05)}
.mc-slot.tbd{cursor:default}
.mc-slot.winner{background:rgba(252,0,37,.10);border-left:2px solid var(--red)}
.mc-slot.loser{opacity:.32}
.mc-flag{font-size:14px;line-height:1;flex-shrink:0;width:18px;text-align:center}
.mc-name{font-size:10px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100px}
.mc-name.tbd-txt{color:var(--text-3);font-weight:400}
.mc-slot.winner .mc-name{color:var(--red)}
.mc-div{height:1px;background:var(--border);margin:0}
.final-col{position:absolute;top:0;display:flex;flex-direction:column;justify-content:center;gap:14px}
.fc-final{background:var(--panel-2);border:1px solid rgba(252,0,37,.25);border-radius:var(--radius);overflow:hidden}
.fc-final-label{font-size:10px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;text-align:center;color:var(--red);padding:8px 10px 6px;border-bottom:1px solid var(--border)}
.champ-area{padding:8px 4px 4px;text-align:center}
.champ-trophy{font-size:20px;margin-bottom:2px}
.champ-name{font-size:11px;font-weight:700;color:var(--amber)}
.champ-empty{font-size:10px;color:var(--text-3);padding:4px 0}
.fc-third{background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.fc-third-label{font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;text-align:center;color:var(--text-3);padding:6px 10px 4px;border-bottom:1px solid var(--border)}
#bsvg{position:absolute;top:0;left:0;width:100%;pointer-events:none;overflow:visible}
.save-bar{display:flex;align-items:center;gap:12px;margin-top:18px;flex-wrap:wrap}
.btn-save{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border-radius:var(--radius-sm);background:var(--red);border:none;color:#fff;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:filter var(--t) var(--ease)}
.btn-save:hover{filter:brightness(1.1)}
.btn-save:disabled{opacity:.5;cursor:not-allowed}
.btn-reset{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;border-radius:var(--radius-sm);background:transparent;border:1px solid rgba(255,255,255,.12);color:var(--text-2);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease)}
.btn-reset:hover{border-color:rgba(252,0,37,.35);color:var(--red)}
.save-msg{font-size:12px;color:var(--text-2)}
.save-msg.ok{color:var(--green)}
.save-msg.err{color:var(--red)}
.tab-nav{display:flex;gap:8px;margin-bottom:20px}
.tab-btn{padding:8px 20px;border-radius:99px;background:var(--panel);border:1px solid var(--border);color:var(--text-2);font-size:12px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease);font-family:var(--font)}
.tab-btn:hover{border-color:var(--border-md);color:var(--text)}
.tab-btn.active{background:var(--red);border-color:var(--red);color:#fff}
.tab-panel{display:none}
.tab-panel.active{display:block}
.rank-table{width:100%;border-collapse:collapse}
.rank-table th{padding:11px 16px;font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-3);border-bottom:1px solid var(--border);background:var(--panel-2)}
.rank-table td{padding:12px 16px;font-size:13px;border-bottom:1px solid var(--border);vertical-align:middle}
.rank-table tr:last-child td{border-bottom:none}
.rank-table tbody tr:hover{background:var(--panel-2)}
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
      <div class="hero-sub">Clique em um time para avançá-lo à próxima fase. Salve seu palpite ao terminar.</div>
    </div>
  </div>

  <div class="content">

    <div class="tab-nav">
      <button class="tab-btn active" onclick="switchTab('bracket',this)"><i class="bi bi-diagram-3"></i> Meu Palpite</button>
      <button class="tab-btn" onclick="switchTab('ranking',this)"><i class="bi bi-bar-chart-fill"></i> Ranking</button>
    </div>

    <div id="tabBracket" class="tab-panel active">
    <div class="progress-wrap">
      <div class="progress-bar-outer"><div class="progress-bar-inner" id="progressBar" style="width:0%"></div></div>
      <span class="progress-text" id="progressText">0 / 31 palpites</span>
    </div>

    <div class="bracket-outer">
      <!-- Round labels — inside the scroll wrapper so they align -->
      <div class="round-labels" id="roundLabels">
        <div class="round-label-cell" style="width:152px">Segundas de Final</div>
        <div class="round-label-gap" style="width:20px"></div>
        <div class="round-label-cell" style="width:152px">Oitavas de Final</div>
        <div class="round-label-gap" style="width:20px"></div>
        <div class="round-label-cell" style="width:152px">Quartas de Final</div>
        <div class="round-label-gap" style="width:20px"></div>
        <div class="round-label-cell" style="width:152px">Semifinal</div>
        <div class="round-label-gap" style="width:20px"></div>
        <div class="round-label-cell final" style="width:172px">Final</div>
        <div class="round-label-gap" style="width:20px"></div>
        <div class="round-label-cell" style="width:152px">Semifinal</div>
        <div class="round-label-gap" style="width:20px"></div>
        <div class="round-label-cell" style="width:152px">Quartas de Final</div>
        <div class="round-label-gap" style="width:20px"></div>
        <div class="round-label-cell" style="width:152px">Oitavas de Final</div>
        <div class="round-label-gap" style="width:20px"></div>
        <div class="round-label-cell" style="width:152px">Segundas de Final</div>
      </div>

      <!-- Bracket -->
      <div class="bracket-wrap" id="bracketWrap">
        <!-- Columns rendered by JS -->
        <svg id="bsvg" xmlns="http://www.w3.org/2000/svg"></svg>
      </div>
    </div>

    <div class="save-bar">
      <button class="btn-save" id="btnSave" onclick="savePicks(false)">
        <i class="bi bi-send-fill"></i> Salvar Palpite
      </button>
      <?php if ($isAdmin): ?>
      <button class="btn-save" id="btnSaveOfficial" onclick="savePicks(true)" style="background:var(--amber);margin-left:2px">
        <i class="bi bi-clipboard-check-fill"></i> Salvar Resultado Oficial
      </button>
      <?php endif; ?>
      <button class="btn-reset" onclick="resetPicks()">
        <i class="bi bi-arrow-counterclockwise"></i> Limpar
      </button>
      <span class="save-msg" id="saveMsg"></span>
    </div>
    </div><!-- #tabBracket -->

    <div id="tabRanking" class="tab-panel">
      <?php if (empty($copaRanking)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--text-3)">
          <i class="bi bi-inbox" style="font-size:32px;display:block;margin-bottom:12px"></i>
          Nenhum palpite enviado ainda. Seja o primeiro!
        </div>
      <?php else: ?>
        <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:24px">
          <div style="padding:14px 18px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px">
            <span style="font-size:20px">⚽</span>
            <div>
              <div style="font-weight:700;font-size:14px">Bolão Copa do Mundo 2026</div>
              <div style="font-size:11px;color:var(--text-3)"><?= empty($officialArr) ? 'Resultados oficiais ainda não cadastrados' : count($officialArr).' acertos possíveis' ?> · <?= count($copaRanking) ?> participantes</div>
            </div>
          </div>
          <div style="overflow-x:auto">
            <table class="rank-table">
              <thead>
                <tr>
                  <th style="width:44px">#</th>
                  <th>Participante</th>
                  <th>Campeão Apostado</th>
                  <th style="text-align:right">Pontos</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($copaRanking as $i => $r): ?>
                <tr>
                  <td style="font-weight:800;color:<?= $i===0?'var(--amber)':($i===1?'#94a3b8':($i===2?'#b45309':'var(--text-3)')) ?>">
                    <?= $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':($i+1))) ?>
                  </td>
                  <td>
                    <div style="font-weight:600"><?= htmlspecialchars($r['uname']) ?></div>
                    <?php if ($r['team']): ?>
                    <div style="font-size:11px;color:var(--text-3)"><?= htmlspecialchars($r['team']) ?><?= $r['league'] ? ' · '.$r['league'] : '' ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($r['champion']): ?>
                      <span style="font-weight:700;color:var(--amber)"><?= htmlspecialchars($ptNamesPHP[$r['champion']] ?? $r['champion']) ?></span>
                      <?php if ($officialArr && isset($officialArr['J104']) && $officialArr['J104'] === $r['champion']): ?>
                        <span style="font-size:10px;color:var(--green);margin-left:4px">✓</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span style="font-size:12px;color:var(--text-3);font-style:italic">Não preenchido</span>
                    <?php endif; ?>
                  </td>
                  <td style="text-align:right;font-size:16px;font-weight:800;color:<?= $r['pts']>0?'var(--red)':'var(--text-3)' ?>">
                    <?= $r['pts'] ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div><!-- #tabRanking -->

  </div>
</main>
</div><!-- .app -->

<script>
// ═══════════════════════════════════════════════
// DATA
// ═══════════════════════════════════════════════
const FLAGS = {
  GER:'🇩🇪',PAR:'🇵🇾',FRA:'🇫🇷',SWE:'🇸🇪',RSA:'🇿🇦',CAN:'🇨🇦',NED:'🇳🇱',MAR:'🇲🇦',
  POR:'🇵🇹',CRO:'🇭🇷',ESP:'🇪🇸',AUT:'🇦🇹',USA:'🇺🇸',BIH:'🇧🇦',BEL:'🇧🇪',SEN:'🇸🇳',
  BRA:'🇧🇷',JPN:'🇯🇵',CIV:'🇨🇮',NOR:'🇳🇴',MEX:'🇲🇽',ECU:'🇪🇨',ENG:'🏴󠁧󠁢󠁥󠁮󠁧󠁿',COD:'🇨🇩',
  ARG:'🇦🇷',CPV:'🇨🇻',AUS:'🇦🇺',EGY:'🇪🇬',SUI:'🇨🇭',ALG:'🇩🇿',COL:'🇨🇴',GHA:'🇬🇭',
};
const PT = {
  GER:'Alemanha',PAR:'Paraguai',FRA:'França',SWE:'Suécia',
  RSA:'África do Sul',CAN:'Canadá',NED:'Holanda',MAR:'Marrocos',
  POR:'Portugal',CRO:'Croácia',ESP:'Espanha',AUT:'Áustria',
  USA:'EUA',BIH:'Bósnia',BEL:'Bélgica',SEN:'Senegal',
  BRA:'Brasil',JPN:'Japão',CIV:'C. do Marfim',NOR:'Noruega',
  MEX:'México',ECU:'Equador',ENG:'Inglaterra',COD:'RD Congo',
  ARG:'Argentina',CPV:'Cabo Verde',AUS:'Austrália',EGY:'Egito',
  SUI:'Suíça',ALG:'Argélia',COL:'Colômbia',GHA:'Gana',
};
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const OFFICIAL_PICKS = <?= $officialPicks ?>;

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

// R32 fixed teams
const R32 = {
  J73:['RSA','CAN'],J74:['GER','PAR'],J75:['NED','MAR'],J76:['BRA','JPN'],
  J77:['FRA','SWE'],J78:['CIV','NOR'],J79:['MEX','ECU'],J80:['ENG','COD'],
  J81:['USA','BIH'],J82:['BEL','SEN'],J83:['POR','CRO'],J84:['ESP','AUT'],
  J85:['SUI','ALG'],J86:['ARG','CPV'],J87:['COL','GHA'],J88:['AUS','EGY'],
};

// Feeds into each game: [top_feeder, bottom_feeder]
const FEEDS = {
  J89:['J74','J77'],J90:['J73','J75'],
  J91:['J76','J78'],J92:['J79','J80'],
  J93:['J83','J84'],J94:['J81','J82'],
  J95:['J86','J88'],J96:['J85','J87'],
  J97:['J89','J90'],J98:['J93','J94'],
  J99:['J91','J92'],J100:['J95','J96'],
  J101:['J97','J98'],J102:['J99','J100'],
  J104:['J101','J102'],
  J103:['J101_L','J102_L'],
};

// Downstream: what each game's winner feeds into
const INTO = {
  J73:{g:'J90',s:1},J74:{g:'J89',s:0},J75:{g:'J90',s:1},J76:{g:'J91',s:0},
  J77:{g:'J89',s:1},J78:{g:'J91',s:1},J79:{g:'J92',s:0},J80:{g:'J92',s:1},
  J81:{g:'J94',s:0},J82:{g:'J94',s:1},J83:{g:'J93',s:0},J84:{g:'J93',s:1},
  J85:{g:'J96',s:0},J86:{g:'J95',s:0},J87:{g:'J96',s:1},J88:{g:'J95',s:1},
  J89:{g:'J97',s:0},J90:{g:'J97',s:1},
  J91:{g:'J99',s:0},J92:{g:'J99',s:1},
  J93:{g:'J98',s:0},J94:{g:'J98',s:1},
  J95:{g:'J100',s:0},J96:{g:'J100',s:1},
  J97:{g:'J101',s:0},J98:{g:'J101',s:1},
  J99:{g:'J102',s:0},J100:{g:'J102',s:1},
  J101:{g:'J104',s:0},J102:{g:'J104',s:1},
};

// ═══════════════════════════════════════════════
// LAYOUT CONSTANTS (absolute positions in px)
// Card height = 76px. Slot height for R32 = 88px.
// Bracket height = 8 * 88 = 704px.
// R32 top[i] = i*88 + 6
// R32 center[i] = i*88 + 6 + 38 = i*88 + 44
// R16 top[i]: center between R32[2i] and R32[2i+1]
//   R16 center[i] = ((2i)*88+44 + (2i+1)*88+44)/2 = 2i*88+44+44 = 176i+88
//   R16 top[i] = 176i+88-38 = 176i+50
// QF top[i]:
//   QF center[i] = (R16 center[2i] + R16 center[2i+1])/2 = (176*2i+88 + 176*(2i+1)+88)/2 = 352i+176
//   QF top[i] = 352i+176-38 = 352i+138
// SF:
//   SF center = (QF center[0]+QF center[1])/2 = (176+528)/2 = 352
//   SF top = 352-38 = 314
// ═══════════════════════════════════════════════
const CARD_H = 76;
const SLOT_H = 88;
const BRKT_H = 704;

// Column X positions (left edge)
const COL_W  = 152;  // regular column width
const COL_FW = 172;  // final column width
const GAP    = 20;
const COL_X = {
  lr32: 0,
  lr16: COL_W + GAP,                       // 172
  lqf:  2*(COL_W + GAP),                   // 344
  lsf:  3*(COL_W + GAP),                   // 516
  fin:  4*(COL_W + GAP),                   // 688
  rsf:  4*(COL_W + GAP) + COL_FW + GAP,   // 880
  rqf:  5*(COL_W + GAP) + COL_FW,         // 1052
  rr16: 6*(COL_W + GAP) + COL_FW,         // 1224
  rr32: 7*(COL_W + GAP) + COL_FW,         // 1396
};

// Card Y positions per round (top of card)
function r32Top(i) { return i * SLOT_H + 6; }
function r16Top(i) { return 176*i + 50; }
function qfTop(i)  { return 352*i + 138; }
const SF_TOP = 314;

// Which games go in which column, and their index within that column
const COL_GAMES = {
  lr32: ['J74','J77','J73','J75','J83','J84','J81','J82'],
  lr16: ['J89','J90','J93','J94'],
  lqf:  ['J97','J98'],
  lsf:  ['J101'],
  rsf:  ['J102'],
  rqf:  ['J99','J100'],
  rr16: ['J91','J92','J95','J96'],
  rr32: ['J76','J78','J79','J80','J86','J88','J85','J87'],
};

function gameTop(colKey, idx) {
  switch(colKey) {
    case 'lr32': case 'rr32': return r32Top(idx);
    case 'lr16': case 'rr16': return r16Top(idx);
    case 'lqf':  case 'rqf':  return qfTop(idx);
    case 'lsf':  case 'rsf':  return SF_TOP;
    default: return 0;
  }
}

// ═══════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════
let picks = <?= $myPicks ?>;
// Descartar picks com códigos inválidos (palpites de fase de grupos ou versão antiga)
(function(){
    const valid = new Set(Object.keys(PT));
    Object.keys(picks).forEach(k => { if (picks[k] && !valid.has(picks[k])) delete picks[k]; });
})();
const TOTAL = 31;

function getTeams(gid) {
  if (R32[gid]) return [...R32[gid]];
  const f = FEEDS[gid];
  if (!f) return [null,null];
  return [resolveTeam(f[0]), resolveTeam(f[1])];
}

function resolveTeam(ref) {
  if (!ref) return null;
  if (ref.endsWith('_L')) {
    const g = ref.replace('_L','');
    const [a,b] = getTeams(g);
    const w = picks[g];
    if (!w) return null;
    return w === a ? b : a;
  }
  return picks[ref] || null;
}

function pick(gid, team) {
  const old = picks[gid];
  if (old === team) { delete picks[gid]; clearDown(gid, old); }
  else { clearDown(gid, old); picks[gid] = team; }
  render();
}

function clearDown(gid, old) {
  if (!old) return;
  const info = INTO[gid];
  if (!info) return;
  if (picks[info.g] === old) { clearDown(info.g, old); delete picks[info.g]; }
}

function resetPicks() {
  if (!confirm('Limpar todos os palpites?')) return;
  picks = {};
  render();
}

// ═══════════════════════════════════════════════
// RENDER
// ═══════════════════════════════════════════════
function slotHtml(gid, code, winner) {
  if (!code) {
    return `<div class="mc-slot tbd"><span class="mc-flag"></span><span class="mc-name tbd-txt">A definir</span></div>`;
  }
  const flag = FLAGS[code] || '';
  const name = PT[code] || code;
  let cls = '';
  if (winner) cls = (winner === code) ? ' winner' : ' loser';
  return `<div class="mc-slot${cls}" onclick="pick('${gid}','${code}')"><span class="mc-flag">${flag}</span><span class="mc-name">${name}</span></div>`;
}

function matchCardHtml(gid, topPx, widthPx) {
  const [t0, t1] = getTeams(gid);
  const w = picks[gid] || null;
  const info = GINFO[gid] || {};
  return `<div class="mc" data-gid="${gid}" style="top:${topPx}px;width:${widthPx}px">
    <div class="mc-meta">${gid} · ${info.d||''} ${info.t||''}</div>
    ${slotHtml(gid,t0,w)}
    <div class="mc-div"></div>
    ${slotHtml(gid,t1,w)}
  </div>`;
}

function finalColHtml(xPx, hPx) {
  // Final card (J104)
  const [f0,f1] = getTeams('J104');
  const wf = picks['J104']||null;
  const champion = wf;
  const champArea = champion
    ? `<div class="champ-area"><div class="champ-trophy">🏆</div><div class="champ-name">${FLAGS[champion]||''} ${PT[champion]||champion}</div></div>`
    : `<div class="champ-area"><div class="champ-empty">Campeão</div></div>`;

  const finInfo = GINFO['J104']||{};
  const finalHtml = `<div class="fc-final" data-gid="J104">
    <div class="fc-final-label">🏆 Final · ${finInfo.d||''}</div>
    ${champArea}
    <div class="mc-div"></div>
    ${slotHtml('J104',f0,wf)}
    <div class="mc-div"></div>
    ${slotHtml('J104',f1,wf)}
  </div>`;

  // 3rd place card (J103)
  const [l0,l1] = getTeams('J103');
  const w3 = picks['J103']||null;
  const thirdInfo = GINFO['J103']||{};
  const thirdHtml = `<div class="fc-third" data-gid="J103">
    <div class="fc-third-label">3º Lugar · ${thirdInfo.d||''} ${thirdInfo.t||''}</div>
    ${slotHtml('J103',l0,w3)}
    <div class="mc-div"></div>
    ${slotHtml('J103',l1,w3)}
  </div>`;

  return `<div class="final-col" style="left:${xPx}px;width:${COL_FW}px;height:${hPx}px">
    ${finalHtml}
    ${thirdHtml}
  </div>`;
}

function render() {
  const wrap = document.getElementById('bracketWrap');
  if (!wrap) return;

  let html = `<svg id="bsvg" xmlns="http://www.w3.org/2000/svg" style="position:absolute;top:0;left:0;width:1548px;height:${BRKT_H}px;pointer-events:none;overflow:visible"></svg>`;

  // Regular columns
  for (const [colKey, games] of Object.entries(COL_GAMES)) {
    const x = COL_X[colKey];
    html += `<div class="br-col" style="left:${x}px;width:${COL_W}px;height:${BRKT_H}px">`;
    games.forEach((gid, idx) => {
      html += matchCardHtml(gid, gameTop(colKey, idx), COL_W);
    });
    html += '</div>';
  }

  // Final center column
  html += finalColHtml(COL_X.fin, BRKT_H);

  wrap.innerHTML = html;
  wrap.style.height = BRKT_H + 'px';

  updateProgress();
  drawConnectors();
}

function updateProgress() {
  const done = Object.keys(picks).length;
  const pct = Math.round(done/TOTAL*100);
  const bar = document.getElementById('progressBar');
  const txt = document.getElementById('progressText');
  if (bar) bar.style.width = pct+'%';
  if (txt) txt.textContent = `${done} / ${TOTAL} palpites`;
}

// ═══════════════════════════════════════════════
// SVG CONNECTORS
// ═══════════════════════════════════════════════
// Precomputed centers for each game
function gameCenter(gid) {
  for (const [colKey, games] of Object.entries(COL_GAMES)) {
    const idx = games.indexOf(gid);
    if (idx === -1) continue;
    const x = COL_X[colKey];
    const y = gameTop(colKey, idx) + CARD_H/2;
    return { x, y, w: COL_W, right: x+COL_W, left: x, cx: x+COL_W/2 };
  }
  return null;
}

function sfCenter(side) {
  const colKey = side === 'left' ? 'lsf' : 'rsf';
  const x = COL_X[colKey];
  return { x, y: SF_TOP + CARD_H/2, w: COL_W, right: x+COL_W, left: x };
}

// [topGame, bottomGame, resultGame, direction]
// direction: 'right' = left bracket (connectors go rightward)
//            'left'  = right bracket (connectors go leftward)
const CONN_PAIRS = [
  // Left bracket
  ['J74','J77','J89','right'],['J73','J75','J90','right'],
  ['J83','J84','J93','right'],['J81','J82','J94','right'],
  ['J89','J90','J97','right'],['J93','J94','J98','right'],
  ['J97','J98','J101','right'],
  // Right bracket
  ['J76','J78','J91','left'],['J79','J80','J92','left'],
  ['J86','J88','J95','left'],['J85','J87','J96','left'],
  ['J91','J92','J99','left'],['J95','J96','J100','left'],
  ['J99','J100','J102','left'],
  // SF → Final
  ['J101','J102','J104','center'],
];

function drawConnectors() {
  const svg = document.getElementById('bsvg');
  if (!svg) return;
  svg.innerHTML = '';

  const C_IDLE = 'rgba(255,255,255,.09)';
  const C_LIVE = 'rgba(252,0,37,.40)';

  function ln(x1,y1,x2,y2,color) {
    const l = document.createElementNS('http://www.w3.org/2000/svg','line');
    l.setAttribute('x1',x1); l.setAttribute('y1',y1);
    l.setAttribute('x2',x2); l.setAttribute('y2',y2);
    l.setAttribute('stroke',color); l.setAttribute('stroke-width','1.5');
    svg.appendChild(l);
  }

  for (const [g1, g2, gR, dir] of CONN_PAIRS) {
    const A = gameCenter(g1) || (g1==='J101'?sfCenter('left'):g1==='J102'?sfCenter('right'):null);
    const B = gameCenter(g2) || (g2==='J102'?sfCenter('right'):null);
    if (!A || !B) continue;

    let R_x, R_y, R_right, R_left;
    if (gR === 'J104') {
      // Final center column
      R_x = COL_X.fin;
      R_y = SF_TOP + CARD_H/2; // centered same as SF
      R_right = COL_X.fin + COL_FW;
      R_left = COL_X.fin;
    } else {
      const RC = gameCenter(gR);
      if (!RC) continue;
      R_x = RC.x; R_y = RC.y; R_right = RC.right; R_left = RC.left;
    }

    const hasBoth = !!picks[g1] && !!picks[g2];
    const col = hasBoth ? C_LIVE : C_IDLE;
    const Ay = A.y, By = B.y;

    if (dir === 'right') {
      const midX = A.right + GAP/2;
      ln(A.right, Ay, midX, Ay, col);
      ln(midX, Ay, midX, By, col);
      ln(A.right, By, midX, By, col);
      ln(midX, (Ay+By)/2, R_left, R_y, col);
    } else if (dir === 'left') {
      const midX = A.left - GAP/2;
      ln(A.left, Ay, midX, Ay, col);
      ln(midX, Ay, midX, By, col);
      ln(A.left, By, midX, By, col);
      ln(midX, (Ay+By)/2, R_right, R_y, col);
    } else {
      // center: J101 (left SF) → Final, J102 (right SF) → Final
      const sfL = sfCenter('left');
      const sfR = sfCenter('right');
      const finMidX = COL_X.fin + COL_FW/2;
      const finY = SF_TOP + CARD_H/2;
      const colc = (picks['J101']&&picks['J102']) ? C_LIVE : C_IDLE;
      ln(sfL.right, sfL.y, finMidX, finY, colc);
      ln(sfR.left,  sfR.y, finMidX, finY, colc);
    }
  }
}

// ═══════════════════════════════════════════════
// SAVE
// ═══════════════════════════════════════════════
async function savePicks(official) {
  const btnId = official ? 'btnSaveOfficial' : 'btnSave';
  const btn = document.getElementById(btnId);
  const msg = document.getElementById('saveMsg');
  if (btn) btn.disabled = true;
  msg.textContent = 'Salvando…';
  msg.className = 'save-msg';
  try {
    const payload = official ? {picks, official: true} : {picks};
    const res = await fetch('', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const json = await res.json();
    if (json.success) {
      msg.textContent = official ? '✓ Resultado oficial salvo!' : '✓ Palpite salvo!';
      msg.className = 'save-msg ok';
    } else { msg.textContent = 'Erro ao salvar.'; msg.className = 'save-msg err'; }
  } catch(e) {
    msg.textContent = 'Erro de conexão.'; msg.className = 'save-msg err';
  } finally {
    if (btn) btn.disabled = false;
    setTimeout(() => { msg.textContent=''; msg.className='save-msg'; }, 4000);
  }
}

// ═══════════════════════════════════════════════
// TABS
// ═══════════════════════════════════════════════
function switchTab(name, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab' + name.charAt(0).toUpperCase() + name.slice(1)).classList.add('active');
  btn.classList.add('active');
}

// ═══════════════════════════════════════════════
// SIDEBAR
// ═══════════════════════════════════════════════
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sbOverlay');
const menuBtn = document.getElementById('menuBtn');
if (menuBtn) menuBtn.addEventListener('click', ()=>{ sidebar.classList.add('open'); overlay.classList.add('show'); });
if (overlay) overlay.addEventListener('click', ()=>{ sidebar.classList.remove('open'); overlay.classList.remove('show'); });

// ═══════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════
render();
</script>
<script src="/js/pwa.js"></script>
</body>
</html>
