<?php
session_start();
require_once 'backend/auth.php';
require_once 'backend/db.php';

requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Rankings - GM FBA</title>
  
  <!-- PWA Meta Tags -->
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#0a0a0c">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="FBA Manager">
  <link rel="apple-touch-icon" href="/img/icon-192.png">
  
  <link rel="icon" type="image/x-icon" href="/img/favicon.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
</head>
<body>
  <!-- Botão Hamburguer para Mobile -->
  <button class="sidebar-toggle" id="sidebarToggle">
    <i class="bi bi-list fs-4"></i>
  </button>
  
  <!-- Overlay para fechar sidebar no mobile -->
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
      <li><a href="/free-agency.php"><i class="bi bi-person-plus-fill"></i>Free Agency</a></li>
      <li><a href="/drafts.php"><i class="bi bi-trophy"></i>Draft</a></li>
      <li><a href="/rankings.php" class="active"><i class="bi bi-bar-chart-fill"></i>Rankings</a></li>
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
  </div>

  <div class="dashboard-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="text-white fw-bold mb-0">
        <i class="bi bi-bar-chart-fill me-2 text-orange"></i>
        Rankings
      </h1>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4 ranking-filters" role="group">
      <button type="button" class="btn btn-outline-orange btn-sm" data-league="ELITE" onclick="loadRanking('ELITE')">ELITE</button>
      <button type="button" class="btn btn-outline-orange btn-sm" data-league="NEXT" onclick="loadRanking('NEXT')">NEXT</button>
      <button type="button" class="btn btn-outline-orange btn-sm" data-league="RISE" onclick="loadRanking('RISE')">RISE</button>
      <button type="button" class="btn btn-outline-orange btn-sm" data-league="ROOKIE" onclick="loadRanking('ROOKIE')">ROOKIE</button>
    </div>

    <div id="rankingContainer">
      <div class="text-center py-5">
        <div class="spinner-border text-orange"></div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script>
    const userLeague = '<?= htmlspecialchars($user['league'] ?? 'ELITE') ?>'.toUpperCase();
    let currentLeague = userLeague;

    function updateActiveButton() {
      document.querySelectorAll('.ranking-filters button').forEach(btn => {
        if (btn.dataset.league === currentLeague) {
          btn.classList.remove('btn-outline-orange');
          btn.classList.add('btn-orange');
        } else {
          btn.classList.add('btn-outline-orange');
          btn.classList.remove('btn-orange');
        }
      });
    }

    async function loadRanking(league = userLeague) {
      currentLeague = league.toUpperCase();
      updateActiveButton();

      const container = document.getElementById('rankingContainer');
      container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';

      try {
        const endpoint = `/api/history-points.php?action=get_ranking&league=${encodeURIComponent(currentLeague)}`;
        
        const response = await fetch(endpoint);
        const data = await response.json();
        
        if (!data.success) {
          throw new Error(data.error);
        }

        const ranking = data.ranking[currentLeague] || [];

        if (ranking.length === 0) {
          container.innerHTML = `
            <div class="alert alert-info" style="border-radius: 15px;">
              <i class="bi bi-info-circle me-2"></i>
              Nenhum dado de ranking disponível ainda.
            </div>
          `;
          return;
        }

        container.innerHTML = `
          <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-dark table-hover mb-0">
                  <thead>
                    <tr>
                      <th style="border-radius: 15px 0 0 0; width: 50px;">#</th>
                      <th>Time</th>
                      <th class="hide-mobile" style="width: 90px;">Liga</th>
                      <th style="width: 90px; text-align: center;">
                        <i class="bi bi-trophy text-info"></i><span class="d-none d-md-inline ms-1">Títulos</span>
                      </th>
                      <th style="border-radius: 0 15px 0 0; width: 80px; text-align: center;">
                        <i class="bi bi-trophy-fill text-warning"></i><span class="d-none d-md-inline ms-1">Pontos</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    ${ranking.map((team, idx) => `
                      <tr>
                        <td>
                          ${idx < 3 ? 
                            `<span class="badge ${idx === 0 ? 'bg-warning' : idx === 1 ? 'bg-secondary' : 'bg-danger'}">${idx + 1}º</span>` : 
                            `<strong class="text-muted">${idx + 1}º</strong>`
                          }
                        </td>
                        <td>
                          <span class="fw-bold text-white" style="font-size: 0.9rem;">${team.team_name}</span>
                          <small class="d-md-none d-block text-light-gray">${team.league}</small>
                        </td>
                        <td class="hide-mobile"><span class="badge bg-gradient-orange">${team.league}</span></td>
                        <td class="text-center">
                          <strong class="text-info">${team.total_titles || 0}</strong>
                        </td>
                        <td class="text-center">
                          <strong class="text-warning">${team.total_points || 0}</strong>
                        </td>
                      </tr>
                    `).join('')}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        `;
      } catch (e) {
        console.error(e);
        container.innerHTML = `
          <div class="alert alert-danger" style="border-radius: 15px;">
            Erro ao carregar ranking: ${e.message || 'Desconhecido'}
          </div>
        `;
      }
    }

    // Carregar ranking geral ao iniciar
    document.addEventListener('DOMContentLoaded', () => {
      loadRanking(userLeague);
    });
  </script>
  <script src="/js/pwa.js"></script>
</body>
</html>
