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
  
  <link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
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
      <li><a href="/free-agency.php"><i class="bi bi-coin"></i>Free Agency</a></li>
  <li><a href="/leilao.php"><i class="bi bi-hammer"></i>Leilão</a></li>
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
    <div class="page-header mb-4">
      <h1 class="text-white fw-bold mb-0">
        <i class="bi bi-trophy me-2 text-orange"></i>
        Draft
      </h1>
      <div class="page-actions">
        <?php if ($isAdmin): ?>
        <button class="btn btn-outline-light me-2" onclick="openAddDraftPlayerModal()">
          <i class="bi bi-person-plus me-2"></i>
          Adicionar jogador
        </button>
        <button class="btn btn-outline-orange" onclick="toggleHistoryView()">
          <i class="bi bi-clock-history me-2"></i>
          <span id="viewToggleText">Ver Histórico</span>
        </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- View do Draft Ativo -->
    <div id="activeDraftView">
      <div id="draftContainer">
        <div class="text-center py-5">
          <div class="spinner-border text-orange"></div>
        </div>
      </div>
    </div>

    <!-- View do Histórico de Drafts (oculta por padrão) -->
    <?php if ($isAdmin): ?>
    <div id="historyView" style="display: none;">
      <!-- Seletor de Liga -->
      <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
        <div class="card-body">
          <div class="row align-items-center">
            <div class="col-md-3">
              <label class="text-white mb-0">
                <i class="bi bi-funnel text-orange me-2"></i>
                Selecionar Liga:
              </label>
            </div>
            <div class="col-md-6">
              <select id="leagueSelector" class="form-select bg-dark text-white border-secondary" onchange="loadHistoryForLeague()">
                <option value="ELITE">ELITE</option>
                <option value="NEXT">NEXT</option>
                <option value="RISE">RISE</option>
                <option value="ROOKIE">ROOKIE</option>
              </select>
            </div>
            <div class="col-md-3 text-end">
              <span class="badge bg-gradient-orange" id="selectedLeagueBadge">ROOKIE</span>
            </div>
          </div>
        </div>
      </div>
      
      <div id="historyContainer">
        <div class="text-center py-5">
          <div class="spinner-border text-orange"></div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <div class="mt-4" id="finalizeDraftContainer" style="display: none;">
      <div class="card bg-dark-panel border-orange">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h6 class="text-white mb-1"><i class="bi bi-check2-circle me-2 text-orange"></i>Finalizar Draft</h6>
            <small class="text-light-gray">Use quando a seleção estiver concluída.</small>
          </div>
          <button class="btn btn-orange" type="button" onclick="finalizeDraft()">
            <i class="bi bi-flag-fill me-2"></i>Finalizar Draft
          </button>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Modal para selecionar jogador -->
  <div class="modal fade" id="pickModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white">
            <i class="bi bi-person-plus me-2 text-orange"></i>
            <span id="pickModalTitle">Escolher Jogador</span>
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="text" id="playerSearch" class="form-control mb-3 bg-dark text-white border-secondary" placeholder="Buscar jogador...">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <small class="text-light-gray">Jogadores disponíveis</small>
            <small class="text-light-gray" id="availablePlayersCount">0</small>
          </div>
          <div id="availablePlayers" class="row g-3">
            <div class="text-center py-3">
              <div class="spinner-border text-orange"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal para preencher pick de draft passado (Admin) -->
  <div class="modal fade" id="fillPastPickModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white">
            <i class="bi bi-pencil-square me-2 text-orange"></i>
            Preencher Pick - <span id="fillPickTeamName"></span>
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="text" id="pastPlayerSearch" class="form-control mb-3 bg-dark text-white border-secondary" placeholder="Buscar jogador...">
          <div id="pastPlayersDropdown" class="row g-3">
            <div class="text-center py-3">
              <div class="spinner-border text-orange"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal para adicionar novo jogador ao draft (Admin) -->
  <div class="modal fade" id="addDraftPlayerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-person-plus me-2 text-orange"></i>Novo jogador do draft</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="addDraftPlayerForm">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label text-white">Nome</label>
                <input type="text" class="form-control bg-dark text-white border-secondary" name="name" required>
              </div>
              <div class="col-md-3">
                <label class="form-label text-white">Posição</label>
                <input type="text" class="form-control bg-dark text-white border-secondary" name="position" maxlength="3" placeholder="PG" required>
              </div>
              <div class="col-md-3">
                <label class="form-label text-white">Idade</label>
                <input type="number" class="form-control bg-dark text-white border-secondary" name="age" min="16" max="50" required>
              </div>
              <div class="col-md-4">
                <label class="form-label text-white">OVR</label>
                <input type="number" class="form-control bg-dark text-white border-secondary" name="ovr" min="40" max="99" required>
              </div>
            </div>
          </form>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-orange" onclick="submitAddDraftPlayer()">
            <i class="bi bi-check2-circle me-1"></i>Adicionar jogador
          </button>
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
    let allPlayersList = [];
    let refreshInterval = null;
    let currentView = 'active'; // 'active' ou 'history'
    let currentPickForFill = null;
    let currentDraftSessionForFill = null;
    let currentSeasonIdView = null; // Para rastrear qual temporada está sendo visualizada
    let currentDraftStatusView = null;
    let selectedLeague = userLeague; // Liga atualmente selecionada no histórico
  let allowPickSelections = true;

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
  const isAdminRound2 = isAdmin && session.status === 'in_progress' && Number(session.current_round) === 2;
        if (session.status === 'in_progress' && !isAdminRound2) {
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
      const round2Raw = picks.filter(p => p.round == 2);
      const round1OrderMap = new Map(round1Picks.map(p => [String(p.team_id), Number(p.pick_position)]));
      const round2Picks = [...round2Raw].sort((a, b) => {
        const aOrder = round1OrderMap.get(String(a.team_id)) ?? a.pick_position;
        const bOrder = round1OrderMap.get(String(b.team_id)) ?? b.pick_position;
        return aOrder - bOrder;
      });

      const statusBadge = {
        'setup': '<span class="badge bg-warning">Configurando</span>',
        'in_progress': '<span class="badge bg-success">Em Andamento</span>',
        'completed': '<span class="badge bg-secondary">Concluído</span>'
      };

      // Verificar se é a vez do usuário
      let currentPickInfo = null;
      if (session.status === 'in_progress') {
        const allPicks = [...round1Picks, ...round2Raw];
        currentPickInfo = allPicks.find(p => p.round == session.current_round && p.pick_position == session.current_pick && !p.picked_player_id);
      }

      const isMyTurn = currentPickInfo && parseInt(currentPickInfo.team_id) === userTeamId && session.current_round == 1;
      const showRound2Admin = isAdmin && session.status === 'in_progress' && session.current_round == 2;
      const round2History = round2Raw
        .filter(p => p.picked_player_id)
        .sort((a, b) => {
          const aPos = round1OrderMap.get(String(a.team_id)) ?? a.pick_position;
          const bPos = round1OrderMap.get(String(b.team_id)) ?? b.pick_position;
          return aPos - bPos;
        });

      document.getElementById('draftContainer').innerHTML = `
        <!-- Header do Draft -->
        <div class="row g-3 mb-4">
          <div class="col-md-6 col-lg-4">
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
          <div class="col-md-6 col-lg-4">
            <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
              <div class="card-body d-flex flex-column justify-content-between h-100">
                <div>
                  <h5 class="text-white mb-2">
                    <i class="bi bi-search text-orange me-2"></i>
                    Ver opções
                  </h5>
                  <p class="text-light-gray mb-0">Confira quem ainda está disponível no draft.</p>
                </div>
                <div class="mt-3">
                  <button class="btn btn-outline-light w-100" type="button" onclick="openOptionsModal()">
                    <i class="bi bi-eye me-2"></i>Ver jogadores disponíveis
                  </button>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-6 col-lg-4">
            <div class="card ${isMyTurn ? 'bg-success' : 'bg-dark-panel'} border-orange" style="border-radius: 15px;">
              <div class="card-body text-center">
                ${session.status === 'in_progress' ? `
                  ${Number(session.current_round) === 2 ? `
                    <h5 class="text-white mb-2">
                      <i class="bi bi-clock text-orange me-2"></i>
                      2ª rodada
                    </h5>
                    <p class="text-light-gray mb-0">Envie sua escolha para o admin.</p>
                  ` : `
                    <h5 class="text-white mb-2">
                      <i class="bi bi-clock text-orange me-2"></i>
                      Rodada ${session.current_round} - Pick ${session.current_pick}
                    </h5>
                    ${currentPickInfo ? `
                      <p class="mb-1 ${isMyTurn ? 'text-white fw-bold' : 'text-light-gray'}">
                        ${isMyTurn ? '🎉 É A SUA VEZ!' : `Vez de: ${currentPickInfo.team_city} ${currentPickInfo.team_name}`}
                      </p>
                    ` : ''}
                    ${isMyTurn ? `
                      <button class="btn btn-light mt-2" onclick="openPickModal()">
                        <i class="bi bi-person-plus me-2"></i>Fazer Minha Pick
                      </button>
                    ` : ''}
                  `}
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
            ${showRound2Admin ? `
              <div class="card bg-dark border-secondary mb-3" style="border-radius: 12px;">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                      <h6 class="text-white mb-1"><i class="bi bi-person-check me-2 text-orange"></i>Seleção Admin - 2ª Rodada</h6>
                      <small class="text-light-gray">Escolha o time e o jogador disponível.</small>
                    </div>
                    <span class="badge bg-secondary" id="round2RemainingBadge">-</span>
                  </div>
                  <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                      <label class="form-label text-white">Time</label>
                      <select class="form-select bg-dark text-white border-secondary" id="round2TeamSelect">
                        <option value="">Selecione o time...</option>
                      </select>
                    </div>
                    <div class="col-md-5">
                      <label class="form-label text-white">Jogador</label>
                      <select class="form-select bg-dark text-white border-secondary" id="round2PlayerSelect">
                        <option value="">Selecione o jogador...</option>
                      </select>
                    </div>
                    <div class="col-md-2">
                      <button class="btn btn-orange w-100" onclick="submitRound2Pick()">
                        <i class="bi bi-check2-circle me-1"></i>Adicionar
                      </button>
                    </div>
                  </div>
                  <div class="mt-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <small class="text-light-gray">Jogadores disponíveis</small>
                      <small class="text-light-gray" id="round2PlayersCount">0</small>
                    </div>
                    <div class="mb-2">
                      <button class="btn btn-sm btn-outline-light" type="button" onclick="openAddDraftPlayerModal()">
                        <i class="bi bi-person-plus me-1"></i>Adicionar novo jogador ao draft
                      </button>
                    </div>
                    <div id="round2PlayersList" class="row g-2"></div>
                  </div>
                </div>
              </div>
            ` : ''}
            <div class="card bg-dark border-secondary" style="border-radius: 12px;">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h6 class="text-white mb-0">
                    <i class="bi bi-clock-history me-2 text-orange"></i>Histórico da 2ª rodada
                  </h6>
                  <span class="badge bg-secondary">${round2History.length}</span>
                </div>
                ${round2History.length ? `
                  <div class="list-group">
                    ${round2History.map((pick) => `
                      <div class="list-group-item bg-dark text-white border-secondary d-flex justify-content-between align-items-center">
                        <div>
                          <strong>${pick.player_name}</strong>
                          <small class="text-light-gray d-block">${pick.team_city} ${pick.team_name}</small>
                        </div>
                        <span class="badge bg-orange">R2</span>
                      </div>
                    `).join('')}
                  </div>
                ` : `
                  <div class="text-light-gray">Nenhuma pick registrada na 2ª rodada.</div>
                `}
              </div>
            </div>
          </div>
        </div>
      `;

      const finalizeContainer = document.getElementById('finalizeDraftContainer');
      if (finalizeContainer) {
        finalizeContainer.style.display = (session.status === 'in_progress') ? 'block' : 'none';
      }

      if (showRound2Admin) {
        initRound2AdminPanel(round2Raw);
      }
    }

    function renderPickCard(pick, session, displayNum) {
      const isCurrent = session.status === 'in_progress' && 
                        pick.round == session.current_round && 
                        pick.pick_position == session.current_pick &&
                        !pick.picked_player_id;
      const isCompleted = pick.picked_player_id !== null;
      const isMyPick = parseInt(pick.team_id) === userTeamId;
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
                
              </div>
              <div class="text-center">
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
      await openPlayersModal(true);
    }

    async function openOptionsModal() {
      await openPlayersModal(false);
    }

    async function openPlayersModal(allowPick) {
      if (!currentDraftSession) return;
      allowPickSelections = allowPick;

      const titleEl = document.getElementById('pickModalTitle');
      if (titleEl) {
        titleEl.textContent = allowPick ? 'Escolher Jogador' : 'Jogadores disponíveis';
      }

      const modal = new bootstrap.Modal(document.getElementById('pickModal'));
      modal.show();

      const container = document.getElementById('availablePlayers');
      container.innerHTML = '<div class="col-12 text-center py-3"><div class="spinner-border text-orange"></div></div>';

      try {
        const data = await api(`draft.php?action=available_players&season_id=${currentDraftSession.season_id}`);
        availablePlayersList = data.players || [];
        renderAvailablePlayers(availablePlayersList, allowPickSelections);
        
        // Setup search
        const searchInput = document.getElementById('playerSearch');
        if (searchInput) {
          searchInput.value = '';
          searchInput.oninput = (e) => {
            const q = e.target.value.toLowerCase();
            const filtered = availablePlayersList.filter(p => 
              p.name.toLowerCase().includes(q) || 
              p.position.toLowerCase().includes(q)
            );
            renderAvailablePlayers(filtered, allowPickSelections);
          };
        }
      } catch (e) {
        container.innerHTML = `<div class="col-12 text-center text-danger py-3">Erro: ${e.error || 'Desconhecido'}</div>`;
      }
    }

    function renderAvailablePlayers(players, allowPick) {
      const container = document.getElementById('availablePlayers');
      const countEl = document.getElementById('availablePlayersCount');
      if (countEl) {
        countEl.textContent = `${players.length}`;
      }
      if (players.length === 0) {
        container.innerHTML = '<div class="col-12 text-center text-light-gray py-3">Nenhum jogador encontrado</div>';
        return;
      }
      container.innerHTML = players.map(p => `
        <div class="col-md-4 col-lg-3">
          <div class="card bg-dark border-secondary ${allowPick ? 'player-select-card' : ''}" ${allowPick ? `onclick="makePick(${p.id}, '${p.name.replace(/'/g, "\\'")}')"` : ''} style="border-radius: 10px;">
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

    function initRound2AdminPanel(round2PicksRaw) {
      if (!currentDraftSession) return;
      const teamSelect = document.getElementById('round2TeamSelect');
      const playerSelect = document.getElementById('round2PlayerSelect');
      const playersList = document.getElementById('round2PlayersList');
      const playersCount = document.getElementById('round2PlayersCount');
      const remainingBadge = document.getElementById('round2RemainingBadge');
      if (!teamSelect || !playerSelect || !playersList) return;

      const unpickedRound2 = round2PicksRaw.filter(p => !p.picked_player_id);
      remainingBadge.textContent = `${unpickedRound2.length} picks pendentes`;

      teamSelect.innerHTML = '<option value="">Selecione o time...</option>';
      const teamMap = new Map();
      round2PicksRaw.forEach(p => {
        const key = String(p.team_id);
        if (!teamMap.has(key)) {
          teamMap.set(key, `${p.team_city} ${p.team_name}`);
        }
      });

      Array.from(teamMap.entries())
        .sort((a, b) => a[1].localeCompare(b[1]))
        .forEach(([id, label]) => {
          const option = document.createElement('option');
          option.value = id;
          option.textContent = label;
          teamSelect.appendChild(option);
        });

      refreshRound2Players();

      teamSelect.onchange = () => {
        refreshRound2Players();
      };
    }

    async function refreshRound2Players() {
      if (!currentDraftSession) return;
      const teamSelect = document.getElementById('round2TeamSelect');
      const playerSelect = document.getElementById('round2PlayerSelect');
      const playersList = document.getElementById('round2PlayersList');
      const playersCount = document.getElementById('round2PlayersCount');
      if (!playerSelect || !playersList) return;

      playerSelect.innerHTML = '<option value="">Selecione o jogador...</option>';
      playersList.innerHTML = '<div class="col-12 text-center py-2"><div class="spinner-border text-orange"></div></div>';

      try {
        const data = await api(`draft.php?action=available_players&season_id=${currentDraftSession.season_id}`);
        const players = data.players || [];
        playersCount.textContent = `${players.length}`;

        players.forEach(p => {
          const option = document.createElement('option');
          option.value = p.id;
          option.textContent = `${p.name} (${p.position}) - OVR ${p.ovr}`;
          playerSelect.appendChild(option);
        });

        if (players.length === 0) {
          playersList.innerHTML = '<div class="col-12 text-center text-light-gray py-2">Nenhum jogador disponível.</div>';
          return;
        }

        playersList.innerHTML = players.map(p => `
          <div class="col-md-4 col-lg-3">
            <div class="card bg-dark border-secondary" style="border-radius: 10px;">
              <div class="card-body p-2 text-center">
                <div class="text-white" style="font-size: 0.85rem;">${p.name}</div>
                <div class="mt-1">
                  <span class="badge bg-orange">${p.position}</span>
                  <span class="badge bg-success">OVR ${p.ovr}</span>
                </div>
              </div>
            </div>
          </div>
        `).join('');
      } catch (e) {
        playersList.innerHTML = `<div class="col-12 text-center text-danger py-2">Erro: ${e.error || 'Desconhecido'}</div>`;
      }
    }

    async function submitRound2Pick() {
      if (!currentDraftSession) return;
      const teamSelect = document.getElementById('round2TeamSelect');
      const playerSelect = document.getElementById('round2PlayerSelect');
      if (!teamSelect || !playerSelect) return;
      const teamId = teamSelect.value;
      const playerId = playerSelect.value;

      if (!teamId) {
        alert('Selecione o time.');
        return;
      }
      if (!playerId) {
        alert('Selecione o jogador.');
        return;
      }

      try {
        const result = await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'make_pick',
            draft_session_id: currentDraftSession.id,
            player_id: parseInt(playerId, 10),
            team_id: parseInt(teamId, 10)
          })
        });
        alert(result.message || 'Pick registrada!');
        await loadDraft();
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }

    function openAddDraftPlayerModal() {
      if (!currentDraftSession) return;
      const modalEl = document.getElementById('addDraftPlayerModal');
      if (!modalEl) return;
      const form = document.getElementById('addDraftPlayerForm');
      if (form) form.reset();
      const modal = new bootstrap.Modal(modalEl);
      modal.show();
    }

    async function submitAddDraftPlayer() {
      if (!currentDraftSession) return;
      const form = document.getElementById('addDraftPlayerForm');
      if (!form) return;
      const formData = new FormData(form);
      const payload = {
        action: 'add_draft_player',
        draft_session_id: currentDraftSession.id,
        name: String(formData.get('name') || '').trim(),
        position: String(formData.get('position') || '').trim().toUpperCase(),
        age: Number(formData.get('age')),
        ovr: Number(formData.get('ovr'))
      };

      if (!payload.name || !payload.position || !payload.age || !payload.ovr) {
        alert('Preencha todos os campos.');
        return;
      }

      try {
        const result = await api('draft.php', {
          method: 'POST',
          body: JSON.stringify(payload)
        });
        alert(result.message || 'Jogador adicionado!');
        bootstrap.Modal.getInstance(document.getElementById('addDraftPlayerModal')).hide();
        refreshRound2Players();
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }

    async function finalizeDraft() {
      if (!currentDraftSession) return;
      if (!confirm('Finalizar o draft agora?')) return;
      try {
        const result = await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'finalize_draft',
            draft_session_id: currentDraftSession.id
          })
        });
        alert(result.message || 'Draft finalizado!');
        loadDraft();
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
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

    // Toggle entre view ativa e histórico
    function toggleHistoryView() {
      if (currentView === 'active') {
        currentView = 'history';
        document.getElementById('activeDraftView').style.display = 'none';
        document.getElementById('historyView').style.display = 'block';
        document.getElementById('viewToggleText').textContent = 'Ver Draft Ativo';
        if (refreshInterval) clearInterval(refreshInterval);
        
        // Configurar seletor de liga com a liga atual do usuário
        const leagueSelector = document.getElementById('leagueSelector');
        if (leagueSelector) {
          leagueSelector.value = userLeague;
          selectedLeague = userLeague;
          document.getElementById('selectedLeagueBadge').textContent = userLeague;
        }
        
        loadHistory();
      } else {
        currentView = 'active';
        document.getElementById('activeDraftView').style.display = 'block';
        document.getElementById('historyView').style.display = 'none';
        document.getElementById('viewToggleText').textContent = 'Ver Histórico';
        loadDraft();
      }
    }

    // Carregar histórico da liga selecionada
    function loadHistoryForLeague() {
      const leagueSelector = document.getElementById('leagueSelector');
      selectedLeague = leagueSelector.value;
      document.getElementById('selectedLeagueBadge').textContent = selectedLeague;
      loadHistory();
    }

    // Carregar histórico de drafts
    async function loadHistory() {
      try {
        const data = await api(`draft.php?action=draft_history&league=${selectedLeague}`);
        const seasons = data.seasons || [];
        
        if (seasons.length === 0) {
          document.getElementById('historyContainer').innerHTML = `
            <div class="alert alert-info">
              <i class="bi bi-info-circle me-2"></i>
              Nenhum histórico de draft encontrado para a liga ${selectedLeague}.
            </div>
          `;
          return;
        }

        renderHistory(seasons);
      } catch (e) {
        console.error(e);
        document.getElementById('historyContainer').innerHTML = `
          <div class="alert alert-danger">
            Erro ao carregar histórico: ${e.error || 'Desconhecido'}
          </div>
        `;
      }
    }

    function renderHistory(seasons) {
      let html = '<div class="row g-3">';
      
      seasons.forEach(season => {
        const statusBadge = getHistoryStatusBadge(season);
        const actionButton = getHistoryActionButton(season);
        
        html += `
          <div class="col-md-6 col-lg-4">
            <div class="card bg-dark-panel border-orange h-100" style="border-radius: 15px;">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                  <div>
                    <h5 class="text-white mb-1">T${season.season_number} Ano ${season.year}</h5>
                    <small class="text-light-gray">Liga: ${season.league}</small>
                  </div>
                  ${statusBadge}
                </div>
                ${actionButton}
              </div>
            </div>
          </div>
        `;
      });
      
      html += '</div>';
      document.getElementById('historyContainer').innerHTML = html;
    }

    function getHistoryStatusBadge(season) {
      if (season.draft_status === 'in_progress') {
        return '<span class="badge bg-success">Em Andamento</span>';
      } else if (season.draft_status === 'completed') {
        return '<span class="badge bg-secondary">Concluído</span>';
      } else if (season.draft_status === 'setup') {
        return '<span class="badge bg-warning">Configurando</span>';
      } else {
        return '<span class="badge bg-danger">Sem Draft</span>';
      }
    }

    function getHistoryActionButton(season) {
      if (!season.draft_session_id) {
        return `<p class="text-light-gray text-center mb-0">Sem sessão de draft</p>`;
      }
      
      return `
        <button class="btn btn-outline-orange w-100" onclick="viewDraftHistory(${season.id}, '${season.draft_status}', ${season.draft_session_id})">
          <i class="bi bi-eye me-2"></i>Ver Ordem do Draft
        </button>
      `;
    }

    // Visualizar draft histórico
    async function viewDraftHistory(seasonId, draftStatus, draftSessionId) {
      currentSeasonIdView = seasonId;
      currentDraftStatusView = draftStatus;
      currentDraftSessionForFill = draftSessionId;
      
      try {
        const data = await api(`draft.php?action=draft_history&season_id=${seasonId}`);
        const order = data.draft_order || [];
        const season = data.season;
        
        if (order.length === 0) {
          alert('Nenhuma ordem de draft encontrada para esta temporada');
          return;
        }

        renderHistoricalDraft(season, order, draftStatus, draftSessionId);
      } catch (e) {
        console.error(e);
        alert('Erro ao carregar draft: ' + (e.error || 'Desconhecido'));
      }
    }

    function renderHistoricalDraft(season, picks, draftStatus, draftSessionId) {
      const round1Picks = picks.filter(p => p.round == 1);
      const round2Picks = picks.filter(p => p.round == 2);

      const statusBadge = {
        'setup': '<span class="badge bg-warning">Configurando</span>',
        'in_progress': '<span class="badge bg-success">Em Andamento</span>',
        'completed': '<span class="badge bg-secondary">Concluído</span>'
      };

      document.getElementById('historyContainer').innerHTML = `
        <div class="mb-3">
          <button class="btn btn-outline-secondary" onclick="loadHistory()">
            <i class="bi bi-arrow-left me-2"></i>Voltar ao Histórico
          </button>
        </div>

        <!-- Header do Draft Histórico -->
        <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h4 class="text-white mb-2">
                  <i class="bi bi-calendar3 text-orange me-2"></i>
                  Temporada ${season.season_number} - Ano ${season.year}
                </h4>
                <p class="text-light-gray mb-0">Liga: <span class="badge bg-gradient-orange">${season.league}</span></p>
              </div>
              <div>
                ${statusBadge[draftStatus] || statusBadge['completed']}
              </div>
            </div>
          </div>
        </div>

        <!-- Mensagem informativa para Admin -->
        ${isAdmin ? `
          <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Admin:</strong> Você pode preencher picks vazias clicando nos cards "Aguardando".
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
              ${round1Picks.map((p, idx) => renderHistoricalPickCard(p, draftStatus, draftSessionId)).join('')}
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
              ${round2Picks.map((p, idx) => renderHistoricalPickCard(p, draftStatus, draftSessionId)).join('')}
            </div>
          </div>
        </div>
      `;
    }

    function renderHistoricalPickCard(pick, draftStatus, draftSessionId) {
      const isCompleted = pick.picked_player_id !== null;
      let cardClass = 'pick-card';
      if (isCompleted) cardClass += ' completed';

      // Admin pode preencher qualquer pick vazia no histórico (completed ou in_progress)
      const canEdit = isAdmin && !isCompleted;

      return `
        <div class="col-md-4 col-lg-3">
          <div class="card bg-dark border-secondary ${cardClass}" 
               style="border-radius: 10px; ${canEdit ? 'cursor: pointer;' : ''}"
               ${canEdit ? `onclick="openFillPastPickModal(${pick.id}, '${pick.team_city} ${pick.team_name}', ${draftSessionId})"` : ''}>
            <div class="card-body p-2">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <span class="badge ${isCompleted ? 'bg-success' : 'bg-secondary'}">
                  #${pick.pick_position}
                </span>
                
              </div>
              <div class="text-center">
                <strong class="text-white" style="font-size: 0.85rem;">
                  ${pick.team_city} ${pick.team_name}
                </strong>
                ${isCompleted ? `
                  <div class="mt-2 p-2 bg-success bg-opacity-25 rounded">
                    <small class="text-success d-block fw-bold">${pick.player_name || 'Jogador Desconhecido'}</small>
                    ${pick.player_position ? `<span class="badge bg-orange" style="font-size: 0.65rem;">${pick.player_position}</span>` : ''}
                    ${pick.player_ovr ? `<span class="badge bg-secondary" style="font-size: 0.65rem;">OVR ${pick.player_ovr}</span>` : ''}
                  </div>
                ` : `
                  <div class="mt-2 p-2 bg-secondary bg-opacity-25 rounded">
                    <small class="text-light-gray">${canEdit ? 'Clique para preencher' : 'Aguardando'}</small>
                    ${canEdit ? '<i class="bi bi-pencil text-orange d-block mt-1"></i>' : ''}
                  </div>
                `}
              </div>
            </div>
          </div>
        </div>
      `;
    }

    // Abrir modal para preencher pick de draft passado
    async function openFillPastPickModal(pickId, teamName, draftSessionId) {
      currentPickForFill = pickId;
      currentDraftSessionForFill = draftSessionId;
      
      document.getElementById('fillPickTeamName').textContent = teamName;
      
      const modal = new bootstrap.Modal(document.getElementById('fillPastPickModal'));
      modal.show();

      const container = document.getElementById('pastPlayersDropdown');
      container.innerHTML = '<div class="col-12 text-center py-3"><div class="spinner-border text-orange"></div></div>';

      try {
        // Buscar jogadores do draft_pool dessa sessão de draft
        const data = await api(`draft.php?action=available_players_for_past_draft&draft_session_id=${draftSessionId}`);
        allPlayersList = data.players || [];
        renderPastPlayers(allPlayersList);
        
        // Setup search
        const searchInput = document.getElementById('pastPlayerSearch');
        searchInput.value = ''; // Limpar busca anterior
        searchInput.addEventListener('input', (e) => {
          const q = e.target.value.toLowerCase();
          const filtered = allPlayersList.filter(p => 
            p.name.toLowerCase().includes(q) || 
            (p.position && p.position.toLowerCase().includes(q))
          );
          renderPastPlayers(filtered);
        });
      } catch (e) {
        container.innerHTML = `<div class="col-12 text-center text-danger py-3">Erro: ${e.error || 'Desconhecido'}</div>`;
      }
    }

    function renderPastPlayers(players) {
      const container = document.getElementById('pastPlayersDropdown');
      if (players.length === 0) {
        container.innerHTML = '<div class="col-12 text-center text-light-gray py-3">Nenhum jogador disponível no draft pool</div>';
        return;
      }
      container.innerHTML = players.map(p => `
        <div class="col-md-4 col-lg-3">
          <div class="card bg-dark border-secondary player-select-card" onclick="fillPastPick(${p.id}, '${(p.name || '').replace(/'/g, "\\'")}')" style="border-radius: 10px;">
            <div class="card-body p-3 text-center">
              <h6 class="text-white mb-1">${p.name || 'Sem nome'}</h6>
              <span class="badge bg-orange">${p.position || 'N/A'}</span>
              <span class="badge bg-success">OVR ${p.ovr || '0'}</span>
              <p class="text-light-gray mb-0 mt-2" style="font-size: 0.8rem;">${p.age || '?'} anos</p>
            </div>
          </div>
        </div>
      `).join('');
    }

    async function fillPastPick(playerId, playerName) {
      if (!confirm(`Confirma preencher esta pick com ${playerName}?`)) return;

      try {
        const result = await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'fill_past_pick',
            pick_id: currentPickForFill,
            player_id: playerId,
            draft_session_id: currentDraftSessionForFill
          })
        });

        alert(result.message);
        bootstrap.Modal.getInstance(document.getElementById('fillPastPickModal')).hide();
        
        // Recarregar a visualização do draft atual
        if (currentSeasonIdView && currentDraftStatusView && currentDraftSessionForFill) {
          viewDraftHistory(currentSeasonIdView, currentDraftStatusView, currentDraftSessionForFill);
        } else {
          loadHistory();
        }
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }

    loadDraft();
  </script>
  <script src="/js/pwa.js"></script>
</body>
</html>
