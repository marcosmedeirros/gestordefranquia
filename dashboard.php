<?php
require_once __DIR__ . '/backend/auth.php';
requireAuth();

$user = getUserSession();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard - FBA Manager Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css" />
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="/dashboard.php">
                <img src="/img/fba-logo.png" alt="FBA" height="40" class="me-2">
                <span class="fw-bold">Manager Control FBA</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <span class="badge bg-gradient-orange me-3">
                            <i class="bi bi-trophy-fill me-1"></i><?= htmlspecialchars($user['league']) ?>
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/teams.php"><i class="bi bi-people-fill me-1"></i>Elencos</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($user['name']) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end bg-dark-panel border-orange">
                            <li><a class="dropdown-item text-light" href="/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sair</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="row mb-4">
            <div class="col">
                <h1 class="display-5 fw-bold">
                    <i class="bi bi-speedometer2 text-orange me-2"></i>Dashboard
                </h1>
                <p class="text-light-gray">Bem-vindo, <?= htmlspecialchars($user['name']) ?>! Você está na liga <span class="badge bg-gradient-orange"><?= htmlspecialchars($user['league']) ?></span></p>
            </div>
        </div>

        <div class="row g-4">
            <!-- Criar Time -->
            <div class="col-md-6">
                <div class="card bg-dark-panel border-orange h-100">
                    <div class="card-header bg-transparent border-orange">
                        <h5 class="mb-0"><i class="bi bi-trophy me-2 text-orange"></i>Criar Time</h5>
                    </div>
                    <div class="card-body">
                        <form id="form-team">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <div class="mb-3">
                                <label class="form-label">Nome do time</label>
                                <input name="name" class="form-control bg-dark text-light" placeholder="Ex: Lakers" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Cidade</label>
                                <input name="city" class="form-control bg-dark text-light" placeholder="Ex: Los Angeles" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Mascote</label>
                                <input name="mascot" class="form-control bg-dark text-light" placeholder="Ex: Lakers" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">URL da foto (opcional)</label>
                                <input name="photo_url" type="url" class="form-control bg-dark text-light" placeholder="https://...">
                            </div>
                            <button type="submit" class="btn btn-orange w-100"><i class="bi bi-plus-circle me-2"></i>Criar Time</button>
                        </form>
                        <div id="team-message" class="mt-3"></div>
                    </div>
                </div>
            </div>

            <!-- Adicionar Jogador -->
            <div class="col-md-6">
                <div class="card bg-dark-panel border-orange h-100">
                    <div class="card-header bg-transparent border-orange">
                        <h5 class="mb-0"><i class="bi bi-person-plus me-2 text-orange"></i>Adicionar Jogador</h5>
                    </div>
                    <div class="card-body">
                        <form id="form-player">
                            <div class="mb-3">
                                <label class="form-label">ID do Time</label>
                                <input name="team_id" type="number" class="form-control bg-dark text-light" placeholder="1" required>
                            </div>
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Nome</label>
                                    <input name="name" class="form-control bg-dark text-light" placeholder="LeBron James" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Idade</label>
                                    <input name="age" type="number" class="form-control bg-dark text-light" placeholder="30" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Posição</label>
                                    <input name="position" class="form-control bg-dark text-light" placeholder="SF" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">OVR</label>
                                    <input name="ovr" type="number" class="form-control bg-dark text-light" placeholder="90" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Tipo</label>
                                    <select name="role" class="form-control bg-dark text-light">
                                        <option value="Titular">Titular</option>
                                        <option value="Banco">Banco</option>
                                        <option value="Outro">Outro</option>
                                        <option value="G-League">G-League</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-check mb-3">
                                <input type="checkbox" name="available_for_trade" class="form-check-input" id="tradeCheck">
                                <label class="form-check-label text-light-gray" for="tradeCheck">
                                    Disponível para troca
                                </label>
                            </div>
                            <button type="submit" class="btn btn-orange w-100"><i class="bi bi-plus-circle me-2"></i>Adicionar Jogador</button>
                        </form>
                        <div id="player-message" class="mt-3"></div>
                    </div>
                </div>
            </div>

            <!-- Lista de Times -->
            <div class="col-12">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-header bg-transparent border-orange d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2 text-orange"></i>Meus Times</h5>
                        <button id="btn-load-teams" class="btn btn-sm btn-orange"><i class="bi bi-arrow-clockwise me-1"></i>Recarregar</button>
                    </div>
                    <div class="card-body">
                        <div id="teams-list" class="table-responsive"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>FBA — Manager Control • marcosmedeiros.page</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/dashboard.js"></script>
</body>
</html>
