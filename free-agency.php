<?php
session_start();
require_once 'backend/config.php';
require_once 'backend/db.php';
$pdo = db();
require_once 'backend/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? false;
$team_id = $_SESSION['team_id'] ?? null;
$league_id = $_SESSION['current_league_id'] ?? null;

$team_name = '';
$team_moedas = 0;
$team_league = null;
if ($team_id) {
    $stmt = $pdo->prepare("SELECT name, moedas, league FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch();
    $team_name = $team['name'] ?? '';
    $team_moedas = (int)($team['moedas'] ?? 0);
    $team_league = $team['league'] ?? null;
}

$leagues = [];
if ($is_admin) {
    $stmt = $pdo->query("SELECT name FROM leagues ORDER BY name");
    $leagues = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$leagues) {
        $leagues = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
    }
}
$default_admin_league = $team_league ?? ($leagues[0] ?? 'ELITE');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Free Agency - FBA Brasil</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300..700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
</head>
<body>
    <div class="d-flex">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div class="dashboard-content">
            <div class="mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <h1 class="text-white fw-bold mb-0">
                        <i class="bi bi-coin text-orange me-2"></i>Free Agency
                    </h1>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($team_league): ?>
                            <span class="badge bg-dark border border-warning text-warning">
                                <i class="bi bi-trophy me-1"></i><?= htmlspecialchars($team_league) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($team_id): ?>
                            <span class="badge bg-warning text-dark">
                                <i class="bi bi-coin me-1"></i><?= $team_moedas ?> moedas
                            </span>
                        <?php endif; ?>
                        <?php if ($is_admin): ?>
                            <span class="badge bg-danger">Admin</span>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="text-light-gray mb-0">
                    Envie propostas com moedas para contratar jogadores dispensados. As moedas so sao descontadas apos aprovacao do admin.
                </p>
            </div>

            <ul class="nav nav-tabs mb-4" id="freeAgencyTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="fa-players-tab" data-bs-toggle="tab" data-bs-target="#fa-players" type="button" role="tab">
                        <i class="bi bi-people-fill me-1"></i>Jogadores
                    </button>
                </li>
                <?php if ($team_id): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="fa-my-offers-tab" data-bs-toggle="tab" data-bs-target="#fa-my-offers" type="button" role="tab">
                        <i class="bi bi-hand-index me-1"></i>Minhas Propostas
                    </button>
                </li>
                <?php endif; ?>
                <?php if ($is_admin): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="fa-admin-offers-tab" data-bs-toggle="tab" data-bs-target="#fa-admin-offers" type="button" role="tab">
                        <i class="bi bi-inbox-fill me-1"></i>Admin Propostas
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="fa-admin-players-tab" data-bs-toggle="tab" data-bs-target="#fa-admin-players" type="button" role="tab">
                        <i class="bi bi-gear-fill me-1"></i>Admin Jogadores
                    </button>
                </li>
                <?php endif; ?>
            </ul>

            <div class="tab-content" id="freeAgencyTabsContent">
                <div class="tab-pane fade show active" id="fa-players" role="tabpanel">
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-people-fill text-orange me-2"></i>Jogadores Disponiveis</h5>
                        </div>
                        <div class="card-body">
                            <div id="freeAgentsContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($team_id): ?>
                <div class="tab-pane fade" id="fa-my-offers" role="tabpanel">
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-hand-index text-orange me-2"></i>Minhas Propostas</h5>
                        </div>
                        <div class="card-body">
                            <div id="myOffersContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($is_admin): ?>
                <div class="tab-pane fade" id="fa-admin-offers" role="tabpanel">
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <h5 class="mb-0 text-white"><i class="bi bi-inbox-fill text-orange me-2"></i>Propostas Pendentes</h5>
                                <div class="d-flex align-items-center gap-2">
                                    <label for="adminLeagueSelect" class="text-light-gray">Liga</label>
                                    <select id="adminLeagueSelect" class="form-select form-select-sm" style="min-width: 140px;">
                                        <?php foreach ($leagues as $league): ?>
                                            <option value="<?= htmlspecialchars($league) ?>" <?= $league === $default_admin_league ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($league) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="adminOffersContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="fa-admin-players" role="tabpanel">
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-gear-fill text-orange me-2"></i>Adicionar Free Agent</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label for="faLeague" class="form-label">Liga</label>
                                    <select id="faLeague" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($leagues as $league): ?>
                                            <option value="<?= htmlspecialchars($league) ?>"><?= htmlspecialchars($league) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="faPlayerName" class="form-label">Nome</label>
                                    <input type="text" id="faPlayerName" class="form-control" placeholder="Nome do jogador">
                                </div>
                                <div class="col-md-2">
                                    <label for="faPosition" class="form-label">Posicao</label>
                                    <select id="faPosition" class="form-select">
                                        <option value="PG">PG</option>
                                        <option value="SG">SG</option>
                                        <option value="SF">SF</option>
                                        <option value="PF">PF</option>
                                        <option value="C">C</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="faSecondaryPosition" class="form-label">Posicao Secundaria</label>
                                    <input type="text" id="faSecondaryPosition" class="form-control" placeholder="Opcional">
                                </div>
                                <div class="col-md-2">
                                    <label for="faAge" class="form-label">Idade</label>
                                    <input type="number" id="faAge" class="form-control" value="25">
                                </div>
                                <div class="col-md-2">
                                    <label for="faOvr" class="form-label">OVR</label>
                                    <input type="number" id="faOvr" class="form-control" value="70">
                                </div>
                                <div class="col-12">
                                    <button id="btnAddFreeAgent" class="btn btn-orange">
                                        <i class="bi bi-plus-circle me-1"></i>Adicionar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-dark-panel border-orange">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-list-ul text-orange me-2"></i>Gerenciar Jogadores</h5>
                        </div>
                        <div class="card-body">
                            <div id="adminFreeAgentsContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalOffer" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-coin"></i> Enviar Proposta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="freeAgentIdOffer">
                    <div class="alert alert-info">
                        <strong>Jogador:</strong> <span id="freeAgentNomeOffer"></span>
                    </div>
                    <div class="mb-3">
                        <label for="offerAmount" class="form-label">Moedas da proposta</label>
                        <input type="number" id="offerAmount" class="form-control" min="1" value="1">
                    </div>
                    <div class="alert alert-warning">
                        Moedas disponiveis: <strong id="moedasDisponiveis"><?= $team_moedas ?></strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmOffer">Enviar Proposta</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;
        const userTeamId = <?= $team_id ? $team_id : 'null' ?>;
        const userTeamName = '<?= addslashes($team_name) ?>';
        const userMoedas = <?= $team_moedas ?>;
        const userLeague = <?= $team_league ? "'" . addslashes($team_league) . "'" : 'null' ?>;
        const defaultAdminLeague = '<?= addslashes($default_admin_league) ?>';
    </script>
    <script src="js/sidebar.js"></script>
    <script src="js/free-agency.js"></script>
</body>
</html>
