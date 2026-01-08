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
    <title>Elencos - FBA Manager Control</title>
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
                        <a class="nav-link" href="/dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
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
            <div class="col-md-8">
                <h1 class="display-5 fw-bold">
                    <i class="bi bi-people-fill text-orange me-2"></i>Elencos e CAP
                </h1>
                <p class="text-light-gray">Visualize todos os times, jogadores e status de CAP</p>
            </div>
            <div class="col-md-4 text-end">
                <button id="btn-refresh-rosters" class="btn btn-orange">
                    <i class="bi bi-arrow-clockwise me-2"></i>Recarregar
                </button>
            </div>
        </div>

        <div class="alert alert-info bg-dark-panel border-orange">
            <i class="bi bi-info-circle me-2"></i>
            <strong>CAP:</strong> Soma dos 8 maiores OVRs do time • Mínimo: 618 • Máximo: 648
        </div>

        <div id="roster-status" class="text-center mb-3">
            <div class="spinner-border text-orange" role="status"></div>
            <p class="text-muted mt-2">Carregando elencos...</p>
        </div>

        <div id="roster-grid" class="row g-4"></div>
    </div>

    <footer class="footer">
        <p>FBA — Manager Control • marcosmedeiros.page</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/teams.js"></script>
</body>
</html>
