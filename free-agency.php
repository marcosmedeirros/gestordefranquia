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
$is_admin = $_SESSION['is_admin'] ?? (($_SESSION['user_type'] ?? '') === 'admin');
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
$team_league = $team_league ?? ($_SESSION['user_league'] ?? null);

if (!$team_id && $user_id) {
    $stmt = $pdo->prepare("SELECT id, name, moedas, league FROM teams WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $team = $stmt->fetch();
    if ($team) {
        $team_id = (int)$team['id'];
        $team_name = $team['name'] ?? $team_name;
        $team_moedas = (int)($team['moedas'] ?? $team_moedas);
        $team_league = $team['league'] ?? $team_league;
    }
}
$team_league = $team_league ?? ($_SESSION['user_league'] ?? null);

$leagues = [];
$leagues_admin = [];
if ($is_admin) {
    $stmt = $pdo->query("SELECT id, name FROM leagues ORDER BY name");
    $leagues_admin = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $leagues = array_map(static function ($league) {
        return $league['name'];
    }, $leagues_admin);
    if (!$leagues) {
        $leagues = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
        $leagues_admin = [];
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
                    Envie lances com moedas para contratar jogadores dispensados. As moedas so sao descontadas apos aprovacao do admin.
                </p>
            </div>

            <ul class="nav nav-tabs mb-4" id="freeAgencyTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="fa-players-tab" data-bs-toggle="tab" data-bs-target="#fa-players" type="button" role="tab">
                        <i class="bi bi-people-fill me-1"></i>Free Agency
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="fa-auction-tab" data-bs-toggle="tab" data-bs-target="#fa-auction" type="button" role="tab">
                        <i class="bi bi-hammer me-1"></i>Leilao
                    </button>
                </li>
                <?php if ($is_admin): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="fa-admin-tab" data-bs-toggle="tab" data-bs-target="#fa-admin" type="button" role="tab">
                        <i class="bi bi-shield-lock-fill me-1"></i>Admin
                    </button>
                </li>
                <?php endif; ?>
            </ul>

            <div class="tab-content" id="freeAgencyTabsContent">
                <div class="tab-pane fade show active" id="fa-players" role="tabpanel">
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <h5 class="mb-0 text-white"><i class="bi bi-people-fill text-orange me-2"></i>Jogadores Disponiveis</h5>
                                <?php if ($is_admin): ?>
                                    <button class="btn btn-sm btn-outline-orange" type="button" id="btnOpenAdminTab">
                                        <i class="bi bi-plus-circle me-1"></i>Adicionar Jogador
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-2 align-items-end mb-3">
                                <div class="col-md-6">
                                    <label for="faSearchInput" class="form-label">Buscar jogador</label>
                                    <input type="text" id="faSearchInput" class="form-control" placeholder="Digite o nome">
                                </div>
                                <div class="col-md-3">
                                    <label for="faPositionFilter" class="form-label">Posicao</label>
                                    <select id="faPositionFilter" class="form-select">
                                        <option value="">Todas</option>
                                        <option value="PG">PG</option>
                                        <option value="SG">SG</option>
                                        <option value="SF">SF</option>
                                        <option value="PF">PF</option>
                                        <option value="C">C</option>
                                    </select>
                                </div>
                            </div>
                            <div id="freeAgentsContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="fa-auction" role="tabpanel">
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-hammer text-orange me-2"></i>Leiloes Ativos</h5>
                        </div>
                        <div class="card-body">
                            <div id="leiloesAtivosContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>

                    <?php if ($team_id): ?>
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-send text-orange me-2"></i>Minhas Propostas</h5>
                        </div>
                        <div class="card-body">
                            <div id="minhasPropostasContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-dark-panel border-orange">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-inbox text-orange me-2"></i>Propostas Recebidas</h5>
                        </div>
                        <div class="card-body">
                            <div id="propostasRecebidasContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($is_admin): ?>
                    <div class="card bg-dark-panel border-orange mt-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-hammer text-orange me-2"></i>Admin Leilao</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-3">
                                    <label for="selectLeague" class="form-label">Liga</label>
                                    <select id="selectLeague" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($leagues_admin as $league): ?>
                                            <option value="<?= (int)$league['id'] ?>" data-league-name="<?= htmlspecialchars($league['name']) ?>">
                                                <?= htmlspecialchars($league['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="selectTeam" class="form-label">Time</label>
                                    <select id="selectTeam" class="form-select" disabled>
                                        <option value="">Selecione primeiro uma liga...</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="selectPlayer" class="form-label">Jogador</label>
                                    <select id="selectPlayer" class="form-select" disabled>
                                        <option value="">Selecione primeiro um time...</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button id="btnCadastrarLeilao" class="btn btn-orange w-100" disabled>
                                        <i class="bi bi-play-fill me-1"></i>Iniciar 20min
                                    </button>
                                </div>
                            </div>

                            <div class="border-top border-secondary mt-3 pt-3">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="toggleNewAuctionPlayer">
                                    <label class="form-check-label" for="toggleNewAuctionPlayer">
                                        Criar novo jogador para leilao
                                    </label>
                                </div>
                                <div class="row g-2" id="newAuctionPlayerFields" style="display:none;">
                                    <div class="col-md-4">
                                        <input type="text" id="auctionPlayerName" class="form-control" placeholder="Nome">
                                    </div>
                                    <div class="col-md-2">
                                        <select id="auctionPlayerPosition" class="form-select">
                                            <option value="PG">PG</option>
                                            <option value="SG">SG</option>
                                            <option value="SF">SF</option>
                                            <option value="PF">PF</option>
                                            <option value="C">C</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" id="auctionPlayerAge" class="form-control" placeholder="Idade" value="25">
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" id="auctionPlayerOvr" class="form-control" placeholder="OVR" value="70">
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3">
                                <div id="adminLeiloesContainer">
                                    <p class="text-muted">Carregando...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($is_admin): ?>
                <div class="tab-pane fade" id="fa-admin" role="tabpanel">
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <h5 class="mb-0 text-white"><i class="bi bi-inbox-fill text-orange me-2"></i>Propostas Pendentes</h5>
                                <div class="d-flex align-items-center gap-2">
                                    <label for="adminLeagueSelect" class="text-light-gray">Liga</label>
                                    <select id="adminLeagueSelect" class="form-select form-select-sm" style="min-width: 140px;">
                                        <?php foreach ($leagues_admin as $league): ?>
                                            <option value="<?= htmlspecialchars($league['name']) ?>" data-league-id="<?= (int)$league['id'] ?>" <?= $league['name'] === $default_admin_league ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($league['name']) ?>
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
                    <h5 class="modal-title"><i class="bi bi-coin"></i> Fazer Lance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="freeAgentIdOffer">
                    <div class="alert alert-info">
                        <strong>Jogador:</strong> <span id="freeAgentNomeOffer"></span>
                    </div>
                    <div class="mb-3">
                        <label for="offerAmount" class="form-label">Moedas do lance</label>
                        <input type="number" id="offerAmount" class="form-control" min="1" value="1">
                    </div>
                    <div class="alert alert-warning">
                        Moedas disponiveis: <strong id="moedasDisponiveis"><?= $team_moedas ?></strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmOffer">Confirmar Lance</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalProposta" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-send"></i> Enviar Proposta de Leilao</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="leilaoIdProposta">
                    <div class="alert alert-info">
                        <strong>Jogador em Leilao:</strong> <span id="jogadorLeilaoNome"></span>
                    </div>
                    <h6 class="text-light-gray">Selecione jogadores para oferecer (opcional):</h6>
                    <div id="meusJogadoresParaTroca" class="mb-3">
                        <p class="text-muted">Carregando...</p>
                    </div>
                    <div class="mb-3">
                        <label for="notasProposta" class="form-label">Mensagem da proposta</label>
                        <textarea id="notasProposta" class="form-control" rows="3" placeholder="Digite sua proposta"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-orange" id="btnEnviarProposta">
                        <i class="bi bi-send"></i> Enviar Proposta
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalVerPropostas" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-inbox"></i> Propostas Recebidas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="leilaoIdVerPropostas">
                    <div id="listaPropostasRecebidas">
                        <p class="text-muted">Carregando...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
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
        const currentLeagueId = <?= $league_id ? $league_id : 'null' ?>;
    </script>
    <script src="js/sidebar.js"></script>
    <script src="js/free-agency.js"></script>
    <script src="js/leilao.js"></script>
</body>
</html>
