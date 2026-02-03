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
    <!-- Botão Hamburguer para Mobile -->
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="bi bi-list fs-4"></i>
    </button>
    
    <!-- Overlay para fechar sidebar no mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="d-flex">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <div class="dashboard-content">
            <div class="mb-4">
                <div class="d-flex flex-column flex-md-row flex-wrap justify-content-between align-items-start align-items-md-center gap-2 gap-md-3">
                    <h1 class="text-white fw-bold mb-0" style="font-size: 1.5rem;">
                        <i class="bi bi-hammer text-orange me-2"></i>Leilão
                    </h1>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if (!empty($team_name)): ?>
                            <span class="badge bg-dark border border-warning text-warning" style="font-size: 0.75rem;">
                                <?= htmlspecialchars($team_name) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($is_admin): ?>
                            <span class="badge bg-danger" style="font-size: 0.75rem;">Admin</span>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="text-light-gray mb-0 mt-2" style="font-size: 0.85rem;">
                    Participe de leilões ativos ou gerencie novas entradas como admin.
                </p>
            </div>

            <ul class="nav nav-tabs mb-4 flex-nowrap overflow-auto" role="tablist" style="scrollbar-width: none; -webkit-overflow-scrolling: touch;">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active text-nowrap" id="auction-active-tab" data-bs-toggle="tab" data-bs-target="#auction-active" type="button" role="tab">
                        <i class="bi bi-hammer me-1"></i>Leilões ativos
                    </button>
                </li>
                <?php if ($is_admin): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link text-nowrap" id="auction-admin-tab" data-bs-toggle="tab" data-bs-target="#auction-admin" type="button" role="tab">
                        <i class="bi bi-shield-lock-fill me-1"></i>Admin Leilão
                    </button>
                </li>
                <?php endif; ?>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="auction-active" role="tabpanel">
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-hammer text-orange me-2"></i>Leilões Ativos</h5>
                        </div>
                        <div class="card-body">
                            <div id="leiloesAtivosContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>

                    <?php if ($team_id): ?>
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

                    <div class="card bg-dark-panel border-orange mt-4">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-clock-history text-orange me-2"></i>Histórico de Leilões</h5>
                        </div>
                        <div class="card-body">
                            <div id="leiloesHistoricoContainer">
                                <p class="text-muted">Carregando...</p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($is_admin): ?>
                <div class="tab-pane fade" id="auction-admin" role="tabpanel">
                    <div class="card bg-dark-panel border-orange">
                        <div class="card-header bg-dark border-bottom border-orange">
                            <h5 class="mb-0 text-white"><i class="bi bi-hammer text-orange me-2"></i>Leilão admin</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 align-items-end stack-mobile">
                                <div class="col-md-3">
                                    <label for="selectLeague" class="form-label">Liga</label>
                                    <select id="selectLeague" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($leagues as $league): ?>
                                            <option value="<?= (int)$league['id'] ?>" data-league-name="<?= htmlspecialchars($league['name']) ?>">
                                                <?= htmlspecialchars($league['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-7">
                                    <div class="d-flex flex-wrap gap-3 align-items-center text-white mt-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="auctionMode" id="auctionModeSearch" value="search" checked>
                                            <label class="form-check-label" for="auctionModeSearch">Buscar jogador</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="auctionMode" id="auctionModeCreate" value="create">
                                            <label class="form-check-label" for="auctionModeCreate">Criar jogador</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2 mt-4">
                                    <button id="btnCadastrarLeilao" class="btn btn-orange w-100" disabled>
                                        <i class="bi bi-play-fill me-1"></i>Iniciar 20min
                                    </button>
                                </div>
                            </div>

                            <div class="border-top border-secondary mt-3 pt-3">
                                <div id="auctionSearchArea">
                                    <div class="row g-2 align-items-end stack-mobile">
                                        <div class="col-md-6">
                                            <label for="auctionPlayerSearch" class="form-label">Buscar jogador</label>
                                            <input type="text" id="auctionPlayerSearch" class="form-control" placeholder="Digite o nome">
                                        </div>
                                        <div class="col-md-2">
                                            <button class="btn btn-outline-orange w-100" id="auctionSearchBtn">
                                                <i class="bi bi-search"></i> Buscar
                                            </button>
                                        </div>
                                    </div>
                                    <div class="list-group mt-2" id="auctionPlayerResults" style="display:none;"></div>
                                    <div class="text-light-gray mt-2" id="auctionSelectedLabel" style="display:none;"></div>
                                    <input type="hidden" id="auctionSelectedPlayerId">
                                    <input type="hidden" id="auctionSelectedTeamId">
                                </div>

                                <div id="auctionCreateArea" style="display:none;">
                                    <div class="row g-2 stack-mobile">
                                        <div class="col-12">
                                            <div class="text-light-gray small mb-1">O jogador será criado no leilão e não precisa selecionar time.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="auctionPlayerName" class="form-label">Nome</label>
                                            <input type="text" id="auctionPlayerName" class="form-control" placeholder="Nome">
                                        </div>
                                        <div class="col-md-2">
                                            <label for="auctionPlayerPosition" class="form-label">Posição</label>
                                            <select id="auctionPlayerPosition" class="form-select">
                                                <option value="PG">PG</option>
                                                <option value="SG">SG</option>
                                                <option value="SF">SF</option>
                                                <option value="PF">PF</option>
                                                <option value="C">C</option>
                                            </select>
                                        </div>
                                        <div class="col-md-1">
                                            <label for="auctionPlayerAge" class="form-label">Idade</label>
                                            <input type="number" id="auctionPlayerAge" class="form-control" value="25">
                                        </div>
                                        <div class="col-md-1">
                                            <label for="auctionPlayerOvr" class="form-label">OVR</label>
                                            <input type="number" id="auctionPlayerOvr" class="form-control" value="70">
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button class="btn btn-success w-100" type="button" id="btnCriarJogadorLeilao">
                                                <i class="bi bi-plus-circle me-1"></i>Criar jogador
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <h6 class="text-white mb-2"><i class="bi bi-person-plus text-orange me-2"></i>Jogadores criados (sem time)</h6>
                                        <div id="auctionTempList"><p class="text-light-gray">Nenhum jogador criado.</p></div>
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
                    <h6 class="text-light-gray">Selecione picks para oferecer (opcional):</h6>
                    <div id="minhasPicksParaTroca" class="mb-3">
                        <p class="text-muted">Carregando...</p>
                    </div>
                    <div class="mb-3">
                        <label for="notasProposta" class="form-label">O que vai dar na proposta</label>
                        <textarea id="notasProposta" class="form-control" rows="3" placeholder="Ex: 1 jogador + escolha de draft ou moedas"></textarea>
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
