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

// Se não tem time, redireciona para onboarding
if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

// Buscar estatísticas do time
$stmtPlayers = $pdo->prepare('SELECT COUNT(*) as total, SUM(ovr) as total_ovr FROM players WHERE team_id = ?');
$stmtPlayers->execute([$team['id']]);
$stats = $stmtPlayers->fetch();

$totalPlayers = $stats['total'] ?? 0;
$avgOvr = $totalPlayers > 0 ? round($stats['total_ovr'] / $totalPlayers, 1) : 0;

// Buscar jogadores titulares
$stmtTitulares = $pdo->prepare("SELECT * FROM players WHERE team_id = ? AND role = 'Titular' ORDER BY ovr DESC");
$stmtTitulares->execute([$team['id']]);
$titulares = $stmtTitulares->fetchAll();

// Calcular CAP Top8 (soma dos 8 maiores OVRs)
$teamCap = 0;
$stmtCap = $pdo->prepare('
    SELECT SUM(ovr) as cap FROM (
        SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8
    ) as top_eight
');
$stmtCap->execute([$team['id']]);
$capData = $stmtCap->fetch();
$teamCap = $capData['cap'] ?? 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard - FBA Manager</title>
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
            <li>
                <a href="/dashboard.php" class="active">
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
                <a href="/my-roster.php">
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
            <h1 class="text-white fw-bold mb-2">Dashboard</h1>
            <p class="text-light-gray">Bem-vindo ao painel de controle do <?= htmlspecialchars($team['name']) ?></p>
        </div>

        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">Jogadores</div>
                            <div class="stat-value"><?= $totalPlayers ?></div>
                        </div>
                        <i class="bi bi-people-fill display-4 text-orange"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">OVR Médio</div>
                            <div class="stat-value"><?= $avgOvr ?></div>
                        </div>
                        <i class="bi bi-star-fill display-4 text-orange"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">CAP Total</div>
                            <div class="stat-value"><?= $teamCap ?></div>
                        </div>
                        <i class="bi bi-cash-stack display-4 text-orange"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">Liga</div>
                            <div class="stat-value" style="font-size: 1.8rem;"><?= htmlspecialchars($user['league']) ?></div>
                        </div>
                        <i class="bi bi-trophy-fill display-4 text-orange"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Titular -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-header bg-transparent border-orange d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 text-white">
                            <i class="bi bi-trophy me-2 text-orange"></i>Quinteto Titular
                        </h4>
                        <button class="btn btn-outline-orange btn-sm" onclick="window.location.href='/teams.php'">
                            <i class="bi bi-plus-circle me-1"></i>Gerenciar Elenco
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (count($titulares) > 0): ?>
                            <div class="row g-3 justify-content-center">
                                <?php foreach ($titulares as $player): ?>
                                    <div class="col-md-2">
                                        <div class="card bg-dark text-white h-100">
                                            <div class="card-body text-center p-3">
                                                <span class="badge bg-orange mb-2"><?= htmlspecialchars($player['position']) ?></span>
                                                <h6 class="mb-1"><?= htmlspecialchars($player['name']) ?></h6>
                                                <p class="mb-0 text-light-gray small">OVR: <?= $player['ovr'] ?></p>
                                                <p class="mb-0 text-light-gray small"><?= $player['age'] ?> anos</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-light-gray py-5">
                                <i class="bi bi-exclamation-circle display-1"></i>
                                <p class="mt-3">Você ainda não tem jogadores titulares.</p>
                                <a href="/teams.php" class="btn btn-orange">
                                    <i class="bi bi-plus-circle me-2"></i>Adicionar Jogadores
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
