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
if ($team_id) {
    $stmt = $pdo->prepare("SELECT name FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch();
    $team_name = $team['name'] ?? '';
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
    <title>Leilao - FBA Brasil</title>
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
                <h1><i class="bi bi-hammer"></i> Leilao de Jogadores</h1>

                <?php if ($is_admin): ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-gear-fill"></i> Administracao do Leilao</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="selectLeague" class="form-label">Liga</label>
                                <select id="selectLeague" class="form-select">
                                    <option value="">Selecione uma liga...</option>
                                    <?php foreach ($leagues as $league): ?>
                                        <option value="<?= $league['id'] ?>"><?= htmlspecialchars($league['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="selectTeam" class="form-label">Time</label>
                                <select id="selectTeam" class="form-select" disabled>
                                    <option value="">Selecione primeiro uma liga...</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="selectPlayer" class="form-label">Jogador</label>
                                <select id="selectPlayer" class="form-select" disabled>
                                    <option value="">Selecione primeiro um time...</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="dataInicio" class="form-label">Data/Hora Inicio</label>
                                <input type="datetime-local" id="dataInicio" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="dataFim" class="form-label">Data/Hora Fim</label>
                                <input type="datetime-local" id="dataFim" class="form-control">
                            </div>
                        </div>
                        <button id="btnCadastrarLeilao" class="btn btn-success" disabled>
                            <i class="bi bi-plus-lg"></i> Cadastrar Jogador no Leilao
                        </button>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Gerenciar Leiloes</h5>
                    </div>
                    <div class="card-body">
                        <div id="adminLeiloesContainer">
                            <p class="text-muted">Carregando...</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Leiloes em Andamento</h5>
                    </div>
                    <div class="card-body">
                        <div id="leiloesAtivosContainer">
                            <p class="text-muted">Carregando...</p>
                        </div>
                    </div>
                </div>

                <?php if ($team_id): ?>
                <div class="card mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="bi bi-send"></i> Minhas Propostas Enviadas</h5>
                    </div>
                    <div class="card-body">
                        <div id="minhasPropostasContainer">
                            <p class="text-muted">Carregando...</p>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-inbox-fill"></i> Propostas Recebidas</h5>
                    </div>
                    <div class="card-body">
                        <div id="propostasRecebidasContainer">
                            <p class="text-muted">Carregando...</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div class="modal fade" id="modalProposta" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-send"></i> Enviar Proposta de Troca</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="leilaoIdProposta">
                    <div class="alert alert-info">
                        <strong>Jogador em Leilao:</strong> <span id="jogadorLeilaoNome"></span>
                    </div>
                    <h6>Selecione os jogadores que voce oferece em troca:</h6>
                    <div id="meusJogadoresParaTroca" class="mb-3">
                        <p class="text-muted">Carregando...</p>
                    </div>
                    <div class="mb-3">
                        <label for="notasProposta" class="form-label">Observacoes (opcional)</label>
                        <textarea id="notasProposta" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btnEnviarProposta">
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
        const currentLeagueId = <?= $league_id ? $league_id : 'null' ?>;
    </script>
    <script src="js/sidebar.js"></script>
    <script src="js/leilao.js"></script>
</body>
</html>
