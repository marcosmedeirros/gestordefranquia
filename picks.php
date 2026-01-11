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

// Buscar todos os times da liga para o dropdown "Recebida de"
$stmtTeams = $pdo->prepare('SELECT id, city, name FROM teams WHERE league = ? ORDER BY city, name');
$stmtTeams->execute([$user['league']]);
$allTeams = $stmtTeams->fetchAll();

// Buscar picks do time
$stmtPicks = $pdo->prepare('
    SELECT p.*, t.city, t.name as team_name 
    FROM picks p
    LEFT JOIN teams t ON p.original_team_id = t.id
    WHERE p.team_id = ?
    ORDER BY p.season_year, p.round
');
$stmtPicks->execute([$team['id']]);
$picks = $stmtPicks->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
    <title>Minhas Picks - FBA Manager</title>
    
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
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" 
                 alt="<?= htmlspecialchars($team['name']) ?>" class="team-avatar">
            <h5 class="text-white mb-1"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></h5>
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
                <a href="/my-roster.php">
                    <i class="bi bi-person-fill"></i>
                    Meu Elenco
                </a>
            </li>
            <li>
                <a href="/picks.php" class="active">
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
            <h1 class="text-white fw-bold mb-2"><i class="bi bi-calendar-check-fill me-2 text-orange"></i>Minhas Picks</h1>
            <p class="text-light-gray">Visualize as picks de draft do seu time (geradas automaticamente pelo sistema)</p>
        </div>

        <!-- Informação sobre picks automáticas -->
        <div class="alert alert-info mb-4" style="border-radius: 15px; background: rgba(13, 110, 253, 0.1); border: 1px solid rgba(13, 110, 253, 0.3);">
            <i class="bi bi-info-circle me-2"></i>
            As picks são geradas automaticamente quando uma nova temporada é criada. Cada time recebe 2 picks (1ª e 2ª rodada) por temporada.
        </div>

        <!-- Lista de picks -->
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0">
                <thead style="background: linear-gradient(135deg, #f17507, #ff8c1a);">
                    <tr>
                        <th class="text-white fw-bold">Ano</th>
                        <th class="text-white fw-bold">Rodada</th>
                        <th class="text-white fw-bold">Origem</th>
                        <th class="text-white fw-bold">Status</th>
                    </tr>
                </thead>
                <tbody id="picksTableBody">
                    <?php if (count($picks) === 0): ?>
                        <tr>
                            <td colspan="4" class="text-center text-light-gray py-4">
                                <i class="bi bi-calendar-x fs-1 d-block mb-2 text-orange"></i>
                                Nenhuma pick ainda. As picks serão geradas automaticamente quando o admin criar uma temporada.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($picks as $pick): ?>
                            <tr>
                                <td class="fw-bold text-orange"><?= 'Ano ' . str_pad((string)(int)$pick['season_year'], 2, '0', STR_PAD_LEFT) ?></td>
                                <td>
                                    <span class="badge bg-orange"><?= $pick['round'] ?>ª Rodada</span>
                                </td>
                                <td class="text-light-gray">
                                    <?php if ($pick['original_team_id'] == $team['id']): ?>
                                        <span class="badge bg-success">Própria</span>
                                    <?php else: ?>
                                        via <?= htmlspecialchars($pick['city'] . ' ' . $pick['team_name']) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($pick['auto_generated'])): ?>
                                        <span class="badge bg-info">
                                            <i class="bi bi-cpu me-1"></i>Auto
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Manual</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/sidebar.js"></script>
    <script src="/js/pwa.js"></script>
</body>
</html>
