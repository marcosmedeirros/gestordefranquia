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

$isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Free Agency - FBA Manager</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
  <style>
    .fa-card {
      background: var(--fba-panel);
      border: 1px solid var(--fba-border);
      border-radius: 12px;
      padding: 1rem;
      margin-bottom: 1rem;
      transition: all 0.3s ease;
    }
    .fa-card:hover {
      border-color: var(--fba-orange);
      transform: translateY(-2px);
    }
    .fa-card .player-name {
      font-size: 1.1rem;
      font-weight: 600;
      color: white;
    }
    .fa-card .player-ovr {
      font-size: 1.5rem;
      font-weight: bold;
    }
    .fa-card .player-info {
      color: var(--fba-text-muted);
      font-size: 0.9rem;
    }
    .offer-card {
      background: rgba(241, 117, 7, 0.1);
      border: 1px solid var(--fba-orange);
      border-radius: 8px;
      padding: 0.75rem;
      margin-bottom: 0.5rem;
    }
    .nav-tabs {
      border-bottom: 2px solid var(--fba-border);
    }
    .nav-tabs .nav-link {
      background: transparent;
      border: none;
      color: var(--fba-text-muted);
      font-weight: 500;
      padding: 12px 24px;
      border-bottom: 3px solid transparent;
      margin-bottom: -2px;
    }
    .nav-tabs .nav-link:hover {
      background: rgba(241, 117, 7, 0.1);
      color: var(--fba-orange);
    }
    .nav-tabs .nav-link.active {
      background: rgba(241, 117, 7, 0.15);
      color: var(--fba-orange);
      border-bottom-color: var(--fba-orange);
    }
    .limit-badge {
      font-size: 0.85rem;
      padding: 6px 12px;
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
      <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i>Dashboard</a></li>
      <li><a href="/teams.php"><i class="bi bi-people-fill"></i>Times</a></li>
      <li><a href="/my-roster.php"><i class="bi bi-person-badge-fill"></i>Meu Elenco</a></li>
      <li><a href="/picks.php"><i class="bi bi-trophy-fill"></i>Picks</a></li>
      <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i>Trades</a></li>
      <li><a href="/free-agency.php" class="active"><i class="bi bi-person-plus-fill"></i>Free Agency</a></li>
      <li><a href="/drafts.php"><i class="bi bi-trophy"></i>Draft</a></li>
      <li><a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i>Rankings</a></li>
      <li><a href="/history.php"><i class="bi bi-clock-history"></i>Histórico</a></li>
      <?php if ($isAdmin): ?>
      <li><a href="/admin.php"><i class="bi bi-shield-lock-fill"></i>Admin</a></li>
      <li><a href="/temporadas.php"><i class="bi bi-calendar3"></i>Temporadas</a></li>
      <?php endif; ?>
      <li><a href="/settings.php"><i class="bi bi-gear-fill"></i>Configurações</a></li>
    </ul>

    <hr style="border-color: var(--fba-border);">
    <div class="text-center">
      <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-2"></i>Sair</a>
    </div>
    <div class="text-center mt-3">
      <small class="text-light-gray"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($user['name']) ?></small>
    </div>
  </div>

  <!-- Main Content -->
  <div class="dashboard-content">
    <div class="mb-4">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1 class="text-white fw-bold mb-0"><i class="bi bi-person-plus-fill me-2 text-orange"></i>Free Agency</h1>
        <div class="d-flex gap-2 align-items-center">
          <span class="badge bg-secondary limit-badge" id="waivers-badge">
            <i class="bi bi-person-dash me-1"></i>Dispensas: <span id="waivers-count">0/3</span>
          </span>
          <span class="badge bg-success limit-badge" id="signings-badge">
            <i class="bi bi-person-plus me-1"></i>Contratações: <span id="signings-count">0/3</span>
          </span>
          <!-- Botão Resetar FA removido -->
        </div>
      </div>
    </div>

    <?php if (!$teamId): ?>
      <div class="alert alert-warning">Você ainda não possui um time.</div>
    <?php else: ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="faTabs" role="tablist">
      <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#available">
          <i class="bi bi-people me-1"></i>Disponíveis
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#my-offers">
          <i class="bi bi-send me-1"></i>Minhas Propostas
        </button>
      </li>
      <?php if ($isAdmin): ?>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#admin-offers">
          <i class="bi bi-shield-check me-1"></i>Gerenciar Propostas
        </button>
      </li>
      <?php endif; ?>
    </ul>

    <div class="tab-content">
      <!-- Free Agents Disponíveis -->
      <div class="tab-pane fade show active" id="available">
        <div class="row mb-3">
          <div class="col-md-4">
            <input type="text" class="form-control bg-dark text-white border-orange" 
                   id="search-fa" placeholder="Buscar jogador...">
          </div>
          <div class="col-md-3">
            <select class="form-select bg-dark text-white border-orange" id="filter-position">
              <option value="">Todas posições</option>
              <option value="PG">PG</option>
              <option value="SG">SG</option>
              <option value="SF">SF</option>
              <option value="PF">PF</option>
              <option value="C">C</option>
            </select>
          </div>
        </div>
        <div id="fa-list" class="row">
          <div class="col-12 text-center py-5">
            <div class="spinner-border text-orange"></div>
          </div>
        </div>
      </div>

      <!-- Minhas Propostas -->
      <div class="tab-pane fade" id="my-offers">
        <div id="my-offers-list">
          <div class="text-center py-5">
            <div class="spinner-border text-orange"></div>
          </div>
        </div>
      </div>

      <?php if ($isAdmin): ?>
      <!-- Admin: Gerenciar Propostas -->
      <div class="tab-pane fade" id="admin-offers">
        <div id="admin-offers-list">
          <div class="text-center py-5">
            <div class="spinner-border text-orange"></div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php endif; ?>
  </div>

  <!-- Modal: Enviar Proposta -->
  <div class="modal fade" id="offerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content bg-dark-panel border-orange">
        <div class="modal-header border-bottom border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-send me-2 text-orange"></i>Enviar Proposta</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-light-gray">Enviar proposta para contratar:</p>
          <h5 class="text-white" id="offer-player-name"></h5>
          <p class="text-light-gray" id="offer-player-info"></p>
          <input type="hidden" id="offer-fa-id">
          <div class="mb-3">
            <label class="form-label text-white">Mensagem (opcional)</label>
            <textarea class="form-control bg-dark text-white border-orange" id="offer-notes" rows="2" 
                      placeholder="Ex: Preciso dele para reforçar o banco..."></textarea>
          </div>
        </div>
        <div class="modal-footer border-top border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-orange" id="btn-send-offer">
            <i class="bi bi-send me-1"></i>Enviar Proposta
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    window.__TEAM_ID__ = <?= $teamId ? (int)$teamId : 'null' ?>;
    window.__IS_ADMIN__ = <?= $isAdmin ? 'true' : 'false' ?>;
    window.__USER_LEAGUE__ = '<?= htmlspecialchars($user['league'], ENT_QUOTES) ?>';
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script src="/js/free-agency.js"></script>
</body>
</html>
