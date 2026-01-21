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

// Buscar limite de trades da liga
$maxTrades = 10; // Default
if ($team) {
    $stmtSettings = $pdo->prepare('SELECT max_trades FROM league_settings WHERE league = ?');
    $stmtSettings->execute([$team['league']]);
    $settings = $stmtSettings->fetch();
    $maxTrades = $settings['max_trades'] ?? 10;
}

// Contar trades criadas pelo usuário nesta temporada
$tradeCount = 0;
if ($teamId) {
  try {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) as total FROM trades WHERE status = 'accepted' AND YEAR(updated_at) = YEAR(NOW()) AND (from_team_id = ? OR to_team_id = ?)");
    $stmtCount->execute([$teamId, $teamId]);
    $tradeCount = $stmtCount->fetch()['total'] ?? 0;
  } catch (Exception $e) {
    $tradeCount = 0;
  }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Trades - FBA Manager</title>
  
  <!-- PWA Meta Tags -->
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#0a0a0c">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="FBA Manager">
  <link rel="apple-touch-icon" href="/img/icon-192.png">
  
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
  <style>
    .trade-list-panel {
      background: var(--fba-card-bg);
      border: 1px solid var(--fba-border);
      border-radius: 10px;
      padding: 20px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
    }

    .trade-list-search .form-control,
    .trade-list-search .form-select {
      background: var(--fba-dark-bg);
      color: var(--fba-text);
      border: 1px solid var(--fba-border);
    }

    .trade-list-search .form-control:focus,
    .trade-list-search .form-select:focus {
      border-color: var(--fba-orange);
      box-shadow: 0 0 0 0.25rem rgba(241, 117, 7, 0.25);
    }

    .player-card {
      background: var(--fba-dark-bg);
      border: 1px solid var(--fba-border);
      border-radius: 10px;
      padding: 16px;
      margin-bottom: 12px;
      transition: transform 0.2s ease, border 0.2s ease;
    }

    .player-card:hover {
      border-color: var(--fba-orange);
      transform: translateY(-2px);
      box-shadow: 0 10px 20px rgba(241, 117, 7, 0.25);
    }

    .player-name {
      font-weight: 600;
      color: var(--fba-text);
      font-size: 1.05rem;
    }

    .player-meta {
      font-size: 0.9rem;
      color: var(--fba-text-muted);
    }

    .team-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid var(--fba-border);
      border-radius: 30px;
      padding: 6px 14px;
    }

    .team-chip img {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      object-fit: cover;
      border: 1px solid rgba(255,255,255,0.2);
    }

    #playersList .alert {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid var(--fba-border);
      color: var(--fba-text);
    }

    /* Modern Trade Card Styles */
    .trade-card-modern {
      background: linear-gradient(135deg, rgba(20, 20, 20, 0.95) 0%, rgba(26, 26, 26, 0.95) 100%);
      border: 1px solid var(--fba-border);
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
      transition: all 0.3s ease;
    }

    .trade-card-modern:hover {
      transform: translateY(-4px);
      box-shadow: 0 15px 50px rgba(252, 0, 37, 0.2);
      border-color: var(--fba-brand);
    }

    .trade-card-header {
      background: linear-gradient(135deg, #1a1a1a 0%, #141414 100%);
      padding: 24px;
      border-bottom: 2px solid var(--fba-border);
    }

    .trade-card-teams {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
    }

    .trade-team {
      flex: 1;
    }

    .trade-team-name {
      font-size: 1.2rem;
      font-weight: 700;
      color: var(--fba-white);
      margin-bottom: 4px;
    }

    .trade-arrow {
      background: var(--fba-brand);
      width: 48px;
      height: 48px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      color: white;
      box-shadow: 0 4px 15px rgba(252, 0, 37, 0.4);
      animation: pulse 2s infinite;
    }

    @keyframes pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.1); }
    }

    .trade-card-body {
      padding: 24px;
    }

    .trade-section {
      background: rgba(0, 0, 0, 0.3);
      border: 1px solid var(--fba-border);
      border-radius: 12px;
      padding: 16px;
      height: 100%;
    }

    .trade-section-header {
      font-size: 0.95rem;
      font-weight: 600;
      color: var(--fba-brand);
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--fba-border);
      display: flex;
      align-items: center;
    }

    .trade-items {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .trade-item-card {
      background: var(--fba-dark-bg);
      border: 1px solid var(--fba-border);
      border-radius: 10px;
      padding: 12px;
      transition: all 0.2s ease;
    }

    .trade-item-card:hover {
      border-color: var(--fba-brand);
      transform: translateX(4px);
      background: rgba(252, 0, 37, 0.05);
    }

    .trade-item-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      background: linear-gradient(135deg, var(--fba-brand) 0%, #ff2a44 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
      color: white;
      box-shadow: 0 4px 12px rgba(252, 0, 37, 0.3);
    }

    .trade-item-icon.pick-icon {
      background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
      box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
    }

    .trade-item-name {
      font-weight: 600;
      color: var(--fba-white);
      font-size: 1rem;
      margin-bottom: 4px;
    }

    .trade-item-meta {
      display: flex;
      gap: 8px;
      align-items: center;
      font-size: 0.85rem;
    }

    .trade-item-ovr {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--fba-brand);
      min-width: 45px;
      text-align: center;
    }

    .trade-notes {
      background: rgba(252, 0, 37, 0.1);
      border: 1px solid rgba(252, 0, 37, 0.3);
      border-radius: 10px;
      padding: 12px 16px;
      margin-top: 16px;
      color: var(--fba-text-muted);
      font-size: 0.9rem;
      display: flex;
      align-items: start;
      gap: 8px;
    }

    .trade-response-note {
      background: rgba(255, 193, 7, 0.1);
      border: 1px solid rgba(255, 193, 7, 0.3);
      border-radius: 10px;
      padding: 16px;
      margin-top: 16px;
    }

    .trade-card-footer {
      padding: 20px 24px;
      background: rgba(0, 0, 0, 0.3);
      border-top: 1px solid var(--fba-border);
    }

    .trade-card-footer .btn {
      padding: 10px 20px;
      font-weight: 600;
      border-radius: 8px;
      transition: all 0.2s ease;
    }

    .trade-card-footer .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    }

    /* Modern Trade Modal Styles */
    .trade-modal-modern {
      background: linear-gradient(135deg, #0a0a0c 0%, #141414 100%);
      border: 2px solid var(--fba-brand);
      border-radius: 20px;
      overflow: hidden;
    }

    .trade-modal-header {
      background: linear-gradient(135deg, #1a1a1a 0%, #0a0a0c 100%);
      border-bottom: 2px solid var(--fba-brand);
      padding: 24px 32px;
    }

    .trade-modal-body {
      padding: 32px;
      max-height: 75vh;
      overflow-y: auto;
    }

    .trade-modal-body::-webkit-scrollbar {
      width: 8px;
    }

    .trade-modal-body::-webkit-scrollbar-track {
      background: rgba(0, 0, 0, 0.3);
      border-radius: 10px;
    }

    .trade-modal-body::-webkit-scrollbar-thumb {
      background: var(--fba-brand);
      border-radius: 10px;
    }

    .trade-team-selector .trade-select {
      background: var(--fba-dark-bg);
      color: var(--fba-white);
      border: 2px solid var(--fba-border);
      border-radius: 12px;
      padding: 14px 20px;
      font-size: 1.1rem;
      font-weight: 600;
      transition: all 0.3s ease;
    }

    .trade-team-selector .trade-select:focus {
      border-color: var(--fba-brand);
      box-shadow: 0 0 0 0.25rem rgba(252, 0, 37, 0.25);
      background: rgba(252, 0, 37, 0.05);
    }

    .trade-grid {
      display: grid;
      grid-template-columns: 1fr auto 1fr;
      gap: 24px;
      margin-bottom: 24px;
    }

    @media (max-width: 991px) {
      .trade-grid {
        grid-template-columns: 1fr;
        gap: 20px;
      }
      
      .trade-divider {
        display: none;
      }
    }

    .trade-side {
      background: rgba(0, 0, 0, 0.3);
      border: 1px solid var(--fba-border);
      border-radius: 16px;
      padding: 20px;
    }

    .trade-side-offer {
      border-left: 3px solid #ff9800;
    }

    .trade-side-request {
      border-left: 3px solid #4caf50;
    }

    .trade-side-header {
      font-size: 1.2rem;
      font-weight: 700;
      color: var(--fba-white);
      margin-bottom: 20px;
      padding-bottom: 12px;
      border-bottom: 2px solid var(--fba-border);
      display: flex;
      align-items: center;
    }

    .trade-divider {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .trade-divider-icon {
      width: 60px;
      height: 60px;
      background: var(--fba-brand);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.8rem;
      color: white;
      box-shadow: 0 4px 20px rgba(252, 0, 37, 0.5);
      animation: pulse 2s infinite;
    }

    .trade-category {
      margin-bottom: 20px;
    }

    .trade-category:last-child {
      margin-bottom: 0;
    }

    .trade-category-header {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.95rem;
      font-weight: 600;
      color: var(--fba-text-muted);
      margin-bottom: 12px;
      padding: 8px 12px;
      background: rgba(0, 0, 0, 0.3);
      border-radius: 8px;
    }

    .trade-category-header .badge {
      margin-left: auto;
    }

    .trade-items-container {
      max-height: 250px;
      overflow-y: auto;
      padding: 8px;
      background: rgba(0, 0, 0, 0.2);
      border-radius: 10px;
    }

    .trade-items-container::-webkit-scrollbar {
      width: 6px;
    }

    .trade-items-container::-webkit-scrollbar-track {
      background: rgba(0, 0, 0, 0.2);
      border-radius: 10px;
    }

    .trade-items-container::-webkit-scrollbar-thumb {
      background: var(--fba-brand);
      border-radius: 10px;
    }

    .trade-selectable-item {
      background: var(--fba-dark-bg);
      border: 2px solid var(--fba-border);
      border-radius: 10px;
      padding: 12px;
      margin-bottom: 8px;
      cursor: pointer;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .trade-selectable-item:hover {
      border-color: var(--fba-brand);
      transform: translateX(4px);
      background: rgba(252, 0, 37, 0.05);
    }

    .trade-selectable-item.selected {
      border-color: var(--fba-brand);
      background: rgba(252, 0, 37, 0.15);
      box-shadow: 0 4px 12px rgba(252, 0, 37, 0.3);
    }

    .trade-selectable-item.unavailable {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .trade-selectable-item.unavailable:hover {
      transform: none;
      border-color: var(--fba-border);
      background: var(--fba-dark-bg);
    }

    .trade-item-check {
      width: 24px;
      height: 24px;
      border: 2px solid var(--fba-border);
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
      flex-shrink: 0;
    }

    .trade-selectable-item.selected .trade-item-check {
      background: var(--fba-brand);
      border-color: var(--fba-brand);
    }

    .trade-selectable-item.selected .trade-item-check i {
      color: white;
      font-size: 0.9rem;
    }

    .trade-item-icon-small {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      background: linear-gradient(135deg, var(--fba-brand) 0%, #ff2a44 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
      color: white;
      flex-shrink: 0;
    }

    .trade-item-icon-small.pick {
      background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
    }

    .trade-item-info {
      flex: 1;
      min-width: 0;
    }

    .trade-item-name-small {
      font-weight: 600;
      color: var(--fba-white);
      font-size: 0.95rem;
      margin-bottom: 4px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .trade-item-meta-small {
      display: flex;
      gap: 8px;
      align-items: center;
      font-size: 0.8rem;
      color: var(--fba-text-muted);
      flex-wrap: wrap;
    }

    .trade-item-ovr-small {
      font-size: 1.2rem;
      font-weight: 700;
      color: var(--fba-brand);
      flex-shrink: 0;
    }

    .trade-notes-section {
      background: rgba(0, 0, 0, 0.3);
      border: 1px solid var(--fba-border);
      border-radius: 12px;
      padding: 20px;
    }

    .trade-textarea {
      background: var(--fba-dark-bg);
      color: var(--fba-white);
      border: 2px solid var(--fba-border);
      border-radius: 10px;
      padding: 12px;
      resize: vertical;
      transition: all 0.3s ease;
    }

    .trade-textarea:focus {
      border-color: var(--fba-brand);
      box-shadow: 0 0 0 0.25rem rgba(252, 0, 37, 0.25);
      background: rgba(252, 0, 37, 0.05);
    }

    .trade-modal-footer {
      background: rgba(0, 0, 0, 0.3);
      border-top: 2px solid var(--fba-border);
      padding: 20px 32px;
    }

    .trade-modal-footer .btn-lg {
      padding: 12px 32px;
      font-size: 1.1rem;
      font-weight: 600;
      border-radius: 10px;
    }

    @media (max-width: 576px) {
      .trade-modal-header {
        padding: 20px;
      }

      .trade-modal-body {
        padding: 20px;
      }

      .trade-modal-footer {
        padding: 16px 20px;
      }

      .trade-modal-footer .btn-lg {
        padding: 10px 20px;
        font-size: 1rem;
      }

      .trade-side {
        padding: 16px;
      }

      .trade-item-name-small {
        font-size: 0.9rem;
      }

      .trade-item-meta-small {
        font-size: 0.75rem;
      }

      .trade-item-ovr-small {
        font-size: 1.1rem;
      }
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
        <a href="/free-agency.php">
          <i class="bi bi-coin"></i>
          Free Agency
        </a>
      </li>
      <li>
        <a href="/drafts.php">
          <i class="bi bi-trophy"></i>
          Draft
        </a>
      </li>
      <li>
        <a href="/rankings.php">
          <i class="bi bi-bar-chart-fill"></i>
          Rankings
        </a>
      </li>
      <li>
        <a href="/history.php">
          <i class="bi bi-clock-history"></i>
          Histórico
        </a>
      </li>
      <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
      <li>
        <a href="/admin.php">
          <i class="bi bi-shield-lock-fill"></i>
          Admin
        </a>
      </li>
      <li>
        <a href="/temporadas.php">
          <i class="bi bi-calendar3"></i>
          Temporadas
        </a>
      </li>
      <?php endif; ?>
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
          <span class="badge bg-secondary me-2"><?= $tradeCount ?> / <?= $maxTrades ?> Trades esta temporada</span>
          <button class="btn btn-orange" data-bs-toggle="modal" data-bs-target="#proposeTradeModal" <?= $tradeCount >= $maxTrades ? 'disabled' : '' ?>>
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
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="league-tab" data-bs-toggle="tab" data-bs-target="#league" type="button">
          <i class="bi bi-trophy me-1"></i>Trocas Gerais
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="trade-list-tab" data-bs-toggle="tab" data-bs-target="#trade-list" type="button">
          <i class="bi bi-list-stars me-1"></i>Trade List
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

      <!-- Todas as trades da liga -->
      <div class="tab-pane fade" id="league" role="tabpanel">
        <div class="trade-list-panel">
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
              <h5 class="text-white mb-1"><i class="bi bi-trophy me-2 text-orange"></i>Todas as trocas desta liga</h5>
              <p class="text-light-gray mb-0 small">Histórico completo de negociações aceitas na sua liga.</p>
            </div>
            <span class="badge bg-secondary" id="leagueTradesCount">0 trocas</span>
          </div>
          <div id="leagueTradesList"></div>
        </div>
      </div>

      <!-- Trade List (Disponíveis para troca na sua liga) -->
      <div class="tab-pane fade" id="trade-list" role="tabpanel">
        <div class="trade-list-panel">
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
            <div>
              <h5 class="text-white mb-1"><i class="bi bi-list-stars me-2 text-orange"></i>Jogadores disponíveis para troca</h5>
              <p class="text-light-gray mb-0 small">Somente atletas marcados como disponíveis na sua liga atual.</p>
            </div>
            <span class="badge bg-secondary" id="countBadge">0 jogadores</span>
          </div>
          <div class="trade-list-search d-flex flex-column flex-md-row gap-2 mb-3">
            <input type="text" class="form-control" id="searchInput" placeholder="Procurar por nome...">
            <select class="form-select" id="sortSelect">
              <option value="ovr_desc">OVR (Maior primeiro)</option>
              <option value="ovr_asc">OVR (Menor primeiro)</option>
              <option value="name_asc">Nome (A-Z)</option>
              <option value="name_desc">Nome (Z-A)</option>
              <option value="age_asc">Idade (Menor primeiro)</option>
              <option value="age_desc">Idade (Maior primeiro)</option>
              <option value="position_asc">Posição (A-Z)</option>
              <option value="position_desc">Posição (Z-A)</option>
              <option value="team_asc">Time (A-Z)</option>
              <option value="team_desc">Time (Z-A)</option>
            </select>
          </div>
          <div id="playersList"></div>
        </div>
      </div>
    </div>

    <?php endif; ?>
  </div>

  <!-- Modal: Propor Trade -->
  <div class="modal fade" id="proposeTradeModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen-lg-down modal-xl">
      <div class="modal-content trade-modal-modern">
        <div class="modal-header trade-modal-header">
          <div>
            <h5 class="modal-title text-white mb-1">
              <i class="bi bi-arrow-left-right me-2 text-orange"></i>Propor Trade
            </h5>
            <small class="text-muted">Selecione os itens que deseja trocar</small>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body trade-modal-body">
          <form id="proposeTradeForm">
            <!-- Selecionar time -->
            <div class="trade-team-selector mb-4">
              <label class="form-label text-white fw-bold mb-3">
                <i class="bi bi-building me-2"></i>Para qual time?
              </label>
              <select class="form-select trade-select" id="targetTeam" required>
                <option value="">Selecione um time...</option>
              </select>
            </div>

            <div class="trade-grid">
              <!-- O que você oferece -->
              <div class="trade-side trade-side-offer">
                <div class="trade-side-header">
                  <i class="bi bi-box-arrow-right me-2"></i>
                  <span>Você oferece</span>
                </div>
                
                <!-- Jogadores oferecidos -->
                <div class="trade-category">
                  <div class="trade-category-header">
                    <i class="bi bi-people-fill me-2"></i>
                    <span>Jogadores</span>
                    <span class="badge bg-secondary" id="offerPlayersCount">0</span>
                  </div>
                  <div class="trade-items-container" id="offerPlayersContainer">
                    <div class="text-center text-muted py-4">
                      <i class="bi bi-inbox mb-2" style="font-size: 2rem;"></i>
                      <p class="mb-0">Nenhum jogador disponível</p>
                    </div>
                  </div>
                </div>

                <!-- Picks oferecidas -->
                <div class="trade-category">
                  <div class="trade-category-header">
                    <i class="bi bi-trophy-fill me-2"></i>
                    <span>Picks</span>
                    <span class="badge bg-secondary" id="offerPicksCount">0</span>
                  </div>
                  <div class="trade-items-container" id="offerPicksContainer">
                    <div class="text-center text-muted py-4">
                      <i class="bi bi-inbox mb-2" style="font-size: 2rem;"></i>
                      <p class="mb-0">Nenhuma pick disponível</p>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Divisor central -->
              <div class="trade-divider">
                <div class="trade-divider-icon">
                  <i class="bi bi-arrow-left-right"></i>
                </div>
              </div>

              <!-- O que você quer -->
              <div class="trade-side trade-side-request">
                <div class="trade-side-header">
                  <i class="bi bi-box-arrow-in-left me-2"></i>
                  <span>Você recebe</span>
                </div>

                <!-- Jogadores solicitados -->
                <div class="trade-category">
                  <div class="trade-category-header">
                    <i class="bi bi-people-fill me-2"></i>
                    <span>Jogadores</span>
                    <span class="badge bg-secondary" id="requestPlayersCount">0</span>
                  </div>
                  <div class="trade-items-container" id="requestPlayersContainer">
                    <div class="text-center text-muted py-4">
                      <i class="bi bi-inbox mb-2" style="font-size: 2rem;"></i>
                      <p class="mb-0">Selecione um time primeiro</p>
                    </div>
                  </div>
                </div>

                <!-- Picks solicitadas -->
                <div class="trade-category">
                  <div class="trade-category-header">
                    <i class="bi bi-trophy-fill me-2"></i>
                    <span>Picks</span>
                    <span class="badge bg-secondary" id="requestPicksCount">0</span>
                  </div>
                  <div class="trade-items-container" id="requestPicksContainer">
                    <div class="text-center text-muted py-4">
                      <i class="bi bi-inbox mb-2" style="font-size: 2rem;"></i>
                      <p class="mb-0">Selecione um time primeiro</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Nota -->
            <div class="trade-notes-section">
              <label class="form-label text-white fw-bold mb-2">
                <i class="bi bi-chat-left-text me-2"></i>Mensagem (opcional)
              </label>
              <textarea class="form-control trade-textarea" id="tradeNotes" rows="3" 
                        placeholder="Adicione uma mensagem para o outro GM..."></textarea>
            </div>

            <!-- Selects escondidos (compatibilidade) -->
            <select id="offerPlayers" multiple style="display: none;"></select>
            <select id="offerPicks" multiple style="display: none;"></select>
            <select id="requestPlayers" multiple style="display: none;"></select>
            <select id="requestPicks" multiple style="display: none;"></select>
          </form>
        </div>
        <div class="modal-footer trade-modal-footer">
          <button type="button" class="btn btn-secondary btn-lg" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-2"></i>Cancelar
          </button>
          <button type="button" class="btn btn-orange btn-lg" id="submitTradeBtn">
            <i class="bi bi-send-fill me-2"></i>Enviar Proposta
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    window.__TEAM_ID__ = <?= $teamId ? (int)$teamId : 'null' ?>;
    window.__USER_LEAGUE__ = '<?= htmlspecialchars($user['league'], ENT_QUOTES) ?>';
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script src="/js/trades.js"></script>
  <script src="/js/trade-list.js"></script>
  <script src="/js/pwa.js"></script>
</body>
</html>
