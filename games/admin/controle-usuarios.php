<?php
session_start();
require '../core/conexao.php';
/** @var PDO $pdo */

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

$stmt = $pdo->prepare("SELECT is_admin, nome, pontos, fba_points, COALESCE(numero_tapas, 0) as numero_tapas FROM usuarios WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$admin || $admin['is_admin'] != 1) die("Acesso negado.");

// ── AJAX ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'adicionar') {
            $uid   = (int)$_POST['user_id'];
            $tipo  = $_POST['tipo'] === 'fba' ? 'fba_points' : 'pontos';
            $valor = (int)$_POST['valor'];
            if ($uid <= 0 || $valor === 0) throw new Exception("Dados inválidos.");
            $col = $tipo === 'fba_points' ? 'fba_points' : 'pontos';
            $pdo->prepare("UPDATE usuarios SET {$col} = {$col} + :v WHERE id = :id")
                ->execute([':v' => $valor, ':id' => $uid]);
            $row = $pdo->prepare("SELECT pontos, fba_points FROM usuarios WHERE id = :id");
            $row->execute([':id' => $uid]);
            echo json_encode(['ok' => true, 'saldo' => $row->fetch(PDO::FETCH_ASSOC)]);
            exit;
        }

        if ($acao === 'editar') {
            $uid    = (int)$_POST['user_id'];
            $pontos = (int)$_POST['pontos'];
            $fba    = (int)$_POST['fba_points'];
            if ($uid <= 0) throw new Exception("Usuário inválido.");
            $pdo->prepare("UPDATE usuarios SET pontos = :p, fba_points = :f WHERE id = :id")
                ->execute([':p' => $pontos, ':f' => $fba, ':id' => $uid]);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'toggle_admin') {
            $uid = (int)$_POST['user_id'];
            $val = (int)$_POST['valor'];
            if ($uid <= 0) throw new Exception("Usuário inválido.");
            if ($uid === (int)$_SESSION['user_id']) throw new Exception("Você não pode remover seu próprio acesso admin.");
            $pdo->prepare("UPDATE usuarios SET is_admin = :v WHERE id = :id")
                ->execute([':v' => ($val ? 1 : 0), ':id' => $uid]);
            echo json_encode(['ok' => true]);
            exit;
        }

        throw new Exception("Ação desconhecida.");
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
        exit;
    }
}

// ── Carrega usuários ───────────────────────────────────────────────────────────
$usuarios = $pdo->query(
    "SELECT id, nome, email, pontos, fba_points, is_admin FROM usuarios ORDER BY nome ASC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#fc0025">
  <title>Controle de Usuários - FBA Admin</title>
	<link rel="icon" type="image/png" href="/games/fbagames.png">
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
      --blue:       #3b82f6;
      --font:       'Poppins', sans-serif;
      --radius:     14px;
      --radius-sm:  10px;
      --ease:       cubic-bezier(.2,.8,.2,1);
      --t:          200ms;
      --sb-w:       220px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

    /* ── Sidebar ── */
    .page-layout { display: flex; min-height: 100vh; }
    .sidebar { width: 240px; flex-shrink: 0; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; z-index: 200; overflow-y: auto; }
    .page-content { flex: 1; margin-left: 240px; min-width: 0; }
    .sb-header { display: flex; align-items: center; gap: 10px; padding: 16px 14px 12px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
    .sb-logo { width: 30px; height: 30px; border-radius: 8px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 11px; color: #fff; flex-shrink: 0; }
    .sb-brand { font-weight: 800; font-size: 13px; color: var(--text); flex: 1; }
    .sb-brand span { color: var(--red); }
    .sb-close { display: none; background: none; border: none; color: var(--text-2); font-size: 18px; cursor: pointer; padding: 4px; }
    .sb-user { padding: 14px; border-bottom: 1px solid var(--border); }
    .sb-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--red-soft); border: 2px solid var(--border-red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 15px; color: var(--red); margin-bottom: 8px; }
    .sb-user-name { font-size: 13px; font-weight: 700; color: #fff; }
    .sb-user-role { font-size: 10px; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
    .sb-stats { padding: 10px 14px; border-bottom: 1px solid var(--border); display: flex; flex-direction: row; gap: 6px; }
    .sb-stat { display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; gap: 4px; padding: 8px 4px; background: var(--panel-2); border-radius: 8px; border: 1px solid var(--border); }
    .sb-stat i { font-size: 13px; color: var(--red); }
    .sb-stat-val { font-size: 12px; font-weight: 700; color: var(--text); line-height: 1.2; }
    .sb-stat-label { font-size: 9px; color: var(--text-3); }
    .sb-nav { flex: 1; padding: 8px 0; overflow-y: auto; }
    .sb-nav-section { font-size: 9px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 8px 14px 4px; }
    .sb-link { display: flex; align-items: center; gap: 10px; padding: 9px 14px; text-decoration: none; font-size: 12px; font-weight: 500; color: var(--text-2); transition: all var(--t) var(--ease); border-left: 3px solid transparent; }
    .sb-link i { width: 16px; text-align: center; font-size: 13px; }
    .sb-link:hover { background: var(--panel-2); color: var(--text); border-left-color: var(--border-md); }
    .sb-link.active { background: var(--red-soft); color: var(--red); border-left-color: var(--red); font-weight: 700; }
    .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); flex-shrink: 0; }
    .sb-logout { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text-2); text-decoration: none; font-family: var(--font); font-size: 12px; font-weight: 600; transition: all var(--t) var(--ease); }
    .sb-logout:hover { background: rgba(252,0,37,.1); border-color: var(--border-red); color: var(--red); }
    .mob-bar { display: none; align-items: center; gap: 12px; height: 52px; padding: 0 14px; background: var(--panel); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; }
    .mob-ham { background: none; border: 1px solid var(--border); border-radius: 8px; color: var(--text); width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; flex-shrink: 0; }
    .mob-title { font-size: 14px; font-weight: 800; color: var(--text); flex: 1; }
    .mob-title span { color: var(--red); }
    .mob-chips { display: flex; align-items: center; gap: 6px; }
    .mob-chip { display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 20px; background: var(--panel-2); border: 1px solid var(--border); font-size: 11px; font-weight: 700; color: var(--text); white-space: nowrap; }
    .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 199; backdrop-filter: blur(2px); }
    @media (max-width: 768px) {
      .sidebar { transform: translateX(-100%); transition: transform 280ms var(--ease); }
      .sidebar.open { transform: translateX(0); }
      .page-content { margin-left: 0; }
      .mob-bar { display: flex; }
      .sb-close { display: flex; align-items: center; justify-content: center; }
      .sb-overlay.open { display: block; }
    }

    /* ── Content ── */
    .page-content-inner { padding: 28px 28px 60px; max-width: 980px; }
    @media (max-width: 768px) { .page-content-inner { padding: 16px 16px 48px; } }
    .page-header { margin-bottom: 28px; }
    .page-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
    .page-title { font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
    .page-title i { color: var(--red); }

    /* ── Adicionador ── */
    .adder-card {
      background: var(--panel); border: 1px solid var(--border-red);
      border-radius: var(--radius); padding: 20px 22px; margin-bottom: 28px;
    }
    .adder-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--red); margin-bottom: 14px; display: flex; align-items: center; gap: 6px; }
    .adder-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .adder-field { display: flex; flex-direction: column; gap: 5px; }
    .adder-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: var(--text-3); }
    .adder-select, .adder-input {
      background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: var(--radius-sm); color: var(--text); font-family: var(--font);
      font-size: 13px; font-weight: 500; padding: 9px 12px;
      transition: border-color var(--t) var(--ease); outline: none;
    }
    .adder-select:focus, .adder-input:focus { border-color: var(--red); }
    .adder-select { min-width: 220px; }
    .adder-input  { width: 120px; }
    .tipo-toggle { display: flex; gap: 0; border-radius: var(--radius-sm); overflow: hidden; border: 1px solid var(--border-md); }
    .tipo-btn { padding: 9px 16px; font-size: 12px; font-weight: 700; cursor: pointer; border: none; background: var(--panel-2); color: var(--text-2); transition: all var(--t) var(--ease); }
    .tipo-btn.active.fba   { background: rgba(245,158,11,.15); color: var(--amber); }
    .tipo-btn.active.moeda { background: rgba(34,197,94,.12);  color: var(--green); }
    .btn-add {
      display: flex; align-items: center; gap: 7px;
      background: var(--red); color: #fff; border: none; border-radius: var(--radius-sm);
      padding: 9px 18px; font-size: 13px; font-weight: 700; cursor: pointer;
      font-family: var(--font); transition: opacity var(--t) var(--ease);
    }
    .btn-add:hover { opacity: .85; }
    .adder-feedback { font-size: 12px; font-weight: 600; margin-top: 8px; display: none; }
    .adder-feedback.ok  { color: var(--green); }
    .adder-feedback.err { color: #f87171; }

    /* ── Tabela ── */
    .section-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: var(--text-3); margin-bottom: 14px; display: flex; align-items: center; gap: 6px; }
    .section-label i { color: var(--red); font-size: 12px; }
    .search-bar { position: relative; margin-bottom: 14px; }
    .search-bar input {
      width: 100%; background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: var(--radius-sm); color: var(--text); font-family: var(--font);
      font-size: 13px; padding: 9px 12px 9px 36px; outline: none;
      transition: border-color var(--t) var(--ease);
    }
    .search-bar input:focus { border-color: var(--red); }
    .search-bar i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-3); font-size: 14px; pointer-events: none; }
    .users-table-wrap { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .users-table { width: 100%; border-collapse: collapse; }
    .users-table thead th {
      background: var(--panel-2); font-size: 10px; font-weight: 700;
      text-transform: uppercase; letter-spacing: .8px; color: var(--text-3);
      padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border);
    }
    .users-table thead th.right { text-align: right; }
    .users-table thead th.center { text-align: center; }
    .users-table tbody tr { border-bottom: 1px solid var(--border); transition: background var(--t) var(--ease); }
    .users-table tbody tr:last-child { border-bottom: none; }
    .users-table tbody tr:hover { background: var(--panel-2); }
    .users-table td { padding: 10px 14px; font-size: 13px; vertical-align: middle; }
    .user-name  { font-weight: 700; color: var(--text); }
    .user-email { font-size: 11px; color: var(--text-3); margin-top: 1px; }
    .saldo-input {
      background: var(--panel-3); border: 1px solid var(--border);
      border-radius: 8px; color: var(--text); font-family: var(--font);
      font-size: 13px; font-weight: 700; padding: 5px 9px;
      width: 90px; text-align: right; outline: none;
      transition: border-color var(--t) var(--ease);
    }
    .saldo-input:focus { border-color: var(--red); background: var(--panel-2); }
    .saldo-input.fba:focus { border-color: var(--amber); }
    .btn-save-row {
      padding: 5px 12px; border-radius: 8px; border: none; cursor: pointer;
      font-size: 11px; font-weight: 700; font-family: var(--font);
      background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red);
      transition: all var(--t) var(--ease); opacity: 0; pointer-events: none;
    }
    .btn-save-row.visible { opacity: 1; pointer-events: auto; }
    .btn-save-row:hover { background: var(--red); color: #fff; }
    .saved-flash { font-size: 11px; font-weight: 700; color: var(--green); margin-left: 6px; opacity: 0; transition: opacity .3s; }
    .saved-flash.show { opacity: 1; }

    /* ── Toggle Admin ── */
    .admin-toggle-wrap { display: flex; align-items: center; justify-content: center; gap: 7px; }
    .admin-toggle { position: relative; display: inline-block; width: 38px; height: 22px; flex-shrink: 0; }
    .admin-toggle input { opacity: 0; width: 0; height: 0; }
    .admin-slider {
      position: absolute; inset: 0; border-radius: 22px;
      background: var(--panel-3); border: 1px solid var(--border-md);
      cursor: pointer; transition: background var(--t) var(--ease), border-color var(--t) var(--ease);
    }
    .admin-slider::before {
      content: ''; position: absolute; width: 16px; height: 16px; border-radius: 50%;
      background: var(--text-3); left: 2px; top: 2px;
      transition: transform var(--t) var(--ease), background var(--t) var(--ease);
    }
    .admin-toggle input:checked + .admin-slider { background: rgba(252,0,37,.2); border-color: var(--border-red); }
    .admin-toggle input:checked + .admin-slider::before { transform: translateX(16px); background: var(--red); }
    .admin-toggle input:disabled + .admin-slider { opacity: .4; cursor: not-allowed; }
    .admin-badge { font-size: 10px; font-weight: 700; color: var(--red); letter-spacing: .4px; display: none; }
    .admin-toggle input:checked ~ .admin-badge { display: inline; }

    @media (max-width: 768px) {
      .adder-row { flex-direction: column; }
      .adder-select, .adder-input { min-width: 0; width: 100%; }
      .users-table thead { display: none; }
      .users-table tbody tr { display: block; padding: 12px 14px; }
      .users-table td { display: block; padding: 4px 0; border: none; }
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
    <div class="sb-user-name"><?= htmlspecialchars($admin['nome'] ?? '') ?></div>
    <div class="sb-user-role">Admin</div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat">
      <i class="bi bi-hand-index-fill" style="color:var(--green)"></i>
      <div class="sb-stat-val"><?= number_format($admin['numero_tapas'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Tapas</div>
    </div>
    <div class="sb-stat">
      <img src="../moeda.png" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">
      <div class="sb-stat-val"><?= number_format($admin['pontos'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Moedas</div>
    </div>
    <div class="sb-stat">
      <img src="../lebron.png" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">
      <div class="sb-stat-val"><?= number_format($admin['fba_points'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">FBA Pts</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a href="../index.php"              class="sb-link"><i class="bi bi-lightning-charge"></i>Apostas</a>
    <a href="../games.php"              class="sb-link"><i class="bi bi-joystick"></i>Games</a>
    <a href="../copa26.php"             class="sb-link"><i class="bi bi-trophy-fill"></i>Copa 2026</a>
    <a href="../user/ranking-geral.php" class="sb-link"><i class="bi bi-bar-chart-fill"></i>Ranking Geral</a>
    <div class="sb-nav-section">Admin</div>
    <a href="controlegames.php"         class="sb-link"><i class="bi bi-gear-fill"></i>Controle de Jogos</a>
    <a href="dashboard.php"             class="sb-link"><i class="bi bi-receipt-cutoff"></i>Controle Apostas</a>
    <a href="controle-financas.php"     class="sb-link"><i class="bi bi-cash-coin"></i>Controle Finanças</a>
    <a href="controle-tapas.php"        class="sb-link"><i class="bi bi-hand-index-thumb-fill"></i>Controle de Tapas</a>
    <a href="controle-usuarios.php"     class="sb-link active"><i class="bi bi-people-fill"></i>Controle de Usuários</a>
    <a href="dadosjogadores.php"        class="sb-link"><i class="bi bi-person-lines-fill"></i>Dados dos Jogadores</a>
  </nav>
  <div class="sb-footer">
    <a href="../auth/logout.php" class="sb-logout"><i class="bi bi-box-arrow-right"></i>Sair</a>
  </div>
</aside>

<div class="page-content">
<div class="mob-bar">
  <button class="mob-ham" onclick="openSidebar()"><i class="bi bi-list"></i></button>
  <span class="mob-title">FBA <span>Admin</span></span>
  <div class="mob-chips">
    <span class="mob-chip"><img src="../moeda.png" style="width:14px;height:14px;object-fit:contain;vertical-align:middle"><?= number_format($admin['pontos'] ?? 0, 0, ',', '.') ?></span>
    <span class="mob-chip"><img src="../lebron.png" style="width:14px;height:14px;object-fit:contain;vertical-align:middle"><?= number_format($admin['fba_points'] ?? 0, 0, ',', '.') ?></span>
  </div>
</div>

<div class="page-content-inner">
  <div class="page-header">
    <div class="page-eyebrow">Admin</div>
    <h1 class="page-title"><i class="bi bi-people-fill"></i> Controle de Usuários</h1>
  </div>

  <!-- ── Adicionador de saldo ── -->
  <div class="adder-card">
    <div class="adder-title"><i class="bi bi-plus-circle-fill"></i> Adicionar / Remover Saldo</div>
    <div class="adder-row">

      <div class="adder-field">
        <span class="adder-label">Usuário</span>
        <select id="addUser" class="adder-select">
          <option value="">Selecione um usuário…</option>
          <?php foreach ($usuarios as $u): ?>
          <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="adder-field">
        <span class="adder-label">Tipo</span>
        <div class="tipo-toggle">
          <button type="button" class="tipo-btn active fba"   id="btnFba"   onclick="setTipo('fba')">⭐ FBA Points</button>
          <button type="button" class="tipo-btn moeda"        id="btnMoeda" onclick="setTipo('moeda')">🪙 Moedas</button>
        </div>
      </div>

      <div class="adder-field">
        <span class="adder-label">Valor (use negativo para remover)</span>
        <input type="number" id="addValor" class="adder-input" placeholder="ex: 500" value="">
      </div>

      <div class="adder-field" style="justify-content:flex-end">
        <span class="adder-label">&nbsp;</span>
        <button class="btn-add" onclick="adicionarSaldo()">
          <i class="bi bi-plus-lg"></i> Aplicar
        </button>
      </div>

    </div>
    <div id="adderFeedback" class="adder-feedback"></div>
  </div>

  <!-- ── Lista de usuários ── -->
  <div class="section-label"><i class="bi bi-people-fill"></i> Usuários — <?= count($usuarios) ?> cadastrados</div>

  <div class="search-bar">
    <i class="bi bi-search"></i>
    <input type="text" id="searchInput" placeholder="Filtrar por nome ou e-mail…" oninput="filtrarTabela()">
  </div>

  <div class="users-table-wrap">
    <table class="users-table" id="usersTable">
      <thead>
        <tr>
          <th>Usuário</th>
          <th class="right"  style="width:120px">🪙 Moedas</th>
          <th class="right"  style="width:120px">⭐ FBA Points</th>
          <th class="center" style="width:90px">Admin</th>
          <th style="width:100px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usuarios as $u):
          $isSelf = ($u['id'] == $_SESSION['user_id']);
        ?>
        <tr data-uid="<?= $u['id'] ?>" data-name="<?= strtolower(htmlspecialchars($u['nome'])) ?>" data-email="<?= strtolower(htmlspecialchars($u['email'])) ?>">
          <td>
            <div class="user-name"><?= htmlspecialchars($u['nome']) ?></div>
            <div class="user-email"><?= htmlspecialchars($u['email']) ?></div>
          </td>
          <td style="text-align:right">
            <input type="number" class="saldo-input"     data-field="pontos"     value="<?= (int)$u['pontos'] ?>"     onchange="markDirty(this)" oninput="markDirty(this)">
          </td>
          <td style="text-align:right">
            <input type="number" class="saldo-input fba" data-field="fba_points" value="<?= (int)$u['fba_points'] ?>" onchange="markDirty(this)" oninput="markDirty(this)">
          </td>
          <td style="text-align:center">
            <div class="admin-toggle-wrap">
              <label class="admin-toggle">
                <input type="checkbox" <?= $u['is_admin'] ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>
                  onchange="toggleAdmin(this, <?= (int)$u['id'] ?>)">
                <span class="admin-slider"></span>
              </label>
              <?php if ($isSelf): ?>
                <span style="font-size:10px;color:var(--text-3)">você</span>
              <?php endif; ?>
            </div>
          </td>
          <td style="text-align:right">
            <button class="btn-save-row" onclick="salvarRow(this)">Salvar</button>
            <span class="saved-flash">✓ Salvo</span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div><!-- /page-content-inner -->
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

let tipoAtual = 'fba';

function setTipo(t) {
  tipoAtual = t;
  document.getElementById('btnFba').classList.toggle('active', t === 'fba');
  document.getElementById('btnMoeda').classList.toggle('active', t === 'moeda');
}

async function adicionarSaldo() {
  const uid   = document.getElementById('addUser').value;
  const valor = parseInt(document.getElementById('addValor').value, 10);

  if (!uid)              { showFeedback('Selecione um usuário.', false); return; }
  if (!valor || isNaN(valor)) { showFeedback('Informe um valor válido.', false); return; }

  const fd = new FormData();
  fd.append('ajax', '1');
  fd.append('acao', 'adicionar');
  fd.append('user_id', uid);
  fd.append('tipo', tipoAtual);
  fd.append('valor', valor);

  const res  = await fetch('controle-usuarios.php', { method: 'POST', body: fd });
  const data = await res.json();

  if (data.ok) {
    const row = document.querySelector(`tr[data-uid="${uid}"]`);
    if (row) {
      row.querySelector('[data-field="pontos"]').value     = data.saldo.pontos;
      row.querySelector('[data-field="fba_points"]').value = data.saldo.fba_points;
    }
    const label = tipoAtual === 'fba' ? '⭐ FBA Points' : '🪙 Moedas';
    const sinal = valor > 0 ? '+' : '';
    showFeedback(`${sinal}${valor} ${label} aplicados com sucesso.`, true);
    document.getElementById('addValor').value = '';
  } else {
    showFeedback(data.erro || 'Erro ao aplicar.', false);
  }
}

function showFeedback(msg, ok) {
  const el = document.getElementById('adderFeedback');
  el.textContent = msg;
  el.className   = 'adder-feedback ' + (ok ? 'ok' : 'err');
  el.style.display = 'block';
  clearTimeout(el._t);
  el._t = setTimeout(() => el.style.display = 'none', 4000);
}

function markDirty(input) {
  input.closest('tr').querySelector('.btn-save-row').classList.add('visible');
}

async function salvarRow(btn) {
  const row    = btn.closest('tr');
  const uid    = row.dataset.uid;
  const pontos = row.querySelector('[data-field="pontos"]').value;
  const fba    = row.querySelector('[data-field="fba_points"]').value;
  const flash  = row.querySelector('.saved-flash');

  const fd = new FormData();
  fd.append('ajax', '1');
  fd.append('acao', 'editar');
  fd.append('user_id', uid);
  fd.append('pontos', pontos);
  fd.append('fba_points', fba);

  btn.textContent = '…';
  const res  = await fetch('controle-usuarios.php', { method: 'POST', body: fd });
  const data = await res.json();

  if (data.ok) {
    btn.classList.remove('visible');
    btn.textContent = 'Salvar';
    flash.classList.add('show');
    clearTimeout(flash._t);
    flash._t = setTimeout(() => flash.classList.remove('show'), 2000);
  } else {
    btn.textContent = 'Erro';
    setTimeout(() => { btn.textContent = 'Salvar'; }, 2000);
  }
}

async function toggleAdmin(checkbox, uid) {
  const val = checkbox.checked ? 1 : 0;
  checkbox.disabled = true;

  const fd = new FormData();
  fd.append('ajax', '1');
  fd.append('acao', 'toggle_admin');
  fd.append('user_id', uid);
  fd.append('valor', val);

  const res  = await fetch('controle-usuarios.php', { method: 'POST', body: fd });
  const data = await res.json();

  if (!data.ok) {
    checkbox.checked = !checkbox.checked; // reverte
    alert(data.erro || 'Erro ao alterar permissão.');
  }
  checkbox.disabled = false;
}

function filtrarTabela() {
  const q = document.getElementById('searchInput').value.toLowerCase();
  document.querySelectorAll('#usersTable tbody tr').forEach(tr => {
    const match = tr.dataset.name.includes(q) || tr.dataset.email.includes(q);
    tr.style.display = match ? '' : 'none';
  });
}

document.getElementById('addValor').addEventListener('keydown', e => {
  if (e.key === 'Enter') adicionarSaldo();
});
</script>
</body>
</html>
