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
$minPlayers = 13;
$maxPlayers = 15;
$playersOutOfRange = $totalPlayers < $minPlayers || $totalPlayers > $maxPlayers;
$playersColor = $playersOutOfRange ? '#ff4444' : 'inherit';

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

// Buscar última diretriz enviada pelo time
$lastDirective = null;
$lastDirectiveStarters = [];
$lastDirectiveBench = [];
try {
    if (!empty($team['id'])) {
        $stmtLastDir = $pdo->prepare("SELECT td.*, dd.description, dd.deadline_date
            FROM team_directives td
            INNER JOIN directive_deadlines dd ON dd.id = td.deadline_id
            WHERE td.team_id = ?
            ORDER BY td.submitted_at DESC
            LIMIT 1");
        $stmtLastDir->execute([(int)$team['id']]);
        $lastDirective = $stmtLastDir->fetch(PDO::FETCH_ASSOC);

        if ($lastDirective) {
            $ids = [];
            foreach (['starter_1_id','starter_2_id','starter_3_id','starter_4_id','starter_5_id','bench_1_id','bench_2_id','bench_3_id'] as $key) {
                if (!empty($lastDirective[$key])) $ids[] = (int)$lastDirective[$key];
            }
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmtPlayers = $pdo->prepare("SELECT id, name, position, ovr FROM players WHERE id IN ($in)");
                $stmtPlayers->execute($ids);
                $pmap = [];
                while ($r = $stmtPlayers->fetch(PDO::FETCH_ASSOC)) { $pmap[(int)$r['id']] = $r; }

                for ($i=1; $i<=5; $i++) {
                    $pid = (int)($lastDirective['starter_'.$i.'_id'] ?? 0);
                    if ($pid && isset($pmap[$pid])) {
                        $p = $pmap[$pid];
                        $lastDirectiveStarters[] = $p['name'] . ' (' . $p['position'] . ' · ' . $p['ovr'] . ')';
                    }
                }
                for ($i=1; $i<=3; $i++) {
                    $pid = (int)($lastDirective['bench_'.$i.'_id'] ?? 0);
                    if ($pid && isset($pmap[$pid])) {
                        $p = $pmap[$pid];
                        $lastDirectiveBench[] = $p['name'] . ' (' . $p['position'] . ' · ' . $p['ovr'] . ')';
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    // Tabelas podem não existir ainda
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
    SELECT p.season_year, p.round, 
           orig.city, orig.name AS team_name,
           p.original_team_id, p.team_id
    FROM picks p
    JOIN teams orig ON p.original_team_id = orig.id
    WHERE p.team_id = ?
    ORDER BY p.season_year ASC, p.round ASC
");
$stmtPicks->execute([$team['id']]);
$teamPicks = $stmtPicks->fetchAll(PDO::FETCH_ASSOC);
$teamPicksForCopy = $teamPicks;
$copySeasonYear = !empty($seasonDisplayYear) ? (int)$seasonDisplayYear : (int)date('Y');
$teamPicksForCopy = array_values(array_filter($teamPicks, function ($pick) use ($copySeasonYear) {
    return (int)($pick['season_year'] ?? 0) > $copySeasonYear;
}));

// Contador de trades por time (novo modelo)
function syncTeamTradeCounterDashboard(PDO $pdo, int $teamId): int
{
    try {
        $stmt = $pdo->prepare('SELECT current_cycle, trades_cycle, trades_used FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0;
        }

        $currentCycle = (int)($row['current_cycle'] ?? 0);
        $tradesCycle = (int)($row['trades_cycle'] ?? 0);
        $tradesUsed = (int)($row['trades_used'] ?? 0);

        if ($currentCycle > 0 && $tradesCycle !== $currentCycle) {
            $pdo->prepare('UPDATE teams SET trades_used = 0, trades_cycle = ? WHERE id = ?')
                ->execute([$currentCycle, $teamId]);
            return 0;
        }

        if ($currentCycle > 0 && $tradesCycle <= 0) {
            $pdo->prepare('UPDATE teams SET trades_cycle = ? WHERE id = ?')
                ->execute([$currentCycle, $teamId]);
        }

        return $tradesUsed;
    } catch (Exception $e) {
        return 0;
    }
}

$tradesCount = syncTeamTradeCounterDashboard($pdo, (int)$team['id']);

// Buscar última trade da liga (mais recente)
$lastTrade = null;
$lastTradeFromPlayers = [];
$lastTradeToPlayers = [];
$lastTradeFromPicks = [];
$lastTradeToPicks = [];
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
    
    if ($lastTrade) {
        // Buscar jogadores oferecidos (FROM team)
        $stmtFromPlayers = $pdo->prepare('
            SELECT p.name, p.position, p.ovr 
            FROM players p
            JOIN trade_items ti ON p.id = ti.player_id
            WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.player_id IS NOT NULL
        ');
        $stmtFromPlayers->execute([$lastTrade['id']]);
        $lastTradeFromPlayers = $stmtFromPlayers->fetchAll();
        
        // Buscar picks oferecidas (FROM team)
        $stmtFromPicks = $pdo->prepare('
            SELECT pk.season_year, pk.round 
            FROM picks pk
            JOIN trade_items ti ON pk.id = ti.pick_id
            WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.pick_id IS NOT NULL
        ');
        $stmtFromPicks->execute([$lastTrade['id']]);
        $lastTradeFromPicks = $stmtFromPicks->fetchAll();
        
        // Buscar jogadores pedidos (TO team)
        $stmtToPlayers = $pdo->prepare('
            SELECT p.name, p.position, p.ovr 
            FROM players p
            JOIN trade_items ti ON p.id = ti.player_id
            WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.player_id IS NOT NULL
        ');
        $stmtToPlayers->execute([$lastTrade['id']]);
        $lastTradeToPlayers = $stmtToPlayers->fetchAll();
        
        // Buscar picks pedidas (TO team)
        $stmtToPicks = $pdo->prepare('
            SELECT pk.season_year, pk.round 
            FROM picks pk
            JOIN trade_items ti ON pk.id = ti.pick_id
            WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.pick_id IS NOT NULL
        ');
        $stmtToPicks->execute([$lastTrade['id']]);
        $lastTradeToPicks = $stmtToPicks->fetchAll();
    }
} catch (Exception $e) {
    // Debug: descomentar para ver erro
    error_log("Erro ao buscar última trade: " . $e->getMessage());
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

// Buscar draft inicial ativo e próximas picks
$activeInitDraftSession = null;
$currentDraftPick = null;
$nextDraftPick = null;
$remainingDraftPicks = 0;
$initDraftTeamsPerRound = 0;
try {
    $stmtInitSession = $pdo->prepare("SELECT * FROM initdraft_sessions WHERE league = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
    $stmtInitSession->execute([$team['league']]);
    $activeInitDraftSession = $stmtInitSession->fetch(PDO::FETCH_ASSOC);

    if ($activeInitDraftSession) {
        $sessionId = (int)$activeInitDraftSession['id'];

        $stmtCurrentPick = $pdo->prepare("
            SELECT io.*, t.city, t.name AS team_name, t.photo_url, u.name AS owner_name
            FROM initdraft_order io
            JOIN teams t ON io.team_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE io.initdraft_session_id = ?
              AND io.picked_player_id IS NULL
            ORDER BY io.round ASC, io.pick_position ASC
            LIMIT 1
        ");
        $stmtCurrentPick->execute([$sessionId]);
        $currentDraftPick = $stmtCurrentPick->fetch(PDO::FETCH_ASSOC);

        if ($currentDraftPick) {
            $stmtNextPick = $pdo->prepare("
                SELECT io.*, t.city, t.name AS team_name, t.photo_url
                FROM initdraft_order io
                JOIN teams t ON io.team_id = t.id
                WHERE io.initdraft_session_id = ?
                  AND io.picked_player_id IS NULL
                ORDER BY io.round ASC, io.pick_position ASC
                LIMIT 1 OFFSET 1
            ");
            $stmtNextPick->execute([$sessionId]);
            $nextDraftPick = $stmtNextPick->fetch(PDO::FETCH_ASSOC);

            $stmtRemaining = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND picked_player_id IS NULL');
            $stmtRemaining->execute([$sessionId]);
            $remainingDraftPicks = (int)$stmtRemaining->fetchColumn();

            $stmtTeamsPerRound = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = 1');
            $stmtTeamsPerRound->execute([$sessionId]);
            $initDraftTeamsPerRound = (int)$stmtTeamsPerRound->fetchColumn();
        }
    }
} catch (Exception $e) {
    // Silencioso
}
$activeDraft = $activeInitDraftSession && $currentDraftPick;
$currentDraftOverallNumber = null;
$nextDraftOverallNumber = null;
if ($currentDraftPick && $initDraftTeamsPerRound > 0) {
    $currentDraftOverallNumber = (($currentDraftPick['round'] - 1) * $initDraftTeamsPerRound) + $currentDraftPick['pick_position'];
}
if ($nextDraftPick && $initDraftTeamsPerRound > 0) {
    $nextDraftOverallNumber = (($nextDraftPick['round'] - 1) * $initDraftTeamsPerRound) + $nextDraftPick['pick_position'];
}

// Buscar Top 5 do Ranking
$topRanking = [];
try {
    $stmtTopRanking = $pdo->prepare("
        SELECT t.id, t.city, t.name, t.photo_url, t.ranking_points, u.name as owner_name
        FROM teams t
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.league = ?
        ORDER BY t.ranking_points DESC
        LIMIT 5
    ");
    $stmtTopRanking->execute([$team['league']]);
    $topRanking = $stmtTopRanking->fetchAll();
} catch (Exception $e) {
    // Pode falhar
}

// Buscar último campeão, vice e MVP
$lastChampion = null;
$lastRunnerUp = null;
$lastMVP = null;
$lastSprintInfo = null;
try {
    // Buscar o último registro de season_history (onde ficam os vencedores)
    $stmtLastSprint = $pdo->prepare("
        SELECT sh.*, 
               t1.id as champion_id, t1.city as champion_city, t1.name as champion_name, 
               t1.photo_url as champion_photo, u1.name as champion_owner,
               t2.id as runner_up_id, t2.city as runner_up_city, t2.name as runner_up_name,
               t2.photo_url as runner_up_photo, u2.name as runner_up_owner
        FROM season_history sh
        LEFT JOIN teams t1 ON sh.champion_team_id = t1.id
        LEFT JOIN users u1 ON t1.user_id = u1.id
        LEFT JOIN teams t2 ON sh.runner_up_team_id = t2.id
        LEFT JOIN users u2 ON t2.user_id = u2.id
        WHERE sh.league = ?
        ORDER BY sh.id DESC
        LIMIT 1
    ");
    $stmtLastSprint->execute([$team['league']]);
    $lastSprintInfo = $stmtLastSprint->fetch();
    
    if ($lastSprintInfo) {
        // Montar dados do campeão
        if ($lastSprintInfo['champion_id']) {
            $lastChampion = [
                'id' => $lastSprintInfo['champion_id'],
                'city' => $lastSprintInfo['champion_city'],
                'name' => $lastSprintInfo['champion_name'],
                'photo_url' => $lastSprintInfo['champion_photo'],
                'owner_name' => $lastSprintInfo['champion_owner']
            ];
        }
        
        // Montar dados do vice
        if ($lastSprintInfo['runner_up_id']) {
            $lastRunnerUp = [
                'id' => $lastSprintInfo['runner_up_id'],
                'city' => $lastSprintInfo['runner_up_city'],
                'name' => $lastSprintInfo['runner_up_name'],
                'photo_url' => $lastSprintInfo['runner_up_photo'],
                'owner_name' => $lastSprintInfo['runner_up_owner']
            ];
        }
        
        // Montar dados do MVP (nome do jogador está diretamente na tabela)
        if (!empty($lastSprintInfo['mvp_player'])) {
            $lastMVP = [
                'name' => $lastSprintInfo['mvp_player'],
                'position' => null, // Não temos essa info na season_history
                'ovr' => null,
                'team_city' => null,
                'team_name' => null
            ];
            
            // Tentar buscar time do MVP se tiver mvp_team_id
            if (!empty($lastSprintInfo['mvp_team_id'])) {
                $stmtMvpTeam = $pdo->prepare("SELECT city, name FROM teams WHERE id = ?");
                $stmtMvpTeam->execute([$lastSprintInfo['mvp_team_id']]);
                $mvpTeam = $stmtMvpTeam->fetch();
                if ($mvpTeam) {
                    $lastMVP['team_city'] = $mvpTeam['city'];
                    $lastMVP['team_name'] = $mvpTeam['name'];
                }
            }
        }
    }
} catch (Exception $e) {
    // Pode falhar se tabela não existir
    // Debug: descomentar linha abaixo para ver erro
    // error_log("Erro ao buscar vencedores: " . $e->getMessage());
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
    <link rel="manifest" href="/manifest.json?v=3">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
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

        .ranking-item {
            transition: all 0.2s ease;
        }

        .ranking-item:hover {
            transform: translateX(5px);
        }

        .winner-item {
            transition: all 0.2s ease;
        }

        .winner-item:hover {
            transform: scale(1.02);
        }

        .draft-live-card {
            background: linear-gradient(135deg, rgba(255, 107, 0, 0.15), rgba(0, 0, 0, 0.6));
            border: 1px solid rgba(255, 107, 0, 0.4);
            border-radius: 16px;
            padding: 1.5rem;
        }

        .draft-live-card .on-clock-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--fba-orange);
        }

        .draft-live-card .draft-meta {
            font-size: 0.9rem;
            color: var(--light-gray);
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
                <a href="/leilao.php">
                    <i class="bi bi-hammer"></i>
                    Leilão
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
        <div class="page-header mb-4">
            <div>
                <h1 class="text-white fw-bold mb-2">Dashboard</h1>
                <p class="text-light-gray">Bem-vindo ao painel de controle do <?= htmlspecialchars($team['name']) ?></p>
            </div>
            <div class="page-actions">
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
                                <div class="stat-value" style="color: <?= $playersColor ?>;">
                                    <?= $totalPlayers ?>
                                </div>
                                <small class="text-light-gray" style="font-size: clamp(13px, 1.1vw, 15px); color: <?= $playersColor ?>;">
                                    Min: <?= $minPlayers ?> · Max: <?= $maxPlayers ?>
                                </small>
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
                            <small class="text-light-gray" style="font-size: clamp(13px, 1.1vw, 15px); color: <?= $capColor ?>;">
                                Min: <?= $capMin ?> · Max: <?= $capMax ?>
                            </small>
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

        <?php if ($activeDirectiveDeadline): ?>
        <div class="row g-4 mb-4">
            <div class="col-12">
                <a href="/diretrizes.php" class="text-decoration-none">
                    <div class="card bg-dark-panel border-orange">
                        <div class="card-header bg-transparent border-orange">
                            <h4 class="mb-0 text-white">
                                <i class="bi bi-clipboard-check text-orange me-2"></i>Envio de Rotações
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3">
                                <div>
                                    <p class="text-light-gray mb-1"><?= htmlspecialchars($activeDirectiveDeadline['description'] ?? 'Diretrizes de jogo') ?></p>
                                    <p class="text-danger mb-0 fw-bold">
                                        <i class="bi bi-clock-fill me-1"></i>
                                        Prazo: <?= htmlspecialchars($activeDirectiveDeadline['deadline_date_display'] ?? '') ?>
                                    </p>
                                </div>
                                <span class="btn btn-outline-orange">
                                    <i class="bi bi-arrow-right-circle me-2"></i>Enviar Rotação
                                </span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        </div>
        <?php endif; ?>

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
                            <div class="col-md-4">
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
                            
                            <div class="col-md-4">
                                <a href="/players.php" class="text-decoration-none">
                                    <div class="quick-action-card">
                                        <div class="text-center py-3">
                                            <i class="bi bi-search display-1 text-success mb-2"></i>
                                            <h5 class="text-white mb-1">Buscar Jogadores</h5>
                                            <p class="text-light-gray small mb-0">Encontre novos talentos</p>
                                        </div>
                                    </div>
                                </a>
                            </div>

                            <div class="col-md-4">
                                <a href="https://games.fbabrasil.com.br/auth/login.php" class="text-decoration-none" target="_blank" rel="noopener">
                                    <div class="quick-action-card">
                                        <div class="text-center py-3">
                                            <i class="bi bi-controller display-1 text-warning mb-2"></i>
                                            <h5 class="text-white mb-1">FBA GAMES</h5>
                                            <p class="text-light-gray small mb-0">Acesse os mini jogos</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($activeDraft && $currentDraftPick): ?>
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="draft-live-card d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <img src="<?= htmlspecialchars($currentDraftPick['photo_url'] ?? '/img/default-team.png') ?>" alt="<?= htmlspecialchars($currentDraftPick['city'] . ' ' . $currentDraftPick['team_name']) ?>" class="on-clock-avatar">
                        <div>
                            <p class="text-uppercase text-light-gray mb-1 small">Na vez agora</p>
                            <h4 class="text-white mb-0"><?= htmlspecialchars($currentDraftPick['city'] . ' ' . $currentDraftPick['team_name']) ?></h4>
                            <div class="draft-meta">
                                <?php if ($currentDraftOverallNumber): ?>Pick geral #<?= (int)$currentDraftOverallNumber ?> · <?php endif; ?>
                                Rodada <?= (int)$currentDraftPick['round'] ?> · Pick <?= (int)$currentDraftPick['pick_position'] ?>
                                <?php if (!empty($currentDraftPick['owner_name'])): ?>
                                    · Manager: <?= htmlspecialchars($currentDraftPick['owner_name']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="flex-grow-1">
                        <?php if ($nextDraftPick): ?>
                        <p class="text-uppercase text-light-gray mb-1 small">Próxima pick</p>
                        <div class="text-white fw-bold">
                            <?php if ($nextDraftOverallNumber): ?>Pick geral #<?= (int)$nextDraftOverallNumber ?> · <?php endif; ?>
                            R<?= (int)$nextDraftPick['round'] ?> · Pick <?= (int)$nextDraftPick['pick_position'] ?> — <?= htmlspecialchars($nextDraftPick['city'] . ' ' . $nextDraftPick['team_name']) ?>
                        </div>
                        <?php else: ?>
                        <p class="text-light-gray mb-0">Você está acompanhando a última pick desta rodada.</p>
                        <?php endif; ?>
                        <div class="text-light-gray small mt-2"><i class="bi bi-list-ol me-1"></i><?= (int)$remainingDraftPicks ?> picks restantes</div>
                    </div>
                    <div class="text-end d-flex flex-column gap-2">
                        <?php if ($activeInitDraftSession && !empty($activeInitDraftSession['access_token'])): ?>
                        <a href="/initdraftselecao.php?token=<?= htmlspecialchars($activeInitDraftSession['access_token']) ?>" class="btn btn-outline-light">
                            <i class="bi bi-trophy me-2"></i>Abrir sala do draft inicial
                        </a>
                        <?php endif; ?>
                        <?php if (($user['user_type'] ?? '') === 'admin' && $activeInitDraftSession): ?>
                        <button class="btn btn-orange text-dark fw-bold" type="button" onclick="openAdminInitDraftModal()">
                            <i class="bi bi-hand-index-thumb me-2"></i>Escolher como admin
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Draft e Trades (2 na mesma linha) -->
        <div class="row g-4 mb-4">
            <!-- Draft Ativo -->
            <div class="col-md-6">
                <div class="card bg-dark-panel border-orange h-100">
                    <div class="card-header bg-transparent border-orange d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 text-white">
                            <i class="bi bi-trophy me-2 text-orange"></i>Draft
                        </h4>
                        <a href="/drafts.php" class="btn btn-sm btn-outline-orange">Ver Drafts</a>
                    </div>
                    <div class="card-body">
                        <?php if ($activeDraft && $currentDraftPick): ?>
                            <!-- Draft Ativo - Próxima Pick -->
                            <div class="text-center py-4">
                                <p class="text-orange fw-bold mb-4">
                                    <i class="bi bi-alarm me-2"></i>PRÓXIMA PICK
                                </p>
                                <div class="mb-3">
                                    <img src="<?= htmlspecialchars($currentDraftPick['photo_url'] ?? '/img/default-team.png') ?>" 
                                         alt="<?= htmlspecialchars($currentDraftPick['city'] . ' ' . $currentDraftPick['team_name']) ?>"
                                         class="rounded-circle" 
                                         style="width: 80px; height: 80px; object-fit: cover; border: 3px solid var(--fba-orange); display: block; margin: 0 auto;">
                                </div>
                                <h5 class="text-white mb-0"><?= htmlspecialchars($currentDraftPick['city'] . ' ' . $currentDraftPick['team_name']) ?></h5>
                            </div>
                        <?php else: ?>
                            <!-- Sem draft ativo -->
                            <div class="text-center text-light-gray py-4">
                                <i class="bi bi-trophy display-4"></i>
                                <p class="mt-3 mb-0 text-white fw-bold">Sem draft atualmente</p>
                                <small>Aguarde o próximo draft da liga</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Última Trade -->
            <div class="col-md-6">
                <div class="card bg-dark-panel border-orange h-100">
                    <div class="card-header bg-transparent border-orange d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 text-white">
                            <i class="bi bi-arrow-left-right me-2 text-orange"></i>Trades
                        </h4>
                        <a href="/trades.php" class="btn btn-sm btn-outline-orange">Ver Todas</a>
                    </div>
                    <div class="card-body">
                        <?php if ($tradesEnabled == 0): ?>
                            <!-- Trades Desativadas -->
                            <div class="text-center text-danger py-4">
                                <i class="bi bi-x-circle-fill display-4"></i>
                                <p class="mt-3 mb-0 fw-bold">Trades Desativadas</p>
                                <small class="text-light-gray">Bloqueado pelo administrador</small>
                            </div>
                        <?php elseif ($lastTrade): ?>
                            <!-- Última Trade Realizada -->
                            <div class="last-trade-compact">
                                <div class="row align-items-center g-2 mb-3">
                                    <!-- Time 1 -->
                                    <div class="col-5 text-center">
                                        <div class="mb-2">
                                            <img src="<?= htmlspecialchars($lastTrade['from_photo'] ?? '/img/default-team.png') ?>" 
                                                 alt="<?= htmlspecialchars($lastTrade['from_city'] . ' ' . $lastTrade['from_name']) ?>"
                                                 class="rounded-circle" 
                                                 style="width: 50px; height: 50px; object-fit: cover; border: 2px solid var(--fba-orange); display: block; margin: 0 auto;">
                                        </div>
                                        <h6 class="text-white mb-0 small"><?= htmlspecialchars($lastTrade['from_city'] . ' ' . $lastTrade['from_name']) ?></h6>
                                    </div>
                                    
                                    <!-- Seta -->
                                    <div class="col-2 text-center">
                                        <i class="bi bi-arrow-left-right text-orange" style="font-size: 1.5rem;"></i>
                                    </div>
                                    
                                    <!-- Time 2 -->
                                    <div class="col-5 text-center">
                                        <div class="mb-2">
                                            <img src="<?= htmlspecialchars($lastTrade['to_photo'] ?? '/img/default-team.png') ?>" 
                                                 alt="<?= htmlspecialchars($lastTrade['to_city'] . ' ' . $lastTrade['to_name']) ?>"
                                                 class="rounded-circle" 
                                                 style="width: 50px; height: 50px; object-fit: cover; border: 2px solid var(--fba-orange); display: block; margin: 0 auto;">
                                        </div>
                                        <h6 class="text-white mb-0 small"><?= htmlspecialchars($lastTrade['to_city'] . ' ' . $lastTrade['to_name']) ?></h6>
                                    </div>
                                </div>
                                
                                <!-- Itens trocados -->
                                <div class="trade-items">
                                    <div class="row g-2">
                                        <!-- Do Time 1 -->
                                        <div class="col-6">
                                            <div class="p-2 bg-dark rounded" style="min-height: 120px;">
                                                <small class="text-orange fw-bold d-block mb-2">Enviou:</small>
                                                <?php if (count($lastTradeFromPlayers) > 0): ?>
                                                    <?php foreach ($lastTradeFromPlayers as $player): ?>
                                                        <div class="text-white small mb-1">
                                                            <i class="bi bi-person-fill text-orange"></i>
                                                            <?= htmlspecialchars($player['name']) ?> 
                                                            <span class="text-light-gray">(<?= $player['position'] ?> - <?= $player['ovr'] ?>)</span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                <?php if (count($lastTradeFromPicks) > 0): ?>
                                                    <?php foreach ($lastTradeFromPicks as $pick): ?>
                                                        <div class="text-white small mb-1">
                                                            <i class="bi bi-calendar-check text-orange"></i>
                                                            Pick <?= $pick['season_year'] ?> - R<?= $pick['round'] ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                <?php if (count($lastTradeFromPlayers) == 0 && count($lastTradeFromPicks) == 0): ?>
                                                    <small class="text-light-gray">-</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <!-- Do Time 2 -->
                                        <div class="col-6">
                                            <div class="p-2 bg-dark rounded" style="min-height: 120px;">
                                                <small class="text-orange fw-bold d-block mb-2">Enviou:</small>
                                                <?php if (count($lastTradeToPlayers) > 0): ?>
                                                    <?php foreach ($lastTradeToPlayers as $player): ?>
                                                        <div class="text-white small mb-1">
                                                            <i class="bi bi-person-fill text-orange"></i>
                                                            <?= htmlspecialchars($player['name']) ?> 
                                                            <span class="text-light-gray">(<?= $player['position'] ?> - <?= $player['ovr'] ?>)</span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                <?php if (count($lastTradeToPicks) > 0): ?>
                                                    <?php foreach ($lastTradeToPicks as $pick): ?>
                                                        <div class="text-white small mb-1">
                                                            <i class="bi bi-calendar-check text-orange"></i>
                                                            Pick <?= $pick['season_year'] ?> - R<?= $pick['round'] ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                <?php if (count($lastTradeToPlayers) == 0 && count($lastTradeToPicks) == 0): ?>
                                                    <small class="text-light-gray">-</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Data -->
                                <div class="text-center mt-2">
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
                        <?php else: ?>
                            <!-- Nenhuma trade ainda -->
                            <div class="text-center text-light-gray py-4">
                                <i class="bi bi-arrow-left-right display-4"></i>
                                <p class="mt-3 mb-0 text-white fw-bold">Nenhuma trade realizada</p>
                                <small>Seja o primeiro a trocar!</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- Informações da Liga, Top 5 Ranking e Últimos Vencedores (3 cards) -->
        <div class="row g-4 mb-4">
            <!-- Card 1: Informações da Liga -->
            <div class="col-lg-4">
                <div class="card bg-dark-panel border-orange h-100">
                    <div class="card-body text-center py-4">
                        <div class="mb-3">
                            <img src="/img/logo-<?= strtolower($user['league']) ?>.png" 
                                 alt="<?= htmlspecialchars($user['league']) ?>" 
                                 class="league-logo" 
                                 style="height: 80px; width: auto; object-fit: contain; display: block; margin: 0 auto;">
                        </div>
                        <h4 class="text-orange mb-3"><?= htmlspecialchars($user['league']) ?></h4>
                        
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="p-2 bg-dark rounded">
                                    <small class="text-light-gray d-block mb-1">Ranking</small>
                                    <strong class="text-white"><?= (int)($team['ranking_points'] ?? 0) ?></strong>
                                </div>
                            </div>
                            <?php if ($currentSeason): ?>
                            <div class="col-6">
                                <div class="p-2 bg-dark rounded">
                                    <small class="text-light-gray d-block mb-1">Temporada</small>
                                    <strong class="text-white"><?= (int)$seasonDisplayYear ?></strong>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 bg-dark rounded">
                                    <small class="text-light-gray d-block mb-1">Sprint</small>
                                    <strong class="text-white"><?= (int)($currentSeason['sprint_number'] ?? 1) ?></strong>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="col-<?= $currentSeason ? '6' : '12' ?>">
                                <div class="p-2 bg-dark rounded">
                                    <small class="text-light-gray d-block mb-1">CAP</small>
                                    <strong class="text-white small"><?= $capMin ?>-<?= $capMax ?></strong>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($hasEdital): ?>
                        <div class="mt-3">
                            <a href="/api/edital.php?action=download_edital&league=<?= urlencode($team['league']) ?>" 
                               class="btn btn-orange btn-sm w-100" download>
                                <i class="bi bi-download me-1"></i>Edital
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Card 2: Top 5 Ranking -->
            <div class="col-lg-4">
                <div class="card bg-dark-panel border-orange h-100">
                    <div class="card-header bg-transparent border-orange">
                        <h5 class="mb-0 text-white">
                            <i class="bi bi-trophy-fill me-2 text-orange"></i>Top 5 Ranking
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($topRanking) > 0): ?>
                            <div class="ranking-list">
                                <?php foreach ($topRanking as $index => $rankTeam): ?>
                                    <div class="ranking-item d-flex align-items-center mb-3 p-2 rounded <?= $rankTeam['id'] == $team['id'] ? 'bg-dark border border-orange' : 'bg-dark' ?>">
                                        <div class="rank-number me-3">
                                            <span class="badge <?= $index == 0 ? 'bg-warning' : ($index == 1 ? 'bg-secondary' : ($index == 2 ? 'bg-danger' : 'bg-dark-gray')) ?> fw-bold">
                                                <?= $index + 1 ?>º
                                            </span>
                                        </div>
                                        <img src="<?= htmlspecialchars($rankTeam['photo_url'] ?? '/img/default-team.png') ?>" 
                                             alt="<?= htmlspecialchars($rankTeam['city'] . ' ' . $rankTeam['name']) ?>"
                                             class="rounded-circle me-2" 
                                             style="width: 35px; height: 35px; object-fit: cover;">
                                        <div class="flex-grow-1">
                                            <div class="text-white small fw-bold"><?= htmlspecialchars($rankTeam['city'] . ' ' . $rankTeam['name']) ?></div>
                                            <small class="text-light-gray"><?= htmlspecialchars($rankTeam['owner_name'] ?? '') ?></small>
                                        </div>
                                        <div class="text-end">
                                            <strong class="text-orange"><?= (int)$rankTeam['ranking_points'] ?></strong>
                                            <small class="text-light-gray d-block">pts</small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-light-gray py-4">
                                <i class="bi bi-trophy display-4"></i>
                                <p class="mt-3 mb-0">Ranking em breve</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Card 3: Últimos Vencedores -->
            <div class="col-lg-4">
                <div class="card bg-dark-panel border-orange h-100">
                    <div class="card-header bg-transparent border-orange">
                        <h5 class="mb-0 text-white">
                            <i class="bi bi-award-fill me-2 text-orange"></i>Último Sprint
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($lastChampion || $lastRunnerUp || $lastMVP): ?>
                            <?php if ($lastSprintInfo): ?>
                            <div class="text-center mb-3">
                                <small class="text-light-gray">
                                    Sprint <?= (int)($lastSprintInfo['sprint_number'] ?? 0) ?>
                                    <?php if (!empty($lastSprintInfo['start_year'])): ?>
                                        - Temporada <?= (int)$lastSprintInfo['start_year'] ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Campeão -->
                            <?php if ($lastChampion): ?>
                            <div class="winner-item mb-3 p-2 bg-dark rounded border border-warning">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-trophy-fill text-warning fs-4 me-2"></i>
                                    <img src="<?= htmlspecialchars($lastChampion['photo_url'] ?? '/img/default-team.png') ?>" 
                                         alt="<?= htmlspecialchars($lastChampion['city'] . ' ' . $lastChampion['name']) ?>"
                                         class="rounded-circle me-2" 
                                         style="width: 40px; height: 40px; object-fit: cover;">
                                    <div>
                                        <div class="text-warning small fw-bold">CAMPEÃO</div>
                                        <div class="text-white small"><?= htmlspecialchars($lastChampion['city'] . ' ' . $lastChampion['name']) ?></div>
                                        <small class="text-light-gray"><?= htmlspecialchars($lastChampion['owner_name'] ?? '') ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Vice -->
                            <?php if ($lastRunnerUp): ?>
                            <div class="winner-item mb-3 p-2 bg-dark rounded">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-award text-secondary fs-4 me-2"></i>
                                    <img src="<?= htmlspecialchars($lastRunnerUp['photo_url'] ?? '/img/default-team.png') ?>" 
                                         alt="<?= htmlspecialchars($lastRunnerUp['city'] . ' ' . $lastRunnerUp['name']) ?>"
                                         class="rounded-circle me-2" 
                                         style="width: 35px; height: 35px; object-fit: cover;">
                                    <div>
                                        <div class="text-secondary small fw-bold">VICE-CAMPEÃO</div>
                                        <div class="text-white small"><?= htmlspecialchars($lastRunnerUp['city'] . ' ' . $lastRunnerUp['name']) ?></div>
                                        <small class="text-light-gray"><?= htmlspecialchars($lastRunnerUp['owner_name'] ?? '') ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- MVP -->
                            <?php if ($lastMVP): ?>
                            <div class="winner-item p-2 bg-dark rounded border border-orange">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-star-fill text-orange fs-4 me-2"></i>
                                    <div>
                                        <div class="text-orange small fw-bold">MVP</div>
                                        <div class="text-white"><?= htmlspecialchars($lastMVP['name']) ?></div>
                                        <small class="text-light-gray">
                                            <?php if (!empty($lastMVP['position']) && !empty($lastMVP['ovr'])): ?>
                                                <?= htmlspecialchars($lastMVP['position']) ?> - <?= (int)$lastMVP['ovr'] ?> OVR
                                                <?php if (!empty($lastMVP['team_city']) && !empty($lastMVP['team_name'])): ?>
                                                    <br>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($lastMVP['team_city']) && !empty($lastMVP['team_name'])): ?>
                                                <?= htmlspecialchars($lastMVP['team_city'] . ' ' . $lastMVP['team_name']) ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center text-light-gray py-4">
                                <i class="bi bi-award display-4"></i>
                                <p class="mt-3 mb-0 text-white fw-bold">Temporada não iniciada</p>
                                <small>Vencedores aparecerão após o primeiro sprint</small>
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
                                    <?php
                                        $playerName = $player['name'] ?? '';
                                        $nbaPlayerId = $player['nba_player_id'] ?? null;
                                        $playerPhoto = $nbaPlayerId
                                            ? 'https://cdn.nba.com/headshots/nba/latest/1040x760/' . rawurlencode((string)$nbaPlayerId) . '.png'
                                            : 'https://ui-avatars.com/api/?name=' . rawurlencode($playerName) . '&background=121212&color=f17507&rounded=true&bold=true';
                                    ?>
                                    <div class="col-md-2">
                                        <div class="card bg-dark text-white h-100">
                                            <div class="card-body text-center p-3">
                                                <img src="<?= htmlspecialchars($playerPhoto) ?>" alt="<?= htmlspecialchars($playerName) ?>"
                                                     style="width: 72px; height: 72px; object-fit: cover; border-radius: 50%; border: 2px solid var(--fba-orange); background: #1a1a1a;"
                                                     onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($playerName) ?>&background=121212&color=f17507&rounded=true&bold=true'">
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

    <?php if (($user['user_type'] ?? '') === 'admin'): ?>
    <div class="modal fade" id="adminInitDraftModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content bg-dark-panel border-orange">
                <div class="modal-header border-orange">
                    <h5 class="modal-title text-white">
                        <i class="bi bi-hand-index-thumb me-2 text-orange"></i>Escolher jogador (Admin)
                    </h5>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light-gray small">Use esta opção apenas quando o time responsável não estiver disponível. As picks são registradas imediatamente.</p>
                    <div class="table-responsive">
                        <table class="table table-dark table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Jogador</th>
                                    <th>Pos</th>
                                    <th>OVR</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="adminInitDraftPlayers">
                                <tr>
                                    <td colspan="4" class="text-center text-light-gray">Carregando...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer border-orange">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
    const picksData = <?= json_encode($teamPicksForCopy) ?>;
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
                const isTraded = (pk.original_team_id != pk.team_id);
                return `-${pk.season_year}${isTraded ? ` (via ${pk.city} ${pk.team_name})` : ''} `;
            });
            const round2Years = picksData.filter(pk => pk.round == 2).map(pk => {
                const isTraded = (pk.original_team_id != pk.team_id);
                return `-${pk.season_year}${isTraded ? ` (via ${pk.city} ${pk.team_name})` : ''} `;
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
                    lines.push(`${p.position}: ${p.name} - ${ovr} | ${formatAge(age)}`);
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
                    lines.push(`${p.position}: ${p.name} - ${ovr} | ${formatAge(age)}`);
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

        const INIT_DRAFT_SESSION_ID = <?= $activeInitDraftSession ? (int)$activeInitDraftSession['id'] : 'null'; ?>;
        const IS_ADMIN_USER = <?= (($user['user_type'] ?? '') === 'admin') ? 'true' : 'false'; ?>;
        const escapeHtml = (value = '') => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        async function openAdminInitDraftModal() {
            if (!IS_ADMIN_USER) return;
            if (!INIT_DRAFT_SESSION_ID) {
                alert('Nenhum draft inicial ativo.');
                return;
            }
            await loadAdminInitDraftPlayers();
            const modalEl = document.getElementById('adminInitDraftModal');
            if (!modalEl) return;
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }

        async function loadAdminInitDraftPlayers() {
            const tbody = document.getElementById('adminInitDraftPlayers');
            if (!tbody || !INIT_DRAFT_SESSION_ID) return;
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-light-gray">Carregando jogadores...</td></tr>';
            try {
                const res = await fetch(`/api/initdraft.php?action=available_players&session_id=${INIT_DRAFT_SESSION_ID}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Falha ao buscar jogadores');
                const players = data.players || [];
                if (players.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-light-gray">Nenhum jogador disponível.</td></tr>';
                    return;
                }
                tbody.innerHTML = players.map(p => `
                    <tr>
                        <td>${escapeHtml(p.name)}</td>
                        <td><span class="badge bg-orange">${escapeHtml(p.position)}</span></td>
                        <td>${escapeHtml(p.ovr)}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-success" onclick="adminMakeInitDraftPick(${p.id}, this)">
                                <i class="bi bi-check2"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger">${err.message}</td></tr>`;
            }
        }

        async function adminMakeInitDraftPick(playerId, buttonEl) {
            if (!IS_ADMIN_USER || !INIT_DRAFT_SESSION_ID) return;
            if (!confirm('Confirmar escolha deste jogador?')) return;
            buttonEl?.classList.add('disabled');
            try {
                const res = await fetch('/api/initdraft.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'admin_make_pick', session_id: INIT_DRAFT_SESSION_ID, player_id: playerId })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Falha ao registrar pick');
                alert('Pick registrada com sucesso.');
                location.reload();
            } catch (err) {
                alert(err.message);
                buttonEl?.classList.remove('disabled');
            }
        }
    </script>
</body>
</html>
