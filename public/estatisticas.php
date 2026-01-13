<?php
session_start();
require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

requireAuth();

$user = $_SESSION['user'];
$league = $user['league'];

$pdo = getDB();
$stmt = $pdo->prepare("SELECT id, name FROM teams WHERE user_id = ? AND league = ?");
$stmt->execute([$user['id'], $league]);
$userTeam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userTeam) {
    header('Location: onboarding.php');
    exit;
}

// Obter estatísticas do time
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_players,
        AVG(ovr) as avg_ovr,
        MAX(ovr) as max_ovr,
        MIN(ovr) as min_ovr,
        SUM(CASE WHEN role = 'Titular' THEN 1 ELSE 0 END) as titular_count,
        SUM(CASE WHEN role = 'Banco' THEN 1 ELSE 0 END) as banco_count,
        SUM(CASE WHEN role = 'Outro' THEN 1 ELSE 0 END) as outro_count,
        SUM(CASE WHEN role = 'G-League' THEN 1 ELSE 0 END) as gleague_count
    FROM players
    WHERE team_id = ?
");
$stmt->execute([$userTeam['id']]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Obter players por posição
$stmt = $pdo->prepare("
    SELECT position, COUNT(*) as count, AVG(ovr) as avg_ovr
    FROM players
    WHERE team_id = ?
    GROUP BY position
    ORDER BY position
");
$stmt->execute([$userTeam['id']]);
$positionStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Estatísticas - FBA Manager</title>
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0a0a0c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/icon-192.png">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        .stat-card {
            background: var(--fba-card-bg);
            border: 1px solid var(--fba-border);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            border-color: var(--fba-orange);
            box-shadow: 0 4px 12px rgba(241, 117, 7, 0.2);
        }
        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--fba-orange);
            margin: 10px 0;
        }
        .stat-label {
            font-size: 0.95rem;
            color: var(--fba-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .chart-container {
            background: var(--fba-card-bg);
            border: 1px solid var(--fba-border);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .chart-title {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--fba-text);
            margin-bottom: 20px;
        }
        .role-bar {
            display: flex;
            align-items: center;
            padding: 12px;
            background: var(--fba-dark-bg);
            border-radius: 6px;
            margin-bottom: 10px;
            border-left: 3px solid var(--fba-orange);
        }
        .role-label {
            min-width: 100px;
            font-weight: 500;
            color: var(--fba-text);
        }
        .role-bar-progress {
            flex: 1;
            background: var(--fba-border);
            height: 24px;
            border-radius: 4px;
            margin: 0 15px;
            position: relative;
            overflow: hidden;
        }
        .role-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--fba-orange), #ff9900);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #000;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .role-count {
            min-width: 50px;
            text-align: right;
            font-weight: bold;
            color: var(--fba-orange);
        }
        .position-table {
            background: var(--fba-card-bg);
            border: 1px solid var(--fba-border);
            border-radius: 8px;
            overflow: hidden;
        }
        .position-table-header {
            background: var(--fba-dark-bg);
            padding: 15px;
            border-bottom: 1px solid var(--fba-border);
            font-weight: bold;
            color: var(--fba-text);
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
        }
        .position-row {
            padding: 12px 15px;
            border-bottom: 1px solid var(--fba-border);
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            align-items: center;
        }
        .position-row:last-child {
            border-bottom: none;
        }
        .position-name {
            font-weight: 500;
            color: var(--fba-text);
        }
        .position-count {
            text-align: center;
            color: var(--fba-text-muted);
        }
        .position-ovr {
            text-align: right;
            color: var(--fba-orange);
            font-weight: bold;
        }
    </style>
</head>
<body class="fba-dark">
    <div class="container-fluid">
        <div class="row" style="min-height: 100vh;">
            <!-- Sidebar -->
            <?php include 'components/sidebar.php'; ?>
            
            <!-- Main Content -->
            <div class="col-md-9 p-4">
                <div class="mb-4">
                    <h1 class="display-5 mb-2" style="color: var(--fba-text);">
                        <i class="bi bi-graph-up"></i> Estatísticas
                    </h1>
                    <p class="text-muted">Análise detalhada do seu time</p>
                </div>

                <!-- Stats Overview -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card">
                            <i class="bi bi-people-fill" style="font-size: 2rem; color: var(--fba-orange);"></i>
                            <div class="stat-value"><?= $stats['total_players'] ?? 0 ?></div>
                            <div class="stat-label">Total de Jogadores</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card">
                            <i class="bi bi-graph-up" style="font-size: 2rem; color: var(--fba-orange);"></i>
                            <div class="stat-value"><?= round($stats['avg_ovr'] ?? 0) ?></div>
                            <div class="stat-label">OVR Médio</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card">
                            <i class="bi bi-star-fill" style="font-size: 2rem; color: var(--fba-orange);"></i>
                            <div class="stat-value"><?= $stats['max_ovr'] ?? 0 ?></div>
                            <div class="stat-label">Melhor OVR</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card">
                            <i class="bi bi-graph-down" style="font-size: 2rem; color: var(--fba-orange);"></i>
                            <div class="stat-value"><?= $stats['min_ovr'] ?? 0 ?></div>
                            <div class="stat-label">Menor OVR</div>
                        </div>
                    </div>
                </div>

                <!-- Roster Composition -->
                <div class="chart-container">
                    <div class="chart-title">Composição do Elenco</div>
                    <div>
                        <div class="role-bar">
                            <div class="role-label">Titulares</div>
                            <div class="role-bar-progress">
                                <div class="role-bar-fill" style="width: <?= ($stats['titular_count'] ?? 0) / ($stats['total_players'] ?? 1) * 100 ?>%;">
                                    <?= round(($stats['titular_count'] ?? 0) / ($stats['total_players'] ?? 1) * 100) ?>%
                                </div>
                            </div>
                            <div class="role-count"><?= $stats['titular_count'] ?? 0 ?></div>
                        </div>
                        <div class="role-bar">
                            <div class="role-label">Banco</div>
                            <div class="role-bar-progress">
                                <div class="role-bar-fill" style="width: <?= ($stats['banco_count'] ?? 0) / ($stats['total_players'] ?? 1) * 100 ?>%;">
                                    <?= round(($stats['banco_count'] ?? 0) / ($stats['total_players'] ?? 1) * 100) ?>%
                                </div>
                            </div>
                            <div class="role-count"><?= $stats['banco_count'] ?? 0 ?></div>
                        </div>
                        <div class="role-bar">
                            <div class="role-label">Outro</div>
                            <div class="role-bar-progress">
                                <div class="role-bar-fill" style="width: <?= ($stats['outro_count'] ?? 0) / ($stats['total_players'] ?? 1) * 100 ?>%;">
                                    <?= round(($stats['outro_count'] ?? 0) / ($stats['total_players'] ?? 1) * 100) ?>%
                                </div>
                            </div>
                            <div class="role-count"><?= $stats['outro_count'] ?? 0 ?></div>
                        </div>
                        <div class="role-bar">
                            <div class="role-label">G-League</div>
                            <div class="role-bar-progress">
                                <div class="role-bar-fill" style="width: <?= ($stats['gleague_count'] ?? 0) / ($stats['total_players'] ?? 1) * 100 ?>%;">
                                    <?= round(($stats['gleague_count'] ?? 0) / ($stats['total_players'] ?? 1) * 100) ?>%
                                </div>
                            </div>
                            <div class="role-count"><?= $stats['gleague_count'] ?? 0 ?></div>
                        </div>
                    </div>
                </div>

                <!-- Players by Position -->
                <div class="chart-container">
                    <div class="chart-title">Jogadores por Posição</div>
                    <div class="position-table">
                        <div class="position-table-header">
                            <div>Posição</div>
                            <div style="text-align: center;">Quantidade</div>
                            <div style="text-align: right;">OVR Médio</div>
                        </div>
                        <?php foreach ($positionStats as $position): ?>
                        <div class="position-row">
                            <div class="position-name"><?= htmlspecialchars($position['position']) ?></div>
                            <div class="position-count"><?= $position['count'] ?></div>
                            <div class="position-ovr"><?= round($position['avg_ovr']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="js/app.js"></script>
    <script src="/js/pwa.js"></script>
</body>
</html>
