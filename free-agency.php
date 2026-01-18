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
if ($team_id) {
    $stmt = $pdo->prepare("SELECT name, moedas FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch();
    $team_name = $team['name'] ?? '';
    $team_moedas = (int)($team['moedas'] ?? 0);
}

$leagues = [];
if ($is_admin) {
    $stmt = $pdo->query("SELECT id, name FROM leagues ORDER BY name");
    $leagues = $stmt->fetchAll();
}
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

        <div class="main-content flex-grow-1">
            <div class="container-fluid py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="bi bi-person-plus-fill"></i> Free Agency</h1>
                    <?php if ($team_id): ?>
                    <div class="alert alert-info mb-0 py-2 px-3">
                        <i class="bi bi-coin"></i> Suas Moedas: <strong id="minhasMoedas"><?= $team_moedas ?></strong>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($is_admin): ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-gear-fill"></i> Adicionar Jogador</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="faLeague" class="form-label">Liga</label>
                                <select id="faLeague" class="form-select">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($leagues as $league): ?>
                                        <option value="<?= $league['id'] ?>"><?= htmlspecialchars($league['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="faPlayerName" class="form-label">Nome</label>
                                <input type="text" id="faPlayerName" class="form-control">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label for="faPosition" class="form-label">Posicao</label>
                                <select id="faPosition" class="form-select">
                                    <option value="PG">PG</option>
                                    <option value="SG">SG</option>
                                    <option value="SF">SF</option>
                                    <option value="PF">PF</option>
                                    <option value="C">C</option>
                                </select>
                            </div>
                            <div class="col-md-1 mb-3">
                                <label for="faAge" class="form-label">Idade</label>
                                <input type="number" id="faAge" class="form-control" value="25">
                            </div>
                            <div class="col-md-1 mb-3">
                                <label for="faOverall" class="form-label">OVR</label>
                                <input type="number" id="faOverall" class="form-control" value="70">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label for="faMinBid" class="form-label">Lance Min</label>
                                <input type="number" id="faMinBid" class="form-control" value="0">
                            </div>
                        </div>
                        <button id="btnAddFreeAgent" class="btn btn-success">
                            <i class="bi bi-plus-lg"></i> Adicionar
                        </button>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Gerenciar</h5>
                    </div>
                    <div class="card-body">
                        <div id="adminFreeAgentsContainer">
                            <p class="text-muted">Carregando...</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-people-fill"></i> Jogadores Disponiveis</h5>
                    </div>
                    <div class="card-body">
                        <div id="freeAgentsContainer">
                            <p class="text-muted">Carregando...</p>
                        </div>
                    </div>
                </div>

                <?php if ($team_id): ?>
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-hand-index"></i> Meus Lances</h5>
                    </div>
                    <div class="card-body">
                        <div id="meusLancesContainer">
                            <p class="text-muted">Carregando...</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div class="modal fade" id="modalLance" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-coin"></i> Fazer Lance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="freeAgentIdLance">
                    <div class="alert alert-info">
                        <strong>Jogador:</strong> <span id="freeAgentNomeLance"></span>
                    </div>
                    <p>Lance Minimo: <span id="lanceMinimo">0</span> moedas</p>
                    <p>Maior Lance: <span id="maiorLance">0</span> moedas</p>
                    <div class="mb-3">
                        <label for="valorLance" class="form-label">Seu Lance</label>
                        <input type="number" id="valorLance" class="form-control" min="0">
                    </div>
                    <div class="alert alert-warning">
                        Moedas disponiveis: <strong id="moedasDisponiveis"><?= $team_moedas ?></strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmarLance">Confirmar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalEscolherVencedor" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-trophy"></i> Escolher Vencedor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="freeAgentIdVencedor">
                    <div class="alert alert-info">
                        <strong>Jogador:</strong> <span id="freeAgentNomeVencedor"></span>
                    </div>
                    <div id="listLancesVencedor">
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
        const currentLeagueId = <?= $league_id ? $league_id : 'null' ?>;
    </script>
    <script src="js/sidebar.js"></script>
    <script src="js/free-agency.js"></script>
</body>
</html>
