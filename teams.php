<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário
$stmtTeam = $pdo->prepare('
    SELECT t.*
    FROM teams t
    LEFT JOIN (
        SELECT team_id, COUNT(*) AS player_count
        FROM players
        GROUP BY team_id
    ) pc ON pc.team_id = t.id
    WHERE t.user_id = ?
    ORDER BY COALESCE(pc.player_count, 0) DESC, t.id DESC
    LIMIT 1
');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;

// Buscar todos os times da liga (sem duplicatas)
$stmtTeams = $pdo->prepare('
    SELECT t.id, t.user_id, t.league, t.conference, t.name, t.city, t.mascot, t.photo_url, u.name AS owner_name
    FROM teams t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.league = ?
    ORDER BY t.conference, t.name, t.id
');
$stmtTeams->execute([$user['league']]);
$allTeams = $stmtTeams->fetchAll() ?: [];

// Calcular contagem de jogadores para cada time
foreach ($allTeams as &$t) {
  $stmtPlayers = $pdo->prepare('SELECT COUNT(*) as count FROM players WHERE team_id = ?');
  $stmtPlayers->execute([$t['id']]);
  $playerData = $stmtPlayers->fetch();
  $t['total_players'] = $playerData['count'] ?? 0;
}

// Calcular CAP Top8 para cada time (já tem total_players acima)
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
        width: 60px;
        height: 60px;
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
                <a href="/trades.php">
                    <i class="bi bi-arrow-left-right"></i>
                    Trades
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
                    <button class="btn btn-sm btn-orange" onclick="showTeamPlayers(<?= $t['id'] ?>, '<?= htmlspecialchars($t['city'] . ' ' . $t['name'], ENT_QUOTES) ?>')">
                        <i class="bi bi-eye me-1"></i>Ver
                    </button>
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
                    <button class="btn btn-sm btn-orange" onclick="showTeamPlayers(<?= $t['id'] ?>, '<?= htmlspecialchars($t['city'] . ' ' . $t['name'], ENT_QUOTES) ?>')">
                        <i class="bi bi-eye me-1"></i>Ver
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal para mostrar jogadores -->
    <div class="modal fade" id="teamPlayersModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark-panel border-orange">
                <div class="modal-header border-orange">
                    <h5 class="modal-title text-white" id="teamModalTitle"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="playersLoading" class="text-center py-4">
                        <div class="spinner-border text-orange" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                    <div id="playersContent" style="display: none;">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover mb-0">
                                <thead style="background: linear-gradient(135deg, #f17507, #ff8c1a);">
                                    <tr>
                                        <th class="text-white fw-bold">Jogador</th>
                                        <th class="text-white fw-bold">OVR</th>
                                        <th class="text-white fw-bold">Posição</th>
                                        <th class="text-white fw-bold">Função</th>
                                        <th class="text-white fw-bold">Idade</th>
                                        <th class="text-white fw-bold">Troca?</th>
                                    </tr>
                                </thead>
                                <tbody id="playersTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/sidebar.js"></script>
    <script>
        async function showTeamPlayers(teamId, teamName) {
            document.getElementById('teamModalTitle').textContent = teamName;
            document.getElementById('playersLoading').style.display = 'block';
            document.getElementById('playersContent').style.display = 'none';
            
            const modal = new bootstrap.Modal(document.getElementById('teamPlayersModal'));
            modal.show();

            try {
                const response = await fetch(`/api/team-players.php?team_id=${teamId}`);
                const data = await response.json();

                if (data.success && data.players) {
                    const tbody = document.getElementById('playersTableBody');
                    tbody.innerHTML = '';

                    if (data.players.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-light-gray">Nenhum jogador cadastrado</td></tr>';
                    } else {
                        data.players.forEach(player => {
                            const tradeClass = player.available_for_trade ? 'trade' : 'notrade';
                            const tradeText = player.available_for_trade ? 'Disponível' : 'Fechado';
                            const row = document.createElement('tr');
                            row.innerHTML = `
                                <td class="fw-bold">${player.name}</td>
                                <td><span class="badge" style="background-color: ${getOvrColor(player.ovr)}; color: #000;">${player.ovr}</span></td>
                                <td><span class="badge bg-orange">${player.position}</span></td>
                                <td><span class="badge bg-secondary">${player.role}</span></td>
                                <td class="text-light-gray">${player.age} anos</td>
                                <td><span class="badge badge-${tradeClass}">${tradeText}</span></td>
                            `;
                            tbody.appendChild(row);
                        });
                    }

                    document.getElementById('playersLoading').style.display = 'none';
                    document.getElementById('playersContent').style.display = 'block';
                } else {
                    throw new Error('Erro ao carregar jogadores');
                }
            } catch (err) {
                document.getElementById('playersLoading').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> Erro ao carregar jogadores
                    </div>
                `;
            }
        }

        function getOvrColor(ovr) {
            if (ovr >= 95) return '#00ff00';
            if (ovr >= 90) return '#80ff00';
            if (ovr >= 85) return '#ffff00';
            if (ovr >= 80) return '#ff9900';
            if (ovr >= 70) return '#ff6600';
            return '#ff3333';
        }
    </script>
</body>
</html>
