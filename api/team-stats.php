<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = getUserSession();

$teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if (!$teamId) { echo json_encode(['error' => 'team_id obrigatório']); exit; }

// Verificar se o time pertence à mesma liga do usuário (ou é admin)
$stmtTeam = $pdo->prepare("SELECT t.*, CONCAT(t.city,' ',t.name) AS full_name, u.name AS owner_name FROM teams t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
$stmtTeam->execute([$teamId]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
if (!$team) { echo json_encode(['error' => 'Time não encontrado']); exit; }

$league = $team['league'];

// ── 1. Total de pontos e temporadas ──────────────────────────────────────────
$stmtPts = $pdo->prepare("
    SELECT COUNT(*) AS seasons_played,
           SUM(points) AS total_points,
           MAX(points) AS best_season_pts,
           MIN(points) AS worst_season_pts
    FROM team_season_points
    WHERE team_id = ?
");
$stmtPts->execute([$teamId]);
$ptsData = $stmtPts->fetch(PDO::FETCH_ASSOC);

// ── 2. Playoffs (temporadas com pelo menos 2 pts) ─────────────────────────────
$stmtPlayoffs = $pdo->prepare("
    SELECT COUNT(*) AS playoff_appearances
    FROM team_season_points
    WHERE team_id = ? AND points >= 2
");
$stmtPlayoffs->execute([$teamId]);
$playoffData = $stmtPlayoffs->fetch(PDO::FETCH_ASSOC);

// ── 3. Títulos e finais ───────────────────────────────────────────────────────
$stmtTitles = $pdo->prepare("
    SELECT
        SUM(champion_team_id = ?)   AS titles,
        SUM(runner_up_team_id = ?)  AS runner_ups
    FROM season_history
");
$stmtTitles->execute([$teamId, $teamId]);
$titlesData = $stmtTitles->fetch(PDO::FETCH_ASSOC);

// Temporada(s) em que foi campeão
$stmtChampSeasons = $pdo->prepare("
    SELECT sh.season_id, sh.year, sh.season_number, sh.sprint_number
    FROM season_history sh
    WHERE sh.champion_team_id = ?
    ORDER BY sh.year DESC
");
$stmtChampSeasons->execute([$teamId]);
$champSeasons = $stmtChampSeasons->fetchAll(PDO::FETCH_ASSOC);

// ── 4. Picks ──────────────────────────────────────────────────────────────────
$stmtPicks = $pdo->prepare("
    SELECT
        COUNT(*) AS total_picks,
        SUM(round = 1) AS picks_r1,
        SUM(round = 2) AS picks_r2
    FROM picks
    WHERE original_team_id = ? AND auto_generated = 1
");
$stmtPicks->execute([$teamId]);
$picksOwned = $stmtPicks->fetch(PDO::FETCH_ASSOC);

// Picks top1 recebidas (original de outro time, rodada 1)
$stmtPicksReceived = $pdo->prepare("
    SELECT COUNT(*) AS received_r1_foreign
    FROM picks
    WHERE team_id = ? AND original_team_id != ? AND round = 1
");
$stmtPicksReceived->execute([$teamId, $teamId]);
$picksReceived = $stmtPicksReceived->fetch(PDO::FETCH_ASSOC);

// ── 5. Trocas ─────────────────────────────────────────────────────────────────
$stmtTrades = $pdo->prepare("
    SELECT COUNT(*) AS total_trades
    FROM trades
    WHERE status = 'accepted' AND (from_team_id = ? OR to_team_id = ?)
");
$stmtTrades->execute([$teamId, $teamId]);
$tradesData = $stmtTrades->fetch(PDO::FETCH_ASSOC);

// Multi-trades
$stmtMultiTrades = $pdo->prepare("
    SELECT COUNT(DISTINCT mt.id) AS multi_trades
    FROM multi_trades mt
    JOIN multi_trade_teams mtt ON mtt.trade_id = mt.id
    WHERE mt.status = 'accepted' AND mtt.team_id = ?
");
$stmtMultiTrades->execute([$teamId]);
$multiData = $stmtMultiTrades->fetch(PDO::FETCH_ASSOC);
$totalTrades = (int)($tradesData['total_trades'] ?? 0) + (int)($multiData['multi_trades'] ?? 0);

// ── 6. Jogadores que passaram pelo clube ─────────────────────────────────────
$stmtPlayers = $pdo->prepare("
    SELECT COUNT(DISTINCT player_id) AS total_players,
           COUNT(DISTINCT CASE WHEN team_id = ? THEN player_id END) AS current_players
    FROM player_season_log
    WHERE team_id = ?
");
$stmtPlayers->execute([$teamId, $teamId]);
$playersData = $stmtPlayers->fetch(PDO::FETCH_ASSOC);

// Total de jogadores distintos que passaram (incluindo trocas)
$stmtAllPlayers = $pdo->prepare("
    SELECT COUNT(DISTINCT player_id) AS total_ever
    FROM player_season_log
    WHERE team_id = ?
");
$stmtAllPlayers->execute([$teamId]);
$allPlayers = $stmtAllPlayers->fetch(PDO::FETCH_ASSOC);

// ── 7. Melhor e pior jogador (por OVR histórico) ─────────────────────────────
$stmtBest = $pdo->prepare("
    SELECT player_name, ovr, position, year
    FROM player_season_log
    WHERE team_id = ?
    ORDER BY ovr DESC
    LIMIT 1
");
$stmtBest->execute([$teamId]);
$bestPlayer = $stmtBest->fetch(PDO::FETCH_ASSOC);

$stmtWorst = $pdo->prepare("
    SELECT player_name, ovr, position, year
    FROM player_season_log
    WHERE team_id = ? AND ovr > 0
    ORDER BY ovr ASC
    LIMIT 1
");
$stmtWorst->execute([$teamId]);
$worstPlayer = $stmtWorst->fetch(PDO::FETCH_ASSOC);

// ── 8. Melhor jogador por posição (histórico) ─────────────────────────────────
$bestByPos = [];
foreach (['PG','SG','SF','PF','C'] as $pos) {
    $stmtPos = $pdo->prepare("
        SELECT player_name, ovr, year
        FROM player_season_log
        WHERE team_id = ? AND position = ?
        ORDER BY ovr DESC
        LIMIT 1
    ");
    $stmtPos->execute([$teamId, $pos]);
    $row = $stmtPos->fetch(PDO::FETCH_ASSOC);
    $bestByPos[$pos] = $row ?: null;
}

// ── 9. Time campeão (elenco da temporada do título) ──────────────────────────
$champRoster = [];
if (!empty($champSeasons)) {
    $champSeasonId = $champSeasons[0]['season_id'];
    $stmtRoster = $pdo->prepare("
        SELECT player_name, position, ovr, age
        FROM player_season_log
        WHERE team_id = ? AND season_id = ?
        ORDER BY ovr DESC
    ");
    $stmtRoster->execute([$teamId, $champSeasonId]);
    $champRoster = $stmtRoster->fetchAll(PDO::FETCH_ASSOC);
}

// ── 10. Prêmios individuais conquistados ────────────────────────────────────
$stmtAwards = $pdo->prepare("
    SELECT award_type, COUNT(*) AS total
    FROM season_awards
    WHERE team_id = ?
    GROUP BY award_type
    ORDER BY total DESC
");
$stmtAwards->execute([$teamId]);
$awards = $stmtAwards->fetchAll(PDO::FETCH_ASSOC);

// ── 11. Conferências finais e semifinais ─────────────────────────────────────
$stmtPlayoffResults = $pdo->prepare("
    SELECT position, COUNT(*) AS total
    FROM playoff_results
    WHERE team_id = ?
    GROUP BY position
");
$stmtPlayoffResults->execute([$teamId]);
$playoffResults = [];
foreach ($stmtPlayoffResults->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $playoffResults[$r['position']] = (int)$r['total'];
}

// ── 12. Melhor temporada (mais pontos) ───────────────────────────────────────
$stmtBestSeason = $pdo->prepare("
    SELECT tsp.season_id, tsp.points, tsp.season_number, s.year
    FROM team_season_points tsp
    LEFT JOIN seasons s ON s.id = tsp.season_id
    WHERE tsp.team_id = ?
    ORDER BY tsp.points DESC
    LIMIT 1
");
$stmtBestSeason->execute([$teamId]);
$bestSeason = $stmtBestSeason->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success'   => true,
    'team'      => $team,
    'seasons'   => [
        'played'       => (int)($ptsData['seasons_played'] ?? 0),
        'total_points' => (int)($ptsData['total_points'] ?? 0),
        'best_pts'     => (int)($ptsData['best_season_pts'] ?? 0),
        'best_season'  => $bestSeason,
    ],
    'playoffs'  => [
        'appearances'    => (int)($playoffData['playoff_appearances'] ?? 0),
        'titles'         => (int)($titlesData['titles'] ?? 0),
        'runner_ups'     => (int)($titlesData['runner_ups'] ?? 0),
        'conf_finals'    => $playoffResults['conference_final'] ?? 0,
        'second_round'   => $playoffResults['second_round'] ?? 0,
        'first_round'    => $playoffResults['first_round'] ?? 0,
        'champ_seasons'  => $champSeasons,
        'champ_roster'   => $champRoster,
    ],
    'picks'     => [
        'own_r1'         => (int)($picksOwned['picks_r1'] ?? 0),
        'own_r2'         => (int)($picksOwned['picks_r2'] ?? 0),
        'received_r1_foreign' => (int)($picksReceived['received_r1_foreign'] ?? 0),
    ],
    'trades'    => $totalTrades,
    'players'   => [
        'total_ever'  => (int)($allPlayers['total_ever'] ?? 0),
        'best'        => $bestPlayer,
        'worst'       => $worstPlayer,
        'best_by_pos' => $bestByPos,
    ],
    'awards'    => $awards,
]);
