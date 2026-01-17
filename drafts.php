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
$isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <title>Draft - GM FBA</title>
  
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  
  <link rel="icon" type="image/x-icon" href="/img/favicon.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
  <style>
    .pick-card {
      transition: all 0.3s ease;
    }
    .pick-card.current {
      border: 2px solid var(--fba-orange) !important;
      animation: pulse 2s infinite;
    }
    .pick-card.completed {
      opacity: 0.7;
    }
    .pick-card.my-pick {
      background: rgba(252, 0, 37, 0.1) !important;
    }
    @keyframes pulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(252, 0, 37, 0.4); }
      50% { box-shadow: 0 0 0 10px rgba(252, 0, 37, 0); }
    }
    .player-select-card {
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .player-select-card:hover {
      transform: translateY(-2px);
      border-color: var(--fba-orange) !important;
    }
    .traded-badge {
      font-size: 0.7rem;
    }
  </style>
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
      <li><a href="/free-agency.php"><i class="bi bi-hammer"></i>Leilões</a></li>
      <li><a href="/drafts.php" class="active"><i class="bi bi-trophy"></i>Draft</a></li>
      <li><a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i>Rankings</a></li>
      <li><a href="/history.php"><i class="bi bi-clock-history"></i>Histórico</a></li>
      <?php if ($isAdmin): ?>
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
        Draft
      </h1>
    </div>

    <div id="draftContainer">
      <div class="text-center py-5">
        <div class="spinner-border text-orange"></div>
      </div>
    </div>
  </div>

  <!-- Modal para selecionar jogador -->
  <div class="modal fade" id="pickModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-person-plus me-2 text-orange"></i>Escolher Jogador</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="text" id="playerSearch" class="form-control mb-3 bg-dark text-white border-secondary" placeholder="Buscar jogador...">
          <div id="availablePlayers" class="row g-3">
            <div class="text-center py-3">
              <div class="spinner-border text-orange"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script>
    const userLeague = '<?= $userLeague ?>';
    const userTeamId = <?= (int)$team['id'] ?>;
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    let currentDraftSession = null;
    let availablePlayersList = [];
    let refreshInterval = null;

    const api = async (path, options = {}) => {
      const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
      let body = {};
      try { body = await res.json(); } catch {}
      if (!res.ok) throw body;
      return body;
    };

    async function loadDraft() {
      try {
        // Buscar draft ativo da liga
        const draftData = await api(`draft.php?action=active_draft&league=${userLeague}`);
        
        if (!draftData.draft) {
          document.getElementById('draftContainer').innerHTML = `
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              Não há draft ativo para a liga ${userLeague} no momento.
              ${isAdmin ? '<br><small>Use a página de Temporadas para criar uma sessão de draft.</small>' : ''}
            </div>
          `;
          return;
        }

        currentDraftSession = draftData.draft;

        // Buscar ordem e picks
        const orderData = await api(`draft.php?action=draft_order&draft_session_id=${currentDraftSession.id}`);
        const picks = orderData.order || [];
        const session = orderData.session;

        renderDraft(session, picks);

        // Auto-refresh se draft em andamento
        if (session.status === 'in_progress') {
          if (refreshInterval) clearInterval(refreshInterval);
          refreshInterval = setInterval(loadDraft, 10000);
        } else {
          if (refreshInterval) clearInterval(refreshInterval);
        }

      } catch (e) {
        console.error(e);
        document.getElementById('draftContainer').innerHTML = `
          <div class="alert alert-danger">
            Erro ao carregar draft: ${e.error || 'Desconhecido'}
          </div>
        `;
      }
    }

    function renderDraft(session, picks) {
      const round1Picks = picks.filter(p => p.round == 1);
      const round2Picks = picks.filter(p => p.round == 2);

      const statusBadge = {
        'setup': '<span class="badge bg-warning">Configurando</span>',
        'in_progress': '<span class="badge bg-success">Em Andamento</span>',
        'completed': '<span class="badge bg-secondary">Concluído</span>'
      };

      // Verificar se é a vez do usuário
      let currentPickInfo = null;
      if (session.status === 'in_progress') {
        const allPicks = [...round1Picks, ...round2Picks];
        currentPickInfo = allPicks.find(p => p.round == session.current_round && p.pick_position == session.current_pick && !p.picked_player_id);
      }

      const isMyTurn = currentPickInfo && parseInt(currentPickInfo.team_id) === userTeamId;

      document.getElementById('draftContainer').innerHTML = `
        <!-- Header do Draft -->
        <div class="row g-3 mb-4">
          <div class="col-md-6">
            <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <h5 class="text-white mb-2">
                      <i class="bi bi-calendar3 text-orange me-2"></i>
                      Temporada ${session.season_number || currentDraftSession.season_number}
                    </h5>
                    <p class="text-light-gray mb-0">Ano: ${session.year || currentDraftSession.year}</p>
                    <p class="text-light-gray mb-0">Liga: <span class="badge bg-gradient-orange">${userLeague}</span></p>
                  </div>
                  <div>
                    ${statusBadge[session.status]}
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card ${isMyTurn ? 'bg-success' : 'bg-dark-panel'} border-orange" style="border-radius: 15px;">
              <div class="card-body text-center">
                ${session.status === 'in_progress' ? `
                  <h5 class="text-white mb-2">
                    <i class="bi bi-clock text-orange me-2"></i>
                    Rodada ${session.current_round} - Pick ${session.current_pick}
                  </h5>
                  ${currentPickInfo ? `
                    <p class="mb-1 ${isMyTurn ? 'text-white fw-bold' : 'text-light-gray'}">
                      ${isMyTurn ? '🎉 É A SUA VEZ!' : `Vez de: ${currentPickInfo.team_city} ${currentPickInfo.team_name}`}
                    </p>
                    ${currentPickInfo.traded_from_team_id ? `
                      <span class="badge bg-info traded-badge">
                        <i class="bi bi-arrow-left-right me-1"></i>
                        Pick trocada (original: ${currentPickInfo.original_city} ${currentPickInfo.original_name})
                      </span>
                    ` : ''}
                  ` : ''}
                  ${isMyTurn ? `
                    <button class="btn btn-light mt-2" onclick="openPickModal()">
                      <i class="bi bi-person-plus me-2"></i>Fazer Minha Pick
                    </button>
                  ` : ''}
                ` : session.status === 'setup' ? `
                  <h5 class="text-white mb-2">
                    <i class="bi bi-gear text-orange me-2"></i>
                    Aguardando início
                  </h5>
                  <p class="text-light-gray mb-0">O administrador está configurando o draft</p>
                ` : `
                  <h5 class="text-white mb-2">
                    <i class="bi bi-check-circle text-success me-2"></i>
                    Draft Concluído!
                  </h5>
                `}
              </div>
            </div>
          </div>
        </div>

        ${isAdmin && session.status === 'setup' ? `
          <div class="alert alert-warning mb-4">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Admin:</strong> Configure a ordem do draft na página de 
            <a href="/temporadas.php" class="alert-link">Temporadas</a> e inicie quando estiver pronto.
          </div>
        ` : ''}

        <!-- Rodada 1 -->
        <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
          <div class="card-header bg-transparent border-orange">
            <h5 class="text-white mb-0">
              <i class="bi bi-1-circle-fill me-2 text-orange"></i>
              1ª Rodada
            </h5>
          </div>
          <div class="card-body">
            <div class="row g-2">
              ${round1Picks.map((p, idx) => renderPickCard(p, session, idx + 1)).join('')}
            </div>
          </div>
        </div>

        <!-- Rodada 2 -->
        <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
          <div class="card-header bg-transparent border-orange">
            <h5 class="text-white mb-0">
              <i class="bi bi-2-circle-fill me-2 text-orange"></i>
              2ª Rodada
            </h5>
          </div>
          <div class="card-body">
            <div class="row g-2">
              ${round2Picks.map((p, idx) => renderPickCard(p, session, idx + 1)).join('')}
            </div>
          </div>
        </div>
      `;
    }

    function renderPickCard(pick, session, displayNum) {
      const isCurrent = session.status === 'in_progress' && 
                        pick.round == session.current_round && 
                        pick.pick_position == session.current_pick &&
                        !pick.picked_player_id;
      const isCompleted = pick.picked_player_id !== null;
      const isMyPick = parseInt(pick.team_id) === userTeamId;
      const wasTraded = pick.traded_from_team_id !== null;

      let cardClass = 'pick-card';
      if (isCurrent) cardClass += ' current';
      if (isCompleted) cardClass += ' completed';
      if (isMyPick) cardClass += ' my-pick';

      return `
        <div class="col-md-4 col-lg-3">
          <div class="card bg-dark border-secondary ${cardClass}" style="border-radius: 10px;">
            <div class="card-body p-2">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <span class="badge ${isCompleted ? 'bg-success' : 'bg-secondary'}">
                  #${pick.pick_position}
                </span>
                ${wasTraded ? `
                  <span class="badge bg-info traded-badge" title="Pick trocada de ${pick.traded_from_city} ${pick.traded_from_name}">
                    <i class="bi bi-arrow-left-right"></i>
                  </span>
                ` : ''}
              </div>
              <div class="text-center">
                <small class="text-light-gray d-block" style="font-size: 0.7rem;">
                  ${wasTraded ? `Original: ${pick.original_city}` : ''}
                </small>
                <strong class="text-white" style="font-size: 0.85rem;">
                  ${pick.team_city} ${pick.team_name}
                </strong>
                ${isCompleted ? `
                  <div class="mt-2 p-2 bg-success bg-opacity-25 rounded">
                    <small class="text-success d-block fw-bold">${pick.player_name}</small>
                    <span class="badge bg-orange" style="font-size: 0.65rem;">${pick.player_position}</span>
                    <span class="badge bg-secondary" style="font-size: 0.65rem;">OVR ${pick.player_ovr}</span>
                  </div>
                ` : `
                  <div class="mt-2 p-2 bg-secondary bg-opacity-25 rounded">
                    <small class="text-light-gray">${isCurrent ? 'Escolhendo...' : 'Aguardando'}</small>
                  </div>
                `}
              </div>
            </div>
          </div>
        </div>
      `;
    }

    async function openPickModal() {
      if (!currentDraftSession) return;

      const modal = new bootstrap.Modal(document.getElementById('pickModal'));
      modal.show();

      const container = document.getElementById('availablePlayers');
      container.innerHTML = '<div class="col-12 text-center py-3"><div class="spinner-border text-orange"></div></div>';

      try {
        const data = await api(`draft.php?action=available_players&season_id=${currentDraftSession.season_id}`);
        availablePlayersList = data.players || [];
        renderAvailablePlayers(availablePlayersList);
        
        // Setup search
        document.getElementById('playerSearch').addEventListener('input', (e) => {
          const q = e.target.value.toLowerCase();
          const filtered = availablePlayersList.filter(p => 
            p.name.toLowerCase().includes(q) || 
            p.position.toLowerCase().includes(q)
          );
          renderAvailablePlayers(filtered);
        });
      } catch (e) {
        container.innerHTML = `<div class="col-12 text-center text-danger py-3">Erro: ${e.error || 'Desconhecido'}</div>`;
      }
    }

    function renderAvailablePlayers(players) {
      const container = document.getElementById('availablePlayers');
      if (players.length === 0) {
        container.innerHTML = '<div class="col-12 text-center text-light-gray py-3">Nenhum jogador encontrado</div>';
        return;
      }
      container.innerHTML = players.map(p => `
        <div class="col-md-4 col-lg-3">
          <div class="card bg-dark border-secondary player-select-card" onclick="makePick(${p.id}, '${p.name.replace(/'/g, "\\'")}')" style="border-radius: 10px;">
            <div class="card-body p-3 text-center">
              <h6 class="text-white mb-1">${p.name}</h6>
              <span class="badge bg-orange">${p.position}</span>
              <span class="badge bg-success">OVR ${p.ovr}</span>
              <p class="text-light-gray mb-0 mt-2" style="font-size: 0.8rem;">${p.age} anos</p>
            </div>
          </div>
        </div>
      `).join('');
    }

    async function makePick(playerId, playerName) {
      if (!confirm(`Confirma a escolha de ${playerName}?`)) return;

      try {
        const result = await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'make_pick',
            draft_session_id: currentDraftSession.id,
            player_id: playerId
          })
        });

        alert(result.message);
        bootstrap.Modal.getInstance(document.getElementById('pickModal')).hide();
        loadDraft();
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }

    loadDraft();
  </script>
  <script src="/js/pwa.js"></script>
</body>
</html>
