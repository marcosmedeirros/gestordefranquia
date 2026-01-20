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
    SELECT p.*, orig.city AS original_city, orig.name AS original_name,
           last_owner.city AS last_owner_city, last_owner.name AS last_owner_name
    FROM picks p
    LEFT JOIN teams orig ON p.original_team_id = orig.id
    LEFT JOIN teams last_owner ON p.last_owner_team_id = last_owner.id
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
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
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
                    <i class="bi bi-coin"></i>
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

        <div class="card bg-dark-panel border-orange mb-4">
            <div class="card-header bg-transparent border-orange d-flex align-items-center justify-content-between">
                <h5 class="mb-0 text-white"><i class="bi bi-plus-circle me-2 text-orange"></i>Adicionar Pick</h5>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label">Ano</label>
                        <input type="number" id="addPickYear" class="form-control bg-dark text-white border-orange" placeholder="2026" min="2000">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Rodada</label>
                        <select id="addPickRound" class="form-select bg-dark text-white border-orange">
                            <option value="1">1ª</option>
                            <option value="2">2ª</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Origem</label>
                        <select id="addPickOrigin" class="form-select bg-dark text-white border-orange">
                            <?php foreach ($allTeams as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['city'] . ' ' . $t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button class="btn btn-orange w-100" id="addPickBtn">
                            <i class="bi bi-save me-1"></i>Adicionar
                        </button>
                    </div>
                </div>
            </div>
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
                        <th class="text-white fw-bold text-end">Status</th>
                        <th class="text-white fw-bold text-end">Ações</th>
                    </tr>
                </thead>
                <tbody id="picksTableBody">
                    <?php if (count($picks) === 0): ?>
                        <tr>
                            <td colspan="5" class="text-center text-light-gray py-4">
                                <i class="bi bi-calendar-x fs-1 d-block mb-2 text-orange"></i>
                                Nenhuma pick ainda. As picks serão geradas automaticamente quando o admin criar uma temporada.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($picks as $pick): ?>
                            <tr 
                              data-pick-id="<?= (int)$pick['id'] ?>" 
                              data-year="<?= (int)$pick['season_year'] ?>" 
                              data-round="<?= htmlspecialchars($pick['round']) ?>" 
                              data-original-team-id="<?= (int)$pick['original_team_id'] ?>" 
                              data-notes="<?= htmlspecialchars($pick['notes'] ?? '') ?>"
                              data-auto="<?= (int)($pick['auto_generated'] ?? 0) ?>">
                                <td class="fw-bold text-orange"><?= htmlspecialchars((string)(int)$pick['season_year']) ?></td>
                                <td>
                                    <span class="badge bg-orange"><?= $pick['round'] ?>ª Rodada</span>
                                </td>
                                <td class="text-light-gray">
                                    <?php if ((int)($pick['last_owner_team_id'] ?? 0) === (int)$team['id']): ?>
                                        <span class="badge bg-success">Própria</span>
                                    <?php elseif (!empty($pick['last_owner_city'])): ?>
                                        via <?= htmlspecialchars($pick['last_owner_city'] . ' ' . $pick['last_owner_name']) ?>
                                    <?php elseif (!empty($pick['original_city'])): ?>
                                        via <?= htmlspecialchars($pick['original_city'] . ' ' . $pick['original_name']) ?>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if (!empty($pick['auto_generated'])): ?>
                                        <span class="badge bg-info">
                                            <i class="bi bi-cpu me-1"></i>Auto
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Manual</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-light" onclick="openEditPick(this)">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" onclick="deletePick(<?= (int)$pick['id'] ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
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

    <!-- Modal Editar Pick -->
    <div class="modal fade" id="editPickModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content bg-dark text-white border border-orange">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square text-orange me-2"></i>Editar pick</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editPickId">
                    <div class="mb-3">
                        <label class="form-label">Ano</label>
                        <input type="number" class="form-control bg-dark text-white border-orange" id="editPickYear" min="2000">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rodada</label>
                        <select class="form-select bg-dark text-white border-orange" id="editPickRound">
                            <option value="1">1ª rodada</option>
                            <option value="2">2ª rodada</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Origem</label>
                        <select class="form-select bg-dark text-white border-orange" id="editPickOrigin">
                            <?php foreach ($allTeams as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['city'] . ' ' . $t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Notas (opcional)</label>
                        <input type="text" class="form-control bg-dark text-white border-orange" id="editPickNotes" maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-orange" id="savePickBtn">
                        <i class="bi bi-save me-1"></i>Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const teamsOptions = <?= json_encode($allTeams) ?>;
        let editModal = null;

        function openEditPick(button) {
            const row = button.closest('tr');
            const id = row.dataset.pickId;
            const year = row.dataset.year;
            const round = row.dataset.round;
            const origin = row.dataset.originalTeamId;
            const notes = row.dataset.notes || '';
            const isAuto = row.dataset.auto === '1';

            document.getElementById('editPickId').value = id;
            document.getElementById('editPickYear').value = year;
            document.getElementById('editPickRound').value = round;
            document.getElementById('editPickOrigin').value = origin;
            document.getElementById('editPickNotes').value = notes;
            document.getElementById('editPickNotes').placeholder = isAuto ? 'Esta pick era auto; edições salvam como manual' : '';

            if (!editModal) {
                editModal = new bootstrap.Modal(document.getElementById('editPickModal'));
            }
            editModal.show();
        }

        document.getElementById('savePickBtn')?.addEventListener('click', async () => {
            const id = document.getElementById('editPickId').value;
            const year = parseInt(document.getElementById('editPickYear').value, 10);
            const round = document.getElementById('editPickRound').value;
            const original_team_id = document.getElementById('editPickOrigin').value;
            const notes = document.getElementById('editPickNotes').value.trim();

            if (!year || year < 2000) {
                alert('Informe um ano válido');
                return;
            }

            try {
                const res = await fetch('/api/picks.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, year, round, original_team_id, notes })
                });
                const body = await res.json();
                if (!res.ok || !body.success) {
                    throw new Error(body.error || 'Erro ao salvar');
                }
                editModal?.hide();
                location.reload();
            } catch (err) {
                alert(err.message || 'Erro ao salvar pick');
            }
        });

        document.getElementById('addPickBtn')?.addEventListener('click', async () => {
            const year = parseInt(document.getElementById('addPickYear').value, 10);
            const round = document.getElementById('addPickRound').value;
            const original_team_id = document.getElementById('addPickOrigin').value;
            if (!year || year < 2000) {
                alert('Informe um ano válido');
                return;
            }
            try {
                const res = await fetch('/api/picks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        team_id: <?= (int)$team['id'] ?>,
                        original_team_id,
                        year,
                        round
                    })
                });
                const body = await res.json();
                if (!res.ok || !body.success) {
                    throw new Error(body.error || 'Erro ao adicionar pick');
                }
                location.reload();
            } catch (err) {
                alert(err.message || 'Erro ao adicionar pick');
            }
        });

        async function deletePick(id) {
            if (!confirm('Deseja excluir esta pick manual?')) return;
            try {
                const res = await fetch(`/api/picks.php?id=${id}`, { method: 'DELETE' });
                const body = await res.json();
                if (!res.ok || !body.success) {
                    throw new Error(body.error || 'Erro ao excluir');
                }
                location.reload();
            } catch (err) {
                alert(err.message || 'Erro ao excluir pick');
            }
        }
    </script>
</body>
</html>
