<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$user = getUserSession();
if (($user['user_type'] ?? 'jogador') !== 'admin') {
    header('Location: /dashboard.php');
    exit;
}
$pdo = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Punições - Admin</title>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#0a0a0c">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
  <style>
    .punicao-card {
      background: var(--fba-panel);
      border: 1px solid var(--fba-border);
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 12px;
    }
  </style>
</head>
<body>
  <button class="sidebar-toggle" id="sidebarToggle">
    <i class="bi bi-list fs-4"></i>
  </button>
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <div class="dashboard-sidebar" id="sidebar">
    <div class="text-center mb-4">
      <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" alt="Admin" class="team-avatar">
      <h5 class="text-white mb-1"><?= $team ? htmlspecialchars($team['city'] . ' ' . $team['name']) : 'Admin' ?></h5>
      <span class="badge bg-gradient-orange"><?= $team ? htmlspecialchars($team['league']) : 'Painel' ?></span>
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
      <li><a href="/drafts.php"><i class="bi bi-trophy"></i>Draft</a></li>
      <li><a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i>Rankings</a></li>
      <li><a href="/history.php"><i class="bi bi-clock-history"></i>Histórico</a></li>
      <li><a href="/admin.php"><i class="bi bi-shield-lock-fill"></i>Admin</a></li>
      <li><a href="/punicoes.php" class="active"><i class="bi bi-exclamation-triangle-fill"></i>Punições</a></li>
      <li><a href="/temporadas.php"><i class="bi bi-calendar3"></i>Temporadas</a></li>
      <li><a href="/settings.php"><i class="bi bi-gear-fill"></i>Configurações</a></li>
    </ul>
    <hr style="border-color: var(--fba-border);">
    <div class="text-center">
      <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-2"></i>Sair</a>
    </div>
  </div>

  <div class="dashboard-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="text-white fw-bold mb-0"><i class="bi bi-exclamation-triangle-fill me-2 text-orange"></i>Punições</h1>
    </div>

    <div class="row g-4">
      <div class="col-lg-4">
        <div class="bg-dark-panel border-orange rounded p-4 h-100">
          <h5 class="text-white mb-3">Nova punição</h5>
          <div class="mb-3">
            <label class="form-label text-light-gray">Motivo</label>
            <select id="punicaoMotive" class="form-select bg-dark text-white border-orange"></select>
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Liga</label>
            <select id="punicaoLeague" class="form-select bg-dark text-white border-orange"></select>
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Time</label>
            <select id="punicaoTeam" class="form-select bg-dark text-white border-orange"></select>
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Punição</label>
            <select id="punicaoType" class="form-select bg-dark text-white border-orange"></select>
          </div>
          <div class="mb-3" id="punicaoPickRow" style="display:none;">
            <label class="form-label text-light-gray">Pick específica</label>
            <select id="punicaoPick" class="form-select bg-dark text-white border-orange"></select>
          </div>
          <div class="mb-3" id="punicaoScopeRow" style="display:none;">
            <label class="form-label text-light-gray">Temporada</label>
            <select id="punicaoScope" class="form-select bg-dark text-white border-orange">
              <option value="current">Temporada atual</option>
              <option value="next">Próxima temporada</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Observações</label>
            <textarea id="punicaoNotes" class="form-control bg-dark text-white border-orange" rows="3" placeholder="Detalhes ou contexto..."></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Data da punição (manual)</label>
            <input type="datetime-local" id="punicaoDate" class="form-control bg-dark text-white border-orange" />
          </div>
          <button id="punicaoSubmit" class="btn btn-orange w-100"><i class="bi bi-check2-circle me-2"></i>Registrar punição</button>
        </div>
        <div class="bg-dark-panel border-orange rounded p-4 mt-3">
          <h5 class="text-white mb-3">Cadastrar motivo/punição</h5>
          <div class="mb-3">
            <label class="form-label text-light-gray">Novo motivo</label>
            <div class="input-group">
              <input type="text" id="newMotiveLabel" class="form-control bg-dark text-white border-orange" placeholder="Ex: Diretrizes erradas">
              <button class="btn btn-outline-light" id="newMotiveBtn"><i class="bi bi-plus-circle"></i></button>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Nova punição</label>
            <input type="text" id="newPunishmentLabel" class="form-control bg-dark text-white border-orange" placeholder="Ex: Perda de pick específica">
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Consequência</label>
            <select id="newPunishmentEffect" class="form-select bg-dark text-white border-orange">
              <option value="AVISO_FORMAL">Aviso formal</option>
              <option value="PERDA_PICK_1R">Perda da Pick 1º rodada</option>
              <option value="PERDA_PICK_ESPECIFICA">Perda de pick específica</option>
              <option value="BAN_TRADES">Trades bloqueadas por uma temporada</option>
              <option value="BAN_TRADES_PICKS">Trades sem picks</option>
              <option value="BAN_FREE_AGENCY">Sem poder usar FA na temporada</option>
              <option value="ROTACAO_AUTOMATICA">Rotação automatica</option>
            </select>
          </div>
          <div class="d-flex gap-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="newPunishmentPick">
              <label class="form-check-label text-light-gray" for="newPunishmentPick">Requer pick</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="newPunishmentScope">
              <label class="form-check-label text-light-gray" for="newPunishmentScope">Requer temporada</label>
            </div>
          </div>
          <button class="btn btn-outline-light mt-3 w-100" id="newPunishmentBtn"><i class="bi bi-plus-circle me-2"></i>Salvar punição</button>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="bg-dark-panel border-orange rounded p-4">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="text-white mb-0">Histórico do time</h5>
            <div class="d-flex flex-wrap gap-2">
              <select id="punicaoHistoryLeague" class="form-select bg-dark text-white border-orange">
                <option value="">Todas as ligas</option>
              </select>
              <select id="punicaoHistoryTeam" class="form-select bg-dark text-white border-orange">
                <option value="">Todos os times</option>
              </select>
            </div>
          </div>
          <div id="punicoesList" class="text-light-gray">Selecione um time para ver as punições.</div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script src="/js/punicoes.js"></script>
</body>
</html>
