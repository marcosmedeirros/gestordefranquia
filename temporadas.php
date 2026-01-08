<?php
session_start();
require_once 'backend/auth.php';
require_once 'backend/db.php';

requireAuth();
$user = getUserSession();

// Verificar se é admin
if (($user['user_type'] ?? 'jogador') !== 'admin') {
    header('Location: /dashboard.php');
    exit;
}

$pdo = db();
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Temporadas - GM FBA</title>
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
      <li><a href="/history.php"><i class="bi bi-clock-history"></i>Histórico</a></li>
      <li><a href="/admin.php"><i class="bi bi-shield-lock-fill"></i>Admin</a></li>
      <li><a href="/temporadas.php" class="active"><i class="bi bi-calendar3"></i>Temporadas</a></li>
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
        <i class="bi bi-calendar3 me-2 text-orange"></i>
        Gerenciar Temporadas
      </h1>
    </div>

    <div id="mainContainer">
      <div class="text-center py-5">
        <div class="spinner-border text-orange"></div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script>
    // API helper
    const api = async (path, options = {}) => {
      const res = await fetch(`/api/${path}`, { headers: { 'Content-Type': 'application/json' }, ...options });
      let body = {};
      try { body = await res.json(); } catch {}
      if (!res.ok) throw body;
      return body;
    };

    let currentLeague = null;
    let currentSeasonData = null;
    let currentSeasonId = null;
    let timerInterval = null;

    // ========== TELA INICIAL COM AS 4 LIGAS ==========
    async function showLeaguesOverview() {
      const container = document.getElementById('mainContainer');
      container.innerHTML = `
        <div class="row g-4">
          <div class="col-12">
            <p class="text-light-gray">Selecione uma liga para gerenciar suas temporadas:</p>
          </div>
          
          <!-- ELITE -->
          <div class="col-md-6 col-lg-3">
            <div class="league-card" onclick="showLeagueManagement('ELITE')" style="cursor: pointer;">
              <h3>ELITE</h3>
              <p class="text-light-gray mb-2">20 temporadas por sprint</p>
              <span class="badge bg-gradient-orange">Gerenciar</span>
            </div>
          </div>
          
          <!-- NEXT -->
          <div class="col-md-6 col-lg-3">
            <div class="league-card" onclick="showLeagueManagement('NEXT')" style="cursor: pointer;">
              <h3>NEXT</h3>
              <p class="text-light-gray mb-2">15 temporadas por sprint</p>
              <span class="badge bg-gradient-orange">Gerenciar</span>
            </div>
          </div>
          
          <!-- RISE -->
          <div class="col-md-6 col-lg-3">
            <div class="league-card" onclick="showLeagueManagement('RISE')" style="cursor: pointer;">
              <h3>RISE</h3>
              <p class="text-light-gray mb-2">10 temporadas por sprint</p>
              <span class="badge bg-gradient-orange">Gerenciar</span>
            </div>
          </div>
          
          <!-- ROOKIE -->
          <div class="col-md-6 col-lg-3">
            <div class="league-card" onclick="showLeagueManagement('ROOKIE')" style="cursor: pointer;">
              <h3>ROOKIE</h3>
              <p class="text-light-gray mb-2">10 temporadas por sprint</p>
              <span class="badge bg-gradient-orange">Gerenciar</span>
            </div>
          </div>
        </div>
      `;
    }

    // ========== TELA DE GERENCIAMENTO DE UMA LIGA ==========
    async function showLeagueManagement(league) {
      currentLeague = league;
      
      try {
        // Buscar temporada atual
        const data = await api(`seasons.php?action=current_season&league=${league}`);
        currentSeasonData = data.season;
        
        const container = document.getElementById('mainContainer');
        
        if (!currentSeasonData) {
          // Nenhuma temporada ativa - mostrar botão para iniciar
          container.innerHTML = `
            <button class="btn btn-back mb-4" onclick="showLeaguesOverview()">
              <i class="bi bi-arrow-left me-2"></i>Voltar
            </button>
            
            <div class="text-center py-5">
              <i class="bi bi-calendar-plus text-orange fs-1 mb-3 d-block"></i>
              <h3 class="text-white mb-3">Nenhuma temporada ativa</h3>
              <p class="text-light-gray mb-4">Inicie uma nova temporada para a liga <strong class="text-orange">${league}</strong></p>
              <button class="btn btn-orange btn-lg" onclick="startNewSeason('${league}')">
                <i class="bi bi-play-fill me-2"></i>Iniciar Nova Temporada
              </button>
            </div>
          `;
        } else {
          // Temporada ativa - mostrar contador e opções
          renderActiveSeasonView(league, currentSeasonData);
        }
      } catch (e) {
        console.error(e);
        alert('Erro ao carregar dados da liga');
      }
    }

    // ========== RENDERIZAR TELA DE TEMPORADA ATIVA ==========
    function renderActiveSeasonView(league, season) {
      const container = document.getElementById('mainContainer');
      
      // Verificar se sprint acabou
      const maxSeasons = getMaxSeasonsForLeague(league);
      const sprintCompleted = season.season_number >= maxSeasons;
      
      container.innerHTML = `
        <button class="btn btn-back mb-4" onclick="showLeaguesOverview()">
          <i class="bi bi-arrow-left me-2"></i>Voltar
        </button>
        
        <div class="row g-4 mb-4">
          <div class="col-md-8">
            <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-4">
                  <div>
                    <h3 class="text-white mb-2">
                      <i class="bi bi-calendar3 text-orange me-2"></i>
                      Liga ${league}
                    </h3>
                    <p class="text-light-gray mb-0">
                      Temporada ${String(season.season_number).padStart(2, '0')} de ${maxSeasons}
                    </p>
                  </div>
                  <span class="badge bg-gradient-orange fs-5">${season.year}</span>
                </div>
                
                ${sprintCompleted ? `
                  <div class="alert alert-success mb-4" style="border-radius: 15px; background: rgba(25, 135, 84, 0.2); border: 1px solid rgba(25, 135, 84, 0.5);">
                    <i class="bi bi-check-circle me-2 text-success"></i>
                    <strong class="text-white">Sprint Completo!</strong> 
                    <span class="text-light-gray">Todas as ${maxSeasons} temporadas foram concluídas.</span>
                  </div>
                  <button class="btn btn-orange btn-lg w-100" onclick="confirmResetSprint('${league}')">
                    <i class="bi bi-arrow-clockwise me-2"></i>Começar Novo Sprint
                  </button>
                ` : `
                  <div class="mb-3 text-center">
                    <p class="text-light-gray mb-2">Temporada iniciada em:</p>
                    <p class="text-white fw-bold">${new Date(season.created_at).toLocaleString('pt-BR')}</p>
                  </div>
                  <button class="btn btn-outline-orange w-100" onclick="advanceToNextSeason('${league}')">
                    <i class="bi bi-skip-forward me-2"></i>Avançar para Próxima Temporada
                  </button>
                `}
              </div>
            </div>
          </div>
          
          <div class="col-md-4">
            <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
              <div class="card-body">
                <h5 class="text-white mb-3">
                  <i class="bi bi-info-circle text-orange me-2"></i>
                  Progresso do Sprint
                </h5>
                <div class="progress mb-3" style="height: 25px; border-radius: 15px; background: rgba(241, 117, 7, 0.2);">
                  <div class="progress-bar bg-gradient-orange" role="progressbar" 
                       style="width: ${(season.season_number / maxSeasons * 100).toFixed(0)}%">
                    ${(season.season_number / maxSeasons * 100).toFixed(0)}%
                  </div>
                </div>
                <p class="text-light-gray mb-0">
                  <strong class="text-orange">${season.season_number}</strong> de <strong class="text-white">${maxSeasons}</strong> temporadas
                </p>
              </div>
            </div>
          </div>
        </div>
        
        <!-- GERENCIAR DRAFT -->
        <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
          <div class="card-body">
            <h4 class="text-white mb-3">
              <i class="bi bi-trophy text-orange me-2"></i>
              Gerenciar Draft
            </h4>
            <button class="btn btn-orange" onclick="showDraftManagement(${season.id}, '${league}')">
              <i class="bi bi-people me-2"></i>Gerenciar Jogadores do Draft
            </button>
          </div>
        </div>
        
        <!-- CADASTRO DE HISTÓRICO -->
        <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
          <div class="card-body">
            <h4 class="text-white mb-3">
              <i class="bi bi-award text-orange me-2"></i>
              Cadastro de Histórico
            </h4>
            <p class="text-light-gray mb-3">
              Registre os resultados da temporada ${String(season.season_number).padStart(2, '0')}
            </p>
            <button class="btn btn-orange" onclick="showHistoryForm(${season.id}, '${league}')">
              <i class="bi bi-pencil me-2"></i>Cadastrar Histórico da Temporada
            </button>
          </div>
        </div>
      `;
      
      // Iniciar contador se temporada ativa
      if (!sprintCompleted) {
        startTimer(season.created_at);
      }
    }

    // ========== CONTADOR DE TEMPO ==========
    function startTimer(startDate) {
      if (timerInterval) clearInterval(timerInterval);
      
      const start = new Date(startDate).getTime();
      
      timerInterval = setInterval(() => {
        const now = new Date().getTime();
        const diff = now - start;
        
        const hours = Math.floor(diff / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
        
        const timerEl = document.getElementById('timer');
        if (timerEl) {
          timerEl.textContent = 
            String(hours).padStart(2, '0') + ':' + 
            String(minutes).padStart(2, '0') + ':' + 
            String(seconds).padStart(2, '0');
        }
      }, 1000);
    }

    // ========== HELPERS ==========
    function getMaxSeasonsForLeague(league) {
      switch(league) {
        case 'ELITE': return 20;
        case 'NEXT': return 15;
        case 'RISE': return 10;
        case 'ROOKIE': return 10;
        default: return 10;
      }
    }

    // ========== INICIAR NOVA TEMPORADA ==========
    async function startNewSeason(league) {
      if (!confirm(`Iniciar uma nova temporada para a liga ${league}?`)) return;
      
      try {
        await api('seasons.php?action=create_season', {
          method: 'POST',
          body: JSON.stringify({ league })
        });
        
        alert('Nova temporada iniciada com sucesso!');
        showLeagueManagement(league);
      } catch (e) {
        alert('Erro ao iniciar temporada: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== AVANÇAR PARA PRÓXIMA TEMPORADA ==========
    async function advanceToNextSeason(league) {
      if (!confirm('Avançar para a próxima temporada? Isso criará uma nova temporada.')) return;
      
      try {
        await api('seasons.php?action=create_season', {
          method: 'POST',
          body: JSON.stringify({ league })
        });
        
        alert('Avançado para próxima temporada!');
        showLeagueManagement(league);
      } catch (e) {
        alert('Erro ao avançar: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== RESETAR SPRINT (NOVO CICLO) ==========
    async function confirmResetSprint(league) {
      if (!confirm(`ATENÇÃO! Isso irá RESETAR TODOS os dados da liga ${league} e começar um novo sprint do zero. Confirma?`)) return;
      if (!confirm('Tem CERTEZA ABSOLUTA? Todos os times, jogadores, picks e trades serão DELETADOS!')) return;
      
      try {
        await api('seasons.php?action=reset_sprint', {
          method: 'POST',
          body: JSON.stringify({ league })
        });
        
        alert('Sprint resetado! Começando do zero.');
        showLeagueManagement(league);
      } catch (e) {
        alert('Erro ao resetar: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== GERENCIAR DRAFT ==========
    async function showDraftManagement(seasonId, league) {
      currentSeasonId = seasonId;
      currentLeague = league;
      const container = document.getElementById('mainContainer');
      
      try {
        // Buscar jogadores do draft
        const data = await api(`seasons.php?action=draft_players&season_id=${seasonId}`);
        const players = data.players || [];
        const available = players.filter(p => p.draft_status === 'available');
        const drafted = players.filter(p => p.draft_status === 'drafted');
        
        // Buscar dados da temporada
        const seasonData = await api(`seasons.php?action=current_season&league=${league}`);
        const season = seasonData.season;
        
        container.innerHTML = `
          <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left me-2"></i>Voltar
          </button>
          
          <div class="row g-3 mb-4">
            <div class="col-md-8">
              <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
                <div class="card-body">
                  <h4 class="text-white mb-1">Draft - Temporada ${season.season_number}</h4>
                  <p class="text-light-gray mb-0">${league} | Sprint ${season.sprint_number || '?'} | Ano ${season.year}</p>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <button class="btn btn-orange w-100 h-100" onclick="showAddDraftPlayerModal()" style="border-radius: 15px;">
                <i class="bi bi-plus-circle me-1"></i>Adicionar Jogador ao Draft
              </button>
            </div>
          </div>
          
          <div class="row g-3">
            <div class="col-md-8">
              <div class="bg-dark-panel border-orange rounded p-4">
                <h5 class="text-white mb-3">
                  <i class="bi bi-people-fill me-2 text-orange"></i>
                  Disponíveis (${available.length})
                </h5>
                <div class="row g-3">
                  ${available.map(p => `
                    <div class="col-md-4">
                      <div class="card bg-dark-panel border-orange h-100">
                        <div class="card-body">
                          <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge bg-orange">${p.position}</span>
                            <span class="badge bg-success">OVR ${p.ovr}</span>
                          </div>
                          <h6 class="text-white mb-1">${p.name}</h6>
                          <p class="text-light-gray small mb-2">${p.age} anos</p>
                          <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-danger flex-fill" onclick="deleteDraftPlayer(${p.id})">
                              <i class="bi bi-trash"></i>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                  `).join('') || '<p class="text-light-gray">Nenhum jogador disponível</p>'}
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="bg-dark-panel border-success rounded p-4">
                <h5 class="text-white mb-3">
                  <i class="bi bi-check-circle-fill me-2 text-success"></i>
                  Draftados (${drafted.length})
                </h5>
                ${drafted.map(p => `
                  <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-dark rounded">
                    <div>
                      <div class="text-white small">${p.name}</div>
                      <div class="text-light-gray" style="font-size: 0.75rem;">Pick #${p.draft_order}</div>
                    </div>
                    <span class="badge bg-success">${p.ovr}</span>
                  </div>
                `).join('') || '<p class="text-light-gray small">Nenhum ainda</p>'}
              </div>
            </div>
          </div>
        `;
      } catch (e) {
        container.innerHTML = `
          <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left me-2"></i>Voltar
          </button>
          <div class="alert alert-danger">Erro ao carregar jogadores: ${e.error || 'Desconhecido'}</div>
        `;
      }
    }
    
    // Adicionar jogador ao draft
    function showAddDraftPlayerModal() {
      const modal = document.createElement('div');
      modal.innerHTML = `
        <div class="modal fade" id="addPlayerModal" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content bg-dark">
              <div class="modal-header border-orange">
                <h5 class="modal-title text-white">Adicionar Jogador ao Draft</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <form id="addPlayerForm" onsubmit="submitAddPlayer(event)">
                  <div class="mb-3">
                    <label class="form-label text-white">Nome</label>
                    <input type="text" class="form-control bg-dark text-white border-orange" name="name" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label text-white">Idade</label>
                    <input type="number" class="form-control bg-dark text-white border-orange" name="age" min="18" max="40" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label text-white">Posição</label>
                    <select class="form-select bg-dark text-white border-orange" name="position" required>
                      <option value="">Selecione...</option>
                      <option value="PG">PG</option>
                      <option value="SG">SG</option>
                      <option value="SF">SF</option>
                      <option value="PF">PF</option>
                      <option value="C">C</option>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label text-white">OVR</label>
                    <input type="number" class="form-control bg-dark text-white border-orange" name="ovr" min="1" max="99" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label text-white">Foto (URL)</label>
                    <input type="url" class="form-control bg-dark text-white border-orange" name="photo_url" placeholder="https://...">
                  </div>
                  <button type="submit" class="btn btn-orange w-100">Adicionar</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
      const bsModal = new bootstrap.Modal(document.getElementById('addPlayerModal'));
      bsModal.show();
      document.getElementById('addPlayerModal').addEventListener('hidden.bs.modal', () => {
        modal.remove();
      });
    }
    
    async function submitAddPlayer(event) {
      event.preventDefault();
      const form = event.target;
      const formData = new FormData(form);
      
      try {
        await api('seasons.php?action=add_draft_player', {
          method: 'POST',
          body: JSON.stringify({
            season_id: currentSeasonId,
            name: formData.get('name'),
            age: formData.get('age'),
            position: formData.get('position'),
            ovr: formData.get('ovr'),
            photo_url: formData.get('photo_url')
          })
        });
        
        bootstrap.Modal.getInstance(document.getElementById('addPlayerModal')).hide();
        
        // Recarregar a lista
        showDraftManagement(currentSeasonId, currentLeague);
        
        alert('Jogador adicionado com sucesso!');
      } catch (e) {
        alert('Erro ao adicionar jogador: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function deleteDraftPlayer(playerId) {
      if (!confirm('Deseja realmente remover este jogador do draft?')) return;
      
      try {
        await api('seasons.php?action=delete_draft_player', {
          method: 'POST',
          body: JSON.stringify({ player_id: playerId })
        });
        
        showDraftManagement(currentSeasonId, currentLeague);
        alert('Jogador removido com sucesso!');
      } catch (e) {
        alert('Erro ao remover jogador: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== CADASTRO DE HISTÓRICO ==========
    async function showHistoryForm(seasonId, league) {
      const container = document.getElementById('mainContainer');
      
      // Buscar times da liga
      try {
        const teamsData = await api(`admin.php?action=teams&league=${league}`);
        const teams = teamsData.teams || [];
        
        container.innerHTML = `
          <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left me-2"></i>Voltar
          </button>
          
          <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
            <div class="card-body">
              <h3 class="text-white mb-4">
                <i class="bi bi-award text-orange me-2"></i>
                Cadastrar Histórico - Temporada ${String(currentSeasonData.season_number).padStart(2, '0')}
              </h3>
              
              <form id="historyForm" onsubmit="saveHistory(event, ${seasonId}, '${league}')">
                <!-- Campeão -->
                <div class="mb-4">
                  <label class="form-label text-white">
                    <i class="bi bi-trophy-fill text-warning me-2"></i>Campeão
                  </label>
                  <select class="form-select" name="champion" required>
                    <option value="">Selecione o campeão</option>
                    ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                </div>
                
                <!-- Vice-Campeão -->
                <div class="mb-4">
                  <label class="form-label text-white">
                    <i class="bi bi-award text-secondary me-2"></i>Vice-Campeão
                  </label>
                  <select class="form-select" name="runner_up" required>
                    <option value="">Selecione o vice-campeão</option>
                    ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                </div>
                
                <!-- MVP -->
                <div class="mb-4">
                  <label class="form-label text-white">
                    <i class="bi bi-star-fill text-warning me-2"></i>MVP
                  </label>
                  <select class="form-select" name="mvp">
                    <option value="">Selecione o MVP (opcional)</option>
                    ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                </div>
                
                <!-- DPOY -->
                <div class="mb-4">
                  <label class="form-label text-white">
                    <i class="bi bi-shield-fill text-primary me-2"></i>DPOY (Defensor do Ano)
                  </label>
                  <select class="form-select" name="dpoy">
                    <option value="">Selecione o DPOY (opcional)</option>
                    ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                </div>
                
                <!-- MIP -->
                <div class="mb-4">
                  <label class="form-label text-white">
                    <i class="bi bi-graph-up-arrow text-success me-2"></i>MIP (Jogador que Mais Evoluiu)
                  </label>
                  <select class="form-select" name="mip">
                    <option value="">Selecione o MIP (opcional)</option>
                    ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                </div>
                
                <!-- 6º Homem -->
                <div class="mb-4">
                  <label class="form-label text-white">
                    <i class="bi bi-person-plus text-info me-2"></i>6º Homem
                  </label>
                  <select class="form-select" name="sixth_man">
                    <option value="">Selecione o 6º Homem (opcional)</option>
                    ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                </div>
                
                <div class="d-grid gap-2">
                  <button type="submit" class="btn btn-orange btn-lg">
                    <i class="bi bi-save me-2"></i>Salvar Histórico
                  </button>
                </div>
              </form>
            </div>
          </div>
        `;
      } catch (e) {
        alert('Erro ao carregar times: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== SALVAR HISTÓRICO ==========
    async function saveHistory(event, seasonId, league) {
      event.preventDefault();
      
      const form = event.target;
      const formData = new FormData(form);
      
      const data = {
        season_id: seasonId,
        champion: formData.get('champion'),
        runner_up: formData.get('runner_up'),
        mvp: formData.get('mvp') || null,
        dpoy: formData.get('dpoy') || null,
        mip: formData.get('mip') || null,
        sixth_man: formData.get('sixth_man') || null
      };
      
      try {
        await api('seasons.php?action=save_history', {
          method: 'POST',
          body: JSON.stringify(data)
        });
        
        alert('Histórico salvo com sucesso!');
        showLeagueManagement(league);
      } catch (e) {
        alert('Erro ao salvar histórico: ' + (e.error || 'Desconhecido'));
      }
    }

    // Carregar ao iniciar
    document.addEventListener('DOMContentLoaded', () => {
      showLeaguesOverview();
    });
    
    // Limpar timer ao sair
    window.addEventListener('beforeunload', () => {
      if (timerInterval) clearInterval(timerInterval);
    });
  </script>
</body>
</html>
