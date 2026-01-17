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
$league = $team['league'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Trade List - FBA Manager</title>
  
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
    .player-card { background: var(--fba-card-bg); border: 1px solid var(--fba-border); border-radius: 8px; padding: 12px; margin-bottom: 12px; }
    .player-card:hover { border-color: var(--fba-orange); box-shadow: 0 4px 12px rgba(241, 117, 7, 0.15); }
    .player-name { font-weight: 600; color: var(--fba-text); }
    .player-meta { font-size: 0.9rem; color: var(--fba-text-muted); }
    .team-chip { display: inline-flex; align-items: center; gap: 8px; background: var(--fba-dark-bg); border: 1px solid var(--fba-border); padding: 6px 10px; border-radius: 20px; }
    .team-chip img { width: 24px; height: 24px; border-radius: 50%; object-fit: cover; }
    .search-bar { display: flex; gap: 10px; }
  </style>
</head>
<body>
  <!-- Sidebar -->
  <div class="dashboard-sidebar">
    <div class="text-center mb-4">
      <img src="<?= htmlspecialchars(($team['photo_url'] ?? '/img/default-team.png')) ?>" alt="<?= htmlspecialchars($team['name'] ?? 'Time') ?>" class="team-avatar">
      <h5 class="text-white mb-1"><?= isset($team['name']) ? htmlspecialchars(($team['city'] . ' ' . $team['name'])) : 'Sem time' ?></h5>
      <span class="badge bg-gradient-orange"><?= htmlspecialchars($league) ?></span>
    </div>

    <hr style="border-color: var(--fba-border);">

    <ul class="sidebar-menu">
      <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i>Dashboard</a></li>
      <li><a href="/teams.php"><i class="bi bi-people-fill"></i>Times</a></li>
      <li><a href="/my-roster.php"><i class="bi bi-person-badge-fill"></i>Meu Elenco</a></li>
      <li><a href="/picks.php"><i class="bi bi-trophy-fill"></i>Picks</a></li>
      <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i>Trades</a></li>
      <li><a href="/trade-list.php" class="active"><i class="bi bi-list-stars"></i>Trade List</a></li>
      <li><a href="/free-agency.php"><i class="bi bi-hammer"></i>Leilões</a></li>
      <li><a href="/drafts.php"><i class="bi bi-trophy"></i>Draft</a></li>
      <li><a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i>Rankings</a></li>
      <li><a href="/history.php"><i class="bi bi-clock-history"></i>Histórico</a></li>
      <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
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
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="text-white fw-bold mb-0"><i class="bi bi-list-stars me-2 text-orange"></i>Trade List</h1>
      <span class="badge bg-secondary" id="countBadge">0 jogadores</span>
    </div>

    <div class="search-bar mb-3">
      <input type="text" class="form-control bg-dark text-white border-orange" id="searchInput" placeholder="Procurar por nome...">
      <select class="form-select bg-dark text-white border-orange" id="sortSelect">
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

  <script>
  window.__USER_LEAGUE__ = '<?= htmlspecialchars($league, ENT_QUOTES) ?>';
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script src="/js/trade-list.js"></script>
  <script src="/js/pwa.js"></script>
</body>
</html>
