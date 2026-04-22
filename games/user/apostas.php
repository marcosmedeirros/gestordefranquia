<?php
session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT nome, pontos, is_admin, fba_points, COALESCE(numero_tapas,0) as numero_tapas FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar usuário: " . $e->getMessage());
}

try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_apostas,
            COALESCE(SUM(p.valor), 0) as total_apostado,
            COALESCE(SUM(
                CASE
                    WHEN e.status = 'encerrada' AND e.vencedor_opcao_id = p.opcao_id
                    THEN p.valor * p.odd_registrada
                    ELSE 0
                END
            ), 0) as total_ganhos
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        WHERE p.id_usuario = :uid
    ");
    $stmt->execute([':uid' => $user_id]);
    $resumo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_apostas' => 0, 'total_apostado' => 0, 'total_ganhos' => 0];
} catch (PDOException $e) {
    $resumo = ['total_apostas' => 0, 'total_apostado' => 0, 'total_ganhos' => 0];
}

try {
    $stmt = $pdo->prepare("
        SELECT p.valor, p.odd_registrada, p.data_palpite, p.opcao_id,
               o.descricao as opcao_descricao,
               e.nome as evento_nome,
               e.status as evento_status,
               e.vencedor_opcao_id
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        WHERE p.id_usuario = :uid
        ORDER BY p.data_palpite DESC
        LIMIT 100
    ");
    $stmt->execute([':uid' => $user_id]);
    $historico = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $historico = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#fc0025">
  <title>Minhas Apostas - FBA Games</title>
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
    .sb-stat-info { display: flex; flex-direction: column; align-items: center; }
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
    .mob-chip i { font-size: 11px; }
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

    /* ── Main ── */
    .main { max-width: 800px; margin: 0 auto; padding: 28px 20px 60px; }

    /* ── Section label ── */
    .section-label {
      display: flex; align-items: center; gap: 8px;
      font-size: 10px; font-weight: 700; letter-spacing: 1.2px;
      text-transform: uppercase; color: var(--text-3);
      margin-bottom: 14px; margin-top: 28px;
    }
    .section-label i { color: var(--red); font-size: 13px; }

    /* ── Stat cards ── */
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 4px; }
    .stat-card {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 18px;
      display: flex; flex-direction: column; gap: 6px;
    }
    .stat-label { font-size: 10px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: var(--text-2); display: flex; align-items: center; gap: 6px; }
    .stat-label i { color: var(--red); }
    .stat-value { font-size: 22px; font-weight: 800; color: var(--text); line-height: 1.1; }

    /* ── Aposta cards ── */
    .aposta-card {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 18px; margin-bottom: 10px;
      transition: border-color var(--t) var(--ease);
    }
    .aposta-card:hover { border-color: var(--border-md); }
    .aposta-card.win { border-color: rgba(34,197,94,.35); background: rgba(34,197,94,.04); }
    .aposta-card.lose { border-color: rgba(252,0,37,.3); background: rgba(252,0,37,.04); }
    .aposta-event { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
    .aposta-opcao { font-size: 12px; color: var(--text-2); margin-bottom: 14px; }
    .aposta-details { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }
    .aposta-detail { display: flex; flex-direction: column; gap: 2px; }
    .aposta-detail-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--text-3); }
    .aposta-detail-val { font-size: 13px; font-weight: 700; color: var(--text); }
    .aposta-detail-val.win { color: var(--green); }
    .aposta-detail-val.lose { color: var(--red); }
    .aposta-detail-val.neutral { color: var(--text-2); }

    /* ── Status badge ── */
    .status-badge {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 2px 9px; border-radius: 999px;
      font-size: 10px; font-weight: 700;
    }
    .status-badge.win  { background: rgba(34,197,94,.15); color: var(--green); border: 1px solid rgba(34,197,94,.25); }
    .status-badge.lose { background: rgba(252,0,37,.1);   color: #ff6680; border: 1px solid var(--border-red); }
    .status-badge.open { background: rgba(245,158,11,.1); color: var(--amber); border: 1px solid rgba(245,158,11,.25); }

    /* ── Empty state ── */
    .state-empty { padding: 48px 24px; text-align: center; color: var(--text-3); }
    .state-empty i { font-size: 36px; display: block; margin-bottom: 12px; opacity: .5; }
    .state-empty p { font-size: 13px; }

    @media (max-width: 600px) {
      .stats-grid { grid-template-columns: 1fr 1fr; }
      .stats-grid .stat-card:last-child { grid-column: 1 / -1; }
    }
  </style>
</head>
<body>

<div class="page-layout">
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-logo">FBA</div>
    <span class="sb-brand">FBA <span>Games</span></span>
    <button class="sb-close" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr($usuario['nome'] ?? 'U', 0, 1)) ?></div>
    <div class="sb-user-name"><?= htmlspecialchars($usuario['nome'] ?? '') ?></div>
    <div class="sb-user-role"><?= !empty($usuario['is_admin']) ? 'Admin' : 'Jogador' ?></div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat">
      <i class="bi bi-hand-index-fill" style="color:var(--green)"></i>
      <div class="sb-stat-val"><?= number_format($usuario['numero_tapas'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Tapas</div>
    </div>
    <div class="sb-stat">
      <i class="bi bi-coin" style="color:var(--amber)"></i>
      <div class="sb-stat-val"><?= number_format($usuario['pontos'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Moedas</div>
    </div>
    <div class="sb-stat">
      <i class="bi bi-gem" style="color:#a78bfa"></i>
      <div class="sb-stat-val"><?= number_format($usuario['fba_points'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">FBA Pts</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a href="../index.php" class="sb-link active">
      <i class="bi bi-lightning-charge"></i>Apostas
    </a>
    <a href="../games.php" class="sb-link">
      <i class="bi bi-joystick"></i>Games
    </a>
    <a href="ranking-geral.php" class="sb-link">
      <i class="bi bi-trophy"></i>Ranking Geral
    </a>
    <?php if (!empty($usuario['is_admin'])): ?>
    <div class="sb-nav-section">Admin</div>
    <a href="../admin/controlegames.php" class="sb-link">
      <i class="bi bi-gear-fill"></i>Controle de Jogos
    </a>
    <a href="../admin/dashboard.php" class="sb-link">
      <i class="bi bi-receipt-cutoff"></i>Controle Apostas
    </a>
    <?php endif; ?>
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
  <span class="mob-title">FBA <span>Games</span></span>
  <div class="mob-chips">
    <span class="mob-chip"><i class="bi bi-coin" style="color:var(--amber)"></i><?= number_format($usuario['pontos'] ?? 0, 0, ',', '.') ?></span>
    <span class="mob-chip"><i class="bi bi-gem" style="color:#a78bfa"></i><?= number_format($usuario['fba_points'] ?? 0, 0, ',', '.') ?></span>
  </div>
  <a href="../index.php" class="mob-back" title="Voltar"><i class="bi bi-arrow-left"></i></a>
</div>

<div class="main">

  <div class="section-label" style="margin-top:0"><i class="bi bi-graph-up"></i>Resumo</div>
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label"><i class="bi bi-receipt"></i>Total de apostas</div>
      <div class="stat-value"><?= (int)$resumo['total_apostas'] ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="bi bi-coin"></i>Total apostado</div>
      <div class="stat-value" style="font-size:16px"><?= number_format((float)$resumo['total_apostado'], 0, ',', '.') ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="bi bi-trophy"></i>Total de ganhos</div>
      <div class="stat-value" style="font-size:16px"><?= number_format((float)$resumo['total_ganhos'], 0, ',', '.') ?></div>
    </div>
  </div>

  <div class="section-label"><i class="bi bi-cash-stack"></i>Histórico de Apostas</div>

  <?php if (!empty($historico)): ?>
    <?php foreach ($historico as $palpite):
      $resultado = null;
      $cardClass = '';
      $statusClass = 'neutral';
      if (($palpite['evento_status'] ?? '') === 'encerrada' && $palpite['vencedor_opcao_id']) {
          if ((int)$palpite['vencedor_opcao_id'] === (int)$palpite['opcao_id']) {
              $resultado = 'Ganhou';
              $cardClass = 'win';
              $statusClass = 'win';
          } else {
              $resultado = 'Perdeu';
              $cardClass = 'lose';
              $statusClass = 'lose';
          }
      }
      $statusLabel = $resultado ?: (($palpite['evento_status'] ?? '') === 'aberta' ? 'Aberta' : 'Encerrada');
      $statusBadge = $resultado ? ($resultado === 'Ganhou' ? 'win' : 'lose') : 'open';
      $ganhos = $resultado === 'Ganhou' ? $palpite['valor'] * $palpite['odd_registrada'] : 0;
    ?>
    <div class="aposta-card <?= $cardClass ?>">
      <div class="aposta-event"><?= htmlspecialchars($palpite['evento_nome']) ?></div>
      <div class="aposta-opcao"><i class="bi bi-check2-circle me-1"></i>Opção: <?= htmlspecialchars($palpite['opcao_descricao']) ?></div>
      <div class="aposta-details">
        <div class="aposta-detail">
          <div class="aposta-detail-label">Valor apostado</div>
          <div class="aposta-detail-val"><?= number_format($palpite['valor'], 0, ',', '.') ?> moedas</div>
        </div>
        <div class="aposta-detail">
          <div class="aposta-detail-label">Odd</div>
          <div class="aposta-detail-val"><?= number_format($palpite['odd_registrada'], 2) ?>x</div>
        </div>
        <div class="aposta-detail">
          <div class="aposta-detail-label">Status</div>
          <div class="aposta-detail-val">
            <span class="status-badge <?= $statusBadge ?>">
              <?php if ($statusBadge === 'win'): ?><i class="bi bi-check-circle-fill"></i>
              <?php elseif ($statusBadge === 'lose'): ?><i class="bi bi-x-circle-fill"></i>
              <?php else: ?><i class="bi bi-clock"></i>
              <?php endif; ?>
              <?= $statusLabel ?>
            </span>
          </div>
        </div>
        <?php if ($ganhos > 0): ?>
        <div class="aposta-detail">
          <div class="aposta-detail-label">Ganhos</div>
          <div class="aposta-detail-val win"><?= number_format($ganhos, 0, ',', '.') ?> moedas</div>
        </div>
        <?php endif; ?>
        <div class="aposta-detail">
          <div class="aposta-detail-label">Data</div>
          <div class="aposta-detail-val neutral"><?= date('d/m/Y H:i', strtotime($palpite['data_palpite'])) ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="state-empty">
      <i class="bi bi-inbox"></i>
      <p>Você ainda não fez nenhuma aposta.</p>
    </div>
  <?php endif; ?>

</div><!-- /main -->
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
</script>
</body>
</html>
