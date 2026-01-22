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
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Temporadas - GM FBA</title>
  
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
      <li><a href="/free-agency.php"><i class="bi bi-coin"></i>Free Agency</a></li>
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
      const sprintStartYear = resolveSprintStartYearFromSeason(season);
      // Corrigir exibição do ano: usar fórmula start_year + season_number - 1 quando possível
      const displayedYear = (sprintStartYear && season?.season_number)
        ? (Number(sprintStartYear) + Number(season.season_number) - 1)
        : Number(season.year);
      
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
                    <p class="text-light-gray mb-0">
                      Sprint iniciado em <span class="text-white fw-bold">${sprintStartYear || '??'}</span>
                    </p>
                  </div>
                  <span class="badge bg-gradient-orange fs-5">Ano ${displayedYear}</span>
                </div>
                
                ${sprintCompleted ? `
                  <div class="alert alert-success mb-4" style="border-radius: 15px; background: rgba(25, 135, 84, 0.2); border: 1px solid rgba(25, 135, 84, 0.5);">
                    <i class="bi bi-check-circle me-2 text-success"></i>
                    <strong class="text-white">Sprint Completo!</strong> 
                    <span class="text-light-gray">Todas as ${maxSeasons} temporadas foram concluídas.</span>
                  </div>
                  <div class="alert alert-warning mb-4" style="border-radius: 15px; background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.5);">
                    <i class="bi bi-exclamation-triangle me-2 text-warning"></i>
                    <strong class="text-white">Atenção!</strong> 
                    <span class="text-light-gray">Antes de iniciar um novo sprint, você precisa resetar os times. Isso irá limpar jogadores, picks, trades e histórico, mantendo apenas os pontos do ranking.</span>
                  </div>
                  <button class="btn btn-danger btn-lg w-100 mb-3" onclick="confirmResetTeams('${league}')">
                    <i class="bi bi-trash3 me-2"></i>Resetar Times
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
            <div class="row g-2">
              <div class="col-md-4">
                <button class="btn btn-orange w-100" onclick="showDraftManagement(${season.id}, '${league}')">
                  <i class="bi bi-people me-2"></i>Jogadores do Draft
                </button>
              </div>
              <div class="col-md-4">
                <button class="btn btn-outline-orange w-100" onclick="showDraftSessionManagement(${season.id}, '${league}')">
                  <i class="bi bi-list-ol me-2"></i>Configurar Sessão
                </button>
              </div>
              <div class="col-md-4">
                <button class="btn btn-success w-100" onclick="showDraftHistory('${league}')">
                  <i class="bi bi-clock-history me-2"></i>Histórico
                </button>
              </div>
            </div>
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

          <!-- MOEDAS DA TEMPORADA (MANUAL) -->
          <div class="card bg-dark-panel border-success mt-4" style="border-radius: 15px;">
            <div class="card-body">
              <h4 class="text-white mb-3">
                <i class="bi bi-coin text-success me-2"></i>
                Gerenciar Moedas da Temporada
              </h4>
              <p class="text-light-gray mb-3">
                Defina quantas moedas cada time terá nesta temporada. O valor pode ser editado a qualquer momento.
              </p>
              <button class="btn btn-outline-success" onclick="showSeasonCoinsForm(${season.id}, '${league}')">
                <i class="bi bi-pencil-square me-2"></i>Editar Moedas
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

      // ========== MOEDAS DA TEMPORADA ==========
      async function showSeasonCoinsForm(seasonId, league) {
        const container = document.getElementById('mainContainer');
        container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-success"></div></div>';

        try {
          const data = await api(`seasons.php?action=season_coins&season_id=${seasonId}`);
          const teams = data.teams || [];

          container.innerHTML = `
            <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
              <i class="bi bi-arrow-left me-2"></i>Voltar
            </button>
            <div class="card bg-dark-panel border-success mb-4" style="border-radius: 15px;">
              <div class="card-body">
                <h4 class="text-white mb-0">
                  <i class="bi bi-coin text-success me-2"></i>
                  Moedas da Temporada
                </h4>
              </div>
            </div>
            <form id="coinsForm">
              <div class="table-responsive">
                <table class="table table-dark table-hover mb-0">
                  <thead>
                    <tr>
                      <th>Time</th>
                      <th>Moedas</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${teams.map(t => `
                      <tr>
                        <td><strong>${t.city} ${t.name}</strong></td>
                        <td>
                          <input type="number" class="form-control bg-dark text-success border-success" 
                            name="moedas_${t.id}" value="${t.moedas}" min="0" style="max-width:120px;">
                        </td>
                      </tr>
                    `).join('')}
                  </tbody>
                </table>
              </div>
              <div class="d-grid mt-4">
                <button type="submit" class="btn btn-success btn-lg">
                  <i class="bi bi-save me-2"></i>Salvar Moedas
                </button>
              </div>
            </form>
          `;

          document.getElementById('coinsForm').onsubmit = async function(e) {
            e.preventDefault();
            const form = e.target;
            const updates = [];
            teams.forEach(t => {
              updates.push({
                team_id: t.id,
                moedas: parseInt(form[`moedas_${t.id}`].value, 10) || 0
              });
            });
            try {
              await api('seasons.php?action=season_coins&season_id=' + seasonId, {
                method: 'POST',
                body: JSON.stringify({ updates })
              });
              alert('Moedas atualizadas!');
              showSeasonCoinsForm(seasonId, league);
            } catch (err) {
              alert('Erro ao salvar moedas: ' + (err.error || 'Desconhecido'));
            }
          };
        } catch (e) {
          container.innerHTML = '<div class="alert alert-danger">Erro ao carregar times: ' + (e.error || 'Desconhecido') + '</div>';
        }
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

    function resolveSprintStartYearFromSeason(season) {
      if (!season) return null;
      if (season.start_year) return Number(season.start_year);
      if (season.year && season.season_number) {
        return Number(season.year) - Number(season.season_number) + 1;
      }
      return null;
    }

    function promptForStartYear(defaultYear) {
      const fallback = defaultYear ?? new Date().getFullYear();
      const input = prompt('Informe o ano inicial do sprint (ex: 2016):', fallback);
      if (input === null) return null;
      const parsed = parseInt(input, 10);
      if (!parsed || parsed < 1900) {
        alert('Ano inválido. Informe um número como 2016.');
        return null;
      }
      return parsed;
    }

    // ========== INICIAR NOVA TEMPORADA ==========
    async function startNewSeason(league) {
      const fallbackStart = resolveSprintStartYearFromSeason(currentSeasonData) ?? new Date().getFullYear();
      const startYear = promptForStartYear(fallbackStart);
      if (!startYear) return;
      const seasonYear = startYear;
      if (!confirm(`Iniciar uma nova temporada para a liga ${league} com sprint começando em ${startYear}?`)) return;

      try {
        await api('seasons.php?action=create_season', {
          method: 'POST',
          body: JSON.stringify({ league, season_year: seasonYear, start_year: startYear })
        });

        alert('Nova temporada iniciada com sucesso!');
        showLeagueManagement(league);
      } catch (e) {
        alert('Erro ao iniciar temporada: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== AVANÇAR PARA PRÓXIMA TEMPORADA ==========
    async function advanceToNextSeason(league) {
      if (!currentSeasonData) {
        return startNewSeason(league);
      }

      let sprintStart = resolveSprintStartYearFromSeason(currentSeasonData);
      if (!sprintStart) {
        sprintStart = promptForStartYear(new Date().getFullYear());
      }
      if (!sprintStart) return;

      const nextSeasonNumber = Number(currentSeasonData.season_number || 0) + 1;
      const seasonYear = sprintStart + nextSeasonNumber - 1;
      if (!confirm(`Avançar para a próxima temporada da liga ${league} (Temporada ${String(nextSeasonNumber).padStart(2, '0')} - ano ${seasonYear})?`)) return;

      try {
        await api('seasons.php?action=create_season', {
          method: 'POST',
          body: JSON.stringify({ league, season_year: seasonYear, start_year: sprintStart })
        });

        alert('Avançado para próxima temporada!');
        showLeagueManagement(league);
      } catch (e) {
        alert('Erro ao avançar: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== RESETAR SPRINT (NOVO CICLO) ==========
    // ========== RESETAR TIMES (MANTER PONTOS) ==========
    async function confirmResetTeams(league) {
      if (!confirm(`ATENÇÃO! Isso irá LIMPAR todos os jogadores, picks, trades e histórico da liga ${league}.\n\nAPENAS os pontos do ranking serão mantidos.\n\nConfirma?`)) return;
      if (!confirm('Tem CERTEZA ABSOLUTA? Esta ação não pode ser desfeita!')) return;
      
      try {
        await api('seasons.php?action=reset_teams', {
          method: 'POST',
          body: JSON.stringify({ league })
        });
        
        alert('Times resetados com sucesso! Os pontos do ranking foram mantidos.');
        showLeagueManagement(league);
      } catch (e) {
        alert('Erro ao resetar times: ' + (e.error || 'Desconhecido'));
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
        const sprintStartYear = resolveSprintStartYearFromSeason(season);
        const draftDisplayedYear = (sprintStartYear && season?.season_number)
          ? (Number(sprintStartYear) + Number(season.season_number) - 1)
          : Number(season.year);
        
        // Buscar times da liga
        const teamsData = await api(`admin.php?action=teams&league=${league}`);
        const teams = teamsData.teams || [];
        
        container.innerHTML = `
          <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left me-2"></i>Voltar
          </button>
          
          <div class="row g-3 mb-4">
            <div class="col-md-8">
              <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
                <div class="card-body">
                  <h4 class="text-white mb-1">Draft - Temporada ${season.season_number}</h4>
                  <p class="text-light-gray mb-0">${league} | Sprint ${season.sprint_number || '?'} | Ano ${draftDisplayedYear}</p>
                </div>
              </div>
            </div>
            <div class="col-md-2">
              <button class="btn btn-orange w-100 h-100" onclick="showAddDraftPlayerModal()" style="border-radius: 15px;">
                <i class="bi bi-plus-circle me-1"></i>Adicionar
              </button>
            </div>
            <div class="col-md-2">
              <button class="btn btn-info w-100 h-100" onclick="showImportCSVModal(${season.id}, '${league}', ${season.season_number})" style="border-radius: 15px;">
                <i class="bi bi-file-earmark-arrow-up me-1"></i>Importar CSV
              </button>
            </div>
          </div>
          
          <!-- Aba de Draft -->
          <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
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
                  <p class="mt-3">Nenhum jogador disponível</p>
                </div>
              ` : `
                <div class="table-responsive">
                  <table class="table table-dark table-hover mb-0">
                    <thead>
                      <tr>
                        <th class="d-none d-md-table-cell" style="width: 50px;">#</th>
                        <th>Nome</th>
                        <th style="width: 80px;">Pos</th>
                        <th class="d-none d-lg-table-cell" style="width: 80px;">Idade</th>
                        <th style="width: 80px;">OVR</th>
                        <th class="d-none d-xl-table-cell" style="width: 250px;">Draftar para Time</th>
                        <th style="width: 150px;">Ações</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${available.map((p, idx) => `
                        <tr>
                          <td class="text-light-gray d-none d-md-table-cell">${idx + 1}</td>
                          <td class="text-white fw-bold">${p.name}</td>
                          <td><span class="badge bg-orange">${p.position || 'N/A'}</span></td>
                          <td class="text-light-gray d-none d-lg-table-cell">${p.age}</td>
                          <td><span class="badge bg-success">OVR ${p.ovr}</span></td>
                          <td class="d-none d-xl-table-cell">
                            <select class="form-select form-select-sm bg-dark text-white border-orange" id="team-${p.id}">
                              <option value="">Selecione o time...</option>
                              ${teams.map(t => `
                                <option value="${t.id}">${t.city} ${t.name}</option>
                              `).join('')}
                            </select>
                          </td>
                          <td>
                            <div class="d-flex gap-1 flex-wrap">
                              <button class="btn btn-sm btn-success d-xl-none" onclick="showDraftModal(${p.id}, '${p.name}')" title="Draftar">
                                <i class="bi bi-check-lg"></i>
                              </button>
                              <button class="btn btn-sm btn-success d-none d-xl-inline-block" onclick="draftPlayer(${p.id})" title="Draftar">
                                <i class="bi bi-check-lg"></i>
                              </button>
                              <button class="btn btn-sm btn-danger" onclick="deleteDraftPlayer(${p.id})" title="Remover">
                                <i class="bi bi-trash"></i>
                              </button>
                            </div>
                          </td>
                        </tr>
                      `).join('')}
                    </tbody>
                  </table>
                </div>
              `}
            </div>
          </div>
          
          <!-- Lista de Draftados -->
          ${drafted.length > 0 ? `
            <div class="card bg-dark-panel border-success mt-4" style="border-radius: 15px;">
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
                        <th>Pick</th>
                        <th>Nome</th>
                        <th>Posição</th>
                        <th>OVR</th>
                        <th>Time</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${drafted.map(p => `
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
          ` : ''}
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
                      <option value="PG">PG - Armador</option>
                      <option value="SG">SG - Ala-Armador</option>
                      <option value="SF">SF - Ala</option>
                      <option value="PF">PF - Ala-Pivô</option>
                      <option value="C">C - Pivô</option>
                    </select>
                  </div>
                    <div class="mb-3">
                      <label class="form-label text-white">Posição Secundária</label>
                      <select class="form-select bg-dark text-white border-orange" name="secondary_position">
                        <option value="">Nenhuma</option>
                        <option value="PG">PG - Armador</option>
                        <option value="SG">SG - Ala-Armador</option>
                        <option value="SF">SF - Ala</option>
                        <option value="PF">PF - Ala-Pivô</option>
                        <option value="C">C - Pivô</option>
                      </select>
                    </div>
                  <div class="mb-3">
                    <label class="form-label text-white">OVR</label>
                    <input type="number" class="form-control bg-dark text-white border-orange" name="ovr" min="1" max="99" required>
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
  const secondaryPosition = formData.get('secondary_position');
      
      try {
        await api('seasons.php?action=add_draft_player', {
          method: 'POST',
          body: JSON.stringify({
            season_id: currentSeasonId,
            name: formData.get('name'),
            age: formData.get('age'),
            position: formData.get('position'),
            secondary_position: secondaryPosition,
            ovr: formData.get('ovr'),
            photo_url: null
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

    // ========== IMPORTAR CSV ==========
    function showImportCSVModal(seasonId, league, seasonNumber) {
      const modal = document.createElement('div');
      modal.innerHTML = `
        <div class="modal fade" id="importCSVModal" tabindex="-1">
          <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark">
              <div class="modal-header border-orange">
                <h5 class="modal-title text-white">
                  <i class="bi bi-file-earmark-arrow-up me-2"></i>
                  Importar Jogadores via CSV
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <div class="alert alert-info mb-3">
                  <strong>Temporada:</strong> ${league} - Temporada ${seasonNumber}
                </div>
                
                <div class="card bg-dark-panel border-orange mb-3">
                  <div class="card-body">
                    <h6 class="text-white mb-2">
                      <i class="bi bi-info-circle me-2"></i>Formato do CSV
                    </h6>
                    <p class="text-light-gray mb-2 small">
                      O arquivo deve ter as colunas: <code class="text-success">nome,posicao,idade,ovr</code>
                    </p>
                    <div class="bg-dark rounded p-2 mb-2">
                      <code class="text-white small" style="display: block; white-space: pre;">nome,posicao,idade,ovr
LeBron James,SF,39,96
Stephen Curry,PG,35,95</code>
                    </div>
                    <button class="btn btn-sm btn-outline-orange" onclick="downloadCSVTemplate()">
                      <i class="bi bi-download me-1"></i>Baixar Template
                    </button>
                  </div>
                </div>
                
                <form id="importCSVForm" onsubmit="submitImportCSV(event, ${seasonId})">
                  <div class="mb-3">
                    <label class="form-label text-white">Selecione o arquivo CSV</label>
                    <input type="file" class="form-control bg-dark text-white border-orange" 
                           id="csvFileInput" accept=".csv" required>
                  </div>
                  <button type="submit" class="btn btn-success w-100">
                    <i class="bi bi-upload me-2"></i>Importar Jogadores
                  </button>
                </form>
                
                <div id="importResult" class="mt-3" style="display: none;"></div>
              </div>
            </div>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
      const bsModal = new bootstrap.Modal(document.getElementById('importCSVModal'));
      bsModal.show();
      document.getElementById('importCSVModal').addEventListener('hidden.bs.modal', () => {
        modal.remove();
      });
    }

    async function submitImportCSV(event, seasonId) {
      event.preventDefault();
      
      const fileInput = document.getElementById('csvFileInput');
      const file = fileInput.files[0];
      
      if (!file) {
        alert('Selecione um arquivo CSV');
        return;
      }
      
      const formData = new FormData();
      formData.append('csv_file', file);
      formData.append('season_id', seasonId);
      
      const resultDiv = document.getElementById('importResult');
      resultDiv.style.display = 'block';
      resultDiv.innerHTML = '<div class="alert alert-info"><i class="bi bi-hourglass-split me-2"></i>Importando...</div>';
      
      try {
        const response = await fetch('/api/import-draft-players.php', {
          method: 'POST',
          body: formData
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
          resultDiv.innerHTML = `<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>${data.message}</div>`;
          
          setTimeout(() => {
            bootstrap.Modal.getInstance(document.getElementById('importCSVModal')).hide();
            showDraftManagement(currentSeasonId, currentLeague);
          }, 2000);
        } else {
          let errorMsg = data.error || 'Erro desconhecido';
          if (data.file && data.line) {
            errorMsg += ` (${data.file}:${data.line})`;
          }
          resultDiv.innerHTML = `<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i>Erro: ${errorMsg}</div>`;
        }
      } catch (e) {
        console.error('Erro na importação:', e);
        resultDiv.innerHTML = `<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i>Erro: ${e.message || 'Desconhecido'}</div>`;
      }
    }

    function downloadCSVTemplate() {
      const csv = 'nome,posicao,idade,ovr\\nLeBron James,SF,39,96\\nStephen Curry,PG,35,95\\nKevin Durant,PF,35,94\\n';
      const blob = new Blob([csv], { type: 'text/csv' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'template-draft-players.csv';
      a.click();
      window.URL.revokeObjectURL(url);
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
    
    async function draftPlayer(playerId) {
      const teamSelect = document.getElementById(`team-${playerId}`);
      const teamId = teamSelect.value;
      
      if (!teamId) {
        alert('Por favor, selecione um time para draftar este jogador.');
        return;
      }
      
      try {
        await api('seasons.php?action=assign_draft_pick', {
          method: 'POST',
          body: JSON.stringify({
            player_id: playerId,
            team_id: teamId
          })
        });
        
        showDraftManagement(currentSeasonId, currentLeague);
        alert('Jogador draftado com sucesso!');
      } catch (e) {
        alert('Erro ao draftar jogador: ' + (e.error || 'Desconhecido'));
      }
    }
    
    // Modal para draftar no mobile
    async function showDraftModal(playerId, playerName) {
      // Buscar times da liga
      const teamsData = await api(`admin.php?action=teams&league=${currentLeague}`);
      const teams = teamsData.teams || [];
      
      const modal = document.createElement('div');
      modal.innerHTML = `
        <div class="modal fade" id="draftModal" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content bg-dark">
              <div class="modal-header border-orange">
                <h5 class="modal-title text-white">Draftar ${playerName}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <label class="form-label text-white">Selecione o Time</label>
                <select class="form-select bg-dark text-white border-orange" id="modalTeamSelect">
                  <option value="">Selecione...</option>
                  ${teams.map(t => `
                    <option value="${t.id}">${t.city} ${t.name}</option>
                  `).join('')}
                </select>
              </div>
              <div class="modal-footer border-orange">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" onclick="confirmDraft(${playerId})">Draftar</button>
              </div>
            </div>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
      const bsModal = new bootstrap.Modal(document.getElementById('draftModal'));
      bsModal.show();
      document.getElementById('draftModal').addEventListener('hidden.bs.modal', () => {
        modal.remove();
      });
    }
    
    async function confirmDraft(playerId) {
      const teamId = document.getElementById('modalTeamSelect').value;
      
      if (!teamId) {
        alert('Por favor, selecione um time.');
        return;
      }
      
      try {
        await api('seasons.php?action=assign_draft_pick', {
          method: 'POST',
          body: JSON.stringify({
            player_id: playerId,
            team_id: teamId
          })
        });
        
        bootstrap.Modal.getInstance(document.getElementById('draftModal')).hide();
        showDraftManagement(currentSeasonId, currentLeague);
        alert('Jogador draftado com sucesso!');
      } catch (e) {
        alert('Erro ao draftar jogador: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== CADASTRO DE HISTÓRICO ==========
    // Estado global do sistema de playoffs
    let playoffState = {
      step: 1,
      seasonId: null,
      league: null,
      teams: [],
      standings: { LESTE: [], OESTE: [] },
      bracket: { LESTE: [], OESTE: [] },
      matches: [],
      awards: {}
    };

    async function showHistoryForm(seasonId, league) {
      const container = document.getElementById('mainContainer');
      playoffState.seasonId = seasonId;
      playoffState.league = league;
      playoffState.step = 1;
      
      try {
        // Buscar times da liga
        const teamsData = await api(`admin.php?action=teams&league=${league}`);
        playoffState.teams = teamsData.teams || [];
        
        // Separar por conferência
        const teamsLeste = playoffState.teams.filter(t => t.conference === 'LESTE');
        const teamsOeste = playoffState.teams.filter(t => t.conference === 'OESTE');
        
        // Verificar se há bracket existente
        let existingBracket = null;
        try {
          const bracketData = await fetch(`/api/playoffs.php?action=bracket&season_id=${seasonId}&league=${league}`);
          const bracketResult = await bracketData.json();
          if (bracketResult.success && bracketResult.bracket && bracketResult.bracket.length > 0) {
            existingBracket = bracketResult.bracket;
          }
        } catch (e) {}
        
        if (existingBracket) {
          // Já existe bracket - ir para etapa de jogos
          playoffState.bracket.LESTE = existingBracket.filter(b => b.conference === 'LESTE');
          playoffState.bracket.OESTE = existingBracket.filter(b => b.conference === 'OESTE');
          playoffState.step = 2;
          renderPlayoffStep2();
        } else {
          // Não existe - mostrar seleção de classificação
          renderPlayoffStep1(teamsLeste, teamsOeste);
        }
      } catch (e) {
        alert('Erro ao carregar times: ' + (e.error || 'Desconhecido'));
      }
    }

    // PASSO 1: Definir classificação da temporada regular (1-8 por conferência)
    function renderPlayoffStep1(teamsLeste, teamsOeste) {
      const container = document.getElementById('mainContainer');
      
      container.innerHTML = `
        <button class="btn btn-back mb-4" onclick="showLeagueManagement('${playoffState.league}')">
          <i class="bi bi-arrow-left me-2"></i>Voltar
        </button>
        
        <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
          <div class="card-body">
            <h3 class="text-white mb-2">
              <i class="bi bi-trophy text-orange me-2"></i>
              Playoffs - Temporada ${String(currentSeasonData.season_number).padStart(2, '0')}
            </h3>
            <p class="text-light-gray mb-0">
              <span class="badge bg-orange me-2">Passo 1 de 4</span>
              Defina a classificação da temporada regular para cada conferência (1º ao 8º lugar)
            </p>
          </div>
        </div>

        <div class="alert alert-info mb-4">
          <i class="bi bi-info-circle me-2"></i>
          <strong>Pontos por Classificação:</strong> 1º lugar +4pts | 2º ao 4º +3pts | 5º ao 8º +2pts
        </div>
        
        <div class="row">
          <!-- CONFERÊNCIA LESTE -->
          <div class="col-lg-6 mb-4">
            <div class="card bg-dark border-danger" style="border-radius: 15px;">
              <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Conferência LESTE</h5>
              </div>
              <div class="card-body">
                ${renderStandingsSelectors('LESTE', teamsLeste)}
              </div>
            </div>
          </div>
          
          <!-- CONFERÊNCIA OESTE -->
          <div class="col-lg-6 mb-4">
            <div class="card bg-dark border-primary" style="border-radius: 15px;">
              <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Conferência OESTE</h5>
              </div>
              <div class="card-body">
                ${renderStandingsSelectors('OESTE', teamsOeste)}
              </div>
            </div>
          </div>
        </div>
        
        <div class="d-grid">
          <button class="btn btn-orange btn-lg" onclick="submitStandings()">
            <i class="bi bi-arrow-right me-2"></i>Prosseguir para Playoffs
          </button>
        </div>
      `;
    }

    function renderStandingsSelectors(conference, teams) {
      let html = '';
      for (let i = 1; i <= 8; i++) {
        const pointsLabel = i === 1 ? '+4pts' : (i <= 4 ? '+3pts' : '+2pts');
        const badgeClass = i === 1 ? 'bg-warning text-dark' : (i <= 4 ? 'bg-success' : 'bg-secondary');
        
        html += `
          <div class="d-flex align-items-center mb-2">
            <span class="badge ${badgeClass} me-2" style="width: 30px;">${i}º</span>
            <select class="form-select form-select-sm bg-dark text-white" id="standing_${conference}_${i}" onchange="updateStandingSelectors('${conference}')">
              <option value="">Selecione o ${i}º lugar</option>
              ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
            </select>
            <span class="badge bg-orange ms-2" style="font-size: 0.7rem;">${pointsLabel}</span>
          </div>
        `;
      }
      return html;
    }

    function updateStandingSelectors(conference) {
      const selected = [];
      for (let i = 1; i <= 8; i++) {
        const select = document.getElementById(`standing_${conference}_${i}`);
        if (select && select.value) {
          selected.push(select.value);
        }
      }
      
      // Desabilitar opções já selecionadas em outros selects
      for (let i = 1; i <= 8; i++) {
        const select = document.getElementById(`standing_${conference}_${i}`);
        if (select) {
          const currentValue = select.value;
          Array.from(select.options).forEach(opt => {
            if (opt.value && opt.value !== currentValue) {
              opt.disabled = selected.includes(opt.value);
            }
          });
        }
      }
    }

    async function submitStandings() {
      // Validar seleções
      const standings = { LESTE: [], OESTE: [] };
      
      for (const conf of ['LESTE', 'OESTE']) {
        for (let i = 1; i <= 8; i++) {
          const select = document.getElementById(`standing_${conf}_${i}`);
          if (!select || !select.value) {
            alert(`Por favor, selecione todos os 8 times da conferência ${conf}`);
            return;
          }
          standings[conf].push({
            team_id: parseInt(select.value),
            seed: i
          });
        }
      }
      
      playoffState.standings = standings;
      
      // Enviar para criar o bracket
      try {
        const response = await fetch('/api/playoffs.php?action=setup_bracket', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            season_id: playoffState.seasonId,
            league: playoffState.league,
            standings: standings
          })
        });
        const result = await response.json();
        
        if (!result.success) {
          throw new Error(result.error);
        }
        
        // Buscar bracket criado
        const bracketData = await fetch(`/api/playoffs.php?action=bracket&season_id=${playoffState.seasonId}&league=${playoffState.league}`);
        const bracketResult = await bracketData.json();
        
        playoffState.bracket.LESTE = bracketResult.bracket.filter(b => b.conference === 'LESTE');
        playoffState.bracket.OESTE = bracketResult.bracket.filter(b => b.conference === 'OESTE');
        
        playoffState.step = 2;
        renderPlayoffStep2();
      } catch (e) {
        alert('Erro ao criar bracket: ' + (e.message || 'Desconhecido'));
      }
    }

    // PASSO 2: Bracket de Playoffs (selecionar vencedores)
    async function renderPlayoffStep2() {
      const container = document.getElementById('mainContainer');
      
      // Buscar partidas existentes
      try {
        const matchesData = await fetch(`/api/playoffs.php?action=matches&season_id=${playoffState.seasonId}&league=${playoffState.league}`);
        const matchesResult = await matchesData.json();
        playoffState.matches = matchesResult.matches || [];
      } catch (e) {
        playoffState.matches = [];
      }
      
      container.innerHTML = `
        <button class="btn btn-back mb-4" onclick="showLeagueManagement('${playoffState.league}')">
          <i class="bi bi-arrow-left me-2"></i>Voltar
        </button>
        
        <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
          <div class="card-body">
            <h3 class="text-white mb-2">
              <i class="bi bi-diagram-3 text-orange me-2"></i>
              Bracket de Playoffs - Temporada ${String(currentSeasonData.season_number).padStart(2, '0')}
            </h3>
            <p class="text-light-gray mb-0">
              <span class="badge bg-orange me-2">Passo 2 de 4</span>
              Clique em cada confronto para selecionar o vencedor
            </p>
          </div>
        </div>

        <div class="alert alert-info mb-4">
          <i class="bi bi-info-circle me-2"></i>
          <strong>Pontos Playoffs:</strong> 
          1ª Rodada +1pt | 2ª Rodada +2pts | Final Conferência +3pts | Vice +2pts | Campeão +5pts
        </div>
        
        <div class="row">
          <!-- CONFERÊNCIA LESTE -->
          <div class="col-lg-6 mb-4">
            <div class="card bg-dark border-danger" style="border-radius: 15px;">
              <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Playoffs LESTE</h5>
              </div>
              <div class="card-body">
                ${renderBracket('LESTE')}
              </div>
            </div>
          </div>
          
          <!-- CONFERÊNCIA OESTE -->
          <div class="col-lg-6 mb-4">
            <div class="card bg-dark border-primary" style="border-radius: 15px;">
              <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Playoffs OESTE</h5>
              </div>
              <div class="card-body">
                ${renderBracket('OESTE')}
              </div>
            </div>
          </div>
        </div>

        <!-- FINAIS DA LIGA -->
        <div class="card bg-dark border-warning mb-4" style="border-radius: 15px;">
          <div class="card-header bg-warning text-dark">
            <h5 class="mb-0"><i class="bi bi-trophy-fill me-2"></i>FINAIS DA LIGA</h5>
          </div>
          <div class="card-body text-center">
            ${renderFinals()}
          </div>
        </div>
        
        <div class="d-grid">
          <button class="btn btn-orange btn-lg" onclick="goToStep3()" id="btnStep3" disabled>
            <i class="bi bi-arrow-right me-2"></i>Prosseguir para Prêmios Individuais
          </button>
        </div>
      `;
      
      checkFinalsComplete();
    }

    function renderBracket(conference) {
      const bracket = playoffState.bracket[conference];
      if (!bracket || bracket.length === 0) {
        return '<p class="text-muted">Bracket não configurado</p>';
      }
      
      // Organizar por seed
      const teamsBySeed = {};
      bracket.forEach(b => {
        teamsBySeed[b.seed] = b;
      });
      
      // Formato: 1v8, 4v5, 3v6, 2v7
      const firstRoundMatchups = [
        { match: 1, seeds: [1, 8] },
        { match: 2, seeds: [4, 5] },
        { match: 3, seeds: [3, 6] },
        { match: 4, seeds: [2, 7] }
      ];
      
      let html = `<div class="bracket-container">`;
      
      // PRIMEIRA RODADA
      html += `<div class="mb-4"><h6 class="text-warning mb-3">1ª Rodada (+1pt)</h6>`;
      firstRoundMatchups.forEach(m => {
        const team1 = teamsBySeed[m.seeds[0]];
        const team2 = teamsBySeed[m.seeds[1]];
        const match = getMatch(conference, 'first_round', m.match);
        html += renderMatchup(conference, 'first_round', m.match, team1, team2, match?.winner_id);
      });
      html += `</div>`;
      
      // SEMIFINAIS
      html += `<div class="mb-4"><h6 class="text-info mb-3">Semifinais (+2pts)</h6>`;
      html += renderMatchup(conference, 'semifinals', 1, null, null, getMatch(conference, 'semifinals', 1)?.winner_id, 'Vencedor 1v8', 'Vencedor 4v5');
      html += renderMatchup(conference, 'semifinals', 2, null, null, getMatch(conference, 'semifinals', 2)?.winner_id, 'Vencedor 3v6', 'Vencedor 2v7');
      html += `</div>`;
      
      // FINAL DA CONFERÊNCIA
      html += `<div class="mb-4"><h6 class="text-success mb-3">Final da Conferência (+3pts)</h6>`;
      html += renderMatchup(conference, 'conference_finals', 1, null, null, getMatch(conference, 'conference_finals', 1)?.winner_id, 'Vencedor Semi 1', 'Vencedor Semi 2');
      html += `</div>`;
      
      html += `</div>`;
      return html;
    }

    function getMatch(conference, round, matchNumber) {
      return playoffState.matches.find(m => 
        m.conference === conference && 
        m.round === round && 
        m.match_number === matchNumber
      );
    }

    function getTeamInfo(teamId) {
      const team = playoffState.teams.find(t => t.id == teamId);
      return team ? `${team.city} ${team.name}` : 'TBD';
    }

    function renderMatchup(conference, round, matchNumber, team1, team2, winnerId, placeholder1 = null, placeholder2 = null) {
      const t1Name = team1 ? `(${team1.seed}) ${getTeamInfo(team1.team_id)}` : placeholder1;
      const t2Name = team2 ? `(${team2.seed}) ${getTeamInfo(team2.team_id)}` : placeholder2;
      const t1Id = team1 ? team1.team_id : null;
      const t2Id = team2 ? team2.team_id : null;
      
      // Para rodadas avançadas, buscar vencedores anteriores
      let actualT1Id = t1Id, actualT2Id = t2Id;
      let actualT1Name = t1Name, actualT2Name = t2Name;
      
      if (round === 'semifinals') {
        if (matchNumber === 1) {
          const prev1 = getMatch(conference, 'first_round', 1);
          const prev2 = getMatch(conference, 'first_round', 2);
          if (prev1?.winner_id) { actualT1Id = prev1.winner_id; actualT1Name = getTeamInfo(prev1.winner_id); }
          if (prev2?.winner_id) { actualT2Id = prev2.winner_id; actualT2Name = getTeamInfo(prev2.winner_id); }
        } else {
          const prev3 = getMatch(conference, 'first_round', 3);
          const prev4 = getMatch(conference, 'first_round', 4);
          if (prev3?.winner_id) { actualT1Id = prev3.winner_id; actualT1Name = getTeamInfo(prev3.winner_id); }
          if (prev4?.winner_id) { actualT2Id = prev4.winner_id; actualT2Name = getTeamInfo(prev4.winner_id); }
        }
      } else if (round === 'conference_finals') {
        const semi1 = getMatch(conference, 'semifinals', 1);
        const semi2 = getMatch(conference, 'semifinals', 2);
        if (semi1?.winner_id) { actualT1Id = semi1.winner_id; actualT1Name = getTeamInfo(semi1.winner_id); }
        if (semi2?.winner_id) { actualT2Id = semi2.winner_id; actualT2Name = getTeamInfo(semi2.winner_id); }
      }
      
      const canSelect = actualT1Id && actualT2Id;
      const t1Class = winnerId == actualT1Id ? 'btn-success' : 'btn-outline-light';
      const t2Class = winnerId == actualT2Id ? 'btn-success' : 'btn-outline-light';
      
      return `
        <div class="matchup mb-2 p-2 bg-dark-panel rounded">
          <div class="d-flex justify-content-between align-items-center">
            <button class="btn btn-sm ${t1Class} flex-grow-1 me-1 ${!canSelect ? 'disabled' : ''}" 
                    onclick="selectWinner('${conference}', '${round}', ${matchNumber}, ${actualT1Id})"
                    ${!canSelect ? 'disabled' : ''}>
              ${actualT1Name || 'TBD'}
            </button>
            <span class="text-muted mx-1">vs</span>
            <button class="btn btn-sm ${t2Class} flex-grow-1 ms-1 ${!canSelect ? 'disabled' : ''}"
                    onclick="selectWinner('${conference}', '${round}', ${matchNumber}, ${actualT2Id})"
                    ${!canSelect ? 'disabled' : ''}>
              ${actualT2Name || 'TBD'}
            </button>
          </div>
        </div>
      `;
    }

    function renderFinals() {
      const lesteChamp = getMatch('LESTE', 'conference_finals', 1);
      const oesteChamp = getMatch('OESTE', 'conference_finals', 1);
      const finalsMatch = getMatch('FINALS', 'finals', 1);
      
      const lesteTeam = lesteChamp?.winner_id ? getTeamInfo(lesteChamp.winner_id) : 'Campeão LESTE';
      const oesteTeam = oesteChamp?.winner_id ? getTeamInfo(oesteChamp.winner_id) : 'Campeão OESTE';
      
      const canSelect = lesteChamp?.winner_id && oesteChamp?.winner_id;
      const lesteClass = finalsMatch?.winner_id == lesteChamp?.winner_id ? 'btn-warning' : 'btn-outline-danger';
      const oesteClass = finalsMatch?.winner_id == oesteChamp?.winner_id ? 'btn-warning' : 'btn-outline-primary';
      
      return `
        <div class="finals-matchup p-3">
          <div class="d-flex justify-content-center align-items-center gap-3">
            <button class="btn btn-lg ${lesteClass} ${!canSelect ? 'disabled' : ''}"
                    onclick="selectFinalWinner(${lesteChamp?.winner_id})"
                    ${!canSelect ? 'disabled' : ''}>
              <i class="bi bi-trophy me-2"></i>${lesteTeam}
            </button>
            <span class="text-warning fs-4 fw-bold">VS</span>
            <button class="btn btn-lg ${oesteClass} ${!canSelect ? 'disabled' : ''}"
                    onclick="selectFinalWinner(${oesteChamp?.winner_id})"
                    ${!canSelect ? 'disabled' : ''}>
              ${oesteTeam}<i class="bi bi-trophy ms-2"></i>
            </button>
          </div>
          ${finalsMatch?.winner_id ? `
            <div class="mt-3">
              <span class="badge bg-warning text-dark fs-5 p-2">
                <i class="bi bi-trophy-fill me-2"></i>CAMPEÃO: ${getTeamInfo(finalsMatch.winner_id)}
              </span>
            </div>
          ` : ''}
        </div>
      `;
    }

    async function selectWinner(conference, round, matchNumber, winnerId) {
      if (!winnerId) return;
      
      try {
        // Determinar os times do confronto
        let team1Id, team2Id;
        const bracket = playoffState.bracket[conference];
        
        if (round === 'first_round') {
          const matchups = [[1,8], [4,5], [3,6], [2,7]];
          const seeds = matchups[matchNumber - 1];
          team1Id = bracket.find(b => b.seed == seeds[0])?.team_id;
          team2Id = bracket.find(b => b.seed == seeds[1])?.team_id;
        } else if (round === 'semifinals') {
          if (matchNumber === 1) {
            team1Id = getMatch(conference, 'first_round', 1)?.winner_id;
            team2Id = getMatch(conference, 'first_round', 2)?.winner_id;
          } else {
            team1Id = getMatch(conference, 'first_round', 3)?.winner_id;
            team2Id = getMatch(conference, 'first_round', 4)?.winner_id;
          }
        } else if (round === 'conference_finals') {
          team1Id = getMatch(conference, 'semifinals', 1)?.winner_id;
          team2Id = getMatch(conference, 'semifinals', 2)?.winner_id;
        }
        
        const response = await fetch('/api/playoffs.php?action=record_result', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            season_id: playoffState.seasonId,
            league: playoffState.league,
            conference: conference,
            round: round,
            match_number: matchNumber,
            team1_id: team1Id,
            team2_id: team2Id,
            winner_id: winnerId
          })
        });
        const result = await response.json();
        
        if (!result.success) {
          throw new Error(result.error);
        }
        
        // Recarregar a tela
        renderPlayoffStep2();
      } catch (e) {
        alert('Erro ao registrar resultado: ' + (e.message || 'Desconhecido'));
      }
    }

    async function selectFinalWinner(winnerId) {
      if (!winnerId) return;
      
      const lesteChamp = getMatch('LESTE', 'conference_finals', 1);
      const oesteChamp = getMatch('OESTE', 'conference_finals', 1);
      
      try {
        const response = await fetch('/api/playoffs.php?action=record_result', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            season_id: playoffState.seasonId,
            league: playoffState.league,
            conference: 'FINALS',
            round: 'finals',
            match_number: 1,
            team1_id: lesteChamp.winner_id,
            team2_id: oesteChamp.winner_id,
            winner_id: winnerId
          })
        });
        const result = await response.json();
        
        if (!result.success) {
          throw new Error(result.error);
        }
        
        renderPlayoffStep2();
      } catch (e) {
        alert('Erro ao registrar campeão: ' + (e.message || 'Desconhecido'));
      }
    }

    function checkFinalsComplete() {
      const finalsMatch = getMatch('FINALS', 'finals', 1);
      const btn = document.getElementById('btnStep3');
      if (btn) {
        btn.disabled = !finalsMatch?.winner_id;
      }
    }

    function goToStep3() {
      playoffState.step = 3;
      renderPlayoffStep3();
    }

    // PASSO 3: Prêmios Individuais
    function renderPlayoffStep3() {
      const container = document.getElementById('mainContainer');
      const teams = playoffState.teams;
      
      container.innerHTML = `
        <button class="btn btn-back mb-4" onclick="renderPlayoffStep2()">
          <i class="bi bi-arrow-left me-2"></i>Voltar ao Bracket
        </button>
        
        <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
          <div class="card-body">
            <h3 class="text-white mb-2">
              <i class="bi bi-award text-orange me-2"></i>
              Prêmios Individuais - Temporada ${String(currentSeasonData.season_number).padStart(2, '0')}
            </h3>
            <p class="text-light-gray mb-0">
              <span class="badge bg-orange me-2">Passo 3 de 4</span>
              Registre os prêmios individuais da temporada (+1 ponto cada para o time)
            </p>
          </div>
        </div>

        <div class="card bg-dark border-warning mb-4" style="border-radius: 15px;">
          <div class="card-body">
            <form id="awardsForm">
              <!-- MVP -->
              <div class="row mb-4">
                <div class="col-md-6">
                  <label class="form-label text-white">
                    <i class="bi bi-star-fill text-warning me-2"></i>MVP (+1pt para o time)
                  </label>
                  <input type="text" class="form-control bg-dark text-white" name="mvp_player" placeholder="Nome do jogador">
                </div>
                <div class="col-md-6">
                  <label class="form-label text-white">Time do MVP</label>
                  <select class="form-select bg-dark text-white" name="mvp_team_id">
                    <option value="">Selecione o time</option>
                    ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                </div>
              </div>
              
              <!-- DPOY -->
              <div class="row mb-4">
                <div class="col-md-6">
                  <label class="form-label text-white">
                    <i class="bi bi-shield-fill text-info me-2"></i>DPOY (+1pt para o time)
                  </label>
                  <input type="text" class="form-control bg-dark text-white" name="dpoy_player" placeholder="Nome do jogador">
                </div>
                <div class="col-md-6">
                  <label class="form-label text-white">Time do DPOY</label>
                  <select class="form-select bg-dark text-white" name="dpoy_team_id">
                    <option value="">Selecione o time</option>
                    ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                </div>
              </div>
              
              <!-- MIP -->
              <div class="row mb-4">
                <div class="col-md-6">
                  <label class="form-label text-white">
                    <i class="bi bi-graph-up-arrow text-success me-2"></i>MIP (+1pt para o time)
                  </label>
                  <input type="text" class="form-control bg-dark text-white" name="mip_player" placeholder="Nome do jogador">
                </div>
                <div class="col-md-6">
                  <label class="form-label text-white">Time do MIP</label>
                  <select class="form-select bg-dark text-white" name="mip_team_id">
                    <option value="">Selecione o time</option>
                    ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                </div>
              </div>
              
              <!-- 6º Homem -->
              <div class="row mb-4">
                <div class="col-md-6">
                  <label class="form-label text-white">
                    <i class="bi bi-person-plus text-primary me-2"></i>6º Homem (+1pt para o time)
                  </label>
                  <input type="text" class="form-control bg-dark text-white" name="sixth_man_player" placeholder="Nome do jogador">
                </div>
                <div class="col-md-6">
                  <label class="form-label text-white">Time do 6º Homem</label>
                  <select class="form-select bg-dark text-white" name="sixth_man_team_id">
                    <option value="">Selecione o time</option>
                    ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                </div>
              </div>

              <!-- ROY -->
              <div class="row mb-2">
                <div class="col-md-6">
                  <label class="form-label text-white">
                    <i class="bi bi-star-fill text-warning me-2"></i>ROY (+1pt para o time)
                  </label>
                  <input type="text" class="form-control bg-dark text-white" name="roy_player" placeholder="Nome do jogador">
                </div>
                <div class="col-md-6">
                  <label class="form-label text-white">Time do ROY</label>
                  <select class="form-select bg-dark text-white" name="roy_team_id">
                    <option value="">Selecione o time</option>
                    ${teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                </div>
              </div>
            </form>
          </div>
        </div>
        
        <div class="d-grid">
          <button class="btn btn-orange btn-lg" onclick="goToStep4()">
            <i class="bi bi-arrow-right me-2"></i>Revisar e Finalizar
          </button>
        </div>
      `;
    }

    function goToStep4() {
      // Salvar dados do formulário
      const form = document.getElementById('awardsForm');
      const formData = new FormData(form);
      
      playoffState.awards = {
        mvp_player: formData.get('mvp_player') || null,
        mvp_team_id: formData.get('mvp_team_id') || null,
        dpoy_player: formData.get('dpoy_player') || null,
        dpoy_team_id: formData.get('dpoy_team_id') || null,
        mip_player: formData.get('mip_player') || null,
        mip_team_id: formData.get('mip_team_id') || null,
        sixth_man_player: formData.get('sixth_man_player') || null,
        sixth_man_team_id: formData.get('sixth_man_team_id') || null,
        roy_player: formData.get('roy_player') || null,
        roy_team_id: formData.get('roy_team_id') || null
      };
      
      playoffState.step = 4;
      renderPlayoffStep4();
    }

    // PASSO 4: Revisão e Finalização
    async function renderPlayoffStep4() {
      const container = document.getElementById('mainContainer');
      const finalsMatch = getMatch('FINALS', 'finals', 1);
      const lesteChamp = getMatch('LESTE', 'conference_finals', 1);
      const oesteChamp = getMatch('OESTE', 'conference_finals', 1);
      
      const champion = finalsMatch?.winner_id;
      const runnerUp = champion == lesteChamp?.winner_id ? oesteChamp?.winner_id : lesteChamp?.winner_id;
      
      container.innerHTML = `
        <button class="btn btn-back mb-4" onclick="renderPlayoffStep3()">
          <i class="bi bi-arrow-left me-2"></i>Voltar aos Prêmios
        </button>
        
        <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
          <div class="card-body">
            <h3 class="text-white mb-2">
              <i class="bi bi-check-circle text-orange me-2"></i>
              Revisão Final - Temporada ${String(currentSeasonData.season_number).padStart(2, '0')}
            </h3>
            <p class="text-light-gray mb-0">
              <span class="badge bg-orange me-2">Passo 4 de 4</span>
              Revise os dados e clique em Finalizar para salvar e calcular todos os pontos
            </p>
          </div>
        </div>

        <!-- Resumo dos Playoffs -->
        <div class="row mb-4">
          <div class="col-md-6">
            <div class="card bg-dark border-warning h-100">
              <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-trophy-fill me-2"></i>Resultado dos Playoffs</h5>
              </div>
              <div class="card-body">
                <p class="mb-2"><strong class="text-warning">Campeão (+5pts):</strong> <span class="text-white">${champion ? getTeamInfo(champion) : 'N/A'}</span></p>
                <p class="mb-2"><strong class="text-secondary">Vice-Campeão (+2pts):</strong> <span class="text-white">${runnerUp ? getTeamInfo(runnerUp) : 'N/A'}</span></p>
                <p class="mb-2"><strong class="text-success">Finalista LESTE (+3pts):</strong> <span class="text-white">${lesteChamp?.winner_id ? getTeamInfo(lesteChamp.winner_id) : 'N/A'}</span></p>
                <p class="mb-0"><strong class="text-primary">Finalista OESTE (+3pts):</strong> <span class="text-white">${oesteChamp?.winner_id ? getTeamInfo(oesteChamp.winner_id) : 'N/A'}</span></p>
              </div>
            </div>
          </div>
          
          <div class="col-md-6">
            <div class="card bg-dark border-info h-100">
              <div class="card-header bg-info text-dark">
                <h5 class="mb-0"><i class="bi bi-award me-2"></i>Prêmios Individuais</h5>
              </div>
              <div class="card-body">
                ${playoffState.awards.mvp_player ? `<p class="mb-2"><strong class="text-warning">MVP:</strong> <span class="text-white">${playoffState.awards.mvp_player}</span></p>` : ''}
                ${playoffState.awards.dpoy_player ? `<p class="mb-2"><strong class="text-info">DPOY:</strong> <span class="text-white">${playoffState.awards.dpoy_player}</span></p>` : ''}
                ${playoffState.awards.mip_player ? `<p class="mb-2"><strong class="text-success">MIP:</strong> <span class="text-white">${playoffState.awards.mip_player}</span></p>` : ''}
                ${playoffState.awards.sixth_man_player ? `<p class="mb-0"><strong class="text-primary">6º Homem:</strong> <span class="text-white">${playoffState.awards.sixth_man_player}</span></p>` : ''}
                ${playoffState.awards.roy_player ? `<p class="mb-0"><strong class="text-warning">ROY:</strong> <span class="text-white">${playoffState.awards.roy_player}</span></p>` : ''}
                ${!playoffState.awards.mvp_player && !playoffState.awards.dpoy_player && !playoffState.awards.mip_player && !playoffState.awards.sixth_man_player && !playoffState.awards.roy_player ? '<p class="text-muted mb-0">Nenhum prêmio registrado</p>' : ''}
              </div>
            </div>
          </div>
        </div>

        <!-- Resumo de Pontos -->
        <div class="card bg-dark border-success mb-4">
          <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="bi bi-calculator me-2"></i>Sistema de Pontuação</h5>
          </div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-4">
                <h6 class="text-warning">Playoffs</h6>
                <ul class="list-unstyled text-light-gray small">
                  <li>• Campeão: +5 pts</li>
                  <li>• Vice-Campeão: +2 pts</li>
                  <li>• Finalista Conferência: +3 pts</li>
                  <li>• Semifinalista: +2 pts</li>
                  <li>• 1ª Rodada: +1 pt</li>
                </ul>
              </div>
              <div class="col-md-4">
                <h6 class="text-info">Temporada Regular</h6>
                <ul class="list-unstyled text-light-gray small">
                  <li>• 1º Lugar: +4 pts</li>
                  <li>• 2º ao 4º Lugar: +3 pts</li>
                  <li>• 5º ao 8º Lugar: +2 pts</li>
                </ul>
              </div>
              <div class="col-md-4">
                <h6 class="text-success">Prêmios Individuais</h6>
                <ul class="list-unstyled text-light-gray small">
                  <li>• MVP: +1 pt</li>
                  <li>• DPOY: +1 pt</li>
                  <li>• MIP: +1 pt</li>
                  <li>• 6º Homem: +1 pt</li>
                  <li>• ROY: +1 pt</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
        
        <div class="d-grid">
          <button class="btn btn-success btn-lg" onclick="finalizePlayoffs()" id="btnFinalize">
            <i class="bi bi-check-circle me-2"></i>Finalizar e Calcular Pontos
          </button>
        </div>
      `;
    }

    async function finalizePlayoffs() {
      const btn = document.getElementById('btnFinalize');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processando...';
      
      try {
        // 1. Salvar prêmios individuais
        await fetch('/api/playoffs.php?action=save_awards', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            season_id: playoffState.seasonId,
            league: playoffState.league,
            awards: playoffState.awards
          })
        });
        
        // 2. Finalizar e calcular pontos
        const response = await fetch('/api/playoffs.php?action=finalize', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            season_id: playoffState.seasonId,
            league: playoffState.league
          })
        });
        const result = await response.json();
        
        if (!result.success) {
          throw new Error(result.error);
        }
        
        alert('Playoffs finalizados com sucesso! Todos os pontos foram calculados e aplicados.');
        showLeagueManagement(playoffState.league);
      } catch (e) {
        alert('Erro ao finalizar playoffs: ' + (e.message || 'Desconhecido'));
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Finalizar e Calcular Pontos';
      }
    }

    // ========== REGISTRAR PONTOS DA TEMPORADA (MANUAL) ==========
    async function showSeasonPointsForm(seasonId, league) {
      const container = document.getElementById('mainContainer');

      try {
        // Buscar times com pontos atuais desta temporada
        const pointsResp = await fetch(`/api/history-points.php?action=get_teams_for_points&season_id=${seasonId}&league=${league}`);
        const pointsData = await pointsResp.json();
        if (!pointsData.success) throw new Error(pointsData.error || 'Erro ao carregar pontos');
        const teams = pointsData.teams || [];

        container.innerHTML = `
          <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left me-2"></i>Voltar
          </button>

          <div class="card bg-dark-panel border-warning" style="border-radius: 15px;">
            <div class="card-body">
              <h3 class="text-white mb-4">
                <i class="bi bi-bar-chart-steps text-warning me-2"></i>
                Pontos da Temporada ${String(currentSeasonData.season_number).padStart(2, '0')}
              </h3>

              <form id="pointsForm" onsubmit="saveSeasonPoints(event, ${seasonId}, '${league}')">
                <div class="table-responsive">
                  <table class="table table-dark table-hover">
                    <thead>
                      <tr>
                        <th>Time</th>
                        <th style="width: 120px;">Pontos</th>
                        <th class="d-none d-md-table-cell">Observação (opcional)</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${teams.map(t => `
                        <tr>
                          <td>
                            <div class="d-flex align-items-center gap-2">
                              <img src="${t.photo_url || '/img/default-team.png'}" alt="${t.team_name}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">
                              <span>${t.team_name}</span>
                            </div>
                          </td>
                          <td>
                            <input type="number" class="form-control bg-dark text-white border-warning" name="points_${t.id}" value="${Number(t.current_points || 0)}" min="0" />
                          </td>
                          <td class="d-none d-md-table-cell">
                            <input type="text" class="form-control bg-dark text-white border-warning" name="reason_${t.id}" placeholder="Ex: desempenho regular, bônus" />
                          </td>
                        </tr>
                      `).join('')}
                    </tbody>
                  </table>
                </div>

                <div class="d-grid gap-2">
                  <button type="submit" class="btn btn-warning">
                    <i class="bi bi-save me-2"></i>Salvar Pontos (Editar)
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

    async function saveSeasonPoints(event, seasonId, league) {
      event.preventDefault();
      const form = event.target;

      // Montar payload
      const teamPoints = [];
      const formData = new FormData(form);
      for (const [key, value] of formData.entries()) {
        if (key.startsWith('points_')) {
          const teamId = Number(key.replace('points_', ''));
          const points = Number(value || 0);
          teamPoints.push({ team_id: teamId, points });
        }
      }

      try {
        await fetch('/api/history-points.php?action=save_season_points', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ 
            season_id: seasonId, 
            league: league,
            team_points: teamPoints 
          })
        }).then(res => res.json()).then(data => {
          if (!data.success) throw new Error(data.error);
        });
        
        alert('Pontos salvos com sucesso!');
        showLeagueManagement(league);
      } catch (e) {
        alert('Erro ao salvar pontos: ' + (e.message || 'Desconhecido'));
      }
    }

    // ========== SALVAR HISTÓRICO ==========
    // ========== GERENCIAR SESSÃO DE DRAFT ==========
    async function showDraftSessionManagement(seasonId, league) {
      currentSeasonId = seasonId;
      currentLeague = league;
      const container = document.getElementById('mainContainer');
      
      container.innerHTML = `
        <div class="text-center py-5">
          <div class="spinner-border text-orange"></div>
          <p class="text-light-gray mt-2">Carregando sessão de draft...</p>
        </div>
      `;
      
      try {
        // Verificar se já existe uma sessão de draft para esta temporada
        const draftData = await api(`draft.php?action=active_draft&league=${league}`);
        const session = draftData.draft;
        
        // Buscar times da liga
        const teamsData = await api(`admin.php?action=teams&league=${league}`);
        const teams = teamsData.teams || [];
        
        if (session && session.season_id == seasonId) {
          // Já existe sessão - mostrar configuração
          await renderDraftSessionConfig(session, teams, league);
        } else {
          // Não existe sessão - mostrar botão para criar
          renderCreateDraftSession(seasonId, league, teams);
        }
      } catch (e) {
        container.innerHTML = `
          <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left me-2"></i>Voltar
          </button>
          <div class="alert alert-danger">Erro: ${e.error || 'Desconhecido'}</div>
        `;
      }
    }
    
    function renderCreateDraftSession(seasonId, league, teams) {
      const container = document.getElementById('mainContainer');
      
      container.innerHTML = `
        <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
          <i class="bi bi-arrow-left me-2"></i>Voltar
        </button>
        
        <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
          <div class="card-body text-center py-5">
            <i class="bi bi-trophy text-orange display-1 mb-4"></i>
            <h3 class="text-white mb-3">Criar Sessão de Draft</h3>
            <p class="text-light-gray mb-4">
              Crie uma nova sessão de draft para a temporada atual.<br>
              O draft terá 2 rodadas com ordem snake (a ordem inverte na 2ª rodada).
            </p>
            <button class="btn btn-orange btn-lg" onclick="createDraftSession(${seasonId}, '${league}')">
              <i class="bi bi-plus-circle me-2"></i>Criar Sessão de Draft
            </button>
          </div>
        </div>
      `;
    }
    
    async function createDraftSession(seasonId, league) {
      try {
        const result = await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'create_session',
            season_id: seasonId
          })
        });
        
        alert('Sessão de draft criada com sucesso!');
        showDraftSessionManagement(seasonId, league);
      } catch (e) {
        alert('Erro ao criar sessão: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function renderDraftSessionConfig(session, teams, league) {
      const container = document.getElementById('mainContainer');
      
      // Buscar ordem do draft se existir
      let orderData = { order: [] };
      try {
        orderData = await api(`draft.php?action=draft_order&draft_session_id=${session.id}`);
      } catch (e) {}
      
      const picks = orderData.order || [];
      const round1Picks = picks.filter(p => p.round == 1);
      const round2Picks = picks.filter(p => p.round == 2);
      
      const statusBadge = {
        'setup': '<span class="badge bg-warning">Configurando</span>',
        'in_progress': '<span class="badge bg-success">Em Andamento</span>',
        'completed': '<span class="badge bg-secondary">Concluído</span>'
      };
      
      container.innerHTML = `
        <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
          <i class="bi bi-arrow-left me-2"></i>Voltar
        </button>
        
        <!-- Status da Sessão -->
        <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h4 class="text-white mb-0">
                <i class="bi bi-trophy text-orange me-2"></i>
                Sessão de Draft #${session.id}
              </h4>
              ${statusBadge[session.status]}
            </div>
            <div class="row g-3">
              <div class="col-md-4">
                <div class="bg-dark p-3 rounded">
                  <small class="text-light-gray d-block">Temporada</small>
                  <strong class="text-white">${session.season_number || 'N/A'}</strong>
                </div>
              </div>
              <div class="col-md-4">
                <div class="bg-dark p-3 rounded">
                  <small class="text-light-gray d-block">Rodada Atual</small>
                  <strong class="text-orange">${session.current_round || 1}</strong>
                </div>
              </div>
              <div class="col-md-4">
                <div class="bg-dark p-3 rounded">
                  <small class="text-light-gray d-block">Pick Atual</small>
                  <strong class="text-orange">${session.current_pick || 1}</strong>
                </div>
              </div>
            </div>
            
            ${session.status === 'setup' ? `
              <div class="mt-4 d-flex gap-2 flex-wrap">
                <button class="btn btn-success" onclick="startDraftSession(${session.id}, '${league}')" ${round1Picks.length === 0 ? 'disabled' : ''}>
                  <i class="bi bi-play-fill me-2"></i>Iniciar Draft
                </button>
                <button class="btn btn-danger" onclick="deleteDraftSession(${session.id}, '${league}')">
                  <i class="bi bi-trash me-2"></i>Excluir Sessão
                </button>
              </div>
              ${round1Picks.length === 0 ? `
                <div class="alert alert-warning mt-3 mb-0">
                  <i class="bi bi-exclamation-triangle me-2"></i>
                  Configure a ordem do draft antes de iniciar.
                </div>
              ` : ''}
            ` : session.status === 'in_progress' ? `
              <div class="mt-4 d-flex gap-2 flex-wrap">
                <a href="/drafts.php" class="btn btn-orange">
                  <i class="bi bi-eye me-2"></i>Ver Draft em Andamento
                </a>
                <button class="btn btn-outline-warning" onclick="showAdminPickPanel(${session.id}, ${session.season_id})">
                  <i class="bi bi-shield-lock me-2"></i>Escolher Jogador (Admin)
                </button>
              </div>
            ` : ''}
          </div>
        </div>
        
        <!-- Configurar Ordem -->
        ${session.status === 'setup' ? `
          <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
            <div class="card-header bg-transparent border-orange">
              <h5 class="text-white mb-0">
                <i class="bi bi-list-ol text-orange me-2"></i>
                Definir Ordem do Draft
              </h5>
            </div>
            <div class="card-body">
              <p class="text-light-gray mb-3">
                Arraste os times para definir a ordem da 1ª rodada. A 2ª rodada terá ordem invertida (snake).
              </p>
              <div class="mb-3">
                <label class="text-white mb-2">Selecione os times na ordem do draft:</label>
                <div id="draftOrderList" class="border border-secondary rounded p-2" style="min-height: 100px;">
                  ${round1Picks.length > 0 ? 
                    round1Picks.map((p, idx) => `
                      <div class="draft-order-item bg-dark p-2 mb-2 rounded d-flex justify-content-between align-items-center" data-team-id="${p.original_team_id}" data-pick-id="${p.id}">
                        <span>
                          <strong class="text-orange">#${idx + 1}</strong>
                          <span class="text-white ms-2">${p.team_city} ${p.team_name}</span>
                          ${p.traded_from_team_id ? '<span class="badge bg-info ms-2">Trocada</span>' : ''}
                        </span>
                        <button class="btn btn-sm btn-outline-danger" onclick="removeFromDraftOrder(${p.id}, ${session.id}, '${league}')">
                          <i class="bi bi-x"></i>
                        </button>
                      </div>
                    `).join('') 
                    : '<p class="text-light-gray text-center my-3">Nenhum time adicionado ainda</p>'
                  }
                </div>
              </div>
              
              <div class="mb-3">
                <label class="text-white mb-2">Adicionar time à ordem:</label>
                <div class="input-group">
                  <select class="form-select bg-dark text-white border-orange" id="addTeamSelect">
                    <option value="">Selecione um time...</option>
                    ${teams.filter(t => !round1Picks.some(p => p.original_team_id == t.id))
                      .map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('')}
                  </select>
                  <button class="btn btn-orange" onclick="addTeamToDraftOrder(${session.id}, '${league}')">
                    <i class="bi bi-plus"></i> Adicionar
                  </button>
                </div>
              </div>
              
              <button class="btn btn-outline-success" onclick="autoGenerateDraftOrder(${session.id}, '${league}', ${JSON.stringify(teams).replace(/"/g, '&quot;')})">
                <i class="bi bi-magic me-2"></i>Gerar Ordem Automática (${teams.length} times)
              </button>
            </div>
          </div>
        ` : ''}
        
        <!-- Visualizar Ordem -->
        ${round1Picks.length > 0 ? `
          <div class="row g-4">
            <div class="col-md-6">
              <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
                <div class="card-header bg-transparent border-orange">
                  <h5 class="text-white mb-0">
                    <i class="bi bi-1-circle-fill text-orange me-2"></i>
                    1ª Rodada
                  </h5>
                </div>
                <div class="card-body p-0">
                  <ul class="list-group list-group-flush bg-transparent">
                    ${round1Picks.map((p, idx) => `
                      <li class="list-group-item bg-dark text-white d-flex justify-content-between align-items-center">
                        <span>
                          <strong class="text-orange me-2">#${idx + 1}</strong>
                          ${p.team_city} ${p.team_name}
                        </span>
                        ${p.picked_player_id ? `<span class="badge bg-success">${p.player_name}</span>` : 
                          p.traded_from_team_id ? '<span class="badge bg-info">Trocada</span>' : ''}
                      </li>
                    `).join('')}
                  </ul>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
                <div class="card-header bg-transparent border-orange">
                  <h5 class="text-white mb-0">
                    <i class="bi bi-2-circle-fill text-orange me-2"></i>
                    2ª Rodada (Snake)
                  </h5>
                </div>
                <div class="card-body p-0">
                  <ul class="list-group list-group-flush bg-transparent">
                    ${round2Picks.map((p, idx) => `
                      <li class="list-group-item bg-dark text-white d-flex justify-content-between align-items-center">
                        <span>
                          <strong class="text-orange me-2">#${idx + 1}</strong>
                          ${p.team_city} ${p.team_name}
                        </span>
                        ${p.picked_player_id ? `<span class="badge bg-success">${p.player_name}</span>` : 
                          p.traded_from_team_id ? '<span class="badge bg-info">Trocada</span>' : ''}
                      </li>
                    `).join('')}
                  </ul>
                </div>
              </div>
            </div>
          </div>
        ` : ''}
      `;
    }

    // ========== ADMIN: ESCOLHER JOGADOR NA VEZ ATUAL ==========
    async function showAdminPickPanel(draftSessionId, seasonId) {
      const container = document.getElementById('mainContainer');
      const orderData = await api(`draft.php?action=draft_order&draft_session_id=${draftSessionId}`);
      const session = orderData.session || {};
      const currentRound = session.current_round;
      const currentPickPos = session.current_pick;

      // Buscar pick atual sem jogador
      let currentPick = null;
      const picks = orderData.order || [];
      for (const p of picks) {
        if (p.round == currentRound && p.pick_position == currentPickPos && !p.picked_player_id) {
          currentPick = p;
          break;
        }
      }

      // Buscar jogadores disponíveis
      const playersData = await api(`draft.php?action=available_players&season_id=${seasonId}`);
      const players = playersData.players || [];

      container.innerHTML = `
        <button class="btn btn-back mb-4" onclick="showDraftSessionManagement(${session.season_id}, '${currentLeague || ''}')">
          <i class="bi bi-arrow-left me-2"></i>Voltar
        </button>

        <div class="card bg-dark-panel border-warning" style="border-radius: 15px;">
          <div class="card-header bg-transparent border-warning">
            <h5 class="text-white mb-0">
              <i class="bi bi-shield-lock text-warning me-2"></i>
              Escolher Jogador (Admin)
            </h5>
          </div>
          <div class="card-body">
            ${currentPick ? `
              <div class="bg-dark p-3 rounded border border-warning text-white">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <div><small class="text-light-gray">Rodada</small> <strong class="text-orange">${currentRound}</strong></div>
                    <div><small class="text-light-gray">Pick</small> <strong class="text-orange">${currentPickPos}</strong></div>
                    <div><small class="text-light-gray">Time</small> <strong class="text-white">${currentPick.team_city} ${currentPick.team_name}</strong></div>
                  </div>
                  <span class="badge bg-info">Vez do Time</span>
                </div>
              </div>
            ` : `
              <div class="alert alert-secondary">Nenhuma pick pendente no momento.</div>
            `}

            <div class="mb-3">
              <input type="text" id="adminPickSearch" class="form-control bg-dark text-white border-warning" placeholder="Buscar jogador por nome ou posição..." oninput="filterAdminPickList()" />
            </div>

            <div class="table-responsive">
              <table class="table table-dark table-hover" id="adminPickTable">
                <thead>
                  <tr>
                    <th>Jogador</th>
                    <th style="width:80px">Pos</th>
                    <th style="width:80px">OVR</th>
                    <th style="width:160px"></th>
                  </tr>
                </thead>
                <tbody>
                  ${players.map(pl => `
                    <tr>
                      <td class="text-white">${pl.name}</td>
                      <td>${pl.position}</td>
                      <td>${pl.ovr}</td>
                      <td>
                        <button class="btn btn-warning btn-sm" onclick="adminMakePick(${draftSessionId}, ${pl.id})" ${currentPick ? '' : 'disabled'}>
                          <i class="bi bi-check2-circle me-1"></i>Escolher
                        </button>
                      </td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      `;
    }

    function filterAdminPickList() {
      const term = (document.getElementById('adminPickSearch').value || '').toLowerCase();
      const rows = document.querySelectorAll('#adminPickTable tbody tr');
      rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
      });
    }

    async function adminMakePick(draftSessionId, playerId) {
      if (!confirm('Confirmar escolha deste jogador nesta pick?')) return;
      try {
        const res = await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'make_pick',
            draft_session_id: draftSessionId,
            player_id: playerId
          })
        });
        alert(res.message || 'Jogador escolhido com sucesso');
        // Voltar para a gestão da sessão
        showDraftSessionManagement(currentSeasonId, currentLeague);
      } catch (e) {
        alert('Erro ao escolher jogador: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function addTeamToDraftOrder(sessionId, league) {
      const select = document.getElementById('addTeamSelect');
      const teamId = select.value;
      
      if (!teamId) {
        alert('Selecione um time');
        return;
      }
      
      try {
        await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'add_to_order',
            draft_session_id: sessionId,
            team_id: teamId
          })
        });
        
        showDraftSessionManagement(currentSeasonId, league);
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function removeFromDraftOrder(pickId, sessionId, league) {
      if (!confirm('Remover este time da ordem?')) return;
      
      try {
        await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'remove_from_order',
            pick_id: pickId,
            draft_session_id: sessionId
          })
        });
        
        showDraftSessionManagement(currentSeasonId, league);
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function autoGenerateDraftOrder(sessionId, league, teams) {
      if (!confirm(`Gerar ordem automática com ${teams.length} times? Isso substituirá a ordem atual.`)) return;
      
      try {
        // Primeiro limpar a ordem existente
        await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'clear_order',
            draft_session_id: sessionId
          })
        });
        
        // Adicionar cada time na ordem
        for (let i = 0; i < teams.length; i++) {
          await api('draft.php', {
            method: 'POST',
            body: JSON.stringify({
              action: 'add_to_order',
              draft_session_id: sessionId,
              team_id: teams[i].id
            })
          });
        }
        
        alert('Ordem gerada com sucesso!');
        showDraftSessionManagement(currentSeasonId, league);
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function startDraftSession(sessionId, league) {
      if (!confirm('Iniciar o draft? Os usuários poderão fazer suas picks.')) return;
      
      try {
        await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'start_draft',
            draft_session_id: sessionId
          })
        });
        
        alert('Draft iniciado!');
        showDraftSessionManagement(currentSeasonId, league);
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }
    
    async function deleteDraftSession(sessionId, league) {
      if (!confirm('Tem certeza que deseja excluir esta sessão de draft?')) return;
      
      try {
        await api('draft.php', {
          method: 'POST',
          body: JSON.stringify({
            action: 'delete_session',
            draft_session_id: sessionId
          })
        });
        
        alert('Sessão excluída!');
        showDraftSessionManagement(currentSeasonId, league);
      } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
      }
    }

    // ========== HISTÓRICO DE DRAFTS ==========
    async function showDraftHistory(league) {
      currentLeague = league;
      const container = document.getElementById('mainContainer');
      container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
      
      try {
        // Buscar todas temporadas com drafts
        const data = await api(`draft.php?action=draft_history&league=${league}`);
        const seasons = data.seasons || [];
        
        container.innerHTML = `
          <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left me-2"></i>Voltar
          </button>
          
          <div class="card bg-dark-panel border-orange mb-4" style="border-radius: 15px;">
            <div class="card-body">
              <h4 class="text-white mb-0">
                <i class="bi bi-clock-history text-orange me-2"></i>
                Histórico de Drafts - ${league}
              </h4>
            </div>
          </div>
          
          ${seasons.length === 0 ? `
            <div class="alert alert-info bg-dark border-orange text-white">
              <i class="bi bi-info-circle me-2"></i>
              Nenhuma temporada encontrada com histórico de draft.
            </div>
          ` : `
            <div class="accordion" id="draftHistoryAccordion">
              ${seasons.map((s, idx) => `
                <div class="accordion-item bg-dark border-orange mb-2" style="border-radius: 10px;">
                  <h2 class="accordion-header">
                    <button class="accordion-button collapsed bg-dark-panel text-white" type="button" 
                            data-bs-toggle="collapse" data-bs-target="#collapse${s.id}"
                            onclick="loadDraftSeasonDetails(${s.id})">
                      <span class="me-3">
                        <span class="badge bg-gradient-orange me-2">T${s.season_number}</span>
                        Ano ${s.year}
                      </span>
                      <span class="badge ${s.has_snapshot || s.draft_status === 'completed' ? 'bg-success' : (s.draft_status ? 'bg-warning text-dark' : 'bg-secondary')}">
                        ${s.has_snapshot || s.draft_status === 'completed' ? 'Finalizado' : (s.draft_status === 'in_progress' ? 'Em Andamento' : (s.draft_status === 'setup' ? 'Configurando' : 'Sem Draft'))}
                      </span>
                    </button>
                  </h2>
                  <div id="collapse${s.id}" class="accordion-collapse collapse">
                    <div class="accordion-body" id="draftDetails${s.id}">
                      <div class="text-center py-3">
                        <div class="spinner-border spinner-border-sm text-orange"></div>
                        <span class="ms-2 text-light-gray">Carregando...</span>
                      </div>
                    </div>
                  </div>
                </div>
              `).join('')}
            </div>
          `}
        `;
      } catch (e) {
        container.innerHTML = `
          <button class="btn btn-back mb-4" onclick="showLeagueManagement('${league}')">
            <i class="bi bi-arrow-left me-2"></i>Voltar
          </button>
          <div class="alert alert-danger">Erro ao carregar histórico: ${e.error || 'Desconhecido'}</div>
        `;
      }
    }
    
    async function loadDraftSeasonDetails(seasonId) {
      const container = document.getElementById(`draftDetails${seasonId}`);
      
      // Se já tem conteúdo carregado (não é o spinner), não recarregar
      if (!container.innerHTML.includes('spinner-border')) return;
      
      try {
        const data = await api(`draft.php?action=draft_history&season_id=${seasonId}`);
        const draftOrder = data.draft_order || [];
        
        if (draftOrder.length === 0) {
          container.innerHTML = `
            <div class="text-center text-light-gray py-3">
              <i class="bi bi-inbox display-4"></i>
              <p class="mt-2">Nenhuma escolha registrada nesta temporada.</p>
            </div>
          `;
          return;
        }
        
        // Agrupar por rodada
        const rounds = {};
        draftOrder.forEach(pick => {
          const r = pick.round || 1;
          if (!rounds[r]) rounds[r] = [];
          rounds[r].push(pick);
        });
        
        let html = '';
        for (const [round, picks] of Object.entries(rounds)) {
          html += `
            <h6 class="text-orange mt-3 mb-2"><i class="bi bi-trophy-fill me-2"></i>Rodada ${round}</h6>
            <div class="table-responsive">
              <table class="table table-dark table-sm table-hover mb-0">
                <thead>
                  <tr>
                    <th style="width: 60px;">Pick</th>
                    <th>Time</th>
                    <th>Jogador Escolhido</th>
                    <th class="d-none d-md-table-cell">Pos</th>
                    <th class="d-none d-md-table-cell">OVR</th>
                  </tr>
                </thead>
                <tbody>
                  ${picks.map(p => `
                    <tr>
                      <td><span class="badge bg-orange">#${p.pick_position}</span></td>
                      <td>
                        <strong class="text-white">${p.team_city || ''} ${p.team_name || ''}</strong>
                        ${p.traded_from_team_id ? `<br><small class="text-muted">via ${p.traded_from_city || ''} ${p.traded_from_name || ''}</small>` : ''}
                      </td>
                      <td>
                        ${p.player_name ? `
                          <span class="text-success fw-bold">${p.player_name}</span>
                        ` : `
                          <span class="text-muted">-</span>
                        `}
                      </td>
                      <td class="d-none d-md-table-cell">
                        ${p.player_position ? `<span class="badge bg-secondary">${p.player_position}</span>` : '-'}
                      </td>
                      <td class="d-none d-md-table-cell">
                        ${p.player_ovr ? `<span class="badge bg-success">${p.player_ovr}</span>` : '-'}
                      </td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
          `;
        }
        
        container.innerHTML = html;
      } catch (e) {
        container.innerHTML = `
          <div class="alert alert-danger mb-0">Erro ao carregar detalhes: ${e.error || 'Desconhecido'}</div>
        `;
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
  <script src="/js/pwa.js"></script>
</body>
</html>
