<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário (se existir)
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Configurações - FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css" />
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
                <a href="/teams.php">
                    <i class="bi bi-people-fill"></i>
                    Todos os Times
                </a>
            </li>
            <li>
                <a href="#" onclick="alert('Em breve!'); return false;">
                    <i class="bi bi-person-fill"></i>
                    Meu Elenco
                </a>
            </li>
            <li>
                <a href="#" onclick="alert('Em breve!'); return false;">
                    <i class="bi bi-trophy-fill"></i>
                    Drafts
                </a>
            </li>
            <li>
                <a href="#" onclick="alert('Em breve!'); return false;">
                    <i class="bi bi-arrow-left-right"></i>
                    Trades
                </a>
            </li>
            <li>
                <a href="#" onclick="alert('Em breve!'); return false;">
                    <i class="bi bi-bar-chart-fill"></i>
                    Estatísticas
                </a>
            </li>
            <li>
                <a href="/settings.php" class="active">
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
        <div class="mb-4 d-flex align-items-center justify-content-between">
            <h1 class="text-white fw-bold mb-2"><i class="bi bi-gear-fill me-2 text-orange"></i>Configurações</h1>
            <a class="btn btn-outline-orange" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i> Voltar ao Dashboard</a>
        </div>

        <div class="row g-4">
            <!-- Perfil -->
            <div class="col-md-6">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-header bg-transparent border-orange">
                        <h4 class="mb-0 text-white"><i class="bi bi-person-circle me-2 text-orange"></i>Meu Perfil</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="photo-upload-container mx-auto" style="width: 150px;">
                                <img src="<?= htmlspecialchars($user['photo_url'] ?? '/img/default-avatar.png') ?>" alt="Avatar" class="photo-preview" id="profile-photo-preview">
                                <label for="profile-photo-upload" class="photo-upload-overlay">
                                    <i class="bi bi-camera-fill"></i>
                                    <span>Alterar Foto</span>
                                </label>
                                <input type="file" id="profile-photo-upload" class="d-none" accept="image/*">
                            </div>
                        </div>
                        <form id="form-profile">
                            <div class="mb-3">
                                <label class="form-label text-white fw-bold">Nome</label>
                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-white fw-bold">E-mail</label>
                                <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                                <small class="text-light-gray">E-mail não pode ser alterado.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-white fw-bold">Liga</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($user['league']) ?>" disabled>
                            </div>
                            <div class="text-end">
                                <button type="button" class="btn btn-orange" id="btn-save-profile"><i class="bi bi-save2 me-1"></i> Salvar Perfil</button>
                            </div>
                        </form>
                        <hr class="my-4" style="border-color: var(--fba-border);">
                        <h5 class="text-white mb-3"><i class="bi bi-shield-lock-fill me-2 text-orange"></i>Alterar Senha</h5>
                        <form id="form-password">
                            <div class="mb-3">
                                <label class="form-label text-white fw-bold">Senha atual</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-white fw-bold">Nova senha</label>
                                <input type="password" name="new_password" class="form-control" required>
                            </div>
                            <div class="text-end">
                                <button type="button" class="btn btn-orange" id="btn-change-password"><i class="bi bi-key-fill me-1"></i> Alterar Senha</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Time -->
            <div class="col-md-6">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-header bg-transparent border-orange d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 text-white"><i class="bi bi-trophy me-2 text-orange"></i>Meu Time</h4>
                        <?php if ($team): ?>
                        <span class="badge bg-gradient-orange"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($team): ?>
                        <div class="text-center mb-3">
                            <div class="photo-upload-container mx-auto" style="width: 150px;">
                                <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" alt="Logo" class="photo-preview" id="team-photo-preview">
                                <label for="team-photo-upload" class="photo-upload-overlay">
                                    <i class="bi bi-image-fill"></i>
                                    <span>Alterar Logo</span>
                                </label>
                                <input type="file" id="team-photo-upload" class="d-none" accept="image/*">
                            </div>
                        </div>
                        <form id="form-team-settings">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-white fw-bold">Nome do Time</label>
                                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($team['name']) ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-white fw-bold">Cidade</label>
                                    <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($team['city']) ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-white fw-bold">Mascote</label>
                                <input type="text" name="mascot" class="form-control" value="<?= htmlspecialchars($team['mascot']) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-white fw-bold">Conferência</label>
                                <select name="conference" class="form-select">
                                    <option value="LESTE" <?= (isset($team['conference']) && $team['conference'] === 'LESTE') ? 'selected' : '' ?>>LESTE</option>
                                    <option value="OESTE" <?= (isset($team['conference']) && $team['conference'] === 'OESTE') ? 'selected' : '' ?>>OESTE</option>
                                </select>
                            </div>
                            <div class="text-end">
                                <button type="button" class="btn btn-orange" id="btn-save-team"><i class="bi bi-save2 me-1"></i> Salvar Time</button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="alert alert-warning">Você ainda não possui um time. Crie um no onboarding.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/settings.js"></script>
</body>
</html>
