<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário para a sidebar
$stmtTeam = $pdo->prepare('
  SELECT t.*, COUNT(p.id) as player_count
  FROM teams t
  LEFT JOIN players p ON p.team_id = t.id
  WHERE t.user_id = ?
  GROUP BY t.id
  ORDER BY player_count DESC, t.id DESC
');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

// Buscar TODOS os times da liga do usuário
$stmt = $pdo->prepare('SELECT id, city, name, mascot, photo_url, user_id FROM teams WHERE league = ? ORDER BY id ASC');
$stmt->execute([$user['league']]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// DEBUG: Verificar IDs únicos
error_log("=== DEBUG TEAMS.PHP ===");
error_log("Liga: " . $user['league']);
error_log("Total times retornados: " . count($teams));
foreach ($teams as $idx => $tm) {
    error_log("Index $idx: ID={$tm['id']}, Nome={$tm['city']} {$tm['name']}, user_id={$tm['user_id']}");
}

// Remover duplicatas por ID (se existirem)
$uniqueTeams = [];
$seenIds = [];
foreach ($teams as $t) {
    if (!in_array($t['id'], $seenIds)) {
        $seenIds[] = $t['id'];
        $uniqueTeams[] = $t;
    } else {
        error_log("DUPLICATA ENCONTRADA: ID {$t['id']}");
    }
}
$teams = $uniqueTeams;

// Adicionar dados complementares para cada time
foreach ($teams as &$t) {
  $ownerStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
  $ownerStmt->execute([$t['user_id']]);
  $owner = $ownerStmt->fetch();
  $t['owner_name'] = $owner['name'] ?? 'N/A';
  
  $playerStmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM players WHERE team_id = ?');
  $playerStmt->execute([$t['id']]);
  $t['total_players'] = (int)$playerStmt->fetch()['cnt'];
  
  $capStmt = $pdo->prepare('SELECT SUM(ovr) as cap FROM (SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8) as top8');
  $capStmt->execute([$t['id']]);
  $capResult = $capStmt->fetch();
  $t['cap_top8'] = (int)($capResult['cap'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Times - FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css">
</head>
<body>
    <!-- Sidebar -->
    <div class="dashboard-sidebar">
        <div class="text-center mb-4">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" 
                 alt="<?= htmlspecialchars($team['name'] ?? 'Time') ?>" class="team-avatar">
            <h5 class="text-white mb-1"><?= htmlspecialchars(($team['city'] ?? '') . ' ' . ($team['name'] ?? 'Sem time')) ?></h5>
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
        <h1 class="display-5 fw-bold mb-4">
            <i class="bi bi-people-fill text-orange me-2"></i>Times da Liga
        </h1>

        <div class="card bg-dark-panel border-orange">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead style="background: linear-gradient(135deg, #f17507, #ff8c1a);">
                            <tr>
                                <th class="text-white fw-bold" style="width: 80px;">Logo</th>
                                <th class="text-white fw-bold">Time</th>
                                <th class="text-white fw-bold">Proprietário</th>
                                <th class="text-white fw-bold text-center">Jogadores</th>
                                <th class="text-white fw-bold text-center">CAP Top8</th>
                                <th class="text-white fw-bold text-center" style="width: 120px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teams as $t): ?>
                            <tr>
                                <td>
                                    <img src="<?= htmlspecialchars($t['photo_url'] ?? '/img/default-team.png') ?>" 
                                         alt="<?= htmlspecialchars($t['name']) ?>" 
                                         style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; border: 2px solid var(--fba-orange);">
                                </td>
                                <td>
                                    <div class="fw-bold text-orange"><?= htmlspecialchars($t['city'] . ' ' . $t['name']) ?></div>
                                    <small class="text-light-gray"><?= htmlspecialchars($t['mascot'] ?? '') ?></small>
                                </td>
                                <td>
                                    <i class="bi bi-person me-1 text-orange"></i><?= htmlspecialchars($t['owner_name']) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-gradient-orange"><?= $t['total_players'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-gradient-orange"><?= $t['cap_top8'] ?></span>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-orange" onclick="verJogadores(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['city'] . ' ' . $t['name'])) ?>')">
                                        <i class="bi bi-eye me-1"></i>Ver Elenco
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="playersModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark-panel border-orange">
                <div class="modal-header border-orange">
                    <h5 class="modal-title text-white" id="modalTitle"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="loading" class="text-center py-4">
                        <div class="spinner-border text-orange" role="status"></div>
                    </div>
                    <div id="content" style="display: none;">
                        <table class="table table-dark table-hover mb-0">
                            <thead style="background: var(--fba-orange); color: #000;">
                                <tr>
                                    <th>Jogador</th>
                                    <th>OVR</th>
                                    <th>Posição</th>
                                    <th>Função</th>
                                </tr>
                            </thead>
                            <tbody id="playersList"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/sidebar.js"></script>
    <script>
        async function verJogadores(teamId, teamName) {
            document.getElementById('modalTitle').textContent = 'Elenco: ' + teamName;
            document.getElementById('loading').style.display = 'block';
            document.getElementById('content').style.display = 'none';
            
            const modal = new bootstrap.Modal(document.getElementById('playersModal'));
            modal.show();

            try {
                const res = await fetch(`/api/team-players.php?team_id=${teamId}`);
                const data = await res.json();

                if (data.success && data.players) {
                    const tbody = document.getElementById('playersList');
                    tbody.innerHTML = '';

                    if (data.players.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Nenhum jogador</td></tr>';
                    } else {
                        data.players.forEach(p => {
                            tbody.innerHTML += `<tr>
                                <td><strong>${p.name}</strong></td>
                                <td><span class="badge bg-warning text-dark">${p.ovr}</span></td>
                                <td>${p.position}</td>
                                <td>${p.role}</td>
                            </tr>`;
                        });
                    }

                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('content').style.display = 'block';
                }
            } catch (err) {
                document.getElementById('loading').innerHTML = '<div class="alert alert-danger">Erro ao carregar</div>';
            }
        }
    </script>
</body>
</html>