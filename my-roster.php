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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Meu Elenco - FBA Manager</title>
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
          Todos os Times
        </a>
      </li>
      <li>
        <a href="/my-roster.php" class="active">
          <i class="bi bi-person-fill"></i>
          Meu Elenco
        </a>
      </li>
      <li>
        <a href="/drafts.php">
          <i class="bi bi-trophy-fill"></i>
          Drafts
        </a>
      </li>
      <li>
        <a href="/trades.php">
          <i class="bi bi-arrow-left-right"></i>
          Trades
        </a>
      </li>
      <li>
        <a href="/estatisticas.php">
          <i class="bi bi-bar-chart-fill"></i>
          Estatísticas
        </a>
      </li>
      <li>
        <a href="/ranking.php">
          <i class="bi bi-trophy-fill"></i>
          Ranking
        </a>
      </li>
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
    <div class="mb-4 d-flex align-items-center justify-content-between">
      <h1 class="text-white fw-bold mb-2"><i class="bi bi-people-fill me-2 text-orange"></i>Meu Elenco</h1>
      <?php if ($teamId): ?>
      <span class="badge bg-gradient-orange">Time ID: <?= (int)$teamId ?></span>
      <?php endif; ?>
    </div>

    <?php if (!$teamId): ?>
      <div class="alert alert-warning">Você ainda não possui um time. Crie um no onboarding.</div>
    <?php else: ?>
    <!-- Accordion para adicionar jogador -->
    <div class="accordion mb-4" id="accordionAddPlayer">
      <div class="accordion-item bg-dark-panel border-orange">
        <h2 class="accordion-header">
          <button class="accordion-button collapsed bg-dark-panel text-white border-orange" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAddPlayer">
            <i class="bi bi-plus-circle me-2 text-orange"></i>Adicionar Jogador
          </button>
        </h2>
        <div id="collapseAddPlayer" class="accordion-collapse collapse" data-bs-parent="#accordionAddPlayer">
          <div class="accordion-body bg-dark-panel border-top border-orange">
            <form id="form-player">
              <div class="row g-3">
                <div class="col-md-3">
                  <label class="form-label text-white fw-bold">Nome</label>
                  <input type="text" name="name" class="form-control" required>
                </div>
                <div class="col-md-2">
                  <label class="form-label text-white fw-bold">OVR</label>
                  <input type="number" name="ovr" class="form-control" min="40" max="99" required>
                </div>
                <div class="col-md-2">
                  <label class="form-label text-white fw-bold">Idade</label>
                  <input type="number" name="age" class="form-control" min="16" max="50" required>
                </div>
                <div class="col-md-2">
                  <label class="form-label text-white fw-bold">Posição</label>
                  <select name="position" class="form-select" required>
                    <option value="PG">PG</option>
                    <option value="SG">SG</option>
                    <option value="SF">SF</option>
                    <option value="PF">PF</option>
                    <option value="C">C</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label text-white fw-bold">Função</label>
                  <select name="role" class="form-select" required>
                    <option value="Titular">Titular</option>
                    <option value="Banco">Banco</option>
                    <option value="Outro">Outro</option>
                    <option value="G-League">G-League</option>
                  </select>
                </div>
                <div class="col-md-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="available_for_trade" name="available_for_trade">
                    <label class="form-check-label text-light-gray" for="available_for_trade">Disponível para troca</label>
                  </div>
                </div>
              </div>
              <div class="text-end mt-3">
                <button type="button" class="btn btn-orange" id="btn-add-player"><i class="bi bi-save2 me-1"></i> Adicionar</button>
              </div>
            </form>
          </div>
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
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/my-roster.js"></script>
</body>
</html>
