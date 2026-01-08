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
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Minhas Picks - FBA Manager</title>
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
            <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
            <li>
                <a href="/admin.php">
                    <i class="bi bi-shield-lock-fill"></i>
                    Admin
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
            <p class="text-light-gray">Gerencie as picks de draft do seu time</p>
        </div>

        <!-- Formulário para adicionar pick -->
        <div class="card bg-dark-panel border-orange mb-4">
            <div class="card-header bg-transparent border-orange">
                <h5 class="text-white mb-0"><i class="bi bi-plus-circle me-2 text-orange"></i>Adicionar Nova Pick</h5>
            </div>
            <div class="card-body">
                <form id="formAddPick">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label text-white fw-bold">Ano</label>
                            <input type="number" name="year" class="form-control bg-dark text-white border-orange" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-white fw-bold">Rodada</label>
                            <select name="round" class="form-select bg-dark text-white border-orange" required>
                                <option value="">Selecione...</option>
                                <option value="1">1ª Rodada</option>
                                <option value="2">2ª Rodada</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-white fw-bold">Recebida de (opcional)</label>
                            <select name="original_team_id" class="form-select bg-dark text-white border-orange">
                                <option value="">Própria do time</option>
                                <?php foreach ($allTeams as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['city'] . ' ' . $t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-orange w-100">
                                <i class="bi bi-plus-circle me-1"></i>Adicionar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista de picks -->
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0">
                <thead style="background: linear-gradient(135deg, #f17507, #ff8c1a);">
                    <tr>
                        <th class="text-white fw-bold">Ano</th>
                        <th class="text-white fw-bold">Rodada</th>
                        <th class="text-white fw-bold">Origem</th>
                        <th class="text-white fw-bold">Ações</th>
                    </tr>
                </thead>
                <tbody id="picksTableBody">
                    <?php if (count($picks) === 0): ?>
                        <tr>
                            <td colspan="4" class="text-center text-light-gray">
                                Nenhuma pick cadastrada ainda
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($picks as $pick): ?>
                            <tr>
                                <td class="fw-bold text-orange"><?= $pick['season_year'] ?></td>
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
                                    <button class="btn btn-sm btn-danger" onclick="deletePick(<?= $pick['id'] ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
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
    <script>
        const teamId = <?= $team['id'] ?>;

        document.getElementById('formAddPick').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const year = formData.get('year');
            const round = formData.get('round');
            const originalTeamId = formData.get('original_team_id') || teamId;

            try {
                const response = await fetch('/api/picks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        team_id: teamId,
                        original_team_id: originalTeamId,
                        year: year,
                        round: round
                    })
                });

                const data = await response.json();

                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'Erro ao adicionar pick');
                }
            } catch (err) {
                alert('Erro ao adicionar pick');
            }
        });

        async function deletePick(pickId) {
            if (!confirm('Deseja realmente excluir esta pick?')) return;

            try {
                const response = await fetch(`/api/picks.php?id=${pickId}`, {
                    method: 'DELETE'
                });

                const data = await response.json();

                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'Erro ao excluir pick');
                }
            } catch (err) {
                alert('Erro ao excluir pick');
            }
        }
    </script>
</body>
</html>
