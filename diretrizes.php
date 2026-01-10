<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

// Buscar prazo ativo
$stmtDeadline = $pdo->prepare("
    SELECT * FROM directive_deadlines 
    WHERE league = ? AND is_active = 1 
    ORDER BY deadline_date DESC LIMIT 1
");
$stmtDeadline->execute([$team['league']]);
$deadline = $stmtDeadline->fetch();

// Buscar jogadores do time
$stmtPlayers = $pdo->prepare("
    SELECT id, name, position, ovr, role 
    FROM players 
    WHERE team_id = ? 
    ORDER BY ovr DESC
");
$stmtPlayers->execute([$team['id']]);
$players = $stmtPlayers->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Enviar Diretrizes - FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css" />
</head>
<body>
    <!-- Sidebar -->
    <div class="dashboard-sidebar">
        <div class="text-center mb-4">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" 
                 alt="<?= htmlspecialchars($team['name']) ?>" class="team-avatar">
            <h5 class="text-white mb-1"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></h5>
            <span class="badge bg-gradient-orange"><?= htmlspecialchars($user['league']) ?></span>
        </div>

        <hr style="border-color: var(--fba-border);">

        <ul class="sidebar-menu">
            <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a></li>
            <li><a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a></li>
            <li><a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a></li>
            <li><a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a></li>
            <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a></li>
            <li><a href="/free-agency.php"><i class="bi bi-person-plus-fill"></i> Free Agency</a></li>
            <li><a href="/diretrizes.php" class="active"><i class="bi bi-clipboard-check"></i> Diretrizes</a></li>
            <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
            <li><a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a></li>
            <?php endif; ?>
            <li><a href="/settings.php"><i class="bi bi-gear-fill"></i> Configurações</a></li>
        </ul>

        <hr style="border-color: var(--fba-border);">

        <div class="text-center">
            <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100">
                <i class="bi bi-box-arrow-right me-2"></i>Sair
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="dashboard-content">
        <div class="mb-4">
            <h1 class="text-white fw-bold"><i class="bi bi-clipboard-check me-2 text-orange"></i>Diretrizes de Jogo</h1>
            <p class="text-light-gray">Configure seu quinteto titular, banco e estratégias de jogo</p>
        </div>

        <?php if (!$deadline): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Não há prazo ativo para envio de diretrizes no momento.
        </div>
        <?php else: ?>
        <div class="alert alert-info bg-dark-panel border-orange mb-4">
            <div class="d-flex align-items-center">
                <i class="bi bi-calendar-event text-orange me-3 fs-3"></i>
                <div>
                    <h5 class="text-white mb-1">Prazo de Envio</h5>
                    <p class="text-light-gray mb-0">
                        <?= htmlspecialchars($deadline['description'] ?? 'Envio de diretrizes') ?> - 
                        <strong>Até <?= date('d/m/Y', strtotime($deadline['deadline_date'])) ?></strong>
                    </p>
                </div>
            </div>
        </div>

        <form id="form-diretrizes">
            <input type="hidden" id="deadline-id" value="<?= $deadline['id'] ?>">
            
            <!-- Quinteto Titular -->
            <div class="card bg-dark-panel border-orange mb-4">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-trophy me-2"></i>Quinteto Titular (5 jogadores)</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <div class="col-md-4">
                            <label class="form-label text-white">Titular <?= $i ?></label>
                            <select class="form-select bg-dark text-white border-orange" name="starter_<?= $i ?>_id" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($players as $p): ?>
                                <option value="<?= $p['id'] ?>" data-ovr="<?= $p['ovr'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> - <?= $p['position'] ?> (<?= $p['ovr'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Banco -->
            <div class="card bg-dark-panel border-orange mb-4">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-people me-2"></i>Banco (3 jogadores)</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php for ($i = 1; $i <= 3; $i++): ?>
                        <div class="col-md-4">
                            <label class="form-label text-white">Banco <?= $i ?></label>
                            <select class="form-select bg-dark text-white border-orange" name="bench_<?= $i ?>_id" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($players as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> - <?= $p['position'] ?> (<?= $p['ovr'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Estratégia de Jogo -->
            <div class="card bg-dark-panel border-orange mb-4">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-bar-chart me-2"></i>Estratégia de Jogo</h5>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label text-white">Tempo de Ataque (Pace)</label>
                            <input type="range" class="form-range" name="pace" id="pace" min="0" max="100" value="50">
                            <div class="d-flex justify-content-between text-light-gray small">
                                <span>Lento</span>
                                <span id="pace-value">50</span>
                                <span>Rápido</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white">Rebote Ofensivo</label>
                            <input type="range" class="form-range" name="offensive_rebound" id="offensive_rebound" min="0" max="100" value="50">
                            <div class="d-flex justify-content-between text-light-gray small">
                                <span>Baixo</span>
                                <span id="offensive_rebound-value">50</span>
                                <span>Alto</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white">Agressividade Ofensiva</label>
                            <input type="range" class="form-range" name="offensive_aggression" id="offensive_aggression" min="0" max="100" value="50">
                            <div class="d-flex justify-content-between text-light-gray small">
                                <span>Conservador</span>
                                <span id="offensive_aggression-value">50</span>
                                <span>Agressivo</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white">Rebote Defensivo</label>
                            <input type="range" class="form-range" name="defensive_rebound" id="defensive_rebound" min="0" max="100" value="50">
                            <div class="d-flex justify-content-between text-light-gray small">
                                <span>Baixo</span>
                                <span id="defensive_rebound-value">50</span>
                                <span>Alto</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estilos de Jogo -->
            <div class="card bg-dark-panel border-orange mb-4">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-gear me-2"></i>Configurações de Rotação e Estilos</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-white">Estilo de Rotação</label>
                            <select class="form-select bg-dark text-white border-orange" name="rotation_style">
                                <option value="balanced">Balanceado (7-8 jogadores)</option>
                                <option value="short">Curta (5-6 jogadores)</option>
                                <option value="deep">Profunda (9-10 jogadores)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white">Estilo de Jogo</label>
                            <select class="form-select bg-dark text-white border-orange" name="game_style">
                                <option value="balanced">Balanceado</option>
                                <option value="fast">Rápido (Transição)</option>
                                <option value="slow">Lento (Posicional)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white">Estilo de Ataque</label>
                            <select class="form-select bg-dark text-white border-orange" name="offense_style">
                                <option value="balanced">Balanceado</option>
                                <option value="inside">Interior (Garrafão)</option>
                                <option value="outside">Exterior (3 pontos)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white">Estilo de Defesa</label>
                            <select class="form-select bg-dark text-white border-orange" name="defense_style">
                                <option value="man">Individual</option>
                                <option value="zone">Zona</option>
                                <option value="mixed">Mista</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Observações -->
            <div class="card bg-dark-panel border-orange mb-4">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-chat-text me-2"></i>Observações</h5>
                </div>
                <div class="card-body">
                    <textarea class="form-control bg-dark text-white border-orange" name="notes" rows="4" 
                              placeholder="Observações adicionais sobre sua estratégia..."></textarea>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-3">
                <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='/dashboard.php'">
                    Cancelar
                </button>
                <button type="submit" class="btn btn-orange btn-lg">
                    <i class="bi bi-send me-2"></i>Enviar Diretrizes
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <script>
        window.__DEADLINE_ID__ = <?= $deadline ? (int)$deadline['id'] : 'null' ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/sidebar.js"></script>
    <script src="/js/diretrizes.js"></script>
</body>
</html>
