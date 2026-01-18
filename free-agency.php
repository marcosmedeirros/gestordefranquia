<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;
$teamId = $team['id'] ?? null;
$teamPoints = (int)($team['ranking_points'] ?? 0);

$isAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <meta name="theme-color" content="#fc0025">
  <title>Leiloes - FBA Manager</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
  <style>
    .fa-card { background: var(--fba-panel); border: 1px solid var(--fba-border); border-radius: 12px; padding: 1rem; margin-bottom: 1rem; transition: all 0.3s ease; }
    .fa-card:hover { border-color: var(--fba-orange); transform: translateY(-2px); }
    .fa-card.auction-card { position: relative; overflow: hidden; }
    .fa-card.auction-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--fba-orange), #ff6b00); }
    .fa-card.border-success { border-color: #28a745 !important; }
    .fa-card.border-success::before { background: linear-gradient(90deg, #28a745, #20c997); }
    .points-badge { font-size: 1rem; padding: 8px 16px; }
    .auction-timer.ending-soon { animation: pulse 1s infinite; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
  </style>
</head>
<body>
  <div class="dashboard-sidebar">
    <div class="text-center mb-4">
      <img src="<?= htmlspecialchars(($team['photo_url'] ?? '/img/default-team.png')) ?>" alt="<?= htmlspecialchars($team['name'] ?? 'Time') ?>" class="team-avatar">
      <h5 class="text-white mb-1"><?= isset($team['name']) ? htmlspecialchars(($team['city'] . ' ' . $team['name'])) : 'Sem time' ?></h5>
      <span class="badge bg-gradient-orange"><?= htmlspecialchars($user['league']) ?></span>
    </div>
    <hr style="border-color: var(--fba-border);">
    <ul class="sidebar-menu">
      <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i>Dashboard</a></li>
      <li><a href="/teams.php"><i class="bi bi-people-fill"></i>Times</a></li>
      <li><a href="/my-roster.php"><i class="bi bi-person-badge-fill"></i>Meu Elenco</a></li>
      <li><a href="/picks.php"><i class="bi bi-trophy-fill"></i>Picks</a></li>
      <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i>Trades</a></li>
      <li><a href="/free-agency.php" class="active"><i class="bi bi-hammer"></i>Leiloes</a></li>
      <li><a href="/drafts.php"><i class="bi bi-trophy"></i>Draft</a></li>
      <li><a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i>Rankings</a></li>
      <li><a href="/history.php"><i class="bi bi-clock-history"></i>Historico</a></li>
      <?php if ($isAdmin): ?>
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
    <div class="mb-4">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1 class="text-white fw-bold mb-0"><i class="bi bi-hammer me-2 text-orange"></i>Leilões</h1>
        <div class="d-flex gap-2 align-items-center flex-wrap">
          <?php if ($isAdmin): ?>
          <select class="form-select bg-dark text-white border-orange" id="adminLeagueFilter" style="width: auto;">
            <option value="ELITE" <?= ($user['league'] ?? '') === 'ELITE' ? 'selected' : '' ?>>ELITE</option>
            <option value="NEXT" <?= ($user['league'] ?? '') === 'NEXT' ? 'selected' : '' ?>>NEXT</option>
            <option value="RISE" <?= ($user['league'] ?? '') === 'RISE' ? 'selected' : '' ?>>RISE</option>
            <option value="ROOKIE" <?= ($user['league'] ?? '') === 'ROOKIE' ? 'selected' : '' ?>>ROOKIE</option>
          </select>
          <?php endif; ?>
          <span class="badge bg-orange points-badge">
            <i class="bi bi-coin me-1"></i>Seus Pontos: <span id="available-points"><?= $teamPoints ?></span>
          </span>
          <?php if ($isAdmin): ?>
          <button class="btn btn-orange" data-bs-toggle="modal" data-bs-target="#adminAuctionModal">
            <i class="bi bi-gear me-1"></i>Gerenciar Leilões
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$teamId): ?>
      <div class="alert alert-warning">Voce ainda nao possui um time.</div>
    <?php else: ?>

    <div class="alert alert-info bg-dark border-orange mb-4">
      <div class="d-flex align-items-start">
        <i class="bi bi-info-circle-fill text-orange me-3 fs-4"></i>
        <div>
          <h6 class="text-white mb-1">Como funciona o Leilao?</h6>
          <ul class="text-light-gray mb-0 small">
            <li>Cada leilao dura <strong class="text-orange">20 minutos</strong></li>
            <li>O lance inicial e de <strong class="text-orange">1 ponto</strong></li>
            <li>Para dar um lance, voce precisa cobrir o lance atual</li>
            <li>Quando o tempo acabar, o <strong class="text-success">maior lance vence</strong></li>
            <li>Os pontos sao deduzidos apenas do vencedor</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="text-white mb-0"><i class="bi bi-broadcast text-success me-2"></i>Leiloes Ativos</h4>
      <button class="btn btn-outline-orange btn-sm" onclick="loadActiveAuctions()">
        <i class="bi bi-arrow-repeat"></i> Atualizar
      </button>
    </div>
    
    <div id="auctions-list" class="row">
      <div class="col-12 text-center py-5">
        <div class="spinner-border text-orange"></div>
      </div>
    </div>

    <div class="mt-5">
      <h4 class="text-white mb-3"><i class="bi bi-clock-history text-secondary me-2"></i>Leiloes Recentes</h4>
      <div id="recent-auctions-list">
        <div class="text-center py-3"><div class="spinner-border spinner-border-sm text-secondary"></div></div>
      </div>
    </div>

    <?php endif; ?>
  </div>

  <?php if ($isAdmin): ?>
  <div class="modal fade" id="adminAuctionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content bg-dark-panel border-orange">
        <div class="modal-header border-bottom border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-gear me-2 text-orange"></i>Gerenciar Leiloes</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-4">
            <h6 class="text-orange mb-3"><i class="bi bi-plus-circle me-1"></i>Cadastrar Jogador e Iniciar Leilao</h6>
            <form id="createAuctionForm">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label text-light-gray">Nome do Jogador</label>
                  <input type="text" class="form-control bg-dark text-white border-orange" id="auctionPlayerName" required>
                </div>
                <div class="col-md-3">
                  <label class="form-label text-light-gray">Idade</label>
                  <input type="number" class="form-control bg-dark text-white border-orange" id="auctionPlayerAge" min="18" max="45" value="25" required>
                </div>
                <div class="col-md-3">
                  <label class="form-label text-light-gray">OVR</label>
                  <input type="number" class="form-control bg-dark text-white border-orange" id="auctionPlayerOvr" min="40" max="99" value="70" required>
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-4">
                  <label class="form-label text-light-gray">Posicao</label>
                  <select class="form-select bg-dark text-white border-orange" id="auctionPlayerPosition" required>
                    <option value="PG">PG - Armador</option>
                    <option value="SG">SG - Ala-Armador</option>
                    <option value="SF">SF - Ala</option>
                    <option value="PF">PF - Ala-Pivo</option>
                    <option value="C">C - Pivo</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label text-light-gray">Posicao Secundaria</label>
                  <select class="form-select bg-dark text-white border-orange" id="auctionPlayerSecondary">
                    <option value="">Nenhuma</option>
                    <option value="PG">PG</option>
                    <option value="SG">SG</option>
                    <option value="SF">SF</option>
                    <option value="PF">PF</option>
                    <option value="C">C</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label text-light-gray">Liga</label>
                  <select class="form-select bg-dark text-white border-orange" id="auctionPlayerLeague">
                    <option value="ELITE">ELITE</option>
                    <option value="NEXT">NEXT</option>
                    <option value="RISE">RISE</option>
                    <option value="ROOKIE">ROOKIE</option>
                  </select>
                </div>
              </div>
              <div class="mt-3">
                <button type="submit" class="btn btn-orange">
                  <i class="bi bi-hammer me-1"></i>Cadastrar e Iniciar Leilao (20 min)
                </button>
              </div>
            </form>
          </div>
          <hr class="border-orange">
          <div>
            <h6 class="text-orange mb-3"><i class="bi bi-broadcast me-1"></i>Leiloes em Andamento</h6>
            <div id="admin-auctions-list">
              <div class="text-center py-3"><div class="spinner-border spinner-border-sm text-orange"></div></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="modal fade" id="bidModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content bg-dark-panel border-orange">
        <div class="modal-header border-bottom border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-hammer me-2 text-orange"></i>Fazer Lance</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="bid-auction-id">
          <div class="text-center mb-4">
            <h4 class="text-white mb-0" id="bid-player-name"></h4>
            <small class="text-light-gray" id="bid-player-info"></small>
          </div>
          <div class="row g-3 mb-4">
            <div class="col-6">
              <div class="bg-dark rounded p-3 text-center">
                <small class="text-light-gray d-block">Lance Atual</small>
                <span class="text-orange fw-bold fs-4" id="bid-current">0</span>
                <small class="text-light-gray"> pts</small>
              </div>
            </div>
            <div class="col-6">
              <div class="bg-dark rounded p-3 text-center">
                <small class="text-light-gray d-block">Seus Pontos</small>
                <span class="text-success fw-bold fs-4" id="bid-available">0</span>
                <small class="text-light-gray"> pts</small>
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label text-white">Seu Lance (minimo: <span class="text-orange" id="bid-min">1</span> pts)</label>
            <input type="number" class="form-control bg-dark text-white border-orange fs-4 text-center" id="bid-amount" min="1" step="1" required>
          </div>
          <div class="alert alert-warning small mb-0">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Se voce vencer, os pontos serao deduzidos e voce recebe o jogador!
          </div>
        </div>
        <div class="modal-footer border-top border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-orange" id="btn-place-bid">
            <i class="bi bi-hammer me-1"></i>Confirmar Lance
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    window.__TEAM_ID__ = <?= $teamId ? (int)$teamId : 'null' ?>;
    window.__IS_ADMIN__ = <?= $isAdmin ? 'true' : 'false' ?>;
    window.__USER_LEAGUE__ = '<?= htmlspecialchars($user['league'], ENT_QUOTES) ?>';
    window.__TEAM_POINTS__ = <?= $teamPoints ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script src="/js/free-agency.js"></script>
  <script src="/js/pwa.js"></script>
</body>
</html>
