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
  <title>Draft - GM FBA</title>
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
      <h5 class="text-white mb-1"><?= htmlspecialchars($team['city']) ?></h5>
      <h6 class="text-white mb-1"><?= htmlspecialchars($team['name']) ?></h6>
      <span class="badge bg-gradient-orange"><?= htmlspecialchars($userLeague) ?></span>
    </div>
    <hr style="border-color: var(--fba-border);">
    <ul class="sidebar-menu">
      <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i>Dashboard</a></li>
      <li><a href="/teams.php"><i class="bi bi-people-fill"></i>Times</a></li>
      <li><a href="/my-roster.php"><i class="bi bi-person-fill"></i>Meu Elenco</a></li>
      <li><a href="/picks.php"><i class="bi bi-calendar-check-fill"></i>Picks</a></li>
      <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i>Trades</a></li>
      <li><a href="/free-agency.php"><i class="bi bi-person-plus-fill"></i>Free Agency</a></li>
      <li><a href="/drafts.php" class="active"><i class="bi bi-trophy"></i>Draft</a></li>
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
  </div>

  <div class="dashboard-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="text-white fw-bold mb-0">
        <i class="bi bi-trophy me-2 text-orange"></i>
        Próximo Draft
      </h1>
    </div>

    <div id="draftContainer">
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

    async function loadDraft() {
      try {
        // Buscar temporada atual da liga do usuário
        const seasonData = await api(`seasons.php?action=current_season&league=${userLeague}`);
        
        if (!seasonData.season) {
          document.getElementById('draftContainer').innerHTML = `
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              Ainda não há temporada ativa para a liga ${userLeague}.
            </div>
          `;
          return;
        }

        // Buscar jogadores do draft
        const draftData = await api(`seasons.php?action=draft_players&season_id=${seasonData.season.id}`);
        const players = draftData.players || [];
        
        const available = players.filter(p => p.draft_status === 'available');
        const drafted = players.filter(p => p.draft_status === 'drafted');
        
        console.log('Total players:', players.length);
        console.log('Available:', available.length);
        console.log('Drafted:', drafted.length);
        console.log('Drafted players:', drafted);

        document.getElementById('draftContainer').innerHTML = `
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
                <div class="card-body">
                  <h5 class="text-white mb-2">
                    <i class="bi bi-calendar3 text-orange me-2"></i>
                    Temporada ${seasonData.season.season_number}
                  </h5>
                  <p class="text-light-gray mb-0">Ano: ${seasonData.season.year}</p>
                  <p class="text-light-gray mb-0">Liga: <span class="badge bg-gradient-orange">${userLeague}</span></p>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
                <div class="card-body">
                  <h5 class="text-white mb-2">
                    <i class="bi bi-people text-orange me-2"></i>
                    Disponíveis
                  </h5>
                  <h2 class="text-orange mb-0">${available.length}</h2>
                  <p class="text-light-gray mb-0">Aguardando draft</p>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="card bg-dark-panel border-success" style="border-radius: 15px;">
                <div class="card-body">
                  <h5 class="text-white mb-2">
                    <i class="bi bi-check-circle text-success me-2"></i>
                    Já Draftados
                  </h5>
                  <h2 class="text-success mb-0">${drafted.length}</h2>
                  <p class="text-light-gray mb-0">Picks realizadas</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Jogadores Disponíveis -->
          <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
            <div class="card-header bg-transparent border-orange">
              <h5 class="text-white mb-0">
                <i class="bi bi-people-fill me-2 text-orange"></i>
                Jogadores Disponíveis para Draft (${available.length})
              </h5>
            </div>
            <div class="card-body p-0">
              ${available.length === 0 ? `
                <div class="text-center text-light-gray py-5">
                  <i class="bi bi-inbox display-1"></i>
                  <p class="mt-3">Nenhum jogador disponível no momento</p>
                </div>
              ` : `
                <div class="table-responsive">
                  <table class="table table-dark table-hover mb-0">
                    <thead>
                      <tr>
                        <th style="width: 50px;">#</th>
                        <th>Nome</th>
                        <th style="width: 100px;">Posição</th>
                        <th style="width: 100px;">Idade</th>
                        <th style="width: 100px;">OVR</th>
                        <th style="width: 150px;">Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${available.map((p, idx) => `
                        <tr>
                          <td class="text-light-gray">${idx + 1}</td>
                          <td class="text-white fw-bold">${p.name}</td>
                          <td><span class="badge bg-orange">${p.position}</span></td>
                          <td class="text-light-gray">${p.age} anos</td>
                          <td><span class="badge bg-success">OVR ${p.ovr}</span></td>
                          <td><span class="badge bg-info">Disponível</span></td>
                        </tr>
                      `).join('')}
                    </tbody>
                  </table>
                </div>
              `}
            </div>
          </div>

          <!-- Jogadores Já Draftados -->
          ${(() => {
            console.log('Checking drafted condition:', drafted.length > 0);
            if (drafted.length > 0) {
              return `
                <div class="card bg-dark-panel border-success" style="border-radius: 15px;">
                  <div class="card-header bg-transparent border-success">
                    <h5 class="text-white mb-0">
                      <i class="bi bi-check-circle-fill me-2 text-success"></i>
                      Jogadores Já Draftados (${drafted.length})
                    </h5>
                  </div>
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table table-dark table-hover mb-0">
                        <thead>
                          <tr>
                            <th style="width: 100px;">Pick #</th>
                            <th>Nome</th>
                            <th style="width: 100px;">Posição</th>
                            <th style="width: 100px;">OVR</th>
                            <th>Time</th>
                          </tr>
                        </thead>
                        <tbody>
                          ${drafted.sort((a, b) => a.draft_order - b.draft_order).map(p => `
                            <tr>
                              <td><span class="badge bg-success">Pick #${p.draft_order}</span></td>
                              <td class="text-white fw-bold">${p.name}</td>
                              <td><span class="badge bg-orange">${p.position}</span></td>
                              <td><span class="badge bg-success">OVR ${p.ovr}</span></td>
                              <td class="text-light-gray">${p.team_name || 'N/A'}</td>
                            </tr>
                          `).join('')}
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              `;
            } else {
              return `
                <div class="alert alert-info">
                  <i class="bi bi-info-circle me-2"></i>
                  Nenhum jogador foi draftado ainda.
                </div>
              `;
            }
          })()}
        `;
      } catch (e) {
        console.error(e);
        document.getElementById('draftContainer').innerHTML = `
          <div class="alert alert-danger">
            Erro ao carregar draft: ${e.error || 'Desconhecido'}
          </div>
        `;
      }
    }

    loadDraft();
  </script>
</body>
</html>
