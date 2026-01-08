<?php
session_start();
require_once 'backend/auth.php';
require_once 'backend/db.php';

requireAuth();

$user = getUserSession();
$pdo = db();
$teamId = $user['team_id'] ?? null;

if (!$teamId) {
    header('Location: /onboarding.php');
    exit;
}

// Buscar liga do time do usuário
$stmt = $pdo->prepare("SELECT league FROM teams WHERE id = ?");
$stmt->execute([$teamId]);
$userLeague = $stmt->fetchColumn();
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
      <img src="<?= htmlspecialchars($user['photo_url'] ?? '/img/default-team.png') ?>" alt="Time" class="team-avatar">
      <h5 class="text-white mb-1"><?= htmlspecialchars($user['city'] ?? 'Cidade') ?></h5>
      <h6 class="text-white mb-1"><?= htmlspecialchars($user['name'] ?? 'Nome') ?></h6>
      <span class="badge bg-gradient-orange"><?= htmlspecialchars($userLeague ?? 'LEAGUE') ?></span>
    </div>
    <hr style="border-color: var(--fba-border);">
    <ul class="sidebar-menu">
      <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i>Dashboard</a></li>
      <li><a href="/teams.php"><i class="bi bi-people-fill"></i>Times</a></li>
      <li><a href="/my-roster.php"><i class="bi bi-person-fill"></i>Meu Elenco</a></li>
      <li><a href="/picks.php"><i class="bi bi-calendar-check-fill"></i>Picks</a></li>
      <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i>Trades</a></li>
      <li><a href="/drafts.php" class="active"><i class="bi bi-trophy"></i>Draft</a></li>
      <li><a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i>Rankings</a></li>
      <li><a href="/history.php"><i class="bi bi-clock-history"></i>Histórico</a></li>
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

        document.getElementById('draftContainer').innerHTML = `
          <div class="row mb-4">
            <div class="col-md-6">
              <div class="card bg-dark-elevated border-0" style="border-radius: 15px;">
                <div class="card-body">
                  <h5 class="text-white mb-2">
                    <i class="bi bi-calendar3 text-orange me-2"></i>
                    Temporada ${seasonData.season.season_number}
                  </h5>
                  <p class="text-light-gray mb-0">Ano: ${seasonData.season.year}</p>
                  <p class="text-light-gray mb-0">Status: <span class="badge bg-gradient-orange">${seasonData.season.status.toUpperCase()}</span></p>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="card bg-dark-elevated border-0" style="border-radius: 15px;">
                <div class="card-body">
                  <h5 class="text-white mb-2">
                    <i class="bi bi-people text-orange me-2"></i>
                    Jogadores Disponíveis
                  </h5>
                  <h2 class="text-orange mb-0">${available.length}</h2>
                  <p class="text-light-gray mb-0">${drafted.length} já draftados</p>
                </div>
              </div>
            </div>
          </div>

          <h4 class="text-white mb-3">
            <i class="bi bi-list-stars text-orange me-2"></i>
            Jogadores Disponíveis
          </h4>
          
          <div class="row g-3">
            ${available.length === 0 ? `
              <div class="col-12">
                <div class="alert alert-warning">
                  Nenhum jogador disponível para draft no momento.
                </div>
              </div>
            ` : available.map(p => `
              <div class="col-md-6 col-lg-4">
                <div class="card bg-dark-elevated border-0 h-100" style="border-radius: 15px;">
                  <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                      <img src="${p.photo_url || '/img/default-player.png'}" 
                           alt="${p.name}" 
                           style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid var(--fba-orange);">
                      <div class="ms-3">
                        <h5 class="text-white mb-0">${p.name}</h5>
                        <p class="text-light-gray mb-0">${p.position}</p>
                      </div>
                    </div>
                    <div class="row g-2 text-center mb-2">
                      <div class="col-4">
                        <small class="text-light-gray">Idade</small>
                        <p class="text-white mb-0"><strong>${p.age}</strong></p>
                      </div>
                      <div class="col-4">
                        <small class="text-light-gray">OVR</small>
                        <p class="text-orange mb-0"><strong>${p.ovr}</strong></p>
                      </div>
                      <div class="col-4">
                        <small class="text-light-gray">Status</small>
                        <p class="text-success mb-0"><i class="bi bi-check-circle"></i></p>
                      </div>
                    </div>
                    ${p.bio ? `<p class="text-light-gray small mb-2">${p.bio}</p>` : ''}
                    ${p.strengths ? `
                      <p class="text-light-gray small mb-1">
                        <i class="bi bi-arrow-up-circle text-success me-1"></i>
                        <strong>Pontos fortes:</strong> ${p.strengths}
                      </p>
                    ` : ''}
                    ${p.weaknesses ? `
                      <p class="text-light-gray small mb-0">
                        <i class="bi bi-arrow-down-circle text-danger me-1"></i>
                        <strong>Pontos fracos:</strong> ${p.weaknesses}
                      </p>
                    ` : ''}
                  </div>
                </div>
              </div>
            `).join('')}
          </div>
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
