<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;
$teamId = $team['id'] ?? null;

// Contar trades criadas pelo usuário nesta temporada
$tradeCount = 0;
if ($teamId) {
    $stmtCount = $pdo->prepare('SELECT COUNT(*) as total FROM trades WHERE from_team_id = ? AND YEAR(created_at) = YEAR(NOW())');
    $stmtCount->execute([$teamId]);
    $tradeCount = $stmtCount->fetch()['total'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Trades - FBA Manager</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
  <style>
    .nav-tabs {
      border-bottom: 2px solid var(--fba-border);
    }
    .nav-tabs .nav-link {
      background: transparent;
      border: none;
      color: var(--fba-text-muted);
      font-weight: 500;
      padding: 12px 24px;
      transition: all 0.3s ease;
      border-bottom: 3px solid transparent;
      margin-bottom: -2px;
    }
    .nav-tabs .nav-link:hover {
      background: rgba(241, 117, 7, 0.1);
      color: var(--fba-orange);
      border-bottom-color: var(--fba-orange);
    }
    .nav-tabs .nav-link.active {
      background: rgba(241, 117, 7, 0.15);
      color: var(--fba-orange);
      border-bottom-color: var(--fba-orange);
      font-weight: 600;
    }
  </style>
</head>
<body>
  <!-- Sidebar -->
  <div class="dashboard-sidebar">
    <div class="text-center mb-4">
      <img src="<?= htmlspecialchars(($team['photo_url'] ?? '/img/default-team.png')) ?>" 
           alt="<?= htmlspecialchars($team['name'] ?? 'Time') ?>" class="team-avatar">
      <h5 class="text-white mb-1"><?= isset($team['name']) ? htmlspecialchars(($team['city'] . ' ' . $team['name'])) : 'Sem time' ?></h5>
      <span class="badge bg-gradient-orange"><?= htmlspecialchars($user['league']) ?></span>
    </div>

    <hr style="border-color: var(--fba-border);">

    <ul class="sidebar-menu">
      <li>
        <a href="/dashboard.php">
          <i class="bi bi-house-door-fill"></i>
          Dashboard
        </a>
      </li>
      <li>
        <a href="/teams.php">
          <i class="bi bi-people-fill"></i>
          Times
        </a>
      </li>
      <li>
        <a href="/my-roster.php">
          <i class="bi bi-person-badge-fill"></i>
          Meu Elenco
        </a>
      </li>
      <li>
        <a href="/picks.php">
          <i class="bi bi-trophy-fill"></i>
          Picks
        </a>
      </li>
      <li>
        <a href="/trades.php" class="active">
          <i class="bi bi-arrow-left-right"></i>
          Trades
        </a>
      </li>
      <li>
        <a href="/settings.php">
          <i class="bi bi-gear-fill"></i>
          Configurações
        </a>
      </li>
    </ul>

    <hr style="border-color: var(--fba-border);">

    <div class="text-center">
      <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100">
        <i class="bi bi-box-arrow-right me-2"></i>Sair
      </a>
    </div>

    <div class="text-center mt-3">
      <small class="text-light-gray">
        <i class="bi bi-person-circle me-1"></i>
        <?= htmlspecialchars($user['name']) ?>
      </small>
    </div>
  </div>

  <!-- Main Content -->
  <div class="dashboard-content">
    <div class="mb-4">
      <div class="d-flex justify-content-between align-items-center">
        <h1 class="text-white fw-bold mb-0"><i class="bi bi-arrow-left-right me-2 text-orange"></i>Trades</h1>
        <div>
          <span class="badge bg-secondary me-2"><?= $tradeCount ?> / 10 Trades este ano</span>
          <button class="btn btn-orange" data-bs-toggle="modal" data-bs-target="#proposeTradeModal" <?= $tradeCount >= 10 ? 'disabled' : '' ?>>
            <i class="bi bi-plus-circle me-1"></i>Nova Trade
          </button>
        </div>
      </div>
    </div>

    <?php if (!$teamId): ?>
      <div class="alert alert-warning">Você ainda não possui um time.</div>
    <?php else: ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="tradesTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="received-tab" data-bs-toggle="tab" data-bs-target="#received" type="button">
          <i class="bi bi-inbox-fill me-1"></i>Recebidas
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="sent-tab" data-bs-toggle="tab" data-bs-target="#sent" type="button">
          <i class="bi bi-send-fill me-1"></i>Enviadas
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button">
          <i class="bi bi-clock-history me-1"></i>Histórico
        </button>
      </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="tradesTabContent">
      <!-- Trades Recebidas -->
      <div class="tab-pane fade show active" id="received" role="tabpanel">
        <div id="receivedTradesList"></div>
      </div>

      <!-- Trades Enviadas -->
      <div class="tab-pane fade" id="sent" role="tabpanel">
        <div id="sentTradesList"></div>
      </div>

      <!-- Histórico -->
      <div class="tab-pane fade" id="history" role="tabpanel">
        <div id="historyTradesList"></div>
      </div>
    </div>

    <?php endif; ?>
  </div>

  <!-- Modal: Propor Trade -->
  <div class="modal fade" id="proposeTradeModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
      <div class="modal-content bg-dark-panel border-orange">
        <div class="modal-header border-bottom border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-arrow-left-right me-2 text-orange"></i>Propor Trade</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="proposeTradeForm">
            <!-- Selecionar time -->
            <div class="mb-4">
              <label class="form-label text-white fw-bold">Para qual time?</label>
              <select class="form-select bg-dark text-white border-orange" id="targetTeam" required>
                <option value="">Selecione...</option>
              </select>
            </div>

            <div class="row">
              <!-- O que você oferece -->
              <div class="col-md-6">
                <h6 class="text-orange mb-3">Você oferece</h6>
                <div class="mb-3">
                  <label class="form-label text-white">Jogadores</label>
                  <select class="form-select bg-dark text-white border-orange" id="offerPlayers" multiple size="5">
                  </select>
                  <small class="text-light-gray">Ctrl/Cmd + clique para múltiplos</small>
                </div>
                <div class="mb-3">
                  <label class="form-label text-white">Picks</label>
                  <select class="form-select bg-dark text-white border-orange" id="offerPicks" multiple size="3">
                  </select>
                </div>
              </div>

              <!-- O que você quer -->
              <div class="col-md-6">
                <h6 class="text-orange mb-3">Você quer</h6>
                <div class="mb-3">
                  <label class="form-label text-white">Jogadores</label>
                  <select class="form-select bg-dark text-white border-orange" id="requestPlayers" multiple size="5">
                  </select>
                  <small class="text-light-gray">Ctrl/Cmd + clique para múltiplos</small>
                </div>
                <div class="mb-3">
                  <label class="form-label text-white">Picks</label>
                  <select class="form-select bg-dark text-white border-orange" id="requestPicks" multiple size="3">
                  </select>
                </div>
              </div>
            </div>

            <!-- Nota -->
            <div class="mb-3">
              <label class="form-label text-white">Mensagem (opcional)</label>
              <textarea class="form-control bg-dark text-white border-orange" id="tradeNotes" rows="2"></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer border-top border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-orange" id="submitTradeBtn">
            <i class="bi bi-send me-1"></i>Enviar Proposta
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    window.__TEAM_ID__ = <?= $teamId ? (int)$teamId : 'null' ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/trades.js"></script>
</body>
</html>
