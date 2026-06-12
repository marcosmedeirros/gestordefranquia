<?php
session_start();
require '../core/conexao.php';
/** @var \PDO $pdo */
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

$user_id = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT nome, pontos, is_admin, fba_points, COALESCE(numero_tapas,0) as numero_tapas FROM usuarios WHERE id=?");
$stmt->execute([$user_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);
$isAdmin = !empty($usuario['is_admin']);

// Garante coluna
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN copa26_pago TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}

// ── POST: toggle pagamento ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Sem permissão']); exit; }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $act  = $body['action'] ?? '';
    if ($act === 'toggle_pago') {
        $uid  = (int)($body['user_id'] ?? 0);
        $pago = (int)($body['pago'] ?? 0) ? 1 : 0;
        $pdo->prepare("UPDATE usuarios SET copa26_pago=? WHERE id=?")->execute([$pago, $uid]);
        echo json_encode(['ok'=>true]);
    }
    exit;
}

// ── Carregar usuários ─────────────────────────────────────────────────────────
$participants = [];
try {
    $participants = $pdo->query("
        SELECT u.id, u.nome, u.email, u.copa26_pago,
               p.submitted_at, p.points, p.champion
        FROM usuarios u
        LEFT JOIN copa26_predictions p ON p.user_id = u.id
        ORDER BY u.copa26_pago DESC, p.submitted_at DESC, u.nome ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#fc0025">
<title>Controle Finanças · FBA Admin</title>
	<link rel="icon" type="image/png" href="/img/fbagames.png">
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
    --green:      #22c55e;
    --amber:      #f59e0b;
    --font:       'Poppins', sans-serif;
    --radius:     14px;
    --radius-sm:  10px;
    --ease:       cubic-bezier(.2,.8,.2,1);
    --t:          200ms;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

  .page-layout { display: flex; min-height: 100vh; }
  .sidebar {
    width: 240px; flex-shrink: 0;
    background: var(--panel); border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    position: fixed; top: 0; left: 0; bottom: 0; z-index: 200; overflow-y: auto;
  }
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
  .sb-stat-info { display: flex; flex-direction: column; align-items: center; }
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
  .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 199; backdrop-filter: blur(2px); }
  @media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); transition: transform 280ms var(--ease); }
    .sidebar.open { transform: translateX(0); }
    .page-content { margin-left: 0; }
    .mob-bar { display: flex; }
    .sb-close { display: flex; align-items: center; justify-content: center; }
    .sb-overlay.open { display: block; }
  }

  .main { max-width: 900px; margin: 0 auto; padding: 28px 24px 60px; }
  .section-label { display: flex; align-items: center; gap: 8px; font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); margin-bottom: 14px; margin-top: 28px; }
  .section-label i { color: var(--red); font-size: 13px; }

  .panel-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 16px; }
  .panel-head { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .panel-title { font-size: 13px; font-weight: 700; }

  .fin-table { width: 100%; border-collapse: collapse; }
  .fin-table th { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--text-3); padding: 10px 16px; border-bottom: 1px solid var(--border); text-align: left; }
  .fin-table td { padding: 12px 16px; border-bottom: 1px solid var(--border); font-size: 13px; color: var(--text-2); vertical-align: middle; }
  .fin-table tr:last-child td { border-bottom: none; }
  .fin-table tr:hover td { background: rgba(255,255,255,.015); }

  .pago-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
  .pago-badge.sim { background: rgba(34,197,94,.12); color: #22c55e; border: 1px solid rgba(34,197,94,.25); }
  .pago-badge.nao { background: rgba(255,255,255,.05); color: var(--text-3); border: 1px solid var(--border); }

  .toggle-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; font-family: var(--font); transition: all var(--t); }
  .toggle-btn.on { background: rgba(34,197,94,.15); color: #22c55e; border: 1px solid rgba(34,197,94,.3); }
  .toggle-btn.on:hover { background: rgba(34,197,94,.25); }
  .toggle-btn.off { background: var(--panel-2); color: var(--text-3); border: 1px solid var(--border); }
  .toggle-btn.off:hover { background: rgba(34,197,94,.1); color: #22c55e; border-color: rgba(34,197,94,.25); }

  .summary-bar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
  .summary-card { flex: 1; min-width: 140px; background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px; text-align: center; }
  .summary-val { font-size: 28px; font-weight: 800; color: var(--text); line-height: 1; }
  .summary-lbl { font-size: 11px; color: var(--text-3); margin-top: 4px; }

  .page-footer { text-align: center; padding: 24px; border-top: 1px solid var(--border); color: var(--text-3); font-size: 11px; margin-top: 40px; }

  @media (max-width: 768px) {
    .main { padding: 20px 16px 48px; }
    .fin-table th, .fin-table td { padding: 9px 10px; }
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
    <div class="sb-user-name"><?= htmlspecialchars($usuario['nome'] ?? '') ?></div>
    <div class="sb-user-role">Admin</div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat">
      <i class="bi bi-hand-index-fill" style="color:var(--green)"></i>
      <div class="sb-stat-val"><?= number_format($usuario['numero_tapas'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Tapas</div>
    </div>
    <div class="sb-stat">
      <img src="../moeda.png" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">
      <div class="sb-stat-val"><?= number_format($usuario['pontos'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Moedas</div>
    </div>
    <div class="sb-stat">
      <img src="../lebron.png" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">
      <div class="sb-stat-val"><?= number_format($usuario['fba_points'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">FBA Pts</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a href="../index.php" class="sb-link">
      <i class="bi bi-lightning-charge"></i>Apostas
    </a>
    <a href="../games.php" class="sb-link">
      <i class="bi bi-joystick"></i>Games
    </a>
    <a href="../copa26.php" class="sb-link">
      <i class="bi bi-trophy-fill"></i>Copa 2026
    </a>
    <a href="../user/ranking-geral.php" class="sb-link">
      <i class="bi bi-bar-chart-fill"></i>Ranking Geral
    </a>
    <div class="sb-nav-section">Admin</div>
    <a href="controlegames.php" class="sb-link">
      <i class="bi bi-gear-fill"></i>Controle de Jogos
    </a>
    <a href="dashboard.php" class="sb-link">
      <i class="bi bi-receipt-cutoff"></i>Controle Apostas
    </a>
    <a href="controle-financas.php" class="sb-link active">
      <i class="bi bi-cash-coin"></i>Controle Finanças
    </a>
    <a href="controle-tapas.php" class="sb-link">
      <i class="bi bi-hand-index-thumb-fill"></i>Controle de Tapas
    </a>
    <a href="controle-pontuacao.php" class="sb-link">
      <i class="bi bi-coin"></i>Controle Pontuação
    </a>
    <a href="dadosjogadores.php" class="sb-link">
      <i class="bi bi-person-lines-fill"></i>Dados dos Jogadores
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
  <span class="mob-title">Controle <span>Finanças</span></span>
</div>

<div class="main">

  <?php if (!$isAdmin): ?>
  <div style="text-align:center;padding:60px 20px">
    <div style="font-size:40px;margin-bottom:12px">🔒</div>
    <h2 style="font-size:18px;font-weight:700;color:var(--text)">Acesso restrito a administradores</h2>
  </div>
  <?php else: ?>

  <?php
  $total       = count($participants);
  $pagos       = count(array_filter($participants, fn($p) => $p['copa26_pago']));
  $submetidos  = count(array_filter($participants, fn($p) => $p['submitted_at']));
  $pagoSubm    = count(array_filter($participants, fn($p) => $p['copa26_pago'] && $p['submitted_at']));
  ?>

  <div class="section-label" style="margin-top:0"><i class="bi bi-cash-coin"></i>Bolão Copa 2026 · Pagamentos</div>

  <div class="summary-bar">
    <div class="summary-card">
      <div class="summary-val"><?= $total ?></div>
      <div class="summary-lbl">Total de usuários</div>
    </div>
    <div class="summary-card" style="border-color:rgba(34,197,94,.2)">
      <div class="summary-val" style="color:var(--green)"><?= $pagos ?></div>
      <div class="summary-lbl">Pagos / com acesso</div>
    </div>
    <div class="summary-card" style="border-color:rgba(59,130,246,.2)">
      <div class="summary-val" style="color:#3b82f6"><?= $submetidos ?></div>
      <div class="summary-lbl">Brackets enviados</div>
    </div>
    <div class="summary-card" style="border-color:rgba(245,158,11,.2)">
      <div class="summary-val" style="color:var(--amber)"><?= $pagoSubm ?></div>
      <div class="summary-lbl">Pagos + enviados (no ranking)</div>
    </div>
  </div>

  <div class="panel-card">
    <div class="panel-head">
      <span class="panel-title"><i class="bi bi-people-fill" style="color:var(--red);margin-right:7px"></i>Usuários</span>
      <div style="display:flex;align-items:center;gap:10px">
        <div style="position:relative">
          <i class="bi bi-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:12px;pointer-events:none"></i>
          <input type="text" id="searchNome" placeholder="Buscar por nome..." oninput="filtrarParticipants()"
            style="background:var(--panel-3);border:1px solid var(--border-md);border-radius:8px;padding:6px 10px 6px 30px;color:var(--text);font-family:var(--font);font-size:12px;width:200px;outline:none"
            onfocus="this.style.borderColor='var(--red)'" onblur="this.style.borderColor='var(--border-md)'">
        </div>
        <span style="font-size:11px;color:var(--text-3)" id="countLabel"><?= $total ?> usuários</span>
      </div>
    </div>
    <div style="overflow-x:auto">
      <table class="fin-table">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Bracket</th>
            <th>Campeão apostado</th>
            <th>Status</th>
            <th>Ação</th>
          </tr>
        </thead>
        <tbody id="participantsList">
        <?php foreach ($participants as $p): ?>
        <tr id="row_<?= $p['id'] ?>">
          <td>
            <span style="font-weight:600;color:var(--text)"><?= htmlspecialchars($p['nome']) ?></span>
            <div style="font-size:10px;color:var(--text-3)"><?= htmlspecialchars($p['email'] ?? '') ?></div>
          </td>
          <td>
            <?php if ($p['submitted_at']): ?>
              <span style="color:var(--green);font-weight:600;font-size:12px"><i class="bi bi-check-circle-fill"></i> Enviado</span>
              <div style="font-size:10px;color:var(--text-3)"><?= date('d/m/Y H:i', strtotime($p['submitted_at'])) ?></div>
            <?php else: ?>
              <span style="color:var(--text-3);font-size:12px">—</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:var(--text-3)"><?= htmlspecialchars($p['champion'] ?? '—') ?></td>
          <td>
            <span class="pago-badge <?= $p['copa26_pago'] ? 'sim' : 'nao' ?>" id="badge_<?= $p['id'] ?>">
              <i class="bi bi-<?= $p['copa26_pago'] ? 'check-circle-fill' : 'x-circle' ?>"></i>
              <?= $p['copa26_pago'] ? 'Pago' : 'Não pago' ?>
            </span>
          </td>
          <td>
            <button class="toggle-btn <?= $p['copa26_pago'] ? 'on' : 'off' ?>"
                    id="btn_<?= $p['id'] ?>"
                    onclick="togglePago(<?= $p['id'] ?>, <?= $p['copa26_pago'] ? 0 : 1 ?>)">
              <i class="bi bi-<?= $p['copa26_pago'] ? 'toggle-on' : 'toggle-off' ?>"></i>
              <?= $p['copa26_pago'] ? 'Revogar' : 'Confirmar pagamento' ?>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($participants)): ?>
        <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-3)">Nenhum usuário encontrado.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php endif; ?>

</div>

<div class="page-footer">
  <i class="bi bi-heart-fill" style="color:var(--red)"></i> FBA Games © 2026 — Administração
</div>
</div>
</div>

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

async function togglePago(uid, newVal) {
  const btn   = document.getElementById('btn_' + uid);
  const badge = document.getElementById('badge_' + uid);
  const orig  = btn.textContent.trim();
  btn.disabled = true;
  btn.textContent = '...';

  const res = await fetch('controle-financas.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'toggle_pago', user_id: uid, pago: newVal })
  }).then(r => r.json()).catch(() => ({ ok: false }));

  if (res.ok) {
    const pago = newVal === 1;
    badge.className = 'pago-badge ' + (pago ? 'sim' : 'nao');
    badge.innerHTML = `<i class="bi bi-${pago ? 'check-circle-fill' : 'x-circle'}"></i> ${pago ? 'Pago' : 'Não pago'}`;
    btn.className = 'toggle-btn ' + (pago ? 'on' : 'off');
    btn.innerHTML = `<i class="bi bi-toggle-${pago ? 'on' : 'off'}"></i> ${pago ? 'Revogar' : 'Confirmar pagamento'}`;
    btn.setAttribute('onclick', `togglePago(${uid}, ${pago ? 0 : 1})`);
    btn.disabled = false;
    showToast(pago ? 'Pagamento confirmado!' : 'Acesso revogado.');
  } else {
    btn.disabled = false;
    btn.textContent = orig;
    showToast('Erro ao atualizar.', true);
  }
}

function filtrarParticipants() {
  const q = document.getElementById('searchNome').value.toLowerCase().trim();
  const rows = document.querySelectorAll('#participantsList tr');
  let visible = 0;
  rows.forEach(row => {
    const nome = row.querySelector('td span')?.textContent?.toLowerCase() ?? '';
    const show = !q || nome.includes(q);
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  const lbl = document.getElementById('countLabel');
  if (lbl) lbl.textContent = visible + ' usuário' + (visible !== 1 ? 's' : '');
}

function showToast(msg, err = false) {
  let t = document.getElementById('fba-toast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'fba-toast';
    t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;transition:opacity .3s;font-family:var(--font)';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.style.background = err ? '#fc0025' : '#22c55e';
  t.style.color = '#fff';
  t.style.opacity = '1';
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.style.opacity = '0', 2800);
}
</script>
</body>
</html>
