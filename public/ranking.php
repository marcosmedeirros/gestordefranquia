<?php
session_start();
require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

requireAuth();

$user = $_SESSION['user'];
$league = $user['league'];

$pdo = getDB();

// Obter informações de conferência
$stmt = $pdo->prepare("
    SELECT DISTINCT conference 
    FROM teams 
    WHERE league = ? AND conference IS NOT NULL
    ORDER BY conference
");
$stmt->execute([$league]);
$conferences = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Obter dados de todos os times da liga com estatísticas
$stmt = $pdo->prepare("
    SELECT 
        t.id,
        t.name,
        t.mascot,
        t.conference,
        u.name as owner_name,
        COUNT(p.id) as total_players,
        AVG(p.ovr) as avg_ovr,
        MAX(p.ovr) as max_ovr,
        SUM(CASE WHEN p.role = 'Titular' THEN 1 ELSE 0 END) as titular_count,
        SUM(CASE WHEN p.available_for_trade = 1 THEN 1 ELSE 0 END) as available_count
    FROM teams t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN players p ON t.id = p.team_id
    WHERE t.league = ?
    GROUP BY t.id, t.name, t.mascot, t.conference, u.name
    ORDER BY avg_ovr DESC
");
$stmt->execute([$league]);
$standings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separar por conferência
$conference_standings = [];
foreach ($standings as $team) {
    $conf = $team['conference'] ?? 'Sem Conferência';
    if (!isset($conference_standings[$conf])) {
        $conference_standings[$conf] = [];
    }
    $conference_standings[$conf][] = $team;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ranking da Liga - FBA Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        .standings-container {
            background: var(--fba-card-bg);
            border: 1px solid var(--fba-border);
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 30px;
        }
        .conference-header {
            background: var(--fba-dark-bg);
            padding: 15px 20px;
            border-bottom: 2px solid var(--fba-orange);
            font-weight: bold;
            color: var(--fba-orange);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .standings-table {
            width: 100%;
            border-collapse: collapse;
        }
        .standings-header {
            background: var(--fba-dark-bg);
            padding: 12px 15px;
            color: var(--fba-text-muted);
            font-size: 0.9rem;
            text-transform: uppercase;
            display: grid;
            grid-template-columns: 50px 1fr 100px 80px 80px 100px 80px;
            gap: 15px;
            border-bottom: 1px solid var(--fba-border);
            font-weight: 500;
            letter-spacing: 0.5px;
        }
        .standings-row {
            padding: 12px 15px;
            display: grid;
            grid-template-columns: 50px 1fr 100px 80px 80px 100px 80px;
            gap: 15px;
            align-items: center;
            border-bottom: 1px solid var(--fba-border);
            transition: all 0.3s ease;
        }
        .standings-row:hover {
            background: var(--fba-dark-bg);
        }
        .standings-row:last-child {
            border-bottom: none;
        }
        .position-badge {
            background: var(--fba-orange);
            color: #000;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1rem;
        }
        .team-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .team-name {
            font-weight: 500;
            color: var(--fba-text);
        }
        .team-owner {
            font-size: 0.8rem;
            color: var(--fba-text-muted);
        }
        .stat-value {
            color: var(--fba-text);
            font-weight: 500;
        }
        .stat-muted {
            color: var(--fba-text-muted);
            font-size: 0.9rem;
        }
        .ovr-value {
            color: var(--fba-orange);
            font-weight: bold;
            font-size: 1.1rem;
        }
        .medals {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        .medal {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
        }
        .medal-gold {
            background: #ffd700;
            color: #000;
        }
        .medal-silver {
            background: #c0c0c0;
            color: #000;
        }
        .medal-bronze {
            background: #cd7f32;
            color: #fff;
        }
        .no-teams {
            text-align: center;
            padding: 40px 20px;
            color: var(--fba-text-muted);
        }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--fba-border);
        }
        .tab-btn {
            padding: 10px 20px;
            background: transparent;
            border: none;
            color: var(--fba-text-muted);
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        .tab-btn.active {
            color: var(--fba-orange);
            border-bottom-color: var(--fba-orange);
        }
        .tab-btn:hover {
            color: var(--fba-text);
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
                        <i class="bi bi-trophy"></i> Ranking da Liga
                    </h1>
                    <p class="text-muted">Posicionamento de todos os times da <?= htmlspecialchars($league) ?></p>
                </div>

                <!-- Tabs for Conferences -->
                <?php if (count($conference_standings) > 1): ?>
                <div class="tabs">
                    <button class="tab-btn active" onclick="showConference(this, 'all')">Geral</button>
                    <?php foreach ($conference_standings as $conf => $teams): ?>
                        <?php if ($conf !== 'Sem Conferência'): ?>
                    <button class="tab-btn" onclick="showConference(this, '<?= htmlspecialchars($conf) ?>')">
                        <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($conf) ?>
                    </button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- All Conferences -->
                <?php foreach ($conference_standings as $conf => $teams): ?>
                <div class="standings-container" data-conference="<?= htmlspecialchars($conf) ?>">
                    <div class="conference-header">
                        <i class="bi bi-diagram-3"></i> <?= htmlspecialchars($conf) ?>
                    </div>
                    
                    <?php if (empty($teams)): ?>
                    <div class="no-teams">
                        <p>Nenhum time registrado nesta conferência</p>
                    </div>
                    <?php else: ?>
                    <div class="standings-header">
                        <div>#</div>
                        <div>Time</div>
                        <div style="text-align: center;">Jogadores</div>
                        <div style="text-align: center;">Titulares</div>
                        <div style="text-align: center;">Disponível</div>
                        <div style="text-align: right;">OVR Médio</div>
                        <div style="text-align: right;">Melhor OVR</div>
                    </div>
                    
                    <?php foreach ($teams as $index => $team): ?>
                    <div class="standings-row">
                        <div>
                            <div class="medals">
                                <div class="position-badge">
                                    <?php if ($index === 0): ?>
                                    <i class="bi bi-award" style="color: #ffd700;"></i>
                                    <?php elseif ($index === 1): ?>
                                    <i class="bi bi-award" style="color: #c0c0c0;"></i>
                                    <?php elseif ($index === 2): ?>
                                    <i class="bi bi-award" style="color: #cd7f32;"></i>
                                    <?php else: ?>
                                    <?= $index + 1 ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="team-info">
                            <div>
                                <div class="team-name"><?= htmlspecialchars($team['name']) ?></div>
                                <div class="team-owner"><i class="bi bi-person"></i> <?= htmlspecialchars($team['owner_name'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <div style="text-align: center;" class="stat-value">
                            <?= $team['total_players'] ?? 0 ?>
                        </div>
                        <div style="text-align: center;" class="stat-value">
                            <?= $team['titular_count'] ?? 0 ?>
                        </div>
                        <div style="text-align: center;" class="stat-value">
                            <?= $team['available_count'] ?? 0 ?>
                        </div>
                        <div style="text-align: right;" class="ovr-value">
                            <?= round($team['avg_ovr'] ?? 0) ?>
                        </div>
                        <div style="text-align: right;" class="ovr-value">
                            <?= $team['max_ovr'] ?? 0 ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="js/app.js"></script>
    <script>
        function showConference(button, conference) {
            // Atualizar abas
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');

            // Mostrar/esconder containers
            document.querySelectorAll('[data-conference]').forEach(container => {
                if (conference === 'all') {
                    container.style.display = 'block';
                } else {
                    container.style.display = container.dataset.conference === conference ? 'block' : 'none';
                }
            });
        }
    </script>
</body>
</html>
