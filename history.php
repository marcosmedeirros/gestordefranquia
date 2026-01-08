<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

$userLeague = $team['league'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Histórico - GM FBA</title>
  <link rel="icon" type="image/x-icon" href="/img/favicon.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
</head>
<body>
  <button class="sidebar-toggle" id="sidebarToggle">
    <i class="bi bi-list fs-4"></i>
  </button>
  
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <div class="dashboard-sidebar" id="sidebar">
    <div class="text-center mb-4">
      <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" alt="<?= htmlspecialchars($team['name']) ?>" class="team-avatar">
      <h5 class="text-white mb-1"><?= htmlspecialchars($team['city']) ?></h5>
      <h6 class="text-white mb-1"><?= htmlspecialchars($team['name']) ?></h6>
      <span class="badge bg-gradient-orange"><?= htmlspecialchars($team['league']) ?></span>
    </div>
    <hr style="border-color: var(--fba-border);">
    <ul class="sidebar-menu">
      <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i>Dashboard</a></li>
      <li><a href="/teams.php"><i class="bi bi-people-fill"></i>Times</a></li>
      <li><a href="/my-roster.php"><i class="bi bi-person-fill"></i>Meu Elenco</a></li>
      <li><a href="/picks.php"><i class="bi bi-calendar-check-fill"></i>Picks</a></li>
      <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i>Trades</a></li>
      <li><a href="/drafts.php"><i class="bi bi-trophy"></i>Draft</a></li>
      <li><a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i>Rankings</a></li>
      <li><a href="/history.php" class="active"><i class="bi bi-clock-history"></i>Histórico</a></li>
      <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
      <li><a href="/admin.php"><i class="bi bi-shield-lock-fill"></i>Admin</a></li>
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
        <i class="bi bi-clock-history me-2 text-orange"></i>
        Histórico de Temporadas
      </h1>
    </div>

    <div id="historyContainer">
      <div class="text-center py-5">
        <div class="spinner-border text-orange"></div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script>
    const userLeague = '<?= $userLeague ?>';

    const api = async (path, options = {}) => {
      const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
      let body = {};
      try { body = await res.json(); } catch {}
      if (!res.ok) throw body;
      return body;
    };

    async function loadHistory() {
      try {
        // Buscar histórico de campeões
        const championsData = await api('seasons.php?action=champions_history&league=' + userLeague);
        const champions = championsData.history || [];

        if (champions.length === 0) {
          document.getElementById('historyContainer').innerHTML = `
            <div class="card bg-dark-elevated border-0 text-center py-5" style="border-radius: 15px;">
              <div class="card-body">
                <i class="bi bi-clock-history text-orange fs-1 mb-3"></i>
                <h5 class="text-white mb-2">Nenhum histórico ainda</h5>
                <p class="text-light-gray">
                  Ainda não há histórico de temporadas registrado para a liga <strong class="text-orange">${userLeague}</strong>.
                </p>
              </div>
            </div>
          `;
          return;
        }

        // Agrupar por temporada
        const seasons = {};
        champions.forEach(c => {
          if (!seasons[c.season_id]) {
            seasons[c.season_id] = {
              id: c.season_id,
              number: c.season_number,
              year: c.year,
              league: c.league,
              champion: null,
              runner_up: null
            };
          }
          if (c.position === 'champion') {
            seasons[c.season_id].champion = { city: c.city, name: c.team_name, id: c.team_id };
          } else if (c.position === 'runner_up') {
            seasons[c.season_id].runner_up = { city: c.city, name: c.team_name, id: c.team_id };
          }
        });

        const seasonsList = Object.values(seasons).reverse();

        document.getElementById('historyContainer').innerHTML = `
          <div class="row g-4">
            ${seasonsList.map(s => `
              <div class="col-md-6 col-lg-4">
                <div class="card bg-dark-elevated border-0 h-100" style="border-radius: 15px;">
                  <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                      <h5 class="text-white mb-0">
                        <i class="bi bi-calendar3 text-orange me-2"></i>
                        Temporada ${String(s.number).padStart(2, '0')}
                      </h5>
                      <span class="badge bg-gradient-orange">${s.year}</span>
                    </div>

                    ${s.champion ? `
                      <div class="mb-3">
                        <div class="d-flex align-items-center gap-2 p-3" style="background: rgba(241, 117, 7, 0.1); border-radius: 10px; border-left: 4px solid var(--fba-orange);">
                          <i class="bi bi-trophy-fill text-orange" style="font-size: 1.5rem;"></i>
                          <div>
                            <small class="text-light-gray d-block">Campeão</small>
                            <strong class="text-white">${s.champion.city} ${s.champion.name}</strong>
                          </div>
                        </div>
                      </div>
                    ` : ''}

                    ${s.runner_up ? `
                      <div class="mb-2">
                        <div class="d-flex align-items-center gap-2 p-3" style="background: rgba(200, 200, 200, 0.05); border-radius: 10px; border-left: 4px solid #888;">
                          <i class="bi bi-award-fill text-light-gray" style="font-size: 1.5rem;"></i>
                          <div>
                            <small class="text-light-gray d-block">Vice-Campeão</small>
                            <strong class="text-white">${s.runner_up.city} ${s.runner_up.name}</strong>
                          </div>
                        </div>
                      </div>
                    ` : ''}
                  </div>
                </div>
              </div>
            `).join('')}
          </div>
        `;
      } catch (e) {
        console.error(e);
        document.getElementById('historyContainer').innerHTML = `
          <div class="alert alert-danger" style="border-radius: 15px;">
            Erro ao carregar histórico: ${e.error || 'Desconhecido'}
          </div>
        `;
      }
    }

    loadHistory();
  </script>
</body>
</html>
