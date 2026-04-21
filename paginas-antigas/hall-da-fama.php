<?php
session_start();
require_once 'backend/auth.php';
require_once 'backend/db.php';

requireAuth();

$user = getUserSession();
$pdo = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Hall da Fama - GM FBA</title>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#0a0a0c">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="FBA Manager">
  <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
  <link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
  <style>
    .hof-card {
      background: var(--fba-card-bg);
      border: 1px solid var(--fba-border);
      border-radius: 14px;
      padding: 16px;
    }
    .hof-title {
      font-weight: 600;
      color: #fff;
      margin-bottom: 4px;
      font-size: 1.15rem;
    }
    .hof-meta {
      color: var(--fba-text-muted);
      font-size: 0.9rem;
    }
    .hof-subtitle {
      color: var(--fba-text-muted);
      font-size: 0.85rem;
    }
    .hof-status {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--fba-orange);
      font-weight: 600;
    }
    .hof-titles {
      font-size: 1.35rem;
      font-weight: 700;
      color: var(--fba-orange);
    }
  </style>
</head>
<body>
  <button class="sidebar-toggle" id="sidebarToggle">
    <i class="bi bi-list fs-4"></i>
  </button>
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <div class="dashboard-sidebar" id="sidebar">
    <div class="text-center mb-4">
      <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" alt="Time" class="team-avatar">
      <h5 class="text-white mb-1"><?= htmlspecialchars(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')) ?></h5>
      <span class="badge bg-gradient-orange"><?= htmlspecialchars($team['league'] ?? $user['league'] ?? 'LEAGUE') ?></span>
    </div>
    <hr style="border-color: var(--fba-border);">
    <ul class="sidebar-menu">
      <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i>Dashboard</a></li>
      <li><a href="/teams.php"><i class="bi bi-people-fill"></i>Times</a></li>
      <li><a href="/my-roster.php"><i class="bi bi-person-fill"></i>Meu Elenco</a></li>
      <li><a href="/picks.php"><i class="bi bi-calendar-check-fill"></i>Picks</a></li>
      <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i>Trades</a></li>
      <li><a href="/free-agency.php"><i class="bi bi-coin"></i>Free Agency</a></li>
      <li><a href="/leilao.php"><i class="bi bi-hammer"></i>Leilao</a></li>
      <li><a href="/drafts.php"><i class="bi bi-trophy"></i>Draft</a></li>
      <li><a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i>Rankings</a></li>
      <li><a href="/hall-da-fama.php" class="active"><i class="bi bi-award-fill"></i>Hall da Fama</a></li>
      <li><a href="/history.php"><i class="bi bi-clock-history"></i>Historico</a></li>
      <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
      <li><a href="/admin.php"><i class="bi bi-shield-lock-fill"></i>Admin</a></li>
      <li><a href="/temporadas.php"><i class="bi bi-calendar3"></i>Temporadas</a></li>
      <?php endif; ?>
      <li><a href="/settings.php"><i class="bi bi-gear-fill"></i>Configuracoes</a></li>
    </ul>
    <hr style="border-color: var(--fba-border);">
    <div class="text-center">
      <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-2"></i>Sair</a>
    </div>
  </div>

  <div class="dashboard-content">
    <div class="page-header mb-4">
      <h1 class="text-white fw-bold mb-0">
        <i class="bi bi-award-fill me-2 text-orange"></i>
        Hall da Fama
      </h1>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
      <select class="form-select form-select-sm bg-dark text-white border-orange" id="hofLeagueFilter" style="max-width: 220px;">
        <option value="ALL">Todas as ligas</option>
        <option value="ELITE">ELITE</option>
        <option value="NEXT">NEXT</option>
        <option value="RISE">RISE</option>
        <option value="ROOKIE">ROOKIE</option>
      </select>
    </div>

    <div id="hallOfFameContainer" class="row g-3">
      <div class="text-center py-5">
        <div class="spinner-border text-orange"></div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script>
    let hallOfFameItems = [];

    function renderHallOfFame(items) {
      const container = document.getElementById('hallOfFameContainer');
      if (!items.length) {
        container.innerHTML = '<div class="alert alert-info">Nenhum time no Hall da Fama.</div>';
        return;
      }
      container.innerHTML = items.map(item => {
        const league = item.league ? `<span class="badge bg-gradient-orange">${item.league}</span>` : '';
        const gmName = item.gm_name || 'GM não informado';
        const isActive = Number(item.is_active) === 1;
  const subtitle = isActive ? (item.team_name || 'Time não informado') : '';
        const statusLabel = isActive ? 'Ativo' : 'Inativo';
        return `
          <div class="col-md-6 col-lg-4">
            <div class="hof-card h-100">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div class="hof-status mb-1">${statusLabel}</div>
                  <div class="hof-title">${gmName}</div>
                  ${subtitle ? `<div class="hof-subtitle">${subtitle}</div>` : ''}
                </div>
                <div class="text-end">
                  ${league}
                  <div class="hof-titles">${item.titles || 0}</div>
                  <div class="hof-meta">Titulos</div>
                </div>
              </div>
            </div>
          </div>
        `;
      }).join('');
    }

    function applyHallOfFameFilter() {
      const filter = document.getElementById('hofLeagueFilter').value;
      if (filter === 'ALL') {
        renderHallOfFame(hallOfFameItems);
        return;
      }
      const filtered = hallOfFameItems.filter(item => (item.league || '').toUpperCase() === filter);
      renderHallOfFame(filtered);
    }

    async function loadHallOfFame() {
      const container = document.getElementById('hallOfFameContainer');
      container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';

      try {
        const resp = await fetch('/api/hall-of-fame.php');
        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Falha ao carregar');

        hallOfFameItems = Array.isArray(data.items) ? data.items : [];
        applyHallOfFameFilter();
      } catch (e) {
        container.innerHTML = '<div class="alert alert-danger">Erro ao carregar Hall da Fama.</div>';
      }
    }

    document.addEventListener('DOMContentLoaded', () => {
      document.getElementById('hofLeagueFilter').addEventListener('change', applyHallOfFameFilter);
      loadHallOfFame();
    });
  </script>
  <script src="/js/pwa.js"></script>
</body>
</html>
