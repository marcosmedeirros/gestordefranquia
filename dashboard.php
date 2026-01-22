<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário
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

// Se não tem time, redireciona para onboarding
if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

// Buscar estatísticas do time
$stmtPlayers = $pdo->prepare('SELECT COUNT(*) as total, SUM(ovr) as total_ovr FROM players WHERE team_id = ?');
$stmtPlayers->execute([$team['id']]);
$stats = $stmtPlayers->fetch();

$totalPlayers = $stats['total'] ?? 0;
$avgOvr = $totalPlayers > 0 ? round($stats['total_ovr'] / $totalPlayers, 1) : 0;

// Buscar jogadores titulares
$stmtTitulares = $pdo->prepare("SELECT * FROM players WHERE team_id = ? AND role = 'Titular' ORDER BY ovr DESC");
$stmtTitulares->execute([$team['id']]);
$titulares = $stmtTitulares->fetchAll();

// Calcular CAP Top8 (soma dos 8 maiores OVRs)
$teamCap = 0;
$stmtCap = $pdo->prepare('
    SELECT SUM(ovr) as cap FROM (
        SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8
    ) as top_eight
');
$stmtCap->execute([$team['id']]);
$capData = $stmtCap->fetch();
$teamCap = $capData['cap'] ?? 0;

// Buscar limites de CAP da liga
$capMin = 600;
$capMax = 700;
try {
    $stmtCapLimits = $pdo->prepare('SELECT cap_min, cap_max FROM league_settings WHERE league = ?');
    $stmtCapLimits->execute([$team['league']]);
    $capLimits = $stmtCapLimits->fetch();
    if ($capLimits) {
        $capMin = (int)$capLimits['cap_min'];
        $capMax = (int)$capLimits['cap_max'];
    }
} catch (Exception $e) {
    // Tabela league_settings pode não existir ainda
}

// Determinar cor do CAP
$capColor = '#00ff00'; // Verde
if ($teamCap < $capMin || $teamCap > $capMax) {
    $capColor = '#ff4444'; // Vermelho
}

// Buscar limites de CAP da liga
$capMin = 0;
$capMax = 999;
try {
    $stmtCapLimits = $pdo->prepare('SELECT cap_min, cap_max FROM league_settings WHERE league = ?');
    $stmtCapLimits->execute([$team['league']]);
    $capLimits = $stmtCapLimits->fetch();
    if ($capLimits) {
        $capMin = $capLimits['cap_min'] ?? 0;
        $capMax = $capLimits['cap_max'] ?? 999;
    }
} catch (Exception $e) {
    // Tabela league_settings pode não existir ainda
}

// Determinar cor do CAP
$capColor = '#00ff00'; // Verde por padrão
if ($teamCap < $capMin || $teamCap > $capMax) {
    $capColor = '#ff4444'; // Vermelho se fora dos limites
}

// Buscar edital da liga
$editalData = null;
$hasEdital = false;
try {
    $stmtEdital = $pdo->prepare('SELECT edital, edital_file FROM league_settings WHERE league = ?');
    $stmtEdital->execute([$team['league']]);
    $editalData = $stmtEdital->fetch();
    $hasEdital = $editalData && !empty($editalData['edital_file']);
} catch (Exception $e) {
    // Pode ocorrer antes da migração criar a tabela
}

// Buscar temporada atual da liga
// Buscar prazo ativo de diretrizes (somente se ainda não expirou - usando horário de Brasília)
$activeDirectiveDeadline = null;
try {
    // Calcular horário atual de Brasília via PHP para comparação
    $nowBrasilia = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
    
    $stmtDirective = $pdo->prepare("
        SELECT * FROM directive_deadlines 
        WHERE league = ? AND is_active = 1 AND deadline_date > ?
        ORDER BY deadline_date ASC LIMIT 1
    ");
    $stmtDirective->execute([$team['league'], $nowBrasilia]);
    $activeDirectiveDeadline = $stmtDirective->fetch();
        if ($activeDirectiveDeadline && !empty($activeDirectiveDeadline['deadline_date'])) {
            try {
                $deadlineDateTime = new DateTime($activeDirectiveDeadline['deadline_date'], new DateTimeZone('America/Sao_Paulo'));
                $activeDirectiveDeadline['deadline_date_display'] = $deadlineDateTime->format('d/m/Y \à\s H:i');
            } catch (Exception $e) {
                $activeDirectiveDeadline['deadline_date_display'] = date('d/m/Y', strtotime($activeDirectiveDeadline['deadline_date']));
            }
        }
} catch (Exception $e) {
    // Tabela pode não existir ainda
}

$currentSeason = null;
try {
    $stmtSeason = $pdo->prepare("
        SELECT s.season_number, s.year, s.status, sp.sprint_number, sp.start_year
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed'))
        ORDER BY s.created_at DESC 
        LIMIT 1
    ");
    $stmtSeason->execute([$team['league']]);
    $currentSeason = $stmtSeason->fetch();
} catch (Exception $e) {
    // Tabela seasons pode não existir ainda
    $currentSeason = null;
}

// Corrigir exibição do ano no dashboard: usar start_year + season_number - 1 quando disponível
$seasonDisplayYear = null;
if ($currentSeason && isset($currentSeason['start_year']) && isset($currentSeason['season_number'])) {
    $seasonDisplayYear = (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1;
} elseif ($currentSeason && isset($currentSeason['year'])) {
    $seasonDisplayYear = (int)$currentSeason['year'];
}

// Buscar jogadores e picks para cópia
$stmtAllPlayers = $pdo->prepare("SELECT id, name, position, role, ovr, age FROM players WHERE team_id = ? ORDER BY ovr DESC, name ASC");
$stmtAllPlayers->execute([$team['id']]);
$allPlayers = $stmtAllPlayers->fetchAll(PDO::FETCH_ASSOC);

$stmtPicks = $pdo->prepare("
    SELECT p.season_year, p.round, orig.city, orig.name AS team_name
    FROM picks p
    JOIN teams orig ON p.original_team_id = orig.id
    WHERE p.team_id = ?
    ORDER BY p.season_year ASC, p.round ASC
");
$stmtPicks->execute([$team['id']]);
$teamPicks = $stmtPicks->fetchAll(PDO::FETCH_ASSOC);

$stmtTrades = $pdo->prepare("SELECT COUNT(*) FROM trades WHERE status = 'accepted' AND (from_team_id = ? OR to_team_id = ?)");
$stmtTrades->execute([$team['id'], $team['id']]);
$tradesCount = (int)$stmtTrades->fetchColumn();

// Buscar última trade da liga (mais recente)
$lastTrade = null;
try {
    $stmtLastTrade = $pdo->prepare("
        SELECT 
            t.*,
            t1.city as from_city, t1.name as from_name, t1.photo_url as from_photo,
            t2.city as to_city, t2.name as to_name, t2.photo_url as to_photo,
            u1.name as from_owner, u2.name as to_owner
        FROM trades t
        JOIN teams t1 ON t.from_team_id = t1.id
        JOIN teams t2 ON t.to_team_id = t2.id
        LEFT JOIN users u1 ON t1.user_id = u1.id
        LEFT JOIN users u2 ON t2.user_id = u2.id
        WHERE t.status = 'accepted' AND t1.league = ?
        ORDER BY t.updated_at DESC
        LIMIT 1
    ");
    $stmtLastTrade->execute([$team['league']]);
    $lastTrade = $stmtLastTrade->fetch();
} catch (Exception $e) {
    // Pode falhar se tabela ainda não existe
}

// Limite de trades por temporada (por liga) e verificar se trades estão ativas
$maxTrades = 3;
$tradesEnabled = 1; // Padrão: ativas
try {
    $stmtMaxTrades = $pdo->prepare('SELECT max_trades, trades_enabled FROM league_settings WHERE league = ?');
    $stmtMaxTrades->execute([$team['league']]);
    $rowMax = $stmtMaxTrades->fetch();
    if ($rowMax) {
        if (isset($rowMax['max_trades'])) {
            $maxTrades = (int)$rowMax['max_trades'];
        }
        if (isset($rowMax['trades_enabled'])) {
            $tradesEnabled = (int)$rowMax['trades_enabled'];
        }
    }
} catch (Exception $e) {
    // Tabela league_settings pode não existir ainda ou coluna trades_enabled não migrada
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/img/icon-192x192.png">
    <title>Dashboard - FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css" />
    <style>
        .hover-lift {
            transition: all 0.3s ease;
        }

        .hover-lift:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(255, 107, 0, 0.3);
        }

        .quick-action-card {
            background: var(--card-bg);
            border: 1px solid rgba(255, 107, 0, 0.3);
            border-radius: 12px;
            padding: 1rem;
            transition: all 0.3s ease;
        }

        .quick-action-card:hover {
            border-color: var(--fba-orange);
            box-shadow: 0 4px 12px rgba(255, 107, 0, 0.2);
            transform: translateY(-3px);
        }

        .quick-action-card.urgent {
            border-color: #dc3545;
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.1), transparent);
        }

        .quick-action-card.urgent:hover {
            border-color: #dc3545;
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.2), transparent);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .pick-item {
            transition: all 0.2s ease;
        }

        .pick-item:hover {
            background: rgba(255, 107, 0, 0.1) !important;
        }

        .last-trade-card {
            background: linear-gradient(135deg, rgba(255, 107, 0, 0.05), transparent);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px dashed rgba(255, 107, 0, 0.3);
        }

        @media (max-width: 768px) {
            .stat-value {
                font-size: 1.5rem;
            }

            .quick-action-card .display-1 {
                font-size: 2.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Botão Hamburguer para Mobile -->
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="bi bi-list fs-4"></i>
    </button>
    
    <!-- Overlay para fechar sidebar no mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

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
                <a href="/dashboard.php" class="active">
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
        <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h1 class="text-white fw-bold mb-2">Dashboard</h1>
                <p class="text-light-gray">Bem-vindo ao painel de controle do <?= htmlspecialchars($team['name']) ?></p>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <button class="btn btn-outline-light" id="copyTeamBtn">
                    <i class="bi bi-clipboard-check me-2"></i>Copiar time
                </button>
                <span class="badge bg-success" style="font-size: 1rem; padding: 0.6rem 1rem;">
                    <i class="bi bi-star-fill me-1"></i>
                    <?= (int)($team['ranking_points'] ?? 0) ?> pts
                </span>
                <span class="badge bg-warning text-dark" style="font-size: 1rem; padding: 0.6rem 1rem;">
                    <i class="bi bi-coin me-1"></i>
                    <?= (int)($team['moedas'] ?? 0) ?> moedas
                </span>
                <?php if ($currentSeason): ?>
                <span class="badge bg-gradient-orange" style="font-size: 1.1rem; padding: 0.75rem 1.5rem;">
                    <i class="bi bi-calendar3 me-2"></i>
                    Temporada <?= (int)$seasonDisplayYear ?>
                </span>
                <?php else: ?>
                <span class="badge bg-secondary" style="font-size: 1rem; padding: 0.5rem 1rem;">
                    <i class="bi bi-calendar-x me-2"></i>
                    Nenhuma temporada ativa
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <a href="/my-roster.php" class="text-decoration-none">
                    <div class="stat-card hover-lift">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="stat-label">Jogadores</div>
                                <div class="stat-value"><?= $totalPlayers ?>/15</div>
                                <small class="text-light-gray">OVR Médio: <?= $avgOvr ?></small>
                            </div>
                            <i class="bi bi-people-fill display-4 text-orange"></i>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">CAP Top8</div>
                            <div class="stat-value" style="color: <?= $capColor ?>;"><?= $teamCap ?></div>
                            <small class="text-light-gray">Limite: <?= $capMin ?>-<?= $capMax ?></small>
                        </div>
                        <i class="bi bi-cash-stack display-4" style="color: <?= $capColor ?>;"></i>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <a href="/picks.php" class="text-decoration-none">
                    <div class="stat-card hover-lift">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="stat-label">Picks</div>
                                <div class="stat-value"><?= count($teamPicks) ?></div>
                                <small class="text-light-gray">Próximas escolhas</small>
                            </div>
                            <i class="bi bi-calendar-check-fill display-4 text-success"></i>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-3">
                <a href="/trades.php" class="text-decoration-none">
                    <div class="stat-card hover-lift">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="stat-label">Trades</div>
                                <div class="stat-value"><?= $tradesCount ?>/<?= $maxTrades ?></div>
                                <small class="text-light-gray">Realizadas</small>
                            </div>
                            <i class="bi bi-arrow-left-right display-4 text-info"></i>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Ações Rápidas -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-header bg-transparent border-orange">
                        <h4 class="mb-0 text-white">
                            <i class="bi bi-lightning-fill me-2 text-orange"></i>Ações Rápidas
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php if ($activeDirectiveDeadline): ?>
                            <div class="col-md-6">
                                <a href="/diretrizes.php" class="text-decoration-none">
                                    <div class="quick-action-card urgent">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <h5 class="text-white mb-2">
                                                    <i class="bi bi-clipboard-check text-danger me-2"></i>
                                                    Enviar Rotação
                                                </h5>
                                                <p class="text-light-gray mb-1"><?= htmlspecialchars($activeDirectiveDeadline['description'] ?? 'Diretrizes de jogo') ?></p>
                                                <p class="text-danger mb-0 fw-bold">
                                                    <i class="bi bi-clock-fill me-1"></i>
                                                    Prazo: <?= htmlspecialchars($activeDirectiveDeadline['deadline_date_display'] ?? '') ?>
                                                </p>
                                            </div>
                                            <i class="bi bi-arrow-right-circle-fill text-danger" style="font-size: 3rem;"></i>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-md-<?= $activeDirectiveDeadline ? '6' : '4' ?>">
                                <a href="/trades.php" class="text-decoration-none">
                                    <div class="quick-action-card">
                                        <div class="text-center py-3">
                                            <i class="bi bi-arrow-left-right display-1 text-orange mb-2"></i>
                                            <h5 class="text-white mb-1">Propor Trade</h5>
                                            <p class="text-light-gray small mb-0">Negocie jogadores e picks</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            
                            <div class="col-md-<?= $activeDirectiveDeadline ? '6' : '4' ?>">
                                <a href="/teams.php" class="text-decoration-none">
                                    <div class="quick-action-card">
                                        <div class="text-center py-3">
                                            <i class="bi bi-search display-1 text-info mb-2"></i>
                                            <h5 class="text-white mb-1">Explorar Times</h5>
                                            <p class="text-light-gray small mb-0">Veja elencos de outros times</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            
                            <div class="col-md-<?= $activeDirectiveDeadline ? '6' : '4' ?>">
                                <a href="/rankings.php" class="text-decoration-none">
                                    <div class="quick-action-card">
                                        <div class="text-center py-3">
                                            <i class="bi bi-trophy-fill display-1 text-warning mb-2"></i>
                                            <h5 class="text-white mb-1">Rankings</h5>
                                            <p class="text-light-gray small mb-0">Veja sua posição</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Informações da Liga -->
        <div class="row g-4 mb-4">
            <!-- Liga Info -->
            <div class="col-md-6">
                <div class="card bg-dark-panel border-orange h-100">
                    <div class="card-header bg-transparent border-orange">
                        <h4 class="mb-0 text-white">
                            <i class="bi bi-shield-fill me-2 text-orange"></i>Informações da Liga
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <img src="/img/logo-<?= strtolower($user['league']) ?>.png" 
                                 alt="<?= htmlspecialchars($user['league']) ?>" 
                                 class="league-logo mb-3" 
                                 style="height: 120px; width: auto; object-fit: contain;">
                            <h3 class="text-orange mb-3"><?= htmlspecialchars($user['league']) ?></h3>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-dark rounded">
                            <span class="text-light-gray">Ranking Global:</span>
                            <span class="text-white fw-bold"><?= (int)($team['ranking_points'] ?? 0) ?> pontos</span>
                        </div>
                        
                        <?php if ($currentSeason): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-dark rounded">
                            <span class="text-light-gray">Temporada:</span>
                            <span class="text-white fw-bold"><?= (int)$seasonDisplayYear ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-dark rounded">
                            <span class="text-light-gray">Sprint:</span>
                            <span class="text-white fw-bold"><?= (int)($currentSeason['sprint_number'] ?? 1) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-between align-items-center p-2 bg-dark rounded">
                            <span class="text-light-gray">CAP Permitido:</span>
                            <span class="text-white fw-bold"><?= $capMin ?> - <?= $capMax ?></span>
                        </div>
                        
                        <?php if ($hasEdital): ?>
                        <div class="mt-3">
                            <a href="/api/edital.php?action=download_edital&league=<?= urlencode($team['league']) ?>" 
                               class="btn btn-orange w-100" download>
                                <i class="bi bi-download me-2"></i>Baixar Edital da Liga
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Próximas Picks -->
            <div class="col-md-6">
                <div class="card bg-dark-panel border-orange h-100">
                    <div class="card-header bg-transparent border-orange d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 text-white">
                            <i class="bi bi-calendar-check me-2 text-orange"></i>Próximas Picks
                        </h4>
                        <a href="/picks.php" class="btn btn-sm btn-outline-orange">Ver Todas</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($teamPicks) > 0): ?>
                            <div class="picks-list">
                                <?php 
                                $displayedPicks = array_slice($teamPicks, 0, 5);
                                foreach ($displayedPicks as $pick): 
                                ?>
                                    <div class="pick-item d-flex justify-content-between align-items-center mb-2 p-2 bg-dark rounded">
                                        <div>
                                            <span class="badge bg-orange me-2"><?= htmlspecialchars($pick['season_year']) ?></span>
                                            <span class="badge bg-secondary"><?= $pick['round'] ?>ª Rodada</span>
                                        </div>
                                        <small class="text-light-gray">
                                            <?= htmlspecialchars($pick['city'] . ' ' . $pick['team_name']) ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($teamPicks) > 5): ?>
                                    <div class="text-center mt-2">
                                        <small class="text-light-gray">+ <?= count($teamPicks) - 5 ?> picks</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-light-gray py-4">
                                <i class="bi bi-calendar-x display-4"></i>
                                <p class="mt-3 mb-0">Nenhuma pick disponível</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Última Trade ou Status -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-header bg-transparent border-orange">
                        <h4 class="mb-0 text-white">
                            <i class="bi bi-arrow-left-right me-2 text-orange"></i>Trades na Liga
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if ($tradesEnabled == 0): ?>
                            <!-- Trades Desativadas -->
                            <div class="alert alert-danger border-danger mb-0" role="alert">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <h5 class="alert-heading mb-2">
                                            <i class="bi bi-x-circle-fill me-2"></i>Trades Desativadas
                                        </h5>
                                        <p class="mb-0">O administrador bloqueou temporariamente as trocas nesta liga. Você não pode propor ou aceitar trades no momento.</p>
                                    </div>
                                    <i class="bi bi-lock-fill" style="font-size: 3rem; opacity: 0.5;"></i>
                                </div>
                            </div>
                        <?php elseif ($lastTrade): ?>
                            <!-- Última Trade Realizada -->
                            <div class="last-trade-card">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                                    <!-- Time 1 -->
                                    <div class="text-center flex-shrink-0" style="min-width: 150px;">
                                        <img src="<?= htmlspecialchars($lastTrade['from_photo'] ?? '/img/default-team.png') ?>" 
                                             alt="<?= htmlspecialchars($lastTrade['from_city'] . ' ' . $lastTrade['from_name']) ?>"
                                             class="rounded-circle mb-2" 
                                             style="width: 60px; height: 60px; object-fit: cover; border: 2px solid var(--fba-orange);">
                                        <h6 class="text-white mb-1"><?= htmlspecialchars($lastTrade['from_city'] . ' ' . $lastTrade['from_name']) ?></h6>
                                        <small class="text-light-gray"><?= htmlspecialchars($lastTrade['from_owner'] ?? '') ?></small>
                                    </div>
                                    
                                    <!-- Seta -->
                                    <div class="text-center flex-shrink-0">
                                        <i class="bi bi-arrow-left-right text-orange" style="font-size: 2.5rem;"></i>
                                        <div class="mt-2">
                                            <small class="text-light-gray">
                                                <?php
                                                if (!empty($lastTrade['updated_at'])) {
                                                    $tradeDate = new DateTime($lastTrade['updated_at']);
                                                    $now = new DateTime();
                                                    $diff = $now->diff($tradeDate);
                                                    
                                                    if ($diff->days == 0) {
                                                        echo "Hoje";
                                                    } elseif ($diff->days == 1) {
                                                        echo "Ontem";
                                                    } elseif ($diff->days < 7) {
                                                        echo $diff->days . " dias atrás";
                                                    } else {
                                                        echo $tradeDate->format('d/m/Y');
                                                    }
                                                }
                                                ?>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <!-- Time 2 -->
                                    <div class="text-center flex-shrink-0" style="min-width: 150px;">
                                        <img src="<?= htmlspecialchars($lastTrade['to_photo'] ?? '/img/default-team.png') ?>" 
                                             alt="<?= htmlspecialchars($lastTrade['to_city'] . ' ' . $lastTrade['to_name']) ?>"
                                             class="rounded-circle mb-2" 
                                             style="width: 60px; height: 60px; object-fit: cover; border: 2px solid var(--fba-orange);">
                                        <h6 class="text-white mb-1"><?= htmlspecialchars($lastTrade['to_city'] . ' ' . $lastTrade['to_name']) ?></h6>
                                        <small class="text-light-gray"><?= htmlspecialchars($lastTrade['to_owner'] ?? '') ?></small>
                                    </div>
                                    
                                    <!-- Botão Ver Detalhes -->
                                    <div class="flex-shrink-0 ms-auto">
                                        <a href="/trades.php" class="btn btn-orange">
                                            <i class="bi bi-eye me-1"></i>Ver Todas as Trades
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Nenhuma trade ainda -->
                            <div class="text-center text-light-gray py-4">
                                <i class="bi bi-arrow-left-right display-4"></i>
                                <p class="mt-3 mb-1 text-white fw-bold">Nenhuma trade realizada ainda</p>
                                <p class="mb-0">Seja o primeiro a fazer uma troca na liga <?= htmlspecialchars($team['league']) ?>!</p>
                                <a href="/trades.php" class="btn btn-orange mt-3">
                                    <i class="bi bi-plus-circle me-1"></i>Propor Trade
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quinteto Titular -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-header bg-transparent border-orange d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 text-white">
                            <i class="bi bi-trophy me-2 text-orange"></i>Quinteto Titular
                        </h4>
                        <button class="btn btn-outline-orange btn-sm" onclick="window.location.href='/my-roster.php'">
                            <i class="bi bi-plus-circle me-1"></i>Gerenciar Elenco
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (count($titulares) > 0): ?>
                            <div class="row g-3 justify-content-center">
                                <?php foreach ($titulares as $player): ?>
                                    <div class="col-md-2">
                                        <div class="card bg-dark text-white h-100">
                                            <div class="card-body text-center p-3">
                                                <span class="badge bg-orange mb-2"><?= htmlspecialchars($player['position']) ?></span>
                                                <h6 class="mb-1"><?= htmlspecialchars($player['name']) ?></h6>
                                                <p class="mb-0 text-light-gray small">OVR: <?= $player['ovr'] ?></p>
                                                <p class="mb-0 text-light-gray small"><?= $player['age'] ?> anos</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-light-gray py-5">
                                <i class="bi bi-exclamation-circle display-1"></i>
                                <p class="mt-3">Você ainda não tem jogadores titulares.</p>
                                <a href="/my-roster.php" class="btn btn-orange">
                                    <i class="bi bi-plus-circle me-2"></i>Adicionar Jogadores
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/sidebar.js"></script>
    <script src="/js/pwa.js"></script>
    <script>
        // Hover effect para eventos do calendário
        document.querySelectorAll('.calendar-event').forEach(el => {
            el.addEventListener('mouseenter', function() {
                if (!this.style.opacity || this.style.opacity === '1') {
                    this.style.background = 'rgba(255, 112, 67, 0.1)';
                }
            });
            el.addEventListener('mouseleave', function() {
                if (!this.style.opacity || this.style.opacity === '1') {
                    this.style.background = 'transparent';
                }
            });
        });

        const rosterData = <?= json_encode($allPlayers) ?>;
        const picksData = <?= json_encode($teamPicks) ?>;
        const teamMeta = {
            name: <?= json_encode($team['city'] . ' ' . $team['name']) ?>,
            city: <?= json_encode($team['city']) ?>,
            teamName: <?= json_encode($team['name']) ?>,
            userName: <?= json_encode($user['name']) ?>,
            cap: <?= (int)$teamCap ?>,
            capMin: <?= (int)$capMin ?>,
            capMax: <?= (int)$capMax ?>,
            trades: <?= (int)$tradesCount ?>,
            maxTrades: <?= (int)$maxTrades ?>
        };

        function buildTeamSummary() {
            const positions = ['PG','SG','SF','PF','C'];
            const startersMap = {};
            positions.forEach(pos => startersMap[pos] = null);

            const formatAge = (age) => (Number.isFinite(age) && age > 0) ? `${age}y` : '-';

            const formatLine = (label, player) => {
                if (!player) return `${label}: -`;
                const ovr = player.ovr ?? '-';
                const age = player.age ?? '-';
                return `${label}: ${player.name} - ${ovr} | ${formatAge(age)}`;
            };

            const starters = rosterData.filter(p => p.role === 'Titular');
            // Preencher mapa por posição
            starters.forEach(p => {
                if (positions.includes(p.position) && !startersMap[p.position]) {
                    startersMap[p.position] = p;
                }
            });

            const benchPlayers = rosterData.filter(p => p.role === 'Banco');
            const othersPlayers = rosterData.filter(p => p.role === 'Outro');
            const gleaguePlayers = rosterData.filter(p => (p.role || '').toLowerCase() === 'g-league');

            // Picks por round
            const round1Years = picksData.filter(pk => pk.round == 1).map(pk => {
                const isOwn = (pk.city === teamMeta.city && pk.team_name === teamMeta.teamName);
                return `-${pk.season_year}${isOwn ? '' : ` (via ${pk.city} ${pk.team_name})`} `;
            });
            const round2Years = picksData.filter(pk => pk.round == 2).map(pk => {
                const isOwn = (pk.city === teamMeta.city && pk.team_name === teamMeta.teamName);
                return `-${pk.season_year}${isOwn ? '' : ` (via ${pk.city} ${pk.team_name})`} `;
            });

            const lines = [];
            lines.push(`*${teamMeta.name}*`);
            lines.push(teamMeta.userName);
            lines.push('');
            lines.push('_Starters_');
            positions.forEach(pos => {
                lines.push(formatLine(pos, startersMap[pos]));
            });
            lines.push('');
            lines.push('_Bench_');
            if (benchPlayers.length) {
                benchPlayers.forEach(p => {
                    const ovr = p.ovr ?? '-';
                    const age = p.age ?? '-';
                    lines.push(`${p.position}: ${p.name} - ${ovr} | ${formatAge(age)}`);
                });
            } else {
                lines.push('-');
            }
            lines.push('');
            lines.push('_Others_');
            if (othersPlayers.length) {
                othersPlayers.forEach(p => {
                    const ovr = p.ovr ?? '-';
                    const age = p.age ?? '-';
                    lines.push(`${p.name} - ${ovr} | ${formatAge(age)}`);
                });
            } else {
                lines.push('-');
            }
            lines.push('');
            lines.push('_G-League_');
            if (gleaguePlayers.length) {
                gleaguePlayers.forEach(p => {
                    const ovr = p.ovr ?? '-';
                    const age = p.age ?? '-';
                    lines.push(`${p.name} - ${ovr} | ${formatAge(age)}`);
                });
            } else {
                lines.push('-');
            }
            lines.push('');
            lines.push('_Picks 1º round_:');
            lines.push(...(round1Years.length ? round1Years : ['-']));
            lines.push('');
            lines.push('_Picks 2º round_:');
            lines.push(...(round2Years.length ? round2Years : ['-']));
            lines.push('');
            lines.push(`_CAP_: ${teamMeta.capMin} / *${teamMeta.cap}* / ${teamMeta.capMax}`);
            lines.push(`_Trades_: ${teamMeta.trades} / ${teamMeta.maxTrades}`);

            return lines.join('\n');
        }

        document.getElementById('copyTeamBtn')?.addEventListener('click', async () => {
            const text = buildTeamSummary();
            try {
                await navigator.clipboard.writeText(text);
                alert('Time copiado para a área de transferência!');
            } catch (err) {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                alert('Time copiado para a área de transferência!');
            }
        });
    </script>
</body>
</html>
