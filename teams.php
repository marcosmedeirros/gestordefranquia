<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;

// Buscar todos os times da liga
$stmtTeams = $pdo->prepare('
  SELECT t.*, u.name as owner_name, COUNT(DISTINCT p.id) as total_players
  FROM teams t
  LEFT JOIN users u ON t.user_id = u.id
  LEFT JOIN players p ON t.id = p.team_id
  WHERE t.league = ?
  GROUP BY t.id, t.name, t.city, t.mascot, t.photo_url, t.conference, u.name
  ORDER BY t.conference, t.name
');
$stmtTeams->execute([$user['league']]);
$allTeams = $stmtTeams->fetchAll() ?: [];

// Calcular CAP Top8 para cada time
foreach ($allTeams as &$t) {
  $stmt = $pdo->prepare('SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8');
  $stmt->execute([$t['id']]);
  $topPlayers = $stmt->fetchAll();
  $t['cap_top8'] = array_reduce($topPlayers, function($carry, $item) {
    return $carry + $item['ovr'];
  }, 0);
}

// Separar por conferência
$teams_by_conference = [];
foreach ($allTeams as $t) {
  $conf = $t['conference'] ?? 'Sem Conferência';
  if (!isset($teams_by_conference[$conf])) {
    $teams_by_conference[$conf] = [];
  }
  $teams_by_conference[$conf][] = $t;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Todos os Times - FBA Manager Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css" />
    <style>
      .team-card {
        background: var(--fba-panel);
        border: 2px solid var(--fba-border);
        border-radius: 8px;
        padding: 15px;
        display: flex;
        align-items: center;
        gap: 15px;
        transition: all 0.3s ease;
        cursor: pointer;
      }
      .team-card:hover {
        border-color: var(--fba-orange);
        box-shadow: 0 4px 12px rgba(241, 117, 7, 0.3);
        transform: translateY(-2px);
      }
      .team-logo {
        width: 80px;
        height: 80px;
        border-radius: 8px;
        border: 2px solid var(--fba-orange);
        object-fit: cover;
        flex-shrink: 0;
      }
      .team-info {
        flex: 1;
      }
      .team-name {
        font-size: 1.1rem;
        font-weight: bold;
        color: var(--fba-orange);
        margin-bottom: 5px;
      }
      .team-owner {
        font-size: 0.9rem;
        color: var(--fba-text-muted);
        margin-bottom: 8px;
      }
      .team-stats {
        display: flex;
        gap: 15px;
        font-size: 0.85rem;
      }
      .team-stat {
        display: flex;
        flex-direction: column;
      }
      .team-stat-value {
        font-weight: bold;
        color: var(--fba-text);
        font-size: 1rem;
      }
      .team-stat-label {
        color: var(--fba-text-muted);
        font-size: 0.75rem;
        text-transform: uppercase;
      }
      .conference-section {
        margin-bottom: 40px;
      }
      .conference-title {
        font-size: 1.8rem;
        font-weight: bold;
        color: var(--fba-orange);
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--fba-orange);
      }
      .conference-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 30px;
      }
      @media (max-width: 768px) {
        .conference-grid {
          grid-template-columns: 1fr;
        }
      }
    </style>
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
                <a href="/teams.php" class="active">
                    <i class="bi bi-people-fill"></i>
                    Tabela
                </a>
            </li>
            <li>
                <a href="/my-roster.php">
                    <i class="bi bi-person-fill"></i>
                    Meu Elenco
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
            <h1 class="display-5 fw-bold mb-2">
                <i class="bi bi-people-fill text-orange me-2"></i>Todos os Times
            </h1>
            <p class="text-light-gray">Liga <span class="badge bg-gradient-orange"><?= htmlspecialchars($user['league']) ?></span></p>
        </div>

        <!-- CONFERÊNCIA LESTE -->
        <?php if (isset($teams_by_conference['LESTE'])): ?>
        <div class="conference-section">
            <div class="conference-title">
                <i class="bi bi-geo-alt me-2"></i>Conferência LESTE
            </div>
            <div class="conference-grid">
                <?php foreach ($teams_by_conference['LESTE'] as $t): ?>
                <div class="team-card">
                    <img src="<?= htmlspecialchars($t['photo_url'] ?? '/img/default-team.png') ?>" 
                         alt="<?= htmlspecialchars($t['name']) ?>" 
                         class="team-logo">
                    <div class="team-info">
                        <div class="team-name"><?= htmlspecialchars($t['city'] . ' ' . $t['name']) ?></div>
                        <div class="team-owner">
                            <i class="bi bi-person me-1"></i><?= htmlspecialchars($t['owner_name'] ?? 'N/A') ?>
                        </div>
                        <div class="team-stats">
                            <div class="team-stat">
                                <span class="team-stat-value"><?= $t['total_players'] ?? 0 ?></span>
                                <span class="team-stat-label">Jogadores</span>
                            </div>
                            <div class="team-stat">
                                <span class="team-stat-value"><?= $t['cap_top8'] ?? 0 ?></span>
                                <span class="team-stat-label">CAP Top8</span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- CONFERÊNCIA OESTE -->
        <?php if (isset($teams_by_conference['OESTE'])): ?>
        <div class="conference-section">
            <div class="conference-title">
                <i class="bi bi-geo-alt me-2"></i>Conferência OESTE
            </div>
            <div class="conference-grid">
                <?php foreach ($teams_by_conference['OESTE'] as $t): ?>
                <div class="team-card">
                    <img src="<?= htmlspecialchars($t['photo_url'] ?? '/img/default-team.png') ?>" 
                         alt="<?= htmlspecialchars($t['name']) ?>" 
                         class="team-logo">
                    <div class="team-info">
                        <div class="team-name"><?= htmlspecialchars($t['city'] . ' ' . $t['name']) ?></div>
                        <div class="team-owner">
                            <i class="bi bi-person me-1"></i><?= htmlspecialchars($t['owner_name'] ?? 'N/A') ?>
                        </div>
                        <div class="team-stats">
                            <div class="team-stat">
                                <span class="team-stat-value"><?= $t['total_players'] ?? 0 ?></span>
                                <span class="team-stat-label">Jogadores</span>
                            </div>
                            <div class="team-stat">
                                <span class="team-stat-value"><?= $t['cap_top8'] ?? 0 ?></span>
                                <span class="team-stat-label">CAP Top8</span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/teams.js"></script>
</body>
</html>
