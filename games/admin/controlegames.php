<?php
// controlegames.php - CONTROLE DE JOGOS (DOBRO DE MOEDAS)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

$stmt = $pdo->prepare("SELECT is_admin, nome, pontos, fba_points, COALESCE(numero_tapas, 0) as numero_tapas FROM usuarios WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['is_admin'] != 1) {
    die("Acesso negado: Area restrita a administradores.");
}

$mensagem = '';
$msgType = 'success';
$gameKeys = ['memoria', 'termo', 'flappy', 'pinguim', 'ai'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        $stmtUp = $pdo->prepare("INSERT INTO fba_game_controls (game_key, is_double) VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE is_double = VALUES(is_double)");

        foreach ($gameKeys as $key) {
            $val = isset($_POST['double'][$key]) ? (int)$_POST['double'][$key] : 0;
            $stmtUp->execute([':k' => $key, ':v' => ($val === 1 ? 1 : 0)]);
        }

        $pdo->commit();
        $mensagem = 'Configurações salvas com sucesso.';
        $msgType = 'success';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensagem = 'Erro ao salvar: ' . htmlspecialchars($e->getMessage());
        $msgType = 'danger';
    }
}

$config = array_fill_keys($gameKeys, 0);
try {
    $stmtCfg = $pdo->query("SELECT game_key, is_double FROM fba_game_controls");
    while ($row = $stmtCfg->fetch(PDO::FETCH_ASSOC)) {
        $config[$row['game_key']] = (int)$row['is_double'];
    }
} catch (Exception $e) {
}

$labelMap = [
    'memoria' => ['label' => 'Memória', 'desc' => 'Vitória = 200 moedas', 'icon' => 'bi-grid-3x3-gap-fill'],
    'termo'   => ['label' => 'Termo',   'desc' => 'Vitória = 200 moedas', 'icon' => 'bi-alphabet'],
    'flappy'  => ['label' => 'Flappy',  'desc' => 'Dobrar moedas',        'icon' => 'bi-wind'],
    'pinguim' => ['label' => 'Pinguim', 'desc' => 'Dobrar moedas',        'icon' => 'bi-snow2'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#fc0025">
  <title>Controle de Jogos - FBA Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --red:        #fc0025;
      --red-soft:   rgba(252,0,37,.10);
      --bg:         #07070a;
      --panel:      #101013;
      --panel-2:    #16161a;
      --panel-3:    #1c1c21;
      --border:     rgba(255,255,255,.06);
      --border-md:  rgba(255,255,255,.10);
      --border-red: rgba(252,0,37,.22);
      --text:       #f0f0f3;
      --text-2:     #868690;
      --text-3:     #48484f;
      --amber:      #f59e0b;
      --green:      #22c55e;
      --font:       'Poppins', sans-serif;
      --radius:     14px;
      --radius-sm:  10px;
      --ease:       cubic-bezier(.2,.8,.2,1);
      --t:          200ms;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

    .topbar {
      position: sticky; top: 0; z-index: 300; height: 58px;
      background: var(--panel); border-bottom: 1px solid var(--border);
      display: flex; align-items: center; padding: 0 24px; gap: 16px;
    }
    .topbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; flex-shrink: 0; }
    .topbar-logo {
      width: 32px; height: 32px; border-radius: 8px; background: var(--red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 12px; color: #fff;
    }
    .topbar-name { font-weight: 800; font-size: 15px; color: var(--text); }
    .topbar-name span { color: var(--red); }
    .topbar-spacer { flex: 1; }
    .balance-chip {
      display: flex; align-items: center; gap: 6px;
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: 999px; padding: 5px 12px;
      font-size: 12px; font-weight: 700; color: var(--text);
    }
    .balance-chip i { color: var(--red); }
    .icon-btn {
      width: 32px; height: 32px; border-radius: 8px;
      background: transparent; border: 1px solid var(--border);
      color: var(--text-2); display: flex; align-items: center; justify-content: center;
      font-size: 14px; cursor: pointer; text-decoration: none;
      transition: all var(--t) var(--ease);
    }
    .icon-btn:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

    .main { max-width: 700px; margin: 0 auto; padding: 28px 20px 60px; }

    .section-label {
      display: flex; align-items: center; gap: 8px;
      font-size: 10px; font-weight: 700; letter-spacing: 1.2px;
      text-transform: uppercase; color: var(--text-3);
      margin-bottom: 18px; margin-top: 28px;
    }
    .section-label i { color: var(--red); font-size: 13px; }

    .fba-alert {
      display: flex; align-items: center; gap: 10px;
      padding: 12px 16px; border-radius: var(--radius-sm);
      font-size: 13px; font-weight: 500; margin-bottom: 20px;
    }
    .fba-alert.success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.2); color: #4ade80; }
    .fba-alert.danger  { background: rgba(252,0,37,.1);  border: 1px solid var(--border-red); color: #ff6680; }

    .panel-card {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); overflow: hidden;
    }
    .panel-head {
      padding: 14px 18px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 10px;
      font-size: 13px; font-weight: 700;
    }
    .panel-head i { color: var(--red); }
    .panel-body { padding: 18px; }

    .game-row {
      display: flex; align-items: center; justify-content: space-between;
      padding: 14px 0; border-bottom: 1px solid var(--border);
    }
    .game-row:last-of-type { border-bottom: none; padding-bottom: 0; }
    .game-row:first-of-type { padding-top: 0; }
    .game-meta { display: flex; align-items: center; gap: 12px; }
    .game-icon {
      width: 38px; height: 38px; border-radius: 10px;
      background: var(--panel-2); border: 1px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      font-size: 16px; color: var(--text-2); flex-shrink: 0;
    }
    .game-label { font-size: 13px; font-weight: 600; color: var(--text); }
    .game-desc  { font-size: 11px; color: var(--text-3); margin-top: 2px; }

    /* Toggle switch */
    .toggle-wrap { display: flex; align-items: center; gap: 10px; }
    .toggle-status { font-size: 11px; font-weight: 700; color: var(--text-3); transition: color var(--t); }
    .toggle-status.on { color: var(--green); }
    .switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider {
      position: absolute; cursor: pointer; inset: 0;
      background: var(--panel-3); border: 1px solid var(--border-md);
      border-radius: 999px; transition: all var(--t) var(--ease);
    }
    .slider:before {
      content: ''; position: absolute;
      height: 16px; width: 16px; left: 4px; bottom: 3px;
      background: var(--text-3); border-radius: 50%;
      transition: all var(--t) var(--ease);
    }
    input:checked + .slider { background: rgba(34,197,94,.2); border-color: rgba(34,197,94,.3); }
    input:checked + .slider:before { transform: translateX(20px); background: var(--green); }

    .btn-save {
      background: var(--red); color: #fff; border: none;
      border-radius: var(--radius-sm); padding: 10px 24px;
      font-family: var(--font); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: opacity var(--t) var(--ease);
      display: flex; align-items: center; gap: 8px;
    }
    .btn-save:hover { opacity: .85; }

    /* ── Sidebar Layout ── */
    .page-layout { display: flex; min-height: 100vh; }
    .sidebar {
      width: 240px; flex-shrink: 0;
      background: var(--panel); border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      position: fixed; top: 0; left: 0; bottom: 0; z-index: 200;
      overflow-y: auto;
    }
    .page-content { flex: 1; margin-left: 240px; min-width: 0; }
    .sb-header {
      display: flex; align-items: center; gap: 10px;
      padding: 16px 14px 12px; border-bottom: 1px solid var(--border); flex-shrink: 0;
    }
    .sb-logo {
      width: 30px; height: 30px; border-radius: 8px; background: var(--red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 11px; color: #fff; flex-shrink: 0;
    }
    .sb-brand { font-weight: 800; font-size: 13px; color: var(--text); flex: 1; }
    .sb-brand span { color: var(--red); }
    .sb-close { display: none; background: none; border: none; color: var(--text-2); font-size: 18px; cursor: pointer; padding: 4px; }
    .sb-user { padding: 14px; border-bottom: 1px solid var(--border); }
    .sb-avatar {
      width: 40px; height: 40px; border-radius: 50%;
      background: var(--red-soft); border: 2px solid var(--border-red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 15px; color: var(--red); margin-bottom: 8px;
    }
    .sb-user-name { font-size: 13px; font-weight: 700; color: var(--text); }
    .sb-user-role { font-size: 10px; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
    .sb-stats { padding: 8px 14px; border-bottom: 1px solid var(--border); display: flex; flex-direction: column; }
    .sb-stat { display: flex; align-items: center; gap: 10px; padding: 7px 0; }
    .sb-stat i { width: 16px; text-align: center; font-size: 12px; color: var(--red); flex-shrink: 0; }
    .sb-stat-info { display: flex; flex-direction: column; }
    .sb-stat-val { font-size: 13px; font-weight: 700; color: var(--text); line-height: 1.2; }
    .sb-stat-label { font-size: 10px; color: var(--text-3); }
    .sb-nav { flex: 1; padding: 8px 0; overflow-y: auto; }
    .sb-nav-section { font-size: 9px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 8px 14px 4px; }
    .sb-link {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 14px; text-decoration: none;
      font-size: 12px; font-weight: 500; color: var(--text-2);
      transition: all var(--t) var(--ease); border-left: 3px solid transparent;
    }
    .sb-link i { width: 16px; text-align: center; font-size: 13px; }
    .sb-link:hover { background: var(--panel-2); color: var(--text); border-left-color: var(--border-md); }
    .sb-link.active { background: var(--red-soft); color: var(--red); border-left-color: var(--red); font-weight: 700; }
    .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); flex-shrink: 0; }
    .sb-logout {
      display: flex; align-items: center; gap: 8px;
      width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border);
      background: transparent; color: var(--text-2); text-decoration: none;
      font-family: var(--font); font-size: 12px; font-weight: 600;
      transition: all var(--t) var(--ease);
    }
    .sb-logout:hover { background: rgba(252,0,37,.1); border-color: var(--border-red); color: var(--red); }
    .mob-bar {
      display: none; align-items: center; gap: 12px;
      height: 52px; padding: 0 14px;
      background: var(--panel); border-bottom: 1px solid var(--border);
      position: sticky; top: 0; z-index: 100;
    }
    .mob-ham {
      background: none; border: 1px solid var(--border); border-radius: 8px;
      color: var(--text); width: 34px; height: 34px;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; font-size: 16px; flex-shrink: 0;
    }
    .mob-title { font-size: 14px; font-weight: 800; color: var(--text); flex: 1; }
    .mob-title span { color: var(--red); }
    .mob-back {
      width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border);
      background: transparent; color: var(--text-2);
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; text-decoration: none; transition: all var(--t) var(--ease);
    }
    .mob-back:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }
    .sb-overlay {
      display: none; position: fixed; inset: 0; background: rgba(0,0,0,.6);
      z-index: 199; backdrop-filter: blur(2px);
    }
    @media (max-width: 768px) {
      .sidebar { transform: translateX(-100%); transition: transform 280ms var(--ease); }
      .sidebar.open { transform: translateX(0); }
      .page-content { margin-left: 0; }
      .mob-bar { display: flex; }
      .sb-close { display: flex; align-items: center; justify-content: center; }
      .sb-overlay.open { display: block; }
    }
  </style>
</head>
<body>

<div class="page-layout">
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-logo">FBA</div>
    <span class="sb-brand">FBA <span>Admin</span></span>
    <button class="sb-close" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr($user['nome'] ?? 'A', 0, 1)) ?></div>
    <div class="sb-user-name"><?= htmlspecialchars($user['nome'] ?? '') ?></div>
    <div class="sb-user-role">Admin</div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat">
      <i class="bi bi-hand-index-fill" style="color:var(--green)"></i>
      <div class="sb-stat-info">
        <div class="sb-stat-val"><?= number_format($user['numero_tapas'] ?? 0, 0, ',', '.') ?></div>
        <div class="sb-stat-label">Tapas</div>
      </div>
    </div>
    <div class="sb-stat">
      <i class="bi bi-coin"></i>
      <div class="sb-stat-info">
        <div class="sb-stat-val"><?= number_format($user['pontos'] ?? 0, 0, ',', '.') ?></div>
        <div class="sb-stat-label">Moedas</div>
      </div>
    </div>
    <div class="sb-stat">
      <i class="bi bi-gem" style="color:var(--amber)"></i>
      <div class="sb-stat-info">
        <div class="sb-stat-val"><?= number_format($user['fba_points'] ?? 0, 0, ',', '.') ?></div>
        <div class="sb-stat-label">FBA Points</div>
      </div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a href="../index.clean.php" class="sb-link">
      <i class="bi bi-lightning-charge"></i>Apostas
    </a>
    <a href="../index.clean.php" class="sb-link">
      <i class="bi bi-joystick"></i>Games
    </a>
    <a href="../user/ranking-geral.php" class="sb-link">
      <i class="bi bi-trophy"></i>Ranking Geral
    </a>
    <div class="sb-nav-section">Admin</div>
    <a href="controlegames.php" class="sb-link active">
      <i class="bi bi-gear-fill"></i>Controle de Jogos
    </a>
    <a href="dashboard.php" class="sb-link">
      <i class="bi bi-grid-fill"></i>Dashboard
    </a>
  </nav>
  <div class="sb-footer">
    <a href="../auth/logout.php" class="sb-logout">
      <i class="bi bi-box-arrow-right"></i>Sair
    </a>
  </div>
</aside>

<div class="page-content">
<div class="mob-bar">
  <button class="mob-ham" onclick="openSidebar()"><i class="bi bi-list"></i></button>
  <span class="mob-title">FBA <span>Admin</span></span>
  <a href="../index.clean.php" class="mob-back" title="Voltar"><i class="bi bi-arrow-left"></i></a>
</div>

<div class="main">

  <?php if ($mensagem): ?>
  <div class="fba-alert <?= $msgType ?>">
    <i class="bi bi-<?= $msgType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
    <?= $mensagem ?>
  </div>
  <?php endif; ?>

  <div class="section-label" style="margin-top:0"><i class="bi bi-gear-fill"></i>Controle de Jogos</div>

  <div class="panel-card">
    <div class="panel-head">
      <i class="bi bi-toggles"></i>
      Dobro de moedas por jogo
    </div>
    <div class="panel-body">
      <form method="POST">
        <?php foreach ($labelMap as $key => $meta): ?>
        <div class="game-row">
          <div class="game-meta">
            <div class="game-icon"><i class="bi <?= $meta['icon'] ?>"></i></div>
            <div>
              <div class="game-label"><?= htmlspecialchars($meta['label']) ?></div>
              <div class="game-desc"><?= htmlspecialchars($meta['desc']) ?></div>
            </div>
          </div>
          <div class="toggle-wrap">
            <span class="toggle-status <?= $config[$key] ? 'on' : '' ?>" id="status-<?= $key ?>">
              <?= $config[$key] ? 'ATIVO' : 'OFF' ?>
            </span>
            <label class="switch">
              <input type="checkbox" name="double[<?= $key ?>]" value="1"
                     <?= $config[$key] ? 'checked' : '' ?>
                     onchange="updateStatus(this,'<?= $key ?>')">
              <span class="slider"></span>
            </label>
          </div>
        </div>
        <?php endforeach; ?>

        <div style="margin-top:20px">
          <button type="submit" class="btn-save">
            <i class="bi bi-save-fill"></i> Salvar configurações
          </button>
        </div>
      </form>
    </div>
  </div>

</div>

</div><!-- /page-content -->
</div><!-- /page-layout -->

<script>
function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sbOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sbOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
function updateStatus(input, key) {
  const span = document.getElementById('status-' + key);
  span.textContent = input.checked ? 'ATIVO' : 'OFF';
  span.classList.toggle('on', input.checked);
}
</script>
</body>
</html>
