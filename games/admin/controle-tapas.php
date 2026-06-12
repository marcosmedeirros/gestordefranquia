<?php
session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

$stmt = $pdo->prepare("SELECT is_admin, nome, pontos, fba_points, COALESCE(numero_tapas, 0) as numero_tapas FROM usuarios WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['is_admin'] != 1) {
    die("Acesso negado: Area restrita a administradores.");
}

$hiddenEmailLower = 'marcoscemd@gmail.com';

// =========================================================================
// ENDPOINTS AJAX
// =========================================================================
if (isset($_GET['ajax_tapas'])) {
    $stmtTapas = $pdo->prepare("SELECT id, nome, numero_tapas FROM usuarios WHERE numero_tapas > 0 AND LOWER(email) <> ? ORDER BY numero_tapas DESC, nome ASC");
    $stmtTapas->execute([$hiddenEmailLower]);
    $usuarios_tapas = $stmtTapas->fetchAll(PDO::FETCH_ASSOC);
    $stmtAllUsers = $pdo->prepare("SELECT id, nome FROM usuarios WHERE LOWER(email) <> ? ORDER BY nome ASC");
    $stmtAllUsers->execute([$hiddenEmailLower]);
    $todos_usuarios = $stmtAllUsers->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['usuarios_tapas' => $usuarios_tapas, 'todos_usuarios' => $todos_usuarios]);
    exit;
}

if (isset($_POST['admin_tapa_action']) && isset($_POST['ajax'])) {
    $msg = '';
    if ($_POST['admin_tapa_action'] === 'remover' && !empty($_POST['remover_id'])) {
        $id = (int)$_POST['remover_id'];
        $pdo->prepare("UPDATE usuarios SET numero_tapas = GREATEST(numero_tapas-1,0) WHERE id = ?")->execute([$id]);
        $msg = 'Tapa removido!';
    }
    if ($_POST['admin_tapa_action'] === 'adicionar' && !empty($_POST['adicionar_id'])) {
        $id = (int)$_POST['adicionar_id'];
        $pdo->prepare("UPDATE usuarios SET numero_tapas = COALESCE(numero_tapas,0)+1 WHERE id = ?")->execute([$id]);
        $msg = 'Tapa adicionado!';
    }
    header('Content-Type: application/json');
    echo json_encode(['msg' => $msg]);
    exit;
}
// =========================================================================
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#fc0025">
  <title>Controle de Tapas - FBA Admin</title>
	<link rel="icon" type="image/png" href="/games/fbagames.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --red:        #fc0025;
      --red-soft:   rgba(252,0,37,.10);
      --red-glow:   rgba(252,0,37,.18);
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

    .tapa-list { list-style: none; padding: 0; margin-bottom: 20px; }
    .tapa-item {
      display: flex; align-items: center; justify-content: space-between;
      padding: 10px 14px; border-radius: var(--radius-sm);
      background: var(--panel-2); border: 1px solid var(--border);
      margin-bottom: 6px; font-size: 13px;
    }
    .tapa-badge {
      background: rgba(252,0,37,.12); color: #ff6680;
      border: 1px solid var(--border-red);
      padding: 2px 8px; border-radius: 999px;
      font-size: 11px; font-weight: 700;
    }
    .btn-danger-sm {
      background: rgba(239,68,68,.15); color: #f87171;
      border: 1px solid rgba(239,68,68,.2); border-radius: var(--radius-sm);
      padding: 5px 10px; font-size: 12px; font-weight: 600;
      cursor: pointer; font-family: var(--font);
      transition: all var(--t) var(--ease);
    }
    .btn-danger-sm:hover { background: rgba(239,68,68,.25); }

    .fba-select {
      background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: var(--radius-sm); padding: 8px 12px;
      color: var(--text); font-family: var(--font); font-size: 13px;
      outline: none; cursor: pointer;
    }
    .btn-red {
      background: var(--red); color: #fff; border: none;
      border-radius: var(--radius-sm); padding: 8px 16px;
      font-family: var(--font); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: opacity var(--t) var(--ease);
      display: flex; align-items: center; gap: 6px;
    }
    .btn-red:hover { opacity: .85; }

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
    .sb-user-name { font-size: 13px; font-weight: 700; color: #fff; }
    .sb-user-role { font-size: 10px; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
    .sb-stats { padding: 10px 14px; border-bottom: 1px solid var(--border); display: flex; flex-direction: row; gap: 6px; }
    .sb-stat { display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; gap: 4px; padding: 8px 4px; background: var(--panel-2); border-radius: 8px; border: 1px solid var(--border); }
    .sb-stat i { font-size: 13px; color: var(--red); }
    .sb-stat-val { font-size: 12px; font-weight: 700; color: var(--text); line-height: 1.2; }
    .sb-stat-label { font-size: 9px; color: var(--text-3); }
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
    .mob-chips { display: flex; align-items: center; gap: 6px; }
    .mob-chip { display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 20px; background: var(--panel-2); border: 1px solid var(--border); font-size: 11px; font-weight: 700; color: var(--text); white-space: nowrap; }
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
    <div class="sb-user-name"><?= htmlspecialchars($user['nome'] ?? '') ?></div>
    <div class="sb-user-role">Admin</div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat">
      <i class="bi bi-hand-index-fill" style="color:var(--green)"></i>
      <div class="sb-stat-val"><?= number_format($user['numero_tapas'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Tapas</div>
    </div>
    <div class="sb-stat">
      <img src="../moeda.png" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">
      <div class="sb-stat-val"><?= number_format($user['pontos'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Moedas</div>
    </div>
    <div class="sb-stat">
      <img src="../lebron.png" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">
      <div class="sb-stat-val"><?= number_format($user['fba_points'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">FBA Pts</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a href="../index.php" class="sb-link"><i class="bi bi-lightning-charge"></i>Apostas</a>
    <a href="../games.php" class="sb-link"><i class="bi bi-joystick"></i>Games</a>
    <a href="../copa26.php" class="sb-link"><i class="bi bi-trophy-fill"></i>Copa 2026</a>
    <a href="../user/ranking-geral.php" class="sb-link"><i class="bi bi-trophy"></i>Ranking Geral</a>
    <div class="sb-nav-section">Admin</div>
    <a href="controlegames.php" class="sb-link"><i class="bi bi-gear-fill"></i>Controle de Jogos</a>
    <a href="dashboard.php" class="sb-link"><i class="bi bi-receipt-cutoff"></i>Controle Apostas</a>
    <a href="controle-financas.php" class="sb-link"><i class="bi bi-cash-coin"></i>Controle Finanças</a>
    <a href="controle-tapas.php" class="sb-link active"><i class="bi bi-hand-index-thumb-fill"></i>Controle de Tapas</a>
    <a href="controle-pontuacao.php" class="sb-link"><i class="bi bi-coin"></i>Controle Pontuação</a>
    <a href="dadosjogadores.php" class="sb-link"><i class="bi bi-person-lines-fill"></i>Dados dos Jogadores</a>
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
  <div class="mob-chips">
    <span class="mob-chip"><img src="../moeda.png" style="width:14px;height:14px;object-fit:contain;vertical-align:middle"><?= number_format($user['pontos'] ?? 0, 0, ',', '.') ?></span>
    <span class="mob-chip"><img src="../lebron.png" style="width:14px;height:14px;object-fit:contain;vertical-align:middle"><?= number_format($user['fba_points'] ?? 0, 0, ',', '.') ?></span>
  </div>
  <a href="../index.php" class="mob-back" title="Voltar"><i class="bi bi-arrow-left"></i></a>
</div>

<div class="main">

  <div class="section-label" style="margin-top:0"><i class="bi bi-hand-index-thumb-fill"></i>Controle de Tapas</div>

  <div id="tapa-msg"></div>

  <div class="panel-card">
    <div class="panel-head" style="justify-content:space-between">
      <span style="display:flex;align-items:center;gap:8px"><i class="bi bi-list-ul"></i>Usuários com tapas</span>
      <div style="position:relative">
        <i class="bi bi-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:12px;pointer-events:none"></i>
        <input type="text" id="searchTapas" placeholder="Buscar por nome..." oninput="filtrarTapas()"
          style="background:var(--panel-3);border:1px solid var(--border-md);border-radius:8px;padding:6px 10px 6px 30px;color:var(--text);font-family:var(--font);font-size:12px;width:200px;outline:none"
          onfocus="this.style.borderColor='var(--red)'" onblur="this.style.borderColor='var(--border-md)'">
      </div>
    </div>
    <div class="panel-body">
      <ul class="tapa-list" id="lista-tapas">
        <li class="tapa-item" style="color:var(--text-3)">Carregando...</li>
      </ul>
    </div>
  </div>

  <div class="panel-card" style="margin-top:16px">
    <div class="panel-head" style="justify-content:space-between">
      <span style="display:flex;align-items:center;gap:8px"><i class="bi bi-plus-circle"></i>Adicionar tapa</span>
      <div style="position:relative">
        <i class="bi bi-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:12px;pointer-events:none"></i>
        <input type="text" id="searchAdicionar" placeholder="Filtrar usuário..." oninput="filtrarSelect()"
          style="background:var(--panel-3);border:1px solid var(--border-md);border-radius:8px;padding:6px 10px 6px 30px;color:var(--text);font-family:var(--font);font-size:12px;width:200px;outline:none"
          onfocus="this.style.borderColor='var(--red)'" onblur="this.style.borderColor='var(--border-md)'">
      </div>
    </div>
    <div class="panel-body">
      <form id="form-add-tapa" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <select name="adicionar_id" id="adicionar_id" class="fba-select" required style="min-width:200px">
          <option value="">Carregando...</option>
        </select>
        <button type="submit" class="btn-red"><i class="bi bi-plus"></i>Adicionar</button>
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

async function fetchTapasAdmin() {
  const res = await fetch('controle-tapas.php?ajax_tapas=1');
  const data = await res.json();
  const lista = document.getElementById('lista-tapas');
  lista.innerHTML = '';
  if (!data.usuarios_tapas.length) {
    lista.innerHTML = '<li class="tapa-item" style="color:var(--text-3)">Nenhum usuário com tapas.</li>';
  } else {
    data.usuarios_tapas.forEach(u => {
      const li = document.createElement('li');
      li.className = 'tapa-item';
      li.innerHTML = `<span>${u.nome} <span class="tapa-badge">👋 ${u.numero_tapas}</span></span>
        <button class="btn-danger-sm" onclick="removerTapa(${u.id})"><i class="bi bi-dash"></i> Remover</button>`;
      lista.appendChild(li);
    });
  }
  const sel = document.getElementById('adicionar_id');
  sel.innerHTML = '<option value="">Selecione o usuário</option>';
  data.todos_usuarios.forEach(u => { sel.innerHTML += `<option value="${u.id}">${u.nome}</option>`; });
}

async function removerTapa(id) {
  const res = await fetch('controle-tapas.php', {
    method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `admin_tapa_action=remover&remover_id=${id}&ajax=1`
  });
  const data = await res.json();
  document.getElementById('tapa-msg').innerHTML = `<div class="fba-alert success"><i class="bi bi-check-circle"></i> ${data.msg}</div>`;
  fetchTapasAdmin();
}

document.getElementById('form-add-tapa').addEventListener('submit', async function(e) {
  e.preventDefault();
  const id = document.getElementById('adicionar_id').value;
  if (!id) return;
  const res = await fetch('controle-tapas.php', {
    method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `admin_tapa_action=adicionar&adicionar_id=${id}&ajax=1`
  });
  const data = await res.json();
  document.getElementById('tapa-msg').innerHTML = `<div class="fba-alert success"><i class="bi bi-check-circle"></i> ${data.msg}</div>`;
  fetchTapasAdmin();
});

fetchTapasAdmin();

function filtrarTapas() {
  const q = document.getElementById('searchTapas').value.toLowerCase().trim();
  document.querySelectorAll('#lista-tapas .tapa-item').forEach(li => {
    const nome = li.querySelector('span')?.textContent?.toLowerCase() ?? '';
    li.style.display = !q || nome.includes(q) ? '' : 'none';
  });
}

function filtrarSelect() {
  const q = document.getElementById('searchAdicionar').value.toLowerCase().trim();
  const sel = document.getElementById('adicionar_id');
  Array.from(sel.options).forEach(opt => {
    if (!opt.value) return;
    opt.hidden = q && !opt.textContent.toLowerCase().includes(q);
  });
  if (sel.selectedOptions[0]?.hidden) sel.value = '';
}
</script>
</body>
</html>
