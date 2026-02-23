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
$tradesEnabled = 1; // Default: ativas
if ($team) {
    $stmtSettings = $pdo->prepare('SELECT max_trades, trades_enabled FROM league_settings WHERE league = ?');
    $stmtSettings->execute([$team['league']]);
    $settings = $stmtSettings->fetch();
    $maxTrades = $settings['max_trades'] ?? 10;
    $tradesEnabled = $settings['trades_enabled'] ?? 1;
}

$currentSeasonYear = null;
if (!empty($team['league'])) {
  try {
    $stmtSeason = $pdo->prepare('
      SELECT s.season_number, s.year, sp.start_year
      FROM seasons s
      LEFT JOIN sprints sp ON s.sprint_id = sp.id
      WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ("completed"))
      ORDER BY s.created_at DESC
      LIMIT 1
    ');
    $stmtSeason->execute([$team['league']]);
    $currentSeason = $stmtSeason->fetch();
    if ($currentSeason && isset($currentSeason['start_year'], $currentSeason['season_number'])) {
      $currentSeasonYear = (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1;
    } elseif ($currentSeason && isset($currentSeason['year'])) {
      $currentSeasonYear = (int)$currentSeason['year'];
    }
  } catch (Exception $e) {
    $currentSeasonYear = null;
  }
}
if (!$currentSeasonYear) {
  $currentSeasonYear = (int)date('Y');
}

function syncTeamTradeCounter(PDO $pdo, int $teamId): int
{
    try {
        $stmt = $pdo->prepare('SELECT current_cycle, trades_cycle, trades_used FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0;
        }
        $currentCycle = (int)($row['current_cycle'] ?? 0);
        $tradesCycle = (int)($row['trades_cycle'] ?? 0);
        $tradesUsed = (int)($row['trades_used'] ?? 0);

    // Se trades_cycle ainda não estiver inicializado, alinhar com current_cycle e não zerar o contador.
    if ($currentCycle > 0 && $tradesCycle <= 0) {
      $pdo->prepare('UPDATE teams SET trades_cycle = ? WHERE id = ?')
        ->execute([$currentCycle, $teamId]);
      return $tradesUsed;
    }

    // Só zera quando já existe um ciclo anterior registrado e ele mudou
    if ($currentCycle > 0 && $tradesCycle > 0 && $tradesCycle !== $currentCycle) {
            $pdo->prepare('UPDATE teams SET trades_used = 0, trades_cycle = ? WHERE id = ?')
                ->execute([$currentCycle, $teamId]);
            return 0;
        }

        return $tradesUsed;
    } catch (Exception $e) {
        return 0;
    }
}

// Contador de trades (mostrar exatamente o campo trades_used do time logado)
$tradeCount = (int)($team['trades_used'] ?? 0);
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

    /* Ocultar seção de pick swaps (temporariamente) */
    #pick-swaps,
    .pick-swaps,
    .pick-swap {
      display: none !important;
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

    .team-chip-badge {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255,255,255,0.2);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 0.85rem;
      letter-spacing: 0.05em;
      color: var(--fba-text);
    }

    #playersList .alert {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid var(--fba-border);
      color: var(--fba-text);
    }

    .pick-selector {
      background: rgba(255, 255, 255, 0.02);
      border: 1px solid var(--fba-border);
      border-radius: 12px;
      padding: 16px;
      box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.2);
    }

    .pick-options {
      max-height: 220px;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 10px;
      margin-bottom: 14px;
    }

    .pick-option-card {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: var(--fba-dark-bg);
      border: 1px solid var(--fba-border);
      border-radius: 10px;
      padding: 10px 14px;
      transition: border 0.2s ease, transform 0.2s ease;
    }

    .pick-option-card:hover {
      border-color: var(--fba-orange);
      transform: translateY(-1px);
    }

    .pick-option-card.is-selected {
      opacity: 0.6;
    }

    .pick-title {
      color: var(--fba-text);
      font-weight: 600;
      margin-bottom: 2px;
    }

    .pick-meta {
      font-size: 0.85rem;
      color: var(--fba-text-muted);
    }

    .selected-picks {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .selected-pick-card {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      justify-content: space-between;
      align-items: center;
      background: rgba(241, 117, 7, 0.08);
      border: 1px solid rgba(241, 117, 7, 0.6);
      border-radius: 10px;
      padding: 12px 14px;
    }

    .selected-pick-info {
      flex: 1;
      min-width: 200px;
    }

    .selected-pick-actions {
      display: flex;
      gap: 10px;
      align-items: center;
    }

    .pick-protection-select {
      background: #ffffff;
      color: #000000;
      border: 1px solid var(--fba-orange);
      border-radius: 8px;
      padding: 6px 10px;
      min-width: 140px;
    }

    .pick-protection-select:hover,
    .pick-protection-select:focus {
      background: #ffffff;
      color: #000000;
      box-shadow: none;
    }

    .pick-protection-select option {
      color: #000000;
    }

    .pick-empty-state {
      text-align: center;
      padding: 12px;
      background: rgba(255, 255, 255, 0.03);
      border: 1px dashed var(--fba-border);
      border-radius: 10px;
      color: var(--fba-text-muted);
      font-size: 0.9rem;
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
        <a href="/leilao.php">
          <i class="bi bi-hammer"></i>
          Leilão
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
      <div class="page-header">
        <div class="d-flex align-items-center gap-3">
          <h1 class="text-white fw-bold mb-0"><i class="bi bi-arrow-left-right me-2 text-orange"></i>Trades</h1>
          <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
            <div class="d-flex align-items-center gap-2">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="tradesStatusToggle" <?= ($tradesEnabled ?? 1) == 1 ? 'checked' : '' ?>>
                <label class="form-check-label text-light-gray" for="tradesStatusToggle">Ativar trocas</label>
              </div>
              <span id="tradesStatusBadge" class="badge <?= ($tradesEnabled ?? 1) == 1 ? 'bg-success' : 'bg-danger' ?>" style="font-size:0.8rem;">
                <?= ($tradesEnabled ?? 1) == 1 ? 'Trocas abertas' : 'Trocas bloqueadas' ?>
              </span>
            </div>
          <?php endif; ?>
        </div>
        <div class="page-actions">
          <span class="badge bg-secondary me-2">Número de trocas feitas: <?= htmlspecialchars((string)$tradeCount) ?></span>
          <?php if ($tradesEnabled == 0): ?>
            <button class="btn btn-secondary" disabled title="Trades desativadas pelo administrador">
              <i class="bi bi-lock-fill me-1"></i>Trades Bloqueadas
            </button>
          <?php else: ?>
            <button class="btn btn-orange" data-bs-toggle="modal" data-bs-target="#proposeTradeModal" <?= $tradeCount >= $maxTrades ? 'disabled' : '' ?>>
              <i class="bi bi-plus-circle me-1"></i>Nova Trade
            </button>
            <button class="btn btn-outline-orange" data-bs-toggle="modal" data-bs-target="#multiTradeModal" <?= $tradeCount >= $maxTrades ? 'disabled' : '' ?>>
              <i class="bi bi-people-fill me-1"></i>Trade Múltipla
            </button>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$teamId): ?>
      <div class="alert alert-warning">Você ainda não possui um time.</div>
    <?php else: ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs nav-tabs-scroll mb-4" id="tradesTabs" role="tablist">
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
        <button class="nav-link" id="rumors-tab" data-bs-toggle="tab" data-bs-target="#rumors" type="button">
          <i class="bi bi-megaphone me-1"></i>Rumores
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
          <!-- Busca de jogador nas trocas -->
          <div class="mb-3">
            <input type="text" class="form-control" id="leagueTradesSearch" placeholder="🔍 Buscar jogador nas trocas...">
          </div>
          <div id="leagueTradesList"></div>
        </div>
      </div>

      <!-- Rumores (GMs e comentários do Admin) -->
      <div class="tab-pane fade" id="rumors" role="tabpanel">
        <div class="trade-list-panel">
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
              <h5 class="text-white mb-1"><i class="bi bi-megaphone me-2 text-orange"></i>Rumores da Liga</h5>
              <p class="text-light-gray mb-0 small">Compartilhe o que está procurando ou quais jogadores quer negociar.</p>
            </div>
            <span class="badge bg-secondary" id="rumorsCount">0 rumores</span>
          </div>

          <!-- Comentários do Admin -->
          <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="text-white mb-0"><i class="bi bi-pin-angle-fill me-2 text-orange"></i>Comentários do Admin</h6>
              <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
              <button class="btn btn-sm btn-outline-orange" id="addAdminCommentBtn"><i class="bi bi-plus-lg me-1"></i>Adicionar</button>
              <?php endif; ?>
            </div>
            <div id="adminCommentsList" class="list-group"></div>
          </div>

          <!-- Formulário de novo rumor (GM) -->
          <div class="mb-3">
            <label class="form-label text-white">Seu rumor</label>
            <textarea class="form-control bg-dark text-white border-orange" id="rumorContent" rows="2" placeholder="Ex.: Procuro SG com OVR 80+ ou vendo PF"></textarea>
            <div class="d-flex justify-content-end mt-2">
              <button class="btn btn-orange" id="submitRumorBtn"><i class="bi bi-megaphone-fill me-1"></i>Publicar</button>
            </div>
          </div>

          <!-- Lista de rumores -->
          <div id="rumorsList"></div>
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
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label text-white mb-0">Jogadores</label>
                    <small class="text-light-gray">Adicionar e revisar seleção</small>
                  </div>
                  <div class="pick-selector">
                    <div class="pick-options" id="offerPlayersOptions"></div>
                    <div class="selected-picks" id="offerPlayersSelected"></div>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label text-white mb-0">Picks</label>
                    <small class="text-light-gray">Adicione picks na proposta</small>
                  </div>
                  <div class="pick-selector">
                    <div class="pick-options" id="offerPicksOptions"></div>
                    <div class="selected-picks" id="offerPicksSelected"></div>
                  </div>
                </div>
              </div>

              <!-- O que você quer -->
              <div class="col-md-6">
                <h6 class="text-orange mb-3">Você quer</h6>
                <div class="mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label text-white mb-0">Jogadores</label>
                    <small class="text-light-gray">Selecione atletas do time alvo</small>
                  </div>
                  <div class="pick-selector">
                    <div class="pick-options" id="requestPlayersOptions"></div>
                    <div class="selected-picks" id="requestPlayersSelected"></div>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label text-white mb-0">Picks</label>
                    <small class="text-light-gray">Selecione picks do time alvo</small>
                  </div>
                  <div class="pick-selector">
                    <div class="pick-options" id="requestPicksOptions"></div>
                    <div class="selected-picks" id="requestPicksSelected"></div>
                  </div>
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
          <?php if ($tradesEnabled == 0): ?>
            <button type="button" class="btn btn-secondary" id="submitTradeBtn" disabled title="Trades desativadas pelo administrador">
              <i class="bi bi-lock-fill me-1"></i>Enviar Proposta
            </button>
          <?php else: ?>
            <button type="button" class="btn btn-orange" id="submitTradeBtn">
              <i class="bi bi-send me-1"></i>Enviar Proposta
            </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal: Trade Múltipla -->
  <div class="modal fade" id="multiTradeModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
      <div class="modal-content bg-dark-panel border-orange">
        <div class="modal-header border-bottom border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-people-fill me-2 text-orange"></i>Trade Múltipla</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="multiTradeForm">
            <div class="mb-3">
              <label class="form-label text-white fw-bold">Times participantes (máx. 7)</label>
              <div id="multiTradeTeamsList" class="d-flex flex-column gap-2"></div>
            </div>

            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label text-white fw-bold mb-0">Itens da troca</label>
                <button type="button" class="btn btn-sm btn-outline-orange" id="addMultiTradeItemBtn">
                  <i class="bi bi-plus-lg me-1"></i>Adicionar item
                </button>
              </div>
              <div id="multiTradeItems"></div>
            </div>

            <div class="mb-3">
              <label class="form-label text-white">Mensagem (opcional)</label>
              <textarea class="form-control bg-dark text-white border-orange" id="multiTradeNotes" rows="2"></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer border-top border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php if ($tradesEnabled == 0): ?>
            <button type="button" class="btn btn-secondary" id="submitMultiTradeBtn" disabled title="Trades desativadas pelo administrador">
              <i class="bi bi-lock-fill me-1"></i>Enviar Trade Múltipla
            </button>
          <?php else: ?>
            <button type="button" class="btn btn-orange" id="submitMultiTradeBtn">
              <i class="bi bi-send me-1"></i>Enviar Trade Múltipla
            </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    window.__TEAM_ID__ = <?= $teamId ? (int)$teamId : 'null' ?>;
    window.__USER_LEAGUE__ = '<?= htmlspecialchars($user['league'], ENT_QUOTES) ?>';
    window.__CURRENT_SEASON_YEAR__ = <?= (int)$currentSeasonYear ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script src="/js/trades.js?v=20260206"></script>
  <script src="/js/trade-list.js?v=20260130"></script>
  <script src="/js/rumors.js?v=20260130"></script>
  <script src="/js/pwa.js?v=20260130"></script>
  <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
  <script>
    (function(){
      const toggle = document.getElementById('tradesStatusToggle');
      const badge = document.getElementById('tradesStatusBadge');
      const league = window.__USER_LEAGUE__;
      if (!toggle || !league) return;
      toggle.addEventListener('change', async (e) => {
        const enabled = e.target.checked ? 1 : 0;
        try {
          const res = await fetch('/api/admin.php?action=league_settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ league: league, trades_enabled: enabled })
          });
          const data = await res.json();
          if (!res.ok || data.success === false) throw new Error(data.error || 'Erro ao salvar');
          // Atualiza badge
          if (enabled === 1) {
            badge.className = 'badge bg-success';
            badge.textContent = 'Trocas abertas';
          } else {
            badge.className = 'badge bg-danger';
            badge.textContent = 'Trocas bloqueadas';
          }
        } catch (err) {
          alert('Erro ao atualizar status das trocas');
          // Reverte o switch
          e.target.checked = !e.target.checked;
        }
      });
    })();
  </script>
  <?php endif; ?>
</body>
</html>
