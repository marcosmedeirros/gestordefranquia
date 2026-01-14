<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;
$teamId = $team['id'] ?? null;

// Buscar contagem de jogadores separadamente
$playerCount = 0;
if ($teamId) {
    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM players WHERE team_id = ?');
    $stmtCount->execute([$teamId]);
    $playerCount = (int)$stmtCount->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Meu Elenco - FBA Manager</title>
  
  <!-- PWA Meta Tags -->
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#0a0a0c">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="FBA Manager">
  <link rel="apple-touch-icon" href="/img/icon-192.png">
  
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
</head>
<body>
  <!-- Sidebar -->
  <div class="dashboard-sidebar">
    <div class="text-center mb-4">
      <img src="<?= htmlspecialchars(($team['photo_url'] ?? '/img/default-team.png')) ?>" 
           alt="<?= htmlspecialchars($team['name'] ?? 'Time') ?>" class="team-avatar">
      <h5 class="text-white mb-1"><?= isset($team['name']) ? htmlspecialchars(($team['city'] . ' ' . $team['name'])) : 'Sem time' ?></h5>
      <span class="badge bg-gradient-orange"><?= htmlspecialchars($user['league']) ?></span>
    </div>

    <hr style="border-color: var(--fba-border);">

    <ul class="sidebar-menu">
      <li>
        <a href="/dashboard.php">
          <i class="bi bi-house-door-fill"></i>
          Dashboard
        </a>
      </li>
      <li>
        <a href="/teams.php">
          <i class="bi bi-people-fill"></i>
          Times
        </a>
      </li>
      <li>
        <a href="/my-roster.php" class="active">
          <i class="bi bi-person-fill"></i>
          Meu Elenco
        </a>
      </li>
      <li>
        <a href="/picks.php">
          <i class="bi bi-calendar-check-fill"></i>
          Picks
        </a>
      </li>
      <li>
        <a href="/trades.php">
          <i class="bi bi-arrow-left-right"></i>
          Trades
        </a>
      </li>
      <li>
        <a href="/free-agency.php">
          <i class="bi bi-person-plus-fill"></i>
          Free Agency
        </a>
      </li>
      <li>
        <a href="/drafts.php">
          <i class="bi bi-trophy"></i>
          Draft
        </a>
      </li>
      <li>
        <a href="/rankings.php">
          <i class="bi bi-bar-chart-fill"></i>
          Rankings
        </a>
      </li>
      <li>
        <a href="/history.php">
          <i class="bi bi-clock-history"></i>
          Histórico
        </a>
      </li>
      <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
      <li>
        <a href="/admin.php">
          <i class="bi bi-shield-lock-fill"></i>
          Admin
        </a>
      </li>
      <li>
        <a href="/temporadas.php">
          <i class="bi bi-calendar3"></i>
          Temporadas
        </a>
      </li>
      <?php endif; ?>
      <li>
        <a href="/settings.php">
          <i class="bi bi-gear-fill"></i>
          Configurações
        </a>
      </li>
    </ul>

    <hr style="border-color: var(--fba-border);">

    <div class="text-center">
      <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100">
        <i class="bi bi-box-arrow-right me-2"></i>Sair
      </a>
    </div>

    <div class="text-center mt-3">
      <small class="text-light-gray">
        <i class="bi bi-person-circle me-1"></i>
        <?= htmlspecialchars($user['name']) ?>
      </small>
    </div>
  </div>

  <!-- Main Content -->
  <div class="dashboard-content">
    <div class="mb-4">
      <div class="d-flex justify-content-between align-items-center">
        <h1 class="text-white fw-bold mb-0"><i class="bi bi-people-fill me-2 text-orange"></i>Meu Elenco</h1>
        <div class="d-flex gap-3 flex-wrap justify-content-end">
          <div class="text-center">
            <small class="text-light-gray">Total</small>
            <div class="text-white fw-bold" id="total-players" style="font-size: 1.5rem;">0</div>
          </div>
          <div class="text-center">
            <small class="text-light-gray">CAP Top8</small>
            <div class="text-orange fw-bold" id="cap-top8" style="font-size: 1.5rem;">0</div>
          </div>
          <div class="text-center">
            <small class="text-light-gray">Dispensas (FA)</small>
            <div class="text-white fw-bold" id="waivers-count" style="font-size: 1.1rem;">0 / 0</div>
          </div>
          <div class="text-center">
            <small class="text-light-gray">Contratações FA</small>
            <div class="text-white fw-bold" id="signings-count" style="font-size: 1.1rem;">0 / 0</div>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$teamId): ?>
      <div class="alert alert-warning">Você ainda não possui um time. Crie um no onboarding.</div>
    <?php else: ?>
    <div class="card bg-dark-panel border-orange mb-4">
      <div class="card-header bg-transparent border-orange d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h5 class="mb-0 text-white"><i class="bi bi-plus-circle me-2 text-orange"></i>Adicionar Jogador</h5>
          <small class="text-light-gray">Cadastre reforços rapidamente enquanto a temporada está em andamento.</small>
        </div>
        <button class="btn btn-sm btn-outline-light" type="button" data-bs-toggle="collapse" data-bs-target="#addPlayerCollapse" aria-expanded="true" aria-controls="addPlayerCollapse">
          <i class="bi bi-chevron-down"></i>
        </button>
      </div>
      <div class="collapse show" id="addPlayerCollapse">
        <div class="card-body">
          <form id="form-player">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label text-white">Nome</label>
                <input type="text" class="form-control bg-dark text-white border-orange" name="name" placeholder="Ex: John Doe" required>
              </div>
              <div class="col-md-2">
                <label class="form-label text-white">Idade</label>
                <input type="number" class="form-control bg-dark text-white border-orange" name="age" min="16" max="45" required>
              </div>
              <div class="col-md-3">
                <label class="form-label text-white">Posição</label>
                <select class="form-select bg-dark text-white border-orange" name="position" required>
                  <option value="">Selecione</option>
                  <option value="PG">PG</option>
                  <option value="SG">SG</option>
                  <option value="SF">SF</option>
                  <option value="PF">PF</option>
                  <option value="C">C</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label text-white">Posição Secundária</label>
                <select class="form-select bg-dark text-white border-orange" name="secondary_position">
                  <option value="">Nenhuma</option>
                  <option value="PG">PG</option>
                  <option value="SG">SG</option>
                  <option value="SF">SF</option>
                  <option value="PF">PF</option>
                  <option value="C">C</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label text-white">Função</label>
                <select class="form-select bg-dark text-white border-orange" name="role" required>
                  <option value="Titular">Titular</option>
                  <option value="Banco">Banco</option>
                  <option value="Outro">Outro</option>
                  <option value="G-League">G-League</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label text-white">OVR</label>
                <input type="number" class="form-control bg-dark text-white border-orange" name="ovr" min="40" max="99" required>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="available_for_trade" name="available_for_trade" checked>
                  <label class="form-check-label text-light-gray" for="available_for_trade">Disponível para troca</label>
                </div>
              </div>
            </div>
            <div class="bg-dark rounded border border-warning p-3 mt-4">
              <small class="text-light-gray d-block mb-1"><i class="bi bi-info-circle text-orange me-1"></i>Regras rápidas</small>
              <ul class="text-light-gray mb-0 small">
                <li>Máximo de 5 titulares e 2 jogadores G-League por elenco.</li>
                <li>Jogadores G-League precisam ter menos de 25 anos.</li>
                <li>O CAP Top 8 é atualizado automaticamente após cada cadastro.</li>
              </ul>
            </div>
            <div class="text-end mt-3">
              <button type="button" class="btn btn-orange" id="btn-add-player">
                <i class="bi bi-cloud-upload me-1"></i>Cadastrar Jogador
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="card bg-dark-panel border-orange">
      <div class="card-header bg-transparent border-orange d-flex justify-content-between align-items-center">
        <h5 class="mb-0 text-white"><i class="bi bi-list-ul me-2 text-orange"></i>Jogadores</h5>
        <button class="btn btn-outline-orange btn-sm" id="btn-refresh-players"><i class="bi bi-arrow-clockwise me-1"></i> Atualizar</button>
      </div>
      <div class="card-body">
        <div id="players-status" class="text-center mb-3">
          <div class="spinner-border text-orange" role="status"></div>
          <p class="text-light-gray mt-2">Carregando jogadores...</p>
        </div>
        <!-- Desktop: Tabela -->
        <div id="players-list" class="table-responsive d-none">
          <table class="table table-dark align-middle">
            <thead>
              <tr>
                <th class="sortable" data-sort="name" style="cursor: pointer; user-select: none;" title="Clique para ordenar">
                  <i class="bi bi-arrow-down-up" style="opacity: 0.5; margin-right: 5px;"></i>Nome
                </th>
                <th class="sortable" data-sort="ovr" style="cursor: pointer; user-select: none;" title="Clique para ordenar">
                  <i class="bi bi-arrow-down-up" style="opacity: 0.5; margin-right: 5px;"></i>OVR
                </th>
                <th class="sortable" data-sort="position" style="cursor: pointer; user-select: none;" title="Clique para ordenar">
                  <i class="bi bi-arrow-down-up" style="opacity: 0.5; margin-right: 5px;"></i>Posição
                </th>
                <th class="sortable" data-sort="secondary_position" style="cursor: pointer; user-select: none;" title="Clique para ordenar">
                  <i class="bi bi-arrow-down-up" style="opacity: 0.5; margin-right: 5px;"></i>Pos. Sec.
                </th>
                <th class="sortable" data-sort="role" style="cursor: pointer; user-select: none;" title="Clique para ordenar">
                  <i class="bi bi-arrow-down-up" style="opacity: 0.5; margin-right: 5px;"></i>Função
                </th>
                <th class="sortable" data-sort="age" style="cursor: pointer; user-select: none;" title="Clique para ordenar">
                  <i class="bi bi-arrow-down-up" style="opacity: 0.5; margin-right: 5px;"></i>Idade
                </th>
                <th class="sortable" data-sort="trade" style="cursor: pointer; user-select: none;" title="Clique para ordenar">
                  <i class="bi bi-arrow-down-up" style="opacity: 0.5; margin-right: 5px;"></i>Trade?
                </th>
                <th style="cursor: default;">Ações</th>
              </tr>
            </thead>
            <tbody id="players-tbody"></tbody>
          </table>
        </div>
        <!-- Mobile: Cards -->
        <div id="players-cards-mobile" class="player-card-mobile"></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Edit Player Modal -->
  <div class="modal fade" id="editPlayerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content bg-dark-panel border-orange">
        <div class="modal-header border-bottom border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-pencil-square me-2 text-orange"></i>Editar Jogador</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="edit-player-id">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label text-white fw-bold">Nome</label>
              <input type="text" id="edit-name" class="form-control bg-dark text-white border-orange" required>
            </div>
            <div class="col-md-2">
              <label class="form-label text-white fw-bold">Idade</label>
              <input type="number" id="edit-age" class="form-control bg-dark text-white border-orange" min="16" max="50" required>
            </div>
            <div class="col-md-2">
              <label class="form-label text-white fw-bold">Posição</label>
              <select id="edit-position" class="form-select bg-dark text-white border-orange" required>
                <option value="PG">PG</option>
                <option value="SG">SG</option>
                <option value="SF">SF</option>
                <option value="PF">PF</option>
                <option value="C">C</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label text-white fw-bold">Pos. Sec.</label>
              <select id="edit-secondary-position" class="form-select bg-dark text-white border-orange">
                <option value="">Nenhuma</option>
                <option value="PG">PG</option>
                <option value="SG">SG</option>
                <option value="SF">SF</option>
                <option value="PF">PF</option>
                <option value="C">C</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label text-white fw-bold">OVR</label>
              <input type="number" id="edit-ovr" class="form-control bg-dark text-white border-orange" min="40" max="99" required>
            </div>
            <div class="col-md-4">
              <label class="form-label text-white fw-bold">Função</label>
              <select id="edit-role" class="form-select bg-dark text-white border-orange" required>
                <option value="Titular">Titular</option>
                <option value="Banco">Banco</option>
                <option value="Outro">Outro</option>
                <option value="G-League">G-League</option>
              </select>
            </div>
            <div class="col-md-4 d-flex align-items-center">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" id="edit-available">
                <label class="form-check-label text-light-gray" for="edit-available">Disponível para troca</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer border-top border-orange">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-orange" id="btn-save-edit"><i class="bi bi-save2 me-1"></i> Salvar Mudanças</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    window.__TEAM_ID__ = <?= $teamId ? (int)$teamId : 'null' ?>;
    console.log('Team ID:', window.__TEAM_ID__, 'Team:', <?= json_encode($team) ?>);
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script src="/js/my-roster.js"></script>
  <script src="/js/pwa.js"></script>
</body>
</html>
