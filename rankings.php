<?php
session_start();
require_once 'backend/auth.php';
require_once 'backend/db.php';

requireAuth();

$user = getUserSession();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Rankings - GM FBA</title>
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
      <img src="<?= htmlspecialchars($user['photo_url'] ?? '/img/default-team.png') ?>" alt="Time" class="team-avatar">
      <h5 class="text-white mb-1"><?= htmlspecialchars($user['city'] ?? 'Cidade') ?></h5>
      <h6 class="text-white mb-1"><?= htmlspecialchars($user['name'] ?? 'Nome') ?></h6>
      <span class="badge bg-gradient-orange"><?= htmlspecialchars($user['league'] ?? 'LEAGUE') ?></span>
    </div>
    <hr style="border-color: var(--fba-border);">
    <ul class="sidebar-menu">
      <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i>Dashboard</a></li>
      <li><a href="/teams.php"><i class="bi bi-people-fill"></i>Times</a></li>
      <li><a href="/my-roster.php"><i class="bi bi-person-fill"></i>Meu Elenco</a></li>
      <li><a href="/picks.php"><i class="bi bi-calendar-check-fill"></i>Picks</a></li>
      <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i>Trades</a></li>
      <li><a href="/drafts.php"><i class="bi bi-trophy"></i>Draft</a></li>
      <li><a href="/rankings.php" class="active"><i class="bi bi-bar-chart-fill"></i>Rankings</a></li>
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
        <i class="bi bi-bar-chart-fill me-2 text-orange"></i>
        Rankings
      </h1>
    </div>

    <div class="btn-group mb-4" role="group">
      <button type="button" class="btn btn-orange" onclick="loadRanking('global')">
        <i class="bi bi-globe me-1"></i>Geral
      </button>
      <button type="button" class="btn btn-outline-orange" onclick="loadRanking('elite')">ELITE</button>
      <button type="button" class="btn btn-outline-orange" onclick="loadRanking('next')">NEXT</button>
      <button type="button" class="btn btn-outline-orange" onclick="loadRanking('rise')">RISE</button>
      <button type="button" class="btn btn-outline-orange" onclick="loadRanking('rookie')">ROOKIE</button>
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
    const api = async (path, options = {}) => {
      const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
      let body = {};
      try { body = await res.json(); } catch {}
      if (!res.ok) throw body;
      return body;
    };

    let currentType = 'global';

    async function loadRanking(type = 'global') {
      currentType = type;
      
      // Atualizar botões ativos
      document.querySelectorAll('.btn-group button').forEach(btn => {
        btn.classList.remove('btn-orange');
        btn.classList.add('btn-outline-orange');
      });
      event.target.classList.remove('btn-outline-orange');
      event.target.classList.add('btn-orange');

      const container = document.getElementById('rankingContainer');
      container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';

      try {
        const endpoint = type === 'global' 
          ? 'seasons.php?action=global_ranking'
          : `seasons.php?action=league_ranking&league=${type.toUpperCase()}`;
        
        const data = await api(endpoint);
        const ranking = data.ranking || [];

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
          <div class="card bg-dark-elevated border-0" style="border-radius: 15px;">
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-dark table-hover mb-0">
                  <thead>
                    <tr>
                      <th style="border-radius: 15px 0 0 0;">#</th>
                      <th>Time</th>
                      <th>Liga</th>
                      <th>Pontos</th>
                      <th>Temporadas</th>
                      <th>🏆 Títulos</th>
                      <th>🥈 Vices</th>
                      <th style="border-radius: 0 15px 0 0;">⭐ Prêmios</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${ranking.map((team, idx) => `
                      <tr>
                        <td><strong class="text-orange">${idx + 1}º</strong></td>
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <img src="${team.photo_url || '/img/default-team.png'}" 
                                 alt="${team.team_name}" 
                                 style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                            <span>${team.city} ${team.team_name}</span>
                          </div>
                        </td>
                        <td><span class="badge bg-gradient-orange">${team.league}</span></td>
                        <td><strong class="text-warning">${team.total_points || 0}</strong></td>
                        <td>${team.seasons_played || 0}</td>
                        <td>${team.championships || 0}</td>
                        <td>${team.runner_ups || 0}</td>
                        <td>${team.total_awards || 0}</td>
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
            Erro ao carregar ranking: ${e.error || 'Desconhecido'}
          </div>
        `;
      }
    }

    // Carregar ranking geral ao iniciar
    loadRanking('global');
  </script>
</body>
</html>
