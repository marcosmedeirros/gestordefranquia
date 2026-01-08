<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$user = getUserSession();
if (($user['user_type'] ?? 'jogador') !== 'admin') {
  header('Location: /dashboard.php');
  exit;
}
$pdo = db();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin - Ligas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
</head>
<body>
  <div class="dashboard-sidebar">
    <div class="text-center mb-4">
      <img src="<?= htmlspecialchars($user['photo_url'] ?? '/img/default-team.png') ?>" alt="Admin" class="team-avatar">
      <h5 class="text-white mb-1">Admin</h5>
      <span class="badge bg-gradient-orange">Painel</span>
    </div>
    <hr style="border-color: var(--fba-border);">
    <ul class="sidebar-menu">
      <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i>Dashboard</a></li>
      <li><a href="/teams.php"><i class="bi bi-people-fill"></i>Times</a></li>
      <li><a href="/my-roster.php"><i class="bi bi-person-fill"></i>Meu Elenco</a></li>
      <li><a href="/picks.php"><i class="bi bi-calendar-check-fill"></i>Picks</a></li>
      <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i>Trades</a></li>
      <li><a href="/admin.php" class="active"><i class="bi bi-shield-lock-fill"></i>Admin</a></li>
      <li><a href="/settings.php"><i class="bi bi-gear-fill"></i>Configurações</a></li>
    </ul>
    <hr style="border-color: var(--fba-border);">
    <div class="text-center">
      <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-2"></i>Sair</a>
    </div>
  </div>

  <div class="dashboard-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="text-white fw-bold mb-0"><i class="bi bi-shield-lock-fill me-2 text-orange"></i>Painel Administrativo</h1>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-4" id="adminTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="leagues-tab" data-bs-toggle="tab" data-bs-target="#leagues" type="button" role="tab">
          <i class="bi bi-trophy-fill me-2"></i>Ligas
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="teams-tab" data-bs-toggle="tab" data-bs-target="#teams" type="button" role="tab">
          <i class="bi bi-people-fill me-2"></i>Times
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="rosters-tab" data-bs-toggle="tab" data-bs-target="#rosters" type="button" role="tab">
          <i class="bi bi-person-badge-fill me-2"></i>Elencos
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="trades-tab" data-bs-toggle="tab" data-bs-target="#trades" type="button" role="tab">
          <i class="bi bi-arrow-left-right me-2"></i>Trades
        </button>
      </li>
    </ul>

    <!-- Tabs Content -->
    <div class="tab-content" id="adminTabsContent">
      <!-- Ligas Tab -->
      <div class="tab-pane fade show active" id="leagues" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h4 class="text-white mb-0">Configurações das Ligas</h4>
          <button class="btn btn-orange" id="saveLeagueSettingsBtn">
            <i class="bi bi-save2 me-1"></i>Salvar Configurações
          </button>
        </div>
        <div id="leaguesContainer">
          <div class="text-center py-4">
            <div class="spinner-border text-orange"></div>
          </div>
        </div>
      </div>

      <!-- Times Tab -->
      <div class="tab-pane fade" id="teams" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h4 class="text-white mb-0">Gestão de Times</h4>
          <div class="btn-group">
            <button class="btn btn-outline-orange btn-sm" data-league="ELITE" onclick="filterTeamsByLeague('ELITE')">ELITE</button>
            <button class="btn btn-outline-orange btn-sm" data-league="PRIME" onclick="filterTeamsByLeague('PRIME')">PRIME</button>
            <button class="btn btn-outline-orange btn-sm" data-league="RISE" onclick="filterTeamsByLeague('RISE')">RISE</button>
            <button class="btn btn-outline-orange btn-sm" data-league="ROOKIE" onclick="filterTeamsByLeague('ROOKIE')">ROOKIE</button>
            <button class="btn btn-orange btn-sm active" onclick="filterTeamsByLeague(null)">TODAS</button>
          </div>
        </div>
        <div id="teamsContainer">
          <div class="text-center py-4">
            <div class="spinner-border text-orange"></div>
          </div>
        </div>
      </div>

      <!-- Elencos Tab -->
      <div class="tab-pane fade" id="rosters" role="tabpanel">
        <div class="mb-3">
          <h4 class="text-white mb-3">Gerenciar Elenco de Time</h4>
          <select class="form-select bg-dark text-white border-orange" id="teamSelectForRoster">
            <option value="">Selecione um time...</option>
          </select>
        </div>
        <div id="rosterContainer">
          <div class="text-center py-4 text-light-gray">
            <i class="bi bi-info-circle fs-1"></i>
            <p class="mt-2">Selecione um time para gerenciar o elenco</p>
          </div>
        </div>
      </div>

      <!-- Trades Tab -->
      <div class="tab-pane fade" id="trades" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h4 class="text-white mb-0">Gerenciar Trades</h4>
          <div class="btn-group">
            <button class="btn btn-outline-orange btn-sm" onclick="filterTrades('pending')">Pendentes</button>
            <button class="btn btn-outline-orange btn-sm" onclick="filterTrades('accepted')">Aceitas</button>
            <button class="btn btn-outline-orange btn-sm" onclick="filterTrades('rejected')">Rejeitadas</button>
            <button class="btn btn-orange btn-sm active" onclick="filterTrades('all')">Todas</button>
          </div>
        </div>
        <div id="tradesContainer">
          <div class="text-center py-4">
            <div class="spinner-border text-orange"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/admin.js"></script>
</body>
</html>
